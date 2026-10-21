<?php
/**
 * AMANAH — Ganti Password Sendiri
 *
 * Berlaku untuk semua peran, termasuk supplier. Wajib memasukkan password
 * lama, sehingga sesi yang tertinggal terbuka di komputer gudang tidak bisa
 * dipakai orang lain untuk membajak akun.
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/_lib.php';
require_login();

$u      = current_user();
$errors = [];
$sukses = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    wajib_csrf($_POST['csrf'] ?? null);

    $lama    = $_POST['password_lama'] ?? '';
    $baru    = $_POST['password_baru'] ?? '';
    $ulangi  = $_POST['password_ulangi'] ?? '';

    if ($lama === '' || $baru === '') {
        $errors[] = 'Password lama dan password baru wajib diisi.';
    } elseif (strlen($baru) < 8) {
        $errors[] = 'Password baru minimal 8 karakter.';
    } elseif ($baru !== $ulangi) {
        $errors[] = 'Konfirmasi password baru tidak sama.';
    } elseif ($baru === $lama) {
        $errors[] = 'Password baru harus berbeda dari password lama.';
    } elseif (in_array(strtolower($baru), ['password', '12345678', 'amanah', 'gudang', 'gudangbayi'], true)) {
        $errors[] = 'Password terlalu mudah ditebak. Pilih yang lain.';
    }

    if (!$errors) {
        try {
            $st = $pdo->prepare("SELECT password FROM users WHERE id = ? AND deleted_at IS NULL");
            $st->execute([$u['id']]);
            $hash = $st->fetchColumn();

            if (!$hash || !password_verify($lama, $hash)) {
                $errors[] = 'Password lama salah.';
                catat_aktivitas($pdo, 'update', 'auth', (int) $u['id'], 'Gagal ganti password: password lama salah');
            } else {
                $pdo->prepare("UPDATE users SET password = ? WHERE id = ?")
                    ->execute([password_hash($baru, PASSWORD_BCRYPT), $u['id']]);

                // Perbarui sesi supaya cookie lama tidak bisa dipakai ulang.
                session_regenerate_id(true);

                catat_aktivitas($pdo, 'update', 'auth', (int) $u['id'], 'Ganti password sendiri');
                $sukses = true;
            }
        } catch (Throwable $e) {
            $errors[] = pesan_error_aman($e, 'ganti password');
        }
    }
}

/* Menu per peran tidak lagi dipasang di sini — _header.php yang memilihnya
   berdasarkan peran, jadi satu-satunya daftarnya ada di satu tempat. */

$PAGE_TITLE = 'Ganti Password'; $ACTIVE = 'sandi';
require __DIR__ . '/_header.php';
?>
<div class="page-head">
  <div><h1>Ganti Password</h1><p>Untuk akun Anda sendiri: <?= e($u['email']) ?></p></div>
  <a class="btn btn-ghost" href="<?= beranda_peran() ?>">← Kembali</a>
</div>

<?php if ($sukses): ?>
  <div class="flash flash-ok">✅ Password berhasil diganti. Gunakan password baru pada login berikutnya.</div>
<?php endif; ?>
<?php if ($errors): ?>
  <div class="flash flash-err"><?php foreach ($errors as $er) echo '• ' . e($er) . '<br>'; ?></div>
<?php endif; ?>

<div class="card" style="max-width:480px">
  <h3>Password Baru</h3>
  <div class="sub">Wajib memasukkan password lama sebagai pengaman</div>
  <form method="post">
    <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
    <div class="field"><label>Password Lama <span class="req">*</span></label>
      <input type="password" name="password_lama" required autofocus autocomplete="current-password"></div>
    <div class="field"><label>Password Baru <span class="req">*</span></label>
      <input type="password" name="password_baru" minlength="8" required autocomplete="new-password"
             placeholder="Minimal 8 karakter"></div>
    <div class="field"><label>Ulangi Password Baru <span class="req">*</span></label>
      <input type="password" name="password_ulangi" minlength="8" required autocomplete="new-password"></div>
    <button class="btn btn-primary"><svg aria-hidden="true"><use href="#i-simpan"></use></svg> Simpan Password Baru</button>
  </form>
  <p style="color:var(--muted);font-size:12px;margin-top:14px;line-height:1.7">
    🔒 Jangan pakai password yang sama dengan akun lain, dan jangan menuliskannya di grup terbuka.
    Bila Anda Super Admin dan lupa password, lihat <code>db/arsip_sql/RESET_PASSWORD.sql</code> (dijalankan lewat phpMyAdmin).
  </p>
</div>
<?php require __DIR__ . '/_footer.php'; ?>
