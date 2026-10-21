<?php
/**
 * AMANAH — Perbaikan & Pelepasan Barang
 *
 * Tiga jalan keluar barang selain disalurkan, di satu halaman:
 *   • masuk perbaikan, lalu ditutup dengan salah satu dari tiga hasil
 *   • dibuang — kedaluwarsa atau rusak tak terperbaiki
 *   • dijual — biasanya setelah diperbaiki
 *
 * Sebelum halaman ini ada, batch kedaluwarsa hanya bisa DILIHAT di Batch &
 * Expired tanpa bisa ditindaklanjuti, sehingga barang yang sudah tidak
 * boleh disalurkan tetap terhitung sebagai persediaan.
 *
 * BARANG TITIPAN. Sebagian besar isi gudang ini milik donatur. Karena itu
 * alasan wajib diisi, donatur asalnya ikut dicatat pada tiap pelepasan,
 * dan seluruh riwayatnya tidak bisa dihapus dari layar mana pun.
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/_lib.php';
require_login();
tolak_supplier();
tolak_di_luar_peran($pdo);

/* Melepas barang tidak bisa dibatalkan oleh yang melakukannya, dan yang
   dilepas adalah milik orang lain. Karena itu setingkat manager, sama
   dengan menghapus data induk. */
$bolehLepas = boleh_kelola_master($pdo);

$errors = [];

// ==================================================================== AKSI
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$bolehLepas) {
        http_response_code(403);
        die('<p style="font-family:sans-serif;padding:30px">403 — Membuang, menjual, atau '
          . 'mengirim barang ke perbaikan hanya untuk tingkat Manager. '
          . '<a href="pelepasan.php">Kembali</a></p>');
    }
    wajib_csrf($_POST['csrf'] ?? null);
    $aksi = $_POST['aksi'] ?? '';

    // ---------------------------------------------------------- LEPAS
    if ($aksi === 'lepas') {
        $batchId = (int) ($_POST['batch_id'] ?? 0);
        $qty     = (int) ($_POST['qty'] ?? 0);
        $jenis   = (string) ($_POST['jenis'] ?? '');
        $alasan  = trim($_POST['alasan'] ?? '');

        try {
            $pdo->beginTransaction();

            /* Batch harus berada di gudang akun ini. Tanpa pemeriksaan ini,
               menebak angka di batch_id sudah cukup untuk membuang stok
               gudang lain — dan pembuangan tidak bisa ditarik kembali. */
            $cek = $pdo->prepare(
                "SELECT bb.id FROM batch_barang bb
                 WHERE bb.id = ? AND bb.lokasi_rak_id IN (" . in_rak_saya($pdo) . ")"
            );
            $cek->execute([$batchId]);
            if (!$cek->fetch()) {
                throw new RuntimeException('Batch itu tidak ada di gudang Anda.');
            }

            $ekstra = [];
            if ($jenis === 'dijual') {
                $ekstra['nilai_jual']     = ($_POST['nilai_jual'] ?? '') !== ''
                                          ? (float) $_POST['nilai_jual'] : null;
                $ekstra['no_bukti_setor'] = trim($_POST['no_bukti_setor'] ?? '') ?: null;
                if (!$ekstra['no_bukti_setor']) {
                    throw new RuntimeException(
                        'Nomor bukti setor wajib diisi untuk penjualan — inilah yang '
                      . 'menautkan uangnya ke barang yang keluar.');
                }
            }

            $id = lepas_stok($pdo, $batchId, $qty, $jenis, $alasan, $ekstra);
            $bid = (int) $pdo->query("SELECT barang_id FROM pelepasan_stok WHERE id = $id")->fetchColumn();
            recalc_stok($pdo, $bid);

            $pdo->commit();
            catat_aktivitas($pdo, 'create', 'pelepasan_stok', $id,
                ucfirst($jenis) . " $qty unit dari batch #$batchId: $alasan");
            set_flash('ok', JENIS_PELEPASAN[$jenis][0] . " tercatat — $qty unit keluar dari stok tersedia.");

        } catch (Throwable $e) {
            if ($pdo->inTransaction()) { $pdo->rollBack(); }
            set_flash('err', $e instanceof RuntimeException
                ? $e->getMessage() : pesan_error_aman($e, 'lepas stok'));
        }
        header('Location: pelepasan.php'); exit;
    }

    // ------------------------------------------------ SELESAIKAN PERBAIKAN
    if ($aksi === 'selesai') {
        $pid   = (int) ($_POST['id'] ?? 0);
        $hasil = (string) ($_POST['hasil'] ?? '');

        try {
            $pdo->beginTransaction();

            $ekstra = ['biaya' => ($_POST['biaya'] ?? '') !== '' ? (float) $_POST['biaya'] : null];
            if ($hasil === 'dijual') {
                $ekstra['nilai_jual']     = ($_POST['nilai_jual'] ?? '') !== ''
                                          ? (float) $_POST['nilai_jual'] : null;
                $ekstra['no_bukti_setor'] = trim($_POST['no_bukti_setor'] ?? '') ?: null;
                if (!$ekstra['no_bukti_setor']) {
                    throw new RuntimeException('Nomor bukti setor wajib diisi untuk penjualan.');
                }
            }

            $barangId = (int) $pdo->query(
                "SELECT barang_id FROM pelepasan_stok WHERE id = " . $pid)->fetchColumn();
            selesaikan_perbaikan($pdo, $pid, $hasil, $ekstra);
            if ($barangId) { recalc_stok($pdo, $barangId); }

            $pdo->commit();
            catat_aktivitas($pdo, 'update', 'pelepasan_stok', $pid, "Perbaikan selesai: $hasil");
            set_flash('ok', 'Perbaikan ditutup — ' . HASIL_PERBAIKAN[$hasil][0] . '.');

        } catch (Throwable $e) {
            if ($pdo->inTransaction()) { $pdo->rollBack(); }
            set_flash('err', $e instanceof RuntimeException
                ? $e->getMessage() : pesan_error_aman($e, 'selesaikan perbaikan'));
        }
        header('Location: pelepasan.php'); exit;
    }
}

// ==================================================================== DATA
$inRak = in_rak_saya($pdo);

/* Batch yang PANTAS dilepas: sudah kedaluwarsa, atau dinilai tidak layak
   pakai oleh supplier. Batch sehat sengaja tidak ditawarkan di sini —
   membuang barang yang masih baik seharusnya bukan satu klik yang mudah. */
$kandidat = [];
try {
    $kandidat = $pdo->query(
        "SELECT bb.id, bb.nomor_batch, bb.qty_tersisa, bb.kondisi, bb.tanggal_expired,
                b.nama AS nama_barang, b.kode AS kode_barang, b.satuan, b.harga,
                lr.kode AS kode_rak, g.nama AS nama_gudang,
                d.nama AS nama_donatur, d.anonim AS donatur_anonim, d.wilayah AS donatur_wilayah,
                (bb.tanggal_expired IS NOT NULL AND bb.tanggal_expired < CURDATE()) AS sudah_lewat
         FROM batch_barang bb
         JOIN barang b            ON b.id  = bb.barang_id
         LEFT JOIN lokasi_rak lr  ON lr.id = bb.lokasi_rak_id
         LEFT JOIN gudang g       ON g.id  = lr.gudang_id
         LEFT JOIN donatur d      ON d.id  = bb.donatur_id
         WHERE bb.qty_tersisa > 0
           AND bb.lokasi_rak_id IN ($inRak)
           AND (bb.kondisi IN (" . kondisi_tidak_layak_sql() . ")
                OR (bb.tanggal_expired IS NOT NULL AND bb.tanggal_expired < CURDATE()))
         ORDER BY sudah_lewat DESC, bb.tanggal_expired ASC, b.nama"
    )->fetchAll();
} catch (Throwable $e) { error_log('[AMANAH] pelepasan kandidat: ' . $e->getMessage()); }

$proses  = [];
$riwayat = [];
try {
    $proses = $pdo->query(
        "SELECT * FROM v_pelepasan WHERE jenis = 'perbaikan' AND status = 'proses'
         ORDER BY lama_hari DESC, tanggal ASC")->fetchAll();
    $riwayat = $pdo->query(
        "SELECT * FROM v_pelepasan WHERE NOT (jenis = 'perbaikan' AND status = 'proses')
         ORDER BY id DESC LIMIT 40")->fetchAll();
} catch (Throwable $e) { error_log('[AMANAH] pelepasan daftar: ' . $e->getMessage()); }

$totalDibuang = 0; $totalJual = 0.0; $totalBiaya = 0.0;
foreach ($riwayat as $r) {
    if ($r['jenis'] === 'dibuang' || $r['hasil'] === 'dibuang') { $totalDibuang += (int) $r['qty']; }
    $totalJual  += (float) $r['nilai_jual'];
    $totalBiaya += (float) $r['biaya'];
}

$PAGE_TITLE = 'Perbaikan & Pelepasan'; $ACTIVE = 'expired';
require __DIR__ . '/_header.php';
?>
<div class="page-head">
  <div><h1>Perbaikan &amp; Pelepasan</h1>
    <p>Barang bekas yang diperbaiki, dibuang, atau dijual — beserta jejaknya</p></div>
</div>

<?= peringatan_tanpa_gudang($pdo) ?>

<?php if (!$bolehLepas): ?>
  <div class="alert" style="--ac:var(--amber);margin-bottom:16px">
    <span class="alert-ic"><svg aria-hidden="true"><use href="#i-info"></use></svg></span>
    <div class="alert-tx"><b>Anda dapat melihat, tidak dapat mengubah</b>
      <small>Membuang, menjual, dan mengirim barang ke perbaikan adalah wewenang tingkat
        Manager. Halaman ini tetap terbuka supaya riwayatnya bisa Anda periksa.</small></div>
  </div>
<?php endif; ?>

<div class="kpi">
  <div class="kpi-card" style="--kc:var(--blue)">
    <div class="lbl">Sedang diperbaiki</div>
    <div class="val"><?= angka(count($proses)) ?></div>
    <div class="sub2">catatan berjalan</div>
  </div>
  <div class="kpi-card" style="--kc:var(--amber)">
    <div class="lbl">Menunggu keputusan</div>
    <div class="val"><?= angka(count($kandidat)) ?></div>
    <div class="sub2">batch kedaluwarsa / tidak layak</div>
  </div>
  <div class="kpi-card" style="--kc:var(--red)">
    <div class="lbl">Sudah dibuang</div>
    <div class="val"><?= angka($totalDibuang) ?></div>
    <div class="sub2">unit, dari 40 catatan terakhir</div>
  </div>
  <div class="kpi-card" style="--kc:var(--green)">
    <div class="lbl">Hasil penjualan</div>
    <div class="val"><?= rupiah($totalJual) ?></div>
    <div class="sub2">biaya perbaikan <?= rupiah($totalBiaya) ?></div>
  </div>
</div>

<!-- ============================================== SEDANG DIPERBAIKI -->
<?php if ($proses): ?>
<div class="card">
  <h3>Sedang Diperbaiki</h3>
  <div class="sub">Sudah tidak terhitung stok tersedia, tapi belum hilang — tinggal ditutup hasilnya</div>
  <div class="scrollx"><table class="tbl-kartu">
    <thead><tr><th>No.</th><th>Barang</th><th>Batch</th><th class="num">Jumlah</th>
      <th>Alasan</th><th class="num">Lama</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($proses as $p): ?>
      <tr>
        <td data-label="No."><code><?= e($p['no_pelepasan']) ?></code><br>
          <small class="mut2"><?= e(tgl_id($p['tanggal'])) ?></small></td>
        <td data-label="Barang"><b><?= e($p['nama_barang']) ?></b><br>
          <small class="mut2"><?= e($p['kode_barang']) ?></small></td>
        <td data-label="Batch"><?= $p['nomor_batch'] ? '<code>' . e($p['nomor_batch']) . '</code>' : '—' ?>
          <?php if ($p['nama_donatur']): ?><br><small class="mut2">dari <?= e(nama_donatur_tampil([
            'nama' => $p['nama_donatur'], 'anonim' => $p['donatur_anonim'],
            'wilayah' => $p['donatur_wilayah']])) ?></small><?php endif; ?></td>
        <td data-label="Jumlah" class="num"><b><?= angka($p['qty']) ?></b> <?= e($p['satuan']) ?></td>
        <td data-label="Alasan" style="font-size:12.5px"><?= e($p['alasan']) ?></td>
        <td data-label="Lama" class="num">
          <?php /* Yang tertahan lama disorot: barang yang terlupakan di bengkel
                   tidak pernah mengumumkan dirinya sendiri. */ ?>
          <span class="tag <?= (int) $p['lama_hari'] > 30 ? 't-red' : 't-gray' ?>">
            <?= (int) $p['lama_hari'] ?> hari</span></td>
        <td>
          <?php if ($bolehLepas): ?>
            <button type="button" class="btn btn-primary btn-sm"
                    onclick="bukaSelesai(<?= (int) $p['id'] ?>, '<?= e(addslashes($p['nama_barang'])) ?>', <?= (int) $p['qty'] ?>)">
              Selesaikan</button>
          <?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
</div>
<?php endif; ?>

<!-- ============================================ MENUNGGU KEPUTUSAN -->
<div class="card">
  <h3>Menunggu Keputusan</h3>
  <div class="sub">Batch kedaluwarsa atau dinilai tidak layak pakai — masih terhitung stok sampai dilepas</div>
  <div class="scrollx"><table class="tbl-kartu">
    <thead><tr><th>Barang</th><th>Batch</th><th>Kondisi</th><th>Kedaluwarsa</th>
      <th>Lokasi</th><th class="num">Sisa</th><th></th></tr></thead>
    <tbody>
    <?php if (!$kandidat): ?>
      <tr><td colspan="7" class="empty">
        Tidak ada batch yang perlu diputuskan. Semua stok di gudang Anda masih layak dan belum kedaluwarsa.
      </td></tr>
    <?php else: foreach ($kandidat as $k):
        [$lblK, $wrnK] = label_kondisi($k['kondisi']); ?>
      <tr>
        <td data-label="Barang"><b><?= e($k['nama_barang']) ?></b><br>
          <small class="mut2"><?= e($k['kode_barang']) ?></small>
          <?php if ($k['nama_donatur']): ?><br><small class="mut2">dari <?= e(nama_donatur_tampil([
            'nama' => $k['nama_donatur'], 'anonim' => $k['donatur_anonim'],
            'wilayah' => $k['donatur_wilayah']])) ?></small><?php endif; ?></td>
        <td data-label="Batch"><code><?= e($k['nomor_batch']) ?></code></td>
        <td data-label="Kondisi"><span class="tag <?= $wrnK ?>"><?= e($lblK) ?></span></td>
        <td data-label="Kedaluwarsa">
          <?php if ($k['tanggal_expired']): ?>
            <span class="tag <?= $k['sudah_lewat'] ? 't-red' : 't-gray' ?>">
              <?= e(tgl_id($k['tanggal_expired'])) ?></span>
          <?php else: ?>—<?php endif; ?></td>
        <td data-label="Lokasi"><?= e($k['kode_rak'] ?: '—') ?>
          <?php if ($k['nama_gudang']): ?><br><small class="mut2"><?= e($k['nama_gudang']) ?></small><?php endif; ?></td>
        <td data-label="Sisa" class="num"><b><?= angka($k['qty_tersisa']) ?></b> <?= e($k['satuan']) ?></td>
        <td>
          <?php if ($bolehLepas): ?>
            <button type="button" class="btn btn-ghost btn-sm"
                    onclick="bukaLepas(<?= (int) $k['id'] ?>, '<?= e(addslashes($k['nama_barang'])) ?>', <?= (int) $k['qty_tersisa'] ?>, '<?= e($k['satuan']) ?>')">
              Putuskan</button>
          <?php endif; ?>
        </td>
      </tr>
    <?php endforeach; endif; ?>
    </tbody>
  </table></div>
</div>

<!-- ==================================================== RIWAYAT -->
<div class="card">
  <h3>Riwayat Pelepasan</h3>
  <div class="sub">40 catatan terakhir · tidak dapat dihapus</div>
  <div class="scrollx"><table class="tbl-kartu">
    <thead><tr><th>No.</th><th>Barang</th><th>Jenis</th><th class="num">Jumlah</th>
      <th>Alasan / Hasil</th><th class="num">Nilai</th><th>Petugas</th></tr></thead>
    <tbody>
    <?php if (!$riwayat): ?>
      <tr><td colspan="7" class="empty">Belum ada barang yang dilepas.</td></tr>
    <?php else: foreach ($riwayat as $r):
        [$lblJ, $wrnJ] = label_pelepasan($r['jenis']); ?>
      <tr>
        <td data-label="No."><code><?= e($r['no_pelepasan']) ?></code><br>
          <small class="mut2"><?= e(tgl_id($r['tanggal'])) ?></small></td>
        <td data-label="Barang"><b><?= e($r['nama_barang']) ?></b>
          <?php if ($r['nama_donatur']): ?><br><small class="mut2">dari <?= e(nama_donatur_tampil([
            'nama' => $r['nama_donatur'], 'anonim' => $r['donatur_anonim'],
            'wilayah' => $r['donatur_wilayah']])) ?></small><?php endif; ?></td>
        <td data-label="Jenis"><span class="tag <?= $wrnJ ?>"><?= e($lblJ) ?></span>
          <?php if ($r['jenis'] === 'perbaikan' && $r['hasil']):
                  [$lblH, $wrnH] = label_hasil($r['hasil']); ?>
            <br><span class="tag <?= $wrnH ?>"><?= e($lblH) ?></span>
          <?php endif; ?></td>
        <td data-label="Jumlah" class="num"><b><?= angka($r['qty']) ?></b> <?= e($r['satuan']) ?></td>
        <td data-label="Alasan / Hasil" style="font-size:12.5px"><?= e($r['alasan']) ?></td>
        <td data-label="Nilai" class="num">
          <?php if ($r['nilai_jual'] !== null && (float) $r['nilai_jual'] > 0): ?>
            <b style="color:var(--green-t)"><?= rupiah($r['nilai_jual']) ?></b>
            <?php if ($r['no_bukti_setor']): ?><br><small class="mut2">bukti <?= e($r['no_bukti_setor']) ?></small><?php endif; ?>
          <?php elseif ($r['biaya'] !== null && (float) $r['biaya'] > 0): ?>
            <small class="mut2">biaya <?= rupiah($r['biaya']) ?></small>
          <?php else: ?>—<?php endif; ?></td>
        <td data-label="Petugas" style="font-size:12.5px"><?= e($r['petugas'] ?: '—') ?></td>
      </tr>
    <?php endforeach; endif; ?>
    </tbody>
  </table></div>
</div>

<?php if ($bolehLepas): ?>
<!-- ================================================= POPUP: PUTUSKAN -->
<div class="card form-lipat" id="formLepas">
  <div class="modal-kepala">
    <div><h3>Putuskan Nasib Barang</h3>
      <div class="sub" id="lepasJudul">—</div></div>
    <a class="modal-tutup" href="pelepasan.php" data-tutup-modal aria-label="Tutup">&times;</a>
  </div>
  <form method="post">
    <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
    <input type="hidden" name="aksi" value="lepas">
    <input type="hidden" name="batch_id" id="lepasBatch">

    <div class="form-grid">
      <div class="field"><label>Tindakan <span class="req">*</span></label>
        <select name="jenis" id="lepasJenis" onchange="ubahJenis()" required>
          <?php foreach (JENIS_PELEPASAN as $k => $v): ?>
            <option value="<?= e($k) ?>"><?= e($v[0]) ?></option>
          <?php endforeach; ?>
        </select>
        <small class="petunjuk" id="lepasKet"><?= e(JENIS_PELEPASAN['perbaikan'][2]) ?></small>
      </div>
      <div class="field"><label>Jumlah <span class="req">*</span></label>
        <input type="number" name="qty" id="lepasQty" min="1" value="1" required>
        <small class="petunjuk" id="lepasSisa">—</small>
      </div>
    </div>

    <div class="field"><label>Alasan <span class="req">*</span></label>
      <input name="alasan" maxlength="255" required
             placeholder="mis. kedaluwarsa 3 bulan, kemasan sobek dan isinya tumpah">
      <small class="petunjuk">Wajib diisi. Barang ini titipan orang, dan alasannya yang
        menjelaskan kepada donatur ke mana barangnya pergi.</small></div>

    <div id="lepasJual" style="display:none">
      <div class="form-grid">
        <div class="field"><label>Hasil penjualan (Rp)</label>
          <input type="number" name="nilai_jual" min="0" step="1000" placeholder="0"></div>
        <div class="field"><label>Nomor bukti setor <span class="req">*</span></label>
          <input name="no_bukti_setor" maxlength="60" placeholder="mis. TRF-20260827-01">
          <small class="petunjuk">Inilah yang menautkan uangnya ke barang yang keluar.</small></div>
      </div>
    </div>

    <div class="modal-f">
      <a class="btn btn-ghost" href="pelepasan.php" data-tutup-modal>Batal</a>
      <button class="btn btn-primary">Simpan Keputusan</button>
    </div>
  </form>
</div>

<!-- ============================================ POPUP: SELESAIKAN -->
<div class="card form-lipat" id="formSelesai">
  <div class="modal-kepala">
    <div><h3>Selesaikan Perbaikan</h3>
      <div class="sub" id="selesaiJudul">—</div></div>
    <a class="modal-tutup" href="pelepasan.php" data-tutup-modal aria-label="Tutup">&times;</a>
  </div>
  <form method="post">
    <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
    <input type="hidden" name="aksi" value="selesai">
    <input type="hidden" name="id" id="selesaiId">

    <div class="form-grid">
      <div class="field"><label>Hasil <span class="req">*</span></label>
        <select name="hasil" id="selesaiHasil" onchange="ubahHasil()" required>
          <?php foreach (HASIL_PERBAIKAN as $k => $v): ?>
            <option value="<?= e($k) ?>"><?= e($v[0]) ?></option>
          <?php endforeach; ?>
        </select>
        <small class="petunjuk" id="selesaiKet">Barang kembali ke stok sebagai bekas layak pakai,
          dalam batch baru berakhiran <code>-R</code>.</small>
      </div>
      <div class="field"><label>Biaya perbaikan (Rp)</label>
        <input type="number" name="biaya" min="0" step="1000" placeholder="kosongkan bila gratis">
        <small class="petunjuk">Opsional — berguna untuk menilai apakah memperbaiki masih
          lebih murah daripada membuang.</small></div>
    </div>

    <div id="selesaiJual" style="display:none">
      <div class="form-grid">
        <div class="field"><label>Hasil penjualan (Rp)</label>
          <input type="number" name="nilai_jual" min="0" step="1000" placeholder="0"></div>
        <div class="field"><label>Nomor bukti setor <span class="req">*</span></label>
          <input name="no_bukti_setor" maxlength="60" placeholder="mis. TRF-20260827-01"></div>
      </div>
    </div>

    <div class="modal-f">
      <a class="btn btn-ghost" href="pelepasan.php" data-tutup-modal>Batal</a>
      <button class="btn btn-primary">Tutup Perbaikan</button>
    </div>
  </form>
</div>
<?php endif; ?>

<script>
var KET_JENIS = <?= json_encode(array_map(function ($v) { return $v[2]; }, JENIS_PELEPASAN)) ?>;

function bukaLepas(id, nama, sisa, satuan) {
  document.getElementById('lepasBatch').value = id;
  document.getElementById('lepasJudul').textContent = nama;
  var q = document.getElementById('lepasQty');
  q.max = sisa; q.value = sisa;
  document.getElementById('lepasSisa').textContent = 'Sisa batch ' + sisa + ' ' + satuan;
  document.getElementById('formLepas').classList.add('terbuka');
  ubahJenis();
}

function ubahJenis() {
  var j = document.getElementById('lepasJenis').value;
  document.getElementById('lepasKet').textContent = KET_JENIS[j] || '';
  var jual = document.getElementById('lepasJual');
  jual.style.display = j === 'dijual' ? '' : 'none';
  /* Wajibnya dilepas saat kotaknya tersembunyi — kalau tidak, formulir
     pembuangan menolak terkirim karena isian yang tidak terlihat. */
  jual.querySelector('[name=no_bukti_setor]').required = (j === 'dijual');
}

function bukaSelesai(id, nama, qty) {
  document.getElementById('selesaiId').value = id;
  document.getElementById('selesaiJudul').textContent = nama + ' · ' + qty + ' unit';
  document.getElementById('formSelesai').classList.add('terbuka');
  ubahHasil();
}

function ubahHasil() {
  var h = document.getElementById('selesaiHasil').value;
  var jual = document.getElementById('selesaiJual');
  jual.style.display = h === 'dijual' ? '' : 'none';
  jual.querySelector('[name=no_bukti_setor]').required = (h === 'dijual');
  var ket = {
    layak:   'Barang kembali ke stok sebagai bekas layak pakai, dalam batch baru berakhiran -R.',
    dibuang: 'Barang tidak kembali. Stoknya memang sudah dikurangi saat masuk perbaikan.',
    dijual:  'Barang tidak kembali. Catat hasil penjualan dan nomor bukti setornya.'
  };
  document.getElementById('selesaiKet').textContent = ket[h] || '';
}
</script>

<?php require __DIR__ . '/_footer.php'; ?>
