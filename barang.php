<?php
/**
 * AMANAH — Master Data Barang
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/_lib.php';
require_once __DIR__ . '/_barang.php';
require_login();
tolak_supplier($pdo, "kelola_master");

/* ------------------------------------------------------------ POPUP TAMBAH
   Formulir tambah barang kini popup di halaman ini, jadi kiriman formulirnya
   ditangani di sini juga. Alasannya bukan kerapian: kalau POST-nya dikirim ke
   barang_form.php, satu galat validasi akan melempar orang ke halaman lain —
   sementara keenam halaman master data lain menahannya di tempat, dengan
   isian dan pesan galat masih di dalam popup. Perilaku yang sama di semua
   layar itulah yang membuat sistemnya bisa ditebak.

   Validasi & penyimpanannya sendiri ada di _barang.php, dipakai bersama
   dengan barang_form.php. */
/* Keduanya setingkat manager. $canEdit disetel di sini, bukan dibiarkan
   diisi _header.php, karena ia mengendalikan kolom Aksi — tombol Ubah dan
   Hapus. Bawaan dari header adalah boleh_edit(), yang juga benar untuk
   staff, dan staff tidak boleh menghapus master barang. */
$bolehTambah = boleh_kelola_master($pdo);
$canEdit     = boleh_kelola_master($pdo);
$errors  = [];
$pilihan = barang_pilihan($pdo);
$formB   = barang_kosong($pdo);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['act'] ?? '') === 'tambah_barang') {
    if (!$bolehTambah) { http_response_code(403); die('403'); }
    wajib_csrf($_POST['csrf'] ?? null);

    $formB  = array_merge($formB, barang_dari_post($_POST));
    $errors = barang_periksa($pdo, $formB, 0);

    if (!$errors) {
        try {
            [, $pesan] = barang_simpan($pdo, $formB, 0);
            set_flash('ok', $pesan);
            // Dialihkan supaya menekan F5 tidak menyimpan barang kedua.
            header('Location: barang.php'); exit;
        } catch (PDOException $e) {
            /* Harus DI ATAS cabang RuntimeException: PDOException adalah
               turunannya, dan pesan aslinya memuat nama tabel serta kolom —
               itu dicatat ke Galat Sistem, bukan ditampilkan. */
            $errors[] = pesan_error_aman($e, 'simpan barang');
        } catch (RuntimeException $e) {
            /* RuntimeException di sini adalah pesan UNTUK PENGGUNA — foto terlalu
               besar, format tidak didukung, folder unggahan tidak bisa ditulis.
               Dulu ikut tertelan cabang di bawah dan berubah jadi "Terjadi
               kesalahan… kode 3A9A4721": orangnya tidak tahu harus berbuat apa,
               padahal jawabannya cuma "perkecil fotonya". */
            $errors[] = pesan_runtime($e, 'barang');
        } catch (Throwable $e) {
            $errors[] = pesan_error_aman($e, 'simpan barang');
        }
    }
}

$q      = trim($_GET['q'] ?? '');
$katId  = (int) ($_GET['kategori'] ?? 0);
$gdgId  = (int) ($_GET['gudang'] ?? 0);
$status = trim($_GET['status'] ?? '');
$hal    = max(1, (int) ($_GET['hal'] ?? 1));
$perHal = 30;

$where = ['b.deleted_at IS NULL'];
$p = [];

if ($q !== '') {
    // PERBAIKAN v1: % dan _ yang diketik pengguna dulu diperlakukan
    // sebagai wildcard sehingga hasil pencarian membingungkan.
    $like = '%' . like_escape($q) . '%';
    $where[] = '(b.nama LIKE ? OR b.kode LIKE ? OR b.merek LIKE ? OR b.barcode LIKE ?)';
    array_push($p, $like, $like, $like, $like);
}
if ($katId > 0) { $where[] = 'b.kategori_id = ?'; $p[] = $katId; }
if ($gdgId > 0) {
    $where[] = 'EXISTS (SELECT 1 FROM batch_barang bb
                        JOIN lokasi_rak lr2 ON lr2.id = bb.lokasi_rak_id
                        WHERE bb.barang_id = b.id AND bb.qty_tersisa > 0 AND lr2.gudang_id = ?)';
    $p[] = $gdgId;
}
if ($status === 'habis')       $where[] = 'COALESCE(s.qty_total,0) = 0';
if ($status === 'hampir')      $where[] = 'COALESCE(s.qty_total,0) > 0 AND COALESCE(s.qty_total,0) <= b.stok_minimum';

$sqlWhere = 'WHERE ' . implode(' AND ', $where);

/* DIKELOMPOKKAN PER KATEGORI bila tidak sedang menyaring satu kategori.
   Daftar ratusan produk yang diurutkan kode saja terbaca sebagai satu
   gulungan panjang tanpa jeda; dengan kepala kategori, orang membaca
   "PAMPERS: 12 produk" lalu melompat — bukan menggulir. Di dalam
   kelompok urutannya tetap kode. */
$dikelompokkan = $katId === 0;
$urutSql = $dikelompokkan ? '(k.nama IS NULL), k.nama, b.kode' : 'b.kode';

$rows = []; $total = 0;
try {
    $stC = $pdo->prepare("SELECT COUNT(*) FROM barang b LEFT JOIN stok s ON s.barang_id = b.id $sqlWhere");
    $stC->execute($p);
    $total = (int) $stC->fetchColumn();

    $offset = ($hal - 1) * $perHal;
    $st = $pdo->prepare(
        "SELECT b.id, b.kode, b.barcode, b.nama, b.merek, b.ukuran, b.satuan, b.harga, b.stok_minimum, b.foto,
                k.nama AS kategori, lr.nama AS rak, g.nama AS gudang, sup.nama AS supplier,
                COALESCE(s.qty_total,0) AS stok
         FROM barang b
         LEFT JOIN kategori k    ON k.id   = b.kategori_id
         LEFT JOIN lokasi_rak lr ON lr.id  = b.lokasi_rak_id
         LEFT JOIN gudang g      ON g.id   = lr.gudang_id
         LEFT JOIN supplier sup  ON sup.id = b.supplier_id
         LEFT JOIN stok s        ON s.barang_id = b.id
         $sqlWhere
         ORDER BY $urutSql
         LIMIT $perHal OFFSET $offset"
    );
    $st->execute($p);
    $rows = $st->fetchAll();
} catch (Throwable $e) {
    error_log('[AMANAH] daftar barang: ' . $e->getMessage());
}

$totalHal = max(1, (int) ceil($total / $perHal));

$kategoriList = [];
$gudangList   = [];
try {
    /* Ikon & jumlah ikut dibawa untuk pil kategori di atas daftar. Jumlahnya
       dihitung atas SELURUH produk, bukan hasil saringan — pil yang berbunyi
       nol padahal isinya sebelas hanya menyesatkan. */
    $kategoriList = $pdo->query(
        "SELECT k.id, k.nama, k.ikon, COUNT(b.id) AS jml
         FROM kategori k LEFT JOIN barang b ON b.kategori_id = k.id AND b.deleted_at IS NULL
         WHERE k.deleted_at IS NULL GROUP BY k.id ORDER BY k.nama")->fetchAll();
    $totalSemua = (int) $pdo->query("SELECT COUNT(*) FROM barang WHERE deleted_at IS NULL")->fetchColumn();
    $gudangList   = $pdo->query(
        "SELECT id, nama FROM gudang WHERE deleted_at IS NULL
           AND id IN (" . in_gudang_saya($pdo) . ") ORDER BY kode")->fetchAll();
} catch (Throwable $e) { /* gudang belum dimigrasi */ }

/* =====================================================================
   IKHTISAR PER KATEGORI — tampilan awal saat tidak ada saringan apa pun.
   =====================================================================
   Memaginasi daftar yang dikelompokkan tidak menolong: pada 1.247 produk,
   halaman pertama hanya memuat dua kategori dan kategori kedua terpotong ke
   halaman berikutnya. Yang berguna justru melihat SEMUA kategori sekaligus,
   masing-masing dengan beberapa produk pertamanya dan tautan "lihat semua"
   yang membuka daftar berhalaman untuk kategori itu. Jadi:

     tanpa saringan          → ikhtisar (setiap kategori, maks 6 produk)
     satu kategori / cari    → daftar berhalaman seperti biasa

   Satu kueri kecil per kategori (≤ 20 kategori) dipilih ketimbang fungsi
   jendela ROW_NUMBER() yang belum tentu ada di MySQL hosting. */
$ikhtisar   = $katId === 0 && $q === '' && $gdgId === 0 && $status === '';
$ikhtisarN  = 6;
$jmlKategori = [];      // nama kategori → total produk (untuk kepala kelompok)
$idKategori  = [];      // nama kategori → id (untuk tautan "lihat semua")
if ($ikhtisar) {
    $rows = [];
    $totalHal = 1;
    try {
        $stG = $pdo->prepare(
            "SELECT b.id, b.kode, b.barcode, b.nama, b.merek, b.ukuran, b.satuan, b.harga, b.stok_minimum, b.foto,
                    k.nama AS kategori, lr.nama AS rak, g.nama AS gudang, sup.nama AS supplier,
                    COALESCE(s.qty_total,0) AS stok
             FROM barang b
             LEFT JOIN kategori k    ON k.id   = b.kategori_id
             LEFT JOIN lokasi_rak lr ON lr.id  = b.lokasi_rak_id
             LEFT JOIN gudang g      ON g.id   = lr.gudang_id
             LEFT JOIN supplier sup  ON sup.id = b.supplier_id
             LEFT JOIN stok s        ON s.barang_id = b.id
             WHERE b.deleted_at IS NULL AND b.kategori_id <=> ?
             ORDER BY b.kode LIMIT $ikhtisarN");
        foreach ($kategoriList as $k) {
            if ((int) $k['jml'] === 0) { continue; }
            $jmlKategori[$k['nama']] = (int) $k['jml'];
            $idKategori[$k['nama']]  = (int) $k['id'];
            $stG->execute([(int) $k['id']]);
            foreach ($stG->fetchAll() as $r) { $rows[] = $r; }
        }
        $tanpaKat = (int) $pdo->query("SELECT COUNT(*) FROM barang WHERE deleted_at IS NULL AND kategori_id IS NULL")->fetchColumn();
        if ($tanpaKat > 0) {
            $jmlKategori['Tanpa kategori'] = $tanpaKat;
            $stG->execute([null]);
            foreach ($stG->fetchAll() as $r) { $rows[] = $r; }
        }
    } catch (Throwable $e) {
        error_log('[AMANAH] ikhtisar barang: ' . $e->getMessage());
    }
}

$PAGE_TITLE = 'Data Barang'; $ACTIVE = 'barang';
require __DIR__ . '/_header.php';
?>
<div class="page-head">
  <div><h1>Data Barang</h1><p>Master seluruh perlengkapan bayi · <?= angka($total) ?> item</p></div>
  <?php if ($canEdit && $bolehTambah): ?>
    <?php /* href tetap menunjuk barang_form.php, bukan "#". Kalau JavaScript
             gagal dimuat, tombol ini masih membawa ke halaman formulir penuh —
             memasukkan barang baru tidak pernah jadi mustahil. */ ?>
    <a class="btn btn-primary" href="barang_form.php" onclick="bukaFormTambah(event)">
      <svg aria-hidden="true"><use href="#i-plus"></use></svg> Tambah Barang</a>
  <?php endif; ?>
</div>

<div class="card">
  <?php /* Pil kategori — jalan pintas yang paling sering dipakai. Parameter
           lain (cari, gudang, status) ikut dibawa supaya memilih kategori
           tidak membuang saringan yang sudah diatur. */
        $qsKat = array_filter(['q' => $q, 'gudang' => $gdgId ?: '', 'status' => $status], fn($v) => $v !== '' && $v !== 0); ?>
  <div class="saring kat-saring">
    <a class="<?= $katId === 0 ? 'on' : '' ?>" href="barang.php?<?= e(http_build_query($qsKat)) ?>">Semua <span class="n"><?= angka($totalSemua ?? $total) ?></span></a>
    <?php foreach ($kategoriList as $k): ?>
      <a class="<?= $katId === (int) $k['id'] ? 'on' : '' ?>" href="barang.php?<?= e(http_build_query($qsKat + ['kategori' => (int) $k['id']])) ?>">
        <?= $k['ikon'] ? e($k['ikon']) . ' ' : '' ?><?= e($k['nama']) ?> <span class="n"><?= angka($k['jml']) ?></span></a>
    <?php endforeach; ?>
  </div>
<div class="card">
  <form class="tools" method="get">
    <input type="text" name="q" value="<?= e($q) ?>" placeholder="Cari nama / kode / merek / barcode…" style="flex:1;min-width:180px">
    <select name="kategori">
      <option value="0">Semua Kategori</option>
      <?php foreach ($kategoriList as $k): ?>
        <option value="<?= (int) $k['id'] ?>" <?= $katId === (int) $k['id'] ? 'selected' : '' ?>><?= e($k['nama']) ?></option>
      <?php endforeach; ?>
    </select>
    <?php if ($gudangList): ?>
      <select name="gudang">
        <option value="0">Semua Gudang</option>
        <?php foreach ($gudangList as $g): ?>
          <option value="<?= (int) $g['id'] ?>" <?= $gdgId === (int) $g['id'] ? 'selected' : '' ?>><?= e($g['nama']) ?></option>
        <?php endforeach; ?>
      </select>
    <?php endif; ?>
    <select name="status">
      <option value="">Semua Status</option>
      <option value="habis"  <?= $status === 'habis'  ? 'selected' : '' ?>>Habis</option>
      <option value="hampir" <?= $status === 'hampir' ? 'selected' : '' ?>>Hampir habis</option>
    </select>
    <button class="btn btn-ghost" type="submit">Filter</button>
    <?php if ($q !== '' || $katId || $gdgId || $status !== ''): ?>
      <a class="btn btn-ghost" href="barang.php">Reset</a>
    <?php endif; ?>
  </form>

  <div class="scrollx"><table class="tbl-kartu">
    <thead><tr>
      <th>Barang</th><th>Kode</th><th>Kategori</th><th>Stok</th><th>Status</th>
      <th>Lokasi</th><th>Supplier</th><th>Harga</th>
      <?php if ($canEdit): ?><th>Aksi</th><?php endif; ?>
    </tr></thead>
    <tbody>
    <?php if (!$rows): ?>
      <tr><td colspan="<?= $canEdit ? 9 : 8 ?>" class="empty">
        Tidak ada data barang<?= ($q !== '' || $katId || $gdgId || $status !== '') ? ' untuk filter ini' : '' ?>.
      </td></tr>
    <?php else:
      /* Jumlah per kategori: total sebenarnya pada ikhtisar, jumlah di
         halaman ini pada daftar berhalaman. */
      $jmlGrup = [];
      foreach ($rows as $b) { $g = $b['kategori'] ?: 'Tanpa kategori'; $jmlGrup[$g] = ($jmlGrup[$g] ?? 0) + 1; }
      $tampilGrup = $jmlGrup;
      if ($ikhtisar) { $jmlGrup = $jmlKategori + $jmlGrup; }
      $grupKini = null;
      foreach ($rows as $idx => $b):
        [$s, $sc] = status_stok((int) $b['stok'], (int) $b['stok_minimum']);
        $grupIni = $b['kategori'] ?: 'Tanpa kategori';
        $slug = preg_replace('/[^a-z0-9]+/', '-', strtolower($grupIni));
        if ($dikelompokkan && $grupIni !== $grupKini): $grupKini = $grupIni;
          $ikonG = '';
          foreach ($kategoriList as $k) { if ($k['nama'] === $grupIni) { $ikonG = (string) $k['ikon']; break; } } ?>
      <tr class="grup-kat" data-grup="<?= e($slug) ?>">
        <td colspan="<?= $canEdit ? 9 : 8 ?>">
          <button type="button" class="grup-toggle" onclick="lipatGrup(this)" aria-expanded="true">
            <svg aria-hidden="true" class="grup-panah"><use href="#i-bawah"></use></svg>
            <?= $ikonG !== '' ? e($ikonG) . ' ' : '' ?><?= e($grupIni) ?>
            <span class="n"><?= angka($jmlGrup[$grupIni] ?? 0) ?> produk</span>
          </button>
        </td>
      </tr>
        <?php endif; ?>
      <tr data-grup="<?= e($slug) ?>">
        <td class="judul" data-label="Barang"><div style="display:flex;align-items:center;gap:9px">
          <?php $fotoUrl = url_unggahan((string) ($b['foto'] ?? '')); ?>
          <?php /* Foto produk bila ada; kotak berikon bila belum. Placeholder
                   dibuat SEUKURAN fotonya, bukan emoji kecil, supaya baris
                   yang sudah berfoto dan yang belum tetap sama tingginya —
                   daftar yang barisnya naik-turun jauh lebih sulit dipindai. */ ?>
          <?php if ($fotoUrl !== ''): ?>
            <img class="thumb" src="<?= e($fotoUrl) ?>" alt="" loading="lazy"
                 width="38" height="38" style="object-fit:cover;border-radius:9px">
          <?php else: ?>
            <span class="thumb">📦</span>
          <?php endif; ?>
          <div><a href="barang_detail.php?id=<?= (int) $b['id'] ?>" class="tautan-judul"><b><?= e($b['nama']) ?></b></a><br>
            <small class="mut"><?= e($b['merek'] ?: '-') ?> · <?= e($b['ukuran'] ?: '-') ?></small></div>
        </div></td>
        <td class="sekunder" data-label="Kode"><code><?= e($b['kode']) ?></code></td>
        <td class="sekunder" data-label="Kategori"><?= e($b['kategori'] ?: '—') ?></td>
        <td data-label="Stok"><b><?= angka($b['stok']) ?></b> <?= e($b['satuan']) ?>
          <br><small class="mut">min <?= angka($b['stok_minimum']) ?></small></td>
        <td data-label="Status"><span class="tag <?= $sc ?>"><?= $s ?></span></td>
        <td class="sekunder" data-label="Lokasi"><?= e($b['rak'] ?: '—') ?>
          <?php if ($b['gudang']): ?><br><small class="mut"><?= e($b['gudang']) ?></small><?php endif; ?>
        </td>
        <td class="sekunder" data-label="Supplier"><?= e($b['supplier'] ?: '—') ?></td>
        <td data-label="Harga"><?= rupiah($b['harga']) ?></td>
        <?php if ($canEdit): ?>
        <td class="nowrap aksi">
          <?php /* Stok dipisahkan dari Edit karena keduanya mengubah hal yang
                   berbeda: Edit mengubah IDENTITAS produk (nama, kode, harga),
                   Stok mengubah JUMLAH BARANG yang benar-benar ada di rak.
                   Menyatukannya dalam satu formulir membuat kolom jumlah
                   duduk bersebelahan dengan kolom nama, dan satu simpan yang
                   tidak sengaja bisa mengubah stok saat yang diniatkan hanya
                   membetulkan ejaan. */ ?>
          <a class="btn btn-ghost btn-sm" href="barang_stok.php?id=<?= (int) $b['id'] ?>">Stok</a>
          <a class="btn btn-ghost btn-sm" href="barang_form.php?id=<?= (int) $b['id'] ?>">Edit</a>
          <form method="post" action="barang_hapus.php" class="inline" data-konfirm="Hapus barang ini?" data-obj="<?= e($b['nama']) ?>" data-info="<?= e($b['kode']) ?>">
            <input type="hidden" name="id" value="<?= (int) $b['id'] ?>">
            <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
            <button class="btn btn-red btn-sm" type="submit">Hapus</button>
          </form>
        </td>
        <?php endif; ?>
      </tr>
      <?php /* Ikhtisar: bila kategori ini punya lebih dari yang ditampilkan,
               baris terakhirnya diikuti tautan ke daftar lengkap kategori itu. */
        $grupBerikut = isset($rows[$idx + 1]) ? ($rows[$idx + 1]['kategori'] ?: 'Tanpa kategori') : null;
        if ($ikhtisar && $grupBerikut !== $grupIni && ($jmlGrup[$grupIni] ?? 0) > ($tampilGrup[$grupIni] ?? 0)): ?>
      <tr data-grup="<?= e($slug) ?>" class="grup-lanjut">
        <td colspan="<?= $canEdit ? 9 : 8 ?>">
          <a href="barang.php?kategori=<?= (int) ($idKategori[$grupIni] ?? 0) ?>">
            Lihat semua <?= angka($jmlGrup[$grupIni]) ?> produk <?= e($grupIni) ?> →
          </a>
          <small class="mut"><?= angka($tampilGrup[$grupIni]) ?> pertama ditampilkan di sini</small>
        </td>
      </tr>
        <?php endif; ?>
    <?php endforeach; endif; ?>
    </tbody>
  </table></div>

  <?php if ($totalHal > 1): ?>
    <div style="display:flex;gap:6px;justify-content:center;align-items:center;margin-top:16px;flex-wrap:wrap">
      <?php $qs = $_GET; for ($i = 1; $i <= $totalHal; $i++):
        if ($totalHal > 9 && $i > 3 && $i < $totalHal - 2 && abs($i - $hal) > 1) {
            if ($i === 4) echo '<span class="mut">…</span>';
            continue;
        }
        $qs['hal'] = $i; ?>
        <a class="btn <?= $i === $hal ? 'btn-primary' : 'btn-ghost' ?> btn-sm"
           href="?<?= e(http_build_query($qs)) ?>"><?= $i ?></a>
      <?php endfor; ?>
      <span style="color:var(--muted);font-size:12px;margin-left:8px">Halaman <?= $hal ?> dari <?= $totalHal ?></span>
    </div>
  <?php endif; ?>
</div>

<?php if ($canEdit && $bolehTambah):
  /* Panel popup Tambah Barang. Isiannya dari _barang_isian.php — partial yang
     sama dengan halaman penuh, jadi kolom yang ditambahkan nanti muncul di
     kedua layar tanpa disalin dua kali. */
  $b        = $formB;
  $b['id']  = 0;
  $isEdit   = false;
  $aksiUrl  = 'barang.php';
  $aksiNama = 'tambah_barang';
?>
<div class="card form-lipat<?= $errors ? ' terbuka' : '' ?>" id="formTambah">
  <div class="modal-kepala">
    <div>
      <h3>Tambah Barang</h3>
      <div class="sub">Barang baru untuk master data</div>
    </div>
    <a class="modal-tutup" href="barang.php" data-tutup-modal
       aria-label="Tutup formulir" title="Tutup (Esc)">&times;</a>
  </div>

  <?php /* Galat validasi di DALAM panel — di badan halaman ia akan tertutup
           lapisan gelap dan tak terbaca. */ ?>
  <?php if ($errors): ?>
    <div class="flash flash-err" data-tetap="1">
      <?php foreach ($errors as $er) echo '• ' . e($er) . '<br>'; ?>
    </div>
  <?php endif; ?>

  <?php require __DIR__ . '/_barang_isian.php'; ?>
</div>
<?php endif; ?>
<?php require __DIR__ . '/_footer.php'; ?>
<script>
/* Melipat/membuka satu kelompok kategori. Yang dilipat diingat di
   localStorage supaya kategori yang jarang dilihat tetap tertutup pada
   kunjungan berikutnya — bukan dibuka ulang tiap muat halaman. */
function lipatGrup(btn){
  var tr = btn.closest('tr'), g = tr.dataset.grup;
  var buka = btn.getAttribute('aria-expanded') !== 'true';
  btn.setAttribute('aria-expanded', buka ? 'true' : 'false');
  document.querySelectorAll('tr[data-grup="' + g + '"]:not(.grup-kat)').forEach(function(r){ r.classList.toggle('terlipat', !buka); });
  try {
    var s = JSON.parse(localStorage.getItem('amanah_grup_lipat') || '[]');
    s = s.filter(function(x){ return x !== g; }); if (!buka) s.push(g);
    localStorage.setItem('amanah_grup_lipat', JSON.stringify(s));
  } catch (e) {}
}
(function(){
  try {
    var s = JSON.parse(localStorage.getItem('amanah_grup_lipat') || '[]');
    s.forEach(function(g){
      var b = document.querySelector('tr.grup-kat[data-grup="' + g + '"] .grup-toggle');
      if (b) { b.setAttribute('aria-expanded', 'true'); lipatGrup(b); }
    });
  } catch (e) {}
})();
</script>
