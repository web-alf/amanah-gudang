<?php
/**
 * GET /api/notifikasi.php
 *
 * Daftar hal yang menunggu tindakan pemegang token — diturunkan dari
 * keadaan data, bukan dibaca dari tabel. Logikanya ada di _notifikasi.php
 * (akar aplikasi) dan dipakai juga oleh halaman web notifikasi.php, supaya
 * ponsel dan peramban tidak pernah menampilkan daftar yang berbeda untuk
 * akun yang sama.
 *
 * Respons: { ok, data: [ {tipe, judul, pesan, rute, jumlah, tingkat} ],
 *            jumlah, penting }
 */
require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/_lingkup.php';
require_once __DIR__ . '/../_lib.php';
require_once __DIR__ . '/../_notifikasi.php';
only('GET');

$p = auth_required();

try {
    ok(susun_notifikasi($pdo, $p));
} catch (Throwable $e) {
    error_log('[AMANAH API] notifikasi: ' . $e->getMessage());
    err('Gagal menyusun daftar notifikasi.', 500);
}
