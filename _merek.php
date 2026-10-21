<?php
/* ---------------------------------------------------------------- MEREK
   Logo, favicon, dan gambar share diambil dari Pengaturan > Logo &
   Identitas. Semuanya boleh kosong: sistem lalu memakai bawaannya, jadi
   tampilan tidak pernah bergantung pada operator sudah mengunggah sesuatu.

   Blok ini dipakai bersama _header.php, index.php, dan login.php — kalau
   masing-masing menyusun meta-nya sendiri, tautan yang dibagikan dari
   halaman berbeda akan menampilkan gambar yang berbeda. */
$MEREK = [
    'logo'    => url_unggahan(pengaturan($pdo, 'logo_utama')),
    'favicon' => url_unggahan(pengaturan($pdo, 'logo_favicon')),
    'share'   => url_unggahan(pengaturan($pdo, 'gambar_share')),
    'sj'      => pengaturan($pdo, 'share_judul', ident(APP_NAMA, APP_KEPANJANGAN)),
    'st'      => pengaturan($pdo, 'share_teks',
                     'Sistem pencatatan gudang & rantai pasok: supplier → gudang → toko/penerima.'),
];

/* URL absolut wajib untuk og:image — WhatsApp dan media sosial mengambil
   gambarnya dari servernya sendiri, jadi jalur relatif tidak akan pernah
   terselesaikan. */
$SKEMA = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$AKAR  = $SKEMA . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost')
       // SCRIPT_NAME adalah jalur URL, jadi pemisahnya selalu '/' —
       // tidak perlu ikut memangkas backslash gaya Windows.
       . rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '')), '/') . '/';
?>
<?php if ($MEREK['favicon']): ?>
  <link rel="icon" href="<?= e($MEREK['favicon']) ?>">
  <link rel="apple-touch-icon" href="<?= e($MEREK['favicon']) ?>">
<?php endif; ?>
<meta property="og:type" content="website">
<meta property="og:site_name" content="<?= e(APP_NAMA) ?>">
<meta property="og:title" content="<?= e($MEREK['sj']) ?>">
<meta property="og:description" content="<?= e($MEREK['st']) ?>">
<meta name="description" content="<?= e($MEREK['st']) ?>">
<?php if ($MEREK['share']): ?>
  <meta property="og:image" content="<?= e($AKAR . $MEREK['share']) ?>">
  <meta name="twitter:card" content="summary_large_image">
  <meta name="twitter:image" content="<?= e($AKAR . $MEREK['share']) ?>">
<?php else: ?>
  <meta name="twitter:card" content="summary">
<?php endif; ?>
