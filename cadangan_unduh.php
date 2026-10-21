<?php
/**
 * AMANAH — Unduh satu berkas cadangan (Super Admin).
 * Folder cadangan_berkas/ tertutup dari HTTP; berkasnya dialirkan lewat sini supaya
 * hak aksesnya diperiksa dulu dan nama berkasnya tidak bisa dipakai menjelajah folder.
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/_lib.php';
require_once __DIR__ . '/_cadangan.php';
require_role(['super_admin']);

$nama = (string) ($_GET['f'] ?? '');
$path = cadangan_dir() . '/' . $nama;
if (!cadangan_nama_sah($nama) || !is_file($path)) {
    http_response_code(404);
    die('<p style="font-family:sans-serif;padding:30px">Berkas cadangan tidak ditemukan. <a href="cadangan.php">Kembali</a></p>');
}
catat_aktivitas($pdo, 'export', 'cadangan', null, "Unduh cadangan $nama");
header('Content-Type: application/gzip');
header('Content-Disposition: attachment; filename="' . $nama . '"');
header('Content-Length: ' . filesize($path));
header('Cache-Control: no-store');
readfile($path);
