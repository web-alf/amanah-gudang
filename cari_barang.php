<?php
/**
 * AMANAH — Endpoint pencarian barang untuk input gaya scan.
 *
 * Dipanggil assets/scan.js setiap kali sebuah kode ditembak scanner,
 * dibaca kamera, atau diketik manual. Mengembalikan JSON.
 *
 * Urutan pencocokan sengaja dari yang paling pasti ke paling longgar:
 *   1. barcode persis      → hasil tunggal, langsung dipakai
 *   2. kode barang persis  → hasil tunggal
 *   3. nomor batch persis  → hasil tunggal (label rak kerap memakai ini)
 *   4. cocok sebagian nama/kode/merek → beberapa hasil, user memilih
 *
 * Parameter:
 *   q      kode/nama yang dicari                        (wajib)
 *   lokasi 'gudang' | 'rak' | 'toko' | ''               (opsional)
 *   id     id gudang/rak/toko untuk menghitung stoknya  (opsional)
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/_lib.php';
require_login();

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$q      = trim($_GET['q'] ?? '');
$lokasi = $_GET['lokasi'] ?? '';
$lokId  = (int) ($_GET['id'] ?? 0);

if ($q === '' || mb_strlen($q) > 80) {
    echo json_encode(['ok' => false, 'pesan' => 'Kode pencarian kosong atau terlalu panjang.']);
    exit;
}

/** Hitung stok satu barang pada lokasi yang sedang dipakai form. */
function stok_konteks(PDO $pdo, int $barangId, string $lokasi, int $lokId): ?int {
    try {
        switch ($lokasi) {
            case 'toko':   return $lokId ? stok_di_toko($pdo, $barangId, $lokId)   : null;
            case 'gudang': return $lokId ? stok_di_gudang($pdo, $barangId, $lokId) : null;
            case 'rak':    return $lokId ? stok_tersedia($pdo, $barangId, $lokId)  : null;
            default:       return stok_tersedia($pdo, $barangId);
        }
    } catch (Throwable $e) {
        error_log('[AMANAH] cari_barang stok: ' . $e->getMessage());
        return null;
    }
}

try {
    $kolom = "b.id, b.kode, b.barcode, b.nama, b.merek, b.ukuran, b.satuan, b.harga,
              b.stok_minimum, b.is_active, k.nama AS kategori";
    $dari  = "FROM barang b LEFT JOIN kategori k ON k.id = b.kategori_id
              WHERE b.deleted_at IS NULL";

    $hasil = [];
    $sumber = '';

    // 1 & 2 — barcode atau kode barang persis
    $st = $pdo->prepare("SELECT $kolom $dari AND (b.barcode = ? OR b.kode = ?) LIMIT 5");
    $st->execute([$q, $q]);
    $hasil = $st->fetchAll();
    if ($hasil) $sumber = 'kode';

    // 3 — nomor batch persis
    if (!$hasil) {
        $st = $pdo->prepare(
            "SELECT $kolom $dari
               AND b.id IN (SELECT barang_id FROM batch_barang WHERE nomor_batch = ?)
             LIMIT 5"
        );
        $st->execute([$q]);
        $hasil = $st->fetchAll();
        if ($hasil) $sumber = 'batch';
    }

    // 4 — cocok sebagian
    if (!$hasil) {
        $like = '%' . like_escape($q) . '%';
        $st = $pdo->prepare(
            "SELECT $kolom $dari AND (b.nama LIKE ? OR b.kode LIKE ? OR b.merek LIKE ? OR b.barcode LIKE ?)
             ORDER BY b.nama LIMIT 12"
        );
        $st->execute([$like, $like, $like, $like]);
        $hasil = $st->fetchAll();
        if ($hasil) $sumber = 'mirip';
    }

    if (!$hasil) {
        echo json_encode([
            'ok'    => false,
            'pesan' => "Barang dengan kode \"$q\" tidak ditemukan.",
            'saran' => 'Periksa kembali kodenya, atau tambahkan barangnya dulu di menu Data Barang.',
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $data = [];
    foreach ($hasil as $b) {
        $stok = stok_konteks($pdo, (int) $b['id'], $lokasi, $lokId);
        $data[] = [
            'id'       => (int) $b['id'],
            'kode'     => $b['kode'],
            'barcode'  => $b['barcode'],
            'nama'     => $b['nama'],
            'merek'    => $b['merek'],
            'ukuran'   => $b['ukuran'],
            'satuan'   => $b['satuan'],
            'harga'    => (float) $b['harga'],
            // Formulir yang hanya menerima produk aktif (Barang Masuk) menolak yang false.
            'aktif'    => (int) $b['is_active'] === 1,
            'kategori' => $b['kategori'],
            'stok'     => $stok,
            'label'    => $b['nama'] . ' (' . $b['kode'] . ')',
        ];
    }

    echo json_encode([
        'ok'     => true,
        'sumber' => $sumber,           // 'kode' | 'batch' | 'mirip'
        'pasti'  => $sumber !== 'mirip' && count($data) === 1,
        'data'   => $data,
    ], JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {
    error_log('[AMANAH] cari_barang: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'pesan' => 'Terjadi kesalahan saat mencari barang.']);
}
