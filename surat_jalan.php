<?php
/**
 * AMANAH — Cetak Surat Jalan
 *
 * Halaman berdiri sendiri (tanpa sidebar) yang dioptimalkan untuk cetak
 * A4. Hanya dokumen berstatus `disetujui` / `selesai` yang bisa dicetak —
 * ini mencegah surat jalan beredar untuk barang yang belum disahkan.
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/_lib.php';
require_once __DIR__ . '/_qr.php';
require_login();

$id = (int) ($_GET['id'] ?? 0);

$st = $pdo->prepare(
    "SELECT p.*, u.nama AS pembuat, ua.nama AS penyetuju
     FROM perpindahan p
     LEFT JOIN users u  ON u.id  = p.user_id
     LEFT JOIN users ua ON ua.id = p.approved_by
     WHERE p.id = ?"
);
$st->execute([$id]);
$dok = $st->fetch();

if (!$dok) {
    http_response_code(404);
    die('<p style="font-family:sans-serif;padding:30px">Dokumen tidak ditemukan.</p>');
}
// Status yang boleh dicetak. 'dikirim' termasuk, karena surat jalan supplier
// justru harus tercetak SEBELUM barang berangkat.
if (!in_array($dok['status'], ['disetujui', 'dikirim', 'diterima', 'selesai'], true)) {
    http_response_code(403);
    die('<p style="font-family:sans-serif;padding:30px">Surat jalan hanya dapat dicetak untuk dokumen yang sudah '
      . 'disetujui atau dikirim. Status dokumen ini: <b>' . e($dok['status']) . '</b>. '
      . '<a href="' . beranda_peran() . '">Kembali</a></p>');
}

/* ---------------------------------------------------------------------
   SIAPA BOLEH MENCETAK — pengirim, penerima, dan Super Admin. Titik.

   Dulu di sini ada dua percabangan: satu untuk supplier, satu untuk toko.
   Keduanya benar, tetapi keduanya saja — SETIAP peran internal lolos
   tanpa diperiksa. Petugas gudang A cukup mengganti angka di alamat untuk
   mencetak surat jalan gudang B, lengkap dengan nama penerima manfaat dan
   alamat pengirimannya. Pimpinan pun begitu.

   Sekarang satu pemeriksaan, memakai aturan yang sama dengan daftar
   dokumennya. Aturan yang ditulis dua kali akan berbeda suatu hari;
   yang ini dulu ditulis tiga kali. */
if (!boleh_lihat_perpindahan($pdo, (int) $dok['id'])) {
    http_response_code(403);
    die('<p style="font-family:sans-serif;padding:30px">403 — Surat jalan ini bukan untuk lokasi '
      . 'yang Anda pegang. Hanya pengirim dan penerimanya yang bisa mencetaknya. '
      . '<a href="' . beranda_peran() . '">Kembali ke dasbor</a></p>');
}

$stD = $pdo->prepare(
    "SELECT d.*, b.nama AS nama_barang, b.kode AS kode_barang, b.merek, b.ukuran, b.satuan
     FROM perpindahan_detail d
     JOIN barang b ON b.id = d.barang_id
     WHERE d.perpindahan_id = ? ORDER BY d.id"
);
$stD->execute([$id]);
$detail = $stD->fetchAll();

// Catat bahwa surat jalan ini dicetak (berguna saat audit).
try {
    $pdo->prepare("UPDATE perpindahan SET printed_at = NOW(), print_count = print_count + 1 WHERE id = ?")
        ->execute([$id]);
    // $dok dibaca SEBELUM baris ini, jadi nilainya tertinggal satu langkah.
    // Tanpa penyesuaian, halaman selalu menampilkan "Sudah dicetak 0×" pada
    // cetakan pertama — angka yang justru dipakai saat audit menelusuri
    // berapa kali satu surat jalan diterbitkan ulang.
    $dok['print_count'] = (int) $dok['print_count'] + 1;
    catat_aktivitas($pdo, 'print', 'surat_jalan', $id, 'Cetak surat jalan ' . $dok['no_surat_jalan']);
} catch (Throwable $e) { error_log('[AMANAH] catat cetak: ' . $e->getMessage()); }

[$jLbl] = label_jenis($dok['jenis']);
$asal   = nama_lokasi($pdo, $dok['asal_tipe'],   $dok['asal_id']   ? (int) $dok['asal_id']   : null, $dok['asal_teks']);
$tujuan = nama_lokasi($pdo, $dok['tujuan_tipe'], $dok['tujuan_id'] ? (int) $dok['tujuan_id'] : null, $dok['tujuan_teks']);
// Titik transit ikut dicetak — dihitung di sini karena lembar_surat() tidak memegang $pdo.
$titikCetak = titik_transit($pdo, (int) $dok['id']);
$dok['transit_teks'] = implode(' → ', array_column($titikCetak, 'lokasi_nama'));
// Kendaraan/pengemudi per ruas sesudah transit — tercetak bila sudah dicatat saat berangkat dari titik itu.
$dok['transit_angkut'] = [];
foreach ($titikCetak as $t) {
    $a = teks_angkutan($t['kendaraan'] ?? null, $t['no_polisi'] ?? null, $t['pengemudi'] ?? null);
    if ($a !== '') { $dok['transit_angkut'][] = 'dari ' . $t['lokasi_nama'] . ': ' . $a; }
}
$totalQty = array_sum(array_map(fn($d) => (int) $d['qty'], $detail));
/* Kardus (fitur kardus): barisnya dipisah dari barang lepas. Bagian A memuat
   barang lepas, bagian B daftar kardus; isi tiap kardus dicetak sebagai
   lampiran di lembar tersendiri supaya penerima bisa mencocokkan per kardus. */
$kardusDok   = kardus_dokumen_lengkap($pdo, $id);
$detailLepas = array_values(array_filter($detail, fn($d) => empty($d['kardus_id'])));
$qtyKardus   = $totalQty - array_sum(array_map(fn($d) => (int) $d['qty'], $detailLepas));

// Kode verifikasi ringkas — dicocokkan bila ada keraguan atas keaslian lembar.
$verifikasi = strtoupper(substr(hash('sha256', $dok['no_surat_jalan'] . '|' . $dok['id'] . '|' . $dok['approved_at']), 0, 10));

/* ------------------------------------------------------------------ QR
   Isi QR adalah URL ke halaman Cek Surat Jalan beserta NOMOR surat jalannya
   — bukan id basis data. Dua alasan:

     • Nomor itu tercetak besar di lembar yang sama. Bila QR-nya rusak, sobek,
       atau kena tinta, petugas bisa mengetiknya manual dan hasilnya sama
       persis. QR berisi id internal tidak punya jalan mundur seperti itu.
     • Id basis data berurutan, jadi menaruhnya di QR mengundang orang
       menebak-nebak dokumen lain hanya dengan mengubah angkanya.

   Tidak ada token rahasia di dalam QR, dan itu keputusan sadar: nomor surat
   jalan sudah tercetak terbuka di lembar yang sama, jadi token tidak
   menambah kerahasiaan apa pun. Yang menjaga isi dokumen adalah login dan
   pemeriksaan lingkup di cek_surat.php, bukan kerahasiaan tautannya. */
$urlQr = url_penuh('cek_surat.php') . '?k=' . rawurlencode((string) $dok['no_surat_jalan']);
$svgQr = qr_svg($urlQr, 0, '#111');

/* qr_svg() mengembalikan null bila muatannya melewati kapasitas versi 6.
   Untuk URL surat jalan itu praktis mustahil, tapi lembar tanpa QR harus
   tetap berguna — jadi disiapkan cadangan berisi nomornya saja, dan bila itu
   pun gagal, kotak QR tidak dicetak sama sekali (lebih baik hilang daripada
   tercetak rusak dan membuat orang memindai berulang kali tanpa hasil). */
if ($svgQr === null) { $svgQr = qr_svg((string) $dok['no_surat_jalan'], 0, '#111'); }
?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Surat Jalan <?= e($dok['no_surat_jalan']) ?> · <?= APP_NAMA ?></title>
<style>
  @page { size: A4; margin: 12mm 14mm; }
  *{box-sizing:border-box;margin:0;padding:0}
  body{font-family:"Segoe UI",Arial,Helvetica,sans-serif;color:#111;background:#e9edf2;font-size:12px;line-height:1.45}

  .toolbar{max-width:210mm;margin:16px auto 0;display:flex;gap:8px;flex-wrap:wrap;align-items:center;padding:0 4px}
  .toolbar .btn{cursor:pointer;border:none;border-radius:9px;padding:9px 14px;font-weight:600;font-size:13px;
    text-decoration:none;display:inline-flex;align-items:center;gap:7px;font-family:inherit;line-height:1.2}
  /* Ukuran ikon WAJIB disebut. Halaman ini berdiri sendiri tanpa amanah.css,
     dan <svg> tanpa width/height diberi ukuran bawaan 300x150 oleh peramban —
     itulah yang dulu membuat tombol Cetak jadi kotak ungu raksasa. */
  .toolbar .btn svg{width:16px;height:16px;flex:0 0 auto;fill:none;stroke:currentColor;
    stroke-width:2;stroke-linecap:round;stroke-linejoin:round}
  .btn-p{background:#2563EB;color:#fff}.btn-p:hover{background:#1D4ED8}
  .btn-g{background:#fff;border:1px solid #cbd5e1;color:#334155}
  .btn-g:hover{background:#f8fafc}
  .toolbar small{color:#64748b;margin-left:auto}
  /* Lembar kedua tersembunyi sampai kotak centangnya ditandai. Aturan ini
     dulu tidak ada, sehingga arsipnya selalu ikut tampil dan orang mencetak
     dua lembar tanpa sengaja. */
  .hide{display:none}

  .sheet{max-width:210mm;margin:14px auto;background:#fff;padding:14mm;box-shadow:0 2px 14px rgba(0,0,0,.12)}

  /* Strip penanda lembar berdiri di ALIRAN, di atas kop — bukan melayang.
     Versi lama memakai position:absolute di sudut kanan atas, tepat di tempat
     tulisan "AMANAH v3.0" berada, jadi keduanya saling menumpuk dan tak satu
     pun terbaca. Elemen melayang di dokumen cetak selalu berisiko begitu:
     ia tidak ikut diperhitungkan saat elemen lain menghitung ruangnya. */
  .strip{display:flex;align-items:baseline;gap:8px;margin-bottom:9px;font-size:9.5px;color:#94a3b8}
  .strip .lembar{border:1px solid #ddd6fe;background:#faf8ff;color:#1D4ED8;font-weight:700;
    border-radius:4px;padding:2px 8px;letter-spacing:.02em}
  .strip .aplikasi{margin-left:auto;text-align:right;line-height:1.45}

  .kop{display:flex;gap:14px;align-items:flex-start;border-bottom:3px double #2563EB;padding-bottom:12px;margin-bottom:14px}
  .kop .logo{width:56px;height:56px;border-radius:12px;background:linear-gradient(135deg,#2563EB,#3B82F6);
    color:#fff;display:flex;align-items:center;justify-content:center;font-size:26px;font-weight:800;flex-shrink:0}
  .kop .lembaga{font-size:17px;font-weight:800;letter-spacing:.3px;color:#1D4ED8}
  .kop .sub{font-size:11px;color:#475569;margin-top:2px;line-height:1.5}
  .kop .judulblok{margin-left:auto;text-align:right;flex-shrink:0}
  .kop h1.judul{font-size:17px;letter-spacing:2.5px;text-transform:uppercase;margin:0 0 3px;white-space:nowrap}
  .kop .nomor{font-size:11px;color:#475569}
  .kop .nomor b{font-size:13px;letter-spacing:.5px;color:#111;display:block;margin-top:1px}

  /* Kotak QR. Sisinya 22mm — di bawah ±18mm, modul QR versi 6 jadi lebih
     kecil dari resolusi cetak printer laser biasa dan sering gagal dipindai. */
  .qrblok{display:flex;gap:10px;align-items:center;border:1px solid #DDD6FE;border-radius:8px;
    padding:9px 11px;background:#FCFBFF;margin-bottom:12px}
  .qrblok .gbr{width:22mm;height:22mm;flex:0 0 auto}
  .qrblok .gbr svg{width:100%;height:100%;display:block}
  .qrblok .ket{font-size:10px;color:#475569;line-height:1.5}
  .qrblok .ket b{color:#5B21B6;font-size:11px;display:block;margin-bottom:2px}
  .qrblok .kodemono{font-family:"Courier New",monospace;font-size:11px;letter-spacing:.5px;color:#111}

  .meta{display:grid;grid-template-columns:1fr 1fr;gap:0 22px;margin-bottom:14px}
  .meta table{width:100%;border-collapse:collapse}
  .meta td{padding:3px 0;vertical-align:top;font-size:11.5px}
  .meta td:first-child{width:96px;color:#475569}
  .meta td:first-child::after{content:":";float:right;padding-right:6px}

  table.items{width:100%;border-collapse:collapse;margin:10px 0 14px}
  table.items th{background:#f1f5f9;border:1px solid #cbd5e1;padding:7px 8px;font-size:10.5px;
    text-transform:uppercase;letter-spacing:.03em;text-align:left;color:#1e293b}
  table.items td{border:1px solid #cbd5e1;padding:7px 8px;font-size:11.5px;vertical-align:top}
  table.items td.c{text-align:center}table.items td.r{text-align:right}
  table.items tfoot td{background:#f8fafc;font-weight:700}

  .judul-bagian{font-size:11px;font-weight:800;letter-spacing:.06em;color:#1e293b;border-bottom:1.5px solid #1e293b;padding-bottom:3px;margin:12px 0 6px}
  .grup-kardus{page-break-inside:avoid;break-inside:avoid;margin-bottom:4px}
  .judul-kardus{font-size:11.5px;font-weight:700;color:#111;background:#f1f5f9;border:1px solid #cbd5e1;border-bottom:none;padding:5px 8px;margin-top:8px}
  .judul-kardus .mono{font-family:"Courier New",monospace;letter-spacing:.5px}
  .judul-kardus .ringkas-kardus{float:right;font-weight:400;color:#475569;font-size:10px}
  .grup-kardus table.items{margin-top:0}
  .sub-barang{color:#64748b;font-size:10px}
  table.total{width:100%;border-collapse:collapse;margin:4px 0 12px;font-size:11px}
  table.total td{padding:5px 8px;border:1px solid #cbd5e1;background:#f8fafc}
  table.total td.r{text-align:right;background:#fff}
  @page { @bottom-right { content: "Hal. " counter(page); font-size:9px; color:#94a3b8 } }
  .catatan{border:1px solid #e2e8f0;border-radius:6px;padding:9px 11px;font-size:11px;margin-bottom:16px;background:#fafbfc}
  .catatan b{color:#1D4ED8}

  .ttd{display:grid;grid-template-columns:repeat(3,1fr);gap:16px;margin-top:22px;text-align:center}
  .ttd .kotak{font-size:11px}
  .ttd .peran{color:#475569;margin-bottom:4px}
  .ttd .garis{height:56px;border-bottom:1px solid #64748b;margin-bottom:5px}
  .ttd .nama{font-weight:700;min-height:15px}
  .ttd .ket{color:#94a3b8;font-size:9.5px;margin-top:2px}

  .kaki{margin-top:18px;padding-top:9px;border-top:1px solid #e2e8f0;
    display:flex;justify-content:space-between;font-size:9.5px;color:#94a3b8;gap:12px}
  .verif{font-family:"Courier New",monospace;letter-spacing:1px;color:#475569}

  @media print{
    body{background:#fff}
    .toolbar{display:none}
    .sheet{box-shadow:none;margin:0;padding:0;max-width:none}
    .pagebreak{page-break-before:always}
  }
</style>
</head>
<body>
<?php /* Sprite ikon ikut ditanam: halaman ini berdiri sendiri, jadi tanpa ini
         <use href="#i-cetak"> tidak menunjuk ke apa pun. */
require __DIR__ . '/_ikon.php'; ?>

<div class="toolbar">
  <button class="btn btn-p" onclick="window.print()"><svg aria-hidden="true"><use href="#i-cetak"></use></svg> Cetak</button>
  <a class="btn btn-g" href="persetujuan.php?id=<?= (int) $dok['id'] ?>">← Kembali ke Dokumen</a>
  <a class="btn btn-g" href="perpindahan.php">Daftar Perpindahan</a>
  <label class="btn btn-g" style="cursor:pointer">
    <input type="checkbox" id="rangkap" onchange="toggleRangkap()" style="vertical-align:-1px"> Cetak 2 lembar (Penerima + Arsip)
  </label>
  <small>Sudah dicetak <?= (int) $dok['print_count'] ?>×</small>
</div>

<?php
/** Satu lembar surat jalan. Dipanggil dua kali bila dicetak rangkap. */
function lembar_surat(array $dok, array $detail, string $asal, string $tujuan, string $jLbl,
                      int $totalQty, string $verifikasi, string $peruntukan,
                      ?string $svgQr, bool $break = false, array $kardusDok = [], int $qtyKardus = 0) { ?>
<div class="sheet <?= $break ? 'pagebreak' : '' ?>">
  <div class="strip">
    <span class="lembar"><?= e($peruntukan) ?></span>
    <span class="aplikasi"><?= APP_NAMA ?> v<?= APP_VERSI ?> · <?= APP_KEPANJANGAN ?></span>
  </div>

  <div class="kop">
    <div class="logo">A</div>
    <div>
      <?php if (APP_LEMBAGA !== ''): ?><div class="lembaga"><?= e(APP_LEMBAGA) ?></div><?php endif; ?>
      <div class="sub">
        <?php if (SURAT_ALAMAT !== ''): ?><?= e(SURAT_ALAMAT) ?><br><?php endif; ?>
        <?= e(ident(SURAT_TELEPON !== '' ? 'Telp ' . SURAT_TELEPON : '', SURAT_EMAIL, SURAT_WEB)) ?>
      </div>
    </div>
    <?php /* Judul dan nomor pindah ke kop, sejajar identitas lembaga. Dulu
             keduanya di tengah pada baris sendiri, memakan ±14mm tinggi yang
             berharga — surat jalan berisi 10 barang jadi meluber ke halaman
             kedua padahal isinya masih muat. */ ?>
    <div class="judulblok">
      <h1 class="judul">Surat Jalan</h1>
      <div class="nomor">Nomor<b><?= e($dok['no_surat_jalan']) ?></b></div>
    </div>
  </div>

  <?php if ($svgQr !== null): ?>
    <div class="qrblok">
      <div class="gbr"><?= $svgQr /* SVG dibuat sendiri di server, bukan masukan pengguna */ ?></div>
      <div class="ket">
        <b>Pindai saat barang tiba</b>
        Buka daftar barang pada kiriman ini, lalu catat penerimaannya di gudang
        atau toko tujuan. Bila QR tidak terbaca, buka menu <b>Cek Surat Jalan</b>
        dan ketik nomornya:
        <span class="kodemono"><?= e($dok['no_surat_jalan']) ?></span>
      </div>
    </div>
  <?php endif; ?>

  <div class="meta">
    <table>
      <tr><td>Jenis</td><td><b><?= e($jLbl) ?></b></td></tr>
      <tr><td>Dari</td><td><b><?= e($asal) ?></b></td></tr>
      <tr><td>Kepada</td><td><b><?= e($tujuan) ?></b></td></tr>
      <?php if (!empty($dok['transit_teks'])): ?>
        <tr><td>Transit</td><td><?= e($dok['transit_teks']) ?>
          <?php foreach (($dok['transit_angkut'] ?? []) as $a): ?><br><small><?= e($a) ?></small><?php endforeach; ?></td></tr>
      <?php endif; ?>
      <?php if ($dok['penerima']): ?><tr><td>Penerima</td><td><?= e($dok['penerima']) ?></td></tr><?php endif; ?>
      <?php if ($dok['untuk_program']): ?><tr><td>Keperluan</td><td><?= e($dok['untuk_program']) ?></td></tr><?php endif; ?>
    </table>
    <table>
      <tr><td>Tanggal</td><td><b><?= e(tgl_id($dok['tanggal'], 'panjang')) ?></b></td></tr>
      <tr><td>No. Dokumen</td><td><?= e($dok['no_perpindahan']) ?></td></tr>
      <?php if ($dok['no_resi']): ?>
        <tr><td>No. Resi</td><td><b><?= e($dok['no_resi']) ?></b><?= $dok['ekspedisi'] ? ' — ' . e($dok['ekspedisi']) : '' ?></td></tr>
      <?php endif; ?>
      <?php if ($dok['kendaraan'] || $dok['no_polisi']): ?>
        <tr><td>Kendaraan</td><td><?= e(trim(($dok['kendaraan'] ?? '') . ' ' . ($dok['no_polisi'] ? '(' . $dok['no_polisi'] . ')' : ''))) ?></td></tr>
      <?php endif; ?>
      <tr><td>Disetujui</td><td><?= e($dok['penyetuju'] ?: '—') ?> · <?= e(tgl_id($dok['approved_at'])) ?></td></tr>
    </table>
  </div>

  <?php /* ------------------------------------------------------------------
           A. BARANG DALAM KARDUS — tiap kardus satu kelompok berjudul kode
              kardusnya, lengkap dengan seluruh baris isinya.
           B. BARANG TANPA KARDUS.
           Bagian yang kosong disembunyikan. Satu nomor surat jalan untuk
           seluruhnya. Judul kardus tidak dipisahkan dari isinya saat ganti
           halaman (page-break-inside: avoid pada kelompoknya). */ ?>
  <?php if ($kardusDok): ?>
    <div class="judul-bagian">A. BARANG DALAM KARDUS — <?= count($kardusDok) ?> kardus</div>
    <?php foreach ($kardusDok as $kk): $rk = kardus_ringkas($kk['isi']); ?>
      <div class="grup-kardus">
        <div class="judul-kardus">Kardus <?= (int) $kk['urut'] ?> dari <?= (int) $kk['jumlah_kardus'] ?> — <span class="mono"><?= e($kk['kode']) ?></span>
          <?= $kk['nama'] ? ' · ' . e($kk['nama']) : '' ?>
          <span class="ringkas-kardus"><?= $rk['jenis'] ?> jenis · <?= e(kardus_teks_satuan($rk['per_satuan'])) ?><?= $kk['berat_kg'] ? ' · ' . e(rtrim(rtrim((string) $kk['berat_kg'], '0'), '.')) . ' kg' : '' ?></span></div>
        <table class="items">
          <thead><tr><th style="width:26px">No</th><th style="width:78px">Kode Barang</th><th>Nama Barang / Varian</th><th style="width:96px">No. Batch</th><th style="width:74px">Jumlah</th><th style="width:60px">Satuan</th><th style="width:110px">Keterangan</th></tr></thead>
          <tbody>
          <?php foreach ($kk['isi'] as $i => $r): ?>
            <tr><td class="c"><?= $i + 1 ?></td><td><?= e($r['kode_barang']) ?></td>
              <td><b><?= e($r['nama_barang']) ?></b><?= ($r['merek'] || $r['ukuran']) ? '<br><span class="sub-barang">' . e(trim(($r['merek'] ?? '') . ' ' . ($r['ukuran'] ? '· ' . $r['ukuran'] : ''))) . '</span>' : '' ?></td>
              <td><?= e($r['nomor_batch'] ?: '—') ?><?= $r['tanggal_expired'] ? '<br><span class="sub-barang">exp ' . e(tgl_id($r['tanggal_expired'])) . '</span>' : '' ?></td>
              <td class="r"><b><?= angka($r['qty']) ?></b></td><td><?= e($r['satuan']) ?></td><td><?= e($r['catatan'] ?: '') ?></td></tr>
          <?php endforeach; ?>
          </tbody>
        </table>
        <?php if ($kk['catatan']): ?><div class="sub-barang" style="margin:-8px 0 10px">Catatan kardus: <?= e($kk['catatan']) ?></div><?php endif; ?>
      </div>
    <?php endforeach; ?>
  <?php endif; ?>

  <?php if ($detail): ?>
  <div class="judul-bagian"><?= $kardusDok ? 'B. BARANG TANPA KARDUS' : 'RINCIAN BARANG' ?></div>
  <table class="items">
    <thead><tr>
      <th style="width:26px">No</th>
      <th style="width:78px">Kode Barang</th>
      <th>Nama Barang / Varian</th>
      <th style="width:96px">No. Batch</th>
      <th style="width:74px">Jumlah</th>
      <th style="width:60px">Satuan</th>
      <th style="width:110px">Keterangan</th>
    </tr></thead>
    <tbody>
    <?php foreach ($detail as $i => $d): ?>
      <tr>
        <td class="c"><?= $i + 1 ?></td>
        <td><?= e($d['kode_barang']) ?></td>
        <td>
          <b><?= e($d['nama_barang']) ?></b>
          <?php if ($d['merek'] || $d['ukuran']): ?>
            <br><span class="sub-barang"><?= e(trim(($d['merek'] ?? '') . ' ' . ($d['ukuran'] ? '· ' . $d['ukuran'] : ''))) ?></span>
          <?php endif; ?>
        </td>
        <td><?= e($d['nomor_batch'] ?: '—') ?><?= $d['tanggal_expired'] ? '<br><span class="sub-barang">exp ' . e(tgl_id($d['tanggal_expired'])) . '</span>' : '' ?></td>
        <td class="r"><b><?= angka($d['qty']) ?></b></td>
        <td><?= e($d['satuan']) ?></td>
        <td><?= e($d['keterangan'] ?: '') ?></td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$kardusDok): for ($k = count($detail); $k < 5; $k++): ?>
      <tr><td class="c" style="color:#cbd5e1"><?= $k + 1 ?></td><td></td><td>&nbsp;</td><td></td><td></td><td></td><td></td></tr>
    <?php endfor; endif; ?>
    </tbody>
  </table>
  <?php endif; ?>

  <?php /* Kaki: total kardus & total kuantitas per satuan seluruh dokumen. */
        $perSatuanSemua = [];
        foreach ($kardusDok as $kk) { foreach ($kk['isi'] as $r) { $perSatuanSemua[$r['satuan'] ?: 'unit'] = ($perSatuanSemua[$r['satuan'] ?: 'unit'] ?? 0) + (int) $r['qty']; } }
        foreach ($detail as $d) { $perSatuanSemua[$d['satuan'] ?: 'unit'] = ($perSatuanSemua[$d['satuan'] ?: 'unit'] ?? 0) + (int) $d['qty']; } ?>
  <table class="total">
    <tr><td>Total kardus</td><td class="r"><b><?= count($kardusDok) ?></b></td>
        <td>Jenis barang</td><td class="r"><b><?= count($detail) + array_sum(array_map(fn($kk) => count($kk['isi']), $kardusDok)) ?></b></td>
        <td>Total kuantitas</td><td class="r"><b><?= e(kardus_teks_satuan($perSatuanSemua)) ?></b></td></tr>
  </table>
  <div class="catatan">
    <b>Catatan:</b>
    <?= $dok['catatan'] ? e($dok['catatan']) : 'Barang diterima dalam keadaan baik dan sesuai jumlah yang tercantum.' ?>
    <br>
    <span style="color:#64748b">Barang yang telah diterima dan ditandatangani menjadi tanggung jawab penerima.
    Keberatan atas jumlah atau kondisi barang disampaikan maksimal 1×24 jam sejak diterima.</span>
  </div>

  <div class="ttd">
    <div class="kotak">
      <div class="peran">Diserahkan oleh,</div>
      <div class="garis"></div>
      <div class="nama"><?= e($dok['pengirim'] ?: '') ?></div>
      <div class="ket">Petugas Gudang</div>
    </div>
    <div class="kotak">
      <div class="peran">Diangkut oleh,</div>
      <div class="garis"></div>
      <div class="nama">&nbsp;</div>
      <div class="ket">Pengemudi / Ekspedisi</div>
    </div>
    <div class="kotak">
      <div class="peran">Diterima oleh,</div>
      <div class="garis"></div>
      <div class="nama"><?= e($dok['penerima'] ?: '') ?></div>
      <div class="ket">Nama terang &amp; tanggal</div>
    </div>
  </div>

  <div class="kaki">
    <div>Dicetak <?= e(tgl_id(date('Y-m-d H:i:s'), 'lengkap')) ?> oleh <?= e(current_user()['nama'] ?? '—') ?></div>
    <div class="verif">Kode verifikasi: <?= e($verifikasi) ?></div>
  </div>
</div>
<?php }

lembar_surat($dok, $detailLepas, $asal, $tujuan, $jLbl, $totalQty, $verifikasi, 'Lembar 1 — Penerima', $svgQr, false, $kardusDok, $qtyKardus);

?>

<div id="lembar2" class="hide">
<?php lembar_surat($dok, $detailLepas, $asal, $tujuan, $jLbl, $totalQty, $verifikasi, 'Lembar 2 — Arsip Gudang', $svgQr, true, $kardusDok, $qtyKardus); ?>
</div>

<script>
function toggleRangkap(){
  /* Kelas, bukan style.display — supaya satu tempat saja yang menentukan
     tampil/tidaknya lembar arsip. Mencampur keduanya berarti aturan CSS bisa
     benar tapi tetap tertimpa gaya sebaris, dan itu sulit dilacak. */
  document.getElementById('lembar2')
          .classList.toggle('hide', !document.getElementById('rangkap').checked);
}
</script>
</body>
</html>
