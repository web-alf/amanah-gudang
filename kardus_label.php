<?php
/**
 * AMANAH — Cetak Label Kardus
 *
 * Halaman berdiri sendiri (tanpa sidebar). Pilihan:
 *   ?id=5                  satu kardus
 *   ?id[]=5&id[]=6         beberapa kardus (dari Folder Kardus)
 *   ?pp=12                 seluruh kardus satu perpindahan, urut
 *   &ukuran=100x150|a6|a4  (bawaan 100×150 mm; A4 memuat 4 label)
 * Kode kardus tetap — mencetak ulang tidak membuat kode baru. QR berisi
 * alamat detail kardus + kodenya (stabil); bila QR rusak, kodenya tercetak
 * besar dan bisa diketik di Folder Kardus / Cek Surat Jalan.
 * Pencetakan tidak mengubah stok; hanya dicatat di riwayat kardus.
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/_lib.php';
require_once __DIR__ . '/_qr.php';
require_login();
tolak_supplier();

if (!kardus_aktif($pdo)) { die('<p style="font-family:sans-serif;padding:30px">Fitur kardus belum aktif (migrasi belum dijalankan).</p>'); }

$ukuran = (string) ($_GET['ukuran'] ?? '100x150');
if (!in_array($ukuran, ['100x150', 'a6', 'a4'], true)) { $ukuran = '100x150'; }
$ppMinta = (int) ($_GET['pp'] ?? 0);
if ($ppMinta > 0) {
    $ids = kardus_di_dokumen($pdo, $ppMinta);
} else {
    $idRaw = $_GET['id'] ?? [];
    $ids = array_values(array_unique(array_filter(array_map('intval', is_array($idRaw) ? $idRaw : [$idRaw]))));
}
$ids = array_slice($ids, 0, 60);

$daftar = []; $perDok = [];
foreach ($ids as $id) {
    $k = kardus_ambil($pdo, $id);
    if (!$k || !kardus_boleh_lihat($pdo, (int) $k['gudang_id'], $k['perpindahan_id'] ? (int) $k['perpindahan_id'] : null)) { continue; }
    $ppId = (int) $k['perpindahan_id'];
    if ($ppId && !isset($perDok[$ppId])) { $perDok[$ppId] = kardus_dokumen_lengkap($pdo, $ppId); }
    $k['isi']   = $ppId && isset($perDok[$ppId][$id]) ? $perDok[$ppId][$id]['isi'] : kardus_isi($pdo, $id);
    $k['urut']  = $ppId && isset($perDok[$ppId][$id]) ? $perDok[$ppId][$id]['urut'] : null;
    $k['jumlah_kardus'] = $ppId ? count($perDok[$ppId]) : 0;
    $k['asal']   = $ppId ? nama_lokasi($pdo, $k['asal_tipe'], $k['asal_id'] ? (int) $k['asal_id'] : null, $k['asal_teks']) : ($k['gudang_nama'] ?: '—');
    $k['tujuan'] = $ppId ? nama_lokasi($pdo, $k['tujuan_tipe'], $k['tujuan_id'] ? (int) $k['tujuan_id'] : null, $k['tujuan_teks']) : '';
    $url = url_penuh('kardus_detail.php') . '?k=' . rawurlencode($k['kode']);
    $k['qr'] = qr_svg($url, 0, '#111') ?? qr_svg($k['kode'], 0, '#111');
    $daftar[] = $k;
    try { kardus_catat($pdo, $id, 'cetak_label', 'Label dicetak (' . $ukuran . ')'); } catch (Throwable $e) { error_log('[AMANAH] catat label: ' . $e->getMessage()); }
}
if (!$daftar) {
    http_response_code(404);
    die('<p style="font-family:sans-serif;padding:30px">Kardus tidak ditemukan atau bukan untuk lokasi Anda. <a href="kardus.php">Kembali</a></p>');
}
$logo = url_unggahan(pengaturan($pdo, 'logo_utama'));
$lembaga = defined('APP_LEMBAGA') ? APP_LEMBAGA : '';
$pageSize = ['100x150' => '100mm 150mm', 'a6' => 'A6', 'a4' => 'A4'][$ukuran];
$kembali = $ppMinta ? 'persetujuan.php?id=' . $ppMinta : (count($daftar) === 1 ? 'kardus_detail.php?id=' . (int) $daftar[0]['id'] : 'kardus.php');
$ukuranLain = ['100x150' => 'Label 100×150', 'a6' => 'A6', 'a4' => 'A4 (4 label)'];
$qsId = $ppMinta ? 'pp=' . $ppMinta : http_build_query(['id' => $ids]);
?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Label Kardus <?= e(count($daftar) === 1 ? $daftar[0]['kode'] : count($daftar) . ' kardus') ?> · <?= APP_NAMA ?></title>
<style>
  @page { size: <?= $pageSize ?>; margin: <?= $ukuran === 'a4' ? '8mm' : '4mm' ?>; }
  *{box-sizing:border-box;margin:0;padding:0}
  body{font-family:"Segoe UI",Arial,Helvetica,sans-serif;color:#111;background:#e9edf2;font-size:11px;line-height:1.35}
  .toolbar{max-width:220mm;margin:16px auto 0;display:flex;gap:8px;flex-wrap:wrap;align-items:center;padding:0 4px}
  .toolbar .btn{cursor:pointer;border:none;border-radius:9px;padding:9px 14px;font-weight:600;font-size:13px;text-decoration:none;display:inline-flex;align-items:center;gap:7px;font-family:inherit;line-height:1.2}
  .toolbar .btn svg{width:16px;height:16px;fill:none;stroke:currentColor;stroke-width:2;stroke-linecap:round;stroke-linejoin:round}
  .btn-p{background:#2563EB;color:#fff}.btn-g{background:#fff;border:1px solid #cbd5e1;color:#334155}.btn-g.aktif{background:#dbeafe;border-color:#2563EB}
  .toolbar small{color:#64748b;margin-left:auto}
  .wadah{max-width:220mm;margin:14px auto}
  .label{background:#fff;border:1px solid #cbd5e1;padding:5mm;display:flex;flex-direction:column;gap:2.5mm;page-break-inside:avoid;break-inside:avoid;position:relative;overflow:hidden}
  .l-100x150 .label{width:100mm;min-height:150mm;margin:0 auto 10px}
  .l-a6 .label{width:105mm;min-height:148mm;margin:0 auto 10px}
  .l-a4 .wadah{display:grid;grid-template-columns:1fr 1fr;gap:6mm;max-width:200mm}
  .l-a4 .label{width:auto;min-height:135mm}
  .kop{display:flex;align-items:center;gap:3mm;border-bottom:2px solid #111;padding-bottom:2mm}
  .kop img{height:10mm;width:auto;max-width:28mm;object-fit:contain}
  .kop .logo-teks{width:10mm;height:10mm;border-radius:2.5mm;background:#111;color:#fff;display:flex;align-items:center;justify-content:center;font-size:6.5mm;font-weight:800}
  .kop .lembaga{font-weight:800;font-size:12px}
  .kop .sub{font-size:9px;color:#475569}
  .kop .urut{margin-left:auto;text-align:right;font-size:10px;font-weight:700;border:1.5px solid #111;border-radius:2mm;padding:1mm 2.5mm;white-space:nowrap}
  .kode{font-family:"Courier New",monospace;font-size:21px;font-weight:800;letter-spacing:1px;text-align:center;line-height:1.1}
  .nama{text-align:center;font-size:12px;font-weight:700;color:#1e293b}
  .qr{display:flex;gap:4mm;align-items:center}
  .qr .gbr{width:36mm;height:36mm;flex:0 0 auto}.qr .gbr svg{width:100%;height:100%;display:block}
  .qr .ket{font-size:9.5px;color:#334155;line-height:1.45}
  .qr .ket b{color:#111;font-size:10.5px}
  table.meta{width:100%;border-collapse:collapse}
  table.meta td{padding:1.1mm 0;border-bottom:1px dashed #cbd5e1;vertical-align:top;font-size:10.5px}
  table.meta td:first-child{width:26mm;color:#475569}
  table.meta td b{font-size:11px}
  .isi{flex:1}
  .isi h4{font-size:9.5px;text-transform:uppercase;letter-spacing:1px;color:#334155;margin-bottom:1mm;border-bottom:1px solid #111;padding-bottom:.5mm}
  table.isi-t{width:100%;border-collapse:collapse}
  table.isi-t td{padding:.8mm 0;border-bottom:1px solid #e2e8f0;font-size:10.5px}
  table.isi-t td.n{text-align:right;white-space:nowrap;font-weight:700}
  .status{display:inline-block;border:1px solid #475569;border-radius:3mm;padding:.5mm 2.5mm;font-size:9.5px;font-weight:700;text-transform:uppercase;letter-spacing:.5px}
  .kaki{display:flex;justify-content:space-between;gap:4mm;font-size:8.5px;color:#64748b;border-top:1px solid #cbd5e1;padding-top:1.5mm}
  .air{position:absolute;inset:0;display:flex;align-items:center;justify-content:center;font-size:22mm;font-weight:900;color:rgba(239,68,68,.13);transform:rotate(-25deg);pointer-events:none;letter-spacing:3px;text-align:center}
  @media print{
    body{background:#fff}.toolbar{display:none}.wadah{margin:0;max-width:none}
    .label{border:none;margin:0;box-shadow:none}
    .l-100x150 .label,.l-a6 .label{width:auto;min-height:auto;height:100%;page-break-after:always}
    .l-a4 .wadah{gap:6mm}.l-a4 .label{border:1px dashed #cbd5e1}
  }
</style>
</head>
<body class="l-<?= e($ukuran) ?>">
<?php require __DIR__ . '/_ikon.php'; ?>
<div class="toolbar">
  <button class="btn btn-p" onclick="window.print()"><svg aria-hidden="true"><use href="#i-cetak"></use></svg> Cetak<?= count($daftar) > 1 ? ' semua (' . count($daftar) . ')' : '' ?></button>
  <a class="btn btn-g" href="<?= e($kembali) ?>">← Kembali</a>
  <?php foreach ($ukuranLain as $kd => $lb): ?>
    <a class="btn btn-g <?= $kd === $ukuran ? 'aktif' : '' ?>" href="kardus_label.php?<?= e($qsId) ?>&amp;ukuran=<?= e($kd) ?>"><?= e($lb) ?></a>
  <?php endforeach; ?>
  <small><?= count($daftar) ?> label · simpan sebagai PDF lewat dialog cetak (tujuan: "Save as PDF")</small>
</div>

<div class="wadah">
<?php foreach ($daftar as $k): [$sLbl] = label_status_kardus($k['status']); $ring = kardus_ringkas($k['isi']);
      $maksBaris = $ukuran === 'a4' ? 7 : 11; $isiTampil = array_slice($k['isi'], 0, $maksBaris); ?>
  <div class="label">
    <?php if ($k['status'] === 'dibatalkan'): ?><div class="air">DIBATALKAN</div><?php elseif (in_array($k['pp_status'], ['draft', 'menunggu_persetujuan'], true)): ?><div class="air">BELUM<br>DISETUJUI</div><?php endif; ?>
    <div class="kop">
      <?php if ($logo): ?><img src="<?= e($logo) ?>" alt=""><?php else: ?><div class="logo-teks">A</div><?php endif; ?>
      <div><div class="lembaga"><?= e($lembaga !== '' ? $lembaga : APP_NAMA) ?></div><div class="sub"><?= e($lembaga !== '' ? APP_NAMA : (defined('APP_KEPANJANGAN') ? APP_KEPANJANGAN : '')) ?></div></div>
      <?php if ($k['urut']): ?><div class="urut">Kardus <?= (int) $k['urut'] ?> dari <?= (int) $k['jumlah_kardus'] ?></div><?php endif; ?>
    </div>
    <div class="kode"><?= e($k['kode']) ?></div>
    <?php if ($k['nama']): ?><div class="nama"><?= e($k['nama']) ?></div><?php endif; ?>
    <div class="qr">
      <?php if ($k['qr']): ?><div class="gbr"><?= $k['qr'] /* SVG dibuat di server */ ?></div><?php endif; ?>
      <div class="ket"><b>Pindai QR untuk rincian lengkap</b><br>Isi, status, dan riwayat kardus. Bila QR tidak terbaca, ketik kode di atas pada menu <b>Folder Kardus</b> atau <b>Cek Surat Jalan</b>.
        <br><br><span class="status"><?= e($sLbl) ?></span></div>
    </div>
    <table class="meta">
      <tr><td>No. perpindahan</td><td><b><?= e($k['no_perpindahan'] ?: '—') ?></b></td></tr>
      <tr><td>No. surat jalan</td><td><b><?= e($k['no_surat_jalan'] ?: '—') ?></b><?= ($k['pp_resi'] && $k['pp_resi'] !== $k['no_perpindahan']) ? '<br>resi ' . e($k['pp_resi']) : '' ?></td></tr>
      <tr><td>Dari</td><td><b><?= e($k['asal']) ?></b></td></tr>
      <tr><td>Ke</td><td><b><?= e($k['tujuan'] ?: '—') ?></b><?= $k['penerima'] ? '<br>u.p. ' . e($k['penerima']) : '' ?></td></tr>
      <tr><td>Tanggal</td><td><b><?= e(tgl_id($k['tanggal_kemas'])) ?></b><?= $k['petugas_nama'] ? ' · ' . e($k['petugas_nama']) : '' ?><?= $k['berat_kg'] ? ' · ' . e(rtrim(rtrim((string) $k['berat_kg'], '0'), '.')) . ' kg' : '' ?></td></tr>
      <?php if ($k['catatan']): ?><tr><td>Catatan</td><td><?= e($k['catatan']) ?></td></tr><?php endif; ?>
    </table>
    <div class="isi">
      <h4>Isi · <?= $ring['jenis'] ?> jenis · <?= e(kardus_teks_satuan($ring['per_satuan'])) ?></h4>
      <table class="isi-t">
        <?php foreach ($isiTampil as $r): ?>
          <tr><td><?= e($r['nama_barang']) ?></td><td class="n"><?= angka($r['qty']) ?> <?= e($r['satuan']) ?></td></tr>
        <?php endforeach; ?>
        <?php if (count($k['isi']) > $maksBaris): ?><tr><td colspan="2" style="color:#475569">+<?= count($k['isi']) - $maksBaris ?> jenis lainnya — pindai QR untuk rincian lengkap</td></tr><?php endif; ?>
      </table>
    </div>
    <div class="kaki"><span><?= APP_NAMA ?> · dicetak <?= e(date('d/m/Y H:i')) ?></span><span><?= e($k['kode']) ?></span></div>
  </div>
<?php endforeach; ?>
</div>
</body>
</html>
