<?php
/**
 * AMANAH — Lacak Kiriman
 *
 * Menjawab "barangnya sudah sampai mana?" untuk satu surat jalan: linimasa
 * dari dibuat, disetujui, tiba/berangkat di tiap gudang transit, sampai
 * diterima di tujuan. Di halaman ini pula petugas gudang transit menandai
 * "tiba" dan "berangkat" — tanpa menerima atau mengirim ulang stok, karena
 * stok tetap milik pengirim sampai tujuan akhir (lihat _lacak.php).
 *
 * Dibuka dari: tombol Lacak di daftar Surat Jalan, tombol di Cek Surat
 * Jalan (sesudah memindai QR), atau menu Lacak Kiriman lalu ketik nomornya.
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/_lib.php';
require_login();
tolak_supplier();
$u = current_user();
pastikan_tabel_titik($pdo);   // sebelum transaksi apa pun di halaman ini

$id   = (int) ($_GET['id'] ?? $_POST['id'] ?? 0);
$kode = trim((string) ($_GET['k'] ?? $_POST['kode'] ?? ''));

// Nomor surat jalan / dokumen / resi → id. Tiga nomor itu tercetak di lembar yang sama.
if ($id <= 0 && $kode !== '') {
    $st = $pdo->prepare(
        "SELECT id FROM perpindahan WHERE no_surat_jalan = ? OR no_perpindahan = ? OR no_resi = ?
         ORDER BY id DESC LIMIT 1");
    $st->execute([$kode, $kode, $kode]);
    $id = (int) $st->fetchColumn();
}

$dok = null; $pesan = '';
if ($id > 0) {
    if (!boleh_lihat_perpindahan($pdo, $id)) {
        $pesan = 'Surat jalan ini ada, tetapi bukan untuk lokasi yang Anda pegang.';
    } else {
        $st = $pdo->prepare(
            "SELECT p.*, u.nama AS pembuat, ua.nama AS penyetuju, ud.nama AS penerima_nama
             FROM perpindahan p
             LEFT JOIN users u  ON u.id  = p.user_id
             LEFT JOIN users ua ON ua.id = p.approved_by
             LEFT JOIN users ud ON ud.id = p.diterima_oleh
             WHERE p.id = ?");
        $st->execute([$id]);
        $dok = $st->fetch() ?: null;
        if (!$dok) { $pesan = 'Dokumen tidak ditemukan.'; }
    }
} elseif ($kode !== '') {
    $pesan = 'Nomor ini tidak ditemukan. Periksa kembali ketikannya, atau pindai ulang QR-nya.';
}

// ------------------------------------------------- tandai tiba / berangkat
if ($dok && $_SERVER['REQUEST_METHOD'] === 'POST' && in_array($_POST['aksi'] ?? '', ['tiba', 'berangkat'], true)) {
    wajib_csrf($_POST['csrf'] ?? null);
    try {
        $pdo->beginTransaction();
        $h = catat_transit($pdo, (int) ($_POST['titik_id'] ?? 0), (string) $_POST['aksi'],
                           (int) $u['id'], (string) ($_POST['catatan'] ?? ''),
                           ['kendaraan' => $_POST['kendaraan'] ?? '', 'no_polisi' => $_POST['no_polisi'] ?? '', 'pengemudi' => $_POST['pengemudi'] ?? '']);
        $pdo->commit();
        $namaLok = nama_lokasi($pdo, $h['titik']['lokasi_tipe'], (int) $h['titik']['lokasi_id'], null);
        catat_aktivitas($pdo, 'update', 'perpindahan', (int) $dok['id'],
            ($_POST['aksi'] === 'tiba' ? 'Tiba di ' : 'Berangkat dari ') . "$namaLok (transit) — {$dok['no_perpindahan']}");
        set_flash('ok', ($_POST['aksi'] === 'tiba' ? 'Kiriman ditandai TIBA di ' : 'Kiriman ditandai BERANGKAT dari ')
                        . $namaLok . '.');
    } catch (PDOException $e) {
        if ($pdo->inTransaction()) { $pdo->rollBack(); }
        set_flash('err', pesan_error_aman($e, 'catat transit'));
    } catch (RuntimeException $e) {
        if ($pdo->inTransaction()) { $pdo->rollBack(); }
        set_flash('err', $e->getMessage());
    }
    header('Location: lacak.php?id=' . (int) $dok['id']); exit;
}

// --------------------------------------------------------------- tampilan
$titik = []; $posisi = null; $linimasa = []; $detail = []; $asal = $tujuan = '';
if ($dok) {
    $titik    = titik_transit($pdo, (int) $dok['id']);
    $posisi   = posisi_kiriman($pdo, $dok, $titik);
    $linimasa = linimasa_kiriman($pdo, $dok, $titik);
    $asal     = nama_lokasi($pdo, $dok['asal_tipe'],   $dok['asal_id']   ? (int) $dok['asal_id']   : null, $dok['asal_teks']);
    $tujuan   = nama_lokasi($pdo, $dok['tujuan_tipe'], $dok['tujuan_id'] ? (int) $dok['tujuan_id'] : null, $dok['tujuan_teks']);
    [$jLbl]        = label_jenis($dok['jenis']);
    [$sLbl, $sCls] = label_status($dok['status']);
    $stD = $pdo->prepare(
        "SELECT d.qty, d.nomor_batch, b.nama AS nama_barang, b.kode AS kode_barang, b.satuan
         FROM perpindahan_detail d JOIN barang b ON b.id = d.barang_id
         WHERE d.perpindahan_id = ? ORDER BY d.id");
    $stD->execute([(int) $dok['id']]);
    $detail = $stD->fetchAll();
    // Titik mana yang boleh ditandai akun ini — dihitung sekali, dipakai di linimasa.
    $bolehTitik = [];
    foreach ($titik as $t) { $bolehTitik[(int) $t['id']] = boleh_catat_transit($pdo, $t); }
}

$PAGE_TITLE = 'Lacak Kiriman'; $ACTIVE = 'lacak';
require __DIR__ . '/_header.php';
?>
<div class="page-head">
  <div><h1>Lacak Kiriman</h1>
    <p>Posisi surat jalan yang singgah di gudang transit — sudah sampai mana</p></div>
  <?php if ($dok): ?>
    <a class="btn btn-ghost" href="perpindahan.php">← Surat Jalan</a>
  <?php endif; ?>
</div>

<?php if (!$dok): ?>
<div class="card">
  <h3>Nomor Surat Jalan</h3>
  <div class="sub">Ketik atau tembak nomor surat jalan, nomor dokumen, atau nomor resi</div>
  <form method="get" class="tools">
    <input name="k" value="<?= e($kode) ?>" autofocus autocomplete="off" placeholder="SJ-20261001-0001"
           style="flex:1;min-width:220px;font-family:'Courier New',monospace;letter-spacing:1px">
    <button class="btn btn-primary" type="submit"><svg class="ikon" aria-hidden="true"><use href="#i-cari"></use></svg> Lacak</button>
    <a class="btn btn-ghost" href="cek_surat.php"><svg class="ikon" aria-hidden="true"><use href="#i-qr"></use></svg> Pindai QR</a>
  </form>
  <?php if ($pesan !== ''): ?>
    <div class="kosong-blok" style="margin-top:14px"><p><?= e($pesan) ?></p></div>
  <?php endif; ?>
</div>

<?php
/* Daftar kiriman yang sedang berjalan dalam lingkup akun ini — supaya menu
   ini berguna tanpa harus tahu nomornya lebih dulu. */
try {
    $stJ = $pdo->query(
        "SELECT p.* FROM perpindahan p
         WHERE p.status = 'dikirim' AND " . syarat_perpindahan($pdo, 'p') . "
         ORDER BY p.approved_at DESC, p.id DESC LIMIT 30");
    $berjalan = $stJ->fetchAll();
} catch (Throwable $e) { $berjalan = []; error_log('[AMANAH] lacak daftar: ' . $e->getMessage()); }
?>
<div class="card">
  <h3>Sedang dalam perjalanan</h3>
  <div class="sub"><?= count($berjalan) ?> surat jalan yang belum diterima di tujuannya</div>
  <?php if (!$berjalan): ?>
    <div class="kosong-blok"><p>Tidak ada kiriman yang sedang berjalan.</p></div>
  <?php else: ?>
  <div class="tbl-wrap"><table class="tbl-kartu">
    <thead><tr><th>Surat Jalan</th><th>Berangkat</th><th>Dari</th><th>Ke</th><th>Posisi sekarang</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($berjalan as $r):
        $tR = titik_transit($pdo, (int) $r['id']); $pR = posisi_kiriman($pdo, $r, $tR); ?>
      <tr>
        <td data-label="Surat Jalan"><code><?= e($r['no_surat_jalan'] ?: $r['no_perpindahan']) ?></code></td>
        <td class="sekunder nowrap" data-label="Berangkat"><?= e(tgl_id($r['approved_at'])) ?></td>
        <td data-label="Dari"><?= e(nama_lokasi($pdo, $r['asal_tipe'], $r['asal_id'] ? (int) $r['asal_id'] : null, $r['asal_teks'])) ?></td>
        <td data-label="Ke"><?= e(nama_lokasi($pdo, $r['tujuan_tipe'], $r['tujuan_id'] ? (int) $r['tujuan_id'] : null, $r['tujuan_teks'])) ?>
          <?php if ($tR): ?><br><small class="mut2">via <?= e(implode(' → ', array_column($tR, 'lokasi_nama'))) ?></small><?php endif; ?></td>
        <td data-label="Posisi sekarang"><span class="tag <?= e($pR['kelas']) ?>">📍 <?= e($pR['teks']) ?></span></td>
        <td><a class="btn btn-ghost btn-sm" href="lacak.php?id=<?= (int) $r['id'] ?>">Lacak</a></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
  <?php endif; ?>
</div>

<?php else: ?>

<div class="card">
  <div style="display:flex;justify-content:space-between;gap:14px;flex-wrap:wrap;align-items:flex-start">
    <div>
      <div class="mut-sm">Surat Jalan</div>
      <h3 style="margin:2px 0 6px;font-family:'Courier New',monospace;letter-spacing:1px"><?= e($dok['no_surat_jalan'] ?: $dok['no_perpindahan']) ?></h3>
      <span class="tag <?= e($sCls) ?>"><?= e($sLbl) ?></span>
      <span class="tag t-gray"><?= e($jLbl) ?></span>
    </div>
    <div style="text-align:right">
      <div class="mut-sm">Posisi sekarang</div>
      <div style="font-size:20px;font-weight:700;margin-top:2px">📍 <?= e($posisi['teks']) ?></div>
    </div>
  </div>
  <div class="form-grid" style="margin-top:14px;font-size:13.5px">
    <div class="span-all"><span class="mut2">Angkutan ruas pertama (dari surat jalan)</span><br>
      <b><?= e(teks_angkutan($dok['kendaraan'], $dok['no_polisi'], $dok['pengirim']) ?: '—') ?></b>
      <?php if ($titik): ?><small class="mut2"> · ruas berikutnya dicatat saat "berangkat" dari tiap transit</small><?php endif; ?></div>
    <div><span class="mut2">Dari</span><br><b><?= e($asal) ?></b></div>
    <div><span class="mut2">Ke</span><br><b><?= e($tujuan) ?></b></div>
    <div><span class="mut2">Transit</span><br><b><?= $titik ? e(implode(' → ', array_column($titik, 'lokasi_nama'))) : '—' ?></b></div>
    <div><span class="mut2">Resi / Ekspedisi</span><br><b><?= e($dok['no_resi'] ?: '—') ?></b><?= $dok['ekspedisi'] ? ' · ' . e($dok['ekspedisi']) : '' ?></div>
  </div>
  <div style="margin-top:14px;display:flex;gap:8px;flex-wrap:wrap">
    <?php if ($dok['no_surat_jalan']): ?>
      <a class="btn btn-ghost btn-sm" href="surat_jalan.php?id=<?= (int) $dok['id'] ?>" target="_blank" rel="noopener">
        <svg class="ikon" aria-hidden="true"><use href="#i-cetak"></use></svg> Lembar Cetak</a>
    <?php endif; ?>
    <a class="btn btn-ghost btn-sm" href="cek_surat.php?k=<?= e(rawurlencode($dok['no_surat_jalan'] ?: $dok['no_perpindahan'])) ?>">Isi kiriman</a>
    <?php if ($dok['status'] === 'dikirim'): ?>
      <a class="btn btn-ghost btn-sm" href="penerimaan.php?id=<?= (int) $dok['id'] ?>">Penerimaan di tujuan</a>
    <?php endif; ?>
  </div>
</div>

<div class="card">
  <h3>Perjalanan</h3>
  <div class="sub"><?= $titik ? 'Petugas gudang transit menandai tiba dan berangkat di sini' : 'Surat jalan ini tidak singgah di gudang transit' ?></div>
  <ol class="lini">
    <?php foreach ($linimasa as $L): $aksiBoleh = $L['aksi'] && $L['keadaan'] === 'kini' && !empty($bolehTitik[$L['titik_id']]); ?>
      <li class="lini-<?= e($L['keadaan']) ?>">
        <div class="lini-titik"></div>
        <div class="lini-isi">
          <b><?= e($L['judul']) ?></b>
          <div class="mut-sm">
            <?php if ($L['waktu']): ?><?= e(tgl_jam_id($L['waktu'])) ?><?= $L['oleh'] ? ' · ' . e($L['oleh']) : '' ?>
            <?php elseif ($L['keadaan'] === 'lewat'): ?>tidak dicatat
            <?php elseif ($L['keadaan'] === 'kini'): ?>langkah berikutnya
            <?php else: ?>—<?php endif; ?>
          </div>
          <?php if (!empty($L['kirim'])): ?>
            <div class="mut-sm">🚚 <?= e($L['kirim']) ?></div>
          <?php endif; ?>
          <?php if ($aksiBoleh): ?>
            <form method="post" class="lini-aksi" data-konfirm="Tandai kiriman <?= $L['aksi'] === 'tiba' ? 'TIBA di' : 'BERANGKAT dari' ?> lokasi ini sekarang?"
                  data-tombol="<?= $L['aksi'] === 'tiba' ? 'Ya, sudah tiba' : 'Ya, sudah berangkat' ?>" data-warna="primary">
              <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
              <input type="hidden" name="id" value="<?= (int) $dok['id'] ?>">
              <input type="hidden" name="titik_id" value="<?= (int) $L['titik_id'] ?>">
              <input type="hidden" name="aksi" value="<?= e($L['aksi']) ?>">
              <?php if ($L['aksi'] === 'berangkat'): ?>
                <?php /* Ruas berikutnya sering memakai mobil dan sopir yang berbeda dari
                         yang tercetak di surat jalan — dicatat di titik ini. */ ?>
                <input type="text" name="kendaraan" maxlength="60" placeholder="kendaraan, mis. Mobil box" style="max-width:170px">
                <input type="text" name="no_polisi" maxlength="20" placeholder="nomor polisi" style="max-width:130px">
                <input type="text" name="pengemudi" maxlength="100" placeholder="pengemudi / driver" style="max-width:170px">
              <?php endif; ?>
              <input type="text" name="catatan" maxlength="255" placeholder="catatan (opsional)" style="max-width:200px">
              <button class="btn btn-primary btn-sm" type="submit">
                <?= $L['aksi'] === 'tiba' ? '📥 Tandai tiba di sini' : '📤 Tandai berangkat' ?></button>
            </form>
          <?php elseif ($L['aksi'] && $L['keadaan'] === 'kini'): ?>
            <small class="mut2">Ditandai oleh petugas gudang itu.</small>
          <?php endif; ?>
        </div>
      </li>
    <?php endforeach; ?>
  </ol>
  <?php foreach ($titik as $t): if ($t['catatan']): ?>
    <p class="mut-sm" style="margin:6px 0 0">Catatan <?= e($t['lokasi_nama']) ?>: <?= e($t['catatan']) ?></p>
  <?php endif; endforeach; ?>
</div>

<div class="card">
  <h3>Isi kiriman</h3>
  <div class="sub"><?= count($detail) ?> jenis · <?= angka(array_sum(array_column($detail, 'qty'))) ?> unit</div>
  <div class="tbl-wrap"><table class="tbl-kartu">
    <thead><tr><th>Barang</th><th>Batch</th><th class="num">Jumlah</th></tr></thead>
    <tbody>
    <?php foreach ($detail as $d): ?>
      <tr>
        <td data-label="Barang"><b><?= e($d['nama_barang']) ?></b><br><small class="mut2"><?= e($d['kode_barang']) ?></small></td>
        <td class="sekunder" data-label="Batch"><?= e($d['nomor_batch'] ?: '—') ?></td>
        <td class="num" data-label="Jumlah"><b><?= angka($d['qty']) ?></b> <?= e($d['satuan']) ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
</div>
<?php endif; ?>

<style>
.lini{list-style:none;margin:0;padding:0 0 0 6px;border-left:2px solid var(--border)}
.lini li{position:relative;padding:0 0 18px 22px}
.lini li:last-child{padding-bottom:4px}
.lini-titik{position:absolute;left:-8px;top:3px;width:14px;height:14px;border-radius:50%;
  background:var(--surface-2);border:2px solid var(--border-kuat)}
.lini-selesai .lini-titik{background:var(--green);border-color:var(--green)}
.lini-kini .lini-titik{background:var(--primary);border-color:var(--primary);box-shadow:0 0 0 4px var(--primary-lembut)}
.lini-kini > .lini-isi > b{color:var(--primary)}
.lini-lewat .lini-isi{opacity:.55}
.lini-nanti .lini-isi{opacity:.7}
.lini-aksi{display:flex;gap:8px;flex-wrap:wrap;align-items:center;margin-top:8px}
</style>
<?php require __DIR__ . '/_footer.php'; ?>
