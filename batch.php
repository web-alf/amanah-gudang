<?php
/**
 * AMANAH — Daftar Batch
 * =============================================================================
 * Halaman Batch & Expired menjawab "mana yang bermasalah": yang mendekati
 * kedaluwarsa, yang tanggalnya belum diisi, yang tanggalnya mustahil. Ia tidak
 * pernah memuat batch yang baik-baik saja — dan itu memang bukan tugasnya.
 *
 * Tapi akibatnya satu batch yang perlu dibetulkan TANPA sedang bermasalah —
 * nomor batchnya tertukar, kondisinya salah pilih, tanggalnya meleset setahun
 * tapi masih jauh — tidak punya tempat untuk dicari. Satu-satunya jalan lewat
 * Data Barang → buka produknya → cari batchnya di daftar bawah, dan itu hanya
 * berhasil kalau orang sudah tahu produknya yang mana.
 *
 * Halaman ini daftarnya: seluruh batch dalam lingkup, bisa dicari dan disaring,
 * tiap baris bertombol Perbaiki.
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/_lib.php';
require_login();
tolak_supplier();

$L       = lingkup_stok($pdo);
$syarat  = syarat_lingkup($pdo, 'bb');
$bolehUbah = boleh_edit();

/* --- penyaring keadaan. Namanya menggambarkan pertanyaan yang diajukan
       orang, bukan nama kolom: "mana yang belum bertanggal" lebih sering
       ditanya daripada "mana yang tanggal_expired IS NULL". */
$SARING = [
    ''            => ['Semua batch',            '1=1'],
    'ada'         => ['Masih bersisa',          'bb.qty_tersisa > 0'],
    'habis'       => ['Sudah habis',            'bb.qty_tersisa <= 0'],
    'tanpa_tgl'   => ['Belum bertanggal',       'bb.tanggal_expired IS NULL'],
    'kedaluwarsa' => ['Sudah kedaluwarsa',      "bb.tanggal_expired IS NOT NULL AND bb.tanggal_expired < CURDATE()"],
    'aneh'        => ['Tanggal tidak masuk akal', "bb.tanggal_expired IS NOT NULL AND bb.tanggal_expired < '" . EXP_PALING_TUA . "'"],
];
$gSaring = (string) ($_GET['saring'] ?? '');
if (!isset($SARING[$gSaring])) { $gSaring = ''; }
$syaratSaring = $SARING[$gSaring][1];

$q = trim((string) ($_GET['q'] ?? ''));
$syaratCari = syarat_cari($pdo, $q, ['b.nama', 'b.kode', 'bb.nomor_batch']);

$dasar = "FROM batch_barang bb
          JOIN barang b           ON b.id  = bb.barang_id AND b.deleted_at IS NULL
          LEFT JOIN lokasi_rak lr ON lr.id = bb.lokasi_rak_id
          LEFT JOIN gudang g      ON g.id  = lr.gudang_id
          LEFT JOIN toko tk       ON tk.id = bb.toko_id
          WHERE $syarat AND $syaratSaring AND $syaratCari";

$perHal     = 30;
$totalBaris = (int) $pdo->query("SELECT COUNT(*) $dasar")->fetchColumn();
[$hal, $offset, $jmlHal] = paginasi_hitung($totalBaris, $perHal);

$list = $pdo->query(
    "SELECT bb.id, bb.nomor_batch, bb.tanggal_masuk, bb.tanggal_expired,
            bb.kondisi, bb.qty_awal, bb.qty_tersisa,
            b.kode AS kode_barang, b.nama AS nama_barang, b.satuan,
            COALESCE(lr.nama, tk.nama, '—') AS lokasi_utama,
            g.nama AS lokasi_induk,
            bb.toko_id
     $dasar
     ORDER BY bb.tanggal_expired IS NULL, bb.tanggal_expired, b.nama
     LIMIT $perHal OFFSET $offset")->fetchAll();

/* Angka ringkas per keadaan — dipakai pil penyaring supaya jumlahnya
   terlihat sebelum diklik. Dihitung dengan syarat yang sama persis. */
$hitungSaring = [];
foreach ($SARING as $kunci => [$label, $kondisi]) {
    $hitungSaring[$kunci] = (int) $pdo->query(
        "SELECT COUNT(*) FROM batch_barang bb
         JOIN barang b ON b.id = bb.barang_id AND b.deleted_at IS NULL
         WHERE $syarat AND $kondisi")->fetchColumn();
}

$PAGE_TITLE = 'Daftar Batch'; $ACTIVE = 'monitoring';
require __DIR__ . '/_header.php';
?>
<div class="page-head">
  <div>
    <h1>Daftar Batch</h1>
    <p>Seluruh batch dalam lingkup Anda · <?= angka($totalBaris) ?> baris</p>
  </div>
  <a class="btn btn-ghost" href="monitoring.php">
    <svg aria-hidden="true"><use href="#i-cal"></use></svg> Batch &amp; Expired</a>
</div>

<div class="card">
  <div class="saring-pil" style="margin-bottom:14px;flex-wrap:wrap">
    <?php foreach ($SARING as $kunci => [$label, $_k]):
      $aktif = $kunci === $gSaring;
      $par   = array_filter(['saring' => $kunci, 'q' => $q]); ?>
      <a class="pil<?= $aktif ? ' aktif' : '' ?>" href="?<?= e(http_build_query($par)) ?>"
         <?= $aktif ? 'aria-current="true"' : '' ?>>
        <?= e($label) ?> <b><?= angka($hitungSaring[$kunci]) ?></b></a>
    <?php endforeach; ?>
  </div>

  <?php cari_bar($q, 'Cari nomor batch, nama, atau kode barang…',
                 $q !== '' ? $totalBaris : null, ['saring' => $gSaring]); ?>

  <div class="tbl-wrap"><table class="tbl-kartu">
    <thead><tr>
      <th>Barang</th><th>Batch</th><th>Lokasi</th><th>Kondisi</th>
      <th>Kedaluwarsa</th><th>Masuk</th><th class="num">Sisa</th>
      <?php if ($bolehUbah): ?><th></th><?php endif; ?>
    </tr></thead>
    <tbody>
    <?php if (!$list): ?>
      <tr><td colspan="<?= $bolehUbah ? 8 : 7 ?>" class="empty">
        <?= $q !== '' ? 'Tidak ada batch yang cocok dengan pencarian itu.'
                      : 'Belum ada batch pada saringan ini.' ?></td></tr>
    <?php else: foreach ($list as $b):
      [$lblK, $wrnK] = label_kondisi($b['kondisi'] ?? 'baru');
      /* Tiga keadaan tanggal, tiga tampilan berbeda — dan yang mustahil
         tidak boleh dihitung sisa harinya sama sekali: "lewat 739.886 hari"
         bukan keterangan, melainkan kebisingan. */
      $tgl   = $b['tanggal_expired'];
      $aneh  = $tgl !== null && $tgl < EXP_PALING_TUA;
      $sisa  = ($tgl !== null && !$aneh)
             ? (int) floor((strtotime($tgl) - strtotime(date('Y-m-d'))) / 86400) : null;
    ?>
      <tr>
        <td class="judul" data-label="Barang"><b><?= e($b['nama_barang']) ?></b><br>
          <small class="mut2"><?= e($b['kode_barang']) ?></small></td>
        <td class="sekunder" data-label="Batch"><code><?= e($b['nomor_batch'] ?: '—') ?></code></td>
        <td class="sekunder" data-label="Lokasi">
          <?= $b['toko_id'] ? '🏪' : '🏭' ?>
          <b><?= e($b['lokasi_utama']) ?></b>
          <?php if (!empty($b['lokasi_induk'])): ?>
            <br><small class="mut2"><?= e($b['lokasi_induk']) ?></small>
          <?php endif; ?>
        </td>
        <td class="sekunder" data-label="Kondisi"><span class="tag <?= e($wrnK) ?>"><?= e($lblK) ?></span></td>
        <td data-label="Kedaluwarsa">
          <?php if ($tgl === null): ?>
            <small class="mut">belum diisi</small>
          <?php elseif ($aneh): ?>
            <span class="tag t-red"><?= e($tgl) ?></span>
          <?php else: ?>
            <?= e(tgl_id($tgl)) ?><br>
            <small class="<?= $sisa < 0 ? 't-red' : 'mut' ?>">
              <?= $sisa < 0 ? 'lewat ' . abs($sisa) . ' hari' : $sisa . ' hari lagi' ?></small>
          <?php endif; ?>
        </td>
        <td class="sekunder" data-label="Masuk"><?= e(tgl_id($b['tanggal_masuk'])) ?></td>
        <td class="num" data-label="Sisa">
          <b><?= angka($b['qty_tersisa']) ?></b> <small><?= e($b['satuan']) ?></small><br>
          <small class="mut2">dari <?= angka($b['qty_awal']) ?></small></td>
        <?php if ($bolehUbah): ?>
        <td class="aksi">
          <a class="btn <?= $aneh ? 'btn-red' : 'btn-ghost' ?> btn-sm"
             href="batch_edit.php?id=<?= (int) $b['id'] ?>">Perbaiki</a>
        </td>
        <?php endif; ?>
      </tr>
    <?php endforeach; endif; ?>
    </tbody>
  </table></div>

  <?php paginasi_nav($hal, $jmlHal, $totalBaris, $perHal, ['q' => $q, 'saring' => $gSaring]); ?>
</div>

<?php require __DIR__ . '/_footer.php'; ?>
