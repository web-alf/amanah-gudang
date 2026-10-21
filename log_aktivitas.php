<?php
/**
 * AMANAH — Jejak Aktivitas (audit log)
 *
 * Seluruh isi tabel activity_log, bisa dicari dan disaring: per modul, per
 * jenis aksi, per pengguna, per periode, dan kata kunci pada deskripsinya.
 *
 * Sampai 13 Sep 2026 jejak ini hanya terlihat 7 baris di dasbor dan 15
 * baris di profil masing-masing. Padahal argumen utama sistem ini —
 * "barang titipan, setiap angka harus bisa dijelaskan" — bertumpu pada
 * kemampuan menjawab "siapa mengubah apa, kapan" untuk kejadian berbulan-
 * bulan lalu, bukan hanya tujuh yang terakhir.
 *
 * Hanya Super Admin dan Pimpinan: jejak memuat tindakan semua orang.
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/_lib.php';
require_role(['super_admin', 'pimpinan']);

$PER_HAL = 50;

/* Pilihan penyaring dibangun dari isi tabel — modul dan aksi yang pernah
   tercatat — bukan daftar tetap yang harus ikut diubah tiap ada modul baru. */
$modulList = $pengguna = $aksiList = [];
try {
    foreach ($pdo->query("SELECT DISTINCT modul FROM activity_log ORDER BY modul")->fetchAll(PDO::FETCH_COLUMN) as $m) {
        $modulList[$m] = ucfirst(str_replace('_', ' ', $m));
    }
    foreach ($pdo->query("SELECT DISTINCT aksi FROM activity_log ORDER BY aksi")->fetchAll(PDO::FETCH_COLUMN) as $a) {
        $aksiList[$a] = ucfirst($a);
    }
    foreach ($pdo->query("SELECT id, nama FROM users WHERE deleted_at IS NULL ORDER BY nama")->fetchAll() as $u) {
        $pengguna[(string) $u['id']] = $u['nama'];
    }
} catch (Throwable $e) { error_log('[AMANAH] log filter: ' . $e->getMessage()); }

$def = [
    ['nama' => 'q',       'jenis' => 'cari',    'label' => 'Kata kunci', 'placeholder' => 'Cari deskripsi…'],
    ['nama' => 'modul',   'jenis' => 'pilih',   'label' => 'Modul',     'opsi' => $modulList, 'kosong' => 'Semua modul'],
    ['nama' => 'aksi',    'jenis' => 'pilih',   'label' => 'Aksi',      'opsi' => $aksiList,  'kosong' => 'Semua aksi'],
    ['nama' => 'user',    'jenis' => 'pilih',   'label' => 'Pengguna',  'opsi' => $pengguna,  'kosong' => 'Semua pengguna'],
    ['nama' => 'periode', 'jenis' => 'periode', 'label' => 'Periode'],
];
$nilai = filter_baca($def);

$where = ['1=1']; $par = [];
if ($nilai['q'] !== '')     { $where[] = 'a.deskripsi LIKE ?'; $par[] = '%' . like_escape($nilai['q']) . '%'; }
if ($nilai['modul'] !== '') { $where[] = 'a.modul = ?';       $par[] = $nilai['modul']; }
if ($nilai['aksi'] !== '')  { $where[] = 'a.aksi = ?';        $par[] = $nilai['aksi']; }
if ($nilai['user'] !== '')  { $where[] = 'a.user_id = ?';     $par[] = (int) $nilai['user']; }
if ($nilai['periode'] !== '' && ($r = periode_rentang($nilai['periode']))) {
    $where[] = 'a.created_at >= ? AND a.created_at < DATE_ADD(?, INTERVAL 1 DAY)';
    $par[] = $r[0]; $par[] = $r[1];
}
$wSql = implode(' AND ', $where);

$hal = max(1, (int) ($_GET['hal'] ?? 1));
$total = 0; $rows = [];
try {
    $st = $pdo->prepare("SELECT COUNT(*) FROM activity_log a WHERE $wSql");
    $st->execute($par);
    $total = (int) $st->fetchColumn();
    $jmlHal = max(1, (int) ceil($total / $PER_HAL));
    if ($hal > $jmlHal) { $hal = $jmlHal; }
    $offset = ($hal - 1) * $PER_HAL;

    $st = $pdo->prepare(
        "SELECT a.id, a.aksi, a.modul, a.referensi_id, a.deskripsi, a.ip_address, a.created_at,
                u.nama AS petugas
         FROM activity_log a LEFT JOIN users u ON u.id = a.user_id
         WHERE $wSql ORDER BY a.id DESC LIMIT $PER_HAL OFFSET $offset");
    $st->execute($par);
    $rows = $st->fetchAll();
} catch (Throwable $e) {
    error_log('[AMANAH] log aktivitas: ' . $e->getMessage());
}
$jmlHal = max(1, (int) ceil($total / $PER_HAL));

/** URL halaman ke-N dengan penyaring yang sedang aktif. */
function url_hal(array $nilai, int $n): string {
    $par = array_filter($nilai, fn($v) => $v !== '');
    $par['hal'] = $n;
    return 'log_aktivitas.php?' . http_build_query($par);
}

$PAGE_TITLE = 'Jejak Aktivitas'; $ACTIVE = 'pengaturan';
require __DIR__ . '/_header.php';
?>
<div class="page-head">
  <div><h1>Jejak Aktivitas</h1>
    <p><?= angka($total) ?> catatan · siapa mengubah apa, kapan</p></div>
  <a class="btn btn-ghost" href="<?= is_super() ? 'pengaturan.php' : 'dashboard.php' ?>">← Kembali</a>
</div>

<div class="card">
  <?php filter_bar($def, $nilai, [], filter_ada($nilai) ? $total : null); ?>

  <div class="tbl-wrap"><table class="tbl-kartu">
    <thead><tr><th>Waktu</th><th>Pengguna</th><th>Aksi</th><th>Modul</th><th>Keterangan</th><th>IP</th></tr></thead>
    <tbody>
    <?php if (!$rows): ?>
      <tr><td colspan="6" class="empty">Tidak ada catatan yang cocok.</td></tr>
    <?php endif; ?>
    <?php foreach ($rows as $a): [$ik, $wrn] = gaya_aktivitas($a['aksi']); ?>
      <tr>
        <td class="sekunder nowrap" data-label="Waktu"><?= e(tgl_jam_id($a['created_at'])) ?></td>
        <td data-label="Pengguna"><?= e($a['petugas'] ?: 'Sistem') ?></td>
        <td data-label="Aksi"><span class="tag" style="color:<?= $wrn ?>;border-color:currentColor"><?= e($a['aksi']) ?></span></td>
        <td class="sekunder" data-label="Modul"><?= e($a['modul']) ?><?= $a['referensi_id'] ? ' <small class="mut2">#' . (int) $a['referensi_id'] . '</small>' : '' ?></td>
        <td data-label="Keterangan"><?= e($a['deskripsi'] ?: '—') ?></td>
        <td class="sekunder" data-label="IP"><small><?= e($a['ip_address'] ?: '—') ?></small></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>

  <?php if ($jmlHal > 1): ?>
    <div class="paginasi">
      <span class="mut">Halaman <?= $hal ?> dari <?= $jmlHal ?></span>
      <span>
        <?php if ($hal > 1): ?><a class="btn btn-ghost btn-sm" href="<?= e(url_hal($nilai, $hal - 1)) ?>">← Sebelumnya</a><?php endif; ?>
        <?php if ($hal < $jmlHal): ?><a class="btn btn-ghost btn-sm" href="<?= e(url_hal($nilai, $hal + 1)) ?>">Berikutnya →</a><?php endif; ?>
      </span>
    </div>
  <?php endif; ?>
</div>
<style>.paginasi{display:flex;justify-content:space-between;align-items:center;gap:10px;margin-top:12px;flex-wrap:wrap}</style>
<?php require __DIR__ . '/_footer.php'; ?>
