<?php
/**
 * GET    /api/pengguna.php       — list semua user (super_admin only)
 * GET    /api/pengguna.php?id=N  — detail user
 * POST   /api/pengguna.php       — tambah user
 * PUT    /api/pengguna.php?id=N  — edit user
 * DELETE /api/pengguna.php?id=N  — nonaktifkan user
 */
require_once __DIR__ . '/_bootstrap.php';
$payload = auth_required();
if ($payload['role'] !== 'super_admin') { err('Hanya Super Admin yang bisa mengelola pengguna.', 403); }

$method = $_SERVER['REQUEST_METHOD'];
$id     = isset($_GET['id']) ? (int) $_GET['id'] : null;

if ($method === 'GET') {
    if ($id) {
        try {
            $st = $pdo->prepare(
                "SELECT u.id, u.email, u.nama, u.telepon, r.nama AS role, r.label AS role_label,
                        u.tingkat, u.gudang_id, u.supplier_id, u.is_active, u.idle_menit,
                        u.created_at, u.last_login_at
                 FROM users u
             LEFT JOIN roles r ON r.id = u.role_id WHERE u.id = ? AND u.deleted_at IS NULL"
            );
            $st->execute([$id]);
            $user = $st->fetch();
        } catch (Throwable $e) { err('Gagal mengambil data.', 500); }
        if (!$user) { err('Pengguna tidak ditemukan.', 404); }

        // Toko yang dikaitkan
        try {
            $stToko = $pdo->prepare(
                "SELECT t.id, t.kode, t.nama FROM user_toko ut
                 JOIN toko t ON t.id = ut.toko_id AND t.deleted_at IS NULL
                 WHERE ut.user_id = ?"
            );
            $stToko->execute([$id]);
            $user['toko'] = $stToko->fetchAll();
        } catch (Throwable $e) { $user['toko'] = []; }

        ok(['user' => $user]);
    }

    // List
    [$limit, $offset] = paginate(30);
    $cari  = trim($_GET['q'] ?? '');
    $roleF = $_GET['role'] ?? '';
    $where  = ['u.deleted_at IS NULL'];
    $params = [];
    if ($cari) { $where[] = '(u.nama LIKE ? OR u.email LIKE ? OR u.email LIKE ?)'; $like = '%'.$cari.'%'; $params = array_merge($params, [$like,$like,$like]); }
    if ($roleF) { $where[] = 'r.nama = ?'; $params[] = $roleF; }
    $wSql = implode(' AND ', $where);
    try {
        $stCount = $pdo->prepare("SELECT COUNT(*) FROM users u
             LEFT JOIN roles r ON r.id = u.role_id WHERE $wSql");
        $stCount->execute($params);
        $total = (int) $stCount->fetchColumn();

        $st = $pdo->prepare(
            "SELECT u.id, u.email, u.nama, u.telepon, r.nama AS role, r.label AS role_label,
                    u.tingkat, u.is_active, u.last_login_at,
                    g.nama AS gudang_nama, sp.nama AS supplier_nama
             FROM users u
             LEFT JOIN roles r ON r.id = u.role_id
             LEFT JOIN gudang g    ON g.id  = u.gudang_id AND g.deleted_at IS NULL
             LEFT JOIN supplier sp ON sp.id = u.supplier_id
             WHERE $wSql ORDER BY u.nama ASC LIMIT $limit OFFSET $offset"
        );
        $st->execute($params);
        ok(['data' => $st->fetchAll(), 'total' => $total]);
    } catch (Throwable $e) { error_log("[AMANAH API] " . basename(__FILE__) . ": " . $e->getMessage()); err("Terjadi kesalahan server.", 500); }
}

/**
 * Terjemahkan nama peran menjadi role_id.
 *
 * Tabel `users` TIDAK punya kolom `role` — perannya lewat role_id yang
 * menunjuk tabel `roles`. Versi sebelumnya menulis langsung ke kolom
 * `role` (dan `username`, dan `dibuat_oleh`), tiganya sama-sama tidak
 * ada, sehingga menambah maupun menyunting pengguna selalu gagal.
 */
function role_id_dari(PDO $pdo, string $nama): int {
    $st = $pdo->prepare("SELECT id FROM roles WHERE nama = ?");
    $st->execute([$nama]);
    $rid = $st->fetchColumn();
    if ($rid === false) {
        $ada = $pdo->query("SELECT nama FROM roles ORDER BY nama")->fetchAll(PDO::FETCH_COLUMN);
        throw new RuntimeException("Peran '$nama' tidak dikenal. Yang tersedia: " . implode(', ', $ada) . '.');
    }
    return (int) $rid;
}

/** Hanya 'manager' yang diakui; selain itu jatuh ke wewenang terkecil. */
function tingkat_dari($nilai): string {
    return $nilai === 'manager' ? 'manager' : 'staff';
}

if ($method === 'POST') {
    $b        = body();
    $nama     = trim($b['nama']     ?? '');
    $email    = trim($b['email']    ?? '');
    $role     = $b['role']     ?? '';
    $password = $b['password'] ?? '';

    if (!$nama || !$email || !$role || !$password) {
        err('Nama, email, peran, dan kata sandi wajib diisi untuk pengguna baru.');
    }
    if (strlen($password) < 8) { err('Kata sandi minimal 8 karakter.'); }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) { err('Format email tidak valid.'); }

    try {
        $roleId = role_id_dari($pdo, $role);

        $pdo->beginTransaction();
        $pdo->prepare(
            "INSERT INTO users (role_id,tingkat,nama,email,telepon,password,
                                gudang_id,supplier_id,idle_menit,is_active)
             VALUES (?,?,?,?,?,?,?,?,?,1)"
        )->execute([
            $roleId,
            tingkat_dari($b['tingkat'] ?? 'staff'),
            $nama, $email,
            trim($b['telepon'] ?? '') ?: null,
            password_hash($password, PASSWORD_DEFAULT),
            !empty($b['gudang_id'])   ? (int) $b['gudang_id']   : null,
            !empty($b['supplier_id']) ? (int) $b['supplier_id'] : null,
            (int) ($b['idle_menit'] ?? 30),
        ]);
        $newId = (int) $pdo->lastInsertId();

        if (!empty($b['toko']) && is_array($b['toko'])) {
            $stT = $pdo->prepare("INSERT IGNORE INTO user_toko (user_id,toko_id) VALUES (?,?)");
            foreach ($b['toko'] as $tokoId) { $stT->execute([$newId, (int) $tokoId]); }
        }
        $pdo->commit();

        ok(['id' => $newId, 'pesan' => "Pengguna $nama berhasil ditambahkan."]);

    } catch (RuntimeException $e) {
        if ($pdo->inTransaction()) { $pdo->rollBack(); }
        if (str_contains($e->getMessage(), 'Duplicate')) { err('Email itu sudah digunakan akun lain.'); }
        err(pesan_api($e));
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) { $pdo->rollBack(); }
        if (str_contains($e->getMessage(), 'Duplicate')) { err('Email itu sudah digunakan akun lain.'); }
        error_log('[AMANAH API] pengguna tambah: ' . $e->getMessage());
        err('Gagal menambah pengguna.', 500);
    }
}

if ($method === 'PUT') {
    if (!$id) { err('id wajib diisi.'); }
    $b     = body();
    $nama  = trim($b['nama']  ?? '');
    $email = trim($b['email'] ?? '');
    $role  = $b['role'] ?? '';
    if (!$nama || !$email || !$role) { err('Nama, email, dan peran wajib diisi.'); }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) { err('Format email tidak valid.'); }

    try {
        $roleId = role_id_dari($pdo, $role);

        /* Kolom dan nilainya dipasangkan berbarengan.

           Versi sebelumnya menyusun daftar nilai lalu menyaringnya dengan
           array_filter(..., $v !== null). Menghapus satu nilai di tengah
           MENGGESER seluruh sisanya: kalau gudang_id kosong, nilai
           supplier_id mendarat di kolom gudang_id, dan yang paling
           berbahaya — $id di urutan terakhir ikut bergeser, sehingga
           WHERE id=? menunjuk pengguna yang salah. Menyunting satu akun
           bisa diam-diam mengubah akun orang lain. */
        $set = ['role_id=?', 'tingkat=?', 'nama=?', 'email=?', 'telepon=?',
                'gudang_id=?', 'supplier_id=?', 'idle_menit=?', 'is_active=?'];
        $par = [
            $roleId,
            tingkat_dari($b['tingkat'] ?? 'staff'),
            $nama, $email,
            trim($b['telepon'] ?? '') ?: null,
            !empty($b['gudang_id'])   ? (int) $b['gudang_id']   : null,
            !empty($b['supplier_id']) ? (int) $b['supplier_id'] : null,
            (int) ($b['idle_menit'] ?? 30),
            (int) ($b['is_active'] ?? 1),
        ];
        if (!empty($b['password'])) {
            if (strlen($b['password']) < 8) { err('Kata sandi minimal 8 karakter.'); }
            $set[] = 'password=?';
            $par[] = password_hash($b['password'], PASSWORD_DEFAULT);
        }
        $par[] = $id;

        $pdo->beginTransaction();
        $st = $pdo->prepare("UPDATE users SET " . implode(',', $set)
                          . " WHERE id=? AND deleted_at IS NULL");
        $st->execute($par);
        if ($st->rowCount() === 0) {
            $cek = $pdo->prepare("SELECT id FROM users WHERE id=? AND deleted_at IS NULL");
            $cek->execute([$id]);
            if (!$cek->fetch()) { $pdo->rollBack(); err('Pengguna tidak ditemukan.', 404); }
        }

        if (isset($b['toko'])) {
            $pdo->prepare("DELETE FROM user_toko WHERE user_id=?")->execute([$id]);
            $stT = $pdo->prepare("INSERT IGNORE INTO user_toko (user_id,toko_id) VALUES (?,?)");
            foreach ((array) $b['toko'] as $tokoId) { $stT->execute([$id, (int) $tokoId]); }
        }
        $pdo->commit();

        ok(['pesan' => "Pengguna $nama berhasil diperbarui."]);

    } catch (RuntimeException $e) {
        if ($pdo->inTransaction()) { $pdo->rollBack(); }
        if (str_contains($e->getMessage(), 'Duplicate')) { err('Email itu sudah digunakan akun lain.'); }
        err(pesan_api($e));
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) { $pdo->rollBack(); }
        if (str_contains($e->getMessage(), 'Duplicate')) { err('Email itu sudah digunakan akun lain.'); }
        error_log('[AMANAH API] pengguna ubah: ' . $e->getMessage());
        err('Gagal memperbarui pengguna.', 500);
    }
}

if ($method === 'DELETE') {
    if (!$id) { err('id wajib diisi.'); }
    if ($id === (int) $payload['user_id']) { err('Tidak bisa menonaktifkan akun sendiri.'); }
    try {
        $pdo->prepare("UPDATE users SET is_active=0, deleted_at=NOW() WHERE id=? AND deleted_at IS NULL")
            ->execute([$id]);
        ok(['pesan' => 'Pengguna berhasil dinonaktifkan.']);
    } catch (Throwable $e) { error_log("[AMANAH API] " . basename(__FILE__) . ": " . $e->getMessage()); err("Terjadi kesalahan server.", 500); }
}

err('Method tidak diizinkan.', 405);
