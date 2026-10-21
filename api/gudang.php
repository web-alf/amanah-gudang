<?php
/**
 * =====================================================================
 *  AMANAH — GUDANG (untuk layar Gudang di aplikasi ponsel)
 * =====================================================================
 *  GET /api/gudang.php                 → daftar gudang + ringkasan
 *  GET /api/gudang.php?id=N            → rincian satu gudang
 *  GET /api/gudang.php?id=N&isi=stok   → daftar stok barang di gudang itu
 *
 *  ---------------------------------------------------------------------
 *  KENAPA TIDAK MENUMPANG master.php
 *  ---------------------------------------------------------------------
 *  master.php melayani lima entitas dengan satu bentuk balasan yang sama:
 *  sebaris data per entitas, tanpa hitungan apa pun. Layar Gudang di
 *  ponsel butuh yang lain — kapasitas, keterisian, jumlah jenis barang,
 *  isi tiap rak, aktivitas terakhir. Menyisipkannya ke master.php berarti
 *  empat entitas lain ikut membayar biaya subkueri yang tidak mereka
 *  pakai, dan bentuk balasannya jadi berbeda-beda per entitas — persis
 *  yang membuat mesin daftar generik tidak bisa lagi dipakai.
 *
 *  Jadi master.php dibiarkan apa adanya (formulir tambah/ubah/hapus
 *  gudang tetap ke sana), dan yang di sini hanya MEMBACA.
 *
 *  ---------------------------------------------------------------------
 *  ANGKANYA DARI MANA
 *  ---------------------------------------------------------------------
 *  Rumusnya disalin dari gudang.php sisi web, bukan dikarang ulang:
 *    * kapasitas  = SUM(lokasi_rak.kapasitas) milik gudang itu
 *    * terisi     = SUM(batch_barang.qty_tersisa) lewat raknya
 *    * jml_barang = COUNT(DISTINCT barang_id) yang masih bersisa
 *  Dua tampilan yang menghitung hal sama dengan rumus berbeda adalah cara
 *  paling mudah membuat web dan ponsel menyebut angka yang berlainan.
 * =====================================================================
 */
require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/_lingkup.php';
require_once __DIR__ . '/../_lib.php';   // DIR_UNGGAHAN

$payload = auth_required();

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    /* Tambah/ubah/hapus tetap lewat master.php supaya aturan wewenang
       gudang hanya ditulis di satu tempat. */
    err('Endpoint ini hanya membaca. Untuk mengubah data gudang, pakai master.php?entity=gudang.', 405);
}

$id = (int) ($_GET['id'] ?? 0);

/* ---------------------------------------------------------------------
   Pembatas lingkup.

   Admin gudang hanya boleh melihat gudangnya sendiri. Tanpa pagar ini,
   menebak id di URL sudah cukup untuk membaca isi gudang cabang lain —
   dan tidak ada satu pun jejak bahwa itu terjadi.
   --------------------------------------------------------------------- */
$gidSaya = api_gudang_id($payload);          // null = boleh lihat semua
$pagar   = $gidSaya === null ? '' : ' AND g.id = ' . (int) $gidSaya;

if ($gidSaya !== null && $gidSaya <= 0) {
    // Terikat gudang, tapi belum ditugaskan ke gudang mana pun.
    ok(['data' => [], 'ringkasan' => ['total' => 0, 'aktif' => 0, 'kapasitas' => 0, 'terisi' => 0]]);
}
if ($id > 0 && $gidSaya !== null && $id !== $gidSaya) {
    err('Gudang ini di luar wewenang akun Anda.', 403);
}

/* Subkueri yang dipakai ulang di daftar maupun rincian. */
const SUB_GUDANG = "
    (SELECT COUNT(*) FROM lokasi_rak lr
      WHERE lr.gudang_id = g.id AND lr.deleted_at IS NULL) AS jml_rak,
    (SELECT COALESCE(SUM(lr.kapasitas),0) FROM lokasi_rak lr
      WHERE lr.gudang_id = g.id AND lr.deleted_at IS NULL) AS kapasitas,
    (SELECT COALESCE(SUM(bb.qty_tersisa),0)
       FROM batch_barang bb
       JOIN lokasi_rak lr ON lr.id = bb.lokasi_rak_id
      WHERE lr.gudang_id = g.id AND bb.qty_tersisa > 0) AS terisi,
    (SELECT COUNT(DISTINCT bb.barang_id)
       FROM batch_barang bb
       JOIN lokasi_rak lr ON lr.id = bb.lokasi_rak_id
      WHERE lr.gudang_id = g.id AND bb.qty_tersisa > 0) AS jml_barang";

try {

// =====================================================================
//  A. DAFTAR GUDANG
// =====================================================================
if ($id === 0) {
    $cari = trim($_GET['q'] ?? '');
    [$limit, $offset] = paginate(50);

    $w = "g.deleted_at IS NULL" . $pagar;
    $p = [];
    if ($cari !== '') {
        $w .= " AND (g.nama LIKE ? OR g.kode LIKE ? OR g.alamat LIKE ?)";
        $p = ['%'.$cari.'%', '%'.$cari.'%', '%'.$cari.'%'];
    }
    /* Saringan status dari keping "Semua / Aktif / Nonaktif" di layar. */
    $status = $_GET['status'] ?? '';
    if ($status === 'aktif')    { $w .= " AND g.is_active = 1"; }
    if ($status === 'nonaktif') { $w .= " AND g.is_active = 0"; }

    $st = $pdo->prepare(
        "SELECT g.id, g.kode, g.nama, g.jenis, g.alamat, g.penanggung_jawab,
                g.telepon, g.keterangan, g.is_active," . SUB_GUDANG . "
         FROM gudang g WHERE $w
         ORDER BY g.is_active DESC, g.kode
         LIMIT $limit OFFSET $offset"
    );
    $st->execute($p);
    $baris = $st->fetchAll();

    foreach ($baris as &$b) { rapikan($b); }
    unset($b);

    /* Ringkasan dihitung atas SELURUH gudang dalam lingkup, bukan atas
       halaman yang sedang tampil — kalau tidak, "Total 4 gudang" akan
       berubah-ubah mengikuti pencarian, yang membuatnya tidak berarti. */
    $r = $pdo->query(
        "SELECT COUNT(*) AS total,
                COALESCE(SUM(g.is_active),0) AS aktif,
                (SELECT COALESCE(SUM(lr.kapasitas),0) FROM lokasi_rak lr
                  JOIN gudang gg ON gg.id = lr.gudang_id
                 WHERE lr.deleted_at IS NULL AND gg.deleted_at IS NULL"
                 . str_replace(' AND g.id', ' AND gg.id', $pagar) . ") AS kapasitas,
                (SELECT COALESCE(SUM(bb.qty_tersisa),0)
                   FROM batch_barang bb
                   JOIN lokasi_rak lr ON lr.id = bb.lokasi_rak_id
                   JOIN gudang gg ON gg.id = lr.gudang_id
                  WHERE bb.qty_tersisa > 0 AND gg.deleted_at IS NULL"
                  . str_replace(' AND g.id', ' AND gg.id', $pagar) . ") AS terisi
         FROM gudang g WHERE g.deleted_at IS NULL" . $pagar
    )->fetch();

    ok([
        'data' => $baris,
        'ringkasan' => [
            'total'     => (int) $r['total'],
            'aktif'     => (int) $r['aktif'],
            'kapasitas' => (int) $r['kapasitas'],
            'terisi'    => (int) $r['terisi'],
        ],
    ]);
}

// =====================================================================
//  B. DAFTAR STOK DI SATU GUDANG   (?id=N&isi=stok)
// =====================================================================
if (($_GET['isi'] ?? '') === 'stok') {
    $cari = trim($_GET['q'] ?? '');
    [$limit, $offset, $hal] = paginate(25);

    $w = "lr.gudang_id = ? AND bb.qty_tersisa > 0";
    $p = [$id];
    if ($cari !== '') {
        $w .= " AND (b.nama LIKE ? OR b.kode LIKE ? OR b.barcode LIKE ?)";
        array_push($p, '%'.$cari.'%', '%'.$cari.'%', '%'.$cari.'%');
    }

    /* Satu baris per BARANG, bukan per batch. Satu barang yang masuk lima
       kali punya lima batch; menampilkannya sebagai lima baris membuat
       tabelnya terbaca seolah stoknya lima jenis berbeda. */
    $st = $pdo->prepare(
        "SELECT b.id, b.kode, b.nama, b.satuan, b.foto, b.stok_minimum,
                k.nama AS kategori,
                SUM(bb.qty_tersisa) AS qty,
                COUNT(DISTINCT bb.lokasi_rak_id) AS jml_rak,
                MIN(lr.kode) AS rak_kode,
                MIN(bb.tanggal_expired) AS expired_terdekat
         FROM batch_barang bb
         JOIN lokasi_rak lr ON lr.id = bb.lokasi_rak_id
         JOIN barang b ON b.id = bb.barang_id AND b.deleted_at IS NULL
         LEFT JOIN kategori k ON k.id = b.kategori_id
         WHERE $w
         GROUP BY b.id
         ORDER BY b.nama
         LIMIT $limit OFFSET $offset"
    );
    $st->execute($p);
    $baris = $st->fetchAll();

    foreach ($baris as &$b) {
        $b['id']           = (int) $b['id'];
        $b['qty']          = (int) $b['qty'];
        $b['jml_rak']      = (int) $b['jml_rak'];
        $b['stok_minimum'] = (int) $b['stok_minimum'];
        $b['foto_url']     = foto_url_gudang($b['foto'] ?? '');
        /* Statusnya dihitung di sini, sekali, supaya keping warna di
           ponsel dan lencana di web tidak bisa berbeda pendapat. */
        $b['status'] = $b['qty'] <= 0 ? 'habis'
                     : ($b['stok_minimum'] > 0 && $b['qty'] <= $b['stok_minimum'] ? 'menipis' : 'aman');
        unset($b['foto']);
    }
    unset($b);

    /* Jumlah total baris — dibutuhkan paginasi "Menampilkan 1–10 dari 47".
       COUNT jalan sendiri, bukan SQL_CALC_FOUND_ROWS: fungsi itu sudah
       usang di MySQL 8 dan diam-diam diabaikan. */
    $stc = $pdo->prepare(
        "SELECT COUNT(DISTINCT b.id)
         FROM batch_barang bb
         JOIN lokasi_rak lr ON lr.id = bb.lokasi_rak_id
         JOIN barang b ON b.id = bb.barang_id AND b.deleted_at IS NULL
         WHERE $w"
    );
    $stc->execute($p);
    $jml = (int) $stc->fetchColumn();

    ok([
        'data' => $baris,
        'paginasi' => [
            'halaman'  => $hal,
            'per_hal'  => $limit,
            'total'    => $jml,
            'halaman_total' => (int) ceil($jml / $limit),
        ],
        'kpi' => kpiGudang($pdo, $id),
    ]);
}

// =====================================================================
//  C. RINCIAN SATU GUDANG
// =====================================================================
$st = $pdo->prepare(
    "SELECT g.id, g.kode, g.nama, g.jenis, g.alamat, g.penanggung_jawab,
            g.telepon, g.keterangan, g.is_active, g.created_at," . SUB_GUDANG . "
     FROM gudang g WHERE g.id = ? AND g.deleted_at IS NULL"
);
$st->execute([$id]);
$g = $st->fetch();
if (!$g) { err('Gudang tidak ditemukan.', 404); }
rapikan($g);

/* --- Isi tiap rak, untuk tab "Stok per Rak" --- */
$st = $pdo->prepare(
    "SELECT lr.id, lr.kode, lr.nama, lr.kapasitas,
            COALESCE(SUM(bb.qty_tersisa),0) AS terisi,
            COUNT(DISTINCT bb.barang_id) AS jml_barang
     FROM lokasi_rak lr
     LEFT JOIN batch_barang bb ON bb.lokasi_rak_id = lr.id AND bb.qty_tersisa > 0
     WHERE lr.gudang_id = ? AND lr.deleted_at IS NULL
     GROUP BY lr.id ORDER BY lr.kode"
);
$st->execute([$id]);
$rak = array_map(function ($r) {
    $kap = (int) $r['kapasitas'];
    $isi = (int) $r['terisi'];
    return [
        'id'         => (int) $r['id'],
        'kode'       => $r['kode'],
        'nama'       => $r['nama'],
        'kapasitas'  => $kap,
        'terisi'     => $isi,
        'jml_barang' => (int) $r['jml_barang'],
        /* null, bukan 0, kalau kapasitasnya belum diisi. Batang 0% pada
           rak yang sebetulnya penuh adalah salah baca yang meyakinkan. */
        'persen'     => $kap > 0 ? min(100, (int) round($isi * 100 / $kap)) : null,
    ];
}, $st->fetchAll());

/* --- Stok per kategori, untuk tab kedua --- */
$st = $pdo->prepare(
    "SELECT COALESCE(k.nama,'Tanpa Kategori') AS nama,
            SUM(bb.qty_tersisa) AS qty,
            COUNT(DISTINCT b.id) AS jml_barang
     FROM batch_barang bb
     JOIN lokasi_rak lr ON lr.id = bb.lokasi_rak_id
     JOIN barang b ON b.id = bb.barang_id AND b.deleted_at IS NULL
     LEFT JOIN kategori k ON k.id = b.kategori_id
     WHERE lr.gudang_id = ? AND bb.qty_tersisa > 0
     GROUP BY k.id ORDER BY qty DESC"
);
$st->execute([$id]);
$kategori = array_map(fn($r) => [
    'nama' => $r['nama'],
    'qty' => (int) $r['qty'],
    'jml_barang' => (int) $r['jml_barang'],
], $st->fetchAll());

/* --- Aktivitas terakhir: masuk & keluar digabung, terbaru di atas --- */
$st = $pdo->prepare(
    "(SELECT 'masuk' AS jenis, tm.no_transaksi AS nomor, tm.tanggal,
             b.nama AS barang, d.qty, u.nama AS oleh
        FROM transaksi_masuk_detail d
        JOIN transaksi_masuk tm ON tm.id = d.transaksi_masuk_id
        JOIN lokasi_rak lr ON lr.id = d.lokasi_rak_id
        JOIN barang b ON b.id = d.barang_id
        LEFT JOIN users u ON u.id = tm.user_id
       WHERE lr.gudang_id = ?)
     UNION ALL
     (SELECT 'keluar' AS jenis, tk.no_transaksi AS nomor, tk.tanggal,
             b.nama AS barang, d.qty, u.nama AS oleh
        FROM transaksi_keluar_detail d
        JOIN transaksi_keluar tk ON tk.id = d.transaksi_keluar_id
        JOIN batch_barang bb ON bb.id = d.batch_id
        JOIN lokasi_rak lr ON lr.id = bb.lokasi_rak_id
        JOIN barang b ON b.id = d.barang_id
        LEFT JOIN users u ON u.id = tk.user_id
       WHERE lr.gudang_id = ?)
     ORDER BY tanggal DESC, nomor DESC
     LIMIT 12"
);
$st->execute([$id, $id]);
$aktivitas = array_map(fn($r) => [
    'jenis'   => $r['jenis'],
    'nomor'   => $r['nomor'],
    'tanggal' => $r['tanggal'],
    'barang'  => $r['barang'],
    'qty'     => (int) $r['qty'],
    'oleh'    => $r['oleh'] ?? '—',
], $st->fetchAll());

/* --- Ringkasan 7 hari terakhir --- */
$st = $pdo->prepare(
    "SELECT COALESCE(SUM(d.qty),0) AS qty, COUNT(DISTINCT tm.id) AS dok
       FROM transaksi_masuk_detail d
       JOIN transaksi_masuk tm ON tm.id = d.transaksi_masuk_id
       JOIN lokasi_rak lr ON lr.id = d.lokasi_rak_id
      WHERE lr.gudang_id = ? AND tm.tanggal >= DATE_SUB(CURDATE(), INTERVAL 6 DAY)"
);
$st->execute([$id]);
$m7 = $st->fetch();

$st = $pdo->prepare(
    "SELECT COALESCE(SUM(d.qty),0) AS qty, COUNT(DISTINCT tk.id) AS dok
       FROM transaksi_keluar_detail d
       JOIN transaksi_keluar tk ON tk.id = d.transaksi_keluar_id
       JOIN batch_barang bb ON bb.id = d.batch_id
       JOIN lokasi_rak lr ON lr.id = bb.lokasi_rak_id
      WHERE lr.gudang_id = ? AND tk.tanggal >= DATE_SUB(CURDATE(), INTERVAL 6 DAY)"
);
$st->execute([$id]);
$k7 = $st->fetch();

ok([
    'gudang'    => $g,
    'kpi'       => kpiGudang($pdo, $id),
    'rak'       => $rak,
    'kategori'  => $kategori,
    'aktivitas' => $aktivitas,
    'tujuh_hari' => [
        'masuk_qty'  => (int) $m7['qty'],
        'masuk_dok'  => (int) $m7['dok'],
        'keluar_qty' => (int) $k7['qty'],
        'keluar_dok' => (int) $k7['dok'],
    ],
]);

} catch (Throwable $e) {
    /* Pesan aslinya TIDAK ikut dikirim. Galat SQL memuat nama tabel dan
       potongan kueri — peta gratis bagi yang mencari celah. Yang lengkap
       masuk ke error_log server. */
    error_log('[api/gudang.php] ' . $e->getMessage());
    err('Terjadi kesalahan saat membaca data gudang.', 500);
}

// =====================================================================
//  PEMBANTU
// =====================================================================

/** Empat angka besar di kepala layar. */
function kpiGudang(PDO $pdo, int $id): array
{
    $st = $pdo->prepare(
        "SELECT COUNT(DISTINCT b.id) AS jml_barang,
                COALESCE(SUM(bb.qty_tersisa),0) AS total_stok,
                COUNT(DISTINCT CASE WHEN b.stok_minimum > 0 THEN b.id END) AS punya_minimum
         FROM batch_barang bb
         JOIN lokasi_rak lr ON lr.id = bb.lokasi_rak_id
         JOIN barang b ON b.id = bb.barang_id AND b.deleted_at IS NULL
         WHERE lr.gudang_id = ? AND bb.qty_tersisa > 0"
    );
    $st->execute([$id]);
    $a = $st->fetch();

    /* "Menipis" dan "Habis" harus dihitung setelah qty per barang
       dijumlahkan — barang dengan tiga batch @2 unit dan minimum 5 itu
       menipis, tapi kalau diperiksa per batch ia terlihat menipis tiga
       kali. Karena itu dikumpulkan dulu di subkueri. */
    $st = $pdo->prepare(
        "SELECT SUM(CASE WHEN t.qty <= t.minimum THEN 1 ELSE 0 END) AS menipis
         FROM (
             SELECT b.id, b.stok_minimum AS minimum, SUM(bb.qty_tersisa) AS qty
             FROM batch_barang bb
             JOIN lokasi_rak lr ON lr.id = bb.lokasi_rak_id
             JOIN barang b ON b.id = bb.barang_id AND b.deleted_at IS NULL
             WHERE lr.gudang_id = ? AND bb.qty_tersisa > 0 AND b.stok_minimum > 0
             GROUP BY b.id
         ) t"
    );
    $st->execute([$id]);
    $menipis = (int) ($st->fetchColumn() ?: 0);

    /* Habis = barang yang PERNAH ada di gudang ini tapi kini nol.
       Barang yang belum pernah masuk ke sini bukan "habis", ia memang
       bukan urusan gudang ini. */
    $st = $pdo->prepare(
        "SELECT COUNT(DISTINCT bb.barang_id)
         FROM batch_barang bb
         JOIN lokasi_rak lr ON lr.id = bb.lokasi_rak_id
         WHERE lr.gudang_id = ?
           AND bb.barang_id NOT IN (
               SELECT bb2.barang_id FROM batch_barang bb2
               JOIN lokasi_rak lr2 ON lr2.id = bb2.lokasi_rak_id
               WHERE lr2.gudang_id = ? AND bb2.qty_tersisa > 0
           )"
    );
    $st->execute([$id, $id]);

    return [
        'total_item'  => (int) $a['jml_barang'],
        'total_stok'  => (int) $a['total_stok'],
        'menipis'     => $menipis,
        'habis'       => (int) $st->fetchColumn(),
    ];
}

/** Ubah kolom mentah jadi bentuk yang siap dipakai layar. */
function rapikan(array &$b): void
{
    $b['id']         = (int) $b['id'];
    $b['jml_rak']    = (int) $b['jml_rak'];
    $b['kapasitas']  = (int) $b['kapasitas'];
    $b['terisi']     = (int) $b['terisi'];
    $b['jml_barang'] = (int) $b['jml_barang'];
    $b['is_active']  = (int) $b['is_active'] === 1;
    $b['persen']     = $b['kapasitas'] > 0
        ? min(100, (int) round($b['terisi'] * 100 / $b['kapasitas']))
        : null;
}

/**
 * Nama berkas foto → URL utuh yang bisa dibuka ponsel.
 *
 * Kembarannya foto_url_penuh() di api/barang.php. Sengaja disalin, bukan
 * dipanggil: fungsi itu berada di dalam endpoint, dan memuat barang.php
 * dari sini berarti ikut menjalankannya. Kalau salah satu berubah, yang
 * lain harus ikut — karena itu keduanya saling disebut di komentar.
 */
function foto_url_gudang(?string $nama): ?string
{
    $nama = basename(trim((string) $nama));
    if ($nama === '') { return null; }

    $berkas = __DIR__ . '/../' . DIR_UNGGAHAN . '/' . $nama;
    /* Nama yang tersimpan di basis data belum tentu masih ada berkasnya —
       berkas bisa hilang dari server tanpa lewat aplikasi. Mengirim URL
       yang menuju 404 membuat ponsel menggambar kotak rusak; mengirim
       null membuatnya menggambar petak berikon yang rapi. */
    if (!is_file($berkas)) { return null; }

    $proto = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host  = (string) ($_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? 'localhost');
    $host  = preg_replace('/[^A-Za-z0-9.\-:]/', '', $host);

    // dirname dua kali: /Gudang/api/gudang.php -> /Gudang
    $dirApi = rtrim(str_replace('\\', '/', dirname((string) ($_SERVER['SCRIPT_NAME'] ?? '/'))), '/');
    $dasar  = rtrim(str_replace('\\', '/', dirname($dirApi)), '/');
    if ($dasar === '.' || $dasar === '/') { $dasar = ''; }

    // Cap waktu berkas jadi pemecah cache: foto yang diganti langsung
    // terlihat di ponsel, bukan menunggu cache gambar kedaluwarsa sendiri.
    return $proto . '://' . $host . $dasar . '/' . DIR_UNGGAHAN . '/'
         . rawurlencode($nama) . '?v=' . filemtime($berkas);
}
