<?php
/**
 * AMANAH — Laporan Harian Donasi (revisi tim gudang, Oktober 2026, butir 2)
 *
 * Satu tanggal, dua jenis: berapa barang donasi KUNJUNGAN dan donasi PAKET
 * yang diterima hari itu, lalu akumulasinya sejak tanggal 1 bulan berjalan
 * sampai tanggal laporan — masing-masing dalam ITEM (total unit, kolom
 * Jumlah di penerimaan) dan NOMINAL (jumlah × harga di baris penerimaan).
 *
 * Sumber angkanya baris penerimaan Barang Masuk (transaksi_masuk_detail)
 * yang batchnya berasal dari donasi, dikelompokkan menurut Jenis Donasi
 * pada catatan donasi yang tersambung ke penerimaan itu (butir 1).
 * Penerimaan donasi yang belum punya jenis (data lama, atau dari aplikasi
 * Android versi lama) dilaporkan terpisah sebagai "Belum diklasifikasi" —
 * tampil di halaman, TIDAK ikut ke teks WhatsApp.
 *
 * ASUMSI (ditandai di rencana, menunggu konfirmasi tim): SEMUA barang donasi
 * yang diterima ikut dihitung, termasuk yang kondisinya Rusak/Bocor, Expired,
 * atau tidak layak salur.
 *
 * Penyaring gudang bekerja per baris lewat rak (lihat sql_rak_gudang di
 * _dokumen_masuk.php), sama dengan Riwayat Barang Masuk.
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/_lib.php';
require_once __DIR__ . '/_dokumen_masuk.php';
require_login();
tolak_supplier();
if (!boleh_lihat_laporan($pdo)) {
    http_response_code(403);
    die('<p style="font-family:sans-serif;padding:30px">403 — Akun Anda tidak diberi izin '
      . 'membuka laporan. <a href="' . e(beranda_peran()) . '">Kembali</a></p>');
}

/* ------------------------------------------------------------- penyaring */
$tgl = trim((string) ($_GET['tanggal'] ?? ''));
$d   = $tgl !== '' ? DateTime::createFromFormat('!Y-m-d', $tgl) : false;
if (!$d || $d->format('Y-m-d') !== $tgl) { $tgl = date('Y-m-d'); }
$awalBulan = substr($tgl, 0, 8) . '01';

$gudangOpsi = gudang_opsi_filter($pdo);
/* Akun yang terikat gudang hanya melihat gudangnya sendiri. */
if (terikat_satu_gudang()) {
    $gudangOpsi = array_intersect_key($gudangOpsi, array_flip(array_map('strval', gudang_saya($pdo))));
}
$gudangId = (int) ($_GET['gudang'] ?? 0);
if ($gudangId && !isset($gudangOpsi[(string) $gudangId])) { $gudangId = 0; }

/* ------------------------------------------------------------------ data */
$adaKolom = kolom_ada($pdo, 'transaksi_masuk', 'donasi_id');
$kelasJenis = $adaKolom
    ? "CASE WHEN dj.jenis IN ('kunjungan','paket') THEN dj.jenis ELSE 'belum' END"
    : "'belum'";
$joinDonasi = $adaKolom ? 'LEFT JOIN donasi dj ON dj.id = tm.donasi_id' : '';

$where = ["bb.sumber = 'donasi'", 'DATE(tm.tanggal) BETWEEN ? AND ?'];
$par   = [$awalBulan, $tgl];
if ($gudangId > 0) { $where[] = sql_rak_gudang('d', $gudangId); }
elseif (terikat_satu_gudang()) { $where[] = 'd.lokasi_rak_id IN (' . in_rak_saya($pdo) . ')'; }

$angka = ['kunjungan' => ['hari' => [0, 0], 'bulan' => [0, 0]],
          'paket'     => ['hari' => [0, 0], 'bulan' => [0, 0]],
          'belum'     => ['hari' => [0, 0], 'bulan' => [0, 0]]];
$galatData = '';
try {
    $st = $pdo->prepare(
        "SELECT $kelasJenis AS jenis,
                SUM(CASE WHEN DATE(tm.tanggal) = ? THEN d.qty ELSE 0 END)            AS item_hari,
                SUM(CASE WHEN DATE(tm.tanggal) = ? THEN d.qty * d.harga ELSE 0 END)  AS nominal_hari,
                SUM(d.qty)           AS item_bulan,
                SUM(d.qty * d.harga) AS nominal_bulan
         FROM transaksi_masuk_detail d
         JOIN transaksi_masuk tm ON tm.id = d.transaksi_masuk_id
         JOIN batch_barang bb    ON bb.id = d.batch_id
         $joinDonasi
         WHERE " . implode(' AND ', $where) . "
         GROUP BY 1");
    $st->execute(array_merge([$tgl, $tgl], $par));
    foreach ($st->fetchAll() as $r) {
        $angka[$r['jenis']] = ['hari'  => [(int) $r['item_hari'],  (float) $r['nominal_hari']],
                               'bulan' => [(int) $r['item_bulan'], (float) $r['nominal_bulan']]];
    }
} catch (Throwable $e) {
    error_log('[AMANAH] laporan harian donasi: ' . $e->getMessage());
    $galatData = 'Data tidak bisa dibaca. Bila migrasi basis data belum dijalankan, jalankan dulu lewat Pengaturan › Migrasi.';
}

/* ------------------------------------------------------- teks WhatsApp
   Formatnya PERSIS seperti contoh pemilik sistem, termasuk spasi-spasi
   ganjilnya (spasi di depan baris tanggal, empat spasi sesudah "harian*",
   spasi di ujung dua baris). Jangan dirapikan — teks ini dibandingkan
   mata dengan laporan yang sudah biasa dikirim. */
[$hariTgl, $bulanNama, $tahun] = explode(' ', tgl_id($tgl, 'panjang'));
$rentang = '01 - ' . substr($tgl, 8, 2) . ' ' . $bulanNama;
$rp = fn(float $n) => number_format($n, 0, ',', '.');
$teksWa = "*Laporan Data Gudang, Donasi Barang Kunjungan Harian, Donasi Paket Harian*\n"
        . " *{$hariTgl} {$bulanNama} {$tahun}*\n"
        . "📌 *Donasi barang kunjungan harian* " . angka($angka['kunjungan']['hari'][0]) . " buah barang\n"
        . "📌 *Donasi paket harian*    " . angka($angka['paket']['hari'][0]) . " buah barang \n"
        . "📌 *Total donasi barang kunjungan {$rentang}* " . angka($angka['kunjungan']['bulan'][0]) . " barang (Rp. " . $rp($angka['kunjungan']['bulan'][1]) . ") \n"
        . "📌 *Total donasi Paket  {$rentang}* " . angka($angka['paket']['bulan'][0]) . " barang (Rp. " . $rp($angka['paket']['bulan'][1]) . ")";

$PAGE_TITLE = 'Laporan Harian Donasi'; $ACTIVE = 'laporan';
require __DIR__ . '/_header.php';
?>
<div class="page-head">
  <div><h1>Laporan Harian Donasi</h1>
    <p>Donasi kunjungan dan donasi paket per tanggal · akumulasi sejak tanggal 1 bulan berjalan</p></div>
  <a class="btn btn-ghost" href="laporan_donasi.php">Laporan Donasi</a>
</div>

<div class="card">
  <form method="get" class="tools">
    <input type="date" name="tanggal" value="<?= e($tgl) ?>" aria-label="Tanggal laporan">
    <select name="gudang" aria-label="Gudang">
      <option value="0">Semua gudang</option>
      <?php foreach ($gudangOpsi as $gid => $gnama): ?>
        <option value="<?= (int) $gid ?>" <?= $gudangId === (int) $gid ? 'selected' : '' ?>><?= e($gnama) ?></option>
      <?php endforeach; ?>
    </select>
    <button class="btn btn-primary" type="submit"><svg aria-hidden="true"><use href="#i-saring"></use></svg> Tampilkan</button>
  </form>
  <?php if ($galatData): ?><div class="flash flash-err" data-tetap="1"><?= e($galatData) ?></div><?php endif; ?>
</div>

<?php
$kartu = [
    ['Donasi kunjungan — ' . tgl_id($tgl), $angka['kunjungan']['hari'], 't-green'],
    ['Donasi paket — ' . tgl_id($tgl),     $angka['paket']['hari'],     't-blue'],
    ['Total kunjungan ' . $rentang,         $angka['kunjungan']['bulan'], 't-green'],
    ['Total paket ' . $rentang,             $angka['paket']['bulan'],     't-blue'],
];
?>
<div class="kpi">
  <?php foreach ($kartu as [$judul, [$item, $nominal], $cls]): ?>
    <div class="card">
      <div class="mut-sm"><?= e($judul) ?></div>
      <div style="font-size:24px;font-weight:800;margin-top:4px"><?= angka($item) ?> <small class="mut" style="font-size:12px;font-weight:500">barang</small></div>
      <div class="mut-sm"><?= rupiah($nominal) ?></div>
    </div>
  <?php endforeach; ?>
</div>

<?php if ($angka['belum']['bulan'][0] > 0): ?>
  <div class="card" style="border-left:4px solid var(--amber)">
    <b>Belum diklasifikasi:</b> <?= angka($angka['belum']['hari'][0]) ?> barang pada <?= e(tgl_id($tgl)) ?>,
    <?= angka($angka['belum']['bulan'][0]) ?> barang (<?= rupiah($angka['belum']['bulan'][1]) ?>) sejak tanggal 1.
    <span class="mut">Penerimaan donasi yang belum punya jenis kunjungan/paket — tidak ikut ke teks WhatsApp.
      Cari di <a href="masuk_riwayat.php?jenis=belum">Riwayat Barang Masuk</a>.</span>
  </div>
<?php endif; ?>

<div class="card">
  <h3>Teks untuk WhatsApp</h3>
  <div class="sub">Format mengikuti laporan yang biasa dikirim. Tekan Salin, lalu tempel di grup.</div>
  <textarea id="teksWa" readonly rows="7" style="width:100%;font-family:inherit;white-space:pre"><?= e($teksWa) ?></textarea>
  <div style="display:flex;gap:9px;align-items:center;margin-top:10px;flex-wrap:wrap">
    <button type="button" class="btn btn-primary" id="btnSalinWa">📋 Salin untuk WhatsApp</button>
    <small class="mut" id="ketSalin"></small>
  </div>
  <p class="mut" style="font-size:12px;margin:12px 0 0">
    Item = total unit (kolom Jumlah). Nominal = jumlah × harga pada baris penerimaan.
    Semua barang donasi yang diterima ikut dihitung, termasuk yang berkondisi Rusak/Bocor atau Expired.
  </p>
</div>

<script>
document.getElementById('btnSalinWa').addEventListener('click', function(){
  var ta = document.getElementById('teksWa'), ket = document.getElementById('ketSalin');
  var selesai = function(ok){ ket.textContent = ok ? 'Tersalin — tinggal tempel di WhatsApp.' : 'Tidak bisa menyalin otomatis; pilih teksnya lalu salin manual.'; };
  if (navigator.clipboard && window.isSecureContext) {
    navigator.clipboard.writeText(ta.value).then(function(){ selesai(true); }, function(){ selesai(false); });
  } else {
    /* Cadangan untuk halaman yang dibuka lewat alamat IP (bukan HTTPS). */
    ta.focus(); ta.select();
    try { selesai(document.execCommand('copy')); } catch (e) { selesai(false); }
  }
});
</script>
<?php require __DIR__ . '/_footer.php'; ?>
