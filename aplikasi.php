<?php
/**
 * AMANAH — Unduh Aplikasi Android
 *
 * Satu halaman untuk SEMUA peran yang menjawab "di mana saya ambil
 * aplikasinya": versi yang tersedia, ukurannya, apa yang baru, tombol
 * unduh, dan kode QR yang tinggal dipindai ponsel — karena orang yang
 * membuka halaman ini di komputer justru butuh berkasnya di ponsel.
 *
 * Halaman ini bisa dibuka TANPA masuk bila alamatnya diketahui, sama
 * seperti halaman depan: petugas baru yang belum punya akun pun perlu
 * memasang aplikasinya dulu. Yang ditampilkan hanya versi dan catatan
 * rilis, tidak ada data lembaga.
 *
 * Sumber datanya sama dengan api/versi_aplikasi.php: kunci apk_* di tabel
 * pengaturan (diisi lewat Pengaturan › Pembaruan Aplikasi) dan berkas
 * unduhan/Amanah.apk.
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/_lib.php';
require_once __DIR__ . '/_qr.php';

$berkas = __DIR__ . '/unduhan/Amanah.apk';
$ada    = is_file($berkas);
$kode   = (int) pengaturan($pdo, 'apk_versi_kode', '0');
$nama   = pengaturan($pdo, 'apk_versi_nama', '');
$cat    = pengaturan($pdo, 'apk_catatan', '');
$urlK   = trim(pengaturan($pdo, 'apk_url', ''));
$url    = $urlK !== '' ? $urlK : ($ada ? url_penuh('unduhan/Amanah.apk') . '?v=' . filemtime($berkas) : '');
$svgQr  = $url !== '' ? qr_svg($url, 0, '#111') : null;
$masuk  = !empty($_SESSION['user_id']);

$PAGE_TITLE = 'Aplikasi Android'; $ACTIVE = 'pengaturan';
if ($masuk) {
    require __DIR__ . '/_header.php';
} else {
    ?><!doctype html><html lang="id" data-theme="light"><head><meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Aplikasi Android · <?= e(APP_NAMA) ?></title>
    <link rel="stylesheet" href="assets/amanah.css?v=<?= (int) @filemtime(__DIR__ . '/assets/amanah.css') ?>">
    <style>body{padding:24px;max-width:960px;margin:0 auto}</style></head><body>
    <?php require __DIR__ . '/_ikon.php'; ?>
    <p style="margin-bottom:14px"><a href="index.php" class="btn btn-ghost btn-sm">← <?= e(APP_NAMA) ?></a></p>
    <?php
}
?>
<div class="page-head">
  <div><h1>Aplikasi Android <?= e(APP_NAMA) ?></h1>
    <p>Pasang di ponsel petugas gudang, toko, dan mitra · pemindai barcode, surat jalan, stock opname</p></div>
  <?php if ($masuk): ?><a class="btn btn-ghost" href="<?= e(beranda_peran()) ?>">← Kembali</a><?php endif; ?>
</div>

<?php if ($url === ''): ?>
  <div class="card"><div class="kosong-blok">
    <p><b>Berkas aplikasi belum tersedia di server ini.</b><br>
       Minta Super Admin mengunggah APK ke folder <code>unduhan/</code> dan mengumumkan versinya di Pengaturan › Pembaruan Aplikasi.</p>
  </div></div>
<?php else: ?>
<div class="apk-grid">
  <div class="card">
    <div class="apk-kepala">
      <span class="apk-ikon"><svg aria-hidden="true"><use href="#i-unduh"></use></svg></span>
      <div>
        <h3 style="margin:0">Versi <?= e($nama !== '' ? $nama : ($kode > 0 ? "kode $kode" : 'terbaru')) ?></h3>
        <p class="mut" style="margin:4px 0 0">
          <?php if ($ada && $urlK === ''): ?>
            <?= number_format(filesize($berkas) / 1048576, 1, ',', '.') ?> MB · diperbarui <?= e(tgl_jam_id(date('Y-m-d H:i:s', filemtime($berkas)))) ?>
          <?php else: ?>diunduh dari alamat yang ditentukan Super Admin<?php endif; ?>
          <?= $kode > 0 ? ' · versionCode ' . $kode : '' ?>
        </p>
      </div>
    </div>
    <?php if ($cat !== ''): ?>
      <div class="apk-catatan"><b>Apa yang baru</b><pre><?= e($cat) ?></pre></div>
    <?php endif; ?>
    <a class="btn btn-primary" href="<?= e($url) ?>" style="margin-top:14px">
      <svg aria-hidden="true"><use href="#i-unduh"></use></svg> Unduh Amanah.apk</a>
    <p class="mut" style="margin-top:14px;line-height:1.7;font-size:12.5px">
      Setelah diunduh di ponsel, buka berkasnya dan pilih <b>Pasang</b>. Bila Android menolak,
      izinkan "pasang dari sumber tidak dikenal" untuk peramban yang dipakai, lalu ulangi.
      Memasang di atas versi lama tidak menghapus data maupun sesi masuk.
    </p>
  </div>

  <div class="card apk-qr">
    <h3>Pindai dari ponsel</h3>
    <?php if ($svgQr): ?>
      <div class="apk-qr-kotak"><?= $svgQr ?></div>
      <p class="mut" style="font-size:12.5px;line-height:1.6;margin:10px 0 0">
        Arahkan kamera ponsel ke kode ini; tautan unduhnya langsung terbuka.
        Membuka halaman ini di komputer lalu memindai jauh lebih cepat daripada mengetik alamatnya.
      </p>
    <?php else: ?>
      <p class="mut">Alamat unduhnya terlalu panjang untuk kode QR. Salin tautan tombol Unduh dan kirim ke ponsel.</p>
    <?php endif; ?>
    <p style="margin-top:10px;font-size:12px;word-break:break-all"><code><?= e($url) ?></code></p>
  </div>
</div>
<?php endif; ?>

<style>
.apk-grid{ display:grid; grid-template-columns:1.4fr 1fr; gap:16px; align-items:start } @media(max-width:860px){ .apk-grid{ grid-template-columns:1fr } }
.apk-kepala{ display:flex; gap:14px; align-items:center }
.apk-ikon{ width:48px; height:48px; border-radius:14px; display:grid; place-items:center; background:var(--primary-lembut); color:var(--primary-t); flex:none }
.apk-ikon svg{ width:22px; height:22px; stroke:currentColor; fill:none; stroke-width:1.9; stroke-linecap:round; stroke-linejoin:round }
.apk-catatan{ margin-top:14px; padding:12px 14px; background:var(--surface-3); border-radius:10px; font-size:13px }
.apk-catatan pre{ white-space:pre-wrap; font:inherit; margin:6px 0 0; line-height:1.6 }
.apk-qr{ text-align:center }
.apk-qr-kotak{ display:inline-block; background:#fff; padding:12px; border-radius:12px; border:1px solid var(--border) }
.apk-qr-kotak svg{ width:200px; height:200px; display:block }
.btn svg{ width:16px; height:16px; stroke:currentColor; fill:none; stroke-width:1.9 }
</style>
<?php if ($masuk) { require __DIR__ . '/_footer.php'; } else { echo '</body></html>'; } ?>
