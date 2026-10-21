<?php
/**
 * GET /api/dashboard.php
 * Ringkasan untuk dasbor aplikasi.
 *
 * ---------------------------------------------------------------------
 * DUA PERBAIKAN PENTING
 * ---------------------------------------------------------------------
 * 1. LINGKUP. Versi sebelumnya menghitung SELURUH lembaga untuk siapa pun.
 *    Petugas gudang melihat "Total Stok 58.420" padahal yang ada di raknya
 *    444 — angka yang benar untuk lembaga, tapi menyesatkan untuk orang
 *    yang memakainya memutuskan apa yang bisa ia keluarkan hari ini.
 *
 * 2. DELTA "vs kemarin". Hanya diberikan untuk angka yang BENAR-BENAR bisa
 *    dibandingkan: barang masuk dan barang keluar, keduanya punya tanggal
 *    di basis data. Total stok, jumlah produk, dan nilai persediaan TIDAK
 *    diberi delta — tidak ada tabel riwayat harian, jadi angka kemarinnya
 *    harus ditebak dari selisih pergerakan, dan tebakan itu meleset setiap
 *    kali ada penerimaan dari mitra (yang menambah stok tanpa lewat
 *    transaksi_masuk). Lebih baik tanpa delta daripada delta yang salah:
 *    "+8,3% vs kemarin" yang keliru akan dipakai orang mengambil keputusan.
 *
 *    Kalau delta stok memang diperlukan, yang benar adalah menambah tabel
 *    snapshot harian yang diisi cron — itu pekerjaan tersendiri.
 */
require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/_lingkup.php';
require_once __DIR__ . '/../_lib.php';
only('GET');
$payload = auth_required();

function q(PDO $pdo, string $sql, $default = 0) {
    try { return $pdo->query($sql)->fetchColumn() ?: $default; }
    catch (Throwable $e) { error_log('[AMANAH API] dashboard q: ' . $e->getMessage()); return $default; }
}
function rows(PDO $pdo, string $sql): array {
    try { return $pdo->query($sql)->fetchAll(); }
    catch (Throwable $e) { error_log('[AMANAH API] dashboard rows: ' . $e->getMessage()); return []; }
}

/* Lingkup rak. String kosong berarti tidak dibatasi (Super Admin, Pimpinan);
   '0' berarti terikat gudang tapi belum punya rak — bukan hal yang sama, dan
   keduanya harus menghasilkan angka yang berbeda. */
$inRak     = api_in_rak($pdo, $payload);
$gudangId  = api_gudang_id($payload);

/* DUA penanda yang berbeda, dan membedakannya penting:

     $adaRak   — akun ini punya daftar rak, jadi penyaring "lokasi_rak_id
                 IN (...)" bisa dipakai.
     $terbatas — angkanya bukan angka seluruh lembaga.

   Keduanya sempat satu variabel. Begitu toko dan mitra ikut ditandai
   terbatas — keduanya memang tidak boleh melihat angka lembaga — setiap
   penyaring rak berubah jadi "IN ()", yang bukan SQL sah dan mematikan
   kueri tanpa menyebut sebabnya. */
$adaRak    = $inRak !== '';
$terbatas  = $adaRak;

/**
 * Nama tempat yang jadi lingkup akun ini — untuk chip di kepala dasbor.
 *
 * Mengembalikan string kosong untuk pengawas (Super Admin, Pimpinan):
 * lingkup mereka seluruh lembaga, dan chip bertuliskan "Semua Gudang" hanya
 * mengulang apa yang sudah jelas dari isi layarnya.
 *
 * Nama dibaca ulang tiap permintaan, bukan dititipkan ke token. Token
 * berlaku tujuh hari, sedangkan penugasan gudang bisa berubah di tengah
 * itu — dan chip yang menyebut gudang lama sementara angkanya sudah gudang
 * baru adalah kesalahan yang tidak akan pernah dicurigai siapa pun.
 */
function nama_lingkup_dasbor(PDO $pdo, array $p, bool $isMitra,
                             bool $isToko, bool $adaRak): string {
    try {
        if ($isMitra) {
            $sid = (int) ($p['supplier_id'] ?? 0);
            if ($sid <= 0) { return ''; }
            $st = $pdo->prepare("SELECT nama FROM supplier WHERE id = ? AND deleted_at IS NULL");
            $st->execute([$sid]);
            return (string) ($st->fetchColumn() ?: '');
        }
        if ($isToko) {
            $ids = api_toko_saya($pdo, $p);
            if (!$ids) { return ''; }
            $in = implode(',', array_map('intval', $ids));
            $nama = $pdo->query("SELECT nama FROM toko WHERE id IN ($in)
                                  AND deleted_at IS NULL ORDER BY kode")
                        ->fetchAll(PDO::FETCH_COLUMN);
            return ringkas_nama_lingkup($nama);
        }
        if ($adaRak) {
            $gid = api_gudang_id($p);
            $sql = $gid
                ? "SELECT nama FROM gudang WHERE id = " . (int) $gid . " AND deleted_at IS NULL"
                : "SELECT g.nama FROM gudang g
                     JOIN user_gudang ug ON ug.gudang_id = g.id
                    WHERE ug.user_id = " . (int) ($p['user_id'] ?? 0) . "
                      AND g.deleted_at IS NULL ORDER BY g.kode";
            return ringkas_nama_lingkup($pdo->query($sql)->fetchAll(PDO::FETCH_COLUMN));
        }
    } catch (Throwable $e) {
        error_log('[AMANAH] nama_lingkup_dasbor: ' . $e->getMessage());
    }
    return '';
}

/**
 * Ringkas jadi teks chip yang muat di kepala dasbor.
 *
 * Batasnya PANJANG HURUF, bukan jumlah nama. Aturan "maksimal dua nama"
 * sempat dipakai dan hasilnya "S LITTLE DINO, S COCO BABY SHOP" — dua nama,
 * tiga puluh satu huruf, dan chip-nya memakan lebih dari separuh baris
 * sampai sapaan di sebelahnya terhimpit jadi tiga baris. Yang menentukan
 * lebar chip memang huruf, bukan koma.
 */
function ringkas_nama_lingkup(array $nama): string {
    $nama = array_values(array_filter(array_map('strval', $nama), 'strlen'));
    if (!$nama) { return ''; }
    if (count($nama) === 1) { return $nama[0]; }

    $dua = $nama[0] . ', ' . $nama[1];
    if (count($nama) === 2 && mb_strlen($dua) <= 24) { return $dua; }
    if (count($nama) > 2 && mb_strlen($dua) <= 20) {
        return $dua . ', +' . (count($nama) - 2) . ' lainnya';
    }
    return $nama[0] . ' +' . (count($nama) - 1) . ' lainnya';
}

/* MITRA DAN TOKO TIDAK MELIHAT PERSEDIAAN LEMBAGA.

   api_in_rak() hanya membatasi peran sisi gudang, jadi supplier dan toko
   jatuh ke cabang "tidak dibatasi" dan menerima total stok serta NILAI
   PERSEDIAAN seluruh yayasan. Aplikasi memang tidak menggambarnya di layar
   mereka, tapi angkanya tetap terkirim — dan siapa pun yang memegang token
   mitra bisa memanggil endpoint ini langsung.

   Yang benar: mitra melihat stok yang IA pegang, toko melihat stok yang ada
   di tokonya. Bukan nol — keduanya punya persediaan sendiri yang memang
   perlu mereka lihat.

   Ditulis dengan if/elseif, BUKAN match(). Hosting produksi berjalan pada
   PHP 7.4 dan match adalah sintaks PHP 8 — ia gagal saat parse, bukan saat
   dijalankan, jadi seluruh berkas mati sekaligus dengan pesan yang tidak
   menyebut sebabnya. */
$isMitra = ($payload['role'] ?? '') === 'supplier';
$isToko  = ($payload['role'] ?? '') === 'toko';

if ($terbatas) {
    $syaratBb = "bb.lokasi_rak_id IN ($inRak)";
} elseif ($isToko) {
    $idsToko  = api_toko_saya($pdo, $payload);
    $inToko   = $idsToko ? implode(',', $idsToko) : '0';
    $syaratBb = "bb.toko_id IN ($inToko)";
    // Dihitung dengan mesin yang sama dengan gudang, hanya syaratnya beda.
    $terbatas = true;
} elseif ($isMitra) {
    /* Stok mitra hidup di stok_supplier, BUKAN di batch_barang. Syarat
       yang selalu salah dipakai supaya cabang berbasis batch_barang
       menghasilkan nol; angka mitra diisi terpisah di bawah. */
    $syaratBb = '1=0';
    $terbatas = true;
} else {
    $syaratBb = 'bb.toko_id IS NULL';
}

/* =======================================================================
   LINGKUP ARUS — dipakai KPI harian, grafik 7 hari, dan Aktivitas Terbaru
   =======================================================================
   Ketiganya sebelumnya disaring dengan pola `$adaRak ? … : tanpa batas`.

   Untuk peran sisi gudang itu benar. Tetapi api_in_rak() HANYA membatasi
   peran sisi gudang: toko dan mitra selalu mendapat daftar rak kosong, dan
   "kosong" pada pola itu berarti TANPA BATAS. Akibatnya dasbor toko dan
   mitra menerima angka arus, grafik Barang Masuk, dan daftar Aktivitas
   Terbaru SELURUH LEMBAGA — termasuk baris transaksi_keluar beserta NAMA
   PENERIMA penyaluran. Bagi mitra itu bukan angka yang tak relevan,
   melainkan daftar penerima bantuan yayasan.

   Cacatnya lolos karena KPI stok sudah disaring lebih dulu lewat
   $terbatas: angka besar di kartunya benar, jadi tidak ada yang tampak
   salah di layar, sementara tiga bagian lain bocor.

   Sekarang syaratnya ditulis sekali di sini, apa adanya per peran:

     gudang  rak yang dipegangnya
     toko    rak gudang toko — di sanalah barang toko mendarat sebelum
             dipecah, dan transaksi_masuk_detail tidak punya kolom toko_id
     mitra   masuk = kiriman DARI dirinya (supplier_id); keluar dan opname
             tidak ada, karena arus keluar lembaga bukan lingkupnya
   ======================================================================= */
$inRakArus = $inRak;
if ($inRakArus === '' && $isToko) {
    // Id akun ikut dibawa supaya penugasan gudangnya dihormati — kalau
    // tidak, dasbor aplikasi menampilkan arus gudang toko lain sementara
    // daftar stoknya sudah menyempit. Dua angka untuk satu akun.
    $rt = api_rak_gudang_toko($pdo, (int) ($payload['user_id'] ?? 0));
    // '0', bukan '' — daftar rak kosong harus berarti NOL, bukan segalanya.
    $inRakArus = $rt ? implode(',', array_map('intval', $rt)) : '0';
}
$pakaiRak = $inRakArus !== '';
$sidMitra = $isMitra ? (int) ($payload['supplier_id'] ?? 0) : 0;

if ($pakaiRak) {
    $batasMasuk   = "AND d.lokasi_rak_id IN ($inRakArus)";
    $batasKeluar  = "AND EXISTS(SELECT 1 FROM batch_barang bb
                                 WHERE bb.id = d.batch_id
                                   AND bb.lokasi_rak_id IN ($inRakArus))";
    $batasGrafik  = "AND d.lokasi_rak_id IN ($inRakArus)";
    $syaratMasuk  = "EXISTS(SELECT 1 FROM transaksi_masuk_detail d
                             WHERE d.transaksi_masuk_id = h.id
                               AND d.lokasi_rak_id IN ($inRakArus))";
    $syaratKeluar = "EXISTS(SELECT 1 FROM transaksi_keluar_detail d
                             JOIN batch_barang bb ON bb.id = d.batch_id
                            WHERE d.transaksi_keluar_id = h.id
                              AND bb.lokasi_rak_id IN ($inRakArus))";
    $syaratOpname = "AND ps.lokasi_rak_id IN ($inRakArus)";
} elseif ($isMitra) {
    $batasMasuk   = "AND h.supplier_id = $sidMitra";
    $batasKeluar  = 'AND 1=0';
    $batasGrafik  = "AND h.supplier_id = $sidMitra";
    $syaratMasuk  = "h.supplier_id = $sidMitra";
    $syaratKeluar = '1=0';
    $syaratOpname = 'AND 1=0';
} else {
    // Lingkup lembaga: super admin dan pimpinan.
    $batasMasuk = $batasKeluar = $batasGrafik = $syaratOpname = '';
    $syaratMasuk = $syaratKeluar = '1=1';
}

// ---------------------------------------------------------------- angka
if ($terbatas) {
    /* Dihitung dari batch di rak sendiri, bukan dari tabel `stok` yang
       menyimpan total lembaga. */
    $totalJenis = (int) q($pdo,
        "SELECT COUNT(DISTINCT bb.barang_id) FROM batch_barang bb
         WHERE bb.qty_tersisa > 0 AND $syaratBb");
    $totalStok = (int) q($pdo,
        "SELECT COALESCE(SUM(bb.qty_tersisa),0) FROM batch_barang bb WHERE $syaratBb");
    $nilaiStok = (float) q($pdo,
        "SELECT COALESCE(SUM(bb.qty_tersisa * b.harga),0)
         FROM batch_barang bb JOIN barang b ON b.id = bb.barang_id
         WHERE bb.qty_tersisa > 0 AND $syaratBb");

    $habis = (int) q($pdo,
        "SELECT COUNT(*) FROM barang b
         WHERE b.deleted_at IS NULL AND b.is_active = 1
           AND COALESCE((SELECT SUM(bb.qty_tersisa) FROM batch_barang bb
                          WHERE bb.barang_id = b.id AND $syaratBb),0) <= 0");
    $hampirHabis = (int) q($pdo,
        "SELECT COUNT(*) FROM barang b
         WHERE b.deleted_at IS NULL AND b.is_active = 1
           AND COALESCE((SELECT SUM(bb.qty_tersisa) FROM batch_barang bb
                          WHERE bb.barang_id = b.id AND $syaratBb),0)
               BETWEEN 1 AND b.stok_minimum");

    $expSegera = (int) q($pdo,
        "SELECT COUNT(*) FROM batch_barang bb WHERE bb.qty_tersisa > 0 AND $syaratBb
           AND bb.tanggal_expired IS NOT NULL
           AND bb.tanggal_expired BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 7 DAY)");
    $expWaspada = (int) q($pdo,
        "SELECT COUNT(*) FROM batch_barang bb WHERE bb.qty_tersisa > 0 AND $syaratBb
           AND bb.tanggal_expired > DATE_ADD(CURDATE(), INTERVAL 7 DAY)
           AND bb.tanggal_expired <= DATE_ADD(CURDATE(), INTERVAL 30 DAY)");
    $expExpired = (int) q($pdo,
        "SELECT COUNT(*) FROM batch_barang bb WHERE bb.qty_tersisa > 0 AND $syaratBb
           AND bb.tanggal_expired IS NOT NULL AND bb.tanggal_expired < CURDATE()");
} else {
    $totalJenis  = (int)   q($pdo, "SELECT COUNT(*) FROM barang WHERE deleted_at IS NULL");
    $totalStok   = (int)   q($pdo, "SELECT COALESCE(SUM(qty_total),0) FROM stok");
    $nilaiStok   = (float) q($pdo, "SELECT COALESCE(SUM(s.qty_total*b.harga),0)
                                    FROM stok s JOIN barang b ON b.id=s.barang_id");
    $hampirHabis = (int)   q($pdo, "SELECT COUNT(*) FROM v_status_stok WHERE status_stok='hampir_habis'");
    $habis       = (int)   q($pdo, "SELECT COUNT(*) FROM v_status_stok WHERE status_stok='habis'");
    $expSegera   = (int)   q($pdo, "SELECT COUNT(*) FROM v_status_expired WHERE status_expired='segera_expired'");
    $expWaspada  = (int)   q($pdo, "SELECT COUNT(*) FROM v_status_expired WHERE status_expired='waspada'");
    $expExpired  = (int)   q($pdo, "SELECT COUNT(*) FROM v_status_expired WHERE status_expired='expired'");
}

/* Angka mitra datang dari stok_supplier, tabel yang berbeda dari
   batch_barang milik gudang. Ditimpa DI SINI, setelah cabang berbasis
   batch_barang di atas menghasilkan nol. */
if ($isMitra) {
    $sidM = $payload['supplier_id'] !== null ? (int) $payload['supplier_id'] : 0;
    $totalJenis = (int) q($pdo,
        "SELECT COUNT(DISTINCT barang_id) FROM stok_supplier WHERE supplier_id=$sidM AND qty>0");
    $totalStok = (int) q($pdo,
        "SELECT COALESCE(SUM(qty),0) FROM stok_supplier WHERE supplier_id=$sidM");
    $nilaiStok = (float) q($pdo,
        "SELECT COALESCE(SUM(ss.qty*ss.harga),0) FROM stok_supplier ss WHERE ss.supplier_id=$sidM");
    $expSegera = (int) q($pdo,
        "SELECT COUNT(*) FROM stok_supplier WHERE supplier_id=$sidM AND qty>0
           AND tanggal_expired BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 7 DAY)");
    $expWaspada = (int) q($pdo,
        "SELECT COUNT(*) FROM stok_supplier WHERE supplier_id=$sidM AND qty>0
           AND tanggal_expired > DATE_ADD(CURDATE(), INTERVAL 7 DAY)
           AND tanggal_expired <= DATE_ADD(CURDATE(), INTERVAL 30 DAY)");
    $expExpired = (int) q($pdo,
        "SELECT COUNT(*) FROM stok_supplier WHERE supplier_id=$sidM AND qty>0
           AND tanggal_expired IS NOT NULL AND tanggal_expired < CURDATE()");
    // Mitra tidak punya "stok minimum" per barang; keduanya dibiarkan nol
    // daripada dihitung dari ambang milik lembaga yang bukan urusannya.
    $habis = 0;
    $hampirHabis = 0;
}

/** Jumlah unit masuk/keluar pada satu hari, dalam lingkup akun ini. */
/**
 * Jumlah unit yang bergerak pada satu hari.
 *
 * $batas diterima SEBAGAI POTONGAN SQL yang sudah jadi, bukan dihitung di
 * dalam. Sebelumnya fungsi ini menyusun sendiri penyaringnya dari
 * ($terbatas, $inRak) — dan karena api_in_rak() tidak melayani toko maupun
 * mitra, keduanya masuk ke cabang "tanpa batas" dan menerima angka arus
 * seluruh lembaga. Satu tempat perhitungan lingkup, dipakai bersama grafik
 * dan Aktivitas Terbaru, membuat ketiganya tidak bisa lagi berselisih.
 *
 * Catatan: barang keluar tidak menyimpan raknya di baris detail — yang
 * tercatat adalah batch-nya, dan batch itulah yang tahu raknya.
 */
function gerak(PDO $pdo, string $arah, string $hari, string $batas): int {
    $tabel  = $arah === 'masuk' ? 'transaksi_masuk' : 'transaksi_keluar';
    $detail = $tabel . '_detail';
    $fk     = $tabel . '_id';
    return (int) q($pdo,
        "SELECT COALESCE(SUM(d.qty),0) FROM $detail d
         JOIN $tabel h ON h.id = d.$fk
         WHERE DATE(h.tanggal) = $hari $batas");
}

$masukHari    = gerak($pdo, 'masuk',  'CURDATE()', $batasMasuk);
$keluarHari   = gerak($pdo, 'keluar', 'CURDATE()', $batasKeluar);
$masukKemarin = gerak($pdo, 'masuk',  'DATE_SUB(CURDATE(), INTERVAL 1 DAY)', $batasMasuk);
$keluarKemarin= gerak($pdo, 'keluar', 'DATE_SUB(CURDATE(), INTERVAL 1 DAY)', $batasKeluar);

/* Dokumen yang menunggu dibatasi lingkup yang sama dengan halaman
   Persetujuan dan Penerimaan — kalau berbeda, angka di dasbor tidak cocok
   dengan panjang daftar yang dibuka saat kartunya diketuk. */
$batasDok = '(' . api_syarat_perpindahan($pdo, $payload, 'p')
          . ' OR ' . api_syarat_persetujuan_saya($pdo, $payload, 'p') . ')';
$menunggu = (int) q($pdo,
    "SELECT COUNT(*) FROM perpindahan p WHERE p.status='menunggu_persetujuan' AND $batasDok");
$diJalan  = (int) q($pdo,
    "SELECT COUNT(*) FROM perpindahan p WHERE p.status='dikirim' AND $batasDok");

/* ------------------------------------------------- rincian per status
   Untuk diagram donat di dasbor. Dua sumber yang berbeda, dan sengaja
   TIDAK disatukan di bawah satu nama:

     status_dokumen  — dokumen perpindahan dalam lingkup akun ini
     status_kiriman  — kiriman milik mitra, dari pengiriman_supplier

   Keduanya menghitung benda yang berbeda meski terdengar mirip. Menaruh
   keduanya di satu field akan membuat angka yang sama dibaca sebagai dua
   arti tergantung siapa yang membukanya. */
$statusDokumen = rows($pdo,
    "SELECT p.status, COUNT(*) AS jml
     FROM perpindahan p WHERE $batasDok
     GROUP BY p.status ORDER BY jml DESC");

$statusKiriman = [];
$totalProdukMitra = 0;
$returMitra = 0;
if (($payload['role'] ?? '') === 'supplier') {
    $sid = $payload['supplier_id'] !== null ? (int) $payload['supplier_id'] : 0;
    $statusKiriman = rows($pdo,
        "SELECT status, COUNT(*) AS jml FROM pengiriman_supplier
         WHERE supplier_id = $sid GROUP BY status ORDER BY jml DESC");
    // Produk yang benar-benar dipegang mitra, bukan katalog lembaga.
    $totalProdukMitra = (int) q($pdo,
        "SELECT COUNT(DISTINCT barang_id) FROM stok_supplier
         WHERE supplier_id = $sid AND qty > 0");
    $returMitra = (int) q($pdo,
        "SELECT COUNT(*) FROM perpindahan
         WHERE jenis = 'retur' AND ((asal_tipe='supplier' AND asal_id=$sid)
                                 OR (tujuan_tipe='supplier' AND tujuan_id=$sid))");
}

/**
 * Persentase perubahan terhadap kemarin.
 *
 * null bila kemarin nol — "naik tak terhingga persen" bukan kabar yang
 * berguna, dan "+100%" untuk perubahan 0→1 melebih-lebihkan sesuatu yang
 * sebenarnya kecil.
 */
function delta(int $kini, int $lalu): ?array {
    if ($lalu <= 0) { return null; }
    $p = (($kini - $lalu) / $lalu) * 100;
    return [
        'persen' => round($p, 1),
        'naik'   => $p >= 0,
        'teks'   => ($p >= 0 ? '+' : '') . number_format($p, 1, ',', '.') . '%',
    ];
}

// -------------------------------------------------------- stok per lokasi
if ($isMitra) {
    /* Mitra melihat sebaran per PRODUK, bukan per lokasi: stok mitra
       memang berada di satu tempat, dan memecahnya per rak tidak
       menjawab pertanyaan apa pun yang ia punya. */
    $sidM = $payload['supplier_id'] !== null ? (int) $payload['supplier_id'] : 0;
    $stokGudang = rows($pdo,
        "SELECT b.kode, b.nama,
                COALESCE(SUM(ss.qty),0) AS qty,
                COUNT(*)                AS jenis
         FROM stok_supplier ss
         JOIN barang b ON b.id = ss.barang_id
         WHERE ss.supplier_id = $sidM AND ss.qty > 0
         GROUP BY b.id ORDER BY qty DESC LIMIT 6");
} elseif ($isToko) {
    $stokGudang = rows($pdo,
        "SELECT t.kode, t.nama,
                COALESCE(SUM(bb.qty_tersisa),0) AS qty,
                COUNT(DISTINCT bb.barang_id)    AS jenis
         FROM toko t
         LEFT JOIN batch_barang bb ON bb.toko_id = t.id AND bb.qty_tersisa > 0
         WHERE t.deleted_at IS NULL AND $syaratBb
         GROUP BY t.id ORDER BY qty DESC LIMIT 6");
} else {
$stokGudang = $terbatas
    ? rows($pdo,
        "SELECT lr.kode, lr.nama,
                COALESCE(SUM(bb.qty_tersisa),0) AS qty,
                COUNT(DISTINCT bb.barang_id)    AS jenis
         FROM lokasi_rak lr
         LEFT JOIN batch_barang bb ON bb.lokasi_rak_id = lr.id AND bb.qty_tersisa > 0
         WHERE lr.gudang_id = " . (int) $gudangId . " AND lr.deleted_at IS NULL
         GROUP BY lr.id ORDER BY qty DESC LIMIT 6")
    : rows($pdo,
        "SELECT g.kode, g.nama,
                COALESCE(SUM(bb.qty_tersisa),0) AS qty,
                COUNT(DISTINCT bb.barang_id)    AS jenis
         FROM gudang g
         LEFT JOIN lokasi_rak lr   ON lr.gudang_id=g.id AND lr.deleted_at IS NULL
         LEFT JOIN batch_barang bb ON bb.lokasi_rak_id=lr.id AND bb.qty_tersisa>0
         WHERE g.deleted_at IS NULL AND g.is_active=1
         GROUP BY g.id ORDER BY qty DESC LIMIT 6");
}

// $batasGrafik dihitung di blok LINGKUP ARUS di atas, bersama penyaring
// KPI harian dan Aktivitas Terbaru — supaya ketiganya tidak bisa berselisih.
$grafik7Hari = rows($pdo,
    "SELECT DATE(h.tanggal) AS tgl, COALESCE(SUM(d.qty),0) AS masuk
     FROM transaksi_masuk h
     JOIN transaksi_masuk_detail d ON d.transaksi_masuk_id=h.id
     WHERE h.tanggal >= DATE_SUB(CURDATE(), INTERVAL 6 DAY) $batasGrafik
     GROUP BY DATE(h.tanggal) ORDER BY tgl");

/* ------------------------------------------------------------ aktivitas
   Peristiwa terbaru dari empat sumber, digabung dan diurutkan waktu.

   COLLATE dipasang eksplisit di setiap kolom teks: pelepasan_stok memakai
   utf8mb4_general_ci sementara tabel lain utf8mb4_unicode_ci, dan MySQL
   menolak UNION yang mencampur keduanya. */
$C = 'COLLATE utf8mb4_unicode_ci';
$aktivitas = rows($pdo,
    "SELECT * FROM (
        SELECT CAST('masuk' AS CHAR) $C AS jenis,
               CAST(h.no_transaksi AS CHAR) $C AS nomor,
               CAST(COALESCE(sp.nama,'Diantar langsung') AS CHAR) $C AS pihak,
               h.tanggal AS waktu,
               CAST('selesai' AS CHAR) $C AS status
          FROM transaksi_masuk h
          LEFT JOIN supplier sp ON sp.id = h.supplier_id
         WHERE $syaratMasuk
        UNION ALL
        SELECT CAST('keluar' AS CHAR) $C,
               CAST(h.no_transaksi AS CHAR) $C,
               CAST(COALESCE(h.penerima,'—') AS CHAR) $C,
               h.tanggal,
               CAST('selesai' AS CHAR) $C
          FROM transaksi_keluar h
         WHERE $syaratKeluar
        UNION ALL
        /* Nama tujuan diselesaikan dari tabelnya, bukan dibaca dari
           tujuan_teks. Kolom itu hanya terisi untuk tujuan yang diketik
           bebas; untuk perpindahan antar lokasi ia kosong, dan seluruh
           baris perpindahan di Aktivitas Terbaru jadi hanya bertanda
           hubung -- menghapus satu-satunya keterangan yang berguna:
           barangnya pergi ke mana.

           Catatan penulisan: komentar ini tidak boleh memuat tanda kutip
           ganda. Seluruh kueri ini adalah string PHP berkutip ganda, dan
           satu tanda kutip di sini menutup stringnya di tengah jalan. */
        SELECT CAST(p.jenis AS CHAR) $C,
               CAST(p.no_perpindahan AS CHAR) $C,
               CAST(COALESCE(
                    NULLIF(p.tujuan_teks,''),
                    CASE p.tujuan_tipe
                      WHEN 'gudang'   THEN (SELECT g2.nama  FROM gudang g2   WHERE g2.id = p.tujuan_id)
                      WHEN 'toko'     THEN (SELECT t2.nama  FROM toko t2     WHERE t2.id = p.tujuan_id)
                      WHEN 'supplier' THEN (SELECT s2.nama  FROM supplier s2 WHERE s2.id = p.tujuan_id)
                      WHEN 'penerima' THEN (SELECT pn.nama  FROM penerima pn WHERE pn.id = p.tujuan_id)
                      WHEN 'rak'      THEN (SELECT CONCAT('Rak ', lr2.kode)
                                              FROM lokasi_rak lr2 WHERE lr2.id = p.tujuan_id)
                    END,
                    '—') AS CHAR) $C,
               p.tanggal,
               CAST(p.status AS CHAR) $C
          FROM perpindahan p
         WHERE $batasDok
        UNION ALL
        SELECT CAST('opname' AS CHAR) $C,
               CAST(ps.no_penyesuaian AS CHAR) $C,
               CAST(COALESCE(lr.kode,'—') AS CHAR) $C,
               ps.tanggal,
               CAST(ps.jenis AS CHAR) $C
          FROM penyesuaian_stok ps
          LEFT JOIN lokasi_rak lr ON lr.id = ps.lokasi_rak_id
         WHERE ps.lokasi_tipe = 'gudang' $syaratOpname
     ) x ORDER BY waktu DESC LIMIT 8");

/* Nilai persediaan ditahan DI SERVER untuk tingkat staff — cerminan
   boleh_lihat_nilai() di _lib.php. Menyembunyikan kartunya di aplikasi
   saja tidak menjaga apa pun: siapa pun yang memegang token bisa
   memanggil endpoint ini langsung. */

/* =====================================================================
   KPI DASBOR ADMIN — enam angka beserta pembandingnya
   =====================================================================
   Mengikuti rancangan "04 Dashboard Admin": Total Produk, Total Stok,
   Supplier, Gudang, Toko/Outlet, dan Pesanan, masing-masing dengan
   perubahan "vs kemarin".

   ---------------------------------------------------------------------
   PEMBANDINGNYA DIHITUNG, BUKAN DITEBAK
   ---------------------------------------------------------------------
   Catatan di kepala berkas ini dulu menolak SELURUH delta selain barang
   masuk dan keluar. Alasannya benar untuk stok, tapi terlalu digeneralkan:
   lima dari enam angka di atas dihitung dari tabel yang punya
   `created_at` dan `deleted_at`, jadi "berapa jumlahnya kemarin" bukan
   tebakan melainkan pertanyaan yang bisa dijawab persis —

       ada pada akhir kemarin  =  dibuat sebelum hari ini
                              DAN (belum dihapus, atau dihapus hari ini)

   Definisinya sengaja memakai KEBERADAAN baris, bukan `is_active`.
   Kolom is_active tidak punya riwayat: gudang yang dinonaktifkan pagi
   ini tampak "sudah nonaktif sejak dulu", sehingga pembandingnya akan
   melaporkan penurunan yang tidak pernah terjadi. Keberadaan baris punya
   riwayat, dan hanya itu yang bisa dibandingkan dengan jujur.

   ---------------------------------------------------------------------
   TOTAL STOK SENGAJA TANPA PEMBANDING
   ---------------------------------------------------------------------
   Satu-satunya yang tidak diberi delta. Stok berubah lewat banyak jalur —
   transaksi masuk, keluar, penerimaan dari mitra, perpindahan antar
   gudang, penyesuaian opname, dan pelepasan — dan tidak ada tabel
   riwayat harian yang mencatat totalnya. Merekonstruksi angka kemarin
   berarti menjumlahkan seluruh jalur itu dan berharap tidak ada satu pun
   yang terlewat; sekali meleset, yang tampil adalah persentase salah
   yang dipakai orang mengambil keputusan.

   Rancangannya sendiri sudah menyiapkan tempat untuk keadaan ini: kartu
   Supplier di berkas desain menampilkan "-" pada baris perubahannya.
   Delta null di sini dirender persis seperti itu.

   Kalau delta stok memang diperlukan, yang benar adalah tabel snapshot
   harian yang diisi cron — pekerjaan tersendiri, bukan tambalan di sini.
   ===================================================================== */
$kpiAdmin = null;
if (!$terbatas && !$isMitra && !$isToko) {

    /** Jumlah baris yang ADA pada akhir hari kemarin. */
    $jumlahKemarin = function (string $tabel) use ($pdo): int {
        $tabel = preg_replace('/[^a-z_]/', '', $tabel);
        $adaHapus = (bool) q($pdo,
            "SELECT COUNT(*) FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '$tabel'
                AND COLUMN_NAME = 'deleted_at'");
        $syarat = $adaHapus
            ? "created_at < CURDATE() AND (deleted_at IS NULL OR deleted_at >= CURDATE())"
            : "created_at < CURDATE()";
        return (int) q($pdo, "SELECT COUNT(*) FROM `$tabel` WHERE $syarat");
    };

    $produkKini   = (int) q($pdo, "SELECT COUNT(*) FROM barang   WHERE deleted_at IS NULL");
    $supplierKini = (int) q($pdo, "SELECT COUNT(*) FROM supplier WHERE deleted_at IS NULL");
    $gudangKini   = (int) q($pdo, "SELECT COUNT(*) FROM gudang   WHERE deleted_at IS NULL");
    $tokoKini     = (int) q($pdo, "SELECT COUNT(*) FROM toko     WHERE deleted_at IS NULL");
    $pesananKini  = (int) q($pdo, "SELECT COUNT(*) FROM penjualan");

    /* Urutannya sama persis dengan urutan kartu di rancangan, supaya
       aplikasi tinggal merender berurutan tanpa menyusun ulang. */
    $kpiAdmin = [
        [
            'kunci' => 'total_produk',
            'judul' => 'Total Produk',
            'nilai' => $produkKini,
            'delta' => delta($produkKini, $jumlahKemarin('barang')),
        ],
        [
            'kunci' => 'total_stok',
            'judul' => 'Total Stok',
            'nilai' => $totalStok,
            // null — lihat alasannya di atas.
            'delta' => null,
        ],
        [
            'kunci' => 'supplier',
            'judul' => 'Supplier',
            'nilai' => $supplierKini,
            'delta' => delta($supplierKini, $jumlahKemarin('supplier')),
        ],
        [
            'kunci' => 'gudang',
            'judul' => 'Gudang',
            'nilai' => $gudangKini,
            'delta' => delta($gudangKini, $jumlahKemarin('gudang')),
        ],
        [
            'kunci' => 'toko',
            'judul' => 'Toko / Outlet',
            'nilai' => $tokoKini,
            'delta' => delta($tokoKini, $jumlahKemarin('toko')),
        ],
        [
            'kunci' => 'pesanan',
            'judul' => 'Pesanan',
            'nilai' => $pesananKini,
            'delta' => delta($pesananKini, $jumlahKemarin('penjualan')),
        ],
    ];
}


/* =====================================================================
   GRAFIK STOK 7 HARI TERAKHIR
   =====================================================================
   Rancangan "04 Dashboard Admin" meminta garis total stok tujuh hari
   terakhir. Basis data ini TIDAK menyimpan riwayat stok: `stok.qty_total`
   hanyalah ringkasan `batch_barang.qty_tersisa` yang ditulis ulang setiap
   kali batch berubah, dan tabel `mutasi_barang` yang dimaksudkan jadi buku
   besar tidak pernah diisi.

   Karena itu dipakai dua sumber, dan keduanya dibedakan dengan jelas:

     1. SNAPSHOT — tabel `stok_harian`, diisi sekali sehari saat dasbor
        pertama dibuka. Nilainya diambil langsung dari keadaan sebenarnya,
        jadi TEPAT. Mulai hari pemasangan, grafik ini terisi sendiri.

     2. REKONSTRUKSI — untuk hari-hari SEBELUM snapshot pertama, angkanya
        dihitung mundur dari total hari ini dikurangi pergerakan tiap hari:

            stok(kemarin) = stok(hari ini) - masuk + keluar
                            - koreksi_tambah + koreksi_kurang
                            + pelepasan + pindah_ke_toko - pindah_dari_luar

        Ini PERKIRAAN, bukan angka audit. Ia meleset bila ada perubahan
        qty_tersisa yang tidak melewati salah satu tabel di atas. Batasnya
        disebutkan apa adanya lewat field `perkiraan` pada tiap titik,
        supaya aplikasi bisa menandainya bila kelak diperlukan — bukan
        disembunyikan lalu dibaca orang sebagai angka pasti.

   Setelah tujuh hari berjalan, seluruh titik berasal dari snapshot dan
   rekonstruksinya tidak terpakai lagi.
   ===================================================================== */
$grafikStok = [];
if (!$terbatas && !$isMitra && !$isToko) {
    try {
        /* Tabel dibuat bila belum ada. Ditaruh di sini, bukan di berkas
           migrasi terpisah, supaya pemasangan di hosting tidak menuntut
           satu langkah manual lagi — sudah cukup banyak yang harus
           diunggah. CREATE ... IF NOT EXISTS aman diulang. */
        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS stok_harian (
                tanggal     DATE        NOT NULL PRIMARY KEY,
                total_qty   BIGINT      NOT NULL DEFAULT 0,
                total_nilai DECIMAL(18,2) NOT NULL DEFAULT 0,
                dicatat_at  TIMESTAMP   NULL DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );

        /* Snapshot hari ini. Ditulis sekali; pembukaan dasbor berikutnya
           pada hari yang sama memperbaruinya, sehingga nilai terakhirlah
           yang tersimpan — bukan nilai saat orang pertama membuka aplikasi
           pagi-pagi sebelum ada transaksi apa pun. */
        $stSnap = $pdo->prepare(
            "INSERT INTO stok_harian (tanggal, total_qty, total_nilai)
             VALUES (CURDATE(), ?, ?)
             ON DUPLICATE KEY UPDATE total_qty = VALUES(total_qty),
                                     total_nilai = VALUES(total_nilai)"
        );
        $stSnap->execute([$totalStok, $nilaiStok]);

        // Snapshot yang sudah ada, tujuh hari terakhir.
        $snap = [];
        $stS = $pdo->query(
            "SELECT tanggal, total_qty FROM stok_harian
              WHERE tanggal >= DATE_SUB(CURDATE(), INTERVAL 6 DAY)"
        );
        foreach ($stS->fetchAll() as $b) {
            $snap[$b['tanggal']] = (int) $b['total_qty'];
        }

        /** Perubahan bersih stok pada satu tanggal. */
        $gerakHari = function (string $tgl) use ($pdo): int {
            $satu = function (string $sql) use ($pdo, $tgl): int {
                $st = $pdo->prepare($sql);
                $st->execute([$tgl]);
                return (int) $st->fetchColumn();
            };
            $masuk = $satu(
                "SELECT COALESCE(SUM(d.qty),0) FROM transaksi_masuk_detail d
                   JOIN transaksi_masuk h ON h.id = d.transaksi_masuk_id
                  WHERE DATE(h.tanggal) = ?");
            $keluar = $satu(
                "SELECT COALESCE(SUM(d.qty),0) FROM transaksi_keluar_detail d
                   JOIN transaksi_keluar h ON h.id = d.transaksi_keluar_id
                  WHERE DATE(h.tanggal) = ?");
            $sesuaiTambah = $satu(
                "SELECT COALESCE(SUM(qty),0) FROM penyesuaian_stok
                  WHERE DATE(tanggal) = ? AND lokasi_tipe = 'gudang'
                    AND jenis = 'koreksi_tambah'");
            $sesuaiKurang = $satu(
                "SELECT COALESCE(SUM(qty),0) FROM penyesuaian_stok
                  WHERE DATE(tanggal) = ? AND lokasi_tipe = 'gudang'
                    AND jenis <> 'koreksi_tambah'");
            /* Perbaikan tidak dihitung keluar: barangnya kembali ke rak
               setelah selesai. Hanya yang dibuang dan dijual yang benar-
               benar meninggalkan gudang. */
            $lepas = $satu(
                "SELECT COALESCE(SUM(qty),0) FROM pelepasan_stok
                  WHERE DATE(tanggal) = ? AND jenis IN ('dibuang','dijual')");
            /* Perpindahan yang MELINTASI batas lembaga. Perpindahan antar
               rak atau antar gudang sendiri tidak mengubah total, jadi
               sengaja tidak dihitung — memasukkannya akan menghitung
               barang yang sama dua kali. */
            $keToko = $satu(
                "SELECT COALESCE(SUM(d.qty),0) FROM perpindahan_detail d
                   JOIN perpindahan p ON p.id = d.perpindahan_id
                  WHERE DATE(p.tanggal) = ? AND p.tujuan_tipe = 'toko'
                    AND p.status IN ('dikirim','diterima','selesai')");
            $dariLuar = $satu(
                "SELECT COALESCE(SUM(COALESCE(d.qty_diterima, d.qty)),0)
                   FROM perpindahan_detail d
                   JOIN perpindahan p ON p.id = d.perpindahan_id
                  WHERE DATE(p.tanggal) = ? AND p.asal_tipe IN ('supplier','eksternal')
                    AND p.status IN ('diterima','selesai')");

            return $masuk - $keluar + $sesuaiTambah - $sesuaiKurang
                 - $lepas - $keToko + $dariLuar;
        };

        /* Disusun MUNDUR dari hari ini. Titik yang punya snapshot memakai
           snapshot; sisanya dihitung dari titik sesudahnya dikurangi
           pergerakan hari itu. */
        $seri = [];
        $berjalan = $totalStok;
        for ($i = 0; $i <= 6; $i++) {
            $tgl = date('Y-m-d', strtotime("-$i day"));
            if (isset($snap[$tgl])) {
                $berjalan = $snap[$tgl];
                $perkiraan = false;
            } else {
                $perkiraan = true;
            }
            $seri[] = [
                'tgl'       => $tgl,
                'qty'       => max(0, $berjalan),
                'perkiraan' => $perkiraan,
            ];
            // Mundur satu hari: buang pergerakan hari ini.
            $berjalan -= $gerakHari($tgl);
        }
        $grafikStok = array_reverse($seri);

    } catch (Throwable $e) {
        /* Grafik yang gagal dihitung TIDAK menjatuhkan dasbor. Yang hilang
           hanya satu bagian; angka-angka pokoknya tetap sampai. */
        error_log('[AMANAH API] grafik stok: ' . $e->getMessage());
        $grafikStok = [];
    }
}

$lihatNilai = api_lihat_nilai($pdo, $payload);

ok([
    'ringkasan' => [
        'total_jenis'   => $totalJenis,
        'total_stok'    => $totalStok,
        'nilai_stok'    => $lihatNilai ? $nilaiStok : null,
        'hampir_habis'  => $hampirHabis,
        'habis'         => $habis,
        'exp_segera'    => $expSegera,
        'exp_waspada'   => $expWaspada,
        'exp_expired'   => $expExpired,
        'masuk_hari'    => $masukHari,
        'keluar_hari'   => $keluarHari,
        'menunggu_persetujuan' => $menunggu,
        'di_jalan'      => $diJalan,
    ],
    // Hanya dua yang punya pembanding sungguhan. Sisanya sengaja absen —
    // lihat catatan di kepala berkas.
    'delta' => [
        'masuk_hari'  => delta($masukHari, $masukKemarin),
        'keluar_hari' => delta($keluarHari, $keluarKemarin),
    ],
    // Memberi tahu aplikasi apakah angkanya milik satu gudang atau seluruh
    // lembaga, supaya judulnya bisa menyebutkan yang benar.
    /* Menyebutkan angka ini milik SIAPA, supaya aplikasi bisa menulis
       keterangan yang benar di kartunya. "unit di gudang Anda" salah
       untuk mitra, dan "unit lembaga" salah untuk semua kecuali pengawas. */
    'lingkup' => $isMitra ? 'mitra'
               : ($isToko ? 'toko'
               : ($adaRak ? 'gudang' : 'lembaga')),
    /* NAMA lingkupnya, bukan hanya jenisnya.
       Dipakai chip di kepala dasbor aplikasi ("Gudang Utama", "Toko
       Amanah 2", "PT. Maju Bersama"). Jenis saja tidak cukup: akun yang
       memegang dua gudang perlu tahu angka di layarnya milik yang mana,
       dan "Gudang" tidak menjawab itu.

       Dipotong tiga nama. Akun yang memegang delapan gudang akan
       menghasilkan chip selebar layar; sisanya dirangkum jadi angka. */
    'lingkup_nama' => nama_lingkup_dasbor($pdo, $payload, $isMitra, $isToko, $adaRak),
    // Enam KPI dasbor admin; null untuk peran yang lingkupnya terbatas.
    'kpi_admin'    => $kpiAdmin,
    // Garis stok tujuh hari untuk dasbor admin; kosong bila lingkupnya terbatas.
    'grafik_stok'  => $grafikStok,
    'lihat_nilai'  => $lihatNilai,
    'stok_gudang'  => $stokGudang,
    'grafik_masuk' => $grafik7Hari,
    'aktivitas'    => $aktivitas,

    'status_dokumen' => $statusDokumen,
    // Tiga di bawah hanya terisi untuk akun mitra; nol untuk yang lain.
    'status_kiriman'    => $statusKiriman,
    'total_produk_mitra'=> $totalProdukMitra,
    'retur_mitra'       => $returMitra,
]);
