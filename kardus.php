<?php
/**
 * AMANAH — Folder Kardus
 *
 * Daftar kardus yang dibuat lewat transaksi Perpindahan: dicari (kode,
 * nama, isi barang, nomor perpindahan / surat jalan / resi), disaring per
 * gudang asal, status, kondisi, dan periode; dibuka rinciannya; dipindai
 * (kode KRD-… dari label); dicetak ulang labelnya (satu atau banyak).
 * Kardus TIDAK dibuat dari sini — hanya dari formulir Perpindahan.
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/_lib.php';
require_login();
tolak_supplier();
if (is_toko()) {
    http_response_code(403);
    die('<p style="font-family:sans-serif;padding:30px">403 — Folder kardus hanya untuk peran gudang dan pimpinan. Kardus kiriman untuk toko Anda terlihat di menu Penerimaan. <a href="' . beranda_peran() . '">Kembali</a></p>');
}
$PER_HAL = 25;
$aktif = kardus_aktif($pdo);

// Pindai / ketik kode → langsung ke detail.
$kodePindai = strtoupper(trim((string) ($_GET['k'] ?? '')));
if ($kodePindai !== '') {
    $kodePindai = preg_replace('~^.*[?&]K=~i', '', $kodePindai);
    $kid = $aktif ? kardus_cari_kode($pdo, $kodePindai) : null;
    if ($kid) { header('Location: kardus_detail.php?id=' . $kid); exit; }
    set_flash('err', "Kardus dengan kode \"$kodePindai\" tidak ditemukan.");
}

$gudangOpsi = [];
try {
    $where = "deleted_at IS NULL AND jenis <> 'supplier'";
    if (terikat_satu_gudang() && !is_super()) {
        $ids = array_map('intval', gudang_saya($pdo));
        $where .= $ids ? ' AND id IN (' . implode(',', $ids) . ')' : ' AND 0';
    }
    foreach ($pdo->query("SELECT id, nama FROM gudang WHERE $where ORDER BY nama")->fetchAll() as $g) { $gudangOpsi[(string) $g['id']] = $g['nama']; }
} catch (Throwable $e) { error_log('[AMANAH] kardus gudang: ' . $e->getMessage()); }

$statusOpsi = []; foreach (KARDUS_STATUS as $kd => $lb) { if ($kd !== 'draft' && $kd !== 'siap_dikirim') { $statusOpsi[$kd] = $lb[0]; } }
$kondisiOpsi = []; foreach (KARDUS_KONDISI as $kd => $lb) { $kondisiOpsi[$kd] = $lb[0]; }
$def = [
    ['nama' => 'q',       'jenis' => 'cari',    'label' => 'Kata kunci', 'placeholder' => 'Cari kode kardus, nama, barang, nomor perpindahan / surat jalan / resi…'],
    ['nama' => 'gudang',  'jenis' => 'pilih',   'label' => 'Gudang asal', 'opsi' => $gudangOpsi,  'kosong' => 'Semua gudang'],
    ['nama' => 'status',  'jenis' => 'pilih',   'label' => 'Status',  'opsi' => $statusOpsi,  'kosong' => 'Semua status'],
    ['nama' => 'kondisi', 'jenis' => 'pilih',   'label' => 'Kondisi', 'opsi' => $kondisiOpsi, 'kosong' => 'Semua kondisi'],
    ['nama' => 'periode', 'jenis' => 'periode', 'label' => 'Periode'],
];
$nilai = filter_baca($def);

$where = ['1=1']; $par = [];
if (terikat_satu_gudang() && !is_super()) {
    /* Lingkup: kardus dari gudang akun ini, atau yang kirimannya terlihat akun ini (tujuan / transit). */
    $where[] = '(' . ($gudangOpsi ? 'k.gudang_id IN (' . implode(',', array_map('intval', array_keys($gudangOpsi))) . ')' : '0') . ' OR ' . syarat_perpindahan($pdo, 'p') . ')';
}
if ($nilai['q'] !== '') {
    $like = '%' . like_escape($nilai['q']) . '%';
    $where[] = "(k.kode LIKE ? OR k.nama LIKE ? OR k.no_resi LIKE ? OR p.no_surat_jalan LIKE ? OR p.no_perpindahan LIKE ? OR p.no_resi LIKE ?
                 OR EXISTS (SELECT 1 FROM perpindahan_detail dq JOIN barang bq ON bq.id = dq.barang_id WHERE dq.kardus_id = k.id AND (bq.nama LIKE ? OR bq.kode LIKE ?)))";
    array_push($par, $like, $like, $like, $like, $like, $like, $like, $like);
}
if ($nilai['gudang']  !== '') { $where[] = 'k.gudang_id = ?'; $par[] = (int) $nilai['gudang']; }
if ($nilai['status']  !== '') { $where[] = 'k.status = ?';    $par[] = $nilai['status']; }
if ($nilai['kondisi'] !== '') { $where[] = 'k.kondisi = ?';   $par[] = $nilai['kondisi']; }
if ($nilai['periode'] !== '' && ($r = periode_rentang($nilai['periode']))) { $where[] = 'k.tanggal_kemas BETWEEN ? AND ?'; $par[] = $r[0]; $par[] = $r[1]; }
$wSql = implode(' AND ', $where);

$total = 0; $baris = []; $hal = 1; $jmlHal = 1; $isiPer = []; $hitungStatus = []; $urutPer = [];
if ($aktif) {
    try {
        $dari = "FROM kardus k LEFT JOIN perpindahan p ON p.id = k.perpindahan_id";
        $st = $pdo->prepare("SELECT COUNT(*) $dari WHERE $wSql"); $st->execute($par);
        $total = (int) $st->fetchColumn();
        [$hal, $offset, $jmlHal] = paginasi_hitung($total, $PER_HAL);
        $st = $pdo->prepare(
            "SELECT k.*, g.nama AS gudang_nama, u.nama AS petugas_nama,
                    p.no_surat_jalan, p.no_perpindahan, p.no_resi AS pp_resi, p.ekspedisi, p.status AS pp_status, p.jenis AS pp_jenis,
                    p.asal_tipe, p.asal_id, p.asal_teks, p.tujuan_tipe, p.tujuan_id, p.tujuan_teks, p.penerima, p.diterima_at AS pp_diterima_at
             $dari LEFT JOIN gudang g ON g.id = k.gudang_id LEFT JOIN users u ON u.id = k.petugas_id
             WHERE $wSql ORDER BY k.id DESC LIMIT $PER_HAL OFFSET $offset");
        $st->execute($par);
        $baris = $st->fetchAll();
        if ($baris) {
            $ids = implode(',', array_map(fn($b) => (int) $b['id'], $baris));
            $stI = $pdo->query("SELECT d.kardus_id, d.qty, b.nama AS nama_barang, b.satuan FROM perpindahan_detail d JOIN barang b ON b.id = d.barang_id WHERE d.kardus_id IN ($ids) ORDER BY d.id");
            foreach ($stI->fetchAll() as $r) { $isiPer[(int) $r['kardus_id']][] = $r; }
            // Nomor urut kardus dalam kirimannya ("2 dari 3").
            $pps = array_values(array_unique(array_filter(array_map(fn($b) => (int) $b['perpindahan_id'], $baris))));
            if ($pps) {
                $stU = $pdo->query("SELECT id, perpindahan_id FROM kardus WHERE perpindahan_id IN (" . implode(',', $pps) . ") AND status <> 'dibatalkan' ORDER BY perpindahan_id, id");
                $n = []; foreach ($stU->fetchAll() as $r) { $pp = (int) $r['perpindahan_id']; $n[$pp] = ($n[$pp] ?? 0) + 1; $urutPer[(int) $r['id']] = [$n[$pp], $pp]; }
                foreach ($urutPer as $kid => [$u, $pp]) { $urutPer[$kid] = [$u, $n[$pp]]; }
            }
        }
        $wLingkup = count($where) > 1 && strpos($where[1], '(k.gudang_id IN') === 0 ? $where[0] . ' AND ' . $where[1] : $where[0];
        foreach ($pdo->query("SELECT k.status, COUNT(*) n FROM kardus k LEFT JOIN perpindahan p ON p.id = k.perpindahan_id WHERE $wLingkup GROUP BY k.status")->fetchAll() as $r) { $hitungStatus[$r['status']] = (int) $r['n']; }
    } catch (Throwable $e) { error_log('[AMANAH] kardus daftar: ' . $e->getMessage()); }
}

$PAGE_TITLE = 'Folder Kardus'; $ACTIVE = 'kardus';
require __DIR__ . '/_header.php';
?>
<div class="page-head">
  <div><h1>Folder Kardus</h1>
    <p><?= angka($total) ?> kardus<?= filter_ada($nilai) ? ' cocok dengan penyaring' : '' ?> · dibuat lewat menu Perpindahan, dilacak di sini</p></div>
  <form method="get" class="tools" style="margin:0">
    <input name="k" autocomplete="off" placeholder="pindai / ketik kode KRD-…" style="font-family:'Courier New',monospace;min-width:220px">
    <button class="btn btn-ghost" type="submit"><svg class="ikon" aria-hidden="true"><use href="#i-qr"></use></svg> Buka</button>
  </form>
</div>

<?php if (!$aktif): ?>
  <div class="card"><div class="kosong-blok"><p><b>Fitur kardus belum aktif.</b> Jalankan migrasi basis data <code>db/migrasi_tim_gudang_03_kardus.sql</code> lewat <a href="migrasi.php">migrasi.php</a> (backup dulu).</p></div></div>
<?php require __DIR__ . '/_footer.php'; exit; endif; ?>

<div class="card">
  <div style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:12px">
    <?php foreach ($statusOpsi as $kd => $lb): [, $cls] = label_status_kardus($kd); $n = $hitungStatus[$kd] ?? 0; ?>
      <a class="tag <?= e($cls) ?>" href="<?= e(filter_url($nilai, [], 'status', $kd)) ?>" style="text-decoration:none"><?= e($lb) ?> <b><?= angka($n) ?></b></a>
    <?php endforeach; ?>
  </div>
  <?php filter_bar($def, $nilai, [], filter_ada($nilai) ? $total : null); ?>

  <?php if (!$baris): ?>
    <div class="kosong-blok"><p>Belum ada kardus<?= filter_ada($nilai) ? ' yang cocok dengan penyaring' : '' ?>. Kardus dibuat saat menyusun surat jalan di menu <a href="perpindahan.php">Perpindahan Barang</a> (pilih "Masuk kardus").</p></div>
  <?php else: ?>
  <form method="get" action="kardus_label.php" target="_blank" id="formLabel">
  <div class="tbl-wrap"><table class="tbl-kartu">
    <thead><tr>
      <th style="width:28px"><input type="checkbox" id="cekSemua" title="Pilih semua di halaman ini"></th>
      <th>Kardus</th><th>Perpindahan</th><th>Isi</th><th>Asal → Tujuan</th><th>Status</th><th>Lokasi terakhir</th><th></th>
    </tr></thead>
    <tbody>
    <?php foreach ($baris as $b):
        [$sLbl, $sCls] = label_status_kardus($b['status']); [$kLbl, $kCls] = label_kondisi_kardus($b['kondisi']);
        $isi = $isiPer[(int) $b['id']] ?? []; $ring = kardus_ringkas($isi);
        $asal   = $b['perpindahan_id'] ? nama_lokasi($pdo, $b['asal_tipe'], $b['asal_id'] ? (int) $b['asal_id'] : null, $b['asal_teks']) : ($b['gudang_nama'] ?: '—');
        $tujuan = $b['perpindahan_id'] ? nama_lokasi($pdo, $b['tujuan_tipe'], $b['tujuan_id'] ? (int) $b['tujuan_id'] : null, $b['tujuan_teks']) : '—';
        $urut = $urutPer[(int) $b['id']] ?? null; ?>
      <tr>
        <td><input type="checkbox" name="id[]" value="<?= (int) $b['id'] ?>" class="cekLabel"></td>
        <td data-label="Kardus"><a href="kardus_detail.php?id=<?= (int) $b['id'] ?>"><code><?= e($b['kode']) ?></code></a>
          <?php if ($urut): ?> <small class="mut2"><?= $urut[0] ?>/<?= $urut[1] ?></small><?php endif; ?>
          <?php if ($b['nama']): ?><br><b><?= e($b['nama']) ?></b><?php endif; ?>
          <br><small class="mut2"><?= e(tgl_id($b['tanggal_kemas'])) ?><?= $b['petugas_nama'] ? ' · ' . e($b['petugas_nama']) : '' ?></small></td>
        <td data-label="Perpindahan"><?php if ($b['perpindahan_id']): ?><a href="persetujuan.php?id=<?= (int) $b['perpindahan_id'] ?>"><?= e($b['no_perpindahan']) ?></a>
            <?= $b['no_surat_jalan'] ? '<br><small>SJ ' . e($b['no_surat_jalan']) . '</small>' : '' ?><?= ($b['no_resi'] ?: $b['pp_resi']) && ($b['no_resi'] ?: $b['pp_resi']) !== $b['no_perpindahan'] ? '<br><small class="mut2">resi ' . e($b['no_resi'] ?: $b['pp_resi']) . '</small>' : '' ?>
          <?php else: ?><span class="mut2">—</span><?php endif; ?></td>
        <td data-label="Isi"><?php if ($isi): ?><b><?= $ring['jenis'] ?> jenis</b> · <?= e(kardus_teks_satuan($ring['per_satuan'])) ?><br><small class="mut2"><?= e(kardus_teks_isi($isi, 3)) ?></small><?php else: ?><span class="mut2">kosong</span><?php endif; ?></td>
        <td data-label="Asal → Tujuan"><?= e($asal) ?><br><small class="mut2">→ <?= e($tujuan) ?></small></td>
        <td data-label="Status"><span class="tag <?= e($sCls) ?>"><?= e($sLbl) ?></span><?php if ($b['kondisi'] !== 'baik'): ?> <span class="tag <?= e($kCls) ?>"><?= e($kLbl) ?></span><?php endif; ?></td>
        <td data-label="Lokasi terakhir"><small>📍 <?= e(kardus_posisi($pdo, $b)) ?></small></td>
        <td class="nowrap">
          <a class="btn btn-ghost btn-sm" href="kardus_detail.php?id=<?= (int) $b['id'] ?>">Detail</a>
          <?php if ($b['status'] !== 'dibatalkan'): ?><a class="btn btn-ghost btn-sm" href="kardus_label.php?id=<?= (int) $b['id'] ?>" target="_blank" rel="noopener" title="Cetak label"><svg class="ikon" aria-hidden="true"><use href="#i-cetak"></use></svg></a><?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
  <div class="tools" style="margin-top:10px">
    <button class="btn btn-ghost btn-sm" type="submit" id="btnCetakTerpilih" disabled><svg class="ikon" aria-hidden="true"><use href="#i-cetak"></use></svg> Cetak label yang dicentang</button>
    <select name="ukuran" aria-label="Ukuran label"><option value="100x150">Label 100×150 mm</option><option value="a6">A6</option><option value="a4">A4 (beberapa label per lembar)</option></select>
  </div>
  </form>
  <?php paginasi_nav($hal, $jmlHal, $total, $PER_HAL, $nilai); ?>
  <?php endif; ?>
</div>
<script>
(function(){
  var semua = document.getElementById('cekSemua'), tombol = document.getElementById('btnCetakTerpilih');
  if (!semua) return;
  function segarkan(){ tombol.disabled = !document.querySelector('.cekLabel:checked'); }
  semua.addEventListener('change', function(){ document.querySelectorAll('.cekLabel').forEach(function(c){ c.checked = semua.checked; }); segarkan(); });
  document.querySelectorAll('.cekLabel').forEach(function(c){ c.addEventListener('change', segarkan); });
})();
</script>
<?php require __DIR__ . '/_footer.php'; ?>
