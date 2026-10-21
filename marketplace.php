<?php
/**
 * AMANAH — Sambungan Marketplace
 *
 * Tempat menyimpan kredensial dan menjalankan penarikan pesanan.
 *
 * KEAMANAN: partner_key, access_token, dan refresh_token adalah RAHASIA
 * yang setara password. Ketiganya:
 *   • tidak pernah dikirim balik ke halaman, bahkan tersamar;
 *   • hanya bisa DIGANTI, tidak bisa dilihat;
 *   • hanya boleh disentuh Super Admin.
 * Menampilkan kunci tersamar tetap berbahaya — panjangnya saja sudah
 * membocorkan informasi, dan riwayat peramban menyimpan apa yang tampil.
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/_lib.php';
require_once __DIR__ . '/_marketplace.php';
require_role(['super_admin']);

$errors = [];

/* Alamat kembali sesudah otorisasi Shopee. Harus persis sama dengan yang
   didaftarkan di Shopee Open Platform; kalau tidak, Shopee menolak
   mengembalikan pengguna. */
$skema  = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$URL_KEMBALI = $skema . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost')
             . rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '')), '/')
             . '/marketplace.php';

// ==================================================================== AKSI
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    wajib_csrf($_POST['csrf'] ?? null);
    $aksi = $_POST['aksi'] ?? '';

    // ------------------------------------------------ simpan kredensial
    if ($aksi === 'simpan') {
        $tokoId    = (int) ($_POST['toko_id'] ?? 0);
        $partnerId = trim($_POST['partner_id'] ?? '');
        $shopId    = trim($_POST['shop_id'] ?? '');
        $fsId      = trim($_POST['fs_id'] ?? '');
        $partnerKey = (string) ($_POST['partner_key'] ?? '');

        /* Platform DIBACA dari toko.marketplace, tidak dipilih operator.
           Satu baris toko memang satu lapak — 'S STARKIDS' dan 'T STARKIDS'
           adalah dua baris terpisah untuk merek yang sama. Menyediakan
           pilihan platform di sini hanya membuka satu kelas kesalahan:
           kredensial Tokopedia tersimpan pada baris toko Shopee, lalu
           penarikannya gagal dengan pesan yang tidak menjelaskan sebabnya. */
        $platform = $tokoId > 0 ? mp_platform_toko($pdo, $tokoId) : null;

        if ($tokoId <= 0)           { $errors[] = 'Toko wajib dipilih.'; }
        elseif ($platform === null) {
            $errors[] = 'Toko itu tidak bertanda marketplace yang punya API. Ubah dulu '
                      . 'kolom Marketplace pada data tokonya menjadi Shopee atau Tokopedia.';
        }
        if ($partnerId === '') { $errors[] = 'Partner ID / Client ID wajib diisi.'; }
        if ($platform === 'tokopedia' && $fsId === '') {
            $errors[] = 'FS ID wajib diisi untuk Tokopedia.';
        }

        if (!$errors) {
            try {
                /* partner_key hanya ditulis bila kolomnya diisi. Dikosongkan
                   berarti "jangan diubah" — bukan "hapus kuncinya". Tanpa
                   aturan ini, menyunting nama toko saja akan menghapus
                   kunci yang tidak pernah bisa dilihat lagi untuk diisi ulang. */
                /* Dicari per TOKO saja, tanpa platform: kunci uniknya kini
                   (toko_id) — satu toko satu kredensial. */
                $ada = $pdo->prepare("SELECT id FROM marketplace_akun WHERE toko_id = ?");
                $ada->execute([$tokoId]);
                $akunId = (int) ($ada->fetchColumn() ?: 0);

                if ($akunId > 0) {
                    $sql = "UPDATE marketplace_akun
                               SET platform=?, partner_id=?, shop_id=?, fs_id=?, aktif=?";
                    $arg = [$platform, $partnerId, $shopId, $fsId ?: null,
                            isset($_POST['aktif']) ? 1 : 0];
                    if ($partnerKey !== '') { $sql .= ", partner_key=?"; $arg[] = $partnerKey; }
                    $sql .= " WHERE id=?"; $arg[] = $akunId;
                    $pdo->prepare($sql)->execute($arg);
                } else {
                    if ($partnerKey === '') {
                        throw new RuntimeException('Partner Key / Client Secret wajib diisi saat pertama kali.');
                    }
                    $pdo->prepare(
                        "INSERT INTO marketplace_akun
                           (toko_id, platform, partner_id, partner_key, shop_id, fs_id, status, aktif)
                         VALUES (?, ?, ?, ?, ?, ?, 'belum_tersambung', ?)"
                    )->execute([$tokoId, $platform, $partnerId, $partnerKey, $shopId,
                                $fsId ?: null, isset($_POST['aktif']) ? 1 : 0]);
                }
                catat_aktivitas($pdo, 'update', 'marketplace_akun', $tokoId,
                    'Simpan kredensial ' . (MP_PLATFORM[$platform] ?? $platform));
                set_flash('ok', 'Kredensial ' . (MP_PLATFORM[$platform] ?? '') . ' disimpan. '
                    . (mp_butuh_otorisasi($platform)
                        ? 'Langkah berikutnya: tekan Otorisasi.'
                        : 'Tokopedia tidak perlu otorisasi — langsung tekan Tarik sekarang.'));
            } catch (Throwable $e) {
                $errors[] = pesan_error_aman($e, 'simpan kredensial marketplace');
            }
        }
        if (!$errors) { header('Location: marketplace.php'); exit; }
    }

    // ------------------------------------------------------ tarik manual
    if ($aksi === 'tarik') {
        $akun = mp_akun($pdo, (int) ($_POST['akun_id'] ?? 0));
        if (!$akun) {
            set_flash('err', 'Sambungan tidak ditemukan.');
        } elseif (mp_butuh_otorisasi($akun['platform']) && empty($akun['access_token'])) {
            /* Syarat ini HANYA berlaku untuk Shopee. Tokopedia mengambil
               tokennya sendiri saat penarikan, jadi menahannya dengan syarat
               'belum diotorisasi' akan memblokir sambungan yang sebenarnya
               sudah siap. */
            set_flash('err', 'Belum diotorisasi. Tekan Otorisasi lebih dulu.');
        } else {
            $r = mp_tarik($pdo, $akun, (int) ($_POST['hari'] ?? 7));
            $pesan = "Selesai: {$r['baru']} pesanan baru, {$r['diperbarui']} diperbarui";
            if ($r['gagal']) { $pesan .= ", {$r['gagal']} gagal"; }
            if ($r['pesan'])  { $pesan .= '. ' . implode(' | ', array_slice($r['pesan'], 0, 3)); }
            set_flash($r['gagal'] ? 'err' : 'ok', $pesan);
        }
        header('Location: marketplace.php'); exit;
    }

    // ---------------------------------------------------- putus sambungan
    if ($aksi === 'putus') {
        $id = (int) ($_POST['akun_id'] ?? 0);
        /* Token dikosongkan, kredensial partner TIDAK. Memutus sambungan
           adalah tindakan sehari-hari (mis. saat token bermasalah);
           menghapus partner_key sekalian akan memaksa Super Admin mencarinya
           lagi dari Shopee Open Platform hanya untuk menyambung ulang. */
        $pdo->prepare("UPDATE marketplace_akun
                          SET access_token='', refresh_token='', token_expired_at=NULL,
                              status='belum_tersambung', pesan_terakhir=NULL
                        WHERE id=?")->execute([$id]);
        catat_aktivitas($pdo, 'update', 'marketplace_akun', $id, 'Putus sambungan marketplace');
        set_flash('ok', 'Sambungan diputus. Kredensial tetap tersimpan.');
        header('Location: marketplace.php'); exit;
    }
}

// ------------------------------------------------ balasan otorisasi Shopee
/* Shopee mengembalikan pengguna ke sini membawa ?code=...&shop_id=...
   Kode itu hanya berlaku sangat singkat, jadi ditukar segera. */
if (isset($_GET['code'], $_GET['shop_id'])) {
    /* `state` harus sama dengan yang diterbitkan marketplace_auth.php pada
       sesi INI, dan belum kedaluwarsa. Balasan tanpa state yang cocok
       dibuang — lihat catatan di marketplace_auth.php. */
    $stateSesi = $_SESSION['shopee_state'] ?? null;
    unset($_SESSION['shopee_state']);
    $stateSah = is_array($stateSesi)
        && isset($_GET['state'])
        && hash_equals((string) $stateSesi['nilai'], (string) $_GET['state'])
        && time() <= (int) $stateSesi['sampai'];
    if (!$stateSah) {
        set_flash('err', 'Balasan otorisasi Shopee tidak dikenali (state tidak cocok atau '
                       . 'sudah kedaluwarsa). Mulai lagi dari tombol Sambungkan.');
        header('Location: marketplace.php'); exit;
    }

    // Hanya Shopee yang mengembalikan pengguna dengan membawa kode.
    $st = $pdo->prepare("SELECT * FROM marketplace_akun
                         WHERE platform = 'shopee' AND shop_id = ?");
    $st->execute([trim((string) $_GET['shop_id'])]);
    $akun = $st->fetch();

    if (!$akun) {
        set_flash('err', 'Shop ID ' . e((string) $_GET['shop_id'])
                       . ' belum terdaftar di sini. Simpan kredensialnya lebih dulu.');
    } else {
        $r = shopee_tukar_kode($pdo, $akun, (string) $_GET['code']);
        set_flash($r['ok'] ? 'ok' : 'err', $r['ok']
            ? 'Berhasil tersambung ke Shopee.'
            : 'Otorisasi gagal: ' . $r['pesan']);
    }
    header('Location: marketplace.php'); exit;
}

// ==================================================================== DATA
/* Hanya toko yang marketplace-nya punya API. Menawarkan toko 'offline'
   di sini berarti menawarkan sambungan yang tidak mungkin ada. */
$tokoList = $pdo->query("SELECT id, kode, nama, marketplace FROM toko
                         WHERE deleted_at IS NULL
                           AND marketplace IN ('shopee','tokopedia')
                         ORDER BY marketplace, nama")->fetchAll();

/* partner_key, access_token, dan refresh_token TIDAK diambil dari basis
   data di sini. Yang tidak pernah dimuat tidak mungkin bocor ke halaman —
   termasuk lewat galat PHP yang menampilkan isi variabel. */
$akunList = [];
try {
    $akunList = $pdo->query(
        "SELECT a.id, a.toko_id, a.platform, a.partner_id, a.shop_id, a.nama_shop,
                a.status, a.pesan_terakhir, a.aktif,
                a.token_expired_at, a.terakhir_tarik_at, a.terakhir_segar_at,
                (a.access_token <> '') AS ada_token,
                (a.partner_key  <> '') AS ada_kunci,
                (a.fs_id IS NOT NULL AND a.fs_id <> '') AS ada_fs,
                t.nama AS nama_toko, t.kode AS kode_toko,
                (SELECT COUNT(*) FROM penjualan p
                  WHERE p.toko_id = a.toko_id AND p.sumber = 'api') AS jml_pesanan
         FROM marketplace_akun a
         JOIN toko t ON t.id = a.toko_id
         ORDER BY a.platform, t.nama"
    )->fetchAll();
} catch (Throwable $e) {
    $errors[] = 'Tabel sambungan belum dimigrasi. Jalankan migrasi_marketplace.sql.';
}

// Tarikan terakhir, untuk menelusuri masalah pemetaan field.
$jejak = [];
try {
    $jejak = $pdo->query(
        "SELECT k.created_at, k.endpoint, k.kode_http, k.sukses, k.pesan,
                k.no_pesanan, t.nama AS nama_toko
         FROM marketplace_tarikan k JOIN toko t ON t.id = k.toko_id
         ORDER BY k.id DESC LIMIT 15"
    )->fetchAll();
} catch (Throwable $e) { /* belum dimigrasi */ }

$PAGE_TITLE = 'Sambungan Marketplace'; $ACTIVE = 'pengaturan';
require __DIR__ . '/_header.php';
?>
<div class="page-head">
  <div><h1>Sambungan Marketplace</h1>
    <p>Menarik pesanan, status kiriman, dan rincian pembayaran langsung dari
      Shopee dan Tokopedia · <b>satu toko satu kredensial</b></p></div>
  <a class="btn btn-ghost" href="pengaturan.php">← Pengaturan</a>
</div>

<?php if ($errors): ?>
  <div class="flash flash-err"><?php foreach ($errors as $e) echo '• ' . e($e) . '<br>'; ?></div>
<?php endif; ?>

<?php /* Kesiapan diperiksa dan ditampilkan terang-terangan. Integrasi ini
         punya beberapa prasyarat di luar kode, dan menemukannya satu per
         satu lewat galat jauh lebih lama daripada melihatnya sekaligus. */ ?>
<?php
$siap = [
    ['ekstensi json',   extension_loaded('json'),
     'Wajib. Kedua marketplace hanya bicara JSON.'],
    ['ekstensi curl',   extension_loaded('curl'),
     'Wajib untuk memanggil API marketplace.'],
    ['HMAC SHA-256',    in_array('sha256', hash_hmac_algos(), true),
     'Wajib untuk menandatangani permintaan Shopee.'],
    ['HTTPS',           $skema === 'https',
     'Diperlukan Shopee untuk alamat kembali di lingkungan produksi. Tokopedia tidak memakainya.'],
];
$semuaSiap = !in_array(false, array_column($siap, 1), true);
?>
<div class="card mb16">
  <h3>Kesiapan server</h3>
  <div class="sub">Diperiksa langsung di server ini, bukan diasumsikan</div>
  <div class="al-tabel">
    <table>
      <thead><tr><th style="min-width:130px">Prasyarat</th><th>Status</th><th>Keterangan</th></tr></thead>
      <tbody>
      <?php foreach ($siap as [$nama, $ok, $ket]): ?>
        <tr><td><b><?= e($nama) ?></b></td>
          <td class="<?= $ok ? 'ya' : 'tidak' ?>"><?= $ok ? '✓ ada' : '— belum' ?></td>
          <td><?= e($ket) ?></td></tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php if (!$semuaSiap): ?>
    <div class="al-catat" style="border-left-color:var(--amber)">
      <b>Sebagian prasyarat belum terpenuhi.</b> Kredensial masih bisa disimpan,
      tetapi penarikan akan gagal sampai baris bertanda “belum” di atas beres.
      Untuk hosting cPanel, ekstensi diaktifkan di <b>Select PHP Version →
      Extensions</b>.
    </div>
  <?php endif; ?>
</div>

<?php /* ------------------------------------------------ daftar sambungan */ ?>
<div class="card mb16">
  <h3>Toko yang tersambung</h3>
  <div class="sub">Satu sambungan per toko</div>

  <?php if (!$akunList): ?>
    <div class="empty-state">
      <div class="ic"><svg aria-hidden="true"><use href="#i-toko"></use></svg></div>
      <b>Belum ada sambungan</b>
      <p>Isi kredensial di bawah untuk menyambungkan toko pertama.</p>
    </div>
  <?php else: ?>
    <div class="al-tabel">
      <table>
        <thead><tr>
          <th style="min-width:130px">Toko</th><th>Platform</th><th>Status</th><th>Token</th>
          <th>Terakhir tarik</th><th>Pesanan</th><th></th>
        </tr></thead>
        <tbody>
        <?php foreach ($akunList as $a):
          $peta = ['tersambung' => ['Tersambung','t-green'],
                   'belum_tersambung' => ['Belum diotorisasi','t-gray'],
                   'kedaluwarsa' => ['Token kedaluwarsa','t-amber'],
                   'error' => ['Bermasalah','t-red']];
          [$sLbl, $sCls] = $peta[$a['status']] ?? ['—','t-gray'];
          $kadaluarsa = $a['token_expired_at'] && strtotime($a['token_expired_at']) < time();
        ?>
          <tr>
            <td><b><?= e($a['nama_toko']) ?></b>
              <br><small class="mut"><?= e($a['kode_toko']) ?>
                <?= $a['shop_id'] ? ' · shop ' . e($a['shop_id']) : '' ?></small></td>
            <td><span class="tag <?= $a['platform'] === 'tokopedia' ? 't-green' : 't-orange' ?>">
                <?= e(MP_PLATFORM[$a['platform']] ?? $a['platform']) ?></span>
              <?php /* FS ID hanya bermakna bagi Tokopedia, dan tanpa itu
                       penarikannya pasti gagal — jadi ketiadaannya
                       ditunjukkan, bukan dibiarkan jadi kejutan. */ ?>
              <?php if ($a['platform'] === 'tokopedia' && !$a['ada_fs']): ?>
                <br><span class="tag t-red">FS ID belum ada</span>
              <?php endif; ?></td>
            <td><span class="tag <?= $sCls ?>"><?= e($sLbl) ?></span>
              <?php if (!$a['aktif']): ?><br><small class="mut">penarikan otomatis mati</small><?php endif; ?>
              <?php if ($a['pesan_terakhir']): ?>
                <br><small style="color:var(--red)"><?= e(mb_substr($a['pesan_terakhir'], 0, 90)) ?></small>
              <?php endif; ?></td>
            <td>
              <?php if (!$a['ada_kunci']): ?>
                <span class="tag t-red">Kunci belum ada</span>
              <?php elseif (!$a['ada_token'] && !mp_butuh_otorisasi($a['platform'])): ?>
                <?php /* Tokopedia mengambil token sendiri saat penarikan, jadi
                         "belum ada" di sini bukan masalah yang perlu diperbaiki. */ ?>
                <span class="mut-sm">diambil otomatis saat tarik</span>
              <?php elseif (!$a['ada_token']): ?>
                <span class="mut-sm">belum ada</span>
              <?php else: ?>
                <span class="tag <?= $kadaluarsa ? 't-amber' : 't-green' ?>">
                  <?= $kadaluarsa ? 'kedaluwarsa' : 'berlaku' ?></span>
                <br><small class="mut">s.d. <?= e(tgl_id($a['token_expired_at'], 'lengkap')) ?></small>
              <?php endif; ?></td>
            <td class="sekunder"><?= $a['terakhir_tarik_at']
                  ? e(tgl_id($a['terakhir_tarik_at'], 'lengkap')) : '—' ?></td>
            <td><b><?= angka($a['jml_pesanan']) ?></b></td>
            <td class="aksi">
              <div class="baris" style="gap:6px;flex-wrap:wrap">
                <?php if ($a['ada_kunci'] && mp_butuh_otorisasi($a['platform'])): ?>
                  <?php
                  /* URL otorisasi butuh partner_key untuk ditandatangani, jadi
                     ia dibuat SAAT DIKLIK lewat aksi tersendiri — bukan
                     ditanam di HTML, yang berarti tanda tangannya ikut
                     tersimpan di riwayat peramban.
                     Tombol ini TIDAK muncul untuk Tokopedia: halaman
                     otorisasi per lapak itu tidak ada di sana, dan menuntun
                     operator ke sana hanya membuatnya menyimpulkan
                     sambungannya rusak. */
                  ?>
                  <a class="btn btn-ghost btn-sm"
                     href="marketplace_auth.php?akun=<?= (int) $a['id'] ?>">Otorisasi</a>
                <?php endif; ?>
                <?php /* Tokopedia bisa langsung ditarik begitu kredensialnya ada. */ ?>
                <?php if ($a['ada_token'] || (!mp_butuh_otorisasi($a['platform']) && $a['ada_kunci'] && $a['ada_fs'])): ?>
                  <form method="post" style="display:inline">
                    <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                    <input type="hidden" name="aksi" value="tarik">
                    <input type="hidden" name="akun_id" value="<?= (int) $a['id'] ?>">
                    <input type="hidden" name="hari" value="7">
                    <button class="btn btn-primary btn-sm" type="submit">Tarik sekarang</button>
                  </form>
                  <form method="post" style="display:inline" data-konfirm="Putus sambungan toko ini?" data-akibat="Pesanan tidak lagi ditarik otomatis sampai disambungkan kembali." data-tombol="Ya, putus" data-warna="amber">
                    <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                    <input type="hidden" name="aksi" value="putus">
                    <input type="hidden" name="akun_id" value="<?= (int) $a['id'] ?>">
                    <button class="btn btn-ghost btn-sm" type="submit">Putus</button>
                  </form>
                <?php endif; ?>
              </div>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</div>

<?php /* ------------------------------------------------ formulir kredensial */ ?>
<div class="card mb16">
  <h3>Kredensial marketplace</h3>
  <div class="sub">Satu toko satu kredensial · platform mengikuti data tokonya</div>

  <form method="post">
    <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
    <input type="hidden" name="aksi" value="simpan">
    <div class="form-grid">
      <div class="field span-all"><label>Toko <span class="req">*</span></label>
        <select name="toko_id" id="pilihToko" required>
          <option value="">— pilih toko —</option>
          <?php foreach ($tokoList as $t): ?>
            <?php /* data-mp dipakai skrip di bawah untuk menyesuaikan label.
                     Nilainya juga sudah tersimpan di data toko, jadi server
                     tidak pernah memercayai atribut ini — ia hanya petunjuk
                     tampilan. */ ?>
            <option value="<?= (int) $t['id'] ?>" data-mp="<?= e($t['marketplace']) ?>">
              <?= e($t['nama']) ?> (<?= e($t['kode']) ?>) —
              <?= e(MP_PLATFORM[$t['marketplace']] ?? $t['marketplace']) ?>
            </option>
          <?php endforeach; ?>
        </select>
        <small class="petunjuk">Platform tidak dipilih di sini — ia dibaca dari
          kolom Marketplace pada data tokonya. Satu baris toko memang satu lapak.</small></div>

      <div class="field"><label id="lblPartnerId">Partner ID / Client ID <span class="req">*</span></label>
        <input name="partner_id" required placeholder="Shopee: Partner ID · Tokopedia: Client ID"></div>
      <div class="field"><label>Shop ID</label>
        <input name="shop_id" inputmode="numeric" placeholder="id lapak">
        <small class="petunjuk" id="hintShop">Shopee: terisi otomatis saat otorisasi.</small></div>

      <?php /* FS ID hanya ada di Tokopedia. Disembunyikan untuk Shopee, tapi
               TETAP ADA di HTML tanpa JavaScript — kolom yang hilang karena
               skrip gagal jauh lebih merepotkan daripada kolom yang tampil
               tapi tak terpakai. */ ?>
      <div class="field" id="kotakFs" hidden><label>FS ID <span class="req">*</span></label>
        <input name="fs_id" placeholder="Fulfillment Service ID">
        <small class="petunjuk">Khusus Tokopedia. Tanpa ini penarikannya pasti gagal.</small></div>

      <div class="field span-all"><label id="lblKey">Partner Key / Client Secret</label>
        <input type="password" name="partner_key" autocomplete="new-password"
               placeholder="kosongkan bila tidak ingin mengubah">
        <small class="petunjuk">
          <b>Tidak pernah ditampilkan kembali</b> — ia setara password. Kosongkan
          kolom ini bila hanya ingin mengubah data lain; kuncinya tidak akan
          terhapus.</small></div>

      <div class="field span-all">
        <label style="display:flex;align-items:center;gap:8px;cursor:pointer">
          <input type="checkbox" name="aktif" checked>
          Ikut penarikan otomatis
        </label></div>
    </div>
    <button class="btn btn-primary" type="submit">
      <svg aria-hidden="true"><use href="#i-simpan"></use></svg> Simpan Kredensial
    </button>
  </form>

  <div class="al-catat">
    <b>Alamat kembali untuk Shopee:</b>
    <br><code style="font-size:12px"><?= e($URL_KEMBALI) ?></code>
    <br><br>Daftarkan alamat ini di Shopee Open Platform — harus <b>persis sama</b>,
    beda satu karakter pun Shopee menolak mengembalikan pengguna setelah
    otorisasi. <b>Tokopedia tidak memerlukannya</b>: di sana tidak ada
    otorisasi per lapak.
  </div>
</div>

<script>
/* Menyesuaikan label agar cocok dengan konsol platform yang dipilih.
   Operator membaca label di konsol Shopee/Tokopedia, bukan nama kolom kami —
   label yang salah membuatnya menempelkan nilai ke kolom yang salah.

   Semuanya PENAMBAH: tanpa JavaScript, labelnya tetap menyebut kedua nama
   dan kolom FS ID tetap terlihat. Yang hilang hanya penyesuaiannya. */
(function () {
  'use strict';
  var pilih = document.getElementById('pilihToko');
  if (!pilih) { return; }

  var lblId  = document.getElementById('lblPartnerId');
  var lblKey = document.getElementById('lblKey');
  var kotakFs = document.getElementById('kotakFs');
  var hintShop = document.getElementById('hintShop');
  var inputFs = kotakFs ? kotakFs.querySelector('input') : null;

  function sesuaikan() {
    var opt = pilih.options[pilih.selectedIndex];
    var mp  = opt ? (opt.getAttribute('data-mp') || '') : '';

    if (mp === 'tokopedia') {
      lblId.innerHTML  = 'Client ID <span class="req">*</span>';
      lblKey.textContent = 'Client Secret';
      hintShop.textContent = 'Id lapak Tokopedia.';
      if (kotakFs) { kotakFs.hidden = false; }
      if (inputFs) { inputFs.required = true; }
    } else if (mp === 'shopee') {
      lblId.innerHTML  = 'Partner ID <span class="req">*</span>';
      lblKey.textContent = 'Partner Key';
      hintShop.textContent = 'Terisi otomatis saat otorisasi.';
      if (kotakFs) { kotakFs.hidden = true; }
      /* required DILEPAS saat disembunyikan. Kolom wajib yang tidak terlihat
         membuat formulir menolak dikirim tanpa memberi tahu kolom mana yang
         kosong — peramban tidak bisa menyorot kolom yang hidden. */
      if (inputFs) { inputFs.required = false; inputFs.value = ''; }
    } else {
      lblId.innerHTML  = 'Partner ID / Client ID <span class="req">*</span>';
      lblKey.textContent = 'Partner Key / Client Secret';
      hintShop.textContent = 'Shopee: terisi otomatis saat otorisasi.';
      if (kotakFs) { kotakFs.hidden = true; }
      if (inputFs) { inputFs.required = false; }
    }
  }

  pilih.addEventListener('change', sesuaikan);
  sesuaikan();
})();
</script>

<?php /* --------------------------------------------------------- jejak */ ?>
<?php if ($jejak): ?>
  <div class="card">
    <h3>15 panggilan terakhir</h3>
    <div class="sub">Untuk menelusuri masalah pemetaan field. Balasan mentahnya
      tersimpan di tabel <code>marketplace_tarikan</code>.</div>
    <div class="al-tabel">
      <table>
        <thead><tr><th>Waktu</th><th>Toko</th><th>Endpoint</th>
          <th>Pesanan</th><th>Hasil</th></tr></thead>
        <tbody>
        <?php foreach ($jejak as $j): ?>
          <tr>
            <td class="sekunder"><?= e(tgl_id($j['created_at'], 'lengkap')) ?></td>
            <td><?= e($j['nama_toko']) ?></td>
            <td><code><?= e(str_replace('/api/v2/', '', $j['endpoint'])) ?></code></td>
            <td class="sekunder"><?= e($j['no_pesanan'] ?: '—') ?></td>
            <td><?php if ($j['sukses']): ?>
                  <span class="tag t-green">OK</span>
                <?php else: ?>
                  <span class="tag t-red">HTTP <?= (int) $j['kode_http'] ?></span>
                  <?php if ($j['pesan']): ?><br><small class="mut"><?= e(mb_substr($j['pesan'], 0, 80)) ?></small><?php endif; ?>
                <?php endif; ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
<?php endif; ?>

<div class="card mt16">
  <h3>Urutan menyambungkan</h3>
  <div class="sub">Berbeda per platform — perbedaannya bukan detail kecil</div>

  <div class="al-tabel">
    <table>
      <thead><tr>
        <th style="min-width:120px">Langkah</th>
        <th style="min-width:230px">Shopee</th>
        <th style="min-width:230px">Tokopedia</th>
      </tr></thead>
      <tbody>
        <tr>
          <td><b>1. Daftar</b></td>
          <td>Daftarkan aplikasi di <b>open.shopee.com</b>, tunggu persetujuan Shopee.</td>
          <td>Ajukan akses <b>Tokopedia Seller API</b> (Fulfillment Service), tunggu persetujuan.</td>
        </tr>
        <tr>
          <td><b>2. Kredensial</b></td>
          <td>Catat <b>Partner ID</b> dan <b>Partner Key</b>.</td>
          <td>Catat <b>Client ID</b>, <b>Client Secret</b>, dan <b>FS ID</b>.</td>
        </tr>
        <tr>
          <td><b>3. Alamat kembali</b></td>
          <td>Daftarkan alamat kembali yang tertulis di atas — harus persis sama.</td>
          <td class="tidak">Tidak perlu.</td>
        </tr>
        <tr>
          <td><b>4. Simpan</b></td>
          <td colspan="2">Isi formulir di atas. Platform diambil dari data tokonya sendiri.</td>
        </tr>
        <tr>
          <td><b>5. Otorisasi</b></td>
          <td>Tekan <b>Otorisasi</b> — Anda dibawa ke Shopee untuk menyetujui,
            lalu dikembalikan ke sini. Shop ID terisi sendiri.</td>
          <td class="tidak">Tidak ada. Token diambil sendiri saat penarikan.</td>
        </tr>
        <tr>
          <td><b>6. Uji</b></td>
          <td colspan="2">Tekan <b>Tarik sekarang</b>, lalu periksa hasilnya di
            <b>Dasbor Toko &rsaquo; Pesanan Marketplace</b>. Bila ada yang
            aneh, lihat <b>15 panggilan terakhir</b> di bawah.</td>
        </tr>
        <tr>
          <td><b>7. Otomatis</b></td>
          <td colspan="2">Pasang penjadwal (cron) setiap 15 menit — petunjuknya
            di kepala berkas <code>cron_marketplace.php</code>. Satu penjadwal
            menangani semua toko dan semua platform.</td>
        </tr>
      </tbody>
    </table>
  </div>

  <div class="al-catat">
    <b>Kenapa Shopee butuh otorisasi dan Tokopedia tidak.</b>
    Shopee meminta setiap lapak menyetujui aplikasi Anda lewat halamannya
    sendiri, lalu memberi token per lapak yang harus diperbarui berkala.
    Tokopedia memakai satu kredensial Fulfillment Service dan menerbitkan
    token langsung dari kredensial itu — tanpa persetujuan per lapak, dan
    tanpa refresh token. Karena itu tombol yang tampil di tabel atas berbeda
    per platform; itu bukan kekeliruan tampilan.
  </div>

  <div class="al-catat" style="border-left-color:var(--amber)">
    <b>Satu toko satu kredensial.</b> Satu baris toko mewakili satu lapak, jadi
    ia hanya menyimpan satu kredensial. Merek yang berjualan di Shopee dan
    Tokopedia sekaligus dicatat sebagai <b>dua baris toko</b> — seperti
    “S STARKIDS” dan “T STARKIDS” pada data Anda — masing-masing dengan
    kredensialnya sendiri.
  </div>

  <div class="al-catat" style="border-left-color:var(--amber)">
    <b>Angka pembayaran di sini adalah salinan data marketplace</b>, bukan
    catatan kas. Untuk pembukuan lembaga, tetap cocokkan dengan mutasi bank dan
    dashboard marketplace sebelum dipakai sebagai angka resmi.
  </div>
</div>

<?php require __DIR__ . '/_footer.php'; ?>
