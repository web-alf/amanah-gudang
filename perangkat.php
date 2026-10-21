<?php
/**
 * AMANAH — Perangkat yang Dipakai
 *
 * Satu akun dibatasi aktif di 3 perangkat, dan login ke-4 DITOLAK. Batas
 * itu hanya adil kalau ada yang bisa melihat dan membuka kuncinya —
 * halaman ini pasangannya.
 *
 * Siapa melihat apa:
 *   Super Admin  seluruh akun, dan bisa mengakhiri sesi siapa pun
 *   Peran lain   perangkatnya sendiri saja, dan hanya bisa mengakhiri
 *                sesinya sendiri
 *
 * "Aktif" berarti terlihat dalam batas menganggur MILIK AKUN ITU —
 * angka yang sama yang memutus sesinya. Sesi yang browsernya ditutup tanpa
 * menekan Keluar gugur sendiri setelah ambang itu, jadi tidak ada yang
 * terkunci selamanya oleh sesinya sendiri yang sudah mati.
 *
 * CATATAN PRIVASI
 * Alamat IP dan jenis perangkat adalah data pribadi petugas. Halaman ini
 * memakainya untuk satu tujuan: mengenali sesi mana yang mau diakhiri.
 * Jangan disalin ke luar sistem.
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/_lib.php';
require_login();

$saya    = (int) ($_SESSION['user_id'] ?? 0);
$super   = is_super();
$sidSaya = sid_sekarang();

// ==================================================================== AKSI
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    wajib_csrf($_POST['csrf'] ?? null);
    $aksi = $_POST['aksi'] ?? '';
    $id   = (int) ($_POST['id'] ?? 0);

    if ($aksi === 'akhiri' && $id > 0) {
        // Pemilik sesi diperiksa di server. Tanpa ini, mengganti angka di
        // formulir cukup untuk mengeluarkan orang lain dari sistem.
        $st = $pdo->prepare("SELECT s.user_id, s.sid_hash, s.perangkat, u.nama
                             FROM sesi_perangkat s JOIN users u ON u.id = s.user_id
                             WHERE s.id = ? AND s.diakhiri_at IS NULL");
        $st->execute([$id]);
        $ses = $st->fetch();

        if (!$ses) {
            set_flash('err', 'Sesi itu sudah tidak aktif.');
        } elseif (!$super && (int) $ses['user_id'] !== $saya) {
            set_flash('err', 'Anda hanya dapat mengakhiri sesi milik akun Anda sendiri.');
        } else {
            $milikSendiri = (int) $ses['user_id'] === $saya;
            akhiri_perangkat($pdo, $id, $milikSendiri ? null : $saya);
            catat_aktivitas($pdo, 'update', 'sesi_perangkat', $id,
                'Akhiri sesi ' . $ses['perangkat'] . ' milik ' . $ses['nama']);

            // Mengakhiri sesi SENDIRI yang sedang dipakai = keluar.
            if (hash_equals($ses['sid_hash'], $sidSaya)) {
                header('Location: logout.php'); exit;
            }
            set_flash('ok', 'Sesi ' . $ses['perangkat'] . ' diakhiri. Perangkat itu akan '
                          . 'diminta masuk lagi saat halaman berikutnya dibuka.');
        }
        /* Penyaring dipertahankan. Datang dari Kelola Pengguna untuk
           mengeluarkan satu orang, lalu terlempar ke daftar seluruh akun,
           berarti harus mencarinya lagi hanya untuk memastikan berhasil. */
        $kembaliKe = (int) ($_GET['user'] ?? 0) > 0
            ? 'perangkat.php?user=' . (int) $_GET['user']
            : 'perangkat.php';
        header('Location: ' . $kembaliKe); exit;
    }
}

// ==================================================================== DATA
// Ambang milik akun yang sedang membuka halaman; tiap baris akun
// nanti memakai ambangnya sendiri.
$AMBANG = ambang_perangkat($pdo, $saya);

/* Penyaring satu akun, dipakai tautan dari halaman Kelola Pengguna.

   Hanya berlaku untuk Super Admin — peran lain memang sudah dibatasi ke
   dirinya sendiri, dan menerima ?user= dari mereka akan menjadi cara
   melihat siapa saja yang sedang masuk hanya dengan menebak angka. */
$saring = $super ? (int) ($_GET['user'] ?? 0) : 0;
$akunDisaring = null;

/** Akun yang ditampilkan: semua (Super Admin) atau hanya diri sendiri. */
try {
    if ($super) {
        $sqlAkun =
            "SELECT u.id, u.nama, u.email, r.label AS peran,
                    (SELECT COUNT(*) FROM sesi_perangkat s
                      WHERE s.user_id = u.id AND s.diakhiri_at IS NULL
                        AND s.terakhir_at >= (NOW() - INTERVAL u.idle_menit MINUTE)) AS aktif,
                    u.idle_menit, u.maks_perangkat
             FROM users u JOIN roles r ON r.id = u.role_id
             WHERE u.deleted_at IS NULL"
          . ($saring > 0 ? ' AND u.id = ' . $saring : '')
          . ' ORDER BY aktif DESC, u.nama';
        $akun = $pdo->query($sqlAkun)->fetchAll();
        if ($saring > 0) { $akunDisaring = $akun[0] ?? null; }
    } else {
        $st = $pdo->prepare(
            "SELECT u.id, u.nama, u.email, r.label AS peran,
                    (SELECT COUNT(*) FROM sesi_perangkat s
                      WHERE s.user_id = u.id AND s.diakhiri_at IS NULL
                        AND s.terakhir_at >= (NOW() - INTERVAL u.idle_menit MINUTE)) AS aktif,
                    u.idle_menit, u.maks_perangkat
             FROM users u JOIN roles r ON r.id = u.role_id WHERE u.id = ?"
        );
        $st->execute([$saya]);
        $akun = $st->fetchAll();
    }
} catch (Throwable $e) {
    error_log('[AMANAH] perangkat daftar akun: ' . $e->getMessage());
    $akun = [];
}

/* Sesi hidup DAN sesi basi, dikelompokkan per akun.

   Kenapa yang basi ikut diambil: ia belum ditutup, hanya sudah lewat
   ambang menganggur. Selama barisnya masih ada, ia BANGKIT KEMBALI
   begitu idle_menit akun itu dinaikkan — ambangnya bergerak, barisnya
   tidak. Menaikkan batas menganggur dari 30 menit ke 30 hari akan
   menghidupkan lagi setiap sesi yang tertinggal sebulan terakhir, dan itu
   tidak boleh terjadi tanpa ada yang bisa melihat dan menutupnya.

   Dipisah jadi dua daftar, bukan satu bercampur: yang aktif menuntut
   tindakan sekarang, yang basi hanya perlu dibersihkan. */
$sesi     = [];   // dalam ambang — memakai slot
$sesiBasi = [];   // lewat ambang — tidak memakai slot, tapi belum ditutup
try {
    $batasUser = $super ? ($saring > 0 ? ' AND s.user_id = ' . $saring : '')
                        : ' AND s.user_id = ' . (int) $saya;
    $sql = "SELECT s.*, u.nama AS nama_user,
                   (s.terakhir_at >= (NOW() - INTERVAL u.idle_menit MINUTE)) AS masih_hidup
            FROM sesi_perangkat s JOIN users u ON u.id = s.user_id
            WHERE s.diakhiri_at IS NULL" . $batasUser
         . ' ORDER BY s.terakhir_at DESC';
    foreach ($pdo->query($sql) as $r) {
        if ((int) $r['masih_hidup'] === 1) { $sesi[(int) $r['user_id']][] = $r; }
        else                              { $sesiBasi[(int) $r['user_id']][] = $r; }
    }
} catch (Throwable $e) {
    error_log('[AMANAH] perangkat daftar sesi: ' . $e->getMessage());
}

$totalAktif = array_sum(array_map('count', $sesi));
$penuh      = 0;
/* "Penuh" dihitung terhadap batas MASING-MASING akun.

   Dengan angka global, akun berbatas 1 yang sudah terpakai tidak pernah
   ikut terhitung penuh — dan ringkasan di atas halaman melaporkan nol
   akun penuh padahal ada yang tidak bisa masuk. Akun tanpa batas (0)
   memang tidak pernah penuh. */
foreach ($akun as $a) {
    $mp  = $a['maks_perangkat'] ?? null;
    $bat = $mp === null ? MAKS_PERANGKAT : (int) $mp;
    if ($bat > 0 && (int) $a['aktif'] >= $bat) { $penuh++; }
}

$PAGE_TITLE = 'Perangkat'; $ACTIVE = 'pengaturan';
require __DIR__ . '/_header.php';
?>
<div class="page-head">
  <div><h1>Perangkat yang Dipakai</h1>
    <p>Batasnya diatur per akun — bawaannya <?= MAKS_PERANGKAT ?> perangkat.
      <?= $super ? 'Seluruh akun terlihat di sini.' : 'Anda melihat perangkat akun Anda sendiri.' ?></p></div>
</div>

<?php if ($akunDisaring): ?>
  <?php /* Penyaring dinyatakan terang-terangan beserta jalan keluarnya.
           Daftar yang tersaring tanpa penanda terbaca sebagai "hanya satu
           orang yang sedang masuk" — kesimpulan yang salah dan menyesatkan
           justru pada halaman yang dipakai memeriksa keamanan. */ ?>
  <div class="alert" style="--ac:var(--info);margin-bottom:18px">
    <span class="alert-ic"><svg aria-hidden="true"><use href="#i-info"></use></svg></span>
    <div class="alert-tx">
      <b>Menampilkan satu akun: <?= e($akunDisaring['nama']) ?></b>
      <small><?= e($akunDisaring['email']) ?> ·
        <a href="perangkat.php">Lihat seluruh akun</a></small>
    </div>
  </div>
<?php elseif ($super && (int) ($_GET['user'] ?? 0) > 0): ?>
  <div class="alert" style="--ac:var(--amber);margin-bottom:18px">
    <span class="alert-ic"><svg aria-hidden="true"><use href="#i-info"></use></svg></span>
    <div class="alert-tx">
      <b>Akun itu tidak ditemukan</b>
      <small>Mungkin sudah dinonaktifkan. <a href="perangkat.php">Lihat seluruh akun</a></small>
    </div>
  </div>
<?php endif; ?>

<div class="alert" style="--ac:var(--info);margin-bottom:18px">
  <span class="alert-ic"><svg aria-hidden="true"><use href="#i-info"></use></svg></span>
  <div class="alert-tx">
    <b>Perangkat dihitung aktif selama sesinya masih bisa dipakai</b>
    <small>Ambangnya mengikuti batas menganggur tiap akun — akun Anda:
      <b><?= e(label_idle($AMBANG)) ?></b>. Menutup browser tanpa menekan <b>Keluar</b> tidak
      langsung membebaskan slotnya; sesi itu gugur sendiri setelah ambang itu. Kalau perlu
      segera, akhiri sesinya di sini.</small>
  </div>
</div>

<div class="kpi">
  <div class="kpi-card" style="--kc:var(--primary-2)">
    <div class="kpi-top"><span class="kpi-l">Sesi Aktif</span>
      <span class="kpi-ic"><svg aria-hidden="true"><use href="#i-users"></use></svg></span></div>
    <div class="kpi-v"><?= angka($totalAktif) ?></div>
    <div class="kpi-d">di <?= angka(count($sesi)) ?> akun</div>
  </div>
  <div class="kpi-card<?= $penuh > 0 ? ' perlu' : '' ?>" style="--kc:var(--amber)">
    <div class="kpi-top"><span class="kpi-l">Kuota Penuh</span>
      <span class="kpi-ic"><svg aria-hidden="true"><use href="#i-alert"></use></svg></span></div>
    <div class="kpi-v"><?= angka($penuh) ?></div>
    <div class="kpi-d">akun tidak bisa menambah perangkat</div>
  </div>
</div>

<?php foreach ($akun as $a):
  $uid   = (int) $a['id'];
  $milik = $sesi[$uid] ?? [];
  $basi  = $sesiBasi[$uid] ?? [];

  /* Akun tanpa sesi apa pun dilewati saat melihat SELURUH akun — kalau
     tidak, daftarnya penuh baris "0 perangkat" dan yang penting tenggelam.

     TAPI tidak dilewati saat tersaring ke satu akun. Datang dari Kelola
     Pengguna untuk memeriksa satu orang lalu menemukan halaman kosong
     tanpa penjelasan adalah ujung mati — dan itu yang terjadi sebelum
     baris ini dibetulkan: akun yang justru paling sering ingin diperiksa
     (yang sedang tidak online) tidak punya kartu sama sekali. */
  if ($super && $saring === 0 && !$milik && !$basi) { continue; }
?>
  <div class="card mb16">
    <div class="baris" style="justify-content:space-between;align-items:flex-start">
      <div>
        <h3><?= e($a['nama']) ?></h3>
        <div class="sub"><?= e($a['peran']) ?> · <?= e($a['email']) ?></div>
      </div>
      <div class="baris">
        <span class="tag t-gray"><svg aria-hidden="true"><use href="#i-clock"></use></svg>
          <?= e(label_idle((int) ($a['idle_menit'] ?? IDLE_MENIT_BAWAAN))) ?></span>
        <?php /* Batas MILIK AKUN INI. Menyebut angka global membuat
                 akun berbatas 1 tampil sebagai "1 / 3" — dan orang
                 menyimpulkan masih ada dua slot yang sebenarnya tidak ada.

                 null berarti ikut bawaan; 0 berarti tanpa batas, dan
                 pembanding >= tidak berlaku untuk itu. */
              $mpA  = $a['maks_perangkat'] ?? null;
              $batA = $mpA === null ? MAKS_PERANGKAT : (int) $mpA;
              $penuhA = $batA > 0 && (int) $a['aktif'] >= $batA; ?>
        <span class="tag <?= $penuhA ? 't-amber' : 't-green' ?>">
          <?= (int) $a['aktif'] ?> / <?= $batA === 0 ? '∞' : $batA ?> perangkat
        </span>
      </div>
    </div>

    <?php if (!$milik): ?>
      <div class="empty">Tidak ada perangkat aktif.</div>
    <?php else: ?>
      <div class="tbl-wrap"><table class="tbl-kartu">
        <thead><tr><th>Perangkat</th><th>Alamat IP</th><th>Mulai</th>
          <th>Aktivitas Terakhir</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($milik as $s):
          $iniSaya = hash_equals($s['sid_hash'], $sidSaya); ?>
          <tr>
            <td data-label="Perangkat"><b><?= e($s['perangkat'] ?: 'Tidak dikenal') ?></b>
              <?php if ($iniSaya): ?>
                <span class="tag t-green">perangkat ini</span>
              <?php endif; ?></td>
            <td class="sekunder" data-label="Alamat IP"><code><?= e($s['ip'] ?: '—') ?></code></td>
            <td class="sekunder" data-label="Mulai"><?= e(tgl_jam_id($s['dibuat_at'])) ?></td>
            <td data-label="Aktivitas Terakhir"><?= e(tgl_jam_id($s['terakhir_at'])) ?></td>
            <td class="aksi">
              <form method="post" style="display:inline">
                <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="aksi" value="akhiri">
                <input type="hidden" name="id" value="<?= (int) $s['id'] ?>">
                <button class="btn btn-red btn-sm">
                  <svg aria-hidden="true"><use href="#i-keluar-pintu"></use></svg>
                  <?= $iniSaya ? 'Keluar dari Sini' : 'Akhiri Sesi' ?>
                </button>
              </form>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table></div>
    <?php endif; ?>

    <?php /* ------------------------------------------------ SESI BASI
             Sesi yang belum ditutup tapi sudah lewat ambang menganggur.
             Tidak memakai slot dan tidak bisa dipakai — SELAMA ambangnya
             tidak berubah. Menaikkan batas menganggur akun ini akan
             menghidupkannya kembali, karena ambangnya bergerak sementara
             barisnya tidak. Itu sebabnya ia ditampilkan dan bisa
             diakhiri, bukan disembunyikan. */ ?>
    <?php if ($basi): ?>
      <details style="margin-top:14px">
        <summary style="cursor:pointer;font-size:13px;color:var(--muted)">
          <?= count($basi) ?> sesi lama belum ditutup —
          tidak aktif, tapi bisa hidup lagi bila batas menganggur dinaikkan
        </summary>
        <div class="tbl-wrap" style="margin-top:10px"><table class="tbl-kartu">
          <thead><tr><th>Perangkat</th><th>Alamat IP</th><th>Mulai</th>
            <th>Terakhir Aktif</th><th></th></tr></thead>
          <tbody>
          <?php foreach ($basi as $s): ?>
            <tr>
              <td data-label="Perangkat"><?= e($s['perangkat'] ?: 'Tidak dikenal') ?></td>
              <td class="sekunder" data-label="Alamat IP"><code><?= e($s['ip'] ?: '—') ?></code></td>
              <td class="sekunder" data-label="Mulai"><?= e(tgl_jam_id($s['dibuat_at'])) ?></td>
              <td class="sekunder" data-label="Terakhir Aktif"><?= e(tgl_jam_id($s['terakhir_at'])) ?></td>
              <td class="aksi">
                <form method="post" style="display:inline">
                  <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                  <input type="hidden" name="aksi" value="akhiri">
                  <input type="hidden" name="id" value="<?= (int) $s['id'] ?>">
                  <button class="btn btn-ghost btn-sm">
                    <svg aria-hidden="true"><use href="#i-keluar-pintu"></use></svg> Tutup
                  </button>
                </form>
              </td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table></div>
      </details>
    <?php endif; ?>
  </div>
<?php endforeach; ?>

<?php /* Hanya saat melihat SELURUH akun. Bila tersaring ke satu akun,
         kartunya sudah dirender di atas beserta keterangannya sendiri —
         menambahkan "belum ada sesi aktif" di bawahnya membuat satu
         halaman menyatakan dua hal tentang hal yang sama. */ ?>
<?php if ($super && $saring === 0 && $totalAktif === 0): ?>
  <div class="card">
    <div class="empty-state">
      <div class="ic"><svg aria-hidden="true"><use href="#i-users"></use></svg></div>
      <b>Belum ada sesi aktif</b>
      <p>Sesi tercatat begitu ada yang masuk. Sesi yang sudah lewat batas menganggur
        akunnya masing-masing tidak lagi dihitung.</p>
    </div>
  </div>
<?php endif; ?>

<?php require __DIR__ . '/_footer.php'; ?>
