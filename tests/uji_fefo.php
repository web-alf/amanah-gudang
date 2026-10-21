<?php
/**
 * Uji FEFO — dijalankan dari baris perintah:
 *     php tests/uji_fefo.php
 *
 * Memakai basis data yang dikonfigurasi config.local.php, di dalam SATU
 * transaksi yang selalu di-ROLLBACK: barang, rak, dan batch uji tidak
 * pernah tersimpan. Aman dijalankan di komputer sendiri kapan pun.
 *
 * Yang diuji adalah kontrak fefo_sql() yang dipakai ambil_fefo() DAN
 * pratinjau_fefo():
 *   1. urutan: kedaluwarsa terdekat lebih dulu, tanpa tanggal paling akhir
 *   2. $hanyaLayak melewati batch berkondisi tidak layak salur
 *   3. $batchPilih didahulukan tanpa menghilangkan FEFO untuk sisanya
 *   4. pratinjau dan ambil menghasilkan urutan batch yang SAMA
 *   5. stok kurang → RuntimeException, bukan diam-diam mengambil sebagian
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
    if (!$gid) { throw new RuntimeException('Tidak ada gudang untuk uji.'); }
    $pdo->exec("INSERT INTO lokasi_rak (kode, nama, gudang_id) VALUES ('UJI-FEFO', 'Rak Uji FEFO', $gid)");
    $rak = (int) $pdo->lastInsertId();
    $pdo->exec("INSERT INTO barang (kode, nama, satuan, kategori_id, stok_minimum, harga)
                VALUES ('UJI-FEFO-1', 'Barang Uji FEFO', 'pcs', (SELECT MIN(id) FROM kategori), 0, 0)");
    $bid = (int) $pdo->lastInsertId();
    $layak = kondisi_layak_salur();
    $tidakLayak = 'rusak';   // salah satu kondisi tidak layak salur
    $ins = $pdo->prepare("INSERT INTO batch_barang (barang_id, nomor_batch, kondisi, lokasi_rak_id, tanggal_masuk, tanggal_expired, qty_awal, qty_tersisa)
                          VALUES (?,?,?,?,CURDATE(),?,?,?)");
    // nomor      kondisi         expired            qty
    $ins->execute([$bid, 'B-JAUH',   $layak[0],   $rak, '2030-01-01', 10, 10]);
    $ins->execute([$bid, 'B-DEKAT',  $layak[0],   $rak, '2027-01-01', 5,  5]);
    $ins->execute([$bid, 'B-TANPA',  $layak[0],   $rak, null,          7,  7]);
    $ins->execute([$bid, 'B-RUSAK',  $tidakLayak, $rak, '2026-12-01', 4,  4]);

    echo "1. Urutan FEFO (pratinjau)\n";
    $p = pratinjau_fefo($pdo, $bid, 12, null, $gid, null);
    $urut = array_column($p, 'nomor_batch');
    uji('batch rusak (2026-12) ikut bila tidak dibatasi layak, dan paling dulu', ($urut[0] ?? '') === 'B-RUSAK', implode(',', $urut));
    uji('lalu kedaluwarsa terdekat', ($urut[1] ?? '') === 'B-DEKAT', implode(',', $urut));
    uji('lalu yang jauh', ($urut[2] ?? '') === 'B-JAUH', implode(',', $urut));
    uji('jumlah yang diambil tepat 12', array_sum(array_column($p, 'ambil')) === 12);

    echo "2. hanyaLayak melewati batch tidak layak\n";
    $p = pratinjau_fefo($pdo, $bid, 12, null, $gid, null, true);
    $urut = array_column($p, 'nomor_batch');
    uji('B-RUSAK tidak ada', !in_array('B-RUSAK', $urut, true), implode(',', $urut));
    uji('mulai dari B-DEKAT', ($urut[0] ?? '') === 'B-DEKAT');
    uji('tanpa tanggal paling akhir', end($urut) === 'B-TANPA' || !in_array('B-TANPA', $urut, true));

    echo "3. batchPilih didahulukan\n";
    $p = pratinjau_fefo($pdo, $bid, 12, null, $gid, null, true, 'B-JAUH');
    $urut = array_column($p, 'nomor_batch');
    uji('B-JAUH di depan', ($urut[0] ?? '') === 'B-JAUH', implode(',', $urut));
    uji('sisanya tetap FEFO (B-DEKAT)', ($urut[1] ?? '') === 'B-DEKAT', implode(',', $urut));
    $p2 = pratinjau_fefo($pdo, $bid, 12, null, $gid, null, true, 'B-JAUH, B-DEKAT');
    uji('nilai berkoma diabaikan (bukan pilihan)', (array_column($p2, 'nomor_batch')[0] ?? '') === 'B-DEKAT');

    echo "4. pratinjau == ambil\n";
    $a = ambil_fefo($pdo, $bid, 12, null, $gid, null, true, 'B-JAUH');
    uji('urutan batch sama', array_column($a, 'nomor_batch') === array_column($p, 'nomor_batch'),
        implode(',', array_column($a, 'nomor_batch')) . ' vs ' . implode(',', array_column($p, 'nomor_batch')));
    uji('jumlah per batch sama', array_column($a, 'ambil') === array_column($p, 'ambil'));

    echo "5. stok kurang\n";
    $lempar = false;
    try { ambil_fefo($pdo, $bid, 999, null, $gid, null, true); } catch (RuntimeException $e) { $lempar = true; }
    uji('melempar RuntimeException', $lempar);
} catch (Throwable $e) {
    $gagal++;
    echo "  GAGAL galat tak terduga: " . $e->getMessage() . "\n";
} finally {
    $pdo->rollBack();
}
echo "\n$lulus lulus, $gagal gagal\n";
exit($gagal > 0 ? 1 : 0);
