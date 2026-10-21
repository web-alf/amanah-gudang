<?php
/**
 * AMANAH — Master Donatur
 *
 * Sisi hulu rantai barang, yang sebelumnya tidak punya wujud:
 *     Donatur → Supplier/Mitra → Gudang → Toko / Penerima
 *
 * Sebelum halaman ini ada, asal barang titipan hanya berupa teks bebas di
 * kolom `perpindahan.asal_teks` — "Donatur perorangan", "Hamba Allah",
 * "komunitas ibu-ibu". Akibatnya pertanyaan yang paling wajar ditanyakan
 * sebuah lembaga penerima titipan — siapa yang sudah membantu kita, sudah
 * berapa kali, dan kapan terakhir — tidak punya jawaban sama sekali.
 *
 * PERINGKAT: "paling sering" dan "paling banyak" adalah dua hal berbeda,
 * dan keduanya berbeda lagi dari "paling rajin". Satu donatur yang datang
 * sekali membawa sepuluh jenis barang bukan sepuluh kali memberi. Karena
 * itu donasi disimpan berkepala (tabel `donasi`) dan halaman ini mengurut
 * dari view v_peringkat_donatur yang menghitung ketiganya terpisah.
 *
 * PRIVASI — dibaca sebelum menyunting berkas ini:
 *   • Nomor telepon, email, dan alamat hanya tampil bagi yang
 *     berkepentingan; selain itu disamarkan. Lihat
 *     boleh_lihat_kontak_donatur() di _lib.php.
 *   • Kolom `anonim` dihormati di SETIAP tampilan, bukan hanya laporan
 *     publik. Donatur yang minta tidak disebut namanya tidak sedang minta
 *     namanya disembunyikan dari orang luar saja.
 *   • Mitra tidak melihat daftar donatur mitra lain.
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/_lib.php';
require_login();

/* Toko tidak berurusan dengan donatur — barang sudah jadi stok jauh
   sebelum sampai ke toko. Supplier justru WAJIB bisa membuka halaman ini:
   dialah yang menerima titipan dan mendaftarkan donaturnya. */
if (is_toko()) {
    http_response_code(403);
    die('<p style="font-family:sans-serif;padding:30px">403 — Halaman ini tidak termasuk lingkup akun toko. '
      . '<a href="dasbor_toko.php">Kembali</a></p>');
}

$supplierSaya  = supplier_saya();
/* SENGAJA BUKAN $canEdit. _header.php baris 26 menyetel ulang $canEdit
   menjadi boleh_edit() saat ia di-require di bawah, jadi nilai apa pun yang
   ditulis halaman ini sebelum itu akan hilang tanpa jejak. Halaman lain
   tidak pernah tersandung karena semuanya kebetulan mengisi $canEdit dengan
   boleh_edit() juga — halaman inilah yang pertama membutuhkan nilai berbeda,
   karena di sini SUPPLIER pun boleh mengelola.

   barang.php justru BERGANTUNG pada _header.php yang menyetelnya, jadi baris
   di _header.php tidak bisa sekadar dibuang. Nama yang berbeda memutus
   tabrakannya tanpa menyentuh halaman lain. */
$bolehKelola = boleh_kelola_master($pdo) || is_supplier();
$bolehHapus  = is_supplier() ? true : boleh_hapus($pdo);
$lihatKontak = boleh_lihat_kontak_donatur($pdo);
$lihatNilai  = boleh_lihat_nilai($pdo);

$errors = [];
$editId = (int) ($_GET['edit'] ?? 0);
$buka   = isset($_GET['tambah']);

$JENIS = [
    'pribadi'   => 'Pribadi (perorangan)',
    'komunitas' => 'Komunitas / kelompok',
    'lembaga'   => 'Lembaga / perusahaan',
];

/* Pilihan pengurutan. Inilah yang menjawab "donatur mana yang paling
   rajin / paling sering / paling banyak" — tiga pertanyaan, tiga kolom
   berbeda, bukan satu angka yang dipaksa mewakili semuanya. */
$URUT = [
    'nama'   => ['Nama (A–Z)',                'd.nama ASC'],
    'sering' => ['Paling sering memberi',     'v.jml_donasi DESC, v.total_qty DESC'],
    'kunjung'=> ['Paling sering berkunjung',  'v.jml_kunjungan DESC, v.jml_donasi DESC'],
    'banyak' => ['Paling banyak barang',      'v.total_qty DESC, v.jml_donasi DESC'],
    'nilai'  => ['Paling besar taksirannya',  'v.total_nilai DESC, v.total_qty DESC'],
    'rajin'  => ['Paling rajin (per bulan)',  'v.rata_per_bulan DESC, v.jml_donasi DESC'],
    'baru'   => ['Terakhir memberi',          'v.donasi_terakhir DESC'],
    'lama'   => ['Paling lama tidak memberi', 'v.donasi_terakhir ASC'],
];

$row = ['id' => 0, 'kode' => '', 'nama' => '', 'jenis' => 'pribadi', 'wilayah' => '',
        'alamat' => '', 'telepon' => '', 'email' => '', 'kontak_pic' => '',
        'jml_anggota' => '', 'anonim' => 0, 'keterangan' => '', 'is_active' => 1];

// ==================================================================== AKSI
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$bolehKelola) { http_response_code(403); die('403'); }
    wajib_csrf($_POST['csrf'] ?? null);
    $aksi = $_POST['aksi'] ?? '';

    if ($aksi === 'simpan') {
        $id       = (int) ($_POST['id'] ?? 0);
        $kode     = trim($_POST['kode'] ?? '');
        $nama     = trim($_POST['nama'] ?? '');
        $jenis    = array_key_exists($_POST['jenis'] ?? '', $JENIS) ? $_POST['jenis'] : 'pribadi';
        $wilayah  = trim($_POST['wilayah'] ?? '');
        $alamat   = trim($_POST['alamat'] ?? '');
        $telepon  = trim($_POST['telepon'] ?? '');
        $email    = trim($_POST['email'] ?? '');
        $pic      = trim($_POST['kontak_pic'] ?? '');
        $anggota  = (int) ($_POST['jml_anggota'] ?? 0);
        $anonim   = isset($_POST['anonim']) ? 1 : 0;
        $ket      = trim($_POST['keterangan'] ?? '');
        $aktif    = isset($_POST['is_active']) ? 1 : 0;

        if ($kode === '') { $errors[] = 'Kode wajib diisi.'; }
        if ($nama === '') { $errors[] = 'Nama donatur wajib diisi.'; }
        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'Format email tidak sah.';
        }

        if (!$errors) {
            $c = $pdo->prepare("SELECT id FROM donatur WHERE kode = ? AND id <> ?");
            $c->execute([$kode, $id]);
            if ($c->fetch()) { $errors[] = 'Kode itu sudah dipakai donatur lain.'; }
        }

        /* Mitra hanya boleh menyunting donatur yang ia daftarkan sendiri.
           Tanpa pemeriksaan ini, menebak angka di ?edit= sudah cukup untuk
           membuka data pribadi donatur mitra lain. */
        if (!$errors && $id > 0 && is_supplier()) {
            $c = $pdo->prepare("SELECT supplier_id FROM donatur WHERE id = ?");
            $c->execute([$id]);
            $pemilik = $c->fetchColumn();
            if ($pemilik !== false && $pemilik !== null && (int) $pemilik !== (int) $supplierSaya) {
                http_response_code(403);
                die('403 — donatur ini didaftarkan mitra lain.');
            }
        }

        if (!$errors) {
            try {
                if ($id > 0) {
                    $pdo->prepare(
                        "UPDATE donatur SET kode=?, nama=?, jenis=?, wilayah=?, alamat=?, telepon=?,
                                email=?, kontak_pic=?, jml_anggota=?, anonim=?, keterangan=?, is_active=?
                         WHERE id=?"
                    )->execute([$kode, $nama, $jenis, $wilayah ?: null, $alamat ?: null,
                                $telepon ?: null, $email ?: null, $pic ?: null,
                                $anggota ?: null, $anonim, $ket ?: null, $aktif, $id]);
                    // Nama donatur TIDAK ikut dicatat di log: log aktivitas
                    // dibaca banyak orang, dan menuliskan nama di sana
                    // membocorkan justru yang sedang dijaga halaman ini.
                    catat_aktivitas($pdo, 'update', 'donatur', $id, "Ubah donatur #$id");
                    set_flash('ok', 'Data donatur diperbarui.');
                } else {
                    $pdo->prepare(
                        "INSERT INTO donatur (kode, nama, jenis, supplier_id, wilayah, alamat,
                                telepon, email, kontak_pic, jml_anggota, anonim, keterangan, is_active)
                         VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)"
                    )->execute([$kode, $nama, $jenis, $supplierSaya, $wilayah ?: null, $alamat ?: null,
                                $telepon ?: null, $email ?: null, $pic ?: null,
                                $anggota ?: null, $anonim, $ket ?: null, $aktif]);
                    $baru = (int) $pdo->lastInsertId();
                    catat_aktivitas($pdo, 'create', 'donatur', $baru, "Tambah donatur #$baru");
                    set_flash('ok', 'Donatur ditambahkan. Sekarang bisa dipilih saat mencatat barang masuk.');
                }
                header('Location: donatur.php'); exit;
            } catch (Throwable $e) {
                $errors[] = pesan_error_aman($e, 'simpan donatur');
            }
        }

        $row = ['id' => $id, 'kode' => $kode, 'nama' => $nama, 'jenis' => $jenis,
                'wilayah' => $wilayah, 'alamat' => $alamat, 'telepon' => $telepon,
                'email' => $email, 'kontak_pic' => $pic, 'jml_anggota' => $anggota ?: '',
                'anonim' => $anonim, 'keterangan' => $ket, 'is_active' => $aktif];
        $editId = $id;
    }

    if ($aksi === 'hapus') {
        if (!$bolehHapus) { http_response_code(403); die('403 — penghapusan hanya untuk manager.'); }
        $id = (int) ($_POST['id'] ?? 0);
        try {
            /* Hapus lunak. Riwayat pemberiannya menjadi dasar laporan
               pertanggungjawaban, dan laporan yang barisnya bisa hilang
               karena satu penghapusan di halaman lain tidak bisa dipercaya.
               Kunci asing donasi → donatur memang RESTRICT untuk itu. */
            $st = $pdo->prepare(
                "UPDATE donatur SET deleted_at = NOW(), is_active = 0
                 WHERE id = ?" . (is_supplier() ? " AND supplier_id = " . (int) $supplierSaya : '')
            );
            $st->execute([$id]);
            catat_aktivitas($pdo, 'delete', 'donatur', $id, "Hapus donatur #$id");
            set_flash('ok', 'Donatur dihapus. Riwayat pemberiannya tetap tersimpan.');
        } catch (Throwable $e) {
            set_flash('err', pesan_error_aman($e, 'hapus donatur'));
        }
        header('Location: donatur.php'); exit;
    }
}

// ==================================================================== DATA
if ($editId > 0 && !$errors) {
    $sql = "SELECT * FROM donatur WHERE id = ? AND deleted_at IS NULL";
    $par = [$editId];
    if (is_supplier()) { $sql .= " AND (supplier_id = ? OR supplier_id IS NULL)"; $par[] = $supplierSaya; }
    $st = $pdo->prepare($sql);
    $st->execute($par);
    if ($f = $st->fetch()) { $row = $f; }
} elseif (!$errors && !$row['kode']) {
    $row['kode'] = kode_berikut($pdo, 'donatur', 'DN');
}

// ------------------------------------------------------------- penyaringan
$cari    = trim($_GET['q'] ?? '');
$fJenis  = array_key_exists($_GET['jenis'] ?? '', $JENIS) ? $_GET['jenis'] : '';
$fWil    = trim($_GET['wilayah'] ?? '');
$fAktif  = $_GET['aktif'] ?? '';
$fUrut   = array_key_exists($_GET['urut'] ?? '', $URUT) ? $_GET['urut'] : 'nama';
$adaSaring = ($cari !== '' || $fJenis !== '' || $fWil !== '' || $fAktif !== '' || $fUrut !== 'nama');

$where = ['d.deleted_at IS NULL'];
$par   = [];
if ($cari !== '') {
    $like = '%' . like_escape($cari) . '%';
    $where[] = '(d.nama LIKE ? OR d.kode LIKE ? OR d.wilayah LIKE ? OR d.kontak_pic LIKE ?)';
    array_push($par, $like, $like, $like, $like);
}
if ($fJenis !== '') { $where[] = 'd.jenis = ?';   $par[] = $fJenis; }
if ($fWil !== '')   { $where[] = 'd.wilayah = ?'; $par[] = $fWil; }
if ($fAktif === '1') { $where[] = 'd.is_active = 1'; }
if ($fAktif === '0') { $where[] = 'd.is_active = 0'; }
// Mitra hanya melihat donaturnya sendiri dan yang tak terikat mitra.
if (is_supplier()) { $where[] = '(d.supplier_id = ? OR d.supplier_id IS NULL)'; $par[] = $supplierSaya; }

/* Ringkasan dihitung atas SELURUH hasil saringan (SQL), daftarnya dipaginasi. */
$perHal = 30; $totDonatur = 0; $totQty = 0; $totNilai = 0.0; $hal = 1; $offset = 0; $jmlHal = 1;
try {
    $stT = $pdo->prepare(
        "SELECT COUNT(*), COALESCE(SUM(v.total_qty),0), COALESCE(SUM(v.total_nilai),0)
         FROM donatur d LEFT JOIN v_peringkat_donatur v ON v.donatur_id = d.id
         WHERE " . implode(' AND ', $where));
    $stT->execute($par);
    [$totDonatur, $totQty, $totNilai] = array_map(fn($x) => $x + 0, $stT->fetch(PDO::FETCH_NUM) ?: [0, 0, 0]);
    [$hal, $offset, $jmlHal] = paginasi_hitung((int) $totDonatur, $perHal);
} catch (Throwable $e) { error_log('[AMANAH] donatur ringkas: ' . $e->getMessage()); }

$daftar = [];
try {
    $st = $pdo->prepare(
        "SELECT d.*, v.jml_donasi, v.total_qty, v.total_nilai, v.jml_jenis_barang,
                v.jml_kunjungan, v.jml_paket,
                v.donasi_pertama, v.donasi_terakhir, v.hari_sejak_terakhir, v.rata_per_bulan
         FROM donatur d
         LEFT JOIN v_peringkat_donatur v ON v.donatur_id = d.id
         WHERE " . implode(' AND ', $where) . "
         ORDER BY " . $URUT[$fUrut][1] . " LIMIT $perHal OFFSET $offset"
    );
    $st->execute($par);
    $daftar = $st->fetchAll();
} catch (Throwable $e) {
    error_log('[AMANAH] donatur: ' . $e->getMessage());
    $errors[] = pesan_error_aman($e, 'memuat daftar donatur');
}

// Pilihan wilayah untuk penyaring diambil dari data yang ADA, bukan
// daftar tetap — wilayah diketik bebas, jadi daftar tetap akan salah.
$wilayahOpsi = [];
try {
    $sqlW = "SELECT DISTINCT wilayah FROM donatur
             WHERE deleted_at IS NULL AND wilayah IS NOT NULL AND wilayah <> ''"
          . (is_supplier() ? " AND (supplier_id = " . (int) $supplierSaya . " OR supplier_id IS NULL)" : '')
          . " ORDER BY wilayah";
    $wilayahOpsi = $pdo->query($sqlW)->fetchAll(PDO::FETCH_COLUMN);
} catch (Throwable $e) { /* penyaring opsional; daftar kosong tidak merusak halaman */ }

/* Donatur yang berbagi nomor HP dengan donatur lain.
 *
 * Menurut aturannya ini memang sah — nama berbeda berarti orang berbeda.
 * Tapi jauh lebih sering penyebabnya nama yang diketik berbeda untuk orang
 * yang sama, dan akibatnya riwayat pemberiannya terpecah dua: keduanya
 * terlihat jarang memberi padahal satu orang itu rajin.
 *
 * Peringatan saat menyimpan hanya muncul sebagai toast enam detik, dan
 * pesan sependek itu tidak cukup untuk sesuatu yang perlu ditindaklanjuti.
 * Karena itu diulang DI SINI — di halaman tempat orang memperbaikinya. */
$nomorKembar = [];
$petaNomor   = [];
foreach ($daftar as $d) {
    $k = kunci_telepon($d['telepon'] ?? '');
    if ($k === '') { continue; }
    $petaNomor[$k][] = $d;
}
foreach ($petaNomor as $k => $grup) {
    if (count($grup) > 1) { $nomorKembar[$k] = $grup; }
}

$totDonatur = (int) $totDonatur; $totQty = (int) $totQty; $totNilai = (float) $totNilai;
$belumPernah = count(array_filter($daftar, fn($d) => (int) $d['jml_donasi'] === 0));
$menghilang  = count(array_filter($daftar,
    fn($d) => (int) $d['jml_donasi'] > 0 && (int) $d['hari_sejak_terakhir'] > 180));

$PAGE_TITLE = 'Donatur'; $ACTIVE = 'donatur';
require __DIR__ . '/_header.php';
?>
<div class="page-head">
  <div><h1>Donatur</h1>
    <p>Sisi hulu rantai barang — siapa yang menitipkan, sudah berapa kali, dan kapan terakhir</p></div>
  <?php if ($bolehKelola): ?>
    <div class="aksi">
      <a class="btn btn-primary" href="#formTambah" onclick="bukaFormTambah(event)">
        <svg aria-hidden="true"><use href="#i-plus"></use></svg> Tambah Donatur
      </a>
    </div>
  <?php endif; ?>
</div>

<div class="alert" style="--ac:var(--info);margin-bottom:16px">
  <span class="alert-ic"><svg aria-hidden="true"><use href="#i-info"></use></svg></span>
  <div class="alert-tx">
    <b>Data di sini bersifat pribadi</b>
    <small>Nama, nomor telepon, dan alamat donatur hanya untuk keperluan internal. Jangan
      membagikan tangkapan layar halaman ini ke grup terbuka, dan samarkan namanya saat
      meneruskan percakapan. Donatur bertanda &ldquo;minta anonim&rdquo; ditampilkan sebagai
      &ldquo;Hamba Allah&rdquo; pada daftar, laporan, dan cetakan — tetapi nama aslinya tetap
      terlihat pada pemilih donatur saat mencatat barang masuk, supaya titipan tidak
      tercatat ke orang yang salah.
      <?php if (!$lihatKontak): ?>
        Nomor telepon disamarkan untuk akun Anda.
      <?php endif; ?>
    </small>
  </div>
</div>

<?php if ($nomorKembar): ?>
  <div class="alert" style="--ac:var(--amber);margin-bottom:16px">
    <span class="alert-ic"><svg aria-hidden="true"><use href="#i-alert"></use></svg></span>
    <div class="alert-tx">
      <b><?= count($nomorKembar) ?> nomor HP dipakai lebih dari satu donatur</b>
      <small>
        Ini sah bila memang orang yang berbeda — misalnya satu keluarga memakai satu nomor.
        Tapi lebih sering penyebabnya nama yang tertulis berbeda untuk orang yang sama, dan
        akibatnya riwayat pemberiannya terpecah: keduanya terlihat jarang memberi padahal
        satu orang itu rajin. Samakan namanya bila memang orang yang sama.
        <?php foreach ($nomorKembar as $nomor => $grup): ?>
          <br>&bull; <?= $lihatKontak ? e($nomor) : e(samarkan_telepon($nomor)) ?> —
          <?php $nm = [];
                foreach ($grup as $g) { $nm[] = $g['kode'] . ' (' . nama_donatur_tampil($g) . ')'; }
                echo e(implode(' · ', $nm)); ?>
        <?php endforeach; ?>
      </small>
    </div>
  </div>
<?php endif; ?>

<div class="kpi">
  <div class="kpi-card" style="--kc:var(--primary-2)">
    <div class="kpi-top"><span class="kpi-l">Donatur Terdaftar</span>
      <span class="kpi-ic"><svg aria-hidden="true"><use href="#i-users"></use></svg></span></div>
    <div class="kpi-v"><?= angka($totDonatur) ?></div>
    <div class="kpi-d"><?= $adaSaring ? 'Sesuai penyaring' : 'Seluruhnya' ?></div>
  </div>
  <div class="kpi-card" style="--kc:var(--green)">
    <div class="kpi-top"><span class="kpi-l">Unit Diterima</span>
      <span class="kpi-ic"><svg aria-hidden="true"><use href="#i-masuk"></use></svg></span></div>
    <div class="kpi-v"><?= angka($totQty) ?></div>
    <div class="kpi-d">Dari seluruh donasi</div>
  </div>
  <?php if ($lihatNilai): ?>
    <div class="kpi-card" style="--kc:var(--accent)">
      <div class="kpi-top"><span class="kpi-l">Taksiran Nilai</span>
        <span class="kpi-ic"><svg aria-hidden="true"><use href="#i-uang"></use></svg></span></div>
      <div class="kpi-v kecil"><?= rupiah($totNilai) ?></div>
      <div class="kpi-d">Taksiran internal, bukan nilai pajak</div>
    </div>
  <?php endif; ?>
  <div class="kpi-card<?= $menghilang > 0 ? ' perlu' : '' ?>" style="--kc:var(--amber)">
    <div class="kpi-top"><span class="kpi-l">Lama Tidak Memberi</span>
      <span class="kpi-ic"><svg aria-hidden="true"><use href="#i-clock"></use></svg></span></div>
    <div class="kpi-v"><?= angka($menghilang) ?></div>
    <div class="kpi-d">Lewat 6 bulan · <?= angka($belumPernah) ?> belum pernah</div>
  </div>
</div>

<div class="tumpuk">
  <div class="card">
    <h3>Daftar Donatur</h3>
    <div class="sub">
      <?= e($URUT[$fUrut][0]) ?> · <?= angka($totDonatur) ?> donatur
    </div>

    <?php /* Penyaring memakai GET supaya hasilnya bisa ditandai dan
             dibagikan sebagai tautan — dan supaya menekan Kembali
             mengembalikan penyaring sebelumnya, bukan mengosongkannya. */ ?>
    <form class="tools" method="get">
      <input name="q" value="<?= e($cari) ?>" placeholder="Cari nama, kode, wilayah, PIC…"
             style="flex:1;min-width:170px">
      <select name="jenis" style="min-width:150px">
        <option value="">Semua jenis</option>
        <?php foreach ($JENIS as $k => $lbl): ?>
          <option value="<?= $k ?>" <?= $fJenis === $k ? 'selected' : '' ?>><?= e($lbl) ?></option>
        <?php endforeach; ?>
      </select>
      <?php if ($wilayahOpsi): ?>
        <select name="wilayah" style="min-width:140px">
          <option value="">Semua wilayah</option>
          <?php foreach ($wilayahOpsi as $w): ?>
            <option value="<?= e($w) ?>" <?= $fWil === $w ? 'selected' : '' ?>><?= e($w) ?></option>
          <?php endforeach; ?>
        </select>
      <?php endif; ?>
      <select name="aktif" style="min-width:120px">
        <option value="">Aktif &amp; nonaktif</option>
        <option value="1" <?= $fAktif === '1' ? 'selected' : '' ?>>Hanya aktif</option>
        <option value="0" <?= $fAktif === '0' ? 'selected' : '' ?>>Hanya nonaktif</option>
      </select>
      <select name="urut" style="min-width:190px">
        <?php foreach ($URUT as $k => $u): ?>
          <option value="<?= $k ?>" <?= $fUrut === $k ? 'selected' : '' ?>><?= e($u[0]) ?></option>
        <?php endforeach; ?>
      </select>
      <button class="btn btn-ghost" type="submit">
        <svg aria-hidden="true"><use href="#i-saring"></use></svg> Terapkan
      </button>
      <?php if ($adaSaring): ?><a class="btn btn-ghost" href="donatur.php">Reset</a><?php endif; ?>
    </form>

    <?php if (!$daftar): ?>
      <div class="empty-state">
        <div class="ic"><svg aria-hidden="true"><use href="#i-users"></use></svg></div>
        <b><?= $adaSaring ? 'Tidak ada yang cocok' : 'Belum ada donatur terdaftar' ?></b>
        <p><?= $adaSaring
              ? 'Coba ubah penyaringnya, atau tambahkan donatur baru.'
              : 'Daftarkan donatur lebih dulu supaya titipan bisa dilacak per pemberi — bukan sekadar tercatat sebagai "barang donasi".' ?></p>
        <?php if ($bolehKelola): ?>
          <div class="aksi">
            <button type="button" class="btn btn-primary btn-sm" onclick="bukaFormTambah(event)">
              <svg aria-hidden="true"><use href="#i-plus"></use></svg> Tambah Donatur
            </button>
          </div>
        <?php endif; ?>
      </div>
    <?php else: ?>
      <div class="tbl-wrap"><table class="tbl-kartu">
        <thead><tr>
          <th>Kode</th><th>Donatur</th><th>Jenis</th><th>Wilayah</th>
          <th class="num">Kali Memberi</th><th class="num">Unit</th>
          <th class="num">Per Bulan</th><th>Terakhir</th>
          <?php if ($lihatKontak): ?><th>Kontak</th><?php endif; ?>
          <th></th>
        </tr></thead>
        <tbody>
        <?php foreach ($daftar as $d):
          $jml   = (int) $d['jml_donasi'];
          $hari  = $d['hari_sejak_terakhir'];
          $sepi  = $jml > 0 && $hari !== null && (int) $hari > 180; ?>
          <tr>
            <td class="sekunder" data-label="Kode"><code><?= e($d['kode']) ?></code></td>
            <td class="judul" data-label="Donatur">
              <b><?= e(nama_donatur_tampil($d)) ?></b>
              <?php if (!empty($d['anonim'])): ?>
                <span class="tag t-gray" title="Donatur minta tidak disebut namanya">anonim</span>
              <?php endif; ?>
              <?php if ((int) $d['is_active'] === 0): ?>
                <span class="tag t-gray">nonaktif</span>
              <?php endif; ?>
              <?php if ($d['kontak_pic']): ?>
                <br><small class="mut2">PIC: <?= e($d['kontak_pic']) ?></small>
              <?php endif; ?>
              <?php if ($d['jml_anggota']): ?>
                <br><small class="mut2">± <?= angka($d['jml_anggota']) ?> anggota</small>
              <?php endif; ?>
            </td>
            <td data-label="Jenis"><span class="tag t-blue"><?= e($JENIS[$d['jenis']] ?? $d['jenis']) ?></span></td>
            <td data-label="Wilayah"><?= e($d['wilayah'] ?: '—') ?></td>
            <td data-label="Kali Memberi" class="num">
              <?= $jml > 0 ? '<b>' . angka($jml) . '</b>' : '<span class="tag t-amber">belum pernah</span>' ?>
              <?php /* Pecahan per bentuk pemberian. Hanya yang berisi yang
                       ditulis — deretan "0 kunjungan · 0 paket" pada donatur
                       biasa cuma memenuhi kolom tanpa memberi tahu apa pun. */
                    $kj = (int) ($d['jml_kunjungan'] ?? 0);
                    $pk = (int) ($d['jml_paket'] ?? 0);
                    if ($kj || $pk): ?>
                <br><small class="mut2">
                  <?php if ($kj): ?><?= angka($kj) ?> kunjungan<?php endif; ?>
                  <?php if ($kj && $pk): ?> · <?php endif; ?>
                  <?php if ($pk): ?><?= angka($pk) ?> paket<?php endif; ?>
                </small>
              <?php endif; ?>
            </td>
            <td data-label="Unit" class="num"><?= angka($d['total_qty'] ?? 0) ?></td>
            <td data-label="Per Bulan" class="num"><?= $jml > 0
                  ? e(number_format((float) $d['rata_per_bulan'], 2, ',', '.')) : '—' ?></td>
            <td class="sekunder" data-label="Terakhir">
              <?php if ($d['donasi_terakhir']): ?>
                <?= e(tgl_id($d['donasi_terakhir'])) ?>
                <?php if ($sepi): ?><br><span class="tag t-amber"><?= (int) $hari ?> hari lalu</span><?php endif; ?>
              <?php else: ?><span class="mut2">—</span><?php endif; ?>
            </td>
            <?php if ($lihatKontak): ?>
              <td class="sekunder" data-label="Kontak">
                <?= $d['telepon'] ? e($d['telepon']) : '<span class="mut2">—</span>' ?>
                <?php if ($d['email']): ?><br><small class="mut2"><?= e($d['email']) ?></small><?php endif; ?>
              </td>
            <?php endif; ?>
            <td class="aksi">
              <?php if ($bolehKelola): ?>
                <a class="btn btn-ghost btn-sm" href="donatur.php?edit=<?= (int) $d['id'] ?>#formTambah">
                  <svg aria-hidden="true"><use href="#i-ubah"></use></svg>
                </a>
              <?php endif; ?>
              <?php if ($bolehHapus): ?>
                <form method="post" class="inline"
                      data-konfirm="Hapus donatur ini?"
                      data-obj="<?= e(nama_donatur_tampil($d)) ?>"
                      data-info="<?= e($d['kode']) ?> · <?= $jml ?> kali memberi tercatat"
                      data-akibat="Riwayat pemberiannya tetap tersimpan supaya laporan pertanggungjawaban tidak terputus. Donatur ini hanya tidak muncul lagi saat mencatat barang masuk.">
                  <input type="hidden" name="aksi" value="hapus">
                  <input type="hidden" name="id" value="<?= (int) $d['id'] ?>">
                  <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                  <button class="btn btn-red btn-sm"><svg aria-hidden="true"><use href="#i-hapus"></use></svg></button>
                </form>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table></div>
      <?php paginasi_nav($hal, $jmlHal, (int) $totDonatur, $perHal, ['q' => $cari, 'jenis' => $fJenis, 'wilayah' => $fWil, 'aktif' => $fAktif, 'urut' => $fUrut]); ?>
    <?php endif; ?>
  </div>

  <?php if ($bolehKelola): ?>
  <div class="card form-lipat<?= ($row['id'] || $errors || $buka) ? ' terbuka' : '' ?>" id="formTambah">
    <div class="modal-kepala">
      <div>
        <h3><?= $row['id'] ? 'Ubah Donatur' : 'Tambah Donatur' ?></h3>
        <div class="sub">Isi seperlunya — hanya kode dan nama yang wajib</div>
      </div>
      <a class="modal-tutup" href="donatur.php" data-tutup-modal
         aria-label="Tutup formulir" title="Tutup (Esc)">&times;</a>
    </div>

    <?php /* Galat validasi tinggal DI DALAM popup. Di badan halaman, ia akan
             berada di belakang lapisan gelap: panel terbuka dengan isian yang
             tadi diketik, tapi alasan gagalnya tak terlihat sama sekali. */ ?>
    <?php if ($errors): ?>
      <div class="flash flash-err" data-tetap="1">
        <?php foreach ($errors as $er) echo '• ' . e($er) . '<br>'; ?>
      </div>
    <?php endif; ?>

    <form method="post">
      <input type="hidden" name="aksi" value="simpan">
      <input type="hidden" name="id" value="<?= (int) $row['id'] ?>">
      <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">

      <div class="form-grid">
        <div class="field"><label>Kode <span class="req">*</span></label>
          <input name="kode" value="<?= e($row['kode']) ?>" maxlength="20" required></div>
        <div class="field"><label>Jenis</label>
          <select name="jenis" id="jenisDonatur" onchange="ubahJenisDonatur()">
            <?php foreach ($JENIS as $k => $lbl): ?>
              <option value="<?= $k ?>" <?= $row['jenis'] === $k ? 'selected' : '' ?>><?= e($lbl) ?></option>
            <?php endforeach; ?>
          </select></div>
      </div>

      <div class="field"><label>Nama <span class="req">*</span></label>
        <input name="nama" value="<?= e($row['nama']) ?>" maxlength="150" required
               placeholder="Nama perorangan, komunitas, atau lembaga">
        <small class="petunjuk">Tetap isi nama sebenarnya walau donatur minta anonim —
          sistem yang menyembunyikannya saat ditampilkan.</small></div>

      <div class="form-grid">
        <div class="field"><label>Wilayah</label>
          <input name="wilayah" value="<?= e($row['wilayah'] ?? '') ?>" maxlength="100"
                 placeholder="Kota / kecamatan">
          <small class="petunjuk">Dipakai sebagai pengganti nama pada laporan yang disamarkan.</small></div>
        <div class="field"><label>Telepon</label>
          <input name="telepon" value="<?= e($row['telepon'] ?? '') ?>" maxlength="30"
                 placeholder="08xx"></div>
      </div>

      <div class="form-grid">
        <div class="field"><label>Email</label>
          <input name="email" type="email" value="<?= e($row['email'] ?? '') ?>" maxlength="150"></div>
        <div class="field" id="fieldAnggota" style="<?= $row['jenis'] === 'pribadi' ? 'display:none' : '' ?>">
          <label>Perkiraan Anggota</label>
          <input name="jml_anggota" type="number" min="0" value="<?= e($row['jml_anggota'] ?? '') ?>"></div>
      </div>

      <div class="field" id="fieldPic" style="<?= $row['jenis'] === 'pribadi' ? 'display:none' : '' ?>">
        <label>Penanggung Jawab</label>
        <input name="kontak_pic" value="<?= e($row['kontak_pic'] ?? '') ?>" maxlength="150"
               placeholder="Nama yang bisa dihubungi"></div>

      <div class="field"><label>Alamat</label>
        <textarea name="alamat" rows="2" maxlength="255"
                  placeholder="opsional"><?= e($row['alamat'] ?? '') ?></textarea></div>

      <div class="field"><label>Keterangan</label>
        <textarea name="keterangan" rows="2" maxlength="255"><?= e($row['keterangan'] ?? '') ?></textarea></div>

      <div class="field">
        <label class="baris" style="font-weight:500;color:var(--text)">
          <input type="checkbox" name="anonim" value="1"
            <?= (int) ($row['anonim'] ?? 0) === 1 ? 'checked' : '' ?>>
          Minta tidak disebut namanya
        </label>
        <small class="petunjuk">Namanya akan tampil sebagai &ldquo;Hamba Allah&rdquo; di
          seluruh sistem — daftar, laporan, dan ekspor.</small>
      </div>

      <div class="field">
        <label class="baris" style="font-weight:500;color:var(--text)">
          <input type="checkbox" name="is_active" value="1"
            <?= (int) $row['is_active'] === 1 ? 'checked' : '' ?>>
          Aktif — muncul saat mencatat barang masuk
        </label>
      </div>

      <div class="baris">
        <button class="btn btn-primary">
          <svg aria-hidden="true"><use href="#i-simpan"></use></svg> Simpan
        </button>
        <?php if ($row['id']): ?><a class="btn btn-ghost" href="donatur.php">Batal</a><?php endif; ?>
      </div>
    </form>
  </div>
  <?php endif; ?>
</div>

<script>
/* Penanggung jawab dan jumlah anggota hanya berarti untuk komunitas dan
   lembaga. Menyembunyikannya untuk donatur pribadi mencegah pertanyaan
   "siapa penanggung jawabnya" muncul untuk satu orang. */
function ubahJenisDonatur(){
  var pribadi = document.getElementById('jenisDonatur').value === 'pribadi';
  document.getElementById('fieldPic').style.display     = pribadi ? 'none' : '';
  document.getElementById('fieldAnggota').style.display = pribadi ? 'none' : '';
}
</script>
<?php require __DIR__ . '/_footer.php'; ?>
