<?php
/**
 * AMANAH — Pengalihan ke halaman otorisasi Shopee
 *
 * Berkas terpisah, dan itu disengaja. URL otorisasi memuat tanda tangan
 * HMAC yang dibuat dari partner_key. Kalau URL itu ditanam sebagai atribut
 * href di halaman daftar, ia akan:
 *   • tersimpan di riwayat peramban,
 *   • ikut terkirim sebagai Referer ke Shopee,
 *   • terlihat oleh siapa pun yang membaca sumber halaman.
 *
 * Dengan dibuat saat diklik lalu langsung dialihkan, tanda tangannya hanya
 * hidup selama satu pengalihan.
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/_lib.php';
require_once __DIR__ . '/_shopee.php';
require_role(['super_admin']);

$akun = shopee_akun($pdo, (int) ($_GET['akun'] ?? 0));

if (!$akun || $akun['platform'] !== 'shopee') {
    set_flash('err', 'Sambungan tidak ditemukan.');
    header('Location: marketplace.php'); exit;
}
if (empty($akun['partner_id']) || empty($akun['partner_key'])) {
    set_flash('err', 'Partner ID dan Partner Key harus terisi sebelum otorisasi.');
    header('Location: marketplace.php'); exit;
}

/* Parameter `state`: nilai acak yang disimpan di sesi dan harus kembali
   utuh bersama `code`. Tanpa ini, orang lain bisa membuat Super Admin
   membuka marketplace.php?code=...&shop_id=... buatannya, dan aplikasi
   akan menukar kode itu — menyambungkan toko yang salah tanpa disadari.
   Shopee meneruskan query string pada URL redirect apa adanya. */
$state = bin2hex(random_bytes(16));
$_SESSION['shopee_state'] = ['nilai' => $state, 'akun' => (int) $akun['id'], 'sampai' => time() + 900];

$kembali = url_penuh('marketplace.php') . '?state=' . $state;

catat_aktivitas($pdo, 'update', 'marketplace_akun', (int) $akun['id'],
    'Mulai otorisasi Shopee');

header('Location: ' . shopee_url_otorisasi($akun, $kembali));
exit;
