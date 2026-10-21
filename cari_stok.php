<?php
/**
 * AMANAH — Cari stok untuk panel "Pilih Banyak Barang"
 * =============================================================================
 * Menjawab satu pertanyaan yang tidak bisa dijawab dari data yang sudah
 * dimuat di halaman: "barang apa saja yang ADA di gudang/mitra ini, dan
 * yang MASUK dalam rentang tanggal tertentu."
 *
 * Kenapa endpoint sendiri, bukan disuntikkan ke halaman seperti daftar
 * barang biasa: penyaringnya berubah-ubah — sumbernya bisa gudang mana
 * pun, rentang tanggalnya bebas. Memuat semua kemungkinan di awal berarti
 * mengirim seluruh isi gudang ke peramban setiap kali formulir dibuka.
 *
 * -----------------------------------------------------------------------------
 * BALASANNYA JSON, TAPI INI BUKAN api/
 * -----------------------------------------------------------------------------
 * Berkas ini memakai SESI web, bukan token JWT, karena pemakainya adalah
 * halaman web yang sudah login. Menaruhnya di api/ akan menuntut token
 * yang tidak dipegang peramban, dan menyalin auth-nya ke sana berarti dua
 * jalur autentikasi untuk satu layar.
 *
 * -----------------------------------------------------------------------------
 * LINGKUP
 * -----------------------------------------------------------------------------
 * Disaring syarat_lingkup() — aturan yang sama dengan halaman laporan.
 * Tanpa itu, panel pemilih barang jadi jalan pintas untuk membaca isi
 * gudang orang lain: cukup mengganti satu angka di alamatnya.
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/_lib.php';
require_login();

/* Peran yang sama dengan yang boleh membuat perpindahan — panel ini
   hanya dipakai di formulir itu. Pimpinan tidak membuat dokumen, jadi
   tidak perlu membacanya dari sini. */
require_role(['super_admin', 'admin_gudang', 'admin_terpadu', 'toko', 'supplier']);

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

/** Balas lalu berhenti. */
function balas(array $d): void {
    echo json_encode($d, JSON_UNESCAPED_UNICODE);
    exit;
}

/* --------------------------------------------------------------- masukan */
$sumberTipe = $_GET['sumber_tipe'] ?? 'semua';
$sumberId   = (int) ($_GET['sumber_id'] ?? 0);
$dari       = trim($_GET['dari'] ?? '');
$ke         = trim($_GET['ke'] ?? '');

/* layak=1: hanya stok siap salur (butir 5). Dipakai formulir surat jalan
   untuk jenis yang keluar ke penerima/toko; untuk transfer gudang & retur
   seluruh kondisi tetap tampil. */
$hanyaLayak = (string) ($_GET['layak'] ?? '') === '1';
if (!in_array($sumberTipe, ['semua', 'gudang', 'rak', 'toko', 'supplier'], true)) {
    $sumberTipe = 'semua';
}

/** Terima hanya bentuk Y-m-d; sisanya dianggap tidak disaring. */
function tgl_saring(string $s): ?string {
    if ($s === '') { return null; }
    $d = DateTime::createFromFormat('!Y-m-d', $s);
    return ($d && $d->format('Y-m-d') === $s) ? $s : null;
}
$dari = tgl_saring($dari);
$ke   = tgl_saring($ke);

/* Rentang terbalik DIBETULKAN, bukan ditolak. Orang yang mengisi "dari 10
   sampai 3" jelas bermaksud 3 sampai 10, dan menolaknya hanya memaksa ia
   menukar sendiri dua kotak yang bersebelahan. */
if ($dari && $ke && $dari > $ke) { [$dari, $ke] = [$ke, $dari]; }

/* ------------------------------------------------------- penyaring lokasi
   Dibangun terpisah untuk batch_barang (stok gudang & toko) dan
   stok_supplier (stok mitra), karena keduanya menyimpan lokasinya dengan
   kolom yang berbeda. Nilai bawaannya membiarkan semuanya lolos. */
$wBatch = '1=1';
$wSup   = '1=1';

if ($sumberTipe === 'gudang') {
    // Rak-rak milik gudang itu. Dibaca lewat subkueri, bukan JOIN, supaya
    // penyaringnya tetap satu klausa yang bisa digabung.
    $wBatch = "bb.lokasi_rak_id IN (SELECT id FROM lokasi_rak
                                     WHERE gudang_id = " . (int) $sumberId . "
                                       AND deleted_at IS NULL)";
    $wSup   = '1=0';
} elseif ($sumberTipe === 'rak') {
    $wBatch = 'bb.lokasi_rak_id = ' . (int) $sumberId;
    $wSup   = '1=0';
} elseif ($sumberTipe === 'toko') {
    $wBatch = 'bb.toko_id = ' . (int) $sumberId;
    $wSup   = '1=0';
} elseif ($sumberTipe === 'supplier') {
    /* Mitra punya DUA tempat stok yang mungkin: stok_supplier (miliknya
       sendiri), dan batch_barang yang tercatat berasal darinya. Keduanya
       dihitung — menghilangkan salah satunya membuat angka di panel tidak
       pernah cocok dengan yang dilihat mitra di dasbornya. */
    $wBatch = 'bb.supplier_id = ' . (int) $sumberId;
    $wSup   = 'ss.supplier_id = ' . (int) $sumberId;
}

/* -------------------------------------------------------- penyaring tanggal
   Memakai tanggal_masuk, BUKAN created_at. Keduanya sering sama, tetapi
   tanggal_masuk adalah tanggal barang benar-benar tiba menurut petugas,
   sedangkan created_at tanggal barisnya diketik. Untuk pertanyaan "apa
   yang masuk hari ini", yang benar adalah yang pertama. */
if ($hanyaLayak) {
    $inLayak = implode(',', array_map([$pdo, 'quote'], kondisi_layak_salur()));
    $wBatch .= " AND bb.kondisi IN ($inLayak)";
    $wSup   .= " AND ss.kondisi IN ($inLayak)";
}

$wTgl = [];
if ($dari) { $wTgl[] = "tanggal_masuk >= " . $pdo->quote($dari); }
if ($ke)   { $wTgl[] = "tanggal_masuk <= " . $pdo->quote($ke); }
$sTgl = $wTgl ? ' AND ' . implode(' AND ', $wTgl) : '';

/* ------------------------------------------------------------------ kueri
   UNION dua sumber stok. CAST + COLLATE dipasang eksplisit pada kolom
   teks: sebagian tabel di basis data ini memakai utf8mb4_general_ci
   sementara sisanya utf8mb4_unicode_ci, dan MySQL menolak UNION yang
   mencampur keduanya dengan "Illegal mix of collations". */
$C = 'COLLATE utf8mb4_unicode_ci';
$lingkupB = syarat_lingkup($pdo, 'bb');
$lingkupS = syarat_lingkup($pdo, 'ss');

/* Dikelompokkan sampai NOMOR BATCH, bukan berhenti di lokasi.

   Sebabnya permintaan yang jelas dari lapangan: saat mencentang barang,
   petugas perlu memutuskan berapa banyak DAN dari batch mana — batch
   berumur panjang untuk toko yang jauh, batch yang mau kedaluwarsa
   dihabiskan lebih dulu. Tanpa nomor batch di balasan ini, pilihan itu
   tidak punya daftar untuk dipilih.

   Rangkuman per lokasi tetap dibentuk, tapi di PHP dari baris yang sama —
   bukan lewat kueri kedua. Dua kueri atas data yang sama pada dua saat
   yang berbeda berarti angka lokasi dan angka batch bisa tidak sepadan,
   dan yang terlihat di layar jadi jumlah yang tidak pernah ada. */
$sql = "
SELECT barang_id, lokasi, nomor_batch, tanggal_expired,
       SUM(qty) AS qty, MAX(masuk) AS masuk_akhir
  FROM (
    SELECT bb.barang_id,
           CAST(COALESCE(t.nama,
                CONCAT(COALESCE(g.nama,'—'), ' › ', COALESCE(lr.nama, lr.kode, '—'))
           ) AS CHAR) $C AS lokasi,
           CAST(bb.nomor_batch AS CHAR) $C AS nomor_batch,
           bb.tanggal_expired AS tanggal_expired,
           bb.qty_tersisa AS qty,
           bb.tanggal_masuk AS masuk
      FROM batch_barang bb
      LEFT JOIN toko t        ON t.id  = bb.toko_id
      LEFT JOIN lokasi_rak lr ON lr.id = bb.lokasi_rak_id
      LEFT JOIN gudang g      ON g.id  = lr.gudang_id
     WHERE bb.qty_tersisa > 0 AND ($wBatch) AND ($lingkupB)" . sql_tanpa_kardus($pdo) . " "
     . str_replace('tanggal_masuk', 'bb.tanggal_masuk', $sTgl) . "

    UNION ALL

    SELECT ss.barang_id,
           CAST(CONCAT('Mitra: ', COALESCE(sp.nama,'—')) AS CHAR) $C AS lokasi,
           CAST(ss.nomor_batch AS CHAR) $C AS nomor_batch,
           ss.tanggal_expired AS tanggal_expired,
           ss.qty AS qty,
           ss.tanggal_masuk AS masuk
      FROM stok_supplier ss
      LEFT JOIN supplier sp ON sp.id = ss.supplier_id
     WHERE ss.qty > 0 AND ($wSup) AND ($lingkupS) "
     . str_replace('tanggal_masuk', 'ss.tanggal_masuk', $sTgl) . "
  ) x
 GROUP BY barang_id, lokasi, nomor_batch, tanggal_expired
 HAVING qty > 0
 ORDER BY (tanggal_expired IS NULL), tanggal_expired ASC, qty DESC";

try {
    $baris = $pdo->query($sql)->fetchAll();
} catch (Throwable $e) {
    error_log('[AMANAH] cari_stok: ' . $e->getMessage());
    balas(['ok' => false, 'error' => 'Gagal membaca stok.']);
}

/* Nama & satuan barang diambil sekali, bukan lewat JOIN di dalam UNION —
   UNION yang membawa kolom teks tambahan menuntut CAST di kedua sisinya,
   dan setiap kolom teks baru adalah satu peluang lagi untuk galat
   collation. */
$nama = [];
foreach ($pdo->query("SELECT id, kode, nama, satuan FROM barang
                       WHERE deleted_at IS NULL") as $b) {
    $nama[(int) $b['id']] = $b;
}

$hasil = [];
$perLokasi = [];   // [barang_id][nama lokasi] => qty, dijumlahkan antar batch

foreach ($baris as $r) {
    $bid = (int) $r['barang_id'];
    if (!isset($nama[$bid])) { continue; }   // barang sudah dihapus
    if (!isset($hasil[$bid])) {
        $hasil[$bid] = [
            'id'     => $bid,
            'k'      => $nama[$bid]['kode'],
            'n'      => $nama[$bid]['nama'],
            's'      => $nama[$bid]['satuan'],
            'total'  => 0,
            'lokasi' => [],
            'lain'   => 0,
            'masuk'  => null,
            'batch'  => [],
        ];
        $perLokasi[$bid] = [];
    }

    $qty = (int) $r['qty'];
    $hasil[$bid]['total'] += $qty;

    $lok = (string) $r['lokasi'];
    $perLokasi[$bid][$lok] = ($perLokasi[$bid][$lok] ?? 0) + $qty;

    /* Batch DIGABUNG menurut nomor + kedaluwarsa, walau tersebar di
       beberapa rak — lokasinya dikumpulkan jadi keterangan.

       Semula tiap lokasi jadi pilihan sendiri, dengan alasan "B260901 di
       Rak A dan di Rak B adalah dua tumpukan fisik". Alasannya benar,
       tapi tidak bisa ditepati: yang tersimpan di dokumen adalah NOMOR
       batch, dan pemotongan stok mendahulukan batch bernomor itu di mana
       pun ia berada. Menawarkan "B260901 di Rak A · 2 unit" berarti
       menjanjikan ketepatan yang mesinnya tidak punya — orang memilih
       satu tumpukan, sistem mengambil dari tumpukan lain, dan tidak ada
       yang memberitahu. Pilihannya kini dibuat sepadan dengan yang
       benar-benar dijalankan. */
    $kb = $r['nomor_batch'] . '|' . ($r['tanggal_expired'] ?? '');
    if (!isset($hasil[$bid]['batch'][$kb])) {
        $hasil[$bid]['batch'][$kb] = [
            'b' => (string) $r['nomor_batch'],
            'e' => $r['tanggal_expired'],
            'q' => 0,
            'l' => [],
        ];
    }
    $hasil[$bid]['batch'][$kb]['q'] += $qty;
    $hasil[$bid]['batch'][$kb]['l'][$lok] = true;

    if ($r['masuk_akhir'] && $r['masuk_akhir'] > (string) $hasil[$bid]['masuk']) {
        $hasil[$bid]['masuk'] = $r['masuk_akhir'];
    }
}

/* Rangkuman lokasi dibentuk SESUDAH semuanya terkumpul. Kalau dibentuk
   sambil menyusuri, batas "maksimal empat lokasi" akan terhitung per
   batch, bukan per lokasi — satu rak berisi lima batch sudah menghabiskan
   kuotanya sendiri dan rak-rak lain hilang dari layar. */
foreach ($perLokasi as $bid => $daftar) {
    arsort($daftar);                                    // terbanyak dulu
    $n = 0;
    foreach ($daftar as $namaLok => $q) {
        if ($n < 4) { $hasil[$bid]['lokasi'][] = ['n' => $namaLok, 'q' => $q]; }
        else        { $hasil[$bid]['lain']++; }
        $n++;
    }
}

/* Kunci gabungan batch dibuang, lokasinya diubah jadi teks. Kunci itu alat
   pengelompokan di sisi PHP; mengirimkannya ke peramban hanya menambah
   bentuk data yang harus dipahami dua kali. */
foreach ($hasil as $bid => $b) {
    $daftar = [];
    foreach ($b['batch'] as $bt) {
        $lokasi = array_keys($bt['l']);
        $daftar[] = [
            'b' => $bt['b'],
            'e' => $bt['e'],
            'q' => $bt['q'],
            'l' => count($lokasi) > 2
                 ? ($lokasi[0] . ' +' . (count($lokasi) - 1) . ' lokasi')
                 : implode(', ', $lokasi),
        ];
    }
    $hasil[$bid]['batch'] = $daftar;
}

/* Diurutkan menurut jumlah, terbanyak dulu — panel ini dipakai memilih
   barang yang akan dipindahkan, dan yang banyak stoknya paling mungkin
   jadi kandidat. */
/* Stok yang sudah dipesan surat jalan lain (menunggu persetujuan / sedang
   dikirim) dari sumber ini — panel menampilkan "tersedia" = total − pesan
   − yang sudah ada di formulir (revisi 18 Okt 2026). */
if (in_array($sumberTipe, ['gudang', 'rak', 'toko'], true) && $sumberId > 0 && $hasil) {
    try {
        foreach (stok_dipesan($pdo, $sumberTipe, $sumberId, array_keys($hasil)) as $bidP => $p) {
            if (isset($hasil[$bidP])) { $hasil[$bidP]['pesan'] = (int) $p['qty']; }
        }
        /* Sumber RAK: surat jalan lain yang mengambil dari GUDANG-nya juga bisa
           menghabiskan isi rak ini. Yang ditampilkan dipakai yang lebih kecil:
           sisa rak, atau sisa gudang setelah pesanan tingkat gudang. */
        if ($sumberTipe === 'rak') {
            $gRak = (int) $pdo->query('SELECT gudang_id FROM lokasi_rak WHERE id = ' . (int) $sumberId)->fetchColumn();
            $pesanG = $gRak ? stok_dipesan($pdo, 'gudang', $gRak, array_keys($hasil)) : [];
            if ($pesanG) {
                $inG = implode(',', array_map('intval', array_keys($pesanG)));
                $syLayak = $hanyaLayak ? ' AND bb.kondisi IN (' . implode(',', array_map([$pdo, 'quote'], kondisi_layak_salur())) . ')' : '';
                $stokG = [];
                foreach ($pdo->query("SELECT bb.barang_id, SUM(bb.qty_tersisa) q FROM batch_barang bb JOIN lokasi_rak lr ON lr.id = bb.lokasi_rak_id
                                      WHERE lr.gudang_id = $gRak AND lr.deleted_at IS NULL AND bb.qty_tersisa > 0 AND bb.barang_id IN ($inG)$syLayak" . sql_tanpa_kardus($pdo) . "
                                      GROUP BY bb.barang_id") as $rg) { $stokG[(int) $rg['barang_id']] = (int) $rg['q']; }
                foreach ($pesanG as $bidG => $pg) {
                    if (!isset($hasil[$bidG])) { continue; }
                    $sisaRak = $hasil[$bidG]['total'] - (int) ($hasil[$bidG]['pesan'] ?? 0);
                    $sisaG   = ($stokG[$bidG] ?? 0) - (int) $pg['qty'];
                    $sisa    = max(0, min($sisaRak, $sisaG));
                    $hasil[$bidG]['pesan'] = $hasil[$bidG]['total'] - $sisa;
                }
            }
        }
    } catch (Throwable $e) { error_log('[AMANAH] cari_stok pesan: ' . $e->getMessage()); }
}
usort($hasil, fn($a, $b) => $b['total'] <=> $a['total']);

balas([
    'ok'      => true,
    'barang'  => array_values($hasil),
    'periode' => ['dari' => $dari, 'ke' => $ke],
]);
