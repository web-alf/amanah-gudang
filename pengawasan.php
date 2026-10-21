<?php
/**
 * AMANAH — Mitra & Lokasi (mode lihat-saja)
 *
 * Satu tempat bagi Super Admin & Pimpinan untuk melihat seluruh rantai:
 * dasbor tiap supplier, dasbor tiap toko, dan ringkasan gudang.
 *
 * Sifatnya MELIHAT saja. Mencatat penjualan atau menerbitkan surat jalan
 * atas nama pihak lain akan membuat laporan menunjukkan pelaku yang keliru,
 * jadi tombol tindakan sengaja disembunyikan di mode ini.
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/_lib.php';
require_login();
tolak_supplier();

if (!boleh_mengawasi()) {
    http_response_code(403);
    die('<p style="font-family:sans-serif;padding:30px">403 — Halaman ini untuk Super Admin dan Pimpinan. '
      . '<a href="dashboard.php">Kembali</a></p>');
}

function pw_rows(PDO $pdo, string $sql): array {
    try { return $pdo->query($sql)->fetchAll(); }
    catch (Throwable $e) { error_log('[AMANAH] pengawasan: ' . $e->getMessage()); return []; }
}
function pw_one(PDO $pdo, string $sql, $d = 0) {
    try { return $pdo->query($sql)->fetchColumn(); }
    catch (Throwable $e) { error_log('[AMANAH] pengawasan: ' . $e->getMessage()); return $d; }
}

// ---------------------------------------------------------------- GUDANG
$gudangList = pw_rows($pdo,
    "SELECT g.id, g.kode, g.nama,
            (SELECT COUNT(*) FROM lokasi_rak lr WHERE lr.gudang_id = g.id AND lr.deleted_at IS NULL) AS jml_rak,
            (SELECT COALESCE(SUM(bb.qty_tersisa),0) FROM batch_barang bb
               JOIN lokasi_rak lr ON lr.id = bb.lokasi_rak_id
              WHERE lr.gudang_id = g.id AND bb.qty_tersisa > 0) AS stok,
            (SELECT COUNT(DISTINCT bb.barang_id) FROM batch_barang bb
               JOIN lokasi_rak lr ON lr.id = bb.lokasi_rak_id
              WHERE lr.gudang_id = g.id AND bb.qty_tersisa > 0) AS jenis
     FROM gudang g WHERE g.deleted_at IS NULL ORDER BY g.kode");

// -------------------------------------------------------------- SUPPLIER
$supplierList = pw_rows($pdo,
    "SELECT s.id, s.kode, s.nama, s.telepon,
            (SELECT COUNT(*) FROM users us WHERE us.supplier_id = s.id
               AND us.is_active = 1 AND us.deleted_at IS NULL) AS jml_akun,
            (SELECT COALESCE(SUM(ss.qty),0) FROM stok_supplier ss WHERE ss.supplier_id = s.id) AS siap_kirim,
            (SELECT COUNT(*) FROM perpindahan p
              WHERE p.asal_tipe = 'supplier' AND p.asal_id = s.id AND p.status = 'dikirim') AS di_jalan,
            (SELECT COALESCE(SUM(COALESCE(d.qty_diterima, d.qty)),0)
               FROM perpindahan p JOIN perpindahan_detail d ON d.perpindahan_id = p.id
              WHERE p.asal_tipe = 'supplier' AND p.asal_id = s.id AND p.status = 'selesai'
                AND YEAR(p.tanggal) = YEAR(CURDATE()) AND MONTH(p.tanggal) = MONTH(CURDATE())) AS diterima_bln
     FROM supplier s WHERE s.deleted_at IS NULL ORDER BY s.kode");

// ------------------------------------------------------------------ TOKO
$tokoList = pw_rows($pdo,
    "SELECT t.id, t.kode, t.nama, t.marketplace,
            (SELECT COUNT(*) FROM user_toko ut JOIN users us ON us.id = ut.user_id
              WHERE ut.toko_id = t.id AND us.is_active = 1 AND us.deleted_at IS NULL) AS jml_akun,
            (SELECT COALESCE(SUM(bb.qty_tersisa),0) FROM batch_barang bb
              WHERE bb.toko_id = t.id AND bb.qty_tersisa > 0) AS stok,
            (SELECT COUNT(DISTINCT bb.barang_id) FROM batch_barang bb
              WHERE bb.toko_id = t.id AND bb.qty_tersisa > 0) AS jenis,
            (SELECT COUNT(*) FROM perpindahan p
              WHERE p.tujuan_tipe = 'toko' AND p.tujuan_id = t.id AND p.status = 'dikirim') AS menunggu_terima,
            (SELECT COALESCE(SUM(d.subtotal),0) FROM penjualan pj
               JOIN penjualan_detail d ON d.penjualan_id = pj.id
              WHERE pj.toko_id = t.id AND pj.status <> 'batal'
                AND YEAR(pj.tanggal) = YEAR(CURDATE()) AND MONTH(pj.tanggal) = MONTH(CURDATE())) AS omzet_bln
     FROM toko t WHERE t.deleted_at IS NULL ORDER BY t.kode");

// -------------------------------------------------------- ANGKA GABUNGAN
$totGudang = (int)   pw_one($pdo, "SELECT COALESCE(SUM(qty_tersisa),0) FROM batch_barang WHERE toko_id IS NULL");
$totToko   = (int)   pw_one($pdo, "SELECT COALESCE(SUM(qty_tersisa),0) FROM batch_barang WHERE toko_id IS NOT NULL");
$diJalan   = (int)   pw_one($pdo, "SELECT COALESCE(SUM(d.qty),0) FROM perpindahan p
                                   JOIN perpindahan_detail d ON d.perpindahan_id = p.id
                                   WHERE p.status = 'dikirim'");
$omzetBln  = (float) pw_one($pdo, "SELECT COALESCE(SUM(d.subtotal),0) FROM penjualan p
                                   JOIN penjualan_detail d ON d.penjualan_id = p.id
                                   WHERE p.status <> 'batal'
                                     AND YEAR(p.tanggal)=YEAR(CURDATE()) AND MONTH(p.tanggal)=MONTH(CURDATE())");

$PAGE_TITLE = 'Mitra & Lokasi'; $ACTIVE = 'pengawasan';
require __DIR__ . '/_header.php';
?>
<div class="page-head">
  <div><h1>Mitra &amp; Lokasi</h1>
    <p>Semua supplier, gudang, dan toko beserta angka terkininya</p></div>
</div>

<div class="alert" style="--ac:var(--info);margin-bottom:18px">
  <span class="alert-ic"><svg aria-hidden="true"><use href="#i-mata"></use></svg></span>
  <div class="alert-tx">
    <b>Halaman ini hanya untuk melihat</b>
    <small>Dasbor supplier &amp; toko mana pun bisa dibuka, tetapi tombol tindakannya
      disembunyikan — pencatatan harus dilakukan pemilik akunnya sendiri supaya laporan
      menunjukkan pelaku yang sebenarnya.</small>
  </div>
</div>

<div class="kpi">
  <div class="kpi-card" style="--kc:var(--primary-2)">
    <div class="kpi-top"><span class="kpi-l">Stok di Gudang</span>
      <span class="kpi-ic"><svg aria-hidden="true"><use href="#i-gudang"></use></svg></span></div>
    <div class="kpi-v"><?= angka($totGudang) ?></div>
    <div class="kpi-d"><?= count($gudangList) ?> gudang terdaftar</div>
  </div>
  <div class="kpi-card" style="--kc:var(--accent)">
    <div class="kpi-top"><span class="kpi-l">Stok di Toko</span>
      <span class="kpi-ic"><svg aria-hidden="true"><use href="#i-toko"></use></svg></span></div>
    <div class="kpi-v"><?= angka($totToko) ?></div>
    <div class="kpi-d"><?= count($tokoList) ?> toko terdaftar</div>
  </div>
  <div class="kpi-card" style="--kc:var(--amber)">
    <div class="kpi-top"><span class="kpi-l">Dalam Perjalanan</span>
      <span class="kpi-ic"><svg aria-hidden="true"><use href="#i-truck"></use></svg></span></div>
    <div class="kpi-v"><?= angka($diJalan) ?></div>
    <div class="kpi-d">Unit sedang dikirim</div>
  </div>
  <div class="kpi-card" style="--kc:var(--green)">
    <div class="kpi-top"><span class="kpi-l">Omzet Toko Bulan Ini</span>
      <span class="kpi-ic"><svg aria-hidden="true"><use href="#i-uang"></use></svg></span></div>
    <div class="kpi-v kecil"><?= rupiah($omzetBln) ?></div>
    <div class="kpi-d"><?= count($supplierList) ?> supplier di rantai</div>
  </div>
</div>

<?php
/* Akun yang belum dibuat ditandai supaya terlihat sebagai pekerjaan yang
   menunggu, bukan sekadar angka nol. */
$supTanpaAkun = count(array_filter($supplierList, fn($s) => (int) $s['jml_akun'] === 0));
$tokTanpaAkun = count(array_filter($tokoList,     fn($t) => (int) $t['jml_akun'] === 0));
?>

<?php if ($supTanpaAkun || $tokTanpaAkun): ?>
  <div class="alert" style="--ac:var(--amber);margin-bottom:16px">
    <span class="alert-ic"><svg aria-hidden="true"><use href="#i-users"></use></svg></span>
    <div class="alert-tx">
      <b><?= angka($supTanpaAkun + $tokTanpaAkun) ?> mitra belum punya akun login</b>
      <small><?= $supTanpaAkun ?> supplier &amp; <?= $tokTanpaAkun ?> toko. Tanpa akun,
        mereka tidak bisa mencatat sendiri dan dasbornya akan selalu kosong.</small>
    </div>
    <?php if (is_super()): ?>
      <a class="btn btn-ghost btn-sm" href="pengaturan.php">Buat Akun</a>
    <?php endif; ?>
  </div>
<?php endif; ?>

<!-- ============================================================ SUPPLIER -->
<div class="card mb16">
  <div class="page-head" style="margin:0 0 16px;padding-bottom:14px">
    <div>
      <h3 style="display:flex;align-items:center;gap:9px">
        <svg class="ikon" style="stroke:var(--accent)" aria-hidden="true"><use href="#i-truck"></use></svg>
        Supplier
      </h3>
      <p><?= count($supplierList) ?> supplier · klik Lihat Dasbor untuk membuka tampilan miliknya</p>
    </div>
  </div>

  <?php if (!$supplierList): ?>
    <div class="empty-state">
      <div class="ic"><svg aria-hidden="true"><use href="#i-truck"></use></svg></div>
      <b>Belum ada supplier</b>
      <p>Supplier adalah pihak yang mengirim barang ke gudang atau langsung ke toko.</p>
      <div class="aksi"><a class="btn btn-primary btn-sm" href="supplier.php">+ Tambah Supplier</a></div>
    </div>
  <?php else: ?>
    <div class="tbl-wrap"><table class="tbl-kartu">
      <thead><tr>
        <th>Kode</th><th>Supplier</th><th>Akun</th><th class="num">Siap Kirim</th>
        <th class="num">Di Jalan</th><th class="num">Diterima Bln Ini</th><th></th>
      </tr></thead>
      <tbody>
      <?php foreach ($supplierList as $s): ?>
        <tr>
          <td class="sekunder" data-label="Kode"><code><?= e($s['kode']) ?></code></td>
          <td class="judul" data-label="Supplier"><b><?= e($s['nama']) ?></b>
            <?php if ($s['telepon']): ?>
              <br><small class="mut2"><?= e($s['telepon']) ?></small>
            <?php endif; ?>
          </td>
          <td class="sekunder" data-label="Akun"><?= (int) $s['jml_akun'] > 0
                ? '<span class="tag t-green">' . (int) $s['jml_akun'] . ' aktif</span>'
                : '<span class="tag t-amber">belum ada</span>' ?></td>
          <td data-label="Siap Kirim" class="num"><b><?= angka($s['siap_kirim']) ?></b>
            <small class="mut2">unit</small></td>
          <td data-label="Di Jalan" class="num"><?= (int) $s['di_jalan'] > 0
                ? '<span class="tag t-amber">' . (int) $s['di_jalan'] . ' SJ</span>'
                : '<span class="mut2">—</span>' ?></td>
          <td data-label="Diterima Bln Ini" class="num sekunder"><?= angka($s['diterima_bln']) ?>
            <small class="mut2">unit</small></td>
          <td class="aksi">
            <a class="btn btn-ghost btn-sm" href="dasbor_supplier.php?supplier=<?= (int) $s['id'] ?>">
              <svg aria-hidden="true"><use href="#i-mata"></use></svg> Lihat Dasbor
            </a>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table></div>
  <?php endif; ?>
</div>

<!-- ============================================================== GUDANG -->
<div class="card mb16">
  <div class="page-head" style="margin:0 0 16px;padding-bottom:14px">
    <div>
      <h3 style="display:flex;align-items:center;gap:9px">
        <svg class="ikon" style="stroke:var(--primary-t)" aria-hidden="true"><use href="#i-gudang"></use></svg>
        Gudang
      </h3>
      <p><?= count($gudangList) ?> gudang · <?= angka($totGudang) ?> unit tersimpan</p>
    </div>
  </div>

  <?php if (!$gudangList): ?>
    <div class="empty-state">
      <div class="ic"><svg aria-hidden="true"><use href="#i-gudang"></use></svg></div>
      <b>Belum ada gudang</b>
      <p>Gudang adalah tempat barang disimpan sebelum disalurkan ke toko.</p>
      <div class="aksi"><a class="btn btn-primary btn-sm" href="gudang.php">+ Tambah Gudang</a></div>
    </div>
  <?php else: ?>
    <div class="tbl-wrap"><table class="tbl-kartu">
      <thead><tr><th>Kode</th><th>Gudang</th><th class="num">Rak</th>
        <th class="num">Jenis</th><th class="num">Stok</th><th></th></tr></thead>
      <tbody>
      <?php foreach ($gudangList as $g): ?>
        <tr>
          <td class="sekunder" data-label="Kode"><code><?= e($g['kode']) ?></code></td>
          <td class="judul" data-label="Gudang"><b><?= e($g['nama']) ?></b></td>
          <td data-label="Rak" class="num sekunder">
            <?php if ((int) $g['jml_rak'] === 0): ?>
              <span class="tag t-amber">belum ada rak</span>
            <?php else: ?><?= (int) $g['jml_rak'] ?><?php endif; ?>
          </td>
          <td data-label="Jenis" class="num"><?= (int) $g['jenis'] ?></td>
          <td data-label="Stok" class="num"><b><?= angka($g['stok']) ?></b>
            <small class="mut2">unit</small></td>
          <td class="aksi">
            <a class="btn btn-ghost btn-sm" href="rak.php?gudang=<?= (int) $g['id'] ?>">
              <svg aria-hidden="true"><use href="#i-layers"></use></svg> Rak
            </a>
            <a class="btn btn-ghost btn-sm" href="barang.php?gudang=<?= (int) $g['id'] ?>">
              <svg aria-hidden="true"><use href="#i-box"></use></svg> Barang
            </a>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
      <tfoot><tr><td colspan="4">Total</td>
        <td class="num"><?= angka($totGudang) ?> unit</td><td></td></tr></tfoot>
    </table></div>
  <?php endif; ?>
</div>

<!-- ================================================================ TOKO -->
<div class="card">
  <div class="page-head" style="margin:0 0 16px;padding-bottom:14px">
    <div>
      <h3 style="display:flex;align-items:center;gap:9px">
        <svg class="ikon" style="stroke:var(--green)" aria-hidden="true"><use href="#i-toko"></use></svg>
        Toko / Outlet
      </h3>
      <p><?= count($tokoList) ?> toko · omzet bulan ini <?= rupiah($omzetBln) ?></p>
    </div>
  </div>

  <?php if (!$tokoList): ?>
    <div class="empty-state">
      <div class="ic"><svg aria-hidden="true"><use href="#i-toko"></use></svg></div>
      <b>Belum ada toko</b>
      <p>Toko adalah gerai atau lapak marketplace tempat barang dijual ke pembeli.</p>
      <div class="aksi"><a class="btn btn-primary btn-sm" href="toko.php">+ Tambah Toko</a></div>
    </div>
  <?php else: ?>
    <div class="tbl-wrap"><table class="tbl-kartu">
      <thead><tr>
        <th>Kode</th><th>Toko</th><th>Kanal</th><th>Akun</th>
        <th class="num">Stok</th><th class="num">Menunggu</th><th class="num">Omzet Bln Ini</th><th></th>
      </tr></thead>
      <tbody>
      <?php foreach ($tokoList as $t): [$mpLbl, $mpCls] = label_marketplace($t['marketplace']); ?>
        <tr>
          <td class="sekunder" data-label="Kode"><code><?= e($t['kode']) ?></code></td>
          <td class="judul" data-label="Toko"><b><?= e($t['nama']) ?></b></td>
          <td data-label="Kanal"><span class="tag <?= $mpCls ?>"><?= e($mpLbl) ?></span></td>
          <td class="sekunder" data-label="Akun"><?= (int) $t['jml_akun'] > 0
                ? '<span class="tag t-green">' . (int) $t['jml_akun'] . ' aktif</span>'
                : '<span class="tag t-amber">belum ada</span>' ?></td>
          <td data-label="Stok" class="num"><b><?= angka($t['stok']) ?></b>
            <small class="mut2"><?= (int) $t['jenis'] ?> jenis</small></td>
          <td data-label="Menunggu" class="num"><?= (int) $t['menunggu_terima'] > 0
                ? '<span class="tag t-amber">' . (int) $t['menunggu_terima'] . ' kiriman</span>'
                : '<span class="mut2">—</span>' ?></td>
          <td data-label="Omzet Bln Ini" class="num"><b><?= rupiah($t['omzet_bln']) ?></b></td>
          <td class="aksi">
            <a class="btn btn-ghost btn-sm" href="dasbor_toko.php?toko=<?= (int) $t['id'] ?>">
              <svg aria-hidden="true"><use href="#i-mata"></use></svg> Lihat Dasbor
            </a>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
      <tfoot><tr><td colspan="4">Total</td>
        <td class="num"><?= angka($totToko) ?> unit</td><td></td>
        <td class="num"><?= rupiah($omzetBln) ?></td><td></td></tr></tfoot>
    </table></div>
  <?php endif; ?>
</div>

<?php require __DIR__ . '/_footer.php'; ?>
