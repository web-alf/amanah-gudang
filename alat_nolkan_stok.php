<?php
/**
 * AMANAH — Nolkan seluruh stok di satu lokasi
 * =============================================================================
 * Menolkan, BUKAN menghapus. Setiap batch di lokasi yang dipilih diturunkan
 * jumlahnya ke nol lewat sesuaikan_batch() — fungsi yang sama persis dengan
 * Stock Opname dan halaman Sesuaikan Stok. Tiga jalur, satu fungsi, sehingga
 * tidak mungkin menghasilkan penyesuaian yang berbeda bentuknya.
 *
 * Akibatnya: tiap batch meninggalkan satu baris `penyesuaian_stok` bernomor
 * PS-… beralasan. Riwayat lama TIDAK tersentuh — laporan bulan lalu, surat
 * jalan yang sudah dicetak, dan hasil opname tetap menunjuk batch yang sama.
 * Yang berubah hanya sisanya: nol.
 *
 * -----------------------------------------------------------------------------
 * TIGA PENJAGA, DAN KENAPA ADA
 * -----------------------------------------------------------------------------
 * 1. PRATINJAU ADALAH BAWAANNYA. Tanpa --terapkan, alat ini tidak menulis
 *    apa pun. Perintah yang salah ketik berakhir sebagai laporan, bukan
 *    sebagai gudang yang terlanjur dikosongkan.
 *
 * 2. NAMA LOKASI HARUS DIKETIK ULANG (--ya="..."). Menekan Enter pada
 *    pertanyaan "yakin?" adalah refleks; mengetik "Gudang Depo" bukan.
 *    Penjaga ini khusus melawan satu kesalahan yang paling mahal di sini —
 *    salah angka id, dan yang dikosongkan jadi gudang yang masih dipakai.
 *
 * 3. PELAKUNYA HARUS DISEBUT (--oleh=email). Penyesuaian stok adalah
 *    pernyataan bahwa barang titipan orang sudah tidak ada. Pernyataan
 *    seperti itu tidak boleh anonim, dan tidak boleh diatasnamakan akun
 *    pertama yang kebetulan ditemukan di basis data.
 * -----------------------------------------------------------------------------
 *
 * Pemakaian:
 *
 *   # 1. lihat dulu (tidak mengubah apa pun)
 *   php alat_nolkan_stok.php gudang 6 --alasan="Opname awal, rak kosong"
 *
 *   # 2. jalankan sungguhan
 *   php alat_nolkan_stok.php gudang 6 --alasan="Opname awal, rak kosong" \
 *       --oleh=admin@alfatihah.com --ya="Gudang Ramah" --terapkan
 *
 * Mitra/supplier TIDAK dilayani alat ini — stoknya ada di tabel
 * `stok_supplier`, bukan `batch_barang`, sehingga sesuaikan_batch() tidak
 * berlaku untuknya. Lihat pesan yang keluar bila dicoba.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Alat ini hanya untuk command line.\n");
}

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/_lib.php';

function n($v): string { return number_format((int) $v, 0, ',', '.'); }
function keluar(string $pesan): void { fwrite(STDERR, $pesan . "\n"); exit(1); }

/* ------------------------------------------------------------- baca argumen */
$tipe = $argv[1] ?? '';
$id   = (int) ($argv[2] ?? 0);
$opsi = ['alasan' => '', 'oleh' => '', 'ya' => '', 'terapkan' => false];
foreach (array_slice($argv, 3) as $a) {
    if ($a === '--terapkan') { $opsi['terapkan'] = true; continue; }
    if (preg_match('/^--(alasan|oleh|ya)=(.*)$/s', $a, $m)) { $opsi[$m[1]] = $m[2]; }
}

if ($tipe === 'supplier') {
    keluar("Stok mitra ada di tabel `stok_supplier`, terpisah dari batch gudang,\n"
         . "jadi sesuaikan_batch() tidak berlaku untuknya. Menolkannya butuh\n"
         . "jalur tersendiri — minta dibuatkan kalau memang diperlukan.");
}
if (!in_array($tipe, ['gudang', 'toko'], true) || $id <= 0) {
    keluar("Pemakaian: php alat_nolkan_stok.php gudang|toko <id> --alasan=\"...\"\n"
         . "           [--oleh=email --ya=\"Nama Lokasi\" --terapkan]");
}

/* ------------------------------------------------------ tentukan lingkupnya */
if ($tipe === 'gudang') {
    $nama = $pdo->query("SELECT nama FROM gudang WHERE id = $id AND deleted_at IS NULL")
                ->fetchColumn();
    $syarat = "bb.lokasi_rak_id IN (SELECT id FROM lokasi_rak WHERE gudang_id = $id)";
} else {
    $nama = $pdo->query("SELECT nama FROM toko WHERE id = $id AND deleted_at IS NULL")
                ->fetchColumn();
    $syarat = "bb.toko_id = $id";
}
if ($nama === false || $nama === null) { keluar("Lokasi tidak ditemukan."); }

$alasan = trim($opsi['alasan']);
if ($alasan === '') {
    keluar("--alasan wajib diisi. Barang di gudang ini titipan orang; setiap\n"
         . "selisih harus punya sebab yang tercatat, bukan sekadar 'reset'.");
}

/* -------------------------------------------------------- batch yang kena */
$batch = $pdo->query(
    "SELECT bb.id, bb.barang_id, bb.nomor_batch, bb.qty_tersisa, b.kode, b.nama
     FROM batch_barang bb JOIN barang b ON b.id = bb.barang_id
     WHERE $syarat AND bb.qty_tersisa > 0
     ORDER BY b.nama, bb.nomor_batch")->fetchAll(PDO::FETCH_ASSOC);

echo "\n", strtoupper($tipe), " #$id — $nama\n", str_repeat('=', 60), "\n";

if (!$batch) {
    echo "Tidak ada batch bersisa di lokasi ini. Sudah nol.\n\n";
    exit(0);
}

$totalUnit = 0;
foreach ($batch as $b) { $totalUnit += (int) $b['qty_tersisa']; }
$jenis = count(array_unique(array_column($batch, 'barang_id')));

printf("Akan dinolkan: %s batch · %s jenis barang · %s unit\n\n",
    n(count($batch)), n($jenis), n($totalUnit));
foreach ($batch as $b) {
    printf("  %-12s %-34s batch %-14s %8s -> 0\n",
        $b['kode'], mb_strimwidth($b['nama'], 0, 34, '…'),
        $b['nomor_batch'] ?: '—', n($b['qty_tersisa']));
}
printf("\nAlasan yang akan tercatat: \"%s\"\n", $alasan);

/* ------------------------------------------------------------- pratinjau? */
if (!$opsi['terapkan']) {
    echo "\n" . str_repeat('-', 60) . "\n";
    echo "PRATINJAU — belum ada satu pun yang diubah.\n\n";
    echo "Untuk menjalankannya sungguhan, ulangi perintah ini dengan tambahan:\n";
    printf("  --oleh=<email Anda> --ya=%s --terapkan\n", escapeshellarg($nama));
    echo "\n";
    exit(0);
}

/* ------------------------------------------------------------ penjaga 2 & 3 */
if ($opsi['ya'] !== $nama) {
    keluar("\n--ya harus berisi nama lokasinya PERSIS: \"$nama\"\n"
         . "Yang Anda tulis: \"" . $opsi['ya'] . "\"\n"
         . "Penjaga ini ada supaya salah angka id tidak berakhir sebagai\n"
         . "gudang lain yang terlanjur dikosongkan.");
}

$oleh = trim($opsi['oleh']);
if ($oleh === '') { keluar("--oleh=<email> wajib diisi saat --terapkan."); }
$st = $pdo->prepare(
    "SELECT u.id, u.nama, r.nama AS peran FROM users u
     JOIN roles r ON r.id = u.role_id
     WHERE u.email = ? AND u.deleted_at IS NULL AND u.is_active = 1");
$st->execute([$oleh]);
$pelaku = $st->fetch(PDO::FETCH_ASSOC);
if (!$pelaku) { keluar("Akun \"$oleh\" tidak ditemukan atau tidak aktif."); }
if (!in_array($pelaku['peran'], ['super_admin', 'admin_gudang', 'admin_terpadu'], true)) {
    keluar("Peran \"{$pelaku['peran']}\" tidak berwenang menyesuaikan stok gudang.");
}

/* ------------------------------------------------------------------ jalan */
echo "\n" . str_repeat('-', 60) . "\n";
printf("MENJALANKAN atas nama %s (%s)\n\n", $pelaku['nama'], $pelaku['peran']);

/* Satu transaksi untuk seluruhnya. Kalau satu batch gagal di tengah, yang
   sudah terlanjur dinolkan ikut dikembalikan — gudang setengah kosong
   dengan separuh riwayat penyesuaian jauh lebih sulit dibereskan daripada
   gudang yang belum disentuh sama sekali. */
$pdo->beginTransaction();
try {
    /* Mesinnya di _lib.php, dipakai bersama tombol Kosongkan di halaman Rak
       dan Gudang. Ditulis dua kali, cepat atau lambat salah satunya akan
       lupa memanggil recalc_stok() — dan gejalanya bukan galat, melainkan
       angka stok yang diam-diam salah sampai ada yang menghitung fisik. */
    $hasil = nolkan_stok_lokasi($pdo, $tipe, $id, $alasan, (int) $pelaku['id']);

    $pdo->commit();
    printf("Selesai. %s batch dinolkan, %s jenis barang dihitung ulang.\n",
        n($hasil['batch']), n($hasil['jenis']));
    echo "Tiap batch meninggalkan satu baris penyesuaian bernomor PS-… yang\n"
       . "bisa ditelusuri di halaman Batch & Expired dan laporan.\n\n";
} catch (Throwable $e) {
    $pdo->rollBack();
    keluar("GAGAL — tidak ada satu pun yang diubah.\n" . $e->getMessage());
}
