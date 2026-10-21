<?php
/**
 * AMANAH — Detail Gudang (baca saja)
 *
 * Kepala gudang, empat angka (rak, kapasitas, terisi, jenis barang), daftar
 * rak beserta isinya, dan daftar barang yang ada di gudang ini. Cerminan
 * api/gudang.php?id yang sudah dipakai aplikasi Android; web dulu hanya
 * punya daftar gudang tanpa bisa masuk ke dalamnya.
 *
 * Lingkupnya lewat api_in_gudang() dengan principal sesi — admin gudang
 * tidak bisa membuka gudang cabang lain dengan menebak id.
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/_lib.php';
require_once __DIR__ . '/api/_lingkup.php';
require_login();

$id = (int) ($_GET['id'] ?? 0);
$p  = principal_sesi($pdo);
$inGudang = api_in_gudang($pdo, $p);
if ($inGudang !== '' && !in_array((string) $id, explode(',', $inGudang), true)) {
    http_response_code(403);
    die('<p style="font-family:sans-serif;padding:30px">403 — Gudang itu di luar lingkup akun Anda. '
      . '<a href="' . e(beranda_peran()) . '">Kembali</a></p>');
}

$st = $pdo->prepare(
    "SELECT g.*, sp.nama AS supplier_nama,
            (SELECT COUNT(*) FROM lokasi_rak lr WHERE lr.gudang_id = g.id AND lr.deleted_at IS NULL) AS jml_rak,
            (SELECT COALESCE(SUM(lr.kapasitas),0) FROM lokasi_rak lr WHERE lr.gudang_id = g.id AND lr.deleted_at IS NULL) AS kapasitas,
            (SELECT COALESCE(SUM(bb.qty_tersisa),0) FROM batch_barang bb JOIN lokasi_rak lr ON lr.id = bb.lokasi_rak_id
              WHERE lr.gudang_id = g.id AND bb.qty_tersisa > 0) AS terisi,
            (SELECT COUNT(DISTINCT bb.barang_id) FROM batch_barang bb JOIN lokasi_rak lr ON lr.id = bb.lokasi_rak_id
              WHERE lr.gudang_id = g.id AND bb.qty_tersisa > 0) AS jml_barang
     FROM gudang g LEFT JOIN supplier sp ON sp.id = g.supplier_id
     WHERE g.id = ? AND g.deleted_at IS NULL");
$st->execute([$id]);
$g = $st->fetch();
if (!$g) {
    http_response_code(404);
    die('<p style="font-family:sans-serif;padding:30px">Gudang tidak ditemukan. <a href="gudang.php">Kembali</a></p>');
}

$rak = $pdo->prepare(
    "SELECT lr.id, lr.kode, lr.nama, lr.kapasitas, lr.keterangan,
            COALESCE(x.qty,0) AS terisi, COALESCE(x.jml_batch,0) AS jml_batch, COALESCE(x.jml_barang,0) AS jml_barang,
            x.exp_terdekat
     FROM lokasi_rak lr
     LEFT JOIN (SELECT lokasi_rak_id, SUM(qty_tersisa) qty, COUNT(*) jml_batch, COUNT(DISTINCT barang_id) jml_barang,
                       MIN(tanggal_expired) exp_terdekat
                FROM batch_barang WHERE qty_tersisa > 0 GROUP BY lokasi_rak_id) x ON x.lokasi_rak_id = lr.id
     WHERE lr.gudang_id = ? AND lr.deleted_at IS NULL ORDER BY lr.kode");
$rak->execute([$id]);
$rakList = $rak->fetchAll();

$brg = $pdo->prepare(
    "SELECT b.id, b.kode, b.nama, b.satuan, b.stok_minimum, k.nama AS kategori,
            SUM(bb.qty_tersisa) AS qty, COUNT(*) AS jml_batch, MIN(bb.tanggal_expired) AS exp_terdekat
     FROM batch_barang bb
     JOIN lokasi_rak lr ON lr.id = bb.lokasi_rak_id
     JOIN barang b      ON b.id = bb.barang_id
     LEFT JOIN kategori k ON k.id = b.kategori_id
     WHERE lr.gudang_id = ? AND bb.qty_tersisa > 0 AND b.deleted_at IS NULL
     GROUP BY b.id ORDER BY b.nama");
$brg->execute([$id]);
$barangList = $brg->fetchAll();

$persen = $g['kapasitas'] > 0 ? min(100, (int) round($g['terisi'] / $g['kapasitas'] * 100)) : null;
$jenisLbl = ['pusat' => 'Gudang Pusat', 'supplier' => 'Gudang Supplier', 'toko' => 'Gudang Toko', 'umum' => 'Gudang'];

$PAGE_TITLE = $g['nama']; $ACTIVE = 'gudang';
require __DIR__ . '/_header.php';
?>
<div class="jejak"><a href="gudang.php">Gudang</a> <span>›</span> <b><?= e($g['nama']) ?></b></div>

<div class="card">
  <div class="kepala-gudang">
    <div style="flex:1;min-width:0">
      <h2 style="margin:0"><?= e($g['nama']) ?> <code style="font-size:13px"><?= e($g['kode']) ?></code></h2>
      <p class="mut" style="margin:4px 0 8px">
        <?= e($jenisLbl[$g['jenis'] ?? 'umum'] ?? 'Gudang') ?>
        <?php if ($g['supplier_nama']): ?> · milik <?= e($g['supplier_nama']) ?><?php endif; ?>
        <?php if ($g['penanggung_jawab']): ?> · PJ <?= e($g['penanggung_jawab']) ?><?php endif; ?>
        <?php if ($g['telepon']): ?> · <?= e($g['telepon']) ?><?php endif; ?>
      </p>
      <?php if ($g['alamat']): ?><p class="mut" style="margin:0"><?= e($g['alamat']) ?></p><?php endif; ?>
      <div style="margin-top:8px"><span class="tag <?= (int) $g['is_active'] ? 't-green' : 't-gray' ?>"><?= (int) $g['is_active'] ? 'Aktif' : 'Nonaktif' ?></span></div>
    </div>
    <div style="display:flex;gap:8px;flex-wrap:wrap">
      <?php if (!is_eksternal() && boleh_kelola_master($pdo)): ?>
        <a class="btn btn-ghost" href="rak.php?gudang=<?= (int) $g['id'] ?>"><svg aria-hidden="true"><use href="#i-layers"></use></svg> Kelola Rak</a>
      <?php endif; ?>
      <?php if (in_array(role_saat_ini(), ['super_admin', 'admin_gudang', 'admin_terpadu'], true)): ?>
        <a class="btn btn-primary" href="opname.php"><svg aria-hidden="true"><use href="#i-clipboard"></use></svg> Stock Opname</a>
      <?php endif; ?>
    </div>
  </div>
</div>

<div class="kpi">
  <div class="kpi-card" style="--kc:var(--info)"><div class="kpi-top"><span class="kpi-l">Rak</span><span class="kpi-ic"><svg aria-hidden="true"><use href="#i-layers"></use></svg></span></div>
    <div class="kpi-v"><?= (int) $g['jml_rak'] ?></div><div class="kpi-d">lokasi penyimpanan</div></div>
  <div class="kpi-card" style="--kc:var(--accent)"><div class="kpi-top"><span class="kpi-l">Terisi</span><span class="kpi-ic"><svg aria-hidden="true"><use href="#i-box"></use></svg></span></div>
    <div class="kpi-v"><?= angka($g['terisi']) ?></div><div class="kpi-d">unit di seluruh rak</div></div>
  <div class="kpi-card" style="--kc:<?= $persen !== null && $persen >= 90 ? 'var(--red)' : 'var(--green)' ?>"><div class="kpi-top"><span class="kpi-l">Kapasitas</span><span class="kpi-ic"><svg aria-hidden="true"><use href="#i-gudang"></use></svg></span></div>
    <div class="kpi-v"><?= $persen !== null ? $persen . '%' : '—' ?></div><div class="kpi-d"><?= $g['kapasitas'] > 0 ? 'dari ' . angka($g['kapasitas']) . ' unit' : 'kapasitas rak belum diatur' ?></div></div>
  <div class="kpi-card" style="--kc:var(--amber)"><div class="kpi-top"><span class="kpi-l">Jenis barang</span><span class="kpi-ic"><svg aria-hidden="true"><use href="#i-saring"></use></svg></span></div>
    <div class="kpi-v"><?= (int) $g['jml_barang'] ?></div><div class="kpi-d">produk berbeda tersimpan</div></div>
</div>

<div class="card">
  <h3>Rak di Gudang Ini</h3>
  <?php if (!$rakList): ?><div class="empty">Belum ada rak. Barang tidak bisa masuk sebelum ada rak.</div>
  <?php else: ?>
  <div class="tbl-wrap"><table class="tbl-kartu">
    <thead><tr><th>Kode</th><th>Rak</th><th>Batch</th><th>Jenis Barang</th><th>Terisi / Kapasitas</th><th>Exp Terdekat</th></tr></thead>
    <tbody>
    <?php foreach ($rakList as $r): $pr = $r['kapasitas'] > 0 ? min(100, (int) round($r['terisi'] / $r['kapasitas'] * 100)) : null; ?>
      <tr>
        <td class="sekunder" data-label="Kode"><code><?= e($r['kode']) ?></code></td>
        <td class="judul" data-label="Rak"><a href="rak_detail.php?id=<?= (int) $r['id'] ?>" class="tautan-judul"><b><?= e($r['nama']) ?></b></a>
          <?php if ($r['keterangan']): ?><br><small class="mut"><?= e($r['keterangan']) ?></small><?php endif; ?></td>
        <td data-label="Batch"><?= (int) $r['jml_batch'] ?></td>
        <td class="sekunder" data-label="Jenis Barang"><?= (int) $r['jml_barang'] ?> jenis</td>
        <td data-label="Terisi / Kapasitas"><b><?= angka($r['terisi']) ?></b><?= $r['kapasitas'] ? ' / ' . angka($r['kapasitas']) : '' ?>
          <?php if ($pr !== null): ?><div class="bar"><span style="width:<?= $pr ?>%;background:<?= $pr >= 90 ? 'var(--red)' : 'var(--accent)' ?>"></span></div><?php endif; ?></td>
        <td class="sekunder" data-label="Exp Terdekat"><?= $r['exp_terdekat'] ? tgl_id($r['exp_terdekat']) : '—' ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
  <?php endif; ?>
</div>

<div class="card">
  <h3>Barang di Gudang Ini <small class="mut"><?= count($barangList) ?> produk</small></h3>
  <?php if (!$barangList): ?><div class="empty">Belum ada stok.</div>
  <?php else: ?>
  <div class="tbl-wrap"><table class="tbl-kartu">
    <thead><tr><th>Produk</th><th>Kategori</th><th>Stok di sini</th><th>Batch</th><th>Exp Terdekat</th></tr></thead>
    <tbody>
    <?php foreach ($barangList as $b): [$s, $sc] = status_stok((int) $b['qty'], (int) $b['stok_minimum']); ?>
      <tr>
        <td class="judul" data-label="Produk"><a href="barang_detail.php?id=<?= (int) $b['id'] ?>" class="tautan-judul"><b><?= e($b['nama']) ?></b></a><br><small class="mut"><?= e($b['kode']) ?></small></td>
        <td class="sekunder" data-label="Kategori"><?= e($b['kategori'] ?: '—') ?></td>
        <td data-label="Stok"><b><?= angka($b['qty']) ?></b> <?= e($b['satuan']) ?> <span class="tag <?= $sc ?>" style="margin-left:6px"><?= $s ?></span></td>
        <td class="sekunder" data-label="Batch"><?= (int) $b['jml_batch'] ?></td>
        <td class="sekunder" data-label="Exp"><?= $b['exp_terdekat'] ? tgl_id($b['exp_terdekat']) : '—' ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
  <?php endif; ?>
</div>

<style>
.jejak{ margin-bottom:14px; font-size:13px } .jejak span{ opacity:.5; margin:0 6px }
.kepala-gudang{ display:flex; gap:18px; align-items:flex-start; flex-wrap:wrap }
.bar{ height:6px; border-radius:6px; background:var(--surface-3); margin-top:5px; overflow:hidden; max-width:160px } .bar span{ display:block; height:100%; border-radius:6px }
</style>
<?php require __DIR__ . '/_footer.php'; ?>
