<?php
/**
 * AMANAH — Dasbor Supplier
 *
 * Alur kerja supplier:
 *   1. Catat "hari ini barang masuk berapa"  → masuk ke kolam SIAP KIRIM
 *   2. Pilih barang dari kolam itu           → terbitkan SURAT JALAN + resi
 *   3. Gudang memindai resinya saat barang tiba dan memverifikasi fisiknya
 *
 * Stok gudang TIDAK bertambah saat surat jalan terbit — hanya saat gudang
 * benar-benar menerima barangnya. Itu sebabnya angka gudang selalu
 * mencerminkan barang yang sungguh ada di rak.
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/_lib.php';
require_login();

$u = current_user();

// Halaman ini bisa dibuka dua cara:
//   1. Supplier sendiri  → dasbornya, bisa bertindak
//   2. Super Admin / Pimpinan dengan ?supplier=N → MODE PENGAWASAN, baca saja
$awasi = false;
if (is_supplier()) {
    $supplierId = supplier_saya();
    if (!$supplierId) {
        die('<p style="font-family:sans-serif;padding:30px">Akun Anda belum dikaitkan ke data supplier. '
          . 'Hubungi admin gudang. <a href="logout.php">Keluar</a></p>');
    }
} elseif (boleh_mengawasi()) {
    $awasi      = true;
    $supplierId = (int) ($_GET['supplier'] ?? 0);
    if (!$supplierId) { header('Location: pengawasan.php'); exit; }
} else {
    header('Location: ' . beranda_peran()); exit;
}

// Mode lihat-saja: mencatat barang masuk atau
// menerbitkan surat jalan atas nama supplier akan mengaburkan jejak audit.
if ($awasi && $_SERVER['REQUEST_METHOD'] === 'POST') { tolak_aksi_pengawasan(); }

$stS = $pdo->prepare("SELECT * FROM supplier WHERE id = ?");
$stS->execute([$supplierId]);
$supplier = $stS->fetch();

$errors = [];
$tab    = $_GET['tab'] ?? 'masuk';

// ==================================================================== AKSI
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    wajib_csrf($_POST['csrf'] ?? null);
    $aksi = $_POST['aksi'] ?? '';

    // ---------------------------------------------- 1. CATAT BARANG MASUK
    if ($aksi === 'barang_masuk') {
        /* Sumber ditentukan sekali untuk seluruh pencatatan, sedangkan
           kondisi per baris. Alasannya beda sifat: satu kali menerima
           titipan adalah satu peristiwa dengan satu asal, tapi isi
           titipan itu bisa bercampur — sebagian tersegel, sebagian bekas
           layak pakai, sebagian sudah tidak bisa dipakai. */
        $sumberForm = sumber_sah($_POST['sumber'] ?? 'pembelian');

        /* Donatur diketik, lalu dicocokkan. Yang dipegang di sini baru
           datanya; id-nya baru ditentukan di dalam transaksi di bawah,
           supaya donatur tidak terlanjur dibuat kalau penyimpanan
           barangnya kemudian gagal. */
        $donaturIsi = [
            'nama'    => trim($_POST['donatur_nama'] ?? ''),
            'telepon' => trim($_POST['donatur_telepon'] ?? ''),
            'jenis'   => $_POST['donatur_jenis'] ?? 'pribadi',
            'wilayah' => trim($_POST['donatur_wilayah'] ?? ''),
            'anonim'  => isset($_POST['donatur_anonim']),
        ];

        $items = [];
        foreach (($_POST['barang_id'] ?? []) as $i => $bid) {
            $bid = (int) $bid;
            $qty = (int) ($_POST['qty'][$i] ?? 0);
            if (!$bid || $qty <= 0) continue;
            if ($qty > MAKS_QTY_BARIS) {
                $errors[] = 'Jumlah ' . angka($qty) . ' tidak wajar (batas ' . angka(MAKS_QTY_BARIS)
                          . '). Biasanya barcode tertembak ke kotak Jumlah.';
                continue;
            }
            $items[] = [
                'barang_id' => $bid,
                'qty'       => $qty,
                'batch'     => trim($_POST['nomor_batch'][$i] ?? '') ?: ('B' . date('ymd')),
                'exp'       => trim($_POST['tanggal_expired'][$i] ?? '') ?: null,
                'harga'     => (float) ($_POST['harga'][$i] ?? 0),
                // Inilah penilaian yang dipercaya sampai ke penerima akhir.
                // Gudang tidak menilai ulang, jadi kalau salah di sini,
                // salahnya terbawa sampai barang disalurkan.
                'kondisi'   => kondisi_sah($_POST['kondisi'][$i] ?? 'baru'),
            ];
        }
        if (!$items) {
            $errors[] = 'Isi minimal satu baris barang beserta jumlahnya.';
        } elseif ($sumberForm === 'donasi' && $donaturIsi['nama'] === '') {
            $errors[] = 'Barang donasi wajib menyebutkan nama donaturnya.';
        } elseif ($sumberForm === 'donasi' && kunci_telepon($donaturIsi['telepon']) === '') {
            /* Nomor HP diwajibkan karena ia separuh dari penanda donatur.
               Tanpa nomor, dua orang bernama sama tidak bisa dibedakan dan
               satu orang yang namanya diketik sedikit berbeda akan tercatat
               dua kali — persis yang membuat angka "sudah berapa kali
               memberi" tidak bisa dipercaya. */
            $errors[] = 'Nomor HP donatur wajib diisi — bersama namanya, itulah yang '
                      . 'dipakai sistem untuk mengenali donatur yang sudah pernah memberi.';
        } else {
            try {
                $pdo->beginTransaction();
                /* Barang yang baru dicatat mendarat di rak bawaan gudang
                   supplier. Baris tanpa rak akan hilang dari laporan yang
                   bertolak dari lokasi, jadi lokasinya ditentukan di sini —
                   bukan dibiarkan kosong untuk dibereskan nanti. */
                $rakBawaan = rak_supplier($pdo);
                $rakBawaan = $rakBawaan ? (int) $rakBawaan[0] : null;

                /* Titipan donasi dicatat sebagai satu peristiwa memberi
                   (donasi + donasi_detail), bukan hanya sebagai tambahan
                   stok. Tanpa kepala donasi, "sudah berapa kali donatur
                   ini memberi" tidak bisa dijawab: sepuluh jenis barang
                   dalam satu kali datang akan terhitung sepuluh kali. */
                $donasiId = null;
                $donaturId = null;
                $cocok = null;
                if ($sumberForm === 'donasi') {
                    // Nama + nomor HP sama berarti donatur lama; kodenya
                    // dipakai ulang. Salah satu berbeda berarti donatur baru.
                    $cocok = donatur_cocok_atau_buat($pdo, $donaturIsi, $supplierId);
                    $donaturId = $cocok['id'];

                    $noDonasi = nomor_dokumen($pdo, 'donasi', 'no_donasi', 'DN');
                    $pdo->prepare(
                        "INSERT INTO donasi (no_donasi, donatur_id, supplier_id, tanggal,
                                             jenis, cara_terima, catatan, dibuat_oleh)
                         VALUES (?,?,?,CURDATE(),?,?,?,?)"
                    )->execute([
                        $noDonasi, $donaturId, $supplierId,
                        jenis_donasi_sah($_POST['jenis_donasi'] ?? null),
                        in_array($_POST['cara_terima'] ?? '', ['diantar', 'dijemput', 'dikirim'], true)
                            ? $_POST['cara_terima'] : 'diantar',
                        trim($_POST['catatan_donasi'] ?? '') ?: null,
                        $u['id'],
                    ]);
                    $donasiId = (int) $pdo->lastInsertId();

                    $stDD = $pdo->prepare(
                        "INSERT INTO donasi_detail
                           (donasi_id, barang_id, kondisi, nomor_batch, tanggal_expired,
                            qty, nilai_taksir)
                         VALUES (?,?,?,?,?,?,?)"
                    );
                    foreach ($items as $it) {
                        $stDD->execute([$donasiId, $it['barang_id'], $it['kondisi'], $it['batch'],
                                        $it['exp'], $it['qty'], $it['qty'] * $it['harga']]);
                    }
                }

                $st = $pdo->prepare(
                    "INSERT INTO stok_supplier
                       (supplier_id, barang_id, lokasi_rak_id, nomor_batch, kondisi, sumber,
                        donatur_id, tanggal_expired, qty, harga, tanggal_masuk)
                     VALUES (?,?,?,?,?,?,?,?,?,?,CURDATE())
                     ON DUPLICATE KEY UPDATE qty = qty + VALUES(qty),
                                             harga = IF(VALUES(harga) > 0, VALUES(harga), harga)"
                );
                $total = 0;
                foreach ($items as $it) {
                    $st->execute([$supplierId, $it['barang_id'], $rakBawaan, $it['batch'],
                                  $it['kondisi'], $sumberForm, $donaturId,
                                  $it['exp'], $it['qty'], $it['harga']]);
                    $total += $it['qty'];
                }
                $pdo->commit();
                catat_aktivitas($pdo, 'create', 'stok_supplier', $supplierId,
                    "Supplier catat barang masuk: " . count($items) . " jenis, $total unit"
                    . ($donasiId ? " (donasi #$donasiId)" : ''));

                $tidakLayak = 0;
                foreach ($items as $it) {
                    if (!kondisi_boleh_salur($it['kondisi'])) $tidakLayak++;
                }

                /* Hasil pencocokan donatur dilaporkan apa adanya. Petugas
                   perlu tahu ia baru saja membuat donatur baru atau menambah
                   catatan donatur lama — itu yang membuat salah ketik nama
                   ketahuan hari itu juga, bukan berbulan-bulan kemudian saat
                   peringkatnya terlihat aneh. */
                $pesanDonatur = '';
                if ($cocok) {
                    $pesanDonatur = $cocok['baru']
                        ? ' Donatur baru terdaftar dengan kode ' . $cocok['kode'] . '.'
                        : ' Tercatat atas donatur lama ' . $cocok['kode'] . '.';
                    if ($cocok['bentrok']) {
                        $pesanDonatur .= ' PERHATIAN: nomor HP itu sudah dipakai donatur '
                            . $cocok['bentrok']['kode'] . ' dengan nama yang berbeda, jadi keduanya'
                            . ' dihitung sebagai dua donatur. Bila ternyata orang yang sama,'
                            . ' perbaiki namanya di halaman Donatur agar riwayatnya tidak terpecah.';
                    }
                }

                set_flash('ok', 'Tercatat ' . count($items) . ' jenis barang (' . angka($total)
                              . ' unit) masuk hari ini. Barang kini ada di daftar Siap Kirim.'
                              . $pesanDonatur
                              . ($tidakLayak
                                  ? " $tidakLayak jenis ditandai tidak layak pakai — barang itu tidak"
                                    . ' akan ikut dihitung sebagai stok yang siap disalurkan.'
                                  : ''));
                header('Location: dasbor_supplier.php?tab=siap'); exit;
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                $errors[] = pesan_error_aman($e, 'supplier catat barang masuk');
            }
        }
    }

    // ------------------------------------------- 2. TERBITKAN SURAT JALAN
    if ($aksi === 'buat_sj') {
        /* Dua tujuan yang mungkin, dan keduanya berbeda alurnya:

             gudang   — surat jalan terbit seketika, stok mitra langsung
                        dipotong, gudang memverifikasi fisiknya nanti.
             penerima — barang diserahkan LANGSUNG ke penerima amanah tanpa
                        singgah di gudang. Menunggu persetujuan Manager, dan
                        stok mitra baru dipotong saat disetujui.

           Stok sengaja TIDAK dipotong saat pengajuan pada jalur penerima:
           kalau dipotong lebih dulu lalu pengajuannya ditolak, barangnya
           harus dikembalikan — dan pengembalian yang gagal separuh jalan
           meninggalkan stok yang hilang tanpa jejak. Dengan memotongnya
           saat disetujui, penolakan tidak perlu membatalkan apa pun. */
        $tujuanKe   = ($_POST['tujuan_ke'] ?? 'gudang') === 'penerima' ? 'penerima' : 'gudang';
        $penerimaId = (int) ($_POST['penerima_id'] ?? 0) ?: null;
        $gudangId  = (int) ($_POST['gudang_id'] ?? 0) ?: null;
        $ekspedisi = trim($_POST['ekspedisi'] ?? '');
        $resiLuar  = trim($_POST['no_resi'] ?? '');
        $kendaraan = trim($_POST['kendaraan'] ?? '');
        $noPolisi  = trim($_POST['no_polisi'] ?? '');
        $catatan   = trim($_POST['catatan'] ?? '');
        /* Tanggal dokumen — fungsi yang SAMA dengan formulir Perpindahan
           dan distribusi toko, supaya batasnya tidak berbeda antar jalur. */
        $tglCek     = tanggal_dokumen_sah($_POST['tanggal'] ?? null);
        if ($tglCek['galat']) { $errors[] = $tglCek['galat']; }
        $tglSimpanS = $tglCek['simpan'];

        // Baris yang dicentang beserta jumlah kirimnya.
        $pilih = [];
        foreach (($_POST['kirim'] ?? []) as $stokId => $qty) {
            $stokId = (int) $stokId; $qty = (int) $qty;
            if ($stokId > 0 && $qty > 0) $pilih[$stokId] = $qty;
        }

        if ($tujuanKe === 'gudang' && !$gudangId)    $errors[] = 'Gudang tujuan wajib dipilih.';
        if ($tujuanKe === 'penerima' && !$penerimaId) $errors[] = 'Penerima amanah wajib dipilih.';
        if (!$pilih) $errors[] = 'Centang minimal satu barang dan isi jumlah kirimnya.';

        if (!$errors) {
            try {
                $hasil = dengan_nomor_dokumen($pdo, 'perpindahan', 'no_perpindahan', 'PP',
                    function (string $noPP) use ($pdo, $supplierId, $gudangId, $ekspedisi, $resiLuar, $tglSimpanS,
                                                  $kendaraan, $noPolisi, $catatan, $pilih, $u, $supplier,
                                                  $tujuanKe, $penerimaId) {
                        $pdo->beginTransaction();

                        // Kunci baris kolam siap kirim, lalu pastikan jumlahnya cukup.
                        $ids = implode(',', array_map('intval', array_keys($pilih)));
                        $st  = $pdo->query(
                            "SELECT * FROM stok_supplier
                             WHERE id IN ($ids) AND supplier_id = " . (int) $supplierId . "
                             FOR UPDATE"
                        );
                        $baris = [];
                        foreach ($st->fetchAll() as $r) $baris[(int) $r['id']] = $r;

                        foreach ($pilih as $sid => $q) {
                            if (!isset($baris[$sid])) {
                                throw new RuntimeException('Ada baris barang yang sudah tidak tersedia. Muat ulang halaman.');
                            }
                            if ($q > (int) $baris[$sid]['qty']) {
                                throw new RuntimeException(
                                    'Jumlah kirim melebihi yang tersedia pada batch '
                                    . $baris[$sid]['nomor_batch'] . ' (tersedia ' . $baris[$sid]['qty'] . ').'
                                );
                            }
                        }

                        $keGudang = ($tujuanKe === 'gudang');

                        /* Ke GUDANG: surat jalan langsung terbit — mitra tidak
                           perlu menunggu persetujuan siapa pun untuk mengirim
                           barang ke gudang lembaga sendiri.

                           Ke PENERIMA: nomor surat jalan BELUM diterbitkan.
                           Ia terbit saat Manager menyetujui, lewat
                           sahkan_perpindahan() — sama seperti dokumen lain.
                           Menerbitkannya di sini berarti ada surat jalan
                           beredar untuk penyerahan yang belum tentu disetujui. */
                        $noSJ = $keGudang
                              ? nomor_dokumen($pdo, 'perpindahan', 'no_surat_jalan', 'SJ')
                              : null;
                        $resi = $keGudang ? ($resiLuar !== '' ? $resiLuar : $noSJ)
                                          : ($resiLuar !== '' ? $resiLuar : null);

                        $pdo->prepare(
                            "INSERT INTO perpindahan
                               (no_perpindahan, no_surat_jalan, jenis, tanggal,
                                asal_tipe, asal_id, tujuan_tipe, tujuan_id,
                                no_resi, ekspedisi, kendaraan, no_polisi, pengirim,
                                status, user_id, catatan)
                             VALUES (?,?,?,?,'supplier',?,?,?,?,?,?,?,?,?,?,?)"
                        )->execute([
                            $noPP, $noSJ,
                            $keGudang ? 'penerimaan' : 'penyaluran',
                            $tglSimpanS,
                            $supplierId,
                            $keGudang ? 'gudang' : 'penerima',
                            $keGudang ? $gudangId : $penerimaId,
                            $resi, $ekspedisi ?: null, $kendaraan ?: null, $noPolisi ?: null,
                            $supplier['nama'] ?? $u['nama'],
                            $keGudang ? 'dikirim' : 'menunggu_persetujuan',
                            $u['id'], $catatan ?: null,
                        ]);
                        $ppId = (int) $pdo->lastInsertId();

                        /* Kondisi, sumber, dan donatur ikut tertulis di baris
                           dokumen. Inilah yang membuat gudang tidak perlu
                           menilai ulang: penilaian supplier menempel pada
                           surat jalannya, dan terapkan_perpindahan() di
                           _lib.php meneruskannya apa adanya ke batch gudang.
                           Sebelumnya ketiga kolom ini dibiarkan kosong, jadi
                           setiap kedatangan dari supplier masuk sebagai
                           barang 'baru' hasil 'pembelian' tanpa donatur —
                           apa pun kenyataannya. */
                        $stD  = $pdo->prepare(
                            "INSERT INTO perpindahan_detail
                               (perpindahan_id, barang_id, nomor_batch, kondisi, sumber, donatur_id,
                                tanggal_expired, qty, harga, subtotal)
                             VALUES (?,?,?,?,?,?,?,?,?,?)"
                        );
                        $kurang = $pdo->prepare("UPDATE stok_supplier SET qty = qty - ? WHERE id = ?");
                        $bersih = $pdo->prepare("DELETE FROM stok_supplier WHERE id = ? AND qty <= 0");

                        $totalUnit = 0;
                        foreach ($pilih as $sid => $q) {
                            $r = $baris[$sid];
                            $stD->execute([$ppId, $r['barang_id'], $r['nomor_batch'],
                                           $r['kondisi'] ?? 'baru', $r['sumber'] ?? 'pembelian',
                                           $r['donatur_id'] ?? null, $r['tanggal_expired'],
                                           $q, $r['harga'], $q * (float) $r['harga']]);
                            /* Stok mitra hanya dipotong pada jalur GUDANG.
                               Untuk penyerahan langsung ke penerima, pemotongan
                               terjadi saat Manager menyetujui — kalau dipotong
                               di sini juga, barangnya berkurang dua kali. */
                            if ($keGudang) {
                                $kurang->execute([$q, $sid]);
                                $bersih->execute([$sid]);
                            }
                            $totalUnit += $q;
                        }

                        $pdo->commit();
                        return ['id' => $ppId, 'no' => $noPP, 'sj' => $noSJ, 'resi' => $resi, 'unit' => $totalUnit];
                    });

                if ($tujuanKe === 'gudang') {
                    catat_aktivitas($pdo, 'create', 'perpindahan', $hasil['id'],
                        "Supplier terbitkan surat jalan {$hasil['sj']} ({$hasil['unit']} unit)");
                    set_flash('ok', "Surat jalan {$hasil['sj']} sudah terbit. Kode resi untuk dipindai gudang: "
                                  . "{$hasil['resi']}. Cetak surat jalannya dan sertakan bersama barang.");
                    header('Location: surat_jalan.php?id=' . $hasil['id']); exit;
                }

                /* Penyerahan langsung ke penerima: belum ada surat jalan, dan
                   stok mitra belum berkurang. Keduanya menyusul saat Manager
                   menyetujui — dikatakan terus terang di sini supaya mitra
                   tidak menyerahkan barangnya lebih dulu lalu bingung kenapa
                   stoknya masih utuh di layar. */
                catat_aktivitas($pdo, 'create', 'perpindahan', $hasil['id'],
                    "Supplier ajukan penyaluran langsung {$hasil['no']} ({$hasil['unit']} unit)");
                set_flash('ok', "Pengajuan {$hasil['no']} terkirim dan menunggu persetujuan Manager. "
                              . "Stok Anda belum berkurang, dan surat jalannya terbit setelah disetujui.");
                header('Location: dasbor_supplier.php?tab=riwayat'); exit;

            } catch (RuntimeException $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                $errors[] = pesan_runtime($e, 'dasbor_supplier');
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                $errors[] = pesan_error_aman($e, 'supplier buat surat jalan');
            }
        }
        $tab = 'siap';
    }

    // ------------------------------------------- Hapus baris siap kirim
    if ($aksi === 'hapus_siap') {
        $sid = (int) ($_POST['id'] ?? 0);
        try {
            $pdo->prepare("DELETE FROM stok_supplier WHERE id = ? AND supplier_id = ?")
                ->execute([$sid, $supplierId]);
            set_flash('ok', 'Baris barang dihapus dari daftar siap kirim.');
        } catch (Throwable $e) {
            set_flash('err', pesan_error_aman($e, 'hapus stok supplier'));
        }
        header('Location: dasbor_supplier.php?tab=siap'); exit;
    }

    // ------------------------------------------------- Pindah antar rak
    /* Pemindahan di DALAM gudang sendiri, bukan peristiwa rantai — jadi
       tidak menerbitkan dokumen perpindahan dan tidak melewati persetujuan.
       Yang berubah hanya lokasi barisnya. */
    if ($aksi === 'pindah_rak') {
        $sid    = (int) ($_POST['stok_id'] ?? 0);
        $rakBaru = (int) ($_POST['rak_tujuan'] ?? 0);
        $rakSah = array_map('intval', rak_supplier($pdo));

        if (!in_array($rakBaru, $rakSah, true)) {
            set_flash('err', 'Rak tujuan tidak berada di gudang Anda.');
            header('Location: dasbor_supplier.php?tab=rak'); exit;
        }
        try {
            /* supplier_id ikut dalam WHERE, bukan hanya id: tanpa itu,
               mengganti angka stok_id di formulir cukup untuk memindahkan
               stok supplier lain. */
            $st = $pdo->prepare(
                "UPDATE stok_supplier SET lokasi_rak_id = ?
                 WHERE id = ? AND supplier_id = ?"
            );
            $st->execute([$rakBaru, $sid, $supplierId]);

            if ($st->rowCount()) {
                catat_aktivitas($pdo, 'update', 'stok_supplier', $sid,
                    'Pindah antar rak di gudang supplier');
                set_flash('ok', 'Barang dipindahkan ke rak tujuan.');
            } else {
                set_flash('err', 'Baris stok tidak ditemukan, atau sudah berada di rak itu.');
            }
        } catch (Throwable $e) {
            set_flash('err', pesan_error_aman($e, 'pindah rak supplier'));
        }
        header('Location: dasbor_supplier.php?tab=rak'); exit;
    }
}

// ==================================================================== DATA
$barangList = $pdo->query(
    "SELECT id, kode, nama, satuan FROM barang WHERE deleted_at IS NULL AND is_active = 1 ORDER BY nama"
)->fetchAll();
$gudangList = $pdo->query(
    "SELECT id, kode, nama FROM gudang WHERE deleted_at IS NULL AND is_active = 1 ORDER BY kode"
)->fetchAll();

/* Penerima amanah, untuk penyerahan langsung tanpa lewat gudang.
   Hanya nama dan wilayah yang diambil — alamat dan telepon penerima tidak
   dibutuhkan mitra untuk memilih dari daftar, dan tidak perlu dikirim ke
   layar yang bukan miliknya. */
$penerimaList = [];
try {
    $penerimaList = $pdo->query(
        "SELECT id, nama, wilayah FROM penerima
         WHERE deleted_at IS NULL AND is_active = 1 ORDER BY nama"
    )->fetchAll();
} catch (Throwable $e) { error_log('[AMANAH] penerima list: ' . $e->getMessage()); }

/* Donatur yang boleh dipilih supplier ini: yang ia daftarkan sendiri, dan
   yang tidak terikat mitra mana pun. Daftar donatur mitra LAIN sengaja
   tidak ikut — itu data pribadi orang yang tidak berhubungan dengan mitra
   ini, dan tidak ada alasan operasional untuk menampilkannya. */
$stDon = $pdo->prepare(
    "SELECT id, nama, telepon, jenis, wilayah, anonim FROM donatur
     WHERE deleted_at IS NULL AND is_active = 1
       AND (supplier_id = ? OR supplier_id IS NULL)
     ORDER BY nama"
);
$stDon->execute([$supplierId]);
$donaturList = $stDon->fetchAll();

function s1sup(PDO $pdo, string $sql, array $p) {
    try { $st = $pdo->prepare($sql); $st->execute($p); return $st->fetchColumn(); }
    catch (Throwable $e) { error_log('[AMANAH] dasbor supplier: ' . $e->getMessage()); return 0; }
}

$masukHari = (int) s1sup($pdo,
    "SELECT COALESCE(SUM(qty),0) FROM stok_supplier WHERE supplier_id = ? AND tanggal_masuk = CURDATE()", [$supplierId]);
$siapKirim = (int) s1sup($pdo,
    "SELECT COALESCE(SUM(qty),0) FROM stok_supplier WHERE supplier_id = ?", [$supplierId]);
$diJalan   = (int) s1sup($pdo,
    "SELECT COALESCE(SUM(d.qty),0) FROM perpindahan p JOIN perpindahan_detail d ON d.perpindahan_id = p.id
     WHERE p.asal_tipe='supplier' AND p.asal_id = ? AND p.status = 'dikirim'", [$supplierId]);
$diterimaBln = (int) s1sup($pdo,
    "SELECT COALESCE(SUM(COALESCE(d.qty_diterima, d.qty)),0) FROM perpindahan p
     JOIN perpindahan_detail d ON d.perpindahan_id = p.id
     WHERE p.asal_tipe='supplier' AND p.asal_id = ? AND p.status IN ('diterima','selesai')
       AND YEAR(p.tanggal)=YEAR(CURDATE()) AND MONTH(p.tanggal)=MONTH(CURDATE())", [$supplierId]);

/* Penyaring daftar siap kirim. Kolom "masuk" ada di paling depan karena
   pertanyaan yang paling sering diajukan ke daftar ini adalah "apa saja
   yang tadi masuk" — bukan mencari satu barang tertentu. */
$defSiap = [
    ['nama' => 'q', 'jenis' => 'cari', 'label' => 'Cari',
     'placeholder' => 'Cari nama barang / kode / nomor batch…'],
    ['nama' => 'masuk', 'jenis' => 'periode', 'label' => 'Masuk', 'kosong' => 'Semua tanggal masuk'],
    ['nama' => 'kondisi', 'jenis' => 'pilih', 'label' => 'Kondisi', 'kosong' => 'Semua kondisi',
     'opsi' => array_map(function ($d) { return $d[0]; }, KONDISI_BARANG)],
    ['nama' => 'sumber', 'jenis' => 'pilih', 'label' => 'Asal', 'kosong' => 'Semua asal',
     'opsi' => ['pembelian' => 'Pembelian', 'donasi' => 'Donasi / titipan']],
    ['nama' => 'exp', 'jenis' => 'pilih', 'label' => 'Kedaluwarsa', 'kosong' => 'Semua kedaluwarsa',
     'opsi' => ['lewat'  => 'Sudah lewat',
                'dekat'  => 'Kurang dari 30 hari',
                'aman'   => 'Lebih dari 30 hari',
                'tanpa'  => 'Tanpa tanggal']],
];
$fSiap = filter_baca($defSiap);

/* Jumlah baris TANPA penyaring. Dipakai untuk menulis "menampilkan 3 dari
   28" — tanpa angka pembanding itu, daftar yang tersaring habis terbaca
   seperti stoknya yang hilang, bukan penyaringnya yang terlalu sempit. */
$siapBaris = (int) s1sup($pdo,
    "SELECT COUNT(*) FROM stok_supplier WHERE supplier_id = ? AND qty > 0", [$supplierId]);

$siap = [];
try {
    $wh  = ['ss.supplier_id = ?', 'ss.qty > 0'];
    $par = [$supplierId];

    if ($fSiap['q'] !== '') {
        $wh[] = "(b.nama LIKE ? ESCAPE '\\\\' OR b.kode LIKE ? ESCAPE '\\\\' OR ss.nomor_batch LIKE ? ESCAPE '\\\\')";
        $cari = '%' . like_escape($fSiap['q']) . '%';
        array_push($par, $cari, $cari, $cari);
    }
    if ($r = periode_rentang($fSiap['masuk'])) {
        $wh[] = 'ss.tanggal_masuk BETWEEN ? AND ?';
        array_push($par, $r[0], $r[1]);
    }
    if ($fSiap['kondisi'] !== '') { $wh[] = 'ss.kondisi = ?'; $par[] = $fSiap['kondisi']; }
    if ($fSiap['sumber']  !== '') { $wh[] = 'ss.sumber  = ?'; $par[] = $fSiap['sumber']; }

    // Barang tanpa tanggal kedaluwarsa TIDAK boleh ikut ke kelompok "aman":
    // NULL bukan berarti masih lama, melainkan tidak diketahui.
    switch ($fSiap['exp']) {
        case 'lewat': $wh[] = 'ss.tanggal_expired IS NOT NULL AND ss.tanggal_expired < CURDATE()'; break;
        case 'dekat': $wh[] = 'ss.tanggal_expired IS NOT NULL AND ss.tanggal_expired >= CURDATE()
                               AND ss.tanggal_expired < DATE_ADD(CURDATE(), INTERVAL 30 DAY)'; break;
        case 'aman':  $wh[] = 'ss.tanggal_expired IS NOT NULL
                               AND ss.tanggal_expired >= DATE_ADD(CURDATE(), INTERVAL 30 DAY)'; break;
        case 'tanpa': $wh[] = 'ss.tanggal_expired IS NULL'; break;
    }

    $st = $pdo->prepare(
        "SELECT ss.*, b.nama AS nama_barang, b.kode AS kode_barang, b.satuan,
                lr.kode AS kode_rak, lr.nama AS nama_rak,
                d.nama AS nama_donatur, d.anonim AS donatur_anonim, d.wilayah AS donatur_wilayah
         FROM stok_supplier ss
         JOIN barang b           ON b.id  = ss.barang_id
         LEFT JOIN lokasi_rak lr ON lr.id = ss.lokasi_rak_id
         LEFT JOIN donatur d     ON d.id  = ss.donatur_id
         WHERE " . implode(' AND ', $wh) . "
         ORDER BY (ss.tanggal_expired IS NULL), ss.tanggal_expired ASC, b.nama"
    );
    $st->execute($par);
    $siap = $st->fetchAll();
} catch (Throwable $e) { error_log('[AMANAH] ' . $e->getMessage()); }

// Gudang & rak milik supplier ini — dipakai tab "Pindah Antar Rak".
$gudangSaya = gudang_supplier($pdo);
$rakSaya    = [];
if ($gudangSaya) {
    try {
        $rakSaya = $pdo->query(
            "SELECT lr.id, lr.kode, lr.nama, g.nama AS nama_gudang
             FROM lokasi_rak lr JOIN gudang g ON g.id = lr.gudang_id
             WHERE lr.gudang_id IN (" . implode(',', array_map('intval', array_column($gudangSaya, 'id'))) . ")
               AND lr.deleted_at IS NULL ORDER BY lr.kode"
        )->fetchAll();
    } catch (Throwable $e) { error_log('[AMANAH] rak supplier: ' . $e->getMessage()); }
}

$kiriman = [];
try {
    $st = $pdo->prepare(
        "SELECT p.*, g.nama AS nama_gudang,
                (SELECT COALESCE(SUM(d.qty),0) FROM perpindahan_detail d WHERE d.perpindahan_id = p.id) AS total_qty,
                (SELECT COALESCE(SUM(COALESCE(d.qty_diterima,0)),0) FROM perpindahan_detail d WHERE d.perpindahan_id = p.id) AS total_terima,
                (SELECT COUNT(*) FROM perpindahan_detail d WHERE d.perpindahan_id = p.id) AS jml_item
         FROM perpindahan p
         LEFT JOIN gudang g ON g.id = p.tujuan_id AND p.tujuan_tipe = 'gudang'
         WHERE p.asal_tipe = 'supplier' AND p.asal_id = ?
         ORDER BY p.id DESC LIMIT 50"
    );
    $st->execute([$supplierId]);
    $kiriman = $st->fetchAll();
} catch (Throwable $e) { error_log('[AMANAH] ' . $e->getMessage()); }

$PAGE_TITLE = 'Dasbor Supplier';
$SUB_JUDUL  = 'Supplier · ' . ($supplier['nama'] ?? '');
$AKTIF      = $tab;
$MENU_LUAR  = menu_supplier(0, $pdo);
if ($awasi) {
    // Tautan menu membawa ?supplier=N agar tetap pada supplier yang diawasi.
    // Baris judul bagian (['sec' => …]) tidak punya 'h' — dilewati, kalau
    // tidak setiap pemuatan halaman memicu "Undefined array key".
    foreach ($MENU_LUAR as $i => $m) {
        if (!isset($m['h'])) { continue; }
        $pemisah = str_contains($m['h'], '?') ? '&' : '?';
        $MENU_LUAR[$i]['h'] = $m['h'] . $pemisah . 'supplier=' . $supplierId;
    }
}
require __DIR__ . '/_header_luar.php';
?>

<?php if ($awasi): ?>
  <div class="flash flash-err" style="display:flex;gap:12px;align-items:center;flex-wrap:wrap">
    <span>👁️ <b>Mode lihat-saja</b> — Anda sedang membuka dasbor <b><?= e($supplier['nama'] ?? '') ?></b>.
      Tombol tindakan disembunyikan; tindakan harus dilakukan supplier sendiri agar jejaknya benar.</span>
    <a class="btn btn-ghost btn-sm" href="pengawasan.php">← Mitra &amp; Lokasi</a>
  </div>
<?php else: ?>
  <div class="hero">
    <h1>Assalamu'alaikum, <?= e($u['nama']) ?> 👋</h1>
    <p>Catat barang yang masuk hari ini, lalu terbitkan surat jalan untuk dikirim ke gudang.
       Kode resi pada surat jalan itulah yang akan dipindai petugas gudang saat barang tiba.</p>
  </div>
<?php endif; ?>

<?php if ($errors): ?>
  <div class="flash flash-err"><?php foreach ($errors as $er) echo '• ' . e($er) . '<br>'; ?></div>
<?php endif; ?>

<div class="kpi">
  <div class="card"><div class="v"><?= angka($masukHari) ?></div><div class="l">📥 Masuk hari ini (unit)</div></div>
  <div class="card"><div class="v" style="color:var(--primary)"><?= angka($siapKirim) ?></div><div class="l">📦 Siap kirim</div></div>
  <div class="card"><div class="v" style="color:var(--amber)"><?= angka($diJalan) ?></div><div class="l">🚚 Dalam perjalanan</div></div>
  <div class="card"><div class="v" style="color:var(--green)"><?= angka($diterimaBln) ?></div><div class="l">✅ Diterima gudang (bln ini)</div></div>
</div>

<?php /* ==================================================== TAB: MASUK */ ?>
<?php if ($tab === 'masuk' && $awasi): ?>
  <div class="card">
    <h3>Barang Masuk Hari Ini</h3>
    <div class="sub">Ringkasan yang dicatat supplier hari ini</div>
    <div class="scrollx"><table class="tbl-kartu">
      <thead><tr><th>Barang</th><th>Batch</th><th>Kedaluwarsa</th><th>Jumlah</th><th>Dicatat</th></tr></thead>
      <tbody>
      <?php $hariIni = array_filter($siap, fn($s) => $s['tanggal_masuk'] === date('Y-m-d'));
      if (!$hariIni): ?>
        <tr><td colspan="5" class="empty">Belum ada barang masuk hari ini.</td></tr>
      <?php else: foreach ($hariIni as $s): ?>
        <tr><td data-label="Barang"><b><?= e($s['nama_barang']) ?></b></td>
          <td data-label="Batch"><code><?= e($s['nomor_batch']) ?></code></td>
          <td data-label="Kedaluwarsa"><?= e(tgl_id($s['tanggal_expired'])) ?></td>
          <td data-label="Jumlah"><b><?= angka($s['qty']) ?></b> <?= e($s['satuan']) ?></td>
          <td data-label="Dicatat"><?= e(tgl_id($s['tanggal_masuk'])) ?></td></tr>
      <?php endforeach; endif; ?>
      </tbody>
    </table></div>
  </div>
<?php elseif ($tab === 'masuk'): ?>
  <div class="card">
    <h3>Catat Barang Masuk Hari Ini</h3>
    <div class="sub">Berapa banyak barang yang masuk ke tempat Anda hari ini — belum dikirim ke gudang</div>

    <div data-scan data-judul="Scan atau ketik kode barang yang masuk"></div>

    <?php /* Di LUAR <form>. Kalau di dalam, "Pulihkan" dan "Buang" ikut
             terbaca sebagai tombol kirim bawaan, dan menekan Enter di kolom
             mana pun akan menyimpan barang masuk alih-alih memulihkan draf. */ ?>
    <div class="card mb16 tawaran-draf" id="tawaranDraf" hidden>
      <div>
        <b>Ada catatan barang masuk yang belum terkirim</b>
        <div class="sub" id="drafKet"></div>
      </div>
      <div class="baris">
        <button type="button" class="btn btn-primary btn-sm" id="drafPulihkan">
          <svg aria-hidden="true"><use href="#i-putar"></use></svg> Pulihkan</button>
        <button type="button" class="btn btn-ghost btn-sm" id="drafBuang">Buang</button>
      </div>
    </div>

    <form method="post" id="frmMasuk">
      <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
      <input type="hidden" name="aksi" value="barang_masuk">

      <p class="petunjuk penanda-draf" id="penandaDraf" hidden>
        <svg aria-hidden="true"><use href="#i-cek"></use></svg>
        Draf tersimpan di perangkat ini — aman kalau halaman tertutup atau Anda menekan Kembali.
      </p>

      <?php /* Asal barang dan kondisi bawaan ditentukan sekali di atas,
               lalu tiap baris boleh menyimpang. Penilaian kondisi di sini
               yang dipakai gudang — gudang tidak menilai ulang. */ ?>
      <div class="form-grid">
        <div class="field">
          <label>Asal barang <span class="req">*</span></label>
          <div style="display:flex;gap:16px;padding:8px 0">
            <label style="display:flex;align-items:center;gap:7px;cursor:pointer;font-weight:500">
              <input type="radio" name="sumber" value="pembelian" checked onchange="ubahSumber()"> Pembelian
            </label>
            <label style="display:flex;align-items:center;gap:7px;cursor:pointer;font-weight:500">
              <input type="radio" name="sumber" value="donasi" onchange="ubahSumber()"> Donasi / titipan
            </label>
          </div>
        </div>
        <div class="field">
          <label>Kondisi bawaan</label>
          <select id="kondisiBawaan">
            <?php foreach (kondisi_tawaran() as $nilai => $def): ?>
              <option value="<?= e($nilai) ?>"><?= e($def[0]) ?></option>
            <?php endforeach; ?>
          </select>
          <small class="mut">Dipakai untuk baris baru; tiap baris tetap bisa diubah sendiri.</small>
        </div>
      </div>

      <?php /* Data donatur DIKETIK, bukan dipilih dari daftar. Yang terjadi
               di lapangan: orang datang membawa titipan, dan mendaftarkannya
               lebih dulu di layar lain hanya menambah langkah. Sistem yang
               memutuskan ia donatur lama atau baru — lihat
               donatur_cocok_atau_buat() di _lib.php. */ ?>
      <?php /* Yang WAJIB hanya dua: nama dan nomor HP. Sisanya dilipat ke
               dalam <details> — wilayah, cara diterima, dan catatan jarang
               diisi, tapi kalau semuanya dibentang sekaligus, isian yang
               benar-benar menentukan jadi tenggelam di antara yang opsional
               dan formulirnya memanjang ke bawah layar. */ ?>
      <div id="blokDonatur" class="kotak-donatur" style="display:none">
        <div class="form-grid">
          <div class="field">
            <label>Nama donatur <span class="req">*</span></label>
            <input name="donatur_nama" id="donaturNama" maxlength="150" list="daftarDonatur"
                   placeholder="nama perorangan / komunitas" autocomplete="off"
                   oninput="isiHpDariSaran()" onchange="isiHpDariSaran()">
          </div>
          <div class="field">
            <label>Nomor HP <span class="req">*</span></label>
            <input name="donatur_telepon" id="donaturHp" maxlength="30" placeholder="08xx"
                   inputmode="tel" autocomplete="off">
          </div>
        </div>

        <datalist id="daftarDonatur">
          <?php foreach ($donaturList as $d): ?>
            <option value="<?= e($d['nama']) ?>"><?= e($d['telepon'] ?: '') ?></option>
          <?php endforeach; ?>
        </datalist>

        <p class="petunjuk" style="margin:-6px 0 12px">
          Nama <b>dan</b> nomor yang sama dianggap donatur yang itu juga — tidak dibuatkan
          kode baru. Format nomor bebas.
        </p>

        <div class="form-grid">
          <div class="field">
            <label>Jenis donatur</label>
            <select name="donatur_jenis">
              <option value="pribadi">Pribadi (perorangan)</option>
              <option value="komunitas">Komunitas / kelompok</option>
              <option value="lembaga">Lembaga / perusahaan</option>
            </select>
          </div>
          <div class="field">
            <label class="baris" style="font-weight:500;color:var(--text);margin-top:26px">
              <input type="checkbox" name="donatur_anonim" value="1">
              Minta tidak disebut namanya
            </label>
          </div>
        </div>

        <?php /* Jenis donasi di depan, bukan di dalam rincian tambahan:
                 bentuk pemberian menentukan penanganan barangnya nanti,
                 jadi ia bukan keterangan sampingan. */ ?>
        <div class="field">
          <label>Jenis donasi</label>
          <select name="jenis_donasi" id="jenisDonasiSup" onchange="ubahJenisDonasiSup()">
            <?php foreach (JENIS_DONASI as $k => $v): ?>
              <option value="<?= e($k) ?>"><?= e($v[0]) ?></option>
            <?php endforeach; ?>
          </select>
          <small class="mut" id="ketJenisDonasiSup"><?= e(JENIS_DONASI['umum'][2]) ?></small>
        </div>

        <details class="rinci-donatur">
          <summary>Rincian tambahan — wilayah, cara diterima, catatan</summary>
          <div class="form-grid" style="margin-top:12px">
            <div class="field">
              <label>Wilayah</label>
              <input name="donatur_wilayah" maxlength="100" placeholder="kota / kecamatan">
            </div>
            <div class="field">
              <label>Cara diterima</label>
              <select name="cara_terima">
                <option value="diantar">Diantar donatur</option>
                <option value="dijemput">Dijemput petugas</option>
                <option value="dikirim">Dikirim ekspedisi</option>
              </select>
            </div>
          </div>
          <div class="field" style="margin-bottom:4px">
            <label>Catatan titipan</label>
            <input name="catatan_donasi" maxlength="500" placeholder="mis. permintaan khusus donatur">
          </div>
        </details>

        <p class="petunjuk" style="margin:10px 0 0">
          Data pribadi — jangan bagikan tangkapan layar halaman ini ke grup terbuka.
        </p>
      </div>

      <div class="scrollx">
        <table class="tbl-kartu">
          <thead><tr>
            <th style="min-width:230px">Barang <span class="req">*</span></th>
            <th style="width:110px">Jumlah <span class="req">*</span></th>
            <th style="width:170px">Kondisi <span class="req">*</span></th>
            <th style="width:140px">No. Batch</th>
            <th style="width:150px">Kedaluwarsa</th>
            <th style="width:130px">Harga/unit</th>
            <th style="width:44px"></th>
          </tr></thead>
          <tbody id="tbMasuk"></tbody>
        </table>
      </div>
      <div style="display:flex;gap:8px;margin-top:12px;flex-wrap:wrap">
        <button type="button" class="btn btn-ghost btn-sm" onclick="tambahBaris()"><svg aria-hidden="true"><use href="#i-plus"></use></svg> Tambah Baris</button>
        <button class="btn btn-primary" type="submit"><svg aria-hidden="true"><use href="#i-simpan"></use></svg> Simpan Barang Masuk</button>
      </div>
      <p style="color:var(--muted);font-size:12px;margin-top:12px;line-height:1.7">
        Nomor batch dan tanggal kedaluwarsa penting untuk susu formula dan makanan bayi —
        sistem memakainya agar barang yang lebih dulu kedaluwarsa disalurkan lebih dulu.<br>
        <b>Kondisi yang Anda pilih di sini dipakai apa adanya oleh gudang.</b> Gudang hanya
        menghitung ulang jumlahnya, tidak menilai ulang kondisinya — jadi barang yang ditandai
        <b>tidak layak pakai</b> tidak akan pernah ikut dihitung sebagai stok siap salur.
      </p>
    </form>
  </div>
<?php endif; ?>

<?php /* ===================================================== TAB: SIAP */ ?>
<?php if ($tab === 'siap' && $awasi): ?>
  <div class="card">
    <h3>Barang Siap Kirim</h3>
    <div class="sub">Belum dikirim ke gudang · <?= count($siap) ?> baris</div>
    <div class="scrollx"><table class="tbl-kartu">
      <thead><tr><th>Barang</th><th>Batch</th><th>Kondisi</th><th>Kedaluwarsa</th><th>Tersedia</th></tr></thead>
      <tbody>
      <?php if (!$siap): ?>
        <tr><td colspan="5" class="empty">Tidak ada barang siap kirim.</td></tr>
      <?php else: foreach ($siap as $s):
          [$lblK, $wrnK] = label_kondisi($s['kondisi'] ?? 'baru');
          [$lblS, $wrnS] = label_sumber($s['sumber'] ?? 'pembelian'); ?>
        <tr><td data-label="Barang"><b><?= e($s['nama_barang']) ?></b><br>
            <small class="mut"><?= e($s['kode_barang']) ?></small></td>
          <td data-label="Batch"><code><?= e($s['nomor_batch']) ?></code></td>
          <td data-label="Kondisi"><span class="tag <?= $wrnK ?>"><?= e($lblK) ?></span>
            <br><span class="tag <?= $wrnS ?>"><?= e($lblS) ?></span></td>
          <td data-label="Kedaluwarsa"><?= e(tgl_id($s['tanggal_expired'])) ?></td>
          <td data-label="Tersedia"><b><?= angka($s['qty']) ?></b> <?= e($s['satuan']) ?></td></tr>
      <?php endforeach; endif; ?>
      </tbody>
    </table></div>
  </div>
<?php elseif ($tab === 'siap'): ?>
  <?php /* Bilah penyaring berdiri di KARTU SENDIRI, di luar form POST di
           bawahnya. Form GET tidak boleh bersarang di dalam form POST —
           peramban mengabaikan yang di dalam, dan tombol Filter akhirnya
           ikut mengirim form penerbitan surat jalan. */ ?>
  <div class="card" style="padding-bottom:2px">
    <?php filter_bar($defSiap, $fSiap, ['tab' => 'siap'], count($siap)); ?>
  </div>

  <form method="post">
    <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
    <input type="hidden" name="aksi" value="buat_sj">

    <div class="card">
      <h3>Barang Siap Kirim</h3>
      <div class="sub">
        <?php if (filter_ada($fSiap)): ?>
          Menampilkan <?= angka(count($siap)) ?> dari <?= angka($siapBaris) ?> baris
        <?php else: ?>
          Centang barang yang akan dikirim, isi jumlahnya, lalu terbitkan surat jalan
        <?php endif; ?>
      </div>

      <div class="scrollx"><table class="tbl-kartu">
        <thead><tr>
          <th style="width:36px"><input type="checkbox" onclick="centangSemua(this)"></th>
          <th>Barang</th><th>Batch</th><th>Kondisi</th><th>Kedaluwarsa</th><th>Tersedia</th>
          <th style="width:120px">Jumlah Kirim</th><th style="width:44px"></th>
        </tr></thead>
        <tbody>
        <?php if (!$siap): ?>
          <?php /* Dua keadaan kosong yang artinya berbeda. Kalau penyaring
                   sedang aktif, barangnya ADA — hanya tidak cocok; menyuruh
                   orang "catat barang masuk dulu" di situ menyesatkan, dan
                   yang dibutuhkannya justru jalan keluar dari penyaringnya. */ ?>
          <tr><td colspan="8" class="empty">
            <?php if (filter_ada($fSiap)): ?>
              Tidak ada barang yang cocok dengan penyaring ini.
              Ada <?= angka($siapBaris) ?> baris siap kirim seluruhnya —
              <a href="<?= e(filter_url([], ['tab' => 'siap'])) ?>" style="text-decoration:underline">tampilkan semua</a>.
            <?php else: ?>
              Belum ada barang siap kirim.
              <a href="dasbor_supplier.php?tab=masuk" style="text-decoration:underline">Catat barang masuk dulu</a>.
            <?php endif; ?>
          </td></tr>
        <?php else: foreach ($siap as $s):
            $sisaHari = $s['tanggal_expired']
                ? (int) floor((strtotime($s['tanggal_expired']) - strtotime(date('Y-m-d'))) / 86400) : null; ?>
          <tr>
            <td><input type="checkbox" class="pilih" onchange="sinkron(this)"></td>
            <td data-label="Barang"><b><?= e($s['nama_barang']) ?></b><br>
              <small class="mut"><?= e($s['kode_barang']) ?></small></td>
            <td data-label="Batch"><code><?= e($s['nomor_batch']) ?></code></td>
            <?php /* Kondisi ikut ditampilkan supaya penilaian yang tadi
                     diberikan bisa diperiksa lagi sebelum barang dikirim —
                     dan supaya dua batch barang yang sama dengan kondisi
                     berbeda tidak terlihat sebagai baris kembar. */ ?>
            <?php [$lblK, $wrnK] = label_kondisi($s['kondisi'] ?? 'baru');
                  [$lblS, $wrnS] = label_sumber($s['sumber'] ?? 'pembelian'); ?>
            <td data-label="Kondisi">
              <span class="tag <?= $wrnK ?>"><?= e($lblK) ?></span>
              <br><span class="tag <?= $wrnS ?>"><?= e($lblS) ?></span>
              <?php if (!kondisi_boleh_salur($s['kondisi'] ?? 'baru')): ?>
                <br><small class="mut2">tidak dihitung sebagai stok siap salur</small>
              <?php endif; ?>
            </td>
            <td data-label="Kedaluwarsa">
              <?= e(tgl_id($s['tanggal_expired'])) ?>
              <?php if ($sisaHari !== null && $sisaHari <= 90): ?>
                <br><span class="tag <?= $sisaHari < 0 ? 't-red' : ($sisaHari <= 30 ? 't-orange' : 't-amber') ?>">
                  <?= $sisaHari < 0 ? 'lewat' : $sisaHari . ' hari' ?></span>
              <?php endif; ?>
            </td>
            <td data-label="Tersedia"><b><?= angka($s['qty']) ?></b> <?= e($s['satuan']) ?></td>
            <td data-label="Jumlah Kirim"><input type="number" name="kirim[<?= (int) $s['id'] ?>]" min="0" max="<?= (int) $s['qty'] ?>"
                       value="0" disabled
                       style="width:100%;padding:8px;border:1px solid var(--border);border-radius:8px;
                              background:var(--surface-2);color:var(--text)"></td>
            <td>
              <button type="submit" form="frmHapus<?= (int) $s['id'] ?>" class="btn btn-red btn-sm"><svg aria-hidden="true"><use href="#i-x"></use></svg> </button>
            </td>
          </tr>
        <?php endforeach; endif; ?>
        </tbody>
      </table></div>
    </div>

    <?php if ($siap): ?>
    <div class="card">
      <h3>Data Pengiriman</h3>
      <div class="sub">Akan tercetak pada surat jalan</div>

      <?php /* Barang mitra tidak selalu lewat gudang. Kunjungan ke lokasi
               penerima dan paket yang diantar sendiri berakhir langsung di
               tangan penerima amanah — dan sebelum ini kejadian itu tidak
               punya tempat di sistem, jadi dicatat seolah lewat gudang
               (padahal tidak pernah sampai) atau tidak dicatat sama sekali. */ ?>
      <div class="field">
        <label>Barang ini diserahkan ke <span class="req">*</span></label>
        <div class="baris" style="gap:18px;padding:6px 0">
          <label class="baris" style="font-weight:500;color:var(--text);gap:7px">
            <input type="radio" name="tujuan_ke" value="gudang" checked onchange="ubahTujuanKirim()">
            Gudang lembaga
          </label>
          <label class="baris" style="font-weight:500;color:var(--text);gap:7px">
            <input type="radio" name="tujuan_ke" value="penerima" onchange="ubahTujuanKirim()">
            Langsung ke penerima amanah
          </label>
        </div>
        <small class="mut" id="ketTujuanKirim">Surat jalan terbit seketika dan stok Anda langsung
          berkurang; gudang memverifikasi fisiknya saat barang tiba.</small>
      </div>

      <div class="form-grid">
        <div class="field" id="kotakGudangTujuan"><label>Gudang Tujuan <span class="req">*</span></label>
          <select name="gudang_id" id="selGudangTujuan" required>
            <option value="">— pilih gudang —</option>
            <?php foreach ($gudangList as $g): ?>
              <option value="<?= (int) $g['id'] ?>"><?= e($g['nama']) ?></option>
            <?php endforeach; ?>
          </select></div>

        <div class="field hide" id="kotakPenerima"><label>Penerima Amanah <span class="req">*</span></label>
          <select name="penerima_id" id="selPenerima">
            <option value="">— pilih penerima —</option>
            <?php foreach ($penerimaList as $pn): ?>
              <option value="<?= (int) $pn['id'] ?>"><?= e($pn['nama']) ?><?= $pn['wilayah'] ? ' · ' . e($pn['wilayah']) : '' ?></option>
            <?php endforeach; ?>
          </select>
          <small class="petunjuk">Menunggu persetujuan Manager. Stok Anda belum berkurang
            sampai disetujui.</small></div>
        <div class="field"><label>Ekspedisi</label>
          <input name="ekspedisi" placeholder="cth: JNE, J&T, kurir sendiri"></div>
        <div class="field"><label>Nomor Resi Ekspedisi</label>
          <input name="no_resi" placeholder="Kosongkan bila memakai kurir sendiri"
                 style="font-family:'Courier New',monospace">
          <small style="color:var(--muted);font-size:11.5px">
            Bila dikosongkan, sistem memakai nomor surat jalan sebagai kode resi untuk dipindai gudang.
          </small></div>
        <div class="field"><label>Kendaraan / Nomor Polisi</label>
          <div class="baris">
            <input name="kendaraan" placeholder="Mobil box">
            <input name="no_polisi" placeholder="B 1234 XYZ">
          </div></div>
        <?php /* Tanggal dokumen — aturan yang sama dengan formulir
                 Perpindahan dan distribusi toko, lewat satu fungsi
                 bersama di _lib.php. Mitra sering menerbitkan surat jalan
                 menyusul, sehari setelah barangnya benar-benar dikirim. */ ?>
        <div class="field"><label>Tanggal Dokumen</label>
          <div class="pilih-tanggal">
            <button type="button" class="btn btn-ghost btn-sm"
                    data-tgl="<?= date('Y-m-d') ?>">Hari ini</button>
            <button type="button" class="btn btn-ghost btn-sm"
                    data-tgl="<?= date('Y-m-d', strtotime('-1 day')) ?>">Kemarin</button>
            <input type="date" name="tanggal"
                   value="<?= date('Y-m-d') ?>">
          </div>
          <small class="petunjuk">Bebas, kapan saja.</small></div>

        <div class="field span-all"><label>Catatan untuk Petugas Gudang</label>
          <textarea name="catatan" rows="2" placeholder="opsional — cth: 3 dus, mohon dicek segelnya"></textarea></div>
      </div>
      <button class="btn btn-primary" type="submit"><svg aria-hidden="true"><use href="#i-doc"></use></svg> Terbitkan Surat Jalan &amp; Kode Resi</button>
      <p style="color:var(--muted);font-size:12px;margin-top:12px;line-height:1.7">
        Surat jalan terbit seketika tanpa menunggu persetujuan. Stok gudang baru bertambah
        setelah petugas gudang memindai resinya dan memverifikasi jumlah fisiknya.
      </p>
    </div>
    <?php endif; ?>
  </form>

  <?php foreach ($siap as $s): ?>
    <form method="post" id="frmHapus<?= (int) $s['id'] ?>" class="hide" data-konfirm="Hapus baris ini dari daftar siap kirim?">
      <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
      <input type="hidden" name="aksi" value="hapus_siap">
      <input type="hidden" name="id" value="<?= (int) $s['id'] ?>">
    </form>
  <?php endforeach; ?>
<?php endif; ?>

<?php /* ================================================== TAB: RIWAYAT */ ?>
<?php /* ------------------------------------------- PINDAH ANTAR RAK
         Pemindahan di dalam gudang supplier sendiri. Bukan peristiwa rantai,
         jadi tanpa surat jalan dan tanpa persetujuan — yang berubah hanya
         lokasi barangnya. */ ?>
<?php if ($tab === 'rak'): ?>
  <?php if (!$gudangSaya): ?>
    <div class="card"><div class="empty-state">
      <div class="ic"><svg aria-hidden="true"><use href="#i-gudang"></use></svg></div>
      <b>Gudang Anda belum tersedia</b>
      <p>Hubungi Super Admin untuk menyiapkan gudang supplier beserta raknya.</p>
    </div></div>
  <?php else: ?>
    <div class="alert" style="--ac:var(--accent);margin-bottom:18px">
      <span class="alert-ic"><svg aria-hidden="true"><use href="#i-putar"></use></svg></span>
      <div class="alert-tx">
        <b><?= e(implode(', ', array_column($gudangSaya, 'nama'))) ?></b>
        <small><?= count($rakSaya) ?> rak. Memindahkan barang antar rak di sini tidak
          menerbitkan surat jalan dan tidak menunggu persetujuan — barangnya belum
          keluar dari gudang Anda.</small>
      </div>
    </div>

    <div class="card">
      <h3>Isi Gudang Anda</h3>
      <div class="sub">Pindahkan barang ke rak lain bila perlu</div>
      <div class="scrollx"><table class="tbl-kartu">
        <thead><tr><th>Barang</th><th>Batch</th><th>Expired</th><th>Qty</th>
          <th>Rak Sekarang</th><th>Pindah ke</th></tr></thead>
        <tbody>
        <?php if (!$siap): ?>
          <tr><td colspan="6" class="empty">Belum ada barang di gudang Anda.</td></tr>
        <?php else: foreach ($siap as $s): ?>
          <tr>
            <td class="judul" data-label="Barang"><b><?= e($s['nama_barang']) ?></b>
              <br><small class="mut"><?= e($s['kode_barang']) ?></small></td>
            <td class="sekunder" data-label="Batch"><code><?= e($s['nomor_batch']) ?></code></td>
            <td data-label="Expired"><?= e($s['tanggal_expired'] ? tgl_id($s['tanggal_expired']) : '—') ?></td>
            <td data-label="Qty"><?= angka($s['qty']) ?> <?= e($s['satuan']) ?></td>
            <td data-label="Rak Sekarang">
              <?= e($s['nama_rak'] ? $s['nama_rak'] . ' (' . $s['kode_rak'] . ')' : 'belum ditempatkan') ?>
            </td>
            <td data-label="Pindah ke">
              <?php if ($awasi): ?>
                <span class="mut-sm">—</span>
              <?php else: ?>
                <form method="post" class="baris" style="gap:6px">
                  <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                  <input type="hidden" name="aksi" value="pindah_rak">
                  <input type="hidden" name="stok_id" value="<?= (int) $s['id'] ?>">
                  <select name="rak_tujuan" required>
                    <option value="">— pilih rak —</option>
                    <?php foreach ($rakSaya as $r): ?>
                      <?php if ((int) $r['id'] === (int) $s['lokasi_rak_id']) { continue; } ?>
                      <option value="<?= (int) $r['id'] ?>"><?= e($r['nama']) ?> (<?= e($r['kode']) ?>)</option>
                    <?php endforeach; ?>
                  </select>
                  <button class="btn btn-ghost btn-sm" type="submit">Pindahkan</button>
                </form>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; endif; ?>
        </tbody>
      </table></div>
      <?php if (count($rakSaya) < 2): ?>
        <p style="color:var(--muted);font-size:12px;margin-top:12px">
          Gudang Anda baru punya <?= count($rakSaya) ?> rak, jadi belum ada tujuan untuk
          dipindahi. Minta Super Admin menambah rak lewat Master Rak.
        </p>
      <?php endif; ?>
    </div>
  <?php endif; ?>
<?php endif; ?>

<?php if ($tab === 'riwayat'): ?>
  <div class="card">
    <h3>Riwayat Pengiriman ke Gudang</h3>
    <div class="sub"><?= count($kiriman) ?> surat jalan terakhir</div>
    <div class="scrollx"><table class="tbl-kartu">
      <thead><tr>
        <th>Surat Jalan</th><th>Tanggal</th><th>Gudang</th><th>Kode Resi</th>
        <th>Dikirim</th><th>Diterima</th><th>Status</th><th></th>
      </tr></thead>
      <tbody>
      <?php if (!$kiriman): ?>
        <tr><td colspan="8" class="empty">Belum ada pengiriman.</td></tr>
      <?php else: foreach ($kiriman as $k):
        [$sLbl, $sCls] = label_status($k['status']);
        $selisih = ($k['status'] === 'diterima' || $k['status'] === 'selesai')
                 ? (int) $k['total_terima'] - (int) $k['total_qty'] : null; ?>
        <tr>
          <td data-label="Surat Jalan"><code><?= e($k['no_surat_jalan'] ?: $k['no_perpindahan']) ?></code></td>
          <td data-label="Tanggal"><?= e(tgl_jam_id($k['tanggal'])) ?></td>
          <td data-label="Gudang"><?= e($k['nama_gudang'] ?: '—') ?></td>
          <td data-label="Kode Resi"><code><?= e($k['no_resi'] ?: '—') ?></code>
            <?= $k['ekspedisi'] ? '<br><small class="mut">' . e($k['ekspedisi']) . '</small>' : '' ?></td>
          <td data-label="Dikirim"><b><?= angka($k['total_qty']) ?></b> unit<br>
            <small class="mut"><?= (int) $k['jml_item'] ?> jenis</small></td>
          <td data-label="Diterima">
            <?php if ($selisih === null): ?>—
            <?php else: ?>
              <b><?= angka($k['total_terima']) ?></b>
              <?php if ($selisih !== 0): ?>
                <br><span class="tag t-red">selisih <?= $selisih > 0 ? '+' : '' ?><?= $selisih ?></span>
              <?php endif; ?>
            <?php endif; ?>
          </td>
          <td data-label="Status"><span class="tag <?= $sCls ?>"><?= $sLbl ?></span></td>
          <td><a class="btn btn-ghost btn-sm" href="surat_jalan.php?id=<?= (int) $k['id'] ?>" target="_blank">🖨️</a></td>
        </tr>
      <?php endforeach; endif; ?>
      </tbody>
    </table></div>
  </div>
<?php endif; ?>

<script>
const BARANG = <?= json_encode(array_map(fn($b) => ['id'=>(int)$b['id'],'t'=>$b['nama'].' ('.$b['kode'].')'], $barangList), JSON_UNESCAPED_UNICODE) ?>;
/* Daftar kondisi datang dari KONDISI_BARANG di _lib.php, tidak ditulis
   ulang di JavaScript. Dua daftar yang ditulis terpisah akan berbeda pada
   perubahan berikutnya, dan yang di layar inilah yang dilihat orang. */
const KONDISI = <?= json_encode(array_map(fn($k, $v) => ['v' => $k, 't' => $v[0]],
    array_keys(kondisi_tawaran()), kondisi_tawaran()), JSON_UNESCAPED_UNICODE) ?>;
const gy = 'width:100%;padding:8px;border:1px solid var(--border);border-radius:8px;background:var(--surface-2);color:var(--text);font-family:inherit;font-size:13px';

function kondisiBawaan(){
  const el = document.getElementById('kondisiBawaan');
  return el ? el.value : 'baru';
}

/* Donatur hanya relevan bila barangnya titipan. Menyembunyikannya saat
   pembelian mencegah pertanyaan "donatur mana" muncul untuk barang yang
   memang dibeli sendiri. */
function ubahSumber(){
  const donasi = document.querySelector('input[name="sumber"]:checked')?.value === 'donasi';
  const blok = document.getElementById('blokDonatur');
  if (blok) blok.style.display = donasi ? '' : 'none';
  // Wajibnya dipasang/dilepas mengikuti pilihan: kalau tetap required saat
  // blok ini tersembunyi, formulir pembelian menolak terkirim karena isian
  // yang tidak terlihat oleh siapa pun.
  ['donaturNama', 'donaturHp'].forEach(function(id){
    const el = document.getElementById(id);
    if (el) el.required = donasi;
  });
}

/* Tujuan pengiriman menentukan isian mana yang wajib. Wajibnya dipasang &
   dilepas di sini juga — kalau tidak, isian yang sedang tersembunyi tetap
   menahan formulir dan orang mencari-cari kesalahan yang tidak terlihat. */
function ubahTujuanKirim(){
  var ke = document.querySelector('input[name="tujuan_ke"]:checked');
  var kePenerima = ke && ke.value === 'penerima';
  var kg = document.getElementById('kotakGudangTujuan');
  var kp = document.getElementById('kotakPenerima');
  var sg = document.getElementById('selGudangTujuan');
  var sp = document.getElementById('selPenerima');
  if (kg) kg.classList.toggle('hide', kePenerima);
  if (kp) kp.classList.toggle('hide', !kePenerima);
  if (sg) sg.required = !kePenerima;
  if (sp) sp.required = kePenerima;
  var k = document.getElementById('ketTujuanKirim');
  if (k) k.textContent = kePenerima
    ? 'Menunggu persetujuan Manager. Surat jalan terbit dan stok Anda berkurang setelah disetujui.'
    : 'Surat jalan terbit seketika dan stok Anda langsung berkurang; gudang memverifikasi fisiknya saat barang tiba.';
}

var KET_DONASI_SUP = <?= json_encode(array_map(function ($v) { return $v[2]; }, JENIS_DONASI)) ?>;
function ubahJenisDonasiSup(){
  var s = document.getElementById('jenisDonasiSup');
  var k = document.getElementById('ketJenisDonasiSup');
  if (s && k) k.textContent = KET_DONASI_SUP[s.value] || '';
}

/* Nomor HP ikut terisi saat nama donatur lama dipilih dari saran. Tanpa ini
   petugas mengetik ulang nomornya, dan satu digit meleset sudah cukup untuk
   membuat donatur kembar. */
function isiHpDariSaran(){
  const nama = document.getElementById('donaturNama');
  const hp   = document.getElementById('donaturHp');
  if (!nama || !hp) return;
  const opsi = document.querySelector('#daftarDonatur option[value="' +
               nama.value.replace(/"/g, '\\"') + '"]');
  if (opsi && opsi.textContent.trim() !== '' && hp.value.trim() === '') {
    hp.value = opsi.textContent.trim();
  }
}

/* Nama barang berasal dari basis data dan disisipkan sebagai HTML — harus
   dilolosi. Satu nama yang memuat < atau > sudah cukup menyuntikkan markup
   ke halaman setiap supplier yang membuka formulir ini. */
function lolos(s){
  return String(s == null ? '' : s).replace(/&/g, '&amp;').replace(/</g, '&lt;')
                  .replace(/>/g, '&gt;').replace(/"/g, '&quot;');
}
function tambahBaris(){
  const tb = document.getElementById('tbMasuk');
  if (!tb) return;
  const tr = document.createElement('tr');
  const bt = 'B' + new Date().toISOString().slice(2,10).replace(/-/g,'');
  tr.innerHTML =
    '<td><select name="barang_id[]" style="' + gy + '"><option value="">— pilih barang —</option>' +
      BARANG.map(b => '<option value="'+b.id+'">'+lolos(b.t)+'</option>').join('') + '</select></td>' +
    '<td><input name="qty[]" type="number" min="1" value="1" style="' + gy + '"></td>' +
    /* Nilai bawaannya diambil dari pilihan "Kondisi Bawaan" di atas tabel,
       supaya satu kali titipan berisi dua puluh baris tidak perlu dipilih
       satu per satu. Tiap baris tetap bisa diubah sendiri. */
    '<td><select name="kondisi[]" style="' + gy + '">' +
      KONDISI.map(k => '<option value="'+lolos(k.v)+'"'+(k.v===kondisiBawaan()?' selected':'')+'>'+lolos(k.t)+'</option>').join('') +
    '</select></td>' +
    '<td><input name="nomor_batch[]" value="'+bt+'" style="' + gy + '"></td>' +
    '<td><input name="tanggal_expired[]" type="date" style="' + gy + '"></td>' +
    '<td><input name="harga[]" type="number" step="0.01" min="0" value="0" style="' + gy + '"></td>' +
    '<td><button type="button" class="btn btn-red btn-sm" onclick="this.closest(\'tr\').remove()"><svg aria-hidden="true"><use href="#i-x"></use></svg> </button></td>';
  tb.appendChild(tr);
}

/* Kotak jumlah kirim baru aktif setelah barisnya dicentang. */
function sinkron(cb){
  const inp = cb.closest('tr').querySelector('input[type="number"]');
  inp.disabled = !cb.checked;
  if (cb.checked && Number(inp.value) === 0) inp.value = inp.max;
  if (!cb.checked) inp.value = 0;
}
function centangSemua(cb){
  document.querySelectorAll('.pilih').forEach(function(c){ c.checked = cb.checked; sinkron(c); });
}

function scanTambahBaris(barang, jumlah){
  var sel = Array.from(document.querySelectorAll('select[name="barang_id[]"]'))
                 .find(function(s){ return String(s.value) === String(barang.id); });
  if (sel){
    var q = sel.closest('tr').querySelector('input[name="qty[]"]');
    q.value = (parseInt(q.value,10) || 0) + jumlah;
    return;
  }
  tambahBaris();
  var baris = document.getElementById('tbMasuk').lastElementChild;
  baris.querySelector('select[name="barang_id[]"]').value = barang.id;
  baris.querySelector('input[name="qty[]"]').value = jumlah;
  if (barang.harga) baris.querySelector('input[name="harga[]"]').value = barang.harga;
  document.querySelectorAll('#tbMasuk tr').forEach(function(tr){
    var s2 = tr.querySelector('select[name="barang_id[]"]');
    if (s2 && !s2.value) tr.remove();
  });
}

const fm = document.getElementById('frmMasuk');
if (fm){
  fm.addEventListener('submit', function(ev){
    const ada = Array.from(document.querySelectorAll('select[name="barang_id[]"]')).some(s => s.value);
    if (!ada){ ev.preventDefault(); alert('Isi minimal satu baris barang.'); }
  });
  tambahBaris(); tambahBaris();
}
</script>
<script src="<?= e(aset('assets/draf-formulir.js')) ?>"></script>
<script>
/* ----------------------------------------------------------------------
   DRAF BARANG MASUK MITRA

   Mesinnya sama dengan formulir Barang Masuk gudang dan Surat Jalan.
   Awalan kuncinya dibedakan — mitra dan gudang bisa memakai perangkat yang
   sama (komputer kantor), dan draf titipan mitra tidak boleh ditawarkan
   di formulir gudang.

   tambahBaris() di halaman ini tidak menerima nilai apa pun; ia menambah
   baris kosong. Jadi barisnya ditambah dulu, lalu diisi.
   ---------------------------------------------------------------------- */
(function () {
  if (typeof pasangDrafFormulir !== 'function') { return; }
  if (!document.getElementById('frmMasuk')) { return; }

  var draf = pasangDrafFormulir({
    form:   'frmMasuk',
    tbody:  'tbMasuk',
    awalan: 'amanah_draf_masuk_mitra_',
    idTawaran: 'tawaranDraf', idKet: 'drafKet',
    idPulihkan: 'drafPulihkan', idBuang: 'drafBuang',
    idPenanda: 'penandaDraf',

    tambahBaris: function (b) {
      if (typeof tambahBaris !== 'function') { return; }
      tambahBaris();
      var tb = document.getElementById('tbMasuk');
      var tr = tb && tb.lastElementChild;
      if (!tr) { return; }
      ['barang_id', 'qty', 'kondisi', 'nomor_batch',
       'tanggal_expired', 'harga'].forEach(function (n) {
        var el = tr.querySelector('[name="' + n + '[]"]');
        if (el && b[n] != null && b[n] !== '') { el.value = b[n]; }
      });
    },
  });

  if (draf) { window.drafSegarkan = draf.segarkan; }
})();
</script>
<?php require __DIR__ . '/_footer_luar.php'; ?>
