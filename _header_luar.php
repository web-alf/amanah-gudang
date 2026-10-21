<?php
/**
 * AMANAH — Jembatan tampilan untuk dasbor Supplier & Toko.
 *
 * Sejak seluruh peran memakai kerangka yang sama, berkas ini tidak lagi
 * memuat tata letaknya sendiri — cukup menerjemahkan variabel gaya lama
 * ($MENU_LUAR, $AKTIF, $PEMILIH_TOKO) ke variabel _header.php, lalu
 * meneruskannya. Halaman yang sudah ada tidak perlu diubah.
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/_lib.php';

$MENU_OVERRIDE = $MENU_LUAR    ?? null;
$ACTIVE        = $AKTIF        ?? '';
$EKSTRA_TOPBAR = $PEMILIH_TOKO ?? '';

require __DIR__ . '/_header.php';
