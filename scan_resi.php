<?php
/**
 * AMANAH — Scan Resi Barang
 *
 * Menerima nomor resi dari tiga sumber:
 *   1. Scanner barcode USB  — bekerja seperti keyboard, terdeteksi otomatis
 *   2. Kamera HP / laptop   — memakai BarcodeDetector bawaan browser
 *   3. Ketik manual         — selalu tersedia sebagai cadangan
 *
 * Nomor yang discan dicocokkan ke kiriman supplier dan dokumen
 * perpindahan, lalu dicatat di resi_scan_log untuk jejak audit.
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/_lib.php';
require_login();
/* Halaman ini dipakai sisi TOKO (paket tiba di toko, resinya discan untuk
   menemukan dokumen kirimannya) dan Super Admin. Yang ditolak hanya
   Supplier: ia mengirim paket, bukan menerimanya. */
if (is_supplier()) { header('Location: dasbor_supplier.php'); exit; }

$u       = current_user();
$canEdit = boleh_edit();

$resi      = '';
$sumber    = 'manual';
$kiriman   = null;
$perpindahanCocok = [];
$sudahCari = false;

// ---------------------------------------------------------------- AKSI
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    wajib_csrf($_POST['csrf'] ?? null);
    $aksi = $_POST['aksi'] ?? 'cari';

    // Tandai kiriman sudah tiba di gudang.
    if ($aksi === 'tandai_tiba') {
        if (!$canEdit) { http_response_code(403); die('403'); }
        $kid = (int) ($_POST['kiriman_id'] ?? 0);
        try {
            $st = $pdo->prepare("UPDATE pengiriman_supplier SET status = 'tiba'
                                 WHERE id = ? AND status IN ('dijadwalkan','dikirim')");
            $st->execute([$kid]);
            catat_aktivitas($pdo, 'update', 'kiriman_supplier', $kid, 'Tandai kiriman tiba di gudang');
            set_flash('ok', $st->rowCount()
                ? 'Kiriman ditandai sudah tiba di gudang.'
                : 'Status kiriman tidak berubah (mungkin sudah diproses).');
        } catch (Throwable $e) {
            set_flash('err', pesan_error_aman($e, 'tandai tiba'));
        }
        header('Location: scan_resi.php?resi=' . urlencode($_POST['no_resi'] ?? '')); exit;
    }

    $resi   = trim($_POST['no_resi'] ?? '');
    $sumber = in_array($_POST['sumber'] ?? '', ['kamera','scanner','manual'], true) ? $_POST['sumber'] : 'manual';
} elseif (isset($_GET['resi'])) {
    $resi = trim($_GET['resi']);
}

// ---------------------------------------------------------------- CARI
if ($resi !== '') {
    $sudahCari = true;
    try {
        $st = $pdo->prepare(
            "SELECT ps.*, s.nama AS nama_supplier, s.telepon AS telepon_supplier,
                    g.nama AS nama_gudang
             FROM pengiriman_supplier ps
             JOIN supplier s ON s.id = ps.supplier_id
             LEFT JOIN gudang g ON g.id = ps.gudang_tujuan_id
             WHERE ps.no_resi = ?
             ORDER BY ps.id DESC LIMIT 1"
        );
        $st->execute([$resi]);
        $kiriman = $st->fetch() ?: null;

        if ($kiriman) {
            $stD = $pdo->prepare(
                "SELECT d.*, b.nama AS nama_barang, b.satuan
                 FROM pengiriman_supplier_detail d
                 LEFT JOIN barang b ON b.id = d.barang_id
                 WHERE d.pengiriman_supplier_id = ?"
            );
            $stD->execute([$kiriman['id']]);
            $kiriman['items'] = $stD->fetchAll();
        }

        // Dokumen perpindahan yang cocok.
        //
        // Dicocokkan ke TIGA nomor sekaligus, bukan hanya no_resi: kertas
        // yang dipegang petugas bisa memuat nomor resi kurir, nomor surat
        // jalan, atau nomor dokumen — dan ketiganya sah untuk dicari.
        // Sebelumnya hanya no_resi yang dicocokkan, sehingga menembak
        // barcode nomor surat jalan selalu berakhir "tidak ditemukan".
        $stP = $pdo->prepare(
            "SELECT id, no_perpindahan, no_surat_jalan, jenis, status, tanggal, no_resi
             FROM perpindahan
             WHERE no_resi = ? OR no_surat_jalan = ? OR no_perpindahan = ?
             ORDER BY id DESC LIMIT 5"
        );
        $stP->execute([$resi, $resi, $resi]);
        $perpindahanCocok = $stP->fetchAll();

        // Catat jejak scan.
        $pdo->prepare(
            "INSERT INTO resi_scan_log (no_resi, sumber, hasil, pengiriman_supplier_id, perpindahan_id, user_id, ip_address)
             VALUES (?,?,?,?,?,?,?)"
        )->execute([
            mb_substr($resi, 0, 60), $sumber,
            ($kiriman || $perpindahanCocok) ? 'cocok' : 'tidak_ditemukan',
            $kiriman['id'] ?? null,
            $perpindahanCocok[0]['id'] ?? null,
            $u['id'],
            $_SERVER['REMOTE_ADDR'] ?? null,
        ]);
    } catch (Throwable $e) {
        error_log('[AMANAH] scan resi: ' . $e->getMessage());
    }
}

// Riwayat scan terakhir.
$riwayat = [];
try {
    $riwayat = $pdo->query(
        "SELECT r.*, u.nama AS petugas
         FROM resi_scan_log r LEFT JOIN users u ON u.id = r.user_id
         ORDER BY r.id DESC LIMIT 12"
    )->fetchAll();
} catch (Throwable $e) { /* tabel belum dimigrasi */ }

$PAGE_TITLE = 'Scan Resi'; $ACTIVE = 'scan';
require __DIR__ . '/_header.php';
?>
<div class="page-head">
  <div><h1>Scan Resi Barang</h1>
    <p>Scanner USB, kamera, atau ketik manual — semuanya masuk ke kotak yang sama</p></div>
  <a class="btn btn-ghost" href="kiriman_supplier.php"><svg aria-hidden="true"><use href="#i-truck"></use></svg> Semua Kiriman</a>
</div>

<div class="dash-grid" style="align-items:start">

  <!-- ============================ INPUT ============================ -->
  <div class="card">
    <h3>Masukkan Nomor Resi</h3>
    <div class="sub">Kotak ini otomatis aktif — tinggal tembak dengan scanner USB</div>

    <form method="post" id="frmScan">
      <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
      <input type="hidden" name="sumber" id="sumber" value="manual">
      <div class="field">
        <label>Nomor Resi / Tracking</label>
        <input name="no_resi" id="inpResi" value="<?= e($resi) ?>" autofocus autocomplete="off"
               placeholder="Tembak barcode atau ketik di sini…"
               style="font-size:17px;font-family:'Courier New',monospace;letter-spacing:1px;padding:14px">
      </div>
      <div style="display:flex;gap:8px;flex-wrap:wrap">
        <button class="btn btn-primary" type="submit"><svg aria-hidden="true"><use href="#i-cari"></use></svg> Cari</button>
        <button class="btn btn-ghost" type="button" id="btnKamera" onclick="toggleKamera()"><svg aria-hidden="true"><use href="#i-scan"></use></svg> Pakai Kamera</button>
        <?php if ($resi !== ''): ?><a class="btn btn-ghost" href="scan_resi.php">Bersihkan</a><?php endif; ?>
      </div>
    </form>

    <div id="areaKamera" style="display:none;margin-top:14px">
      <video id="video" playsinline muted
             style="width:100%;max-height:280px;background:#000;border-radius:11px;object-fit:cover"></video>
      <p id="statusKamera" style="color:var(--muted);font-size:12px;margin-top:8px"></p>
    </div>

    <div id="infoScanner" style="margin-top:14px;padding:11px 13px;background:var(--surface-2);
         border-radius:10px;font-size:12px;color:var(--muted);line-height:1.6">
      <b class="txt">Scanner USB:</b> colok scanner, klik kotak di atas sekali, lalu tembak barcode —
      form akan terkirim sendiri.<br>
      <b class="txt">Kamera:</b> hanya bisa dipakai lewat <code>localhost</code> atau HTTPS.
      Bila dibuka dari komputer lain memakai alamat IP, browser akan memblokir kamera — gunakan scanner USB
      atau ketik manual.
    </div>
  </div>

  <!-- ============================ HASIL ============================ -->
  <div class="card">
    <h3>Hasil Pencarian</h3>
    <div class="sub"><?= $sudahCari ? 'Resi: ' . e($resi) : 'Belum ada resi yang discan' ?></div>

    <?php if (!$sudahCari): ?>
      <div class="empty">Scan atau ketik nomor resi untuk melihat detail kiriman.</div>

    <?php elseif (!$kiriman && !$perpindahanCocok): ?>
      <div class="flash flash-err" style="margin-bottom:12px">
        Nomor resi <code><?= e($resi) ?></code> tidak ditemukan di sistem.
      </div>
      <p style="color:var(--muted);font-size:13px;line-height:1.7">
        Kemungkinan penyebabnya:
      </p>
      <ul style="color:var(--muted);font-size:13px;line-height:1.8;padding-left:20px;margin-top:6px">
        <li>Supplier belum menginput rencana kiriman di portal mereka</li>
        <li>Nomor resi salah ketik atau barcode terbaca sebagian</li>
        <li>Barang datang tanpa pemberitahuan — proses sebagai penerimaan biasa</li>
      </ul>
      <?php if ($canEdit): ?>
        <a class="btn btn-primary" style="margin-top:14px"
           href="perpindahan_form.php"><svg aria-hidden="true"><use href="#i-plus"></use></svg> Buat Penerimaan Manual</a>
      <?php endif; ?>

    <?php else: ?>
      <?php if ($kiriman):
        [$kLbl, $kCls] = label_status_kiriman($kiriman['status']); ?>
        <div style="padding:14px;background:var(--surface-2);border-radius:11px;margin-bottom:14px">
          <div style="display:flex;justify-content:space-between;align-items:center;gap:10px;flex-wrap:wrap;margin-bottom:10px">
            <b style="font-size:15px"><?= e($kiriman['no_pengiriman']) ?></b>
            <span class="tag <?= $kCls ?>"><?= $kLbl ?></span>
          </div>
          <table style="font-size:12.5px">
            <tr><td style="color:var(--muted);width:120px;border:none;padding:4px 0">Supplier</td>
                <td style="border:none;padding:4px 0"><b><?= e($kiriman['nama_supplier']) ?></b>
                <?= $kiriman['telepon_supplier'] ? '<br><small class="mut">' . e($kiriman['telepon_supplier']) . '</small>' : '' ?></td></tr>
            <tr><td style="color:var(--muted);border:none;padding:4px 0">Ekspedisi</td>
                <td style="border:none;padding:4px 0"><?= e($kiriman['ekspedisi'] ?: '—') ?></td></tr>
            <tr><td style="color:var(--muted);border:none;padding:4px 0">Gudang Tujuan</td>
                <td style="border:none;padding:4px 0"><?= e($kiriman['nama_gudang'] ?: '—') ?></td></tr>
            <tr><td style="color:var(--muted);border:none;padding:4px 0">Dikirim</td>
                <td style="border:none;padding:4px 0"><?= e(tgl_id($kiriman['tanggal_kirim'], 'panjang')) ?></td></tr>
            <tr><td style="color:var(--muted);border:none;padding:4px 0">Estimasi Tiba</td>
                <td style="border:none;padding:4px 0"><?= e(tgl_id($kiriman['estimasi_tiba'], 'panjang')) ?></td></tr>
          </table>
        </div>

        <?php if (!empty($kiriman['items'])): ?>
          <div style="overflow-x:auto;margin-bottom:14px"><table class="tbl-kartu">
            <thead><tr><th>Barang</th><th>Batch</th><th>Expired</th><th>Jumlah</th></tr></thead>
            <tbody>
            <?php foreach ($kiriman['items'] as $it): ?>
              <tr>
                <td data-label="Barang"><b><?= e($it['nama_barang'] ?: $it['nama_barang_teks'] ?: '—') ?></b>
                  <?php if (!$it['barang_id']): ?>
                    <br><small style="color:var(--amber)">belum terdaftar di master barang</small>
                  <?php endif; ?>
                </td>
                <td data-label="Batch"><?= e($it['nomor_batch'] ?: '—') ?></td>
                <td data-label="Expired"><?= e(tgl_id($it['tanggal_expired'])) ?></td>
                <td data-label="Jumlah"><b><?= angka($it['qty']) ?></b> <?= e($it['satuan'] ?? '') ?></td>
              </tr>
            <?php endforeach; ?>
            </tbody>
          </table></div>
        <?php endif; ?>

        <?php if ($canEdit): ?>
          <div style="display:flex;gap:8px;flex-wrap:wrap">
            <?php if ($kiriman['perpindahan_id']): ?>
              <a class="btn btn-ghost" href="persetujuan.php?id=<?= (int) $kiriman['perpindahan_id'] ?>">
                Lihat Dokumen Penerimaan
              </a>
            <?php else: ?>
              <a class="btn btn-primary" href="perpindahan_form.php?kiriman=<?= (int) $kiriman['id'] ?>">
                📥 Proses jadi Penerimaan
              </a>
              <?php if (in_array($kiriman['status'], ['dijadwalkan','dikirim'], true)): ?>
                <form method="post" class="inline">
                  <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                  <input type="hidden" name="aksi" value="tandai_tiba">
                  <input type="hidden" name="kiriman_id" value="<?= (int) $kiriman['id'] ?>">
                  <input type="hidden" name="no_resi" value="<?= e($resi) ?>">
                  <button class="btn btn-ghost"><svg aria-hidden="true"><use href="#i-lokasi"></use></svg> Tandai Sudah Tiba</button>
                </form>
              <?php endif; ?>
            <?php endif; ?>
          </div>
        <?php endif; ?>
      <?php endif; ?>

      <?php if ($perpindahanCocok): ?>
        <div style="margin-top:16px;padding-top:14px;border-top:1px solid var(--border)">
          <b style="font-size:13px">Dokumen perpindahan dengan resi ini</b>
          <div style="overflow-x:auto;margin-top:8px"><table class="tbl-kartu">
            <thead><tr><th>No. Dokumen</th><th>Jenis</th><th>Status</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($perpindahanCocok as $pc):
              [$jLbl,,$jCls] = label_jenis($pc['jenis']);
              [$sLbl,$sCls]  = label_status($pc['status']); ?>
              <tr>
                <td data-label="No. Dokumen"><code><?= e($pc['no_perpindahan']) ?></code></td>
                <td data-label="Jenis"><span class="tag <?= $jCls ?>"><?= $jLbl ?></span></td>
                <td data-label="Status"><span class="tag <?= $sCls ?>"><?= $sLbl ?></span></td>
                <td><a class="btn btn-ghost btn-sm" href="persetujuan.php?id=<?= (int) $pc['id'] ?>">Buka</a></td>
              </tr>
            <?php endforeach; ?>
            </tbody>
          </table></div>
        </div>
      <?php endif; ?>
    <?php endif; ?>
  </div>
</div>

<div class="card" style="margin-top:16px">
  <h3>Riwayat Scan Terakhir</h3>
  <div class="sub">Jejak siapa memindai apa dan kapan</div>
  <div class="scrollx"><table class="tbl-kartu">
    <thead><tr><th>Waktu</th><th>Nomor Resi</th><th>Sumber</th><th>Hasil</th><th>Petugas</th></tr></thead>
    <tbody>
    <?php if (!$riwayat): ?>
      <tr><td colspan="5" class="empty">Belum ada riwayat scan.</td></tr>
    <?php else: foreach ($riwayat as $r): ?>
      <tr>
        <td data-label="Waktu"><?= e(tgl_jam_id($r['created_at'])) ?></td>
        <td data-label="Nomor Resi"><a href="scan_resi.php?resi=<?= urlencode($r['no_resi']) ?>"><code><?= e($r['no_resi']) ?></code></a></td>
        <td data-label="Sumber"><span class="tag t-gray"><?= e(ucfirst($r['sumber'])) ?></span></td>
        <td data-label="Hasil"><span class="tag <?= $r['hasil'] === 'cocok' ? 't-green' : 't-red' ?>">
          <?= $r['hasil'] === 'cocok' ? 'Cocok' : 'Tidak ditemukan' ?></span></td>
        <td data-label="Petugas"><?= e($r['petugas'] ?: '—') ?></td>
      </tr>
    <?php endforeach; endif; ?>
    </tbody>
  </table></div>
</div>



<script>
const inp = document.getElementById('inpResi');
const frm = document.getElementById('frmScan');

/* --------------------------------------------------------------------
   Deteksi scanner barcode USB.
   Scanner mengetik jauh lebih cepat dari manusia (< 35 ms per karakter)
   dan diakhiri Enter. Bila pola itu terdeteksi, form dikirim otomatis
   dan sumbernya ditandai 'scanner'.
   -------------------------------------------------------------------- */
let ketikTerakhir = 0, cepat = 0;
inp.addEventListener('keydown', function(ev){
  const now = Date.now();
  const jeda = now - ketikTerakhir;
  ketikTerakhir = now;

  if (ev.key === 'Enter'){
    ev.preventDefault();
    document.getElementById('sumber').value = (cepat >= 4) ? 'scanner' : 'manual';
    cepat = 0;
    if (inp.value.trim()) frm.submit();
    return;
  }
  if (ev.key.length === 1){
    if (jeda < 35) cepat++; else cepat = 0;
  }
});

/* Jaga fokus tetap di kotak resi supaya scanner selalu tersambung. */
document.addEventListener('click', function(ev){
  if (ev.target.closest('a,button,input,select,textarea,label')) return;
  inp.focus();
});

/* --------------------------------------------------------------------
   Kamera — memakai BarcodeDetector bawaan browser (tanpa pustaka luar,
   supaya tetap jalan saat komputer gudang offline).
   -------------------------------------------------------------------- */
let stream = null, loopId = null;

async function toggleKamera(){
  const area   = document.getElementById('areaKamera');
  const status = document.getElementById('statusKamera');
  const btn    = document.getElementById('btnKamera');

  if (stream){ hentikanKamera(); return; }

  if (!window.isSecureContext){
    status.textContent = '⚠️ Kamera diblokir browser karena halaman ini tidak diakses lewat localhost atau HTTPS. Gunakan scanner USB atau ketik manual.';
    area.style.display = 'block';
    return;
  }
  if (!('BarcodeDetector' in window)){
    area.style.display = 'block';
    status.textContent = '⚠️ Browser ini belum mendukung pembacaan barcode lewat kamera. Coba Google Chrome / Edge versi terbaru, atau gunakan scanner USB.';
    return;
  }

  try {
    area.style.display = 'block';
    status.textContent = 'Meminta izin kamera…';
    stream = await navigator.mediaDevices.getUserMedia({
      video: { facingMode: 'environment' }
    });
    const video = document.getElementById('video');
    video.srcObject = stream;
    await video.play();

    btn.textContent = '⏹️ Hentikan Kamera';
    status.textContent = 'Arahkan kamera ke barcode resi…';

    const detector = new BarcodeDetector();
    loopId = setInterval(async function(){
      try {
        const hasil = await detector.detect(video);
        if (hasil && hasil.length){
          const nilai = (hasil[0].rawValue || '').trim();
          if (nilai){
            document.getElementById('sumber').value = 'kamera';
            inp.value = nilai;
            status.textContent = '✅ Terbaca: ' + nilai;
            hentikanKamera();
            frm.submit();
          }
        }
      } catch (e) { /* frame gagal dibaca — abaikan, coba frame berikutnya */ }
    }, 350);

  } catch (e) {
    status.textContent = '⚠️ Tidak bisa mengakses kamera: ' + e.message
                       + '. Gunakan scanner USB atau ketik manual.';
    hentikanKamera();
  }
}

function hentikanKamera(){
  if (loopId){ clearInterval(loopId); loopId = null; }
  if (stream){ stream.getTracks().forEach(function(t){ t.stop(); }); stream = null; }
  document.getElementById('btnKamera').textContent = '📷 Pakai Kamera';
}

window.addEventListener('beforeunload', hentikanKamera);
</script>
<?php require __DIR__ . '/_footer.php'; ?>
