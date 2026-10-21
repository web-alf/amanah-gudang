<?php
/**
 * AMANAH — Impor Master Data (CSV)
 *
 * Satu importer untuk keenam master data. Menambah entitas baru cukup
 * dengan menambah satu entri di definisi_impor() — parser, validator,
 * pratinjau, template, dan penyimpanannya sudah generik.
 *
 * Alurnya dua langkah dan sengaja tidak bisa dilangkahi:
 *   1. Unggah  → PRATINJAU. Tidak ada satu baris pun yang ditulis.
 *   2. Simpan  → baru menulis, di dalam satu transaksi.
 *
 * Kolom relasi diisi dengan KODE, bukan id — orang yang menyiapkan CSV di
 * Excel tidak tahu id, dan id bisa berbeda antar-lingkungan.
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/_lib.php';
require_login();
tolak_supplier();

if (!boleh_edit()) {
    http_response_code(403);
    die('<p style="font-family:sans-serif;padding:30px">403 — Impor data hanya untuk Super Admin '
      . 'dan Admin Gudang. <a href="pengaturan.php">Kembali ke Pengaturan</a></p>');
}
// Catatan: JANGAN pakai tolak_aksi_pengawasan() di sini. Fungsi itu hanya
// masuk akal di dasbor supplier/toko — mode_pengawasan() bernilai true bagi
// setiap Super Admin, jadi pemanggilannya di sini memblokir semua orang.
// Master data memang milik organisasi sendiri; boleh_edit() sudah pagar yang tepat.

/** Batas baris per unggahan — pratinjau disimpan di sesi, jangan sampai membengkak. */
const IMPOR_MAKS_BARIS = 2000;

// =====================================================================
// DEFINISI ENTITAS
// =====================================================================
/**
 * tipe kolom:
 *   teks  apa adanya, dibatasi 'maks'
 *   int   bilangan bulat
 *   uang  desimal 2 angka
 *   bool  1/0 — menerima ya/tidak, aktif/nonaktif, true/false
 *   enum  harus salah satu 'pilihan' (kosong → 'bawaan')
 *   ref   dicocokkan ke kolom `kode` tabel 'ref' lalu disimpan sebagai id
 */
function definisi_impor(): array {
    return [
        'kategori' => [
            't' => 'Kategori', 'i' => 'i-saring', 'x' => '🏷️', 'tabel' => 'kategori',
            'ket' => 'Impor ini dulu — Data Barang merujuk kodenya.',
            'kolom' => [
                ['k' => 'kode',       'db' => 'kode',       'label' => 'Kode',       'wajib' => true, 'tipe' => 'teks', 'maks' => 20,  'contoh' => 'KAT-009'],
                ['k' => 'nama',       'db' => 'nama',       'label' => 'Nama',       'wajib' => true, 'tipe' => 'teks', 'maks' => 100, 'contoh' => 'Mainan Edukasi'],
                ['k' => 'ikon',       'db' => 'ikon',       'label' => 'Ikon',       'tipe' => 'teks', 'maks' => 50,  'contoh' => '🧩'],
                ['k' => 'keterangan', 'db' => 'keterangan', 'label' => 'Keterangan', 'tipe' => 'teks', 'maks' => 255, 'contoh' => 'Mainan stimulasi motorik'],
            ],
        ],

        'gudang' => [
            't' => 'Gudang', 'i' => 'i-gudang', 'x' => '🏭', 'tabel' => 'gudang',
            'ket' => 'Impor sebelum Rak — Rak merujuk kode gudang.',
            'kolom' => [
                ['k' => 'kode',             'db' => 'kode',             'label' => 'Kode',    'wajib' => true, 'tipe' => 'teks', 'maks' => 20,  'contoh' => 'GD-003'],
                ['k' => 'nama',             'db' => 'nama',             'label' => 'Nama',    'wajib' => true, 'tipe' => 'teks', 'maks' => 150, 'contoh' => 'Gudang Cabang Bekasi'],
                ['k' => 'alamat',           'db' => 'alamat',           'label' => 'Alamat',  'tipe' => 'teks', 'maks' => 255, 'contoh' => 'Jl. Ahmad Yani No. 12'],
                ['k' => 'penanggung_jawab', 'db' => 'penanggung_jawab', 'label' => 'Penanggung Jawab', 'alias' => ['pj', 'penanggungjawab'], 'tipe' => 'teks', 'maks' => 150, 'contoh' => 'Budi Santoso'],
                ['k' => 'telepon',          'db' => 'telepon',          'label' => 'Telepon', 'alias' => ['hp', 'no telepon'], 'tipe' => 'teks', 'maks' => 30, 'contoh' => '021-8899001'],
                ['k' => 'keterangan',       'db' => 'keterangan',       'label' => 'Keterangan', 'tipe' => 'teks', 'maks' => 255, 'contoh' => 'Gudang transit wilayah timur'],
                ['k' => 'aktif',            'db' => 'is_active',        'label' => 'Aktif',   'alias' => ['is_active', 'status'], 'tipe' => 'bool', 'bawaan' => 1, 'contoh' => 'ya'],
            ],
        ],

        'rak' => [
            't' => 'Rak / Lokasi', 'i' => 'i-layers', 'x' => '🗄️', 'tabel' => 'lokasi_rak',
            'ket' => 'Butuh Gudang lebih dulu. Kolom <code>gudang_kode</code> harus cocok dengan kode gudang yang sudah ada.',
            'kolom' => [
                ['k' => 'kode',        'db' => 'kode',      'label' => 'Kode',       'wajib' => true, 'tipe' => 'teks', 'maks' => 20,  'contoh' => 'RAK-F01'],
                ['k' => 'nama',        'db' => 'nama',      'label' => 'Nama',       'wajib' => true, 'tipe' => 'teks', 'maks' => 100, 'contoh' => 'Rak A9 — Baris Bawah'],
                ['k' => 'gudang_kode', 'db' => 'gudang_id', 'label' => 'Kode Gudang','wajib' => true, 'tipe' => 'ref',  'ref' => 'gudang', 'ref_t' => 'Gudang', 'alias' => ['gudang', 'kode gudang'], 'contoh' => 'GD-001'],
                ['k' => 'kapasitas',   'db' => 'kapasitas', 'label' => 'Kapasitas',  'tipe' => 'int', 'contoh' => '500'],
                ['k' => 'keterangan',  'db' => 'keterangan','label' => 'Keterangan', 'tipe' => 'teks', 'maks' => 255, 'contoh' => 'Khusus barang ringan'],
            ],
        ],

        'supplier' => [
            't' => 'Supplier', 'i' => 'i-truck', 'x' => '🚚', 'tabel' => 'supplier',
            'ket' => 'Impor ini hanya membuat data supplier. Akun login portalnya dibuat terpisah di Pengaturan → Akun Supplier.',
            'kolom' => [
                ['k' => 'kode',       'db' => 'kode',       'label' => 'Kode',    'wajib' => true, 'tipe' => 'teks', 'maks' => 20,  'contoh' => 'SUP-004'],
                ['k' => 'nama',       'db' => 'nama',       'label' => 'Nama',    'wajib' => true, 'tipe' => 'teks', 'maks' => 150, 'contoh' => 'PT Sentosa Bayi'],
                ['k' => 'telepon',    'db' => 'telepon',    'label' => 'Telepon', 'alias' => ['hp'], 'tipe' => 'teks', 'maks' => 30, 'contoh' => '0812-3456-7890'],
                ['k' => 'email',      'db' => 'email',      'label' => 'Email',   'tipe' => 'teks', 'maks' => 150, 'contoh' => 'sales@sentosabayi.co.id'],
                ['k' => 'alamat',     'db' => 'alamat',     'label' => 'Alamat',  'tipe' => 'teks', 'maks' => 255, 'contoh' => 'Jl. Industri Raya No. 8'],
                ['k' => 'kontak_pic', 'db' => 'kontak_pic', 'label' => 'Kontak PIC', 'alias' => ['pic', 'kontak'], 'tipe' => 'teks', 'maks' => 100, 'contoh' => 'Ibu Rina'],
                ['k' => 'keterangan', 'db' => 'keterangan', 'label' => 'Keterangan', 'tipe' => 'teks', 'maks' => 255, 'contoh' => 'Termin pembayaran 30 hari'],
            ],
        ],

        'toko' => [
            't' => 'Toko', 'i' => 'i-toko', 'x' => '🏪', 'tabel' => 'toko',
            'ket' => 'Impor ini hanya membuat data toko. Akun login dasbornya dibuat terpisah di Pengaturan → Akun Toko.',
            'kolom' => [
                ['k' => 'kode',             'db' => 'kode',             'label' => 'Kode',  'wajib' => true, 'tipe' => 'teks', 'maks' => 20,  'contoh' => 'TK-004'],
                ['k' => 'nama',             'db' => 'nama',             'label' => 'Nama',  'wajib' => true, 'tipe' => 'teks', 'maks' => 150, 'contoh' => 'Toko Utama Shopee'],
                ['k' => 'marketplace',      'db' => 'marketplace',      'label' => 'Marketplace', 'tipe' => 'enum',
                 'pilihan' => ['shopee', 'tokopedia', 'tiktok', 'lazada', 'offline', 'lainnya'], 'bawaan' => 'offline', 'contoh' => 'shopee'],
                ['k' => 'url_toko',         'db' => 'url_toko',         'label' => 'URL Toko', 'alias' => ['url', 'link'], 'tipe' => 'teks', 'maks' => 255, 'contoh' => 'https://shopee.co.id/tokoanda'],
                ['k' => 'penanggung_jawab', 'db' => 'penanggung_jawab', 'label' => 'Penanggung Jawab', 'alias' => ['pj'], 'tipe' => 'teks', 'maks' => 150, 'contoh' => 'Dewi Lestari'],
                ['k' => 'telepon',          'db' => 'telepon',          'label' => 'Telepon', 'alias' => ['hp'], 'tipe' => 'teks', 'maks' => 30, 'contoh' => '0813-1111-2222'],
                ['k' => 'alamat',           'db' => 'alamat',           'label' => 'Alamat',  'tipe' => 'teks', 'maks' => 255, 'contoh' => 'Marketplace — tanpa gerai fisik'],
                ['k' => 'keterangan',       'db' => 'keterangan',       'label' => 'Keterangan', 'tipe' => 'teks', 'maks' => 255, 'contoh' => 'Toko utama kanal daring'],
                ['k' => 'aktif',            'db' => 'is_active',        'label' => 'Aktif', 'alias' => ['is_active', 'status'], 'tipe' => 'bool', 'bawaan' => 1, 'contoh' => 'ya'],
            ],
        ],

        'barang' => [
            't' => 'Data Barang', 'i' => 'i-box', 'x' => '📦', 'tabel' => 'barang',
            'ket' => 'Impor paling akhir — merujuk Kategori (wajib), serta Supplier dan Rak (opsional).',
            'kolom' => [
                ['k' => 'kode',          'db' => 'kode',          'label' => 'Kode',   'wajib' => true, 'tipe' => 'teks', 'maks' => 30,  'contoh' => 'BBY-0016'],
                ['k' => 'nama',          'db' => 'nama',          'label' => 'Nama',   'wajib' => true, 'tipe' => 'teks', 'maks' => 150, 'contoh' => 'Botol Susu Antikolik 250ml'],
                ['k' => 'kategori_kode', 'db' => 'kategori_id',   'label' => 'Kode Kategori', 'wajib' => true, 'tipe' => 'ref', 'ref' => 'kategori', 'ref_t' => 'Kategori', 'alias' => ['kategori', 'kode kategori'], 'contoh' => 'KAT-001'],
                ['k' => 'barcode',       'db' => 'barcode',       'label' => 'Barcode', 'tipe' => 'teks', 'maks' => 50,  'contoh' => '8991234567890'],
                ['k' => 'merek',         'db' => 'merek',         'label' => 'Merek',   'tipe' => 'teks', 'maks' => 100, 'contoh' => 'BabyCare'],
                ['k' => 'ukuran',        'db' => 'ukuran',        'label' => 'Ukuran',  'tipe' => 'teks', 'maks' => 50,  'contoh' => '250 ml'],
                ['k' => 'satuan',        'db' => 'satuan',        'label' => 'Satuan',  'tipe' => 'enum',
                 'pilihan' => ['pcs', 'box', 'pack', 'kaleng', 'lusin', 'set'], 'bawaan' => 'pcs', 'contoh' => 'pcs'],
                ['k' => 'harga',         'db' => 'harga',         'label' => 'Harga',   'tipe' => 'uang', 'contoh' => '45000'],
                ['k' => 'stok_minimum',  'db' => 'stok_minimum',  'label' => 'Stok Minimum', 'alias' => ['stok min', 'minimum'], 'tipe' => 'int', 'contoh' => '24'],
                ['k' => 'supplier_kode', 'db' => 'supplier_id',   'label' => 'Kode Supplier', 'tipe' => 'ref', 'ref' => 'supplier', 'ref_t' => 'Supplier', 'alias' => ['supplier'], 'contoh' => 'SUP-001'],
                ['k' => 'rak_kode',      'db' => 'lokasi_rak_id', 'label' => 'Kode Rak', 'tipe' => 'ref', 'ref' => 'lokasi_rak', 'ref_t' => 'Rak', 'alias' => ['rak', 'lokasi'], 'contoh' => 'RAK-A01'],
                ['k' => 'keterangan',    'db' => 'keterangan',    'label' => 'Keterangan', 'tipe' => 'teks', 'maks' => 2000, 'contoh' => 'BPA free, bisa disterilkan'],
                ['k' => 'aktif',         'db' => 'is_active',     'label' => 'Aktif',  'alias' => ['is_active', 'status'], 'tipe' => 'bool', 'bawaan' => 1, 'contoh' => 'ya'],
            ],
        ],
    ];
}

$DEF = definisi_impor();

/* Impor CSV membuat ratusan baris master data sekaligus — pintu paling
   lebar di seluruh sistem. Jadi jenis yang boleh diimpor harus mengikuti
   lingkup peran yang SAMA dengan menunya. Kalau tidak, halaman Kategori /
   Supplier / Toko yang sudah 403 tetap bisa diisi dari sini.
   Admin Gudang hanya mengurus isi gudangnya: rak dan produk. */
if (terikat_satu_gudang()) {
    $DEF = array_intersect_key($DEF, ['rak' => 1, 'barang' => 1]);
}
if (!$DEF) {
    http_response_code(403);
    die('<p style="font-family:sans-serif;padding:30px">403 — Tidak ada data yang boleh Anda impor. '
      . '<a href="' . beranda_peran() . '">Kembali ke dasbor Anda</a></p>');
}

$bawaan = (string) array_key_first($DEF);
$ent    = $_GET['entitas'] ?? $_POST['entitas'] ?? $bawaan;
if (!isset($DEF[$ent])) { $ent = $bawaan; }
$def = $DEF[$ent];

// =====================================================================
// PENOLONG
// =====================================================================
/** Judul CSV disamakan bentuknya supaya "Kode Gudang" == "kode_gudang". */
function samakan_judul(string $s): string {
    $s = strtolower(trim($s));
    $s = str_replace(["\xEF\xBB\xBF", '-'], ['', ' '], $s);
    $s = preg_replace('/[_\s]+/', ' ', $s);
    return trim((string) $s);
}

/** Cari indeks kolom CSV untuk satu definisi kolom. */
function indeks_kolom(array $judul, array $kol): ?int {
    $cari = array_map('samakan_judul', array_merge(
        [$kol['k'], $kol['label']],
        $kol['alias'] ?? []
    ));
    foreach ($judul as $i => $j) {
        if (in_array(samakan_judul((string) $j), $cari, true)) { return $i; }
    }
    return null;
}

function ke_bool($v): ?int {
    $s = strtolower(trim((string) $v));
    if ($s === '') return null;
    if (in_array($s, ['1', 'ya', 'y', 'aktif', 'true', 'benar', 'iya'], true))          return 1;
    if (in_array($s, ['0', 'tidak', 'n', 'nonaktif', 'false', 'salah', 'no'], true))    return 0;
    return -1;   // tidak dikenali
}

/** "45.000,50" dan "45000.50" sama-sama harus terbaca benar. */
function ke_angka(string $v): ?float {
    $v = trim($v);
    if ($v === '') return null;
    $v = preg_replace('/[^0-9,.\-]/', '', $v);
    if ($v === '' || $v === '-') return null;
    $koma = strrpos($v, ','); $titik = strrpos($v, '.');

    if ($koma !== false && $titik !== false) {
        // Dua-duanya ada: pemisah desimal = yang paling kanan.
        // "45.500,75" → 45500.75   ·   "45,500.75" → 45500.75
        if ($koma > $titik) { $v = str_replace('.', '', $v); $v = str_replace(',', '.', $v); }
        else                { $v = str_replace(',', '', $v); }
    } elseif ($koma !== false) {
        // Satu jenis pemisah saja: TIGA angka di belakangnya berarti ribuan,
        // selebihnya desimal. "45,500" → 45500  ·  "45,75" → 45.75
        $v = (strlen($v) - $koma - 1) === 3 ? str_replace(',', '', $v) : str_replace(',', '.', $v);
    } elseif ($titik !== false) {
        // Aturan yang sama untuk titik — di Indonesia titik adalah pemisah
        // ribuan, jadi "Rp 85.000" wajib terbaca 85000, bukan 85,0.
        // Titik ganda ("1.234.567") selalu ribuan.
        if (substr_count($v, '.') > 1 || (strlen($v) - $titik - 1) === 3) {
            $v = str_replace('.', '', $v);
        }
    }
    return is_numeric($v) ? (float) $v : null;
}

/** Peta kode → id untuk seluruh tabel yang dirujuk definisi ini. */
function peta_referensi(PDO $pdo, array $def): array {
    $peta = [];
    foreach ($def['kolom'] as $kol) {
        if (($kol['tipe'] ?? '') !== 'ref') continue;
        $tabel = $kol['ref'];
        if (isset($peta[$tabel])) continue;
        // Nama tabel berasal dari definisi di berkas ini, bukan dari masukan
        // pengguna — tetap dibersihkan sebagai jaring terakhir.
        $tabel = preg_replace('/[^a-z_]/', '', $tabel);

        /* Referensi ikut lingkup gudang. Tanpa ini impor CSV jadi jalan
           belakang: satu baris ber-"gudang_kode: GD-004" sudah cukup untuk
           menaruh rak di gudang orang lain, dan hasilnya tercatat sah.
           Kode yang di luar lingkup jadi tidak ditemukan, dan pesan galat
           per barisnya sudah ada — tidak perlu jalur penolakan baru. */
        $saring = '';
        if ($tabel === 'gudang')     { $saring = ' AND id IN (' . in_gudang_saya($pdo) . ')'; }
        if ($tabel === 'lokasi_rak') { $saring = ' AND id IN (' . in_rak_saya($pdo) . ')'; }

        $baris = $pdo->query("SELECT id, kode, nama FROM `$tabel` WHERE deleted_at IS NULL$saring")->fetchAll();
        $peta[$kol['ref']] = [];
        foreach ($baris as $b) {
            $peta[$kol['ref']][strtolower(trim($b['kode']))] = ['id' => (int) $b['id'], 'nama' => $b['nama']];
        }
    }
    return $peta;
}

// =====================================================================
// AKSI: UNDUH TEMPLATE  (harus sebelum ada keluaran HTML)
// =====================================================================
if (isset($_GET['template'])) {
    $tEnt = $_GET['template'];
    if (!isset($DEF[$tEnt])) { http_response_code(404); die('Template tidak dikenal.'); }
    $tDef = $DEF[$tEnt];

    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="template_impor_' . $tEnt . '.csv"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");   // BOM: Excel butuh ini agar emoji & é tidak rusak
    fputcsv($out, array_column($tDef['kolom'], 'k'), ',', '"', "\\");
    fputcsv($out, array_map(fn($k) => $k['contoh'] ?? '', $tDef['kolom']), ',', '"', "\\");
    fclose($out);
    exit;
}

// =====================================================================
// AKSI: BACA & PERIKSA  (pratinjau — tidak menulis apa pun)
// =====================================================================
$errors  = [];
$periksa = $_SESSION['impor'][$ent] ?? null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['aksi'] ?? '') === 'periksa') {
    wajib_csrf($_POST['csrf'] ?? null);
    unset($_SESSION['impor'][$ent]);
    $periksa = null;

    $mode = ($_POST['mode'] ?? 'upsert') === 'baru' ? 'baru' : 'upsert';

    if (!isset($_FILES['berkas']) || $_FILES['berkas']['error'] !== UPLOAD_ERR_OK) {
        $petaGalat = [
            UPLOAD_ERR_INI_SIZE  => 'Berkas terlalu besar. Batasnya 8 MB.',
            UPLOAD_ERR_FORM_SIZE => 'Berkas terlalu besar. Batasnya 8 MB.',
            UPLOAD_ERR_NO_FILE   => 'Belum ada berkas yang dipilih.',
        ];
        $errors[] = $petaGalat[$_FILES['berkas']['error'] ?? -1]
                 ?? 'Berkas gagal diunggah. Coba lagi.';
    } else {
        $fh = fopen($_FILES['berkas']['tmp_name'], 'r');
        if (!$fh) {
            $errors[] = 'Berkas tidak dapat dibaca.';
        } else {
            // BOM UTF-8 dari Excel dibuang lebih dulu.
            $awal = fread($fh, 3);
            if ($awal !== "\xEF\xBB\xBF") { rewind($fh); }

            // Deteksi pemisah: Excel berlokal Indonesia mengekspor titik koma.
            $posisi = ftell($fh);
            $baris1 = (string) fgets($fh);
            $pemisah = substr_count($baris1, ';') > substr_count($baris1, ',') ? ';' : ',';
            fseek($fh, $posisi);

            $judul = fgetcsv($fh, 0, $pemisah, '"', "\\");
            if (!$judul) {
                $errors[] = 'Berkas CSV kosong atau tidak dikenali.';
            } else {
                // Petakan setiap kolom definisi ke indeks kolom CSV.
                $idx = [];
                $hilangWajib = [];
                foreach ($def['kolom'] as $kol) {
                    $i = indeks_kolom($judul, $kol);
                    if ($i === null && !empty($kol['wajib'])) { $hilangWajib[] = $kol['k']; }
                    $idx[$kol['k']] = $i;
                }

                if ($hilangWajib) {
                    $errors[] = 'Kolom wajib tidak ditemukan: <b>' . e(implode(', ', $hilangWajib)) . '</b>. '
                              . 'Judul yang terbaca: <code>'
                              . e(implode(' | ', array_slice(array_map('strval', $judul), 0, 15))) . '</code>. '
                              . 'Paling aman: unduh template di bawah lalu isi di sana.';
                }

                if (!$errors) {
                    $peta = peta_referensi($pdo, $def);

                    // Kode yang sudah ada — TANPA filter deleted_at, karena
                    // indeks uniknya hanya di kolom kode. Baris terhapus tetap
                    // memegang kodenya, jadi INSERT akan bentrok.
                    $tabel = preg_replace('/[^a-z_]/', '', $def['tabel']);
                    $adaKode = [];
                    foreach ($pdo->query("SELECT id, kode, deleted_at FROM `$tabel`")->fetchAll() as $b) {
                        $adaKode[strtolower(trim($b['kode']))] = [
                            'id' => (int) $b['id'], 'terhapus' => $b['deleted_at'] !== null,
                        ];
                    }

                    $baris = []; $noBaris = 1; $terpotong = false; $kodeDiBerkas = [];

                    while (($r = fgetcsv($fh, 0, $pemisah, '"', "\\")) !== false) {
                        $noBaris++;
                        if (count(array_filter($r, fn($v) => trim((string) $v) !== '')) === 0) continue;

                        if (count($baris) >= IMPOR_MAKS_BARIS) { $terpotong = true; break; }

                        $nilai = []; $salah = []; $tampil = ['kode' => '', 'nama' => ''];

                        foreach ($def['kolom'] as $kol) {
                            $i    = $idx[$kol['k']];
                            $asli = $i === null ? '' : trim((string) ($r[$i] ?? ''));
                            $tipe = $kol['tipe'] ?? 'teks';

                            // Simpan apa adanya untuk pratinjau: baris yang gagal
                            // validasi tetap harus bisa dikenali di berkas asalnya.
                            if (isset($tampil[$kol['k']])) { $tampil[$kol['k']] = $asli; }

                            if ($asli === '') {
                                if (!empty($kol['wajib'])) {
                                    $salah[] = $kol['label'] . ' wajib diisi';
                                } elseif (array_key_exists('bawaan', $kol)) {
                                    $nilai[$kol['db']] = $kol['bawaan'];
                                } elseif ($i !== null) {
                                    $nilai[$kol['db']] = null;   // kolom ada di CSV tapi dikosongkan
                                }
                                continue;
                            }

                            switch ($tipe) {
                                case 'int':
                                    $n = ke_angka($asli);
                                    if ($n === null) { $salah[] = $kol['label'] . " bukan angka ($asli)"; break; }
                                    $nilai[$kol['db']] = (int) round($n);
                                    break;

                                case 'uang':
                                    $n = ke_angka($asli);
                                    if ($n === null)  { $salah[] = $kol['label'] . " bukan angka ($asli)"; break; }
                                    if ($n < 0)       { $salah[] = $kol['label'] . ' tidak boleh negatif'; break; }
                                    $nilai[$kol['db']] = round($n, 2);
                                    break;

                                case 'bool':
                                    $b = ke_bool($asli);
                                    if ($b === -1) { $salah[] = $kol['label'] . " harus ya/tidak ($asli)"; break; }
                                    $nilai[$kol['db']] = $b;
                                    break;

                                case 'enum':
                                    $v = strtolower($asli);
                                    if (!in_array($v, $kol['pilihan'], true)) {
                                        $salah[] = $kol['label'] . " harus salah satu: " . implode('/', $kol['pilihan']) . " (isi: $asli)";
                                        break;
                                    }
                                    $nilai[$kol['db']] = $v;
                                    break;

                                case 'ref':
                                    $cocok = $peta[$kol['ref']][strtolower($asli)] ?? null;
                                    if (!$cocok) {
                                        $salah[] = $kol['label'] . " \"$asli\" tidak ada di master "
                                                 . ($kol['ref_t'] ?? $kol['ref']) . '. Impor master itu lebih dulu.';
                                        break;
                                    }
                                    $nilai[$kol['db']] = $cocok['id'];
                                    // lokasi_rak masih menyimpan nama gudang sebagai teks
                                    // (kolom lama NOT NULL) — isi sekalian agar konsisten.
                                    if ($kol['db'] === 'gudang_id') { $nilai['gudang'] = $cocok['nama']; }
                                    break;

                                default:
                                    $maks = $kol['maks'] ?? 255;
                                    if (mb_strlen($asli) > $maks) {
                                        $salah[] = $kol['label'] . " kepanjangan (maks $maks huruf, ada " . mb_strlen($asli) . ')';
                                        break;
                                    }
                                    $nilai[$kol['db']] = $asli;
                            }
                        }

                        $kode  = strtolower(trim((string) ($nilai['kode'] ?? '')));
                        $aksi  = 'baru';
                        $lama  = $kode !== '' ? ($adaKode[$kode] ?? null) : null;

                        if ($kode !== '' && isset($kodeDiBerkas[$kode])) {
                            $salah[] = 'Kode ganda di dalam berkas ini (baris ' . $kodeDiBerkas[$kode] . ')';
                        } elseif ($kode !== '') {
                            $kodeDiBerkas[$kode] = $noBaris;
                        }

                        if ($lama) {
                            if ($mode === 'baru') {
                                $aksi = 'lewat';
                            } else {
                                $aksi = $lama['terhapus'] ? 'pulih' : 'perbarui';
                            }
                        }

                        $baris[] = [
                            'no'    => $noBaris,
                            'kode'  => (string) ($nilai['kode'] ?? $tampil['kode']),
                            'nama'  => (string) ($nilai['nama'] ?? $tampil['nama']),
                            'nilai' => $nilai,
                            'id'    => $lama['id'] ?? null,
                            'aksi'  => $salah ? 'galat' : $aksi,
                            'salah' => $salah,
                        ];
                    }
                    fclose($fh);

                    if (!$baris) {
                        $errors[] = 'Tidak ada baris data di bawah judul kolom.';
                    } else {
                        $periksa = [
                            'ent'       => $ent,
                            'mode'      => $mode,
                            'berkas'    => (string) $_FILES['berkas']['name'],
                            'baris'     => $baris,
                            'terpotong' => $terpotong,
                            'token'     => bin2hex(random_bytes(16)),
                        ];
                        $_SESSION['impor'][$ent] = $periksa;
                    }
                }
            }
            if (is_resource($fh)) { fclose($fh); }
        }
    }
}

// =====================================================================
// AKSI: SIMPAN  (satu transaksi)
// =====================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['aksi'] ?? '') === 'simpan') {
    wajib_csrf($_POST['csrf'] ?? null);

    $sesi = $_SESSION['impor'][$ent] ?? null;
    if (!$sesi || !hash_equals((string) $sesi['token'], (string) ($_POST['token'] ?? ''))) {
        set_flash('err', 'Pratinjau sudah kedaluwarsa. Silakan unggah ulang berkasnya.');
        header('Location: impor_data.php?entitas=' . urlencode($ent)); exit;
    }

    $ketat  = isset($_POST['ketat']);
    $galat  = array_values(array_filter($sesi['baris'], fn($b) => $b['aksi'] === 'galat'));
    $pakai  = array_values(array_filter($sesi['baris'], fn($b) => in_array($b['aksi'], ['baru', 'perbarui', 'pulih'], true)));

    if ($ketat && $galat) {
        set_flash('err', 'Dibatalkan: ada ' . count($galat) . ' baris bermasalah dan Anda memilih '
                       . '"batalkan semua bila ada yang bermasalah". Perbaiki berkasnya lalu unggah lagi.');
        header('Location: impor_data.php?entitas=' . urlencode($ent)); exit;
    }
    if (!$pakai) {
        set_flash('err', 'Tidak ada baris yang bisa disimpan.');
        header('Location: impor_data.php?entitas=' . urlencode($ent)); exit;
    }

    $tabel = preg_replace('/[^a-z_]/', '', $def['tabel']);
    $nBaru = 0; $nUbah = 0; $nPulih = 0;

    try {
        $pdo->beginTransaction();

        foreach ($pakai as $b) {
            $nilai = $b['nilai'];

            if ($b['aksi'] === 'baru') {
                $kolom = array_keys($nilai);
                $sql = "INSERT INTO `$tabel` (`" . implode('`,`', $kolom) . "`) VALUES ("
                     . implode(',', array_fill(0, count($kolom), '?')) . ')';
                $pdo->prepare($sql)->execute(array_values($nilai));
                $nBaru++;
            } else {
                // Baris pulih ikut dihidupkan kembali; tanpa ini data lama
                // tetap tersembunyi walaupun kodenya baru saja diimpor.
                $set = [];
                foreach (array_keys($nilai) as $k) { $set[] = "`$k` = ?"; }
                if ($b['aksi'] === 'pulih') { $set[] = '`deleted_at` = NULL'; $nPulih++; } else { $nUbah++; }
                $pdo->prepare("UPDATE `$tabel` SET " . implode(', ', $set) . ' WHERE id = ?')
                    ->execute(array_merge(array_values($nilai), [$b['id']]));
            }
        }

        catat_aktivitas($pdo, 'impor', $ent, null,
            "Impor {$def['t']} dari {$sesi['berkas']}: $nBaru baru, $nUbah diperbarui, $nPulih dipulihkan, "
            . count($galat) . ' dilewati');

        $pdo->commit();

        unset($_SESSION['impor'][$ent]);
        $ringkas = "Impor {$def['t']} selesai — <b>$nBaru</b> ditambahkan, <b>$nUbah</b> diperbarui";
        if ($nPulih) { $ringkas .= ", <b>$nPulih</b> dipulihkan"; }
        if ($galat)  { $ringkas .= ', <b>' . count($galat) . '</b> dilewati karena bermasalah'; }
        set_flash('ok', strip_tags($ringkas) . '.');
        header('Location: impor_data.php?entitas=' . urlencode($ent)); exit;

    } catch (Throwable $e) {
        if ($pdo->inTransaction()) { $pdo->rollBack(); }
        set_flash('err', 'Impor dibatalkan seluruhnya, tidak ada data yang berubah. '
                       . pesan_error_aman($e, 'impor ' . $ent));
        header('Location: impor_data.php?entitas=' . urlencode($ent)); exit;
    }
}

// =====================================================================
// TAMPILAN
// =====================================================================
$PAGE_TITLE = 'Impor Data'; $ACTIVE = 'pengaturan';
require __DIR__ . '/_header.php';

$hitung = ['baru' => 0, 'perbarui' => 0, 'pulih' => 0, 'lewat' => 0, 'galat' => 0];
if ($periksa) {
    foreach ($periksa['baris'] as $b) { $hitung[$b['aksi']]++; }
}
?>


<div class="page-head">
  <div><h1>Impor Master Data</h1>
    <p>Unggah CSV untuk menambah atau memperbarui data dalam jumlah banyak</p></div>
  <a class="btn btn-ghost" href="pengaturan.php">← Pengaturan</a>
</div>

<div class="imp-ent">
  <?php foreach ($DEF as $k => $d): ?>
    <a href="impor_data.php?entitas=<?= e($k) ?>" class="<?= $k === $ent ? 'aktif' : '' ?>">
      <svg class="ikon ikon-sm" aria-hidden="true"><use href="#<?= e($d['i']) ?>"></use></svg> <?= e($d['t']) ?>
    </a>
  <?php endforeach; ?>
</div>

<?php if ($errors): ?>
  <div class="flash flash-err"><?php foreach ($errors as $er) echo '• ' . $er . '<br>'; ?></div>
<?php endif; ?>

<?php if ($periksa): ?>
  <!-- ============================================ LANGKAH 2: PRATINJAU -->
  <div class="card">
    <h3>Pratinjau — <?= e($periksa['berkas']) ?></h3>
    <div class="sub">Belum ada satu baris pun yang disimpan. Periksa dulu, lalu tekan Simpan.</div>

    <div class="imp-ring">
      <div><b style="color:var(--green)"><?= $hitung['baru'] ?></b>akan ditambah</div>
      <div><b style="color:var(--accent)"><?= $hitung['perbarui'] ?></b>akan diperbarui</div>
      <?php if ($hitung['pulih']): ?><div><b style="color:var(--amber)"><?= $hitung['pulih'] ?></b>dipulihkan</div><?php endif; ?>
      <?php if ($hitung['lewat']): ?><div><b class="mut"><?= $hitung['lewat'] ?></b>dilewati (sudah ada)</div><?php endif; ?>
      <?php if ($hitung['galat']): ?><div><b style="color:var(--red)"><?= $hitung['galat'] ?></b>bermasalah</div><?php endif; ?>
    </div>

    <?php if (!empty($periksa['terpotong'])): ?>
      <div class="flash flash-err">⚠️ Berkas melebihi <?= angka(IMPOR_MAKS_BARIS) ?> baris.
        Hanya <?= angka(IMPOR_MAKS_BARIS) ?> baris pertama yang diproses — sisanya <b>tidak</b> terbaca.
        Pecah berkasnya lalu impor bertahap.</div>
    <?php endif; ?>

    <div class="tbl-wrap"><table class="tbl-kartu">
      <thead><tr><th>Baris</th><th>Kode</th><th>Nama</th><th>Tindakan</th><th>Catatan</th></tr></thead>
      <tbody>
      <?php foreach ($periksa['baris'] as $b):
        $petaAksi = [
            'baru'     => ['Tambah baru',     't-green'],
            'perbarui' => ['Perbarui',        't-blue'],
            'pulih'    => ['Pulihkan + ubah', 't-amber'],
            'lewat'    => ['Dilewati',        't-gray'],
        ];
        [$lbl, $cls] = $petaAksi[$b['aksi']] ?? ['Bermasalah', 't-red']; ?>
        <tr>
          <td data-label="Baris"><?= (int) $b['no'] ?></td>
          <td data-label="Kode"><code><?= e($b['kode']) ?></code></td>
          <td data-label="Nama"><?= e($b['nama']) ?></td>
          <td data-label="Tindakan"><span class="tag <?= $cls ?>"><?= $lbl ?></span></td>
          <td data-label="Catatan">
            <?php if ($b['salah']): ?>
              <div class="imp-salah"><?php foreach ($b['salah'] as $s) echo '• ' . e($s) . '<br>'; ?></div>
            <?php elseif ($b['aksi'] === 'lewat'): ?>
              <small class="mut">Kode sudah ada, mode "hanya tambah baru"</small>
            <?php elseif ($b['aksi'] === 'pulih'): ?>
              <small class="mut">Data dengan kode ini pernah dihapus — akan dihidupkan kembali</small>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table></div>

    <form method="post" style="margin-top:17px;display:flex;gap:9px;align-items:center;flex-wrap:wrap">
      <input type="hidden" name="aksi" value="simpan">
      <input type="hidden" name="entitas" value="<?= e($ent) ?>">
      <input type="hidden" name="token" value="<?= e($periksa['token']) ?>">
      <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
      <button class="btn btn-primary"
        <?= ($hitung['baru'] + $hitung['perbarui'] + $hitung['pulih']) === 0 ? 'disabled' : '' ?>>
        💾 Simpan <?= $hitung['baru'] + $hitung['perbarui'] + $hitung['pulih'] ?> baris
      </button>
      <a class="btn btn-ghost" href="impor_data.php?entitas=<?= e($ent) ?>">Batal</a>
      <?php if ($hitung['galat']): ?>
        <label style="display:flex;gap:7px;align-items:center;font-size:12.5px;color:var(--text-2)">
          <input type="checkbox" name="ketat">
          Batalkan semua bila ada baris bermasalah
        </label>
      <?php endif; ?>
    </form>
  </div>

<?php else: ?>
  <!-- ============================================= LANGKAH 1: UNGGAH -->
  <div class="imp-wrap">
    <div class="card">
      <h3><svg class="ikon" style="vertical-align:-3px" aria-hidden="true"><use href="#<?= e($def['i']) ?>"></use></svg> Unggah CSV — <?= e($def['t']) ?></h3>
      <div class="sub"><?= $def['ket'] ?></div>

      <form method="post" enctype="multipart/form-data">
        <input type="hidden" name="aksi" value="periksa">
        <input type="hidden" name="entitas" value="<?= e($ent) ?>">
        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">

        <div class="field">
          <label>Berkas CSV <span class="req">*</span></label>
          <input type="file" name="berkas" accept=".csv,text/csv" required>
        </div>

        <div class="field">
          <label>Bila kode sudah ada di sistem</label>
          <select name="mode">
            <option value="upsert">Perbarui datanya (tambah yang baru, ubah yang sudah ada)</option>
            <option value="baru">Lewati saja (hanya tambah data yang benar-benar baru)</option>
          </select>
        </div>

        <button class="btn btn-primary"><svg aria-hidden="true"><use href="#i-cari"></use></svg> Periksa Dulu</button>
        <a class="btn btn-ghost" href="impor_data.php?template=<?= e($ent) ?>">⬇️ Unduh Template</a>
      </form>

      <p style="margin-top:15px;font-size:12px;color:var(--muted);line-height:1.65">
        Pemisah <b>koma</b> maupun <b>titik koma</b> keduanya dikenali, begitu juga berkas dari Excel
        yang mengandung BOM. Urutan kolom bebas — yang dicocokkan judulnya, bukan posisinya.
        Kolom yang tidak ada di berkas Anda tidak akan disentuh saat memperbarui.
        Maksimal <?= angka(IMPOR_MAKS_BARIS) ?> baris per unggahan.
      </p>
    </div>

    <div class="card">
      <h3>Kolom <?= e($def['t']) ?></h3>
      <div class="sub">Bertanda <span class="req">*</span> wajib ada</div>
      <table class="imp-kol"><tbody>
        <?php foreach ($def['kolom'] as $kol): ?>
          <tr>
            <td><?= e($kol['k']) ?><?= !empty($kol['wajib']) ? ' <span class="req">*</span>' : '' ?></td>
            <td>
              <?= e($kol['label']) ?>
              <?php if (($kol['tipe'] ?? '') === 'enum'): ?>
                <br><small class="mut"><?= e(implode(' / ', $kol['pilihan'])) ?></small>
              <?php elseif (($kol['tipe'] ?? '') === 'ref'): ?>
                <br><small class="mut">kode dari master <?= e($kol['ref']) ?></small>
              <?php elseif (($kol['tipe'] ?? '') === 'bool'): ?>
                <br><small class="mut">ya / tidak</small>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody></table>
    </div>
  </div>

<?php endif; ?>

<?php require __DIR__ . '/_footer.php'; ?>
