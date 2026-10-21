<?php
/**
 * AMANAH — Laporan Donasi
 * =============================================================================
 * Yayasan wajib melaporkan donasi KUNJUNGAN terpisah dari donasi PAKET.
 * Keduanya berbeda pertanggungjawabannya:
 *
 *   Kunjungan — ada tatap muka, ada tanda terima langsung, dan donaturnya
 *               bisa ditanya ulang kapan saja.
 *   Paket     — tidak ada tatap muka. Satu-satunya bukti bahwa barangnya
 *               benar-benar dikirim dan benar-benar tiba adalah NOMOR RESI.
 *
 * Sampai halaman ini ada, perbedaan itu tidak bisa dilaporkan sama sekali:
 * kolom `cara_terima` memang sudah ada di tabel `donasi` sejak awal, tetapi
 * masuk.php menuliskannya mati sebagai 'diantar' untuk setiap donasi. Isinya
 * seragam, jadi angkanya tidak berarti apa-apa.
 *
 * Halaman ini menyaring tiga sumbu sekaligus — periode, cara terima, dan
 * jenis donasi — karena itulah tiga pertanyaan yang diajukan saat laporan
 * disusun, dan menjawabnya satu per satu lewat tiga halaman berbeda berarti
 * angkanya harus dijumlahkan tangan.
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/_lib.php';
require_login();
tolak_supplier();

if (!boleh_lihat_laporan($pdo)) {
    http_response_code(403);
    die('<p style="font-family:sans-serif;padding:30px">403 — Akun Anda tidak diberi izin '
      . 'membuka laporan. <a href="' . e(beranda_peran()) . '">Kembali</a></p>');
}

/* ------------------------------------------------------------- penyaring */
$PERIODE = [
    'bulan'  => ['Bulan Ini',   "d.tanggal >= DATE_FORMAT(CURDATE(), '%Y-%m-01')"],
    'minggu' => ['7 Hari',      "d.tanggal >= DATE_SUB(CURDATE(), INTERVAL 6 DAY)"],
    'tahun'  => ['Tahun Ini',   "YEAR(d.tanggal) = YEAR(CURDATE())"],
    'semua'  => ['Semua Waktu', '1=1'],
];
$gP = (string) ($_GET['periode'] ?? 'bulan');
$periode = isset($PERIODE[$gP]) ? $gP : 'bulan';
$syaratPeriode = $PERIODE[$periode][1];

/* ----------------------------------------------------------------------
   RENTANG TANGGAL SENDIRI

   Empat pil di atas menjawab pertanyaan yang sering diulang. Yang tidak
   terjawab olehnya justru yang paling sering dibutuhkan laporan: satu
   bulan TERTENTU yang sudah lewat, atau periode kepengurusan yang tidak
   jatuh di batas bulan. "Tahun Ini" lalu menghitung sendiri bagian yang
   diinginkan adalah cara paling mudah membuat angka laporan meleset.

   Rentang sendiri MENIMPA pilihan pil — bukan menambahinya. Dua saringan
   waktu yang berlaku bersamaan akan menghasilkan irisan yang tidak
   diminta siapa pun, dan yang membacanya tidak punya cara tahu mana yang
   sedang menang.
   ---------------------------------------------------------------------- */
function tgl_sah(?string $t): ?string {
    $t = trim((string) $t);
    if ($t === '') { return null; }
    $d = DateTime::createFromFormat('Y-m-d', $t);
    return ($d && $d->format('Y-m-d') === $t) ? $t : null;
}
$dari   = tgl_sah($_GET['dari']   ?? null);
$sampai = tgl_sah($_GET['sampai'] ?? null);

/* Dibalik bila terbalik, dan itu DISEBUTKAN. Membetulkannya diam-diam
   menyembunyikan salah ketik yang mungkin juga terjadi di tempat lain
   pada laporan yang sama. */
$tanggalDibalik = false;
if ($dari && $sampai && $dari > $sampai) {
    [$dari, $sampai] = [$sampai, $dari];
    $tanggalDibalik = true;
}

$pakaiRentang = ($dari !== null || $sampai !== null);
if ($pakaiRentang) {
    $bagian = [];
    if ($dari)   { $bagian[] = "d.tanggal >= " . $pdo->quote($dari); }
    if ($sampai) { $bagian[] = "d.tanggal <= " . $pdo->quote($sampai); }
    $syaratPeriode = implode(' AND ', $bagian);

    $labelPeriode = $dari && $sampai ? tgl_id($dari) . ' – ' . tgl_id($sampai)
                  : ($dari ? 'sejak ' . tgl_id($dari) : 'sampai ' . tgl_id($sampai));
} else {
    $labelPeriode = $PERIODE[$periode][0];
}

$gCara = (string) ($_GET['cara'] ?? '');
$syaratCara = isset(CARA_TERIMA_DONASI[$gCara])
    ? "d.cara_terima = " . $pdo->quote($gCara) : '1=1';

$gJenis = (string) ($_GET['jenis'] ?? '');
$syaratJenis = isset(JENIS_DONASI[$gJenis])
    ? "d.jenis = " . $pdo->quote($gJenis) : '1=1';

/* ----------------------------------------------------------------------
   PENYARING EKSPEDISI — dibangun dari ISI DATA, bukan dari daftar saran

   Daftar saran di formulir penerimaan hanya mempercepat pengetikan; isian
   ekspedisinya kotak teks bebas, dan memang harus begitu — ada donasi yang
   datang lewat kurir daerah yang tidak akan pernah ada di daftar mana pun.

   Kalau penyaring ini dibangun dari EKSPEDISI_SARAN, kurir seperti itu
   tidak akan punya pilihannya sendiri dan donasinya hanya bisa ditemukan
   lewat pencarian teks — padahal justru itu yang perlu dilaporkan.

   Dihitung dalam PERIODE yang sedang berlaku tapi mengabaikan saringan
   ekspedisi itu sendiri, supaya memilih satu kurir tidak membuat pilihan
   kurir lain hilang dari daftarnya.
   ---------------------------------------------------------------------- */
$gEks = trim((string) ($_GET['eks'] ?? ''));
$syaratEks = $gEks !== ''
    ? ($gEks === '(kosong)'
        ? "(d.no_resi IS NULL OR d.no_resi = '') AND d.cara_terima = 'dikirim'"
        : "d.ekspedisi = " . $pdo->quote($gEks))
    : '1=1';

$daftarEks = $pdo->query(
    "SELECT d.ekspedisi AS nama, COUNT(*) AS n
     FROM donasi d
     WHERE $syaratPeriode AND d.ekspedisi IS NOT NULL AND d.ekspedisi <> ''
     GROUP BY d.ekspedisi ORDER BY n DESC, d.ekspedisi")->fetchAll();

$q = trim((string) ($_GET['q'] ?? ''));
$syaratCari = syarat_cari($pdo, $q, ['d.no_donasi', 'd.no_resi', 'd.ekspedisi', 'dn.nama']);

$dasar = "FROM donasi d
          LEFT JOIN donatur dn ON dn.id = d.donatur_id
          WHERE $syaratPeriode AND $syaratCara AND $syaratJenis
            AND $syaratEks AND $syaratCari";

/* ------------------------------------------------------------ ringkasan
   Dihitung dengan syarat PERIODE saja, bukan seluruh syarat. Kartu
   ringkasan yang ikut tersaring oleh pilihan "cara terima" akan berbunyi
   "Dikirim ekspedisi 12, Diantar 0" begitu saringan dipasang — angka yang
   terlihat seperti kenyataan padahal cuma cerminan saringannya sendiri. */
$ringkas = [];
foreach (CARA_TERIMA_DONASI as $kunci => $def) {
    $r = $pdo->query(
        "SELECT COUNT(*) n,
                COALESCE((SELECT SUM(dd.qty) FROM donasi_detail dd
                           JOIN donasi d2 ON d2.id = dd.donasi_id
                          WHERE d2.cara_terima = " . $pdo->quote($kunci) . "
                            AND " . str_replace('d.tanggal', 'd2.tanggal', $syaratPeriode) . "),0) unit
         FROM donasi d
         WHERE d.cara_terima = " . $pdo->quote($kunci) . " AND $syaratPeriode")
        ->fetch(PDO::FETCH_ASSOC);
    $ringkas[$kunci] = ['n' => (int) $r['n'], 'unit' => (int) $r['unit'], 'def' => $def];
}

/* Paket yang resinya belum ditulis — satu-satunya bukti yang dipunyai
   donasi tanpa tatap muka, jadi kekosongannya perlu disebut, bukan
   dibiarkan tenggelam di antara baris lain. */
$resiKosong = (int) $pdo->query(
    "SELECT COUNT(*) FROM donasi d
     WHERE d.cara_terima = 'dikirim' AND $syaratPeriode
       AND (d.no_resi IS NULL OR d.no_resi = '')")->fetchColumn();

$perHal     = 30;
$totalBaris = (int) $pdo->query("SELECT COUNT(*) $dasar")->fetchColumn();
[$hal, $offset, $jmlHal] = paginasi_hitung($totalBaris, $perHal);

$list = $pdo->query(
    "SELECT d.id, d.no_donasi, d.tanggal, d.jenis, d.cara_terima, d.ekspedisi,
            d.no_resi, d.catatan,
            dn.nama AS donatur_nama, dn.anonim AS donatur_anonim,
            dn.wilayah AS donatur_wilayah, dn.jenis AS donatur_jenis,
            (SELECT COUNT(*)              FROM donasi_detail x WHERE x.donasi_id = d.id) jml_item,
            (SELECT COALESCE(SUM(x.qty),0) FROM donasi_detail x WHERE x.donasi_id = d.id) unit
     $dasar
     ORDER BY d.tanggal DESC, d.id DESC
     LIMIT $perHal OFFSET $offset")->fetchAll();

/* ----------------------------------------------------------------------
   RINCIAN BARANG TIAP DONASI

   Diambil dalam SATU kueri lalu dikelompokkan — bukan satu kueri per
   donasi. Tiga puluh donasi yang masing-masing menembak kuerinya sendiri
   berarti tiga puluh satu perjalanan ke basis data untuk satu halaman.

   Selalu diambil, tidak hanya saat dicetak: halaman cetak dibuat peramban
   dari halaman yang SUDAH tergambar, jadi tidak ada kesempatan mengambil
   data tambahan setelah tombol Cetak ditekan. Rinciannya harus sudah ada
   di halaman sejak awal, tinggal disembunyikan di layar dan dimunculkan
   oleh aturan @media print.
   ---------------------------------------------------------------------- */
$rinci = [];
if ($list) {
    $idDon = implode(',', array_map(fn($d) => (int) $d['id'], $list));
    $rinci = kelompokkan($pdo->query(
        "SELECT dd.donasi_id, dd.qty, dd.kondisi, dd.nomor_batch, dd.tanggal_expired,
                dd.nilai_taksir, dd.keterangan,
                COALESCE(b.nama, dd.nama_barang_teks, '—') AS nama_barang,
                b.kode AS kode_barang, b.satuan
         FROM donasi_detail dd
         LEFT JOIN barang b ON b.id = dd.barang_id
         WHERE dd.donasi_id IN ($idDon)
         ORDER BY dd.id")->fetchAll(), 'donasi_id');
}

$PAGE_TITLE = 'Laporan Donasi'; $ACTIVE = 'laporan';
require __DIR__ . '/_header.php';

/** Tautan yang mempertahankan saringan lain. */
function tautan(array $ganti): string {
    $p = array_filter(array_merge([
        'periode' => $_GET['periode'] ?? null,
        'cara'    => $_GET['cara'] ?? null,
        'jenis'   => $_GET['jenis'] ?? null,
        'q'       => $_GET['q'] ?? null,
        'dari'    => $_GET['dari'] ?? null,
        'sampai'  => $_GET['sampai'] ?? null,
        'eks'     => $_GET['eks'] ?? null,
    ], $ganti), fn($v) => $v !== null && $v !== '');
    return '?' . http_build_query($p);
}

/* Menekan pil periode MEMBUANG rentang sendiri. Kalau tidak, pilnya
   terlihat terpilih sementara yang berlaku tetap rentangnya — penyaring
   yang menampilkan satu hal dan mengerjakan hal lain. */
function tautanPil(string $k): string {
    return tautan(['periode' => $k, 'dari' => '', 'sampai' => '']);
}
?>
<div class="page-head">
  <div>
    <h1>Laporan Donasi</h1>
    <p>Donasi kunjungan dan donasi paket, terpisah · <?= e($labelPeriode) ?></p>
  </div>
  <button class="btn btn-ghost" onclick="window.print()">
    <svg aria-hidden="true"><use href="#i-cetak"></use></svg> Cetak</button>
</div>

<?php /* ------------------------------------------------- kartu ringkasan */ ?>
<div class="kpi">
  <?php foreach ($ringkas as $kunci => $r):
    $aktif = $gCara === $kunci;
    $warna = $kunci === 'diantar' ? 'var(--green)'
           : ($kunci === 'dikirim' ? 'var(--info)' : 'var(--amber)'); ?>
    <a class="kpi-card<?= $aktif ? ' perlu' : '' ?>" style="--kc:<?= $warna ?>;text-decoration:none"
       href="<?= e(tautan(['cara' => $aktif ? '' : $kunci])) ?>">
      <div class="kpi-top">
        <span class="kpi-l"><?= e($r['def'][0]) ?></span>
      </div>
      <div class="kpi-v"><?= angka($r['n']) ?></div>
      <div class="kpi-d"><?= angka($r['unit']) ?> unit<?= $aktif ? ' · sedang disaring' : '' ?></div>
    </a>
  <?php endforeach; ?>
</div>

<?php if ($resiKosong > 0): ?>
  <div class="flash flash-err" style="margin-bottom:14px">
    <b><?= angka($resiKosong) ?> donasi paket belum punya nomor resi.</b>
    Paket tidak bertatap muka — resi satu-satunya bukti bahwa barangnya benar
    dikirim dan tiba. Lengkapi sebelum laporan ditutup.
    <a href="<?= e(tautan(['cara' => 'dikirim'])) ?>" style="text-decoration:underline">Lihat</a>
  </div>
<?php endif; ?>

<div class="card">
  <?php /* Periode sebagai pil, cara & jenis sebagai tautan — ketiganya
           mempertahankan saringan yang lain lewat tautan(). */ ?>
  <div class="saring-baris" style="margin-bottom:14px">
    <div class="saring-grup">
      <span class="saring-lbl">Periode</span>
      <div class="saring-pil">
        <?php foreach ($PERIODE as $k => $v): ?>
          <?php /* Tidak ada pil yang aktif selama rentang sendiri berlaku —
                   pil terpilih sementara yang mengerjakan rentangnya adalah
                   penyaring yang berbohong tentang dirinya sendiri. */ ?>
          <a class="pil<?= (!$pakaiRentang && $k === $periode) ? ' aktif' : '' ?>"
             href="<?= e(tautanPil($k)) ?>"><?= e($v[0]) ?></a>
        <?php endforeach; ?>
      </div>
    </div>
    <div class="saring-grup">
      <span class="saring-lbl">Jenis</span>
      <div class="saring-pil">
        <a class="pil<?= $gJenis === '' ? ' aktif' : '' ?>"
           href="<?= e(tautan(['jenis' => ''])) ?>">Semua</a>
        <?php foreach (JENIS_DONASI as $k => $v): ?>
          <a class="pil<?= $k === $gJenis ? ' aktif' : '' ?>"
             href="<?= e(tautan(['jenis' => $k])) ?>"><?= e($v[0]) ?></a>
        <?php endforeach; ?>
      </div>
    </div>
  </div>

  <?php /* Barisan ekspedisi berdiri SENDIRI, bukan disisipkan ke baris di
           atas. Jumlahnya tumbuh mengikuti kurir yang benar-benar dipakai —
           sepuluh pil berbaur dengan pil periode dan jenis akan membuat
           ketiganya terbaca sebagai satu kumpulan pilihan yang sama.

           Hanya muncul bila memang ada donasi berekspedisi pada periode
           ini; barisan label tanpa satu pun pilihan hanya memakan ruang. */ ?>
  <?php if ($daftarEks): ?>
    <div class="saring-baris" style="margin-bottom:14px">
      <div class="saring-grup" style="align-items:flex-start">
        <span class="saring-lbl" style="padding-top:7px">Ekspedisi</span>
        <div class="saring-pil" style="flex-wrap:wrap">
          <a class="pil<?= $gEks === '' ? ' aktif' : '' ?>"
             href="<?= e(tautan(['eks' => ''])) ?>">Semua</a>
          <?php foreach ($daftarEks as $ek): ?>
            <a class="pil<?= $gEks === $ek['nama'] ? ' aktif' : '' ?>"
               href="<?= e(tautan(['eks' => $ek['nama']])) ?>">
              <?= e($ek['nama']) ?> <b><?= angka($ek['n']) ?></b></a>
          <?php endforeach; ?>
          <?php /* Paket tanpa resi dijadikan pilihan tersendiri di baris ini,
                   bukan hanya peringatan di atas: yang menyusun laporan perlu
                   bisa MEMBUKA daftarnya, bukan sekadar tahu jumlahnya. */ ?>
          <?php if ($resiKosong > 0): ?>
            <a class="pil<?= $gEks === '(kosong)' ? ' aktif' : '' ?>"
               href="<?= e(tautan(['eks' => '(kosong)'])) ?>"
               style="<?= $gEks === '(kosong)' ? '' : 'color:var(--red)' ?>">
              Belum ada resi <b><?= angka($resiKosong) ?></b></a>
          <?php endif; ?>
        </div>
      </div>
    </div>
  <?php endif; ?>

  <?php /* --------------------------------------------------------------
     RENTANG TANGGAL SENDIRI

     Formulir GET tersendiri, bukan ditempel ke kotak cari: keduanya
     dikirim pada saat yang berbeda — orang memilih rentang sekali lalu
     mencari berkali-kali di dalamnya. Digabung, tiap pencarian memaksa
     rentangnya ikut dikirim ulang dan satu kotak tanggal yang tak sengaja
     dikosongkan menghapus seluruh pilihan periodenya.

     Saringan lain dibawa sebagai hidden supaya tidak hilang.
     -------------------------------------------------------------------- */ ?>
  <form method="get" class="saring-baris" style="margin-bottom:14px">
    <?php foreach (['cara' => $gCara, 'jenis' => $gJenis, 'q' => $q] as $n => $v): ?>
      <?php if ($v !== ''): ?><input type="hidden" name="<?= e($n) ?>" value="<?= e($v) ?>"><?php endif; ?>
    <?php endforeach; ?>
    <div class="saring-grup">
      <span class="saring-lbl">Tanggal Sendiri</span>
      <input type="date" name="dari" value="<?= e($dari ?? '') ?>" aria-label="Tanggal mulai">
      <span style="color:var(--muted)">s/d</span>
      <input type="date" name="sampai" value="<?= e($sampai ?? '') ?>" aria-label="Tanggal akhir">
      <button class="btn btn-primary btn-sm" type="submit">Terapkan</button>
      <?php if ($pakaiRentang): ?>
        <a class="btn btn-ghost btn-sm" href="<?= e(tautanPil('bulan')) ?>">Hapus rentang</a>
      <?php endif; ?>
    </div>
  </form>

  <?php if ($tanggalDibalik): ?>
    <p class="petunjuk" style="margin:-6px 0 12px">
      Tanggal mulai lebih akhir daripada tanggal akhir — keduanya dibalik,
      jadi yang ditampilkan <b><?= e($labelPeriode) ?></b>. Periksa lagi kalau
      bukan itu yang Anda maksud.
    </p>
  <?php endif; ?>

  <?php cari_bar($q, 'Cari no. donasi, nomor resi, ekspedisi, atau nama donatur…',
                 $q !== '' ? $totalBaris : null,
                 ['periode' => $periode, 'cara' => $gCara, 'jenis' => $gJenis,
                  'dari' => $dari ?? '', 'sampai' => $sampai ?? '', 'eks' => $gEks]); ?>

  <div class="tbl-wrap"><table class="tbl-kartu">
    <thead><tr>
      <th>No. Donasi</th><th>Tanggal</th><th>Donatur</th><th>Jenis</th>
      <th>Cara Terima</th><th>Ekspedisi / Resi</th><th class="num">Isi</th>
    </tr></thead>
    <tbody>
    <?php if (!$list): ?>
      <tr><td colspan="7" class="empty">Belum ada donasi pada saringan ini.</td></tr>
    <?php else: foreach ($list as $d):
      [$jLbl, $jCls] = label_jenis_donasi($d['jenis']);
      [$cLbl, $cCls] = label_cara_terima($d['cara_terima']);
      $perluResi = cara_terima_butuh_resi($d['cara_terima']);
    ?>
      <tr>
        <td data-label="No. Donasi"><code><?= e($d['no_donasi']) ?></code></td>
        <td class="sekunder" data-label="Tanggal"><?= e(tgl_id($d['tanggal'])) ?></td>
        <?php /* Wilayah & jenis donatur ikut ditampilkan — laporan yang
                 dicetak dibaca orang yang tidak membuka aplikasinya, dan
                 nama sendirian sering tidak cukup membedakan dua donatur
                 bernama sama.

                 NOMOR TELEPON SENGAJA TIDAK DIMUAT. Ia tidak dibutuhkan
                 untuk mempertanggungjawabkan apa pun, sementara laporan
                 cetak berpindah tangan dan difoto. Yang butuh menghubungi
                 donatur membukanya di halaman Donatur, bukan dari lembar
                 yang beredar. */ ?>
        <td class="judul" data-label="Donatur">
          <b><?= e(nama_donatur_tampil([
                'nama'    => $d['donatur_nama'],
                'anonim'  => $d['donatur_anonim'],
                'wilayah' => $d['donatur_wilayah'],
             ])) ?></b>
          <?php if (empty($d['donatur_anonim'])):
            $ketDon = array_filter([
                JENIS_DONATUR[$d['donatur_jenis'] ?? ''] ?? null,
                $d['donatur_wilayah'] ?: null,
            ]);
            if ($ketDon): ?>
            <br><small class="mut2"><?= e(implode(' · ', $ketDon)) ?></small>
          <?php endif; endif; ?></td>
        <td class="sekunder" data-label="Jenis"><span class="tag <?= e($jCls) ?>"><?= e($jLbl) ?></span></td>
        <td data-label="Cara Terima"><span class="tag <?= e($cCls) ?>"><?= e($cLbl) ?></span></td>
        <td class="sekunder" data-label="Ekspedisi / Resi">
          <?php if (!$perluResi): ?>
            <small class="mut">—</small>
          <?php elseif (empty($d['no_resi']) && empty($d['ekspedisi'])): ?>
            <span class="tag t-red">resi belum ditulis</span>
          <?php else: ?>
            <?= $d['ekspedisi'] ? '<b>' . e($d['ekspedisi']) . '</b><br>' : '' ?>
            <?= $d['no_resi'] ? '<code>' . e($d['no_resi']) . '</code>'
                              : '<span class="tag t-amber">resi kosong</span>' ?>
          <?php endif; ?>
        </td>
        <td class="num" data-label="Isi"><b><?= angka($d['unit']) ?></b> unit<br>
          <?= tombol_rincian('rdon' . (int) $d['id'], (int) $d['jml_item']) ?></td>
      </tr>

      <?php /* Baris rincian: tersembunyi di layar sampai dibuka, TAPI
               selalu tergambar saat dicetak — lihat aturan @media print.
               Laporan yang dicetak dibaca tanpa aplikasinya, jadi "3 unit"
               tanpa menyebut barangnya apa tidak bisa dipertanggungjawabkan
               oleh siapa pun yang memegang lembarannya. */
            $isiDon = $rinci[(int) $d['id']] ?? []; ?>
      <tr class="baris-rinci cetak-buka" id="rdon<?= (int) $d['id'] ?>" hidden>
        <td colspan="7">
          <div class="rinci-isi">
            <?php if (!$isiDon): ?>
              <div class="mut" style="font-size:12.5px">Donasi ini tidak punya rincian barang.</div>
            <?php else: ?>
              <div class="judul">Isi <?= e($d['no_donasi']) ?> —
                <?= e(nama_donatur_tampil([
                      'nama'   => $d['donatur_nama'],
                      'anonim' => $d['donatur_anonim'],
                    ])) ?></div>
              <table>
                <thead><tr>
                  <th>Barang</th><th>Kondisi</th><th>Batch</th><th>Kedaluwarsa</th>
                  <th class="num">Jumlah</th><th>Keterangan</th>
                </tr></thead>
                <tbody>
                <?php foreach ($isiDon as $it):
                  [$lblK, $wrnK] = label_kondisi($it['kondisi'] ?? 'baru'); ?>
                  <tr>
                    <td><b><?= e($it['nama_barang']) ?></b>
                      <?= $it['kode_barang'] ? '<br><small class="mut2">' . e($it['kode_barang']) . '</small>' : '' ?></td>
                    <td><span class="tag <?= e($wrnK) ?>"><?= e($lblK) ?></span></td>
                    <td><?= $it['nomor_batch'] ? '<code>' . e($it['nomor_batch']) . '</code>' : '—' ?></td>
                    <td><?= $it['tanggal_expired'] ? e(tgl_id($it['tanggal_expired'])) : '—' ?></td>
                    <td class="num"><b><?= angka($it['qty']) ?></b>
                      <?= $it['satuan'] ? '<small>' . e($it['satuan']) . '</small>' : '' ?></td>
                    <td><?= e($it['keterangan'] ?: '—') ?></td>
                  </tr>
                <?php endforeach; ?>
                </tbody>
              </table>
            <?php endif; ?>
          </div>
        </td>
      </tr>
    <?php endforeach; endif; ?>
    </tbody>
  </table></div>

  <?php /* ======================================================================
     TABEL KHUSUS CETAK — datar, satu baris per barang

     Tabel di atas dirancang untuk LAYAR: ringkas, dengan rincian yang
     dibuka saat diketuk. Dipaksa tercetak, ia jatuh ke tata letak kartu —
     aturan ponsel ikut berlaku karena pratinjau cetak memakai lebar
     sempit — dan hasilnya satu barang per kotak besar, berhalaman-halaman,
     dengan ruang kosong di mana-mana.

     Jadi lembar cetak punya tabelnya SENDIRI, bukan tabel layar yang
     dipaksa-paksa. Bentuknya datar: satu baris per barang, keterangan
     donasinya diulang di tiap baris.

     DIULANG, bukan digabung dengan rowspan. Sel bergabung yang terpotong
     pergantian halaman meninggalkan daftar barang tanpa kepala barisnya di
     halaman berikutnya — pembacanya harus membalik kembali untuk tahu itu
     donasi siapa. Pengulangan memang memakan tinta, tetapi tiap baris jadi
     bisa dibaca berdiri sendiri, dan itulah yang dibutuhkan lembar yang
     berpindah tangan.
     ====================================================================== */ ?>
  <div class="hanya-cetak">
    <table class="tabel-cetak">
      <thead><tr>
        <th>Tanggal</th><th>No. Donasi</th><th>Donatur</th>
        <th>Barang</th><th class="num">Jumlah</th><th>Cara Terima</th>
      </tr></thead>
      <tbody>
      <?php if (!$list): ?>
        <tr><td colspan="6">Tidak ada donasi pada saringan ini.</td></tr>
      <?php else:
        $totCetak = 0;
        foreach ($list as $d):
          $isiD = $rinci[(int) $d['id']] ?? [];
          [$cLbl] = label_cara_terima($d['cara_terima']);
          $metode = $cLbl;
          if (cara_terima_butuh_resi($d['cara_terima'])) {
              $tambahan = array_filter([$d['ekspedisi'] ?: null, $d['no_resi'] ?: null]);
              $metode .= $tambahan ? ' — ' . implode(' / ', $tambahan) : ' — resi belum ditulis';
          }
          $namaDon = nama_donatur_tampil([
              'nama'    => $d['donatur_nama'],
              'anonim'  => $d['donatur_anonim'],
              'wilayah' => $d['donatur_wilayah'],
          ]);
          /* Donasi tanpa rincian tetap mendapat satu baris. Kalau
             dilewati, jumlah baris di lembar tidak cocok dengan angka
             ringkasan di atasnya dan pemeriksanya mencari yang hilang. */
          $baris = $isiD ?: [null];
          foreach ($baris as $it):
            $totCetak += (int) ($it['qty'] ?? 0); ?>
          <tr>
            <td><?= e(tgl_id($d['tanggal'])) ?></td>
            <td><?= e($d['no_donasi']) ?></td>
            <td><?= e($namaDon) ?></td>
            <td><?php if ($it === null): ?>
                  <i>tanpa rincian barang</i>
                <?php else: ?>
                  <?= e($it['nama_barang']) ?><?php
                  $ket = array_filter([
                      $it['kode_barang'] ?: null,
                      $it['nomor_batch'] ? 'batch ' . $it['nomor_batch'] : null,
                      $it['tanggal_expired'] ? 'exp ' . tgl_id($it['tanggal_expired']) : null,
                  ]);
                  if ($ket) echo ' <span class="kecil">(' . e(implode(' · ', $ket)) . ')</span>'; ?>
                <?php endif; ?></td>
            <td class="num"><?= $it === null ? '—'
                  : angka($it['qty']) . ' ' . e($it['satuan'] ?? '') ?></td>
            <td><?= e($metode) ?></td>
          </tr>
        <?php endforeach; endforeach; ?>
        <tr class="total">
          <td colspan="4">Total <?= angka(count($list)) ?> donasi</td>
          <td class="num"><?= angka($totCetak) ?> unit</td>
          <td></td>
        </tr>
      <?php endif; ?>
      </tbody>
    </table>
    <p class="kaki-cetak">
      <?= e($labelPeriode) ?><?= $gCara !== '' ? ' · ' . e(label_cara_terima($gCara)[0]) : '' ?><?php
        if ($gJenis !== '') echo ' · ' . e(JENIS_DONASI[$gJenis][0]);
        if ($gEks !== '')   echo ' · ' . e($gEks === '(kosong)' ? 'belum ada resi' : $gEks); ?>
      · dicetak <?= e(tgl_jam_id(date('Y-m-d H:i:s'))) ?>
      <?php if ($totalBaris > count($list)): ?>
        · <b>halaman <?= (int) $hal ?> dari <?= (int) $jmlHal ?></b>
          (<?= angka(count($list)) ?> dari <?= angka($totalBaris) ?> donasi)
      <?php endif; ?>
    </p>
  </div>

  <?php paginasi_nav($hal, $jmlHal, $totalBaris, $perHal,
        ['periode' => $periode, 'cara' => $gCara, 'jenis' => $gJenis, 'q' => $q,
         'dari' => $dari ?? '', 'sampai' => $sampai ?? '', 'eks' => $gEks]); ?>
</div>

<?php require __DIR__ . '/_footer.php'; ?>
