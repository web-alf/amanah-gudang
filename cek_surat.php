<?php
/**
 * AMANAH — Cek Surat Jalan
 *
 * Tujuan pemindaian QR yang tercetak di setiap surat jalan. Alur di lapangan:
 * barang tiba di gudang atau toko → petugas memindai QR di lembar surat
 * jalannya → halaman ini terbuka dan langsung memperlihatkan DAFTAR BARANG
 * yang seharusnya ada di dalam kiriman itu.
 *
 * MENGAPA HALAMAN TERSENDIRI, bukan menumpang Scan Resi Paket:
 *   Scan Resi Paket memang urusan toko — paket marketplace tiba di toko dan
 *   resinya dicocokkan. Surat jalan lain sifatnya: ia beredar antara gudang,
 *   toko, supplier, dan penerima amanah, dan yang memindainya bisa siapa pun
 *   di rantai itu. Menggabungkan keduanya berarti salah satu peran harus
 *   diberi menu yang bukan urusannya.
 *
 * MENGAPA TIDAK LANGSUNG KE penerimaan.php:
 *   Penerimaan hanya melayani dokumen yang tujuannya gudang/rak/toko milik
 *   yang login. Padahal memindai untuk MELIHAT isi kiriman berguna juga bagi
 *   pengirimnya, bagi pimpinan yang memantau, dan untuk surat jalan
 *   penyaluran ke penerima amanah — yang tidak punya akun sama sekali.
 *   Jadi halaman ini hanya membaca; tombol konfirmasi penerimaan tetap
 *   mengantar ke penerimaan.php, satu-satunya tempat stok boleh berubah.
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/_lib.php';
require_login();

$u = current_user();

/* Kode bisa datang dari GET (dipindai kamera ponsel, yang langsung membuka
   URL-nya) atau POST (diketik / ditembak scanner USB di halaman ini).
   POST-nya dijaga CSRF seperti formulir lain — tanpa ini situs luar bisa
   menyuruh peramban petugas mencatat pemindaian palsu ke resi_scan_log. */
if ($_SERVER['REQUEST_METHOD'] === 'POST') { wajib_csrf($_POST['csrf'] ?? null); }
$kode = trim((string) ($_POST['kode'] ?? $_GET['k'] ?? ''));

/* Pemindai ponsel menyerahkan URL utuh, bukan nomornya. Kalau yang masuk
   berbentuk URL, nomornya diambil dari parameter k — tanpa ini, petugas yang
   memindai lewat aplikasi kamera lalu menempelkan hasilnya ke kotak di bawah
   akan selalu mendapat "tidak ditemukan", dan penyebabnya tidak kelihatan. */
if ($kode !== '' && preg_match('~^https?://~i', $kode)) {
    $tanya = parse_url($kode, PHP_URL_QUERY);
    if ($tanya) {
        parse_str($tanya, $bagian);
        if (!empty($bagian['k'])) { $kode = trim((string) $bagian['k']); }
    }
}

$dok = null; $detail = []; $pesan = ''; $bukanMilikSaya = false;

/* Label kardus memuat kode KRD-…: pindaiannya diarahkan ke detail kardus,
   hak aksesnya diperiksa di sana. */
if (preg_match('/^KRD-\d{8}-\d{3,}$/i', $kode)) {
    header('Location: kardus_detail.php?k=' . rawurlencode(strtoupper($kode))); exit;
}

if ($kode !== '') {
    /* Dicocokkan ke TIGA nomor: surat jalan, nomor dokumen, dan nomor resi.
       Orang membaca lembar kertas, bukan skema basis data — dan ketiga nomor
       itu tercetak di lembar yang sama. Memaksa hanya satu yang cocok berarti
       petugas mengetik nomor yang benar-benar ada di tangannya lalu diberi
       tahu bahwa nomornya salah. */
    $st = $pdo->prepare(
        "SELECT p.*, u.nama AS pembuat, ua.nama AS penyetuju, ud.nama AS penerima_nama
         FROM perpindahan p
         LEFT JOIN users u  ON u.id  = p.user_id
         LEFT JOIN users ua ON ua.id = p.approved_by
         LEFT JOIN users ud ON ud.id = p.diterima_oleh
         WHERE p.no_surat_jalan = ? OR p.no_perpindahan = ? OR p.no_resi = ?
         ORDER BY p.id DESC LIMIT 1"
    );
    $st->execute([$kode, $kode, $kode]);
    $dok = $st->fetch() ?: null;

    if (!$dok) {
        $pesan = 'Nomor ini tidak ditemukan. Periksa kembali ketikannya, atau pindai ulang QR-nya.';
    } else {
        // ------------------------------------------------ lingkup melihat
        /* Siapa boleh MELIHAT dokumen ini. Dipisahkan dari "siapa boleh
           menerimanya": melihat isi kiriman yang menyangkut lokasi sendiri
           tidak mengubah apa pun, sedangkan menerima menambah stok. */
        /* Satu pemeriksaan bersama — aturan yang sama dengan daftar dokumen
           dan halaman cetaknya. Sebelumnya aturan ini ditulis ulang di sini
           dengan empat percabangan tangan, dan salah satunya melepas
           Pimpinan ke seluruh dokumen lembaga. Aturan yang ditulis di tiga
           tempat akan berbeda di salah satunya; ini yang berbeda. */
        $boleh = boleh_lihat_perpindahan($pdo, (int) $dok['id']);

        if (!$boleh) {
            /* Sengaja TIDAK memakai 403 telanjang. Petugas gudang yang
               memindai surat jalan milik gudang lain perlu tahu bahwa
               dokumennya ada dan siapa yang harus menerimanya — bukan layar
               "akses ditolak" yang membuatnya memindai berulang kali. Yang
               ditahan adalah isinya: daftar barang tidak dibaca sama sekali. */
            $bukanMilikSaya = true;
            $dok = null;
            $pesan = 'Surat jalan ini ada, tetapi bukan untuk lokasi yang Anda pegang. '
                   . 'Hubungi Super Admin bila kirimannya memang tiba di tempat Anda.';
        } else {
            $stD = $pdo->prepare(
                "SELECT d.*, b.nama AS nama_barang, b.kode AS kode_barang,
                        b.merek, b.ukuran, b.satuan
                 FROM perpindahan_detail d
                 JOIN barang b ON b.id = d.barang_id
                 WHERE d.perpindahan_id = ? ORDER BY d.id"
            );
            $stD->execute([(int) $dok['id']]);
            $detail = $stD->fetchAll();
        }
    }
}

// Catat pemindaiannya untuk jejak audit. Gagal mencatat tidak boleh
// menggagalkan halaman — petugas tetap perlu melihat isi kirimannya.
if ($kode !== '') {
    try {
        $pdo->prepare(
            "INSERT INTO resi_scan_log (no_resi, sumber, hasil, perpindahan_id, user_id, ip_address)
             VALUES (?,?,?,?,?,?)"
        )->execute([
            mb_substr($kode, 0, 60),
            in_array($_POST['sumber'] ?? '', ['kamera', 'scanner', 'manual'], true)
                ? $_POST['sumber'] : (isset($_GET['k']) ? 'kamera' : 'manual'),
            $dok ? 'cocok' : 'tidak_ditemukan',
            $dok['id'] ?? null, $u['id'], $_SERVER['REMOTE_ADDR'] ?? null,
        ]);
    } catch (Throwable $e) { error_log('[AMANAH] cek surat: ' . $e->getMessage()); }
}

// --------------------------------------------------------------- tampilan
$asal = $tujuan = ''; $jLbl = $sLbl = $sCls = ''; $totalQty = 0; $totalTerima = 0;
$bisaTerima = false;
if ($dok) {
    [$jLbl]        = label_jenis($dok['jenis']);
    [$sLbl, $sCls] = label_status($dok['status']);
    $asal   = nama_lokasi($pdo, $dok['asal_tipe'],   $dok['asal_id']   ? (int) $dok['asal_id']   : null, $dok['asal_teks']);
    $tujuan = nama_lokasi($pdo, $dok['tujuan_tipe'], $dok['tujuan_id'] ? (int) $dok['tujuan_id'] : null, $dok['tujuan_teks']);
    $totalQty    = array_sum(array_map(fn($d) => (int) $d['qty'], $detail));
    $totalTerima = array_sum(array_map(fn($d) => (int) ($d['qty_diterima'] ?? 0), $detail));

    /* Tombol "Catat Penerimaan" hanya muncul bila dokumennya BETUL-BETUL
       menunggu diterima oleh pihak ini. Menampilkannya di luar keadaan itu
       hanya menghasilkan galat setelah diklik. */
    $bolehPeran = is_super() || terikat_satu_gudang() || is_toko();
    $keTujuanSaya = is_toko()
        ? ($dok['tujuan_tipe'] === 'toko' && in_array((int) $dok['tujuan_id'], toko_saya($pdo), true))
        : ($dok['tujuan_tipe'] === 'rak'    && in_array((int) $dok['tujuan_id'], rak_saya($pdo), true))
       || ($dok['tujuan_tipe'] === 'gudang' && in_array((int) $dok['tujuan_id'], gudang_saya($pdo), true));
    $bisaTerima = $dok['status'] === 'dikirim' && $bolehPeran && ($keTujuanSaya || is_super());
}

$PAGE_TITLE = 'Cek Surat Jalan'; $ACTIVE = 'ceksurat';
require __DIR__ . '/_header.php';
?>
<div class="page-head">
  <div><h1>Cek Surat Jalan</h1>
    <p>Pindai QR di lembar surat jalan — daftar barang kirimannya langsung terlihat</p></div>
  <a class="btn btn-ghost" href="penerimaan.php"><svg class="ikon" aria-hidden="true"><use href="#i-masuk"></use></svg> Penerimaan Barang</a>
</div>

<div class="card">
  <h3>Nomor Surat Jalan</h3>
  <div class="sub">Pindai QR, tembak dengan scanner USB, atau ketik nomornya</div>

  <form method="post" id="frmCek">
    <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
    <input type="hidden" name="sumber" id="sumber" value="manual">
    <div class="field">
      <label for="inpKode">Nomor Surat Jalan / Dokumen / Resi</label>
      <input name="kode" id="inpKode" value="<?= e($kode) ?>" autofocus autocomplete="off"
             placeholder="SJ-20260101-0001"
             style="font-size:17px;font-family:'Courier New',monospace;letter-spacing:1px;padding:14px">
    </div>
    <div class="tombol-baris">
      <button class="btn btn-primary" type="submit"><svg class="ikon" aria-hidden="true"><use href="#i-cari"></use></svg> Cari</button>
      <button class="btn btn-ghost" type="button" id="btnKamera" onclick="toggleKamera()"><svg class="ikon" aria-hidden="true"><use href="#i-qr"></use></svg> Pindai QR</button>
      <?php if ($kode !== ''): ?><a class="btn btn-ghost" href="cek_surat.php">Bersihkan</a><?php endif; ?>
    </div>
  </form>

  <div id="areaKamera" style="display:none;margin-top:14px">
    <video id="video" playsinline muted
           style="width:100%;max-height:300px;background:#000;border-radius:11px;object-fit:cover"></video>
    <p id="statusKamera" class="mut-sm" style="margin-top:8px"></p>
  </div>

  <div class="kotak-info">
    <b class="txt">Kamera</b> hanya bisa dipakai lewat <code>localhost</code> atau HTTPS — itu aturan
    browser, bukan setelan aplikasi. Bila halaman dibuka memakai alamat IP biasa, pakai scanner USB
    atau ketik nomornya.
    <br><b class="txt">Ponsel</b> tidak perlu halaman ini: kamera bawaan yang memindai QR di surat
    jalan akan langsung membuka daftar barangnya.
  </div>
</div>

<?php if ($pesan !== ''): ?>
  <div class="card">
    <div class="kosong-blok">
      <svg class="ikon-besar" aria-hidden="true"><use href="#i-<?= $bukanMilikSaya ? 'kunci' : 'cari' ?>"></use></svg>
      <p><?= e($pesan) ?></p>
      <?php if ($bukanMilikSaya): ?>
        <p class="mut-sm">Nomor yang dicari: <code><?= e($kode) ?></code></p>
      <?php endif; ?>
    </div>
  </div>
<?php endif; ?>

<?php if ($dok): ?>
<div class="card">
  <div class="sj-kepala">
    <div>
      <div class="mut-sm">Surat Jalan</div>
      <h3 class="sj-nomor"><?= e($dok['no_surat_jalan'] ?: $dok['no_perpindahan']) ?></h3>
      <div class="sj-tanda">
        <span class="tag <?= e($sCls) ?>"><?= e($sLbl) ?></span>
        <span class="tag t-gray"><?= e($jLbl) ?></span>
      </div>
    </div>
    <div class="sj-aksi">
      <?php if ($bisaTerima): ?>
        <a class="btn btn-primary" href="penerimaan.php?id=<?= (int) $dok['id'] ?>">
          <svg class="ikon" aria-hidden="true"><use href="#i-cek"></use></svg> Catat Penerimaan</a>
      <?php elseif ($dok['status'] === 'selesai'): ?>
        <span class="tag t-green">Sudah diterima<?= $dok['penerima_nama'] ? ' — ' . e($dok['penerima_nama']) : '' ?></span>
      <?php elseif ($dok['status'] === 'dikirim'): $posCek = posisi_kiriman($pdo, $dok); ?>
        <span class="tag <?= e($posCek['kelas']) ?>">📍 <?= e($posCek['teks']) ?></span>
      <?php endif; ?>
      <a class="btn btn-ghost" href="lacak.php?id=<?= (int) $dok['id'] ?>">
        <svg class="ikon" aria-hidden="true"><use href="#i-truck"></use></svg> Lacak Kiriman</a>
      <?php if ($dok['no_surat_jalan']): ?>
        <a class="btn btn-ghost" href="surat_jalan.php?id=<?= (int) $dok['id'] ?>" target="_blank" rel="noopener">
          <svg class="ikon" aria-hidden="true"><use href="#i-cetak"></use></svg> Lembar Cetak</a>
      <?php endif; ?>
    </div>
  </div>

  <div class="sj-fakta">
    <div><span>Dari</span><b><?= e($asal) ?></b></div>
    <div><span>Kepada</span><b><?= e($tujuan) ?></b></div>
    <div><span>Tanggal</span><b><?= e(tgl_id($dok['tanggal'], 'panjang')) ?></b></div>
    <div><span>No. Dokumen</span><b><?= e($dok['no_perpindahan']) ?></b></div>
    <?php if ($dok['no_resi']): ?>
      <div><span>No. Resi</span><b><?= e($dok['no_resi']) ?><?= $dok['ekspedisi'] ? ' · ' . e($dok['ekspedisi']) : '' ?></b></div>
    <?php endif; ?>
    <?php if ($dok['kendaraan'] || $dok['no_polisi']): ?>
      <div><span>Kendaraan</span><b><?= e(trim(($dok['kendaraan'] ?? '') . ' ' . ($dok['no_polisi'] ? '(' . $dok['no_polisi'] . ')' : ''))) ?></b></div>
    <?php endif; ?>
    <?php if ($dok['untuk_program']): ?>
      <div><span>Keperluan</span><b><?= e($dok['untuk_program']) ?></b></div>
    <?php endif; ?>
    <div><span>Disetujui</span><b><?= e($dok['penyetuju'] ?: '—') ?></b></div>
  </div>
</div>

<div class="card">
  <h3>Daftar Barang dalam Kiriman</h3>
  <div class="sub"><?= count($detail) ?> jenis barang · <?= angka($totalQty) ?> unit menurut surat jalan</div>

  <div class="scrollx">
  <table class="tbl-kartu tbl-manifes">
    <thead><tr>
      <th class="m-no">No</th>
      <th class="m-nama">Barang</th>
      <th class="m-batch">No. Batch</th>
      <th class="m-exp">Expired</th>
      <th class="m-qty">Dikirim</th>
      <?php if ($totalTerima > 0): ?><th class="m-qty">Diterima</th><?php endif; ?>
      <th class="m-ket">Keterangan</th>
    </tr></thead>
    <tbody>
    <?php if (!$detail): ?>
      <tr><td colspan="7" class="empty">Dokumen ini belum punya rincian barang.</td></tr>
    <?php else: foreach ($detail as $i => $d):
      $kurang = $d['qty_diterima'] !== null && (int) $d['qty_diterima'] < (int) $d['qty']; ?>
      <tr>
        <td data-label="No" class="m-no"><?= $i + 1 ?></td>
        <td data-label="Barang">
          <b><?= e($d['nama_barang']) ?></b>
          <br><small class="mut"><?= e($d['kode_barang']) ?><?php
            $ket = trim(($d['merek'] ?? '') . ' ' . ($d['ukuran'] ? '· ' . $d['ukuran'] : ''));
            echo $ket !== '' ? ' · ' . e($ket) : ''; ?></small>
        </td>
        <td data-label="No. Batch"><code><?= e($d['nomor_batch'] ?: '—') ?></code></td>
        <td data-label="Expired"><?= $d['tanggal_expired'] ? e(tgl_id($d['tanggal_expired'])) : '—' ?></td>
        <td data-label="Dikirim"><b><?= angka($d['qty']) ?></b> <?= e($d['satuan']) ?></td>
        <?php if ($totalTerima > 0): ?>
          <td data-label="Diterima">
            <?php if ($d['qty_diterima'] === null): ?>—
            <?php else: ?>
              <b class="<?= $kurang ? 't-red' : '' ?>"><?= angka($d['qty_diterima']) ?></b> <?= e($d['satuan']) ?>
              <?php if ($kurang): ?><br><small class="mut">kurang <?= angka((int) $d['qty'] - (int) $d['qty_diterima']) ?></small><?php endif; ?>
            <?php endif; ?>
          </td>
        <?php endif; ?>
        <td data-label="Keterangan"><small class="mut"><?= e($d['selisih_catatan'] ?: ($d['keterangan'] ?: '')) ?></small></td>
      </tr>
    <?php endforeach; endif; ?>
    </tbody>
    <?php if ($detail): ?>
    <tfoot><tr>
      <td colspan="4" class="r"><b>TOTAL</b></td>
      <td><b><?= angka($totalQty) ?></b> unit</td>
      <?php if ($totalTerima > 0): ?><td><b><?= angka($totalTerima) ?></b> unit</td><?php endif; ?>
      <td></td>
    </tr></tfoot>
    <?php endif; ?>
  </table>
  </div>

  <?php if ($dok['catatan']): ?>
    <div class="kotak-info" style="margin-top:12px"><b class="txt">Catatan pengirim:</b> <?= e($dok['catatan']) ?></div>
  <?php endif; ?>
  <?php if ($dok['catatan_terima']): ?>
    <div class="kotak-info" style="margin-top:8px"><b class="txt">Catatan penerima:</b> <?= e($dok['catatan_terima']) ?></div>
  <?php endif; ?>
</div>
<?php endif; ?>

<script>
var frm = document.getElementById('frmCek');
var inp = document.getElementById('inpKode');

/* Scanner USB berperilaku seperti keyboard yang mengetik sangat cepat lalu
   menekan Enter. Ketikan tangan tidak pernah sedekat itu jaraknya, jadi jeda
   antar tombol dipakai untuk membedakan keduanya — dan sumbernya dicatat
   apa adanya, bukan diklaim "manual" untuk semua. */
var jedaTerakhir = 0, cepat = 0;
inp.addEventListener('keydown', function (ev) {
  var kini = Date.now(), jeda = kini - jedaTerakhir;
  jedaTerakhir = kini;
  if (ev.key === 'Enter') {
    document.getElementById('sumber').value = cepat >= 4 ? 'scanner' : 'manual';
    cepat = 0;
    return;
  }
  if (ev.key.length === 1) { cepat = jeda < 35 ? cepat + 1 : 0; }
});

/* ---------------------------------------------------------------- kamera
   Memakai BarcodeDetector bawaan browser — tanpa pustaka luar, supaya
   komputer gudang yang sedang tanpa internet tetap bisa memindai. */
var stream = null, loopId = null;

function ambilNomor(nilai) {
  /* QR berisi URL lengkap. Yang dibutuhkan kotak isian hanya nomornya, jadi
     parameter k dipetik di sini. Kalau yang terpindai bukan URL (mis. barcode
     resi ekspedisi), nilainya dipakai apa adanya. */
  nilai = (nilai || '').trim();
  if (!/^https?:\/\//i.test(nilai)) { return nilai; }
  try {
    var k = new URL(nilai).searchParams.get('k');
    return k ? k.trim() : nilai;
  } catch (e) { return nilai; }
}

async function toggleKamera() {
  var area   = document.getElementById('areaKamera');
  var status = document.getElementById('statusKamera');
  var btn    = document.getElementById('btnKamera');

  if (stream) { hentikanKamera(); return; }

  area.style.display = 'block';
  if (!window.isSecureContext) {
    status.textContent = '⚠️ Browser memblokir kamera karena halaman ini bukan localhost/HTTPS. '
      + 'Gunakan scanner USB atau ketik nomornya.';
    return;
  }
  if (!('BarcodeDetector' in window)) {
    status.textContent = '⚠️ Browser ini belum bisa membaca QR lewat kamera. Pakai Chrome/Edge terbaru, '
      + 'scanner USB, atau ketik nomornya.';
    return;
  }

  try {
    status.textContent = 'Meminta izin kamera…';
    stream = await navigator.mediaDevices.getUserMedia({ video: { facingMode: 'environment' } });
    var video = document.getElementById('video');
    video.srcObject = stream;
    await video.play();

    btn.textContent = '⏹️ Hentikan';
    status.textContent = 'Arahkan kamera ke QR pada surat jalan…';

    // Dibatasi ke qr_code saja: barcode lain di kardus (EAN produk, label
    // ekspedisi) sering ikut terbaca lebih dulu dan membuka dokumen keliru.
    var detector = new BarcodeDetector({ formats: ['qr_code'] });
    loopId = setInterval(async function () {
      try {
        var hasil = await detector.detect(video);
        if (hasil && hasil.length) {
          var nomor = ambilNomor(hasil[0].rawValue);
          if (nomor) {
            document.getElementById('sumber').value = 'kamera';
            inp.value = nomor;
            status.textContent = '✅ Terbaca: ' + nomor;
            hentikanKamera();
            frm.submit();
          }
        }
      } catch (e) { /* satu frame gagal — coba frame berikutnya */ }
    }, 300);
  } catch (e) {
    status.textContent = '⚠️ Tidak bisa membuka kamera: ' + e.message
      + '. Gunakan scanner USB atau ketik nomornya.';
    hentikanKamera();
  }
}

function hentikanKamera() {
  if (loopId) { clearInterval(loopId); loopId = null; }
  if (stream) { stream.getTracks().forEach(function (t) { t.stop(); }); stream = null; }
  document.getElementById('btnKamera').textContent = '📷 Pindai QR';
}

window.addEventListener('beforeunload', hentikanKamera);
</script>
<?php require __DIR__ . '/_footer.php'; ?>
