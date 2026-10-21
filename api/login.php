<?php
/**
 * POST /api/login.php
 * Body     : { "email": "...", "password": "..." }
 * Respons  : { "ok": true, "token": "...", "user": { ... } }
 *
 * ---------------------------------------------------------------------
 * CATATAN PERBAIKAN
 * ---------------------------------------------------------------------
 * Versi sebelumnya menanyakan kolom `username`, `role`, dan `last_login`
 * pada tabel `users`. Ketiganya TIDAK ADA di skema ini:
 *
 *   - identitas masuk memakai `email` (sama dengan aplikasi web)
 *   - nama peran ada di tabel `roles`, dihubungkan lewat `users.role_id`
 *   - kolom waktu masuk terakhir bernama `last_login_at`
 *
 * Akibatnya setiap percobaan masuk melempar PDOException, tertangkap
 * catch, dan dibalas "Terjadi kesalahan server." — pesan yang benar
 * secara teknis tapi tidak menyebut sebab apa pun, sehingga masalahnya
 * tampak seperti gangguan server padahal kueri yang salah.
 */
require_once __DIR__ . '/_bootstrap.php';
only('POST');

$b = body();

/* `email` adalah nama resminya. `username` tetap diterima karena versi
   pertama dokumentasi API menyebut nama itu, dan klien yang sudah terlanjur
   memakainya tidak perlu ikut berubah hanya karena namanya dirapikan. */
$idMasuk  = trim($b['email'] ?? $b['username'] ?? '');
$password = (string) ($b['password'] ?? '');

if ($idMasuk === '' || $password === '') {
    err('Email dan kata sandi wajib diisi.');
}

/* Pembatas percobaan — pembatas yang SAMA dengan halaman masuk web.
   Endpoint ini dulu tanpa pembatas sama sekali, jadi menebak sandi lewat
   API tidak pernah terhambat walaupun halaman webnya mengunci. */
require_once __DIR__ . '/../_pembatas.php';
$sisaKunci = pembatas_login_terkunci($pdo, $idMasuk);
if ($sisaKunci > 0) {
    err(pembatas_pesan($sisaKunci), 429);
}

try {
    $st = $pdo->prepare(
        "SELECT u.id, u.nama, u.email, u.password, u.gudang_id, u.supplier_id,
                u.is_active, u.idle_menit, u.tingkat,
                r.nama  AS role,
                r.label AS role_label
         FROM users u
         LEFT JOIN roles r ON r.id = u.role_id
         WHERE u.email = ? AND u.deleted_at IS NULL
         LIMIT 1"
    );
    $st->execute([$idMasuk]);
    $user = $st->fetch();
} catch (Throwable $e) {
    error_log('[AMANAH API] login: ' . $e->getMessage());
    err('Terjadi kesalahan server.', 500);
}

/* Pesan yang sama untuk "email tidak ada" dan "kata sandi salah".
   Membedakannya memberi tahu penyerang email mana yang terdaftar — dan
   daftar email pengurus lembaga bukan sesuatu yang perlu bisa diuji dari
   luar satu per satu. */
if (!$user || !password_verify($password, $user['password'])) {
    pembatas_login_gagal($pdo, $idMasuk, 'api');
    err('Email atau kata sandi salah.', 401);
}

if ((int) $user['is_active'] !== 1) {
    err('Akun ini sedang dinonaktifkan. Hubungi Super Admin.', 403);
}
pembatas_login_bersih($pdo, $idMasuk);

// Gagal mencatat waktu masuk tidak boleh menggagalkan proses masuknya.
try {
    $pdo->prepare("UPDATE users SET last_login_at = NOW() WHERE id = ?")
        ->execute([$user['id']]);
} catch (Throwable $e) {
    error_log('[AMANAH API] catat last_login_at: ' . $e->getMessage());
}

/* Tingkat ikut ke dalam token, bukan hanya ke badan respons.
   Endpoint lain memutuskan izin dari isi token — kalau tingkat hanya
   dikirim ke aplikasi, aplikasi bisa menyembunyikan tombolnya tapi server
   tetap menerima permintaannya dari klien mana pun. Nilai selain 'manager'
   jatuh ke 'staff', wewenang paling kecil. */
$tingkat = ($user['tingkat'] ?? '') === 'manager' ? 'manager' : 'staff';

$token = jwt_encode([
    'user_id'     => (int) $user['id'],
    'email'       => $user['email'],
    'role'        => $user['role'],
    'tingkat'     => $tingkat,
    'gudang_id'   => $user['gudang_id'] !== null ? (int) $user['gudang_id'] : null,
    'supplier_id' => $user['supplier_id'] !== null ? (int) $user['supplier_id'] : null,
]);

ok([
    'token' => $token,
    'user'  => [
        'id'          => (int) $user['id'],
        'nama'        => $user['nama'],
        'email'       => $user['email'],
        'role'        => $user['role'],
        'role_label'  => $user['role_label'],
        'tingkat'     => $tingkat,
        'gudang_id'   => $user['gudang_id'] !== null ? (int) $user['gudang_id'] : null,
        'supplier_id' => $user['supplier_id'] !== null ? (int) $user['supplier_id'] : null,
    ],
]);
