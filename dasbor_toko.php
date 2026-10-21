<?php
/**
 * AMANAH — Dasbor Toko
 *
 * SATU dasbor untuk SEMUA toko yang dipegang akun ini. Pemilih di atas
 * layar adalah saringan, bukan pemindah halaman: bawaannya "Semua Toko",
 * dan memilih satu toko hanya mempersempit tampilan.
 *
 * Barang tidak pernah masuk langsung ke toko. Rute yang sah hanya
 * Supplier → Gudang (penerimaan), lalu Gudang → Toko (kirim_toko); lihat
 * rute_sah() di _lib.php. Penyesuaian stok di sini pun hanya bisa
 * MENGURANGI, sehingga tidak ada pintu belakang menambah stok toko.
 *
 * Lingkup toko SELALU diverifikasi ulang di server — mengganti angka di
 * URL tidak membuka toko milik orang lain.
 *
 * Stok toko dilacak sampai tingkat batch (nomor batch + kedaluwarsa), sama
 * seperti di gudang, supaya barang yang lebih dulu kedaluwarsa terjual lebih
 * dulu dan tidak ada susu formula lewat tanggal yang lolos ke pembeli.
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/_lib.php';
require_once __DIR__ . '/_masuk_gudang_toko.php';
require_login();

// Super Admin & Pimpinan boleh MELIHAT dasbor toko mana pun (pengawasan).
// Admin Gudang tidak — urusan penjualan toko bukan wewenangnya.
$awasi = false;
if (!is_toko()) {
    if (!boleh_mengawasi()) { header('Location: ' . beranda_peran()); exit; }
    $awasi = true;
    if ($_SERVER['REQUEST_METHOD'] === 'POST') { tolak_aksi_pengawasan(); }
}

$daftarToko = toko_saya($pdo);
if (!$daftarToko) {
    if (is_toko()) {
        die('<p style="font-family:sans-serif;padding:30px">Akun Anda belum dikaitkan ke toko mana pun. '
          . 'Hubungi Super Admin. <a href="logout.php">Keluar</a></p>');
    }
    set_flash('err', 'Belum ada toko terdaftar. Tambahkan lewat menu Toko.');
    header('Location: toko.php'); exit;
}

$u      = current_user();
$tab    = $_GET['tab'] ?? 'ringkas';
$errors = [];

/* ---------------------------------------------------------------------
   WEWENANG GUDANG TOKO — hanya tingkat MANAGER
   ---------------------------------------------------------------------
   Dua pekerjaan di tab Gudang bukan pekerjaan harian:

     barang masuk ke gudang toko  — MENAMBAH stok tanpa ada dokumen dari
                                    gudang pusat. Kalau staff bisa, jumlah
                                    stok bisa naik tanpa satu pun pihak lain
                                    ikut mengetahuinya.
     distribusi ke tiap toko      — memindahkan barang antar lokasi, dan
                                    menerbitkan dokumen atas nama lembaga.

   Keduanya dibatasi tingkat manager. Staff toko tetap bisa menjual,
   melihat stok, dan menyesuaikan hasil hitung — pekerjaan yang memang
   hariannya.

   is_manager() dibaca dari basis data tiap permintaan, bukan dari sesi,
   supaya penurunan tingkat lewat Kelola Pengguna langsung berlaku tanpa
   menunggu orangnya masuk ulang. */
$bolehGudang = is_manager($pdo) && !$awasi;

/* $tokoId & $toko diisi SETELAH lingkup dihitung — lihat blok "SATU DASBOR
   UNTUK SEMUA TOKO" di bawah. Sengaja tidak lagi memakai toko_aktif(),
   karena fungsi itu menyimpan pilihan di sesi; dasbor ini justru harus
   selalu terbuka dalam keadaan menampilkan semua toko. */
$tokoId = 0;
$toko   = null;

// ---------------------------------------------------------------------
// LINGKUP DASBOR
//
// Shopee dan Tokopedia TIDAK dipisah jadi halaman sendiri — satu dasbor
// memuat semua kanal sekaligus, lalu disaring bila perlu. Alasannya
// praktis: pemilik toko ingin tahu "hari ini masuk berapa" tanpa harus
// menjumlahkan sendiri dari dua layar.
//
// SELURUH tab memakai lingkup ini — Ringkasan, Stok, maupun Penjualan.
// Yang tetap menyebut satu toko hanyalah formulirnya, karena sebuah
// transaksi harus jelas terjadi di toko mana.
// ---------------------------------------------------------------------
$MP_SAH = ['shopee', 'tokopedia', 'tiktok', 'lazada', 'offline', 'lainnya'];

$filterMp = strtolower(trim((string) ($_GET['mp'] ?? '')));
if (!in_array($filterMp, $MP_SAH, true)) { $filterMp = ''; }

// Daftar toko yang boleh dilihat akun ini — sudah divalidasi toko_saya().
$tokoRows = [];
if ($daftarToko) {
    $tanya = implode(',', array_fill(0, count($daftarToko), '?'));
    try {
        $q = $pdo->prepare("SELECT id, kode, nama, marketplace FROM toko
                            WHERE id IN ($tanya) ORDER BY kode");
        $q->execute($daftarToko);
        $tokoRows = $q->fetchAll();
    } catch (Throwable $e) { error_log('[AMANAH] dasbor toko daftar: ' . $e->getMessage()); }
}

// Kanal yang benar-benar dipunyai akun ini — chip filter hanya menampilkan
// yang ada, bukan enam kanal yang lima di antaranya kosong.
$kanalSaya = [];
foreach ($tokoRows as $t) {
    $kanalSaya[$t['marketplace']] = ($kanalSaya[$t['marketplace']] ?? 0) + 1;
}

// ---------------------------------------------------------------------
// SATU DASBOR UNTUK SEMUA TOKO
//
// Dulu halaman ini bekerja per-toko: sebuah dropdown memilih SATU toko,
// pilihannya disimpan di sesi, dan tab Stok serta Penjualan hanya
// memperlihatkan toko itu. Akibatnya pemilik 9 toko harus berpindah
// sembilan kali hanya untuk tahu keadaan keseluruhan.
//
// Sekarang bawaannya SEMUA toko. `?toko=` tidak lagi berarti "toko yang
// sedang aktif" melainkan sekadar SARINGAN — dan sengaja tidak disimpan
// di sesi, supaya membuka halaman selalu memberi gambaran penuh lebih
// dulu, bukan sisa pilihan kemarin.
//
// Yang tetap butuh satu toko hanyalah FORMULIR: sebuah penjualan atau
// penyesuaian harus jelas terjadi di toko mana. Di sana tokonya dipilih
// di dalam formulirnya sendiri.
// ---------------------------------------------------------------------
$bolehId    = array_map('intval', $daftarToko);
$filterToko = (int) ($_GET['toko'] ?? 0);
if (!in_array($filterToko, $bolehId, true)) { $filterToko = 0; }

$lingkup = [];
foreach ($tokoRows as $t) {
    $idT = (int) $t['id'];
    if ($filterMp !== '' && $t['marketplace'] !== $filterMp) { continue; }
    if ($filterToko && $idT !== $filterToko)                 { continue; }
    $lingkup[] = $idT;
}
// Saringan yang menyisakan nol toko tidak berguna — kembalikan ke semua.
if (!$lingkup) { $filterMp = ''; $filterToko = 0; $lingkup = $bolehId; }

$inLingkup = implode(',', array_map('intval', $lingkup));
$nToko     = count($lingkup);

/** Nama toko untuk kolom tabel — dipakai karena satu tabel kini memuat
 *  baris dari beberapa toko sekaligus. */
$namaToko = [];
$mpPerToko = [];
foreach ($tokoRows as $t) {
    $namaToko[(int) $t['id']]  = $t['nama'];
    $mpPerToko[(int) $t['id']] = $t['marketplace'];
}

/* Toko yang dipakai sebagai BAWAAN formulir: yang sedang disaring bila
   ada, kalau tidak toko pertama yang boleh diakses. $toko hanya terisi
   ketika benar-benar satu toko yang sedang dilihat — dipakai untuk
   spanduk mode lihat-saja dan bawaan kanal di formulir penjualan. */
$tokoId = $filterToko ?: (int) ($bolehId[0] ?? 0);
if ($filterToko) {
    $stT = $pdo->prepare("SELECT * FROM toko WHERE id = ?");
    $stT->execute([$filterToko]);
    $toko = $stT->fetch() ?: null;
}

// ==================================================================== AKSI
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    wajib_csrf($_POST['csrf'] ?? null);
    $aksi   = $_POST['aksi'] ?? '';

    /* ------------------------------------------------- GUDANG TOKO
       Ditangani SEBELUM wajib_akses_toko() di bawah, karena gudang toko
       adalah gudang BERSAMA — ia tidak milik satu toko tertentu, jadi tidak
       ada toko_id yang bisa diperiksa.

       Dibuat di sini, bukan di Master Gudang, supaya yang memakainya yang
       mengaturnya: barang dari supplier mendarat di gudang ini lebih dulu,
       baru dipecah ke tiap toko. */
    if ($aksi === 'gudang_simpan') {
        $gid   = (int) ($_POST['gudang_id'] ?? 0);
        $gNama = trim($_POST['g_nama'] ?? '');
        $gAlm  = trim($_POST['g_alamat'] ?? '');
        $gPj   = trim($_POST['g_pj'] ?? '');
        $gTelp = trim($_POST['g_telepon'] ?? '');

        // Hanya gudang berjenis 'toko' yang boleh disunting dari sini.
        // Tanpa pemeriksaan ini, mengganti angka gudang_id di formulir
        // cukup untuk menyunting gudang pusat dari dasbor toko.
        if ($gid > 0) {
            $stG = $pdo->prepare("SELECT jenis FROM gudang WHERE id = ? AND deleted_at IS NULL");
            $stG->execute([$gid]);
            if ($stG->fetchColumn() !== 'toko') {
                set_flash('err', 'Gudang itu bukan gudang toko.');
                header('Location: dasbor_toko.php?tab=gudang'); exit;
            }
        }

        if ($gNama === '') {
            set_flash('err', 'Nama gudang toko wajib diisi.');
            header('Location: dasbor_toko.php?tab=gudang'); exit;
        }

        /* Gudang toko itu SATU, dipakai bersama semua toko. Formulir "Buat"
           memang disembunyikan setelah ada satu, tapi menyembunyikan
           formulir bukan pembatasan — POST berulang tetap bisa membuat
           yang kedua, dan stok akan terpecah ke gudang yang tidak dilihat
           siapa pun. */
        if ($gid === 0 && gudang_toko($pdo)) {
            set_flash('err', 'Gudang toko sudah ada. Satu gudang penampung dipakai bersama '
                           . 'semua toko — ubah yang sudah ada, bukan membuat yang baru.');
            header('Location: dasbor_toko.php?tab=gudang'); exit;
        }

        // Dicatat SEBELUM $gid terisi oleh lastInsertId(), kalau tidak
        // pembuatan baru akan tercatat sebagai 'update'.
        $modeBaru = ($gid === 0);
        try {
            $pdo->beginTransaction();
            if ($gid > 0) {
                $pdo->prepare(
                    "UPDATE gudang SET nama=?, alamat=?, penanggung_jawab=?, telepon=?
                     WHERE id=? AND jenis='toko'"
                )->execute([$gNama, $gAlm ?: null, $gPj ?: null, $gTelp ?: null, $gid]);
                $pesan = 'Gudang toko berhasil diperbarui.';
            } else {
                $pdo->prepare(
                    "INSERT INTO gudang (kode, nama, jenis, alamat, penanggung_jawab, telepon, is_active)
                     VALUES (?,?,'toko',?,?,?,1)"
                )->execute([kode_berikut($pdo, 'gudang', 'GD'), $gNama,
                            $gAlm ?: null, $gPj ?: null, $gTelp ?: null]);
                $gid = (int) $pdo->lastInsertId();

                /* Stok tersimpan PER RAK, bukan per gudang. Gudang tanpa rak
                   akan tampak sudah jadi padahal barang tidak bisa masuk ke
                   mana pun — jadi satu rak penampung langsung dibuatkan.
                   Rak lain bisa ditambah menyusul lewat Master Rak. */
                $pdo->prepare(
                    "INSERT INTO lokasi_rak (kode, nama, gudang_id, gudang, keterangan)
                     VALUES (?,?,?,?,?)"
                )->execute([kode_berikut($pdo, 'lokasi_rak', 'RAK'), 'Area Penampung',
                            $gid, $gNama, 'Rak bawaan gudang toko — barang masuk mendarat di sini.']);
                $pesan = 'Gudang toko dibuat, beserta satu rak penampung.';
            }
            $pdo->commit();
            catat_aktivitas($pdo, $modeBaru ? 'create' : 'update', 'gudang', $gid, $gNama);
            set_flash('ok', $pesan);
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) { $pdo->rollBack(); }
            set_flash('err', pesan_error_aman($e, 'simpan gudang toko'));
        }
        header('Location: dasbor_toko.php?tab=gudang'); exit;
    }

    /* ------------------------------------------------- RAK GUDANG TOKO
       Ditangani di sini, bersama gudang toko dan SEBELUM
       wajib_akses_toko(), untuk alasan yang sama: gudang toko adalah
       gudang bersama, tidak ada toko_id yang bisa diperiksa.

       Dulu satu-satunya rak yang ada adalah "Area Penampung" yang dibuat
       otomatis, dan komentarnya menjanjikan "rak lain bisa ditambah
       menyusul lewat Master Rak". Janji itu tidak bisa ditepati: akun
       toko ditolak Master Rak, sehingga gudang toko selamanya berisi
       satu rak. Stok tersimpan per rak, jadi itu berarti tidak ada cara
       memisahkan susu dari pakaian di gudang penampung. */
    if ($aksi === 'rak_simpan' || $aksi === 'rak_hapus') {
        if ($awasi) {
            set_flash('err', 'Anda sedang memantau dasbor toko, jadi rak tidak bisa diubah dari sini.');
            header('Location: dasbor_toko.php?tab=gudang'); exit;
        }

        /* gudang_toko_saya(): kalau akun toko ini diberi penugasan gudang
           tertentu, hanya rak di gudang ITU yang boleh diubah dari sini.
           Tanpa penugasan, seluruh gudang toko — perilaku lama. */
        $gudangTokoId = array_map('intval', array_column(gudang_toko_saya($pdo), 'id'));
        if (!$gudangTokoId) {
            set_flash('err', 'Gudang toko belum dibuat.');
            header('Location: dasbor_toko.php?tab=gudang'); exit;
        }

        /* Rak yang disebut harus benar-benar milik gudang toko. Tanpa
           pemeriksaan ini, mengganti satu angka di formulir cukup untuk
           menyunting — atau menghapus — rak gudang pusat dari dasbor
           toko, beserta seluruh isinya. */
        $rakId = (int) ($_POST['rak_id'] ?? 0);
        if ($rakId > 0) {
            $stR = $pdo->prepare(
                "SELECT lr.gudang_id FROM lokasi_rak lr
                  WHERE lr.id = ? AND lr.deleted_at IS NULL");
            $stR->execute([$rakId]);
            $gidRak = (int) $stR->fetchColumn();
            if (!$gidRak || !in_array($gidRak, $gudangTokoId, true)) {
                set_flash('err', 'Rak itu bukan rak gudang toko.');
                header('Location: dasbor_toko.php?tab=gudang'); exit;
            }
        }

        if ($aksi === 'rak_hapus') {
            try {
                /* Rak berisi barang TIDAK dihapus. Menghapusnya membuat
                   batch-nya menunjuk rak yang tidak ada lagi: stoknya
                   tetap terhitung di total tetapi tidak muncul di lokasi
                   mana pun — selisih yang tidak bisa dijelaskan siapa
                   pun saat opname. Aturan yang sama dipakai Master Rak. */
                $cek = $pdo->prepare("SELECT COALESCE(SUM(qty_tersisa),0)
                                        FROM batch_barang WHERE lokasi_rak_id = ?");
                $cek->execute([$rakId]);
                if ((int) $cek->fetchColumn() > 0) {
                    set_flash('err', 'Rak tidak bisa dihapus karena masih berisi barang. '
                                   . 'Pindahkan isinya lebih dulu lewat Perpindahan Barang '
                                   . '(jenis Mutasi Rak).');
                } else {
                    $pdo->prepare("UPDATE lokasi_rak SET deleted_at = NOW() WHERE id = ?")
                        ->execute([$rakId]);
                    catat_aktivitas($pdo, 'delete', 'rak', $rakId, 'Hapus rak gudang toko');
                    set_flash('ok', 'Rak dihapus.');
                }
            } catch (Throwable $e) {
                set_flash('err', pesan_error_aman($e, 'hapus rak gudang toko'));
            }
            header('Location: dasbor_toko.php?tab=gudang'); exit;
        }

        $rNama = trim($_POST['r_nama'] ?? '');
        $rKode = trim($_POST['r_kode'] ?? '');
        $rKap  = ($_POST['r_kapasitas'] ?? '') === '' ? null : (int) $_POST['r_kapasitas'];
        $rKet  = trim($_POST['r_keterangan'] ?? '');
        /* Gudangnya: yang dipilih bila ada beberapa, kalau tidak yang satu
           itu. Nilai dari formulir tetap diperiksa keanggotaannya — daftar
           pilihan di layar bukan pembatasan. */
        $rGid  = (int) ($_POST['r_gudang_id'] ?? 0);
        if (!in_array($rGid, $gudangTokoId, true)) { $rGid = $gudangTokoId[0]; }

        if ($rNama === '') {
            set_flash('err', 'Nama rak wajib diisi.');
            header('Location: dasbor_toko.php?tab=gudang'); exit;
        }
        if ($rKode === '') { $rKode = kode_berikut($pdo, 'lokasi_rak', 'RAK'); }

        try {
            $c = $pdo->prepare("SELECT id FROM lokasi_rak
                                 WHERE kode = ? AND id <> ? AND deleted_at IS NULL");
            $c->execute([$rKode, $rakId]);
            if ($c->fetch()) {
                set_flash('err', 'Kode rak "' . $rKode . '" sudah dipakai rak lain.');
                header('Location: dasbor_toko.php?tab=gudang'); exit;
            }

            /* Kolom teks `gudang` ikut diisi, sama seperti Master Rak —
               laporan lama masih membacanya, dan membiarkannya kosong di
               sini akan membuat rak buatan toko tampil tanpa nama gudang
               sementara rak lain punya. */
            $ng = $pdo->prepare("SELECT nama FROM gudang WHERE id = ?");
            $ng->execute([$rGid]);
            $namaGd = (string) $ng->fetchColumn();

            if ($rakId > 0) {
                $pdo->prepare(
                    "UPDATE lokasi_rak SET kode=?, nama=?, gudang_id=?, gudang=?,
                            kapasitas=?, keterangan=? WHERE id=?"
                )->execute([$rKode, $rNama, $rGid, $namaGd, $rKap, $rKet ?: null, $rakId]);
                catat_aktivitas($pdo, 'update', 'rak', $rakId, "Ubah rak gudang toko: $rNama");
                set_flash('ok', "Rak \"$rNama\" diperbarui.");
            } else {
                $pdo->prepare(
                    "INSERT INTO lokasi_rak (kode, nama, gudang_id, gudang, kapasitas, keterangan)
                     VALUES (?,?,?,?,?,?)"
                )->execute([$rKode, $rNama, $rGid, $namaGd, $rKap, $rKet ?: null]);
                catat_aktivitas($pdo, 'create', 'rak', (int) $pdo->lastInsertId(),
                                "Tambah rak gudang toko: $rNama");
                set_flash('ok', "Rak \"$rNama\" ditambahkan.");
            }
        } catch (Throwable $e) {
            set_flash('err', pesan_error_aman($e, 'simpan rak gudang toko'));
        }
        header('Location: dasbor_toko.php?tab=gudang'); exit;
    }

    $tokoId = (int) ($_POST['toko_id'] ?? $tokoId);
    wajib_akses_toko($pdo, $tokoId);

    // ------------------------------------------------- CATAT PENJUALAN
    if ($aksi === 'jual') {
        $noPesanan  = trim($_POST['no_pesanan'] ?? '');
        $pembeli    = trim($_POST['nama_pembeli'] ?? '');
        /* $toko bisa null sekarang (dasbor tidak lagi terikat satu toko),
           jadi kanal bawaannya diambil dari toko yang dipilih di formulir. */
        $mp         = $_POST['marketplace'] ?? ($mpPerToko[$tokoId] ?? 'offline');
        $ongkir     = (float) ($_POST['ongkir'] ?? 0);
        $catatan    = trim($_POST['catatan'] ?? '');
        $tglJual    = trim($_POST['tanggal'] ?? '') ?: date('Y-m-d H:i:s');

        $items = [];
        foreach (($_POST['barang_id'] ?? []) as $i => $bid) {
            $bid = (int) $bid;
            $qty = (int) ($_POST['qty'][$i] ?? 0);
            if (!$bid || $qty <= 0) continue;
            $items[] = ['barang_id' => $bid, 'qty' => $qty,
                        'harga' => (float) ($_POST['harga_jual'][$i] ?? 0)];
        }
        if (!$items) $errors[] = 'Isi minimal satu baris barang beserta jumlahnya.';

        // Cek stok toko secara agregat per barang lebih dulu.
        if (!$errors) {
            $perBarang = [];
            foreach ($items as $it) $perBarang[$it['barang_id']] = ($perBarang[$it['barang_id']] ?? 0) + $it['qty'];
            foreach ($perBarang as $bid => $q) {
                $ada = stok_di_toko($pdo, (int) $bid, $tokoId);
                if ($q > $ada) {
                    $nb = $pdo->prepare("SELECT nama FROM barang WHERE id = ?");
                    $nb->execute([$bid]);
                    $errors[] = (string) $nb->fetchColumn() . ': diminta ' . angka($q)
                              . ' unit, stok toko hanya ' . angka($ada) . ' unit.';
                }
            }
        }

        if (!$errors) {
            try {
                $hasil = dengan_nomor_dokumen($pdo, 'penjualan', 'no_penjualan', 'JL',
                    function (string $noJL) use ($pdo, $tokoId, $tglJual, $mp, $noPesanan,
                                                  $pembeli, $ongkir, $catatan, $items, $u) {
                        $pdo->beginTransaction();
                        kunci_stok($pdo, array_column($items, 'barang_id'));

                        $total = 0;
                        foreach ($items as $it) $total += $it['qty'] * $it['harga'];

                        $pdo->prepare(
                            "INSERT INTO penjualan
                               (no_penjualan, toko_id, tanggal, marketplace, no_pesanan, nama_pembeli,
                                total, ongkir, status, sumber, catatan, user_id)
                             VALUES (?,?,?,?,?,?,?,?,'selesai','manual',?,?)"
                        )->execute([$noJL, $tokoId, $tglJual, $mp, $noPesanan ?: null, $pembeli ?: null,
                                    $total, $ongkir, $catatan ?: null, $u['id']]);
                        $jualId = (int) $pdo->lastInsertId();

                        $stD    = $pdo->prepare(
                            "INSERT INTO penjualan_detail (penjualan_id, barang_id, batch_id, qty, harga_jual, subtotal, keterangan)
                             VALUES (?,?,?,?,?,?,?)"
                        );
                        $potong = $pdo->prepare("UPDATE batch_barang SET qty_tersisa = qty_tersisa - ? WHERE id = ?");

                        foreach ($items as $it) {
                            // FEFO di dalam toko — kedaluwarsa terdekat keluar dulu.
                            $diambil = ambil_fefo($pdo, $it['barang_id'], $it['qty'], null, null, $tokoId);
                            $teks = [];
                            foreach ($diambil as $a) {
                                $potong->execute([$a['ambil'], $a['batch_id']]);
                                $teks[] = $a['nomor_batch'] . ' (' . $a['ambil'] . ')';
                            }
                            $stD->execute([$jualId, $it['barang_id'], $diambil[0]['batch_id'] ?? null,
                                           $it['qty'], $it['harga'], $it['qty'] * $it['harga'],
                                           mb_substr('Batch: ' . implode(', ', $teks), 0, 255)]);
                            recalc_stok($pdo, $it['barang_id']);
                        }

                        $pdo->commit();
                        return ['id' => $jualId, 'no' => $noJL, 'total' => $total];
                    });

                catat_aktivitas($pdo, 'create', 'penjualan', $hasil['id'],
                    "Penjualan {$hasil['no']} di toko #$tokoId");
                set_flash('ok', "Penjualan {$hasil['no']} tercatat. Total " . rupiah($hasil['total'])
                              . '. Stok toko sudah berkurang.');
                header('Location: dasbor_toko.php?tab=jual&toko=' . $tokoId); exit;

            } catch (PDOException $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                $errors[] = str_contains($e->getMessage(), 'uq_penjualan_pesanan')
                    ? 'Nomor pesanan itu sudah pernah dicatat untuk toko ini.'
                    : pesan_error_aman($e, 'catat penjualan');
            } catch (RuntimeException $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                $errors[] = pesan_runtime($e, 'dasbor_toko');
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                $errors[] = pesan_error_aman($e, 'catat penjualan');
            }
        }
        $tab = 'jual';
    }

    // --------------------------------------------- PENYESUAIAN (rusak/hilang)
    if ($aksi === 'sesuaikan') {
        $barangId = (int) ($_POST['s_barang_id'] ?? 0);
        $qty      = (int) ($_POST['s_qty'] ?? 0);
        $jenis    = $_POST['s_jenis'] ?? 'rusak';
        $alasan   = trim($_POST['s_alasan'] ?? '');

        if (!$barangId || $qty <= 0) {
            $errors[] = 'Barang dan jumlah penyesuaian wajib diisi.';
        } elseif (!in_array($jenis, ['rusak', 'hilang', 'kedaluwarsa', 'koreksi_kurang'], true)) {
            $errors[] = 'Jenis penyesuaian tidak valid.';
        } else {
            try {
                $hasil = dengan_nomor_dokumen($pdo, 'penyesuaian_stok', 'no_penyesuaian', 'PS',
                    function (string $noPS) use ($pdo, $tokoId, $barangId, $qty, $jenis, $alasan, $u) {
                        $pdo->beginTransaction();
                        kunci_stok($pdo, [$barangId]);

                        $diambil = ambil_fefo($pdo, $barangId, $qty, null, null, $tokoId);
                        $potong  = $pdo->prepare("UPDATE batch_barang SET qty_tersisa = qty_tersisa - ? WHERE id = ?");
                        foreach ($diambil as $a) $potong->execute([$a['ambil'], $a['batch_id']]);

                        $pdo->prepare(
                            "INSERT INTO penyesuaian_stok
                               (no_penyesuaian, tanggal, lokasi_tipe, toko_id, barang_id, batch_id, jenis, qty, alasan, user_id)
                             VALUES (?,NOW(),'toko',?,?,?,?,?,?,?)"
                        )->execute([$noPS, $tokoId, $barangId, $diambil[0]['batch_id'] ?? null,
                                    $jenis, $qty, $alasan ?: null, $u['id']]);

                        recalc_stok($pdo, $barangId);
                        $pdo->commit();
                        return ['no' => $noPS];
                    });

                catat_aktivitas($pdo, 'create', 'penyesuaian_stok', $tokoId,
                    "Penyesuaian {$hasil['no']}: $jenis $qty unit");
                set_flash('ok', "Penyesuaian {$hasil['no']} tercatat. Stok toko berkurang " . angka($qty) . ' unit.');
                header('Location: dasbor_toko.php?tab=stok&toko=' . $tokoId); exit;

            } catch (RuntimeException $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                $errors[] = pesan_runtime($e, 'dasbor_toko');
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                $errors[] = pesan_error_aman($e, 'penyesuaian stok toko');
            }
        }
        $tab = 'stok';
    }

    // --------------------------------------- DISTRIBUSI GUDANG TOKO → TOKO
    // Inilah langkah kedua rantai di sisi toko: barang yang sudah mendarat
    // di gudang penampung dipecah ke tokonya masing-masing.
    //
    // Dokumennya dibuat berstatus 'menunggu_persetujuan', SAMA seperti
    // perpindahan lain. Stok tidak berpindah sekarang — ia berpindah saat
    // dokumennya disetujui lalu diterima, lewat jalur yang sudah ada.
    // Melewati persetujuan di sini akan membuat sisi toko punya aturan
    // sendiri, dan itu awal dari catatan yang tidak bisa dipercaya.
    /* ------------------------------------------------ barang masuk ke gudang toko
       Barang dari supplier atau pembelian mendarat di gudang penampung
       lebih dulu, BUKAN langsung ke rak toko. Itu yang membuat satu kiriman
       bisa dipecah ke beberapa toko sesudahnya — kalau langsung masuk ke
       satu toko, memecahnya menuntut memindahkan barang yang sudah
       tercatat sebagai milik toko itu.

       Dicatat sebagai transaksi_masuk sungguhan, bukan penyesuaian stok:
       barangnya memang benar-benar datang, dan harus muncul di laporan
       penerimaan seperti barang masuk lainnya. */
    if ($aksi === 'masuk_gudang_toko') {
        if (!$bolehGudang) {
            $errors[] = 'Mencatat barang masuk ke gudang toko adalah wewenang tingkat Manager.';
        } else {
            /* Logikanya di _masuk_gudang_toko.php, dipakai bersama dengan
               menu Barang Masuk (penerimaan.php). Ditulis dua kali, salah
               satunya cepat atau lambat lupa memeriksa raknya. */
            $h = simpan_masuk_gudang_toko($pdo, $_POST, $u);
            if ($h['ok']) {
                set_flash('ok', $h['pesan']);
                header('Location: dasbor_toko.php?tab=gudang'); exit;
            }
            $errors = array_merge($errors, $h['errors']);
        }
        $tab = 'gudang';
    }

    if ($aksi === 'distribusi') {
        /* Dijaga di SERVER, bukan hanya dengan menyembunyikan formulirnya.
           Formulir yang tidak dirender masih bisa dikirim ulang oleh siapa
           pun yang pernah melihatnya sebagai manager lalu diturunkan. */
        if (!$bolehGudang) {
            $errors[] = 'Distribusi ke toko adalah wewenang tingkat Manager.';
        }

        $rakAsal  = (int) ($_POST['rak_asal'] ?? 0);
        $tokoTuju = (int) ($_POST['toko_tujuan'] ?? 0);
        $barangId = (int) ($_POST['d_barang_id'] ?? 0);
        $qty      = (int) ($_POST['d_qty'] ?? 0);
        $catatanD = trim($_POST['d_catatan'] ?? '');
        /* Tanggal dokumen — aturan yang SAMA dengan formulir Perpindahan,
           lewat fungsi bersama di _lib.php. */
        $tglCek    = tanggal_dokumen_sah($_POST['d_tanggal'] ?? null);
        if ($tglCek['galat']) { $errors[] = $tglCek['galat']; }
        $tglSimpanD = $tglCek['simpan'];

        $rakBoleh = rak_gudang_toko($pdo);

        if (!in_array($rakAsal, array_map('intval', $rakBoleh), true)) {
            $errors[] = 'Rak asal bukan milik gudang toko.';
        }
        if (!in_array($tokoTuju, $bolehId, true)) {
            $errors[] = 'Toko tujuan bukan toko yang Anda pegang.';
        }
        if (!$barangId || $qty <= 0) {
            $errors[] = 'Pilih barang dan isi jumlahnya.';
        }

        // Jangan pernah menerbitkan dokumen untuk jumlah yang tidak ada.
        if (!$errors) {
            $ada = (int) sk($pdo,
                "SELECT COALESCE(SUM(qty_tersisa),0) FROM batch_barang
                 WHERE barang_id = ? AND lokasi_rak_id = ? AND qty_tersisa > 0",
                [$barangId, $rakAsal]);
            if ($qty > $ada) {
                $errors[] = 'Stok di gudang toko hanya ' . angka($ada) . ' unit, diminta ' . angka($qty) . '.';
            }
        }

        if (!$errors) {
            try {
                $hasil = dengan_nomor_dokumen($pdo, 'perpindahan', 'no_perpindahan', 'PP',
                    function (string $no) use ($pdo, $rakAsal, $tokoTuju, $barangId, $qty, $catatanD, $u, $tglSimpanD) {
                        $pdo->beginTransaction();
                        $pdo->prepare(
                            "INSERT INTO perpindahan
                               (no_perpindahan, jenis, tanggal, asal_tipe, asal_id,
                                tujuan_tipe, tujuan_id, no_resi, status, user_id, catatan)
                             VALUES (?,'kirim_toko',?,'rak',?,'toko',?,?,'menunggu_persetujuan',?,?)"
                        )->execute([$no, $tglSimpanD, $rakAsal, $tokoTuju, $no, $u['id'], $catatanD ?: null]);
                        $ppId = (int) $pdo->lastInsertId();

                        // Batch tidak ditentukan di sini. FEFO memilihnya saat
                        // dokumen diterima, sama seperti pengiriman dari gudang
                        // pusat — supaya yang paling dekat kedaluwarsa keluar
                        // lebih dulu, bukan yang paling mudah diambil.
                        $pdo->prepare(
                            "INSERT INTO perpindahan_detail (perpindahan_id, barang_id, qty, harga, subtotal)
                             SELECT ?, ?, ?, b.harga, b.harga * ? FROM barang b WHERE b.id = ?"
                        )->execute([$ppId, $barangId, $qty, $qty, $barangId]);

                        $pdo->commit();
                        return ['id' => $ppId, 'no' => $no];
                    });

                catat_aktivitas($pdo, 'create', 'perpindahan', $hasil['id'],
                    "Distribusi gudang toko → toko #$tokoTuju: $qty unit");
                set_flash('ok', 'Dokumen distribusi ' . $hasil['no'] . ' dibuat dan menunggu persetujuan. '
                              . 'Stok berpindah setelah disetujui dan diterima toko.');
                header('Location: dasbor_toko.php?tab=gudang'); exit;

            } catch (Throwable $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                $errors[] = pesan_error_aman($e, 'distribusi gudang toko');
            }
        }
        $tab = 'gudang';
    }
}

// ==================================================================== DATA
/** Query yang tidak berparameter cukup memanggil sk($pdo, $sql). */
function sk(PDO $pdo, string $sql, array $p = []) {
    try { $st = $pdo->prepare($sql); $st->execute($p); return $st->fetchColumn(); }
    catch (Throwable $e) { error_log('[AMANAH] dasbor toko: ' . $e->getMessage()); return 0; }
}
/* $p bernilai bawaan [], sama seperti sk() di atasnya.

   Tanpa bawaan itu, pemanggilan tanpa parameter melempar
   ArgumentCountError — galat fatal, bukan peringatan, sehingga
   halamannya mati dengan HTTP 500 tanpa satu byte pun tercetak.

   Yang membuatnya lolos sekian lama: satu-satunya pemanggilan yang
   kurang argumen ada di balik syarat $gdToko ? … : [], jadi ia baru
   berjalan pada akun toko yang PUNYA gudang terkait. Selama semua
   akun uji tidak punya, cabang itu tidak pernah tersentuh.

   Diperbaiki di definisinya, bukan di pemanggilnya: kedua fungsi
   bersaudara ini sekarang punya kontrak yang sama, sehingga
   pemanggilan berikutnya tidak bisa jatuh ke lubang yang sama. */
function bk(PDO $pdo, string $sql, array $p = []): array {
    try { $st = $pdo->prepare($sql); $st->execute($p); return $st->fetchAll(); }
    catch (Throwable $e) { error_log('[AMANAH] dasbor toko: ' . $e->getMessage()); return []; }
}

// ---------------------------------------------------------------------
// GUDANG TOKO — simpul sebelum barang dipecah ke tiap toko
// ---------------------------------------------------------------------
$gdToko  = gudang_toko_saya($pdo);
$rakToko = rak_gudang_toko($pdo);
$inRak   = $rakToko ? implode(',', array_map('intval', $rakToko)) : '0';

/* Pilihan formulir barang masuk. Hanya diambil bila formulirnya memang
   akan dirender — akun staff tidak perlu memicu tiga kueri untuk isian
   yang tidak pernah muncul di layarnya. */
$pilihanMasuk = $bolehGudang
    ? pilihan_masuk_gudang_toko($pdo)
    : ['rak' => [], 'barang' => [], 'supplier' => []];

$gdStokTotal = (int) sk($pdo, "SELECT COALESCE(SUM(qty_tersisa),0) FROM batch_barang
                               WHERE lokasi_rak_id IN ($inRak) AND qty_tersisa > 0");
$gdJenis     = (int) sk($pdo, "SELECT COUNT(DISTINCT barang_id) FROM batch_barang
                               WHERE lokasi_rak_id IN ($inRak) AND qty_tersisa > 0");
/* Daftar rak gudang toko beserta isinya — untuk pengelola rak di tab
   Gudang. Isinya ikut dihitung karena itu yang menentukan rak mana boleh
   dihapus, dan menampilkan tombol Hapus yang pasti ditolak server hanya
   memindahkan penjelasannya ke tempat yang lebih buruk. */
$gdDaftarRak = $gdToko ? bk($pdo,
    "SELECT lr.id, lr.kode, lr.nama, lr.kapasitas, lr.keterangan,
            lr.gudang_id, g.nama AS nama_gudang,
            COALESCE(SUM(bb.qty_tersisa),0) AS qty,
            COUNT(DISTINCT CASE WHEN bb.qty_tersisa > 0 THEN bb.barang_id END) AS jenis
       FROM lokasi_rak lr
       JOIN gudang g       ON g.id = lr.gudang_id
  LEFT JOIN batch_barang bb ON bb.lokasi_rak_id = lr.id AND bb.qty_tersisa > 0
      WHERE lr.gudang_id IN (" . implode(',', array_map('intval', array_column($gdToko, 'id'))) . ")
        AND lr.deleted_at IS NULL
      GROUP BY lr.id
      ORDER BY lr.kode") : [];

/* Rak yang sedang disunting, bila ada. Diambil dari daftar yang sudah
   dibaca di atas — bukan kueri kedua — sehingga rak di luar gudang toko
   tidak mungkin ikut terbuka lewat ?rak= di alamat. */
$gdRakEdit = null;
if (($_GET['rak'] ?? '') !== '') {
    $cariRak = (int) $_GET['rak'];
    foreach ($gdDaftarRak as $r) {
        if ((int) $r['id'] === $cariRak) { $gdRakEdit = $r; break; }
    }
}

/* Dokumen distribusi yang belum tuntas — dari rak gudang toko ke toko. */
$gdMenunggu  = (int) sk($pdo, "SELECT COUNT(*) FROM perpindahan
                               WHERE jenis = 'kirim_toko' AND asal_tipe = 'rak'
                                 AND asal_id IN ($inRak)
                                 AND status IN ('menunggu_persetujuan','disetujui','dikirim')");

/* Isi gudang toko, dikelompokkan per barang DAN per rak: formulir
   distribusi butuh tahu rak asalnya, bukan hanya jumlah totalnya.

   TETAP TANPA LIMIT, dan itu disengaja. Daftar ini mengisi <select> pada
   formulir "Distribusikan ke Toko" — memotongnya berarti sebagian stok
   diam-diam tidak bisa didistribusikan sama sekali, kegagalan yang jauh
   lebih buruk daripada daftar yang panjang. Yang dipotong TABELNYA, lewat
   $gdStokTampil di bawah. */
$gdStok = bk($pdo,
    "SELECT bb.barang_id, bb.lokasi_rak_id AS rak_id, lr.nama AS nama_rak,
            b.kode, b.nama, b.satuan,
            COALESCE(SUM(bb.qty_tersisa),0) AS qty,
            MIN(bb.tanggal_expired) AS exp_terdekat
     FROM batch_barang bb
     JOIN barang b      ON b.id = bb.barang_id
     LEFT JOIN lokasi_rak lr ON lr.id = bb.lokasi_rak_id
     WHERE bb.lokasi_rak_id IN ($inRak) AND bb.qty_tersisa > 0
     GROUP BY bb.lokasi_rak_id, b.id
     ORDER BY b.nama", []);

/* ---------------------------------------------------------------------
   Baris yang benar-benar DIGAMBAR di tabel "Isi Gudang Toko".

   Kenapa perlu dipisah: gudang toko ini berisi 1.192 jenis barang, dan
   karena dikelompokkan per rak, satu barang yang tersimpan di dua rak
   menghasilkan dua baris. Seluruhnya digambar sekaligus menjadi tabel
   ribuan baris di dalam sebuah kartu dasbor — halaman yang harus digulir
   sangat panjang hanya untuk melewati satu kartu.

   URUTANNYA JUGA DIPERBAIKI. Subjudul kartunya berbunyi "batch terdekat
   kedaluwarsa keluar lebih dulu", tetapi $gdStok diurutkan ORDER BY
   b.nama — abjad. Judul yang menjanjikan urutan kedaluwarsa sementara
   isinya urut abjad membuat orang menyimpulkan barang paling mendesak
   ada di baris atas, padahal yang di atas hanya yang namanya berawalan A.

   Yang null ditaruh PALING BELAKANG: barang tanpa tanggal kedaluwarsa
   bukan yang paling mendesak, sedangkan MySQL mengurutkan NULL lebih
   dulu pada ASC. */
usort($gdStok, function ($x, $y) {
    $ex = $x['exp_terdekat'] ?: '9999-12-31';
    $ey = $y['exp_terdekat'] ?: '9999-12-31';
    return $ex === $ey ? strcasecmp($x['nama'], $y['nama']) : strcmp($ex, $ey);
});

const GD_PER_HAL = 25;
$gdBaris   = count($gdStok);
$gdHalTot  = max(1, (int) ceil($gdBaris / GD_PER_HAL));
/* Halaman dijepit ke rentang yang ada. Tanpa jepitan, ?gh=999 di URL
   menghasilkan tabel kosong pada gudang yang jelas berisi. */
$gdHal     = min($gdHalTot, max(1, (int) ($_GET['gh'] ?? 1)));
$gdStokTampil = array_slice($gdStok, ($gdHal - 1) * GD_PER_HAL, GD_PER_HAL);

$gdDist = bk($pdo,
    "SELECT p.no_perpindahan, p.tanggal, p.status, tk.nama AS nama_toko,
            (SELECT COALESCE(SUM(d.qty),0) FROM perpindahan_detail d
              WHERE d.perpindahan_id = p.id) AS unit
     FROM perpindahan p
     LEFT JOIN toko tk ON tk.id = p.tujuan_id AND p.tujuan_tipe = 'toko'
     WHERE p.jenis = 'kirim_toko' AND p.asal_tipe = 'rak' AND p.asal_id IN ($inRak)
     ORDER BY p.tanggal DESC, p.id DESC LIMIT 10", []);

/* Enam angka ringkas yang dulu dihitung per-toko DIHAPUS dari sini.
   Nilainya sama persis dengan $aStok / $aJenis / $aMasuk / $aOmzetHari /
   $aOmzetBln / $aPesananBln yang sudah dihitung untuk seluruh lingkup di
   blok bawah — jadi menghitungnya dua kali hanya menambah enam query per
   pemuatan halaman tanpa memberi angka baru.

   Ikut terhapus: $topJual. Query itu ternyata tidak pernah dipakai di
   tampilan sama sekali ($aTopJual yang dipakai) — jadi sudah lama
   berjalan tanpa hasil yang dibaca siapa pun. */

/* Stok kini memuat SEMUA toko dalam lingkup, dikelompokkan per toko dan
   per barang, sehingga satu tabel menjawab "barang ini ada di mana saja
   dan berapa" tanpa berpindah halaman. */
$stokList = bk($pdo,
    "SELECT bb.toko_id, tk.nama AS nama_toko, tk.marketplace,
            b.id, b.kode, b.nama, b.satuan, b.stok_minimum, b.harga,
            COALESCE(SUM(bb.qty_tersisa),0) AS qty,
            MIN(bb.tanggal_expired) AS exp_terdekat
     FROM batch_barang bb
     JOIN barang b ON b.id = bb.barang_id
     JOIN toko  tk ON tk.id = bb.toko_id
     WHERE bb.toko_id IN ($inLingkup) AND bb.qty_tersisa > 0
     GROUP BY bb.toko_id, b.id
     ORDER BY b.nama, tk.kode", []);

$jualList = bk($pdo,
    "SELECT p.*, tk.nama AS nama_toko,
            (SELECT COALESCE(SUM(d.qty),0) FROM penjualan_detail d WHERE d.penjualan_id = p.id) AS jml_unit,
            (SELECT COALESCE(SUM(d.subtotal),0) FROM penjualan_detail d WHERE d.penjualan_id = p.id) AS nilai
     FROM penjualan p JOIN toko tk ON tk.id = p.toko_id
     WHERE p.toko_id IN ($inLingkup)
     ORDER BY p.tanggal DESC, p.id DESC LIMIT 60", []);

/* ---------------------------------------------- PESANAN MARKETPLACE
   Dikelompokkan per status supaya pertanyaan "mana yang perlu dikerjakan
   sekarang" bisa dijawab tanpa memindai seluruh daftar. Urutannya
   mengikuti perjalanan pesanan, bukan abjad. */
$mpUrut = [
    'baru'    => ['Perlu diproses',  't-amber',  'Sudah dibayar, belum dikemas'],
    'dikemas' => ['Sedang dikemas',   't-blue',   'Siap diserahkan ke kurir'],
    'dikirim' => ['Di jalan',         't-purple', 'Sudah diserahkan ke kurir'],
    'selesai' => ['Sampai tujuan',    't-green',  'Diterima pembeli'],
    'batal'   => ['Batal',            't-gray',   'Dibatalkan atau dikembalikan'],
];

$mpHitung = [];
$mpPesanan = [];
$mpUang = ['ditahan' => 0.0, 'cair' => 0.0, 'belum' => 0.0];
$mpAdaKolom = true;
try {
    foreach ($pdo->query(
        "SELECT status, COUNT(*) n FROM penjualan
         WHERE toko_id IN ($inLingkup) AND sumber = 'api' GROUP BY status") as $r) {
        $mpHitung[$r['status']] = (int) $r['n'];
    }

    $mpPesanan = $pdo->query(
        "SELECT p.*, tk.nama AS nama_toko
         FROM penjualan p JOIN toko tk ON tk.id = p.toko_id
         WHERE p.toko_id IN ($inLingkup) AND p.sumber = 'api'
         ORDER BY FIELD(p.status,'baru','dikemas','dikirim','selesai','batal'),
                  p.tanggal DESC LIMIT 80")->fetchAll();

    /* Uang dipisah menurut status pembayaran, BUKAN dijumlahkan jadi satu.
       "Ditahan" adalah uang yang sudah dibayar pembeli tapi belum bisa
       dipakai; menjumlahkannya dengan yang sudah cair membuat toko
       menghitung uang yang belum ada di tangannya. */
    foreach ($pdo->query(
        "SELECT status_bayar, COALESCE(SUM(escrow_amount),0) jml
         FROM penjualan WHERE toko_id IN ($inLingkup) AND sumber = 'api'
         GROUP BY status_bayar") as $r) {
        if (isset($mpUang[$r['status_bayar']])) { $mpUang[$r['status_bayar']] = (float) $r['jml']; }
    }
} catch (Throwable $e) {
    // Kolom marketplace belum dimigrasi. Tab-nya tetap terbuka dan
    // menjelaskan apa yang harus dilakukan, bukan menampilkan galat.
    $mpAdaKolom = false;
    error_log('[AMANAH] tab pesanan: ' . $e->getMessage());
}

// =====================================================================
// AGREGAT SELURUH LINGKUP — dipakai tab Ringkasan
//
// $inLingkup dibangun dari id yang sudah lolos toko_saya(), lalu di-cast
// (int) satu per satu. Aman disisipkan langsung, dan menghindari jumlah
// placeholder yang berubah-ubah di setiap query.
// =====================================================================
$aStok = (int) sk($pdo, "SELECT COALESCE(SUM(qty_tersisa),0) FROM batch_barang
                         WHERE toko_id IN ($inLingkup) AND qty_tersisa > 0");
$aJenis = (int) sk($pdo, "SELECT COUNT(DISTINCT barang_id) FROM batch_barang
                          WHERE toko_id IN ($inLingkup) AND qty_tersisa > 0");
$aMasuk = (int) sk($pdo, "SELECT COUNT(*) FROM perpindahan
                          WHERE tujuan_tipe='toko' AND tujuan_id IN ($inLingkup)
                            AND status IN ('dikirim','disetujui')");
$aBaru  = (int) sk($pdo, "SELECT COUNT(*) FROM penjualan
                          WHERE toko_id IN ($inLingkup) AND status='baru'");
$aOmzetHari = (float) sk($pdo,
    "SELECT COALESCE(SUM(d.subtotal),0) FROM penjualan p
     JOIN penjualan_detail d ON d.penjualan_id = p.id
     WHERE p.toko_id IN ($inLingkup) AND p.status <> 'batal' AND DATE(p.tanggal) = CURDATE()");
$aOmzetBln = (float) sk($pdo,
    "SELECT COALESCE(SUM(d.subtotal),0) FROM penjualan p
     JOIN penjualan_detail d ON d.penjualan_id = p.id
     WHERE p.toko_id IN ($inLingkup) AND p.status <> 'batal'
       AND YEAR(p.tanggal)=YEAR(CURDATE()) AND MONTH(p.tanggal)=MONTH(CURDATE())");
$aPesananBln = (int) sk($pdo,
    "SELECT COUNT(*) FROM penjualan WHERE toko_id IN ($inLingkup) AND status <> 'batal'
       AND YEAR(tanggal)=YEAR(CURDATE()) AND MONTH(tanggal)=MONTH(CURDATE())");

/** Ringkasan per toko — inilah pengganti "halaman Shopee" & "halaman Tokopedia". */
$perToko = bk($pdo,
    "SELECT tk.id, tk.kode, tk.nama, tk.marketplace,
            (SELECT COALESCE(SUM(bb.qty_tersisa),0) FROM batch_barang bb
              WHERE bb.toko_id = tk.id AND bb.qty_tersisa > 0) AS stok,
            (SELECT COUNT(*) FROM penjualan p
              WHERE p.toko_id = tk.id AND p.status = 'baru') AS baru,
            (SELECT COUNT(*) FROM penjualan p
              WHERE p.toko_id = tk.id AND p.status <> 'batal'
                AND YEAR(p.tanggal)=YEAR(CURDATE()) AND MONTH(p.tanggal)=MONTH(CURDATE())) AS pesanan,
            (SELECT COALESCE(SUM(d.subtotal),0) FROM penjualan p
              JOIN penjualan_detail d ON d.penjualan_id = p.id
              WHERE p.toko_id = tk.id AND p.status <> 'batal'
                AND YEAR(p.tanggal)=YEAR(CURDATE()) AND MONTH(p.tanggal)=MONTH(CURDATE())) AS omzet
     FROM toko tk WHERE tk.id IN ($inLingkup) ORDER BY omzet DESC, tk.kode", []);

/** Omzet per kanal — menjawab "Shopee atau Tokopedia yang lebih jalan?" */
$perKanal = bk($pdo,
    "SELECT tk.marketplace, COUNT(DISTINCT tk.id) AS toko,
            COUNT(DISTINCT p.id) AS pesanan,
            COALESCE(SUM(d.qty),0) AS unit,
            COALESCE(SUM(d.subtotal),0) AS omzet
     FROM toko tk
     LEFT JOIN penjualan p        ON p.toko_id = tk.id AND p.status <> 'batal'
     LEFT JOIN penjualan_detail d ON d.penjualan_id = p.id
     WHERE tk.id IN ($inLingkup)
     GROUP BY tk.marketplace ORDER BY omzet DESC", []);

$aTopJual = bk($pdo,
    "SELECT b.nama, b.satuan, COALESCE(SUM(d.qty),0) AS unit, COALESCE(SUM(d.subtotal),0) AS nilai
     FROM penjualan p JOIN penjualan_detail d ON d.penjualan_id = p.id
     JOIN barang b ON b.id = d.barang_id
     WHERE p.toko_id IN ($inLingkup) AND p.status <> 'batal'
       AND p.tanggal >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
     GROUP BY b.id ORDER BY unit DESC LIMIT 8", []);

$aPesananBaru = bk($pdo,
    "SELECT p.no_pesanan, p.nama_pembeli, p.marketplace, p.tanggal, p.status,
            tk.nama AS nama_toko,
            (SELECT COALESCE(SUM(d.qty),0) FROM penjualan_detail d WHERE d.penjualan_id = p.id) AS unit,
            (SELECT COALESCE(SUM(d.subtotal),0) FROM penjualan_detail d WHERE d.penjualan_id = p.id) AS nilai
     FROM penjualan p JOIN toko tk ON tk.id = p.toko_id
     WHERE p.toko_id IN ($inLingkup)
     ORDER BY p.tanggal DESC, p.id DESC LIMIT 8", []);

$aPerhatian = bk($pdo,
    "SELECT b.nama, b.satuan, b.stok_minimum, tk.nama AS nama_toko,
            COALESCE(SUM(bb.qty_tersisa),0) AS qty,
            MIN(bb.tanggal_expired) AS exp_terdekat
     FROM batch_barang bb
     JOIN barang b ON b.id = bb.barang_id
     JOIN toko tk  ON tk.id = bb.toko_id
     WHERE bb.toko_id IN ($inLingkup) AND bb.qty_tersisa > 0
     GROUP BY bb.toko_id, b.id
     HAVING qty <= b.stok_minimum
         OR (exp_terdekat IS NOT NULL AND DATEDIFF(exp_terdekat, CURDATE()) <= 90)
     -- MariaDB menolak alias agregat di ORDER BY ('reference to group
     -- function'), jadi agregatnya ditulis ulang di sini. HAVING boleh
     -- memakai alias, ORDER BY tidak.
     ORDER BY MIN(bb.tanggal_expired) IS NULL,
              MIN(bb.tanggal_expired) ASC,
              SUM(bb.qty_tersisa) ASC
     LIMIT 8", []);

// Pemilih toko di kanan atas.
$semuaToko = bk($pdo, "SELECT id, nama, marketplace FROM toko WHERE id IN ("
    . implode(',', array_map('intval', $daftarToko)) . ") ORDER BY kode", []);

ob_start(); ?>
<?php /* Saringan, bukan pemindah halaman: bawaannya "Semua Toko". */ ?>
<div class="pilih-toko">
  <span style="font-size:11px;color:var(--muted)">Toko:</span>
  <select onchange="location.href='dasbor_toko.php?tab=<?= e($tab) ?>'
                   + (this.value ? '&toko=' + this.value : '')">
    <option value="0"<?= $filterToko ? '' : ' selected' ?>>Semua Toko (<?= count($semuaToko) ?>)</option>
    <?php foreach ($semuaToko as $t): ?>
      <option value="<?= (int) $t['id'] ?>" <?= (int) $t['id'] === $filterToko ? 'selected' : '' ?>>
        <?= e($t['nama']) ?>
      </option>
    <?php endforeach; ?>
  </select>
</div>
<?php $PEMILIH_TOKO = ob_get_clean();

$PAGE_TITLE = 'Dasbor Toko';
$SUB_JUDUL  = ident($awasi ? 'Mode lihat-saja' : 'Toko', APP_LEMBAGA);
$AKTIF      = $tab;
$MENU_LUAR  = menu_toko($aMasuk, $pdo);
if ($awasi) {
    // Pengawas tidak punya menu Penerimaan — itu tindakan milik toko.
    //
    // Menu memuat DUA bentuk baris: judul bagian (['sec' => …]) dan tautan
    // (['k','i','t','h']). Dulu blok ini menganggap semuanya tautan,
    // sehingga judul bagian memicu "Undefined array key" pada setiap
    // pemuatan halaman. Sekarang tiap bentuk ditangani terpisah.
    $MENU_LUAR = array_values(array_filter(
        $MENU_LUAR,
        fn($m) => isset($m['sec']) || !in_array($m['k'] ?? '', ['terima', 'impor'], true)
    ));
    foreach ($MENU_LUAR as $i => $m) {
        if (!isset($m['h'])) { continue; }   // judul bagian, bukan tautan
        // Tautan bisa sudah punya query ("?tab=stok") atau belum.
        $pemisah = str_contains($m['h'], '?') ? '&' : '?';
        $MENU_LUAR[$i]['h'] = $m['h'] . $pemisah . 'toko=' . $tokoId;
    }
}
require __DIR__ . '/_header_luar.php';

$mpOpts = ['shopee', 'tokopedia', 'tiktok', 'lazada', 'offline', 'lainnya'];
?>

<?php if ($awasi): ?>
  <div class="flash flash-err" style="display:flex;gap:12px;align-items:center;flex-wrap:wrap">
    <span>👁️ <b>Mode lihat-saja</b> — Anda sedang membuka <b><?= $toko ? e($toko['nama']) : 'dasbor semua toko' ?></b>.
      Pencatatan penjualan dan penerimaan harus dilakukan admin toko sendiri.</span>
    <a class="btn btn-ghost btn-sm" href="pengawasan.php">← Mitra &amp; Lokasi</a>
  </div>
<?php endif; ?>

<?php if ($errors): ?>
  <div class="flash flash-err"><?php foreach ($errors as $er) echo '• ' . e($er) . '<br>'; ?></div>
<?php endif; ?>

<?php if ($aMasuk > 0 && !$awasi): ?>
  <div class="flash flash-ok" style="display:flex;gap:12px;align-items:center;flex-wrap:wrap">
    <span>📥 Ada <b><?= $aMasuk ?></b> kiriman dari gudang menunggu diterima</span>
    <a class="btn btn-primary btn-sm" href="penerimaan.php">Terima Sekarang</a>
  </div>
<?php endif; ?>

<?php /* Angka di sini kini mencakup SELURUH toko dalam lingkup, bukan satu
         toko terpilih — memakai nilai agregat yang memang sudah dihitung. */ ?>
<div class="kpi">
  <div class="card"><div class="v"><?= angka($aStok) ?></div>
    <div class="l">📦 Stok toko (unit) · <?= $aJenis ?> jenis<?php
      if (!$filterToko && $nToko > 1) { echo ' · ' . $nToko . ' toko'; } ?></div></div>
  <div class="card"><div class="v" style="color:var(--green);font-size:19px"><?= rupiah($aOmzetHari) ?></div>
    <div class="l">💰 Omzet hari ini</div></div>
  <div class="card"><div class="v" style="color:var(--primary);font-size:19px"><?= rupiah($aOmzetBln) ?></div>
    <div class="l">📈 Omzet bulan ini</div></div>
  <div class="card"><div class="v"><?= angka($aPesananBln) ?></div>
    <div class="l">🧾 Pesanan bulan ini</div></div>
</div>

<?php /* ================================================== TAB: RINGKAS */ ?>
<?php if ($tab === 'ringkas'): ?>

  <?php /* Chip filter kanal — menggantikan halaman Shopee & Tokopedia yang
           terpisah. Hanya kanal yang benar-benar dimiliki yang tampil. */ ?>
  <?php if (count($kanalSaya) > 1 || $filterMp !== ''): ?>
    <div class="saring">
      <span class="saring-lb">Kanal</span>
      <a href="dasbor_toko.php?tab=ringkas<?= $awasi ? '&toko=' . (int) $tokoId : '' ?>"
         class="<?= $filterMp === '' ? 'on' : '' ?>">
        Semua <span class="n"><?= count($tokoRows) ?></span>
      </a>
      <?php foreach ($kanalSaya as $mp => $jml): [$lbl, $cls] = label_marketplace($mp); ?>
        <a href="dasbor_toko.php?tab=ringkas&mp=<?= e($mp) ?><?= $awasi ? '&toko=' . (int) $tokoId : '' ?>"
           class="<?= $filterMp === $mp ? 'on' : '' ?>">
          <?= e($lbl) ?> <span class="n"><?= (int) $jml ?></span>
        </a>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>

  <div class="kpi">
    <div class="kpi-card" style="--kc:var(--green)">
      <div class="kpi-top"><span class="kpi-l">Omzet Hari Ini</span>
        <span class="kpi-ic"><svg aria-hidden="true"><use href="#i-uang"></use></svg></span></div>
      <div class="kpi-v kecil"><?= rupiah($aOmzetHari) ?></div>
      <div class="kpi-d"><?= $nToko ?> toko<?= $filterMp ? ' · ' . e(label_marketplace($filterMp)[0]) : '' ?></div>
    </div>
    <div class="kpi-card" style="--kc:var(--primary-2)">
      <div class="kpi-top"><span class="kpi-l">Omzet Bulan Ini</span>
        <span class="kpi-ic"><svg aria-hidden="true"><use href="#i-grafik"></use></svg></span></div>
      <div class="kpi-v kecil"><?= rupiah($aOmzetBln) ?></div>
      <div class="kpi-d"><?= angka($aPesananBln) ?> pesanan</div>
    </div>
    <div class="kpi-card" style="--kc:var(--amber)">
      <div class="kpi-top"><span class="kpi-l">Pesanan Baru</span>
        <span class="kpi-ic"><svg aria-hidden="true"><use href="#i-doc"></use></svg></span></div>
      <div class="kpi-v"><?= angka($aBaru) ?></div>
      <div class="kpi-d">Belum diproses</div>
    </div>
    <div class="kpi-card" style="--kc:var(--accent)">
      <div class="kpi-top"><span class="kpi-l">Kiriman Masuk</span>
        <span class="kpi-ic"><svg aria-hidden="true"><use href="#i-paket"></use></svg></span></div>
      <div class="kpi-v"><?= angka($aMasuk) ?></div>
      <div class="kpi-d">Menunggu diterima</div>
    </div>
    <div class="kpi-card" style="--kc:var(--info)">
      <div class="kpi-top"><span class="kpi-l">Stok Toko</span>
        <span class="kpi-ic"><svg aria-hidden="true"><use href="#i-layers"></use></svg></span></div>
      <div class="kpi-v"><?= angka($aStok) ?></div>
      <div class="kpi-d"><?= angka($aJenis) ?> jenis produk</div>
    </div>
  </div>

  <div class="dash-grid">
    <div class="card">
      <h3>Ringkasan per Toko</h3>
      <div class="sub">Semua kanal dalam satu tabel · omzet bulan ini</div>
      <?php if (!$perToko): ?>
        <div class="empty">Belum ada toko dalam saringan ini.</div>
      <?php else: ?>
        <div class="tbl-wrap"><table class="tbl-kartu">
          <thead><tr><th>Toko</th><th>Kanal</th><th class="num">Stok</th>
            <th class="num">Baru</th><th class="num">Pesanan</th><th class="num">Omzet</th><th></th></tr></thead>
          <tbody>
          <?php foreach ($perToko as $t): [$lbl, $cls] = label_marketplace($t['marketplace']); ?>
            <tr>
              <td data-label="Toko"><b><?= e($t['nama']) ?></b></td>
              <td data-label="Kanal"><span class="tag <?= $cls ?>"><?= e($lbl) ?></span></td>
              <td data-label="Stok" class="num"><?= angka($t['stok']) ?></td>
              <td data-label="Baru" class="num">
                <?php if ((int) $t['baru'] > 0): ?>
                  <span class="tag t-amber"><?= angka($t['baru']) ?></span>
                <?php else: ?><span class="mut2">0</span><?php endif; ?>
              </td>
              <td data-label="Pesanan" class="num sekunder"><?= angka($t['pesanan']) ?></td>
              <td data-label="Omzet" class="num"><b><?= rupiah($t['omzet']) ?></b></td>
              <td class="aksi">
                <a class="btn btn-ghost btn-sm"
                   href="dasbor_toko.php?tab=jual&toko=<?= (int) $t['id'] ?>">Buka</a>
              </td>
            </tr>
          <?php endforeach; ?>
          </tbody>
          <tfoot><tr><td>Total</td><td></td>
            <td class="num"><?= angka(array_sum(array_column($perToko, 'stok'))) ?></td>
            <td class="num"><?= angka(array_sum(array_column($perToko, 'baru'))) ?></td>
            <td class="num"><?= angka(array_sum(array_column($perToko, 'pesanan'))) ?></td>
            <td class="num"><?= rupiah(array_sum(array_column($perToko, 'omzet'))) ?></td>
            <td></td></tr></tfoot>
        </table></div>
      <?php endif; ?>
    </div>

    <div class="card">
      <h3>Perbandingan Kanal</h3>
      <div class="sub">Shopee, Tokopedia, dan lainnya berdampingan</div>
      <?php if (!$perKanal): ?>
        <div class="empty">Belum ada data kanal.</div>
      <?php else: $maksK = max(array_map('floatval', array_column($perKanal, 'omzet'))) ?: 1; ?>
        <div class="tbl-wrap"><table class="tbl-kartu">
          <thead><tr><th>Kanal</th><th class="num">Toko</th><th class="num">Pesanan</th>
            <th class="num">Unit</th><th class="num">Omzet</th><th>Porsi</th></tr></thead>
          <tbody>
          <?php foreach ($perKanal as $k): [$lbl, $cls] = label_marketplace($k['marketplace']);
            $persen = $maksK > 0 ? max(2, round((float) $k['omzet'] / $maksK * 100)) : 0; ?>
            <tr>
              <td data-label="Kanal"><span class="tag <?= $cls ?>"><?= e($lbl) ?></span></td>
              <td data-label="Toko" class="num"><?= angka($k['toko']) ?></td>
              <td data-label="Pesanan" class="num sekunder"><?= angka($k['pesanan']) ?></td>
              <td data-label="Unit" class="num"><?= angka($k['unit']) ?></td>
              <td data-label="Omzet" class="num"><b><?= rupiah($k['omzet']) ?></b></td>
              <td data-label="Porsi"><span class="barmini"><i
                style="width:<?= $persen ?>%;background:var(--primary-2)"></i></span></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table></div>
      <?php endif; ?>
    </div>
  </div>

  <div class="dash-grid">
    <div class="card">
      <h3>Pesanan Terbaru</h3>
      <div class="sub">8 pesanan terakhir dari seluruh kanal</div>
      <?php if (!$aPesananBaru): ?>
        <div class="empty-state">
          <div class="ic"><svg aria-hidden="true"><use href="#i-doc"></use></svg></div>
          <b>Belum ada pesanan</b>
          <p>Pesanan muncul setelah dicatat manual atau diimpor dari berkas marketplace.</p>
          <div class="aksi"><a class="btn btn-primary btn-sm" href="impor_pesanan.php">Impor Pesanan</a></div>
        </div>
      <?php else: ?>
        <div class="tbl-wrap"><table class="tbl-kartu">
          <thead><tr><th>No. Pesanan</th><th>Toko</th><th>Kanal</th>
            <th class="num">Unit</th><th class="num">Nilai</th><th>Status</th><th>Tanggal</th></tr></thead>
          <tbody>
          <?php foreach ($aPesananBaru as $o): [$lbl, $cls] = label_marketplace($o['marketplace']);
            $petaSc = ['baru' => 't-amber', 'dikemas' => 't-purple',
                       'dikirim' => 't-blue', 'selesai' => 't-green'];
            $sc = $petaSc[$o['status']] ?? 't-gray'; ?>
            <tr>
              <td data-label="No. Pesanan"><b><?= e($o['no_pesanan'] ?: '—') ?></b><br>
                <small class="mut2"><?= e($o['nama_pembeli'] ?: '') ?></small></td>
              <td data-label="Toko"><?= e($o['nama_toko']) ?></td>
              <td data-label="Kanal"><span class="tag <?= $cls ?>"><?= e($lbl) ?></span></td>
              <td data-label="Unit" class="num"><?= angka($o['unit']) ?></td>
              <td data-label="Nilai" class="num"><b><?= rupiah($o['nilai']) ?></b></td>
              <td data-label="Status"><span class="tag <?= $sc ?>"><?= e(ucfirst($o['status'])) ?></span></td>
              <td data-label="Tanggal"><?= e(tgl_id($o['tanggal'])) ?></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table></div>
      <?php endif; ?>
    </div>

    <div class="card">
      <h3>Barang Paling Laku</h3>
      <div class="sub">30 hari terakhir · seluruh kanal dalam saringan</div>
      <?php if (!$aTopJual): ?>
        <div class="empty">Belum ada penjualan dalam 30 hari terakhir.</div>
      <?php else: $maksU = max(array_map('intval', array_column($aTopJual, 'unit'))) ?: 1; ?>
        <div class="tbl-wrap"><table class="tbl-kartu">
          <thead><tr><th>Barang</th><th class="num">Unit</th><th>Sebaran</th><th class="num">Nilai</th></tr></thead>
          <tbody>
          <?php foreach ($aTopJual as $t):
            $persen = max(2, round((int) $t['unit'] / $maksU * 100)); ?>
            <tr>
              <td data-label="Barang"><b><?= e($t['nama']) ?></b></td>
              <td data-label="Unit" class="num"><b><?= angka($t['unit']) ?></b>
                <small class="mut2"><?= e($t['satuan']) ?></small></td>
              <td data-label="Sebaran"><span class="barmini"><i
                style="width:<?= $persen ?>%;background:var(--green)"></i></span></td>
              <td data-label="Nilai" class="num"><?= rupiah($t['nilai']) ?></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table></div>
      <?php endif; ?>
    </div>
  </div>

  <div class="card">
    <h3>Perlu Perhatian</h3>
    <div class="sub">Stok menipis atau mendekati kedaluwarsa di seluruh toko dalam saringan</div>
    <?php if (!$aPerhatian): ?>
      <div class="empty">Semua stok toko dalam kondisi baik 🎉</div>
    <?php else: ?>
      <div class="tbl-wrap"><table class="tbl-kartu">
        <thead><tr><th>Barang</th><th>Toko</th><th class="num">Stok</th>
          <th class="num">Minimum</th><th>Kedaluwarsa Terdekat</th></tr></thead>
        <tbody>
        <?php foreach ($aPerhatian as $s):
          $sisaHari = $s['exp_terdekat']
              ? (int) floor((strtotime($s['exp_terdekat']) - strtotime(date('Y-m-d'))) / 86400) : null;
          $tipis = (int) $s['qty'] <= (int) $s['stok_minimum']; ?>
          <tr>
            <td data-label="Barang"><b><?= e($s['nama']) ?></b></td>
            <td data-label="Toko"><?= e($s['nama_toko']) ?></td>
            <td data-label="Stok" class="num">
              <b><?= angka($s['qty']) ?></b> <small class="mut2"><?= e($s['satuan']) ?></small>
              <?php if ($tipis): ?> <span class="tag t-amber">menipis</span><?php endif; ?>
            </td>
            <td data-label="Minimum" class="num"><?= angka($s['stok_minimum']) ?></td>
            <td data-label="Kedaluwarsa Terdekat">
              <?= $s['exp_terdekat'] ? e(tgl_id($s['exp_terdekat'])) : '<span class="mut2">—</span>' ?>
              <?php if ($sisaHari !== null && $sisaHari <= 90): ?>
                <span class="tag <?= $sisaHari < 0 ? 't-red' : ($sisaHari <= 30 ? 't-orange' : 't-amber') ?>">
                  <?= $sisaHari < 0 ? 'lewat tanggal' : $sisaHari . ' hari' ?></span>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table></div>
    <?php endif; ?>
  </div>
<?php endif; ?>

<?php /* ------------------------------------------- PESANAN MARKETPLACE
         Pesanan yang datang otomatis dari API marketplace. Tab ini hanya
         MENAMPILKAN — penarikannya dijalankan penjadwal, atau ditekan
         manual oleh Super Admin di Pengaturan > Sambungan Shopee. */ ?>
<?php if ($tab === 'pesanan'): ?>
  <?php if (!$mpAdaKolom): ?>
    <div class="card"><div class="empty-state">
      <div class="ic"><svg aria-hidden="true"><use href="#i-doc"></use></svg></div>
      <b>Kolom marketplace belum tersedia</b>
      <p>Jalankan <code>migrasi_marketplace.sql</code> lebih dulu, lalu buka
        halaman ini kembali.</p>
    </div></div>

  <?php elseif (!$mpPesanan): ?>
    <div class="card"><div class="empty-state">
      <div class="ic"><svg aria-hidden="true"><use href="#i-doc"></use></svg></div>
      <b>Belum ada pesanan dari marketplace</b>
      <p>Pesanan muncul di sini setelah toko disambungkan ke Shopee dan
        penarikan dijalankan. Pengaturannya ada di
        <b>Pengaturan &rsaquo; Sambungan Shopee</b> (Super Admin).</p>
      <p style="margin-top:8px">Pesanan yang dicatat manual atau diimpor dari
        CSV tampil di tab <b>Barang Keluar</b>, bukan di sini.</p>
    </div></div>

  <?php else: ?>
    <?php /* Uang dipisah tiga, tidak dijumlahkan. Lihat alasannya di blok
             data di bagian atas berkas. */ ?>
    <div class="kpi">
      <div class="kpi-card" style="--kc:var(--amber)">
        <div class="kpi-top"><span class="kpi-l">Masih ditahan marketplace</span>
          <span class="kpi-ic"><svg aria-hidden="true"><use href="#i-kunci"></use></svg></span></div>
        <div class="kpi-v"><?= rupiah($mpUang['ditahan']) ?></div>
        <div class="kpi-d">sudah dibayar pembeli, belum bisa dipakai</div>
      </div>
      <div class="kpi-card" style="--kc:var(--green)">
        <div class="kpi-top"><span class="kpi-l">Sudah cair</span>
          <span class="kpi-ic"><svg aria-hidden="true"><use href="#i-cek-bulat"></use></svg></span></div>
        <div class="kpi-v"><?= rupiah($mpUang['cair']) ?></div>
        <div class="kpi-d">sudah dilepas ke saldo penjual</div>
      </div>
      <div class="kpi-card" style="--kc:var(--muted)">
        <div class="kpi-top"><span class="kpi-l">Belum dibayar</span>
          <span class="kpi-ic"><svg aria-hidden="true"><use href="#i-cal"></use></svg></span></div>
        <div class="kpi-v"><?= rupiah($mpUang['belum']) ?></div>
        <div class="kpi-d">menunggu pembayaran pembeli</div>
      </div>
    </div>

    <?php /* Ringkasan per tahap: menjawab "mana yang perlu dikerjakan
             sekarang" tanpa memindai seluruh daftar di bawahnya. */ ?>
    <div class="card mb16">
      <h3>Pesanan per tahap</h3>
      <div class="sub">Mengikuti perjalanan pesanan, dari perlu diproses sampai sampai tujuan</div>
      <div class="al-tabel">
        <table>
          <thead><tr><th style="min-width:150px">Tahap</th><th>Jumlah</th><th>Artinya</th></tr></thead>
          <tbody>
          <?php foreach ($mpUrut as $st => $u): ?>
            <tr>
              <td><span class="tag <?= $u[1] ?>"><?= e($u[0]) ?></span></td>
              <td><b><?= angka($mpHitung[$st] ?? 0) ?></b></td>
              <td class="sekunder"><?= e($u[2]) ?></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>

    <div class="card">
      <h3>Daftar pesanan</h3>
      <div class="sub"><?= count($mpPesanan) ?> pesanan terbaru · diurutkan menurut tahapnya</div>
      <div class="al-tabel">
        <table>
          <thead><tr>
            <th style="min-width:150px">Pesanan</th>
            <th>Tahap</th>
            <th style="min-width:130px">Kiriman</th>
            <th>Nilai</th>
            <th style="min-width:120px">Pembayaran</th>
            <th>Ditarik</th>
          </tr></thead>
          <tbody>
          <?php
          $petaKirim = [
              'belum'    => ['Belum diproses', 't-gray'],
              'diproses' => ['Diproses',       't-blue'],
              'dikirim'  => ['Di jalan',       't-purple'],
              'sampai'   => ['Sampai',         't-green'],
              'gagal'    => ['Gagal kirim',    't-red'],
              'kembali'  => ['Dikembalikan',   't-amber'],
          ];
          $petaBayar = [
              'belum'    => ['Belum dibayar', 't-gray'],
              'ditahan'  => ['Ditahan',       't-amber'],
              'cair'     => ['Cair',          't-green'],
              'sebagian' => ['Sebagian',      't-amber'],
              'batal'    => ['Batal',         't-red'],
          ];
          foreach ($mpPesanan as $p):
            $u  = $mpUrut[$p['status']]          ?? ['—', 't-gray', ''];
            $k  = $petaKirim[$p['status_kirim']] ?? ['—', 't-gray'];
            $b  = $petaBayar[$p['status_bayar']] ?? ['—', 't-gray'];
            // Total potongan: yang paling sering ditanyakan setelah "cair berapa".
            $potongan = (float) $p['komisi'] + (float) $p['biaya_layanan']
                      + (float) $p['biaya_transaksi'] + (float) $p['voucher'];
          ?>
            <tr>
              <td class="judul" data-label="Pesanan">
                <b><?= e($p['no_pesanan'] ?: $p['no_penjualan']) ?></b>
                <br><small class="mut"><?= e(tgl_id($p['tanggal'])) ?>
                  <?php if (count($daftarToko) > 1): ?> · <?= e($p['nama_toko']) ?><?php endif; ?>
                </small>
                <?php if ($p['nama_pembeli']): ?>
                  <br><small class="mut"><?= e($p['nama_pembeli']) ?></small>
                <?php endif; ?>
              </td>
              <td data-label="Tahap"><span class="tag <?= $u[1] ?>"><?= e($u[0]) ?></span>
                <?php /* Status mentah marketplace ditampilkan juga. Itulah yang
                         dipakai saat menanyakan pesanan ke layanan pelanggan
                         Shopee — status internal tidak dikenali di sana. */ ?>
                <?php if ($p['status_marketplace']): ?>
                  <br><small class="mut"><code style="font-size:11px"><?= e($p['status_marketplace']) ?></code></small>
                <?php endif; ?></td>
              <td data-label="Kiriman"><span class="tag <?= $k[1] ?>"><?= e($k[0]) ?></span>
                <?php if ($p['no_resi']): ?>
                  <br><small class="mut"><?= e($p['kurir'] ?: 'resi') ?>:
                    <code style="font-size:11px"><?= e($p['no_resi']) ?></code></small>
                <?php endif; ?></td>
              <td data-label="Nilai"><b><?= rupiah($p['total']) ?></b>
                <?php if ($potongan > 0): ?>
                  <br><small style="color:var(--red)">&minus; <?= rupiah($potongan) ?> potongan</small>
                <?php endif; ?></td>
              <td data-label="Pembayaran"><span class="tag <?= $b[1] ?>"><?= e($b[0]) ?></span>
                <?php if ($p['escrow_amount'] !== null): ?>
                  <br><b style="font-size:13px"><?= rupiah($p['escrow_amount']) ?></b>
                <?php endif; ?>
                <?php if ($p['tgl_settlement']): ?>
                  <br><small class="mut">cair <?= e(tgl_id($p['tgl_settlement'])) ?></small>
                <?php endif; ?></td>
              <td class="sekunder" data-label="Ditarik"><?= $p['ditarik_at']
                    ? e(tgl_id($p['ditarik_at'], 'lengkap')) : '—' ?></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>

      <div class="al-catat" style="border-left-color:var(--amber)">
        <b>Angka di sini adalah salinan data marketplace</b>, bukan catatan kas.
        Untuk pembukuan, cocokkan dengan mutasi bank dan dashboard marketplace
        sebelum dipakai sebagai angka resmi. Kolom <b>Ditarik</b> menunjukkan
        kapan baris ini terakhir diperbarui — data yang lama belum tentu masih
        sesuai keadaan di marketplace.
      </div>
    </div>
  <?php endif; ?>
<?php endif; ?>

<?php /* =================================================== TAB: GUDANG */ ?>
<?php if ($tab === 'gudang'): ?>
  <?php /* Gudang toko dibuat DI SINI, bukan di Master Gudang. Dulu halaman
           ini hanya menyuruh "minta Super Admin", padahal yang paling tahu
           gudang penampungnya justru sisi toko. */ ?>
  <?php if (!$gdToko): ?>
    <div class="card">
      <div class="empty-state">
        <div class="ic"><svg aria-hidden="true"><use href="#i-gudang"></use></svg></div>
        <b>Belum ada gudang toko</b>
        <p>Gudang penampung sisi toko belum dibuat. Barang dari supplier mendarat
          di sini lebih dulu, baru dipecah ke tiap toko.</p>
      </div>
      <?php if (!$awasi): ?>
        <form method="post" style="max-width:620px;margin:0 auto">
          <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
          <input type="hidden" name="aksi" value="gudang_simpan">
          <input type="hidden" name="gudang_id" value="0">
          <div class="form-grid">
            <div class="field span-all"><label>Nama Gudang Toko <span class="req">*</span></label>
              <input name="g_nama" required placeholder="cth: Gudang Penampung Toko"></div>
            <div class="field span-all"><label>Alamat</label>
              <input name="g_alamat" placeholder="opsional"></div>
            <div class="field"><label>Penanggung Jawab</label>
              <input name="g_pj" placeholder="opsional"></div>
            <div class="field"><label>Telepon</label>
              <input name="g_telepon" placeholder="opsional"></div>
          </div>
          <button class="btn btn-primary" type="submit">
            <svg aria-hidden="true"><use href="#i-plus"></use></svg> Buat Gudang Toko
          </button>
          <p style="color:var(--muted);font-size:12px;margin-top:10px">
            Kode gudang dibuat otomatis. Satu rak penampung ikut dibuatkan,
            karena stok tersimpan per rak — tanpa rak, barang tidak bisa masuk.
          </p>
        </form>
      <?php else: ?>
        <p style="text-align:center;color:var(--muted);font-size:13px">
          Anda sedang memantau dasbor toko, jadi tidak bisa membuatnya dari sini.
        </p>
      <?php endif; ?>
    </div>
  <?php else: ?>
    <div class="alert" style="--ac:var(--accent);margin-bottom:18px">
      <span class="alert-ic"><svg aria-hidden="true"><use href="#i-truck"></use></svg></span>
      <div class="alert-tx">
        <b>Barang mendarat di sini dulu, baru dipecah ke tiap toko</b>
        <small>Supplier → <b><?= e(implode(', ', array_column($gdToko, 'nama'))) ?></b> → toko.
          Dokumen distribusi tetap melewati persetujuan; stok berpindah setelah tokonya menerima.</small>
      </div>
    </div>

    <div class="kpi">
      <div class="kpi-card" style="--kc:var(--accent)">
        <div class="kpi-top"><span class="kpi-l">Stok Gudang Toko</span>
          <span class="kpi-ic"><svg aria-hidden="true"><use href="#i-gudang"></use></svg></span></div>
        <div class="kpi-v"><?= angka($gdStokTotal) ?></div>
        <div class="kpi-d"><?= angka($gdJenis) ?> jenis barang</div>
      </div>
      <div class="kpi-card<?= $gdMenunggu > 0 ? ' perlu' : '' ?>" style="--kc:var(--amber)">
        <div class="kpi-top"><span class="kpi-l">Distribusi Berjalan</span>
          <span class="kpi-ic"><svg aria-hidden="true"><use href="#i-truck"></use></svg></span></div>
        <div class="kpi-v"><?= angka($gdMenunggu) ?></div>
        <div class="kpi-d">dokumen belum tuntas</div>
      </div>
    </div>

    <div class="<?= $awasi ? '' : 'tumpuk' ?>">
      <div class="card">
        <h3>Isi Gudang Toko</h3>
        <div class="sub">Siap dibagi ke toko · batch terdekat kedaluwarsa keluar lebih dulu</div>
        <div class="tbl-wrap"><table class="tbl-kartu">
          <thead><tr><th>Barang</th><th>Kode</th><th>Rak</th><th>Stok</th>
            <th>Kedaluwarsa Terdekat</th></tr></thead>
          <tbody>
          <?php if (!$gdStok): ?>
            <tr><td colspan="5" class="empty">Gudang toko masih kosong.
              Barang masuk lewat penerimaan dari supplier.</td></tr>
          <?php else: foreach ($gdStokTampil as $g): ?>
            <tr>
              <td data-label="Barang"><b><?= e($g['nama']) ?></b></td>
              <td class="sekunder" data-label="Kode"><code><?= e($g['kode']) ?></code></td>
              <td data-label="Rak"><?= e($g['nama_rak'] ?: '—') ?></td>
              <td data-label="Stok" class="num"><b><?= angka($g['qty']) ?></b> <?= e($g['satuan']) ?></td>
              <td data-label="Kedaluwarsa Terdekat"><?= e(tgl_id($g['exp_terdekat'])) ?></td>
            </tr>
          <?php endforeach; endif; ?>
          </tbody>
        </table></div>

        <?php /* Baris navigasi hanya muncul kalau memang ada yang tidak
                 tertampil. Pada gudang berisi lima baris, "1 / 1" beserta
                 dua tombol mati hanya menambah perabot. */ ?>
        <?php if ($gdHalTot > 1): ?>
        <div class="gd-nav">
          <span class="gd-info">
            Menampilkan <b><?= angka(($gdHal - 1) * GD_PER_HAL + 1) ?>–<?=
              angka(min($gdHal * GD_PER_HAL, $gdBaris)) ?></b>
            dari <b><?= angka($gdBaris) ?></b> baris
            <span class="sekunder">· satu barang bisa muncul lebih dari sekali
              bila tersimpan di beberapa rak</span>
          </span>
          <span class="gd-tombol">
            <?php /* tab=gudang ikut dibawa. Tanpa itu, menekan
                     "Berikutnya" mengembalikan halaman ke tab Ringkas dan
                     tabelnya hilang dari pandangan. */ ?>
            <?php if ($gdHal > 1): ?>
              <a class="btn btn-ghost btn-sm"
                 href="dasbor_toko.php?tab=gudang&amp;gh=<?= $gdHal - 1 ?>">← Sebelumnya</a>
            <?php else: ?>
              <span class="btn btn-ghost btn-sm mati">← Sebelumnya</span>
            <?php endif; ?>
            <span class="gd-hal"><?= $gdHal ?> / <?= $gdHalTot ?></span>
            <?php if ($gdHal < $gdHalTot): ?>
              <a class="btn btn-ghost btn-sm"
                 href="dasbor_toko.php?tab=gudang&amp;gh=<?= $gdHal + 1 ?>">Berikutnya →</a>
            <?php else: ?>
              <span class="btn btn-ghost btn-sm mati">Berikutnya →</span>
            <?php endif; ?>
          </span>
        </div>
        <?php endif; ?>
      </div>

      <?php /* --------------------------------------- BARANG MASUK KE GUDANG TOKO
               Ditaruh DI ATAS formulir distribusi, mengikuti urutan
               pekerjaannya: barang datang dulu, baru dipecah. Urutan
               sebaliknya membuat orang mencari "kok stoknya kosong" di
               formulir distribusi sebelum menemukan cara mengisinya. */ ?>
      <?php if ($bolehGudang && $pilihanMasuk['rak']): ?>
      <div class="card panel-sisi">
        <h3>Barang Masuk ke Gudang Toko</h3>
        <div class="sub">Barang dari mitra atau pembelian mendarat di sini dulu</div>
        <?php form_masuk_gudang_toko($pilihanMasuk, 'dasbor_toko.php?tab=gudang'); ?>
      </div>
      <?php endif; ?>

      <?php /* Formulir distribusi hanya untuk tingkat manager. Staff toko
               tetap melihat stok gudangnya — yang ditahan wewenang
               memindahkannya, bukan pengetahuannya. */ ?>
      <?php if (!$bolehGudang && !$awasi && $gdStok): ?>
      <div class="card panel-sisi">
        <h3>Distribusikan ke Toko</h3>
        <p style="color:var(--muted);font-size:13px;margin:6px 0 0">
          Mencatat barang masuk dan mendistribusikannya ke toko adalah wewenang
          tingkat <b>Manager</b>. Anda dapat melihat stok gudang toko di sini,
          tetapi pemindahannya diminta lewat manager toko.
        </p>
      </div>
      <?php endif; ?>

      <?php if ($bolehGudang && $gdStok): ?>
      <div class="card panel-sisi">
        <h3>Distribusikan ke Toko</h3>
        <div class="sub">Membuat dokumen kirim · menunggu persetujuan lebih dulu</div>
        <form method="post">
          <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
          <input type="hidden" name="aksi" value="distribusi">
          <?php /* Barang dipilih dengan MENEMBAK barcode-nya (scanner atau kamera
                   HP). Tembakan pertama memilih barangnya, tembakan berikutnya
                   pada barang yang sama menambah jumlahnya satu — sama seperti
                   kasir. Daftar pilihan di bawahnya tetap ada sebagai cadangan
                   untuk barang yang barcode-nya rusak atau belum terdaftar. */ ?>
          <div data-scan data-fungsi="scanDistribusi" data-jumlah="tidak" data-stok="tidak" id="kotakScanDist"
               data-judul="Scan barcode barang — scan lagi untuk menambah jumlah"></div>
          <div class="field"><label>Barang <span class="req">*</span></label>
            <select name="d_barang_id" id="dBarang" required onchange="isiRakDist()">
              <option value="">— pilih barang —</option>
              <?php foreach ($gdStok as $g): ?>
                <option value="<?= (int) $g['barang_id'] ?>" data-rak="<?= (int) $g['rak_id'] ?>"
                        data-max="<?= (int) $g['qty'] ?>">
                  <?= e($g['nama']) ?> — <?= angka($g['qty']) ?> <?= e($g['satuan']) ?>
                </option>
              <?php endforeach; ?>
            </select></div>
          <input type="hidden" name="rak_asal" id="dRak" value="">
          <div class="field"><label>Toko Tujuan <span class="req">*</span></label>
            <select name="toko_tujuan" required>
              <?php foreach ($lingkup as $idT): ?>
                <option value="<?= (int) $idT ?>"><?= e($namaToko[$idT] ?? ('Toko #' . $idT)) ?></option>
              <?php endforeach; ?>
            </select></div>
          <div class="field"><label>Jumlah <span class="req">*</span></label>
            <div class="jml-langkah">
              <button type="button" class="btn btn-ghost" onclick="ubahJmlDist(-1)" aria-label="Kurangi jumlah">−</button>
              <input name="d_qty" id="dQty" type="number" min="1" value="1" required inputmode="numeric">
              <button type="button" class="btn btn-ghost" onclick="ubahJmlDist(1)" aria-label="Tambah jumlah">+</button>
              <button type="button" class="btn btn-ghost" onclick="ubahJmlDist(10)">+10</button>
            </div>
            <small class="petunjuk" id="dSisa">Pilih barang untuk melihat sisa stoknya.</small></div>
          <?php /* Tanggal dokumen — sama seperti formulir Perpindahan, karena
                   distribusi ke toko juga menerbitkan surat jalan yang
                   bertanggal. Batasnya ditegakkan tanggal_dokumen_sah() di
                   _lib.php, fungsi yang sama dipakai ketiga jalur. */ ?>
          <div class="field"><label>Tanggal Dokumen</label>
            <div class="pilih-tanggal">
              <button type="button" class="btn btn-ghost btn-sm"
                      data-tgl="<?= date('Y-m-d') ?>">Hari ini</button>
              <button type="button" class="btn btn-ghost btn-sm"
                      data-tgl="<?= date('Y-m-d', strtotime('-1 day')) ?>">Kemarin</button>
              <input type="date" name="d_tanggal" id="tanggalDok"
                     value="<?= date('Y-m-d') ?>">
            </div>
            <small class="petunjuk">Bebas, kapan saja. Tanggal yang bukan hari ini
              tercatat di riwayat aktivitas.</small></div>

          <div class="field"><label>Catatan</label>
            <input name="d_catatan" placeholder="opsional"></div>
          <button class="btn btn-primary">
            <svg aria-hidden="true"><use href="#i-kirim"></use></svg> Buat Dokumen Distribusi</button>
        </form>
      </div>
      <script>
      /* Rak asal tidak dipilih tangan — ia mengikuti barangnya, karena satu
         baris stok sudah menyebut rak mana yang memuatnya. Batas jumlah pun
         ikut, supaya kesalahan terlihat sebelum formulirnya dikirim. */
      function isiRakDist(){
        var s = document.getElementById('dBarang');
        var o = s.options[s.selectedIndex];
        var rak = o ? o.getAttribute('data-rak') : '';
        var max = o ? parseInt(o.getAttribute('data-max') || '0', 10) : 0;
        document.getElementById('dRak').value = rak || '';
        var q = document.getElementById('dQty');
        if (max > 0) { q.max = max; if (parseInt(q.value,10) > max) q.value = max; }
        document.getElementById('dSisa').textContent =
          max > 0 ? ('Tersedia ' + max + ' unit di gudang toko.')
                  : 'Pilih barang untuk melihat sisa stoknya.';
      }
      isiRakDist();

      /* Tambah/kurang jumlah lewat tombol — di HP mengetik angka di kotak
         kecil jauh lebih lambat daripada menekan +. Dijepit antara 1 dan
         sisa stok barang yang dipilih. */
      function ubahJmlDist(n){
        var q = document.getElementById('dQty');
        var max = parseInt(q.max || '0', 10);
        var v = (parseInt(q.value, 10) || 0) + n;
        if (v < 1) v = 1;
        if (max > 0 && v > max) v = max;
        q.value = v;
      }

      /* Hasil scan untuk formulir distribusi. Mengembalikan TEKS bila
         ditolak (scan.js menampilkannya sebagai galat), atau tidak
         mengembalikan apa pun bila diterima. */
      function scanDistribusi(barang, jumlah){
        var s = document.getElementById('dBarang');
        var q = document.getElementById('dQty');
        var sekarang = s.options[s.selectedIndex];
        // Barang yang sama ditembak lagi → jumlahnya bertambah.
        if (sekarang && sekarang.value && String(sekarang.value) === String(barang.id)) {
          var max = parseInt(sekarang.getAttribute('data-max') || '0', 10);
          var baru = (parseInt(q.value, 10) || 0) + jumlah;
          if (max > 0 && baru > max) {
            q.value = max;
            return 'Stok ' + barang.nama + ' di gudang toko hanya ' + max + '. Jumlah ditahan di ' + max + '.';
          }
          q.value = baru;
          return;
        }
        // Barang lain → pilih barisnya. Satu barang bisa ada di beberapa rak;
        // yang dipilih rak pertama dalam daftar.
        var ketemu = -1;
        for (var i = 0; i < s.options.length; i++) {
          if (String(s.options[i].value) === String(barang.id)) { ketemu = i; break; }
        }
        if (ketemu < 0) {
          return barang.nama + ' tidak ada di gudang toko. Catat barang masuknya dulu, baru distribusikan.';
        }
        s.selectedIndex = ketemu;
        isiRakDist();
        var maks = parseInt(s.options[ketemu].getAttribute('data-max') || '0', 10);
        q.value = (maks > 0 && jumlah > maks) ? maks : jumlah;
      }
      </script>
      <style>
      .jml-langkah{ display:flex; gap:6px; align-items:stretch }
      .jml-langkah input{ flex:1; min-width:0; text-align:center; font-weight:700 }
      .jml-langkah .btn{ min-width:46px; justify-content:center; font-size:16px; font-weight:700 }
      #kotakScanDist{ margin-bottom:14px }
      </style>
      <?php endif; ?>
    </div>

    <?php /* Pengaturan gudang toko ada di sini juga, bukan di Master Gudang:
             satu tempat untuk membuat DAN mengubahnya. */ ?>
    <?php if (!$awasi): ?>
    <div class="card" style="margin-top:16px">
      <h3>Pengaturan Gudang Toko</h3>
      <div class="sub">Identitas gudang penampung. Raknya diatur di kartu bawah.</div>
      <?php foreach ($gdToko as $gt): ?>
        <form method="post" style="max-width:620px;margin-top:12px">
          <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
          <input type="hidden" name="aksi" value="gudang_simpan">
          <input type="hidden" name="gudang_id" value="<?= (int) $gt['id'] ?>">
          <div class="form-grid">
            <div class="field"><label>Kode</label>
              <input value="<?= e($gt['kode']) ?>" disabled></div>
            <div class="field"><label>Nama Gudang Toko <span class="req">*</span></label>
              <input name="g_nama" value="<?= e($gt['nama']) ?>" required></div>
            <div class="field span-all"><label>Alamat</label>
              <input name="g_alamat" value="<?= e($gt['alamat'] ?? '') ?>" placeholder="opsional"></div>
            <div class="field"><label>Penanggung Jawab</label>
              <input name="g_pj" value="<?= e($gt['penanggung_jawab'] ?? '') ?>" placeholder="opsional"></div>
            <div class="field"><label>Telepon</label>
              <input name="g_telepon" value="<?= e($gt['telepon'] ?? '') ?>" placeholder="opsional"></div>
          </div>
          <button class="btn btn-primary" type="submit">
            <svg aria-hidden="true"><use href="#i-simpan"></use></svg> Simpan Perubahan
          </button>
        </form>
      <?php endforeach; ?>
    </div>

    <?php /* -------------------------------------------------------------
             RAK GUDANG TOKO

             Ada di sini, bukan di Master Rak, dengan alasan yang sama
             seperti gudangnya sendiri: yang memakainya yang mengaturnya.
             Dan karena akun toko memang ditolak Master Rak — sebelum ini
             gudang toko selamanya berisi satu rak bawaan, padahal stok
             tersimpan per rak. Tanpa rak kedua, susu dan pakaian
             menumpuk di satu tempat yang sama.
             ------------------------------------------------------------- */ ?>
    <div class="card" style="margin-top:16px">
      <h3>Rak Gudang Toko</h3>
      <div class="sub">
        Stok tersimpan per rak — bukan per gudang. Pisahkan barang menurut
        cara Anda mencarinya di tempat: per jenis, per lorong, atau per toko tujuan.
      </div>

      <?php if ($gdDaftarRak): ?>
        <div class="tbl-wrap" style="margin-top:12px"><table class="tbl-kartu">
          <thead><tr><th>Kode</th><th>Nama Rak</th><th>Isi</th>
            <th>Kapasitas</th><th style="width:150px"></th></tr></thead>
          <tbody>
          <?php foreach ($gdDaftarRak as $r): ?>
            <tr>
              <td data-label="Kode"><code><?= e($r['kode']) ?></code></td>
              <td data-label="Nama Rak"><b><?= e($r['nama']) ?></b>
                <?php if (($r['keterangan'] ?? '') !== ''): ?>
                  <div class="sekunder" style="font-size:12px"><?= e($r['keterangan']) ?></div>
                <?php endif; ?>
              </td>
              <td data-label="Isi">
                <?php if ((int) $r['qty'] > 0): ?>
                  <?= angka((int) $r['qty']) ?> unit
                  <span class="sekunder">· <?= (int) $r['jenis'] ?> jenis</span>
                <?php else: ?>
                  <span class="sekunder">kosong</span>
                <?php endif; ?>
              </td>
              <td data-label="Kapasitas" class="sekunder">
                <?= $r['kapasitas'] !== null ? angka((int) $r['kapasitas']) : '—' ?></td>
              <td class="aksi">
                <a class="btn btn-ghost btn-sm"
                   href="dasbor_toko.php?tab=gudang&rak=<?= (int) $r['id'] ?>#formRak">Ubah</a>
                <?php /* Tombol Hapus hanya untuk rak yang KOSONG. Rak berisi
                         barang akan ditolak server, dan menampilkan tombol
                         yang pasti gagal berarti menyembunyikan alasannya
                         sampai sesudah diklik. */ ?>
                <?php if ((int) $r['qty'] === 0): ?>
                  <form method="post" style="display:inline" data-konfirm="Hapus rak ini?" data-obj="<?= e($r['nama']) ?>">
                    <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                    <input type="hidden" name="aksi" value="rak_hapus">
                    <input type="hidden" name="rak_id" value="<?= (int) $r['id'] ?>">
                    <button class="btn btn-red btn-sm" type="submit">Hapus</button>
                  </form>
                <?php else: ?>
                  <span class="sekunder" style="font-size:11.5px"
                        title="Pindahkan isinya lebih dulu lewat Perpindahan Barang (Mutasi Rak)">terisi</span>
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table></div>
      <?php else: ?>
        <p class="petunjuk" style="margin-top:12px">Belum ada rak di gudang toko.</p>
      <?php endif; ?>

      <form method="post" id="formRak" style="max-width:620px;margin-top:16px">
        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="aksi" value="rak_simpan">
        <input type="hidden" name="rak_id" value="<?= (int) ($gdRakEdit['id'] ?? 0) ?>">
        <b><?= $gdRakEdit ? 'Ubah Rak' : 'Tambah Rak' ?></b>
        <div class="form-grid" style="margin-top:10px">
          <div class="field"><label>Kode Rak</label>
            <?php /* Dibiarkan bisa disunting, dengan usulan terisi. Kode rak
                     di gudang mengikuti penomoran fisik di dinding, dan kode
                     yang dipaksakan sistem membuat layar tidak cocok dengan
                     tempatnya. */ ?>
            <input name="r_kode"
                   value="<?= e($gdRakEdit['kode'] ?? kode_berikut($pdo, 'lokasi_rak', 'RAK')) ?>"
                   placeholder="RAK-A01">
            <small class="petunjuk">Boleh diubah sesuai penomoran di gudang.</small></div>
          <div class="field"><label>Nama Rak <span class="req">*</span></label>
            <input name="r_nama" value="<?= e($gdRakEdit['nama'] ?? '') ?>"
                   required placeholder="cth: Rak Susu, Lorong A"></div>
          <?php if (count($gdToko) > 1): ?>
            <?php /* Pilihan gudang hanya muncul bila memang ada lebih dari
                     satu gudang toko. Dropdown berisi satu pilihan bukan
                     pilihan — hanya satu baris lagi untuk dibaca. */ ?>
            <div class="field"><label>Gudang</label>
              <select name="r_gudang_id">
                <?php foreach ($gdToko as $gt): ?>
                  <option value="<?= (int) $gt['id'] ?>"
                    <?= (int) ($gdRakEdit['gudang_id'] ?? 0) === (int) $gt['id'] ? 'selected' : '' ?>>
                    <?= e($gt['nama']) ?></option>
                <?php endforeach; ?>
              </select></div>
          <?php else: ?>
            <input type="hidden" name="r_gudang_id" value="<?= (int) ($gdToko[0]['id'] ?? 0) ?>">
          <?php endif; ?>
          <div class="field"><label>Kapasitas</label>
            <input name="r_kapasitas" type="number" min="0"
                   value="<?= e((string) ($gdRakEdit['kapasitas'] ?? '')) ?>" placeholder="opsional">
            <small class="petunjuk">Hanya keterangan — tidak membatasi barang masuk.</small></div>
          <div class="field span-all"><label>Keterangan</label>
            <input name="r_keterangan" value="<?= e($gdRakEdit['keterangan'] ?? '') ?>"
                   placeholder="opsional"></div>
        </div>
        <div class="baris">
          <button class="btn btn-primary" type="submit">
            <svg aria-hidden="true"><use href="#i-simpan"></use></svg>
            <?= $gdRakEdit ? 'Simpan Rak' : 'Tambah Rak' ?></button>
          <?php if ($gdRakEdit): ?>
            <a class="btn btn-ghost" href="dasbor_toko.php?tab=gudang">Batal</a>
          <?php endif; ?>
        </div>
      </form>
    </div>
    <?php endif; ?>

    <?php if ($gdDist): ?>
    <div class="card" style="margin-top:16px">
      <h3>Distribusi Terakhir</h3>
      <div class="sub">Dari gudang toko ke tiap toko</div>
      <div class="tbl-wrap"><table class="tbl-kartu">
        <thead><tr><th>No. Dokumen</th><th>Tanggal</th><th>Tujuan</th>
          <th>Unit</th><th>Status</th></tr></thead>
        <tbody>
        <?php foreach ($gdDist as $d): [$sl, $sc] = label_status($d['status']); ?>
          <tr>
            <td data-label="No. Dokumen"><b><?= e($d['no_perpindahan']) ?></b></td>
            <td data-label="Tanggal"><?= e(tgl_jam_id($d['tanggal'])) ?></td>
            <td data-label="Tujuan"><?= e($d['nama_toko'] ?: '—') ?></td>
            <td data-label="Unit" class="num"><b><?= angka($d['unit']) ?></b></td>
            <td data-label="Status"><span class="tag <?= $sc ?>"><?= e($sl) ?></span></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table></div>
    </div>
    <?php endif; ?>
  <?php endif; ?>
<?php endif; ?>

<?php /* ===================================================== TAB: STOK */ ?>
<?php if ($tab === 'stok'): ?>
  <div class="card">
    <h3>Stok Toko</h3>
    <div class="sub"><?= count($stokList) ?> baris ·
      <?= $filterToko ? e($toko['nama'] ?? '') : angka($nToko) . ' toko sekaligus' ?></div>
    <div class="scrollx"><table class="tbl-kartu">
      <thead><tr><th>Barang</th><th>Kode</th><th>Toko</th><th>Stok</th>
        <th>Kedaluwarsa Terdekat</th><th>Harga Acuan</th></tr></thead>
      <tbody>
      <?php if (!$stokList): ?>
        <tr><td colspan="6" class="empty">Belum ada stok. Terima kiriman dari gudang lebih dulu.</td></tr>
      <?php else: foreach ($stokList as $s):
        [$sMp, $sMpC] = label_marketplace($s['marketplace']); ?>
        <tr>
          <td data-label="Barang"><b><?= e($s['nama']) ?></b></td>
          <td class="sekunder" data-label="Kode"><code><?= e($s['kode']) ?></code></td>
          <td data-label="Toko"><?= e($s['nama_toko']) ?>
            <span class="tag <?= $sMpC ?>"><?= e($sMp) ?></span></td>
          <td data-label="Stok" class="num"><b><?= angka($s['qty']) ?></b> <?= e($s['satuan']) ?>
            <?php if ((int) $s['qty'] <= (int) $s['stok_minimum']): ?>
              <span class="tag t-red">di bawah minimum</span>
            <?php endif; ?></td>
          <td data-label="Kedaluwarsa Terdekat"><?= e(tgl_id($s['exp_terdekat'])) ?></td>
          <td data-label="Harga Acuan" class="num"><?= rupiah($s['harga']) ?></td>
        </tr>
      <?php endforeach; endif; ?>
      </tbody>
    </table></div>
  </div>

  <?php if ($stokList && !$awasi): ?>
  <div class="card">
    <h3>Penyesuaian Stok</h3>
    <div class="sub">Untuk barang rusak, hilang, atau lewat tanggal — bukan penjualan</div>
    <form method="post">
      <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
      <input type="hidden" name="aksi" value="sesuaikan">
      <?php /* Dasbornya memuat semua toko, tapi penyesuaian harus jelas
               terjadi di toko MANA — jadi tokonya dipilih di sini, dan
               pilihan barangnya menyusut mengikuti toko yang dipilih. */ ?>
      <div class="form-grid">
        <div class="field"><label>Toko <span class="req">*</span></label>
          <select name="toko_id" id="sesToko" required onchange="saringBarangSes()">
            <?php foreach ($lingkup as $idT): ?>
              <option value="<?= (int) $idT ?>" <?= $idT === $tokoId ? 'selected' : '' ?>>
                <?= e($namaToko[$idT] ?? ('Toko #' . $idT)) ?></option>
            <?php endforeach; ?>
          </select></div>
        <div class="field"><label>Barang <span class="req">*</span></label>
          <select name="s_barang_id" id="sesBarang" required>
            <option value="">— pilih barang —</option>
            <?php foreach ($stokList as $s): ?>
              <option value="<?= (int) $s['id'] ?>" data-toko="<?= (int) $s['toko_id'] ?>">
                <?= e($s['nama']) ?> — stok <?= angka($s['qty']) ?></option>
            <?php endforeach; ?>
          </select></div>
        <div class="field"><label>Jumlah <span class="req">*</span></label>
          <input name="s_qty" type="number" min="1" value="1" required></div>
        <div class="field"><label>Jenis <span class="req">*</span></label>
          <select name="s_jenis">
            <option value="rusak">Rusak</option>
            <option value="hilang">Hilang</option>
            <option value="kedaluwarsa">Lewat tanggal kedaluwarsa</option>
            <option value="koreksi_kurang">Koreksi hasil opname</option>
          </select></div>
        <div class="field"><label>Alasan</label>
          <input name="s_alasan" placeholder="cth: kemasan penyok saat bongkar"></div>
      </div>
      <button class="btn btn-red"><svg aria-hidden="true"><use href="#i-minus"></use></svg> Catat Penyesuaian</button>
    </form>
  </div>
  <script>
  /* Barang yang tidak ada di toko terpilih disembunyikan — mencegah
     petugas memilih barang yang stoknya nol di toko itu lalu ditolak
     server. Memakai disabled+hidden, bukan menghapus, supaya pilihannya
     kembali muncul saat toko lain dipilih. */
  function saringBarangSes(){
    var t = document.getElementById('sesToko').value;
    var b = document.getElementById('sesBarang');
    var adaTerpilih = false;
    for (var i = 0; i < b.options.length; i++) {
      var o = b.options[i];
      if (!o.value) continue;
      var cocok = o.getAttribute('data-toko') === t;
      o.hidden = !cocok; o.disabled = !cocok;
      if (cocok && o.selected) adaTerpilih = true;
    }
    if (!adaTerpilih) b.value = '';
  }
  saringBarangSes();
  </script>
  <?php endif; ?>
<?php endif; ?>

<?php /* ===================================================== TAB: JUAL */ ?>
<?php if ($tab === 'jual' && !$awasi): ?>
  <div class="card">
    <h3>Catat Penjualan</h3>
    <div class="sub">Stok toko langsung berkurang · batch kedaluwarsa terdekat keluar lebih dulu</div>
    <form method="post" id="frmJual">
      <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
      <input type="hidden" name="aksi" value="jual">

      <div class="form-grid">
        <?php /* Penjualan harus jelas terjadi di toko mana. Mengganti toko
                 di sini ikut menyetel kanal & sasaran scan-nya, karena
                 keduanya melekat pada toko itu. */ ?>
        <div class="field"><label>Toko <span class="req">*</span></label>
          <select name="toko_id" id="jualToko" required onchange="gantiTokoJual()">
            <?php foreach ($lingkup as $idT): ?>
              <option value="<?= (int) $idT ?>" <?= $idT === $tokoId ? 'selected' : '' ?>
                      data-mp="<?= e($mpPerToko[$idT] ?? 'offline') ?>">
                <?= e($namaToko[$idT] ?? ('Toko #' . $idT)) ?></option>
            <?php endforeach; ?>
          </select></div>
        <div class="field"><label>Marketplace</label>
          <select name="marketplace" id="jualMp">
            <?php foreach ($mpOpts as $mo): [$l] = label_marketplace($mo); ?>
              <option value="<?= $mo ?>" <?= ($mpPerToko[$tokoId] ?? '') === $mo ? 'selected' : '' ?>><?= $l ?></option>
            <?php endforeach; ?>
          </select></div>
        <div class="field"><label>Nomor Pesanan</label>
          <input name="no_pesanan" placeholder="cth: 240810ABCDEFG"
                 style="font-family:'Courier New',monospace"></div>
        <div class="field"><label>Nama Pembeli</label>
          <input name="nama_pembeli" placeholder="opsional"></div>
        <div class="field"><label>Tanggal</label>
          <input name="tanggal" type="datetime-local" value="<?= date('Y-m-d\TH:i') ?>"></div>
      </div>

      <div data-scan data-lokasi="toko" data-lokasi-id="<?= $tokoId ?>" id="kotakScanJual"
           data-judul="Scan atau ketik kode barang yang terjual"></div>
      <script>
      /* Sasaran scan mengikuti toko yang dipilih — kalau tidak, hasil scan
         dicari di stok toko yang salah dan barang yang ada terbaca kosong. */
      function gantiTokoJual(){
        var s = document.getElementById('jualToko');
        var o = s.options[s.selectedIndex];
        var k = document.getElementById('kotakScanJual');
        if (k) k.setAttribute('data-lokasi-id', s.value);
        var mp = o && o.getAttribute('data-mp');
        var selMp = document.getElementById('jualMp');
        if (mp && selMp) selMp.value = mp;
      }
      </script>

      <div class="scrollx">
        <table class="tbl-kartu">
          <thead><tr>
            <th style="min-width:240px">Barang <span class="req">*</span></th>
            <th style="width:110px">Jumlah <span class="req">*</span></th>
            <th style="width:150px">Harga Jual/unit</th>
            <th style="width:44px"></th>
          </tr></thead>
          <tbody id="tbJual"></tbody>
        </table>
      </div>
      <button type="button" class="btn btn-ghost btn-sm" style="margin-top:10px" onclick="barisJual()"><svg aria-hidden="true"><use href="#i-plus"></use></svg> Tambah Baris</button>

      <div class="form-grid" style="margin-top:14px">
        <div class="field"><label>Ongkos Kirim</label>
          <input name="ongkir" type="number" step="0.01" min="0" value="0"></div>
        <div class="field"><label>Catatan</label>
          <input name="catatan" placeholder="opsional"></div>
      </div>
      <button class="btn btn-primary"><svg aria-hidden="true"><use href="#i-simpan"></use></svg> Simpan Penjualan</button>
    </form>
  </div>

<?php endif; ?>
<?php if ($tab === 'jual'): ?>
  <div class="card">
    <h3>Riwayat Penjualan</h3>
    <div class="sub"><?= count($jualList) ?> penjualan terakhir ·
      <?= $filterToko ? e($toko['nama'] ?? '') : angka($nToko) . ' toko sekaligus' ?></div>
    <div class="scrollx"><table class="tbl-kartu">
      <thead><tr><th>No.</th><th>Tanggal</th><th>Toko</th><th>Pesanan</th><th>Pembeli</th>
        <th>Unit</th><th>Nilai</th><th>Sumber</th></tr></thead>
      <tbody>
      <?php if (!$jualList): ?>
        <tr><td colspan="8" class="empty">Belum ada penjualan.</td></tr>
      <?php else: foreach ($jualList as $j): ?>
        <tr>
          <td data-label="No."><code><?= e($j['no_penjualan']) ?></code></td>
          <td data-label="Tanggal"><?= e(tgl_jam_id($j['tanggal'])) ?></td>
          <td data-label="Toko"><?= e($j['nama_toko']) ?></td>
          <td class="sekunder" data-label="Pesanan"><?= $j['no_pesanan'] ? '<code>' . e($j['no_pesanan']) . '</code>' : '—' ?></td>
          <td data-label="Pembeli"><?= e($j['nama_pembeli'] ?: '—') ?></td>
          <td data-label="Unit" class="num"><?= angka($j['jml_unit']) ?></td>
          <td data-label="Nilai" class="num"><b><?= rupiah($j['nilai']) ?></b></td>
          <td class="sekunder" data-label="Sumber"><span class="tag t-gray"><?= e(ucfirst(str_replace('_', ' ', $j['sumber']))) ?></span></td>
        </tr>
      <?php endforeach; endif; ?>
      </tbody>
    </table></div>
  </div>
<?php endif; ?>

<script>
const STOK = <?= json_encode(array_map(fn($s) => [
    'id' => (int) $s['id'],
    't'  => $s['nama'] . ' — stok ' . (int) $s['qty'],
    'h'  => (float) $s['harga'],
], $stokList), JSON_UNESCAPED_UNICODE) ?>;
const gy = 'width:100%;padding:8px;border:1px solid var(--border);border-radius:8px;background:var(--surface-2);color:var(--text);font-family:inherit;font-size:13px';

/* Nama barang dari basis data disisipkan sebagai HTML — dilolosi dulu. */
function lolos(s){
  return String(s == null ? '' : s).replace(/&/g, '&amp;').replace(/</g, '&lt;')
                  .replace(/>/g, '&gt;').replace(/"/g, '&quot;');
}
function barisJual(){
  const tb = document.getElementById('tbJual');
  if (!tb) return;
  const tr = document.createElement('tr');
  tr.innerHTML =
    '<td><select name="barang_id[]" style="' + gy + '" onchange="isiHarga(this)">' +
      '<option value="">— pilih barang —</option>' +
      STOK.map(s => '<option value="'+s.id+'" data-h="'+lolos(s.h)+'">'+lolos(s.t)+'</option>').join('') +
    '</select></td>' +
    '<td><input name="qty[]" type="number" min="1" value="1" style="' + gy + '"></td>' +
    '<td><input name="harga_jual[]" type="number" step="0.01" min="0" value="0" style="' + gy + '"></td>' +
    '<td><button type="button" class="btn btn-red btn-sm" onclick="this.closest(\'tr\').remove()"><svg aria-hidden="true"><use href="#i-x"></use></svg> </button></td>';
  tb.appendChild(tr);
}

/* Isi harga acuan dari master barang supaya petugas tinggal menyesuaikan. */
function isiHarga(sel){
  const op = sel.options[sel.selectedIndex];
  const inp = sel.closest('tr').querySelector('input[name="harga_jual[]"]');
  if (op && op.dataset.h && Number(inp.value) === 0) inp.value = op.dataset.h;
}

function scanTambahBaris(barang, jumlah){
  var sel = Array.from(document.querySelectorAll('select[name="barang_id[]"]'))
                 .find(function(s){ return String(s.value) === String(barang.id); });
  if (sel){
    var q = sel.closest('tr').querySelector('input[name="qty[]"]');
    q.value = (parseInt(q.value,10) || 0) + jumlah;
    return;
  }
  barisJual();
  var baris = document.getElementById('tbJual').lastElementChild;
  var s3 = baris.querySelector('select[name="barang_id[]"]');
  s3.value = barang.id;
  baris.querySelector('input[name="qty[]"]').value = jumlah;
  isiHarga(s3);
  document.querySelectorAll('#tbJual tr').forEach(function(tr){
    var s2 = tr.querySelector('select[name="barang_id[]"]');
    if (s2 && !s2.value) tr.remove();
  });
}

const fj = document.getElementById('frmJual');
if (fj){
  fj.addEventListener('submit', function(ev){
    const ada = Array.from(document.querySelectorAll('select[name="barang_id[]"]')).some(s => s.value);
    if (!ada){ ev.preventDefault(); alert('Isi minimal satu baris barang.'); }
  });
  barisJual();
}
</script>
<?php require __DIR__ . '/_footer_luar.php'; ?>
