<?php
/**
 * AMANAH — Logo & Identitas Tampilan
 *
 * Tiga gambar dengan tiga tugas berbeda, dan perbedaannya penting:
 *   • Logo utama   — tampil di sidebar & halaman masuk
 *   • Favicon      — ikon kecil di tab peramban
 *   • Gambar share — yang muncul saat tautan dibagikan (WhatsApp, dsb.)
 *
 * Sering dikira satu gambar bisa dipakai untuk ketiganya. Tidak: favicon
 * dilihat pada ukuran 16 piksel sehingga logo bertulisan jadi bercak tak
 * terbaca, sedangkan gambar share dipotong menjadi persegi panjang lebar
 * sehingga logo persegi kehilangan sisinya.
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/_lib.php';
require_role(['super_admin']);

$errors = [];
$ok     = [];

// Kunci pengaturan → [label, awalan berkas, saran ukuran]
$GAMBAR = [
    'logo_utama'   => ['Logo Utama',   'logo',    'PNG latar transparan, tinggi 64–128 px'],
    'logo_favicon' => ['Favicon',      'favicon', 'PNG persegi, 64×64 atau 128×128 px'],
    'gambar_share' => ['Gambar Share', 'share',   'PNG/JPG 1200×630 px (rasio 1,91:1)'],
    // Poster/materi promosi yang tampil di panel kanan halaman Alur Kerja.
    // Rasio tegak dibiarkan bebas: panelnya menyesuaikan tinggi gambarnya,
    // jadi poster tegak maupun mendatar sama-sama utuh tanpa terpotong.
    'gambar_hero'  => ['Gambar Halaman Alur', 'hero',
                       'Poster atau materi promosi · rasio bebas, lebar 800–1200 px'],
];

/**
 * Catatan tentang gambar yang SUDAH terunggah.
 *
 * Sistem menerima ukuran apa pun — menolak gambar yang bisa dipakai hanya
 * karena kurang ideal akan lebih merepotkan daripada menolong. Tapi
 * konsekuensinya perlu terlihat: favicon 1 MB diunduh pada SETIAP pemuatan
 * halaman, dan gambar share dengan rasio yang salah akan dipotong oleh
 * WhatsApp tanpa memberi tahu siapa pun.
 */
function catatan_gambar(string $kunci, string $nama): array {
    $path = __DIR__ . '/' . DIR_UNGGAHAN . '/' . basename($nama);
    if ($nama === '' || !is_file($path)) { return []; }

    $info = @getimagesize($path);
    if ($info === false) { return ['Berkas ini tidak terbaca sebagai gambar.']; }

    [$lebar, $tinggi] = $info;
    $kb    = filesize($path) / 1024;
    $rasio = $tinggi > 0 ? $lebar / $tinggi : 0;
    $catat = [];

    if ($kunci === 'logo_favicon') {
        if ($kb > 100) {
            $catat[] = sprintf('Berkasnya %.0f KB, diunduh peramban pada setiap '
                . 'pemuatan halaman. Ukuran 128×128 px biasanya di bawah 20 KB — '
                . 'perkecil gambarnya, bentuknya sudah benar.', $kb);
        }
        if ($lebar > 256) {
            $catat[] = sprintf('Dimensinya %d×%d px, padahal ditampilkan pada 16–32 px. '
                . 'Kelebihannya tidak pernah terlihat.', $lebar, $tinggi);
        }
        if (abs($rasio - 1) > 0.05) {
            $catat[] = sprintf('Rasionya %.2f, bukan persegi — peramban akan '
                . 'memipihkannya.', $rasio);
        }
    }

    if ($kunci === 'gambar_share') {
        if (abs($rasio - 1.91) > 0.25) {
            $catat[] = sprintf('Rasionya %.2f, sedangkan pratinjau tautan memakai '
                . '1,91 (mis. 1200×630). WhatsApp dan media sosial akan memotong '
                . '%s gambar ini.', $rasio,
                $rasio < 1.91 ? 'bagian atas & bawah' : 'sisi kiri & kanan');
        }
        if ($lebar < 600) {
            $catat[] = sprintf('Lebarnya %d px; di bawah 600 px pratinjaunya '
                . 'sering tampil kecil atau diabaikan.', $lebar);
        }
    }

    if ($kunci === 'logo_utama' && $kb > 300) {
        $catat[] = sprintf('Berkasnya %.0f KB. Logo ditampilkan setinggi ±40 px, '
            . 'jadi versi yang lebih kecil akan mempercepat semua halaman.', $kb);
    }

    if ($kunci === 'gambar_hero') {
        if ($kb > 500) {
            $catat[] = sprintf('Berkasnya %.0f KB. Halaman Alur Kerja terbuka untuk '
                . 'umum dan sering dibuka dari HP — di bawah 300 KB jauh lebih ramah '
                . 'kuota pembacanya.', $kb);
        }
        if ($lebar > 1400) {
            $catat[] = sprintf('Lebarnya %d px, padahal panelnya paling lebar '
                . 'sekitar 520 px. Memperkecilnya tidak mengurangi ketajaman.', $lebar);
        }
    }

    return $catat;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    wajib_csrf($_POST['csrf'] ?? null);
    $aksi = $_POST['aksi'] ?? 'simpan';

    // ------------------------------------------------------ HAPUS SATU
    if ($aksi === 'hapus') {
        $kunci = $_POST['kunci'] ?? '';
        if (isset($GAMBAR[$kunci])) {
            hapus_unggahan(pengaturan($pdo, $kunci));
            simpan_pengaturan($pdo, $kunci, '');
            catat_aktivitas($pdo, 'update', 'pengaturan', null, "Hapus {$GAMBAR[$kunci][0]}");
            set_flash('ok', $GAMBAR[$kunci][0] . ' dihapus. Tampilan kembali ke bawaan.');
        }
        header('Location: pengaturan_merek.php'); exit;
    }

    // ----------------------------------------------------------- SIMPAN
    foreach ($GAMBAR as $kunci => [$label, $awalan, $saran]) {
        [$nama, $galat] = terima_gambar($kunci, $awalan);
        if ($galat !== '') { $errors[] = "$label: $galat"; continue; }
        if ($nama === '')  { continue; }   // tidak diganti

        /* Berkas lama dihapus SETELAH yang baru tersimpan. Urutan ini
           disengaja: kalau unggahan gagal di tengah jalan, yang lama masih
           utuh dan tampilan tidak mendadak kosong. */
        $lama = pengaturan($pdo, $kunci);
        simpan_pengaturan($pdo, $kunci, $nama);
        if ($lama !== '' && $lama !== $nama) { hapus_unggahan($lama); }
        $ok[] = $label;
    }

    // Teks pendamping gambar share.
    foreach (['share_judul' => 'Judul share', 'share_teks' => 'Deskripsi share'] as $k => $lbl) {
        if (isset($_POST[$k])) {
            simpan_pengaturan($pdo, $k, mb_substr(trim($_POST[$k]), 0, 300));
        }
    }

    if (!$errors) {
        catat_aktivitas($pdo, 'update', 'pengaturan', null,
            'Ubah identitas tampilan' . ($ok ? ': ' . implode(', ', $ok) : ''));
        set_flash('ok', $ok
            ? implode(', ', $ok) . ' berhasil diperbarui.'
            : 'Teks share disimpan.');
        header('Location: pengaturan_merek.php'); exit;
    }
}

$nilai = semua_pengaturan($pdo);

$PAGE_TITLE = 'Logo & Identitas'; $ACTIVE = 'pengaturan';
require __DIR__ . '/_header.php';
?>
<div class="page-head">
  <div><h1>Logo &amp; Identitas Tampilan</h1>
    <p>Logo, ikon tab peramban, dan gambar yang muncul saat tautan dibagikan</p></div>
  <a class="btn btn-ghost" href="pengaturan.php">← Pengaturan</a>
</div>

<?php if ($errors): ?>
  <div class="flash flash-err"><?php foreach ($errors as $e) echo '• ' . e($e) . '<br>'; ?></div>
<?php endif; ?>

<form method="post" enctype="multipart/form-data">
  <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
  <input type="hidden" name="aksi" value="simpan">

  <div class="dash-grid">
    <?php foreach ($GAMBAR as $kunci => [$label, $awalan, $saran]):
      $url = url_unggahan($nilai[$kunci] ?? ''); ?>
      <div class="card">
        <h3><?= e($label) ?></h3>
        <div class="sub"><?= e($saran) ?></div>

        <?php /* Pratinjau memakai latar kotak-kotak supaya logo berlatar
                 transparan tetap terlihat batasnya — logo putih di atas
                 kartu putih tampak seperti unggahan yang gagal. */ ?>
        <div style="margin:14px 0;padding:16px;border:1px dashed var(--line);border-radius:12px;
                    display:flex;align-items:center;justify-content:center;min-height:110px;
                    background:repeating-conic-gradient(var(--surface-2) 0% 25%, var(--surface-3) 0% 50%) 50%/16px 16px">
          <?php if ($url): ?>
            <img src="<?= e($url) ?>" alt="Pratinjau <?= e($label) ?>"
                 style="max-height:<?= $kunci === 'gambar_share' ? '150' : '80' ?>px;max-width:100%">
          <?php else: ?>
            <span style="color:var(--muted);font-size:13px">Belum ada — memakai bawaan sistem</span>
          <?php endif; ?>
        </div>

        <?php /* Catatan hanya muncul bila ADA yang perlu dikatakan. Kotak
                 "semuanya baik" pada setiap kartu hanya menambah bacaan
                 tanpa menambah informasi. */ ?>
        <?php $catat = catatan_gambar($kunci, $nilai[$kunci] ?? ''); ?>
        <?php if ($catat): ?>
          <div class="al-jika" style="margin:0 0 14px">
            <b>Bisa diperbaiki:</b>
            <?php foreach ($catat as $c): ?><br>• <?= e($c) ?><?php endforeach; ?>
            <br><small style="color:var(--muted)">Gambarnya tetap dipakai —
              ini hanya catatan.</small>
          </div>
        <?php endif; ?>

        <div class="field">
          <label>Pilih berkas</label>
          <input type="file" name="<?= e($kunci) ?>" accept="image/png,image/jpeg,image/webp">
          <small class="petunjuk">PNG, JPG, atau WebP. Maksimal
            <?= round(MAKS_UNGGAHAN / 1024 / 1024, 1) ?> MB.</small>
        </div>
      </div>
    <?php endforeach; ?>
  </div>

  <div class="card mb16">
    <h3>Teks saat tautan dibagikan</h3>
    <div class="sub">Muncul di bawah gambar share pada WhatsApp, Telegram, dan media sosial</div>
    <div class="form-grid">
      <div class="field span-all"><label>Judul</label>
        <input name="share_judul" maxlength="300" value="<?= e($nilai['share_judul'] ?? '') ?>"
               placeholder="<?= e(APP_NAMA) ?> — Sistem Gudang &amp; Rantai Pasok"></div>
      <div class="field span-all"><label>Deskripsi singkat</label>
        <textarea name="share_teks" rows="2" maxlength="300"
                  placeholder="Satu-dua kalimat tentang sistem ini."><?= e($nilai['share_teks'] ?? '') ?></textarea>
        <small class="petunjuk">Dikosongkan berarti memakai teks bawaan.</small></div>
    </div>
    <button class="btn btn-primary" type="submit">
      <svg aria-hidden="true"><use href="#i-simpan"></use></svg> Simpan Perubahan
    </button>
  </div>
</form>

<?php /* Tombol hapus berdiri di FORMULIR SENDIRI. Kalau ia berada di dalam
         formulir unggahan di atas, satu klik tak sengaja pada tombol hapus
         akan ikut mengirimkan seluruh berkas — dan menghapus gambar yang
         baru saja dipilih. */ ?>
<?php $adaIsi = array_filter(array_map(function ($k) use ($nilai) { return $nilai[$k] ?? ''; }, array_keys($GAMBAR))); ?>
<?php if ($adaIsi): ?>
  <div class="card">
    <h3>Kembalikan ke bawaan</h3>
    <div class="sub">Menghapus gambar yang diunggah; tampilan memakai bawaan sistem lagi</div>
    <div class="baris" style="gap:10px;flex-wrap:wrap;margin-top:12px">
      <?php foreach ($GAMBAR as $kunci => [$label, , ]): ?>
        <?php if (($nilai[$kunci] ?? '') === '') { continue; } ?>
        <form method="post" data-konfirm="Hapus <?= e($label) ?>?">
          <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
          <input type="hidden" name="aksi" value="hapus">
          <input type="hidden" name="kunci" value="<?= e($kunci) ?>">
          <button class="btn btn-ghost btn-sm" type="submit">Hapus <?= e($label) ?></button>
        </form>
      <?php endforeach; ?>
    </div>
  </div>
<?php endif; ?>

<div class="card mt16">
  <h3>Kenapa tiga gambar, bukan satu</h3>
  <ul style="color:var(--muted);font-size:13px;line-height:1.9;margin:8px 0 0;padding-left:20px">
    <li><b>Favicon</b> dilihat pada ukuran 16 piksel. Logo bertulisan akan jadi
      bercak yang tidak terbaca — pakai lambangnya saja, tanpa teks.</li>
    <li><b>Gambar share</b> dipotong menjadi persegi panjang lebar (1,91:1).
      Logo persegi akan kehilangan sisi kiri dan kanannya.</li>
    <li><b>SVG tidak diterima.</b> Berkas SVG adalah XML yang boleh memuat
      skrip, dan menyajikannya dari domain yang sama dengan aplikasi berarti
      skrip itu berjalan memakai sesi pemakainya.</li>
  </ul>
</div>
<?php require __DIR__ . '/_footer.php'; ?>
