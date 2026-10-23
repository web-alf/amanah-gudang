<?php
/**
 * Uji opname/koreksi stok — dijalankan dari baris perintah:
 *     php tests/uji_opname.php
 *
 * Satu transaksi, selalu di-ROLLBACK — aman dijalankan kapan pun.
 *
 * Yang diuji adalah kontrak sesuaikan_batch():
 *   1. jumlah fisik sama dengan sistem → tidak melakukan apa-apa (0, tanpa catatan)
 *   2. beda tanpa alasan → ditolak
 *   3. beda dengan alasan, qty naik → qty_tersisa bertambah, tercatat 'koreksi_tambah'
 *   4. beda dengan alasan, qty turun → qty_tersisa berkurang, tercatat 'koreksi_kurang'
 *   5. jumlah fisik negatif diperlakukan sebagai 0 (max(0, ...))
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit("hanya dari baris perintah\n"); }
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../_lib.php';

$lulus = 0; $gagal = 0;
function uji(string $nama, bool $kondisi, string $ket = ''): void {
    global $lulus, $gagal;
    if ($kondisi) { $lulus++; echo "  ok   $nama\n"; }
    else          { $gagal++; echo "  GAGAL $nama" . ($ket !== '' ? " — $ket" : '') . "\n"; }
}

$pdo->beginTransaction();
try {
    $gid = (int) $pdo->query("SELECT MIN(id) FROM gudang WHERE deleted_at IS NULL")->fetchColumn();
    $uid = (int) $pdo->query("SELECT MIN(id) FROM users WHERE deleted_at IS NULL")->fetchColumn();
    if (!$gid) { throw new RuntimeException('Tidak ada gudang untuk uji.'); }
    $pdo->exec("INSERT INTO lokasi_rak (kode, nama, gudang_id) VALUES ('UJI-OPNM', 'Rak Uji Opname', $gid)");
    $rak = (int) $pdo->lastInsertId();
    $pdo->exec("INSERT INTO barang (kode, nama, satuan, kategori_id, stok_minimum, harga)
                VALUES ('UJI-OPNM-1', 'Barang Uji Opname', 'pcs', (SELECT MIN(id) FROM kategori), 0, 0)");
    $bid = (int) $pdo->lastInsertId();
    $batchId = tambah_ke_lokasi($pdo, $bid, $rak, null, 'B1', null, 10, 0, null, 'baru', 'pembelian');

    echo "1. tidak ada beda\n";
    $beda0 = sesuaikan_batch($pdo, $batchId, 10, '');
    uji('mengembalikan 0', $beda0 === 0);
    $sisa = (int) $pdo->query("SELECT qty_tersisa FROM batch_barang WHERE id = $batchId")->fetchColumn();
    uji('qty_tersisa tidak berubah (masih 10)', $sisa === 10);
    $jmlCatatan = (int) $pdo->query("SELECT COUNT(*) FROM penyesuaian_stok WHERE batch_id = $batchId")->fetchColumn();
    uji('tidak ada catatan penyesuaian dibuat', $jmlCatatan === 0);

    echo "2. beda tanpa alasan ditolak\n";
    $lempar = false;
    try { sesuaikan_batch($pdo, $batchId, 7, ''); } catch (RuntimeException $e) { $lempar = true; }
    uji('melempar RuntimeException', $lempar);
    $sisa = (int) $pdo->query("SELECT qty_tersisa FROM batch_barang WHERE id = $batchId")->fetchColumn();
    uji('qty_tersisa TIDAK berubah walau ditolak', $sisa === 10);

    echo "3. qty naik, dengan alasan\n";
    $beda = sesuaikan_batch($pdo, $batchId, 15, 'stok hasil hitung ulang lebih banyak', $uid);
    uji('mengembalikan +5', $beda === 5, (string) $beda);
    $sisa = (int) $pdo->query("SELECT qty_tersisa FROM batch_barang WHERE id = $batchId")->fetchColumn();
    uji('qty_tersisa jadi 15', $sisa === 15);
    $jenisTercatat = $pdo->query("SELECT jenis FROM penyesuaian_stok WHERE batch_id = $batchId ORDER BY id DESC LIMIT 1")->fetchColumn();
    uji("jenis tercatat 'koreksi_tambah'", $jenisTercatat === 'koreksi_tambah', (string) $jenisTercatat);

    echo "4. qty turun, dengan alasan\n";
    $beda = sesuaikan_batch($pdo, $batchId, 9, 'barang hilang saat stok opname', $uid);
    uji('mengembalikan -6', $beda === -6, (string) $beda);
    $sisa = (int) $pdo->query("SELECT qty_tersisa FROM batch_barang WHERE id = $batchId")->fetchColumn();
    uji('qty_tersisa jadi 9', $sisa === 9);
    $jenisTercatat = $pdo->query("SELECT jenis FROM penyesuaian_stok WHERE batch_id = $batchId ORDER BY id DESC LIMIT 1")->fetchColumn();
    uji("jenis tercatat 'koreksi_kurang'", $jenisTercatat === 'koreksi_kurang', (string) $jenisTercatat);

    echo "5. jumlah fisik negatif dianggap 0\n";
    $beda = sesuaikan_batch($pdo, $batchId, -5, 'input keliru, dianggap kosong', $uid);
    uji('mengembalikan -9 (dari 9 ke 0)', $beda === -9, (string) $beda);
    $sisa = (int) $pdo->query("SELECT qty_tersisa FROM batch_barang WHERE id = $batchId")->fetchColumn();
    uji('qty_tersisa jadi 0, bukan negatif', $sisa === 0);
} catch (Throwable $e) {
    $gagal++;
    echo "  GAGAL galat tak terduga: " . $e->getMessage() . "\n";
} finally {
    $pdo->rollBack();
}
echo "\n$lulus lulus, $gagal gagal\n";
exit($gagal > 0 ? 1 : 0);
