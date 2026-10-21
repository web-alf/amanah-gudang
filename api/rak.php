<?php
/**
 * =====================================================================
 *  AMANAH — RAK / LOKASI (untuk layar Rak di aplikasi ponsel)
 * =====================================================================
 *  GET /api/rak.php                  → daftar rak + ringkasan
 *  GET /api/rak.php?gudang_id=N      → daftar rak di satu gudang saja
 *  GET /api/rak.php?id=N             → rincian satu rak + barang di dalamnya
 *
 *  ---------------------------------------------------------------------
 *  KENAPA TIDAK MENUMPANG master.php
 *  ---------------------------------------------------------------------
 *  Alasannya sama dengan gudang.php: master.php mengirim sebaris data
 *  polos per entitas, sedangkan layar Rak butuh keterisian, sisa ruang,
 *  jumlah jenis barang, dan isi raknya. Menyisipkan subkueri itu ke
 *  master.php membuat empat entitas lain ikut membayar biayanya.
 *
 *  Tambah/ubah/hapus tetap lewat master.php?entity=rak, supaya aturan
 *  wewenangnya hanya ditulis di satu tempat. Yang di sini hanya MEMBACA.
 *
 *  ---------------------------------------------------------------------
 *  ANGKANYA DARI MANA
 *  ---------------------------------------------------------------------
 *  Rumusnya disalin dari blok "isi tiap rak" di gudang.php, bukan
 *  dikarang ulang — supaya angka rak di layar Gudang dan di layar Rak
 *  tidak pernah berselisih:
 *    * terisi     = SUM(batch_barang.qty_tersisa) di rak itu
 *    * jml_barang = COUNT(DISTINCT barang_id) yang masih bersisa
 *    * persen     = terisi / kapasitas, atau null bila kapasitas kosong
 * =====================================================================
 */
require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/_lingkup.php';
require_once __DIR__ . '/../_lib.php';   // DIR_UNGGAHAN

$payload = auth_required();

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    err('Endpoint ini hanya membaca. Untuk mengubah data rak, pakai master.php?entity=rak.', 405);
}

$id = (int) ($_GET['id'] ?? 0);

/* ---------------------------------------------------------------------
   Pembatas lingkup — sama dengan gudang.php.

   Admin gudang hanya boleh melihat rak di gudangnya sendiri. Tanpa pagar
   ini, menebak id di URL sudah cukup untuk membaca isi rak cabang lain.
   --------------------------------------------------------------------- */
$gidSaya = api_gudang_id($payload);          // null = boleh lihat semua
$pagar   = $gidSaya === null ? '' : ' AND lr.gudang_id = ' . (int) $gidSaya;

$ringkasanKosong = [
    'total' => 0, 'terisi' => 0, 'kosong' => 0, 'hampir_penuh' => 0,
    'kapasitas' => 0, 'isi' => 0,
];

if ($gidSaya !== null && $gidSaya <= 0) {
    // Terikat gudang, tapi belum ditugaskan ke gudang mana pun.
    if ($id > 0) { err('Rak ini di luar wewenang akun Anda.', 403); }
    ok(['data' => [], 'ringkasan' => $ringkasanKosong]);
}

/* Subkueri yang dipakai ulang di daftar maupun rincian. */
const SUB_RAK = "
    (SELECT COALESCE(SUM(bb.qty_tersisa),0) FROM batch_barang bb
      WHERE bb.lokasi_rak_id = lr.id AND bb.qty_tersisa > 0) AS terisi,
    (SELECT COUNT(DISTINCT bb.barang_id) FROM batch_barang bb
      WHERE bb.lokasi_rak_id = lr.id AND bb.qty_tersisa > 0) AS jml_barang";

try {

// =====================================================================
//  A. DAFTAR RAK
// =====================================================================
if ($id === 0) {
    $cari     = trim($_GET['q'] ?? '');
    $gudangId = (int) ($_GET['gudang_id'] ?? 0);
    [$limit, $offset] = paginate(100);

    /* $w0 = pagar + saringan gudang, TANPA kata pencarian. Ringkasan di
       kepala layar dihitung dari $w0 supaya angkanya tidak ikut menyusut
       saat orang mengetik — "Total 24" yang berubah jadi "Total 3" begitu
       kotak cari diisi terbaca sebagai rak yang hilang. */
    $w0 = "lr.deleted_at IS NULL AND g.deleted_at IS NULL" . $pagar;
    if ($gudangId > 0) { $w0 .= " AND lr.gudang_id = " . $gudangId; }

    $w = $w0;
    $p = [];
    if ($cari !== '') {
        $w .= " AND (lr.kode LIKE ? OR lr.nama LIKE ? OR g.nama LIKE ?)";
        $p = ['%'.$cari.'%', '%'.$cari.'%', '%'.$cari.'%'];
    }

    $st = $pdo->prepare(
        "SELECT lr.id, lr.kode, lr.nama, lr.kapasitas, lr.keterangan,
                lr.gudang_id, g.kode AS gudang_kode, g.nama AS gudang_nama," . SUB_RAK . "
         FROM lokasi_rak lr
         JOIN gudang g ON g.id = lr.gudang_id
         WHERE $w
         ORDER BY g.kode, lr.kode
         LIMIT $limit OFFSET $offset"
    );
    $st->execute($p);
    $baris = $st->fetchAll();
    foreach ($baris as &$b) { rapikanRak($b); }
    unset($b);

    /* Ringkasan dihitung di SQL dari seluruh baris yang lolos $w0, bukan
       dari halaman yang terkirim — supaya tetap benar sekalipun raknya
       kelak melewati batas satu halaman. */
    $r = $pdo->query(
        "SELECT COUNT(*) AS total,
                COALESCE(SUM(t.terisi > 0), 0) AS terisi,
                COALESCE(SUM(t.terisi = 0), 0) AS kosong,
                COALESCE(SUM(t.kapasitas > 0 AND t.terisi * 100 >= t.kapasitas * 75), 0) AS hampir_penuh,
                COALESCE(SUM(t.kapasitas), 0) AS kapasitas,
                COALESCE(SUM(t.terisi), 0) AS isi
         FROM (
             SELECT COALESCE(lr.kapasitas, 0) AS kapasitas," . SUB_RAK . "
             FROM lokasi_rak lr
             JOIN gudang g ON g.id = lr.gudang_id
             WHERE $w0
         ) t"
    )->fetch();

    ok([
        'data' => $baris,
        'ringkasan' => [
            'total'        => (int) $r['total'],
            'terisi'       => (int) $r['terisi'],
            'kosong'       => (int) $r['kosong'],
            'hampir_penuh' => (int) $r['hampir_penuh'],
            'kapasitas'    => (int) $r['kapasitas'],
            'isi'          => (int) $r['isi'],
        ],
    ]);
}

// =====================================================================
//  B. RINCIAN SATU RAK
// =====================================================================
$st = $pdo->prepare(
    "SELECT lr.id, lr.kode, lr.nama, lr.kapasitas, lr.keterangan, lr.created_at,
            lr.gudang_id, g.kode AS gudang_kode, g.nama AS gudang_nama,
            g.jenis AS gudang_jenis, g.alamat AS gudang_alamat," . SUB_RAK . "
     FROM lokasi_rak lr
     JOIN gudang g ON g.id = lr.gudang_id
     WHERE lr.id = ? AND lr.deleted_at IS NULL"
);
$st->execute([$id]);
$r = $st->fetch();
if (!$r) { err('Rak tidak ditemukan.', 404); }

/* 403, bukan 404, untuk rak yang ada tapi di luar pagar: yang meminta
   perlu tahu bahwa raknya ada dan ia tidak berwenang — bukan mengira
   kodenya salah lalu mengetik ulang. */
if ($gidSaya !== null && (int) $r['gudang_id'] !== $gidSaya) {
    err('Rak ini di luar wewenang akun Anda.', 403);
}
rapikanRak($r);

/* --- Barang di rak ini: satu baris per BARANG, bukan per batch --- */
$st = $pdo->prepare(
    "SELECT b.id, b.kode, b.nama, b.satuan, b.foto, b.stok_minimum,
            k.nama AS kategori,
            SUM(bb.qty_tersisa) AS qty,
            COUNT(bb.id) AS jml_batch,
            MIN(bb.tanggal_expired) AS expired_terdekat
     FROM batch_barang bb
     JOIN barang b ON b.id = bb.barang_id AND b.deleted_at IS NULL
     LEFT JOIN kategori k ON k.id = b.kategori_id
     WHERE bb.lokasi_rak_id = ? AND bb.qty_tersisa > 0
     GROUP BY b.id
     ORDER BY qty DESC, b.nama
     LIMIT 200"
);
$st->execute([$id]);
$barang = $st->fetchAll();
foreach ($barang as &$b) {
    $b['id']           = (int) $b['id'];
    $b['qty']          = (int) $b['qty'];
    $b['jml_batch']    = (int) $b['jml_batch'];
    $b['stok_minimum'] = (int) $b['stok_minimum'];
    $b['foto_url']     = foto_url_rak($b['foto'] ?? '');
    /* Status dihitung sekali di sini, dengan rumus yang sama dengan
       gudang.php — keping warna di dua layar tidak boleh berbeda pendapat
       tentang barang yang sama. */
    $b['status'] = $b['qty'] <= 0 ? 'habis'
                 : ($b['stok_minimum'] > 0 && $b['qty'] <= $b['stok_minimum'] ? 'menipis' : 'aman');
    unset($b['foto']);
}
unset($b);

ok([
    'rak'    => $r,
    'barang' => $barang,
]);

} catch (Throwable $e) {
    /* Pesan aslinya TIDAK ikut dikirim — galat SQL memuat nama tabel dan
       potongan kueri. Yang lengkap masuk ke error_log server. */
    error_log('[api/rak.php] ' . $e->getMessage());
    err('Terjadi kesalahan saat membaca data rak.', 500);
}

// =====================================================================
//  PEMBANTU
// =====================================================================

/** Ubah kolom mentah jadi bentuk yang siap dipakai layar. */
function rapikanRak(array &$b): void
{
    $b['id']         = (int) $b['id'];
    $b['gudang_id']  = (int) $b['gudang_id'];
    $b['kapasitas']  = (int) ($b['kapasitas'] ?? 0);
    $b['terisi']     = (int) $b['terisi'];
    $b['jml_barang'] = (int) $b['jml_barang'];
    $b['keterangan'] = (string) ($b['keterangan'] ?? '');
    $b['sisa']       = max(0, $b['kapasitas'] - $b['terisi']);
    /* null, bukan 0, kalau kapasitasnya belum diisi. Batang 0% pada rak
       yang sebetulnya penuh adalah salah baca yang meyakinkan. */
    $b['persen']     = $b['kapasitas'] > 0
        ? min(100, (int) round($b['terisi'] * 100 / $b['kapasitas']))
        : null;
}

/**
 * Nama berkas foto → URL utuh yang bisa dibuka ponsel.
 *
 * Kembaran foto_url_gudang() di api/gudang.php dan foto_url_penuh() di
 * api/barang.php. Sengaja disalin, bukan dipanggil: keduanya berada di
 * dalam endpoint, dan memuatnya dari sini berarti ikut menjalankannya.
 * Kalau salah satu berubah, yang lain harus ikut.
 */
function foto_url_rak(?string $nama): ?string
{
    $nama = basename(trim((string) $nama));
    if ($nama === '') { return null; }

    $berkas = __DIR__ . '/../' . DIR_UNGGAHAN . '/' . $nama;
    if (!is_file($berkas)) { return null; }

    $proto = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host  = (string) ($_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? 'localhost');
    $host  = preg_replace('/[^A-Za-z0-9.\-:]/', '', $host);

    $dirApi = rtrim(str_replace('\\', '/', dirname((string) ($_SERVER['SCRIPT_NAME'] ?? '/'))), '/');
    $dasar  = rtrim(str_replace('\\', '/', dirname($dirApi)), '/');
    if ($dasar === '.' || $dasar === '/') { $dasar = ''; }

    return $proto . '://' . $host . $dasar . '/' . DIR_UNGGAHAN . '/'
         . rawurlencode($nama) . '?v=' . filemtime($berkas);
}
