<?php
/**
 * AMANAH — Cadangan Data (Super Admin)
 *
 * Satu tombol untuk membuat cadangan SQL lengkap yang bisa langsung diunduh,
 * daftar cadangan yang tersimpan, jadwal otomatis (jarak antar cadangan pada
 * jam aktif, satu kali di luarnya), dan Google Drive lewat akun Google
 * pengguna. Mesinnya di _cadangan.php; penghubungan Google di cadangan_google.php.
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/_lib.php';
require_once __DIR__ . '/_cadangan.php';
require_role(['super_admin']);
$u = current_user();
cadangan_pastikan_tabel($pdo);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    wajib_csrf($_POST['csrf'] ?? null);
    $aksi = (string) ($_POST['aksi'] ?? '');
    try {
        if ($aksi === 'buat') {
            $h = cadangan_buat($pdo, 'manual', (int) $u['id']);
            catat_aktivitas($pdo, 'export', 'cadangan', null, "Cadangan manual {$h['berkas']} (" . angka($h['baris']) . ' baris)');
            set_flash('ok', "Cadangan {$h['berkas']} dibuat (" . number_format($h['ukuran'] / 1024, 0, ',', '.') . " KB, "
                . angka($h['tabel']) . ' tabel/view, ' . angka($h['baris']) . ' baris).' . ($h['drive_pesan'] ? ' ' . $h['drive_pesan'] : ''));
        } elseif ($aksi === 'hapus') {
            $nama = (string) ($_POST['berkas'] ?? '');
            if (!cadangan_nama_sah($nama)) { throw new RuntimeException('Nama berkas tidak dikenali.'); }
            @unlink(cadangan_dir() . '/' . $nama);
            catat_aktivitas($pdo, 'delete', 'cadangan', null, "Hapus cadangan $nama");
            set_flash('ok', "Cadangan $nama dihapus dari server.");
        } elseif ($aksi === 'setelan') {
            simpan_pengaturan($pdo, 'cadangan_otomatis', isset($_POST['otomatis']) ? '1' : '0');
            $interval = (int) ($_POST['interval_jam'] ?? 1);
            simpan_pengaturan($pdo, 'cadangan_interval_jam', (string) (in_array($interval, [1, 2, 3, 6, 12, 24], true) ? $interval : 1));
            foreach (['jam_mulai' => '05:00', 'jam_selesai' => '22:30'] as $k => $bawaan) {
                $v = (string) ($_POST[$k] ?? $bawaan);
                simpan_pengaturan($pdo, 'cadangan_' . $k, preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $v) ? $v : $bawaan);
            }
            simpan_pengaturan($pdo, 'cadangan_malam_sekali', isset($_POST['malam_sekali']) ? '1' : '0');
            simpan_pengaturan($pdo, 'cadangan_simpan', (string) max(1, min(1000, (int) ($_POST['simpan'] ?? 48))));
            /* Folder: boleh ID atau tautan utuh (…/folders/ID?usp=sharing); ID-nya diambil sendiri.
               Folder yang BUKAN buatan aplikasi tidak bisa dipakai (izin drive.file) — pakai tombol Buat folder. */
            $folderIsi = trim((string) ($_POST['drive_folder'] ?? ''));
            if (preg_match('~/folders/([A-Za-z0-9_-]+)~', $folderIsi, $mF) || preg_match('~[?&]id=([A-Za-z0-9_-]+)~', $folderIsi, $mF)) { $folderIsi = $mF[1]; }
            $folderIsi = preg_replace('~[?#].*$~', '', $folderIsi);
            simpan_pengaturan($pdo, 'cadangan_drive_folder', mb_substr($folderIsi, 0, 120));
            // Client ID & Secret Google: secret kosong = tetap yang lama.
            simpan_pengaturan($pdo, 'google_client_id', mb_substr(trim((string) ($_POST['client_id'] ?? '')), 0, 200));
            $secret = trim((string) ($_POST['client_secret'] ?? ''));
            if ($secret !== '') { simpan_pengaturan($pdo, 'google_client_secret', mb_substr($secret, 0, 200)); }
            catat_aktivitas($pdo, 'update', 'cadangan', null, 'Ubah setelan cadangan');
            set_flash('ok', 'Setelan cadangan disimpan.');
        } elseif ($aksi === 'kunci_baru') {
            simpan_pengaturan($pdo, 'cadangan_kunci', bin2hex(random_bytes(16)));
            set_flash('ok', 'Kunci cron baru dibuat. Perbarui perintah cron di hosting.');
        } elseif ($aksi === 'buat_folder') {
            if (!gdrive_terhubung($pdo)) { throw new RuntimeException('Hubungkan dulu akun Google (tombol "Hubungkan akun Google").'); }
            $folder = gdrive_buat_folder($pdo, 'Cadangan ' . APP_NAMA);
            simpan_pengaturan($pdo, 'cadangan_drive_folder', (string) $folder['id']);
            catat_aktivitas($pdo, 'update', 'cadangan', null, 'Buat folder Drive ' . $folder['name'] . ' (' . $folder['id'] . ')');
            set_flash('ok', 'Folder "' . $folder['name'] . '" dibuat di Drive Saya akun ' . gdrive_akun($pdo) . ' dan dipakai sebagai tujuan. Folder boleh dipindahkan atau diganti nama di Drive.');
        } elseif ($aksi === 'putus_google') {
            gdrive_putus($pdo);
            catat_aktivitas($pdo, 'update', 'cadangan', null, 'Akun Google diputus dari cadangan');
            set_flash('ok', 'Akun Google diputus. Unggah ke Drive berhenti sampai dihubungkan lagi.');
        } elseif ($aksi === 'uji_drive') {
            $nama = cadangan_drive_uji($pdo);
            set_flash('ok', "Google Drive siap — folder tujuan: \"$nama\" (akun " . gdrive_akun($pdo) . ').');
        }
    } catch (PDOException $e) {
        set_flash('err', pesan_error_aman($e, 'cadangan'));
    } catch (RuntimeException $e) {
        set_flash('err', $e->getMessage());
    } catch (Throwable $e) {
        set_flash('err', pesan_error_aman($e, 'cadangan'));
    }
    header('Location: cadangan.php'); exit;
}

$daftar = cadangan_daftar();
$log = [];
try { $log = $pdo->query("SELECT c.*, us.nama AS oleh FROM cadangan_log c LEFT JOIN users us ON us.id = c.user_id ORDER BY c.id DESC LIMIT 60")->fetchAll(); } catch (Throwable $e) {}
$logPer = []; foreach ($log as $l) { $logPer[$l['berkas']] = $l; }
$otomatis = pengaturan($pdo, 'cadangan_otomatis') === '1';
$jadwal   = cadangan_jadwal($pdo);
$simpan   = max(1, (int) pengaturan($pdo, 'cadangan_simpan', '48'));
$folder   = pengaturan($pdo, 'cadangan_drive_folder');
$clientId = pengaturan($pdo, 'google_client_id');
$adaSecret = pengaturan($pdo, 'google_client_secret') !== '';
$terhubung = gdrive_terhubung($pdo);
$akun     = gdrive_akun($pdo);
$driveSiap = cadangan_drive_siap($pdo);
$driveAlasan = cadangan_drive_alasan($pdo);
$kunci    = pengaturan($pdo, 'cadangan_kunci');
/* Kunci cron dibuat begitu halaman dibuka, bukan menunggu setelan disimpan. */
if ($kunci === '') {
    try { $kunci = bin2hex(random_bytes(16)); simpan_pengaturan($pdo, 'cadangan_kunci', $kunci); }
    catch (Throwable $e) { error_log('[AMANAH] kunci cron: ' . $e->getMessage()); }
}
$terakhir = pengaturan($pdo, 'cadangan_terakhir');
$urlCron  = url_penuh('cron_cadangan.php') . '?kunci=' . rawurlencode($kunci);
$redirect = gdrive_redirect_uri();
$kb = fn(int $n) => number_format($n / 1024, 0, ',', '.') . ' KB';

$PAGE_TITLE = 'Cadangan Data'; $ACTIVE = 'pengaturan';
require __DIR__ . '/_header.php';
?>
<div class="page-head">
  <div><h1>Cadangan Data</h1>
    <p>Salinan lengkap basis data (SQL) — bisa diunduh, dibuat otomatis sesuai jadwal, dan dikirim ke Google Drive</p></div>
  <a class="btn btn-ghost" href="pengaturan.php">← Pengaturan</a>
</div>

<div class="card">
  <div style="display:flex;gap:14px;flex-wrap:wrap;align-items:center;justify-content:space-between">
    <div>
      <b>Cadangan terakhir:</b> <?= $terakhir ? e(tgl_jam_id($terakhir)) : '<span class="mut">belum pernah</span>' ?>
      <?php if ($otomatis): ?><br><small class="mut">Otomatis menyala — <?= e(cadangan_teks_jadwal($jadwal)) ?>; disimpan <?= $simpan ?> cadangan terakhir<?= $driveSiap ? ', diunggah ke Google Drive akun ' . e($akun) : '' ?>.</small>
      <?php else: ?><br><small class="mut" style="color:var(--amber)">Cadangan otomatis belum dinyalakan.</small><?php endif; ?>
      <?php if (!$driveSiap): ?><br><small class="mut" style="color:var(--amber)">Google Drive: <?= e($driveAlasan) ?></small><?php endif; ?>
    </div>
    <form method="post" data-konfirm="Buat cadangan basis data sekarang?" data-tombol="Ya, buat" data-warna="primary">
      <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="aksi" value="buat">
      <button class="btn btn-primary"><svg aria-hidden="true"><use href="#i-unduh"></use></svg> Buat Cadangan Sekarang</button>
    </form>
  </div>
  <p class="mut" style="font-size:12.5px;margin:12px 0 0">Berkas berbentuk <code>.sql.gz</code> (SQL dipadatkan). Memulihkannya: ekstrak, lalu Import di phpMyAdmin.
    <b>Simpan juga salinannya di luar hosting</b> — unduh, atau hubungkan Google Drive di bawah.</p>
</div>

<div class="card">
  <h3>Cadangan tersimpan di server</h3>
  <div class="sub"><?= count($daftar) ?> berkas · yang lebih lama dari <?= $simpan ?> terakhir dihapus otomatis (di server dan di Drive)</div>
  <?php if (!$daftar): ?><div class="kosong-blok"><p>Belum ada cadangan.</p></div>
  <?php else: ?>
  <div class="tbl-wrap"><table class="tbl-kartu">
    <thead><tr><th>Berkas</th><th>Waktu</th><th>Cara</th><th class="num">Ukuran</th><th>Google Drive</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($daftar as $b): $l = $logPer[$b['berkas']] ?? null; ?>
      <tr>
        <td data-label="Berkas"><code><?= e($b['berkas']) ?></code><?php if ($l && $l['oleh']): ?><br><small class="mut2">oleh <?= e($l['oleh']) ?></small><?php endif; ?></td>
        <td class="sekunder nowrap" data-label="Waktu"><?= e(tgl_jam_id(date('Y-m-d H:i:s', $b['waktu']))) ?></td>
        <td data-label="Cara"><?= e($l['cara'] ?? '—') ?></td>
        <td class="num" data-label="Ukuran"><?= e($kb($b['ukuran'])) ?></td>
        <td data-label="Google Drive"><?php if ($l && $l['drive_id']): ?><span class="tag t-green">terunggah</span>
          <?php elseif ($l && $l['drive_pesan']): ?><small class="mut" style="color:var(--red)"><?= e($l['drive_pesan']) ?></small>
          <?php else: ?><span class="mut2">—</span><?php endif; ?></td>
        <td class="nowrap">
          <a class="btn btn-primary btn-sm" href="cadangan_unduh.php?f=<?= e(rawurlencode($b['berkas'])) ?>">Unduh</a>
          <form method="post" style="display:inline" data-konfirm="Hapus cadangan <?= e($b['berkas']) ?> dari server?" data-tombol="Hapus">
            <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="aksi" value="hapus">
            <input type="hidden" name="berkas" value="<?= e($b['berkas']) ?>">
            <button class="btn btn-ghost btn-sm">Hapus</button>
          </form>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
  <?php endif; ?>
</div>

<form method="post" class="card">
  <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="aksi" value="setelan">
  <h3>Setelan otomatis &amp; Google Drive</h3>
  <div class="form-grid">
    <div class="field">
      <label class="baris" style="font-weight:500;color:var(--text)"><input type="checkbox" name="otomatis" value="1" <?= $otomatis ? 'checked' : '' ?>> Buat cadangan otomatis</label>
      <small class="petunjuk">Dua jalan, keduanya mengikuti jarak di samping: (1) cron hosting memanggil alamat di bawah tiap jam (cPanel: <b>Once Per Hour</b>);
        (2) bila hosting tidak punya cron, cadangan dibuat diam-diam saat Super Admin membuka halaman mana pun ketika sudah jatuh tempo.</small>
    </div>
    <div class="field"><label for="interval_jam">Jarak antar cadangan (pada jam aktif)</label>
      <select name="interval_jam" id="interval_jam">
        <?php foreach ([1, 2, 3, 6, 12, 24] as $n): ?><option value="<?= $n ?>" <?= $jadwal['interval'] === $n ? 'selected' : '' ?>>Tiap <?= $n ?> jam</option><?php endforeach; ?>
      </select></div>
    <div class="field"><label for="jam_mulai">Jam aktif mulai</label><input type="time" name="jam_mulai" id="jam_mulai" value="<?= e($jadwal['jam_mulai']) ?>"></div>
    <div class="field"><label for="jam_selesai">Jam aktif selesai</label><input type="time" name="jam_selesai" id="jam_selesai" value="<?= e($jadwal['jam_selesai']) ?>"></div>
    <div class="field span-all">
      <label class="baris" style="font-weight:500;color:var(--text)"><input type="checkbox" name="malam_sekali" value="1" <?= $jadwal['malam_sekali'] ? 'checked' : '' ?>> Di luar jam aktif cukup satu kali</label>
      <small class="petunjuk">Contoh bawaan: 05:00–22:30 dicadangkan tiap jam; dari 22:30 sampai 04:59 hanya satu kali (pada pemicu pertama setelah 22:30). Bila tidak dicentang, tiap jam sepanjang hari.</small>
    </div>
    <div class="field"><label for="simpan">Simpan berapa cadangan terakhir</label>
      <input type="number" name="simpan" id="simpan" min="1" max="1000" value="<?= $simpan ?>" style="width:120px">
      <small class="petunjuk">Berlaku di server dan di Google Drive; yang lebih lama dihapus otomatis. Tiap 1 jam × 48 = dua hari terakhir.</small></div>
    <div class="field"><label for="drive_folder">Folder Google Drive tujuan</label>
      <input name="drive_folder" id="drive_folder" value="<?= e($folder) ?>" maxlength="120" placeholder="terisi otomatis lewat tombol Buat folder di Drive">
      <small class="petunjuk">Aplikasi hanya bisa memakai folder yang dibuatnya sendiri (izin Drive terbatas). Tekan <b>Buat folder di Drive</b> di bawah; folder itu boleh dipindahkan / diganti nama di Drive setelahnya.</small></div>
    <div class="field span-all" style="border-top:1px solid var(--border);padding-top:14px">
      <label>Akun Google</label>
      <?php if ($terhubung): ?>
        <div>Terhubung sebagai <b><?= e($akun) ?></b> · izin Drive: <?= strpos(pengaturan($pdo, 'google_scope'), GDRIVE_SCOPE) !== false ? '<span class="tag t-green">aktif</span>' : '<span class="tag t-red">belum ada</span> — hubungkan ulang dan setujui izin Google Drive' ?></div>
      <?php else: ?>
        <div class="mut">Belum terhubung. Isi Client ID &amp; Client Secret di bawah, simpan, lalu tekan <b>Hubungkan akun Google</b>.</div>
      <?php endif; ?>
    </div>
    <div class="field"><label for="client_id">Google Client ID</label>
      <input name="client_id" id="client_id" value="<?= e($clientId) ?>" maxlength="200" placeholder="xxxx.apps.googleusercontent.com" autocomplete="off"></div>
    <div class="field"><label for="client_secret">Google Client Secret</label>
      <input type="password" name="client_secret" id="client_secret" maxlength="200" placeholder="<?= $adaSecret ? 'tersimpan — kosongkan bila tidak diganti' : 'GOCSPX-…' ?>" autocomplete="new-password"></div>
    <div class="field span-all">
      <label>Alamat untuk cron hosting (cPanel › Cron Jobs, Once Per Hour)</label>
      <code style="display:block;word-break:break-all;font-size:12px"><?= e($urlCron) ?></code>
      <small class="petunjuk">Perintah cron: <code>curl -s "<?= e($urlCron) ?>" &gt; /dev/null</code>. Alamat ini memuat kunci rahasia — jangan dibagikan. Tambahkan <code>&amp;paksa=1</code> bila ingin membuat cadangan setiap kali dipanggil tanpa melihat jadwal.</small>
    </div>
  </div>
  <details style="margin:4px 0 14px"><summary class="mut" style="cursor:pointer;font-size:12.5px">Cara menyiapkan Google Drive (sekali saja)</summary>
    <ol class="mut" style="font-size:12.5px;margin:8px 0 0 18px;line-height:1.7">
      <li>Buka <code>console.cloud.google.com</code>, pilih/buat project, aktifkan <b>Google Drive API</b> (APIs &amp; Services › Library).</li>
      <li><b>OAuth consent screen</b>: jenis External, isi nama aplikasi &amp; email, lalu di <b>Test users</b> tambahkan alamat Google yang akan dihubungkan (selama aplikasi berstatus Testing).</li>
      <li><b>Credentials › Create Credentials › OAuth client ID</b> › jenis <b>Web application</b>. Pada <b>Authorized redirect URIs</b> tambahkan persis:
        <code><?= e($redirect) ?></code><?php if (substr($redirect, -4) === '.php'): ?> dan juga <code><?= e(substr($redirect, 0, -4)) ?></code><?php endif; ?>.</li>
      <li>Salin Client ID dan Client Secret ke kotak di atas, <b>Simpan Setelan</b>.</li>
      <li>Tekan <b>Hubungkan akun Google</b>, pilih akun, setujui izin Drive. Lalu tekan <b>Buat folder di Drive</b>, lalu <b>Uji Google Drive</b>.</li>
      <li>Akun layanan (service account) tidak dipakai lagi: Google tidak memberinya kuota penyimpanan.</li>
    </ol>
  </details>
  <div style="display:flex;gap:9px;flex-wrap:wrap">
    <button class="btn btn-primary" type="submit"><svg aria-hidden="true"><use href="#i-simpan"></use></svg> Simpan Setelan</button>
  </div>
</form>
<div style="display:flex;gap:9px;flex-wrap:wrap;margin:-6px 0 16px">
  <?php if ($terhubung): ?>
    <form method="post" data-konfirm="Buat folder 'Cadangan <?= e(APP_NAMA) ?>' di Drive Saya akun <?= e($akun) ?> dan pakai sebagai tujuan cadangan?" data-tombol="Ya, buat folder" data-warna="primary">
      <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="aksi" value="buat_folder">
      <button class="btn btn-ghost btn-sm">Buat folder di Drive</button></form>
    <form method="post"><input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="aksi" value="uji_drive">
      <button class="btn btn-ghost btn-sm">Uji Google Drive</button></form>
    <a class="btn btn-ghost btn-sm" href="cadangan_google.php?aksi=hubungkan">Hubungkan ulang akun Google</a>
    <form method="post" data-konfirm="Putus akun Google <?= e($akun) ?> dari cadangan?" data-tombol="Ya, putus" data-warna="red">
      <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="aksi" value="putus_google">
      <button class="btn btn-ghost btn-sm">Putus akun Google</button></form>
  <?php else: ?>
    <a class="btn btn-primary btn-sm" href="cadangan_google.php?aksi=hubungkan" <?= gdrive_terkonfigurasi($pdo) ? '' : 'onclick="alert(\'Isi dan simpan Client ID & Client Secret dulu.\');return false;"' ?>>Hubungkan akun Google</a>
  <?php endif; ?>
  <form method="post" data-konfirm="Buat kunci cron baru? Perintah cron yang lama berhenti bekerja." data-tombol="Ya, buat kunci baru">
    <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="aksi" value="kunci_baru">
    <button class="btn btn-ghost btn-sm">Ganti kunci cron</button></form>
</div>
<?php require __DIR__ . '/_footer.php'; ?>
