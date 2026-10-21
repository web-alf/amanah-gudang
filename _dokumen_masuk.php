<?php
/**
 * AMANAH — Daftar dokumen Barang Masuk (pengambil data + tabelnya)
 *
 * Dipakai dua halaman:
 *   - masuk.php          → "Penerimaan Terakhir", 6 dokumen terbaru
 *   - masuk_riwayat.php  → seluruh dokumen, bisa dicari dan disaring
 *
 * Tabel dan rinciannya dulu tinggal di masuk.php. Ketika daftar lengkapnya
 * diminta (1 Okt 2026), menyalinnya berarti dua tabel yang harus selalu
 * diubah bersamaan — kolom baru di satu tempat akan tertinggal di tempat
 * lain. Sekarang keduanya memanggil dua fungsi di bawah ini.
 */

/**
 * Batas lingkup: akun yang terikat gudang hanya melihat dokumen yang
 * barangnya masuk ke rak gudangnya, atau yang ia catat sendiri. Daftar
 * lengkap bisa dicari — tanpa batas ini admin Gudang A bisa menelusuri
 * seluruh penerimaan Gudang B.
 */
function dokumen_masuk_lingkup(PDO $pdo): string {
    if (!terikat_satu_gudang()) { return ''; }
    return ' AND (tm.user_id = ' . (int) ($_SESSION['user_id'] ?? 0) . '
               OR EXISTS (SELECT 1 FROM transaksi_masuk_detail dx
                          WHERE dx.transaksi_masuk_id = tm.id
                            AND dx.lokasi_rak_id IN (' . in_rak_saya($pdo) . ')))';
}

/**
 * Ambil dokumen berikut rinciannya.
 *
 * @param string $syarat  potongan WHERE tambahan (alias: tm, sp), mis. "tm.tanggal >= ?"
 * @return array{0: array, 1: array}  [dokumen, rincian per id dokumen]
 */
/**
 * Potongan SELECT & JOIN untuk jenis donasi sebuah penerimaan (butir 1 revisi
 * tim gudang). Sebelum migrasinya dijalankan di server, kolom donasi_id belum
 * ada — halaman tetap jalan, jenisnya saja tampil kosong.
 *
 * `asal` = 'donasi' bila ada barisnya yang batchnya berasal dari donasi,
 * selain itu 'pembelian'. Dibaca dari batch, bukan dari ada/tidaknya catatan
 * donasi: penerimaan lama yang catatan donasinya tidak tersambung tetap
 * dikenali sebagai donasi ("Belum diklasifikasi"), bukan dikira pembelian.
 */
function dokumen_masuk_sql_jenis(PDO $pdo): array {
    $asal = "(CASE WHEN EXISTS (SELECT 1 FROM transaksi_masuk_detail da JOIN batch_barang ba ON ba.id = da.batch_id
                                 WHERE da.transaksi_masuk_id = tm.id AND ba.sumber = 'donasi')
                   THEN 'donasi' ELSE 'pembelian' END) AS asal";
    if (!kolom_ada($pdo, 'transaksi_masuk', 'donasi_id')) {
        return [", NULL AS donasi_id, NULL AS jenis_donasi, NULL AS donatur_nama, $asal", ''];
    }
    return [", tm.donasi_id, dj.jenis AS jenis_donasi, dnr.nama AS donatur_nama, $asal",
            ' LEFT JOIN donasi dj ON dj.id = tm.donasi_id LEFT JOIN donatur dnr ON dnr.id = dj.donatur_id'];
}

/* ---------------------------------------------------------------------------
   PENYARING GUDANG — revisi tim gudang, Oktober 2026 (butir 7)

   Penerimaan tidak menyimpan gudang; gudangnya dibaca dari RAK tiap baris
   (transaksi_masuk_detail.lokasi_rak_id → lokasi_rak.gudang_id). Satu
   dokumen boleh berisi baris ke rak di gudang berbeda, jadi penyaring ini
   bekerja per baris: dokumen tampil bila ada barisnya di gudang itu, dan
   angka unit/nilainya hanya menjumlahkan baris gudang itu.
   --------------------------------------------------------------------------- */

/** Pilihan gudang untuk penyaring: [id => nama], dari master, bukan ditulis tangan. */
function gudang_opsi_filter(PDO $pdo): array {
    $h = [];
    try {
        foreach ($pdo->query("SELECT id, nama FROM gudang WHERE deleted_at IS NULL AND is_active = 1 ORDER BY nama")->fetchAll() as $g) {
            $h[(string) $g['id']] = $g['nama'];
        }
    } catch (Throwable $e) { error_log('[AMANAH] gudang_opsi_filter: ' . $e->getMessage()); }
    return $h;
}

/** Klausa "rak milik gudang N" untuk kolom lokasi_rak_id di tabel beralias $a. */
function sql_rak_gudang(string $a, int $gudangId): string {
    return "$a.lokasi_rak_id IN (SELECT lr.id FROM lokasi_rak lr WHERE lr.gudang_id = " . (int) $gudangId . " AND lr.deleted_at IS NULL)";
}

function dokumen_masuk_ambil(PDO $pdo, string $syarat = '1=1', array $par = [],
                             int $limit = 6, int $offset = 0, int $gudangId = 0): array {
    $limit = max(1, $limit); $offset = max(0, $offset);
    [$kolomJenis, $joinJenis] = dokumen_masuk_sql_jenis($pdo);
    /* Dengan penyaring gudang, unit & nilai dihitung dari baris gudang itu
       saja — bukan tm.total yang mencakup seluruh dokumen. */
    $hanyaGudang = $gudangId > 0 ? ' AND ' . sql_rak_gudang('d', $gudangId) : '';
    $kolomTotal  = $gudangId > 0
        ? "(SELECT COALESCE(SUM(d.subtotal),0) FROM transaksi_masuk_detail d WHERE d.transaksi_masuk_id = tm.id$hanyaGudang) AS total"
        : 'tm.total';
    if ($gudangId > 0) { $syarat .= ' AND EXISTS (SELECT 1 FROM transaksi_masuk_detail dg WHERE dg.transaksi_masuk_id = tm.id AND ' . sql_rak_gudang('dg', $gudangId) . ')'; }
    $st = $pdo->prepare(
        "SELECT tm.id, tm.no_transaksi, tm.tanggal, $kolomTotal, tm.catatan, sp.nama AS supplier,
                u.nama AS petugas$kolomJenis,
                (SELECT COUNT(*)          FROM transaksi_masuk_detail d WHERE d.transaksi_masuk_id = tm.id$hanyaGudang) AS jenis,
                (SELECT COALESCE(SUM(d.qty),0) FROM transaksi_masuk_detail d WHERE d.transaksi_masuk_id = tm.id$hanyaGudang) AS qty
         FROM transaksi_masuk tm
         LEFT JOIN supplier sp ON sp.id = tm.supplier_id
         LEFT JOIN users u     ON u.id  = tm.user_id$joinJenis
         WHERE $syarat" . dokumen_masuk_lingkup($pdo) . "
         ORDER BY tm.id DESC LIMIT $limit OFFSET $offset");
    $st->execute($par);
    $dok = $st->fetchAll();

    /* Rincian SELURUH dokumen di halaman diambil sekali, lalu dikelompokkan
       di PHP. Satu kueri per dokumen berarti tujuh perjalanan ke basis data
       untuk satu halaman, dan itu tumbuh mengikuti jumlah barisnya.

       Kondisi dan donatur ikut dibaca dari batch-nya: itulah yang paling
       sering ditanyakan tentang barang titipan — dari siapa, dan layak
       pakai atau tidak. */
    $rinci = [];
    if ($dok) {
        $ids = implode(',', array_map(function ($t) { return (int) $t['id']; }, $dok));
        $rinci = kelompokkan($pdo->query(
            "SELECT d.transaksi_masuk_id, d.qty, d.harga, d.subtotal, d.nomor_batch, d.lokasi_rak_id,
                    d.tanggal_expired, b.nama AS nama_barang, b.kode AS kode_barang, b.satuan,
                    lr.kode AS kode_rak, g.nama AS nama_gudang,
                    bb.kondisi, bb.sumber, dn.nama AS nama_donatur, dn.anonim AS donatur_anonim,
                    dn.wilayah AS donatur_wilayah
             FROM transaksi_masuk_detail d
             JOIN barang b            ON b.id  = d.barang_id
             LEFT JOIN batch_barang bb ON bb.id = d.batch_id
             LEFT JOIN donatur dn      ON dn.id = bb.donatur_id
             LEFT JOIN lokasi_rak lr   ON lr.id = d.lokasi_rak_id
             LEFT JOIN gudang g        ON g.id  = lr.gudang_id
             WHERE d.transaksi_masuk_id IN ($ids)
             ORDER BY d.id")->fetchAll(), 'transaksi_masuk_id');
    }
    return [$dok, $rinci];
}

/** Jumlah dokumen yang cocok — untuk paginasi. */
function dokumen_masuk_hitung(PDO $pdo, string $syarat = '1=1', array $par = [], int $gudangId = 0): int {
    if ($gudangId > 0) { $syarat .= ' AND EXISTS (SELECT 1 FROM transaksi_masuk_detail dg WHERE dg.transaksi_masuk_id = tm.id AND ' . sql_rak_gudang('dg', $gudangId) . ')'; }
    $st = $pdo->prepare(
        "SELECT COUNT(*) FROM transaksi_masuk tm
         LEFT JOIN supplier sp ON sp.id = tm.supplier_id
         WHERE $syarat" . dokumen_masuk_lingkup($pdo));
    $st->execute($par);
    return (int) $st->fetchColumn();
}

/**
 * Boleh/tidaknya akun ini mengubah satu dokumen masuk.
 *
 * Sama dengan mengubah penyesuaian: tingkat Manager, dan — untuk akun yang
 * terikat gudang — SELURUH baris dokumennya harus masuk ke rak gudangnya.
 * Dokumen yang sebagian barisnya di gudang lain tidak boleh diubah separuh.
 *
 * @param array $barisRak  daftar lokasi_rak_id tiap baris dokumen
 */
function dokumen_masuk_boleh_ubah(PDO $pdo, array $barisRak): bool {
    if (is_super()) { return true; }
    if (!boleh_kelola_master($pdo)) { return false; }
    if (!terikat_satu_gudang()) { return true; }
    if (!$barisRak) { return false; }
    $rakSaya = rak_saya($pdo);
    foreach ($barisRak as $rak) {
        if (!in_array((int) $rak, $rakSaya, true)) { return false; }
    }
    return true;
}

/**
 * Ubah satu dokumen Barang Masuk — dan SEMUA yang bersumber darinya.
 *
 * Dokumen masuk bukan catatan yang berdiri sendiri. Satu barisnya menulis
 * ke empat tempat sekaligus saat disimpan (lihat masuk.php):
 *   1. transaksi_masuk_detail  — jumlah, harga, subtotal
 *   2. batch_barang            — qty_awal dan qty_tersisa (stok yang berlaku)
 *   3. stok                    — ringkasan stok gudang per barang
 *   4. donasi_detail           — bila barangnya titipan donatur
 * ditambah total dokumennya. Mengubah angka di dokumen saja membuat keempat
 * tempat itu saling bertentangan; karena itu selisihnya diteruskan ke
 * semuanya di dalam satu transaksi, atau tidak sama sekali.
 *
 * ATURAN
 * - Jumlah turun hanya sejauh stok batch masih ada. Barang yang sudah
 *   keluar atau dipindahkan tidak bisa "dibatalkan masuknya" dari sini.
 * - Jumlah 0 = baris itu dihapus. Semua baris 0 = dokumennya dihapus.
 * - Baris yang jumlah lamanya di atas MAKS_QTY_BARIS (barcode tertembak ke
 *   kotak Jumlah) diperlakukan sebagai salah tulis: dokumen dan qty_awal
 *   dibetulkan, tetapi stok yang berlaku TIDAK disentuh — stok itu sudah
 *   dibetulkan lewat penyesuaian, dan menerapkan selisih miliaran akan
 *   merusaknya lagi.
 * - Harga batch ikut berubah hanya bila harga batch itu memang berasal dari
 *   baris ini (sama dengan harga lamanya, atau masih nol). Batch yang
 *   digabung dari beberapa penerimaan mempertahankan harganya.
 * - Catatan donasi tidak punya tautan langsung ke dokumen masuk; ia dicari
 *   dari jejak yang ditinggalkan transaksi yang sama (petugas dan detik
 *   pembuatan yang sama). Bila tidak ketemu, donasinya dibiarkan dan hal
 *   itu dilaporkan — bukan ditebak.
 *
 * Dipanggil DI DALAM transaksi pemanggil.
 *
 * @param array $isian  [detail_id => ['qty' => int, 'harga' => float]]
 * @return array{no:string, ubah:string[], dihapus:bool, catatan:string[]}
 */
function ubah_dokumen_masuk(PDO $pdo, int $trxId, ?int $supplierId, string $catatan, array $isian): array {
    $st = $pdo->prepare("SELECT barang_id FROM transaksi_masuk_detail WHERE transaksi_masuk_id = ?");
    $st->execute([$trxId]);
    kunci_stok($pdo, $st->fetchAll(PDO::FETCH_COLUMN));

    $st = $pdo->prepare("SELECT * FROM transaksi_masuk WHERE id = ? FOR UPDATE");
    $st->execute([$trxId]);
    $tm = $st->fetch();
    if (!$tm) { throw new RuntimeException('Dokumen itu sudah tidak ada.'); }

    $st = $pdo->prepare(
        "SELECT d.*, b.nama AS nama_barang
         FROM transaksi_masuk_detail d JOIN barang b ON b.id = d.barang_id
         WHERE d.transaksi_masuk_id = ? ORDER BY d.id FOR UPDATE");
    $st->execute([$trxId]);
    $detail = $st->fetchAll();
    if (!dokumen_masuk_boleh_ubah($pdo, array_column($detail, 'lokasi_rak_id'))) {
        throw new RuntimeException('Mengubah dokumen ini di luar wewenang akun Anda.');
    }

    if ($supplierId !== null) {
        $cek = $pdo->prepare("SELECT 1 FROM supplier WHERE id = ? AND deleted_at IS NULL");
        $cek->execute([$supplierId]);
        if (!$cek->fetchColumn()) { throw new RuntimeException('Supplier yang dipilih tidak dikenali.'); }
    }
    $catatan = mb_substr(trim($catatan), 0, 255);

    /* Donasi yang lahir dari transaksi yang sama. Sambungan langsung
       (kolom donasi_id, migrasi Okt 2026) didahulukan; tebakan lewat
       detik pembuatan hanya untuk penerimaan lama yang belum tersambung. */
    $donasiId = null;
    if (kolom_ada($pdo, 'transaksi_masuk', 'donasi_id') && !empty($tm['donasi_id'])) {
        $donasiId = (int) $tm['donasi_id'];
    }
    $stDn = $pdo->prepare(
        "SELECT id FROM donasi
         WHERE dibuat_oleh <=> ? AND ABS(TIMESTAMPDIFF(SECOND, created_at, ?)) <= 5
         ORDER BY ABS(TIMESTAMPDIFF(SECOND, created_at, ?)), id LIMIT 1");
    $stDn->execute([$tm['user_id'], $tm['created_at'], $tm['created_at']]);
    if (!$donasiId) { $donasiId = ((int) $stDn->fetchColumn()) ?: null; }
    if ($donasiId) {
        // Hanya dipakai bila dokumen ini memang berisi barang titipan —
        // pembelian yang kebetulan disimpan berdekatan bukan pasangannya.
        $stAda = $pdo->prepare(
            "SELECT 1 FROM transaksi_masuk_detail d JOIN batch_barang bb ON bb.id = d.batch_id
             WHERE d.transaksi_masuk_id = ? AND bb.sumber = 'donasi' LIMIT 1");
        $stAda->execute([$trxId]);
        if (!$stAda->fetchColumn()) { $donasiId = null; }
    }

    $stBatch  = $pdo->prepare("SELECT * FROM batch_barang WHERE id = ? FOR UPDATE");
    $ubah = []; $ket = []; $barangSentuh = []; $batchDok = []; $sisaBaris = 0;

    foreach ($detail as $d) {
        $did = (int) $d['id'];
        if ($d['batch_id']) { $batchDok[] = (int) $d['batch_id']; }
        $lama      = (int) $d['qty'];
        $hargaLama = (float) $d['harga'];
        $baru      = array_key_exists($did, $isian) ? (int) $isian[$did]['qty'] : $lama;
        $harga     = array_key_exists($did, $isian) ? round((float) $isian[$did]['harga'], 2) : $hargaLama;
        $label     = "{$d['nama_barang']} (batch {$d['nomor_batch']})";

        if ($baru < 0)  { throw new RuntimeException("$label: jumlah tidak boleh negatif."); }
        if ($harga < 0 || $harga > 9999999999) { throw new RuntimeException("$label: harga tidak wajar."); }
        periksa_qty_wajar($baru, "$label: jumlah");

        $gantiQty   = $baru !== $lama;
        $gantiHarga = abs($harga - $hargaLama) >= 0.005;
        if (!$gantiQty && !$gantiHarga) { $sisaBaris++; continue; }

        $bb = null;
        if ($d['batch_id']) { $stBatch->execute([(int) $d['batch_id']]); $bb = $stBatch->fetch() ?: null; }

        if ($gantiQty) {
            $selisih = $baru - $lama;
            if (!$bb) {
                $ket[] = "$label: batchnya sudah tidak ada, jadi hanya dokumennya yang diubah.";
            } elseif ($lama > MAKS_QTY_BARIS) {
                // Salah tulis: betulkan jejaknya, jangan sentuh stok yang berlaku.
                $awal = (int) $bb['qty_awal'] + $selisih;
                if ($awal < 0 || $awal > MAKS_QTY_BARIS) { $awal = max($baru, (int) $bb['qty_tersisa']); }
                $pdo->prepare("UPDATE batch_barang SET qty_awal = ? WHERE id = ?")->execute([$awal, (int) $bb['id']]);
                $ket[] = "$label: angka lamanya tidak wajar — dokumen dibetulkan, stok saat ini ("
                       . angka($bb['qty_tersisa']) . ') tidak diubah.';
            } else {
                $sisa = (int) $bb['qty_tersisa'] + $selisih;
                if ($sisa < 0) {
                    $keluar = $lama - (int) $bb['qty_tersisa'];
                    throw new RuntimeException(
                        "$label: " . angka($keluar) . ' unit dari penerimaan ini sudah keluar atau dipindahkan, '
                      . 'jadi jumlahnya tidak bisa lebih kecil dari ' . angka($keluar) . '.');
                }
                periksa_qty_wajar($sisa, "$label: stok batch");
                $pdo->prepare("UPDATE batch_barang SET qty_awal = GREATEST(0, qty_awal + ?), qty_tersisa = ? WHERE id = ?")
                    ->execute([$selisih, $sisa, (int) $bb['id']]);
                $barangSentuh[(int) $d['barang_id']] = true;
            }
        }
        if ($gantiHarga && $bb) {
            $hb = (float) $bb['harga_beli'];
            if ($hb == 0.0 || abs($hb - $hargaLama) < 0.005) {
                $pdo->prepare("UPDATE batch_barang SET harga_beli = ? WHERE id = ?")->execute([$harga, (int) $bb['id']]);
            }
        }

        if ($baru === 0) {
            $pdo->prepare("DELETE FROM transaksi_masuk_detail WHERE id = ?")->execute([$did]);
        } else {
            $pdo->prepare("UPDATE transaksi_masuk_detail SET qty = ?, harga = ?, subtotal = ? WHERE id = ?")
                ->execute([$baru, $harga, $baru * $harga, $did]);
            $sisaBaris++;
        }

        /* Baris donasi kembarannya: barang, batch, dan jumlah lama yang sama. */
        if ($bb && $bb['sumber'] === 'donasi') {
            $dd = false;
            if ($donasiId) {
                $stDD = $pdo->prepare(
                    "SELECT id FROM donasi_detail
                     WHERE donasi_id = ? AND barang_id = ? AND nomor_batch <=> ? AND qty = ? LIMIT 1");
                $stDD->execute([$donasiId, (int) $d['barang_id'], $d['nomor_batch'], $lama]);
                $dd = $stDD->fetchColumn();
            }
            if ($dd && $baru === 0) {
                $pdo->prepare("DELETE FROM donasi_detail WHERE id = ?")->execute([(int) $dd]);
            } elseif ($dd) {
                $pdo->prepare("UPDATE donasi_detail SET qty = ?, nilai_taksir = ? WHERE id = ?")
                    ->execute([$baru, $baru * $harga, (int) $dd]);
            } else {
                $ket[] = "$label: catatan donasinya tidak ditemukan, jadi tidak ikut diubah — periksa di halaman Donasi.";
            }
        }

        $ubah[] = $label . ': '
            . ($gantiQty ? angka($lama) . ' → ' . angka($baru) . ' unit' : '')
            . ($gantiQty && $gantiHarga ? ', ' : '')
            . ($gantiHarga ? 'harga ' . rupiah($hargaLama) . ' → ' . rupiah($harga) : '');
    }

    /* Kepala dokumen. Supplier ikut ke batch yang suppliernya memang berasal
       dari dokumen ini (sama dengan supplier lamanya). */
    $supLama = $tm['supplier_id'] !== null ? (int) $tm['supplier_id'] : null;
    if ($supplierId !== $supLama) {
        if ($batchDok) {
            $pdo->prepare("UPDATE batch_barang SET supplier_id = ? WHERE id IN (" . implode(',', $batchDok) . ") AND supplier_id <=> ?")
                ->execute([$supplierId, $supLama]);
        }
        if ($donasiId) {
            $pdo->prepare("UPDATE donasi SET supplier_id = ? WHERE id = ? AND supplier_id <=> ?")
                ->execute([$supplierId, $donasiId, $supLama]);
        }
        $ubah[] = 'supplier diganti';
    }
    if ($catatan !== (string) $tm['catatan']) { $ubah[] = 'catatan diubah'; }

    // Dokumen tanpa baris sejak awal tidak ikut terhapus hanya karena disimpan.
    $dihapus = $detail && $sisaBaris === 0;
    if ($dihapus) {
        $pdo->prepare("DELETE FROM transaksi_masuk WHERE id = ?")->execute([$trxId]);
        if ($donasiId) {
            // Donasi yang seluruh barisnya ikut terhapus tidak punya isi lagi.
            $pdo->prepare(
                "DELETE FROM donasi WHERE id = ?
                   AND NOT EXISTS (SELECT 1 FROM donasi_detail WHERE donasi_id = ?)")->execute([$donasiId, $donasiId]);
        }
    } else {
        $pdo->prepare(
            "UPDATE transaksi_masuk
                SET supplier_id = ?, catatan = ?,
                    total = (SELECT COALESCE(SUM(subtotal), 0) FROM transaksi_masuk_detail WHERE transaksi_masuk_id = ?)
              WHERE id = ?")->execute([$supplierId, $catatan !== '' ? $catatan : null, $trxId, $trxId]);
    }
    foreach (array_keys($barangSentuh) as $bid) { recalc_stok($pdo, (int) $bid); }

    return ['no' => (string) $tm['no_transaksi'], 'ubah' => $ubah, 'dihapus' => $dihapus, 'catatan' => $ket];
}

/* ===========================================================================
   EDIT RIWAYAT PENERIMAAN — revisi tim gudang, Oktober 2026 (butir 4 & 9)

   Kasus lapangan: barang kunjungan tertinggal diinput. Dulu terpaksa dibuat
   penerimaan baru atas donatur yang sama, sehingga satu kedatangan tercatat
   sebagai dua, dan salah ketik nama donatur melahirkan donatur kembar.
   Dua tindakan di bawah menyelesaikannya tanpa menyentuh yang lain:
     ganti_donatur_dokumen_masuk()  — penerimaan ini dipindahkan ke donatur
                                      lain (atau donatur baru), berikut batch
                                      stoknya
     tambah_baris_dokumen_masuk()   — baris barang baru masuk ke penerimaan
                                      yang sudah ada, lewat mesin simpan yang
                                      sama dengan Barang Masuk
   Keduanya dipanggil DI DALAM transaksi pemanggil, dan pemanggil mencatat
   jejaknya (siapa, kapan, nilai lama → baru) lewat catat_aktivitas().
   =========================================================================== */

/** Kepala dokumen + catatan donasinya (null bila bukan donasi / tidak tersambung). */
function dokumen_masuk_kepala(PDO $pdo, int $trxId): array {
    $st = $pdo->prepare("SELECT * FROM transaksi_masuk WHERE id = ? FOR UPDATE");
    $st->execute([$trxId]);
    $tm = $st->fetch();
    if (!$tm) { throw new RuntimeException('Dokumen itu sudah tidak ada.'); }
    $donasi = null;
    if (kolom_ada($pdo, 'transaksi_masuk', 'donasi_id') && !empty($tm['donasi_id'])) {
        $st = $pdo->prepare("SELECT * FROM donasi WHERE id = ? FOR UPDATE");
        $st->execute([(int) $tm['donasi_id']]);
        $donasi = $st->fetch() ?: null;
    }
    return [$tm, $donasi];
}

/**
 * Ganti donatur sebuah penerimaan donasi.
 *
 * Yang ikut berpindah: catatan donasi (donatur_id) dan batch stok yang lahir
 * dari penerimaan ini. Batch menyimpan donaturnya sebagai bagian identitas
 * (lihat tambah_ke_lokasi), dan satu batch bisa berisi barang dari dua
 * penerimaan donatur yang sama. Karena itu:
 *   - batch yang HANYA dirujuk penerimaan ini: donaturnya diganti langsung;
 *   - batch yang juga dirujuk penerimaan lain: bagian milik penerimaan ini
 *     dipindahkan ke batch tersendiri atas donatur baru, supaya penerimaan
 *     lain tidak ikut berganti donatur.
 * Barang yang sudah keluar dari batch tetap tercatat atas donatur lama di
 * jejak stoknya — tidak bisa ditarik kembali, dan itu dilaporkan.
 *
 * @param array $isi  nama, telepon, jenis, wilayah, anonim — seperti formulir Barang Masuk
 * @return array{no:string, lama:string, baru:string, kode:string, donatur_baru:bool, catatan:string[]}
 */
function ganti_donatur_dokumen_masuk(PDO $pdo, int $trxId, array $isi): array {
    [$tm, $donasi] = dokumen_masuk_kepala($pdo, $trxId);
    if (!$donasi) {
        throw new RuntimeException('Penerimaan ini tidak tersambung ke catatan donasi, jadi donaturnya tidak bisa diganti dari sini.');
    }
    $st = $pdo->prepare("SELECT d.*, b.nama AS nama_barang FROM transaksi_masuk_detail d JOIN barang b ON b.id = d.barang_id WHERE d.transaksi_masuk_id = ? ORDER BY d.id FOR UPDATE");
    $st->execute([$trxId]);
    $detail = $st->fetchAll();
    if (!dokumen_masuk_boleh_ubah($pdo, array_column($detail, 'lokasi_rak_id'))) {
        throw new RuntimeException('Mengubah dokumen ini di luar wewenang akun Anda.');
    }
    if (trim((string) ($isi['nama'] ?? '')) === '') { throw new RuntimeException('Nama donatur wajib diisi.'); }
    if (kunci_telepon($isi['telepon'] ?? '') === '') { throw new RuntimeException('Nomor HP donatur wajib diisi — bersama namanya, itulah yang dipakai mengenali donatur yang sudah pernah memberi.'); }
    kunci_stok($pdo, array_column($detail, 'barang_id'));

    $lamaId = (int) $donasi['donatur_id'];
    $stN = $pdo->prepare("SELECT nama, kode FROM donatur WHERE id = ?");
    $stN->execute([$lamaId]); $lama = $stN->fetch() ?: ['nama' => '?', 'kode' => '?'];

    $cocok  = donatur_cocok_atau_buat($pdo, $isi, null);
    $baruId = (int) $cocok['id'];
    $stN->execute([$baruId]); $baru = $stN->fetch() ?: ['nama' => '?', 'kode' => $cocok['kode']];
    $hasil = ['no' => (string) $tm['no_transaksi'], 'lama' => $lama['nama'] . ' (' . $lama['kode'] . ')',
              'baru' => $baru['nama'] . ' (' . $baru['kode'] . ')', 'kode' => $cocok['kode'],
              'donatur_baru' => (bool) $cocok['baru'], 'catatan' => []];
    if ($baruId === $lamaId) { return $hasil; }

    $pdo->prepare("UPDATE donasi SET donatur_id = ? WHERE id = ?")->execute([$baruId, (int) $donasi['id']]);

    pindahkan_batch_donatur_dokumen($pdo, $trxId, $detail, $lamaId, $baruId, 'donasi', $hasil);
    return $hasil;
}

/**
 * Tambah baris barang ke penerimaan yang sudah tersimpan.
 *
 * Memakai mesin yang sama dengan Barang Masuk: tambah_ke_rak() membuat atau
 * menggabungkan batch (kondisi, rak, expired, batch, donatur, sumber ikut
 * identitasnya), baris detail dicatat, catatan donasi ditambah barisnya bila
 * penerimaannya donasi, dan total dokumen dihitung ulang.
 *
 * @param array $baris  [['barang_id','qty','harga','nomor_batch','tanggal_expired','lokasi_rak_id','kondisi'], …]
 * @return array{no:string, ubah:string[]}
 */
function tambah_baris_dokumen_masuk(PDO $pdo, int $trxId, array $baris): array {
    [$tm, $donasi] = dokumen_masuk_kepala($pdo, $trxId);
    $st = $pdo->prepare("SELECT d.lokasi_rak_id, d.barang_id, bb.sumber FROM transaksi_masuk_detail d LEFT JOIN batch_barang bb ON bb.id = d.batch_id WHERE d.transaksi_masuk_id = ?");
    $st->execute([$trxId]);
    $ada = $st->fetchAll();
    if (!dokumen_masuk_boleh_ubah($pdo, array_column($ada, 'lokasi_rak_id'))) {
        throw new RuntimeException('Mengubah dokumen ini di luar wewenang akun Anda.');
    }
    // Asal barang mengikuti dokumennya: donasi bila tersambung ke catatan donasi / barisnya donasi.
    $sumber    = ($donasi || in_array('donasi', array_column($ada, 'sumber'), true)) ? 'donasi' : 'pembelian';
    $donaturId = $donasi ? (int) $donasi['donatur_id'] : null;
    $supplierId = $tm['supplier_id'] !== null ? (int) $tm['supplier_id'] : null;

    $bersih = [];
    foreach ($baris as $i => $b) {
        $bid = (int) ($b['barang_id'] ?? 0); $qty = (int) ($b['qty'] ?? 0); $rak = (int) ($b['lokasi_rak_id'] ?? 0);
        if ($bid <= 0 && $qty <= 0) { continue; }   // baris kosong dilewati
        $n = $i + 1;
        if ($bid <= 0) { throw new RuntimeException("Baris $n: barang belum dipilih."); }
        if ($qty <= 0) { throw new RuntimeException("Baris $n: jumlah harus lebih dari 0."); }
        periksa_qty_wajar($qty, "Baris $n: jumlah");
        if ($rak <= 0) { throw new RuntimeException("Baris $n: rak wajib dipilih agar stok per gudang terhitung benar."); }
        if (terikat_satu_gudang() && !in_array($rak, rak_saya($pdo), true)) { throw new RuntimeException("Baris $n: rak itu bukan milik gudang Anda."); }
        $cek = $pdo->prepare("SELECT nama FROM barang WHERE id = ? AND deleted_at IS NULL");
        $cek->execute([$bid]);
        $nama = (string) $cek->fetchColumn();
        if ($nama === '') { throw new RuntimeException("Baris $n: barang tidak ditemukan."); }
        $bersih[] = ['barang_id' => $bid, 'nama' => $nama, 'qty' => $qty,
                     'harga' => max(0, round((float) ($b['harga'] ?? 0), 2)),
                     'batch' => mb_substr(trim((string) ($b['nomor_batch'] ?? '')), 0, 50) ?: ('B' . date('ymd')),
                     'exp'   => tanggal_expired_sah((string) ($b['tanggal_expired'] ?? '')),
                     'rak'   => $rak, 'kondisi' => kondisi_sah($b['kondisi'] ?? 'baru')];
    }
    if (!$bersih) { throw new RuntimeException('Tidak ada baris barang yang diisi.'); }
    kunci_stok($pdo, array_column($bersih, 'barang_id'));

    $insD = $pdo->prepare(
        "INSERT INTO transaksi_masuk_detail (transaksi_masuk_id, barang_id, batch_id, nomor_batch, tanggal_expired, lokasi_rak_id, qty, harga, subtotal)
         VALUES (?,?,?,?,?,?,?,?,?)");
    $insDD = $pdo->prepare(
        "INSERT INTO donasi_detail (donasi_id, barang_id, kondisi, nomor_batch, tanggal_expired, qty, nilai_taksir) VALUES (?,?,?,?,?,?,?)");
    $ubah = [];
    foreach ($bersih as $it) {
        $batchId = tambah_ke_rak($pdo, $it['barang_id'], $it['rak'], $it['batch'], $it['exp'], $it['qty'],
                                 $it['harga'], $supplierId, $it['kondisi'], $sumber, $donaturId);
        $insD->execute([$trxId, $it['barang_id'], $batchId, $it['batch'], $it['exp'], $it['rak'],
                        $it['qty'], $it['harga'], $it['qty'] * $it['harga']]);
        if ($donasi) {
            $insDD->execute([(int) $donasi['id'], $it['barang_id'], $it['kondisi'], $it['batch'], $it['exp'],
                             $it['qty'], $it['qty'] * $it['harga']]);
        }
        recalc_stok($pdo, $it['barang_id']);
        $ubah[] = '+ ' . $it['nama'] . ' ' . angka($it['qty']) . ' (' . label_kondisi_pendek($it['kondisi']) . ', batch ' . $it['batch'] . ')';
    }
    $pdo->prepare("UPDATE transaksi_masuk SET total = (SELECT COALESCE(SUM(subtotal),0) FROM transaksi_masuk_detail WHERE transaksi_masuk_id = ?) WHERE id = ?")
        ->execute([$trxId, $trxId]);
    return ['no' => (string) $tm['no_transaksi'], 'ubah' => $ubah];
}

/**
 * Tabel dokumen dengan rincian yang bisa dibuka per baris.
 * [$pdo] diisi = tombol Ubah digambar untuk dokumen yang boleh diubah akun ini.
 */
function dokumen_masuk_tabel(array $dok, array $rinci, ?PDO $pdo = null): void {
    ?>
    <div class="tbl-wrap"><table class="tbl-kartu">
      <thead><tr><th>No. Dokumen</th><th>Tanggal</th><th>Asal / Jenis</th><th>Supplier</th>
        <th class="num">Unit</th><th class="num">Nilai</th><th>Rincian</th></tr></thead>
      <tbody>
      <?php if (!$dok): ?>
        <tr><td colspan="7" class="empty">Tidak ada dokumen yang cocok.</td></tr>
      <?php endif; ?>
      <?php foreach ($dok as $t):
          $idR = 'rmasuk' . (int) $t['id'];
          $isi = $rinci[(int) $t['id']] ?? []; ?>
        <tr>
          <td data-label="No. Dokumen"><code><?= e($t['no_transaksi']) ?></code>
            <?php if ($t['petugas']): ?><br><small class="mut2">oleh <?= e($t['petugas']) ?></small><?php endif; ?>
          </td>
          <td data-label="Tanggal"><?= e(tgl_jam_id($t['tanggal'])) ?></td>
          <td data-label="Asal / Jenis">
            <?php if (($t['asal'] ?? '') === 'donasi'): [$jdLbl, $jdCls] = label_jenis_donasi_masuk($t['jenis_donasi'] ?? null); ?>
              <span class="tag <?= e($jdCls) ?>"><?= e($jdLbl) ?></span>
              <?php if (!empty($t['donatur_nama'])): ?><br><small class="mut2"><?= e($t['donatur_nama']) ?></small><?php endif; ?>
            <?php else: ?>
              <span class="tag t-blue">Pembelian</span>
            <?php endif; ?>
          </td>
          <td data-label="Supplier"><?= e($t['supplier'] ?: '—') ?></td>
          <td data-label="Unit" class="num"><b><?= angka($t['qty']) ?></b></td>
          <td data-label="Nilai" class="num"><?= rupiah($t['total']) ?></td>
          <td data-label="Rincian" class="nowrap"><?= tombol_rincian($idR, (int) $t['jenis']) ?>
            <?php if ($pdo && dokumen_masuk_boleh_ubah($pdo, array_column($isi, 'lokasi_rak_id'))): ?>
              <a class="btn btn-ghost btn-sm" href="masuk_ubah.php?id=<?= (int) $t['id'] ?>">Ubah</a>
            <?php endif; ?>
          </td>
        </tr>
        <tr class="baris-rinci" id="<?= e($idR) ?>" hidden>
          <td colspan="7">
            <div class="rinci-isi">
              <?php if (!$isi): ?>
                <div class="mut" style="font-size:12.5px">Dokumen ini tidak punya rincian barang.</div>
              <?php else: ?>
                <div class="judul">Isi dokumen <?= e($t['no_transaksi']) ?></div>
                <table>
                  <thead><tr>
                    <th>Barang</th><th>Batch</th><th>Kondisi</th><th>Asal</th>
                    <th>Kedaluwarsa</th><th>Lokasi</th>
                    <th class="num">Jumlah</th><th class="num">Harga</th><th class="num">Subtotal</th>
                  </tr></thead>
                  <tbody>
                  <?php foreach ($isi as $d):
                      [$lblK, $wrnK] = label_kondisi($d['kondisi'] ?? 'baru');
                      [$lblS, $wrnS] = label_sumber($d['sumber'] ?? 'pembelian'); ?>
                    <tr>
                      <td><b><?= e($d['nama_barang']) ?></b><br><small class="mut2"><?= e($d['kode_barang']) ?></small></td>
                      <td><?= $d['nomor_batch'] ? '<code>' . e($d['nomor_batch']) . '</code>' : '—' ?></td>
                      <td><span class="tag <?= $wrnK ?>"><?= e($lblK) ?></span></td>
                      <td><span class="tag <?= $wrnS ?>"><?= e($lblS) ?></span>
                        <?php /* Nama donatur dihormati anonimitasnya di sini juga —
                                 rincian dokumen sama terbukanya dengan halaman mana pun. */
                              if ($d['nama_donatur']): ?>
                          <br><small class="mut2"><?= e(nama_donatur_tampil([
                                'nama' => $d['nama_donatur'], 'anonim' => $d['donatur_anonim'],
                                'wilayah' => $d['donatur_wilayah']])) ?></small>
                        <?php endif; ?>
                      </td>
                      <td><?= e(tgl_id($d['tanggal_expired'])) ?></td>
                      <td><?= $d['kode_rak'] ? e($d['kode_rak']) : '—' ?>
                        <?php if ($d['nama_gudang']): ?><br><small class="mut2"><?= e($d['nama_gudang']) ?></small><?php endif; ?>
                      </td>
                      <td class="num"><b><?= angka($d['qty']) ?></b> <?= e($d['satuan']) ?></td>
                      <td class="num"><?= rupiah($d['harga']) ?></td>
                      <td class="num"><?= rupiah($d['subtotal']) ?></td>
                    </tr>
                  <?php endforeach; ?>
                  </tbody>
                </table>
                <?php if ($t['catatan']): ?>
                  <div class="mut" style="font-size:12.5px;margin-top:10px">
                    Catatan: <?= e($t['catatan']) ?></div>
                <?php endif; ?>
              <?php endif; ?>
            </div>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table></div>
    <?php
}

/**
 * Pindahkan batch-batch sebuah penerimaan ke donatur (dan asal) yang baru.
 * $lamaId null = batch apa pun milik dokumen ini (dipakai saat menyambungkan
 * dokumen lama yang belum punya catatan donasi); selain itu hanya batch
 * donatur lama yang disentuh. Batch yang sudah dirujuk dokumen lain atau
 * punya kembaran dipindahkan isinya lewat tambah_ke_lokasi().
 */
function pindahkan_batch_donatur_dokumen(PDO $pdo, int $trxId, array $detail, ?int $lamaId, int $baruId, string $sumberBaru, array &$hasil): void {
    $stB = $pdo->prepare("SELECT * FROM batch_barang WHERE id = ? FOR UPDATE");
    $stLain = $pdo->prepare("SELECT COUNT(*) FROM transaksi_masuk_detail x WHERE x.batch_id = ? AND x.transaksi_masuk_id <> ?");
    foreach ($detail as $d) {
        if (!$d['batch_id']) { continue; }
        $stB->execute([(int) $d['batch_id']]);
        $bb = $stB->fetch();
        if (!$bb) { continue; }
        if ($lamaId !== null && (int) $bb['donatur_id'] !== $lamaId) { continue; }   // bukan batch donatur lama
        if ((int) $bb['donatur_id'] === $baruId && $bb['sumber'] === $sumberBaru) { continue; }   // sudah benar
        $label = "{$d['nama_barang']} (batch {$bb['nomor_batch']})";

        $stLain->execute([(int) $bb['id'], $trxId]);
        $dirujukLain = (int) $stLain->fetchColumn() > 0;
        /* Sudah ada batch beridentitas sama atas donatur baru? Kunci unik
           tidak menangkapnya bila tanggal_expired NULL (NULL tidak pernah
           dianggap kembar oleh MySQL), jadi diperiksa sendiri dengan <=>.
           Bila ada, isinya dipindahkan ke sana lewat tambah_ke_lokasi()
           alih-alih membuat dua batch kembar. */
        $stKembar = $pdo->prepare(
            "SELECT 1 FROM batch_barang WHERE barang_id = ? AND nomor_batch = ? AND kondisi = ? AND sumber = ?
               AND donatur_id <=> ? AND lokasi_rak_id <=> ? AND toko_id <=> ? AND tanggal_expired <=> ? AND id <> ? LIMIT 1");
        $stKembar->execute([(int) $bb['barang_id'], $bb['nomor_batch'], $bb['kondisi'], $sumberBaru, $baruId,
                            $bb['lokasi_rak_id'], $bb['toko_id'], $bb['tanggal_expired'], (int) $bb['id']]);
        if (!$dirujukLain && !$stKembar->fetchColumn()) {
            try {
                $pdo->prepare("UPDATE batch_barang SET donatur_id = ?, sumber = ? WHERE id = ?")->execute([$baruId, $sumberBaru, (int) $bb['id']]);
                continue;
            } catch (PDOException $e) {
                if ($e->getCode() !== '23000') { throw $e; }
                // Sudah ada batch identik atas donatur baru — pindahkan isinya ke sana.
            }
        }
        $pindah = min((int) $d['qty'], (int) $bb['qty_tersisa']);
        if ($pindah <= 0) {
            $hasil['catatan'][] = "$label: stoknya sudah habis keluar, jejak batchnya tetap atas donatur lama.";
            continue;
        }
        $batchBaru = tambah_ke_lokasi($pdo, (int) $bb['barang_id'],
            $bb['lokasi_rak_id'] ? (int) $bb['lokasi_rak_id'] : null,
            $bb['toko_id'] ? (int) $bb['toko_id'] : null,
            (string) $bb['nomor_batch'], $bb['tanggal_expired'], $pindah, (float) $bb['harga_beli'],
            $bb['supplier_id'] ? (int) $bb['supplier_id'] : null,
            (string) $bb['kondisi'], $sumberBaru, $baruId);
        $pdo->prepare("UPDATE batch_barang SET qty_tersisa = qty_tersisa - ?, qty_awal = GREATEST(0, qty_awal - ?) WHERE id = ?")
            ->execute([$pindah, $pindah, (int) $bb['id']]);
        $pdo->prepare("UPDATE transaksi_masuk_detail SET batch_id = ? WHERE id = ?")->execute([$batchBaru, (int) $d['id']]);
        if ($pindah < (int) $d['qty']) {
            $hasil['catatan'][] = "$label: " . angka((int) $d['qty'] - $pindah) . ' unit sudah keluar — jejaknya tetap atas donatur lama.';
        }
        recalc_stok($pdo, (int) $bb['barang_id']);
    }
}

/**
 * Sambungkan penerimaan yang belum punya catatan donasi (dokumen lama, atau
 * yang tercatat pembelian) ke donatur: catatan donasi + rinciannya dibuat
 * dari baris dokumen, transaksi_masuk.donasi_id diisi, batch-batchnya
 * menjadi donasi atas donatur itu.
 */
function sambungkan_donasi_dokumen_masuk(PDO $pdo, int $trxId, array $isi, string $jenisDonasi, int $userId): array {
    if (!kolom_ada($pdo, 'transaksi_masuk', 'donasi_id')) { throw new RuntimeException('Migrasi basis data (tim_gudang_01) belum dijalankan.'); }
    [$tm, $donasi] = dokumen_masuk_kepala($pdo, $trxId);
    if ($donasi) { throw new RuntimeException('Penerimaan ini sudah tersambung ke catatan donasi — pakai Ganti Donatur.'); }
    $st = $pdo->prepare("SELECT d.*, b.nama AS nama_barang, bb.kondisi AS kondisi_batch FROM transaksi_masuk_detail d JOIN barang b ON b.id = d.barang_id LEFT JOIN batch_barang bb ON bb.id = d.batch_id WHERE d.transaksi_masuk_id = ? ORDER BY d.id FOR UPDATE");
    $st->execute([$trxId]);
    $detail = $st->fetchAll();
    if (!$detail) { throw new RuntimeException('Dokumen ini tidak punya baris barang.'); }
    if (!dokumen_masuk_boleh_ubah($pdo, array_column($detail, 'lokasi_rak_id'))) {
        throw new RuntimeException('Mengubah dokumen ini di luar wewenang akun Anda.');
    }
    if (trim((string) ($isi['nama'] ?? '')) === '') { throw new RuntimeException('Nama donatur wajib diisi.'); }
    if (kunci_telepon($isi['telepon'] ?? '') === '') { throw new RuntimeException('Nomor HP donatur wajib diisi.'); }
    if (!isset(JENIS_DONASI_MASUK[$jenisDonasi])) { throw new RuntimeException('Pilih jenis donasi: kunjungan atau paket.'); }
    kunci_stok($pdo, array_column($detail, 'barang_id'));

    $cocok  = donatur_cocok_atau_buat($pdo, $isi, null);
    $baruId = (int) $cocok['id'];
    $stN = $pdo->prepare("SELECT nama, kode FROM donatur WHERE id = ?"); $stN->execute([$baruId]);
    $baru = $stN->fetch() ?: ['nama' => '?', 'kode' => $cocok['kode']];

    $noDonasi = nomor_dokumen($pdo, 'donasi', 'no_donasi', 'DN');
    $pdo->prepare("INSERT INTO donasi (no_donasi, donatur_id, supplier_id, tanggal, jenis, cara_terima, catatan, dibuat_oleh)
                   VALUES (?,?,?,?,?,'diantar',?,?)")
        ->execute([$noDonasi, $baruId, $tm['supplier_id'] ?: null, substr((string) $tm['tanggal'], 0, 10), $jenisDonasi,
                   mb_substr('Disambungkan dari penerimaan ' . $tm['no_transaksi'] . ($tm['catatan'] ? ' — ' . $tm['catatan'] : ''), 0, 500), $userId]);
    $donasiId = (int) $pdo->lastInsertId();
    $pdo->prepare("UPDATE transaksi_masuk SET donasi_id = ? WHERE id = ?")->execute([$donasiId, $trxId]);
    $stDD = $pdo->prepare("INSERT INTO donasi_detail (donasi_id, barang_id, kondisi, nomor_batch, tanggal_expired, qty, nilai_taksir) VALUES (?,?,?,?,?,?,?)");
    foreach ($detail as $dt) {
        $stDD->execute([$donasiId, (int) $dt['barang_id'], $dt['kondisi_batch'] ?: 'baru', $dt['nomor_batch'] ?: null,
                        $dt['tanggal_expired'] ?: null, (int) $dt['qty'], (float) $dt['qty'] * (float) $dt['harga']]);
    }
    $hasil = ['no' => (string) $tm['no_transaksi'], 'no_donasi' => $noDonasi, 'baru' => $baru['nama'] . ' (' . $baru['kode'] . ')',
              'kode' => $cocok['kode'], 'donatur_baru' => (bool) $cocok['baru'], 'catatan' => []];
    pindahkan_batch_donatur_dokumen($pdo, $trxId, $detail, null, $baruId, 'donasi', $hasil);
    return $hasil;
}

/** Ubah jenis donasi (kunjungan / paket) sebuah penerimaan yang sudah tersambung. */
function ubah_jenis_donasi_dokumen_masuk(PDO $pdo, int $trxId, string $jenisDonasi): array {
    [$tm, $donasi] = dokumen_masuk_kepala($pdo, $trxId);
    if (!$donasi) { throw new RuntimeException('Penerimaan ini belum tersambung ke catatan donasi.'); }
    if (!isset(JENIS_DONASI_MASUK[$jenisDonasi])) { throw new RuntimeException('Pilih jenis donasi: kunjungan atau paket.'); }
    $st = $pdo->prepare("SELECT lokasi_rak_id FROM transaksi_masuk_detail WHERE transaksi_masuk_id = ?"); $st->execute([$trxId]);
    if (!dokumen_masuk_boleh_ubah($pdo, $st->fetchAll(PDO::FETCH_COLUMN))) { throw new RuntimeException('Mengubah dokumen ini di luar wewenang akun Anda.'); }
    $pdo->prepare("UPDATE donasi SET jenis = ? WHERE id = ?")->execute([$jenisDonasi, (int) $donasi['id']]);
    return ['no' => (string) $tm['no_transaksi'], 'lama' => (string) $donasi['jenis'], 'baru' => $jenisDonasi];
}
