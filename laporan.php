<?php
/**
 * AMANAH — Laporan
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/_lib.php';
require_login();

/* Izin 'lihat_laporan' DITEGAKKAN di sini. Sebelumnya ia hanya tercantum
   di katalog dan bisa diatur di Kelola Pengguna, tetapi halaman ini tidak
   pernah memeriksanya — sakelar yang tidak tersambung ke apa pun.
   Bawaannya terbuka untuk semua peran (lihat boleh_lihat_laporan()); yang
   berubah adalah akun yang izinnya DICABUT kini benar-benar tertolak, dan
   menunya ikut hilang lewat halaman_luar_lingkup(). */
if (!boleh_lihat_laporan($pdo)) {
    http_response_code(403);
    die('<p style="font-family:sans-serif;padding:30px">403 — Akun Anda tidak diberi izin membuka laporan. '
      . '<a href="' . e(beranda_peran()) . '">Kembali</a></p>');
}

/* Laporan Stok kini ada di setiap peran, dan tiap peran hanya melihat stok
   yang terhubung dengannya. Lingkupnya dari lingkup_stok() — sumber yang
   sama dengan Batch & Expired. */
$L      = lingkup_stok($pdo);
$syarat = syarat_lingkup($pdo);
$syaratV = syarat_lingkup($pdo, 'v');

/* Arus transaksi (barang masuk/keluar gudang, rekap perpindahan) adalah
   pembukuan sisi GUDANG: transaksi_masuk & transaksi_keluar tidak menyimpan
   lokasi, jadi tidak ada cara menyaringnya per toko atau per supplier.
   Daripada menyajikan angka lembaga di halaman yang berjudul "stok Anda",
   panelnya disembunyikan — angka arus milik supplier & toko sudah ada di
   dasbornya masing-masing, dan menyalinnya ke sini hanya menciptakan dua
   tempat yang bisa berbeda. */
$lihatArus = $L['semua'] || terikat_satu_gudang();

/* Angka rupiah agregat ditahan untuk yang tidak berhak.

   Halaman ini sebelumnya menggambar nilai persediaan TANPA pemeriksaan
   apa pun, sementara endpoint api/laporan.php sengaja menahannya dari
   tingkat staff dan menjelaskan panjang lebar alasannya. Dua pintu ke
   angka yang sama, satu terkunci satu terbuka — dan yang terbuka justru
   yang paling sering dibuka orang.

   Sekarang keduanya memakai satu keputusan yang sama, dan keputusan itu
   kini bisa disimpangkan per akun lewat izin 'lihat_nilai'. */
$lihatNilai = boleh_lihat_nilai($pdo);

function q(PDO $pdo, string $sql): array {
    try { return $pdo->query($sql)->fetchAll(); }
    catch (Throwable $e) { error_log('[AMANAH] laporan: ' . $e->getMessage()); return []; }
}
function s1(PDO $pdo, string $sql, $d = 0) {
    try { return $pdo->query($sql)->fetchColumn(); }
    catch (Throwable $e) { error_log('[AMANAH] laporan: ' . $e->getMessage()); return $d; }
}

/* Semua angka stok bertolak dari v_stok_rantai, bukan dari tabel `stok`.
   Tabel `stok` menyimpan TOTAL lembaga per barang tanpa lokasi — angka yang
   benar untuk Super Admin, tapi tidak bisa disaring per gudang, toko, atau
   supplier. v_stok_rantai menyimpan qty beserta lokasinya. */
$perKat = q($pdo,
    "SELECT k.nama,
            COUNT(DISTINCT v.barang_id) AS jml_barang,
            COALESCE(SUM(v.qty),0) AS total_stok,
            COALESCE(SUM(v.qty * b.harga),0) AS nilai
     FROM kategori k
     LEFT JOIN barang b        ON b.kategori_id = k.id AND b.deleted_at IS NULL
     LEFT JOIN v_stok_rantai v ON v.barang_id = b.id AND $syaratV
     WHERE k.deleted_at IS NULL
     GROUP BY k.id ORDER BY k.nama");

/* Sebaran per LOKASI, bukan per gudang saja: toko menyimpan stok di gudang
   penampung DAN di tokonya sendiri, jadi laporan yang hanya menyebut gudang
   akan kehilangan separuh barangnya. */
$perLokasi = q($pdo,
    "SELECT v.lokasi AS nama,
            COUNT(DISTINCT v.barang_id) AS jenis,
            COALESCE(SUM(v.qty),0) AS qty,
            COALESCE(SUM(v.qty * b.harga),0) AS nilai
     FROM (SELECT r.barang_id, r.qty,
                  COALESCE(t.nama, CONCAT(COALESCE(g.nama,'—'), ' › ', COALESCE(lr.nama,'—'))) AS lokasi
           FROM v_stok_rantai r
           LEFT JOIN toko t        ON t.id  = r.toko_id
           LEFT JOIN lokasi_rak lr ON lr.id = r.lokasi_rak_id
           LEFT JOIN gudang g      ON g.id  = lr.gudang_id
           WHERE r.qty > 0 AND " . syarat_lingkup($pdo, 'r') . ") AS v
     JOIN barang b ON b.id = v.barang_id
     GROUP BY v.lokasi ORDER BY qty DESC, v.lokasi");

$totBarang = (int) s1($pdo, "SELECT COUNT(DISTINCT v.barang_id) FROM v_stok_rantai v
                             WHERE v.qty > 0 AND $syaratV");
$totStok   = (int) s1($pdo, "SELECT COALESCE(SUM(v.qty),0) FROM v_stok_rantai v WHERE $syaratV");
$totNilai  = (float) s1($pdo, "SELECT COALESCE(SUM(v.qty * b.harga),0) FROM v_stok_rantai v
                               JOIN barang b ON b.id = v.barang_id WHERE $syaratV");

$masukBln = $keluarBln = 0;
$topKeluar = $perpindahanBln = [];
if ($lihatArus) {
    $masukBln  = (int) s1($pdo, "SELECT COALESCE(SUM(d.qty),0) FROM transaksi_masuk_detail d
                                 JOIN transaksi_masuk h ON h.id=d.transaksi_masuk_id
                                 WHERE YEAR(h.tanggal)=YEAR(CURDATE()) AND MONTH(h.tanggal)=MONTH(CURDATE())");
    $keluarBln = (int) s1($pdo, "SELECT COALESCE(SUM(d.qty),0) FROM transaksi_keluar_detail d
                                 JOIN transaksi_keluar h ON h.id=d.transaksi_keluar_id
                                 WHERE YEAR(h.tanggal)=YEAR(CURDATE()) AND MONTH(h.tanggal)=MONTH(CURDATE())");

    $topKeluar = q($pdo,
        "SELECT b.nama, COALESCE(SUM(d.qty),0) AS total
         FROM transaksi_keluar_detail d JOIN barang b ON b.id=d.barang_id
         GROUP BY b.id HAVING total > 0 ORDER BY total DESC LIMIT 8");

    // Rekap perpindahan bulan berjalan (modul v2).
    $perpindahanBln = q($pdo,
        "SELECT jenis, COUNT(*) AS jml,
                (SELECT COALESCE(SUM(d.qty),0) FROM perpindahan_detail d WHERE d.perpindahan_id IN
                  (SELECT id FROM perpindahan p2 WHERE p2.jenis = p.jenis AND p2.status IN ('disetujui','selesai')
                     AND YEAR(p2.tanggal)=YEAR(CURDATE()) AND MONTH(p2.tanggal)=MONTH(CURDATE()))) AS qty
         FROM perpindahan p
         WHERE p.status IN ('disetujui','selesai')
           AND YEAR(p.tanggal)=YEAR(CURDATE()) AND MONTH(p.tanggal)=MONTH(CURDATE())
         GROUP BY p.jenis");
}

$expRows = q($pdo, "SELECT status_expired, COUNT(*) jml FROM v_expired_rantai
                    WHERE $syarat GROUP BY status_expired");
$expMap  = ['aman'=>0,'waspada'=>0,'segera_expired'=>0,'expired'=>0,'tanpa_expired'=>0];
foreach ($expRows as $r) $expMap[$r['status_expired']] = (int) $r['jml'];

$PAGE_TITLE = 'Laporan'; $ACTIVE = 'laporan';
require __DIR__ . '/_header.php';
?>
<div class="page-head">
  <div><h1>Laporan Stok</h1>
    <p><?= e(tgl_id(date('Y-m-01'), 'panjang')) ?> s.d. <?= e(tgl_id(date('Y-m-d'), 'panjang')) ?>
      <?php /* Angka stok tanpa keterangan "stok siapa" mudah disalahartikan —
               terutama karena halaman yang sama kini menyajikan lingkup yang
               berbeda untuk setiap peran. */ ?>
      <?php if ($L['label'] !== ''): ?>
        <br><b>Lingkup: <?= e($L['label']) ?></b>
      <?php endif; ?></p></div>
  <div class="baris">
    <a class="btn btn-ghost" href="laporan_export.php">⬇️ Export CSV</a>
    <button class="btn btn-primary" onclick="window.print()"><svg aria-hidden="true"><use href="#i-cetak"></use></svg> Cetak</button>
  </div>
</div>

<div class="kpi">
  <div class="card"><div style="font-size:23px;font-weight:800"><?= angka($totBarang) ?></div>
    <div class="mut-sm">Jenis Barang</div></div>
  <div class="card"><div style="font-size:23px;font-weight:800"><?= angka($totStok) ?></div>
    <div class="mut-sm">Total Stok (unit)</div></div>
  <?php if ($lihatNilai): ?>
  <div class="card"><div style="font-size:18px;font-weight:800"><?= rupiah($totNilai) ?></div>
    <div class="mut-sm">Nilai Stok</div></div>
  <?php endif; ?>
  <?php if ($lihatArus): ?>
  <div class="card"><div style="font-size:23px;font-weight:800">
      <span style="color:var(--green)"><?= angka($masukBln) ?></span> /
      <span style="color:var(--red)"><?= angka($keluarBln) ?></span></div>
    <div class="mut-sm">Masuk / Keluar (bulan ini)</div></div>
  <?php endif; ?>
</div>

<div class="dash-grid">
  <div class="card">
    <h3>Stok per Kategori</h3><div class="sub">Total unit tiap kategori</div>
    <div style="position:relative;height:250px"><canvas id="chKat"></canvas></div>
  </div>
  <div class="card">
    <?php /* Judulnya ikut berubah saat kolom nilai disembunyikan. Judul
             yang menjanjikan angka yang tidak ada di bawahnya terbaca
             sebagai tabel yang rusak, bukan sebagai wewenang yang dibatasi. */ ?>
    <h3><?= $lihatNilai ? 'Nilai Stok per Lokasi' : 'Stok per Lokasi' ?></h3>
    <div class="sub">Sebaran <?= $lihatNilai ? 'aset' : 'persediaan' ?> di tiap gudang, rak, dan toko</div>
    <div class="scrollx"><table class="tbl-kartu">
      <thead><tr><th>Lokasi</th><th>Jenis</th><th>Unit</th>
        <?php if ($lihatNilai): ?><th>Nilai</th><?php endif; ?></tr></thead>
      <tbody>
      <?php if (!$perLokasi): ?>
        <?php /* colspan ikut kolom Nilai yang mungkin disembunyikan —
                 kalau dipatok 4, baris kosongnya melebar satu kolom dan
                 tabelnya terlihat miring. */ ?>
        <tr><td colspan="<?= $lihatNilai ? 4 : 3 ?>" class="empty">Belum ada stok tercatat di lingkup Anda.</td></tr>
      <?php else: foreach ($perLokasi as $g): ?>
        <tr><td data-label="Lokasi"><b><?= e($g['nama']) ?></b></td>
          <td data-label="Jenis"><?= (int) $g['jenis'] ?></td>
          <td data-label="Unit"><?= angka($g['qty']) ?></td>
          <?php if ($lihatNilai): ?>
            <td data-label="Nilai"><?= rupiah($g['nilai']) ?></td>
          <?php endif; ?></tr>
      <?php endforeach; endif; ?>
      </tbody>
    </table></div>
  </div>
</div>

<div class="dash-grid">
  <div class="card">
    <h3><?= $lihatNilai ? 'Ringkasan Nilai per Kategori' : 'Ringkasan per Kategori' ?></h3>
    <div class="sub">Jumlah<?= $lihatNilai ? ' &amp; nilai' : '' ?> persediaan</div>
    <div class="scrollx"><table class="tbl-kartu">
      <thead><tr><th>Kategori</th><th>Jml Barang</th><th>Total Stok</th>
        <?php if ($lihatNilai): ?><th>Nilai</th><?php endif; ?></tr></thead>
      <tbody>
      <?php foreach ($perKat as $k): ?>
        <tr><td data-label="Kategori"><b><?= e($k['nama']) ?></b></td>
          <td data-label="Jml Barang"><?= (int) $k['jml_barang'] ?></td>
          <td data-label="Total Stok"><?= angka($k['total_stok']) ?></td>
          <?php if ($lihatNilai): ?>
            <td data-label="Nilai"><?= rupiah($k['nilai']) ?></td>
          <?php endif; ?></tr>
      <?php endforeach; ?>
      </tbody>
    </table></div>
  </div>

  <?php if ($lihatArus): ?>
  <div class="card">
    <h3>Barang Paling Sering Keluar</h3><div class="sub">Akumulasi unit keluar</div>
    <div class="scrollx"><table class="tbl-kartu">
      <thead><tr><th>Barang</th><th>Total Keluar</th></tr></thead>
      <tbody>
      <?php if (!$topKeluar): ?>
        <tr><td colspan="2" class="empty">Belum ada transaksi keluar.</td></tr>
      <?php else: foreach ($topKeluar as $t): ?>
        <tr><td data-label="Barang"><b><?= e($t['nama']) ?></b></td><td data-label="Total Keluar"><?= angka($t['total']) ?> unit</td></tr>
      <?php endforeach; endif; ?>
      </tbody>
    </table></div>
  </div>
  <?php endif; ?>
</div>

<div class="dash-grid">
  <?php if ($lihatArus): ?>
  <div class="card">
    <h3>Perpindahan Bulan Ini</h3><div class="sub">Dokumen yang sudah disetujui</div>
    <div class="scrollx"><table class="tbl-kartu">
      <thead><tr><th>Jenis</th><th>Jumlah Dokumen</th></tr></thead>
      <tbody>
      <?php if (!$perpindahanBln): ?>
        <tr><td colspan="2" class="empty">Belum ada perpindahan disetujui bulan ini.</td></tr>
      <?php else: foreach ($perpindahanBln as $pb): [$lbl, $ico, $cls] = label_jenis($pb['jenis']); ?>
        <tr><td data-label="Jenis"><span class="tag <?= $cls ?>"><svg aria-hidden="true"><use href="#<?= e($ico) ?>"></use></svg> <?= e($lbl) ?></span></td>
          <td data-label="Jumlah Dokumen"><b><?= (int) $pb['jml'] ?></b> dokumen</td></tr>
      <?php endforeach; endif; ?>
      </tbody>
    </table></div>
  </div>
  <?php endif; ?>

  <?php /* Kedaluwarsa terlihat oleh SEMUA peran — barang kedaluwarsa adalah
           urusan siapa pun yang memegangnya. */ ?>
  <div class="card">
    <h3>Ringkasan Status Kedaluwarsa</h3><div class="sub">Dihitung per batch</div>
    <div class="scrollx"><table class="tbl-kartu">
      <thead><tr><th>Status</th><th>Jumlah Batch</th></tr></thead>
      <tbody>
        <tr><td data-label="Status"><span class="tag t-green">Aman</span></td><td data-label="Jumlah Batch"><?= angka($expMap['aman']) ?></td></tr>
        <tr><td data-label="Status"><span class="tag t-amber">Waspada</span></td><td data-label="Jumlah Batch"><?= angka($expMap['waspada']) ?></td></tr>
        <tr><td data-label="Status"><span class="tag t-orange">Segera expired</span></td><td data-label="Jumlah Batch"><?= angka($expMap['segera_expired']) ?></td></tr>
        <tr><td data-label="Status"><span class="tag t-red">Expired</span></td><td data-label="Jumlah Batch"><?= angka($expMap['expired']) ?></td></tr>
        <tr><td data-label="Status"><span class="tag t-gray">Tanpa expired</span></td><td data-label="Jumlah Batch"><?= angka($expMap['tanpa_expired']) ?></td></tr>
      </tbody>
    </table></div>
  </div>
</div>



<script src="assets/chart.umd.js"></script>
<script>
const KN = <?= json_encode(array_column($perKat, 'nama'), JSON_UNESCAPED_UNICODE) ?>;
const KS = <?= json_encode(array_map('intval', array_column($perKat, 'total_stok'))) ?>;
if (window.Chart) {
  const el = document.getElementById('chKat');
  if (el) new Chart(el, {
    type: 'bar',
    data: { labels: KN, datasets: [{ label: 'Total Stok', data: KS, backgroundColor: '#3B82F6', borderRadius: 8, maxBarThickness: 54 }] },
    options: { maintainAspectRatio: false, plugins: { legend: { display: false } },
               scales: { x: { grid: { display: false } },
                         y: { grid: { color: getComputedStyle(document.documentElement).getPropertyValue('--border') } } } }
  });
}
</script>
<?php require __DIR__ . '/_footer.php'; ?>
