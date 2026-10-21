<?php
/**
 * AMANAH — Pengaturan
 *
 * Satu pintu untuk master data & administrasi sistem. Halaman-halaman
 * tujuannya tidak berubah sedikit pun; yang berubah hanya cara masuknya.
 * Dulu keenam master data berdiri sendiri di sidebar dan mendorong menu
 * operasional harian ke bawah lipatan layar.
 *
 * Susunan kartunya datang dari grup_pengaturan() di _lib.php supaya
 * sidebar dan halaman ini tidak pernah berbeda isi.
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/_lib.php';
require_login();
tolak_supplier();

$grup    = grup_pengaturan();
$canEdit = boleh_edit();

/**
 * Jumlah baris per master data, untuk ditampilkan di kartunya.
 *
 * Query ditulis eksplisit (bukan nama tabel dari array) supaya tidak ada
 * jalan bagi nama tabel untuk masuk ke SQL. Kegagalan tidak boleh
 * menjatuhkan halaman — kartu tetap berguna tanpa angka.
 */
function hitung_baris(PDO $pdo, string $sql): ?int {
    try { return (int) $pdo->query($sql)->fetchColumn(); }
    catch (Throwable $e) {
        error_log('[AMANAH] pengaturan hitung: ' . $e->getMessage());
        return null;
    }
}

$jml = [
    'barang'   => hitung_baris($pdo, "SELECT COUNT(*) FROM barang     WHERE deleted_at IS NULL"),
    'kategori' => hitung_baris($pdo, "SELECT COUNT(*) FROM kategori   WHERE deleted_at IS NULL"),
    'gudang'   => hitung_baris($pdo, "SELECT COUNT(*) FROM gudang     WHERE deleted_at IS NULL"),
    'rak'      => hitung_baris($pdo, "SELECT COUNT(*) FROM lokasi_rak WHERE deleted_at IS NULL"),
    'supplier' => hitung_baris($pdo, "SELECT COUNT(*) FROM supplier   WHERE deleted_at IS NULL"),
    'toko'     => hitung_baris($pdo, "SELECT COUNT(*) FROM toko       WHERE deleted_at IS NULL"),
    'pengguna' => hitung_baris($pdo, "SELECT COUNT(*) FROM users WHERE deleted_at IS NULL"),
    'akun_sup' => hitung_baris($pdo, "SELECT COUNT(*) FROM users u JOIN roles r ON r.id = u.role_id
                                      WHERE r.nama = 'supplier' AND u.deleted_at IS NULL"),
    'akun_tok' => hitung_baris($pdo, "SELECT COUNT(*) FROM users u JOIN roles r ON r.id = u.role_id
                                      WHERE r.nama = 'toko' AND u.deleted_at IS NULL"),
];

$PAGE_TITLE = 'Pengaturan'; $ACTIVE = 'pengaturan';
require __DIR__ . '/_header.php';
?>
<div class="page-head">
  <div><h1>Pengaturan</h1>
    <p>Master data &amp; administrasi sistem<?= $canEdit ? '' : ' · Anda hanya dapat melihat' ?></p></div>
  <a class="btn btn-ghost" href="<?= e(beranda_peran()) ?>">← Kembali ke Dashboard</a>
</div>

<?php if (!$canEdit): ?>
  <div class="flash flash-err">
    👁️ Peran Anda hanya berhak <b>melihat</b> master data. Tombol tambah, ubah, dan hapus
    tidak akan muncul di halaman tujuan.
  </div>
<?php endif; ?>

<?php foreach ($grup as $g): ?>
  <div class="set-grup">
    <h3><?= e($g['judul']) ?></h3>
    <p><?= e($g['ket']) ?></p>
    <div class="set-grid">
      <?php foreach ($g['item'] as $it): $n = $jml[$it['k']] ?? null; ?>
        <a class="card" href="<?= e($it['h']) ?>">
          <?php /* $it['i'] adalah id sprite ("i-impor"), bukan emoji lagi —
                   dulu dicetak apa adanya sehingga tampil sebagai teks mentah. */ ?>
          <span class="set-ic"><svg aria-hidden="true"><use href="#<?= e($it['i']) ?>"></use></svg></span>
          <div class="set-tx">
            <b><?= e($it['t']) ?></b>
            <p><?= e($it['d']) ?></p>
            <?php if ($n !== null): ?>
              <div class="set-jml"><span class="tag t-blue"><?= angka($n) ?> <?= e($it['sat']) ?></span></div>
            <?php endif; ?>
          </div>
        </a>
      <?php endforeach; ?>
    </div>
  </div>
<?php endforeach; ?>

<?php require __DIR__ . '/_footer.php'; ?>
