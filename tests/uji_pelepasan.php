<?php
/**
 * Uji pelepasan stok (perbaikan/dibuang/dijual) — dijalankan dari baris
 * perintah:
 *     php tests/uji_pelepasan.php
 *
 * Satu transaksi, selalu di-ROLLBACK — aman dijalankan kapan pun.
 *
 * Yang diuji adalah lepas_stok() dan selesaikan_perbaikan():
 *   1. jenis tidak dikenal ditolak
 *   2. alasan kosong ditolak ("barang ini titipan orang")
 *   3. qty melebihi sisa batch ditolak
 *   4. qty sah mengurangi qty_tersisa batch asal dan tercatat di pelepasan_stok
 *   5. status awal: 'perbaikan' => proses, lainnya => selesai
 *   6. selesaikan_perbaikan(hasil=layak) membuat BATCH BARU kondisi bekas_layak,
 *      nomor batch berakhiran -R, dan TIDAK menambah qty_tersisa batch asal
 *   7. selesaikan_perbaikan(hasil=dibuang/dijual) TIDAK membuat batch baru
 *   8. menutup catatan yang sudah selesai ditolak (tidak boleh ditutup dua kali)
 *   9. hasil tidak dikenal ditolak
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
    $pdo->exec("INSERT INTO lokasi_rak (kode, nama, gudang_id) VALUES ('UJI-PLPS', 'Rak Uji Pelepasan', $gid)");
    $rak = (int) $pdo->lastInsertId();
    $pdo->exec("INSERT INTO barang (kode, nama, satuan, kategori_id, stok_minimum, harga)
                VALUES ('UJI-PLPS-1', 'Barang Uji Pelepasan', 'pcs', (SELECT MIN(id) FROM kategori), 0, 0)");
    $bid = (int) $pdo->lastInsertId();
    $batchId = tambah_ke_lokasi($pdo, $bid, $rak, null, 'B1', null, 10, 0, null, 'bekas_tidak_layak', 'pembelian');

    echo "1. jenis tidak dikenal\n";
    $lempar = false;
    try { lepas_stok($pdo, $batchId, 1, 'dibakar', 'alasan apa saja'); } catch (RuntimeException $e) { $lempar = true; }
    uji('melempar RuntimeException', $lempar);

    echo "2. alasan kosong\n";
    $lempar = false;
    try { lepas_stok($pdo, $batchId, 1, 'dibuang', '   '); } catch (RuntimeException $e) { $lempar = true; }
    uji('melempar RuntimeException', $lempar);

    echo "3. qty melebihi sisa batch\n";
    $lempar = false;
    try { lepas_stok($pdo, $batchId, 999, 'dibuang', 'rusak total'); } catch (RuntimeException $e) { $lempar = true; }
    uji('melempar RuntimeException', $lempar);
    $sisa = (int) $pdo->query("SELECT qty_tersisa FROM batch_barang WHERE id = $batchId")->fetchColumn();
    uji('qty_tersisa tidak berubah oleh percobaan yang ditolak (masih 10)', $sisa === 10);

    echo "4-5. pelepasan 'dibuang' (status langsung selesai)\n";
    $plpsDibuang = lepas_stok($pdo, $batchId, 3, 'dibuang', 'rusak parah, tidak bisa diperbaiki');
    $sisa = (int) $pdo->query("SELECT qty_tersisa FROM batch_barang WHERE id = $batchId")->fetchColumn();
    uji('qty_tersisa berkurang jadi 7', $sisa === 7, (string) $sisa);
    $row = $pdo->query("SELECT qty, jenis, status FROM pelepasan_stok WHERE id = $plpsDibuang")->fetch();
    uji('qty tercatat benar (3)', (int) $row['qty'] === 3);
    uji("status langsung 'selesai' untuk jenis dibuang", $row['status'] === 'selesai', (string) $row['status']);

    echo "4-5b. pelepasan 'perbaikan' (status proses)\n";
    $plpsPerbaikan = lepas_stok($pdo, $batchId, 4, 'perbaikan', 'jahitan lepas, masih bisa dibetulkan');
    $sisa = (int) $pdo->query("SELECT qty_tersisa FROM batch_barang WHERE id = $batchId")->fetchColumn();
    uji('qty_tersisa berkurang jadi 3', $sisa === 3, (string) $sisa);
    $statusAwal = $pdo->query("SELECT status FROM pelepasan_stok WHERE id = $plpsPerbaikan")->fetchColumn();
    uji("status 'proses' untuk jenis perbaikan", $statusAwal === 'proses', (string) $statusAwal);

    echo "6. selesaikan_perbaikan(layak) membuat batch baru\n";
    $batchBaru = selesaikan_perbaikan($pdo, $plpsPerbaikan, 'layak');
    uji('mengembalikan id batch baru (bukan null, bukan batch lama)', $batchBaru !== null && $batchBaru !== $batchId);
    $bb = $pdo->query("SELECT nomor_batch, kondisi, qty_tersisa FROM batch_barang WHERE id = $batchBaru")->fetch();
    uji("nomor batch berakhiran '-R'", str_ends_with($bb['nomor_batch'], '-R'), (string) $bb['nomor_batch']);
    uji("kondisi batch baru 'bekas_layak'", $bb['kondisi'] === 'bekas_layak', (string) $bb['kondisi']);
    uji('qty batch baru = 4 (sesuai qty pelepasan)', (int) $bb['qty_tersisa'] === 4, (string) $bb['qty_tersisa']);
    $sisaAsal = (int) $pdo->query("SELECT qty_tersisa FROM batch_barang WHERE id = $batchId")->fetchColumn();
    uji('qty_tersisa batch ASAL tidak ikut bertambah (tetap 3)', $sisaAsal === 3, (string) $sisaAsal);
    $statusAkhir = $pdo->query("SELECT status, hasil, batch_kembali_id FROM pelepasan_stok WHERE id = $plpsPerbaikan")->fetch();
    uji("status jadi 'selesai'", $statusAkhir['status'] === 'selesai');
    uji("hasil tercatat 'layak'", $statusAkhir['hasil'] === 'layak');
    uji('batch_kembali_id menunjuk batch baru', (int) $statusAkhir['batch_kembali_id'] === $batchBaru);

    echo "7. selesaikan_perbaikan(dibuang) TIDAK membuat batch baru\n";
    $plpsPerbaikan2 = lepas_stok($pdo, $batchId, 2, 'perbaikan', 'coba diperbaiki dulu');
    $hasilDibuang = selesaikan_perbaikan($pdo, $plpsPerbaikan2, 'dibuang');
    uji('mengembalikan null', $hasilDibuang === null);

    echo "8. tidak boleh ditutup dua kali\n";
    $lempar = false;
    try { selesaikan_perbaikan($pdo, $plpsPerbaikan2, 'layak'); } catch (RuntimeException $e) { $lempar = true; }
    uji('melempar RuntimeException', $lempar);

    echo "9. hasil tidak dikenal\n";
    $plpsPerbaikan3 = lepas_stok($pdo, $batchId, 1, 'perbaikan', 'uji hasil tidak valid');
    $lempar = false;
    try { selesaikan_perbaikan($pdo, $plpsPerbaikan3, 'entah'); } catch (RuntimeException $e) { $lempar = true; }
    uji('melempar RuntimeException', $lempar);
} catch (Throwable $e) {
    $gagal++;
    echo "  GAGAL galat tak terduga: " . $e->getMessage() . "\n";
} finally {
    $pdo->rollBack();
}
echo "\n$lulus lulus, $gagal gagal\n";
exit($gagal > 0 ? 1 : 0);
