<?php
/**
 * AMANAH — Profil Pengguna
 *
 * Rumah untuk data akun sendiri: identitas, keterkaitan (supplier/toko),
 * jejak aktivitas, dan pintu ke Ganti Password. Dapat diakses semua peran
 * — termasuk supplier & toko, karena ini data mereka sendiri.
 *
 * Yang BOLEH diubah sendiri hanya nama dan telepon. Email dan peran
 * menentukan hak akses, jadi perubahannya wewenang Super Admin — kalau
 * pengguna bisa mengubah emailnya sendiri, jejak audit ikut bergeser.
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/_lib.php';
require_login();

$uid    = (int) ($_SESSION['user_id'] ?? 0);
$errors = [];

// ---------------------------------------------------------------- SIMPAN
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['aksi'] ?? '') === 'simpan') {
    wajib_csrf($_POST['csrf'] ?? null);

    $nama    = trim($_POST['nama'] ?? '');
    $telepon = trim($_POST['telepon'] ?? '');

    if ($nama === '')              { $errors[] = 'Nama wajib diisi.'; }
    if (mb_strlen($nama) > 150)    { $errors[] = 'Nama maksimal 150 huruf.'; }
    if (mb_strlen($telepon) > 30)  { $errors[] = 'Telepon maksimal 30 karakter.'; }

    if (!$errors) {
        try {
            $pdo->prepare("UPDATE users SET nama = ?, telepon = ? WHERE id = ?")
                ->execute([$nama, $telepon !== '' ? $telepon : null, $uid]);

            // Sesi ikut diperbarui supaya nama di topbar berubah seketika,
            // tanpa perlu keluar-masuk lagi.
            $_SESSION['user']['nama'] = $nama;

            catat_aktivitas($pdo, 'update', 'profil', $uid, 'Perbarui profil sendiri');
            set_flash('ok', 'Profil diperbarui.');
            header('Location: profil.php'); exit;
        } catch (Throwable $e) {
            $errors[] = pesan_error_aman($e, 'simpan profil');
        }
    }
}

// ------------------------------------------------------------------ DATA
$st = $pdo->prepare(
    "SELECT u.*, r.nama AS role, r.label AS role_label, r.keterangan AS role_ket
     FROM users u JOIN roles r ON r.id = u.role_id
     WHERE u.id = ? AND u.deleted_at IS NULL LIMIT 1"
);
$st->execute([$uid]);
$p = $st->fetch();

if (!$p) {
    set_flash('err', 'Data akun tidak ditemukan.');
    header('Location: logout.php'); exit;
}

// Keterkaitan sesuai peran — supplier ke satu supplier, toko bisa banyak.
$namaSupplier = null;
if ($p['supplier_id']) {
    $s = $pdo->prepare("SELECT kode, nama FROM supplier WHERE id = ?");
    $s->execute([(int) $p['supplier_id']]);
    $namaSupplier = $s->fetch() ?: null;
}

$tokoSaya = [];
try {
    $t = $pdo->prepare(
        "SELECT tk.kode, tk.nama, tk.marketplace
         FROM user_toko ut JOIN toko tk ON tk.id = ut.toko_id
         WHERE ut.user_id = ? AND tk.deleted_at IS NULL ORDER BY tk.kode"
    );
    $t->execute([$uid]);
    $tokoSaya = $t->fetchAll();
} catch (Throwable $e) {
    error_log('[AMANAH] profil toko: ' . $e->getMessage());
}

// Jejak aktivitas sendiri. Berguna sebagai pemeriksaan mandiri: kalau ada
// baris yang tidak dikenali pemiliknya, itu tanda akun dipakai orang lain.
$jejak = [];
$jmlJejak = 0;
try {
    $j = $pdo->prepare(
        "SELECT aksi, modul, deskripsi, ip_address, created_at
         FROM activity_log WHERE user_id = ? ORDER BY id DESC LIMIT 15"
    );
    $j->execute([$uid]);
    $jejak = $j->fetchAll();

    $c = $pdo->prepare("SELECT COUNT(*) FROM activity_log WHERE user_id = ?");
    $c->execute([$uid]);
    $jmlJejak = (int) $c->fetchColumn();
} catch (Throwable $e) {
    error_log('[AMANAH] profil jejak: ' . $e->getMessage());
}

/** Ikon & warna jejak — sama dengan yang dipakai dashboard. */
function gaya_jejak(string $aksi): array {
    $peta = [
        'create'  => ['i-plus',          'var(--green)'],
        'update'  => ['i-ubah',          'var(--info)'],
        'delete'  => ['i-hapus',         'var(--red)'],
        'approve' => ['i-cek-bulat',     'var(--green)'],
        'reject'  => ['i-x',             'var(--red)'],
        'print'   => ['i-cetak',         'var(--muted)'],
        'impor'   => ['i-impor',         'var(--accent)'],
        'login'   => ['i-keluar-pintu',  'var(--primary)'],
    ];
    return $peta[$aksi] ?? ['i-info', 'var(--primary)'];
}

$pecahP  = preg_split('/\s+/', trim((string) $p['nama'])) ?: [];
$inisialP = strtoupper(mb_substr($pecahP[0] ?? 'U', 0, 1)
          . (isset($pecahP[1]) ? mb_substr($pecahP[1], 0, 1) : '')) ?: 'U';

$PAGE_TITLE = 'Profil Saya'; $ACTIVE = 'profil';
if (is_eksternal()) {
    // Peran eksternal memakai kerangka dasbornya sendiri. Menunya tidak
    // disebut di sini — _header.php memilihnya dari peran.
    $SUB_JUDUL = ident($p['role_label'] ?? '', APP_LEMBAGA);
    $AKTIF     = 'profil';
    require __DIR__ . '/_header_luar.php';
} else {
    require __DIR__ . '/_header.php';
}
?>
<div class="page-head">
  <div><h1>Profil Saya</h1>
    <p>Data akun, keterkaitan, dan jejak aktivitas Anda</p></div>
  <div class="aksi">
    <a class="btn btn-ghost" href="ganti_password.php">
      <svg aria-hidden="true"><use href="#i-gear"></use></svg> Ganti Password
    </a>
    <a class="btn btn-red" href="logout.php">
      <svg aria-hidden="true"><use href="#i-keluar-pintu"></use></svg> Keluar
    </a>
  </div>
</div>

<?php if ($errors): ?>
  <div class="flash flash-err" data-tetap="1">
    <?php foreach ($errors as $er) echo '• ' . e($er) . '<br>'; ?>
  </div>
<?php endif; ?>

<div class="card profil-kepala">
  <div class="profil-av"><?= e($inisialP) ?></div>
  <div class="profil-id">
    <h3><?= e($p['nama']) ?></h3>
    <div class="sub" style="margin:0"><?= e($p['email']) ?></div>
    <div class="row-tag">
      <span class="tag t-purple"><?= e($p['role_label']) ?></span>
      <?php if ((int) $p['is_active'] === 1): ?>
        <span class="tag t-green">Aktif</span>
      <?php else: ?>
        <span class="tag t-red">Nonaktif</span>
      <?php endif; ?>
      <?php if ($namaSupplier): ?>
        <span class="tag t-blue"><?= e($namaSupplier['nama']) ?></span>
      <?php endif; ?>
      <?php foreach ($tokoSaya as $tk): ?>
        <span class="tag t-blue"><?= e($tk['nama']) ?></span>
      <?php endforeach; ?>
    </div>
    <?php if ($p['role_ket']): ?>
      <p style="color:var(--muted);font-size:12.5px;margin:10px 0 0;max-width:62ch">
        <?= e($p['role_ket']) ?></p>
    <?php endif; ?>
  </div>
</div>

<div class="kpi" style="margin-top:16px">
  <div class="kpi-card" style="--kc:var(--primary-2)">
    <div class="kpi-top"><span class="kpi-l">Terakhir Masuk</span>
      <span class="kpi-ic"><svg aria-hidden="true"><use href="#i-clock"></use></svg></span></div>
    <div class="kpi-v kecil"><?= $p['last_login_at'] ? e(tgl_id($p['last_login_at'])) : '—' ?></div>
    <div class="kpi-d"><?= $p['last_login_at'] ? e(date('H:i', strtotime($p['last_login_at']))) . ' WIB' : 'Belum pernah' ?></div>
  </div>
  <div class="kpi-card" style="--kc:var(--accent)">
    <div class="kpi-top"><span class="kpi-l">Terdaftar Sejak</span>
      <span class="kpi-ic"><svg aria-hidden="true"><use href="#i-cal"></use></svg></span></div>
    <div class="kpi-v kecil"><?= $p['created_at'] ? e(tgl_id($p['created_at'])) : '—' ?></div>
    <div class="kpi-d">Tanggal akun dibuat</div>
  </div>
  <div class="kpi-card" style="--kc:var(--green)">
    <div class="kpi-top"><span class="kpi-l">Total Aktivitas</span>
      <span class="kpi-ic"><svg aria-hidden="true"><use href="#i-grafik"></use></svg></span></div>
    <div class="kpi-v"><?= angka($jmlJejak) ?></div>
    <div class="kpi-d">Tercatat di jejak audit</div>
  </div>
  <div class="kpi-card" style="--kc:var(--info)">
    <div class="kpi-top"><span class="kpi-l">Peran</span>
      <span class="kpi-ic"><svg aria-hidden="true"><use href="#i-users"></use></svg></span></div>
    <div class="kpi-v kecil"><?= e($p['role_label']) ?></div>
    <div class="kpi-d">Diatur oleh Super Admin</div>
  </div>
</div>

<div class="profil-grid">
  <div class="card">
    <h3>Ubah Data Saya</h3>
    <div class="sub">Hanya nama dan telepon yang bisa Anda ubah sendiri</div>
    <form method="post">
      <input type="hidden" name="aksi" value="simpan">
      <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">

      <div class="field">
        <label>Nama Lengkap <span class="req">*</span></label>
        <input name="nama" value="<?= e($_POST['nama'] ?? $p['nama']) ?>" maxlength="150" required>
      </div>
      <div class="field">
        <label>Telepon</label>
        <input name="telepon" value="<?= e($_POST['telepon'] ?? ($p['telepon'] ?? '')) ?>"
               maxlength="30" placeholder="0812-3456-7890">
      </div>
      <div class="field">
        <label>Email</label>
        <input value="<?= e($p['email']) ?>" disabled>
        <small class="petunjuk">Email dipakai untuk masuk dan menandai jejak audit.
          Perubahannya lewat Super Admin.</small>
      </div>
      <div class="field">
        <label>Peran</label>
        <input value="<?= e($p['role_label']) ?>" disabled>
        <small class="petunjuk">Peran menentukan hak akses, jadi tidak bisa diubah sendiri.</small>
      </div>

      <button class="btn btn-primary">
        <svg aria-hidden="true"><use href="#i-cek"></use></svg> Simpan Perubahan
      </button>
    </form>
  </div>

  <div class="card">
    <h3>Keamanan Akun</h3>
    <div class="sub">Jaga akses Anda sendiri</div>

    <ul class="tl">
      <li>
        <span class="tl-ic" style="--tc:var(--primary)">
          <svg aria-hidden="true"><use href="#i-gear"></use></svg></span>
        <div class="tl-tx"><b>Password</b>
          <small>Ganti berkala, jangan dipakai bersama akun lain</small></div>
        <a class="btn btn-ghost btn-sm" href="ganti_password.php">Ganti</a>
      </li>
      <li>
        <span class="tl-ic" style="--tc:var(--green)">
          <svg aria-hidden="true"><use href="#i-clock"></use></svg></span>
        <div class="tl-tx"><b>Sesi berakhir otomatis</b>
          <small>Setelah 30 menit tanpa aktivitas</small></div>
      </li>
      <li>
        <span class="tl-ic" style="--tc:var(--amber)">
          <svg aria-hidden="true"><use href="#i-alert"></use></svg></span>
        <div class="tl-tx"><b>Percobaan masuk dibatasi</b>
          <small>Terkunci 10 menit setelah 5 kali gagal</small></div>
      </li>
      <li>
        <span class="tl-ic" style="--tc:var(--red)">
          <svg aria-hidden="true"><use href="#i-keluar-pintu"></use></svg></span>
        <div class="tl-tx"><b>Keluar dari sistem</b>
          <small>Selalu keluar bila memakai komputer bersama</small></div>
        <a class="btn btn-red btn-sm" href="logout.php">Keluar</a>
      </li>
    </ul>
  </div>
</div>

<div class="card" style="margin-top:16px">
  <h3>Jejak Aktivitas Saya</h3>
  <div class="sub"><?= angka($jmlJejak) ?> aktivitas tercatat · 15 terakhir ditampilkan.
    Bila ada baris yang bukan Anda lakukan, segera ganti password.</div>

  <?php if (!$jejak): ?>
    <div class="empty">Belum ada aktivitas tercatat untuk akun ini.</div>
  <?php else: ?>
    <div class="tbl-wrap"><table class="tbl-kartu">
      <thead><tr><th>Aktivitas</th><th>Modul</th><th>Alamat IP</th><th>Waktu</th></tr></thead>
      <tbody>
      <?php foreach ($jejak as $j): [$ik, $wrn] = gaya_jejak($j['aksi']); ?>
        <tr>
          <td data-label="Aktivitas">
            <div style="display:flex;gap:10px;align-items:center">
              <span class="tl-ic" style="--tc:<?= $wrn ?>;width:27px;height:27px">
                <svg aria-hidden="true"><use href="#<?= $ik ?>"></use></svg></span>
              <b><?= e($j['deskripsi'] ?: $j['aksi']) ?></b>
            </div>
          </td>
          <td data-label="Modul"><span class="tag t-gray"><?= e($j['modul'] ?: '—') ?></span></td>
          <td data-label="Alamat IP"><code><?= e($j['ip_address'] ?: '—') ?></code></td>
          <td data-label="Waktu"><?= e(tgl_jam_id($j['created_at'])) ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table></div>
  <?php endif; ?>
</div>

<?php require __DIR__ . '/_footer.php'; ?>
