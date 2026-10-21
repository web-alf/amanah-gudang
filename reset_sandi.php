<?php
/**
 * AMANAH — Setel ulang kata sandi lewat tautan sekali pakai
 *
 * Sistem ini tidak punya pengirim email, jadi "lupa kata sandi" tidak bisa
 * ditangani sendiri oleh pemilik akun. Yang bisa: Super Admin membuat
 * tautan dari Kelola Pengguna (tombol Reset) lalu mengirimkannya. Tautan
 * itu membawa token acak 24 byte; yang tersimpan di basis data hanya
 * hash-nya, berlaku 60 menit, dan mati begitu dipakai.
 *
 * Halaman ini publik (tidak butuh login) — pemiliknya justru sedang tidak
 * bisa masuk. Karena itu ia tidak menyebut nama akun sebelum token-nya
 * terbukti sah, dan token yang salah dijawab dengan pesan yang sama
 * dengan token yang kedaluwarsa.
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/_lib.php';

$token = trim((string) ($_GET['t'] ?? $_POST['t'] ?? ''));
$akun  = null; $error = ''; $selesai = false;

if (preg_match('/^[a-f0-9]{48}$/', $token)) {
    try {
        $st = $pdo->prepare(
            "SELECT r.user_id, u.nama, u.email
               FROM reset_sandi r JOIN users u ON u.id = r.user_id
              WHERE r.token_hash = ? AND r.dipakai_at IS NULL AND r.sampai > NOW()
                AND u.deleted_at IS NULL AND u.is_active = 1");
        $st->execute([hash('sha256', $token)]);
        $akun = $st->fetch() ?: null;
    } catch (Throwable $e) {
        error_log('[AMANAH] reset sandi baca: ' . $e->getMessage());
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $akun) {
    if (!check_csrf($_POST['csrf'] ?? null)) {
        $error = 'Sesi tidak valid. Muat ulang halaman lalu coba lagi.';
    } else {
        $p1 = (string) ($_POST['password'] ?? '');
        $p2 = (string) ($_POST['password_ulangi'] ?? '');
        if (strlen($p1) < 8)   { $error = 'Kata sandi minimal 8 karakter.'; }
        elseif ($p1 !== $p2)   { $error = 'Kedua kata sandi tidak sama.'; }
        else {
            try {
                $pdo->beginTransaction();
                $pdo->prepare("UPDATE users SET password = ? WHERE id = ?")
                    ->execute([password_hash($p1, PASSWORD_BCRYPT), (int) $akun['user_id']]);
                $pdo->prepare("UPDATE reset_sandi SET dipakai_at = NOW() WHERE token_hash = ?")
                    ->execute([hash('sha256', $token)]);
                /* Semua sesi lama akun itu diakhiri: kalau kata sandinya
                   diganti karena dicurigai bocor, sesi yang sedang dipegang
                   orang lain harus ikut mati. */
                try {
                    $pdo->prepare("UPDATE sesi_perangkat SET diakhiri_at = NOW(), diakhiri_oleh = ?
                                   WHERE user_id = ? AND diakhiri_at IS NULL")
                        ->execute([(int) $akun['user_id'], (int) $akun['user_id']]);
                } catch (Throwable $e) { /* tabel sesi belum ada di server ini */ }
                $pdo->commit();
                $_SESSION['user_id'] = $_SESSION['user_id'] ?? null;
                catat_aktivitas($pdo, 'update', 'auth', (int) $akun['user_id'],
                    'Kata sandi disetel ulang lewat tautan reset: ' . $akun['nama']);
                $selesai = true;
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) { $pdo->rollBack(); }
                $error = pesan_error_aman($e, 'reset sandi');
            }
        }
    }
}
?><!doctype html>
<html lang="id">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Setel Ulang Kata Sandi · <?= e(APP_NAMA) ?></title>
<link rel="stylesheet" href="assets/amanah.css?v=<?= (int) @filemtime(__DIR__ . '/assets/amanah.css') ?>">
<style>
  body{ min-height:100vh; display:grid; place-items:center; padding:24px; background:var(--bg) }
  .kotak{ width:100%; max-width:420px }
  .kotak .card{ padding:26px }
  .kotak h1{ font-size:20px; margin:0 0 4px }
  .kotak p.sub{ color:var(--muted); font-size:13px; margin:0 0 18px; line-height:1.6 }
  .kotak label{ display:block; font-size:12px; font-weight:600; margin:12px 0 5px; color:var(--text-2) }
  .kotak input{ width:100%; padding:11px 12px; border:1px solid var(--border-kuat); border-radius:10px;
    background:var(--surface-2); color:var(--text); font:inherit }
  .kotak .btn{ width:100%; justify-content:center; margin-top:18px }
  .err{ background:rgba(239,68,68,.12); border:1px solid rgba(239,68,68,.35); color:var(--red-t);
    padding:10px 12px; border-radius:10px; font-size:13px; margin-bottom:12px }
  .ok{ background:rgba(16,185,129,.12); border:1px solid rgba(16,185,129,.35); color:var(--green-t);
    padding:12px; border-radius:10px; font-size:13px; line-height:1.6 }
</style>
</head>
<body>
<div class="kotak"><div class="card">
  <h1><?= e(APP_NAMA) ?> · Setel Ulang Kata Sandi</h1>
  <?php if ($selesai): ?>
    <p class="sub">Selesai.</p>
    <div class="ok">Kata sandi <b><?= e($akun['nama']) ?></b> sudah diganti. Semua sesi lama akun ini
      diakhiri. <a href="login.php">Masuk sekarang →</a></div>
  <?php elseif (!$akun): ?>
    <p class="sub">Tautan ini tidak berlaku.</p>
    <div class="err">Tautan reset tidak dikenali, sudah dipakai, atau sudah lewat 60 menit.
      Minta Super Admin membuat tautan baru dari Kelola Pengguna.</div>
    <a class="btn btn-ghost" href="login.php">Ke halaman masuk</a>
  <?php else: ?>
    <p class="sub">Untuk akun <b><?= e($akun['email']) ?></b>. Tautan ini berlaku sekali.</p>
    <?php if ($error): ?><div class="err"><?= e($error) ?></div><?php endif; ?>
    <form method="post" autocomplete="off">
      <input type="hidden" name="t" value="<?= e($token) ?>">
      <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
      <label>Kata sandi baru</label>
      <input type="password" name="password" minlength="8" required autocomplete="new-password" autofocus>
      <label>Ulangi kata sandi baru</label>
      <input type="password" name="password_ulangi" minlength="8" required autocomplete="new-password">
      <button class="btn btn-primary" type="submit">Simpan Kata Sandi</button>
    </form>
  <?php endif; ?>
</div></div>
</body>
</html>
