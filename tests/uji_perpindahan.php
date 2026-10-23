<?php
/**
 * Uji perpindahan stok — dijalankan dari baris perintah:
 *     php tests/uji_perpindahan.php
 *
 * Satu transaksi, selalu di-ROLLBACK — aman dijalankan kapan pun.
 *
 * Yang diuji:
 *   1. periksa_rute() menolak kombinasi asal/tujuan yang tidak sah
 *   2. rak_tujuan(): rak asal == rak tujuan ditolak (self-transfer)
 *   3. rak_tujuan(): tujuan 'gudang' memilih rak berkapasitas kosong
 *      terbanyak, dan MENGECUALIKAN rak asal
 *   4. terapkan_perpindahan() jenis 'transfer_gudang': stok asal berkurang,
 *      stok tujuan bertambah, jumlah sama persis
 *   5. terapkan_perpindahan() memakai qty_diterima (bila ada) BUKAN qty
 *   6. terapkan_perpindahan() jenis 'penerimaan': stok hanya bertambah di
 *      tujuan, tidak ada pengurangan di mana pun
 *   7. periksa_stok_dokumen(): stok tidak cukup di lokasi asal dilaporkan
 *   8. periksa_stok_dokumen(): stok yang sudah "dipesan" dokumen status
 *      'dikirim' lain ikut dikurangkan dari yang tersedia
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
    $uid = (int) $pdo->query("SELECT MIN(id) FROM users WHERE deleted_at IS NULL")->fetchColumn();

    $pdo->exec("INSERT INTO gudang (kode, nama, jenis) VALUES ('UJI-PP-A', 'Gudang Uji A', 'pusat')");
    $gudangA = (int) $pdo->lastInsertId();
    $pdo->exec("INSERT INTO gudang (kode, nama, jenis) VALUES ('UJI-PP-B', 'Gudang Uji B', 'pusat')");
    $gudangB = (int) $pdo->lastInsertId();

    $pdo->exec("INSERT INTO lokasi_rak (kode, nama, gudang_id, kapasitas) VALUES ('UJI-PP-RA', 'Rak A', $gudangA, 100)");
    $rakA = (int) $pdo->lastInsertId();
    $pdo->exec("INSERT INTO lokasi_rak (kode, nama, gudang_id, kapasitas) VALUES ('UJI-PP-RB1', 'Rak B1 penuh', $gudangB, 10)");
    $rakB1 = (int) $pdo->lastInsertId();
    $pdo->exec("INSERT INTO lokasi_rak (kode, nama, gudang_id, kapasitas) VALUES ('UJI-PP-RB2', 'Rak B2 lega', $gudangB, 100)");
    $rakB2 = (int) $pdo->lastInsertId();

    $pdo->exec("INSERT INTO barang (kode, nama, satuan, kategori_id, stok_minimum, harga)
                VALUES ('UJI-PP-1', 'Barang Uji Perpindahan', 'pcs', (SELECT MIN(id) FROM kategori), 0, 0)");
    $bid = (int) $pdo->lastInsertId();

    echo "1. periksa_rute() menolak kombinasi tidak sah\n";
    uji('penerimaan dari gudang (bukan supplier) ditolak', periksa_rute('penerimaan', 'gudang', 'rak') !== null);
    uji('mutasi_rak dengan tujuan toko ditolak', periksa_rute('mutasi_rak', 'rak', 'toko') !== null);
    uji('transfer_gudang asal rak tujuan gudang itu SAH', periksa_rute('transfer_gudang', 'rak', 'gudang') === null);

    echo "2. rak_tujuan() menolak rak asal == rak tujuan\n";
    $lempar = false;
    try { rak_tujuan($pdo, 'rak', $rakA, $rakA); } catch (RuntimeException $e) { $lempar = true; }
    uji('melempar RuntimeException', $lempar);

    echo "3. rak_tujuan() tujuan gudang memilih rak terlega, kecuali rak asal\n";
    // isi rakB1 (kapasitas 10) sampai nyaris penuh supaya rakB2 (kapasitas 100, kosong) menang
    tambah_ke_lokasi($pdo, $bid, $rakB1, null, 'ISI-B1', null, 9, 0, null, 'baru', 'pembelian');
    $pilih = rak_tujuan($pdo, 'gudang', $gudangB, null);
    uji('memilih rakB2 yang lebih lega', $pilih === $rakB2, "dapat $pilih, harap $rakB2");
    $pilihKecualiB2 = rak_tujuan($pdo, 'gudang', $gudangB, $rakB2);
    uji('rak asal dikecualikan dari pilihan (jatuh ke rakB1)', $pilihKecualiB2 === $rakB1, "dapat $pilihKecualiB2");

    echo "4-5. terapkan_perpindahan() transfer_gudang, qty_diterima menang atas qty\n";
    tambah_ke_lokasi($pdo, $bid, $rakA, null, 'B-TRF', '2030-01-01', 20, 55, null, 'baru', 'pembelian');
    $pdo->exec("INSERT INTO perpindahan
                  (no_perpindahan, jenis, tanggal, asal_tipe, asal_id, tujuan_tipe, tujuan_id, status, user_id)
                VALUES ('UJI-PP-1', 'transfer_gudang', NOW(), 'rak', $rakA, 'rak', $rakB2, 'menunggu_persetujuan', $uid)");
    $ppId = (int) $pdo->lastInsertId();
    $pp = [
        'id' => $ppId, 'jenis' => 'transfer_gudang',
        'asal_tipe' => 'rak', 'asal_id' => $rakA,
        'tujuan_tipe' => 'rak', 'tujuan_id' => $rakB2,
    ];
    $detail = [[
        'id' => 1, 'barang_id' => $bid, 'nomor_batch' => null,
        'kondisi' => 'baru', 'sumber' => 'pembelian', 'donatur_id' => null,
        'qty' => 20, 'qty_diterima' => 15, 'tanggal_expired' => null,
        'harga' => 0, 'kardus_id' => null,
    ]];
    terapkan_perpindahan($pdo, $pp, $detail);
    $sisaA = stok_tersedia($pdo, $bid, $rakA);
    $sisaB2 = stok_tersedia($pdo, $bid, $rakB2);
    uji('qty_diterima (15) dipotong dari asal, BUKAN qty (20) — sisa rak A = 5', $sisaA === 5, "dapat $sisaA");
    uji('qty_diterima (15) yang ditambahkan ke tujuan, bukan 20', $sisaB2 === 15, "dapat $sisaB2");

    echo "6. terapkan_perpindahan() penerimaan: hanya menambah, tidak memotong apa pun\n";
    $totalSebelum = stok_tersedia($pdo, $bid);
    $pdo->exec("INSERT INTO perpindahan
                  (no_perpindahan, jenis, tanggal, asal_tipe, asal_id, tujuan_tipe, tujuan_id, status, user_id)
                VALUES ('UJI-PP-2', 'penerimaan', NOW(), 'supplier', NULL, 'rak', $rakA, 'menunggu_persetujuan', $uid)");
    $ppTerimaId = (int) $pdo->lastInsertId();
    $ppTerima = [
        'id' => $ppTerimaId, 'jenis' => 'penerimaan',
        'asal_tipe' => 'supplier', 'asal_id' => null,
        'tujuan_tipe' => 'rak', 'tujuan_id' => $rakA,
    ];
    $detailTerima = [[
        'id' => 2, 'barang_id' => $bid, 'nomor_batch' => 'B-TERIMA',
        'kondisi' => 'baru', 'sumber' => 'pembelian', 'donatur_id' => null,
        'qty' => 8, 'qty_diterima' => null, 'tanggal_expired' => null,
        'harga' => 0, 'kardus_id' => null,
    ]];
    terapkan_perpindahan($pdo, $ppTerima, $detailTerima);
    $totalSesudah = stok_tersedia($pdo, $bid);
    uji('total stok bertambah persis 8 (8, tanpa pengurangan)', $totalSesudah === $totalSebelum + 8,
        "sebelum=$totalSebelum sesudah=$totalSesudah");

    echo "7. periksa_stok_dokumen(): stok tidak cukup dilaporkan\n";
    $items = [['barang_id' => $bid, 'qty' => 99999]];
    $kurang = periksa_stok_dokumen($pdo, 'transfer_gudang', 'rak', $rakA, $items);
    uji('mengembalikan daftar tidak kosong', !empty($kurang));

    $itemsCukup = [['barang_id' => $bid, 'qty' => 1]];
    $kurangCukup = periksa_stok_dokumen($pdo, 'transfer_gudang', 'rak', $rakA, $itemsCukup);
    uji('stok cukup => daftar kosong', $kurangCukup === []);

    echo "8. periksa_stok_dokumen(): stok yang sudah dipesan dokumen 'dikirim' ikut dikurangkan\n";
    $sisaRakA = stok_tersedia($pdo, $bid, $rakA); // sisa di rak A sekarang
    $pdo->exec("INSERT INTO perpindahan
                  (no_perpindahan, jenis, tanggal, asal_tipe, asal_id, tujuan_tipe, tujuan_id, status, user_id)
                VALUES ('UJI-PP-DOK', 'transfer_gudang', NOW(), 'rak', $rakA, 'rak', $rakB2, 'dikirim', $uid)");
    $ppDok = (int) $pdo->lastInsertId();
    $pdo->exec("INSERT INTO perpindahan_detail (perpindahan_id, barang_id, kondisi, sumber, qty)
                VALUES ($ppDok, $bid, 'baru', 'pembelian', $sisaRakA)");

    $itemsSetelahPesan = [['barang_id' => $bid, 'qty' => 1]];
    $kurangSetelahPesan = periksa_stok_dokumen($pdo, 'transfer_gudang', 'rak', $rakA, $itemsSetelahPesan);
    uji('1 unit pun tidak bisa lagi karena semua sudah dipesan dokumen dikirim', !empty($kurangSetelahPesan));
} catch (Throwable $e) {
    $gagal++;
    echo "  GAGAL galat tak terduga: " . $e->getMessage() . "\n";
} finally {
    $pdo->rollBack();
}
echo "\n$lulus lulus, $gagal gagal\n";
exit($gagal > 0 ? 1 : 0);
