<?php
/**
 * AMANAH — Stock Opname (hitung fisik per rak)
 *
 * Opname dikerjakan sambil berdiri di depan rak: pilih rak, hitung isi
 * tiap batch, tulis jumlah fisiknya. Selisihnya menjadi penyesuaian stok
 * lewat sesuaikan_batch() — fungsi yang SAMA dengan api/opname.php dan
 * halaman Sesuaikan Stok per produk, jadi tiga jalur ini tidak bisa
 * menghasilkan penyesuaian yang berbeda bentuknya.
 *
 * Sampai 13 Sep 2026 halaman ini tidak ada di web: tabel dan API-nya sudah
 * dipakai aplikasi Android, sementara di peramban hanya ada koreksi per
 * produk (barang_stok.php). Opname per rak adalah cara kerja yang benar
 * untuk menghitung fisik — orang menghitung apa yang ada di depannya, bukan
 * mencari satu produk di seluruh gudang.
 *
 * Barang yang hilang dari hitungan adalah titipan orang. Karena itu setiap
 * selisih WAJIB beralasan; sesuaikan_batch() yang menegakkannya.
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/_lib.php';
require_once __DIR__ . '/_penyesuaian_ubah.php';
require_login();
tolak_supplier();
if (!in_array(role_saat_ini(), ['super_admin', 'admin_gudang', 'admin_terpadu'], true)) {
    http_response_code(403);
    die('<p style="font-family:sans-serif;padding:30px">403 — Stock opname adalah pekerjaan sisi gudang. '
      . '<a href="' . e(beranda_peran()) . '">Kembali</a></p>');
}

$rakBoleh = rak_saya($pdo);                       // [] = tidak ada rak dalam lingkup
$inRak    = $rakBoleh ? implode(',', array_map('intval', $rakBoleh)) : '0';
$rakId    = (int) ($_GET['rak'] ?? $_POST['rak_id'] ?? 0);
$errors   = [];

if ($rakId > 0 && !in_array($rakId, $rakBoleh, true)) {
    http_response_code(403);
    die('<p style="font-family:sans-serif;padding:30px">403 — Rak itu bukan bagian dari gudang Anda. '
      . '<a href="opname.php">Kembali</a></p>');
}

// ---------------------------------------------------------------- SIMPAN
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    wajib_csrf($_POST['csrf'] ?? null);
    $catatan = trim((string) ($_POST['catatan'] ?? ''));
    $qtyIn   = $_POST['qty_fisik'] ?? [];
    $alasanIn = $_POST['alasan_baris'] ?? [];

    $hitung = [];
    foreach ((array) $qtyIn as $bid => $q) {
        $bid = (int) $bid;
        if ($bid <= 0 || $q === '' || $q === null) { continue; }   // baris kosong = dilewati, bukan nol
        $hitung[$bid] = ['qty' => max(0, (int) $q), 'alasan' => trim((string) ($alasanIn[$bid] ?? ''))];
    }

    if ($rakId <= 0) {
        $errors[] = 'Rak belum dipilih.';
    } elseif (!$hitung) {
        $errors[] = 'Tidak ada baris yang diisi. Isi jumlah fisik minimal satu batch.';
    } else {
        try {
            $pdo->beginTransaction();
            $in  = implode(',', array_keys($hitung));
            $stB = $pdo->query(
                "SELECT bb.id, bb.barang_id, bb.qty_tersisa, bb.nomor_batch, b.nama AS barang
                 FROM batch_barang bb JOIN barang b ON b.id = bb.barang_id
                 WHERE bb.id IN ($in) AND bb.lokasi_rak_id = $rakId FOR UPDATE");
            $batch = $stB->fetchAll();
            if (!$batch) { throw new RuntimeException('Batch yang dihitung tidak ada di rak itu.'); }

            $selisih = 0; $cocok = 0; $disentuh = [];
            foreach ($batch as $bb) {
                $bid  = (int) $bb['id'];
                $beda = sesuaikan_batch($pdo, $bid, $hitung[$bid]['qty'], $hitung[$bid]['alasan'],
                                        (int) $_SESSION['user_id'], $catatan);
                if ($beda === 0) { $cocok++; continue; }
                $selisih++;
                $disentuh[(int) $bb['barang_id']] = true;
            }
            foreach (array_keys($disentuh) as $bid) { recalc_stok($pdo, (int) $bid); }
            $pdo->commit();

            catat_aktivitas($pdo, 'create', 'penyesuaian_stok', $rakId,
                "Opname rak #$rakId: $selisih selisih, $cocok cocok");
            set_flash('ok', $selisih === 0
                ? "Opname selesai — seluruh $cocok batch cocok dengan catatan sistem."
                : "Opname selesai — $selisih batch disesuaikan, $cocok batch cocok.");
            header('Location: opname.php?rak=' . $rakId); exit;
        } catch (RuntimeException $e) {
            if ($pdo->inTransaction()) { $pdo->rollBack(); }
            $errors[] = pesan_runtime($e, 'opname');
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) { $pdo->rollBack(); }
            $errors[] = pesan_error_aman($e, 'opname');
        }
    }
}

// ------------------------------------------------------------------ DATA
$rakList = $pdo->query(
    "SELECT lr.id, lr.kode, lr.nama, lr.kapasitas, g.nama AS gudang_nama,
            COALESCE(x.jml_batch,0) AS jml_batch, COALESCE(x.qty,0) AS total_qty,
            (SELECT MAX(ps.tanggal) FROM penyesuaian_stok ps WHERE ps.lokasi_rak_id = lr.id) AS opname_terakhir
     FROM lokasi_rak lr
     LEFT JOIN gudang g ON g.id = lr.gudang_id
     LEFT JOIN (SELECT lokasi_rak_id, COUNT(*) jml_batch, SUM(qty_tersisa) qty
                FROM batch_barang WHERE qty_tersisa > 0 GROUP BY lokasi_rak_id) x ON x.lokasi_rak_id = lr.id
     WHERE lr.deleted_at IS NULL AND lr.id IN ($inRak)
     ORDER BY g.kode, lr.kode")->fetchAll();

$rak = null; $isi = [];
if ($rakId > 0) {
    foreach ($rakList as $r) { if ((int) $r['id'] === $rakId) { $rak = $r; break; } }
    if ($rak) {
        $st = $pdo->prepare(
            "SELECT bb.id AS batch_id, bb.nomor_batch, bb.qty_tersisa, bb.kondisi, bb.tanggal_expired,
                    DATEDIFF(bb.tanggal_expired, CURDATE()) AS sisa_hari,
                    b.id AS barang_id, b.nama AS barang, b.kode AS kode_barang, b.satuan,
                    d.nama AS donatur_nama, d.anonim AS donatur_anonim, d.wilayah AS donatur_wilayah
             FROM batch_barang bb
             JOIN barang b       ON b.id = bb.barang_id
             LEFT JOIN donatur d ON d.id = bb.donatur_id
             WHERE bb.lokasi_rak_id = ? AND bb.qty_tersisa > 0
             ORDER BY b.nama, (bb.tanggal_expired IS NULL), bb.tanggal_expired");
        $st->execute([$rakId]);
        $isi = $st->fetchAll();
    }
}

$riwayat = [];
try {
    $riwayat = $pdo->query(
        "SELECT ps.id, ps.no_penyesuaian, ps.tanggal, ps.jenis, ps.qty, ps.alasan,
                ps.lokasi_tipe, ps.lokasi_rak_id, bb.qty_tersisa AS stok_batch,
                b.nama AS barang, bb.nomor_batch, lr.kode AS rak_kode, u.nama AS petugas
         FROM penyesuaian_stok ps
         JOIN barang b             ON b.id  = ps.barang_id
         LEFT JOIN batch_barang bb ON bb.id = ps.batch_id
         LEFT JOIN lokasi_rak lr   ON lr.id = ps.lokasi_rak_id
         LEFT JOIN users u         ON u.id  = ps.user_id
         WHERE ps.lokasi_tipe = 'gudang' AND ps.lokasi_rak_id IN ($inRak)"
        . ($rakId > 0 ? " AND ps.lokasi_rak_id = $rakId" : '') . "
         ORDER BY ps.id DESC LIMIT 40")->fetchAll();
} catch (Throwable $e) { error_log('[AMANAH] opname riwayat: ' . $e->getMessage()); }

$PAGE_TITLE = 'Stock Opname'; $ACTIVE = 'opname';
require __DIR__ . '/_header.php';
?>
<div class="page-head">
  <div><h1>Stock Opname</h1>
    <p>Hitung fisik per rak · selisih menjadi penyesuaian stok beralasan</p></div>
  <?php if ($rak): ?><a class="btn btn-ghost" href="opname.php">← Semua rak</a><?php endif; ?>
</div>

<?php if ($errors): ?>
  <div class="flash flash-err"><?php foreach ($errors as $x) echo '• ' . e($x) . '<br>'; ?></div>
<?php endif; ?>

<?php if (!$rak): ?>
  <?php if (!$rakList): ?>
    <div class="card"><div class="kosong-blok"><p>Tidak ada rak dalam lingkup akun Anda.</p></div></div>
  <?php else: ?>
  <div class="opname-grid">
    <?php foreach ($rakList as $r): ?>
      <a class="card opname-rak" href="opname.php?rak=<?= (int) $r['id'] ?>">
        <div class="opname-kode"><code><?= e($r['kode']) ?></code> <small class="mut"><?= e($r['gudang_nama'] ?: '—') ?></small></div>
        <b><?= e($r['nama']) ?></b>
        <div class="opname-angka">
          <span><b><?= angka($r['total_qty']) ?></b> unit</span>
          <span><b><?= (int) $r['jml_batch'] ?></b> batch</span>
        </div>
        <small class="mut2">Opname terakhir: <?= $r['opname_terakhir'] ? e(tgl_id($r['opname_terakhir'])) : 'belum pernah' ?></small>
      </a>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>
<?php else: ?>
  <div class="card">
    <div class="kepala-rak">
      <div>
        <h3 style="margin:0"><?= e($rak['nama']) ?> <code style="font-size:13px"><?= e($rak['kode']) ?></code></h3>
        <p class="mut" style="margin:4px 0 0"><?= e($rak['gudang_nama'] ?: '—') ?> ·
          <?= (int) $rak['jml_batch'] ?> batch · <?= angka($rak['total_qty']) ?> unit tercatat<?= $rak['kapasitas'] ? ' · kapasitas ' . angka($rak['kapasitas']) : '' ?></p>
      </div>
    </div>
  </div>

  <?php if (!$isi): ?>
    <div class="card"><div class="kosong-blok"><p>Rak ini kosong menurut sistem. Bila fisiknya ada barang, catat lewat
      <a href="barang.php">Sesuaikan Stok</a> pada produknya.</p></div></div>
  <?php else: ?>
  <form method="post" class="card" id="frmOpname">
    <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
    <input type="hidden" name="rak_id" value="<?= (int) $rakId ?>">
    <p class="mut">Isi jumlah hasil hitung fisik. Baris yang dikosongkan dilewati — bukan dianggap nol.
      Setiap selisih wajib diberi alasan. Tombol <b>Sama</b> menyalin angka tercatat ke kolom fisik.</p>

    <div class="tbl-wrap"><table class="tbl-kartu">
      <thead><tr>
        <th>Barang</th><th>Batch</th><th>Kondisi</th><th>Kedaluwarsa</th>
        <th>Tercatat</th><th>Fisik</th><th>Alasan bila berselisih</th>
      </tr></thead>
      <tbody>
      <?php foreach ($isi as $b): [$lk, $wk] = label_kondisi($b['kondisi'] ?? 'baru'); ?>
        <tr>
          <td class="judul" data-label="Barang"><b><?= e($b['barang']) ?></b><br><small class="mut"><?= e($b['kode_barang']) ?></small></td>
          <td data-label="Batch"><code><?= e($b['nomor_batch']) ?></code>
            <?php if ($b['donatur_nama']): ?><br><small class="mut2"><?= e(nama_donatur_tampil(['nama' => $b['donatur_nama'], 'anonim' => $b['donatur_anonim'], 'wilayah' => $b['donatur_wilayah']])) ?></small><?php endif; ?></td>
          <td class="sekunder" data-label="Kondisi"><span class="tag <?= e($wk) ?>"><?= e($lk) ?></span></td>
          <td class="sekunder" data-label="Kedaluwarsa">
            <?php if ($b['tanggal_expired']): ?><?= tgl_id($b['tanggal_expired']) ?>
              <br><small class="mut"><?= (int) $b['sisa_hari'] < 0 ? 'lewat ' . abs((int) $b['sisa_hari']) . ' hari' : (int) $b['sisa_hari'] . ' hari lagi' ?></small>
            <?php else: ?>—<?php endif; ?>
          </td>
          <td data-label="Tercatat"><b class="tercatat" data-n="<?= (int) $b['qty_tersisa'] ?>"><?= angka($b['qty_tersisa']) ?></b> <small class="mut"><?= e($b['satuan']) ?></small></td>
          <td data-label="Fisik">
            <div style="display:flex;gap:6px;align-items:center">
              <input type="number" min="0" inputmode="numeric" class="inp-fisik" style="width:92px"
                     name="qty_fisik[<?= (int) $b['batch_id'] ?>]" placeholder="<?= (int) $b['qty_tersisa'] ?>">
              <button type="button" class="btn btn-ghost btn-sm" onclick="samakan(this)">Sama</button>
            </div>
          </td>
          <td data-label="Alasan"><input type="text" style="width:100%;min-width:180px"
                 name="alasan_baris[<?= (int) $b['batch_id'] ?>]" placeholder="hanya bila berbeda"></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table></div>

    <label style="display:block;margin-top:14px">Catatan untuk seluruh opname ini
      <input type="text" name="catatan" placeholder="opsional, mis. opname bulanan September">
    </label>
    <div style="margin-top:14px;display:flex;gap:8px;flex-wrap:wrap">
      <button class="btn btn-primary" type="submit" id="btnSimpan"><svg aria-hidden="true"><use href="#i-simpan"></use></svg> Simpan Hasil Opname</button>
      <button class="btn btn-ghost" type="button" onclick="samakanSemua()">Semua sama dengan tercatat</button>
    </div>
  </form>
  <?php endif; ?>
<?php endif; ?>

<?php if ($riwayat):
    /* Kolom Ubah hanya digambar bila akun ini memang boleh mengubah —
       kolom kosong di ujung tabel hanya membuat orang mencari tombolnya. */
    $adaUbah = boleh_kelola_master($pdo); ?>
<div class="card">
  <h3>Riwayat Penyesuaian<?= $rak ? ' di rak ini' : '' ?></h3>
  <div class="tbl-wrap"><table class="tbl-kartu">
    <thead><tr><th>Nomor</th><th>Tanggal</th><th>Barang</th><th>Batch</th><th>Rak</th><th>Jenis</th><th>Jumlah</th><th>Alasan</th><th>Petugas</th><?= $adaUbah ? '<th></th>' : '' ?></tr></thead>
    <tbody>
    <?php foreach ($riwayat as $r): $naik = strpos($r['jenis'], 'tambah') !== false; ?>
      <tr>
        <td data-label="Nomor"><code><?= e($r['no_penyesuaian']) ?></code></td>
        <td class="sekunder" data-label="Tanggal"><?= tgl_id($r['tanggal']) ?></td>
        <td data-label="Barang"><?= e($r['barang']) ?></td>
        <td class="sekunder" data-label="Batch"><?= e($r['nomor_batch'] ?: '—') ?></td>
        <td class="sekunder" data-label="Rak"><?= e($r['rak_kode'] ?: '—') ?></td>
        <td data-label="Jenis"><span class="tag <?= $naik ? 't-green' : 't-red' ?>"><?= e(str_replace('_', ' ', $r['jenis'])) ?></span></td>
        <td data-label="Jumlah"><b><?= $naik ? '+' : '−' ?><?= angka($r['qty']) ?></b></td>
        <td class="sekunder" data-label="Alasan"><?= e($r['alasan'] ?: '—') ?></td>
        <td class="sekunder" data-label="Petugas"><?= e($r['petugas'] ?: '—') ?></td>
        <?php if ($adaUbah): ?><td><?= tombol_ubah_penyesuaian($pdo, $r) ?></td><?php endif; ?>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
</div>
<?php if ($adaUbah) { dialog_ubah_penyesuaian('opname.php' . ($rakId > 0 ? '?rak=' . $rakId : '')); } ?>
<?php endif; ?>

<style>
.opname-grid{ display:grid; grid-template-columns:repeat(auto-fill,minmax(240px,1fr)); gap:12px }
.opname-rak{ display:flex; flex-direction:column; gap:6px; text-decoration:none; color:inherit; padding:14px 16px }
.opname-rak:hover{ border-color:var(--primary-2) }
.opname-kode{ display:flex; justify-content:space-between; gap:8px; font-size:12px }
.opname-angka{ display:flex; gap:14px; font-size:13px; color:var(--text-2) }
.kepala-rak{ display:flex; justify-content:space-between; gap:14px; align-items:flex-start }
</style>
<script>
function samakan(btn){
  var tr = btn.closest('tr');
  tr.querySelector('.inp-fisik').value = tr.querySelector('.tercatat').dataset.n;
}
function samakanSemua(){
  document.querySelectorAll('#frmOpname tbody tr').forEach(function(tr){
    var i = tr.querySelector('.inp-fisik');
    if (i.value === '') i.value = tr.querySelector('.tercatat').dataset.n;
  });
}
/* Tombol dikunci saat dikirim: opname menggerakkan stok, dan klik ganda
   berarti dua penyesuaian untuk satu hitungan. */
document.getElementById('frmOpname') && document.getElementById('frmOpname').addEventListener('submit', function(){
  var b = document.getElementById('btnSimpan'); b.disabled = true; b.textContent = 'Menyimpan…';
});
</script>
<?php require __DIR__ . '/_footer.php'; ?>
