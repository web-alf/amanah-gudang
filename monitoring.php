<?php
/**
 * AMANAH — Monitoring Stok & Kedaluwarsa
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/_lib.php';
require_login();

/* Halaman ini kini dipakai SEMUA peran, masing-masing melihat stoknya
   sendiri. Lingkupnya datang dari lingkup_stok() — satu tempat yang juga
   dipakai Laporan Stok, supaya dua halaman yang bersebelahan di menu tidak
   memberi dua angka berbeda untuk barang yang sama. */
$L = lingkup_stok($pdo);

function cnt(PDO $pdo, string $sql): int {
    try { return (int) $pdo->query($sql)->fetchColumn(); }
    catch (Throwable $e) { error_log('[AMANAH] monitoring: ' . $e->getMessage()); return 0; }
}
function rows(PDO $pdo, string $sql): array {
    try { return $pdo->query($sql)->fetchAll(); }
    catch (Throwable $e) { error_log('[AMANAH] monitoring: ' . $e->getMessage()); return []; }
}

// syarat_lingkup() ada di _lib.php — dipakai bersama Laporan Stok, supaya
// kedua halaman menyaring stok dengan aturan yang sama persis.
$syarat = syarat_lingkup($pdo);

$eAman    = cnt($pdo, "SELECT COUNT(*) FROM v_expired_rantai WHERE status_expired='aman' AND $syarat");
$eWaspada = cnt($pdo, "SELECT COUNT(*) FROM v_expired_rantai WHERE status_expired='waspada' AND $syarat");
$eSegera  = cnt($pdo, "SELECT COUNT(*) FROM v_expired_rantai WHERE status_expired='segera_expired' AND $syarat");
$eExpired = cnt($pdo, "SELECT COUNT(*) FROM v_expired_rantai WHERE status_expired='expired' AND $syarat");

/* `sumber` dan `ref_id` ikut diambil supaya tiap baris bisa ditautkan ke
   halaman perbaikannya. v_expired_rantai menggabungkan DUA tabel — batch
   gudang/toko (`sumber` = 'batch') dan stok mitra — jadi ref_id saja tidak
   cukup; tanpa `sumber`, tautan ke batch #15 bisa menunjuk baris mitra yang
   kebetulan ber-id sama. */
/* ----------------------------------------------------------------------
   TANGGAL MUSTAHIL DIKELUARKAN DARI DAFTAR MENDESAK

   Terlihat di data sungguhan: batch bertanggal `0001-01-01`, terhitung
   "lewat 739.886 hari". Karena daftar ini diurutkan dari yang paling
   mendesak, baris sampah itu menempati puncaknya — dan barang yang
   benar-benar kedaluwarsa pekan ini terdorong ke bawah olehnya.

   Dikeluarkan, bukan disembunyikan: yang tersaring di sini muncul lagi di
   bagian "Tanggal Kedaluwarsa Tidak Masuk Akal" di bawah halaman, lengkap
   dengan tombol perbaikannya. Ambangnya EXP_PALING_TUA di _lib.php, satu
   tempat, dipakai juga oleh penyaring saat batch dibuat.

   DIBATASI 40 BARIS. Sebelumnya tanpa batas sama sekali: di gudang berisi
   974 jenis barang, halaman ini menjadi gulungan ratusan baris yang tidak
   ada ujungnya, dan yang paling mendesak — justru yang di puncak — jadi
   tenggelam oleh panjangnya sendiri. Jumlah seluruhnya tetap disebut.
   ---------------------------------------------------------------------- */
$syaratWajar = "tanggal_expired >= '" . EXP_PALING_TUA . "'";
$LIMIT_EXP   = 40;

$nExpTotal = cnt($pdo,
    "SELECT COUNT(*) FROM v_expired_rantai
     WHERE status_expired IN ('expired','segera_expired','waspada')
       AND $syaratWajar AND $syarat");

/* Nama rak dan gudang diambil TERPISAH lewat join, bukan dipecah dari
   `lokasi_nama` yang sudah disatukan view.

   Versi pertama memang memecah untai itu pada tanda '›'. Gagal, dan
   sebabnya tidak terlihat dari kode: di sebagian basis data pemisah itu
   tersimpan sebagai mojibake 'ÔÇ║' — sisa pengodean yang rusak saat
   view-nya dulu dibuat. Memecah untai yang bentuknya bergantung pada
   pengodean basis data adalah kerapuhan yang tidak perlu ditanggung;
   kolomnya sendiri sudah ada. */
$expList = rows($pdo,
    "SELECT v.sumber, v.ref_id, v.nama_barang, v.nomor_batch, v.tanggal_expired,
            v.qty_tersisa, v.sisa_hari, v.status_expired, v.lokasi_tipe,
            COALESCE(lr.nama, tk.nama, '—') AS lokasi_utama,
            g.nama AS lokasi_induk
     FROM v_expired_rantai v
     LEFT JOIN lokasi_rak lr ON lr.id = v.lokasi_rak_id
     LEFT JOIN gudang g      ON g.id  = lr.gudang_id
     LEFT JOIN toko tk       ON tk.id = v.toko_id
     WHERE v.status_expired IN ('expired','segera_expired','waspada')
       AND v.$syaratWajar AND " . syarat_lingkup($pdo, 'v') . "
     ORDER BY v.sisa_hari ASC
     LIMIT $LIMIT_EXP");

/* Batch bertanggal mustahil — dikumpulkan terpisah supaya bisa dibetulkan. */
$expAneh = rows($pdo,
    "SELECT v.sumber, v.ref_id, v.nama_barang, v.nomor_batch, v.tanggal_expired,
            v.qty_tersisa,
            COALESCE(lr.nama, tk.nama, '—') AS lokasi_utama,
            g.nama AS lokasi_induk
     FROM v_expired_rantai v
     LEFT JOIN lokasi_rak lr ON lr.id = v.lokasi_rak_id
     LEFT JOIN gudang g      ON g.id  = lr.gudang_id
     LEFT JOIN toko tk       ON tk.id = v.toko_id
     WHERE v.tanggal_expired IS NOT NULL
       AND v.tanggal_expired < '" . EXP_PALING_TUA . "'
       AND " . syarat_lingkup($pdo, 'v') . "
     ORDER BY v.nama_barang
     LIMIT 50");

/* Peringatan stok dibandingkan terhadap stok DI LINGKUP INI, bukan total
   lembaga. Total lembaga membuat petugas gudang melihat "aman" padahal
   raknya sendiri kosong — peringatan yang justru menyesatkan.

   Dua jalur, dengan sengaja:
   • Lingkup penuh (Super Admin & Pimpinan) tetap memakai v_status_stok,
     yang bertolak dari daftar barang — jadi barang yang belum pernah
     distok pun ikut terhitung "habis", seperti sebelumnya.
   • Peran berlingkup memakai JOIN ke stoknya sendiri: barang yang tidak
     pernah disimpan di sini bukan "habis", ia memang bukan barangnya.
     Kalau tidak, satu supplier akan melihat seluruh katalog produk
     lembaga dilaporkan habis. */
if ($L['semua']) {
    $stokList = rows($pdo,
        "SELECT nama_barang, kode_barang, stok, stok_minimum, status_stok
         FROM v_status_stok WHERE status_stok IN ('habis','hampir_habis') ORDER BY stok ASC");
} else {
    $syaratV = syarat_lingkup($pdo, 'v');
    $stokList = rows($pdo,
        "SELECT b.nama AS nama_barang, b.kode AS kode_barang, b.stok_minimum,
                COALESCE(SUM(v.qty),0) AS stok,
                CASE WHEN COALESCE(SUM(v.qty),0) <= 0             THEN 'habis'
                     WHEN COALESCE(SUM(v.qty),0) <= b.stok_minimum THEN 'hampir_habis'
                     ELSE 'aman' END AS status_stok
         FROM barang b
         JOIN v_stok_rantai v ON v.barang_id = b.id AND $syaratV
         WHERE b.deleted_at IS NULL
         GROUP BY b.id
         HAVING status_stok IN ('habis','hampir_habis')
         ORDER BY stok ASC");
}

function tag_exp(string $s): array {
    $peta = [
        'expired'        => ['Expired',        't-red'],
        'segera_expired' => ['Segera expired', 't-orange'],
        'waspada'        => ['Waspada',        't-amber'],
    ];
    return $peta[$s] ?? ['Aman', 't-green'];
}

$PAGE_TITLE = 'Monitoring'; $ACTIVE = 'monitoring';
require __DIR__ . '/_header.php';
?>
<div class="page-head">
  <div><h1>Monitoring Stok &amp; Kedaluwarsa</h1>
    <?php /* Lingkupnya ditulis terang-terangan. Angka stok tanpa keterangan
             "stok siapa" adalah angka yang mudah disalahartikan. */ ?>
    <p>Status terkini · ambang H-90 (waspada) / H-30 (segera) / lewat tanggal (expired)
      <?php if ($L['label'] !== ''): ?>
        <br><b>Lingkup: <?= e($L['label']) ?></b>
      <?php endif; ?></p></div>
  <?php /* Halaman ini menjawab "mana yang bermasalah". Untuk membetulkan
           batch yang TIDAK sedang bermasalah — nomornya tertukar, kondisinya
           salah pilih — daftarnya ada di halaman tersendiri. */ ?>
  <a class="btn btn-ghost" href="batch.php">
    <svg aria-hidden="true"><use href="#i-box"></use></svg> Daftar Batch</a>
  <button class="btn btn-ghost" onclick="window.print()"><svg aria-hidden="true"><use href="#i-cetak"></use></svg> Cetak</button>
</div>

<div class="kpi">
  <div class="card"><div style="font-size:24px;font-weight:800;color:var(--green)"><?= angka($eAman) ?></div>
    <div class="mut-sm"><i class="dot d-ok"></i> Aman (&gt;90 hari)</div></div>
  <div class="card"><div style="font-size:24px;font-weight:800;color:#b45309"><?= angka($eWaspada) ?></div>
    <div class="mut-sm"><i class="dot d-warn"></i> Waspada (&le;90 hari)</div></div>
  <div class="card"><div style="font-size:24px;font-weight:800;color:var(--orange)"><?= angka($eSegera) ?></div>
    <div class="mut-sm"><i class="dot d-warn2"></i> Segera expired (&le;30 hari)</div></div>
  <div class="card"><div style="font-size:24px;font-weight:800;color:var(--red)"><?= angka($eExpired) ?></div>
    <div class="mut-sm"><i class="dot d-bad"></i> Sudah expired</div></div>
</div>

<div class="dash-grid">
  <div class="card">
    <h3>Peringatan Stok</h3><div class="sub">Sudah habis atau mencapai batas minimum</div>
    <div class="scrollx"><table class="tbl-kartu">
      <thead><tr><th>Barang</th><th>Stok / Min</th><th>Status</th></tr></thead>
      <tbody>
      <?php if (!$stokList): ?>
        <tr><td colspan="3" class="empty">Semua stok aman 🎉</td></tr>
      <?php else: foreach ($stokList as $s):
        $c   = $s['status_stok'] === 'habis' ? 't-red' : 't-amber';
        $lbl = $s['status_stok'] === 'habis' ? 'Habis' : 'Hampir habis'; ?>
        <tr>
          <td class="judul" data-label="Barang"><b><?= e($s['nama_barang']) ?></b>
            <br><small class="mut"><?= e($s['kode_barang']) ?></small></td>
          <td data-label="Stok / Min"><?= angka($s['stok']) ?> / <?= angka($s['stok_minimum']) ?></td>
          <td data-label="Status"><span class="tag <?= $c ?>"><?= $lbl ?></span></td>
        </tr>
      <?php endforeach; endif; ?>
      </tbody>
    </table></div>
  </div>

  <div class="card">
    <div class="baris" style="justify-content:space-between;align-items:flex-start;gap:12px">
      <div>
        <h3>Kedaluwarsa Terdekat</h3>
        <div class="sub">Diurutkan dari yang paling mendesak<?php
          if ($nExpTotal > $LIMIT_EXP): ?> · <?= angka($LIMIT_EXP) ?> teratas
          dari <?= angka($nExpTotal) ?> batch<?php endif; ?></div>
      </div>
      <?php /* Halaman ini hanya MENUNJUKKAN. Sampai sekarang ia buntu:
               batch kedaluwarsa terlihat tapi tidak bisa diapa-apakan, dan
               tetap terhitung persediaan. Tautan ini jalan keluarnya. */ ?>
      <a class="btn btn-ghost btn-sm" href="pelepasan.php">
        <svg aria-hidden="true"><use href="#i-putar"></use></svg> Tindak lanjuti</a>
    </div>
    <div class="scrollx"><table class="tbl-kartu">
      <?php /* Kolom aksi hanya untuk yang berhak memperbaikinya. Bagi yang
               lain tabelnya tetap enam kolom seperti dulu — kolom kosong
               berjudul "Aksi" hanya memberi harapan yang tidak ada. */
            $bolehPerbaiki = boleh_edit(); ?>
      <thead><tr><th>Barang</th><th>Batch</th><th>Lokasi</th><th>Expired</th><th>Sisa</th><th>Qty</th><th>Status</th>
        <?php if ($bolehPerbaiki): ?><th></th><?php endif; ?></tr></thead>
      <tbody>
      <?php if (!$expList): ?>
        <tr><td colspan="<?= $bolehPerbaiki ? 8 : 7 ?>" class="empty">Tidak ada barang yang mendekati kedaluwarsa.</td></tr>
      <?php else: foreach ($expList as $x): [$lbl, $c] = tag_exp($x['status_expired']); ?>
        <tr>
          <td class="judul" data-label="Barang"><b><?= e($x['nama_barang']) ?></b></td>
          <td class="sekunder" data-label="Batch"><code><?= e($x['nomor_batch']) ?></code></td>
          <?php /* Rak/toko di depan, gudang induknya jadi baris kecil di
                   bawahnya. Sebelumnya lencana ikon berdiri sendiri lalu
                   "Gudang Apotek › Area Penampung" menyusul sebagai satu
                   untai panjang — di kolom selebar ini ia patah jadi EMPAT
                   baris, dan tinggi barisnya ikut jadi empat kali. Begini
                   jadi dua baris, dan yang dicari orang lebih dulu — raknya
                   — yang terbaca duluan. */ ?>
          <td class="sekunder" data-label="Lokasi">
            <?= ($x['lokasi_tipe'] ?? '') === 'toko' ? '🏪' : '🏭' ?>
            <b><?= e($x['lokasi_utama'] ?: '—') ?></b>
            <?php if (!empty($x['lokasi_induk'])): ?>
              <br><small class="mut2"><?= e($x['lokasi_induk']) ?></small>
            <?php endif; ?>
          </td>
          <td data-label="Expired"><?= e(tgl_id($x['tanggal_expired'])) ?></td>
          <td data-label="Sisa"><?= (int) $x['sisa_hari'] < 0 ? (int) $x['sisa_hari'] : '+' . (int) $x['sisa_hari'] ?> hari</td>
          <td class="sekunder" data-label="Qty"><?= angka($x['qty_tersisa']) ?></td>
          <td data-label="Status"><span class="tag <?= $c ?>"><?= $lbl ?></span></td>
          <?php if ($bolehPerbaiki): ?>
          <td class="nowrap aksi">
            <?php /* Hanya baris yang berasal dari batch_barang. Baris mitra
                     datang dari stok_supplier — tabel lain, halaman lain, dan
                     bukan wewenang sisi gudang untuk memperbaikinya. */
                  if (($x['sumber'] ?? '') === 'batch'): ?>
              <a class="btn btn-ghost btn-sm"
                 href="batch_edit.php?id=<?= (int) $x['ref_id'] ?>">Perbaiki</a>
            <?php endif; ?>
          </td>
          <?php endif; ?>
        </tr>
      <?php endforeach; endif; ?>
      </tbody>
    </table></div>
  </div>
</div>

<?php /* ======================================================================
   BATCH TANPA TANGGAL KEDALUWARSA

   Bagian ini ada karena satu hal yang baru ketahuan saat tombol Perbaiki
   dipasang: tabel "Kedaluwarsa Terdekat" di atas HANYA memuat batch yang
   sudah punya tanggal dan sudah mendekat. Batch yang tanggalnya lupa diisi
   tidak pernah muncul di sana — padahal justru itu yang perlu dibetulkan,
   dan justru itu yang membuat halaman ini tampak kosong di basis data yang
   seluruh batchnya belum bertanggal.

   Bukan berarti semuanya salah: mainan, perlengkapan, dan pakaian memang
   tidak punya masa kedaluwarsa. Karena itu bahasanya "belum diisi", bukan
   "bermasalah" — yang memutuskan orang yang tahu barangnya.
   ====================================================================== */
$tanpaTgl = rows($pdo,
    "SELECT bb.id, b.kode AS kode_barang, b.nama AS nama_barang, bb.nomor_batch,
            bb.qty_tersisa, bb.tanggal_masuk, b.satuan,
            COALESCE(lr.kode, tk.nama, '—') AS lokasi
     FROM batch_barang bb
     JOIN barang b           ON b.id  = bb.barang_id
     LEFT JOIN lokasi_rak lr ON lr.id = bb.lokasi_rak_id
     LEFT JOIN toko tk       ON tk.id = bb.toko_id
     WHERE bb.tanggal_expired IS NULL AND bb.qty_tersisa > 0
       AND " . syarat_lingkup($pdo, 'bb') . "
     ORDER BY bb.qty_tersisa DESC, b.nama
     LIMIT 50");
$nTanpaTgl = cnt($pdo,
    "SELECT COUNT(*) FROM batch_barang bb
     WHERE bb.tanggal_expired IS NULL AND bb.qty_tersisa > 0
       AND " . syarat_lingkup($pdo, 'bb'));
?>
<?php /* ======================================================================
   TANGGAL YANG MUSTAHIL

   Ditaruh SEBELUM "belum punya tanggal" karena lebih mendesak: batch tanpa
   tanggal jujur mengaku tidak tahu, sedangkan batch bertanggal 0001-01-01
   mengaku tahu dan salah — dan angkanya ikut dihitung sebagai kedaluwarsa
   di kartu ringkasan di puncak halaman.
   ====================================================================== */ ?>
<?php if ($expAneh): ?>
<div class="card mb16" style="border-color:var(--red)">
  <h3>Tanggal Kedaluwarsa Tidak Masuk Akal</h3>
  <div class="sub">
    <?= count($expAneh) ?> batch bertanggal sebelum <?= e(tgl_id(EXP_PALING_TUA)) ?> —
    hampir pasti salah ketik atau terbawa dari impor. Batch ini sengaja
    dikeluarkan dari daftar Kedaluwarsa Terdekat supaya tidak menimbun
    barang yang benar-benar mendesak.
  </div>
  <div class="tbl-wrap"><table class="tbl-kartu">
    <thead><tr><th>Barang</th><th>Batch</th><th>Lokasi</th><th>Tanggal Tercatat</th><th>Sisa</th>
      <?php if (boleh_edit()): ?><th></th><?php endif; ?></tr></thead>
    <tbody>
    <?php foreach ($expAneh as $a): ?>
      <tr>
        <td class="judul" data-label="Barang"><b><?= e($a['nama_barang']) ?></b></td>
        <td class="sekunder" data-label="Batch"><code><?= e($a['nomor_batch'] ?: '—') ?></code></td>
        <td class="sekunder" data-label="Lokasi">
          <b><?= e($a['lokasi_utama'] ?: '—') ?></b>
          <?php if (!empty($a['lokasi_induk'])): ?>
            <br><small class="mut2"><?= e($a['lokasi_induk']) ?></small>
          <?php endif; ?>
        </td>
        <td data-label="Tanggal Tercatat">
          <span class="tag t-red"><?= e($a['tanggal_expired']) ?></span></td>
        <td data-label="Sisa"><b><?= angka($a['qty_tersisa']) ?></b></td>
        <?php if (boleh_edit()): ?>
        <td class="aksi">
          <?php if (($a['sumber'] ?? '') === 'batch'): ?>
            <a class="btn btn-red btn-sm" href="batch_edit.php?id=<?= (int) $a['ref_id'] ?>">Perbaiki</a>
          <?php endif; ?>
        </td>
        <?php endif; ?>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
</div>
<?php endif; ?>

<?php if ($tanpaTgl): ?>
<div class="card mb16">
  <h3>Batch Belum Punya Tanggal Kedaluwarsa</h3>
  <div class="sub">
    <?= angka($nTanpaTgl) ?> batch<?= $nTanpaTgl > 50 ? ' · 50 terbanyak ditampilkan' : '' ?>
    · sebagian barang memang tidak punya masa kedaluwarsa, sebagian lagi terlewat diisi
  </div>
  <div class="tbl-wrap"><table class="tbl-kartu">
    <thead><tr><th>Barang</th><th>Batch</th><th>Lokasi</th><th>Masuk</th><th>Sisa</th>
      <?php if (boleh_edit()): ?><th></th><?php endif; ?></tr></thead>
    <tbody>
    <?php foreach ($tanpaTgl as $t): ?>
      <tr>
        <td class="judul" data-label="Barang"><b><?= e($t['nama_barang']) ?></b><br>
          <small class="mut2"><?= e($t['kode_barang']) ?></small></td>
        <td class="sekunder" data-label="Batch"><code><?= e($t['nomor_batch'] ?: '—') ?></code></td>
        <td class="sekunder" data-label="Lokasi"><?= e($t['lokasi']) ?></td>
        <td class="sekunder" data-label="Masuk"><?= e(tgl_id($t['tanggal_masuk'])) ?></td>
        <td data-label="Sisa"><b><?= angka($t['qty_tersisa']) ?></b> <small><?= e($t['satuan']) ?></small></td>
        <?php if (boleh_edit()): ?>
        <td class="aksi">
          <a class="btn btn-ghost btn-sm" href="batch_edit.php?id=<?= (int) $t['id'] ?>">Perbaiki</a>
        </td>
        <?php endif; ?>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
</div>
<?php endif; ?>

<?php require __DIR__ . '/_footer.php'; ?>
