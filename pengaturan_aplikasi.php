<?php
/**
 * AMANAH — Pembaruan Aplikasi Android
 *
 * Tempat Super Admin mengumumkan versi APK baru. Aplikasi di ponsel
 * memanggil api/versi_aplikasi.php tiap kali dibuka; bila nomor versi di
 * sini lebih besar dari yang terpasang, ponsel menampilkan dialog
 * "Versi baru tersedia" dengan tombol Unduh.
 *
 * Berkas APK-nya TIDAK diunggah lewat halaman ini: ukurannya ±28 MB,
 * jauh di atas batas unggah PHP di hosting bersama (8 MB). Unggah lewat
 * FTP ke folder unduhan/ dengan nama Amanah.apk, lalu isi nomor versinya
 * di sini. Halaman ini menunjukkan apakah berkasnya sudah ada.
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/_lib.php';
require_role(['super_admin']);

$berkas = __DIR__ . '/unduhan/Amanah.apk';
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    wajib_csrf($_POST['csrf'] ?? null);
    $kode  = (int) ($_POST['versi_kode'] ?? 0);
    $nama  = trim((string) ($_POST['versi_nama'] ?? ''));
    $cat   = trim((string) ($_POST['catatan'] ?? ''));
    $wajib = isset($_POST['wajib']) ? '1' : '0';
    $url   = trim((string) ($_POST['url'] ?? ''));

    if ($kode < 0) { $errors[] = 'Nomor versi (versionCode) harus angka bulat ≥ 0.'; }
    if ($kode > 0 && $nama === '') { $errors[] = 'Nama versi wajib diisi, mis. 1.5.0.'; }
    if ($url !== '' && !preg_match('~^https?://~i', $url)) { $errors[] = 'Alamat unduh khusus harus diawali http:// atau https://.'; }
    if ($kode > 0 && $url === '' && !is_file($berkas)) {
        $errors[] = 'Berkas unduhan/Amanah.apk belum ada di server. Unggah dulu lewat FTP, atau isi alamat unduh khusus.';
    }

    if (!$errors) {
        try {
            simpan_pengaturan($pdo, 'apk_versi_kode', (string) $kode);
            simpan_pengaturan($pdo, 'apk_versi_nama', mb_substr($nama, 0, 30));
            simpan_pengaturan($pdo, 'apk_catatan', mb_substr($cat, 0, 500));
            simpan_pengaturan($pdo, 'apk_wajib', $wajib);
            simpan_pengaturan($pdo, 'apk_url', mb_substr($url, 0, 500));
            catat_aktivitas($pdo, 'update', 'pengaturan', null,
                "Umumkan aplikasi Android versi $nama (kode $kode)" . ($wajib === '1' ? ', wajib' : ''));
            set_flash('ok', $kode > 0
                ? "Versi $nama (kode $kode) diumumkan. Ponsel yang membuka aplikasi akan melihat dialog pembaruan."
                : 'Pengumuman pembaruan dimatikan.');
            header('Location: pengaturan_aplikasi.php'); exit;
        } catch (Throwable $e) {
            $errors[] = pesan_error_aman($e, 'simpan versi aplikasi');
        }
    }
}

$v = [
    'versi_kode' => pengaturan($pdo, 'apk_versi_kode', '0'),
    'versi_nama' => pengaturan($pdo, 'apk_versi_nama', ''),
    'catatan'    => pengaturan($pdo, 'apk_catatan', ''),
    'wajib'      => pengaturan($pdo, 'apk_wajib', '0') === '1',
    'url'        => pengaturan($pdo, 'apk_url', ''),
];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $v = ['versi_kode' => (string) $kode, 'versi_nama' => $nama, 'catatan' => $cat, 'wajib' => $wajib === '1', 'url' => $url];
}
$adaApk = is_file($berkas);

$PAGE_TITLE = 'Pembaruan Aplikasi'; $ACTIVE = 'pengaturan';
require __DIR__ . '/_header.php';
?>
<div class="page-head">
  <div><h1>Pembaruan Aplikasi Android</h1>
    <p>Umumkan versi APK baru — ponsel akan diminta memperbarui saat aplikasi dibuka</p></div>
  <a class="btn btn-ghost" href="pengaturan.php">← Pengaturan</a>
</div>

<?php if ($errors): ?>
  <div class="flash flash-err"><?php foreach ($errors as $e) echo '• ' . e($e) . '<br>'; ?></div>
<?php endif; ?>

<div class="dua-kolom">
  <form method="post" class="card">
    <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
    <h3>Versi yang diumumkan</h3>
    <?php /* Pola yang sama dengan Kelola Pengguna: .form-grid berisi .field,
             label di ATAS isian. Versi sebelumnya menaruh isian di dalam
             <label>, dan .form-grid tidak dirancang untuk itu — hasilnya
             kolom yang bertabrakan. */ ?>
    <div class="form-grid">
      <div class="field"><label>Nomor versi (versionCode) <span class="req">*</span></label>
        <input type="number" name="versi_kode" min="0" required value="<?= e($v['versi_kode']) ?>">
        <div class="sub">Angka yang naik tiap rilis, ada di <code>app/build.gradle.kts</code>. Ponsel membandingkan angka ini; 0 = tidak ada pengumuman.</div>
      </div>
      <div class="field"><label>Nama versi</label>
        <input type="text" name="versi_nama" maxlength="30" placeholder="mis. 1.5.0" value="<?= e($v['versi_nama']) ?>">
        <div class="sub">Yang dilihat orang di dialog pembaruan.</div>
      </div>
      <div class="field" style="grid-column:1 / -1"><label>Apa yang baru</label>
        <textarea name="catatan" rows="4" maxlength="500" placeholder="Satu baris per perubahan; ditampilkan di dialog pembaruan"><?= e($v['catatan']) ?></textarea>
      </div>
      <div class="field" style="grid-column:1 / -1"><label>Alamat unduh khusus (opsional)</label>
        <input type="url" name="url" placeholder="Kosongkan untuk memakai unduhan/Amanah.apk di server ini" value="<?= e($v['url']) ?>">
      </div>
      <div class="field" style="grid-column:1 / -1">
        <label class="centang">
          <input type="checkbox" name="wajib" value="1" <?= $v['wajib'] ? 'checked' : '' ?>>
          <span><b>Wajib diperbarui</b> — aplikasi lama tidak bisa dipakai sebelum memperbarui
            <small>Centang hanya bila versi lama benar-benar tidak boleh dipakai lagi, misalnya perubahan API yang tidak kompatibel.</small></span>
        </label>
      </div>
    </div>
    <div>
      <button class="btn btn-primary" type="submit"><svg aria-hidden="true"><use href="#i-simpan"></use></svg> Simpan &amp; Umumkan</button>
    </div>
  </form>

  <div>
    <div class="card">
      <h3>Berkas APK di server</h3>
      <?php if ($adaApk): ?>
        <p><span class="tag t-green">Ada</span> <code>unduhan/Amanah.apk</code></p>
        <p class="mut" style="margin:6px 0 0"><?= number_format(filesize($berkas) / 1048576, 1, ',', '.') ?> MB ·
          diunggah <?= e(tgl_jam_id(date('Y-m-d H:i:s', filemtime($berkas)))) ?></p>
        <p style="margin-top:10px"><a class="btn btn-ghost btn-sm" href="unduhan/Amanah.apk"><svg aria-hidden="true"><use href="#i-unduh"></use></svg> Unduh untuk mencoba</a></p>
      <?php else: ?>
        <p><span class="tag t-amber">Belum ada</span> <code>unduhan/Amanah.apk</code></p>
      <?php endif; ?>
      <p class="mut" style="margin-top:12px;line-height:1.7">
        Unggah APK lewat FTP ke folder <code>unduhan/</code> dengan nama persis <b>Amanah.apk</b>
        (timpa yang lama). Halaman ini tidak menerima unggahan karena ukuran APK jauh di atas
        batas unggah PHP hosting.
      </p>
    </div>
    <div class="card">
      <h3>Urutan merilis versi baru</h3>
      <ol style="margin:0 0 0 18px;line-height:1.9">
        <li>Naikkan <code>versionCode</code> dan <code>versionName</code>, bangun APK rilis.</li>
        <li>Unggah APK ke <code>unduhan/Amanah.apk</code> lewat FTP.</li>
        <li>Isi nomor versi dan catatan di halaman ini, simpan.</li>
        <li>Ponsel yang membuka aplikasi melihat dialog pembaruan dan mengunduh dari sini.</li>
      </ol>
      <p class="mut" style="margin-top:10px">Pemeriksaan versi ada sejak aplikasi 1.5.0; ponsel dengan versi lebih lama harus diperbarui manual sekali.</p>
    </div>
  </div>
</div>
<style>
.dua-kolom{ display:grid; grid-template-columns:1.3fr 1fr; gap:16px; align-items:start } @media(max-width:900px){ .dua-kolom{ grid-template-columns:1fr } }
.field .sub{ font-size:12px; color:var(--muted); margin-top:6px; line-height:1.6 }
.field label.centang{ display:flex; gap:12px; align-items:flex-start; font-size:13.5px; font-weight:500; color:var(--text);
  text-transform:none; letter-spacing:0; padding:12px 14px; border:1px solid var(--border); border-radius:10px; background:var(--surface-3); cursor:pointer }
.field label.centang input{ width:18px; height:18px; margin:2px 0 0; flex:none; accent-color:var(--primary) }
.field label.centang small{ display:block; margin-top:4px; font-size:12px; color:var(--muted); font-weight:400; line-height:1.6 }
</style>
<?php require __DIR__ . '/_footer.php'; ?>
