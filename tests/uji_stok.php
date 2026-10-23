<?php
/**
 * Uji penambahan & penghitungan stok — dijalankan dari baris perintah:
 *     php tests/uji_stok.php
 *
 * Satu transaksi, selalu di-ROLLBACK — aman dijalankan kapan pun.
 *
 * Yang diuji adalah tambah_ke_lokasi() — pintu masuk TUNGGAL ke batch_barang,
 * dipakai baik oleh masuk.php/api/masuk.php maupun terapkan_perpindahan():
 *   1. batch baru dibuat bila belum ada yang identitasnya sama
 *   2. identitas sama (barang+nomor+kondisi+sumber+donatur+lokasi+expired)
 *      digabung ke batch yang sama, bukan baris baru
 *   3. kondisi berbeda dengan nomor_batch sama TIDAK digabung
 *   4. donatur_id diisi memaksa sumber jadi 'donasi' apa pun yang dikirim
 *   5. rak dan toko kosong dua-duanya → ditolak
 *   6. rak dan toko diisi dua-duanya → ditolak
 * ...serta recalc_stok(), stok_tersedia(), stok_layak_salur(), stok_di_toko():
 *   7. recalc_stok() hanya menjumlah batch di GUDANG, stok toko tidak ikut
 *   8. stok_tersedia() menjumlah semua lokasi; stok_layak_salur() membuang
 *      kondisi tidak layak salur DAN batch yang ada di toko
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
    $pdo->exec("INSERT INTO lokasi_rak (kode, nama, gudang_id) VALUES ('UJI-STOK', 'Rak Uji Stok', $gid)");
    $rak = (int) $pdo->lastInsertId();
    $pdo->exec("INSERT INTO toko (kode, nama) VALUES ('UJI-STOK', 'Toko Uji Stok')");
    $toko = (int) $pdo->lastInsertId();
    $pdo->exec("INSERT INTO donatur (kode, nama) VALUES ('UJI-STOK', 'Donatur Uji Stok')");
    $donId = (int) $pdo->lastInsertId();
    $pdo->exec("INSERT INTO barang (kode, nama, satuan, kategori_id, stok_minimum, harga)
                VALUES ('UJI-STOK-1', 'Barang Uji Stok', 'pcs', (SELECT MIN(id) FROM kategori), 0, 0)");
    $bid = (int) $pdo->lastInsertId();

    echo "1-2. batch baru vs digabung (identitas sama)\n";
    $id1 = tambah_ke_lokasi($pdo, $bid, $rak, null, 'B1', null, 10, 100, null, 'baru', 'pembelian');
    $id1b = tambah_ke_lokasi($pdo, $bid, $rak, null, 'B1', null, 5, 999, null, 'baru', 'pembelian');
    uji('baris kedua digabung ke batch yang sama', $id1 === $id1b);
    $bb1 = $pdo->query("SELECT qty_awal, qty_tersisa, harga_beli FROM batch_barang WHERE id = $id1")->fetch();
    uji('qty_tersisa terjumlah (10+5=15)', (int) $bb1['qty_tersisa'] === 15, (string) $bb1['qty_tersisa']);
    uji('qty_awal ikut terjumlah', (int) $bb1['qty_awal'] === 15);
    uji('harga_beli TIDAK ditimpa karena sudah terisi (tetap 100)', (float) $bb1['harga_beli'] === 100.0, (string) $bb1['harga_beli']);

    echo "3. kondisi berbeda tidak digabung\n";
    $id2 = tambah_ke_lokasi($pdo, $bid, $rak, null, 'B1', null, 3, 50, null, 'bekas_layak', 'pembelian');
    uji('batch baru, bukan id1', $id2 !== $id1);

    echo "4. donatur_id memaksa sumber jadi donasi\n";
    $id3 = tambah_ke_lokasi($pdo, $bid, $rak, null, 'B-DONASI', null, 2, 0, null, 'baru', 'pembelian', $donId);
    $sumber3 = $pdo->query("SELECT sumber FROM batch_barang WHERE id = $id3")->fetchColumn();
    uji('sumber dipaksa jadi donasi', $sumber3 === 'donasi', (string) $sumber3);

    echo "5-6. lokasi tujuan wajib tepat satu\n";
    $lempar5 = false;
    try { tambah_ke_lokasi($pdo, $bid, null, null, 'B-X', null, 1); } catch (RuntimeException $e) { $lempar5 = true; }
    uji('rak dan toko kosong dua-duanya ditolak', $lempar5);

    $lempar6 = false;
    try { tambah_ke_lokasi($pdo, $bid, $rak, $toko, 'B-Y', null, 1); } catch (RuntimeException $e) { $lempar6 = true; }
    uji('rak dan toko terisi dua-duanya ditolak', $lempar6);

    echo "7-8. recalc_stok() dan pembagi layak-salur\n";
    tambah_ke_lokasi($pdo, $bid, null, $toko, 'B-TOKO', null, 20, 0, null, 'baru', 'pembelian');
    tambah_ke_lokasi($pdo, $bid, $rak, null, 'B-RUSAK-TL', null, 6, 0, null, 'bekas_tidak_layak', 'pembelian');
    // Total di rak sekarang: 15 (id1) + 3 (id2) + 2 (id3) + 6 (tidak layak) = 26. Di toko: 20.

    recalc_stok($pdo, $bid);
    $qtyTotal = (int) $pdo->query("SELECT qty_total FROM stok WHERE barang_id = $bid")->fetchColumn();
    uji('recalc_stok mengabaikan stok toko (26, bukan 46)', $qtyTotal === 26, (string) $qtyTotal);

    uji('stok_tersedia() tanpa rak menjumlah SEMUA lokasi (46)', stok_tersedia($pdo, $bid) === 46, (string) stok_tersedia($pdo, $bid));
    uji('stok_tersedia() dengan rak hanya rak itu (26)', stok_tersedia($pdo, $bid, $rak) === 26, (string) stok_tersedia($pdo, $bid, $rak));
    uji('stok_di_toko() benar (20)', stok_di_toko($pdo, $bid, $toko) === 20, (string) stok_di_toko($pdo, $bid, $toko));

    $layak = stok_layak_salur($pdo, $bid);
    uji('stok_layak_salur() membuang tidak-layak DAN stok toko (15+3+2=20)', $layak === 20, (string) $layak);
} catch (Throwable $e) {
    $gagal++;
    echo "  GAGAL galat tak terduga: " . $e->getMessage() . "\n";
} finally {
    $pdo->rollBack();
}
echo "\n$lulus lulus, $gagal gagal\n";
exit($gagal > 0 ? 1 : 0);
