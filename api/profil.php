<?php
/**
 * GET  /api/profil.php             — data profil sendiri
 * PUT  /api/profil.php             — update profil
 * GET  /api/profil.php?sub=perangkat     — daftar sesi aktif
 * DELETE /api/profil.php?sub=perangkat&sesi_id=N — akhiri sesi
 * POST /api/profil.php?sub=password — ganti password
 */
require_once __DIR__ . '/_bootstrap.php';
$payload = auth_required();
$method  = $_SERVER['REQUEST_METHOD'];
$sub     = $_GET['sub'] ?? '';
$uid     = (int) $payload['user_id'];

// ---- Perangkat / Sesi ----
if ($sub === 'perangkat') {
    if ($method === 'GET') {
        try {
            $st = $pdo->prepare(
                "SELECT id, perangkat, ip, dibuat_at, terakhir_at
                 FROM sesi_perangkat
                 WHERE user_id = ? AND diakhiri_at IS NULL
                 ORDER BY terakhir_at DESC"
            );
            $st->execute([$uid]);
            ok(['perangkat' => $st->fetchAll()]);
        } catch (Throwable $e) { err('Gagal mengambil data.', 500); }
    }
    if ($method === 'DELETE') {
        $sid = (int) ($_GET['sesi_id'] ?? 0);
        if (!$sid) { err('sesi_id wajib diisi.'); }
        // Super Admin bisa akhiri sesi siapa saja, user biasa hanya miliknya sendiri
        $filter = ($payload['role'] === 'super_admin')
            ? "id = $sid"
            : "id = $sid AND user_id = $uid";
        try {
            $pdo->exec(
                "UPDATE sesi_perangkat SET diakhiri_at = NOW(), diakhiri_oleh = $uid
                 WHERE $filter AND diakhiri_at IS NULL"
            );
            ok(['pesan' => 'Sesi berhasil diakhiri.']);
        } catch (Throwable $e) { err('Gagal mengakhiri sesi.', 500); }
    }
    err('Method tidak diizinkan.', 405);
}

// ---- Ganti Password ----
if ($sub === 'password') {
    only('POST');
    $b = body();
    $lama = $b['password_lama'] ?? '';
    $baru = $b['password_baru'] ?? '';
    if (!$lama || !$baru) { err('Password lama dan baru wajib diisi.'); }
    if (strlen($baru) < 8)  { err('Password baru minimal 8 karakter.'); }

    try {
        $st = $pdo->prepare("SELECT password FROM users WHERE id = ?");
        $st->execute([$uid]);
        $hash = $st->fetchColumn();
    } catch (Throwable $e) { err('Gagal memverifikasi.', 500); }

    if (!password_verify($lama, $hash)) { err('Password lama tidak sesuai.', 401); }

    try {
        $pdo->prepare("UPDATE users SET password = ? WHERE id = ?")
            ->execute([password_hash($baru, PASSWORD_DEFAULT), $uid]);
        ok(['pesan' => 'Password berhasil diubah.']);
    } catch (Throwable $e) { err('Gagal mengubah password.', 500); }
}

// ---- Profil ----
if ($method === 'GET') {
    try {
        $st = $pdo->prepare(
            "SELECT u.id, u.nama, u.email, u.telepon, u.gudang_id, u.supplier_id,
                    r.nama AS role, r.label AS role_label, u.tingkat,
                    g.nama AS gudang_nama, sp.nama AS supplier_nama,
                    u.last_login_at, u.created_at, u.idle_menit
             FROM users u
             LEFT JOIN roles r     ON r.id  = u.role_id
             LEFT JOIN gudang g    ON g.id  = u.gudang_id AND g.deleted_at IS NULL
             LEFT JOIN supplier sp ON sp.id = u.supplier_id
             WHERE u.id = ? AND u.deleted_at IS NULL"
        );
        $st->execute([$uid]);
        $user = $st->fetch();
    } catch (Throwable $e) { err('Gagal mengambil profil.', 500); }
    if (!$user) { err('Pengguna tidak ditemukan.', 404); }
    ok(['user' => $user]);
}

if ($method === 'PUT') {
    $b = body();
    $nama    = trim($b['nama'] ?? '');
    $telepon = trim($b['telepon'] ?? '');
    if ($nama === '') { err('Nama wajib diisi.'); }
    try {
        $pdo->prepare("UPDATE users SET nama = ?, telepon = ? WHERE id = ?")
            ->execute([$nama, $telepon, $uid]);
        ok(['pesan' => 'Profil berhasil diperbarui.']);
    } catch (Throwable $e) { err('Gagal memperbarui profil.', 500); }
}

err('Method tidak diizinkan.', 405);
