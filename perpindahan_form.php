<?php
/**
 * AMANAH — Buat Perpindahan Barang (halaman penuh)
 *
 * Dokumen dibuat dengan status `menunggu_persetujuan` (atau `draft`). Stok
 * TIDAK berubah di sini — perubahannya baru terjadi saat dokumen disetujui di
 * persetujuan.php. Itulah yang membuat setiap pergerakan barang bisa
 * ditelusuri dan tidak bisa diubah diam-diam.
 *
 * Tetap ada meski membuat perpindahan sekarang bisa lewat popup di
 * perpindahan.php, karena halaman ini melayani tiga hal yang popup tidak bisa:
 *
 *   • PREFILL DARI KIRIMAN SUPPLIER — ?kiriman=… mengisi formulir dari
 *     kiriman yang sudah discan. Tautan itu dikirim dari halaman Kiriman
 *     Supplier dan harus bisa dibuka langsung.
 *   • TAUTAN YANG BISA DIBAGIKAN — bisa disalin, dibuka di tab baru, dan
 *     ditandai. Popup tidak punya alamat sendiri.
 *   • JALAN CADANGAN — bila JavaScript gagal dimuat, popup tidak terbuka.
 *     Tombol "Buat Perpindahan" tetap menjadi tautan ke halaman ini.
 *
 * Logika, markup, dan skripnya TIDAK ada di sini — ketiganya di
 * _perpindahan_form.php / _perpindahan_isian.php / _perpindahan_js.php,
 * dipakai bersama dengan popup. Lihat catatan di berkas pertama.
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/_lib.php';

/* Penanda aksi harus disetel SEBELUM logikanya disertakan — penangan POST di
   dalamnya hanya bekerja untuk kiriman yang penandanya cocok. */
$ppAksi  = 'buat_perpindahan';
$aksiUrl = 'perpindahan_form.php';
require __DIR__ . '/_perpindahan_form.php';

$PAGE_TITLE = 'Buat Perpindahan'; $ACTIVE = 'perpindahan';
require __DIR__ . '/_header.php';
?>
<div class="page-head">
  <div><h1>Buat Perpindahan Barang</h1>
    <p>Stok berubah setelah dokumen disetujui — bukan saat disimpan</p></div>
  <a class="btn btn-ghost" href="perpindahan.php">← Kembali</a>
</div>

<?php if ($errors): ?>
  <div class="flash flash-err" data-tetap="1">
    <?php foreach ($errors as $er) echo '• ' . e($er) . '<br>'; ?>
  </div>
<?php endif; ?>

<?php if ($prefill): ?>
  <div class="flash flash-ok" data-tetap="1">
    📦 Form diisi otomatis dari kiriman <b><?= e($prefill['no_pengiriman']) ?></b>
    dari <b><?= e($prefill['nama_supplier']) ?></b>
    <?= $prefill['no_resi'] ? '· Resi <code>' . e($prefill['no_resi']) . '</code>' : '' ?>.
    Periksa jumlah fisiknya sebelum menyimpan.
  </div>
<?php endif; ?>

<?php require __DIR__ . '/_perpindahan_isian.php'; ?>
<?php require __DIR__ . '/_perpindahan_js.php'; ?>
<?php require __DIR__ . '/_footer.php'; ?>
