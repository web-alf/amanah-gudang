<?php
/**
 * AMANAH — Aplikasi Manajemen Amanah & Hibah
 * Sistem manajemen gudang & rantai pasok
 *
 * _lib.php — fungsi bersama untuk seluruh halaman.
 * Selalu di-require SETELAH config.php dan auth.php.
 */

// ---------------------------------------------------------------------
// Identitas aplikasi
// ---------------------------------------------------------------------
const APP_NAMA      = 'AMANAH';
const APP_KEPANJANGAN = 'Aplikasi Manajemen Amanah & Hibah';
/* Nama lembaga. Sengaja KOSONG: sistem ini tidak terikat pada satu
   organisasi. Isi satu baris ini kalau ingin namanya tercetak di
   halaman masuk, footer, dan kop surat jalan. Semua tempat yang
   memakainya lewat ident() akan melewatinya bila kosong. */
const APP_LEMBAGA   = '';
const APP_VERSI     = '3.0';
const APP_TAGLINE   = 'Setiap titipan tercatat, setiap penyaluran terlacak';

// Dipakai di kop surat jalan. Kosongkan bagian yang tidak dipakai —
// barisnya otomatis tidak dicetak.
const SURAT_ALAMAT  = '';
const SURAT_TELEPON = '';
const SURAT_EMAIL   = '';
const SURAT_WEB     = '';

// ---------------------------------------------------------------------
// Kondisi dan sumber barang — DUA SUMBU YANG BERBEDA
// ---------------------------------------------------------------------
/* Dulu keduanya berbagi satu kolom `kondisi`: 'baru' berarti pembelian,
   'bekas' berarti donasi — lihat komentar kolomnya di instal_database.sql
   dan penjelasannya di alur.php. Itu tidak bisa dipertahankan begitu
   kelayakan pakai ikut dicatat, karena barang donasi yang masih tersegel
   harus bisa berkata "baru" secara fisik DAN "donasi" secara asal pada
   waktu yang sama. Selama satu kolom memikul dua arti, salah satunya
   pasti berbohong.

   Sejak migrasi_kondisi_barang.sql + migrasi_sumber_barang.sql:
     kondisi -> keadaan fisik, menentukan boleh disalurkan atau tidak
     sumber  -> asal barang,   menentukan masuk hitungan donasi atau tidak

   Penilaian kondisi dilakukan SUPPLIER saat mencatat barang; gudang
   membacanya dan tidak menilai ulang. Kalau kedua pihak sama-sama menilai,
   akan ada dua penilaian berbeda atas barang yang sama tanpa ada yang bisa
   memutuskan mana yang benar.

   Revisi tim gudang, Oktober 2026 (butir 3): rusak/bocor dan expired dibedakan.
   Pilihan untuk input baru: Baru, Layak, Rusak/Bocor, Expired. Nilai lama
   bekas_tidak_layak TETAP sah — seluruh data lama memakainya — tetapi tidak
   ditawarkan lagi (lihat kondisi_tawaran()).

   Bentuk tiap baris: [label, kelas warna, ikon, boleh disalurkan, ditawarkan]. */
const KONDISI_BARANG = [
    'baru'              => ['Baru',               't-green', 'i-plus',  true,  true],
    'bekas_layak'       => ['Layak',              't-amber', 'i-putar', true,  true],
    'rusak'             => ['Rusak/Bocor',        't-red',   'i-tolak', false, true],
    'expired'           => ['Expired',            't-red',   'i-cal',   false, true],
    'bekas_tidak_layak' => ['Tidak layak (lama)', 't-red',   'i-tolak', false, false],
];

/**
 * Kondisi yang ditawarkan di formulir, berurutan. [$sertakan] = nilai yang
 * sedang tersimpan pada baris yang diedit: ikut ditawarkan walau sudah tidak
 * untuk input baru, supaya menyunting batch lama tidak memaksa ganti kondisi.
 */
function kondisi_tawaran(?string $sertakan = null): array {
    $h = [];
    foreach (KONDISI_BARANG as $k => $v) {
        if ($v[4] || $k === $sertakan) { $h[$k] = $v; }
    }
    return $h;
}

/** Nilai kondisi yang TIDAK layak salur, siap untuk klausa IN (...) SQL. */
function kondisi_tidak_layak_sql(): string {
    return implode(',', array_map(fn($k) => "'$k'", array_keys(array_filter(KONDISI_BARANG, fn($v) => !$v[3]))));
}

/** Bentuk tiap baris: [label, kelas warna, ikon]. */
const SUMBER_BARANG = [
    'pembelian' => ['Pembelian', 't-blue',   'i-uang'],
    'donasi'    => ['Donasi',    't-purple', 'i-users'],
];

/**
 * Saring nilai kondisi dari luar menjadi nilai yang pasti sah.
 *
 * Dipakai di SETIAP tempat yang menerima kondisi dari formulir atau dari
 * baris dokumen. Jangan menulis daftar nilainya lagi di tempat lain: dulu
 * daftar itu ditulis ulang di tambah_ke_lokasi() dan ketinggalan saat enum
 * bertambah, sehingga setiap 'bekas_layak' yang masuk diubah diam-diam
 * menjadi 'baru' — tanpa galat, tanpa jejak.
 */
function kondisi_sah(?string $kondisi): string {
    return isset(KONDISI_BARANG[$kondisi]) ? $kondisi : 'baru';
}

function sumber_sah(?string $sumber): string {
    return isset(SUMBER_BARANG[$sumber]) ? $sumber : 'pembelian';
}

/**
 * Batas tanggal kedaluwarsa yang masuk akal.
 *
 * Dipakai bersama tanggal_expired_sah() dan halaman Batch & Expired, supaya
 * ambangnya satu — bukan satu angka di penyaring dan angka lain di tampilan.
 */
const EXP_PALING_TUA  = '2000-01-01';
const EXP_TAHUN_DEPAN = 50;           // tahun ke depan yang masih diterima

/**
 * Bersihkan tanggal kedaluwarsa: kembalikan null bila tidak masuk akal.
 *
 * ---------------------------------------------------------------------------
 * KENAPA ADA
 * ---------------------------------------------------------------------------
 * Terlihat di data sungguhan: batch bertanggal kedaluwarsa `0001-01-01`,
 * yang dihitung sebagai "lewat 739.886 hari". Akibatnya bukan sekadar angka
 * ganjil di layar — baris itu menempati puncak daftar "Kedaluwarsa Terdekat"
 * yang diurutkan dari yang paling mendesak, sehingga barang yang benar-benar
 * akan kedaluwarsa pekan ini terdorong ke bawah oleh sampah data.
 *
 * Sebelumnya tanggal diteruskan apa adanya ke basis data tanpa satu pun
 * pemeriksaan. MySQL menerima `0001-01-01` tanpa mengeluh, dan tidak ada
 * yang tahu sampai ada yang membaca layarnya.
 *
 * Yang DITERIMA termasuk tanggal yang sudah lewat — stok yang memang sudah
 * kedaluwarsa wajib tetap terlihat; itu justru inti halaman Batch & Expired.
 * Yang ditolak hanya yang mustahil: bukan bentuk tanggal, atau jauh di luar
 * rentang yang mungkin dimiliki barang gudang.
 *
 * @return string|null Tanggal Y-m-d yang sah, atau null bila tidak masuk akal.
 */
function tanggal_expired_sah(?string $tgl): ?string {
    $tgl = trim((string) $tgl);
    if ($tgl === '') { return null; }

    /* Dipotong ke bagian tanggalnya saja — sebagian sumber mengirim
       "2027-03-15 00:00:00", dan itu tanggal yang sah. */
    $tgl = substr($tgl, 0, 10);

    $d = DateTime::createFromFormat('Y-m-d', $tgl);
    // Bentuk yang benar TAPI tanggalnya tidak ada (31 Feb) ditolak juga:
    // createFromFormat menggesernya diam-diam ke 3 Maret.
    if (!$d || $d->format('Y-m-d') !== $tgl) { return null; }

    if ($tgl < EXP_PALING_TUA) { return null; }
    $batasAtas = (new DateTime())->modify('+' . EXP_TAHUN_DEPAN . ' years')->format('Y-m-d');
    if ($tgl > $batasAtas) { return null; }

    return $tgl;
}

/** Apakah barang berkondisi ini boleh ikut disalurkan ke penerima. */
function kondisi_boleh_salur(?string $kondisi): bool {
    return KONDISI_BARANG[kondisi_sah($kondisi)][3];
}

/** Daftar nilai kondisi yang boleh disalurkan — untuk klausa IN(...) SQL. */
function kondisi_layak_salur(): array {
    return array_keys(array_filter(KONDISI_BARANG, fn($k) => $k[3]));
}

/**
 * Jenis surat jalan yang HANYA boleh membawa stok siap salur (revisi tim
 * gudang, Oktober 2026, butir 5). Yang keluar ke penerima amanah atau ke
 * toko harus layak; sedangkan transfer antar gudang, mutasi rak, dan retur
 * justru sering dipakai memindahkan barang rusak/expired — ke Gudang Widuri
 * (drop barang bekas) atau kembali ke supplier — jadi tidak disaring.
 */
function jenis_hanya_layak(string $jenis): bool {
    return in_array($jenis, ['penyaluran', 'kirim_toko', 'antar_toko'], true);
}

/** Stok SIAP SALUR satu barang di rak / gudang / toko / seluruh gudang — pelengkap stok_layak_salur() yang hanya per gudang. */
function stok_layak_salur_lokasi(PDO $pdo, int $barangId, ?int $rakId = null, ?int $gudangId = null, ?int $tokoId = null): int {
    $layak = kondisi_layak_salur();
    $in    = implode(',', array_fill(0, count($layak), '?'));
    if ($tokoId) {
        $sql = "SELECT COALESCE(SUM(qty_tersisa),0) FROM batch_barang WHERE barang_id = ? AND toko_id = ? AND qty_tersisa > 0 AND kondisi IN ($in)" . sql_tanpa_kardus($pdo, 'batch_barang');
        $p = [$barangId, $tokoId];
    } elseif ($gudangId) {
        $sql = "SELECT COALESCE(SUM(bb.qty_tersisa),0) FROM batch_barang bb JOIN lokasi_rak lr ON lr.id = bb.lokasi_rak_id
                WHERE bb.barang_id = ? AND lr.gudang_id = ? AND lr.deleted_at IS NULL AND bb.qty_tersisa > 0 AND bb.kondisi IN ($in)" . sql_tanpa_kardus($pdo);
        $p = [$barangId, $gudangId];
    } elseif ($rakId) {
        $sql = "SELECT COALESCE(SUM(qty_tersisa),0) FROM batch_barang WHERE barang_id = ? AND lokasi_rak_id = ? AND qty_tersisa > 0 AND kondisi IN ($in)" . sql_tanpa_kardus($pdo, 'batch_barang');
        $p = [$barangId, $rakId];
    } else {
        $sql = "SELECT COALESCE(SUM(qty_tersisa),0) FROM batch_barang WHERE barang_id = ? AND toko_id IS NULL AND qty_tersisa > 0 AND kondisi IN ($in)" . sql_tanpa_kardus($pdo, 'batch_barang');
        $p = [$barangId];
    }
    $st = $pdo->prepare($sql);
    $st->execute(array_merge($p, $layak));
    return (int) $st->fetchColumn();
}

// ---------------------------------------------------------------------
// Format
// ---------------------------------------------------------------------
/**
 * Gabungkan beberapa penggalan judul dengan " · ", melewati yang kosong.
 *
 * Dipakai supaya nama lembaga boleh dibiarkan kosong tanpa meninggalkan
 * pemisah menggantung seperti "Toko · " di judul halaman dan kop surat.
 */
function ident(string ...$bagian): string {
    $isi = array_filter(array_map('trim', $bagian), fn($x) => $x !== '');
    return implode(' · ', $isi);
}

function rupiah($n): string {
    return 'Rp' . number_format((float) $n, 0, ',', '.');
}

function angka($n): string {
    return number_format((float) $n, 0, ',', '.');
}

/**
 * Tanggal Bahasa Indonesia.
 *
 * $gaya:
 *   'pendek'  → 24 Agu 2026
 *   'panjang' → 24 Agustus 2026
 *   'hari'    → Senin, 24 Agustus 2026          (tanpa jam)
 *   'lengkap' → Senin, 24 Agustus 2026 14:30    (dengan jam)
 *
 * Gaya 'hari' ditambahkan karena 'lengkap' SELALU menempelkan jam, termasuk
 * saat yang diberikan hanya tanggal. strtotime('2026-08-24') menghasilkan
 * tengah malam, jadi hasilnya "… 00:00" — jam yang tidak pernah dimaksudkan
 * siapa pun, dan terbaca seperti jam yang berhenti. Kalau yang dibutuhkan
 * hanya tanggal berhari, pakai 'hari'; 'lengkap' hanya untuk nilai yang
 * benar-benar punya jam (kolom DATETIME/TIMESTAMP, atau date('Y-m-d H:i:s')).
 */
function tgl_id(?string $tanggal, string $gaya = 'pendek'): string {
    if (!$tanggal) return '—';
    $ts = strtotime($tanggal);
    if ($ts === false) return '—';

    $hari  = ['Sunday'=>'Minggu','Monday'=>'Senin','Tuesday'=>'Selasa','Wednesday'=>'Rabu',
              'Thursday'=>'Kamis','Friday'=>'Jumat','Saturday'=>'Sabtu'];
    $bulan = [1=>'Januari','Februari','Maret','April','Mei','Juni','Juli','Agustus',
              'September','Oktober','November','Desember'];
    $bulanSingkat = [1=>'Jan','Feb','Mar','Apr','Mei','Jun','Jul','Agu','Sep','Okt','Nov','Des'];

    $d = (int) date('j', $ts);
    $m = (int) date('n', $ts);
    $y = date('Y', $ts);

    if ($gaya === 'panjang') { return "$d {$bulan[$m]} $y"; }
    if ($gaya === 'hari')    { return $hari[date('l', $ts)] . ", $d {$bulan[$m]} $y"; }
    if ($gaya === 'lengkap') {
        return $hari[date('l', $ts)] . ", $d {$bulan[$m]} $y " . date('H:i', $ts);
    }
    return "$d {$bulanSingkat[$m]} $y";
}

function tgl_jam_id(?string $tanggal): string {
    if (!$tanggal) return '—';
    return tgl_id($tanggal, 'pendek') . ' ' . date('H:i', strtotime($tanggal));
}

/** Escape untuk LIKE — supaya % dan _ yang diketik user tidak jadi wildcard. */
function like_escape(string $s): string {
    return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $s);
}

// =====================================================================
// PENYARING DAFTAR
// ---------------------------------------------------------------------
// Satu komponen untuk seluruh halaman daftar. Sebelumnya tiap halaman
// merakit sendiri form GET, validasi, potongan WHERE, dan tombol resetnya
// — sehingga "Semua Kategori" di satu halaman bernilai 0, di halaman lain
// bernilai string kosong, dan tombol Reset kadang ikut menghapus tab yang
// sedang dibuka.
//
// Yang disediakan di sini: pembacaan nilai yang sudah tervalidasi, dan
// perakitan tampilannya. Potongan WHERE tetap ditulis halaman
// masing-masing, karena bentuk tabelnya terlalu berbeda untuk digeneralkan
// tanpa membuat abstraksi yang lebih sulit dibaca daripada SQL-nya sendiri.
// =====================================================================

/** Pilihan periode yang dikenal, beserta labelnya. */
function periode_pilihan(): array {
    return [
        'hari_ini'  => 'Hari ini',
        'kemarin'   => 'Kemarin',
        '7hari'     => '7 hari terakhir',
        '30hari'    => '30 hari terakhir',
        'bulan_ini' => 'Bulan ini',
    ];
}

/**
 * Rentang tanggal sebuah periode, sebagai [dari, sampai] format Y-m-d.
 * NULL berarti "kapan saja" — pemanggil tidak menambahkan syarat apa pun.
 */
function periode_rentang(string $kode): ?array {
    $hariIni = date('Y-m-d');
    switch ($kode) {
        case 'hari_ini':  return [$hariIni, $hariIni];
        case 'kemarin':   $k = date('Y-m-d', strtotime('-1 day')); return [$k, $k];
        case '7hari':     return [date('Y-m-d', strtotime('-6 days')), $hariIni];
        case '30hari':    return [date('Y-m-d', strtotime('-29 days')), $hariIni];
        case 'bulan_ini': return [date('Y-m-01'), $hariIni];
        default:          return null;
    }
}

/**
 * Baca nilai penyaring dari query string, sudah tervalidasi.
 *
 * Nilai yang tidak dikenal dijadikan string kosong, BUKAN dipakai apa
 * adanya. Isi query string datang dari luar; membiarkannya lewat berarti
 * halaman menyusun SQL atau menampilkan label dari teks yang diketik siapa
 * saja di bilah alamat.
 */
function filter_baca(array $def): array {
    $out = [];
    foreach ($def as $f) {
        $nama = $f['nama'];
        $v    = trim((string) ($_GET[$nama] ?? ''));
        $jenis = $f['jenis'] ?? 'cari';

        if ($jenis === 'pilih') {
            if (!array_key_exists($v, $f['opsi'] ?? [])) { $v = ''; }
        } elseif ($jenis === 'periode') {
            if (!array_key_exists($v, periode_pilihan())) { $v = ''; }
        } else {
            // Pencarian dipotong supaya tidak ada string sepanjang kilobyte
            // yang ikut masuk ke LIKE dan ke tautan chip di bawah.
            if (mb_strlen($v) > 80) { $v = mb_substr($v, 0, 80); }
        }
        $out[$nama] = $v;
    }
    return $out;
}

/** Apakah ada penyaring yang sedang aktif. */
function filter_ada(array $nilai): bool {
    foreach ($nilai as $v) { if ($v !== '') { return true; } }
    return false;
}

/** URL halaman ini dengan satu penyaring diubah/dihapus, sisanya utuh. */
function filter_url(array $nilai, array $tetap, string $ubah = '', string $jadi = ''): string {
    $par = array_filter($nilai, function ($v) { return $v !== ''; });
    if ($ubah !== '') {
        if ($jadi === '') { unset($par[$ubah]); } else { $par[$ubah] = $jadi; }
    }
    $par = array_merge($tetap, $par);
    $ini = basename($_SERVER['SCRIPT_NAME'] ?? 'index.php');
    return $par ? $ini . '?' . http_build_query($par) : $ini;
}

/**
 * Gambar bilah penyaring: form GET + chip penyaring yang sedang aktif.
 *
 * [$tetap] adalah parameter yang HARUS ikut terbawa tapi bukan penyaring —
 * mis. tab yang sedang dibuka. Tanpa ini, menekan Filter melempar pengguna
 * kembali ke tab pertama, dan itu terasa seperti halamannya rusak.
 *
 * [$jml] bila diisi ditampilkan sebagai jumlah baris hasil, supaya orang
 * tahu penyaringnya memang bekerja dan bukan datanya yang hilang.
 */
function filter_bar(array $def, array $nilai, array $tetap = [], ?int $jml = null): void {
    $aktif = filter_ada($nilai);
    ?>
    <form class="tools" method="get">
      <?php foreach ($tetap as $k => $v): ?>
        <input type="hidden" name="<?= e((string) $k) ?>" value="<?= e((string) $v) ?>">
      <?php endforeach; ?>

      <?php foreach ($def as $f): $n = $f['nama']; $v = $nilai[$n] ?? ''; ?>
        <?php if (($f['jenis'] ?? 'cari') === 'cari'): ?>
          <input type="search" name="<?= e($n) ?>" value="<?= e($v) ?>"
                 placeholder="<?= e($f['placeholder'] ?? 'Cari…') ?>"
                 style="flex:1;min-width:170px">
        <?php elseif ($f['jenis'] === 'periode'): ?>
          <select name="<?= e($n) ?>" aria-label="<?= e($f['label'] ?? $n) ?>">
            <option value=""><?= e($f['kosong'] ?? 'Kapan saja') ?></option>
            <?php foreach (periode_pilihan() as $kode => $lb): ?>
              <option value="<?= e($kode) ?>" <?= $v === $kode ? 'selected' : '' ?>><?= e($lb) ?></option>
            <?php endforeach; ?>
          </select>
        <?php else: ?>
          <select name="<?= e($n) ?>" aria-label="<?= e($f['label'] ?? $n) ?>">
            <option value=""><?= e($f['kosong'] ?? 'Semua') ?></option>
            <?php foreach ($f['opsi'] as $kode => $lb): ?>
              <option value="<?= e((string) $kode) ?>" <?= $v === (string) $kode ? 'selected' : '' ?>><?= e($lb) ?></option>
            <?php endforeach; ?>
          </select>
        <?php endif; ?>
      <?php endforeach; ?>

      <button class="btn btn-ghost" type="submit">
        <svg aria-hidden="true"><use href="#i-cari"></use></svg> Filter</button>
      <?php if ($aktif): ?>
        <a class="btn btn-ghost" href="<?= e(filter_url([], $tetap)) ?>">Reset</a>
      <?php endif; ?>
    </form>

    <?php if ($aktif): ?>
      <div class="saring saring-chip">
        <span class="saring-lb">Penyaring aktif</span>
        <?php foreach ($def as $f): $n = $f['nama']; $v = $nilai[$n] ?? '';
              if ($v === '') { continue; }
              $jenis = $f['jenis'] ?? 'cari';
              if ($jenis === 'periode')   { $teks = periode_pilihan()[$v] ?? $v; }
              elseif ($jenis === 'pilih') { $teks = $f['opsi'][$v] ?? $v; }
              else                        { $teks = '"' . $v . '"'; } ?>
          <?php /* Tiap chip menghapus SATU penyaring, bukan semuanya. Kalau
                   satu-satunya jalan keluar adalah Reset, orang yang salah
                   memilih satu dropdown harus menyusun ulang seluruh
                   penyaringnya dari awal. */ ?>
          <a href="<?= e(filter_url($nilai, $tetap, $n)) ?>"
             title="Hapus penyaring <?= e($f['label'] ?? $n) ?>">
            <?= e($f['label'] ?? $n) ?>: <?= e($teks) ?> &times;
          </a>
        <?php endforeach; ?>
        <?php if ($jml !== null): ?>
          <span class="saring-jml"><?= angka($jml) ?> baris cocok</span>
        <?php endif; ?>
      </div>
    <?php endif;
}

// =====================================================================
// BARIS RINCIAN
// ---------------------------------------------------------------------
// Daftar dokumen di halaman transaksi hanya menampilkan ringkasan: nomor,
// tanggal, dan berapa jenis/unit. Yang paling sering ditanyakan justru
// tidak ada di situ — BARANG APA SAJA yang ada di dalamnya.
//
// Rinciannya dibuka di tempat, sebagai baris tambahan tepat di bawah baris
// dokumennya, bukan di halaman terpisah. Alasannya: yang dicari orang
// biasanya "dokumen mana yang berisi barang X", dan itu berarti membuka-
// tutup beberapa dokumen berturut-turut. Kalau tiap kali harus berpindah
// halaman lalu kembali, posisi gulir dan penyaring yang sedang dipakai
// ikut hilang setiap kali.
//
// Yang disediakan di sini hanya TOMBOL dan pembungkusnya. Isi tabelnya
// digambar masing-masing halaman, karena kolomnya memang berbeda-beda —
// barang masuk punya harga dan rak, barang keluar tidak.
// =====================================================================

/**
 * Tombol pembuka baris rincian.
 *
 * [$id] harus sama dengan id baris <tr> rinciannya.
 */
function tombol_rincian(string $id, int $jml): string {
    $label = $jml > 0 ? angka($jml) . ' jenis' : 'Rincian';
    return '<button type="button" class="btn btn-ghost btn-sm tbl-rinci"'
         . ' data-rinci="' . e($id) . '" aria-expanded="false"'
         . ' aria-controls="' . e($id) . '" title="Lihat barang di dokumen ini">'
         . '<svg aria-hidden="true" class="tanda"><use href="#i-bawah"></use></svg> '
         . e($label) . '</button>';
}

/**
 * Kelompokkan baris rincian berdasarkan id dokumennya.
 *
 * Dipakai supaya rincian seluruh dokumen di halaman diambil dalam SATU
 * kueri, bukan satu kueri per dokumen. Daftar enam dokumen yang masing-
 * masing menembak kueri sendiri berarti tujuh perjalanan ke basis data
 * untuk satu halaman — dan itu tumbuh begitu jumlah dokumennya bertambah.
 */
function kelompokkan(array $baris, string $kunci): array {
    $peta = [];
    foreach ($baris as $b) {
        $peta[(int) $b[$kunci]][] = $b;
    }
    return $peta;
}

// =====================================================================
// PELEPASAN STOK — perbaikan, pembuangan, penjualan
// ---------------------------------------------------------------------
// Tiga jalan keluar barang selain disalurkan. Semuanya lewat SATU fungsi
// di bawah, supaya pengurangan stok, penulisan catatan, dan penyalinan
// donatur tidak punya tiga versi yang perlahan berbeda.
// =====================================================================

const JENIS_PELEPASAN = [
    'perbaikan' => ['Masuk perbaikan', 't-blue',   'Ditahan dari stok tersedia sampai perbaikannya selesai'],
    'dibuang'   => ['Dibuang',         't-red',    'Keluar permanen — kedaluwarsa atau rusak tak terperbaiki'],
    'dijual'    => ['Dijual',          't-amber',  'Keluar permanen, dengan hasil penjualan yang dicatat'],
];

const HASIL_PERBAIKAN = [
    'layak'   => ['Layak pakai — kembali ke stok', 't-green'],
    'dibuang' => ['Gagal — dibuang',               't-red'],
    'dijual'  => ['Dijual setelah diperbaiki',     't-amber'],
];

function label_pelepasan(string $j): array {
    $d = JENIS_PELEPASAN[$j] ?? ['—', 't-gray', ''];
    return [$d[0], $d[1]];
}

function label_hasil(?string $h): array {
    if ($h === null || $h === '') { return ['Masih dikerjakan', 't-blue']; }
    return HASIL_PERBAIKAN[$h] ?? ['—', 't-gray'];
}

/**
 * Kurangi isi sebuah batch, dan catat ke mana perginya.
 *
 * Dipakai untuk ketiga jenis pelepasan. Mengembalikan id barisnya.
 *
 * Yang WAJIB diperhatikan siapa pun yang menyentuh fungsi ini:
 *
 *  • Batch dikunci lebih dulu (FOR UPDATE). Dua petugas yang membuang
 *    batch yang sama pada saat bersamaan akan sama-sama membaca sisa yang
 *    sama, lalu keduanya mengurangi — dan stok berakhir minus.
 *  • Jumlah dibatasi sisa yang benar-benar ada. Tanpa batas atas, angka
 *    yang diketik tangan bisa menghapus stok lebih banyak daripada yang
 *    pernah ada.
 *  • donatur_id DISALIN, bukan dirujuk lewat batch. Batch yang habis bisa
 *    terhapus, dan pertanyaan "barang siapa yang dibuang" harus tetap
 *    terjawab setelah itu.
 *
 * Pemanggil bertanggung jawab membuka transaksi dan memanggil
 * recalc_stok() sesudahnya.
 */
/**
 * Sesuaikan jumlah satu batch ke hasil hitung fisik, dan catat selisihnya.
 *
 * SATU tempat untuk dua pemanggil: Stock Opname (api/opname.php) dan
 * penyuntingan stok di halaman Produk. Keduanya melakukan hal yang persis
 * sama — mengubah qty_tersisa sebuah batch dan meninggalkan jejak kenapa.
 * Ditulis dua kali, cepat atau lambat salah satunya lupa mencatat
 * penyesuaiannya, dan angka stok berubah tanpa ada yang bisa menjelaskan.
 *
 * SELISIH WAJIB BERALASAN. Barang di gudang ini titipan orang; "kurang 3"
 * tanpa keterangan membuat pertanyaan "ke mana perginya" tidak bisa dijawab
 * tiga bulan kemudian. Yang cocok tidak perlu alasan — yang perlu
 * dijelaskan hanya yang berubah.
 *
 * Mengembalikan selisihnya (positif = bertambah, 0 = cocok). Pemanggil
 * bertanggung jawab membuka transaksi dan memanggil recalc_stok() sesudahnya.
 */
/* =====================================================================
   BATAS JUMLAH WAJAR — ditambahkan 1 Okt 2026
   =====================================================================
   Pada 1 Okt 2026 sebuah batch tercatat berisi 2.147.483.647 unit: nomor
   barcode tertembak ke kotak JUMLAH, dan MySQL menjepitnya ke batas atas
   kolom INT. Tidak ada satu lapis pun yang bertanya "masuk akalkah ini?".
   Koreksinya lalu meninggalkan baris "koreksi kurang −2.147.483.646" di
   riwayat, dan angka itu ikut ke setiap laporan yang menjumlahkannya.

   Sejuta unit per baris jauh di atas keperluan gudang mana pun di lembaga
   ini, dan jauh di bawah barcode terpendek (8 digit). Diperiksa di DUA
   fungsi yang dilewati SEMUA jalur penambah stok — tambah_ke_lokasi() dan
   sesuaikan_batch() — jadi formulir web, impor, maupun aplikasi Android
   ikut terjaga tanpa perlu disunting satu per satu.
   ===================================================================== */
const MAKS_QTY_BARIS = 1000000;

function periksa_qty_wajar(int $qty, string $apa = 'Jumlah'): void {
    if ($qty > MAKS_QTY_BARIS) {
        throw new RuntimeException(
            $apa . ' ' . number_format($qty, 0, ',', '.') . ' tidak wajar (batasnya '
          . number_format(MAKS_QTY_BARIS, 0, ',', '.') . ' per baris). Biasanya ini barcode yang '
          . 'tertembak ke kotak Jumlah — periksa lagi isiannya.');
    }
}

function sesuaikan_batch(PDO $pdo, int $batchId, int $qtyFisik, string $alasan,
                         ?int $olehUserId = null, string $catatan = ''): int {
    $st = $pdo->prepare(
        "SELECT bb.*, b.nama AS nama_barang
         FROM batch_barang bb JOIN barang b ON b.id = bb.barang_id
         WHERE bb.id = ? FOR UPDATE");
    $st->execute([$batchId]);
    $bb = $st->fetch();
    if (!$bb) { throw new RuntimeException('Batch tidak ditemukan.'); }

    $qtyFisik = max(0, $qtyFisik);
    periksa_qty_wajar($qtyFisik, 'Jumlah fisik');
    $sistem   = (int) $bb['qty_tersisa'];
    $beda     = $qtyFisik - $sistem;
    if ($beda === 0) { return 0; }

    $alasan = trim($alasan);
    if ($alasan === '') {
        throw new RuntimeException(
            "Batch {$bb['nomor_batch']} ({$bb['nama_barang']}) berselisih "
          . ($beda > 0 ? '+' : '') . $beda . ' unit. Isi dulu alasannya sebelum menyimpan.');
    }

    $pdo->prepare("UPDATE batch_barang SET qty_tersisa = ? WHERE id = ?")
        ->execute([$qtyFisik, $batchId]);

    $pdo->prepare(
        "INSERT INTO penyesuaian_stok
           (no_penyesuaian, tanggal, lokasi_tipe, toko_id, lokasi_rak_id, barang_id,
            batch_id, jenis, qty, alasan, user_id)
         VALUES (?, NOW(), ?, ?, ?, ?, ?, ?, ?, ?, ?)"
    )->execute([
        nomor_dokumen($pdo, 'penyesuaian_stok', 'no_penyesuaian', 'PS'),
        $bb['toko_id'] ? 'toko' : 'gudang',
        $bb['toko_id'] ?: null,
        $bb['lokasi_rak_id'] ?: null,
        (int) $bb['barang_id'],
        $batchId,
        $beda > 0 ? 'koreksi_tambah' : 'koreksi_kurang',
        abs($beda),
        trim($alasan . ($catatan !== '' ? " — $catatan" : '')),
        $olehUserId ?? ($_SESSION['user_id'] ?? null),
    ]);

    return $beda;
}

/**
 * Boleh/tidaknya akun ini mengubah SATU catatan penyesuaian.
 *
 * Membuat penyesuaian boleh dilakukan petugas opname; MENGUBAH yang sudah
 * tercatat adalah wewenang tingkat Manager (sama dengan halaman Sesuaikan
 * Stok), dan hanya untuk rak di gudangnya sendiri.
 */
function boleh_ubah_penyesuaian(PDO $pdo, array $ps): bool {
    if (!boleh_kelola_master($pdo)) { return false; }
    if (!terikat_satu_gudang()) { return true; }
    return ($ps['lokasi_tipe'] ?? '') === 'gudang'
        && in_array((int) ($ps['lokasi_rak_id'] ?? 0), rak_saya($pdo), true);
}

/**
 * Ubah satu catatan penyesuaian stok — jumlah dan/atau alasannya.
 *
 * STOK IKUT BERGERAK. Penyesuaian bukan sekadar tulisan: "koreksi kurang 2"
 * berarti batch itu dulu dikurangi 2. Mengubahnya menjadi "kurang 1" tanpa
 * mengembalikan 1 unit ke batch membuat riwayat dan stok saling
 * bertentangan — jumlah seluruh pergerakan tidak lagi sama dengan isi rak.
 * Karena itu selisihnya diterapkan ke batch yang sama, dan ditolak bila
 * batch itu tidak punya cukup stok untuk menanggungnya.
 *
 * Jumlah 0 berarti penyesuaiannya dibatalkan: catatannya dihapus dan
 * seluruh jumlahnya dikembalikan.
 *
 * SATU PENGECUALIAN: catatan yang jumlahnya di atas MAKS_QTY_BARIS. Angka
 * itu tidak pernah berupa barang — ia lahir dari barcode yang tertembak ke
 * kotak Jumlah, dan penyesuaiannya justru yang membetulkan stok. Menerapkan
 * selisihnya ke batch akan menghidupkan lagi stok miliaran. Untuk catatan
 * seperti itu hanya tulisannya yang diubah; stok tidak disentuh.
 *
 * Dipanggil DI DALAM transaksi pemanggil.
 *
 * @return array{no:string, lama:int, baru:int, geser:int, dihapus:bool,
 *               riwayat_saja:bool, barang_id:int, stok_batch:?int}
 */
function ubah_penyesuaian(PDO $pdo, int $id, int $qtyBaru, string $alasan): array {
    // Baca dulu tanpa kunci untuk tahu barangnya, lalu kunci dalam urutan
    // yang sama dengan jalur lain: stok → penyesuaian → batch.
    $st = $pdo->prepare("SELECT barang_id FROM penyesuaian_stok WHERE id = ?");
    $st->execute([$id]);
    $barangId = (int) $st->fetchColumn();
    if ($barangId <= 0) { throw new RuntimeException('Catatan penyesuaian itu sudah tidak ada.'); }
    kunci_stok($pdo, [$barangId]);

    $st = $pdo->prepare("SELECT * FROM penyesuaian_stok WHERE id = ? FOR UPDATE");
    $st->execute([$id]);
    $ps = $st->fetch();
    if (!$ps) { throw new RuntimeException('Catatan penyesuaian itu sudah tidak ada.'); }
    if (!boleh_ubah_penyesuaian($pdo, $ps)) {
        throw new RuntimeException('Mengubah penyesuaian ini di luar wewenang akun Anda.');
    }

    $alasan = trim($alasan);
    if ($alasan === '') { throw new RuntimeException('Alasan wajib diisi.'); }
    if (mb_strlen($alasan) > 255) { $alasan = mb_substr($alasan, 0, 255); }
    if ($qtyBaru < 0) { throw new RuntimeException('Jumlah tidak boleh negatif.'); }
    periksa_qty_wajar($qtyBaru);

    $lama        = (int) $ps['qty'];
    $naik        = $ps['jenis'] === 'koreksi_tambah';
    $riwayatSaja = $lama > MAKS_QTY_BARIS;
    $geser       = 0;      // perubahan stok batch: + bertambah, − berkurang
    $stokBatch   = null;

    if (!$riwayatSaja && $qtyBaru !== $lama) {
        $geser = $naik ? ($qtyBaru - $lama) : ($lama - $qtyBaru);
        if (empty($ps['batch_id'])) {
            throw new RuntimeException('Penyesuaian ini tidak terikat ke batch mana pun, jadi jumlahnya tidak bisa diubah — hanya alasannya.');
        }
        $stB = $pdo->prepare("SELECT nomor_batch, qty_tersisa FROM batch_barang WHERE id = ? FOR UPDATE");
        $stB->execute([(int) $ps['batch_id']]);
        $bb = $stB->fetch();
        if (!$bb) { throw new RuntimeException('Batch penyesuaian ini sudah tidak ada, jadi jumlahnya tidak bisa diubah.'); }

        $stokBatch = (int) $bb['qty_tersisa'] + $geser;
        if ($stokBatch < 0) {
            throw new RuntimeException(
                "Stok batch {$bb['nomor_batch']} tinggal " . angka($bb['qty_tersisa'])
              . ' — tidak cukup untuk perubahan ini (perlu ' . angka(-$geser) . '). '
              . 'Sebagian barangnya sudah keluar atau dipindahkan.');
        }
        periksa_qty_wajar($stokBatch, 'Stok batch');
        $pdo->prepare("UPDATE batch_barang SET qty_tersisa = ? WHERE id = ?")
            ->execute([$stokBatch, (int) $ps['batch_id']]);
    }

    if ($qtyBaru === 0) {
        $pdo->prepare("DELETE FROM penyesuaian_stok WHERE id = ?")->execute([$id]);
    } else {
        $pdo->prepare("UPDATE penyesuaian_stok SET qty = ?, alasan = ? WHERE id = ?")
            ->execute([$qtyBaru, $alasan, $id]);
    }
    if ($geser !== 0) { recalc_stok($pdo, $barangId); }

    return ['no' => (string) $ps['no_penyesuaian'], 'lama' => $lama, 'baru' => $qtyBaru,
            'geser' => $geser, 'dihapus' => $qtyBaru === 0, 'riwayat_saja' => $riwayatSaja,
            'barang_id' => $barangId, 'stok_batch' => $stokBatch];
}

/**
 * Nolkan SELURUH batch di satu lokasi: rak, gudang, atau toko.
 *
 * ---------------------------------------------------------------------------
 * MENOLKAN, BUKAN MENGHAPUS
 * ---------------------------------------------------------------------------
 * Tiap batch diturunkan ke nol lewat sesuaikan_batch() — fungsi yang sama
 * dipakai Stock Opname dan halaman Sesuaikan Stok. Jadi hasilnya tidak
 * mungkin berbeda bentuk dari opname yang dikerjakan tangan: tiap batch
 * meninggalkan satu baris `penyesuaian_stok` bernomor PS-… beralasan.
 *
 * `qty_awal` TIDAK disentuh. Jejak berapa yang pernah ada di situ tetap
 * terbaca, begitu pula seluruh riwayat yang menunjuk batch itu — laporan
 * bulan lalu dan surat jalan yang sudah dicetak tidak kehilangan rujukannya.
 *
 * ---------------------------------------------------------------------------
 * KENAPA DI SINI, BUKAN DI HALAMANNYA
 * ---------------------------------------------------------------------------
 * Pemanggilnya tiga: tombol di halaman Rak, tombol di halaman Gudang, dan
 * alat command line alat_nolkan_stok.php. Ditulis tiga kali, cepat atau
 * lambat salah satunya akan lupa memanggil recalc_stok() — dan gejalanya
 * bukan galat, melainkan angka stok yang diam-diam salah sampai ada yang
 * menghitung ulang secara fisik.
 *
 * Pemanggil bertanggung jawab atas: penjagaan wewenang, konfirmasi, dan
 * membuka transaksi. Fungsi ini sengaja TIDAK membuka transaksinya sendiri
 * supaya bisa ikut dalam transaksi pemanggil yang lebih besar.
 *
 * @param string $tipe 'rak' | 'gudang' | 'toko'
 * @return array{batch:int, jenis:int, unit:int}  yang benar-benar berubah
 */
function nolkan_stok_lokasi(PDO $pdo, string $tipe, int $id, string $alasan,
                            ?int $olehUserId = null): array {
    $alasan = trim($alasan);
    if ($alasan === '') {
        throw new RuntimeException(
            'Alasan wajib diisi — barang di sini titipan orang, dan setiap '
          . 'selisih harus punya sebab yang tercatat.');
    }
    if ($id <= 0) { throw new RuntimeException('Lokasi tidak sah.'); }

    switch ($tipe) {
        case 'rak':
            $syarat = 'bb.lokasi_rak_id = ' . $id;
            break;
        case 'gudang':
            $syarat = 'bb.lokasi_rak_id IN (SELECT id FROM lokasi_rak WHERE gudang_id = ' . $id . ')';
            break;
        case 'toko':
            $syarat = 'bb.toko_id = ' . $id;
            break;
        default:
            throw new RuntimeException("Tipe lokasi '$tipe' tidak dikenal.");
    }

    /* Hanya batch yang MASIH bersisa. Batch bersisa nol tidak perlu
       disesuaikan — sesuaikan_batch() memang mengembalikan 0 untuknya, tapi
       menyaringnya di sini membuat angka laporan di bawah jujur: "7 batch
       dinolkan" berarti tujuh yang benar-benar berubah. */
    $batch = $pdo->query(
        "SELECT bb.id, bb.barang_id, bb.qty_tersisa
         FROM batch_barang bb
         WHERE $syarat AND bb.qty_tersisa > 0")->fetchAll(PDO::FETCH_ASSOC);

    $unit = 0; $disentuh = []; $n = 0;
    foreach ($batch as $b) {
        $beda = sesuaikan_batch($pdo, (int) $b['id'], 0, $alasan, $olehUserId);
        if ($beda === 0) { continue; }
        $n++;
        $unit += (int) $b['qty_tersisa'];
        $disentuh[(int) $b['barang_id']] = true;
    }

    /* Cache `stok.qty_total` dihitung ulang per BARANG yang tersentuh — sama
       seperti opname.php dan barang_stok.php. Tanpa ini, daftar produk dan
       seluruh peringatan stok menampilkan angka lama. */
    foreach (array_keys($disentuh) as $bid) { recalc_stok($pdo, (int) $bid); }

    return ['batch' => $n, 'jenis' => count($disentuh), 'unit' => $unit];
}

/**
 * Ringkasan isi satu lokasi — untuk menakar sebelum menolkannya.
 *
 * Dipakai tombol Kosongkan supaya dialog konfirmasinya bisa menyebut angka
 * yang sesungguhnya. Konfirmasi yang hanya berbunyi "yakin?" tanpa menyebut
 * apa yang akan hilang bukan konfirmasi, melainkan formalitas.
 *
 * @return array{batch:int, jenis:int, unit:int}
 */
function isi_stok_lokasi(PDO $pdo, string $tipe, int $id): array {
    $peta = [
        'rak'    => 'bb.lokasi_rak_id = ' . $id,
        'gudang' => 'bb.lokasi_rak_id IN (SELECT id FROM lokasi_rak WHERE gudang_id = ' . $id . ')',
        'toko'   => 'bb.toko_id = ' . $id,
    ];
    if ($id <= 0 || !isset($peta[$tipe])) { return ['batch' => 0, 'jenis' => 0, 'unit' => 0]; }
    $r = $pdo->query(
        "SELECT COUNT(*) batch, COUNT(DISTINCT bb.barang_id) jenis,
                COALESCE(SUM(bb.qty_tersisa),0) unit
         FROM batch_barang bb
         WHERE {$peta[$tipe]} AND bb.qty_tersisa > 0")->fetch(PDO::FETCH_ASSOC);
    return ['batch' => (int) $r['batch'], 'jenis' => (int) $r['jenis'],
            'unit'  => (int) $r['unit']];
}

function lepas_stok(PDO $pdo, int $batchId, int $qty, string $jenis, string $alasan,
                    array $ekstra = []): int {
    if (!array_key_exists($jenis, JENIS_PELEPASAN)) {
        throw new RuntimeException('Jenis pelepasan tidak dikenal.');
    }
    $alasan = trim($alasan);
    if ($alasan === '') {
        throw new RuntimeException('Alasan wajib diisi — barang ini titipan orang.');
    }

    $st = $pdo->prepare("SELECT * FROM batch_barang WHERE id = ? FOR UPDATE");
    $st->execute([$batchId]);
    $batch = $st->fetch();
    if (!$batch) { throw new RuntimeException('Batch tidak ditemukan.'); }

    $qty = (int) $qty;
    if ($qty <= 0) { throw new RuntimeException('Jumlah harus lebih dari nol.'); }
    if ($qty > (int) $batch['qty_tersisa']) {
        throw new RuntimeException('Jumlahnya melebihi sisa batch ini ('
            . (int) $batch['qty_tersisa'] . ').');
    }

    $pdo->prepare("UPDATE batch_barang SET qty_tersisa = qty_tersisa - ? WHERE id = ?")
        ->execute([$qty, $batchId]);

    $no = nomor_dokumen($pdo, 'pelepasan_stok', 'no_pelepasan', 'PL');
    $pdo->prepare(
        "INSERT INTO pelepasan_stok
           (no_pelepasan, tanggal, batch_id, barang_id, donatur_id, qty, jenis, alasan,
            status, biaya, nilai_jual, no_bukti_setor, user_id)
         VALUES (?, CURDATE(), ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
    )->execute([
        $no, $batchId, (int) $batch['barang_id'], $batch['donatur_id'] ?: null, $qty,
        $jenis, $alasan,
        $jenis === 'perbaikan' ? 'proses' : 'selesai',
        $ekstra['biaya']      ?? null,
        $ekstra['nilai_jual'] ?? null,
        $ekstra['no_bukti_setor'] ?? null,
        $_SESSION['user_id'] ?? null,
    ]);
    return (int) $pdo->lastInsertId();
}

/**
 * Tutup sebuah perbaikan, dan tentukan nasib barangnya.
 *
 * Tiga ujung yang mungkin:
 *   layak   — kembali ke stok sebagai batch berkondisi bekas_layak
 *   dibuang — tidak kembali; stoknya memang sudah dikurangi saat masuk
 *   dijual  — tidak kembali, hasil penjualannya dicatat
 *
 * Barang yang kembali TIDAK dimasukkan ke batch aslinya, melainkan ke
 * batch baru berkondisi bekas_layak. Batch asalnya berkondisi
 * bekas_tidak_layak; menambahkannya kembali ke sana berarti barang yang
 * sudah diperbaiki tercampur lagi dengan yang belum, dan penilaian yang
 * baru saja dikerjakan orang jadi hilang.
 *
 * Nomor batch barunya diberi akhiran -R agar asal-usulnya terbaca tanpa
 * perlu menelusuri tabel pelepasan.
 *
 * Pemanggil bertanggung jawab membuka transaksi dan memanggil
 * recalc_stok() sesudahnya.
 */
function selesaikan_perbaikan(PDO $pdo, int $pelepasanId, string $hasil,
                              array $ekstra = []): ?int {
    if (!array_key_exists($hasil, HASIL_PERBAIKAN)) {
        throw new RuntimeException('Hasil perbaikan tidak dikenal.');
    }

    $st = $pdo->prepare(
        "SELECT p.*, bb.nomor_batch, bb.lokasi_rak_id, bb.tanggal_expired,
                bb.harga_beli, bb.supplier_id
         FROM pelepasan_stok p
         LEFT JOIN batch_barang bb ON bb.id = p.batch_id
         WHERE p.id = ? FOR UPDATE"
    );
    $st->execute([$pelepasanId]);
    $p = $st->fetch();

    if (!$p)                         { throw new RuntimeException('Catatan perbaikan tidak ditemukan.'); }
    if ($p['jenis'] !== 'perbaikan') { throw new RuntimeException('Catatan ini bukan perbaikan.'); }
    if ($p['status'] === 'selesai')  { throw new RuntimeException('Perbaikan ini sudah ditutup.'); }

    $batchBaru = null;
    if ($hasil === 'layak') {
        /* Rak asalnya dipakai kembali bila masih ada. Batch yang sudah
           terhapus (lokasi_rak_id null) tetap bisa dikembalikan tanpa rak,
           daripada perbaikannya menggantung selamanya. */
        $batchBaru = tambah_ke_rak(
            $pdo,
            (int) $p['barang_id'],
            $p['lokasi_rak_id'] ? (int) $p['lokasi_rak_id'] : null,
            ($p['nomor_batch'] ?: 'PB' . date('ymd')) . '-R',
            $p['tanggal_expired'] ?: null,
            (int) $p['qty'],
            (float) ($p['harga_beli'] ?? 0),
            $p['supplier_id'] ? (int) $p['supplier_id'] : null,
            'bekas_layak',
            'donasi',
            $p['donatur_id'] ? (int) $p['donatur_id'] : null
        );
    }

    $pdo->prepare(
        "UPDATE pelepasan_stok
         SET status = 'selesai', hasil = ?, tanggal_selesai = CURDATE(),
             biaya = ?, nilai_jual = ?, no_bukti_setor = ?, batch_kembali_id = ?
         WHERE id = ?"
    )->execute([
        $hasil,
        $ekstra['biaya'] ?? null,
        $hasil === 'dijual' ? ($ekstra['nilai_jual'] ?? null) : null,
        $hasil === 'dijual' ? ($ekstra['no_bukti_setor'] ?? null) : null,
        $batchBaru,
        $pelepasanId,
    ]);
    return $batchBaru;
}

function set_flash(string $tipe, string $pesan): void {
    $_SESSION['flash'] = ['type' => $tipe === 'ok' ? 'ok' : 'err', 'msg' => $pesan];
}

function ambil_flash(): ?array {
    $f = $_SESSION['flash'] ?? null;
    unset($_SESSION['flash']);
    return $f;
}

// ---------------------------------------------------------------------
// Penanganan error yang aman
// ---------------------------------------------------------------------
/**
 * Catat detail error ke log server, kembalikan pesan aman untuk pengguna.
 * Menggantikan pola lama `$e->getMessage()` yang membocorkan struktur DB.
 */
/**
 * Simpan satu galat ke tabel `galat_sistem`, supaya kode yang muncul di layar
 * ("sampaikan kode ini ke admin: 3A9A4721") bisa DICARI dari halaman
 * Pengaturan › Galat Sistem. Sebelumnya kode itu hanya ada di error_log
 * server — berkas yang di hosting ini bahkan sudah lama tidak ditulis —
 * jadi kodenya tidak pernah bisa ditelusuri siapa pun.
 *
 * Tidak boleh melempar: pencatat galat yang ikut gagal hanya menumpuk
 * masalah di atas masalah.
 */
function catat_galat(string $kode, string $konteks, Throwable $e): void {
    try {
        /* Koneksi TERSENDIRI, bukan $pdo milik halaman. Galat paling penting
           terjadi di tengah transaksi; menulis lewat koneksi yang sama berarti
           catatannya ikut terbuang saat pemanggil me-rollback — atau lebih
           buruk, pencatat ini yang mengganggu transaksi orang lain. */
        static $pdo = null;
        if ($pdo === null) {
            $g = $GLOBALS;
            if (empty($g['DB_NAME']) || empty($g['DB_USER'])) { return; }
            $pdo = new PDO(
                "mysql:host={$g['DB_HOST']};port=" . ($g['DB_PORT'] ?? '3306') . ";dbname={$g['DB_NAME']};charset=utf8mb4",
                $g['DB_USER'], $g['DB_PASS'] ?? '',
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 3]
            );
        }
        $pdo->exec("CREATE TABLE IF NOT EXISTS galat_sistem (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            kode CHAR(8) NOT NULL,
            konteks VARCHAR(100) NULL,
            pesan TEXT NULL,
            berkas VARCHAR(255) NULL,
            halaman VARCHAR(255) NULL,
            user_id BIGINT UNSIGNED NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id), KEY ix_galat_kode (kode), KEY ix_galat_waktu (created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        $pdo->prepare("INSERT INTO galat_sistem (kode, konteks, pesan, berkas, halaman, user_id) VALUES (?,?,?,?,?,?)")
            ->execute([
                $kode, mb_substr($konteks, 0, 100), mb_substr($e->getMessage(), 0, 4000),
                mb_substr(basename($e->getFile()) . ':' . $e->getLine(), 0, 255),
                mb_substr(($_SERVER['REQUEST_METHOD'] ?? '') . ' ' . basename((string) ($_SERVER['SCRIPT_NAME'] ?? '')), 0, 255),
                !empty($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : null,
            ]);
    } catch (Throwable $x) {
        error_log('[AMANAH] catat_galat gagal: ' . $x->getMessage());
    }
}

/**
 * Pesan dari sebuah RuntimeException, AMAN ditampilkan ke pengguna.
 *
 * Di kode ini RuntimeException dipakai untuk pesan yang memang ditujukan
 * kepada pengguna ("stok tidak cukup", "alasan wajib diisi"), dan
 * penangkapnya menampilkan getMessage() apa adanya. Yang terlewat:
 * PDOException adalah TURUNAN RuntimeException — jadi setiap galat basis
 * data yang terjadi di dalam blok itu ikut tampil mentah, lengkap dengan
 * nama tabel dan kolom. Fungsi ini memisahkan keduanya: galat basis data
 * disamarkan dan dicatat (pesan_error_aman), sisanya tampil seperti biasa.
 */
function pesan_runtime(Throwable $e, string $konteks = ''): string {
    return $e instanceof PDOException ? pesan_error_aman($e, $konteks) : $e->getMessage();
}

function pesan_error_aman(Throwable $e, string $konteks = ''): string {
    $kode = strtoupper(substr(bin2hex(random_bytes(4)), 0, 8));
    error_log("[AMANAH][$kode] $konteks — " . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    catat_galat($kode, $konteks, $e);
    return "Terjadi kesalahan saat memproses data. Silakan coba lagi. "
         . "Bila berulang, sampaikan kode ini ke admin sistem: $kode";
}

// ---------------------------------------------------------------------
// Hak akses
// ---------------------------------------------------------------------
// =====================================================================
// PERANGKAT — satu akun maksimal 3 sesi hidup
// ---------------------------------------------------------------------
// Login ke-4 DITOLAK; petugas harus keluar dari salah satu perangkatnya
// atau minta Super Admin mengakhirinya dari halaman Pengaturan.
//
// "Hidup" berarti terlihat dalam ambang menganggur akun itu, bukan
// "belum pernah menekan Keluar". Bedanya menentukan fiturnya berguna
// atau menyiksa: menutup browser tidak memanggil logout, jadi kalau
// sesi mati ikut dihitung, petugas terkunci di luar oleh sesinya sendiri
// dan tidak punya cara memperbaikinya. Ambangnya disamakan dengan
// IDLE_TIMEOUT di auth.php supaya tidak ada dua aturan yang berbeda.
// =====================================================================
/**
 * Batas BAWAAN, dipakai akun yang belum diatur khusus.
 *
 * Namanya dibiarkan MAKS_PERANGKAT — bukan diganti jadi
 * MAKS_PERANGKAT_BAWAAN — karena lima belas tempat sudah memakainya, dan
 * separuhnya kalimat penjelasan di layar. Yang berubah adalah ARTINYA:
 * dari "batas semua orang" menjadi "batas bagi yang tidak diatur".
 * Tempat yang menegakkan batasnya sekarang memakai maks_perangkat().
 */
const MAKS_PERANGKAT = 3;

/**
 * Sahkan tanggal dokumen yang dipilih pengguna.
 *
 * Dipakai TIGA tempat yang semuanya menerbitkan perpindahan:
 * _perpindahan_form.php, dasbor_supplier.php, dan dasbor_toko.php.
 * Diangkat ke sini supaya batasnya tidak jadi tiga salinan — batas
 * tanggal yang berbeda antar halaman berarti dokumen yang sama bisa
 * bertanggal sah di satu jalur dan ditolak di jalur lain.
 *
 * Mengembalikan ['tgl' => 'Y-m-d', 'simpan' => 'Y-m-d H:i:s',
 *                'galat' => string|null, 'geser' => bool].
 *
 * ---------------------------------------------------------------------
 * TANPA BATAS WAKTU — INI KEPUTUSAN PEMILIK SISTEM
 * ---------------------------------------------------------------------
 * Versi pertama menolak tanggal masa depan dan tanggal lebih dari satu
 * tahun ke belakang. Kedua batas itu DICABUT atas permintaan pemilik
 * sistem: surat jalan boleh bertanggal kapan saja.
 *
 * Yang perlu diketahui siapa pun yang membaca ini kelak, karena
 * keduanya tidak lagi dihalangi kode:
 *
 *   - Tanggal MASA DEPAN membuat laporan "bulan ini" memuat baris yang
 *     barangnya belum ada, dan dokumen itu tetap muncul di sana sampai
 *     tanggalnya benar-benar tiba.
 *   - Salah ketik TAHUN — 2025 alih-alih 2026 — adalah kekeliruan
 *     paling sering pada isian tanggal. Akibatnya dokumen hilang dari
 *     laporan periode berjalan, dan tidak ada yang tahu ia pernah
 *     dibuat kecuali mencarinya di tahun yang salah.
 *
 * Yang TETAP dijaga, dan itu penting: setiap tanggal yang bukan hari
 * ini DICATAT di riwayat aktivitas beserta tanggalnya. Batasnya
 * dilepas, jejaknya tidak — kalau kelak angka laporan dipertanyakan,
 * di sanalah tempat melihatnya.
 *
 * Bentuk yang tidak bisa diurai tetap jatuh ke hari ini tanpa galat:
 * yang mengirim tanggal tak terbaca hampir selalu peramban atau salinan
 * formulir, bukan orang yang sengaja.
 *
 * Jam diambil dari waktu PENYIMPANAN, hanya tanggalnya yang dipilih —
 * dokumen bertanggal kemarin pukul 00:00:00 membuat urutan kejadian di
 * hari itu tidak bisa dibaca lagi.
 */
function tanggal_dokumen_sah(?string $masuk): array {
    $hasil = [
        'tgl'    => date('Y-m-d'),
        'simpan' => date('Y-m-d H:i:s'),
        'galat'  => null,
        'geser'  => false,
    ];
    $masuk = trim((string) $masuk);
    if ($masuk === '') { return $hasil; }

    /* '!' di depan format WAJIB. Tanpa itu, createFromFormat mengisi
       bagian yang tidak disebut format dengan NILAI SEKARANG — jadi
       '2026-09-03' menjadi 2026-09-03 pukul 19:56, bukan 00:00.
       Dibandingkan dengan DateTime('today'), tanggal HARI INI terbaca
       lebih besar dan ditolak sebagai masa depan; pilihan yang paling
       sering dipakai justru yang rusak. */
    $d = DateTime::createFromFormat('!Y-m-d', $masuk);
    if (!$d || $d->format('Y-m-d') !== $masuk) { return $hasil; }

    /* Tidak ada penolakan tanggal di sini — lihat catatan di atas. Yang
       tersisa hanya memastikan bentuknya benar-benar tanggal. */
    $hasil['tgl']    = $d->format('Y-m-d');
    $hasil['geser'] = $hasil['tgl'] !== date('Y-m-d');
    $hasil['simpan'] = $hasil['geser']
        ? $hasil['tgl'] . ' ' . date('H:i:s')
        : date('Y-m-d H:i:s');
    return $hasil;
}

/**
 * Batas perangkat MILIK SATU AKUN.
 *
 * Cerminan ambang_perangkat() tepat di bawah: keduanya membaca satu kolom
 * dari users, jatuh ke bawaan bila kolomnya belum dimigrasi, dan tidak
 * pernah melempar. Dibuat sepasang supaya keduanya bisa dibandingkan
 * baris per baris kalau kelak salah satunya berubah.
 *
 * Tiga keadaan, dan membedakannya penting:
 *
 *   NULL   ikut bawaan MAKS_PERANGKAT — bukan terkunci pada angka hari
 *          ini, jadi akun ini ikut berubah bila bawaannya kelak diubah
 *   0      tanpa batas
 *   1..n   batas yang ditetapkan untuk akun itu
 *
 * Mengembalikan 0 untuk "tanpa batas", dan pemanggilnya WAJIB
 * memeriksanya — perbandingan `count($hidup) < 0` selalu salah, jadi
 * yang lupa memeriksa justru mengunci akun itu keluar sama sekali.
 */
function maks_perangkat(PDO $pdo, int $userId): int {
    try {
        $st = $pdo->prepare("SELECT maks_perangkat FROM users WHERE id = ?");
        $st->execute([$userId]);
        $v = $st->fetchColumn();
        if ($v === false || $v === null) { return MAKS_PERANGKAT; }
        return (int) $v;                       // 0 = tanpa batas
    } catch (Throwable $e) {
        return MAKS_PERANGKAT;   // kolom belum dimigrasi
    }
}

/**
 * Pilihan batas perangkat yang ditawarkan di Kelola Pengguna.
 *
 * Kunci -1 dipakai untuk "ikut bawaan", BUKAN null — nilai formulir
 * selalu sampai sebagai string, dan '' sudah dipakai artinya "tidak
 * diisi". Angka yang tidak mungkin jadi batas sungguhan membuat ketiga
 * keadaan tetap bisa dibedakan setelah melewati HTTP.
 */
function pilihan_maks_perangkat(): array {
    return [
        -1 => 'Ikut bawaan (' . MAKS_PERANGKAT . ' perangkat)',
        1  => '1 perangkat — akun bersama di konter',
        2  => '2 perangkat',
        3  => '3 perangkat',
        4  => '4 perangkat',
        5  => '5 perangkat',
        8  => '8 perangkat',
        10 => '10 perangkat',
        0  => 'Tanpa batas',
    ];
}

/** Label terbaca dari sebuah nilai batas perangkat. */
function label_maks_perangkat(?int $v): string {
    if ($v === null)  { return 'Ikut bawaan (' . MAKS_PERANGKAT . ')'; }
    if ($v === 0)     { return 'Tanpa batas'; }
    return $v . ' perangkat';
}

/**
 * Berapa menit sebuah sesi masih dihitung sebagai perangkat aktif.
 *
 * Mengikuti batas menganggur MILIK AKUN ITU, bukan satu angka tetap.
 * Alasannya sederhana: slot perangkat harus terpakai selama sesinya
 * masih bisa dipakai. Kalau akun boleh menganggur sebulan tapi slotnya
 * bebas setelah 30 menit, satu orang bisa mengumpulkan sesi hidup tanpa
 * batas — pembatas 3 perangkatnya jadi tidak berarti apa-apa.
 */
function ambang_perangkat(PDO $pdo, int $userId): int {
    try {
        $st = $pdo->prepare("SELECT idle_menit FROM users WHERE id = ?");
        $st->execute([$userId]);
        $m = (int) $st->fetchColumn();
        return $m > 0 ? $m : IDLE_MENIT_BAWAAN;
    } catch (Throwable $e) {
        return IDLE_MENIT_BAWAAN;   // kolom belum dimigrasi
    }
}

/** Pilihan batas menganggur yang ditawarkan — label untuk ditampilkan. */
function pilihan_idle(): array {
    return [
        30    => '30 menit',
        120   => '2 jam',
        480   => '8 jam — satu giliran kerja',
        1440  => '1 hari',
        10080 => '1 minggu',
        20160 => '2 minggu',
        43200 => '1 bulan',
    ];
}

/** Label terbaca dari jumlah menit. */
function label_idle(int $menit): string {
    return pilihan_idle()[$menit] ?? ($menit . ' menit');
}

/** Ringkasan terbaca dari user agent: "Windows · Chrome". */
function perangkat_ringkas(?string $ua): string {
    $ua = (string) $ua;
    if ($ua === '') { return 'Perangkat tidak dikenal'; }

    $os = 'Sistem lain';
    foreach ([
        'Windows NT 10' => 'Windows', 'Windows NT' => 'Windows',
        'Android'       => 'Android', 'iPhone'     => 'iPhone',
        'iPad'          => 'iPad',    'Macintosh'  => 'Mac',
        'Linux'         => 'Linux',
    ] as $tanda => $nama) {
        if (stripos($ua, $tanda) !== false) { $os = $nama; break; }
    }

    // Urutannya penting: Edge & Opera menyebut "Chrome" di UA-nya, dan
    // Chrome menyebut "Safari". Yang paling khas harus diperiksa dulu.
    $br = 'Peramban lain';
    foreach ([
        'Edg' => 'Edge', 'OPR' => 'Opera', 'Firefox' => 'Firefox',
        'Chrome' => 'Chrome', 'Safari' => 'Safari',
    ] as $tanda => $nama) {
        if (stripos($ua, $tanda) !== false) { $br = $nama; break; }
    }
    return $os . ' · ' . $br;
}

/** Sidik sesi saat ini — disimpan sebagai hash, bukan ID sesi apa adanya. */
function sid_sekarang(): string {
    return hash('sha256', session_id());
}

/** Sesi yang masih hidup untuk sebuah akun. */
function perangkat_hidup(PDO $pdo, int $userId): array {
    try {
        $st = $pdo->prepare(
            "SELECT id, sid_hash, perangkat, ip, dibuat_at, terakhir_at
             FROM sesi_perangkat
             WHERE user_id = ? AND diakhiri_at IS NULL
               AND terakhir_at >= (NOW() - INTERVAL " . ambang_perangkat($pdo, $userId) . " MINUTE)
             ORDER BY terakhir_at DESC"
        );
        $st->execute([$userId]);
        return $st->fetchAll();
    } catch (Throwable $e) {
        error_log('[AMANAH] perangkat_hidup: ' . $e->getMessage());
        return [];   // tabel belum dimigrasi — jangan sampai login jadi mustahil
    }
}

/**
 * Bolehkah akun ini menambah satu perangkat lagi?
 * Mengembalikan [boleh, daftarPerangkatHidup].
 *
 * Sesi yang SUDAH terdaftar tidak dihitung sebagai perangkat baru —
 * kalau tidak, memuat ulang halaman login bisa menolak diri sendiri.
 */
function boleh_tambah_perangkat(PDO $pdo, int $userId): array {
    $hidup = perangkat_hidup($pdo, $userId);
    $sid   = sid_sekarang();
    foreach ($hidup as $h) {
        if (hash_equals($h['sid_hash'], $sid)) { return [true, $hidup]; }
    }

    /* Batasnya milik AKUN INI, bukan konstanta global.

       0 diperiksa LEBIH DULU dan berarti tanpa batas. Tanpa pemeriksaan
       itu, `count($hidup) < 0` selalu salah — dan akun yang disetel
       "tanpa batas" justru tidak bisa masuk dari perangkat mana pun.
       Kekeliruan seperti itu terbaca sebagai sandi yang salah. */
    $maks = maks_perangkat($pdo, $userId);
    if ($maks === 0) { return [true, $hidup]; }

    return [count($hidup) < $maks, $hidup];
}

// =====================================================================
// PENGATURAN APLIKASI (logo, favicon, gambar share)
// =====================================================================
/** Folder unggahan, relatif terhadap akar aplikasi. */
const DIR_UNGGAHAN = 'unggahan';

/** Batas ukuran berkas gambar yang diunggah. */
const MAKS_UNGGAHAN = 2 * 1024 * 1024;   // 2 MB

/**
 * Seluruh pengaturan, dibaca sekali per permintaan.
 *
 * Dimuat sekaligus alih-alih per kunci: _header.php membutuhkan tiga
 * pengaturan pada setiap pemuatan halaman, dan tiga query untuk tiga baris
 * kecil adalah biaya yang tidak perlu dibayar di SETIAP halaman.
 */
function semua_pengaturan(PDO $pdo): array {
    static $cache = null;
    if ($cache !== null) { return $cache; }
    $cache = [];
    try {
        foreach ($pdo->query("SELECT kunci, nilai FROM pengaturan") as $r) {
            $cache[$r['kunci']] = (string) $r['nilai'];
        }
    } catch (Throwable $e) {
        // Tabelnya mungkin belum dimigrasi. Halaman harus tetap terbuka —
        // kehilangan logo jauh lebih ringan daripada situs yang mati.
        error_log('[AMANAH] semua_pengaturan: ' . $e->getMessage());
    }
    return $cache;
}

function pengaturan(PDO $pdo, string $kunci, string $bawaan = ''): string {
    $s = semua_pengaturan($pdo);
    return ($s[$kunci] ?? '') !== '' ? $s[$kunci] : $bawaan;
}

function simpan_pengaturan(PDO $pdo, string $kunci, string $nilai): void {
    $pdo->prepare(
        "INSERT INTO pengaturan (kunci, nilai, diubah_oleh) VALUES (?,?,?)
         ON DUPLICATE KEY UPDATE nilai = VALUES(nilai), diubah_oleh = VALUES(diubah_oleh)"
    )->execute([$kunci, $nilai, $_SESSION['user']['id'] ?? null]);
}

/**
 * URL berkas unggahan, atau '' bila tidak ada.
 *
 * Nama berkas diperiksa ulang di sini, bukan dipercaya dari basis data:
 * nilai yang tersimpan pernah melewati formulir, dan basename() memastikan
 * '../../config.php' tidak pernah menjadi URL yang bisa ditunjuk.
 */
function url_unggahan(string $nama): string {
    $nama = basename(trim($nama));
    if ($nama === '' || !is_file(__DIR__ . '/' . DIR_UNGGAHAN . '/' . $nama)) { return ''; }
    // Cap waktu berkas jadi pemecah cache: logo yang diganti harus langsung
    // terlihat, bukan menunggu cache peramban kedaluwarsa sendiri.
    return DIR_UNGGAHAN . '/' . rawurlencode($nama)
         . '?v=' . filemtime(__DIR__ . '/' . DIR_UNGGAHAN . '/' . $nama);
}

/**
 * Susun URL absolut ke sebuah halaman di aplikasi ini.
 *
 * Dibutuhkan QR code pada surat jalan: isi QR harus URL LENGKAP, karena yang
 * memindainya adalah kamera ponsel yang tidak punya "halaman sekarang" untuk
 * dijadikan acuan URL relatif.
 *
 * Skema dibaca dari tiga sumber, dan urutannya penting. Hosting memakai
 * LiteSpeed di belakang proksi TLS: di sana $_SERVER['HTTPS'] sering kosong
 * walaupun pengunjung datang lewat https, dan yang benar justru ada di
 * X-Forwarded-Proto. Kalau urutannya dibalik, QR akan memuat tautan http
 * yang lalu dialihkan — dan sebagian pemindai ponsel menolak mengikuti
 * pengalihan dari QR.
 */
function url_penuh(string $halaman = ''): string {
    $proto = 'http';
    if (!empty($_SERVER['HTTP_X_FORWARDED_PROTO'])) {
        // Bisa berisi daftar ("https, http") bila lewat lebih dari satu proksi.
        $proto = strtolower(trim(explode(',', $_SERVER['HTTP_X_FORWARDED_PROTO'])[0]));
    } elseif (!empty($_SERVER['HTTPS']) && strtolower($_SERVER['HTTPS']) !== 'off') {
        $proto = 'https';
    } elseif ((int) ($_SERVER['SERVER_PORT'] ?? 0) === 443) {
        $proto = 'https';
    }
    if ($proto !== 'https') { $proto = 'http'; }

    $host = (string) ($_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? 'localhost');
    // Host datang dari permintaan, jadi tidak dipercaya apa adanya: hanya
    // huruf, angka, titik, tanda hubung, dan nomor porta yang diteruskan.
    $host = preg_replace('/[^A-Za-z0-9.\-:]/', '', $host);

    // Folder tempat aplikasi dipasang — di lokal /Gudang, di hosting bisa /.
    $dir = rtrim(str_replace('\\', '/', dirname((string) ($_SERVER['SCRIPT_NAME'] ?? '/'))), '/');

    return $proto . '://' . $host . $dir . '/' . ltrim($halaman, '/');
}

/**
 * Terima satu berkas gambar unggahan. Mengembalikan [namaBerkas, galat].
 *
 * Jenis berkas ditentukan oleh getimagesize(), BUKAN oleh ekstensi atau
 * header Content-Type — keduanya berasal dari pengunggah dan bisa diketik
 * apa saja. Ekstensi hasil pun ditentukan sistem, bukan diambil dari nama
 * berkas aslinya, sehingga "gambar.php" tidak mungkin tersimpan sebagai .php.
 *
 * SVG sengaja TIDAK diterima meskipun ia format logo yang wajar: berkas SVG
 * adalah XML yang boleh memuat <script>, dan menyajikannya dari domain yang
 * sama dengan aplikasi berarti skrip itu berjalan dengan sesi pemakainya.
 */
function terima_gambar(string $field, string $awalan): array {
    if (!isset($_FILES[$field]) || ($_FILES[$field]['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return ['', ''];   // tidak ada berkas dikirim — bukan galat
    }

    $f = $_FILES[$field];
    if ($f['error'] !== UPLOAD_ERR_OK) {
        $peta = [
            UPLOAD_ERR_INI_SIZE   => 'Berkas melebihi batas server (upload_max_filesize).',
            UPLOAD_ERR_FORM_SIZE  => 'Berkas melebihi batas formulir.',
            UPLOAD_ERR_PARTIAL    => 'Berkas terkirim sebagian. Coba lagi.',
            UPLOAD_ERR_NO_TMP_DIR => 'Folder sementara server tidak tersedia.',
            UPLOAD_ERR_CANT_WRITE => 'Server gagal menulis berkas sementara.',
        ];
        return ['', $peta[$f['error']] ?? 'Berkas gagal diunggah.'];
    }
    if ($f['size'] > MAKS_UNGGAHAN) {
        return ['', 'Ukuran maksimal ' . round(MAKS_UNGGAHAN / 1024 / 1024, 1) . ' MB. '
                  . 'Berkas Anda ' . round($f['size'] / 1024 / 1024, 2) . ' MB.'];
    }
    // is_uploaded_file(): memastikan yang dibaca benar-benar berkas unggahan
    // permintaan ini, bukan jalur lain di server yang disebut lewat POST.
    if (!is_uploaded_file($f['tmp_name'])) {
        return ['', 'Berkas tidak dikenali sebagai unggahan yang sah.'];
    }

    $info = @getimagesize($f['tmp_name']);
    if ($info === false) {
        return ['', 'Berkas itu bukan gambar yang bisa dibaca. Pakai PNG, JPG, atau WebP.'];
    }
    $ext = [
        IMAGETYPE_PNG  => 'png',
        IMAGETYPE_JPEG => 'jpg',
        IMAGETYPE_WEBP => 'webp',
    ][$info[2]] ?? null;
    if ($ext === null) {
        return ['', 'Format itu belum didukung. Pakai PNG, JPG, atau WebP '
                  . '(SVG tidak diterima karena bisa memuat skrip).'];
    }

    $dir = __DIR__ . '/' . DIR_UNGGAHAN;
    if (!is_dir($dir) && !@mkdir($dir, 0755, true)) {
        return ['', 'Folder unggahan tidak ada dan tidak bisa dibuat.'];
    }

    // Nama acak: nama asli tidak dipakai sama sekali, jadi tidak ada
    // karakter dari pengguna yang pernah masuk ke jalur berkas.
    $nama = $awalan . '_' . bin2hex(random_bytes(8)) . '.' . $ext;
    if (!@move_uploaded_file($f['tmp_name'], $dir . '/' . $nama)) {
        return ['', 'Gagal menyimpan berkas ke folder unggahan.'];
    }
    @chmod($dir . '/' . $nama, 0644);

    return [$nama, ''];
}

/** Hapus berkas unggahan lama. Kegagalannya tidak menghentikan apa pun. */
function hapus_unggahan(string $nama): void {
    $nama = basename(trim($nama));
    if ($nama === '') { return; }
    $path = __DIR__ . '/' . DIR_UNGGAHAN . '/' . $nama;
    if (is_file($path)) { @unlink($path); }
}

/**
 * Mengapa sesi ini berakhir — dibaca dari catatannya, bukan ditebak.
 *
 * Mengembalikan salah satu kode: 'lain' (diakhiri oleh pemilik akun dari
 * perangkat lain), 'admin' (diakhiri akun lain, mis. Super Admin), atau
 * 'sistem' (diakhiri tanpa lewat aplikasi — biasanya perubahan langsung di
 * basis data). Kode 'sistem' itu penting: ia membedakan "seseorang menekan
 * tombol" dari "ada yang menyentuh basis datanya", dan itu satu-satunya
 * petunjuk yang berguna saat seseorang terlempar keluar berulang kali.
 */
function alasan_akhir_sesi(PDO $pdo, int $userId): string {
    try {
        $st = $pdo->prepare(
            "SELECT diakhiri_oleh FROM sesi_perangkat WHERE sid_hash = ? LIMIT 1"
        );
        $st->execute([sid_sekarang()]);
        $oleh = $st->fetchColumn();

        if ($oleh === false || $oleh === null) { return 'sistem'; }
        return ((int) $oleh === $userId) ? 'lain' : 'admin';
    } catch (Throwable $e) {
        error_log('[AMANAH] alasan_akhir_sesi: ' . $e->getMessage());
        return 'sistem';
    }
}

/** Daftarkan / segarkan sesi saat ini sebagai perangkat aktif. */
function catat_perangkat(PDO $pdo, int $userId): void {
    $ua = substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255);
    try {
        $pdo->prepare(
            "INSERT INTO sesi_perangkat (user_id, sid_hash, perangkat, user_agent, ip)
             VALUES (?,?,?,?,?)
             ON DUPLICATE KEY UPDATE terakhir_at = NOW(), diakhiri_at = NULL"
        )->execute([$userId, sid_sekarang(), perangkat_ringkas($ua), $ua,
                    substr((string) ($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45)]);
    } catch (Throwable $e) {
        error_log('[AMANAH] catat_perangkat: ' . $e->getMessage());
    }
}

/**
 * Segarkan jejak sesi ini, dan beri tahu bila sesinya sudah diakhiri
 * dari perangkat lain. Dipanggil sekali per pemuatan halaman.
 */
function segarkan_perangkat(PDO $pdo, int $userId): bool {
    try {
        /* Sekalian membawa idle_menit — tanpa query tambahan — supaya
           perubahan batas oleh Super Admin berlaku hampir seketika, bukan
           menunggu pengguna login ulang.

           Tingkat TIDAK ikut dibawa ke sesi di sini: tingkat_saat_ini()
           sudah membacanya langsung dari basis data tiap permintaan, jadi
           menyalinnya ke sesi hanya menambah sumber kedua yang bisa basi. */
        $st = $pdo->prepare(
            "SELECT s.diakhiri_at, u.idle_menit
             FROM sesi_perangkat s JOIN users u ON u.id = s.user_id
             WHERE s.sid_hash = ?"
        );
        $st->execute([sid_sekarang()]);
        $baris = $st->fetch();
        if ($baris && isset($baris["idle_menit"])) {
            $_SESSION["idle_menit"] = (int) $baris["idle_menit"];
        }

        // Belum tercatat (mis. sesi dari sebelum fitur ini ada) — daftarkan.
        if (!$baris) { catat_perangkat($pdo, $userId); return true; }

        if ($baris['diakhiri_at'] !== null) { return false; }   // sudah diakhiri

        $pdo->prepare("UPDATE sesi_perangkat SET terakhir_at = NOW() WHERE sid_hash = ?")
            ->execute([sid_sekarang()]);
        return true;
    } catch (Throwable $e) {
        error_log('[AMANAH] segarkan_perangkat: ' . $e->getMessage());
        return true;   // jangan mengunci pengguna karena galat pencatatan
    }
}

/** Akhiri satu sesi. $oleh diisi bila yang mengakhiri bukan pemiliknya. */
function akhiri_perangkat(PDO $pdo, int $id, ?int $oleh = null): bool {
    try {
        $st = $pdo->prepare(
            "UPDATE sesi_perangkat SET diakhiri_at = NOW(), diakhiri_oleh = ?
             WHERE id = ? AND diakhiri_at IS NULL"
        );
        $st->execute([$oleh, $id]);
        return $st->rowCount() > 0;
    } catch (Throwable $e) {
        error_log('[AMANAH] akhiri_perangkat: ' . $e->getMessage());
        return false;
    }
}

/** Tandai sesi ini berakhir — dipanggil saat pengguna menekan Keluar. */
function akhiri_perangkat_ini(PDO $pdo): void {
    try {
        $pdo->prepare("UPDATE sesi_perangkat SET diakhiri_at = NOW()
                       WHERE sid_hash = ? AND diakhiri_at IS NULL")
            ->execute([sid_sekarang()]);
    } catch (Throwable $e) {
        error_log('[AMANAH] akhiri_perangkat_ini: ' . $e->getMessage());
    }
}

// =====================================================================
// GUDANG SISI TOKO
// ---------------------------------------------------------------------
// Rantai barang berujung lewat gudang penampung sebelum dipecah ke tiap
// toko:  Supplier ──> Gudang Toko ──> Toko A / B / C
//
// Bukan tabel baru — hanya penanda pada gudang yang sudah ada, karena
// rute perpindahannya (penerimaan, kirim_toko) memang sudah tersedia.
// =====================================================================

/** Gudang yang berperan sebagai penampung sisi toko. */
function gudang_toko(PDO $pdo): array {
    static $cache = null;
    if ($cache !== null) { return $cache; }
    try {
        $cache = $pdo->query(
            // telepon ikut diambil karena formulir pengaturan di dasbor toko
            // menyuntingnya; tanpa kolom ini formulirnya memicu undefined index.
            "SELECT id, kode, nama, alamat, penanggung_jawab, telepon
             FROM gudang
             WHERE jenis = 'toko' AND deleted_at IS NULL AND is_active = 1
             ORDER BY kode"
        )->fetchAll();
    } catch (Throwable $e) {
        error_log('[AMANAH] gudang_toko: ' . $e->getMessage());
        $cache = [];
    }
    return $cache;
}

/**
 * Gudang sisi toko yang DIPEGANG AKUN INI.
 *
 * Bedanya dengan gudang_toko() di atas: yang itu daftar sekeliling lembaga
 * — dipakai untuk menjawab "apakah gudang toko sudah ada" dan dibaca juga
 * oleh Super Admin. Yang ini lingkup kerja satu akun toko.
 *
 * Kalau akun toko itu diberi penugasan gudang lewat Kelola Pengguna
 * (tabel user_gudang), lingkupnya adalah gudang-gudang ITU. Kalau tidak,
 * ia jatuh ke seluruh gudang toko — perilaku lama, waktu gudang penampung
 * memang satu dan dipakai bersama.
 *
 * Penugasannya hanya berlaku untuk PERAN TOKO. Untuk peran lain fungsi ini
 * mengembalikan daftar sekeliling lembaga, karena Super Admin yang memantau
 * dasbor toko harus melihat gudangnya, bukan gudang yang ditugaskan pada
 * dirinya sendiri.
 */
function gudang_toko_saya(PDO $pdo): array {
    static $cache = null;
    if ($cache !== null) { return $cache; }

    if (!is_toko()) { return $cache = gudang_toko($pdo); }

    $uid = (int) ($_SESSION['user']['id'] ?? 0);
    if ($uid > 0) {
        try {
            $st = $pdo->prepare(
                "SELECT g.id, g.kode, g.nama, g.alamat, g.penanggung_jawab, g.telepon
                   FROM user_gudang ug
                   JOIN gudang g ON g.id = ug.gudang_id
                  WHERE ug.user_id = ? AND g.deleted_at IS NULL AND g.is_active = 1
                  ORDER BY g.kode");
            $st->execute([$uid]);
            $pilih = $st->fetchAll();
            if ($pilih) { return $cache = $pilih; }
        } catch (Throwable $e) {
            error_log('[AMANAH] gudang_toko_saya: ' . $e->getMessage());
        }
    }
    return $cache = gudang_toko($pdo);
}

/** Id rak milik gudang sisi toko yang dipegang akun ini. */
function rak_gudang_toko(PDO $pdo): array {
    $gd = array_column(gudang_toko_saya($pdo), 'id');
    if (!$gd) { return []; }
    $in = implode(',', array_map('intval', $gd));
    try {
        return array_column($pdo->query(
            "SELECT id FROM lokasi_rak WHERE gudang_id IN ($in) AND deleted_at IS NULL"
        )->fetchAll(), 'id');
    } catch (Throwable $e) {
        error_log('[AMANAH] rak_gudang_toko: ' . $e->getMessage());
        return [];
    }
}

function role_saat_ini(): string {
    return $_SESSION['role'] ?? '';
}

/** Gudang milik supplier yang sedang login — satu gudang per supplier. */
function gudang_supplier(PDO $pdo): array {
    static $cache = null;
    if ($cache !== null) { return $cache; }
    $sid = supplier_saya();
    if (!$sid) { return $cache = []; }
    try {
        $st = $pdo->prepare(
            "SELECT id, kode, nama, alamat, penanggung_jawab, telepon
             FROM gudang
             WHERE jenis = 'supplier' AND supplier_id = ?
               AND deleted_at IS NULL AND is_active = 1
             ORDER BY kode"
        );
        $st->execute([$sid]);
        $cache = $st->fetchAll();
    } catch (Throwable $e) {
        error_log('[AMANAH] gudang_supplier: ' . $e->getMessage());
        $cache = [];
    }
    return $cache;
}

/** Id rak di dalam gudang supplier yang sedang login. */
function rak_supplier(PDO $pdo): array {
    $gd = array_column(gudang_supplier($pdo), 'id');
    if (!$gd) { return []; }
    try {
        return array_map('intval', $pdo->query(
            "SELECT id FROM lokasi_rak
             WHERE gudang_id IN (" . implode(',', array_map('intval', $gd)) . ")
               AND deleted_at IS NULL ORDER BY kode"
        )->fetchAll(PDO::FETCH_COLUMN));
    } catch (Throwable $e) {
        error_log('[AMANAH] rak_supplier: ' . $e->getMessage());
        return [];
    }
}

/**
 * Lingkup stok peran yang sedang login.
 *
 * Satu tempat yang menjawab "stok siapa yang boleh dilihat akun ini", dipakai
 * Batch & Expired maupun Laporan Stok. Kalau masing-masing halaman menghitung
 * lingkupnya sendiri, dua halaman yang bersebelahan di menu bisa memberi dua
 * angka berbeda untuk barang yang sama — dan tidak ada cara memberi tahu
 * pemakainya yang mana yang benar.
 *
 * Kunci yang dikembalikan:
 *   semua     seluruh rantai, tanpa penyaringan (Super Admin & Pimpinan)
 *   rak       id rak, untuk batch_barang.lokasi_rak_id
 *   toko      id toko, untuk batch_barang.toko_id
 *   supplier  id supplier, untuk stok_supplier.supplier_id
 *   label     keterangan singkat untuk ditulis di halamannya
 */
function lingkup_stok(PDO $pdo): array {
    static $cache = null;
    if ($cache !== null) { return $cache; }

    $dasar = ['semua' => false, 'rak' => [], 'toko' => [], 'supplier' => null, 'label' => ''];

    if (is_super() || role_saat_ini() === 'pimpinan') {
        return $cache = array_merge($dasar, ['semua' => true, 'label' => 'Seluruh rantai']);
    }

    if (terikat_satu_gudang()) {
        $nama = array_column($pdo->query(
            "SELECT nama FROM gudang WHERE id IN (" . in_gudang_saya($pdo) . ")
               AND deleted_at IS NULL")->fetchAll(), 'nama');
        return $cache = array_merge($dasar, [
            'rak'   => rak_saya($pdo),
            'label' => $nama ? implode(', ', $nama) : 'Belum ada gudang yang ditugaskan',
        ]);
    }

    if (is_toko()) {
        /* Toko punya DUA tempat stok: gudang penampung bersama, dan stok yang
           sudah berada di masing-masing tokonya. Keduanya masuk lingkup —
           menyembunyikan salah satunya membuat total di halaman tidak pernah
           cocok dengan barang yang benar-benar dipegang toko. */
        /* gudang_toko_saya(), bukan gudang_toko(): akun toko yang diberi
           penugasan gudang tertentu hanya melihat gudang itu. Tanpa
           penugasan, jatuh ke seluruh gudang toko seperti dulu. */
        $gd = array_column(gudang_toko_saya($pdo), 'nama');
        return $cache = array_merge($dasar, [
            'rak'   => array_map('intval', rak_gudang_toko($pdo)),
            'toko'  => toko_saya($pdo),
            'label' => 'Toko Anda' . ($gd ? ' & ' . implode(', ', $gd) : ''),
        ]);
    }

    if (is_supplier()) {
        $gd = array_column(gudang_supplier($pdo), 'nama');
        return $cache = array_merge($dasar, [
            'rak'      => rak_supplier($pdo),
            'supplier' => supplier_saya(),
            'label'    => $gd ? implode(', ', $gd) : 'Gudang Anda',
        ]);
    }

    return $cache = $dasar;
}

/**
 * Potongan `IN (...)` untuk sebuah kunci lingkup.
 *
 * Mengembalikan '0' bila kosong, bukan string kosong: `IN ()` adalah galat
 * sintaks MySQL, sementara `IN (0)` sah dan tidak cocok dengan apa pun.
 * Bedanya antara halaman kosong dan halaman rusak.
 */
function in_lingkup(PDO $pdo, string $kunci): string {
    $l = lingkup_stok($pdo);
    $v = array_map('intval', (array) ($l[$kunci] ?? []));
    return $v ? implode(',', $v) : '0';
}

/**
 * Syarat WHERE untuk v_expired_rantai / v_stok_rantai sesuai lingkup peran.
 *
 * $alias mengisi awalan kolom bila view-nya dipakai dengan alias di JOIN.
 *
 * Mengembalikan '1=0' bila lingkupnya kosong — BUKAN '1=1'. Peran yang belum
 * punya gudang atau toko harus melihat halaman kosong; membiarkannya melihat
 * seluruh stok lembaga hanya karena penyaringnya kebetulan tidak terisi
 * adalah kebocoran yang paling mudah lolos dari pemeriksaan.
 */
function syarat_lingkup(PDO $pdo, string $alias = ''): string {
    $L = lingkup_stok($pdo);
    if ($L['semua']) { return '1=1'; }

    $p = $alias !== '' ? $alias . '.' : '';
    $bagian = [];
    if ($L['rak'])      { $bagian[] = "{$p}lokasi_rak_id IN (" . in_lingkup($pdo, 'rak') . ')'; }
    if ($L['toko'])     { $bagian[] = "{$p}toko_id IN ("       . in_lingkup($pdo, 'toko') . ')'; }
    if ($L['supplier']) { $bagian[] = "{$p}supplier_id = "     . (int) $L['supplier']; }
    return $bagian ? '(' . implode(' OR ', $bagian) . ')' : '1=0';
}

/**
 * Gudang dalam lingkup peran ini, sebagai daftar id.
 *
 * Berbeda dari lingkup 'rak': sebuah gudang yang belum punya rak tetap masuk
 * lingkup, karena ia harus bisa dipilih sebagai tujuan barang pertamanya.
 */
function gudang_lingkup(PDO $pdo): array {
    if (is_toko())     { return array_map('intval', array_column(gudang_toko($pdo), 'id')); }
    if (is_supplier()) { return array_map('intval', array_column(gudang_supplier($pdo), 'id')); }
    return gudang_saya($pdo);
}

/**
 * Syarat WHERE untuk tabel `perpindahan` sesuai lingkup peran.
 *
 * Dokumen dianggap milik lingkup bila ASAL atau TUJUAN-nya menyentuhnya —
 * keduanya, bukan salah satu. Dokumen kirim_toko misalnya berasal dari rak
 * gudang dan berakhir di toko: gudang perlu melihatnya sebagai barang yang
 * keluar, toko perlu melihatnya sebagai barang yang datang. Menyaring hanya
 * satu sisi membuat separuh pihak kehilangan dokumen yang menyangkut dirinya.
 */
function syarat_perpindahan(PDO $pdo, string $alias = 'p'): string {
    /* ----------------------------------------------------------------------
       SURAT JALAN HANYA UNTUK PENGIRIM DAN PENERIMA

       Dulu baris ini melepas SELURUH dokumen untuk siapa pun yang
       lingkup_stok()-nya 'semua' — yaitu Super Admin DAN Pimpinan. Untuk
       Pimpinan itu berarti setiap surat jalan di lembaga terbuka, lengkap
       dengan nama penerima manfaat dan alamat pengirimannya.

       Sekarang hanya Super Admin. Pimpinan tetap melihat laporan dan
       ringkasan stok seperti sebelumnya — lingkup_stok() TIDAK diubah,
       supaya perannya tidak ikut berkurang; yang ditutup khusus daftar
       dokumennya. Karena Pimpinan tidak ditugaskan ke gudang atau toko
       mana pun, klausa di bawah menghasilkan 1=0 dan daftarnya kosong;
       menu Surat Jalan pun tidak lagi dipasang untuk peran itu.

       is_super(), bukan $L['semua']: keduanya kebetulan sama untuk Super
       Admin hari ini, tetapi 'semua' adalah pernyataan tentang STOK yang
       boleh dilihat, sedangkan yang diputuskan di sini DOKUMEN. Peran baru
       yang suatu saat diberi pandangan stok menyeluruh tidak boleh
       otomatis ikut mendapat seluruh surat jalan.
       ---------------------------------------------------------------------- */
    if (is_super()) { return '1=1'; }
    $L = lingkup_stok($pdo);

    $a = $alias !== '' ? $alias . '.' : '';
    $bagian = [];

    $pasang = function (string $tipe, string $daftar) use ($a, &$bagian) {
        if ($daftar === '' || $daftar === '0') { return; }
        $bagian[] = "({$a}asal_tipe = '$tipe' AND {$a}asal_id IN ($daftar))";
        $bagian[] = "({$a}tujuan_tipe = '$tipe' AND {$a}tujuan_id IN ($daftar))";
    };

    $pasang('rak', in_lingkup($pdo, 'rak'));
    $pasang('toko', in_lingkup($pdo, 'toko'));

    /* --------------------------------------------------------------------
       DAFTAR GUDANG DIAMBIL DARI PENUGASAN, BUKAN DARI gudang_lingkup()

       gudang_lingkup() menjawab pertanyaan lain: "gudang mana yang boleh
       DILIHAT akun ini", dan untuk peran yang tidak terikat gudang mana
       pun jawabannya SELURUH gudang. Dipakai di sini, itu membuat
       pembatasan di atas tidak ada artinya: Pimpinan tidak lagi mendapat
       jalan cepat '1=1', tetapi daftar gudangnya berisi semua, sehingga
       setiap dokumen bergudang tetap cocok. Diuji dan memang begitu —
       klausanya keluar sebagai `asal_id IN (5,6,11,12,16,17)`.

       Jadi di sini gudangnya harus datang dari PENUGASAN yang nyata:
       - terikat_satu_gudang() -> gudang yang ditugaskan padanya
       - toko                  -> gudang toko yang dipegangnya
       - supplier              -> gudang mitranya
       Peran tanpa penugasan tidak mendapat cabang apa pun, dan hasil
       akhirnya 1=0. Itu arah gagal yang benar: peran yang lupa didaftarkan
       melihat NOL dokumen, bukan seluruhnya.
       -------------------------------------------------------------------- */
    $gd = [];
    if (terikat_satu_gudang())  { $gd = gudang_saya($pdo); }
    elseif (is_toko())          { $gd = array_column(gudang_toko_saya($pdo), 'id'); }
    elseif (is_supplier())      { $gd = array_column(gudang_supplier($pdo), 'id'); }
    if ($gd) { $pasang('gudang', implode(',', array_map('intval', $gd))); }

    if ($L['supplier']) { $pasang('supplier', (string) (int) $L['supplier']); }

    /* Gudang transit ikut melihat surat jalan yang singgah padanya — tanpa
       ini ia tidak bisa menandai "tiba" dan "berangkat" (lihat _lacak.php). */
    if (($transit = syarat_transit_saya($pdo, $alias)) !== '') { $bagian[] = $transit; }

    return $bagian ? '(' . implode(' OR ', $bagian) . ')' : '1=0';
}

/**
 * Bolehkah akun ini melihat SEBUAH surat jalan / dokumen perpindahan.
 *
 * Memakai syarat_perpindahan() — aturan yang sama dengan daftarnya. Itu
 * pokok fungsi ini: sebelumnya aturan yang sama ditulis TIGA kali dengan
 * tiga bentuk berbeda (daftar di perpindahan.php, cetak di surat_jalan.php,
 * pindai di cek_surat.php), dan dua di antaranya melewatkan peran internal
 * sepenuhnya — petugas gudang A bisa mencetak surat jalan gudang B hanya
 * dengan mengganti angka di alamat. Satu aturan, satu tempat.
 */
function boleh_lihat_perpindahan(PDO $pdo, int $ppId): bool {
    if ($ppId <= 0) { return false; }
    try {
        $st = $pdo->prepare(
            "SELECT 1 FROM perpindahan p
              WHERE p.id = ? AND " . syarat_perpindahan($pdo, 'p') . " LIMIT 1");
        $st->execute([$ppId]);
        return (bool) $st->fetchColumn();
    } catch (Throwable $e) {
        /* Gagal memeriksa berarti TIDAK diketahui, dan yang tidak diketahui
           ditolak. Menjawab "boleh" saat pemeriksaannya sendiri gagal adalah
           cara paling sunyi untuk membuka seluruh dokumen. */
        error_log('[AMANAH] boleh_lihat_perpindahan: ' . $e->getMessage());
        return false;
    }
}

function boleh_edit(): bool {
    return in_array(role_saat_ini(), ['super_admin', 'admin_gudang', 'admin_terpadu'], true);
}

// =====================================================================
// PERAN TERPADU — gudang dan penerimaan donasi di satu tempat
// ---------------------------------------------------------------------
// Pada lembaga yang gudang dan penerimaan titipannya berada di satu
// tempat, memisahkan "supplier" dan "gudang" berarti orang yang sama
// menerbitkan surat jalan untuk dirinya sendiri lalu menerimanya sendiri.
// Barangnya tidak berpindah ke mana pun.
//
// Peran ini bekerja seperti Admin Gudang — terikat pada SATU gudang, dan
// seluruh lingkupnya dibatasi gudang itu — hanya saja ia juga mengelola
// donatur dan mencatat titipan langsung ke stok gudang, tanpa singgah di
// stok supplier.
//
// PENTING untuk siapa pun yang menambah peran baru sesudah ini: setiap
// percabangan lingkup di berkas ini yang menyebut is_admin_gudang() harus
// ikut menyebut peran barunya. Kalau terlewat, peran itu JATUH KE CABANG
// TERAKHIR — dan cabang terakhir gudang_saya() mengembalikan SELURUH
// gudang. Peran yang lupa didaftarkan tidak kehilangan akses; ia justru
// mendapat akses ke semuanya. Karena itu keduanya disatukan di
// terikat_satu_gudang() di bawah, supaya hanya ada satu tempat yang perlu
// diingat.
// =====================================================================

function is_admin_terpadu(): bool {
    return role_saat_ini() === 'admin_terpadu';
}

/**
 * Peran yang wewenangnya terikat pada satu gudang saja.
 *
 * Dipakai menggantikan is_admin_gudang() di SEMUA percabangan lingkup.
 */
function terikat_satu_gudang(): bool {
    return is_admin_gudang() || is_admin_terpadu();
}

/**
 * Bolehkah akun ini menyetujui dokumen, secara umum.
 *
 * Bawaannya tetap Super Admin & Pimpinan, tapi kini bisa disimpangkan per
 * akun lewat izin 'setujui'. $pdo opsional supaya pemanggil lama —
 * _header.php dan perpindahan.php — tidak perlu ikut berubah; tanpa $pdo
 * ia menjawab menurut peran saja, persis seperti sebelumnya.
 */
function boleh_setujui(?PDO $pdo = null): bool {
    $bawaan = in_array(role_saat_ini(), ['super_admin', 'pimpinan'], true);
    return $pdo ? izin($pdo, 'setujui', $bawaan) : $bawaan;
}

/**
 * Bolehkah akun ini menyetujui SEBUAH dokumen tertentu.
 *
 * Berbeda dari boleh_setujui() yang menjawab "apakah orang ini seorang
 * penyetuju" tanpa melihat dokumennya. Dipisah karena satu jenis dokumen
 * sengaja diberi penyetuju yang berbeda:
 *
 *   Penyaluran LANGSUNG dari mitra ke penerima amanah cukup disetujui
 *   MANAGER gudang. Barangnya tidak pernah masuk gudang, dan yang perlu
 *   memastikan penerimanya benar serta jumlahnya wajar adalah orang yang
 *   mengurus penyaluran sehari-hari — bukan pimpinan lembaga. Menunggu
 *   pimpinan untuk tiap kunjungan mitra membuat barangnya menganggur di
 *   tempat mitra, dan pada praktiknya membuat orang berhenti mencatatnya.
 *
 * Jenis lain TETAP milik Super Admin dan Pimpinan. Wewenang manager di
 * sini sengaja sempit: satu jenis, satu asal.
 */
/**
 * Potongan WHERE untuk dokumen yang WAJIB terlihat karena akun ini
 * berwenang menyetujuinya — terlepas dari lingkup gudangnya.
 *
 * Ini pasangan SQL dari boleh_setujui_dokumen(), dan tanpanya fitur
 * persetujuan manager tidak bisa dipakai sama sekali: penyaluran langsung
 * mitra → penerima amanah tidak menyentuh gudang mana pun, sehingga
 * syarat_perpindahan() — yang menyaring berdasarkan rak, toko, dan supplier
 * dalam lingkup — menyembunyikannya dari SEMUA manager gudang. Dokumen yang
 * menunggu persetujuan seseorang tapi tidak pernah tampil di layarnya adalah
 * dokumen yang menggantung selamanya.
 *
 * Sengaja SEMPIT: hanya kelas dokumen yang memang boleh ia setujui, bukan
 * pelonggaran lingkup secara umum.
 */
function syarat_persetujuan_saya(PDO $pdo, string $alias = 'p'): string {
    /* Sengaja boleh_setujui() TANPA $pdo — yaitu menurut PERAN saja.

       Kalau izin 'setujui' yang diberikan per akun ikut dihitung di sini,
       ia akan mengembalikan '1=1' dan membuat akun itu melihat SELURUH
       dokumen lembaga. Itu memperluas LINGKUP, bukan wewenang — dan janji
       yang tertulis di katalog_izin() maupun di layar Kelola Pengguna
       adalah bahwa izin tidak pernah memperluas lingkup.

       Jadi izin 'setujui' menjawab "boleh menekan tombol Setujui", bukan
       "boleh melihat dokumen siapa saja". Yang diberi izin tetap hanya
       melihat dokumen dalam lingkupnya, dan menyetujui yang itu. */
    if (boleh_setujui()) { return '1=1'; }
    $a = $alias !== '' ? $alias . '.' : '';
    if (!is_eksternal() && is_manager($pdo)) {
        return "({$a}jenis = 'penyaluran' AND {$a}asal_tipe = 'supplier')";
    }
    return '1=0';
}

function boleh_setujui_dokumen(PDO $pdo, array $pp): bool {
    if (boleh_setujui($pdo)) { return true; }

    if (($pp['jenis'] ?? '') === 'penyaluran' && ($pp['asal_tipe'] ?? '') === 'supplier') {
        // Mitra tidak boleh menyetujui pengeluarannya sendiri — itu
        // menghapus gunanya persetujuan.
        return !is_eksternal() && is_manager($pdo);
    }
    return false;
}

// =====================================================================
// TINGKAT — staff atau manager di dalam perannya
// ---------------------------------------------------------------------
// Peran menjawab "di bagian mana orang ini bekerja"; tingkat menjawab
// "sampai mana wewenangnya di bagian itu".
//
// Aturan perancangannya SATU dan tidak boleh dilanggar: tingkat hanya
// MEMBATASI, tidak pernah memberi. Seluruh pengguna yang ada dijadikan
// manager oleh migrasi, jadi selama tingkat hanya membatasi, tidak ada
// satu pun akun yang berubah kemampuannya hari ini. Kalau tingkat juga
// dipakai untuk memberi wewenang baru — misalnya membuat manager gudang
// bisa menyetujui dokumen, padahal admin gudang tadinya tidak bisa —
// maka satu migrasi diam-diam melebarkan izin di seluruh sistem, dan itu
// bukan sesuatu yang boleh terjadi tanpa diputuskan secara sadar.
//
// Penurunan staff/manager karena itu dilakukan manual lewat Kelola
// Pengguna, dan pemberian wewenang baru tetap lewat peran.
// =====================================================================

/**
 * Tingkat akun yang sedang login: 'staff' atau 'manager'.
 *
 * Dibaca dari basis data, bukan dari sesi — mengikuti alasan yang sama
 * dengan gudang_saya_id(). $_SESSION['role'] tidak pernah disegarkan di
 * aplikasi ini, jadi kalau tingkat pun disimpan di sesi, menurunkan
 * seseorang dari manager ke staff tidak berlaku sampai ia logout. Untuk
 * wewenang, jendela seperti itu terlalu mahal; satu query kecil yang
 * dihafal per permintaan jauh lebih murah.
 */
function tingkat_saat_ini(PDO $pdo): string {
    static $hafal = false, $nilai = 'staff';
    if ($hafal) { return $nilai; }
    $hafal = true;

    // Peran pengawas lembaga tidak punya tingkat. Dikembalikan sebagai
    // manager supaya pemeriksaan di bawah tidak perlu mengecualikannya
    // satu per satu.
    if (is_super() || role_saat_ini() === 'pimpinan') { return $nilai = 'manager'; }

    $uid = (int) ($_SESSION['user']['id'] ?? 0);
    if ($uid <= 0) { return $nilai = 'staff'; }

    try {
        $st = $pdo->prepare("SELECT tingkat FROM users WHERE id = ? AND deleted_at IS NULL");
        $st->execute([$uid]);
        $t = $st->fetchColumn();
        return $nilai = ($t === 'manager' ? 'manager' : 'staff');
    } catch (Throwable $e) {
        error_log('[AMANAH] tingkat_saat_ini: ' . $e->getMessage());
        // Gagal membaca berarti wewenangnya TIDAK diketahui. Jatuh ke
        // wewenang paling kecil: layar yang terlalu sedikit bisa dibuka
        // lagi, tindakan yang terlalu banyak tidak bisa ditarik kembali.
        return $nilai = 'staff';
    }
}

function is_manager(PDO $pdo): bool { return tingkat_saat_ini($pdo) === 'manager'; }
function is_staff(PDO $pdo): bool   { return tingkat_saat_ini($pdo) === 'staff'; }

function label_tingkat(string $tingkat): array {
    return $tingkat === 'manager'
        ? ['Manager', 't-purple']
        : ['Staff',   't-blue'];
}

/* =====================================================================
   IZIN PER PENGGUNA
   =====================================================================
   Sampai sini seluruh wewenang dihitung dari dua hal saja: PERAN dan
   TINGKAT. Itu cukup selama setiap orang berperan sama persis, tetapi di
   lapangan tidak begitu — ada staff gudang yang dipercaya membuka laporan
   tanpa perlu dijadikan manager, dan ada manager yang justru tidak boleh
   menghapus apa pun.

   Lapisan ini menambahkan penyimpangan PER AKUN di atas hitungan itu.
   Bentuknya tiga keadaan, bukan dua:

     tidak ada baris  ikut peran & tingkat  (bawaan)
     nilai = 1        diizinkan             (menyimpang, menambah)
     nilai = 0        ditolak               (menyimpang, mengurangi)

   ---------------------------------------------------------------------
   BERLAKU APA ADANYA, UNTUK SEMUA PERAN DAN SEMUA TINGKAT
   ---------------------------------------------------------------------
   Diizinkan berarti diizinkan, ditolak berarti ditolak. Tidak ada pagar
   peran di antaranya — itu keputusan pemilik sistem, dan alasannya
   dicatat lengkap di katalog_izin() beserta akibat yang perlu diketahui.

   Super Admin ikut terpengaruh. Aman, karena tidak ada satu pun izin di
   katalog yang menjaga pintu masuk ke pengguna.php: halaman itu dijaga
   PERAN (is_super), bukan izin. Jadi setiap kekeliruan di sini masih bisa
   dibatalkan dari halaman yang sama.

   Yang TIDAK diubah lapisan ini: LINGKUP. Memberi izin tidak pernah
   memperluas gudang, rak, atau toko yang terlihat — itu tetap dihitung
   lingkup_stok() dari perannya. Jadi staff gudang yang diberi izin
   laporan tetap hanya melihat laporan gudangnya sendiri, bukan lembaga;
   dan mitra yang diberi kelola_master tetap hanya melihat stoknya sendiri
   meski bisa mengubah katalog.
   ===================================================================== */

/**
 * Daftar wewenang yang boleh disimpangkan per akun.
 *
 * Sengaja daftar TERTUTUP, bukan kunci bebas. Kunci bebas berarti salah
 * ketik di formulir menghasilkan izin yang tidak pernah diperiksa siapa
 * pun — tersimpan rapi di basis data, terlihat aktif di layar, dan tidak
 * berpengaruh apa-apa.
 *
 * Bentuk tiap baris: [label, keterangan].
 *
 * ---------------------------------------------------------------------
 * TIDAK ADA PAGAR PERAN — INI KEPUTUSAN PEMILIK SISTEM
 * ---------------------------------------------------------------------
 * Versi pertama membatasi wewenang mana yang boleh DIBERIKAN ke peran
 * mana. Batas itu dicabut atas permintaan pemilik sistem: setiap wewenang
 * kini bisa diberikan ke peran apa pun dan tingkat apa pun, dan yang
 * memutuskan adalah orang, bukan kode.
 *
 * Yang perlu diketahui siapa pun yang membaca ini kelak:
 *
 *   - Memberi 'kelola_master' atau 'hapus' kepada akun MITRA atau TOKO
 *     memberinya kuasa atas katalog SELURUH lembaga, bukan hanya miliknya.
 *   - Memberi 'setujui' membuat satu orang bisa membuat sekaligus
 *     menyetujui perpindahannya sendiri. Satu-satunya penahan yang
 *     tersisa adalah larangan mitra menyetujui pengeluarannya sendiri di
 *     boleh_setujui_dokumen().
 *
 * Yang TETAP tidak berubah, dan ini penting: izin tidak pernah memperluas
 * LINGKUP. Gudang, rak, dan toko yang terlihat tetap dihitung
 * lingkup_stok() dari perannya. Mitra yang diberi 'kelola_master' bisa
 * mengubah master data, tetapi tetap hanya melihat stoknya sendiri.
 */
function katalog_izin(): array {
    return [
        'kelola_master' => [
            'Kelola Master Data',
            'Menambah & mengubah barang, kategori, rak, gudang, supplier, toko',
        ],
        'hapus' => [
            'Hapus Data',
            'Menghapus data master. Penghapusan tidak bisa dibatalkan sendiri',
        ],
        'lihat_nilai' => [
            'Lihat Nilai Rupiah',
            'Nilai persediaan, total belanja, dan kolom rupiah di laporan',
        ],
        'lihat_laporan' => [
            'Buka & Ekspor Laporan',
            'Halaman Laporan Stok beserta ekspornya',
        ],
        'kontak_donatur' => [
            'Lihat Kontak Donatur',
            'Nomor telepon, email, dan alamat donatur — data pribadi',
        ],
        'masuk_gudang_toko' => [
            'Catat Barang Masuk Toko',
            'Menambah stok ke gudang toko tanpa dokumen dari pusat',
        ],
        'setujui' => [
            'Setujui Dokumen',
            'Menyetujui perpindahan sehingga stok benar-benar berpindah',
        ],
    ];
}

/**
 * Penyimpangan izin milik akun yang sedang masuk.
 *
 * Dibaca sekali per permintaan. Peran pengawas lembaga dilewati sama
 * sekali: super admin yang bisa mencabut izinnya sendiri akan mengunci
 * dirinya keluar dari halaman yang mengembalikannya.
 */
function izin_saya(PDO $pdo): array {
    static $hafal = null;
    if ($hafal !== null) { return $hafal; }

    /* Super Admin TIDAK lagi dikecualikan — "semua peran" berarti semua.

       Aman karena tidak ada satu pun izin di katalog yang menjaga pintu
       masuk ke pengguna.php: halaman itu dijaga is_super(), yaitu PERAN.
       Jadi Super Admin yang mencabut wewenangnya sendiri tetap bisa
       membuka Kelola Pengguna dan mengembalikannya. Setiap kekeliruan di
       sini bisa dibatalkan. */
    $uid = (int) ($_SESSION['user']['id'] ?? 0);
    if ($uid <= 0) { return $hafal = []; }

    try {
        $st = $pdo->prepare("SELECT kunci, nilai FROM izin_pengguna WHERE user_id = ?");
        $st->execute([$uid]);
        $peta = [];
        foreach ($st->fetchAll() as $r) { $peta[$r['kunci']] = ((int) $r['nilai']) === 1; }
        return $hafal = $peta;
    } catch (Throwable $e) {
        /* Tabelnya belum ada (hosting belum dimigrasi) atau basis data
           sedang bermasalah. Jatuh ke BAWAAN, bukan ke menolak semuanya:
           tanpa penyimpangan, sistem berperilaku persis seperti sebelum
           fitur ini ada — dan itu keadaan yang sudah teruji. */
        error_log('[AMANAH] izin_saya: ' . $e->getMessage());
        return $hafal = [];
    }
}

/**
 * Wewenang akhir: bawaan dari peran & tingkat, disimpangkan per akun.
 *
 * Penyimpangan berlaku APA ADANYA — diizinkan berarti diizinkan, ditolak
 * berarti ditolak, untuk peran dan tingkat apa pun. Tidak ada penyaring
 * lain di antaranya; lihat alasannya di katalog_izin().
 *
 * Satu-satunya penjagaan yang tersisa: kunci di luar katalog diabaikan.
 * Kunci asing tidak diperiksa fungsi mana pun, jadi menghormatinya hanya
 * akan menghasilkan izin yang terlihat aktif tapi tidak berarti apa-apa.
 *
 * @param bool $bawaan hasil hitungan peran & tingkat, seperti sebelumnya.
 */
function izin(PDO $pdo, string $kunci, bool $bawaan): bool {
    $peta = izin_saya($pdo);
    if (!array_key_exists($kunci, $peta)) { return $bawaan; }
    if (!isset(katalog_izin()[$kunci]))   { return $bawaan; }
    return $peta[$kunci];
}

/**
 * Menghapus data. Staff mencatat, manager yang membereskan.
 *
 * Penghapusan hampir selalu tidak bisa dibatalkan oleh yang melakukannya,
 * dan di gudang kesalahan input jauh lebih sering daripada kesalahan yang
 * benar-benar perlu dihapus — yang salah input diperbaiki, bukan dibuang.
 */
function boleh_hapus(PDO $pdo): bool {
    return izin($pdo, 'hapus', boleh_edit() && is_manager($pdo));
}

/** Mengubah master data (barang, kategori, rak, gudang, toko, penerima). */
function boleh_kelola_master(PDO $pdo): bool {
    return izin($pdo, 'kelola_master', boleh_edit() && is_manager($pdo));
}

/**
 * Melihat angka rupiah: nilai persediaan, total, dan laporan keuangan.
 *
 * Staff tetap bisa mengisi harga di formulir pemasukan — tanpa itu ia
 * tidak bisa menyelesaikan pekerjaannya. Yang ditahan adalah angka
 * agregat: nilai persediaan, total belanja, dan laporan.
 */
function boleh_lihat_nilai(PDO $pdo): bool {
    return izin($pdo, 'lihat_nilai', is_manager($pdo));
}

/**
 * Membuka halaman laporan dan mengekspornya.
 *
 * Bawaannya TERBUKA untuk semua peran — bukan hanya manager. Laporan Stok
 * memang sengaja dibuka ke tiap peran dengan lingkupnya masing-masing
 * (lihat laporan.php), dan angka rupiahnya sudah dijaga terpisah oleh
 * boleh_lihat_nilai(). Izin ini gunanya MENCABUT: akun tertentu yang tidak
 * perlu melihat laporan bisa ditutup dari Kelola Pengguna, dan penutupan
 * itu kini benar-benar ditegakkan oleh laporan.php & laporan_export.php.
 */
function boleh_lihat_laporan(PDO $pdo): bool {
    return izin($pdo, 'lihat_laporan', true);
}

/**
 * Melihat kontak donatur (nomor telepon, email, alamat).
 *
 * Data pribadi orang yang memberi titipan. Yang berkepentingan: pengawas
 * lembaga, mitra yang mendaftarkan donaturnya sendiri, dan manager gudang
 * yang perlu menghubungi. Staff melihat namanya saja — cukup untuk
 * mencatat, tanpa perlu memegang nomor telepon orang.
 */
function boleh_lihat_kontak_donatur(PDO $pdo): bool {
    $bawaan = is_super() || role_saat_ini() === 'pimpinan' || is_supplier()
           || is_manager($pdo);
    return izin($pdo, 'kontak_donatur', $bawaan);
}

/** Samarkan nomor telepon, sisakan cukup untuk dikenali pemiliknya. */
function samarkan_telepon(?string $telepon): string {
    $t = preg_replace('/\D+/', '', (string) $telepon);
    if ($t === '' || $t === null) { return '—'; }
    if (strlen($t) <= 4) { return str_repeat('•', strlen($t)); }
    return substr($t, 0, 4) . str_repeat('•', max(0, strlen($t) - 6)) . substr($t, -2);
}

/**
 * Nama donatur untuk ditampilkan.
 *
 * Dipakai di DAFTAR, LAPORAN, CETAKAN, dan EKSPOR — tempat banyak baris
 * terlihat sekaligus dan satu tangkapan layar bisa membocorkan semuanya.
 * Di situ donatur yang minta anonim tampil sebagai "Hamba Allah" beserta
 * wilayahnya.
 *
 * SENGAJA TIDAK dipakai di dua tempat: pemilih donatur saat mencatat
 * barang masuk, dan formulir ubah datanya. Petugas yang sedang menerima
 * titipan harus bisa menunjuk orang yang benar; kalau lima donatur anonim
 * dari kota yang sama semuanya tertulis "Hamba Allah", yang terjadi bukan
 * privasi yang lebih baik melainkan titipan yang tercatat ke donatur yang
 * salah. Anonim di sini berarti "jangan disebarkan", bukan "sembunyikan
 * dari petugas yang sedang melayaninya".
 */
/**
 * Bentuk pemberian — berbeda dari cara_terima yang menjawab bagaimana
 * barangnya sampai. Donasi paket bisa saja diantar sendiri, dan donasi
 * kunjungan bisa saja dikirim ekspedisi menyusul.
 */
const JENIS_DONASI = [
    'umum'      => ['Donasi barang',       't-gray',  'Pemberian barang biasa'],
    'kunjungan' => ['Donasi kunjungan',    't-green', 'Donatur datang berkunjung sambil membawa barang'],
    'paket'     => ['Donasi paket',        't-blue',  'Sudah berbentuk paket siap salur, tidak dipecah di gudang'],
];

function label_jenis_donasi(?string $j): array {
    $d = JENIS_DONASI[$j ?? 'umum'] ?? JENIS_DONASI['umum'];
    return [$d[0], $d[1]];
}

/**
 * CARA barang donasi sampai ke gudang — sumbu yang BERBEDA dari JENIS_DONASI.
 *
 * ---------------------------------------------------------------------------
 * DUA SUMBU YANG MUDAH TERTUKAR
 * ---------------------------------------------------------------------------
 *   JENIS_DONASI  = BENTUK pemberiannya (barang biasa / paket siap salur)
 *   CARA_TERIMA   = BAGAIMANA ia sampai (diantar / dijemput / dikirim)
 *
 * Keduanya bisa bersilangan bebas: paket siap salur bisa diantar langsung,
 * dan barang biasa bisa datang lewat ekspedisi. Digabung jadi satu kolom,
 * salah satu pertanyaan selalu kehilangan jawabannya.
 *
 * Yayasan wajib melaporkan donasi kunjungan terpisah dari donasi paket —
 * yang pertama punya tatap muka dan tanda terima langsung, yang kedua hanya
 * punya nomor resi sebagai bukti. Sumbu inilah yang menjawabnya.
 *
 * `butuh_resi` menandai cara yang bukti kirimnya berupa resi. Dipakai
 * formulir untuk memunculkan isian ekspedisi & nomor resi, dan dipakai
 * laporan untuk menandai paket yang resinya belum ditulis.
 */
const CARA_TERIMA_DONASI = [
    'diantar'  => ['Diantar donatur', 't-green', 'Donatur datang sendiri ke gudang — donasi kunjungan', false],
    'dijemput' => ['Dijemput petugas', 't-amber', 'Petugas yang mengambil ke tempat donatur', false],
    'dikirim'  => ['Dikirim ekspedisi', 't-blue', 'Lewat JNE/J&T/GoSend/Grab dan sejenisnya — ada nomor resi', true],
];

function label_cara_terima(?string $c): array {
    $d = CARA_TERIMA_DONASI[$c ?? 'diantar'] ?? CARA_TERIMA_DONASI['diantar'];
    return [$d[0], $d[1]];
}

/** Nilai cara terima yang sah; selain itu jatuh ke 'diantar'. */
function cara_terima_sah(?string $c): string {
    return array_key_exists((string) $c, CARA_TERIMA_DONASI) ? (string) $c : 'diantar';
}

/** Apakah cara terima ini semestinya punya nomor resi. */
function cara_terima_butuh_resi(?string $c): bool {
    return (bool) (CARA_TERIMA_DONASI[cara_terima_sah($c)][3] ?? false);
}

/**
 * Saran nama ekspedisi — SARAN, bukan daftar tertutup.
 *
 * Isian ekspedisi tetap kotak teks bebas dengan <datalist>, bukan <select>.
 * Alasannya terlihat di data sungguhan: ada donasi yang datang lewat "RANS",
 * kurir yang tidak akan pernah ada di daftar mana pun yang kami susun. Kurir
 * daerah, ojek titipan, dan rekan yang kebetulan lewat adalah kenyataan
 * sehari-hari — daftar tertutup akan memaksa semuanya jadi "lain-lain" dan
 * justru menghapus keterangan yang sedang dicari.
 *
 * Daftar ini hanya mempercepat yang sering dipakai dan menyeragamkan
 * ejaannya: "J&T" yang diketik tangan bisa jadi "JnT", "J & T", atau "jnt",
 * dan ketiganya terhitung sebagai tiga ekspedisi berbeda di laporan.
 *
 * Penyaring di Laporan Donasi TIDAK dibangun dari daftar ini melainkan dari
 * isi datanya sendiri — supaya "RANS" pun tetap bisa disaring.
 */
const EKSPEDISI_SARAN = [
    // Ekspedisi reguler
    'JNE', 'J&T Express', 'SiCepat', 'Anteraja', 'Ninja Xpress',
    'POS Indonesia', 'TIKI', 'Wahana', 'Lion Parcel', 'ID Express',
    'SAP Express', 'Indah Cargo', 'Dakota Cargo',
    // Kurir instan / dalam kota
    'GoSend', 'Grab Express', 'Lalamove', 'Deliveree', 'Paxel',
    'Maxim', 'ShopeeFood Kirim',
    // Jalur lain yang sering dipakai donatur
    'Travel / Titipan Bus', 'Kurir Pribadi', 'Ojek Online', 'Diambil Rekan',
];

/** Nilai jenis donasi yang sah; selain itu jatuh ke 'umum'. */
function jenis_donasi_sah(?string $j): string {
    return array_key_exists((string) $j, JENIS_DONASI) ? (string) $j : 'umum';
}

/* ---------------------------------------------------------------------------
   JENIS DONASI DI BARANG MASUK — revisi tim gudang, Oktober 2026 (butir 1)

   Untuk penerimaan lewat Barang Masuk hanya ada dua pilihan, dan WAJIB
   dipilih bila asal barangnya donasi: kunjungan atau paket. Yayasan
   melaporkan keduanya terpisah (Laporan Harian Donasi).

   Nilai 'umum' tetap sah di tabel `donasi` — itulah isi seluruh data lama,
   dan masih dipakai jalur lain (dasbor mitra, aplikasi Android versi lama).
   Untuk penerimaan ia DITAMPILKAN sebagai "Belum diklasifikasi": data lama
   tidak diisi otomatis, menunggu keputusan pemilik sistem.
   --------------------------------------------------------------------------- */
const JENIS_DONASI_MASUK = [
    'kunjungan' => 'Donasi Kunjungan',
    'paket'     => 'Donasi Paket',
];

/** Label & warna jenis donasi sebuah penerimaan. Selain kunjungan/paket = belum diklasifikasi. */
function label_jenis_donasi_masuk(?string $jenis): array {
    if ($jenis === 'kunjungan') { return ['Donasi Kunjungan', 't-green']; }
    if ($jenis === 'paket')     { return ['Donasi Paket', 't-blue']; }
    return ['Belum diklasifikasi', 't-gray'];
}

/**
 * Apakah sebuah kolom sudah ada di basis data ini.
 *
 * Kode baru boleh terunggah SEBELUM migrasinya dijalankan di server (unggah
 * lewat FTP dan migrasi adalah dua langkah terpisah). Bagian yang membaca
 * kolom baru memeriksa ini dulu, supaya halamannya tetap jalan — hanya fitur
 * barunya yang belum aktif — alih-alih tumbang dengan galat SQL.
 */
function kolom_ada(PDO $pdo, string $tabel, string $kolom): bool {
    static $hafal = [];
    $k = "$tabel.$kolom";
    if (!array_key_exists($k, $hafal)) {
        try {
            $st = $pdo->prepare(
                "SELECT 1 FROM information_schema.columns
                  WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?");
            $st->execute([$tabel, $kolom]);
            $hafal[$k] = (bool) $st->fetchColumn();
        } catch (Throwable $e) { $hafal[$k] = false; }
    }
    return $hafal[$k];
}

/**
 * Jenis donatur — label yang terbaca manusia.
 *
 * Dulu ketiganya ditulis tangan di <select> masuk.php dan tidak punya
 * padanan di tempat lain, sehingga laporan yang ingin menyebutnya harus
 * menuliskannya ulang. Dua daftar untuk hal yang sama cepat atau lambat
 * berselisih — dan yang berselisih di laporan adalah yang dibaca orang
 * luar.
 */
const JENIS_DONATUR = [
    'pribadi'   => 'Perorangan',
    'komunitas' => 'Komunitas',
    'lembaga'   => 'Lembaga',
];

function nama_donatur_tampil(array $d): string {
    if (empty($d['anonim'])) { return (string) ($d['nama'] ?? '—'); }
    $wil = trim((string) ($d['wilayah'] ?? ''));
    return 'Hamba Allah' . ($wil !== '' ? ' · ' . $wil : '');
}

// =====================================================================
// PENCOCOKAN DONATUR
// ---------------------------------------------------------------------
// Donatur TIDAK dipilih dari daftar. Orang datang membawa titipan, dan
// petugas mengetik namanya — memaksa memilih dari dropdown berarti setiap
// pemberi harus didaftarkan lebih dulu di layar lain, padahal yang terjadi
// justru sebaliknya: barangnya sudah di meja.
//
// Kuncinya NAMA + NOMOR HP. Keduanya sama berarti orang yang sama, dan
// kodenya dipakai ulang. Salah satu berbeda berarti donatur baru.
//
// Pencocokan dilakukan di PHP, bukan lewat indeks unik di basis data.
// Alasannya: nilai yang dibandingkan adalah nilai yang SUDAH dinormalkan,
// dan menormalkannya di SQL butuh REGEXP_REPLACE yang tidak ada di MySQL
// 5.7 — versi yang masih mungkin dipakai hosting. Kolom bayangan plus
// indeks unik sempat dipertimbangkan, tapi pengisian awalnya harus persis
// sama dengan normalisasi PHP; kalau meleset sedikit saja, donatur lama
// dianggap baru pada penyimpanan pertama. Jumlah donatur di sini ratusan,
// bukan jutaan, jadi membandingkan di PHP jauh lebih murah daripada risiko
// itu.
// =====================================================================

/**
 * Nomor HP dinormalkan: hanya angka, awalan 62 dan +62 menjadi 0.
 *
 * Tanpa ini "0812-3456-7890", "081234567890", dan "+6281234567890" adalah
 * tiga orang berbeda menurut sistem — padahal ketiganya nomor yang sama.
 */
function kunci_telepon(?string $telepon): string {
    $t = preg_replace('/\D+/', '', (string) $telepon);
    if ($t === '') { return ''; }
    if (strpos($t, '62') === 0)  { $t = '0' . substr($t, 2); }
    elseif ($t[0] !== '0')       { $t = '0' . $t; }
    return $t;
}

/** Nama dinormalkan: huruf kecil, spasi berlebih dirapatkan, dipangkas. */
function kunci_nama(?string $nama): string {
    $n = preg_replace('/\s+/u', ' ', trim((string) $nama));
    return function_exists('mb_strtolower') ? mb_strtolower($n, 'UTF-8') : strtolower($n);
}

/**
 * Daftar donatur yang boleh dilihat pihak ini — sekaligus daftar yang
 * dipakai untuk mencocokkan.
 *
 * Mitra hanya mencocokkan ke donaturnya sendiri dan yang tak terikat mitra.
 * Kalau ia ikut mencocokkan ke donatur mitra lain, ia bisa menyimpulkan
 * nomor HP siapa saja yang ada di daftar mitra itu hanya dengan menebak —
 * dan itu kebocoran data pribadi lewat pintu belakang.
 */
function donatur_terlihat(PDO $pdo, ?int $supplierId): array {
    $sql = "SELECT id, kode, nama, telepon, jenis, wilayah, anonim
            FROM donatur WHERE deleted_at IS NULL";
    $par = [];
    if ($supplierId !== null) {
        $sql .= " AND (supplier_id = ? OR supplier_id IS NULL)";
        $par[] = $supplierId;
    }
    $st = $pdo->prepare($sql . " ORDER BY nama");
    $st->execute($par);
    return $st->fetchAll();
}

/**
 * Temukan donatur yang cocok, atau buat baru.
 *
 * Mengembalikan:
 *   id       - id donatur yang dipakai
 *   baru     - true bila baru saja dibuat
 *   kode     - kode donaturnya
 *   bentrok  - baris donatur lain yang NOMORNYA sama tapi namanya berbeda,
 *              atau null. Ini bukan galat: menurut aturannya memang donatur
 *              baru. Tapi hampir selalu ia sebenarnya orang yang sama dengan
 *              nama yang tertulis berbeda, jadi pemanggil WAJIB
 *              memberitahukannya — membuat donatur kembar diam-diam persis
 *              merusak angka "sudah berapa kali memberi" yang jadi alasan
 *              fitur ini dibuat.
 */
function donatur_cocok_atau_buat(PDO $pdo, array $d, ?int $supplierId): array {
    $nama    = trim((string) ($d['nama'] ?? ''));
    $telepon = trim((string) ($d['telepon'] ?? ''));
    $kn = kunci_nama($nama);
    $kt = kunci_telepon($telepon);

    $bentrok = null;
    foreach (donatur_terlihat($pdo, $supplierId) as $ada) {
        $samaNama = kunci_nama($ada['nama']) === $kn;
        $samaHp   = kunci_telepon($ada['telepon']) === $kt;
        if ($samaNama && $samaHp) {
            return ['id' => (int) $ada['id'], 'baru' => false,
                    'kode' => $ada['kode'], 'bentrok' => null];
        }
        // Nomor sama, nama beda — dicatat, tapi tidak menghentikan apa pun.
        if ($samaHp && $kt !== '' && $bentrok === null) { $bentrok = $ada; }
    }

    $jenis = in_array($d['jenis'] ?? '', ['pribadi', 'komunitas', 'lembaga'], true)
           ? $d['jenis'] : 'pribadi';
    $kode  = kode_berikut($pdo, 'donatur', 'DN');

    $pdo->prepare(
        "INSERT INTO donatur (kode, nama, jenis, supplier_id, wilayah, telepon, anonim, is_active)
         VALUES (?,?,?,?,?,?,?,1)"
    )->execute([
        $kode, $nama, $jenis, $supplierId,
        trim((string) ($d['wilayah'] ?? '')) ?: null,
        $telepon ?: null,
        !empty($d['anonim']) ? 1 : 0,
    ]);

    return ['id' => (int) $pdo->lastInsertId(), 'baru' => true,
            'kode' => $kode, 'bentrok' => $bentrok];
}

function is_supplier(): bool {
    return role_saat_ini() === 'supplier';
}

/** ID supplier milik user yang sedang login (null bila bukan supplier). */
function supplier_saya(): ?int {
    $s = $_SESSION['user']['supplier_id'] ?? null;
    return $s ? (int) $s : null;
}

// =====================================================================
// LINGKUP GUDANG — Admin Gudang hanya melihat gudangnya sendiri
// ---------------------------------------------------------------------
// Mengikuti pola akun supplier: satu akun menunjuk satu gudang, dan
// seluruh halaman menyaring lewat gudang_saya() alih-alih memeriksa
// perannya sendiri-sendiri. Satu sumber kebenaran, jadi menambah halaman
// baru tidak berarti mengingat aturannya lagi.
//
// Super Admin & Pimpinan mendapat SELURUH gudang — mereka memang
// mengawasi keseluruhan. Supplier & toko tidak berurusan dengan gudang
// sama sekali; mereka sudah dihalau lebih dulu oleh tolak_supplier().
// =====================================================================
function is_admin_gudang(): bool {
    return role_saat_ini() === 'admin_gudang';
}

/**
 * Gudang yang dipegang akun ini (null bila bukan Admin Gudang).
 *
 * Dibaca dari basis data, bukan dari sesi. Nilai di sesi hanya benar sampai
 * Super Admin mengubah penugasannya: kalau penugasan DICABUT, akun itu masih
 * memegang akses ke gudang lamanya sampai ia logout — dan tidak ada yang
 * memberitahu siapa pun. Satu query kecil per permintaan (dihafal di bawah)
 * jauh lebih murah daripada jendela akses yang menganga tanpa jejak.
 */
function gudang_saya_id(PDO $pdo): ?int {
    static $hafal = false, $nilai = null;
    if ($hafal) { return $nilai; }
    $hafal = true;

    $uid = (int) ($_SESSION['user']['id'] ?? 0);
    if ($uid <= 0) { return $nilai = null; }

    try {
        $st = $pdo->prepare("SELECT gudang_id FROM users WHERE id = ? AND deleted_at IS NULL");
        $st->execute([$uid]);
        $g = $st->fetchColumn();
        // Sesi disamakan supaya tampilan lain yang membacanya tidak ikut basi.
        $_SESSION['user']['gudang_id'] = $g !== false && $g !== null ? (int) $g : null;
        return $nilai = $_SESSION['user']['gudang_id'];
    } catch (Throwable $e) {
        error_log('[AMANAH] gudang_saya_id: ' . $e->getMessage());
        // Gagal membaca berarti lingkupnya TIDAK diketahui. Menganggapnya
        // "punya akses" akan salah arah; kosong hanya menampilkan halaman
        // kosong, dan itu bisa diperbaiki tanpa kerugian.
        $g = $_SESSION['user']['gudang_id'] ?? null;
        return $nilai = $g ? (int) $g : null;
    }
}

/**
 * Daftar id gudang dalam lingkup akun ini.
 *
 * Admin Gudang: hanya gudangnya. Peran internal lain: semuanya.
 * Array kosong berarti Admin Gudang belum ditugaskan ke gudang mana pun —
 * halaman pemanggil harus menanganinya, bukan menampilkan data kosong
 * seolah gudangnya memang tidak berisi apa-apa.
 */
function gudang_saya(PDO $pdo): array {
    static $cache = null;
    if ($cache !== null) { return $cache; }

    /* Cabang di bawah mengembalikan SELURUH gudang. Peran apa pun yang
       seharusnya terikat satu gudang tapi tidak disebut di sini akan
       jatuh ke sana dan diam-diam mendapat akses ke semua gudang — jadi
       yang diperiksa terikat_satu_gudang(), bukan satu nama peran. */
    if (terikat_satu_gudang()) {
        /* ------------------------------------------------------------------
           SATU AKUN BISA MEMEGANG BANYAK GUDANG

           Sumber utamanya tabel `user_gudang` — relasi banyak-ke-banyak yang
           sudah ada di basis data sejak awal, tetapi belum pernah dipakai
           sebagai sumber kebenaran: isinya dijaga sebagai cerminan
           users.gudang_id, dan lingkup akun tetap dihitung dari satu kolom itu.

           Sekarang dibalik. users.gudang_id tinggal menjadi gudang UTAMA —
           dipakai tempat yang memang butuh satu jawaban tunggal (mis. rak
           bawaan) — sementara lingkupnya adalah seluruh baris di user_gudang.

           Kalau tabelnya kosong untuk akun ini, jawabannya JATUH KE
           users.gudang_id. Itu bukan kerapian: akun yang dibuat sebelum
           layar penugasan banyak-gudang ada hanya punya kolom itu, dan
           mengembalikan array kosong akan membuat mereka kehilangan seluruh
           aksesnya begitu berkas ini naik ke hosting.
           ------------------------------------------------------------------ */
        $uid = (int) ($_SESSION['user']['id'] ?? 0);
        if ($uid > 0) {
            try {
                $st = $pdo->prepare(
                    "SELECT ug.gudang_id
                       FROM user_gudang ug
                       JOIN gudang g ON g.id = ug.gudang_id
                      WHERE ug.user_id = ? AND g.deleted_at IS NULL
                      ORDER BY g.kode");
                $st->execute([$uid]);
                $banyak = array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
                if ($banyak) { return $cache = $banyak; }
            } catch (Throwable $e) {
                /* Tabelnya belum ada (migrasi belum jalan) atau gagal dibaca.
                   Jatuh ke gudang utama — perilaku lama — bukan menganggap
                   akun ini tidak punya gudang. */
                error_log('[AMANAH] gudang_saya (user_gudang): ' . $e->getMessage());
            }
        }

        $id = gudang_saya_id($pdo);
        $cache = $id ? [$id] : [];
        return $cache;
    }
    try {
        $cache = array_map('intval', $pdo->query(
            "SELECT id FROM gudang WHERE deleted_at IS NULL ORDER BY kode"
        )->fetchAll(PDO::FETCH_COLUMN));
    } catch (Throwable $e) {
        error_log('[AMANAH] gudang_saya: ' . $e->getMessage());
        $cache = [];
    }
    return $cache;
}

/** Id rak yang berada di dalam gudang lingkup akun ini. */
function rak_saya(PDO $pdo): array {
    static $cache = null;
    if ($cache !== null) { return $cache; }

    $gd = gudang_saya($pdo);
    if (!$gd) { $cache = []; return $cache; }
    try {
        $cache = array_map('intval', $pdo->query(
            "SELECT id FROM lokasi_rak
             WHERE gudang_id IN (" . implode(',', array_map('intval', $gd)) . ")
               AND deleted_at IS NULL ORDER BY kode"
        )->fetchAll(PDO::FETCH_COLUMN));
    } catch (Throwable $e) {
        error_log('[AMANAH] rak_saya: ' . $e->getMessage());
        $cache = [];
    }
    return $cache;
}

/**
 * Potongan SQL `IN (...)` untuk lingkup gudang.
 *
 * Mengembalikan '0' bila lingkupnya kosong — bukan string kosong. `IN ()`
 * adalah galat sintaks MySQL, sementara `IN (0)` benar dan tidak cocok
 * dengan apa pun. Bedanya antara halaman kosong dan halaman galat.
 */
function in_gudang_saya(PDO $pdo): string {
    $g = gudang_saya($pdo);
    return $g ? implode(',', array_map('intval', $g)) : '0';
}

function in_rak_saya(PDO $pdo): string {
    $r = rak_saya($pdo);
    return $r ? implode(',', array_map('intval', $r)) : '0';
}

/** Hentikan bila gudang itu di luar lingkup akun ini. */
function wajib_akses_gudang(PDO $pdo, int $gudangId): void {
    if (!in_array($gudangId, gudang_saya($pdo), true)) {
        http_response_code(403);
        die('<p style="font-family:sans-serif;padding:30px">403 — Gudang itu bukan tanggung jawab '
          . 'akun Anda. <a href="' . beranda_peran() . '">Kembali ke dasbor</a></p>');
    }
}

/** Hentikan bila rak itu berada di gudang di luar lingkup akun ini. */
function wajib_akses_rak(PDO $pdo, int $rakId): void {
    if (!in_array($rakId, rak_saya($pdo), true)) {
        http_response_code(403);
        die('<p style="font-family:sans-serif;padding:30px">403 — Rak itu berada di gudang yang '
          . 'bukan tanggung jawab akun Anda. <a href="' . beranda_peran() . '">Kembali ke dasbor</a></p>');
    }
}

/**
 * Peringatan yang ditampilkan bila Admin Gudang belum punya gudang.
 * Dikembalikan sebagai HTML siap pakai, atau '' bila tidak relevan —
 * supaya tiap halaman cukup mencetaknya tanpa menyusun pesannya sendiri.
 */
function peringatan_tanpa_gudang(PDO $pdo): string {
    if (!terikat_satu_gudang() || gudang_saya($pdo)) { return ''; }
    return '<div class="flash flash-err">Akun Anda belum dikaitkan ke gudang mana pun, '
         . 'jadi belum ada data yang bisa ditampilkan. Minta Super Admin menugaskan gudang '
         . 'lewat <b>Pengaturan &rsaquo; Pengguna</b>.</div>';
}

function is_toko(): bool {
    return role_saat_ini() === 'toko';
}

/** Peran yang punya dasbornya sendiri di luar dasbor gudang. */
function is_eksternal(): bool {
    return is_supplier() || is_toko();
}

/** Halaman awal yang sesuai untuk peran yang sedang login. */
function beranda_peran(): string {
    if (is_supplier()) return 'dasbor_supplier.php';
    if (is_toko())     return 'dasbor_toko.php';
    return 'dashboard.php';
}

/**
 * Daftar ID toko yang boleh diakses akun ini.
 * Satu akun toko boleh memegang beberapa toko sekaligus.
 */
function toko_saya(PDO $pdo): array {
    static $cache = null;
    if ($cache !== null) return $cache;

    if (!is_toko()) {
        // Staf internal boleh melihat semua toko.
        $cache = array_map('intval', $pdo->query(
            "SELECT id FROM toko WHERE deleted_at IS NULL ORDER BY kode"
        )->fetchAll(PDO::FETCH_COLUMN));
        return $cache;
    }

    $st = $pdo->prepare(
        "SELECT t.id FROM user_toko ut
         JOIN toko t ON t.id = ut.toko_id AND t.deleted_at IS NULL AND t.is_active = 1
         WHERE ut.user_id = ? ORDER BY t.kode"
    );
    $st->execute([$_SESSION['user_id'] ?? 0]);
    $cache = array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
    return $cache;
}

/**
 * Toko yang sedang dipilih di pemilih atas layar.
 * Selalu diverifikasi ulang terhadap daftar toko yang boleh diakses —
 * mengganti angka di URL tidak akan membuka toko milik orang lain.
 */
function toko_aktif(PDO $pdo): ?int {
    $boleh = toko_saya($pdo);
    if (!$boleh) return null;

    $minta = (int) ($_GET['toko'] ?? $_POST['toko_id'] ?? $_SESSION['toko_aktif'] ?? 0);
    if ($minta && in_array($minta, $boleh, true)) {
        $_SESSION['toko_aktif'] = $minta;
        return $minta;
    }
    $_SESSION['toko_aktif'] = $boleh[0];
    return $boleh[0];
}

/** Pastikan sebuah toko boleh diakses akun ini; hentikan bila tidak. */
function wajib_akses_toko(PDO $pdo, int $tokoId): void {
    if (!in_array($tokoId, toko_saya($pdo), true)) {
        http_response_code(403);
        die('<p style="font-family:sans-serif;padding:30px">403 — Anda tidak memiliki akses ke toko ini.</p>');
    }
}

function is_super(): bool {
    return role_saat_ini() === 'super_admin';
}

function is_pimpinan(): bool {
    return role_saat_ini() === 'pimpinan';
}

/**
 * Super Admin dan Pimpinan boleh MELIHAT dasbor supplier & toko mana pun.
 *
 * Sengaja hanya melihat, bukan bertindak: mencatat penjualan atau
 * menerbitkan surat jalan atas nama pihak lain akan mengaburkan jejak audit
 * — laporan jadi menunjukkan supplier yang mengirim, padahal sebenarnya
 * Super Admin yang menekan tombolnya.
 */
function boleh_mengawasi(): bool {
    return is_super() || is_pimpinan();
}

/** Sedang membuka dasbor milik pihak lain (mode baca saja)? */
function mode_pengawasan(): bool {
    return boleh_mengawasi() && !is_eksternal();
}

/** Tolak aksi tulis saat sedang mengawasi dasbor milik pihak lain. */
function tolak_aksi_pengawasan(): void {
    if (mode_pengawasan()) {
        http_response_code(403);
        die('<p style="font-family:sans-serif;padding:30px">403 — Anda sedang membuka dasbor pihak lain; mode itu hanya untuk melihat. '
          . 'Tindakan ini harus dilakukan oleh pemilik akunnya sendiri agar jejak auditnya benar. '
          . '<a href="pengawasan.php">Kembali</a></p>');
    }
}

/**
 * Struktur navigasi peran INTERNAL — sumber tunggal untuk sidebar DAN
 * deretan tab di dalam halaman.
 *
 * Sidebar dulu memuat 21 tautan tanpa lipatan, sehingga menu yang dipakai
 * tiap hari (Penerimaan, Persetujuan) berdesakan dengan data yang diubah
 * setahun sekali. Sekarang tiap grup jadi SATU baris sidebar; anggotanya
 * muncul sebagai tab di dalam halaman. Tidak ada halaman yang dihapus —
 * semua tautan lama tetap hidup, hanya letaknya yang berpindah.
 *
 * Bentuk tiap grup:
 *   k        kunci sorotan sidebar
 *   i,t      ikon & label
 *   h        tujuan sidebar (bila kosong: berkas anggota pertama)
 *   badge    angka merah di sidebar
 *   anggota  [{f: berkas, t: label tab, badge?}] — tab muncul bila >1
 *   terkait  berkas yang ikut grup ini tapi bukan tab (form, cetakan)
 */
function grup_navigasi(string $role, array $badge = [], ?PDO $pdo = null): array {
    $bPersetujuan = $badge['persetujuan'] ?? 0;
    $bTerima      = $badge['terima']      ?? 0;
    $bKiriman     = $badge['kiriman']     ?? 0;

    $super    = $role === 'super_admin';
    $pimpinan = $role === 'pimpinan';
    $terpadu  = $role === 'admin_terpadu';
    // Admin Gudang: menunya hanya memuat pekerjaan di dalam gudangnya.
    // Keduanya memegang satu gudang saja, jadi menunya sama-sama menyempit
    // ke pekerjaan di dalam gudang itu.
    $gudangSaja = $role === 'admin_gudang' || $terpadu;

    $grup = [];

    // ----------------------------------------------------------- DASBOR
    // Grup ini adalah jeda yang disengaja: dari Dashboard tidak langsung
    // melompat ke Transaksi. Isinya hal yang sifatnya MELIHAT keadaan,
    // sementara Transaksi berisi hal yang MENGUBAH keadaan.
    $grup[] = ['sec' => 'Dasbor', 'k' => 'dashboard', 'i' => 'i-grafik-bar', 't' => 'Ringkasan',
               'h' => 'dashboard.php', 'anggota' => []];

    if ($super || $pimpinan) {
        // Dulu bernama "Semua Dasbor" — menyesatkan, karena terbaca seolah
        // kumpulan dashboard pribadi. Isinya sebenarnya pemantauan seluruh
        // rantai (supplier → gudang → toko) dan sifatnya hanya melihat.
        // Ikonnya diganti dari mata ke penanda lokasi. Ikon mata ikut
        // menyumbang kesan "mengintai" yang justru ingin dihilangkan —
        // padahal isinya daftar mitra & tempat, bukan pemantauan orang.
        $grup[] = ['sec' => 'Dasbor', 'k' => 'pengawasan', 'i' => 'i-lokasi', 't' => 'Mitra & Lokasi',
                   'h' => 'pengawasan.php', 'anggota' => []];
    }

    // -------------------------------------------------------- TRANSAKSI
    if (!$pimpinan) {
        $grup[] = ['sec' => 'Transaksi', 'k' => 'masuk', 'i' => 'i-masuk', 't' => 'Barang Masuk',
                   'h' => 'masuk.php', 'anggota' => []];
        $grup[] = ['sec' => 'Transaksi', 'k' => 'keluar', 'i' => 'i-keluar', 't' => 'Barang Keluar',
                   'h' => 'keluar.php', 'anggota' => []];
    }

    /* Surat Jalan TIDAK dipasang untuk Pimpinan.

       Daftarnya kini hanya memuat dokumen yang pengirim atau penerimanya
       adalah lokasi yang dipegang akun itu (lihat syarat_perpindahan), dan
       Pimpinan tidak dipegangkan lokasi mana pun — jadi menunya hanya akan
       membuka halaman kosong. Menu yang selalu kosong lebih buruk daripada
       menu yang tidak ada: ia membuat orang mengira datanya hilang.
       Pengawasan Pimpinan tetap lewat Mitra & Lokasi dan Laporan. */
    if (!$pimpinan) {
        $grup[] = ['sec' => 'Transaksi', 'k' => 'surat_jalan', 'i' => 'i-doc', 't' => 'Surat Jalan',
                   'h' => 'perpindahan.php', 'anggota' => [],
                   'terkait' => ['perpindahan_form.php', 'surat_jalan.php', 'perpindahan_ubah.php']];
    }

    if (!$pimpinan) {
        $grup[] = ['sec' => 'Transaksi', 'k' => 'penerimaan', 'i' => 'i-paket',
                   't' => 'Penerimaan Barang', 'badge' => $bTerima + $bKiriman,
                   'anggota' => [
            ['f' => 'penerimaan.php',       't' => 'Penerimaan',       'badge' => $bTerima],
            ['f' => 'kiriman_supplier.php', 't' => 'Kiriman Supplier', 'badge' => $bKiriman],
        ]];
    }

    /* Kardus: mengemas banyak jenis barang jadi satu paket berkode & berlabel QR
       (Okt 2026). Pimpinan boleh melihat daftarnya. */
    if ($role !== 'supplier' && $role !== 'toko') {
        $grup[] = ['sec' => 'Transaksi', 'k' => 'kardus', 'i' => 'i-box', 't' => 'Kardus',
                   'h' => 'kardus.php', 'anggota' => [],
                   'terkait' => ['kardus_detail.php', 'kardus_label.php']];
    }

    $grup[] = ['sec' => 'Transaksi', 'k' => 'persetujuan', 'i' => 'i-cek-bulat',
               't' => 'Persetujuan', 'h' => 'persetujuan.php',
               'badge' => $bPersetujuan, 'anggota' => []];

    /* Stock opname — hitung fisik per rak. Pekerjaan sisi gudang; Pimpinan
       hanya melihat hasilnya lewat laporan. Ditambahkan 13 Sep 2026: API dan
       aplikasinya sudah lama ada, halaman webnya baru sekarang. */
    if (!$pimpinan) {
        $grup[] = ['sec' => 'Transaksi', 'k' => 'opname', 'i' => 'i-clipboard',
                   't' => 'Stock Opname', 'h' => 'opname.php', 'anggota' => []];
    }

    if (!$pimpinan) {
        /* Halaman yang sama, disaring per jenis dokumen — perpindahan.php
           sudah menerima ?jenis=, jadi ini bukan halaman baru.

           Labelnya berbeda untuk Admin Gudang, dan itu bukan kosmetik: ia
           hanya memegang SATU gudang, jadi "Transfer Antar Gudang" menjanjikan
           sesuatu yang tidak bisa ia lakukan. Yang benar-benar ia kerjakan
           adalah memindahkan barang antar rak di dalam gudangnya sendiri. */
        $grup[] = $gudangSaja
            ? ['sec' => 'Transaksi', 'k' => 'transfer', 'i' => 'i-putar',
               't' => 'Pindah Antar Rak',
               'h' => 'perpindahan.php?jenis=mutasi_rak', 'anggota' => []]
            : ['sec' => 'Transaksi', 'k' => 'transfer', 'i' => 'i-putar',
               't' => 'Transfer Antar Gudang',
               'h' => 'perpindahan.php?jenis=transfer_gudang', 'anggota' => []];
    }

    /* Duduk di Transaksi, bukan Dasbor: yang dikerjakan di sini bukan
       sekadar melihat angka, melainkan menindaklanjuti batch yang hampir
       kedaluwarsa — dikeluarkan lebih dulu, ditarik, atau dimusnahkan.

       Batch & Expired dan Perbaikan & Pelepasan adalah satu pekerjaan yang
       dipecah dua layar: yang pertama menunjukkan batch bermasalah, yang
       kedua menindaklanjutinya. Karena itu satu grup bertab, bukan dua
       baris menu yang berdiri sendiri. */
    $grup[] = ['sec' => 'Transaksi', 'k' => 'expired', 'i' => 'i-cal',
               't' => 'Batch & Expired', 'anggota' => [
        ['f' => 'monitoring.php', 't' => 'Batch & Expired'],
        /* Daftar Batch masuk grup ini, bukan berdiri sendiri. Ketiganya
           pekerjaan yang sama dari tiga sudut: yang pertama menunjukkan
           batch bermasalah, yang kedua menindaklanjutinya, yang ketiga
           membuka seluruhnya untuk dicari dan dibetulkan — termasuk yang
           tidak sedang bermasalah dan karena itu tak muncul di manapun. */
        ['f' => 'batch.php',      't' => 'Daftar Batch'],
        ['f' => 'pelepasan.php',  't' => 'Perbaikan & Pelepasan'],
    ]];

    // Scan Resi pindah ke Transaksi. Dulu ia berdiri sendiri di grup
    // "Tools" — satu judul grup untuk satu tautan, padahal isinya justru
    // pekerjaan transaksi: memeriksa paket yang baru tiba. Tempatnya
    // sekarang bersebelahan dengan Penerimaan Barang, tempat ia dipakai.
    /* Scan Resi sekarang pekerjaan sisi TOKO: paket tiba di toko, resinya
       discan untuk menemukan dokumen kirimannya. Sisi gudang sudah punya
       jalurnya sendiri — Kiriman Supplier dan Penerimaan Barang — jadi
       menu ini tidak lagi muncul untuk Admin Gudang.
       Super Admin tetap memilikinya sebagai alat pemeriksaan lintas simpul. */
    if ($super) {
        $grup[] = ['sec' => 'Transaksi', 'k' => 'scan', 'i' => 'i-scan', 't' => 'Scan Resi Paket',
                   'h' => 'scan_resi.php', 'badge' => $bKiriman, 'anggota' => []];
    }

    /* Cek Surat Jalan ada di SEMUA peran, termasuk Admin Gudang — dan itu
       tidak bertabrakan dengan aturan "Scan Resi Paket hanya di toko".
       Keduanya memindai benda yang berbeda: Scan Resi Paket membaca label
       ekspedisi pada paket marketplace yang tiba di toko, sedangkan menu ini
       membaca QR pada lembar surat jalan kita sendiri — dan surat jalan
       memang berhenti di gudang, di toko, maupun di penerima amanah. */
    $grup[] = ['sec' => 'Transaksi', 'k' => 'ceksurat', 'i' => 'i-qr', 't' => 'Cek Surat Jalan',
               'h' => 'cek_surat.php', 'anggota' => [],
               // Alat uji scanner menumpang grup ini, bukan berdiri sendiri di
               // menu: ia dibuka sesekali saat memasang perangkat baru, bukan
               // pekerjaan harian yang pantas memakan satu baris tetap.
               'terkait' => ['alat_scan.php']];

    /* Lacak Kiriman: posisi surat jalan yang singgah di gudang transit —
       "sudah sampai mana". Pimpinan tidak dipegangkan lokasi, jadi tidak
       ada dokumen yang bisa dilacaknya (lihat syarat_perpindahan). */
    if (!$pimpinan) {
        $grup[] = ['sec' => 'Transaksi', 'k' => 'lacak', 'i' => 'i-truck', 't' => 'Lacak Kiriman',
                   'h' => 'lacak.php', 'anggota' => []];
    }

    // ------------------------------------------------------ MASTER DATA
    $grup[] = ['sec' => 'Master Data', 'k' => 'barang', 'i' => 'i-box', 't' => 'Produk',
               'h' => 'barang.php', 'anggota' => [],
               'terkait' => ['barang_form.php', 'barang_hapus.php', 'cari_barang.php']];

    if (!$pimpinan) {
        $grup[] = ['sec' => 'Master Data', 'k' => 'kategori', 'i' => 'i-saring', 't' => 'Kategori',
                   'h' => 'kategori.php', 'anggota' => []];
        $grup[] = ['sec' => 'Master Data', 'k' => 'supplier', 'i' => 'i-truck', 't' => 'Supplier',
                   'h' => 'supplier.php', 'anggota' => []];
        /* Rak jadi ANGGOTA grup Gudang, bukan lagi hanya 'terkait'.

           'terkait' cuma menyalakan sorotan menu saat halamannya terbuka —
           ia tidak pernah menghasilkan satu pun tautan. Akibatnya rak.php
           tidak punya jalan masuk dari menu sama sekali: satu-satunya
           tautan ke sana adalah satu kalimat di tengah halaman Gudang.
           Fitur yang ada tetapi tidak bisa ditemukan sama saja dengan
           fitur yang tidak ada, dan "membuat rak per gudang" adalah
           pekerjaan awal yang justru paling dibutuhkan saat gudang baru
           dibuat.

           Sebagai anggota, keduanya tampil sebagai dua tab pada satu
           baris sidebar — bukan dua baris terpisah. Rak selalu berada di
           dalam sebuah gudang, jadi menaruhnya sebagai baris sendiri
           menyiratkan dua hal yang setara, padahal yang satu isi dari
           yang lain. */
        $grup[] = ['sec' => 'Master Data', 'k' => 'gudang', 'i' => 'i-gudang', 't' => 'Gudang',
                   'anggota' => [
            ['f' => 'gudang.php', 't' => 'Gudang'],
            ['f' => 'rak.php',    't' => 'Rak / Lokasi'],
        ]];
        $grup[] = ['sec' => 'Master Data', 'k' => 'toko', 'i' => 'i-toko', 't' => 'Toko / Outlet',
                   'h' => 'toko.php', 'anggota' => [], 'terkait' => ['impor_pesanan.php']];
        // Ujung ketiga rantai: barang yang disalurkan, bukan dijual.
        $grup[] = ['sec' => 'Master Data', 'k' => 'penerima', 'i' => 'i-users',
                   't' => 'Penerima Amanah', 'h' => 'penerima.php', 'anggota' => []];
        /* Hulu rantai. Diletakkan bersebelahan dengan Penerima Amanah
           dengan sengaja: keduanya adalah orang di dua ujung yang berbeda —
           yang menitipkan dan yang menerima — dan keduanya sama-sama data
           pribadi yang harus dijaga dengan aturan yang sama. */
        $grup[] = ['sec' => 'Master Data', 'k' => 'donatur', 'i' => 'i-users',
                   't' => 'Donatur', 'h' => 'donatur.php', 'anggota' => []];
    }

    // Pengguna DULU tercantum setelah grup "Tools", sehingga judul
    // "Master Data" tercetak DUA KALI di sidebar dengan grup lain
    // menyelipnya. Sekarang ia menempel pada kelompoknya yang benar.
    /* Pengguna TIDAK lagi di sidebar. Ia pindah ke bawah Pengaturan —
       lihat halaman_pengaturan() dan grup_pengaturan().

       Alasannya bukan kerapian belaka: sidebar memetakan pekerjaan
       harian, dan mengelola akun bukan pekerjaan harian. Ia dilakukan
       sesekali, oleh satu orang, dan menaruhnya sejajar dengan Produk
       serta Supplier membuat menu terbaca lebih panjang daripada yang
       sebenarnya dipakai. */

    // ---------------------------------------------------------- LAPORAN
    // "Mitra & Lokasi" sudah masuk grup Dasbor di atas — tidak diulang di sini.
    /* Satu grup bertab, bukan dua baris menu yang berdiri sendiri: keduanya
       laporan, dan yang membukanya datang dengan satu niat — menyusun
       pertanggungjawaban. Laporan Donasi dipisah dari Laporan Stok karena
       pertanyaannya memang berbeda: yang satu "apa isi gudang sekarang",
       yang lain "dari mana saja barang itu datang bulan ini". */
    $grup[] = ['sec' => 'Laporan', 'k' => 'laporan', 'i' => 'i-grafik', 't' => 'Laporan',
               'anggota' => [
        ['f' => 'laporan.php',        't' => 'Laporan Stok'],
        ['f' => 'laporan_donasi.php', 't' => 'Laporan Donasi'],
        ['f' => 'laporan_harian_donasi.php', 't' => 'Laporan Harian Donasi'],
        ['f' => 'laporan_keluar.php', 't' => 'Rincian Barang Keluar'],
    ], 'terkait' => ['laporan_export.php']];

    // ------------------------------------------------------- PENGATURAN
    // 'sidebar' => false: grup ini TIDAK muncul di sidebar — jalan masuknya
    // lewat menu pengguna di topbar. Tetap didaftarkan di sini supaya
    // grup_berisi() masih bisa menyorot & memberi jejak navigasi pada
    // halaman anaknya (impor_data.php, ganti_password.php).
    $grup[] = ['sec' => 'Pengaturan', 'sidebar' => false,
               'k' => 'pengaturan', 'i' => 'i-gear', 't' => 'Pengaturan',
               'h' => 'pengaturan.php', 'anggota' => [],
               'terkait' => halaman_pengaturan()];

    /* Menu tidak boleh menampilkan halaman yang justru akan menolak. Disaring
       dari daftar yang SAMA dengan yang dipakai tolak_di_luar_peran(), supaya
       keduanya tidak mungkin berbeda. */
    $luar = halaman_luar_lingkup($role, $pdo);
    if ($luar) {
        $grup = array_values(array_filter($grup, function ($g) use ($luar) {
            $h = $g['h'] ?? ($g['anggota'][0]['f'] ?? '');
            // 'perpindahan.php?jenis=...' → 'perpindahan.php'
            $berkas = basename(strtok($h, '?'));
            return !isset($luar[$berkas]);
        }));
    }

    return $grup;
}

/**
 * Sidebar peran internal — bergrup, satu baris per tujuan.
 *
 * Judul bagian dibangkitkan otomatis dari kolom 'sec' tiap grup, jadi
 * urutan di grup_navigasi() adalah satu-satunya tempat yang mengatur
 * susunan sidebar. Tidak ada daftar kedua yang bisa jadi tidak sinkron.
 */
function menu_internal(string $role, array $badge = [], ?PDO $pdo = null): array {
    $menu = [];
    $secTerakhir = false;

    foreach (grup_navigasi($role, $badge, $pdo) as $g) {
        // Grup ber-'sidebar' => false hanya untuk penyorotan, bukan navigasi.
        if (($g['sidebar'] ?? true) === false) { continue; }

        $sec = $g['sec'] ?? null;
        if ($sec !== $secTerakhir) {
            if ($sec !== null) { $menu[] = ['sec' => $sec]; }
            $secTerakhir = $sec;
        }
        $menu[] = [
            'k'     => $g['k'],
            'i'     => $g['i'],
            't'     => $g['t'],
            'h'     => $g['h'] ?? $g['anggota'][0]['f'],
            'badge' => $g['badge'] ?? 0,
        ];
    }

    // 'Keluar' TIDAK ditaruh di sini — tempatnya di menu pengguna pada
    // topbar. Sidebar hanya memetakan tempat, bukan tindakan akun.
    return $menu;
}

/**
 * Grup yang memuat berkas tertentu, beserta seluruh berkas anggotanya.
 *
 * TIGA menu menunjuk berkas yang sama dan hanya dibedakan query string-nya:
 *
 *   Surat Jalan          perpindahan.php
 *   Transfer Antar Gudang perpindahan.php?jenis=transfer_gudang
 *   Pindah Antar Rak      perpindahan.php?jenis=mutasi_rak
 *
 * Dulu yang dibandingkan hanya nama berkasnya, sehingga grup pertama selalu
 * menang. Akibatnya dua menu terakhir TIDAK PERNAH menyala: menekannya
 * membuka halaman yang benar, tapi sidebar tetap menyorot Surat Jalan —
 * jadi orang merasa dilempar ke menu lain.
 *
 * Karena itu ?jenis= ikut dibandingkan lebih dulu. Menu tanpa query string
 * tetap menjadi jaring pengaman untuk jenis dokumen yang tidak punya
 * menunya sendiri (penyaluran, retur, kirim_toko), yang memang tepat
 * bernaung di bawah Surat Jalan.
 */
function grup_berisi(string $berkas, string $role, array $badge = [], ?string $jenis = null, ?PDO $pdo = null): ?array {
    $jenis = $jenis !== null ? $jenis : (string) ($_GET['jenis'] ?? '');
    $grup  = grup_navigasi($role, $badge, $pdo);

    $tujuan = function (array $g): array {
        $t = array_column($g['anggota'], 'f');
        if (isset($g['h'])) { $t[] = $g['h']; }
        return array_merge($t, $g['terkait'] ?? []);
    };

    // Putaran pertama: berkas DAN jenisnya sama persis.
    if ($jenis !== '') {
        foreach ($grup as $g) {
            foreach ($tujuan($g) as $b) {
                if (explode('?', $b)[0] !== $berkas) { continue; }
                parse_str((string) parse_url($b, PHP_URL_QUERY), $q);
                if (($q['jenis'] ?? '') === $jenis) { return $g; }
            }
        }
    }

    // Putaran kedua: cukup nama berkasnya.
    foreach ($grup as $g) {
        foreach ($tujuan($g) as $b) {
            if (explode('?', $b)[0] === $berkas) { return $g; }
        }
    }
    return null;
}

/**
 * Menu sidebar dasbor supplier.
 * Bentuknya sama dengan menu internal supaya seluruh peran memakai
 * kerangka tampilan yang identik — hanya isinya yang berbeda.
 */
function menu_supplier(int $badgeJalan = 0, ?PDO $pdo = null): array {
    return saring_menu_eksternal(array_merge([
        ['sec' => 'Transaksi'],
        // Supplier juga punya arus masuk & keluar: masuk = barang tiba dari
        // produksi/pembelian (stok_supplier bertambah), keluar = dikirim ke
        // gudang atau langsung ke toko. Dulu keduanya bernama "Barang Masuk
        // Hari Ini" dan "Siap Kirim", sehingga tidak terbaca sebagai satu
        // pasangan arus yang sama dengan peran lain.
        ['k' => 'masuk',   'i' => 'i-masuk',  't' => 'Barang Masuk',   'h' => 'dasbor_supplier.php?tab=masuk'],
        ['k' => 'siap',    'i' => 'i-keluar', 't' => 'Barang Keluar',  'h' => 'dasbor_supplier.php?tab=siap'],
        // Rak di gudang supplier. Pindahnya memakai stok_supplier, bukan
        // mesin perpindahan — jadi tempatnya di dasbornya sendiri.
        ['k' => 'rak',     'i' => 'i-putar',  't' => 'Pindah Antar Rak', 'h' => 'dasbor_supplier.php?tab=rak'],
        ['sec' => 'Pengiriman'],
        ['k' => 'riwayat', 'i' => 'i-truck',  't' => 'Surat Jalan',    'h' => 'dasbor_supplier.php?tab=riwayat',
         'badge' => $badgeJalan],
        // Supplier ikut memindai: ia perlu memastikan surat jalan yang sudah
        // dicetaknya benar isinya sebelum kurir mengangkat barangnya.
        ['k' => 'ceksurat','i' => 'i-qr',     't' => 'Cek Surat Jalan','h' => 'cek_surat.php'],
        ['sec' => 'Laporan'],
        ['k' => 'expired', 'i' => 'i-cal',    't' => 'Batch & Expired', 'h' => 'monitoring.php'],
        ['k' => 'laporan', 'i' => 'i-grafik', 't' => 'Laporan Stok',    'h' => 'laporan.php'],
        ['sec' => 'Akun'],
        ['k' => 'profil',  'i' => 'i-users',  't' => 'Profil Saya',    'h' => 'profil.php'],
        // Peran eksternal ikut kena batas 3 perangkat, tapi pengaturan.php
        // tertutup untuk mereka — tanpa baris ini batas itu berlaku tanpa
        // ada cara melihat atau melepas sesinya sendiri.
        ['k' => 'perangkat', 'i' => 'i-kunci', 't' => 'Perangkat Saya', 'h' => 'perangkat.php'],
        ['k' => 'aplikasi', 'i' => 'i-unduh', 't' => 'Aplikasi Android', 'h' => 'index.php#aplikasi'],
    ], $pdo ? menu_izin_tambahan($pdo) : []), $pdo);
}

/**
 * Buang butir menu yang halamannya di luar lingkup (mis. Laporan yang
 * izinnya dicabut) — untuk menu supplier & toko, yang tidak lewat
 * grup_navigasi(). Aturannya satu: halaman_luar_lingkup().
 */
function saring_menu_eksternal(array $menu, ?PDO $pdo): array {
    if ($pdo === null) { return $menu; }
    $luar = halaman_luar_lingkup(role_saat_ini(), $pdo);
    if (!$luar) { return $menu; }
    return array_values(array_filter($menu, function ($m) use ($luar) {
        if (!isset($m['h'])) { return true; }
        return !isset($luar[basename(strtok($m['h'], '?'))]);
    }));
}

/** Menu sidebar dasbor toko. */
function menu_toko(int $badgeTerima = 0, ?PDO $pdo = null): array {
    return saring_menu_eksternal(array_merge([
        ['sec' => 'Utama'],
        ['k' => 'ringkas', 'i' => 'i-grafik-bar', 't' => 'Dashboard',      'h' => 'dasbor_toko.php?tab=ringkas'],
        ['sec' => 'Transaksi'],
        // Toko pun punya kedua arah: masuk = penerimaan kiriman gudang atau
        // supplier, keluar = penjualan marketplace. Kosakatanya disamakan
        // dengan Gudang dan Supplier supaya satu sistem terbaca satu bahasa.
        ['k' => 'terima',  'i' => 'i-masuk',      't' => 'Barang Masuk',   'h' => 'penerimaan.php', 'badge' => $badgeTerima],
        ['k' => 'jual',    'i' => 'i-keluar',     't' => 'Barang Keluar',  'h' => 'dasbor_toko.php?tab=jual'],
        // Paket tiba di toko lebih dulu, jadi di sinilah resinya discan —
        // bersebelahan dengan Barang Masuk, tempat tindak lanjutnya.
        ['k' => 'scan',    'i' => 'i-scan',       't' => 'Scan Resi Paket','h' => 'scan_resi.php'],
        // QR di lembar surat jalan mengarah ke sini. Dipisahkan dari Scan
        // Resi Paket karena yang dipindai memang benda berbeda: label
        // ekspedisi pada paket, versus QR pada surat jalan kita sendiri.
        ['k' => 'ceksurat','i' => 'i-qr',         't' => 'Cek Surat Jalan','h' => 'cek_surat.php'],
        ['k' => 'impor',   'i' => 'i-impor',      't' => 'Impor Pesanan',  'h' => 'impor_pesanan.php'],
        // Pesanan yang datang dari API marketplace, lengkap dengan status
        // kiriman dan pembayarannya.
        ['k' => 'pesanan', 'i' => 'i-doc',        't' => 'Pesanan Marketplace',
         'h' => 'dasbor_toko.php?tab=pesanan'],
        ['sec' => 'Inventory'],
        ['k' => 'stok',    'i' => 'i-layers',     't' => 'Stok Toko',      'h' => 'dasbor_toko.php?tab=stok'],
        // Simpul yang sebelumnya tidak terwakili di sisi toko: gudang
        // penampung. Barang dari supplier mendarat di sini lebih dulu,
        // baru dipecah ke tiap toko.
        ['k' => 'gudang',  'i' => 'i-gudang',     't' => 'Gudang Toko',    'h' => 'dasbor_toko.php?tab=gudang'],
        // Rak gudang toko memakai batch_barang, jadi mesin perpindahan yang
        // sama dengan sisi gudang bisa dipakai apa adanya.
        ['k' => 'transfer','i' => 'i-putar',      't' => 'Pindah Antar Rak',
         'h' => 'perpindahan.php?jenis=mutasi_rak'],
        ['sec' => 'Laporan'],
        ['k' => 'expired', 'i' => 'i-cal',        't' => 'Batch & Expired','h' => 'monitoring.php'],
        ['k' => 'laporan', 'i' => 'i-grafik',     't' => 'Laporan Stok',   'h' => 'laporan.php'],
        ['sec' => 'Akun'],
        ['k' => 'profil',  'i' => 'i-users',      't' => 'Profil Saya',    'h' => 'profil.php'],
        // Lihat catatan yang sama di menu_supplier(): batas perangkat hanya
        // adil kalau pemiliknya bisa melepas sesinya sendiri.
        ['k' => 'perangkat', 'i' => 'i-kunci',    't' => 'Perangkat Saya', 'h' => 'perangkat.php'],
        ['k' => 'aplikasi', 'i' => 'i-unduh',    't' => 'Aplikasi Android', 'h' => 'index.php#aplikasi'],
    ], $pdo ? menu_izin_tambahan($pdo) : []), $pdo);
}

/**
 * Menu tambahan yang muncul KARENA IZIN, bukan karena peran.
 *
 * ---------------------------------------------------------------------
 * MENGAPA PERLU ADA
 * ---------------------------------------------------------------------
 * Izin per akun semula hanya mengatur tindakan DI DALAM halaman yang
 * perannya memang sudah bisa dibuka. Menu toko dan supplier adalah daftar
 * tetap, dan halaman internal ditutup tolak_supplier() menurut peran —
 * jadi memberi 'kelola_master' kepada akun toko tersimpan rapi, aktif di
 * basis data, dan tidak mengubah apa pun di layar. Tidak ada pintunya.
 *
 * Izin yang tidak menghasilkan apa-apa lebih buruk daripada izin yang
 * tidak ada, karena yang memberikannya mengira sudah memberi.
 *
 * Fungsi ini menutup celah itu: satu daftar, dipakai menu toko maupun
 * supplier, berisi HANYA yang benar-benar dibuka izin — bukan yang sudah
 * jadi hak perannya.
 *
 * Lingkupnya tetap tidak melebar. Halaman yang dibuka di sini menyaring
 * isinya dengan syarat_lingkup() seperti biasa, jadi akun toko yang
 * diberi Kelola Master Data mengubah katalog lembaga tetapi tetap hanya
 * melihat stoknya sendiri.
 */
function menu_izin_tambahan(PDO $pdo): array {
    $item = [];

    if (boleh_kelola_master($pdo)) {
        /* Nama ikon SENGAJA sama dengan yang dipakai grup_navigasi() untuk
           baris yang sama. Ikon berbeda untuk halaman yang sama membuat
           orang mengira itu dua tempat berbeda. */
        $item[] = ['k' => 'barang',   'i' => 'i-box',    't' => 'Data Barang',  'h' => 'barang.php'];
        $item[] = ['k' => 'kategori', 'i' => 'i-saring', 't' => 'Kategori',     'h' => 'kategori.php'];
        $item[] = ['k' => 'gudang',   'i' => 'i-gudang', 't' => 'Gudang',       'h' => 'gudang.php'];
        $item[] = ['k' => 'rak',      'i' => 'i-layers', 't' => 'Rak / Lokasi', 'h' => 'rak.php'];
        $item[] = ['k' => 'supplier', 'i' => 'i-truck',  't' => 'Supplier',     'h' => 'supplier.php'];
        $item[] = ['k' => 'mtoko',    'i' => 'i-toko',   't' => 'Toko / Outlet','h' => 'toko.php'];
        $item[] = ['k' => 'penerima', 'i' => 'i-users',  't' => 'Penerima Amanah', 'h' => 'penerima.php'];
    }

    /* Persetujuan hanya ditambahkan untuk pihak yang perannya TIDAK
       sudah punya menunya sendiri — kalau tidak, barisnya muncul dua kali
       dan orang ragu apakah keduanya menuju tempat yang sama. */
    if (boleh_setujui($pdo) && is_eksternal()) {
        $item[] = ['k' => 'setuju', 'i' => 'i-cek-bulat', 't' => 'Persetujuan', 'h' => 'persetujuan.php'];
    }

    if (!$item) { return []; }
    array_unshift($item, ['sec' => 'Izin Khusus']);
    return $item;
}

/**
 * Isi halaman Pengaturan — master data & administrasi sistem.
 *
 * Sebelumnya seluruh item ini berdiri sendiri di sidebar, sehingga menu
 * operasional harian (penerimaan, perpindahan, scan) tenggelam di antara
 * data yang jarang diubah. Sekarang satu sumber dipakai dua tempat:
 * pengaturan.php menggambar kartunya, _header.php memakai kuncinya untuk
 * menyorot item sidebar yang tepat saat halaman anaknya dibuka.
 *
 * Peran menentukan isinya: Pimpinan hanya melihat Data Barang (sesuai
 * menu lamanya), akun eksternal hanya Super Admin.
 */
function grup_pengaturan(): array {
    $role  = role_saat_ini();
    $super = $role === 'super_admin';
    $grup  = [];

    // Master data kini punya barisnya sendiri di sidebar (grup MASTER DATA),
    // jadi halaman ini tidak lagi mengulangnya sebagai kartu. Yang tinggal
    // di sini adalah hal yang memang bersifat setelan sistem.
    if (boleh_edit()) {
        $grup[] = ['judul' => 'Impor Data',
                   'ket'   => 'Isi master data sekaligus banyak dari berkas CSV',
                   'item'  => [
            // Keterangannya mengikuti apa yang benar-benar bisa diimpor
            // peran ini — menyebut "supplier, toko" kepada Admin Gudang
            // hanya menjanjikan sesuatu yang akan ditolak.
            ['k' => 'impor', 'i' => 'i-impor', 't' => 'Impor CSV', 'h' => 'impor_data.php',
             'd' => terikat_satu_gudang() ? 'Rak dan produk di gudang Anda'
                                          : 'Produk, kategori, gudang, rak, supplier, toko'],
        ]];
    }

    if ($super) {
        $grup[] = ['judul' => 'Sambungan Marketplace',
                   'ket'   => 'Tarik pesanan, status kiriman, dan pembayaran dari Shopee',
                   'item'  => [
            ['k' => 'marketplace', 'i' => 'i-toko', 't' => 'Sambungan Shopee',
             'h' => 'marketplace.php',
             'd' => 'Kredensial, otorisasi, dan penarikan pesanan'],
        ]];

        $grup[] = ['judul' => 'Tampilan & Identitas',
                   'ket'   => 'Logo, ikon tab, dan gambar saat tautan dibagikan',
                   'item'  => [
            ['k' => 'merek', 'i' => 'i-box', 't' => 'Logo & Identitas',
             'h' => 'pengaturan_merek.php',
             'd' => 'Logo utama, favicon, gambar share'],
        ]];

        $grup[] = ['judul' => 'Sistem',
                   'ket'   => 'Setelah mengunggah kode baru',
                   'item'  => [
            ['k' => 'migrasi', 'i' => 'i-layers', 't' => 'Migrasi Basis Data',
             'h' => 'migrasi.php',
             'd' => 'Jalankan berkas db/migrasi_*.sql yang belum terpasang'],
            ['k' => 'log', 'i' => 'i-clock', 't' => 'Jejak Aktivitas',
             'h' => 'log_aktivitas.php',
             'd' => 'Siapa mengubah apa, kapan — bisa dicari dan disaring'],
            ['k' => 'galat', 'i' => 'i-alert', 't' => 'Galat Sistem',
             'h' => 'galat.php',
             'd' => 'Cari kode galat yang muncul di layar dan lihat sebab aslinya'],
            ['k' => 'koreksi', 'i' => 'i-alert', 't' => 'Koreksi Angka',
             'h' => 'koreksi_angka.php',
             'd' => 'Betulkan jumlah raksasa yang telanjur tercatat (barcode masuk ke kotak Jumlah)'],
            ['k' => 'cadangan', 'i' => 'i-layers', 't' => 'Cadangan Data',
             'h' => 'cadangan.php',
             'd' => 'Salinan SQL lengkap: unduh, otomatis tiap hari, kirim ke Google Drive'],
            ['k' => 'apk', 'i' => 'i-unduh', 't' => 'Pembaruan Aplikasi',
             'h' => 'pengaturan_aplikasi.php',
             'd' => 'Umumkan APK Android baru; ponsel diminta memperbarui'],
        ]];

        $grup[] = ['judul' => 'Administrasi Akun',
                   'ket'   => 'Siapa saja yang bisa masuk sistem',
                   'item'  => [
            // Pindah dari sidebar ke sini. Pertanyaan "siapa saja yang
            // bisa masuk sistem?" dijawab di satu tempat, bersama dua
            // pintu akun mitra di bawahnya.
            ['k' => 'pengguna', 'i' => 'i-users', 't' => 'Pengguna', 'h' => 'pengguna.php',
             'd' => 'Semua akun, peran, dan batas perangkat', 'sat' => 'akun'],
            ['k' => 'akun_sup', 'i' => 'i-truck', 't' => 'Akun Supplier', 'h' => 'supplier.php?akun=1',
             'd' => 'Login portal supplier', 'sat' => 'akun'],
            ['k' => 'akun_tok', 'i' => 'i-toko',  't' => 'Akun Toko',     'h' => 'toko.php?akun=1',
             'd' => 'Login dasbor toko', 'sat' => 'akun'],
        ]];
    }

    $grup[] = ['judul' => 'Akun Saya',
               'ket'   => 'Pengaturan pribadi & keamanan',
               'item'  => [
        ['k' => 'sandi', 'i' => 'i-gear', 't' => 'Ganti Password', 'h' => 'ganti_password.php',
         'd' => 'Ubah password akun Anda sendiri'],
        // Semua peran boleh mengunduh aplikasi — halaman ini tempat satu-satunya
        // yang memuat tombol unduh dan kode QR-nya.
        ['k' => 'apk_unduh', 'i' => 'i-unduh', 't' => 'Aplikasi Android', 'h' => 'index.php#aplikasi',
         'd' => 'Unduh APK terbaru, pindai QR dari ponsel'],
        // Pasangan dari batas 3 perangkat: batas itu hanya adil kalau ada
        // yang bisa melihat dan membukanya sendiri.
        ['k' => 'perangkat', 'i' => 'i-kunci', 't' => 'Perangkat', 'h' => 'perangkat.php',
         /* "Bawaan", bukan "maksimal" — batasnya kini diatur per akun, dan
            menyebut satu angka sebagai maksimum membuat orang mengira itu
            berlaku untuk semua orang. */
         'd' => is_super() ? 'Sesi aktif seluruh akun · batas diatur per akun'
                             : 'Perangkat tempat akun Anda sedang masuk'],
    ]];

    return $grup;
}

/**
 * Berkas yang berada DI BAWAH Pengaturan — untuk sorotan sidebar & jejak
 * navigasi di _header.php.
 *
 * Master data (barang, kategori, gudang, rak, supplier, toko) sudah TIDAK
 * di sini lagi: masing-masing punya barisnya sendiri di sidebar, jadi
 * menyorot "Pengaturan" saat halamannya dibuka justru menyesatkan.
 */
function halaman_pengaturan(): array {
    // Terdaftar di sini supaya grup_berisi() mengenalinya sebagai halaman
    // anak Pengaturan: sidebar tetap menyorot Pengaturan, dan halamannya
    // mendapat jejak navigasi "Pengaturan › …" alih-alih berdiri sendiri
    // tanpa jalan pulang.
    return ['ganti_password.php', 'impor_data.php', 'perangkat.php', 'pengguna.php',
            'pengaturan_merek.php', 'marketplace.php', 'marketplace_auth.php', 'migrasi.php',
            'log_aktivitas.php', 'galat.php', 'koreksi_angka.php', 'pengaturan_aplikasi.php', 'aplikasi.php', 'cadangan.php'];
}

/** Label yang muncul di bawah nama aplikasi pada sidebar. */
function label_dasbor(): string {
    $peta = [
        'super_admin'   => 'Super Admin',
        'admin_gudang'  => 'Dasbor Gudang',
        'admin_terpadu' => 'Gudang & Donasi',
        'pimpinan'      => 'Dasbor Pimpinan',
        'supplier'      => 'Dasbor Supplier',
        'toko'          => 'Dasbor Toko',
    ];
    return $peta[role_saat_ini()] ?? APP_NAMA;
}

/**
 * Blokir akses pihak eksternal (supplier & toko) ke halaman internal gudang.
 *
 * ---------------------------------------------------------------------
 * DIBUKA OLEH IZIN YANG DIBERIKAN SENGAJA
 * ---------------------------------------------------------------------
 * Penjaga ini semula murni PERAN. Akibatnya izin per akun berhenti di
 * sini: akun toko yang diberi 'kelola_master' tetap ditolak sebelum
 * izinnya sempat dibaca, sehingga izin itu tersimpan, terlihat menyala di
 * Kelola Pengguna, dan tidak mengubah apa pun di layar.
 *
 * Sekarang halaman menyebutkan izin mana yang membukanya, dan pemegang
 * izin itu dipersilakan masuk. Halaman yang TIDAK menyebut izin apa pun
 * tetap tertutup rapat seperti sebelumnya — memanggil tolak_supplier()
 * tanpa argumen berperilaku persis seperti versi lama.
 *
 * Yang dibuka hanya PINTUNYA. Isi halamannya tetap disaring
 * syarat_lingkup() menurut peran, jadi akun toko yang masuk ke Data
 * Barang mengubah katalog lembaga tetapi tetap hanya melihat stoknya
 * sendiri.
 *
 * @param ?PDO   $pdo  wajib bila $izin diisi — izin dibaca dari basis data.
 * @param string $izin kunci di katalog_izin() yang membuka halaman ini.
 */
function tolak_supplier(?PDO $pdo = null, string $izin = ''): void {
    if (!is_eksternal()) { return; }
    if ($pdo !== null && $izin !== '' && izin($pdo, $izin, false)) { return; }

    http_response_code(403);
    die('<p style="font-family:sans-serif;padding:30px">403 — Halaman ini khusus staf gudang. '
      . '<a href="' . beranda_peran() . '">Kembali ke dasbor Anda</a></p>');
}

/**
 * Halaman yang berada di luar lingkup sebuah peran, beserta alasannya.
 *
 * SATU daftar, DUA pemakai: grup_navigasi() memakainya untuk menyembunyikan
 * menu, tolak_di_luar_peran() memakainya untuk menolak akses langsung.
 * Kalau masing-masing punya daftarnya sendiri, cepat atau lambat menu akan
 * menampilkan halaman yang justru menolak — atau sebaliknya, halaman yang
 * sudah dicabut dari menu masih terbuka lewat URL yang di-bookmark.
 *
 * Admin Gudang mengurus ISI gudangnya. Master data lembaga (kategori,
 * supplier, toko, penerima amanah) adalah wewenang Super Admin: satu
 * perubahan di sana berlaku untuk semua gudang sekaligus, jadi tidak pantas
 * dipegang oleh satu petugas gudang.
 */
function halaman_luar_lingkup(string $role, ?PDO $pdo = null): array {
    $daftar = halaman_luar_lingkup_peran($role);

    /* Halaman yang DIBUKA izin dicoret dari daftar larangan.
     *
     * Tanpa ini, izin 'kelola_master' yang diberikan kepada Admin Gudang
     * berhenti persis seperti pada akun toko: tersimpan, menyala di Kelola
     * Pengguna, tetapi Kategori/Supplier/Toko/Penerima tetap hilang dari
     * menu dan menolak lewat URL.
     *
     * Dicoret dari SATU daftar, jadi menu dan penjaga halaman berubah
     * bersamaan — tidak mungkin menu menampilkan halaman yang menolak,
     * atau sebaliknya.
     *
     * $pdo opsional supaya pemanggil yang belum sempat diubah tetap sah;
     * tanpa $pdo hasilnya persis seperti versi lama. */
    if ($pdo !== null && $daftar && izin($pdo, 'kelola_master', false)) {
        foreach (['kategori.php', 'supplier.php', 'toko.php', 'penerima.php'] as $h) {
            unset($daftar[$h]);
        }
    }
    /* Kebalikannya: izin yang DICABUT menambah larangan. Menu Laporan
       hilang dan halamannya menolak lewat satu daftar yang sama. */
    if ($pdo !== null && !empty($_SESSION['user_id']) && !boleh_lihat_laporan($pdo)) {
        $daftar['laporan.php']        = 'Akun Anda tidak diberi izin membuka laporan.';
        $daftar['laporan_export.php'] = 'Akun Anda tidak diberi izin mengekspor laporan.';
    }
    return $daftar;
}

/** Daftar mentahnya, murni menurut peran — tanpa penyimpangan izin. */
function halaman_luar_lingkup_peran(string $role): array {
    if ($role === 'admin_gudang') {
        return [
            'kategori.php'      => 'Kategori produk adalah master data lembaga, diatur oleh Super Admin.',
            'supplier.php'      => 'Data supplier adalah master data lembaga, diatur oleh Super Admin.',
            'toko.php'          => 'Data toko adalah master data lembaga, diatur oleh Super Admin.',
            'penerima.php'      => 'Penerima amanah diatur oleh Super Admin, bukan dari sisi gudang.',
            'impor_pesanan.php' => 'Impor pesanan marketplace adalah pekerjaan sisi toko.',
        ];
    }
    /* Peran terpadu memegang seluruh rantai di tempatnya: menerima titipan,
       menyimpan, lalu menyalurkan. Penerima amanah karena itu TIDAK ditutup
       untuknya — ujung penyaluran adalah pekerjaannya sendiri, dan menutup
       masternya berarti ia harus meminta Super Admin setiap kali ada
       keluarga baru yang dibantu.

       Master data yang berlaku lintas lembaga (kategori, supplier, toko)
       tetap ditutup: satu perubahan di sana mengenai semua gudang
       sekaligus, dan itu bukan wewenang satu petugas di satu tempat. */
    if ($role === 'admin_terpadu') {
        return [
            'kategori.php'      => 'Kategori produk adalah master data lembaga, diatur oleh Super Admin.',
            'supplier.php'      => 'Data supplier adalah master data lembaga, diatur oleh Super Admin.',
            'toko.php'          => 'Data toko adalah master data lembaga, diatur oleh Super Admin.',
            'impor_pesanan.php' => 'Impor pesanan marketplace adalah pekerjaan sisi toko.',
        ];
    }
    return [];
}

/**
 * Hentikan bila halaman ini di luar lingkup peran yang sedang login.
 *
 * Dipanggil di ATAS halaman, sebelum POST diproses — kalau ditaruh di bawah,
 * datanya sudah tersimpan lebih dulu dan 403-nya cuma menghias layar.
 */
function tolak_di_luar_peran(?PDO $pdo = null): void {
    $daftar = halaman_luar_lingkup(role_saat_ini(), $pdo);
    $ini    = basename($_SERVER['SCRIPT_NAME'] ?? '');
    if (!isset($daftar[$ini])) { return; }

    http_response_code(403);
    die('<p style="font-family:sans-serif;padding:30px">403 — ' . e($daftar[$ini])
      . ' <a href="' . beranda_peran() . '">Kembali ke dasbor Anda</a></p>');
}

// ---------------------------------------------------------------------
// Kode master data
// ---------------------------------------------------------------------
/**
 * Kode berikutnya untuk sebuah tabel master — dipakai SEMUA formulir.
 *
 * Sebelumnya tiap halaman menyalin query MAX(CAST(SUBSTRING_INDEX(...)))
 * sendiri. Cara itu punya dua kelemahan yang nyata di data yang ada:
 *
 *  1. Kode rak berbentuk RAK-A01 — huruf lorong lalu angka. CAST ke
 *     UNSIGNED menghasilkan 0, sehingga saran kodenya selalu "RAK-1".
 *     Itulah sebabnya rak.php dulu tidak punya kode otomatis sama sekali.
 *  2. Indeks unik pada `kode` TIDAK memandang deleted_at. Baris yang sudah
 *     dihapus lunak tetap memegang kodenya, jadi kode hasil MAX+1 masih
 *     bisa bentrok. Fungsi ini memeriksanya dan melompat ke yang bebas.
 *
 * Lebar angka mengikuti kode yang sudah ada (KAT-008 tetap 3 digit,
 * BBY-0015 tetap 4), jadi tampilannya tidak berubah bentuk di tengah data.
 */
function kode_berikut(PDO $pdo, string $tabel, string $awalan, int $lebarBawaan = 3): string {
    $tabel = preg_replace('/[^a-z_]/', '', $tabel);

    // Urutkan panjang dulu agar RAK-A9 tidak dianggap lebih besar dari RAK-A10.
    /* Hanya kode berpola wajar yang dijadikan acuan: ekor angkanya paling
       banyak 8 digit. Satu produk yang kodenya diisi nomor barcode
       ("BBY-00398173255999989997") dulu menjadi "kode terpanjang", dan
       sejak itu setiap saran kode ikut 20 digit. */
    $st = $pdo->prepare("SELECT kode FROM `$tabel` WHERE kode LIKE ? AND kode REGEXP ?
                         ORDER BY LENGTH(kode) DESC, kode DESC LIMIT 1");
    $st->execute([$awalan . '-%', '^' . preg_quote($awalan, '/') . '-[^0-9]*[0-9]{1,8}$']);
    $terakhir = (string) ($st->fetchColumn() ?: '');

    $kepala = '';               // bagian non-angka di ujung, mis. "A" pada A01
    $angka  = 0;
    $lebar  = $lebarBawaan;

    if ($terakhir !== '') {
        $sisa = substr($terakhir, strlen($awalan) + 1);
        if (preg_match('/^(.*?)(\d+)$/', $sisa, $m)) {
            $kepala = $m[1];
            $angka  = (int) $m[2];
            $lebar  = strlen($m[2]);
        }
    }

    // Lewati kode yang sudah terpakai — termasuk oleh baris terhapus lunak.
    $cek = $pdo->prepare("SELECT 1 FROM `$tabel` WHERE kode = ? LIMIT 1");
    for ($i = 1; $i <= 999; $i++) {
        $calon = $awalan . '-' . $kepala . str_pad((string) ($angka + $i), $lebar, '0', STR_PAD_LEFT);
        $cek->execute([$calon]);
        if (!$cek->fetchColumn()) { return $calon; }
    }

    // Sangat tidak mungkin tercapai; lebih baik kosong daripada kode bentrok.
    return '';
}

// ---------------------------------------------------------------------
// Nomor dokumen
// ---------------------------------------------------------------------
/**
 * Buat nomor dokumen berurutan yang aman dari duplikasi.
 *
 * Berbeda dengan cara lama (COUNT(*) transaksi hari ini) yang bentrok
 * begitu ada dokumen dihapus atau dua admin menyimpan bersamaan, fungsi
 * ini memakai MAX() dari nomor urut yang benar-benar ada.
 *
 * Hasil: PREFIX-YYYYMMDD-0001
 */
function nomor_dokumen(PDO $pdo, string $tabel, string $kolom, string $prefix): string {
    $tabel = preg_replace('/[^a-z_]/', '', $tabel);
    $kolom = preg_replace('/[^a-z_]/', '', $kolom);
    $awal  = $prefix . '-' . date('Ymd') . '-';
    $kunci = "$tabel.$kolom.$awal";

    // Posisi potong dihitung dari panjang prefiks (angka murni, bukan input
    // pengguna) sehingga aman disisipkan langsung ke SQL.
    $posisi = strlen($awal) + 1;

    $urut = 0;
    try {
        // Langkah 1 — siapkan pencacah, diisi dari nomor tertinggi yang sudah
        // ada. Hanya berpengaruh sekali per prefiks (per hari); INSERT IGNORE
        // membuat pemanggilan berikutnya tidak mengubah apa pun.
        $pdo->prepare(
            "INSERT IGNORE INTO dokumen_counter (kunci, urut)
             SELECT ?, COALESCE(MAX(CAST(SUBSTRING($kolom, $posisi) AS UNSIGNED)), 0)
             FROM $tabel WHERE $kolom LIKE ?"
        )->execute([$kunci, $awal . '%']);

        // Langkah 2 — naikkan pencacah. UPDATE adalah pembacaan TERKINI, bukan
        // snapshot MVCC, jadi dua transaksi yang berjalan bersamaan tidak akan
        // pernah memperoleh nomor yang sama.
        $up = $pdo->prepare("UPDATE dokumen_counter SET urut = LAST_INSERT_ID(urut + 1) WHERE kunci = ?");
        $up->execute([$kunci]);

        // Diperiksa lewat rowCount(), BUKAN nilai LAST_INSERT_ID(). Bila UPDATE
        // tidak mengenai baris mana pun, LAST_INSERT_ID() mengembalikan sisa
        // nilai dari operasi lain di sesi yang sama — bisa terpakai sebagai
        // nomor dokumen yang sama sekali tidak berkaitan.
        if ($up->rowCount() > 0) {
            $urut = (int) $pdo->query("SELECT LAST_INSERT_ID()")->fetchColumn();
        }
    } catch (PDOException $e) {
        // Tabel pencacah belum ada — terjadi bila file PHP sudah diperbarui
        // tetapi migrasi bagian 5c belum dijalankan. Jangan matikan seluruh
        // modul; jatuh ke cara lama yang masih benar untuk pemakaian tunggal.
        error_log('[AMANAH] dokumen_counter tidak tersedia, memakai MAX(): ' . $e->getMessage());
        $urut = 0;
    }

    if ($urut <= 0) {
        $st = $pdo->prepare(
            "SELECT COALESCE(MAX(CAST(SUBSTRING($kolom, $posisi) AS UNSIGNED)), 0) + 1
             FROM $tabel WHERE $kolom LIKE ?"
        );
        $st->execute([$awal . '%']);
        $urut = (int) $st->fetchColumn();
    }

    return $awal . str_pad($urut, 4, '0', STR_PAD_LEFT);
}

/**
 * Jalankan operasi yang memakai nomor dokumen, ulangi bila bentrok.
 * Melindungi dari race condition dua user menyimpan pada detik yang sama.
 *
 * $operasi menerima string nomor dan mengembalikan hasil apa pun.
 */
function dengan_nomor_dokumen(PDO $pdo, string $tabel, string $kolom, string $prefix, callable $operasi, int $percobaan = 5) {
    for ($i = 0; $i < $percobaan; $i++) {
        $no = nomor_dokumen($pdo, $tabel, $kolom, $prefix);
        try {
            return $operasi($no);
        } catch (PDOException $e) {
            // Ulangi HANYA bila bentroknya memang pada nomor dokumen ini.
            // Pelanggaran foreign key atau unique key lain (kode barang, resi,
            // batch) juga bersandi 23000 — mengulanginya 5× hanya menunda
            // munculnya pesan kesalahan yang sebenarnya.
            $bentrokNomor = $e->getCode() === '23000' && str_contains($e->getMessage(), $no);

            // Deadlock & lock-wait timeout juga layak diulang: keduanya
            // kegagalan sementara, bukan kesalahan data. Tanpa ini petugas
            // hanya melihat pesan error generik padahal cukup mencoba lagi.
            $sementara = in_array($e->getCode(), ['40001', 'HY000'], true)
                      && (str_contains($e->getMessage(), 'Deadlock')
                          || str_contains($e->getMessage(), 'Lock wait timeout'));

            if (($bentrokNomor || $sementara) && $i < $percobaan - 1) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                usleep(random_int(10000, 60000));
                continue;
            }
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
    }
    throw new RuntimeException('Gagal membuat nomor dokumen unik setelah beberapa percobaan.');
}

// ---------------------------------------------------------------------
// Stok
// ---------------------------------------------------------------------
/**
 * Hitung ulang stok agregat satu barang dari seluruh batch-nya.
 *
 * Sejak v2 ini SATU-SATUNYA sumber kebenaran stok — trigger MySQL lama
 * (trg_batch_after_insert / trg_batch_after_update) sudah dihapus di
 * migrasi karena menyebabkan perhitungan ganda saat batch dipindahkan
 * antar rak.
 */
/**
 * Kunci baris agregat `stok` untuk satu atau beberapa barang.
 *
 * WAJIB dipanggil sebagai langkah PERTAMA di dalam transaksi, sebelum
 * menyentuh batch mana pun.
 *
 * Alasannya: recalc_stok() menghitung SUM(qty_tersisa) di dalam pernyataan
 * INSERT ... ON DUPLICATE KEY UPDATE, dan pembacaan seperti itu mengambil
 * kunci S pada SELURUH baris batch milik barang tersebut. Bila dua transaksi
 * sudah sama-sama memegang kunci X pada batch yang berbeda dari barang yang
 * sama, keduanya saling menunggu — deadlock.
 *
 * Dengan mengunci baris `stok` lebih dulu, transaksi kedua tertahan sebelum
 * menyentuh batch apa pun, sehingga siklus tunggu tidak pernah terbentuk.
 * Daftar ID diurutkan agar dokumen multi-barang selalu mengunci dengan urutan
 * yang sama.
 */
function kunci_stok(PDO $pdo, array $barangIds): void {
    $ids = array_values(array_unique(array_map('intval', $barangIds)));
    sort($ids);

    $kunci    = $pdo->prepare("SELECT qty_total FROM stok WHERE barang_id = ? FOR UPDATE");
    $pastikan = $pdo->prepare("INSERT IGNORE INTO stok (barang_id, qty_total) VALUES (?, 0)");

    foreach ($ids as $id) {
        if ($id <= 0) continue;

        // FOR UPDATE dijalankan LEBIH DULU, dan INSERT IGNORE hanya dipakai
        // bila barisnya memang belum ada.
        //
        // Urutan ini penting: INSERT IGNORE yang menabrak baris yang sudah ada
        // mengambil kunci S. Bila beberapa transaksi sama-sama memegang S lalu
        // semuanya mencoba naik ke X lewat FOR UPDATE, terjadi deadlock
        // naik-kunci. Karena baris stok sudah disemai untuk semua barang di
        // migrasi (dan dibuat oleh barang_form.php untuk barang baru), jalur
        // INSERT hampir tidak pernah tersentuh.
        $kunci->execute([$id]);
        if ($kunci->fetchColumn() === false) {
            $pastikan->execute([$id]);
            $kunci->execute([$id]);
            $kunci->fetchAll();
        }
    }
}

function recalc_stok(PDO $pdo, int $barangId): void {
    // PENTING: hanya batch yang masih di GUDANG (toko_id IS NULL) yang dihitung.
    //
    // Sejak v3 barang bisa berada di toko. Bila stok toko ikut dijumlahkan ke
    // sini, seluruh peringatan gudang jadi buta: barang yang habis di rak tapi
    // menumpuk di toko akan tetap dilaporkan "aman" oleh v_status_stok, dan
    // gudang tidak pernah diberi tahu untuk memesan ulang. Untuk susu formula
    // dan pangan bayi, itu risiko nyata.
    //
    // Stok toko dihitung terpisah lewat stok_di_toko() dan view v_stok_toko.
    $pdo->prepare(
        "INSERT INTO stok (barang_id, qty_total)
         VALUES (:b1, (SELECT COALESCE(SUM(qty_tersisa),0) FROM batch_barang
                       WHERE barang_id = :b2 AND toko_id IS NULL))
         ON DUPLICATE KEY UPDATE
           qty_total = (SELECT COALESCE(SUM(qty_tersisa),0) FROM batch_barang
                        WHERE barang_id = :b3 AND toko_id IS NULL)"
    )->execute([':b1' => $barangId, ':b2' => $barangId, ':b3' => $barangId]);
}

/** Stok tersedia satu barang, opsional dibatasi pada satu rak. */
function stok_tersedia(PDO $pdo, int $barangId, ?int $rakId = null): int {
    $tk = sql_tanpa_kardus($pdo, 'batch_barang');
    if ($rakId) {
        $st = $pdo->prepare("SELECT COALESCE(SUM(qty_tersisa),0) FROM batch_barang
                             WHERE barang_id = ? AND lokasi_rak_id = ? AND qty_tersisa > 0$tk");
        $st->execute([$barangId, $rakId]);
    } else {
        $st = $pdo->prepare("SELECT COALESCE(SUM(qty_tersisa),0) FROM batch_barang
                             WHERE barang_id = ? AND qty_tersisa > 0$tk");
        $st->execute([$barangId]);
    }
    return (int) $st->fetchColumn();
}

/** Stok tersedia satu barang di sebuah toko. */
function stok_di_toko(PDO $pdo, int $barangId, int $tokoId): int {
    $st = $pdo->prepare(
        "SELECT COALESCE(SUM(qty_tersisa),0) FROM batch_barang
         WHERE barang_id = ? AND toko_id = ? AND qty_tersisa > 0"
    );
    $st->execute([$barangId, $tokoId]);
    return (int) $st->fetchColumn();
}

/** Stok tersedia satu barang di seluruh rak milik satu gudang. */
function stok_di_gudang(PDO $pdo, int $barangId, int $gudangId): int {
    // Rak yang sudah dihapus (soft delete) tidak dihitung, agar angka di sini
    // sama dengan angka pada dashboard dan laporan.
    $st = $pdo->prepare(
        "SELECT COALESCE(SUM(bb.qty_tersisa),0)
         FROM batch_barang bb
         JOIN lokasi_rak lr ON lr.id = bb.lokasi_rak_id
         WHERE bb.barang_id = ? AND lr.gudang_id = ? AND bb.qty_tersisa > 0
           AND lr.deleted_at IS NULL" . sql_tanpa_kardus($pdo)
    );
    $st->execute([$barangId, $gudangId]);
    return (int) $st->fetchColumn();
}

/**
 * Stok sebuah barang yang benar-benar BOLEH DISALURKAN.
 *
 * Berbeda dari stok_tersedia() yang menjumlahkan semuanya. Yang ini
 * membuang dua hal yang tidak boleh ikut terhitung saat petugas menyiapkan
 * pengeluaran:
 *   - barang berkondisi bekas_tidak_layak (harus lewat Perbaikan dulu)
 *   - stok yang sudah ada di toko (bukan lagi milik gudang)
 *
 * $gudangId diisi untuk peran yang terikat satu gudang, supaya angka yang
 * dipakai memeriksa kecukupan sama dengan angka yang nanti dipotong
 * ambil_fefo(). Kalau keduanya berbeda, pemeriksaan lolos lalu penyimpanan
 * gagal — dan petugas melihat pesan galat yang seperti salah sistem.
 */
function stok_layak_salur(PDO $pdo, int $barangId, ?int $gudangId = null): int {
    $layak = kondisi_layak_salur();
    $tanya = implode(',', array_fill(0, count($layak), '?'));

    $sql = "SELECT COALESCE(SUM(bb.qty_tersisa),0) FROM batch_barang bb
            WHERE bb.barang_id = ? AND bb.qty_tersisa > 0 AND bb.kondisi IN ($tanya)" . sql_tanpa_kardus($pdo);
    $p = array_merge([$barangId], $layak);

    if ($gudangId) {
        $sql .= " AND bb.lokasi_rak_id IN (SELECT lr.id FROM lokasi_rak lr
                                           WHERE lr.gudang_id = ? AND lr.deleted_at IS NULL)";
        $p[] = $gudangId;
    } else {
        $sql .= " AND bb.toko_id IS NULL";
    }

    $st = $pdo->prepare($sql);
    $st->execute($p);
    return (int) $st->fetchColumn();
}

/**
 * Pembangun kueri FEFO — dipakai ambil_fefo() DAN pratinjau_fefo().
 *
 * Satu tempat untuk tiga keputusan yang harus selalu sama: batch mana yang
 * termasuk (lokasi asal), batch mana yang dilewati (kondisi tidak layak
 * bila $hanyaLayak), dan batch mana yang didahulukan ($batchPilih, lalu
 * tanggal kedaluwarsa terdekat). Sebelumnya keduanya menulis kuerinya
 * sendiri-sendiri dan sudah sempat berbeda: surat jalan mencetak batch
 * yang tidak sama dengan yang benar-benar dipotong saat penerimaan.
 *
 * Mengembalikan [sql, parameter]. Pemanggil menambah FOR UPDATE sendiri
 * bila memang sedang memotong stok.
 */
function fefo_sql(string $kolom, int $barangId, ?int $rakId, ?int $gudangId,
                  ?int $tokoId, bool $hanyaLayak, ?string $batchPilih, string $tanpaKardus = ''): array {
    $sql = "SELECT $kolom FROM batch_barang bb";
    $p   = [$barangId];

    if ($tokoId) {
        $sql .= " WHERE bb.barang_id = ? AND bb.qty_tersisa > 0 AND bb.toko_id = ?";
        $p[] = $tokoId;
    } elseif ($gudangId) {
        // Sengaja memakai sub-kueri, bukan JOIN: FOR UPDATE pada JOIN ikut
        // mengunci baris lokasi_rak dan memperbesar peluang lock-wait dengan
        // petugas yang sedang menyunting data rak.
        $sql .= " WHERE bb.barang_id = ? AND bb.qty_tersisa > 0
                    AND bb.lokasi_rak_id IN (SELECT lr.id FROM lokasi_rak lr
                                             WHERE lr.gudang_id = ? AND lr.deleted_at IS NULL)";
        $p[] = $gudangId;
    } elseif ($rakId) {
        $sql .= " WHERE bb.barang_id = ? AND bb.qty_tersisa > 0 AND bb.lokasi_rak_id = ?";
        $p[] = $rakId;
    } else {
        // Tanpa pembatas lokasi, stok toko TIDAK ikut diambil — barang yang
        // sudah ada di toko bukan lagi milik gudang.
        $sql .= " WHERE bb.barang_id = ? AND bb.qty_tersisa > 0 AND bb.toko_id IS NULL";
    }
    /* Batch yang sudah dikemas ke kardus bukan stok lepas: tidak diambil FEFO
       (fitur kardus, Okt 2026). Batch kardus dipotong lewat jalur kardusnya. */
    $sql .= $tanpaKardus;

    /* Daftar kondisinya diambil dari kondisi_layak_salur(), bukan ditulis
       ulang di sini. Kalau enum kondisinya bertambah suatu saat, tempat
       yang perlu disunting tetap satu. */
    if ($hanyaLayak) {
        $layak = kondisi_layak_salur();
        $sql .= " AND bb.kondisi IN (" . implode(',', array_fill(0, count($layak), '?')) . ")";
        $p = array_merge($p, $layak);
    }

    /* Batch yang diminta pembuat dokumen DIDAHULUKAN — bukan dipaksakan
       tunggal. Bila jumlahnya melebihi isi batch itu, sisanya tetap FEFO
       dari batch lain; memaksakan tunggal membuat dokumen gagal disetujui
       dengan "stok tidak mencukupi" padahal barangnya jelas ada. Nilai
       berkoma (hasil pratinjau) bukan pilihan, jadi diabaikan. */
    $pilihSatu = ($batchPilih !== null && trim($batchPilih) !== ''
                  && strpos($batchPilih, ',') === false) ? trim($batchPilih) : null;

    $sql .= ' ORDER BY ';
    if ($pilihSatu !== null) {
        $sql .= '(bb.nomor_batch = ?) DESC, ';
        $p[] = $pilihSatu;
    }
    $sql .= "(bb.tanggal_expired IS NULL), bb.tanggal_expired ASC, bb.id ASC";

    return [$sql, $p];
}

/**
 * Ambil qty dari batch-batch sebuah barang secara FEFO
 * (First Expired First Out — yang paling cepat kedaluwarsa keluar dulu).
 *
 * Mengembalikan daftar [batch_id, nomor_batch, tanggal_expired, ambil].
 * Melempar exception bila stok tidak mencukupi — TIDAK pernah diam-diam
 * mengambil lebih sedikit dari yang diminta.
 *
 * PENTING: panggil di dalam transaksi. Memakai FOR UPDATE agar dua admin
 * tidak bisa mengambil batch yang sama bersamaan.
 *
 * $hanyaLayak — bila true, batch berkondisi bekas_tidak_layak dilewati.
 * Dipakai jalur PENYALURAN (barang keluar): barang yang sudah dinilai tidak
 * layak tidak boleh sampai ke penerima amanah. Jalur PERPINDAHAN tidak
 * memakainya, karena memindahkan barang tidak layak ke gudang lain atau ke
 * tempat perbaikan justru pekerjaan yang sah.
 *
 * Bawaannya false supaya pemanggil lama tidak berubah perilaku diam-diam.
 */
function ambil_fefo(PDO $pdo, int $barangId, int $qty, ?int $rakId = null,
                    ?int $gudangId = null, ?int $tokoId = null,
                    bool $hanyaLayak = false, ?string $batchPilih = null): array {
    [$sql, $p] = fefo_sql(
        "bb.id, bb.nomor_batch, bb.kondisi, bb.sumber, bb.donatur_id,
         bb.tanggal_expired, bb.qty_tersisa,
         bb.lokasi_rak_id, bb.toko_id, bb.harga_beli, bb.supplier_id",
        $barangId, $rakId, $gudangId, $tokoId, $hanyaLayak, $batchPilih, sql_tanpa_kardus($pdo)
    );
    // FOR UPDATE: dua admin tidak boleh mengambil batch yang sama bersamaan.
    $st = $pdo->prepare($sql . " FOR UPDATE");
    $st->execute($p);
    $batches = $st->fetchAll();

    $sisa = $qty;
    $hasil = [];
    foreach ($batches as $b) {
        if ($sisa <= 0) break;
        $ambil = min($sisa, (int) $b['qty_tersisa']);
        $hasil[] = [
            'batch_id'        => (int) $b['id'],
            'nomor_batch'     => $b['nomor_batch'],
            // Kondisi, sumber, dan donatur ikut dikembalikan supaya batch yang
            // dibuat di lokasi TUJUAN mewarisi ketiganya dari batch asalnya —
            // bukan diam-diam kembali ke nilai bawaan kolom. Barang donasi yang
            // berpindah gudang tidak boleh berubah menjadi barang pembelian,
            // dan atribusi donaturnya tidak boleh putus di tengah rantai.
            'kondisi'         => $b['kondisi'] ?? 'baru',
            'sumber'          => $b['sumber'] ?? 'pembelian',
            'donatur_id'      => $b['donatur_id'] !== null ? (int) $b['donatur_id'] : null,
            'tanggal_expired' => $b['tanggal_expired'],
            'lokasi_rak_id'   => $b['lokasi_rak_id'] ? (int) $b['lokasi_rak_id'] : null,
            'toko_id'         => $b['toko_id'] ? (int) $b['toko_id'] : null,
            'harga_beli'      => (float) $b['harga_beli'],
            'supplier_id'     => $b['supplier_id'] ? (int) $b['supplier_id'] : null,
            'ambil'           => $ambil,
        ];
        $sisa -= $ambil;
    }

    if ($sisa > 0) {
        $tersedia = $qty - $sisa;
        /* Saat batch tidak layak dilewati, angka "tersedia" di sini lebih
           kecil daripada stok yang terlihat di layar. Tanpa penjelasan itu,
           petugas membaca pesan ini sebagai sistem yang salah hitung. */
        throw new RuntimeException($hanyaLayak
            ? "Stok layak salur tidak mencukupi. Diminta $qty unit, yang layak "
            . "disalurkan hanya $tersedia unit. Sisanya berkondisi bekas tidak "
            . "layak dan harus lewat Perbaikan & Pelepasan lebih dulu."
            : "Stok tidak mencukupi. Diminta $qty unit, tersedia hanya $tersedia unit.");
    }

    return $hasil;
}

/**
 * FEFO atas stok MITRA, bukan stok gudang.
 *
 * Stok supplier hidup di tabel `stok_supplier`, terpisah dari `batch_barang`
 * milik gudang. Perbedaan itu penting: ambil_fefo() di atas, bila dipanggil
 * tanpa pembatas lokasi, mengambil dari batch gudang MANA PUN. Dipakai untuk
 * penyaluran langsung dari mitra, ia akan diam-diam memotong stok gudang
 * lembaga untuk barang yang sebenarnya ada di tempat mitra — dua angka
 * sekaligus jadi salah, dan tidak ada yang menandainya.
 *
 * Bentuk kembaliannya sengaja disamakan dengan ambil_fefo() supaya
 * terapkan_perpindahan() bisa memakai keduanya lewat satu jalur.
 */
function ambil_fefo_supplier(PDO $pdo, int $barangId, int $qty, int $supplierId,
                             ?string $batchPilih = null): array {
    /* Batch pilihan didahulukan, alasannya sama dengan ambil_fefo() —
       lihat catatan panjang di sana. Dibuat sepadan supaya stok mitra dan
       stok gudang tidak berperilaku berbeda untuk dokumen yang sama. */
    $pilihSatu = ($batchPilih !== null && trim($batchPilih) !== ''
                  && strpos($batchPilih, ',') === false) ? trim($batchPilih) : null;

    $p   = [$barangId, $supplierId];
    $urut = '';
    if ($pilihSatu !== null) {
        $urut = '(nomor_batch = ?) DESC, ';
        $p[]  = $pilihSatu;
    }

    $st = $pdo->prepare(
        "SELECT id, nomor_batch, kondisi, sumber, donatur_id, tanggal_expired,
                qty, harga, lokasi_rak_id
         FROM stok_supplier
         WHERE barang_id = ? AND supplier_id = ? AND qty > 0
         ORDER BY $urut(tanggal_expired IS NULL), tanggal_expired ASC, id ASC
         FOR UPDATE"
    );
    $st->execute($p);

    $sisa  = $qty;
    $hasil = [];
    foreach ($st->fetchAll() as $b) {
        if ($sisa <= 0) { break; }
        $ambil = min($sisa, (int) $b['qty']);
        $hasil[] = [
            'batch_id'        => (int) $b['id'],   // id baris stok_supplier
            'nomor_batch'     => $b['nomor_batch'],
            'kondisi'         => $b['kondisi'] ?? 'baru',
            'sumber'          => $b['sumber'] ?? 'pembelian',
            'donatur_id'      => $b['donatur_id'] !== null ? (int) $b['donatur_id'] : null,
            'tanggal_expired' => $b['tanggal_expired'],
            'lokasi_rak_id'   => $b['lokasi_rak_id'] ? (int) $b['lokasi_rak_id'] : null,
            'toko_id'         => null,
            'harga_beli'      => (float) $b['harga'],
            'supplier_id'     => $supplierId,
            'ambil'           => $ambil,
        ];
        $sisa -= $ambil;
    }

    if ($sisa > 0) {
        $tersedia = $qty - $sisa;
        throw new RuntimeException(
            "Stok mitra tidak mencukupi. Diminta $qty unit, tersedia hanya $tersedia unit.");
    }
    return $hasil;
}

/**
 * Tambahkan qty ke sebuah rak, digabung ke batch yang sama bila sudah ada.
 * Dipakai saat penerimaan dan saat sisi-tujuan sebuah perpindahan.
 *
 * PENTING — tanggal kedaluwarsa ikut menjadi kunci pencocokan.
 * Sebelumnya pencocokan hanya (barang + nomor batch + rak) dengan
 * `tanggal_expired = COALESCE(tanggal_expired, ?)`, sehingga bila dua rak
 * kebetulan memakai nomor batch sama dengan tanggal kedaluwarsa berbeda,
 * memindahkan barang akan MENIMPA tanggal kedaluwarsanya secara diam-diam.
 * Untuk lembaga yang menyalurkan susu formula & pangan bayi, itu berisiko
 * menyalurkan barang yang sebenarnya sudah dekat kedaluwarsa.
 */
function tambah_ke_lokasi(PDO $pdo, int $barangId, ?int $rakId, ?int $tokoId,
                         string $nomorBatch, ?string $expired,
                         int $qty, float $hargaBeli = 0, ?int $supplierId = null,
                         string $kondisi = 'baru', string $sumber = 'pembelian',
                         ?int $donaturId = null, ?int $kardusId = null): int {
    periksa_qty_wajar($qty);   // lihat BATAS JUMLAH WAJAR
    /* kardus_id ikut identitas batch (fitur kardus, Okt 2026): batch lepas dan
       batch dalam kardus dengan identitas sama hidup berdampingan. Sebelum
       migrasinya dijalankan kolomnya belum ada — pencocokan tanpa kardus. */
    $adaKardus = kolom_ada($pdo, 'batch_barang', 'kardus_id');
    if ($kardusId !== null && !$adaKardus) { throw new RuntimeException('Fitur kardus belum aktif: migrasi basis data belum dijalankan.'); }
    // Kondisi, sumber, dan donatur ikut jadi bagian identitas batch. Tanpa
    // itu, satu dus popok BARU dan satu dus popok BEKAS bernomor batch sama
    // menyatu di baris yang sama dan perbedaannya hilang tanpa pesan galat —
    // begitu juga titipan dari dua donatur berbeda, yang kehilangan
    // atribusinya justru saat atribusi itu dibutuhkan.
    $kondisi = kondisi_sah($kondisi);
    $sumber  = sumber_sah($sumber);
    /* Tanggal kedaluwarsa dibersihkan DI SINI, di pintu masuk tunggal ke
       batch_barang. Dulu diteruskan apa adanya, dan `0001-01-01` lolos ke
       basis data tanpa satu pun keluhan — lihat tanggal_expired_sah().

       Yang tidak masuk akal jadi null, bukan ditolak dengan galat: barang
       fisiknya sudah tiba, dan menolak seluruh penerimaan karena satu kolom
       tanggal salah ketik membuat petugas kehilangan seluruh pekerjaannya.
       Batchnya masuk tanpa tanggal, lalu muncul di daftar "Belum Punya
       Tanggal Kedaluwarsa" untuk dibetulkan. */
    $expired = tanggal_expired_sah($expired);
    // Barang yang punya donatur pasti donasi, apa pun yang dikirim pemanggil.
    if ($donaturId !== null) { $sumber = 'donasi'; }
    if ($rakId === null && $tokoId === null) {
        throw new RuntimeException(
            'Lokasi tujuan tidak dapat ditentukan. Pastikan gudang tujuan sudah memiliki '
          . 'minimal satu rak, atau pilih rak / toko tujuan secara langsung.'
        );
    }
    if ($rakId !== null && $tokoId !== null) {
        throw new RuntimeException('Satu batch tidak boleh berada di rak dan toko sekaligus.');
    }

    // Pencocokan HARUS memakai kolom yang sama persis dengan kunci unik
    // uq_batch_lokasi. Kalau ada kolom kunci yang tidak ikut dicocokkan di
    // sini, SELECT tidak menemukan baris yang sebenarnya ada, lalu INSERT di
    // bawah menabrak kunci unik itu dan pengguna melihat galat mentah.
    // <=> dipakai, bukan =, supaya NULL cocok dengan NULL.
    $st = $pdo->prepare(
        "SELECT id FROM batch_barang
         WHERE barang_id = ? AND nomor_batch = ? AND kondisi = ? AND sumber = ?
           AND donatur_id <=> ?
           AND lokasi_rak_id <=> ? AND toko_id <=> ? AND tanggal_expired <=> ?" . ($adaKardus ? ' AND kardus_id <=> ?' : '') . "
         LIMIT 1"
    );
    $st->execute(array_merge([$barangId, $nomorBatch, $kondisi, $sumber, $donaturId,
                  $rakId, $tokoId, $expired], $adaKardus ? [$kardusId] : []));
    $batchId = $st->fetchColumn();

    if ($batchId) {
        $pdo->prepare(
            "UPDATE batch_barang
             SET qty_awal    = qty_awal + ?,
                 qty_tersisa = qty_tersisa + ?,
                 harga_beli  = IF(harga_beli = 0, ?, harga_beli),
                 supplier_id = COALESCE(supplier_id, ?)
             WHERE id = ?"
        )->execute([$qty, $qty, $hargaBeli, $supplierId, $batchId]);
        return (int) $batchId;
    }

    $pdo->prepare(
        "INSERT INTO batch_barang
           (barang_id, nomor_batch, kondisi, sumber, donatur_id,
            lokasi_rak_id, toko_id, tanggal_masuk, tanggal_expired,
            qty_awal, qty_tersisa, harga_beli, supplier_id" . ($adaKardus ? ', kardus_id' : '') . ")
         VALUES (?,?,?,?,?,?,?,CURDATE(),?,?,?,?,?" . ($adaKardus ? ',?' : '') . ")"
    )->execute(array_merge([$barangId, $nomorBatch, $kondisi, $sumber, $donaturId,
                $rakId, $tokoId, $expired,
                $qty, $qty, $hargaBeli, $supplierId], $adaKardus ? [$kardusId] : []));

    return (int) $pdo->lastInsertId();
}

/**
 * Pratinjau FEFO — batch mana YANG AKAN diambil, tanpa memotong stok.
 *
 * Dipakai saat surat jalan diterbitkan untuk pengiriman yang stoknya baru
 * berpindah ketika barang diterima (mis. gudang → toko).
 *
 * Masalah yang diperbaiki: pada alur itu, batch baru ditentukan saat
 * penerimaan. Akibatnya surat jalan tercetak dengan kolom No. Batch dan
 * Expired berisi "—" — padahal justru itu yang harus diperiksa penerima,
 * dan untuk susu formula bayi itu bukan detail administratif.
 *
 * Berbeda dari ambil_fefo(): TIDAK memakai FOR UPDATE dan tidak mengubah
 * apa pun. Hasilnya perkiraan yang benar pada saat dicetak; batch yang
 * benar-benar terpotong tetap ditentukan ulang saat penerimaan, dan
 * layar penerimaan menampilkan yang sesungguhnya.
 */
function pratinjau_fefo(PDO $pdo, int $barangId, int $qty, ?int $rakId = null,
                        ?int $gudangId = null, ?int $tokoId = null,
                        bool $hanyaLayak = false, ?string $batchPilih = null): array {
    /* Kuerinya dari fefo_sql() — SATU pembangun yang sama dengan
       ambil_fefo(). Dulu ditulis ulang di sini dan sudah sempat menyimpang:
       saringan kondisi layak dan batch pilihan tidak ikut, sehingga batch
       yang tercetak di surat jalan bisa berbeda dari yang benar-benar
       dipotong. Bedanya kini hanya FOR UPDATE, yang memang tidak boleh ada
       pada pratinjau. */
    [$sql, $p] = fefo_sql("bb.nomor_batch, bb.kondisi, bb.tanggal_expired, bb.qty_tersisa",
                          $barangId, $rakId, $gudangId, $tokoId, $hanyaLayak, $batchPilih, sql_tanpa_kardus($pdo));

    try {
        $st = $pdo->prepare($sql);
        $st->execute($p);
        $batches = $st->fetchAll();
    } catch (Throwable $e) {
        error_log('[AMANAH] pratinjau_fefo: ' . $e->getMessage());
        return [];
    }

    $sisa = $qty;
    $hasil = [];
    foreach ($batches as $b) {
        if ($sisa <= 0) { break; }
        $ambil = min($sisa, (int) $b['qty_tersisa']);
        $hasil[] = [
            'nomor_batch'     => $b['nomor_batch'],
            'kondisi'         => $b['kondisi'] ?? 'baru',
            'tanggal_expired' => $b['tanggal_expired'],
            'ambil'           => $ambil,
        ];
        $sisa -= $ambil;
    }
    return $hasil;
}

/**
 * Isi kolom batch & expired pada baris dokumen dari pratinjau FEFO.
 *
 * Hanya mengisi baris yang batch-nya MASIH KOSONG — kalau pembuat dokumen
 * sudah menyebut nomor batch tertentu, pilihannya tidak boleh ditimpa.
 */
function catat_batch_pratinjau(PDO $pdo, array $pp, array $detail): void {
    $rakAsal    = ($pp['asal_tipe'] === 'rak')    ? (int) $pp['asal_id'] : null;
    $gudangAsal = ($pp['asal_tipe'] === 'gudang') ? (int) $pp['asal_id'] : null;
    $tokoAsal   = ($pp['asal_tipe'] === 'toko')   ? (int) $pp['asal_id'] : null;

    $upd = $pdo->prepare(
        "UPDATE perpindahan_detail
         SET nomor_batch = ?, tanggal_expired = ?
         WHERE id = ? AND (nomor_batch IS NULL OR nomor_batch = '')"
    );

    foreach ($detail as $d) {
        if (!empty($d['nomor_batch'])) { continue; }

        $pilih = pratinjau_fefo($pdo, (int) $d['barang_id'], (int) $d['qty'],
                                $rakAsal, $gudangAsal, $tokoAsal, jenis_hanya_layak($pp['jenis']));
        if (!$pilih) { continue; }

        // Beberapa batch dalam satu baris ditulis dipisah koma; kolomnya
        // VARCHAR(50) jadi dipotong aman. Tanggal yang dipakai adalah yang
        // PALING DEKAT kedaluwarsa — itu yang menentukan urgensi penyaluran.
        $nomor = mb_substr(implode(', ', array_column($pilih, 'nomor_batch')), 0, 50);
        $exp   = null;
        foreach ($pilih as $b) {
            if ($b['tanggal_expired'] && ($exp === null || $b['tanggal_expired'] < $exp)) {
                $exp = $b['tanggal_expired'];
            }
        }
        $upd->execute([$nomor, $exp, (int) $d['id']]);
    }
}

/**
 * Label, warna, dan ikon kondisi barang.
 *
 * Isinya dibaca dari KONDISI_BARANG, bukan ditulis ulang di sini. Versi
 * sebelumnya hanya punya dua cabang ('bekas' atau bukan), jadi nilai ketiga
 * akan tampil sebagai "Baru" berwarna hijau — kebalikan dari maksudnya.
 */
function label_kondisi(?string $kondisi): array {
    [$label, $kelas, $ikon] = KONDISI_BARANG[kondisi_sah($kondisi)];
    return [$label, $kelas, $ikon];
}

/** Label pendek untuk tempat sempit seperti sel tabel dan surat jalan. */
function label_kondisi_pendek(?string $kondisi): string {
    return [
        'baru'              => 'Baru',
        'bekas_layak'       => 'Layak',
        'rusak'             => 'Rusak',
        'expired'           => 'Expired',
        'bekas_tidak_layak' => 'Tidak layak',
    ][kondisi_sah($kondisi)];
}

/** Label, warna, dan ikon sumber barang. */
function label_sumber(?string $sumber): array {
    return SUMBER_BARANG[sumber_sah($sumber)];
}

/** Pembungkus lama — tetap dipakai halaman yang hanya berurusan dengan rak. */
function tambah_ke_rak(PDO $pdo, int $barangId, ?int $rakId, string $nomorBatch, ?string $expired,
                       int $qty, float $hargaBeli = 0, ?int $supplierId = null,
                       string $kondisi = 'baru', string $sumber = 'pembelian',
                       ?int $donaturId = null): int {
    return tambah_ke_lokasi($pdo, $barangId, $rakId, null, $nomorBatch, $expired,
                            $qty, $hargaBeli, $supplierId, $kondisi, $sumber, $donaturId);
}

// ---------------------------------------------------------------------
// Mesin perpindahan barang
// ---------------------------------------------------------------------
/**
 * Tentukan rak tujuan konkret sebuah perpindahan.
 *
 * Bila tujuannya sebuah gudang (bukan rak spesifik), pilih rak dengan
 * ruang kosong terbanyak di gudang tersebut — supaya barang tidak
 * "menggantung" tanpa lokasi dan tetap terhitung pada stok per gudang.
 */
function rak_tujuan(PDO $pdo, string $tujuanTipe, ?int $tujuanId, ?int $rakAsal = null): ?int {
    if ($tujuanTipe === 'rak') {
        if ($rakAsal !== null && $tujuanId !== null && $rakAsal === $tujuanId) {
            throw new RuntimeException('Rak asal dan rak tujuan tidak boleh sama.');
        }
        return $tujuanId;
    }
    if ($tujuanTipe !== 'gudang' || !$tujuanId) return null;

    // Rak asal dikecualikan. Tanpa ini, perpindahan "rak → gudang" bisa
    // memilih rak asalnya sendiri sebagai tujuan: barang tidak benar-benar
    // berpindah, qty_awal ikut membengkak, tapi surat jalan tetap terbit.
    $st = $pdo->prepare(
        "SELECT lr.id
         FROM lokasi_rak lr
         LEFT JOIN (SELECT lokasi_rak_id, SUM(qty_tersisa) terisi
                    FROM batch_barang WHERE qty_tersisa > 0 GROUP BY lokasi_rak_id) x
                ON x.lokasi_rak_id = lr.id
         WHERE lr.gudang_id = ? AND lr.deleted_at IS NULL AND (? IS NULL OR lr.id <> ?)
         ORDER BY (COALESCE(lr.kapasitas, 999999) - COALESCE(x.terisi, 0)) DESC, lr.kode ASC
         LIMIT 1"
    );
    $st->execute([$tujuanId, $rakAsal, $rakAsal]);
    $rak = $st->fetchColumn();

    if (!$rak) {
        throw new RuntimeException(
            'Gudang tujuan belum memiliki rak yang bisa dipakai. Tambahkan minimal satu rak '
          . 'di gudang tersebut lebih dulu (selain rak asal).'
        );
    }
    return (int) $rak;
}

/**
 * Rute yang sah untuk tiap jenis perpindahan.
 *
 * Dipakai DI SISI SERVER, bukan hanya di JavaScript form. Tanpa ini, POST
 * yang dimanipulasi bisa mengaku "penerimaan" dengan asal sebuah gudang —
 * dan stok akan tercipta di tujuan tanpa pernah dipotong dari asalnya.
 */
function rute_sah(): array {
    return [
        // Supplier → Gudang
        'penerimaan'      => ['asal' => ['supplier', 'eksternal'], 'tujuan' => ['rak', 'gudang']],
        // Di dalam / antar gudang
        'transfer_gudang' => ['asal' => ['gudang', 'rak'],         'tujuan' => ['rak', 'gudang']],
        'mutasi_rak'      => ['asal' => ['rak'],                   'tujuan' => ['rak']],
        // Gudang → Toko dan sebaliknya
        'kirim_toko'      => ['asal' => ['gudang', 'rak'],         'tujuan' => ['toko']],
        'retur_toko'      => ['asal' => ['toko'],                  'tujuan' => ['rak', 'gudang']],
        'antar_toko'      => ['asal' => ['toko'],                  'tujuan' => ['toko']],
        /* Keluar dari rantai.

           'supplier' termasuk asal yang sah: mitra kadang menyerahkan barang
           LANGSUNG kepada penerima amanah tanpa singgah di gudang — kunjungan
           ke lokasi penerima, atau paket yang diantar sendiri. Sebelumnya
           kejadian itu tidak punya tempat di sistem, jadi barangnya entah
           dicatat seolah lewat gudang (padahal tidak pernah sampai di sana),
           atau tidak dicatat sama sekali.

           Stoknya diambil dari stok_supplier, bukan batch gudang — lihat
           percabangan $dariMitra di terapkan_perpindahan(). */
        'penyaluran'      => ['asal' => ['gudang', 'rak', 'toko', 'supplier'], 'tujuan' => ['penerima']],
        'retur'           => ['asal' => ['gudang', 'rak'],         'tujuan' => ['supplier']],
    ];
}

/**
 * Apakah jenis perpindahan ini punya PIHAK PENERIMA yang memverifikasi fisik?
 *
 * Ini menentukan DI MANA stok diterapkan — dan hanya boleh di SATU tempat:
 *
 *   true  → persetujuan hanya menerbitkan surat jalan (status 'dikirim').
 *           Stok berpindah nanti, saat penerimaan.php memverifikasi fisiknya.
 *   false → tidak ada yang menerima di sistem (barang keluar ke penerima
 *           manfaat, retur ke supplier, atau sekadar geser rak), jadi stok
 *           diterapkan langsung saat disetujui (status 'selesai').
 *
 * Tanpa pemisahan ini, dokumen yang sama bisa diterapkan dua kali —
 * sekali saat disetujui, sekali saat "diterima" — dan stok berlipat ganda.
 */
function butuh_penerimaan(string $jenis): bool {
    return in_array($jenis, [
        'penerimaan',       // supplier → gudang
        'kirim_toko',       // gudang   → toko
        'retur_toko',       // toko     → gudang
        'antar_toko',       // toko     → toko
        'transfer_gudang',  // gudang   → gudang
    ], true);
}

/**
 * Jenis perpindahan yang TIDAK perlu persetujuan siapa pun.
 *
 * Hanya mutasi rak. Barangnya tidak berpindah pemilik, tidak keluar dari
 * gudang, dan tidak berubah jumlah — yang berubah cuma tempatnya di dalam
 * satu gudang yang sama. Meminta Pimpinan menyetujui pemindahan kardus dari
 * rak susu ke rak obat berarti pekerjaan harian petugas berhenti menunggu
 * orang yang tidak punya alasan untuk menolaknya.
 *
 * Akibat nyata sebelum ini ada: satu-satunya tombol di formulir pindah rak
 * adalah "Kirim untuk Persetujuan" dan "Simpan sebagai Draft". Tidak ada
 * cara untuk sekadar memindahkannya, sehingga letak barang di layar
 * menyimpang dari letak sebenarnya di gudang sampai ada yang menyetujui.
 *
 * Jenis lain TETAP lewat persetujuan: begitu barang menyeberang antar
 * gudang, ke toko, ke penerima, atau kembali ke supplier, ada dua pihak
 * yang catatannya harus cocok — dan di situlah persetujuan berguna.
 */
function langsung_sah(string $jenis): bool {
    return $jenis === 'mutasi_rak';
}

/**
 * Jenis perpindahan yang boleh DIBUAT peran yang sedang login.
 *
 * SATU daftar, dipakai penjaga di _perpindahan_form.php sekaligus dropdown
 * di _perpindahan_isian.php. Sebelumnya penjaganya menolak tujuh jenis untuk
 * toko, tapi dropdownnya tetap menawarkan kesembilannya — jadi petugas toko
 * bisa memilih "Penyaluran", mengisi seluruh keranjang, menekan simpan, lalu
 * ditolak 403 setelah semua kerjanya selesai.
 *
 * Toko hanya mengurus gudang penampungnya sendiri: memindahkan barang antar
 * raknya, dan mengirimkannya ke gudang lembaga. Penerimaan dari supplier,
 * penyaluran ke penerima amanah, dan retur adalah alur sisi gudang lembaga,
 * dan toko punya jalurnya sendiri di dasbornya.
 */
function jenis_boleh_peran(): array {
    if (is_toko()) { return ['mutasi_rak', 'transfer_gudang']; }
    return ['penerimaan', 'kirim_toko', 'retur_toko', 'antar_toko',
            'transfer_gudang', 'mutasi_rak', 'penyaluran', 'retur'];
}

/**
 * Sahkan sebuah dokumen perpindahan: terapkan stoknya dan terbitkan
 * nomor surat jalannya.
 *
 * SATU tempat untuk dua pemanggil — persetujuan.php (disetujui Pimpinan)
 * dan _perpindahan_form.php (mutasi rak, langsung sah saat dibuat).
 * Ditulis dua kali, cepat atau lambat salah satunya akan lupa menerapkan
 * stok atau lupa menerbitkan nomor, dan bedanya baru ketahuan saat angka
 * stok tidak cocok dengan fisiknya.
 *
 * Pemanggil bertanggung jawab membuka transaksi. Mengembalikan
 * ['no_sj' => string, 'status' => string, 'ada_penerima' => bool].
 */
function sahkan_perpindahan(PDO $pdo, int $ppId, ?int $olehUserId): array {
    // Dikunci supaya dokumen yang sama tidak disahkan dua kali bersamaan —
    // dua kali penerapan berarti stok berpindah dua kali.
    $st = $pdo->prepare("SELECT * FROM perpindahan WHERE id = ? FOR UPDATE");
    $st->execute([$ppId]);
    $pp = $st->fetch();
    if (!$pp) { throw new RuntimeException('Dokumen tidak ditemukan.'); }

    if (!in_array($pp['status'], ['menunggu_persetujuan', 'draft'], true)) {
        throw new RuntimeException('Dokumen sudah diproses sebelumnya (status: ' . $pp['status'] . ').');
    }

    $stD = $pdo->prepare("SELECT * FROM perpindahan_detail WHERE perpindahan_id = ?");
    $stD->execute([$ppId]);
    $detail = $stD->fetchAll();
    if (!$detail) { throw new RuntimeException('Dokumen tidak memiliki rincian barang.'); }

    /* Stok cukup? (revisi 18 Okt 2026) — dihitung terhadap stok lokasi asal
       dikurangi yang sudah dipesan dokumen lain yang SUDAH disetujui tetapi
       belum diterima (status dikirim). Yang menunggu tidak ikut dihitung,
       supaya dua dokumen yang menunggu tidak saling mengunci: yang disetujui
       lebih dulu mendapat stoknya, yang berikutnya ditolak dengan rincian
       barang yang kurang — bukan baru ketahuan saat penerimaan. */
    $kurang = periksa_stok_dokumen($pdo, (string) $pp['jenis'], (string) $pp['asal_tipe'], $pp['asal_id'] ? (int) $pp['asal_id'] : null,
                                   $detail, ['dikirim'], $ppId);
    if ($kurang) {
        throw new RuntimeException('Stok tidak cukup untuk menyetujui dokumen ini: ' . implode(' • ', $kurang));
    }

    /* Stok hanya diterapkan di sini bila dokumen ini tidak punya pihak
       penerima. Untuk yang punya, pengesahan cuma menerbitkan surat jalan;
       stok berpindah nanti saat penerimaan fisiknya diverifikasi.
       Menerapkannya di kedua tempat akan menggandakan stok. */
    $adaPenerima = butuh_penerimaan($pp['jenis']);
    if (!$adaPenerima) {
        terapkan_perpindahan($pdo, $pp, $detail);
    } else {
        catat_batch_pratinjau($pdo, $pp, $detail);
    }
    $statusBaru = $adaPenerima ? 'dikirim' : 'selesai';

    /* Nomor surat jalan diulang bila bentrok. Dua orang yang mengesahkan
       dokumen BERBEDA pada detik yang sama sebelumnya membuat salah satunya
       gagal total — seluruh kerja pemindahan stok terbuang hanya karena
       rebutan nomor. */
    $upd = $pdo->prepare(
        "UPDATE perpindahan
         SET status = ?, no_surat_jalan = ?, approved_by = ?, approved_at = NOW()
         WHERE id = ?"
    );
    $noSJ = null;
    for ($coba = 0; $coba < 5; $coba++) {
        $kandidat = nomor_dokumen($pdo, 'perpindahan', 'no_surat_jalan', 'SJ');
        try {
            $upd->execute([$statusBaru, $kandidat, $olehUserId, $ppId]);
            $noSJ = $kandidat;
            break;
        } catch (PDOException $ex) {
            // InnoDB membatalkan pernyataannya saja, bukan seluruh transaksi,
            // jadi aman mencoba nomor berikutnya.
            if ($ex->getCode() === '23000' && str_contains($ex->getMessage(), $kandidat)) {
                usleep(random_int(5000, 40000));
                continue;
            }
            throw $ex;
        }
    }
    if ($noSJ === null) {
        throw new RuntimeException('Nomor surat jalan sedang berebut dipakai. Coba lagi sesaat lagi.');
    }
    // Kardus yang dibawa dokumen ini ikut berangkat / langsung diterima (fitur kardus).
    kardus_sesudah_sah($pdo, $ppId, $adaPenerima, $olehUserId);

    return ['no_sj' => $noSJ, 'status' => $statusBaru, 'ada_penerima' => $adaPenerima,
            'no_perpindahan' => $pp['no_perpindahan']];
}

/** Kembalikan pesan kesalahan bila kombinasi jenis/asal/tujuan tidak sah. */
function periksa_rute(string $jenis, string $asalTipe, string $tujuanTipe): ?string {
    $rute = rute_sah();
    if (!isset($rute[$jenis])) return 'Jenis perpindahan tidak dikenal.';

    if (!in_array($asalTipe, $rute[$jenis]['asal'], true)) {
        return "Asal \"$asalTipe\" tidak sah untuk jenis \"$jenis\".";
    }
    if (!in_array($tujuanTipe, $rute[$jenis]['tujuan'], true)) {
        return "Tujuan \"$tujuanTipe\" tidak sah untuk jenis \"$jenis\".";
    }
    return null;
}

/**
 * Terapkan efek stok sebuah perpindahan yang DISETUJUI.
 *
 * Harus dipanggil di dalam transaksi. Melempar exception bila stok tidak
 * mencukupi — pemanggil wajib melakukan rollBack().
 *
 * Mengembalikan ringkasan batch yang terpakai per baris detail.
 */
function terapkan_perpindahan(PDO $pdo, array $pp, array $detail): array {
    $jenis      = $pp['jenis'];
    $asalTipe   = $pp['asal_tipe'];
    $asalId     = $pp['asal_id'] ? (int) $pp['asal_id'] : null;
    $tujuanTipe = $pp['tujuan_tipe'];
    $tujuanId   = $pp['tujuan_id'] ? (int) $pp['tujuan_id'] : null;

    // Pertahanan berlapis: walau form sudah memvalidasi, dokumen bisa saja
    // dibuat versi lama / POST yang dimanipulasi. Menolak di sini memastikan
    // tidak ada stok yang tercipta atau menguap gara-gara rute tidak masuk akal.
    if ($pesan = periksa_rute($jenis, $asalTipe, $tujuanTipe)) {
        throw new RuntimeException(
            "Dokumen ini tidak dapat diproses: $pesan Batalkan dokumen dan buat ulang dengan rute yang benar."
        );
    }

    $masuk  = ($jenis === 'penerimaan');
    // Semua jenis yang MEMINDAHKAN barang antar lokasi di dalam rantai
    // (gudang ↔ gudang, gudang ↔ toko, toko ↔ toko).
    $pindah = in_array($jenis, ['transfer_gudang', 'mutasi_rak', 'kirim_toko', 'retur_toko', 'antar_toko'], true);

    $rakAsal    = ($asalTipe === 'rak')    ? $asalId : null;
    $gudangAsal = ($asalTipe === 'gudang') ? $asalId : null;
    $tokoAsal   = ($asalTipe === 'toko')   ? $asalId : null;

    // Untuk perpindahan yang menaruh barang, lokasi tujuan WAJIB terselesaikan
    // — entah sebuah rak gudang atau sebuah toko. tambah_ke_lokasi() melempar
    // bila keduanya kosong, sehingga stok tidak pernah berakhir pada batch
    // tanpa lokasi (yang akan hilang dari seluruh laporan).
    $tokoTujuan = ($tujuanTipe === 'toko') ? $tujuanId : null;
    $rakTujuan  = ($tokoTujuan === null && ($masuk || $pindah))
                    ? rak_tujuan($pdo, $tujuanTipe, $tujuanId, $rakAsal)
                    : null;

    if (($masuk || $pindah) && $rakTujuan === null && $tokoTujuan === null) {
        throw new RuntimeException('Lokasi tujuan perpindahan tidak dapat ditentukan.');
    }

    $supplierId = ($asalTipe === 'supplier') ? $asalId : null;

    // Kunci seluruh barang yang tersentuh SEBELUM menyentuh batch apa pun.
    kunci_stok($pdo, array_map(fn($d) => (int) $d['barang_id'], $detail));

    $ringkasan = [];

    foreach ($detail as $d) {
        $barangId = (int) $d['barang_id'];

        // Jumlah yang BENAR-BENAR diterima menang atas jumlah yang dikirim.
        // Petugas gudang/toko sering menemukan selisih fisik (kurang, rusak,
        // atau lebih); yang masuk ke stok harus angka hasil hitung fisik itu,
        // bukan angka di surat jalan.
        $qty = (isset($d['qty_diterima']) && $d['qty_diterima'] !== null)
             ? (int) $d['qty_diterima']
             : (int) $d['qty'];

        if ($qty <= 0) continue;

        if ($masuk) {
            // Barang baru masuk — buat / gabungkan batch di lokasi tujuan.
            $batchId = tambah_ke_lokasi(
                $pdo, $barangId, $rakTujuan, $tokoTujuan,
                $d['nomor_batch'] ?: ('B' . date('ymd')),
                $d['tanggal_expired'] ?: null,
                $qty, (float) ($d['harga'] ?? 0), $supplierId,
                // Ketiganya mengikuti baris dokumennya. Kondisi dinilai
                // supplier saat mencatat barang, dan gudang meneruskannya
                // apa adanya — di sinilah "gudang tidak menilai ulang"
                // benar-benar terjadi.
                $d['kondisi'] ?? 'baru',
                $d['sumber'] ?? 'pembelian',
                isset($d['donatur_id']) && $d['donatur_id'] !== null ? (int) $d['donatur_id'] : null
            );
            $ringkasan[(int) $d['id']] = [
                'batch_id' => $batchId,
                'teks'     => ($d['nomor_batch'] ?: 'B' . date('ymd')) . " ($qty)",
            ];
            // Jejak untuk pembalikan bila dokumen ini kelak diubah (_perpindahan_ubah.php).
            catat_mutasi_perpindahan($pdo, (int) $pp['id'], (int) $d['id'], $barangId, null, $batchId, $qty);

        } else {
            /* Ambil stok dari lokasi asal secara FEFO.

               Asal 'supplier' dibaca dari tabel yang BERBEDA: stok mitra ada
               di stok_supplier, bukan batch_barang. Tanpa percabangan ini,
               ambil_fefo() dipanggil tanpa pembatas lokasi dan memotong batch
               gudang mana pun yang kebetulan berisi barang itu — stok gudang
               berkurang untuk barang yang tidak pernah ada di sana. */
            $dariMitra = ($asalTipe === 'supplier');

            /* Nomor batch pada baris dokumen ikut dikirim sebagai PILIHAN.
               Kolom itu bisa berisi dua hal yang berbeda: batch yang memang
               dipilih orang di formulir, atau daftar batch hasil pratinjau
               FEFO yang ditulis catat_batch_pratinjau() dipisah koma. Yang
               kedua sengaja tidak dianggap pilihan — ia hanya cerminan FEFO
               itu sendiri, dan ambil_fefo() sudah menolak nilai berkoma. */
            $batchDiminta = trim((string) ($d['nomor_batch'] ?? '')) ?: null;

            /* Baris berkardus (revisi 12 Okt 2026) diperlakukan sama dengan baris
               lain: stok dipotong FEFO di sini — kardus hanyalah pengelompokan. */
            /* Baris isi kardus yang sudah menunggu di rak siap kirim (dikemas lewat
               mutasi rak) diambil dari rak kardusnya bila rak itu berada di lokasi asal. */
            $rakBaris = !empty($d['kardus_id']) ? kardus_rak_ambil($pdo, (int) $d['kardus_id'], $gudangAsal, $rakAsal) : null;
            try {
                $diambil = ($dariMitra
                    ? ambil_fefo_supplier($pdo, $barangId, $qty, (int) $supplierId, $batchDiminta)
                    /* Penyaluran & kirim toko hanya memotong stok siap salur (butir 5);
                       tanpa ini FEFO justru mengambil batch expired lebih dulu. */
                    : ambil_fefo($pdo, $barangId, $qty, $rakBaris ?? $rakAsal, $rakBaris ? null : $gudangAsal, $tokoAsal,
                                 jenis_hanya_layak($jenis), $batchDiminta));
            } catch (RuntimeException $eF) {
                /* Pesan FEFO tidak menyebut barangnya — dengan puluhan baris, tim tidak
                   tahu barang mana yang kurang (revisi 18 Okt 2026). */
                $stNm = $pdo->prepare("SELECT nama, kode FROM barang WHERE id = ?"); $stNm->execute([$barangId]);
                $nmB = $stNm->fetch() ?: ['nama' => "Barang #$barangId", 'kode' => ''];
                throw new RuntimeException($nmB['nama'] . ($nmB['kode'] ? ' (' . $nmB['kode'] . ')' : '') . ' — ' . $eF->getMessage());
            }

            $potong = $pdo->prepare($dariMitra
                ? "UPDATE stok_supplier SET qty = qty - ? WHERE id = ?"
                : "UPDATE batch_barang SET qty_tersisa = qty_tersisa - ? WHERE id = ?");
            $teks = [];

            foreach ($diambil as $a) {
                // Batch yang KEBETULAN sudah berada di rak tujuan dilewati saja,
                // bukan membatalkan dokumen. Ini terjadi wajar pada konsolidasi
                // "gudang → rak": FEFO memindai semua rak di gudang asal,
                // termasuk rak tujuan itu sendiri. Memotong lalu menambahkannya
                // kembali hanya akan merusak qty_awal tanpa barang berpindah.
                $sudahDiTujuan = $pindah
                    && (($rakTujuan  !== null && $a['lokasi_rak_id'] === $rakTujuan)
                     || ($tokoTujuan !== null && $a['toko_id']       === $tokoTujuan));

                if ($sudahDiTujuan) {
                    $teks[] = $a['nomor_batch'] . ' (' . $a['ambil'] . ', sudah di lokasi tujuan)';
                    continue;
                }

                $potong->execute([$a['ambil'], $a['batch_id']]);
                $teks[] = $a['nomor_batch'] . ' (' . $a['ambil'] . ')';
                $batchTujuanBaru = null;

                // Untuk semua jenis pemindahan, jumlah yang sama ditambahkan di
                // lokasi tujuan — lengkap dengan harga beli & supplier aslinya,
                // supaya jejak harga perolehan dan asal pemasok tidak hilang
                // setiap kali barang berpindah gudang atau masuk ke toko.
                if ($pindah) {
                    $batchTujuanBaru = tambah_ke_lokasi(
                        $pdo, $barangId, $rakTujuan, $tokoTujuan,
                        $a['nomor_batch'], $a['tanggal_expired'],
                        $a['ambil'], $a['harga_beli'], $a['supplier_id'],
                        // Barang bekas tetap bekas setelah pindah gudang, dan
                        // barang donasi tetap donasi. Tanpa ini keduanya kembali
                        // ke nilai bawaan kolom, donasi tercatat sebagai barang
                        // beli, dan jejak donaturnya hilang di perpindahan
                        // pertama — merusak nilai persediaan sekaligus laporan
                        // asal barang.
                        $a['kondisi'] ?? 'baru',
                        $a['sumber'] ?? 'pembelian',
                        $a['donatur_id'] ?? null
                    );
                }
                // Jejak untuk pembalikan. Stok mitra (stok_supplier) tidak dijejak:
                // barisnya bisa terhapus saat habis, jadi tidak bisa dibalikkan.
                if (!$dariMitra) {
                    catat_mutasi_perpindahan($pdo, (int) $pp['id'], (int) $d['id'], $barangId,
                                             (int) $a['batch_id'], $batchTujuanBaru, (int) $a['ambil']);
                }
            }

            /* batch_id DIKOSONGKAN untuk asal mitra.
               perpindahan_detail.batch_id menunjuk batch_barang, sedangkan
               yang barusan dipotong adalah baris stok_supplier. Menuliskan
               id-nya ke sana akan menunjuk batch gudang yang tidak ada
               hubungannya — dan bila id itu kebetulan ada, jejaknya menunjuk
               barang milik orang lain tanpa satu pun galat muncul.
               Nomor batchnya tetap tercatat sebagai teks di bawah. */
            $ringkasan[(int) $d['id']] = [
                'batch_id' => $dariMitra ? null : ($diambil[0]['batch_id'] ?? null),
                'teks'     => implode(', ', $teks),
            ];

            /* Baris stok mitra yang sudah habis dibersihkan, mengikuti
               perilaku penerbitan surat jalan di dasbor supplier.

               SELURUH baris yang tersentuh, bukan hanya yang pertama: FEFO
               kerap mengambil dari beberapa batch sekaligus, dan baris nol
               yang tertinggal akan terus muncul di daftar Siap Kirim sebagai
               barang yang tidak pernah bisa dikirim. */
            if ($dariMitra) {
                $hapus = $pdo->prepare("DELETE FROM stok_supplier WHERE id = ? AND qty <= 0");
                foreach ($diambil as $a) { $hapus->execute([$a['batch_id']]); }
            }
        }

        recalc_stok($pdo, $barangId);
    }

    // Catat jejak per baris: batch mana saja yang benar-benar terpakai.
    // Rinciannya masuk ke `keterangan` (VARCHAR 255), bukan `nomor_batch`
    // (VARCHAR 50) — dokumen yang menyentuh banyak batch dulu terpotong diam-diam.
    $upd = $pdo->prepare(
        "UPDATE perpindahan_detail
         SET batch_id = ?,
             keterangan = LEFT(
               CONCAT(IF(COALESCE(keterangan,'') = '', '', CONCAT(TRIM(keterangan), ' | ')),
                      'Batch: ', ?),
               255)
         WHERE id = ?"
    );
    foreach ($ringkasan as $detailId => $r) {
        // Dipotong di dua sisi: di PHP agar ringkas, lalu LEFT(...,255) di SQL
        // sebagai pengaman keras. Kolom keterangan hanya VARCHAR(255) — tanpa
        // pemotongan, dokumen yang menyentuh banyak batch akan menggagalkan
        // persetujuan dengan error "Data too long" SETELAH stok terlanjur
        // dipindahkan (walau rollback bersih, dokumennya jadi tak bisa disetujui).
        $upd->execute([$r['batch_id'], mb_substr($r['teks'], 0, 180), $detailId]);
    }

    return $ringkasan;
}

// ---------------------------------------------------------------------
// Activity log
// ---------------------------------------------------------------------
function catat_aktivitas(PDO $pdo, string $aksi, string $modul, ?int $refId = null, ?string $deskripsi = null): void {
    try {
        $pdo->prepare(
            "INSERT INTO activity_log (user_id, aksi, modul, referensi_id, deskripsi, ip_address, user_agent)
             VALUES (?,?,?,?,?,?,?)"
        )->execute([
            $_SESSION['user_id'] ?? null,
            $aksi, $modul, $refId, $deskripsi,
            $_SERVER['REMOTE_ADDR'] ?? null,
            substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255),
        ]);
    } catch (Throwable $e) {
        error_log('[AMANAH] gagal catat aktivitas: ' . $e->getMessage());
    }
}

// ---------------------------------------------------------------------
// Label & warna untuk modul perpindahan
// ---------------------------------------------------------------------
function label_jenis(string $jenis): array {
    // Unsur kedua adalah ID SIMBOL di _ikon.php, bukan emoji lagi — bentuk
    // emoji ditentukan sistem operasi dan tidak bisa diberi warna sesuai
    // makna, sementara ikon sprite mengikuti currentColor badge-nya.
    $peta = [
        'penerimaan'      => ['Penerimaan',      'i-masuk',  't-green'],
        'transfer_gudang' => ['Transfer Gudang', 'i-putar',  't-blue'],
        'mutasi_rak'      => ['Mutasi Rak',      'i-layers', 't-blue'],
        'kirim_toko'      => ['Kirim ke Toko',   'i-toko',   't-blue'],
        'retur_toko'      => ['Retur dari Toko', 'i-putar',  't-amber'],
        'antar_toko'      => ['Antar Toko',      'i-toko',   't-blue'],
        'penyaluran'      => ['Penyaluran',      'i-keluar', 't-orange'],
        'retur'           => ['Retur Supplier',  'i-truck',  't-red'],
    ];
    return $peta[$jenis] ?? [ucfirst($jenis), 'i-box', 't-gray'];
}

function label_status(string $status): array {
    $peta = [
        'draft'                => ['Draft',            't-gray'],
        'menunggu_persetujuan' => ['Menunggu Setujui', 't-amber'],
        'disetujui'            => ['Disetujui',        't-green'],
        'ditolak'              => ['Ditolak',          't-red'],
        'dikirim'              => ['Dalam Perjalanan', 't-amber'],
        'diterima'             => ['Diterima',         't-green'],
        'selesai'              => ['Selesai',          't-blue'],
        'dibatalkan'           => ['Dibatalkan',       't-gray'],
    ];
    return $peta[$status] ?? [ucfirst($status), 't-gray'];
}

function label_marketplace(string $mp): array {
    $peta = [
        'shopee'    => ['Shopee',      't-orange'],
        'tokopedia' => ['Tokopedia',   't-green'],
        'tiktok'    => ['TikTok Shop', 't-gray'],
        'lazada'    => ['Lazada',      't-blue'],
        'offline'   => ['Toko Fisik',  't-gray'],
    ];
    return $peta[$mp] ?? ['Lainnya', 't-gray'];
}

function label_status_kiriman(string $status): array {
    $peta = [
        'dijadwalkan' => ['Dijadwalkan',    't-gray'],
        'dikirim'     => ['Dikirim',        't-blue'],
        'tiba'        => ['Tiba di Gudang', 't-amber'],
        'diterima'    => ['Diterima',       't-green'],
        'dibatalkan'  => ['Dibatalkan',     't-red'],
    ];
    return $peta[$status] ?? [ucfirst($status), 't-gray'];
}

/** Nama entitas asal/tujuan sebuah perpindahan. */
function nama_lokasi(PDO $pdo, string $tipe, ?int $id, ?string $teks): string {
    // 'penerima' dulu SELALU jatuh ke teks bebas, bahkan ketika id-nya ada.
    // Sejak penerima amanah punya master data sendiri, namanya dicari ke
    // tabel supaya penyaluran ke orang yang sama bisa dijumlahkan — dan
    // ganti nama di master ikut memperbaiki seluruh dokumen lama.
    if ($tipe === 'eksternal' || !$id) {
        return $teks ?: '—';
    }
    $petaTabel = [
        'supplier' => 'supplier',
        'gudang'   => 'gudang',
        'rak'      => 'lokasi_rak',
        'toko'     => 'toko',
        'penerima' => 'penerima',
    ];
    $tabel = $petaTabel[$tipe] ?? null;
    if (!$tabel) return $teks ?: '—';

    /* Dihafal per permintaan. Daftar perpindahan dan penerimaan memanggil
       ini dua kali per baris (asal & tujuan) — dulu itu dua kueri per baris,
       padahal dalam satu halaman lokasinya itu-itu saja. */
    static $hafal = [];
    $kunci = $tabel . '#' . $id;
    if (!array_key_exists($kunci, $hafal)) {
        $st = $pdo->prepare("SELECT nama FROM $tabel WHERE id = ?");
        $st->execute([$id]);
        $hafal[$kunci] = (string) ($st->fetchColumn() ?: '');
    }
    return $hafal[$kunci] !== '' ? $hafal[$kunci] : ($teks ?: '—');
}

// =====================================================================
// PENJAGA SESI PERANGKAT — dijalankan sekali per pemuatan halaman
// ---------------------------------------------------------------------
// Dua tugas:
//   1. Menyegarkan terakhir_at, supaya sesi ini tetap terhitung hidup.
//   2. Mengeluarkan pengguna bila sesinya sudah diakhiri dari perangkat
//      lain atau oleh Super Admin. Tanpa langkah ini, tombol "Akhiri
//      Sesi" di halaman Perangkat hanya menghapus baris di basis data
//      sementara orangnya tetap bisa bekerja — janji yang tidak ditepati.
//
// logout.php dikecualikan: di sana sesinya memang sedang diakhiri.
// =====================================================================
if (!empty($_SESSION['user_id']) && isset($pdo)
    && basename($_SERVER['SCRIPT_NAME'] ?? '') !== 'logout.php') {

    /* -----------------------------------------------------------------
       3. Peran & status akun disegarkan dari basis data.

       $_SESSION['role'] dulu ditulis sekali saat masuk dan dipercaya
       sampai sesi habis — dan sesi bisa 30 hari. Akun yang dinonaktifkan
       tetap bekerja, dan admin yang diturunkan tetap membawa menu lamanya.
       Satu kueri berkunci primer per halaman menutup itu; pola yang sama
       dengan auth_required() di API.
       ----------------------------------------------------------------- */
    try {
        $stAkun = $pdo->prepare(
            "SELECT u.is_active, u.deleted_at, u.gudang_id, u.supplier_id,
                    r.nama AS role, r.label AS role_label
               FROM users u LEFT JOIN roles r ON r.id = u.role_id
              WHERE u.id = ? LIMIT 1"
        );
        $stAkun->execute([(int) $_SESSION['user_id']]);
        $akunKini = $stAkun->fetch();
        if (!$akunKini || $akunKini['deleted_at'] !== null || (int) $akunKini['is_active'] !== 1) {
            $_SESSION = [];
            if (ini_get('session.use_cookies')) {
                $p = session_get_cookie_params();
                setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'],
                          $p['secure'], $p['httponly']);
            }
            session_destroy();
            header('Location: login.php?nonaktif=1');
            exit;
        }
        $_SESSION['role'] = (string) $akunKini['role'];
        if (isset($_SESSION['user']) && is_array($_SESSION['user'])) {
            $_SESSION['user']['role']        = (string) $akunKini['role'];
            $_SESSION['user']['role_label']  = (string) $akunKini['role_label'];
            $_SESSION['user']['gudang_id']   = $akunKini['gudang_id'];
            $_SESSION['user']['supplier_id'] = $akunKini['supplier_id'];
        }
        unset($stAkun, $akunKini);
    } catch (Throwable $e) {
        // Gagal membaca tidak boleh mengunci semua orang; dicatat saja.
        error_log('[AMANAH] segarkan akun: ' . $e->getMessage());
    }

    if (!segarkan_perangkat($pdo, (int) $_SESSION['user_id'])) {
        /* Alasannya dibaca SEBELUM sesinya dihancurkan — setelah itu sid-nya
           tidak bisa dicocokkan lagi. Tanpa ini pesan di halaman login hanya
           bisa menebak ("entah Anda, entah Super Admin"), dan orang yang
           terlempar keluar berulang kali tidak punya petunjuk apa pun. */
        $alasan = alasan_akhir_sesi($pdo, (int) $_SESSION['user_id']);
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $p = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'],
                      $p['secure'], $p['httponly']);
        }
        session_destroy();
        header('Location: login.php?diakhiri=' . urlencode($alasan));
        exit;
    }
}

// =====================================================================
// PRINCIPAL SESI — jembatan antara web dan API (ditambahkan 13 Sep 2026)
// ---------------------------------------------------------------------
// Fungsi lingkup di api/_lingkup.php bekerja dari array berbentuk isi
// token (user_id, role, tingkat, gudang_id, supplier_id). Halaman web
// bisa memakai fungsi yang SAMA dengan menyusun array itu dari sesi —
// itulah arah yang dituju: satu penafsir lingkup untuk keduanya, bukan
// dua salinan yang perlahan berbeda. Dipakai notifikasi.php dan halaman
// baru lain; pemakai lama _lib.php tidak berubah.
// =====================================================================
function principal_sesi(PDO $pdo): array {
    $u = $_SESSION['user'] ?? [];
    return [
        'user_id'     => (int) ($_SESSION['user_id'] ?? 0),
        'email'       => (string) ($u['email'] ?? ''),
        'role'        => role_saat_ini(),
        'tingkat'     => tingkat_saat_ini($pdo),
        'gudang_id'   => isset($u['gudang_id']) && $u['gudang_id'] !== null ? (int) $u['gudang_id'] : null,
        'supplier_id' => isset($u['supplier_id']) && $u['supplier_id'] !== null ? (int) $u['supplier_id'] : null,
    ];
}

// =====================================================================
// MEMBATALKAN & MENGHAPUS DOKUMEN PERPINDAHAN
// ---------------------------------------------------------------------
// Sampai 13 Sep 2026 tidak ada pintu keluar: draft yang keliru tinggal
// jadi sampah permanen, dan penerimaan.php menyuruh "hubungi pengirim agar
// dokumennya dibatalkan" tanpa satu pun tombol untuk itu.
//
// Yang boleh dibatalkan: draft, menunggu_persetujuan, dan dikirim. Ketiganya
// BELUM menyentuh stok — untuk jenis yang butuh penerimaan, stok baru
// berpindah saat penerimaan dikonfirmasi (lihat sahkan_perpindahan). Dokumen
// 'selesai' TIDAK bisa dibatalkan dari sini: stoknya sudah berpindah, dan
// membaliknya adalah dokumen baru (retur), bukan penghapusan jejak.
//
// Dipakai halaman web maupun API, supaya aturannya satu.
// =====================================================================
function batalkan_perpindahan(PDO $pdo, int $ppId, int $olehUserId, string $alasan): array {
    $pdo->beginTransaction();
    try {
        $st = $pdo->prepare("SELECT * FROM perpindahan WHERE id = ? FOR UPDATE");
        $st->execute([$ppId]);
        $pp = $st->fetch();
        if (!$pp) { throw new RuntimeException('Dokumen tidak ditemukan.'); }
        if (!in_array($pp['status'], ['draft', 'menunggu_persetujuan', 'dikirim'], true)) {
            throw new RuntimeException(
                'Dokumen berstatus "' . $pp['status'] . '" tidak bisa dibatalkan — stoknya sudah '
              . 'berpindah. Buat dokumen retur bila barangnya perlu kembali.');
        }
        /* Batch pratinjau yang sempat ditulis saat pengesahan dilepas lagi,
           supaya dokumen yang dibatalkan tidak tampak "memegang" batch. */
        $pdo->prepare("UPDATE perpindahan_detail SET qty_diterima = NULL WHERE perpindahan_id = ?")
            ->execute([$ppId]);
        // Kardus milik dokumen ini ikut dibatalkan (kardus tidak dipakai ulang).
        kardus_batal_dokumen($pdo, $ppId, $olehUserId, $alasan);
        $pdo->prepare(
            "UPDATE perpindahan
             SET status = 'dibatalkan', alasan_tolak = ?, updated_at = NOW()
             WHERE id = ?"
        )->execute(['Dibatalkan: ' . mb_substr($alasan, 0, 240), $ppId]);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) { $pdo->rollBack(); }
        throw $e;
    }
    $_SESSION['user_id'] = $_SESSION['user_id'] ?? $olehUserId;
    catat_aktivitas($pdo, 'cancel', 'perpindahan', $ppId,
        'Batalkan ' . $pp['no_perpindahan'] . ' (' . $pp['status'] . '): ' . $alasan);
    return $pp;
}

/** Hapus draft beserta rinciannya. Hanya status draft — yang lain punya jejak. */
function hapus_draft_perpindahan(PDO $pdo, int $ppId): array {
    $pdo->beginTransaction();
    try {
        $st = $pdo->prepare("SELECT * FROM perpindahan WHERE id = ? FOR UPDATE");
        $st->execute([$ppId]);
        $pp = $st->fetch();
        if (!$pp) { throw new RuntimeException('Dokumen tidak ditemukan.'); }
        if ($pp['status'] !== 'draft') {
            throw new RuntimeException('Hanya draft yang bisa dihapus. Dokumen lain dibatalkan, bukan dihapus, supaya jejaknya tetap ada.');
        }
        $pdo->prepare("DELETE FROM perpindahan_detail WHERE perpindahan_id = ?")->execute([$ppId]);
        kardus_hapus_dokumen($pdo, $ppId);
        $pdo->prepare("DELETE FROM perpindahan WHERE id = ?")->execute([$ppId]);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) { $pdo->rollBack(); }
        throw $e;
    }
    catat_aktivitas($pdo, 'delete', 'perpindahan', $ppId, 'Hapus draft ' . $pp['no_perpindahan']);
    return $pp;
}

// =====================================================================
// PENOLONG TAMPILAN BERSAMA — dulu lokal di barang.php dan dashboard.php,
// dipindah ke sini 13 Sep 2026 karena halaman detail & jejak aktivitas
// ikut memakainya.
// =====================================================================
/** Label & warna status stok sebuah produk terhadap batas minimumnya. */
function status_stok(int $stok, int $min): array {
    if ($stok === 0)    return ['Habis', 't-red'];
    if ($stok <= $min)  return ['Hampir habis', 't-amber'];
    return ['Aman', 't-green'];
}

/** Ikon & warna sebuah aksi di jejak aktivitas. */
function gaya_aktivitas(string $aksi): array {
    $peta = [
        'create'  => ['i-plus',          'var(--green)'],
        'update'  => ['i-ubah',          'var(--info)'],
        'delete'  => ['i-hapus',         'var(--red)'],
        'approve' => ['i-cek-bulat',     'var(--green)'],
        'reject'  => ['i-x',             'var(--red)'],
        'cancel'  => ['i-tolak',         'var(--amber)'],
        'submit'  => ['i-kirim',         'var(--info)'],
        'export'  => ['i-unduh',         'var(--muted)'],
        'print'   => ['i-cetak',         'var(--muted)'],
        'impor'   => ['i-impor',         'var(--accent)'],
        'login'   => ['i-keluar-pintu',  'var(--primary)'],
    ];
    return $peta[$aksi] ?? ['i-info', 'var(--primary)'];
}

// =====================================================================
// PAGINASI & KOTAK CARI BERSAMA — ditambahkan 13 Sep 2026
// ---------------------------------------------------------------------
// Sembilan halaman daftar memuat SELURUH tabelnya sekali jalan, tanpa
// kotak cari. Di data uji itu tidak terasa; di lembaga yang produknya
// ratusan dan penerimanya ribuan, halamannya makin lambat dan makin sulit
// dicari. Dua pembantu ini dipakai bersama supaya bentuknya seragam.
// =====================================================================
/** [halaman, offset, jumlahHalaman] dari ?hal= dan total baris. */
function paginasi_hitung(int $total, int $perHal = 30): array {
    $hal = max(1, (int) ($_GET['hal'] ?? 1));
    $jml = max(1, (int) ceil($total / $perHal));
    if ($hal > $jml) { $hal = $jml; }
    return [$hal, ($hal - 1) * $perHal, $jml];
}

/** Navigasi halaman: "Baris 31–60 dari 214 · ‹ Sebelumnya · Berikutnya ›". */
function paginasi_nav(int $hal, int $jmlHal, int $total, int $perHal, array $param = []): void {
    if ($total <= $perHal) { return; }
    $par  = array_filter($param, fn($v) => $v !== '' && $v !== null);
    $ini  = basename($_SERVER['SCRIPT_NAME'] ?? 'index.php');
    $url  = function (int $n) use ($par, $ini): string {
        $q = $par; $q['hal'] = $n;
        return $ini . '?' . http_build_query($q);
    };
    $dari = ($hal - 1) * $perHal + 1;
    $sampai = min($total, $hal * $perHal);
    ?>
    <div class="paginasi">
      <span class="mut">Baris <?= angka($dari) ?>–<?= angka($sampai) ?> dari <?= angka($total) ?></span>
      <span class="paginasi-tombol">
        <?php if ($hal > 1): ?><a class="btn btn-ghost btn-sm" href="<?= e($url($hal - 1)) ?>">‹ Sebelumnya</a><?php endif; ?>
        <span class="mut2" style="font-size:12px">hal <?= $hal ?> / <?= $jmlHal ?></span>
        <?php if ($hal < $jmlHal): ?><a class="btn btn-ghost btn-sm" href="<?= e($url($hal + 1)) ?>">Berikutnya ›</a><?php endif; ?>
      </span>
    </div>
    <?php
}

/** Kotak cari satu kolom, bentuknya sama dengan filter_bar(). */
function cari_bar(string $q, string $placeholder = 'Cari…', ?int $jml = null, array $tetap = []): void {
    ?>
    <form class="tools" method="get" data-tanpa-kunci>
      <?php foreach ($tetap as $k => $v): ?>
        <input type="hidden" name="<?= e((string) $k) ?>" value="<?= e((string) $v) ?>">
      <?php endforeach; ?>
      <input type="search" name="q" value="<?= e($q) ?>" placeholder="<?= e($placeholder) ?>"
             aria-label="<?= e($placeholder) ?>" style="flex:1;min-width:170px">
      <button class="btn btn-ghost" type="submit"><svg aria-hidden="true"><use href="#i-cari"></use></svg> Cari</button>
      <?php if ($q !== ''): ?>
        <a class="btn btn-ghost" href="<?= e(basename($_SERVER['SCRIPT_NAME'] ?? '') . ($tetap ? '?' . http_build_query($tetap) : '')) ?>">Reset</a>
        <?php if ($jml !== null): ?><span class="saring-jml" style="align-self:center"><?= angka($jml) ?> cocok</span><?php endif; ?>
      <?php endif; ?>
    </form>
    <?php
}

/** Syarat LIKE untuk beberapa kolom sekaligus, sudah di-quote — aman disisipkan ke SQL. */
function syarat_cari(PDO $pdo, string $q, array $kolom): string {
    if ($q === '' || !$kolom) { return '1=1'; }
    $nilai = $pdo->quote('%' . like_escape($q) . '%');
    return '(' . implode(' OR ', array_map(fn($k) => "$k LIKE $nilai", $kolom)) . ')';
}

/**
 * Halaman 403 yang seragam — pengganti ±20 `die('<p style=…>403 …')` yang
 * masing-masing menulis HTML-nya sendiri. Dipakai halaman baru; halaman
 * lama boleh dipindahkan ke sini satu per satu tanpa mengubah perilaku.
 */
function halaman_403(string $pesan, ?string $kembali = null): void {
    http_response_code(403);
    $kembali = $kembali ?? (function_exists('beranda_peran') && !empty($_SESSION['user_id']) ? beranda_peran() : 'login.php');
    echo '<!doctype html><html lang="id"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
       . '<title>403 · ' . e(APP_NAMA) . '</title></head>'
       . '<body style="font-family:DM Sans,Segoe UI,system-ui,sans-serif;background:#FAFAFA;color:#0A0A0A;margin:0;'
       . 'min-height:100vh;display:grid;place-items:center;padding:24px">'
       . '<div style="max-width:460px;background:#fff;border:1px solid #E8E8EC;border-radius:12px;padding:26px;line-height:1.7">'
       . '<div style="font-size:12px;font-weight:700;letter-spacing:.08em;color:#B01818">403 — TIDAK DIIZINKAN</div>'
       . '<p style="margin:8px 0 16px">' . e($pesan) . '</p>'
       . '<a href="' . e($kembali) . '" style="color:#2563EB;font-weight:600;text-decoration:none">← Kembali</a>'
       . '</div></body></html>';
    exit;
}

/* Titik transit & pelacakan kiriman — fungsinya memakai pembantu di atas,
   jadi dimuat paling akhir. */
require_once __DIR__ . '/_lacak.php';
require_once __DIR__ . '/_perpindahan_ubah.php';
require_once __DIR__ . '/_kardus.php';
require_once __DIR__ . '/_stok_pesan.php';
require_once __DIR__ . '/_rincian_keluar.php';

/* Tabel pendukung (titik transit, jejak mutasi) dipastikan ada SEKARANG —
   di luar transaksi mana pun. CREATE TABLE di dalam transaksi membuat MySQL
   commit diam-diam, jadi pembuatannya tidak boleh menunggu sampai dipakai. */
if (isset($pdo) && $pdo instanceof PDO && !$pdo->inTransaction()) {
    pastikan_tabel_titik($pdo);
    pastikan_tabel_mutasi($pdo);
}
