<?php
/**
 * AMANAH — Pusat Notifikasi
 *
 * Satu halaman berisi semua yang menunggu tindakan akun ini: dokumen yang
 * belum diputuskan, kiriman di jalan, batch yang kedaluwarsa, stok yang
 * habis, perbaikan yang menggantung. Sampai 13 Sep 2026 web hanya punya
 * lonceng berangka yang melompat ke satu halaman; aplikasi Android sudah
 * punya daftar lengkapnya lewat api/notifikasi.php. Sekarang keduanya
 * memakai penyusun yang sama (_notifikasi.php) — lihat catatan di sana.
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/_lib.php';
require_once __DIR__ . '/api/_lingkup.php';
require_once __DIR__ . '/_notifikasi.php';
require_login();

$p = principal_sesi($pdo);
$hasil = ['data' => [], 'jumlah' => 0, 'penting' => 0];
$gagal = '';
try {
    $hasil = susun_notifikasi($pdo, $p);
} catch (Throwable $e) {
    error_log('[AMANAH] notifikasi: ' . $e->getMessage());
    $gagal = 'Daftar notifikasi tidak bisa disusun. Coba muat ulang; bila terus terjadi, laporkan ke pengelola sistem.';
}

$kelompok = ['penting' => [], 'waspada' => [], 'info' => []];
foreach ($hasil['data'] as $d) { $kelompok[$d['tingkat']][] = $d; }

$ikon = [
    'persetujuan'    => 'i-cek-bulat',
    'penerimaan'     => 'i-paket',
    'expired'        => 'i-alert',
    'expired_h7'     => 'i-cal',
    'expired_h30'    => 'i-cal',
    'stok_habis'     => 'i-box',
    'stok_menipis'   => 'i-box',
    'perbaikan_lama' => 'i-putar',
];
$warnaTingkat = ['penting' => 'var(--red)', 'waspada' => 'var(--amber)', 'info' => 'var(--info)'];
$judulTingkat = ['penting' => 'Perlu tindakan sekarang', 'waspada' => 'Perlu diperhatikan', 'info' => 'Informasi'];

$PAGE_TITLE = 'Notifikasi'; $ACTIVE = 'dashboard';
require __DIR__ . '/_header.php';
?>
<div class="page-head">
  <div><h1>Notifikasi</h1>
    <p><?= $hasil['jumlah'] ?> hal menunggu · <?= $hasil['penting'] ?> mendesak · dihitung dari keadaan data saat ini</p></div>
  <a class="btn btn-ghost" href="<?= e(beranda_peran()) ?>">← Kembali</a>
</div>

<?php if ($gagal): ?>
  <div class="flash flash-err"><?= e($gagal) ?></div>
<?php elseif (!$hasil['data']): ?>
  <div class="card">
    <div class="kosong-blok">
      <svg aria-hidden="true" style="width:36px;height:36px;color:var(--green)"><use href="#i-cek-bulat"></use></svg>
      <p><b>Tidak ada yang menunggu.</b><br>Semua dokumen sudah diputuskan, tidak ada batch kedaluwarsa, dan stok di atas batas minimum.</p>
    </div>
  </div>
<?php else: ?>
  <?php foreach ($kelompok as $tingkat => $daftar): if (!$daftar) { continue; } ?>
    <h3 class="notif-judul" style="--tc:<?= $warnaTingkat[$tingkat] ?>"><?= e($judulTingkat[$tingkat]) ?>
      <span class="tag" style="margin-left:6px"><?= count($daftar) ?></span></h3>
    <div class="notif-grid">
      <?php foreach ($daftar as $d): ?>
        <a class="card notif-kartu" href="<?= e(tautan_notifikasi($d['rute'])) ?>" style="--tc:<?= $warnaTingkat[$tingkat] ?>">
          <span class="notif-ikon"><svg aria-hidden="true"><use href="#<?= e($ikon[$d['tipe']] ?? 'i-bel') ?>"></use></svg></span>
          <span class="notif-isi">
            <b><?= e($d['judul']) ?></b>
            <span class="mut"><?= e($d['pesan']) ?></span>
          </span>
          <span class="notif-angka"><?= angka($d['jumlah']) ?></span>
        </a>
      <?php endforeach; ?>
    </div>
  <?php endforeach; ?>
<?php endif; ?>

<style>
.notif-judul{ font-size:13px; text-transform:uppercase; letter-spacing:.06em; color:var(--tc);
  margin:18px 0 10px; display:flex; align-items:center }
.notif-grid{ display:grid; grid-template-columns:repeat(auto-fill,minmax(300px,1fr)); gap:12px }
.notif-kartu{ display:flex; align-items:center; gap:14px; padding:14px 16px; text-decoration:none;
  color:inherit; border-left:4px solid var(--tc); transition:transform .15s, border-color .15s }
.notif-kartu:hover{ transform:translateY(-1px); border-color:var(--tc) }
.notif-ikon{ width:40px; height:40px; border-radius:12px; display:grid; place-items:center;
  background:color-mix(in srgb, var(--tc) 14%, transparent); color:var(--tc); flex:none }
.notif-ikon svg{ width:20px; height:20px; stroke:var(--tc); fill:none; stroke-width:1.85; stroke-linecap:round; stroke-linejoin:round }
.notif-isi{ display:flex; flex-direction:column; gap:3px; min-width:0; flex:1 }
.notif-isi .mut{ font-size:12.5px; line-height:1.5 }
.notif-angka{ font-size:22px; font-weight:800; color:var(--tc); flex:none }
</style>
<?php require __DIR__ . '/_footer.php'; ?>
