<?php
/**
 * AMANAH — Pengisi data contoh
 *
 * =====================================================================
 * CARA MENJALANKAN
 *   php data_contoh.php          isi data contoh
 *   php data_contoh.php --hapus  buang kembali SEMUA data contoh
 * =====================================================================
 *
 * Hanya dari command line. Kalau berkas ini bisa dipanggil lewat peramban,
 * siapa pun yang tahu namanya dapat membanjiri basis data dengan data palsu
 * tanpa perlu login sama sekali.
 *
 * TIGA ATURAN yang dipegang berkas ini:
 *
 * 1. SETIAP baris diberi penanda, sehingga bisa dibuang lagi seluruhnya.
 *    Data contoh yang tak bisa dibedakan dari data sungguhan adalah jebakan:
 *    ia ikut masuk laporan, dan tak ada yang tahu mana yang harus dihapus
 *    sebelum sistemnya dipakai betulan.
 *
 * 2. Nama orang TIDAK dikarang. Penerima diisi sebagai lembaga, dan nama
 *    pembeli marketplace memakai bentuk yang jelas contoh. Data penerima
 *    bantuan adalah data yang paling perlu dijaga, dan nama karangan
 *    berisiko menyerupai orang sungguhan.
 *
 * 3. Stok dihitung ULANG dari batch, tidak diisi terpisah. Mengisi
 *    stok.qty_total dan batch_barang masing-masing pernah membuat keduanya
 *    berselisih, dan selisih stok adalah jenis kesalahan yang paling lama
 *    tidak tertangkap — ia baru ketahuan saat angka laporan tidak cocok.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("403 — berkas ini hanya dijalankan dari command line.\n");
}

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/_lib.php';

/** Penanda yang ditinggalkan di setiap baris contoh. */
const TANDA = '[contoh]';

function keluar(string $s): void { fwrite(STDOUT, $s . PHP_EOL); }
function judul(string $s): void { keluar(''); keluar('--- ' . $s); }

/**
 * Hitung ulang stok.qty_total dari batch_barang.
 *
 * Dipanggil di akhir, sekali, untuk SEMUA barang. Stok tidak pernah diisi
 * baris demi baris di berkas ini — lihat aturan 3 di atas.
 */
function segarkan_stok(PDO $pdo): void
{
    $pdo->exec(
        "INSERT INTO stok (barang_id, qty_total)
         SELECT b.id, COALESCE((SELECT SUM(bb.qty_tersisa) FROM batch_barang bb
                                 WHERE bb.barang_id = b.id AND bb.toko_id IS NULL), 0)
         FROM barang b
         ON DUPLICATE KEY UPDATE qty_total = VALUES(qty_total)"
    );
}

// =====================================================================
// HAPUS
// =====================================================================
if (in_array('--hapus', $argv, true)) {
    keluar('Membuang seluruh data contoh...');

    /* Urutannya dari anak ke induk. Membalik urutannya berarti tertahan
       foreign key, dan pesan galat untuk itu tidak menyebutkan urutan mana
       yang salah — jadi urutan ini disusun dari daftar FK yang nyata, bukan
       dari dugaan. */
    $langkah = [
        ['penjualan_detail',       "DELETE d FROM penjualan_detail d JOIN penjualan p ON p.id=d.penjualan_id WHERE p.catatan LIKE ?"],
        ['penjualan',              "DELETE FROM penjualan WHERE catatan LIKE ?"],
        ['perpindahan_detail',     "DELETE d FROM perpindahan_detail d JOIN perpindahan p ON p.id=d.perpindahan_id WHERE p.catatan LIKE ?"],
        ['pengiriman_supplier',    "DELETE FROM pengiriman_supplier WHERE catatan LIKE ?"],
        ['perpindahan',            "DELETE FROM perpindahan WHERE catatan LIKE ?"],
        ['transaksi_masuk_detail', "DELETE d FROM transaksi_masuk_detail d JOIN transaksi_masuk h ON h.id=d.transaksi_masuk_id WHERE h.catatan LIKE ?"],
        ['transaksi_masuk',        "DELETE FROM transaksi_masuk WHERE catatan LIKE ?"],
        ['transaksi_keluar_detail',"DELETE d FROM transaksi_keluar_detail d JOIN transaksi_keluar h ON h.id=d.transaksi_keluar_id WHERE h.catatan LIKE ?"],
        ['transaksi_keluar',       "DELETE FROM transaksi_keluar WHERE catatan LIKE ?"],
        ['penyesuaian_stok',       "DELETE FROM penyesuaian_stok WHERE alasan LIKE ?"],
        ['stok_supplier',          "DELETE FROM stok_supplier WHERE keterangan LIKE ?"],
        ['batch_barang',           "DELETE FROM batch_barang WHERE qr_payload LIKE ?"],
        ['marketplace_sku',        "DELETE FROM marketplace_sku WHERE nama_di_marketplace LIKE ?"],
        // stok memegang FK ke barang, jadi barisnya harus pergi lebih dulu —
        // kalau tidak, DELETE barang di bawah gagal tanpa sebab yang jelas.
        ['stok',                   "DELETE s FROM stok s JOIN barang b ON b.id=s.barang_id WHERE b.keterangan LIKE ?"],
        ['penerima',               "DELETE FROM penerima WHERE keterangan LIKE ?"],
        ['barang',                 "DELETE FROM barang WHERE keterangan LIKE ?"],
        ['lokasi_rak',             "DELETE FROM lokasi_rak WHERE keterangan LIKE ?"],
    ];
    $gagal = 0;
    foreach ($langkah as [$nama, $sql]) {
        try {
            $st = $pdo->prepare($sql);
            $st->execute(['%' . TANDA . '%']);
            keluar(sprintf('  %-24s %d baris', $nama, $st->rowCount()));
        } catch (Throwable $e) {
            keluar(sprintf('  %-24s GAGAL: %s', $nama, $e->getMessage()));
            $gagal++;
        }
    }
    segarkan_stok($pdo);
    keluar('');
    keluar($gagal === 0
        ? 'Selesai. Data sungguhan tidak tersentuh.'
        : "Selesai dengan $gagal langkah gagal — periksa pesan di atas.");
    exit($gagal === 0 ? 0 : 1);
}

// =====================================================================
// ISI
// =====================================================================
keluar('Mengisi data contoh (penanda: ' . TANDA . ')');

// Sudah pernah dijalankan? Diperiksa, bukan diulang buta — menjalankan dua
// kali akan menggandakan semuanya dan angka laporan jadi mustahil dibaca.
$sudah = (int) $pdo->query(
    "SELECT COUNT(*) FROM penerima WHERE keterangan LIKE '%" . TANDA . "%'")->fetchColumn();
if ($sudah > 0) {
    keluar('');
    keluar("Data contoh sudah ada ($sudah penerima). Jalankan --hapus dulu bila");
    keluar('ingin mengisi ulang dari awal.');
    exit(0);
}

$uid = (int) $pdo->query(
    "SELECT id FROM users WHERE deleted_at IS NULL ORDER BY id LIMIT 1")->fetchColumn();
if ($uid <= 0) { exit("Tidak ada pengguna aktif — buat akun dulu.\n"); }

// ------------------------------------------------------------ PENERIMA
judul('Penerima Amanah');
/* Lembaga, bukan nama orang (aturan 2). Kolom `jenis` adalah enum dengan
   empat nilai saja — memasukkan 'posyandu' akan ditolak, jadi bentuk
   lembaganya ditulis di nama dan enumnya dipilih dari yang sah. */
$penerima = [
    ['Posyandu Melati (contoh)',        'lembaga',  'Bandung',      42,  'Balita & ibu menyusui'],
    ['Posyandu Kenanga (contoh)',       'lembaga',  'Bandung',      35,  'Balita'],
    ['Panti Asuh Harapan (contoh)',     'lembaga',  'Cimahi',       28,  'Anak asuh'],
    ['Majelis Taklim Annisa (contoh)',  'lembaga',  'Bandung',      60,  'Kaum ibu'],
    ['Kampung Binaan Ciparay (contoh)', 'lainnya',  'Kab. Bandung', 120, 'Wilayah binaan'],
    ['Keluarga Binaan Blok A (contoh)', 'keluarga', 'Bandung',      6,   'Keluarga prasejahtera'],
];
$stP = $pdo->prepare(
    "INSERT INTO penerima (kode, nama, jenis, wilayah, jml_jiwa, keterangan, is_active)
     VALUES (?,?,?,?,?,?,1)");
foreach ($penerima as [$n, $j, $w, $jw, $ket]) {
    $stP->execute([kode_berikut($pdo, 'penerima', 'PA'), $n, $j, $w, $jw, $ket . ' ' . TANDA]);
}
keluar('  ' . count($penerima) . ' penerima');

// ---------------------------------------------------------------- RAK
judul('Rak tambahan di gudang pusat');
$gudangPusat = $pdo->query(
    "SELECT id, nama FROM gudang WHERE jenis='pusat' AND deleted_at IS NULL ORDER BY kode")->fetchAll();
$stR = $pdo->prepare(
    "INSERT INTO lokasi_rak (kode, nama, gudang_id, gudang, kapasitas, keterangan)
     VALUES (?,?,?,?,?,?)");
$nRak = 0;
foreach ($gudangPusat as $g) {
    foreach (['RAK PAKAIAN', 'RAK MAINAN', 'RAK OBAT'] as $i => $nm) {
        $stR->execute([kode_berikut($pdo, 'lokasi_rak', 'RAK'), $nm,
                       (int) $g['id'], $g['nama'], 200 + $i * 50, 'Rak uji coba ' . TANDA]);
        $nRak++;
    }
}
keluar("  $nRak rak baru di " . count($gudangPusat) . ' gudang pusat');

// ------------------------------------------------------------- BARANG
judul('Produk');
$kategori     = $pdo->query("SELECT id, nama FROM kategori WHERE deleted_at IS NULL ORDER BY id")->fetchAll();
$supplierList = $pdo->query("SELECT id FROM supplier WHERE deleted_at IS NULL ORDER BY id")->fetchAll(PDO::FETCH_COLUMN);
$rakPusat     = $pdo->query(
    "SELECT lr.id FROM lokasi_rak lr JOIN gudang g ON g.id=lr.gudang_id
     WHERE g.jenis='pusat' AND lr.deleted_at IS NULL ORDER BY lr.id")->fetchAll(PDO::FETCH_COLUMN);

if (!$kategori || !$rakPusat) { exit("Butuh minimal satu kategori dan satu rak gudang pusat.\n"); }

/* Produk disebar ke SEMUA kategori, satu per kategori. Menumpuk semuanya di
   satu kategori membuat laporan per kategori tampak rusak: sepuluh dari
   sebelas barisnya nol. Urutan daftar ini dicocokkan dengan urutan kategori
   yang ada (SUSU, PAMPERS, SKINCARE, OBAT JAMU, ...). */
$contohProduk = [
    ['Susu Formula Tahap 1',   'kaleng', 89000, 10],
    ['Popok Perekat M',        'pack',   67000, 12],
    ['Sabun Bayi Cair',        'pcs',    24500, 15],
    ['Minyak Telon 60ml',      'pcs',    18000, 20],
    ['Bubur Bayi Beras Merah', 'box',    28500, 15],
    ['Beras Kemasan 5kg',      'pack',   72000,  8],
    ['Botol Susu 240ml',       'pcs',    45000, 10],
    ['Baju Bayi Katun',        'pcs',    38000, 10],
    ['Mainan Edukasi Balok',   'set',    65000,  6],
    ['Sabun Cuci Cair 800ml',  'pcs',    21000, 12],
    ['Termometer Digital',     'pcs',    85000,  5],
    ['Susu Formula Tahap 2',   'kaleng', 92000, 10],
];
$stB = $pdo->prepare(
    "INSERT INTO barang (kode, barcode, nama, kategori_id, merek, satuan,
                         harga, stok_minimum, supplier_id, lokasi_rak_id, keterangan, is_active)
     VALUES (?,?,?,?,?,?,?,?,?,?,?,1)");
$barangBaru = [];
foreach ($contohProduk as $i => [$nama, $satuan, $harga, $min]) {
    $stB->execute([
        kode_berikut($pdo, 'barang', 'BBY', 4),
        // Barcode contoh: 13 angka berawalan 899 (prefiks GS1 Indonesia),
        // supaya bentuknya masuk akal saat dipakai menguji pemindai.
        '899' . str_pad((string) (100000000 + $i), 10, '0', STR_PAD_LEFT),
        $nama, (int) $kategori[$i % count($kategori)]['id'], 'Merek Contoh', $satuan,
        $harga, $min,
        $supplierList ? (int) $supplierList[$i % count($supplierList)] : null,
        (int) $rakPusat[$i % count($rakPusat)],
        'Produk uji coba ' . TANDA,
    ]);
    $barangBaru[] = ['id' => (int) $pdo->lastInsertId(), 'nama' => $nama, 'harga' => $harga];
}
keluar('  ' . count($barangBaru) . ' produk tersebar di ' . count($kategori) . ' kategori');

// -------------------------------------------------------------- BATCH
judul('Batch & stok gudang');
$stBt = $pdo->prepare(
    "INSERT INTO batch_barang (barang_id, nomor_batch, kondisi, lokasi_rak_id,
                               qr_payload, tanggal_masuk, tanggal_expired,
                               qty_awal, qty_tersisa, harga_beli, supplier_id)
     VALUES (?,?,?,?,?,?,?,?,?,?,?)");
$nBatch = 0;
foreach ($barangBaru as $i => $b) {
    /* Tanggal kedaluwarsa disebar SENGAJA melewati keempat ambang halaman
       Batch & Expired — aman (>90 hari), waspada (<=90), segera (<=30), dan
       sudah lewat. Kalau semuanya "aman", halaman itu tidak pernah
       memperlihatkan tampilan peringatannya, dan tak ada yang tahu ia
       benar-benar bekerja. */
    $sebar = [400, 75, 20, -10, 200][$i % 5];
    $qty   = [80, 45, 30, 12, 60][$i % 5];
    $stBt->execute([
        $b['id'],
        'B' . date('ymd') . '-' . str_pad((string) ($i + 1), 2, '0', STR_PAD_LEFT),
        $i % 4 === 3 ? 'bekas' : 'baru',
        (int) $rakPusat[$i % count($rakPusat)],
        'contoh-' . $b['id'] . ' ' . TANDA,
        date('Y-m-d', strtotime('-' . (5 + $i) . ' days')),
        date('Y-m-d', strtotime($sebar . ' days')),
        $qty, $qty, round($b['harga'] * 0.8, 2),
        $supplierList ? (int) $supplierList[$i % count($supplierList)] : null,
    ]);
    $nBatch++;
}
segarkan_stok($pdo);
keluar("  $nBatch batch (kedaluwarsa disebar ke 4 ambang), stok dihitung ulang dari batch");

// -------------------------------------------------------- STOK DI TOKO
judul('Stok yang sudah ada di toko');
/* Stok toko duduk di batch_barang dengan toko_id terisi dan lokasi_rak_id
   NULL — bukan di tabel terpisah. Tanpa baris ini, dasbor toko selalu
   berbunyi "semua stok toko dalam kondisi baik", karena memang tak ada stok
   toko sama sekali untuk dinilai. Satu barang sengaja ditaruh DI BAWAH stok
   minimumnya, supaya kartu peringatannya benar-benar pernah tampil. */
$tokoSemua = $pdo->query(
    "SELECT id, nama FROM toko WHERE deleted_at IS NULL ORDER BY id")->fetchAll();
$stBtToko = $pdo->prepare(
    "INSERT INTO batch_barang (barang_id, nomor_batch, kondisi, lokasi_rak_id, toko_id,
                               qr_payload, tanggal_masuk, tanggal_expired,
                               qty_awal, qty_tersisa, harga_beli, supplier_id)
     VALUES (?,?,'baru',NULL,?,?,?,?,?,?,?,?)");
$nBt = 0;
foreach ($tokoSemua as $ti => $tk) {
    foreach (array_slice($barangBaru, $ti % 4, 3) as $j => $b) {
        // Barang pertama tiap toko sengaja tipis; sisanya berkecukupan.
        $qty = $j === 0 ? 2 : (15 + $j * 5);
        $stBtToko->execute([
            $b['id'], 'TK' . date('ymd') . '-' . $tk['id'] . '-' . ($j + 1), (int) $tk['id'],
            'contoh-toko-' . $tk['id'] . '-' . $b['id'] . ' ' . TANDA,
            date('Y-m-d', strtotime('-' . (3 + $j) . ' days')),
            date('Y-m-d', strtotime('+' . (120 + $j * 30) . ' days')),
            $qty, $qty, round($b['harga'] * 0.8, 2),
            $supplierList ? (int) $supplierList[$ti % count($supplierList)] : null,
        ]);
        $nBt++;
    }
}
keluar("  $nBt batch di " . count($tokoSemua) . ' toko (satu per toko sengaja di bawah stok minimum)');

// ----------------------------------------------------- STOK SUPPLIER
judul('Stok siap kirim di gudang supplier');
$stSS = $pdo->prepare(
    "INSERT INTO stok_supplier (supplier_id, barang_id, lokasi_rak_id, nomor_batch,
                                kondisi, tanggal_expired, qty, harga, tanggal_masuk, keterangan)
     VALUES (?,?,?,?,'baru',?,?,?,?,?)");
$nSS = 0;
foreach ($supplierList as $si => $sid) {
    $rakSup = (int) $pdo->query(
        "SELECT lr.id FROM lokasi_rak lr JOIN gudang g ON g.id=lr.gudang_id
         WHERE g.jenis='supplier' AND g.supplier_id=" . (int) $sid . "
           AND lr.deleted_at IS NULL LIMIT 1")->fetchColumn();
    if (!$rakSup) { keluar('  (lewat supplier ' . $sid . ' — belum punya rak)'); continue; }

    foreach (array_slice($barangBaru, $si * 3, 3) as $j => $b) {
        $stSS->execute([(int) $sid, $b['id'], $rakSup,
                        'SUP' . date('ymd') . '-' . $sid . '-' . ($j + 1),
                        date('Y-m-d', strtotime('+180 days')), 25 + $j * 10,
                        round($b['harga'] * 0.75, 2), date('Y-m-d'), 'Siap kirim ' . TANDA]);
        $nSS++;
    }
}
keluar("  $nSS baris stok supplier");

// ------------------------------------------------ TRANSAKSI MASUK
judul('Transaksi barang masuk');
$nTM = 0;
for ($k = 0; $k < 3; $k++) {
    $pdo->prepare("INSERT INTO transaksi_masuk (no_transaksi, tanggal, supplier_id, user_id, total, catatan)
                   VALUES (?,?,?,?,?,?)")
        ->execute([nomor_dokumen($pdo, 'transaksi_masuk', 'no_transaksi', 'TM'),
                   date('Y-m-d H:i:s', strtotime('-' . ($k * 7 + 3) . ' days')),
                   $supplierList ? (int) $supplierList[$k % count($supplierList)] : null,
                   $uid, 0, 'Penerimaan uji coba ' . TANDA]);
    $tmId  = (int) $pdo->lastInsertId();
    $total = 0;
    foreach (array_slice($barangBaru, $k * 3, 3) as $b) {
        $qty = 20 + $k * 5;
        $sub = $qty * $b['harga'];
        $pdo->prepare("INSERT INTO transaksi_masuk_detail
                        (transaksi_masuk_id, barang_id, nomor_batch, tanggal_expired,
                         lokasi_rak_id, qty, harga, subtotal)
                       VALUES (?,?,?,?,?,?,?,?)")
            ->execute([$tmId, $b['id'], 'B' . date('ymd') . '-TM' . $k,
                       date('Y-m-d', strtotime('+365 days')),
                       (int) $rakPusat[0], $qty, $b['harga'], $sub]);
        $total += $sub;
    }
    // Total header diisi dari jumlah detailnya, bukan angka terpisah — sama
    // alasannya dengan stok: dua sumber angka pasti berselisih pada akhirnya.
    $pdo->prepare("UPDATE transaksi_masuk SET total=? WHERE id=?")->execute([$total, $tmId]);
    $nTM++;
}
keluar("  $nTM dokumen barang masuk (total dihitung dari detail)");

// ----------------------------------------------- TRANSAKSI KELUAR
judul('Transaksi barang keluar');
$penerimaNama = $pdo->query(
    "SELECT nama FROM penerima WHERE deleted_at IS NULL ORDER BY id")->fetchAll(PDO::FETCH_COLUMN);
$nTK = 0;
for ($k = 0; $k < 3; $k++) {
    $pdo->prepare("INSERT INTO transaksi_keluar
                    (no_transaksi, tanggal, user_id, penerima, divisi_cabang, catatan)
                   VALUES (?,?,?,?,?,?)")
        ->execute([nomor_dokumen($pdo, 'transaksi_keluar', 'no_transaksi', 'TK'),
                   date('Y-m-d H:i:s', strtotime('-' . ($k * 5 + 1) . ' days')), $uid,
                   $penerimaNama[$k % max(1, count($penerimaNama))] ?? 'Penerima Contoh',
                   'Divisi Sosial', 'Penyaluran uji coba ' . TANDA]);
    $tkId = (int) $pdo->lastInsertId();
    foreach (array_slice($barangBaru, $k * 2, 2) as $b) {
        $pdo->prepare("INSERT INTO transaksi_keluar_detail (transaksi_keluar_id, barang_id, qty)
                       VALUES (?,?,?)")->execute([$tkId, $b['id'], 5 + $k]);
    }
    $nTK++;
}
keluar("  $nTK dokumen barang keluar");

// ------------------------------------------------------- PERPINDAHAN
judul('Perpindahan / surat jalan');
$tokoList = $pdo->query(
    "SELECT id, nama, marketplace FROM toko WHERE deleted_at IS NULL ORDER BY id LIMIT 4")->fetchAll();
$penerimaIds = $pdo->query(
    "SELECT id FROM penerima WHERE deleted_at IS NULL ORDER BY id")->fetchAll(PDO::FETCH_COLUMN);

/* Dibuat pada BEBERAPA status sekaligus, supaya halaman Persetujuan, Surat
   Jalan, dan Penerimaan masing-masing punya isi. Kalau semua dokumennya
   berstatus 'selesai', ketiga halaman itu tampak kosong dan orang menyangka
   fiturnya belum jalan. */
$rencana = [
    ['penerimaan', 'draft',                'rak',      'rak'],
    ['penerimaan', 'menunggu_persetujuan', 'supplier', 'rak'],
    ['mutasi_rak', 'disetujui',            'rak',      'rak'],
    ['kirim_toko', 'dikirim',              'rak',      'toko'],
    ['kirim_toko', 'selesai',              'rak',      'toko'],
    ['penyaluran', 'selesai',              'rak',      'penerima'],
    ['penerimaan', 'ditolak',              'supplier', 'rak'],
    /* Dua baris terakhir bertujuan ke RAK, bukan toko — dan itu perlu
       disebut alasannya. Halaman Penerimaan sisi gudang hanya memandang
       dokumen yang tujuannya gudang/rak; kalau semua dokumen 'dikirim' dan
       'selesai' mengarah ke toko, halaman itu tampak kosong dan orang
       menyimpulkan fitur penerimaan gudang tidak jalan. */
    ['penerimaan', 'dikirim',              'supplier', 'rak'],
    ['penerimaan', 'selesai',              'supplier', 'rak'],
];
$nPP = 0;
foreach ($rencana as $i => [$jenis, $status, $aTipe, $tTipe]) {
    $asalId = $aTipe === 'rak'
        ? (int) $rakPusat[0]
        : ($supplierList ? (int) $supplierList[0] : null);

    if ($tTipe === 'rak') {
        $tujuanId = (int) ($rakPusat[1] ?? $rakPusat[0]);
    } elseif ($tTipe === 'toko') {
        $tujuanId = $tokoList ? (int) $tokoList[$i % count($tokoList)]['id'] : null;
    } else {
        $tujuanId = $penerimaIds ? (int) $penerimaIds[$i % count($penerimaIds)] : null;
    }

    $sudahJalan   = in_array($status, ['dikirim', 'selesai'], true);
    $sudahDinilai = in_array($status, ['disetujui', 'dikirim', 'selesai', 'ditolak'], true);

    $pdo->prepare(
        "INSERT INTO perpindahan (no_perpindahan, no_surat_jalan, jenis, tanggal,
             asal_tipe, asal_id, tujuan_tipe, tujuan_id, no_resi, ekspedisi,
             status, alasan_tolak, user_id, approved_by, approved_at,
             diterima_oleh, diterima_at, catatan)
         VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)"
    )->execute([
        nomor_dokumen($pdo, 'perpindahan', 'no_perpindahan', 'PP'),
        $sudahJalan ? nomor_dokumen($pdo, 'perpindahan', 'no_surat_jalan', 'SJ') : null,
        $jenis, date('Y-m-d H:i:s', strtotime('-' . (10 - $i) . ' days')),
        $aTipe, $asalId, $tTipe, $tujuanId,
        $sudahJalan ? 'RESI' . strtoupper(substr(md5('pp' . $i), 0, 8)) : null,
        $sudahJalan ? 'JNE' : null,
        $status,
        $status === 'ditolak' ? 'Jumlah tidak sesuai permintaan ' . TANDA : null,
        $uid,
        $sudahDinilai ? $uid : null,
        $sudahDinilai ? date('Y-m-d H:i:s', strtotime('-' . (9 - $i) . ' days')) : null,
        $status === 'selesai' ? $uid : null,
        $status === 'selesai' ? date('Y-m-d H:i:s', strtotime('-' . (8 - $i) . ' days')) : null,
        'Dokumen uji coba ' . TANDA,
    ]);
    $ppId = (int) $pdo->lastInsertId();

    foreach (array_slice($barangBaru, $i, 2) as $b) {
        $qty = 5 + $i;
        $pdo->prepare("INSERT INTO perpindahan_detail
                        (perpindahan_id, barang_id, nomor_batch, qty, qty_diterima, harga, subtotal)
                       VALUES (?,?,?,?,?,?,?)")
            ->execute([$ppId, $b['id'], 'B' . date('ymd') . '-PP' . $i, $qty,
                       // qty_diterima sengaja NULL selama belum selesai: itulah
                       // yang membedakan "belum diterima" dari "diterima nol"
                       // di halaman Penerimaan.
                       $status === 'selesai' ? $qty : null,
                       $b['harga'], $qty * $b['harga']]);
    }
    $nPP++;
}
keluar("  $nPP dokumen pada " . count(array_unique(array_column($rencana, 1))) . ' status berbeda');

// ----------------------------------------------- PENGIRIMAN SUPPLIER
judul('Kiriman dari supplier');
$gudangTujuan = (int) $pdo->query(
    "SELECT id FROM gudang WHERE jenis='pusat' AND deleted_at IS NULL ORDER BY kode LIMIT 1")->fetchColumn();
$nKS = 0;
foreach ($supplierList as $sid) {
    foreach ([['dikirim', '-2 days', '+1 day'], ['tiba', '-6 days', '-4 days']] as $j => [$st, $kirim, $tiba]) {
        $pdo->prepare(
            "INSERT INTO pengiriman_supplier (no_pengiriman, supplier_id, gudang_tujuan_id,
                 no_resi, ekspedisi, tanggal_kirim, estimasi_tiba, status, catatan, dibuat_oleh)
             VALUES (?,?,?,?,?,?,?,?,?,?)"
        )->execute([
            nomor_dokumen($pdo, 'pengiriman_supplier', 'no_pengiriman', 'KS'),
            (int) $sid, $gudangTujuan ?: null,
            'RESISUP' . strtoupper(substr(md5($sid . '-' . $j), 0, 6)), 'SiCepat',
            date('Y-m-d', strtotime($kirim)), date('Y-m-d', strtotime($tiba)),
            $st, 'Kiriman uji coba ' . TANDA, $uid,
        ]);
        $nKS++;
    }
}
keluar("  $nKS kiriman supplier");

// ---------------------------------------------------------- PENJUALAN
judul('Pesanan marketplace');
/* Disebar melewati SELURUH tahap pesanan dan SELURUH keadaan pembayaran,
   supaya dasbor toko memperlihatkan setiap kolomnya terisi — termasuk
   pemisahan uang yang masih ditahan marketplace dan uang yang sudah cair.
   Justru bagian itu yang paling sering ditanyakan, dan paling mudah luput
   kalau data contohnya hanya berisi pesanan selesai. */
$tahap = [
    ['baru',    'READY_TO_SHIP', 'belum',    'belum',   null, null],
    ['dikemas', 'PROCESSED',     'diproses', 'ditahan', 0.88, null],
    ['dikirim', 'SHIPPED',       'dikirim',  'ditahan', 0.88, null],
    ['selesai', 'COMPLETED',     'sampai',   'cair',    0.88, '-3 days'],
    ['batal',   'CANCELLED',     'belum',    'batal',   null, null],
];
$stPJ = $pdo->prepare(
    "INSERT INTO penjualan (no_penjualan, toko_id, tanggal, marketplace, no_pesanan,
         no_resi, kurir, nama_pembeli, total, ongkir, status, status_marketplace,
         status_kirim, tgl_kirim, tgl_sampai, escrow_amount, komisi, biaya_layanan,
         biaya_transaksi, voucher, status_bayar, tgl_settlement, sumber, catatan,
         user_id, ditarik_at)
     VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,'api',?,?,NOW())");
$nPJ = 0;
foreach ($tokoList as $ti => $tk) {
    foreach ($tahap as $si => [$st, $stMp, $stKirim, $stBayar, $rasio, $settle]) {
        $b      = $barangBaru[($ti * 5 + $si) % count($barangBaru)];
        $qty    = 1 + ($si % 3);
        $total  = $qty * $b['harga'];
        $escrow = $rasio ? round($total * $rasio, 2) : null;
        $jalan  = in_array($stKirim, ['dikirim', 'sampai'], true);

        $stPJ->execute([
            nomor_dokumen($pdo, 'penjualan', 'no_penjualan', 'JL'),
            (int) $tk['id'],
            date('Y-m-d H:i:s', strtotime('-' . ($si + 1) . ' days')),
            // toko.marketplace boleh kosong; penjualan.marketplace tidak.
            $tk['marketplace'] ?: 'offline',
            'INV/' . date('Ymd') . '/' . $tk['id'] . str_pad((string) $si, 2, '0', STR_PAD_LEFT),
            $jalan ? 'RESIMP' . strtoupper(substr(md5($ti . '-' . $si), 0, 7)) : null,
            $jalan ? 'J&T' : null,
            'Pembeli Contoh ' . chr(65 + $si),
            $total, 12000, $st, $stMp, $stKirim,
            $jalan ? date('Y-m-d H:i:s', strtotime('-' . ($si + 1) . ' days +6 hour')) : null,
            $stKirim === 'sampai' ? date('Y-m-d H:i:s', strtotime('-' . $si . ' days')) : null,
            $escrow,
            $escrow ? round($total * 0.05, 2) : 0,
            $escrow ? 2500 : 0,
            0, 0,
            $stBayar,
            $settle ? date('Y-m-d', strtotime($settle)) : null,
            'Pesanan uji coba ' . TANDA, $uid,
        ]);
        $pjId = (int) $pdo->lastInsertId();
        $pdo->prepare("INSERT INTO penjualan_detail
                        (penjualan_id, barang_id, sku_marketplace, qty, harga_jual, subtotal)
                       VALUES (?,?,?,?,?,?)")
            ->execute([$pjId, $b['id'], 'SKU-' . $tk['id'] . '-' . $b['id'],
                       $qty, $b['harga'], $total]);
        $nPJ++;
    }
}
keluar("  $nPJ pesanan di " . count($tokoList) . ' toko — 5 tahap, 4 keadaan pembayaran');

// -------------------------------------------------------- SKU MARKETPLACE
judul('Pemetaan SKU marketplace');
$nSku = 0;
foreach ($tokoList as $tk) {
    foreach (array_slice($barangBaru, 0, 5) as $b) {
        try {
            $pdo->prepare("INSERT INTO marketplace_sku
                            (toko_id, barang_id, sku_marketplace, nama_di_marketplace)
                           VALUES (?,?,?,?)")
                ->execute([(int) $tk['id'], $b['id'],
                           'SKU-' . $tk['id'] . '-' . $b['id'], $b['nama'] . ' ' . TANDA]);
            $nSku++;
        } catch (PDOException $e) {
            if ($e->getCode() !== '23000') { throw $e; }   // 23000 = sudah dipetakan
        }
    }
}
keluar("  $nSku pemetaan SKU");

// ------------------------------------------------------ PENYESUAIAN STOK
judul('Penyesuaian stok');
/* lokasi_tipe hanya mengenal 'gudang' dan 'toko' — raknya ikut lewat
   lokasi_rak_id, bukan lewat lokasi_tipe. Jenisnya juga enum tertutup, jadi
   dipakai nilai yang benar-benar ada di skema. */
$sesuai = [
    ['gudang', 'rusak',          2, 'Kemasan rusak saat bongkar'],
    ['gudang', 'kedaluwarsa',    3, 'Batch lewat tanggal, ditarik dari rak'],
    ['gudang', 'koreksi_tambah', 4, 'Temuan selisih saat opname'],
    ['gudang', 'koreksi_kurang', 1, 'Koreksi salah hitung'],
    ['gudang', 'hilang',         1, 'Tidak ditemukan saat opname'],
];
$nPS = 0;
foreach ($sesuai as $i => [$lok, $jenis, $qty, $alasan]) {
    $b = $barangBaru[$i % count($barangBaru)];
    $pdo->prepare(
        "INSERT INTO penyesuaian_stok (no_penyesuaian, tanggal, lokasi_tipe,
             lokasi_rak_id, barang_id, jenis, qty, alasan, user_id)
         VALUES (?,?,?,?,?,?,?,?,?)"
    )->execute([
        nomor_dokumen($pdo, 'penyesuaian_stok', 'no_penyesuaian', 'PS'),
        date('Y-m-d H:i:s', strtotime('-' . ($i + 1) . ' days')),
        $lok, (int) $rakPusat[0], $b['id'], $jenis, $qty,
        $alasan . ' ' . TANDA, $uid,
    ]);
    $nPS++;
}
keluar("  $nPS penyesuaian, lima jenis berbeda");

// ---------------------------------------------------------------- SELESAI
segarkan_stok($pdo);

keluar('');
keluar('=== RINGKASAN ISI TABEL ===');
foreach (['kategori','barang','stok','lokasi_rak','penerima','batch_barang','stok_supplier',
          'transaksi_masuk','transaksi_keluar','perpindahan','perpindahan_detail',
          'pengiriman_supplier','penjualan','penjualan_detail','marketplace_sku',
          'penyesuaian_stok'] as $tb) {
    keluar(sprintf('  %-22s %s baris', $tb,
        $pdo->query("SELECT COUNT(*) FROM `$tb`")->fetchColumn()));
}
keluar('');
keluar('Semua baris contoh bertanda ' . TANDA . '. Buang kembali dengan:');
keluar('  php data_contoh.php --hapus');
