<?php
/**
 * AMANAH — Keluar dari sesi
 */
require __DIR__ . '/auth.php';

if (!empty($_SESSION['user_id'])) {
    try {
        require __DIR__ . '/config.php';
        require __DIR__ . '/_lib.php';

        // Bebaskan slot perangkatnya SEKARANG, jangan menunggu 30 menit.
        // Inilah cara benar untuk berpindah perangkat saat batasnya sudah
        // penuh — dan satu-satunya cara yang bisa dilakukan sendiri tanpa
        // meminta bantuan Super Admin.
        akhiri_perangkat_ini($pdo);

        $pdo->prepare(
            "INSERT INTO activity_log (user_id, aksi, modul, deskripsi, ip_address)
             VALUES (?, 'logout', 'auth', 'Logout', ?)"
        )->execute([$_SESSION['user_id'], $_SERVER['REMOTE_ADDR'] ?? null]);
    } catch (Throwable $ex) {
        error_log('[AMANAH] logout log: ' . $ex->getMessage());
    }
}

// Hancurkan sesi sepenuhnya.
$_SESSION = [];
if (ini_get('session.use_cookies')) {
    /* Bentuk ARRAY, bukan tujuh argumen — supaya samesite ikut disebut.
       Peramban mencocokkan atribut saat menimpa cookie, dan cookie yang
       dipasang dengan SameSite=Lax lalu dihapus tanpa menyebutnya bisa
       tertinggal di sebagian peramban. Sejak umurnya panjang, cookie yang
       gagal terhapus berarti orang menekan Keluar lalu tetap masuk. */
    $p = session_get_cookie_params();
    setcookie(session_name(), '', [
        'expires'  => time() - 42000,
        'path'     => $p['path'] ?: '/',
        'domain'   => $p['domain'] ?? '',
        'secure'   => !empty($_SERVER['HTTPS']),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
}
session_destroy();

header('Location: login.php');
exit;
