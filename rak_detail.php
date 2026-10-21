<?php
/**
 * AMANAH — Detail Rak (baca saja)
 *
 * Isi satu rak: batch apa saja, berapa, kondisi, kedaluwarsa, dari donatur
 * mana. Cerminan api/rak.php?id yang dipakai aplikasi Android. Dari sini
 * petugas bisa langsung ke Stock Opname rak yang sama.
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/_lib.php';
require_once __DIR__ . '/api/_lingkup.php';
require_login();

$id = (int) ($_GET['id'] ?? 0);
$p  = principal_sesi($pdo);

$st = $pdo->prepare(
    "SELECT lr.*, g.nama AS gudang_nama, g.kode AS gudang_kode, g.jenis AS gudang_jenis,
            COALESCE(x.qty,0) AS terisi, COALESCE(x.jml_batch,0) AS jml_batch, COALESCE(x.jml_barang,0) AS jml_barang,
            (SELECT MAX(ps.tanggal) FROM penyesuaian_stok ps WHERE ps.lokasi_rak_id = lr.id) AS opname_terakhir
     FROM lokasi_rak lr
     LEFT JOIN gudang g ON g.id = lr.gudang_id
     LEFT JOIN (SELECT lokasi_rak_id, SUM(qty_tersisa) qty, COUNT(*) jml_batch, COUNT(DISTINCT barang_id) jml_barang
                FROM batch_barang WHERE qty_tersisa > 0 GROUP BY lokasi_rak_id) x ON x.lokasi_rak_id = lr.id
     WHERE lr.id = ? AND lr.deleted_at IS NULL");
$st->execute([$id]);
$r = $st->fetch();
if (!$r) {
    http_response_code(404);
    die('<p style="font-family:sans-serif;padding:30px">Rak tidak ditemukan. <a href="rak.php">Kembali</a></p>');
}
$inGudang = api_in_gudang($pdo, $p);
if ($inGudang !== '' && !in_array((string) (int) $r['gudang_id'], explode(',', $inGudang), true)) {
    http_response_code(403);
    die('<p style="font-family:sans-serif;padding:30px">403 — Rak itu di luar lingkup akun Anda. '
      . '<a href="' . e(beranda_peran()) . '">Kembali</a></p>');
}

$st = $pdo->prepare(
    "SELECT bb.id, bb.nomor_batch, bb.qty_tersisa, bb.kondisi, bb.sumber, bb.tanggal_expired, bb.tanggal_masuk,
            DATEDIFF(bb.tanggal_expired, CURDATE()) AS sisa_hari,
            b.id AS barang_id, b.nama AS barang, b.kode AS kode_barang, b.satuan,
            d.nama AS donatur_nama, d.anonim AS donatur_anonim, d.wilayah AS donatur_wilayah
     FROM batch_barang bb
     JOIN barang b       ON b.id = bb.barang_id
     LEFT JOIN donatur d ON d.id = bb.donatur_id
     WHERE bb.lokasi_rak_id = ? AND bb.qty_tersisa > 0
     ORDER BY b.nama, (bb.tanggal_expired IS NULL), bb.tanggal_expired");
$st->execute([$id]);
$isi = $st->fetchAll();

$persen = $r['kapasitas'] > 0 ? min(100, (int) round($r['terisi'] / $r['kapasitas'] * 100)) : null;
$bolehOpname = in_array(role_saat_ini(), ['super_admin', 'admin_gudang', 'admin_terpadu'], true)
            && in_array($id, rak_saya($pdo), true);

$PAGE_TITLE = $r['nama']; $ACTIVE = 'rak';
require __DIR__ . '/_header.php';
?>
<div class="jejak"><a href="gudang.php">Gudang</a> <span>›</span>
  <a href="gudang_detail.php?id=<?= (int) $r['gudang_id'] ?>"><?= e($r['gudang_nama'] ?: '—') ?></a> <span>›</span> <b><?= e($r['nama']) ?></b></div>

<div class="card">
  <div class="kepala-rak">
    <div style="flex:1;min-width:0">
      <h2 style="margin:0"><?= e($r['nama']) ?> <code style="font-size:13px"><?= e($r['kode']) ?></code></h2>
      <p class="mut" style="margin:4px 0 0"><?= e($r['gudang_nama'] ?: '—') ?>
        <?php if ($r['keterangan']): ?> · <?= e($r['keterangan']) ?><?php endif; ?>
        · opname terakhir <?= $r['opname_terakhir'] ? e(tgl_id($r['opname_terakhir'])) : 'belum pernah' ?></p>
    </div>
    <?php if ($bolehOpname): ?>
      <a class="btn btn-primary" href="opname.php?rak=<?= (int) $r['id'] ?>"><svg aria-hidden="true"><use href="#i-clipboard"></use></svg> Opname Rak Ini</a>
    <?php endif; ?>
  </div>
</div>

<div class="kpi">
  <div class="kpi-card" style="--kc:var(--accent)"><div class="kpi-top"><span class="kpi-l">Terisi</span><span class="kpi-ic"><svg aria-hidden="true"><use href="#i-box"></use></svg></span></div>
    <div class="kpi-v"><?= angka($r['terisi']) ?></div><div class="kpi-d">unit</div></div>
  <div class="kpi-card" style="--kc:<?= $persen !== null && $persen >= 90 ? 'var(--red)' : 'var(--green)' ?>"><div class="kpi-top"><span class="kpi-l">Kapasitas</span><span class="kpi-ic"><svg aria-hidden="true"><use href="#i-layers"></use></svg></span></div>
    <div class="kpi-v"><?= $persen !== null ? $persen . '%' : '—' ?></div><div class="kpi-d"><?= $r['kapasitas'] ? 'dari ' . angka($r['kapasitas']) . ' unit' : 'belum diatur' ?></div></div>
  <div class="kpi-card" style="--kc:var(--info)"><div class="kpi-top"><span class="kpi-l">Batch</span><span class="kpi-ic"><svg aria-hidden="true"><use href="#i-clipboard"></use></svg></span></div>
    <div class="kpi-v"><?= (int) $r['jml_batch'] ?></div><div class="kpi-d">aktif</div></div>
  <div class="kpi-card" style="--kc:var(--amber)"><div class="kpi-top"><span class="kpi-l">Jenis barang</span><span class="kpi-ic"><svg aria-hidden="true"><use href="#i-saring"></use></svg></span></div>
    <div class="kpi-v"><?= (int) $r['jml_barang'] ?></div><div class="kpi-d">produk berbeda</div></div>
</div>

<div class="card">
  <h3>Isi Rak <small class="mut">urut nama barang, lalu kedaluwarsa terdekat</small></h3>
  <?php if (!$isi): ?><div class="empty">Rak ini kosong menurut sistem.</div>
  <?php else: ?>
  <div class="tbl-wrap"><table class="tbl-kartu">
    <thead><tr><th>Barang</th><th>Batch</th><th>Kondisi</th><th>Asal</th><th>Masuk</th><th>Kedaluwarsa</th><th>Sisa</th></tr></thead>
    <tbody>
    <?php foreach ($isi as $b): [$lk, $wk] = label_kondisi($b['kondisi'] ?? 'baru'); ?>
      <tr>
        <td class="judul" data-label="Barang"><a href="barang_detail.php?id=<?= (int) $b['barang_id'] ?>" class="tautan-judul"><b><?= e($b['barang']) ?></b></a><br><small class="mut"><?= e($b['kode_barang']) ?></small></td>
        <td data-label="Batch"><code><?= e($b['nomor_batch']) ?></code></td>
        <td class="sekunder" data-label="Kondisi"><span class="tag <?= e($wk) ?>"><?= e($lk) ?></span></td>
        <td class="sekunder" data-label="Asal"><?= e(ucfirst((string) ($b['sumber'] ?: '—'))) ?>
          <?php if ($b['donatur_nama']): ?><br><small class="mut2"><?= e(nama_donatur_tampil(['nama' => $b['donatur_nama'], 'anonim' => $b['donatur_anonim'], 'wilayah' => $b['donatur_wilayah']])) ?></small><?php endif; ?></td>
        <td class="sekunder" data-label="Masuk"><?= $b['tanggal_masuk'] ? tgl_id($b['tanggal_masuk']) : '—' ?></td>
        <td class="sekunder" data-label="Kedaluwarsa">
          <?php if ($b['tanggal_expired']): ?><?= tgl_id($b['tanggal_expired']) ?><br>
            <small class="<?= (int) $b['sisa_hari'] < 0 ? 't-red' : 'mut' ?>"><?= (int) $b['sisa_hari'] < 0 ? 'lewat ' . abs((int) $b['sisa_hari']) . ' hari' : (int) $b['sisa_hari'] . ' hari lagi' ?></small>
          <?php else: ?>—<?php endif; ?></td>
        <td data-label="Sisa"><b><?= angka($b['qty_tersisa']) ?></b> <small class="mut"><?= e($b['satuan']) ?></small></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
  <?php endif; ?>
</div>
<style>
.jejak{ margin-bottom:14px; font-size:13px } .jejak span{ opacity:.5; margin:0 6px }
.kepala-rak{ display:flex; gap:18px; align-items:flex-start; flex-wrap:wrap }
</style>
<?php require __DIR__ . '/_footer.php'; ?>
