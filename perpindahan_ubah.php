<?php
/**
 * AMANAH — Ubah Dokumen Perpindahan (Super Admin)
 *
 * Formulir untuk mengubah surat jalan yang sudah ada: asal, tujuan, titik
 * transit, tanggal, data pengiriman, dan tiap baris barang (barang, jumlah,
 * batch, kedaluwarsa, keterangan). Aturan dan akibatnya ke stok ada di
 * ubah_perpindahan() (_perpindahan_ubah.php) — yang terpenting: dokumen
 * yang sudah selesai dibalikkan stoknya lalu diterapkan ulang, dalam satu
 * transaksi.
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/_lib.php';
require_role(['super_admin']);
$u = current_user();

$id = (int) ($_GET['id'] ?? $_POST['id'] ?? 0);
$st = $pdo->prepare("SELECT p.*, u.nama AS pembuat FROM perpindahan p LEFT JOIN users u ON u.id = p.user_id WHERE p.id = ?");
$st->execute([$id]);
$dok = $st->fetch();
if (!$dok) { set_flash('err', 'Dokumen tidak ditemukan.'); header('Location: perpindahan.php'); exit; }
$bolehStatus = in_array($dok['status'], ['draft', 'menunggu_persetujuan', 'dikirim', 'selesai'], true);
[$jLbl]        = label_jenis($dok['jenis']);
[$sLbl, $sCls] = label_status($dok['status']);
$selesai   = $dok['status'] === 'selesai';
$adaJejak  = $selesai ? ada_jejak_mutasi($pdo, $id) : true;
/* Dokumen selesai tanpa jejak mutasi: rute dan barisnya dikunci di layar.
   Kotak yang dikunci (disabled) TIDAK ikut terkirim oleh peramban — jadi
   server memakai isi dokumen yang sekarang untuk bagian itu, bukan kiriman
   formulirnya. Dulu kiriman kosong itu dibaca apa adanya dan ditolak dengan
   "Asal \"\" tidak sah", sehingga data pengiriman pun tidak pernah bisa disimpan
   (laporan pengguna 8 Okt 2026). */
$kunciStok = $selesai && !$adaJejak;
$rute      = rute_sah()[$dok['jenis']] ?? ['asal' => [], 'tujuan' => []];

$errors = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $bolehStatus) {
    wajib_csrf($_POST['csrf'] ?? null);
    try {
        // Asal/tujuan datang sebagai "tipe:id" dari satu dropdown berkelompok.
        $pecah = function (string $v): array {
            if (strpos($v, ':') === false) { return [$v, 0]; }
            [$t, $i] = explode(':', $v, 2);
            return [$t, (int) $i];
        };
        [$asalTipe, $asalId]     = $pecah((string) ($_POST['asal'] ?? ''));
        [$tujuanTipe, $tujuanId] = $pecah((string) ($_POST['tujuan'] ?? ''));
        $k = [
            'asal_tipe' => $asalTipe, 'asal_id' => $asalId, 'asal_teks' => $_POST['asal_teks'] ?? '',
            'tujuan_tipe' => $tujuanTipe, 'tujuan_id' => $tujuanId, 'tujuan_teks' => $_POST['tujuan_teks'] ?? '',
            'tanggal' => $_POST['tanggal'] ?? '',
        ];
        foreach (['no_resi', 'ekspedisi', 'kendaraan', 'no_polisi', 'pengirim', 'penerima', 'untuk_program', 'catatan'] as $n) {
            $k[$n] = $_POST[$n] ?? '';
        }
        $baris = [];
        foreach ((array) ($_POST['qty'] ?? []) as $i => $q) {
            $q = trim((string) $q);
            if ($q !== '' && !ctype_digit($q)) { throw new RuntimeException('Jumlah harus angka bulat.'); }
            if (strlen($q) > 9) { throw new RuntimeException("Jumlah $q tidak wajar — sepertinya barcode tertembak ke kotak Jumlah."); }
            $baris[] = [
                'id'              => (int) ($_POST['detail_id'][$i] ?? 0),
                'barang_id'       => (int) ($_POST['barang_id'][$i] ?? 0),
                'qty'             => (int) $q,
                'nomor_batch'     => $_POST['nomor_batch'][$i] ?? '',
                'tanggal_expired' => $_POST['tanggal_expired'][$i] ?? '',
                'keterangan'      => $_POST['ket_item'][$i] ?? '',
            ];
        }
        $transitKirim = (array) ($_POST['transit_id'] ?? []);
        if ($kunciStok) {
            $k['asal_tipe']   = $dok['asal_tipe'];   $k['asal_id']   = (int) $dok['asal_id'];   $k['asal_teks']   = (string) $dok['asal_teks'];
            $k['tujuan_tipe'] = $dok['tujuan_tipe']; $k['tujuan_id'] = (int) $dok['tujuan_id']; $k['tujuan_teks'] = (string) $dok['tujuan_teks'];
            $stLama = $pdo->prepare("SELECT * FROM perpindahan_detail WHERE perpindahan_id = ? ORDER BY id");
            $stLama->execute([$id]);
            $baris = [];
            foreach ($stLama->fetchAll() as $d) {
                $baris[] = ['id' => (int) $d['id'], 'barang_id' => (int) $d['barang_id'],
                            'qty' => $d['qty_diterima'] !== null ? (int) $d['qty_diterima'] : (int) $d['qty'],
                            'nomor_batch' => (string) $d['nomor_batch'], 'tanggal_expired' => (string) $d['tanggal_expired'],
                            'keterangan' => (string) $d['keterangan']];
            }
            if (!isset($_POST['transit_id'])) { $transitKirim = array_column(titik_transit($pdo, $id), 'lokasi_id'); }
        }
        $pdo->beginTransaction();
        $h = ubah_perpindahan($pdo, $id, $k, $baris, $transitKirim);
        $pdo->commit();
        /* Data pengiriman yang SEKARANG tersimpan, dibaca ulang dari basis data
           dan disebut di pesan. Laporan 8 Okt 2026: "tidak ada yang berubah"
           padahal petugas merasa sudah mengisi kendaraan — pesan yang hanya
           berkata berhasil/tidak tidak bisa menjelaskan apa yang diterima server. */
        $stCek = $pdo->prepare("SELECT kendaraan, no_polisi, pengirim, ekspedisi FROM perpindahan WHERE id = ?");
        $stCek->execute([$id]);
        $tersimpan = $stCek->fetch() ?: [];
        $ringkasKirim = 'Tersimpan — kendaraan: ' . ($tersimpan['kendaraan'] ?: '(kosong)')
                       . ' · no. polisi: ' . ($tersimpan['no_polisi'] ?: '(kosong)')
                       . ' · pengirim/driver: ' . ($tersimpan['pengirim'] ?: '(kosong)')
                       . ' · ekspedisi: ' . ($tersimpan['ekspedisi'] ?: '(kosong)') . '.';
        if (!$h['ubah']) {
            set_flash('ok', "Tidak ada yang berubah pada {$h['no']}. " . $ringkasKirim);
        } else {
            catat_aktivitas($pdo, 'update', 'perpindahan', $id, "Ubah {$h['no']}: " . mb_substr(implode('; ', $h['ubah']), 0, 400));
            set_flash('ok', "Dokumen {$h['no']} diperbarui" . ($h['stok'] ? ' — stok dibalikkan dan diterapkan ulang sesuai isi baru. ' : '. ')
                            . $ringkasKirim . ' Cetak ulang surat jalannya bila sudah dicetak.');
        }
        // Kembali ke formulir yang sama — isinya langsung memperlihatkan yang tersimpan.
        header('Location: perpindahan_ubah.php?id=' . $id); exit;
    } catch (PDOException $e) {
        if ($pdo->inTransaction()) { $pdo->rollBack(); }
        $errors[] = pesan_error_aman($e, 'ubah perpindahan');
    } catch (RuntimeException $e) {
        if ($pdo->inTransaction()) { $pdo->rollBack(); }
        $errors[] = $e->getMessage();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) { $pdo->rollBack(); }
        $errors[] = pesan_error_aman($e, 'ubah perpindahan');
    }
}

// ------------------------------------------------------------------ data
$stD = $pdo->prepare(
    "SELECT d.*, b.nama AS nama_barang, b.kode AS kode_barang FROM perpindahan_detail d
     JOIN barang b ON b.id = d.barang_id WHERE d.perpindahan_id = ? ORDER BY d.id");
$stD->execute([$id]);
$detail = $stD->fetchAll();
$titik  = titik_transit($pdo, $id);
$barangList = $pdo->query("SELECT id, kode, nama FROM barang WHERE deleted_at IS NULL AND is_active = 1 ORDER BY nama")->fetchAll();
$gudangList = $pdo->query("SELECT id, kode, nama FROM gudang WHERE deleted_at IS NULL AND is_active = 1 ORDER BY nama")->fetchAll();
$rakList    = $pdo->query("SELECT lr.id, lr.kode, lr.nama, g.nama AS gudang FROM lokasi_rak lr LEFT JOIN gudang g ON g.id = lr.gudang_id WHERE lr.deleted_at IS NULL ORDER BY g.nama, lr.kode")->fetchAll();
$tokoList   = $pdo->query("SELECT id, kode, nama FROM toko WHERE deleted_at IS NULL ORDER BY nama")->fetchAll();
$supplierList = $pdo->query("SELECT id, kode, nama FROM supplier WHERE deleted_at IS NULL ORDER BY nama")->fetchAll();
$penerimaList = [];
try { $penerimaList = $pdo->query("SELECT id, kode, nama, wilayah FROM penerima ORDER BY nama LIMIT 500")->fetchAll(); } catch (Throwable $e) {}

/** Opsi "tipe:id" berkelompok, hanya tipe yang sah untuk jenis dokumen ini. */
function opsi_lokasi(array $tipeBoleh, string $terpilih, array $gd, array $rk, array $tk, array $sp, array $pn): string {
    $grup = [
        'gudang'   => ['Gudang',   array_map(fn($g) => [$g['id'], $g['nama'] . ' (' . $g['kode'] . ')'], $gd)],
        'rak'      => ['Rak',      array_map(fn($r) => [$r['id'], ($r['gudang'] ? $r['gudang'] . ' › ' : '') . $r['nama'] . ' (' . $r['kode'] . ')'], $rk)],
        'toko'     => ['Toko',     array_map(fn($t) => [$t['id'], $t['nama'] . ' (' . $t['kode'] . ')'], $tk)],
        'supplier' => ['Supplier', array_map(fn($s) => [$s['id'], $s['nama'] . ' (' . $s['kode'] . ')'], $sp)],
        'penerima' => ['Penerima terdaftar', array_map(fn($p) => [$p['id'], $p['nama'] . ($p['wilayah'] ? ' — ' . $p['wilayah'] : '')], $pn)],
    ];
    $h = '';
    foreach ($tipeBoleh as $tipe) {
        if ($tipe === 'eksternal') { $h .= '<option value="eksternal"' . ($terpilih === 'eksternal' ? ' selected' : '') . '>Pihak lain (ketik nama)</option>'; continue; }
        if ($tipe === 'penerima') { $h .= '<option value="penerima"' . ($terpilih === 'penerima' ? ' selected' : '') . '>Penerima tidak terdaftar (ketik nama)</option>'; }
        if (!isset($grup[$tipe])) { continue; }
        [$label, $isi] = $grup[$tipe];
        $h .= '<optgroup label="' . e($label) . '">';
        foreach ($isi as [$oid, $nama]) {
            $v = "$tipe:$oid";
            $h .= '<option value="' . e($v) . '"' . ($terpilih === $v ? ' selected' : '') . '>' . e($nama) . '</option>';
        }
        $h .= '</optgroup>';
    }
    return $h;
}
$asalNilai   = $_POST['asal']   ?? ($dok['asal_tipe'] === 'eksternal' ? 'eksternal' : $dok['asal_tipe'] . ':' . (int) $dok['asal_id']);
$tujuanNilai = $_POST['tujuan'] ?? (($dok['tujuan_tipe'] === 'penerima' && !$dok['tujuan_id']) ? 'penerima' : $dok['tujuan_tipe'] . ':' . (int) $dok['tujuan_id']);
$transitIsi  = array_values(array_filter(array_map('intval', (array) ($_POST['transit_id'] ?? array_column($titik, 'lokasi_id')))));
$v = fn(string $n) => (string) ($_POST[$n] ?? $dok[$n] ?? '');

$PAGE_TITLE = 'Ubah Perpindahan'; $ACTIVE = 'surat_jalan';
require __DIR__ . '/_header.php';
?>
<div class="jejak">
  <a href="perpindahan.php">Surat Jalan</a> <span>›</span> <b>Ubah <?= e($dok['no_perpindahan']) ?></b>
</div>
<div class="page-head">
  <div><h1>Ubah <?= e($dok['no_surat_jalan'] ?: $dok['no_perpindahan']) ?></h1>
    <p><span class="tag <?= e($sCls) ?>"><?= e($sLbl) ?></span> <span class="tag t-gray"><?= e($jLbl) ?></span>
       <?= $dok['pembuat'] ? ' · dibuat ' . e($dok['pembuat']) : '' ?></p></div>
  <div style="display:flex;gap:8px;flex-wrap:wrap">
    <?php if ($dok['no_surat_jalan']): ?>
      <a class="btn btn-ghost" href="surat_jalan.php?id=<?= (int) $dok['id'] ?>" target="_blank" rel="noopener">🖨️ Cetak Surat Jalan</a>
    <?php endif; ?>
    <a class="btn btn-ghost" href="perpindahan.php">← Surat Jalan</a>
  </div>
</div>

<?php if (!$bolehStatus): ?>
  <div class="card"><div class="kosong-blok"><p>Dokumen berstatus <b><?= e($sLbl) ?></b> tidak bisa diubah.</p></div></div>
<?php require __DIR__ . '/_footer.php'; exit; endif; ?>

<?php if ($errors): ?>
  <div class="flash flash-err" data-tetap="1"><?php foreach ($errors as $er) echo '• ' . e($er) . '<br>'; ?></div>
<?php endif; ?>

<?php if ($selesai): ?>
  <div class="card" style="border-left:4px solid var(--amber)">
    <b>Dokumen ini sudah selesai — stoknya sudah berpindah.</b>
    <p class="mut" style="margin:6px 0 0">
      <?php if ($adaJejak): ?>
        Mengubah jumlah, barang, atau lokasi akan <b>membalikkan stok</b> persis seperti saat diterapkan, lalu menerapkannya ulang
        dengan isi baru — dalam satu langkah. Ditolak bila barangnya sudah keluar lagi dari lokasi tujuan.
      <?php else: ?>
        Dokumen ini selesai sebelum pencatat mutasi dipasang, jadi stoknya tidak bisa dibalikkan: yang bisa diubah hanya
        data pengiriman (resi, ekspedisi, penerima, catatan). Jumlah dan lokasi dibetulkan lewat dokumen baru atau penyesuaian stok.
      <?php endif; ?>
    </p>
  </div>
<?php elseif ($dok['status'] === 'dikirim'): ?>
  <div class="card" style="border-left:4px solid var(--primary)">
    <b>Dokumen sedang dalam perjalanan.</b>
    <p class="mut" style="margin:6px 0 0">Stok belum berpindah, jadi perubahan hanya menyentuh dokumennya. Bila surat jalannya sudah dicetak
      (<?= (int) $dok['print_count'] ?>×), cetak ulang setelah mengubah.</p>
  </div>
<?php endif; ?>

<form method="post" id="frmUbahPp">
  <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
  <input type="hidden" name="id" value="<?= (int) $id ?>">

  <div class="card">
    <h3>Rute</h3>
    <div class="sub">Jenis <b><?= e($jLbl) ?></b> tidak bisa diganti — buat dokumen baru bila jenisnya salah.</div>
    <div class="form-grid">
      <div class="field">
        <label for="fAsal">Dari (asal)</label>
        <select name="asal" id="fAsal" <?= $kunciStok ? 'disabled' : '' ?>>
          <?= opsi_lokasi($rute['asal'], (string) $asalNilai, $gudangList, $rakList, $tokoList, $supplierList, $penerimaList) ?>
        </select>
        <input name="asal_teks" id="fAsalTeks" value="<?= e($v('asal_teks')) ?>" placeholder="nama pihak asal" style="margin-top:6px" <?= $kunciStok ? 'disabled' : '' ?>>
      </div>
      <div class="field">
        <label for="fTujuan">Ke (tujuan)</label>
        <select name="tujuan" id="fTujuan" <?= $kunciStok ? 'disabled' : '' ?>>
          <?= opsi_lokasi($rute['tujuan'], (string) $tujuanNilai, $gudangList, $rakList, $tokoList, $supplierList, $penerimaList) ?>
        </select>
        <input name="tujuan_teks" id="fTujuanTeks" value="<?= e($v('tujuan_teks')) ?>" placeholder="nama penerima" style="margin-top:6px" <?= $kunciStok ? 'disabled' : '' ?>>
      </div>
      <?php if (jenis_boleh_transit($dok['jenis'])): ?>
      <div class="field span-all">
        <label>Singgah di gudang transit <span class="mut2">(opsional, urut)</span></label>
        <div class="form-grid" style="gap:8px 12px">
          <?php for ($i = 0; $i < MAKS_TITIK_TRANSIT; $i++): ?>
            <select name="transit_id[]" aria-label="Transit <?= $i + 1 ?>">
              <option value="">— transit <?= $i + 1 ?>: tidak ada —</option>
              <?php foreach ($gudangList as $g): ?>
                <option value="<?= (int) $g['id'] ?>" <?= ($transitIsi[$i] ?? 0) === (int) $g['id'] ? 'selected' : '' ?>><?= e($g['nama']) ?> (<?= e($g['kode']) ?>)</option>
              <?php endforeach; ?>
            </select>
          <?php endfor; ?>
        </div>
        <?php if (array_filter($titik, fn($t) => $t['tiba_at'] || $t['berangkat_at'])): ?>
          <small class="mut2">Titik yang sudah mencatat tiba/berangkat tidak bisa dihapus.</small>
        <?php endif; ?>
      </div>
      <?php endif; ?>
      <div class="field">
        <label for="fTgl">Tanggal dokumen</label>
        <input type="date" name="tanggal" id="fTgl" value="<?= e(substr($v('tanggal'), 0, 10)) ?>">
      </div>
    </div>
  </div>

  <div class="card">
    <h3>Barang</h3>
    <div class="sub"><?= $selesai ? 'Jumlah = yang benar-benar berpindah. ' : '' ?>Isi <b>0</b> untuk menghapus baris.
      <?= $kunciStok ? 'Baris tidak bisa diubah untuk dokumen ini.' : '' ?>
      <?= array_filter($detail, fn($dd) => !empty($dd['kardus_id'])) ? '<br>Baris isi kardus tetap berada di kardusnya; baris baru masuk sebagai barang tanpa kardus.' : '' ?></div>
    <div class="tbl-wrap"><table class="tbl-kartu" id="tblBaris">
      <thead><tr><th>Barang</th><th>Jumlah</th><th>Batch</th><th>Kedaluwarsa</th><th>Keterangan</th></tr></thead>
      <tbody>
      <?php
      $barisIsi = [];
      if ($_SERVER['REQUEST_METHOD'] === 'POST' && $errors) {
          foreach ((array) ($_POST['qty'] ?? []) as $i => $q) {
              $barisIsi[] = ['id' => (int) ($_POST['detail_id'][$i] ?? 0), 'barang_id' => (int) ($_POST['barang_id'][$i] ?? 0),
                             'qty' => $q, 'nomor_batch' => $_POST['nomor_batch'][$i] ?? '', 'tanggal_expired' => $_POST['tanggal_expired'][$i] ?? '',
                             'keterangan' => $_POST['ket_item'][$i] ?? ''];
          }
      } else {
          foreach ($detail as $d) {
              $ket = (string) ($d['keterangan'] ?? '');
              $ket = trim(preg_replace('/(\s*\|\s*)?Batch: .*$/u', '', $ket));
              $barisIsi[] = ['id' => (int) $d['id'], 'barang_id' => (int) $d['barang_id'],
                             'qty' => $selesai && $d['qty_diterima'] !== null ? (int) $d['qty_diterima'] : (int) $d['qty'],
                             'nomor_batch' => $d['nomor_batch'], 'tanggal_expired' => $d['tanggal_expired'], 'keterangan' => $ket];
          }
      }
      $dis = $kunciStok ? 'disabled' : '';
      foreach ($barisIsi as $b): ?>
        <tr>
          <td data-label="Barang">
            <input type="hidden" name="detail_id[]" value="<?= (int) $b['id'] ?>">
            <select name="barang_id[]" class="sel-tbl" <?= $dis ?> style="min-width:220px">
              <?php foreach ($barangList as $bb): ?>
                <option value="<?= (int) $bb['id'] ?>" <?= (int) $bb['id'] === $b['barang_id'] ? 'selected' : '' ?>><?= e($bb['nama']) ?> (<?= e($bb['kode']) ?>)</option>
              <?php endforeach; ?>
            </select></td>
          <td data-label="Jumlah"><input type="number" name="qty[]" class="inp-tbl" value="<?= e((string) $b['qty']) ?>" min="0" max="<?= MAKS_QTY_BARIS ?>" step="1" inputmode="numeric" required <?= $dis ?> style="width:100px"></td>
          <td data-label="Batch"><input name="nomor_batch[]" class="inp-tbl" value="<?= e((string) $b['nomor_batch']) ?>" maxlength="50" placeholder="FEFO bila kosong" <?= $dis ?> style="width:140px"></td>
          <td data-label="Kedaluwarsa"><input type="date" name="tanggal_expired[]" class="inp-tbl" value="<?= e((string) $b['tanggal_expired']) ?>" <?= $dis ?>></td>
          <td data-label="Keterangan"><input name="ket_item[]" class="inp-tbl" value="<?= e((string) $b['keterangan']) ?>" maxlength="255" <?= $dis ?>></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table></div>
    <?php if (!$kunciStok): ?>
      <button type="button" class="btn btn-ghost btn-sm" style="margin-top:10px" onclick="tambahBarisUbah()">+ Tambah Baris</button>
    <?php endif; ?>
  </div>

  <div class="card">
    <h3>Data pengiriman</h3>
    <div class="form-grid">
      <div class="field"><label>Nomor resi</label><input name="no_resi" value="<?= e($v('no_resi')) ?>" maxlength="60"></div>
      <div class="field"><label>Ekspedisi</label><input name="ekspedisi" value="<?= e($v('ekspedisi')) ?>" maxlength="60"></div>
      <div class="field"><label>Kendaraan</label><input name="kendaraan" value="<?= e($v('kendaraan')) ?>" maxlength="60"></div>
      <div class="field"><label>Nomor polisi</label><input name="no_polisi" value="<?= e($v('no_polisi')) ?>" maxlength="20"></div>
      <div class="field"><label>Pengirim / petugas</label><input name="pengirim" value="<?= e($v('pengirim')) ?>" maxlength="100"></div>
      <div class="field"><label>Nama penerima</label><input name="penerima" value="<?= e($v('penerima')) ?>" maxlength="100"></div>
      <div class="field span-all"><label>Untuk program / keperluan</label><input name="untuk_program" value="<?= e($v('untuk_program')) ?>" maxlength="150"></div>
      <div class="field span-all"><label>Catatan</label><input name="catatan" value="<?= e($v('catatan')) ?>" maxlength="255"></div>
    </div>
    <?php if ($errors): ?>
      <div class="flash flash-err" data-tetap="1" style="margin:4px 0 10px" id="galatBawah">
        <b>Belum tersimpan:</b><br><?php foreach ($errors as $er) echo '• ' . e($er) . '<br>'; ?>
      </div>
    <?php endif; ?>
    <div style="display:flex;gap:9px;flex-wrap:wrap;margin-top:6px">
      <button class="btn btn-primary" type="submit"><svg aria-hidden="true"><use href="#i-simpan"></use></svg> Simpan Perubahan</button>
      <a class="btn btn-ghost" href="perpindahan.php">Batal</a>
    </div>
  </div>
</form>

<script>
/* Kotak nama bebas hanya tampil bila pilihannya "ketik nama". */
(function(){
  function atur(sel, inp){ inp.style.display = (sel.value === 'eksternal' || sel.value === 'penerima') ? '' : 'none'; }
  [['fAsal','fAsalTeks'],['fTujuan','fTujuanTeks']].forEach(function(p){
    var s = document.getElementById(p[0]), i = document.getElementById(p[1]);
    if (!s || !i) return; atur(s, i); s.addEventListener('change', function(){ atur(s, i); });
  });
})();
function tambahBarisUbah(){
  var tb = document.querySelector('#tblBaris tbody'), tr = tb.lastElementChild ? tb.lastElementChild.cloneNode(true) : null;
  if (!tr) return;
  tr.querySelector('input[name="detail_id[]"]').value = '0';
  tr.querySelector('input[name="qty[]"]').value = '1';
  tr.querySelector('input[name="nomor_batch[]"]').value = '';
  tr.querySelector('input[name="tanggal_expired[]"]').value = '';
  tr.querySelector('input[name="ket_item[]"]').value = '';
  tb.appendChild(tr); tr.querySelector('select').focus();
}
</script>
<?php require __DIR__ . '/_footer.php'; ?>
