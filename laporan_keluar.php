<?php
/**
 * AMANAH — Rincian Barang Keluar (revisi 18 Okt 2026)
 *
 * Per bulan, per gudang:
 *   A. Pemakaian lewat Barang Keluar, menurut keperluan (Bayi, Karyawan,
 *      Dapur, Ruang Kesehatan, Finance, Lainnya).
 *   B. Pengiriman lewat surat jalan, menurut tujuan (gudang lain, tim
 *      penjualan / gudang toko, penerima, supplier) — terkirim & dalam perjalanan.
 *   C. Tren enam bulan terakhir.
 * Data & aturannya di _rincian_keluar.php.
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/_lib.php';
require_once __DIR__ . '/_dokumen_masuk.php';
require_login();
tolak_supplier();
if (!boleh_lihat_laporan($pdo)) {
    http_response_code(403);
    die('<p style="font-family:sans-serif;padding:30px">403 — Akun Anda tidak diberi izin membuka laporan. <a href="' . e(beranda_peran()) . '">Kembali</a></p>');
}

/* Gudang dalam lingkup akun: yang terikat gudang hanya melihat gudangnya. */
$opsiSemua = gudang_opsi_filter($pdo);
$lingkup = (terikat_satu_gudang() && !is_super()) ? array_map('intval', gudang_saya($pdo)) : array_map('intval', array_keys($opsiSemua));
$gudangOpsi = array_intersect_key($opsiSemua, array_flip(array_map('strval', $lingkup)));
$gudangId = (int) ($_GET['gudang'] ?? 0);
if ($gudangId && !isset($gudangOpsi[(string) $gudangId])) { $gudangId = 0; }
$gudangPakai = $gudangId ? [$gudangId] : $lingkup;
[$awal, $akhir, $bulan] = rentang_bulan((string) ($_GET['bulan'] ?? date('Y-m')));
$namaBulan = tgl_id($awal, 'panjang'); $namaBulan = preg_replace('/^\d+\s+/', '', $namaBulan);

$galat = ''; $kep = []; $tuj = []; $tren = [];
try {
    $kep  = rincian_keluar_keperluan($pdo, $awal, $akhir, $gudangPakai);
    $tuj  = rincian_keluar_tujuan($pdo, $awal, $akhir, $gudangPakai);
    $tren = rincian_keluar_tren($pdo, $bulan, 6, $gudangPakai);
} catch (Throwable $e) {
    error_log('[AMANAH] laporan_keluar: ' . $e->getMessage());
    $galat = 'Data tidak bisa dibaca: ' . pesan_error_aman($e, 'rincian barang keluar');
}
$totKep  = array_sum(array_column($kep, 'unit'));
$totKirim = array_sum(array_column($tuj, 'terkirim'));
$totJalan = array_sum(array_column($tuj, 'perjalanan'));
$rp = fn(float $n) => 'Rp' . number_format($n, 0, ',', '.');
$maksTren = max(1, max(array_values(array_map(fn($t) => $t["keperluan"] + $t["kirim"], $tren ?: [["keperluan" => 0, "kirim" => 0]]))));
$labelJenis = ['transfer_gudang' => 'Transfer', 'kirim_toko' => 'Kirim toko', 'penyaluran' => 'Penyaluran', 'retur' => 'Retur'];

$PAGE_TITLE = 'Rincian Barang Keluar'; $ACTIVE = 'laporan';
require __DIR__ . '/_header.php';
?>
<div class="page-head">
  <div><h1>Rincian Barang Keluar</h1>
    <p><?= e($namaBulan) ?> · <?= $gudangId ? e($gudangOpsi[(string) $gudangId]) : (count($gudangPakai) > 1 ? 'semua gudang dalam lingkup' : e(reset($gudangOpsi) ?: '')) ?> · pemakaian per keperluan &amp; pengiriman per tujuan</p></div>
</div>

<div class="card">
  <form method="get" class="tools">
    <input type="month" name="bulan" value="<?= e($bulan) ?>" aria-label="Bulan">
    <?php if (count($gudangOpsi) > 1): ?>
      <select name="gudang" aria-label="Gudang">
        <option value="0">Semua gudang</option>
        <?php foreach ($gudangOpsi as $gid => $gnama): ?><option value="<?= (int) $gid ?>" <?= $gudangId === (int) $gid ? 'selected' : '' ?>><?= e($gnama) ?></option><?php endforeach; ?>
      </select>
    <?php endif; ?>
    <button class="btn btn-primary" type="submit"><svg aria-hidden="true"><use href="#i-saring"></use></svg> Tampilkan</button>
    <button class="btn btn-ghost" type="button" onclick="window.print()"><svg aria-hidden="true"><use href="#i-cetak"></use></svg> Cetak</button>
  </form>
  <?php if ($galat): ?><div class="flash flash-err" data-tetap="1"><?= e($galat) ?></div><?php endif; ?>
  <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(170px,1fr));gap:12px;margin-top:14px">
    <div class="card" style="margin:0"><div class="mut-sm">Total keluar</div><div style="font-size:24px;font-weight:800"><?= angka($totKep + $totKirim + $totJalan) ?></div><small class="mut2">unit</small></div>
    <div class="card" style="margin:0"><div class="mut-sm">A. Pemakaian (Barang Keluar)</div><div style="font-size:24px;font-weight:800"><?= angka($totKep) ?></div><small class="mut2"><?= count($kep) ?> keperluan</small></div>
    <div class="card" style="margin:0"><div class="mut-sm">B. Terkirim (surat jalan)</div><div style="font-size:24px;font-weight:800"><?= angka($totKirim) ?></div><small class="mut2"><?= count($tuj) ?> tujuan</small></div>
    <div class="card" style="margin:0"><div class="mut-sm">B. Dalam perjalanan</div><div style="font-size:24px;font-weight:800;color:var(--amber)"><?= angka($totJalan) ?></div><small class="mut2">belum diterima tujuan</small></div>
  </div>
</div>

<div class="card">
  <h3>A. Pemakaian per keperluan</h3>
  <div class="sub">Dari menu Barang Keluar. Keperluan dipilih saat mencatat; data lama tanpa pilihan masuk "Belum dikategorikan".</div>
  <?php if (!$kep): ?><div class="kosong-blok"><p>Tidak ada Barang Keluar pada bulan ini.</p></div>
  <?php else: ?>
  <div class="tbl-wrap"><table class="tbl-kartu">
    <thead><tr><th>Keperluan</th><th class="num">Unit</th><th class="num">Jenis barang</th><th class="num">Dokumen</th><th class="num">Nilai</th><th>Barang terbanyak</th></tr></thead>
    <tbody>
    <?php foreach ($kep as $k => $r): ?>
      <tr>
        <td data-label="Keperluan"><b><?= e(label_keperluan($k)) ?></b></td>
        <td class="num" data-label="Unit"><b><?= angka($r['unit']) ?></b></td>
        <td class="num" data-label="Jenis"><?= angka($r['jenis']) ?></td>
        <td class="num" data-label="Dokumen"><?= angka($r['dok']) ?></td>
        <td class="num" data-label="Nilai"><?= e($rp($r['nilai'])) ?></td>
        <td data-label="Barang"><details><summary class="mut2" style="cursor:pointer"><?= e(implode(', ', array_map(fn($b) => $b['nama'] . ' ' . angka($b['qty']), array_slice($r['barang'], 0, 3)))) ?><?= count($r['barang']) > 3 ? ' …' : '' ?></summary>
          <ul style="margin:6px 0 0 16px;font-size:12.5px"><?php foreach ($r['barang'] as $b): ?><li><?= e($b['nama']) ?> <small class="mut2"><?= e($b['kode']) ?></small>: <b><?= angka($b['qty']) ?></b> <?= e($b['satuan']) ?></li><?php endforeach; ?></ul></details></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
    <tfoot><tr><td><b>Total</b></td><td class="num"><b><?= angka($totKep) ?></b></td><td></td><td></td><td class="num"><b><?= e($rp(array_sum(array_column($kep, 'nilai')))) ?></b></td><td></td></tr></tfoot>
  </table></div>
  <?php endif; ?>
</div>

<div class="card">
  <h3>B. Pengiriman per tujuan</h3>
  <div class="sub">Surat jalan dari gudang ini (transfer gudang, kirim ke toko / tim penjualan, penyaluran, retur), menurut tanggal dokumen. Terkirim = sudah diterima tujuan (jumlah hitung fisik); dalam perjalanan = disetujui, belum diterima.</div>
  <?php if (!$tuj): ?><div class="kosong-blok"><p>Tidak ada pengiriman pada bulan ini.</p></div>
  <?php else: ?>
  <div class="tbl-wrap"><table class="tbl-kartu">
    <thead><tr><th>Tujuan</th><th>Jenis</th><th class="num">Terkirim</th><th class="num">Dalam perjalanan</th><th class="num">Surat jalan</th><th class="num">Nilai</th><th>Barang terbanyak</th></tr></thead>
    <tbody>
    <?php foreach ($tuj as $r): ?>
      <tr>
        <td data-label="Tujuan"><b><?= e($r['nama']) ?></b></td>
        <td data-label="Jenis"><small><?= e(implode(', ', array_map(fn($j) => $labelJenis[$j] ?? $j, array_keys($r['jenis'])))) ?></small></td>
        <td class="num" data-label="Terkirim"><b><?= angka($r['terkirim']) ?></b></td>
        <td class="num" data-label="Dalam perjalanan" <?= $r['perjalanan'] ? 'style="color:var(--amber)"' : '' ?>><?= angka($r['perjalanan']) ?></td>
        <td class="num" data-label="Surat jalan"><?= angka($r['dok']) ?></td>
        <td class="num" data-label="Nilai"><?= e($rp($r['nilai'])) ?></td>
        <td data-label="Barang"><details><summary class="mut2" style="cursor:pointer"><?= e(implode(', ', array_map(fn($b) => $b['nama'] . ' ' . angka($b['qty']), array_slice($r['barang'], 0, 3)))) ?><?= count($r['barang']) > 3 ? ' …' : '' ?></summary>
          <ul style="margin:6px 0 0 16px;font-size:12.5px"><?php foreach ($r['barang'] as $b): ?><li><?= e($b['nama']) ?> <small class="mut2"><?= e($b['kode']) ?></small>: <b><?= angka($b['qty']) ?></b> <?= e($b['satuan']) ?></li><?php endforeach; ?></ul></details></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
    <tfoot><tr><td colspan="2"><b>Total</b></td><td class="num"><b><?= angka($totKirim) ?></b></td><td class="num"><b><?= angka($totJalan) ?></b></td><td></td><td class="num"><b><?= e($rp(array_sum(array_column($tuj, 'nilai')))) ?></b></td><td></td></tr></tfoot>
  </table></div>
  <?php endif; ?>
</div>

<div class="card">
  <h3>C. Enam bulan terakhir</h3>
  <div class="sub">Unit keluar per bulan · biru = pemakaian (A), hijau = pengiriman (B)</div>
  <div class="tbl-wrap"><table class="tbl-kartu">
    <thead><tr><th>Bulan</th><th class="num">Pemakaian</th><th class="num">Pengiriman</th><th class="num">Total</th><th style="width:40%"></th></tr></thead>
    <tbody>
    <?php foreach ($tren as $b => $t): $tot = $t['keperluan'] + $t['kirim']; ?>
      <tr <?= $b === $bulan ? 'style="background:var(--primary-lembut)"' : '' ?>>
        <td data-label="Bulan"><a href="?bulan=<?= e($b) ?><?= $gudangId ? '&gudang=' . $gudangId : '' ?>"><?= e(preg_replace('/^\d+\s+/', '', tgl_id($b . '-01', 'panjang'))) ?></a></td>
        <td class="num" data-label="Pemakaian"><?= angka($t['keperluan']) ?></td>
        <td class="num" data-label="Pengiriman"><?= angka($t['kirim']) ?></td>
        <td class="num" data-label="Total"><b><?= angka($tot) ?></b></td>
        <td><div style="display:flex;height:10px;border-radius:5px;overflow:hidden;background:var(--surface-2)">
          <span style="width:<?= round($t['keperluan'] / $maksTren * 100, 1) ?>%;background:var(--primary)"></span>
          <span style="width:<?= round($t['kirim'] / $maksTren * 100, 1) ?>%;background:var(--green)"></span></div></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
</div>
<?php require __DIR__ . '/_footer.php'; ?>
