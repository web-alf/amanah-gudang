<?php
/**
 * AMANAH — Master Toko
 *
 * Toko adalah simpul ketiga rantai barang: Supplier → Gudang → Toko.
 * Satu akun Admin Toko boleh memegang beberapa toko sekaligus (mis. orang
 * yang sama mengurus Shopee dan Tokopedia).
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/_lib.php';
require_login();
tolak_supplier($pdo, "kelola_master");
tolak_di_luar_peran($pdo);   // master data lembaga: bukan lingkup Admin Gudang

$u       = current_user();
$canEdit = boleh_kelola_master($pdo);
$isSuper = role_saat_ini() === 'super_admin';

$editId = (int) ($_GET['edit'] ?? 0);
$akunId = (int) ($_GET['akun'] ?? 0);
$errors = [];
$row = ['id' => 0, 'kode' => '', 'nama' => '', 'marketplace' => 'shopee', 'url_toko' => '',
        'penanggung_jawab' => '', 'telepon' => '', 'alamat' => '', 'keterangan' => '', 'is_active' => 1];

$mpOpts = ['shopee', 'tokopedia', 'tiktok', 'lazada', 'offline', 'lainnya'];

// ---------------------------------------------------------------- SIMPAN
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['act'] ?? '') === 'save') {
    if (!$canEdit) { http_response_code(403); die('403'); }
    wajib_csrf($_POST['csrf'] ?? null);

    $id     = (int) ($_POST['id'] ?? 0);
    $kode   = trim($_POST['kode'] ?? '');
    $nama   = trim($_POST['nama'] ?? '');
    $mp     = $_POST['marketplace'] ?? 'offline';
    $url    = trim($_POST['url_toko'] ?? '');
    $pj     = trim($_POST['penanggung_jawab'] ?? '');
    $telp   = trim($_POST['telepon'] ?? '');
    $alamat = trim($_POST['alamat'] ?? '');
    $ket    = trim($_POST['keterangan'] ?? '');
    $aktif  = isset($_POST['is_active']) ? 1 : 0;

    if ($kode === '') $errors[] = 'Kode toko wajib diisi.';
    if ($nama === '') $errors[] = 'Nama toko wajib diisi.';
    if (!in_array($mp, $mpOpts, true)) $errors[] = 'Marketplace tidak valid.';

    if (!$errors) {
        $c = $pdo->prepare("SELECT id FROM toko WHERE kode = ? AND id <> ? AND deleted_at IS NULL");
        $c->execute([$kode, $id]);
        if ($c->fetch()) $errors[] = 'Kode toko sudah dipakai.';
    }

    if (!$errors) {
        try {
            if ($id > 0) {
                $pdo->prepare(
                    "UPDATE toko SET kode=?,nama=?,marketplace=?,url_toko=?,penanggung_jawab=?,
                                     telepon=?,alamat=?,keterangan=?,is_active=? WHERE id=?"
                )->execute([$kode, $nama, $mp, $url ?: null, $pj ?: null, $telp ?: null,
                            $alamat ?: null, $ket ?: null, $aktif, $id]);
                catat_aktivitas($pdo, 'update', 'toko', $id, "Ubah toko: $nama");
                set_flash('ok', "Toko \"$nama\" diperbarui.");
            } else {
                $pdo->prepare(
                    "INSERT INTO toko (kode,nama,marketplace,url_toko,penanggung_jawab,telepon,alamat,keterangan,is_active)
                     VALUES (?,?,?,?,?,?,?,?,?)"
                )->execute([$kode, $nama, $mp, $url ?: null, $pj ?: null, $telp ?: null,
                            $alamat ?: null, $ket ?: null, $aktif]);
                catat_aktivitas($pdo, 'create', 'toko', (int) $pdo->lastInsertId(), "Tambah toko: $nama");
                set_flash('ok', "Toko \"$nama\" ditambahkan.");
            }
            header('Location: toko.php'); exit;
        } catch (Throwable $e) {
            $errors[] = pesan_error_aman($e, 'simpan toko');
        }
    }
    $row = ['id' => $id, 'kode' => $kode, 'nama' => $nama, 'marketplace' => $mp, 'url_toko' => $url,
            'penanggung_jawab' => $pj, 'telepon' => $telp, 'alamat' => $alamat,
            'keterangan' => $ket, 'is_active' => $aktif];
}

// ---------------------------------------------------------------- HAPUS
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['act'] ?? '') === 'del') {
    if (!$canEdit) { http_response_code(403); die('403'); }
    wajib_csrf($_POST['csrf'] ?? null);

    $delId = (int) ($_POST['id'] ?? 0);
    try {
        $cek = $pdo->prepare("SELECT COALESCE(SUM(qty_tersisa),0) FROM batch_barang WHERE toko_id = ?");
        $cek->execute([$delId]);
        if ((int) $cek->fetchColumn() > 0) {
            set_flash('err', 'Toko tidak bisa dihapus karena masih menyimpan stok. Retur atau jual dulu isinya.');
        } else {
            $pdo->prepare("UPDATE toko SET deleted_at = NOW(), is_active = 0 WHERE id = ?")->execute([$delId]);
            catat_aktivitas($pdo, 'delete', 'toko', $delId, 'Hapus toko');
            set_flash('ok', 'Toko dihapus.');
        }
    } catch (Throwable $e) {
        set_flash('err', pesan_error_aman($e, 'hapus toko'));
    }
    header('Location: toko.php'); exit;
}

// ------------------------------------------------- AKUN LOGIN ADMIN TOKO
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['act'] ?? '') === 'akun') {
    if (!$isSuper) { http_response_code(403); die('403 — hanya Super Admin.'); }
    wajib_csrf($_POST['csrf'] ?? null);

    $sub    = $_POST['sub'] ?? '';
    $userId = (int) ($_POST['user_id'] ?? 0);

    try {
        if ($sub === 'buat') {
            $nama  = trim($_POST['nama_akun'] ?? '');
            $email = trim($_POST['email_akun'] ?? '');
            $pass  = $_POST['password_akun'] ?? '';
            $tokoDipilih = array_map('intval', $_POST['toko_ids'] ?? []);

            if ($nama === '' || $email === '' || $pass === '') {
                set_flash('err', 'Nama, email, dan password wajib diisi.');
            } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                set_flash('err', 'Format email tidak valid.');
            } elseif (strlen($pass) < 8) {
                set_flash('err', 'Password minimal 8 karakter.');
            } elseif (!$tokoDipilih) {
                set_flash('err', 'Pilih minimal satu toko yang boleh diakses akun ini.');
            } else {
                $cek = $pdo->prepare("SELECT id FROM users WHERE email = ?");
                $cek->execute([$email]);
                if ($cek->fetch()) {
                    set_flash('err', 'Email tersebut sudah dipakai akun lain.');
                } else {
                    $roleId = $pdo->query("SELECT id FROM roles WHERE nama = 'toko' LIMIT 1")->fetchColumn();
                    if (!$roleId) {
                        set_flash('err', 'Role "toko" belum ada. Jalankan migrasi_amanah_v3.sql lebih dulu.');
                    } else {
                        $pdo->beginTransaction();
                        $pdo->prepare(
                            "INSERT INTO users (role_id, nama, email, password, is_active) VALUES (?,?,?,?,1)"
                        )->execute([$roleId, $nama, $email, password_hash($pass, PASSWORD_BCRYPT)]);
                        $newId = (int) $pdo->lastInsertId();

                        $ins = $pdo->prepare("INSERT IGNORE INTO user_toko (user_id, toko_id) VALUES (?,?)");
                        foreach ($tokoDipilih as $tid) $ins->execute([$newId, $tid]);
                        $pdo->commit();

                        catat_aktivitas($pdo, 'create', 'users', $newId, "Buat akun Admin Toko: $email");
                        set_flash('ok', "Akun $email dibuat untuk " . count($tokoDipilih)
                                      . " toko. Sampaikan passwordnya lewat kanal pribadi, bukan grup terbuka.");
                    }
                }
            }

        } elseif ($sub === 'akses' && $userId > 0) {
            $tokoDipilih = array_map('intval', $_POST['toko_ids'] ?? []);
            $pdo->beginTransaction();
            $pdo->prepare("DELETE FROM user_toko WHERE user_id = ?")->execute([$userId]);
            $ins = $pdo->prepare("INSERT IGNORE INTO user_toko (user_id, toko_id) VALUES (?,?)");
            foreach ($tokoDipilih as $tid) $ins->execute([$userId, $tid]);
            $pdo->commit();
            catat_aktivitas($pdo, 'update', 'users', $userId, 'Ubah akses toko');
            set_flash('ok', 'Akses toko diperbarui (' . count($tokoDipilih) . ' toko).');

        } elseif ($sub === 'reset' && $userId > 0) {
            $pass = $_POST['password_akun'] ?? '';
            if (strlen($pass) < 8) {
                set_flash('err', 'Password minimal 8 karakter.');
            } else {
                $pdo->prepare("UPDATE users SET password = ?, is_active = 1 WHERE id = ?")
                    ->execute([password_hash($pass, PASSWORD_BCRYPT), $userId]);
                catat_aktivitas($pdo, 'update', 'users', $userId, 'Reset password Admin Toko');
                set_flash('ok', 'Password akun diperbarui.');
            }

        } elseif ($sub === 'nonaktif' && $userId > 0) {
            $pdo->prepare("UPDATE users SET is_active = 0 WHERE id = ?")->execute([$userId]);
            catat_aktivitas($pdo, 'update', 'users', $userId, 'Nonaktifkan Admin Toko');
            set_flash('ok', 'Akun dinonaktifkan.');
        }
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        set_flash('err', pesan_error_aman($e, 'kelola akun toko'));
    }
    header('Location: toko.php?akun=1'); exit;
}

// ---------------------------------------------------------------- DATA
if ($editId > 0) {
    $st = $pdo->prepare("SELECT * FROM toko WHERE id = ? AND deleted_at IS NULL");
    $st->execute([$editId]);
    $f = $st->fetch();
    if ($f) $row = $f;
} elseif (!$errors) {
    $row['kode'] = kode_berikut($pdo, 'toko', 'TK');
}

$q = trim((string) ($_GET['q'] ?? ''));
$syaratCari = syarat_cari($pdo, $q, ['t.nama', 't.kode', 't.marketplace', 't.alamat', 't.penanggung_jawab']);
$perHal = 25; $totalBaris = 0; $hal = 1; $offset = 0; $jmlHal = 1;
$list = []; $adaTabel = true;
try {
    $totalBaris = (int) $pdo->query("SELECT COUNT(*) FROM toko t WHERE t.deleted_at IS NULL AND $syaratCari")->fetchColumn();
    [$hal, $offset, $jmlHal] = paginasi_hitung($totalBaris, $perHal);
    $list = $pdo->query(
        "SELECT t.*,
                (SELECT COALESCE(SUM(bb.qty_tersisa),0) FROM batch_barang bb
                  WHERE bb.toko_id = t.id AND bb.qty_tersisa > 0) AS stok,
                (SELECT COUNT(DISTINCT bb.barang_id) FROM batch_barang bb
                  WHERE bb.toko_id = t.id AND bb.qty_tersisa > 0) AS jml_barang,
                (SELECT COUNT(*) FROM user_toko ut JOIN users us ON us.id = ut.user_id
                  WHERE ut.toko_id = t.id AND us.is_active = 1 AND us.deleted_at IS NULL) AS jml_akun,
                (SELECT COALESCE(SUM(d.subtotal),0) FROM penjualan p
                   JOIN penjualan_detail d ON d.penjualan_id = p.id
                  WHERE p.toko_id = t.id AND p.status <> 'batal'
                    AND YEAR(p.tanggal)=YEAR(CURDATE()) AND MONTH(p.tanggal)=MONTH(CURDATE())) AS omzet_bln
         FROM toko t WHERE t.deleted_at IS NULL AND $syaratCari
         ORDER BY t.kode LIMIT $perHal OFFSET $offset"
    )->fetchAll();
} catch (Throwable $e) {
    $adaTabel = false;
    error_log('[AMANAH] toko: ' . $e->getMessage());
}

$akunList = [];
if ($akunId && $isSuper && $adaTabel) {
    try {
        $akunList = $pdo->query(
            "SELECT us.id, us.nama, us.email, us.is_active, us.last_login_at,
                    GROUP_CONCAT(t.nama ORDER BY t.kode SEPARATOR ', ') AS daftar_toko,
                    GROUP_CONCAT(t.id ORDER BY t.kode) AS id_toko
             FROM users us
             JOIN roles r ON r.id = us.role_id AND r.nama = 'toko'
             LEFT JOIN user_toko ut ON ut.user_id = us.id
             LEFT JOIN toko t ON t.id = ut.toko_id AND t.deleted_at IS NULL
             WHERE us.deleted_at IS NULL
             GROUP BY us.id ORDER BY us.nama"
        )->fetchAll();
    } catch (Throwable $e) { error_log('[AMANAH] ' . $e->getMessage()); }
}

$PAGE_TITLE = 'Toko'; $ACTIVE = 'toko';
require __DIR__ . '/_header.php';
?>
<div class="page-head">
  <div><h1>Master Toko</h1><p>Simpul ketiga rantai barang · <?= angka($totalBaris) ?> toko</p></div>
  <?php if ($isSuper): ?>
    <a class="btn btn-ghost" href="toko.php?akun=1"><svg aria-hidden="true"><use href="#i-kunci"></use></svg> Akun Admin Toko</a>
  <?php endif; ?>
  <?php if ($canEdit): ?><a class="btn btn-primary" href="#formTambah" onclick="bukaFormTambah(event)"><svg aria-hidden="true"><use href="#i-plus"></use></svg> Tambah Toko</a><?php endif; ?>
</div>

<?php if (!$adaTabel): ?>
  <div class="flash flash-err">
    Tabel <code>toko</code> belum ada. Jalankan <code>migrasi_amanah_v3.sql</code> lebih dulu.
  </div>
<?php else: ?>


<?php if ($akunId && $isSuper): ?>
  <div class="card" style="margin-bottom:16px;border-color:var(--primary)">
    <h3>Akun Admin Toko</h3>
    <div class="sub">Satu akun boleh memegang beberapa toko sekaligus</div>

    <div style="overflow-x:auto;margin-bottom:18px"><table class="tbl-kartu">
      <thead><tr><th>Nama</th><th>Email</th><th>Toko yang Dipegang</th><th>Status</th><th>Login Terakhir</th><th>Aksi</th></tr></thead>
      <tbody>
      <?php if (!$akunList): ?>
        <tr><td colspan="6" class="empty">Belum ada akun Admin Toko.</td></tr>
      <?php else: foreach ($akunList as $a):
        $idToko = array_filter(explode(',', (string) $a['id_toko'])); ?>
        <tr>
          <td class="judul" data-label="Nama"><b><?= e($a['nama']) ?></b></td>
          <td class="sekunder" data-label="Email"><code><?= e($a['email']) ?></code></td>
          <td data-label="Toko yang Dipegang">
            <?= $a['daftar_toko'] ? e($a['daftar_toko']) : '<span class="tag t-red">belum ada toko</span>' ?>
            <details class="mt6">
              <summary style="cursor:pointer;font-size:11.5px;color:var(--muted)">ubah akses</summary>
              <form method="post" style="margin-top:8px">
                <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="act" value="akun">
                <input type="hidden" name="sub" value="akses">
                <input type="hidden" name="user_id" value="<?= (int) $a['id'] ?>">
                <?php foreach ($list as $t): ?>
                  <label style="display:block;font-size:12.5px;margin-bottom:4px;cursor:pointer">
                    <input type="checkbox" name="toko_ids[]" value="<?= (int) $t['id'] ?>"
                           <?= in_array((string) $t['id'], $idToko, true) ? 'checked' : '' ?>>
                    <?= e($t['nama']) ?>
                  </label>
                <?php endforeach; ?>
                <button class="btn btn-ghost btn-sm mt6">Simpan Akses</button>
              </form>
            </details>
          </td>
          <td data-label="Status"><span class="tag <?= (int) $a['is_active'] === 1 ? 't-green' : 't-gray' ?>">
            <?= (int) $a['is_active'] === 1 ? 'Aktif' : 'Nonaktif' ?></span></td>
          <td class="sekunder" data-label="Login Terakhir"><?= $a['last_login_at'] ? e(tgl_jam_id($a['last_login_at'])) : '<small class="mut">belum pernah</small>' ?></td>
          <td class="nowrap aksi">
            <form method="post" style="display:inline-flex;gap:6px;align-items:center">
              <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
              <input type="hidden" name="act" value="akun"><input type="hidden" name="sub" value="reset">
              <input type="hidden" name="user_id" value="<?= (int) $a['id'] ?>">
              <input type="password" name="password_akun" placeholder="Password baru" minlength="8"
                     style="padding:6px 9px;border:1px solid var(--border);border-radius:8px;
                            background:var(--surface-2);color:var(--text);font-size:12.5px;width:140px">
              <button class="btn btn-ghost btn-sm">Reset</button>
            </form>
            <?php if ((int) $a['is_active'] === 1): ?>
              <form method="post" class="inline" data-konfirm="Nonaktifkan akun ini?" data-akibat="Akun tidak bisa dipakai masuk sampai diaktifkan lagi." data-tombol="Ya, nonaktifkan" data-warna="amber">
                <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="act" value="akun"><input type="hidden" name="sub" value="nonaktif">
                <input type="hidden" name="user_id" value="<?= (int) $a['id'] ?>">
                <button class="btn btn-red btn-sm">Nonaktifkan</button>
              </form>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; endif; ?>
      </tbody>
    </table></div>

    <div style="border-top:1px solid var(--border);padding-top:16px">
      <b style="font-size:13.5px">Buat Akun Admin Toko Baru</b>
      <form method="post" style="margin-top:10px">
        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="act" value="akun"><input type="hidden" name="sub" value="buat">
        <div class="form-grid">
          <div class="field"><label>Nama Petugas <span class="req">*</span></label>
            <input name="nama_akun" placeholder="cth: Dewi" required></div>
          <div class="field"><label>Email Login <span class="req">*</span></label>
            <input name="email_akun" type="email" placeholder="nama@contoh.com" required></div>
          <div class="field"><label>Password Awal <span class="req">*</span></label>
            <input name="password_akun" type="password" minlength="8" required placeholder="Minimal 8 karakter"></div>
          <div class="field"><label>Toko yang Boleh Diakses <span class="req">*</span></label>
            <div style="border:1px solid var(--border);border-radius:10px;padding:10px;background:var(--surface-2)">
              <?php foreach ($list as $t): ?>
                <label style="display:block;font-size:13px;margin-bottom:5px;cursor:pointer">
                  <input type="checkbox" name="toko_ids[]" value="<?= (int) $t['id'] ?>">
                  <?= e($t['nama']) ?>
                </label>
              <?php endforeach; ?>
            </div></div>
        </div>
        <button class="btn btn-primary"><svg aria-hidden="true"><use href="#i-plus"></use></svg> Buat Akun</button>
        <a class="btn btn-ghost" href="toko.php">Tutup</a>
      </form>
    </div>
  </div>
<?php endif; ?>

<div class="tumpuk">
  <div class="card">
    <?php cari_bar($q, 'Cari nama, kode, marketplace, atau alamat toko…', $q !== '' ? $totalBaris : null); ?>
    <div class="scrollx"><table class="tbl-kartu">
      <thead><tr>
        <th>Kode</th><th>Toko</th><th>Marketplace</th><th>Stok</th><th>Omzet Bln Ini</th><th>Akun</th><th>Status</th>
        <?php if ($canEdit): ?><th>Aksi</th><?php endif; ?>
      </tr></thead>
      <tbody>
      <?php if (!$list): ?>
        <tr><td colspan="8" class="empty">Belum ada toko. Tambahkan lewat form di samping.</td></tr>
      <?php else: foreach ($list as $t): [$mpLbl, $mpCls] = label_marketplace($t['marketplace']); ?>
        <tr>
          <td class="sekunder" data-label="Kode"><code><?= e($t['kode']) ?></code></td>
          <td class="judul" data-label="Toko"><b><?= e($t['nama']) ?></b>
            <?php if ($t['penanggung_jawab']): ?><br><small class="mut">PIC: <?= e($t['penanggung_jawab']) ?></small><?php endif; ?>
          </td>
          <td data-label="Marketplace"><span class="tag <?= $mpCls ?>"><?= $mpLbl ?></span></td>
          <td data-label="Stok"><b><?= angka($t['stok']) ?></b> unit<br>
            <small class="mut"><?= (int) $t['jml_barang'] ?> jenis</small></td>
          <td data-label="Omzet Bln Ini"><?= rupiah($t['omzet_bln']) ?></td>
          <td class="sekunder" data-label="Akun"><?= (int) $t['jml_akun'] > 0
                ? '<span class="tag t-green">' . (int) $t['jml_akun'] . '</span>'
                : '<span class="tag t-gray">—</span>' ?></td>
          <td data-label="Status"><span class="tag <?= (int) $t['is_active'] === 1 ? 't-green' : 't-gray' ?>">
            <?= (int) $t['is_active'] === 1 ? 'Aktif' : 'Nonaktif' ?></span></td>
          <?php if ($canEdit): ?>
          <td class="nowrap aksi">
            <a class="btn btn-ghost btn-sm" href="toko.php?edit=<?= (int) $t['id'] ?>">Edit</a>
            <form method="post" class="inline" data-konfirm="Hapus toko ini?">
              <input type="hidden" name="act" value="del">
              <input type="hidden" name="id" value="<?= (int) $t['id'] ?>">
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
        <h3><?= $row['id'] ? 'Edit Toko' : 'Tambah Toko' ?></h3>
        <div class="sub">Toko marketplace atau toko fisik</div>
      </div>
      <a class="modal-tutup" href="toko.php" data-tutup-modal
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
      <div class="field"><label>Nama Toko <span class="req">*</span></label>
        <input name="nama" value="<?= e($row['nama']) ?>" placeholder="Toko Utama Shopee" required></div>
      <div class="field"><label>Marketplace</label>
        <select name="marketplace">
          <?php foreach ($mpOpts as $mo): [$l] = label_marketplace($mo); ?>
            <option value="<?= $mo ?>" <?= ($row['marketplace'] ?? '') === $mo ? 'selected' : '' ?>><?= $l ?></option>
          <?php endforeach; ?>
        </select></div>
      <div class="field"><label>URL Toko</label>
        <input name="url_toko" value="<?= e($row['url_toko'] ?? '') ?>" placeholder="https://shopee.co.id/..."></div>
      <div class="field"><label>Penanggung Jawab</label>
        <input name="penanggung_jawab" value="<?= e($row['penanggung_jawab'] ?? '') ?>"></div>
      <div class="field"><label>Telepon</label>
        <input name="telepon" value="<?= e($row['telepon'] ?? '') ?>"></div>
      <div class="field"><label>Alamat</label>
        <input name="alamat" value="<?= e($row['alamat'] ?? '') ?>"></div>
      <div class="field"><label>Keterangan</label>
        <textarea name="keterangan" rows="2"><?= e($row['keterangan'] ?? '') ?></textarea></div>
      <div class="field">
        <label style="display:flex;align-items:center;gap:8px;cursor:pointer">
          <input type="checkbox" name="is_active" <?= (int) ($row['is_active'] ?? 1) === 1 ? 'checked' : '' ?>>
          Toko aktif
        </label></div>

      <div class="baris">
        <button class="btn btn-primary"><svg aria-hidden="true"><use href="#i-simpan"></use></svg> Simpan</button>
        <?php if ($row['id']): ?><a class="btn btn-ghost" href="toko.php">Batal</a><?php endif; ?>
      </div>
    </form>
  </div>
  <?php endif; ?>
</div>
<?php endif; ?>
<?php require __DIR__ . '/_footer.php'; ?>
