<?php
/**
 * AMANAH — Master Kategori Barang
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/_lib.php';
require_login();
tolak_supplier($pdo, "kelola_master");
tolak_di_luar_peran($pdo);   // master data lembaga: bukan lingkup Admin Gudang

$canEdit = boleh_kelola_master($pdo);
$editId  = (int) ($_GET['edit'] ?? 0);
$errors  = [];
$row = ['id' => 0, 'kode' => '', 'nama' => '', 'ikon' => '', 'keterangan' => ''];

// ---------------------------------------------------------------- SIMPAN
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['act'] ?? '') === 'save') {
    if (!$canEdit) { http_response_code(403); die('403'); }
    wajib_csrf($_POST['csrf'] ?? null);

    $id         = (int) ($_POST['id'] ?? 0);
    $kode       = trim($_POST['kode'] ?? '');
    $nama       = trim($_POST['nama'] ?? '');
    $ikon       = trim($_POST['ikon'] ?? '');
    $keterangan = trim($_POST['keterangan'] ?? '');

    if ($kode === '') $errors[] = 'Kode wajib diisi.';
    if ($nama === '') $errors[] = 'Nama wajib diisi.';

    if (!$errors) {
        $c = $pdo->prepare("SELECT id FROM kategori WHERE kode = ? AND id <> ? AND deleted_at IS NULL");
        $c->execute([$kode, $id]);
        if ($c->fetch()) $errors[] = 'Kode kategori sudah dipakai.';
    }

    if (!$errors) {
        try {
            if ($id > 0) {
                $pdo->prepare("UPDATE kategori SET kode=?, nama=?, ikon=?, keterangan=? WHERE id=?")
                    ->execute([$kode, $nama, $ikon ?: null, $keterangan ?: null, $id]);
                catat_aktivitas($pdo, 'update', 'kategori', $id, "Ubah kategori: $nama");
                set_flash('ok', 'Kategori diperbarui.');
            } else {
                $pdo->prepare("INSERT INTO kategori (kode, nama, ikon, keterangan) VALUES (?,?,?,?)")
                    ->execute([$kode, $nama, $ikon ?: null, $keterangan ?: null]);
                catat_aktivitas($pdo, 'create', 'kategori', (int) $pdo->lastInsertId(), "Tambah kategori: $nama");
                set_flash('ok', 'Kategori ditambahkan.');
            }
            header('Location: kategori.php'); exit;
        } catch (Throwable $e) {
            $errors[] = pesan_error_aman($e, 'simpan kategori');
        }
    }

    // PERBAIKAN BUG v1: dulu baris ini memakai
    //   compact('id','kode','nama','ikon','keterangan')
    // padahal variabelnya bernama $ket — akibatnya PHP 8 melempar
    // "Undefined variable $keterangan" lalu "Undefined array key" setiap
    // kali validasi gagal. Sekarang array disusun eksplisit.
    $row = ['id' => $id, 'kode' => $kode, 'nama' => $nama,
            'ikon' => $ikon, 'keterangan' => $keterangan];
}

// ---------------------------------------------------------------- HAPUS
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['act'] ?? '') === 'del') {
    if (!$canEdit) { http_response_code(403); die('403'); }
    wajib_csrf($_POST['csrf'] ?? null);

    $delId = (int) ($_POST['id'] ?? 0);
    try {
        $cek = $pdo->prepare("SELECT COUNT(*) FROM barang WHERE kategori_id = ? AND deleted_at IS NULL");
        $cek->execute([$delId]);
        if ((int) $cek->fetchColumn() > 0) {
            set_flash('err', 'Kategori tidak bisa dihapus karena masih dipakai barang. Pindahkan barangnya ke kategori lain dulu.');
        } else {
            $pdo->prepare("UPDATE kategori SET deleted_at = NOW() WHERE id = ?")->execute([$delId]);
            catat_aktivitas($pdo, 'delete', 'kategori', $delId, 'Hapus kategori');
            set_flash('ok', 'Kategori dihapus.');
        }
    } catch (Throwable $e) {
        set_flash('err', pesan_error_aman($e, 'hapus kategori'));
    }
    header('Location: kategori.php'); exit;
}

// ---------------------------------------------------------------- DATA
if ($editId > 0) {
    $st = $pdo->prepare("SELECT * FROM kategori WHERE id = ? AND deleted_at IS NULL");
    $st->execute([$editId]);
    $f = $st->fetch();
    if ($f) $row = $f;
} elseif (!$errors) {
    $row['kode'] = kode_berikut($pdo, 'kategori', 'KAT');
}

$q = trim((string) ($_GET['q'] ?? ''));
$syaratCari = syarat_cari($pdo, $q, ['k.nama', 'k.kode', 'k.keterangan']);
$perHal = 30;
$totalBaris = (int) $pdo->query("SELECT COUNT(*) FROM kategori k WHERE k.deleted_at IS NULL AND $syaratCari")->fetchColumn();
[$hal, $offset, $jmlHal] = paginasi_hitung($totalBaris, $perHal);

$list = $pdo->query(
    "SELECT k.*,
            (SELECT COUNT(*) FROM barang b WHERE b.kategori_id = k.id AND b.deleted_at IS NULL) AS jml,
            (SELECT COALESCE(SUM(s.qty_total),0) FROM barang b
               LEFT JOIN stok s ON s.barang_id = b.id
              WHERE b.kategori_id = k.id AND b.deleted_at IS NULL) AS total_stok
     FROM kategori k WHERE k.deleted_at IS NULL AND $syaratCari
     ORDER BY k.kode LIMIT $perHal OFFSET $offset"
)->fetchAll();

$PAGE_TITLE = 'Kategori'; $ACTIVE = 'kategori';
require __DIR__ . '/_header.php';
?>
<div class="page-head">
  <div><h1>Kategori Barang</h1><p>Pengelompokan jenis perlengkapan · <?= angka($totalBaris) ?> kategori</p></div>
  <?php if ($canEdit): ?><a class="btn btn-primary" href="#formTambah" onclick="bukaFormTambah(event)"><svg aria-hidden="true"><use href="#i-plus"></use></svg> Tambah Kategori</a><?php endif; ?>
</div>


<div class="tumpuk">
  <div class="card">
    <?php cari_bar($q, 'Cari nama atau kode kategori…', $q !== '' ? $totalBaris : null); ?>
    <div class="scrollx"><table class="tbl-kartu">
      <thead><tr><th>Kode</th><th>Ikon</th><th>Nama</th><th>Jml Barang</th><th>Total Stok</th>
        <?php if ($canEdit): ?><th>Aksi</th><?php endif; ?></tr></thead>
      <tbody>
      <?php if (!$list): ?>
        <tr><td colspan="6" class="empty">Belum ada kategori.</td></tr>
      <?php else: foreach ($list as $k): ?>
        <tr>
          <td class="sekunder" data-label="Kode"><code><?= e($k['kode']) ?></code></td>
          <td class="sekunder" data-label="Ikon" style="font-size:18px"><?= e($k['ikon'] ?: '🏷️') ?></td>
          <td class="judul" data-label="Nama"><b><?= e($k['nama']) ?></b>
            <?php if ($k['keterangan']): ?><br><small class="mut"><?= e($k['keterangan']) ?></small><?php endif; ?>
          </td>
          <td data-label="Jml Barang"><span class="tag t-blue"><?= (int) $k['jml'] ?></span></td>
          <td data-label="Total Stok"><?= angka($k['total_stok']) ?> unit</td>
          <?php if ($canEdit): ?>
          <td class="nowrap aksi">
            <a class="btn btn-ghost btn-sm" href="kategori.php?edit=<?= (int) $k['id'] ?>">Edit</a>
            <form method="post" class="inline" data-konfirm="Hapus kategori ini?">
              <input type="hidden" name="act" value="del">
              <input type="hidden" name="id" value="<?= (int) $k['id'] ?>">
              <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
              <button class="btn btn-red btn-sm">Hapus</button>
            </form>
          </td>
          <?php endif; ?>
        </tr>
      <?php endforeach; endif; ?>
      </tbody>
    </table></div>
    <?php paginasi_nav($hal, $jmlHal, $totalBaris, $perHal, ['q' => $q]); ?>
  </div>

  <?php if ($canEdit): ?>
  <div class="card form-lipat<?= ($row['id'] || $errors) ? ' terbuka' : '' ?>" id="formTambah">
    <div class="modal-kepala">
      <div>
        <h3><?= $row['id'] ? 'Edit Kategori' : 'Tambah Kategori' ?></h3>
        <div class="sub"><?= $row['id'] ? 'Ubah data lalu simpan' : 'Isi form di bawah' ?></div>
      </div>
      <a class="modal-tutup" href="kategori.php" data-tutup-modal
         aria-label="Tutup formulir" title="Tutup (Esc)">&times;</a>
    </div>

    <?php if ($errors): ?>
      <div class="flash flash-err" data-tetap="1">
        <?php foreach ($errors as $er) echo '• ' . e($er) . '<br>'; ?>
      </div>
    <?php endif; ?>

    <form method="post" class="mt6">
      <input type="hidden" name="act" value="save">
      <input type="hidden" name="id" value="<?= (int) $row['id'] ?>">
      <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
      <div class="field"><label>Kode <span class="req">*</span></label>
        <input name="kode" value="<?= e($row['kode']) ?>" required></div>
      <div class="field"><label>Nama <span class="req">*</span></label>
        <input name="nama" value="<?= e($row['nama']) ?>" required></div>
      <div class="field"><label>Ikon (emoji, opsional)</label>
        <input name="ikon" value="<?= e($row['ikon'] ?? '') ?>" placeholder="🍼"></div>
      <div class="field"><label>Keterangan</label>
        <textarea name="keterangan" rows="2"><?= e($row['keterangan'] ?? '') ?></textarea></div>
      <div class="baris">
        <button class="btn btn-primary"><svg aria-hidden="true"><use href="#i-simpan"></use></svg> Simpan</button>
        <?php if ($row['id']): ?><a class="btn btn-ghost" href="kategori.php">Batal</a><?php endif; ?>
      </div>
    </form>
  </div>
  <?php endif; ?>
</div>
<?php require __DIR__ . '/_footer.php'; ?>
