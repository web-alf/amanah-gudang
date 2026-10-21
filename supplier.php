<?php
/**
 * AMANAH — Master Supplier
 *
 * Selain data pemasok, halaman ini juga mengelola AKUN LOGIN supplier
 * untuk portal mereka. Akun supplier dibuat oleh Super Admin dan
 * otomatis terkunci ke satu supplier_id — sehingga satu pemasok tidak
 * bisa melihat data pemasok lain.
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/_lib.php';
require_login();
tolak_supplier($pdo, "kelola_master");
tolak_di_luar_peran($pdo);   // master data lembaga: bukan lingkup Admin Gudang

$u        = current_user();
$canEdit  = boleh_kelola_master($pdo);
$isSuper  = role_saat_ini() === 'super_admin';

$editId = (int) ($_GET['edit'] ?? 0);
$akunId = (int) ($_GET['akun'] ?? 0);
$errors = [];
$row = ['id' => 0, 'kode' => '', 'nama' => '', 'telepon' => '', 'email' => '',
        'alamat' => '', 'kontak_pic' => '', 'keterangan' => ''];

// ---------------------------------------------------------------- SIMPAN
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['act'] ?? '') === 'save') {
    if (!$canEdit) { http_response_code(403); die('403'); }
    wajib_csrf($_POST['csrf'] ?? null);

    $id = (int) ($_POST['id'] ?? 0);
    $kode       = trim($_POST['kode'] ?? '');
    $nama       = trim($_POST['nama'] ?? '');
    $telepon    = trim($_POST['telepon'] ?? '');
    $email      = trim($_POST['email'] ?? '');
    $alamat     = trim($_POST['alamat'] ?? '');
    $kontak_pic = trim($_POST['kontak_pic'] ?? '');
    $keterangan = trim($_POST['keterangan'] ?? '');

    if ($kode === '') $errors[] = 'Kode wajib diisi.';
    if ($nama === '') $errors[] = 'Nama wajib diisi.';
    if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'Format email tidak valid.';

    if (!$errors) {
        $c = $pdo->prepare("SELECT id FROM supplier WHERE kode = ? AND id <> ? AND deleted_at IS NULL");
        $c->execute([$kode, $id]);
        if ($c->fetch()) $errors[] = 'Kode supplier sudah dipakai.';
    }

    if (!$errors) {
        try {
            if ($id > 0) {
                $pdo->prepare(
                    "UPDATE supplier SET kode=?,nama=?,telepon=?,email=?,alamat=?,kontak_pic=?,keterangan=? WHERE id=?"
                )->execute([$kode, $nama, $telepon ?: null, $email ?: null, $alamat ?: null,
                            $kontak_pic ?: null, $keterangan ?: null, $id]);
                catat_aktivitas($pdo, 'update', 'supplier', $id, "Ubah supplier: $nama");
                set_flash('ok', 'Supplier diperbarui.');
            } else {
                $pdo->prepare(
                    "INSERT INTO supplier (kode,nama,telepon,email,alamat,kontak_pic,keterangan) VALUES (?,?,?,?,?,?,?)"
                )->execute([$kode, $nama, $telepon ?: null, $email ?: null, $alamat ?: null,
                            $kontak_pic ?: null, $keterangan ?: null]);
                catat_aktivitas($pdo, 'create', 'supplier', (int) $pdo->lastInsertId(), "Tambah supplier: $nama");
                set_flash('ok', 'Supplier ditambahkan.');
            }
            header('Location: supplier.php'); exit;
        } catch (Throwable $e) {
            $errors[] = pesan_error_aman($e, 'simpan supplier');
        }
    }

    // Pertahankan isian saat validasi gagal.
    $row = compact('id', 'kode', 'nama', 'telepon', 'email', 'alamat', 'kontak_pic', 'keterangan');
}

// ---------------------------------------------------------------- HAPUS
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['act'] ?? '') === 'del') {
    if (!$canEdit) { http_response_code(403); die('403'); }
    wajib_csrf($_POST['csrf'] ?? null);
    try {
        $pdo->prepare("UPDATE supplier SET deleted_at = NOW() WHERE id = ?")->execute([(int) $_POST['id']]);
        catat_aktivitas($pdo, 'delete', 'supplier', (int) $_POST['id'], 'Hapus supplier');
        set_flash('ok', 'Supplier dihapus.');
    } catch (Throwable $e) {
        set_flash('err', 'Supplier tidak bisa dihapus karena masih dipakai barang atau transaksi.');
    }
    header('Location: supplier.php'); exit;
}

// ------------------------------------------------- AKUN LOGIN SUPPLIER
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['act'] ?? '') === 'akun') {
    if (!$isSuper) { http_response_code(403); die('403 — hanya Super Admin yang dapat mengelola akun.'); }
    wajib_csrf($_POST['csrf'] ?? null);

    $sid      = (int) ($_POST['supplier_id'] ?? 0);
    $namaAkun = trim($_POST['nama_akun'] ?? '');
    $emailAkun= trim($_POST['email_akun'] ?? '');
    $pass     = $_POST['password_akun'] ?? '';
    $userId   = (int) ($_POST['user_id'] ?? 0);
    $nonaktif = isset($_POST['nonaktifkan']);

    try {
        if ($userId > 0 && $nonaktif) {
            $pdo->prepare("UPDATE users SET is_active = 0 WHERE id = ? AND supplier_id IS NOT NULL")->execute([$userId]);
            catat_aktivitas($pdo, 'update', 'users', $userId, 'Nonaktifkan akun supplier');
            set_flash('ok', 'Akun supplier dinonaktifkan.');

        } elseif ($userId > 0 && $pass !== '') {
            if (strlen($pass) < 8) {
                set_flash('err', 'Password minimal 8 karakter.');
            } else {
                $pdo->prepare("UPDATE users SET password = ?, is_active = 1 WHERE id = ? AND supplier_id IS NOT NULL")
                    ->execute([password_hash($pass, PASSWORD_BCRYPT), $userId]);
                catat_aktivitas($pdo, 'update', 'users', $userId, 'Reset password akun supplier');
                set_flash('ok', 'Password akun supplier berhasil diperbarui.');
            }

        } else {
            if ($namaAkun === '' || $emailAkun === '' || $pass === '') {
                set_flash('err', 'Nama, email, dan password akun wajib diisi.');
            } elseif (!filter_var($emailAkun, FILTER_VALIDATE_EMAIL)) {
                set_flash('err', 'Format email akun tidak valid.');
            } elseif (strlen($pass) < 8) {
                set_flash('err', 'Password minimal 8 karakter.');
            } else {
                $cek = $pdo->prepare("SELECT id FROM users WHERE email = ?");
                $cek->execute([$emailAkun]);
                if ($cek->fetch()) {
                    set_flash('err', 'Email tersebut sudah dipakai akun lain.');
                } else {
                    $roleId = $pdo->query("SELECT id FROM roles WHERE nama = 'supplier' LIMIT 1")->fetchColumn();
                    if (!$roleId) {
                        set_flash('err', 'Role "supplier" belum ada. Jalankan migrasi_amanah_v2.sql lebih dulu.');
                    } else {
                        $pdo->prepare(
                            "INSERT INTO users (role_id, supplier_id, nama, email, password, is_active)
                             VALUES (?,?,?,?,?,1)"
                        )->execute([$roleId, $sid, $namaAkun, $emailAkun, password_hash($pass, PASSWORD_BCRYPT)]);
                        catat_aktivitas($pdo, 'create', 'users', (int) $pdo->lastInsertId(),
                            "Buat akun portal supplier: $emailAkun");
                        set_flash('ok', "Akun portal untuk $emailAkun berhasil dibuat. Sampaikan passwordnya lewat kanal yang aman — jangan lewat grup terbuka.");
                    }
                }
            }
        }
    } catch (Throwable $e) {
        set_flash('err', pesan_error_aman($e, 'kelola akun supplier'));
    }
    header('Location: supplier.php?akun=' . $sid); exit;
}

// ---------------------------------------------------------------- DATA
if ($editId > 0) {
    $st = $pdo->prepare("SELECT * FROM supplier WHERE id = ? AND deleted_at IS NULL");
    $st->execute([$editId]);
    $f = $st->fetch();
    if ($f) $row = $f;
} elseif (!$errors) {
    $row['kode'] = kode_berikut($pdo, 'supplier', 'SUP');
}

// Daftar supplier + jumlah akun portal & kiriman — dengan cari & paginasi.
$q = trim((string) ($_GET['q'] ?? ''));
$syaratCari = syarat_cari($pdo, $q, ['s.nama', 's.kode', 's.kontak_pic', 's.telepon', 's.alamat']);
$perHal = 25;
$totalBaris = (int) $pdo->query("SELECT COUNT(*) FROM supplier s WHERE s.deleted_at IS NULL AND $syaratCari")->fetchColumn();
[$hal, $offset, $jmlHal] = paginasi_hitung($totalBaris, $perHal);
$list = $pdo->query(
    "SELECT s.*,
            (SELECT COUNT(*) FROM users us WHERE us.supplier_id = s.id AND us.deleted_at IS NULL AND us.is_active = 1) AS jml_akun
     FROM supplier s WHERE s.deleted_at IS NULL AND $syaratCari
     ORDER BY s.kode LIMIT $perHal OFFSET $offset"
)->fetchAll();

// Kiriman per supplier (aman bila tabel belum dimigrasi).
$kirimanPer = [];
try {
    foreach ($pdo->query("SELECT supplier_id, COUNT(*) n FROM pengiriman_supplier GROUP BY supplier_id") as $r) {
        $kirimanPer[(int) $r['supplier_id']] = (int) $r['n'];
    }
} catch (Throwable $e) { /* belum dimigrasi */ }

// Panel akun untuk satu supplier.
$akunSupplier = null; $akunList = [];
if ($akunId > 0 && $isSuper) {
    $st = $pdo->prepare("SELECT * FROM supplier WHERE id = ? AND deleted_at IS NULL");
    $st->execute([$akunId]);
    $akunSupplier = $st->fetch() ?: null;

    if ($akunSupplier) {
        try {
            $stU = $pdo->prepare(
                "SELECT id, nama, email, is_active, last_login_at
                 FROM users WHERE supplier_id = ? AND deleted_at IS NULL ORDER BY id"
            );
            $stU->execute([$akunId]);
            $akunList = $stU->fetchAll();
        } catch (Throwable $e) { error_log('[AMANAH] ' . $e->getMessage()); }
    }
}

$PAGE_TITLE = 'Supplier'; $ACTIVE = 'supplier';
require __DIR__ . '/_header.php';
?>
<div class="page-head">
  <div><h1>Supplier</h1><p>Data pemasok & akun portal · <?= angka($totalBaris) ?> supplier</p></div>
  <a class="btn btn-ghost" href="kiriman_supplier.php"><svg aria-hidden="true"><use href="#i-truck"></use></svg> Kiriman Masuk</a>
  <?php if ($canEdit): ?><a class="btn btn-primary" href="#formTambah" onclick="bukaFormTambah(event)"><svg aria-hidden="true"><use href="#i-plus"></use></svg> Tambah Supplier</a><?php endif; ?>
</div>


<?php if ($akunSupplier): ?>
  <div class="card" style="margin-bottom:16px;border-color:var(--primary)">
    <h3>Akun Portal — <?= e($akunSupplier['nama']) ?></h3>
    <div class="sub">Dengan akun ini supplier dapat login sendiri dan menginput rencana kiriman beserta nomor resi</div>

    <div style="overflow-x:auto;margin-bottom:16px"><table class="tbl-kartu">
      <thead><tr><th>Nama</th><th>Email Login</th><th>Status</th><th>Login Terakhir</th><th>Aksi</th></tr></thead>
      <tbody>
      <?php if (!$akunList): ?>
        <tr><td colspan="5" class="empty">Belum ada akun portal untuk supplier ini.</td></tr>
      <?php else: foreach ($akunList as $a): ?>
        <tr>
          <td class="judul" data-label="Nama"><b><?= e($a['nama']) ?></b></td>
          <td class="sekunder" data-label="Email Login"><code><?= e($a['email']) ?></code></td>
          <td data-label="Status"><span class="tag <?= (int) $a['is_active'] === 1 ? 't-green' : 't-gray' ?>">
            <?= (int) $a['is_active'] === 1 ? 'Aktif' : 'Nonaktif' ?></span></td>
          <td class="sekunder" data-label="Login Terakhir"><?= $a['last_login_at'] ? e(tgl_jam_id($a['last_login_at'])) : '<small class="mut">belum pernah</small>' ?></td>
          <td class="nowrap aksi">
            <form method="post" style="display:inline-flex;gap:6px;align-items:center">
              <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
              <input type="hidden" name="act" value="akun">
              <input type="hidden" name="supplier_id" value="<?= (int) $akunSupplier['id'] ?>">
              <input type="hidden" name="user_id" value="<?= (int) $a['id'] ?>">
              <input type="password" name="password_akun" placeholder="Password baru" minlength="8"
                     style="padding:6px 9px;border:1px solid var(--border);border-radius:8px;
                            background:var(--surface-2);color:var(--text);font-size:12.5px;width:150px">
              <button class="btn btn-ghost btn-sm">Reset</button>
            </form>
            <?php if ((int) $a['is_active'] === 1): ?>
              <form method="post" class="inline" data-konfirm="Nonaktifkan akun ini?" data-akibat="Akun tidak bisa dipakai masuk sampai diaktifkan lagi." data-tombol="Ya, nonaktifkan" data-warna="amber">
                <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="act" value="akun">
                <input type="hidden" name="supplier_id" value="<?= (int) $akunSupplier['id'] ?>">
                <input type="hidden" name="user_id" value="<?= (int) $a['id'] ?>">
                <input type="hidden" name="nonaktifkan" value="1">
                <button class="btn btn-red btn-sm">Nonaktifkan</button>
              </form>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; endif; ?>
      </tbody>
    </table></div>

    <div style="border-top:1px solid var(--border);padding-top:16px">
      <b style="font-size:13.5px">Buat Akun Portal Baru</b>
      <form method="post" style="margin-top:10px">
        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="act" value="akun">
        <input type="hidden" name="supplier_id" value="<?= (int) $akunSupplier['id'] ?>">
        <div class="form-grid">
          <div class="field"><label>Nama Petugas Supplier <span class="req">*</span></label>
            <input name="nama_akun" placeholder="cth: Ibu Ratna" required></div>
          <div class="field"><label>Email Login <span class="req">*</span></label>
            <input name="email_akun" type="email" placeholder="ratna@supplier.com"
                   value="<?= e($akunSupplier['email'] ?? '') ?>" required></div>
          <div class="field span-all"><label>Password Awal <span class="req">*</span></label>
            <input name="password_akun" type="password" minlength="8" required
                   placeholder="Minimal 8 karakter"></div>
        </div>
        <button class="btn btn-primary"><svg aria-hidden="true"><use href="#i-plus"></use></svg> Buat Akun</button>
        <a class="btn btn-ghost" href="supplier.php">Tutup</a>
        <p style="color:var(--muted);font-size:11.5px;margin-top:10px;line-height:1.6">
          🔒 Sampaikan password lewat kanal pribadi (telepon / chat langsung ke PIC), <b>jangan lewat grup terbuka</b>.
          Minta supplier menggantinya setelah login pertama.
        </p>
      </form>
    </div>
  </div>
<?php endif; ?>

<div class="tumpuk">
  <div class="card">
    <?php cari_bar($q, 'Cari nama, kode, kontak, atau alamat supplier…', $q !== '' ? $totalBaris : null); ?>
    <div class="scrollx"><table class="tbl-kartu">
      <thead><tr>
        <th>Kode</th><th>Nama</th><th>Kontak</th><th>Alamat</th><th>Kiriman</th><th>Akun Portal</th>
        <?php if ($canEdit): ?><th>Aksi</th><?php endif; ?>
      </tr></thead>
      <tbody>
      <?php if (!$list): ?>
        <tr><td colspan="7" class="empty">Belum ada supplier.</td></tr>
      <?php else: foreach ($list as $s): ?>
        <tr>
          <td class="sekunder" data-label="Kode"><code><?= e($s['kode']) ?></code></td>
          <td class="judul" data-label="Nama"><b><?= e($s['nama']) ?></b>
            <?php if ($s['kontak_pic']): ?><br><small class="mut">PIC: <?= e($s['kontak_pic']) ?></small><?php endif; ?>
          </td>
          <td data-label="Kontak"><?= e($s['telepon'] ?: '—') ?>
            <?php if ($s['email']): ?><br><small class="mut"><?= e($s['email']) ?></small><?php endif; ?>
          </td>
          <td class="sekunder" data-label="Alamat"><?= e($s['alamat'] ?: '—') ?></td>
          <td data-label="Kiriman"><?= isset($kirimanPer[(int) $s['id']]) ? '<span class="tag t-blue">' . $kirimanPer[(int) $s['id']] . '</span>' : '—' ?></td>
          <td class="sekunder" data-label="Akun Portal">
            <?php if ((int) $s['jml_akun'] > 0): ?>
              <span class="tag t-green"><?= (int) $s['jml_akun'] ?> akun aktif</span>
            <?php else: ?>
              <span class="tag t-gray">belum ada</span>
            <?php endif; ?>
          </td>
          <?php if ($canEdit): ?>
          <td class="nowrap aksi">
            <a class="btn btn-ghost btn-sm" href="supplier.php?edit=<?= (int) $s['id'] ?>">Edit</a>
            <?php if ($isSuper): ?>
              <a class="btn btn-ghost btn-sm" href="supplier.php?akun=<?= (int) $s['id'] ?>">🔑 Akun</a>
            <?php endif; ?>
            <form method="post" class="inline" data-konfirm="Hapus supplier ini?">
              <input type="hidden" name="act" value="del">
              <input type="hidden" name="id" value="<?= (int) $s['id'] ?>">
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
        <h3><?= $row['id'] ? 'Edit Supplier' : 'Tambah Supplier' ?></h3>
        <div class="sub">Data pemasok perlengkapan bayi</div>
      </div>
      <a class="modal-tutup" href="supplier.php" data-tutup-modal
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
      <div class="field"><label>Telepon</label>
        <input name="telepon" value="<?= e($row['telepon'] ?? '') ?>"></div>
      <div class="field"><label>Email</label>
        <input name="email" type="email" value="<?= e($row['email'] ?? '') ?>"></div>
      <div class="field"><label>Alamat</label>
        <input name="alamat" value="<?= e($row['alamat'] ?? '') ?>"></div>
      <div class="field"><label>Kontak PIC</label>
        <input name="kontak_pic" value="<?= e($row['kontak_pic'] ?? '') ?>"></div>
      <div class="field"><label>Keterangan</label>
        <textarea name="keterangan" rows="2"><?= e($row['keterangan'] ?? '') ?></textarea></div>
      <div class="baris">
        <button class="btn btn-primary"><svg aria-hidden="true"><use href="#i-simpan"></use></svg> Simpan</button>
        <?php if ($row['id']): ?><a class="btn btn-ghost" href="supplier.php">Batal</a><?php endif; ?>
      </div>
    </form>
  </div>
  <?php endif; ?>
</div>
<?php require __DIR__ . '/_footer.php'; ?>
