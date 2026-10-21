<?php
/**
 * AMANAH — Riwayat Barang Masuk (semua dokumen)
 *
 * Halaman Barang Masuk hanya menampilkan enam dokumen terakhir — cukup
 * untuk memastikan yang baru disimpan memang masuk, tidak cukup untuk
 * mencari penerimaan minggu lalu. Di sini seluruh dokumennya: bisa dicari
 * (nomor dokumen, nama/kode barang, nomor batch, catatan), disaring per
 * supplier dan periode, dan dibuka rinciannya persis seperti di halaman
 * Barang Masuk — tabelnya memang tabel yang sama (_dokumen_masuk.php).
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/_lib.php';
require_once __DIR__ . '/_dokumen_masuk.php';
require_login();
tolak_supplier();

// Wewenangnya sama dengan halaman Barang Masuk: tautan ke sini hanya ada di sana.
if (!boleh_edit()) {
    http_response_code(403);
    die('<p style="font-family:sans-serif;padding:30px">403 — Riwayat barang masuk hanya untuk '
      . 'Super Admin, Admin Gudang, dan Admin Gudang &amp; Donasi. '
      . '<a href="dashboard.php">Kembali</a></p>');
}

$PER_HAL = 25;

$supplierOpsi = [];
try {
    foreach ($pdo->query("SELECT id, nama FROM supplier WHERE deleted_at IS NULL ORDER BY nama")->fetchAll() as $s) {
        $supplierOpsi[(string) $s['id']] = $s['nama'];
    }
} catch (Throwable $e) { error_log('[AMANAH] riwayat masuk supplier: ' . $e->getMessage()); }

$def = [
    ['nama' => 'q',        'jenis' => 'cari',    'label' => 'Kata kunci',
     'placeholder' => 'Cari nomor dokumen, barang, batch, atau catatan…'],
    ['nama' => 'jenis',    'jenis' => 'pilih',   'label' => 'Asal / jenis donasi', 'kosong' => 'Semua asal',
     'opsi' => ['kunjungan' => 'Donasi Kunjungan', 'paket' => 'Donasi Paket',
                'belum' => 'Donasi — belum diklasifikasi', 'pembelian' => 'Pembelian']],
    ['nama' => 'supplier', 'jenis' => 'pilih',   'label' => 'Supplier', 'opsi' => $supplierOpsi, 'kosong' => 'Semua supplier'],
    /* Gudang dibaca dari rak tiap baris — lihat sql_rak_gudang(). Daftarnya
       dari master gudang, jadi gudang baru (mis. Widuri) langsung muncul. */
    ['nama' => 'gudang',   'jenis' => 'pilih',   'label' => 'Gudang', 'opsi' => gudang_opsi_filter($pdo), 'kosong' => 'Semua gudang'],
    ['nama' => 'periode',  'jenis' => 'periode', 'label' => 'Periode'],
];
$nilai = filter_baca($def);

$where = ['1=1']; $par = [];
if ($nilai['q'] !== '') {
    $like = '%' . like_escape($nilai['q']) . '%';
    $where[] = "(tm.no_transaksi LIKE ? OR tm.catatan LIKE ? OR EXISTS (
                   SELECT 1 FROM transaksi_masuk_detail dq JOIN barang bq ON bq.id = dq.barang_id
                   WHERE dq.transaksi_masuk_id = tm.id
                     AND (bq.nama LIKE ? OR bq.kode LIKE ? OR dq.nomor_batch LIKE ?)))";
    array_push($par, $like, $like, $like, $like, $like);
}
if ($nilai['supplier'] !== '') { $where[] = 'tm.supplier_id = ?'; $par[] = (int) $nilai['supplier']; }
if ($nilai['jenis'] !== '') {
    /* Asal dibaca dari batch barisnya (lihat dokumen_masuk_sql_jenis); jenis
       dari catatan donasi yang tersambung. Sebelum migrasi, saringan jenis
       kunjungan/paket menghasilkan kosong — bukan galat. */
    $adaDonasi = "EXISTS (SELECT 1 FROM transaksi_masuk_detail dj2 JOIN batch_barang bj2 ON bj2.id = dj2.batch_id
                           WHERE dj2.transaksi_masuk_id = tm.id AND bj2.sumber = 'donasi')";
    $adaKolom  = kolom_ada($pdo, 'transaksi_masuk', 'donasi_id');
    $jenisSql  = $adaKolom ? "(SELECT dj3.jenis FROM donasi dj3 WHERE dj3.id = tm.donasi_id)" : 'NULL';
    if ($nilai['jenis'] === 'pembelian')  { $where[] = "NOT $adaDonasi"; }
    elseif ($nilai['jenis'] === 'belum') { $where[] = "$adaDonasi AND COALESCE($jenisSql, 'umum') NOT IN ('kunjungan','paket')"; }
    else { $where[] = "$adaDonasi AND $jenisSql = ?"; $par[] = $nilai['jenis']; }
}
if ($nilai['periode'] !== '' && ($r = periode_rentang($nilai['periode']))) {
    $where[] = 'tm.tanggal >= ? AND tm.tanggal < DATE_ADD(?, INTERVAL 1 DAY)';
    $par[] = $r[0]; $par[] = $r[1];
}
$wSql = implode(' AND ', $where);

$total = 0; $dok = []; $rinci = []; $hal = 1; $jmlHal = 1;
try {
    $gudangId = (int) $nilai['gudang'];
    $total = dokumen_masuk_hitung($pdo, $wSql, $par, $gudangId);
    [$hal, $offset, $jmlHal] = paginasi_hitung($total, $PER_HAL);
    [$dok, $rinci] = dokumen_masuk_ambil($pdo, $wSql, $par, $PER_HAL, $offset, $gudangId);
} catch (Throwable $e) {
    error_log('[AMANAH] riwayat masuk: ' . $e->getMessage());
}

$PAGE_TITLE = 'Riwayat Barang Masuk'; $ACTIVE = 'masuk';
require __DIR__ . '/_header.php';
?>
<div class="jejak">
  <a href="masuk.php">Barang Masuk</a> <span>›</span> <b>Semua dokumen</b>
</div>

<div class="page-head">
  <div><h1>Riwayat Barang Masuk</h1>
    <p><?= angka($total) ?> dokumen<?= filter_ada($nilai) ? ' cocok dengan penyaring' : '' ?> · terbaru di atas<?= $nilai['gudang'] !== '' ? ' · unit &amp; nilai hanya baris ke ' . e(gudang_opsi_filter($pdo)[$nilai['gudang']] ?? 'gudang itu') : '' ?></p></div>
  <a class="btn btn-ghost" href="masuk.php">← Barang Masuk</a>
</div>

<div class="card">
  <?php filter_bar($def, $nilai, [], filter_ada($nilai) ? $total : null); ?>
  <?php dokumen_masuk_tabel($dok, $rinci, $pdo); ?>
  <?php paginasi_nav($hal, $jmlHal, $total, $PER_HAL, $nilai); ?>
</div>
<?php require __DIR__ . '/_footer.php'; ?>
