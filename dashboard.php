<?php
/**
 * AMANAH — Dashboard (sadar peran)
 *
 * Satu berkas, tiga isi. Yang ditampilkan dipilih menurut peran, bukan
 * disembunyikan dengan CSS:
 *
 *   Super Admin  seluruh rantai pasok — supplier, gudang, toko, penjualan
 *   Admin Gudang operasional gudang hari ini
 *   Pimpinan     pengawasan & antrean persetujuan (tanpa tombol ubah)
 *
 * Prinsip: DATA dulu, hiasan belakangan. Tabel dipakai untuk hal yang
 * perlu dibandingkan; grafik hanya untuk yang benar-benar terbaca sebagai
 * bentuk. Angka nol ditampilkan sebagai nol — tidak disembunyikan.
 *
 * Seluruh gaya dari assets/amanah.css. Halaman ini tanpa blok <style>.
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/_lib.php';
require_login();

if (is_eksternal()) { header('Location: ' . beranda_peran()); exit; }

$u        = current_user();
$role     = role_saat_ini();
$isSuper  = $role === 'super_admin';
$isPimpin = $role === 'pimpinan';
// Keduanya memegang satu gudang saja, jadi dasbornya menyempit sama.
$isGudang = in_array($role, ['admin_gudang', 'admin_terpadu'], true);
/** Pimpinan & Super Admin melihat seluruh rantai; Admin Gudang tidak. */
$lihatRantai = $isSuper || $isPimpin;

function skalar(PDO $pdo, string $sql, $default = 0) {
    try { return $pdo->query($sql)->fetchColumn(); }
    catch (Throwable $e) { error_log('[AMANAH] dashboard: ' . $e->getMessage()); return $default; }
}
function baris(PDO $pdo, string $sql): array {
    try { return $pdo->query($sql)->fetchAll(); }
    catch (Throwable $e) { error_log('[AMANAH] dashboard: ' . $e->getMessage()); return []; }
}

// =====================================================================
// LINGKUP — dihitung SEKALI, dipakai seluruh kueri di bawah
// =====================================================================
/* ----------------------------------------------------------------------
   KENAPA BLOK INI ADA

   Sebelumnya setiap angka di halaman ini dihitung untuk SELURUH lembaga,
   lalu dipajang dengan label yang berbunyi seolah tentang gudang orang
   yang sedang melihatnya — "Total Stok · Unit di gudang", "Hampir Habis ·
   Produk", "Mendekati Exp · Batch ≤ 90 hari". Admin Gudang Depo membuka
   dasbornya dan membaca stok, batch kedaluwarsa, serta dokumen milik
   Gudang Ramah sebagai miliknya sendiri.

   Itu dua kerugian sekaligus: angkanya salah bagi yang membacanya, dan
   keadaan gudang lain terbuka bagi yang tidak mengurusnya.

   Lingkupnya dihitung sekali di sini, bukan diulang di tiap kueri. Dua
   puluh kueri yang masing-masing menyusun syaratnya sendiri adalah dua
   puluh kesempatan untuk lupa satu.

   Untuk Super Admin dan Pimpinan, gudang_saya() mengembalikan SELURUH
   gudang — jadi penyaringan yang sama tidak mempersempit apa pun bagi
   mereka. Tidak ada cabang "kalau super admin, lewati saja": cabang
   seperti itu adalah tempat kebocoran berikutnya bersembunyi.
   ---------------------------------------------------------------------- */
$inGudang = in_gudang_saya($pdo);   // '5,6'  — '0' bila lingkupnya kosong
$inRak    = in_rak_saya($pdo);      // '12,13' — '0' bila lingkupnya kosong

/* ----------------------------------------------------------------------
   MENGAWASI DOKUMEN ≠ MEMBUKA SURAT JALANNYA

   syarat_perpindahan() menjawab pertanyaan "surat jalan mana yang boleh
   saya BUKA dan CETAK". Untuk Pimpinan jawabannya sengaja tidak satu pun:
   lembar cetaknya memuat nama penerima manfaat beserta alamatnya, dan
   Pimpinan tidak perlu membukanya satu per satu untuk menjalankan
   tugasnya. Itu keputusan yang sudah diambil dan tetap berlaku.

   Yang ditanyakan widget di halaman ini bukan itu: berapa dokumen per
   status, per jenis, mana yang terbaru, dan mana yang menunggu keputusan.
   Pimpinan justru diangkat untuk mengawasi itu — dan dialah yang
   menyetujuinya. Memakai syarat cetak di sini membuat separuh dasbor
   Pimpinan kosong beserta antrean persetujuannya sendiri; sempat terjadi
   saat menulis perubahan ini, dan tertangkap waktu diuji per peran.

   Jadi dua pertanyaan itu diberi dua nama. Yang di bawah ini soal
   PENGAWASAN; yang soal membuka lembarnya tetap di surat_jalan.php dan
   cek_surat.php, tidak disentuh.
   ---------------------------------------------------------------------- */
$syaratDok  = ($isSuper || $isPimpin) ? '1=1' : syarat_perpindahan($pdo, 'p');
/** Bentuk subkueri, untuk view yang tidak membawa kolom asal/tujuan. */
$idDokBoleh = "SELECT p.id FROM perpindahan p WHERE $syaratDok";

/* Batch di luar gudang mana pun — yang dipegang toko atau supplier —
   TETAP dihitung bagi yang melihat seluruh rantai. Ia bukan urusan satu
   gudang, tapi tetap urusan lembaga: stok kedaluwarsa yang menginap di
   toko tidak boleh hilang dari pandangan Super Admin hanya karena
   halaman ini mulai menyaring per gudang. */
$batchLingkup = $lihatRantai
    ? "(bb.lokasi_rak_id IN ($inRak) OR bb.lokasi_rak_id IS NULL)"
    : "bb.lokasi_rak_id IN ($inRak)";
$expLingkup = $lihatRantai
    ? "(gudang_id IN ($inGudang) OR gudang_id IS NULL)"
    : "gudang_id IN ($inGudang)";

// =====================================================================
// PENYARING — periode & lokasi
// =====================================================================
/* ----------------------------------------------------------------------
   URUTANNYA PENTING: LINGKUP DULU, PILIHAN BELAKANGAN

   Daftar pilihan lokasi dibangun DARI lingkup di atas, lalu pilihan yang
   datang dari alamat dicocokkan ke daftar itu. Bukan sebaliknya.

   Kalau dibalik — ambil `?lokasi=gudang:6` lalu dipakai apa adanya —
   Admin Gudang Depo cukup mengganti satu angka di alamat untuk membaca
   isi Gudang Ramah, dan seluruh pembatasan di atas jadi sia-sia. Dengan
   urutan ini, pilihan yang tidak ada di daftarnya sendiri jatuh diam-diam
   kembali ke "seluruh lingkup saya": tidak ada halaman galat yang
   memberi tahu penebak bahwa gudang itu ADA.
   ---------------------------------------------------------------------- */

/* --- periode. Hanya angka ARUS yang mengikutinya (masuk, keluar,
       dokumen). Angka PERSEDIAAN — total stok, hampir habis, batch
       kedaluwarsa — adalah potret saat ini; "stok bulan ini" tidak
       berarti apa-apa, jadi ia sengaja tidak ikut berubah. Kartunya
       diberi keterangan "saat ini" supaya bedanya terbaca. */
$PERIODE = [
    'hari'   => ['label' => 'Hari Ini',  'sejak' => 'CURDATE()'],
    'minggu' => ['label' => '7 Hari',    'sejak' => 'DATE_SUB(CURDATE(), INTERVAL 6 DAY)'],
    'bulan'  => ['label' => 'Bulan Ini', 'sejak' => "DATE_FORMAT(CURDATE(), '%Y-%m-01')"],
];
$gPeriode = $_GET['periode'] ?? null;
$periode  = (is_string($gPeriode) && isset($PERIODE[$gPeriode])) ? $gPeriode : 'hari';
$sejak    = $PERIODE[$periode]['sejak'];
$labelPeriode = $PERIODE[$periode]['label'];

/* --- daftar lokasi yang BOLEH dipilih akun ini */
$opsiLokasi = [];   // 'gudang:5' => ['grup' => 'Gudang', 'nama' => 'Gudang Depo']
foreach (baris($pdo, "SELECT id, nama FROM gudang
                      WHERE deleted_at IS NULL AND id IN ($inGudang) ORDER BY kode") as $g) {
    $opsiLokasi["gudang:{$g['id']}"] = ['grup' => 'Gudang', 'nama' => $g['nama']];
}
/* Toko dan supplier hanya untuk yang memang melihat seluruh rantai.
   Admin Gudang tidak mengurus gerai, dan memberinya pilihan itu berarti
   membuka persediaan toko yang bukan tanggung jawabnya. */
if ($lihatRantai) {
    foreach (baris($pdo, "SELECT id, nama FROM toko
                          WHERE deleted_at IS NULL ORDER BY kode") as $t) {
        $opsiLokasi["toko:{$t['id']}"] = ['grup' => 'Toko / Outlet', 'nama' => $t['nama']];
    }
    foreach (baris($pdo, "SELECT id, nama FROM supplier
                          WHERE deleted_at IS NULL ORDER BY kode") as $s) {
        $opsiLokasi["supplier:{$s['id']}"] = ['grup' => 'Supplier', 'nama' => $s['nama']];
    }
}

$gLokasi = $_GET['lokasi'] ?? null;
$lokasi  = (is_string($gLokasi) && isset($opsiLokasi[$gLokasi])) ? $gLokasi : '';
$lokTipe = ''; $lokId = 0; $lokNama = '';
if ($lokasi !== '') {
    [$lokTipe, $sisa] = explode(':', $lokasi, 2);
    $lokId   = (int) $sisa;
    $lokNama = $opsiLokasi[$lokasi]['nama'];
}

/* --- pilihan lokasi MEMPERSEMPIT lingkup, tidak pernah melebarkannya.
       Tiap cabang menulis ulang potongan syarat yang sudah ada di atas,
       jadi seluruh kueri di bawah ikut menyempit tanpa satu pun perlu
       tahu bahwa ada penyaring. */
$lokSupplier = false;             // ditangani terpisah: tabelnya lain
if ($lokTipe === 'gudang') {
    $rakLok  = baris($pdo, "SELECT id FROM lokasi_rak
                            WHERE gudang_id = $lokId AND deleted_at IS NULL");
    $inRak   = $rakLok ? implode(',', array_column($rakLok, 'id')) : '0';
    $inGudang = (string) $lokId;
    $batchLingkup = "bb.lokasi_rak_id IN ($inRak)";
    $expLingkup   = "gudang_id = $lokId";
} elseif ($lokTipe === 'toko') {
    /* Persediaan toko memakai kolom yang berbeda: batch yang dipegang
       gerai tidak punya rak, ia punya toko_id. */
    $batchLingkup = "bb.toko_id = $lokId";
    $expLingkup   = "toko_id = $lokId";
    $inRak = '0'; $inGudang = '0';
} elseif ($lokTipe === 'supplier') {
    $lokSupplier = true;
    $batchLingkup = '1=0';        // stok mitra tidak ada di batch_barang
    $expLingkup   = '1=0';
    $inRak = '0'; $inGudang = '0';
}

/* Begitu satu lokasi dipilih, yang dihitung hanya barang yang BENAR-BENAR
   ada di sana — bukan seluruh katalog. Tanpa ini, memilih satu toko
   membuat "Habis" melonjak ke ribuan: seluruh katalog lembaga yang tidak
   pernah dikirim ke gerai itu ikut terhitung kosong. */
$sambungStokPilihan = $lokasi !== '';

// =====================================================================
// ANGKA POKOK — dipakai semua peran
// =====================================================================
/* Katalog barang & kategori TIDAK disaring selama belum ada lokasi yang
   dipilih: keduanya master data milik lembaga, bukan isi rak. "Produk ·
   Jenis terdaftar" memang menghitung seluruh katalog, dan menyempitkannya
   ke barang yang kebetulan sedang ada stoknya akan membuat angkanya
   berubah-ubah setiap kali rak kosong.

   Begitu SATU lokasi dipilih, pertanyaannya berubah: yang ditanya bukan
   lagi "berapa jenis yang lembaga ini punya" melainkan "berapa jenis yang
   ada DI SINI". Labelnya pun ikut berubah di bagian tampilan. */
$totalJenis  = $lokasi === ''
    ? (int) skalar($pdo, "SELECT COUNT(*) FROM barang WHERE deleted_at IS NULL")
    : (int) skalar($pdo, "SELECT COUNT(DISTINCT bb.barang_id) FROM batch_barang bb
                          WHERE bb.qty_tersisa > 0 AND $batchLingkup");

/* Stok dihitung dari batch_barang, BUKAN dari tabel `stok`.

   `stok.qty_total` satu baris per barang tanpa kolom gudang sama sekali —
   ia singgahan seluruh lembaga, jadi secara bentuk memang tidak bisa
   disaring per gudang. Lagi pula tabel sebaran di bawah halaman ini sudah
   menjumlahkan batch_barang per gudang, sehingga angka besar di atas dan
   jumlah baris tabel di bawahnya bisa berselisih tanpa ada yang salah
   mengetik. Sekarang keduanya satu sumber. */
$totalStok   = (int)   skalar($pdo, "SELECT COALESCE(SUM(bb.qty_tersisa),0)
                                     FROM batch_barang bb
                                     WHERE bb.qty_tersisa > 0 AND $batchLingkup");
$nilaiStok   = (float) skalar($pdo, "SELECT COALESCE(SUM(bb.qty_tersisa*b.harga),0)
                                     FROM batch_barang bb
                                     JOIN barang b ON b.id = bb.barang_id
                                     WHERE bb.qty_tersisa > 0 AND $batchLingkup");

/* v_status_stok tidak bisa dipakai lagi: ia membaca `stok` yang global,
   sehingga tidak ada kolom untuk disaring. Diganti hitungan setara yang
   berlingkup, dengan ambang yang sama persis dengan view itu — habis
   berarti nol, hampir habis berarti 1..stok_minimum.

   Jenis sambungannya BERBEDA menurut peran, dan itu disengaja:

     JOIN      untuk peran yang terikat gudang — hanya barang yang memang
               pernah ada di gudangnya yang dihitung. Dengan LEFT JOIN,
               seluruh katalog yang tak pernah singgah di sini ikut
               terhitung "habis", dan Admin Gudang Depo membuka dasbornya
               melihat ratusan barang habis yang tak satu pun urusannya.

     LEFT JOIN untuk yang melihat seluruh rantai — pertanyaan mereka
               memang tentang katalog: "barang apa yang stoknya nol di
               mana pun", yaitu daftar pengadaan. Dengan JOIN, angka itu
               hilang sama sekali; sempat terjadi, dan angka 11 di dasbor
               Super Admin mendadak jadi 0 tanpa ada stok yang berubah. */
$sambungStok = ($lihatRantai && !$sambungStokPilihan) ? 'LEFT JOIN' : 'JOIN';
$stokLingkup = "SELECT b.id, b.stok_minimum, COALESCE(SUM(bb.qty_tersisa),0) AS qty
                  FROM barang b
                  $sambungStok batch_barang bb
                       ON bb.barang_id = b.id AND $batchLingkup
                 WHERE b.deleted_at IS NULL
                 GROUP BY b.id, b.stok_minimum";
$hampirHabis = (int)   skalar($pdo, "SELECT COUNT(*) FROM ($stokLingkup) t
                                     WHERE t.qty > 0 AND t.qty <= t.stok_minimum");
$habis       = (int)   skalar($pdo, "SELECT COUNT(*) FROM ($stokLingkup) t WHERE t.qty <= 0");

$expSegera   = (int)   skalar($pdo, "SELECT COUNT(*) FROM v_status_expired
                                     WHERE status_expired='segera_expired' AND $expLingkup");
$expWaspada  = (int)   skalar($pdo, "SELECT COUNT(*) FROM v_status_expired
                                     WHERE status_expired='waspada' AND $expLingkup");
$expExpired  = (int)   skalar($pdo, "SELECT COUNT(*) FROM v_status_expired
                                     WHERE status_expired='expired' AND $expLingkup");
$mendekati   = $expSegera + $expWaspada;

/* --- persediaan MITRA datang dari tabel yang berbeda.
   stok_supplier berdiri sendiri, bukan bagian dari batch_barang, jadi
   cabang di atas menghasilkan nol untuknya. Angkanya ditimpa DI SINI,
   setelah nol itu terbentuk — pola yang sama dipakai api/dashboard.php,
   supaya web dan aplikasi menjawab dengan cara yang sama. */
if ($lokSupplier) {
    $totalStok = (int) skalar($pdo,
        "SELECT COALESCE(SUM(qty),0) FROM stok_supplier WHERE supplier_id = $lokId");
    $nilaiStok = (float) skalar($pdo,
        "SELECT COALESCE(SUM(qty*harga),0) FROM stok_supplier WHERE supplier_id = $lokId");
    $totalJenis = (int) skalar($pdo,
        "SELECT COUNT(DISTINCT barang_id) FROM stok_supplier
          WHERE supplier_id = $lokId AND qty > 0");
    $expSegera = (int) skalar($pdo,
        "SELECT COUNT(*) FROM stok_supplier WHERE supplier_id = $lokId AND qty > 0
           AND tanggal_expired BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 90 DAY)");
    $expExpired = (int) skalar($pdo,
        "SELECT COUNT(*) FROM stok_supplier WHERE supplier_id = $lokId AND qty > 0
           AND tanggal_expired IS NOT NULL AND tanggal_expired < CURDATE()");
    $expWaspada = 0;
    $mendekati  = $expSegera;
    $hampirHabis = $habis = 0;   // mitra tidak punya batas stok minimum
}

/* transaksi_masuk & transaksi_keluar sendiri TIDAK punya kolom gudang —
   yang tahu tempatnya baris rinciannya: masuk lewat lokasi_rak_id, keluar
   lewat batch yang diambilnya. Jadi penyaringannya dilakukan di sana. */
$masukHari  = (int) skalar($pdo, "SELECT COALESCE(SUM(d.qty),0) FROM transaksi_masuk_detail d
                                  JOIN transaksi_masuk h ON h.id=d.transaksi_masuk_id
                                  WHERE DATE(h.tanggal) >= $sejak
                                    AND d.lokasi_rak_id IN ($inRak)");
$keluarHari = (int) skalar($pdo, "SELECT COALESCE(SUM(d.qty),0) FROM transaksi_keluar_detail d
                                  JOIN transaksi_keluar h ON h.id=d.transaksi_keluar_id
                                  JOIN batch_barang bb    ON bb.id = d.batch_id
                                  WHERE DATE(h.tanggal) >= $sejak
                                    AND bb.lokasi_rak_id IN ($inRak)");

$menunggu   = (int) skalar($pdo, "SELECT COUNT(*) FROM perpindahan p
                                  WHERE p.status='menunggu_persetujuan' AND $syaratDok");
/* "Perlu diterima" sengaja TIDAK memakai $syaratDok. Syarat itu benar untuk
   "dokumen yang boleh saya lihat" — dan itu mencakup dokumen yang saya
   KIRIM. Kiriman yang saya kirim ke gudang lain bukan kiriman yang perlu
   saya terima; yang dihitung di sini hanya yang tujuannya ke tempat saya. */
$perluTerima= (int) skalar($pdo, "SELECT COUNT(*) FROM perpindahan p
                                  WHERE p.status='dikirim'
                                    AND ((p.tujuan_tipe='gudang' AND p.tujuan_id IN ($inGudang))
                                      OR (p.tujuan_tipe='rak'    AND p.tujuan_id IN ($inRak)))");
$kirimanAda = (int) skalar($pdo, "SELECT COUNT(*) FROM pengiriman_supplier
                                  WHERE status IN ('dikirim','tiba')
                                    AND gudang_tujuan_id IN ($inGudang)");
$diJalan    = (int) skalar($pdo, "SELECT COUNT(*) FROM perpindahan p
                                  WHERE p.status='dikirim' AND $syaratDok");

// =====================================================================
// SEBARAN STOK PER LOKASI
// =====================================================================
$stokGudang = baris($pdo,
    "SELECT g.id, g.kode, g.nama,
            COUNT(DISTINCT lr.id) AS jml_rak,
            COUNT(DISTINCT bb.barang_id) AS jenis,
            COALESCE(SUM(bb.qty_tersisa),0) AS qty,
            COALESCE(SUM(bb.qty_tersisa * b.harga),0) AS nilai
     FROM gudang g
     LEFT JOIN lokasi_rak lr   ON lr.gudang_id = g.id AND lr.deleted_at IS NULL
     LEFT JOIN batch_barang bb ON bb.lokasi_rak_id = lr.id AND bb.qty_tersisa > 0
     LEFT JOIN barang b        ON b.id = bb.barang_id
     WHERE g.deleted_at IS NULL AND g.id IN ($inGudang)
     GROUP BY g.id ORDER BY qty DESC, g.kode");

$stokToko = $lihatRantai ? baris($pdo,
    "SELECT tk.id, tk.kode, tk.nama, tk.marketplace,
            COUNT(DISTINCT bb.barang_id) AS jenis,
            COALESCE(SUM(bb.qty_tersisa),0) AS qty,
            COALESCE(SUM(bb.qty_tersisa * b.harga),0) AS nilai
     FROM toko tk
     LEFT JOIN batch_barang bb ON bb.toko_id = tk.id AND bb.qty_tersisa > 0
     LEFT JOIN barang b        ON b.id = bb.barang_id
     WHERE tk.deleted_at IS NULL
     GROUP BY tk.id ORDER BY qty DESC, tk.kode") : [];

$stokSupplier = $lihatRantai ? baris($pdo,
    "SELECT sp.id, sp.kode, sp.nama,
            COUNT(DISTINCT ss.barang_id) AS jenis,
            COALESCE(SUM(ss.qty),0) AS qty,
            (SELECT COUNT(*) FROM pengiriman_supplier ps WHERE ps.supplier_id = sp.id) AS kiriman,
            (SELECT COUNT(*) FROM pengiriman_supplier ps
              WHERE ps.supplier_id = sp.id AND ps.status = 'diterima') AS diterima
     FROM supplier sp
     LEFT JOIN stok_supplier ss ON ss.supplier_id = sp.id AND ss.qty > 0
     WHERE sp.deleted_at IS NULL
     GROUP BY sp.id ORDER BY qty DESC, sp.kode") : [];

// =====================================================================
// STATUS DOKUMEN PERPINDAHAN
// =====================================================================
$STATUS_URUT = ['draft', 'menunggu_persetujuan', 'disetujui', 'dikirim', 'diterima', 'selesai', 'ditolak'];
$dokStatusRaw = baris($pdo,
    "SELECT status, COUNT(*) AS jml, COALESCE(SUM(
            (SELECT SUM(pd.qty) FROM perpindahan_detail pd WHERE pd.perpindahan_id = p.id)
     ),0) AS qty
     FROM perpindahan p WHERE $syaratDok GROUP BY status");
$dokStatus = [];
foreach ($dokStatusRaw as $d) { $dokStatus[$d['status']] = $d; }
$totalDok = array_sum(array_column($dokStatusRaw, 'jml'));

$dokJenis = baris($pdo,
    "SELECT jenis, COUNT(*) AS jml,
            SUM(status IN ('selesai','diterima')) AS tuntas,
            SUM(status = 'dikirim') AS jalan
     FROM perpindahan p WHERE $syaratDok GROUP BY jenis ORDER BY jml DESC");

/* v_perpindahan_ringkas tidak membawa kolom asal_tipe/asal_id, jadi
   syarat_perpindahan() tidak bisa ditempelkan langsung padanya. Disaring
   lewat daftar id — aturannya tetap satu, hanya bentuknya yang berbeda. */
$dokTerbaru = baris($pdo,
    "SELECT no_perpindahan, no_surat_jalan, jenis, tanggal, status,
            asal_nama, tujuan_nama, total_qty, jml_item, no_resi
     FROM v_perpindahan_ringkas
     WHERE id IN ($idDokBoleh)
     ORDER BY tanggal DESC, id DESC LIMIT 8");

// =====================================================================
// PENJUALAN (rantai)
// =====================================================================
$jualToko = $lihatRantai ? baris($pdo,
    "SELECT tk.nama, tk.marketplace,
            COUNT(DISTINCT p.id) AS pesanan,
            COALESCE(SUM(d.qty),0) AS unit,
            COALESCE(SUM(d.subtotal),0) AS omzet,
            SUM(p.status = 'baru') AS baru
     FROM toko tk
     LEFT JOIN penjualan p       ON p.toko_id = tk.id
     LEFT JOIN penjualan_detail d ON d.penjualan_id = p.id
     WHERE tk.deleted_at IS NULL
     GROUP BY tk.id ORDER BY omzet DESC") : [];

$topProduk = baris($pdo,
    "SELECT b.kode, b.nama, b.satuan, COALESCE(SUM(d.qty),0) AS terjual
     FROM penjualan_detail d
     JOIN barang b ON b.id = d.barang_id
     GROUP BY b.id ORDER BY terjual DESC LIMIT 6");

// =====================================================================
// PERLU PERHATIAN
// =====================================================================
$expiredDekat = baris($pdo,
    "SELECT nama_barang, nomor_batch, tanggal_expired, qty_tersisa, sisa_hari,
            status_expired, lokasi_nama
     FROM v_status_expired
     WHERE status_expired IN ('expired','segera_expired','waspada')
       AND $expLingkup
     ORDER BY sisa_hari ASC LIMIT 7");

/* Sejalan dengan $hampirHabis / $habis di atas: dihitung dari batch di
   dalam lingkup, bukan dari v_status_stok yang global. */
$stokRendah = baris($pdo,
    "SELECT b.kode AS kode_barang, b.nama AS nama_barang,
            COALESCE(k.nama,'—') AS kategori,
            COALESCE(SUM(bb.qty_tersisa),0) AS stok, b.stok_minimum,
            CASE WHEN COALESCE(SUM(bb.qty_tersisa),0) = 0 THEN 'habis'
                 ELSE 'hampir_habis' END AS status_stok
     FROM barang b
     $sambungStok batch_barang bb ON bb.barang_id = b.id AND $batchLingkup
     LEFT JOIN kategori k ON k.id = b.kategori_id
     WHERE b.deleted_at IS NULL
     GROUP BY b.id, b.kode, b.nama, k.nama, b.stok_minimum
     HAVING stok <= b.stok_minimum
     ORDER BY stok ASC LIMIT 7");

/* ----------------------------------------------------------------------
   PRODUK DI SATU LOKASI

   Yang diminta: "produk di masing-masing toko". Tabel Stok per Toko yang
   sudah ada hanya menjawab BERAPA — sembilan gerai dengan angka unitnya,
   tanpa satu pun nama barang. Daftar ini menjawab APA.

   Hanya dihitung bila ada lokasi yang dipilih. Tanpa pilihan, daftar ini
   akan berisi seluruh persediaan lembaga dalam satu tabel panjang, dan
   itu bukan ringkasan lagi — halaman Data Barang sudah melakukannya
   dengan pencarian dan penyaring yang jauh lebih baik.
   ---------------------------------------------------------------------- */
$produkLokasi = [];
if ($lokasi !== '' && !$lokSupplier) {
    $produkLokasi = baris($pdo,
        "SELECT b.kode, b.nama, b.satuan, COALESCE(k.nama,'—') AS kategori,
                COUNT(DISTINCT bb.id) AS batch,
                COALESCE(SUM(bb.qty_tersisa),0) AS qty,
                COALESCE(SUM(bb.qty_tersisa * b.harga),0) AS nilai,
                MIN(CASE WHEN bb.tanggal_expired IS NOT NULL
                         THEN bb.tanggal_expired END) AS exp_terdekat
         FROM batch_barang bb
         JOIN barang b   ON b.id = bb.barang_id AND b.deleted_at IS NULL
         LEFT JOIN kategori k ON k.id = b.kategori_id
         WHERE bb.qty_tersisa > 0 AND $batchLingkup
         GROUP BY b.id, b.kode, b.nama, b.satuan, k.nama
         ORDER BY qty DESC, b.nama LIMIT 25");
} elseif ($lokSupplier) {
    /* Mitra: tabelnya stok_supplier, bukan batch_barang. Kolomnya
       disamakan supaya tampilannya satu, bukan dua tabel kembar. */
    $produkLokasi = baris($pdo,
        "SELECT b.kode, b.nama, b.satuan, COALESCE(k.nama,'—') AS kategori,
                COUNT(*) AS batch,
                COALESCE(SUM(ss.qty),0) AS qty,
                COALESCE(SUM(ss.qty * ss.harga),0) AS nilai,
                MIN(CASE WHEN ss.tanggal_expired IS NOT NULL
                         THEN ss.tanggal_expired END) AS exp_terdekat
         FROM stok_supplier ss
         JOIN barang b   ON b.id = ss.barang_id AND b.deleted_at IS NULL
         LEFT JOIN kategori k ON k.id = b.kategori_id
         WHERE ss.qty > 0 AND ss.supplier_id = $lokId
         GROUP BY b.id, b.kode, b.nama, b.satuan, k.nama
         ORDER BY qty DESC, b.nama LIMIT 25");
}

$antrean = ($isPimpin || $isSuper) ? baris($pdo,
    "SELECT no_perpindahan, no_surat_jalan, jenis, tanggal,
            asal_nama, tujuan_nama, total_qty, jml_item, pembuat
     FROM v_perpindahan_ringkas
     WHERE status = 'menunggu_persetujuan' AND id IN ($idDokBoleh)
     ORDER BY tanggal ASC LIMIT 6") : [];

/* ----------------------------------------------------------------------
   AKTIVITAS — disaring per REKAN SEGUDANG, bukan per gudang

   activity_log tidak punya kolom gudang sama sekali; yang tercatat siapa
   yang berbuat, bukan di mana. Jadi yang bisa disaring pelakunya: petugas
   yang berbagi gudang dengan saya, ditambah diri saya sendiri.

   Itu pendekatan, bukan jawaban tepat — seorang Super Admin yang berbuat
   di gudang lain tidak akan tersaring, karena lingkupnya seluruh gudang.
   Tapi jauh lebih baik daripada sebelumnya, ketika baris "menghapus batch
   di Gudang Ramah" muncul utuh di dasbor Admin Gudang Depo.
   ---------------------------------------------------------------------- */
$syaratAktivitas = $lihatRantai ? '1=1' : "(
        a.user_id = " . (int) ($u['id'] ?? 0) . "
     OR a.user_id IN (SELECT ug.user_id FROM user_gudang ug
                       WHERE ug.gudang_id IN ($inGudang))
     OR a.user_id IN (SELECT u2.id FROM users u2
                       WHERE u2.gudang_id IN ($inGudang)))";
$aktivitas = baris($pdo,
    "SELECT a.aksi, a.modul, a.deskripsi, a.created_at, u.nama AS petugas
     FROM activity_log a LEFT JOIN users u ON u.id = a.user_id
     WHERE $syaratAktivitas
     ORDER BY a.id DESC LIMIT 7");

/* Distribusi kategori ikut menyempit begitu satu lokasi dipilih.

   Tanpa itu, halaman berbicara dengan dua suara sekaligus: kartu "Produk"
   berkata 1 jenis di toko ini, sementara diagram di bawahnya menggambar
   seluruh 1.309 produk katalog — dan yang membacanya tidak punya cara
   menebak mana yang sedang dijawab. Selama belum ada pilihan, ia memang
   katalog lembaga, dan itu benar. */
$kat = $lokasi === '' ? baris($pdo,
    "SELECT k.nama, COUNT(b.id) AS jml
     FROM kategori k LEFT JOIN barang b ON b.kategori_id=k.id AND b.deleted_at IS NULL
     WHERE k.deleted_at IS NULL GROUP BY k.id ORDER BY jml DESC, k.nama")
  : ($lokSupplier ? baris($pdo,
    "SELECT COALESCE(k.nama,'Tanpa kategori') AS nama, COUNT(DISTINCT b.id) AS jml
     FROM stok_supplier ss
     JOIN barang b ON b.id = ss.barang_id AND b.deleted_at IS NULL
     LEFT JOIN kategori k ON k.id = b.kategori_id
     WHERE ss.qty > 0 AND ss.supplier_id = $lokId
     GROUP BY k.id, k.nama ORDER BY jml DESC, nama")
  : baris($pdo,
    "SELECT COALESCE(k.nama,'Tanpa kategori') AS nama, COUNT(DISTINCT b.id) AS jml
     FROM batch_barang bb
     JOIN barang b ON b.id = bb.barang_id AND b.deleted_at IS NULL
     LEFT JOIN kategori k ON k.id = b.kategori_id
     WHERE bb.qty_tersisa > 0 AND $batchLingkup
     GROUP BY k.id, k.nama ORDER BY jml DESC, nama"));

// Hitungan simpul, hanya perlu bagi yang melihat rantai.
$nSupplier = $lihatRantai ? (int) skalar($pdo, "SELECT COUNT(*) FROM supplier WHERE deleted_at IS NULL") : 0;
$nGudang   = (int) skalar($pdo, "SELECT COUNT(*) FROM gudang WHERE deleted_at IS NULL
                                   AND id IN ($inGudang)");
$nToko     = $lihatRantai ? (int) skalar($pdo, "SELECT COUNT(*) FROM toko WHERE deleted_at IS NULL") : 0;
$nPesanan  = $lihatRantai ? (int) skalar($pdo, "SELECT COUNT(*) FROM penjualan WHERE status='baru'") : 0;

// =====================================================================
// PENOLONG TAMPILAN
// =====================================================================
function rupiah_pendek(float $n): string {
    if ($n >= 1e9) return 'Rp' . rtrim(rtrim(number_format($n / 1e9, 1, ',', '.'), '0'), ',') . '<small> M</small>';
    if ($n >= 1e6) return 'Rp' . rtrim(rtrim(number_format($n / 1e6, 1, ',', '.'), '0'), ',') . '<small> jt</small>';
    if ($n >= 1e3) return 'Rp' . number_format($n / 1e3, 0, ',', '.') . '<small> rb</small>';
    return rupiah($n);
}
/** Bar mini dalam sel tabel — perbandingan tanpa perlu grafik terpisah. */
function bar_mini(int $nilai, int $maks, string $warna = 'var(--primary-2)'): string {
    $persen = $maks > 0 ? max(2, round($nilai / $maks * 100)) : 0;
    return '<span class="barmini"><i style="width:' . $persen . '%;background:' . $warna . '"></i></span>';
}

/* Keterangan kartu "Produk" ikut berubah saat lokasi dipilih: yang
   dihitung bukan lagi katalog lembaga melainkan isi tempat itu. Label
   yang tidak ikut berubah akan berbohong tentang angkanya sendiri. */
$ketJenis = $lokasi === '' ? 'Jenis terdaftar' : "Jenis di " . $lokNama;

// KPI dipilih per peran — bukan satu daftar untuk semua.
if ($isSuper) {
    $KPI = [
        ['Supplier',        angka($nSupplier),   'Mitra pemasok',    'i-truck',   'var(--accent)'],
        ['Gudang',          angka($nGudang),     'Lokasi simpan',    'i-gudang',  'var(--accent)'],
        ['Toko / Outlet',   angka($nToko),       'Gerai penyalur',   'i-toko',    'var(--accent)'],
        ['Produk',          angka($totalJenis),  $ketJenis,          'i-box',     'var(--primary-2)'],
        ['Total Stok',      angka($totalStok),   'Unit · saat ini',  'i-layers',  'var(--primary-2)'],
        ['Nilai Stok',      rupiah_pendek($nilaiStok), 'Persediaan · saat ini', 'i-uang', 'var(--primary-2)', true],
        ['Menunggu Setuju', angka($menunggu),    'Dokumen',          'i-clock',   'var(--amber)'],
        ['Dalam Perjalanan',angka($diJalan),     'Dokumen dikirim',  'i-truck',   'var(--info)'],
        ['Pesanan Baru',    angka($nPesanan),    'Belum diproses',   'i-doc',     'var(--info)'],
        ['Hampir Habis',    angka($hampirHabis), 'Produk',           'i-alert',   'var(--amber)'],
        ['Mendekati Exp',   angka($mendekati),   'Batch ≤ 90 hari',  'i-cal',     'var(--amber)'],
        ['Sudah Expired',   angka($expExpired),  'Batch',            'i-x',       'var(--red)'],
    ];
} elseif ($isPimpin) {
    $KPI = [
        ['Menunggu Setuju', angka($menunggu),    'Perlu keputusan Anda', 'i-clock', 'var(--amber)'],
        ['Dalam Perjalanan',angka($diJalan),     'Dokumen dikirim',      'i-truck', 'var(--info)'],
        ['Total Dokumen',   angka($totalDok),    'Perpindahan tercatat', 'i-doc',   'var(--primary-2)'],
        ['Nilai Stok',      rupiah_pendek($nilaiStok), 'Persediaan',      'i-uang',  'var(--primary-2)', true],
        ['Total Stok',      angka($totalStok),   'Unit di gudang',       'i-layers','var(--primary-2)'],
        ['Hampir Habis',    angka($hampirHabis), 'Produk',               'i-alert', 'var(--amber)'],
        ['Mendekati Exp',   angka($mendekati),   'Batch ≤ 90 hari',      'i-cal',   'var(--amber)'],
        ['Sudah Expired',   angka($expExpired),  'Batch',                'i-x',     'var(--red)'],
    ];
} else {
    $KPI = [
        ['Total Stok',      angka($totalStok),   'Unit tersedia · saat ini', 'i-layers', 'var(--primary-2)'],
        ['Produk',          angka($totalJenis),  $ketJenis,         'i-box',    'var(--primary-2)'],
        ['Masuk',           angka($masukHari),   "Unit · $labelPeriode",  'i-masuk',  'var(--green)'],
        ['Keluar',          angka($keluarHari),  "Unit · $labelPeriode",  'i-keluar', 'var(--info)'],
        ['Perlu Diterima',  angka($perluTerima), 'Kiriman tiba',    'i-paket',  'var(--amber)'],
        ['Menunggu Setuju', angka($menunggu),    'Dokumen',         'i-clock',  'var(--amber)'],
        ['Hampir Habis',    angka($hampirHabis), 'Produk',          'i-alert',  'var(--amber)'],
        ['Sudah Expired',   angka($expExpired),  'Batch',           'i-x',      'var(--red)'],
    ];
}

$AKSI_CEPAT = $isPimpin
    ? [['Persetujuan', 'persetujuan.php', 'i-cek-bulat', 'var(--green)'],
       ['Mitra & Lokasi', 'pengawasan.php', 'i-lokasi', 'var(--primary-2)'],
       ['Batch & Expired', 'monitoring.php', 'i-cal', 'var(--amber)'],
       ['Laporan Stok', 'laporan.php', 'i-grafik', 'var(--muted)']]
    : [['Scan Resi Paket', 'scan_resi.php', 'i-scan', 'var(--primary-2)'],
       ['Barang Masuk', 'masuk.php', 'i-masuk', 'var(--green)'],
       ['Barang Keluar', 'keluar.php', 'i-keluar', 'var(--info)'],
       /* "Kirim ke Toko" berdiri sendiri, terpisah dari "Buat Surat Jalan"
          yang umum. Keduanya membuka formulir yang sama — bedanya jenis
          dokumennya sudah terisi.

          Alasannya bukan menghemat satu ketukan. Menu yang ada berbunyi
          "Surat Jalan", dan di dalamnya ada delapan jenis dokumen; tidak
          ada satu pun kata "toko" yang terlihat sebelum daftar itu
          dibuka. Orang yang mencari cara membagi barang ke gerai tidak
          punya petunjuk bahwa ia ada di sana. */
       ['Kirim ke Toko', 'perpindahan_form.php?jenis=kirim_toko', 'i-toko', 'var(--green)'],
       ['Buat Surat Jalan', 'perpindahan_form.php', 'i-doc', 'var(--primary-2)'],
       ['Penerimaan', 'penerimaan.php', 'i-paket', 'var(--accent)'],
       ['Batch & Expired', 'monitoring.php', 'i-cal', 'var(--amber)'],
       ['Impor Data', 'impor_data.php', 'i-impor', 'var(--accent)'],
       ['Laporan Stok', 'laporan.php', 'i-grafik', 'var(--muted)']];

$JUDUL_PERAN = $isSuper ? 'Seluruh rantai pasok dalam satu layar'
             : ($isPimpin ? 'Pengawasan & keputusan yang menunggu Anda'
                          : 'Kondisi gudang dan pekerjaan hari ini');

// =====================================================================
// PANDUAN PENYIAPAN
//
// Sistem gudang punya urutan yang tidak bisa dilompati: tanpa rak, stok
// tidak punya tempat; tanpa produk, tidak ada yang bisa dicatat; tanpa
// akun mitra, dasbor supplier & toko selalu kosong.
//
// Dulu semua itu hanya ketahuan setelah petugas mentok di tengah kerja.
// Kartu ini memunculkannya lebih dulu, dan HILANG SENDIRI begitu semua
// langkah beres — supaya tidak jadi hiasan permanen.
// =====================================================================
$langkah = [];
if (!$isPimpin) {
    $jmlKategori = (int) skalar($pdo, "SELECT COUNT(*) FROM kategori WHERE deleted_at IS NULL");
    $jmlGudang   = (int) skalar($pdo, "SELECT COUNT(*) FROM gudang WHERE deleted_at IS NULL
                                         AND id IN ($inGudang)");
    $jmlProduk   = $totalJenis;
    $jmlAkunMitra= (int) skalar($pdo, "SELECT COUNT(*) FROM users u JOIN roles r ON r.id=u.role_id
                                       WHERE r.nama IN ('supplier','toko') AND u.deleted_at IS NULL");
    $jmlMitra    = (int) skalar($pdo, "SELECT (SELECT COUNT(*) FROM supplier WHERE deleted_at IS NULL)
                                            + (SELECT COUNT(*) FROM toko WHERE deleted_at IS NULL)");
    // Gudang yang belum punya rak sama sekali — ini yang paling sering
    // bikin mentok, karena barang masuk langsung ditolak tanpa rak.
    $gudangTanpaRak = (int) skalar($pdo,
        "SELECT COUNT(*) FROM gudang g WHERE g.deleted_at IS NULL
           AND g.id IN ($inGudang)
           AND NOT EXISTS (SELECT 1 FROM lokasi_rak lr
                           WHERE lr.gudang_id = g.id AND lr.deleted_at IS NULL)");

    $langkah = [
        ['Kategori produk', $jmlKategori > 0, 'kategori.php',
         $jmlKategori . ' kategori siap', 'Bikin kategori dulu biar produk gampang dicari'],
        ['Gudang & rak', $jmlGudang > 0 && $gudangTanpaRak === 0, 'rak.php',
         $gudangTanpaRak === 0 ? 'Semua gudang punya rak' : $gudangTanpaRak . ' gudang belum punya rak',
         'Stok disimpan per rak — tanpa rak, barang nggak bisa masuk'],
        ['Produk', $jmlProduk > 0, 'barang_form.php',
         $jmlProduk . ' produk terdaftar', 'Tambah produk atau impor sekaligus dari Excel'],
        ['Akun mitra', $jmlAkunMitra > 0, 'pengguna.php',
         $jmlAkunMitra . ' dari ' . $jmlMitra . ' mitra punya login',
         'Supplier & toko butuh akun biar bisa catat sendiri'],
        ['Stok masuk', $totalStok > 0, 'masuk.php',
         angka($totalStok) . ' unit tersimpan', 'Catat barang masuk biar stoknya kebaca'],
    ];
}
$belumBeres = array_values(array_filter($langkah, fn($l) => !$l[1]));
$sudahBeres = count($langkah) - count($belumBeres);

$PAGE_TITLE = 'Dashboard'; $ACTIVE = 'dashboard';
require __DIR__ . '/_header.php';
?>
<div class="page-head">
  <div>
    <h1>Halo, <?= e(explode(' ', $namaUser)[0]) ?>! 👋</h1>
    <p><?= e($JUDUL_PERAN) ?></p>
  </div>
  <div class="aksi" style="color:var(--muted);font-size:12.5px;text-align:right">
    <div>
      <?php /* Baris tanggal memakai gaya 'hari', BUKAN 'lengkap'. Dulu di sini
               tgl_id(date('Y-m-d'), 'lengkap') — dan karena date('Y-m-d')
               tidak membawa jam, tanggalnya selalu ditempeli "00:00". Jamnya
               memang bukan urusan baris ini; ia ada di baris di bawah. */ ?>
      <div style="font-weight:650;color:var(--text-2)" id="tglHariIni"><?= e(tgl_id(date('Y-m-d'), 'hari')) ?></div>
      <div><span id="jamWib"><?= date('H:i') ?></span> WIB · <?= e($u['role_label'] ?? '') ?></div>
    </div>
    <span class="thumb" style="width:37px;height:37px">
      <svg class="ikon" style="stroke:var(--muted)"><use href="#i-cal"></use></svg>
    </span>
  </div>
</div>

<?php if ($menunggu > 0 || $perluTerima > 0 || $kirimanAda > 0): ?>
  <div class="alert-row" id="alertRow">
    <?php if ($menunggu > 0): ?>
      <div class="alert" style="--ac:var(--amber)">
        <span class="alert-ic"><svg aria-hidden="true"><use href="#i-clock"></use></svg></span>
        <div class="alert-tx"><b><?= angka($menunggu) ?> dokumen menunggu persetujuan</b>
          <small><?= $isPimpin ? 'Menunggu keputusan Anda' : 'Belum disetujui Pimpinan' ?></small></div>
        <a class="btn btn-ghost btn-sm" href="persetujuan.php">Tinjau</a>
      </div>
    <?php endif; ?>
    <?php if ($perluTerima > 0 && !$isPimpin): ?>
      <div class="alert" style="--ac:var(--accent)">
        <span class="alert-ic"><svg aria-hidden="true"><use href="#i-paket"></use></svg></span>
        <div class="alert-tx"><b><?= angka($perluTerima) ?> kiriman perlu diterima</b>
          <small>Verifikasi lalu konfirmasi agar stok masuk</small></div>
        <a class="btn btn-ghost btn-sm" href="penerimaan.php">Terima</a>
      </div>
    <?php endif; ?>
    <?php if ($kirimanAda > 0 && !$isPimpin): ?>
      <div class="alert" style="--ac:var(--info)">
        <span class="alert-ic"><svg aria-hidden="true"><use href="#i-truck"></use></svg></span>
        <div class="alert-tx"><b><?= angka($kirimanAda) ?> kiriman supplier di jalan</b>
          <small>Scan resinya saat kurir tiba</small></div>
        <a class="btn btn-ghost btn-sm" href="kiriman_supplier.php">Lihat</a>
      </div>
    <?php endif; ?>
  </div>
<?php endif; ?>

<?php if ($belumBeres): ?>
  <div class="card mulai">
    <div class="mulai-kepala">
      <div class="mulai-cincin" style="--isi:<?= count($langkah) ? round($sudahBeres / count($langkah) * 100) : 0 ?>">
        <span><?= $sudahBeres ?>/<?= count($langkah) ?></span>
      </div>
      <div>
        <h3>Dikit lagi beres 🎯</h3>
        <p class="mut-sm" style="margin:0">
          <?= count($belumBeres) ?> langkah lagi sebelum gudang siap dipakai penuh.
          Kartu ini hilang sendiri kalau semuanya sudah kelar.
        </p>
      </div>
    </div>

    <div class="mulai-daftar">
      <?php foreach ($langkah as [$judul, $beres, $tautan, $ringkas, $bantu]): ?>
        <a class="mulai-item <?= $beres ? 'beres' : '' ?>" href="<?= e($tautan) ?>">
          <span class="mulai-tanda">
            <?php if ($beres): ?>
              <svg aria-hidden="true"><use href="#i-cek"></use></svg>
            <?php endif; ?>
          </span>
          <div class="mulai-tx">
            <b><?= e($judul) ?></b>
            <small><?= $beres ? e($ringkas) : e($bantu) ?></small>
          </div>
          <?php if (!$beres): ?>
            <svg class="ikon ikon-sm mulai-panah" aria-hidden="true"><use href="#i-panah-kanan"></use></svg>
          <?php endif; ?>
        </a>
      <?php endforeach; ?>
    </div>
  </div>
<?php endif; ?>

<div class="seksi">
  <svg aria-hidden="true"><use href="#i-grafik"></use></svg>
  <h4>Ringkasan<?= $lokasi !== '' ? ' · ' . e($lokNama) : '' ?></h4>
</div>

<?php /* ============================================== BILAH PENYARING
   Formulir biasa dengan method GET, bukan JavaScript. Pilihannya jadi
   terbaca di alamat — bisa di-bookmark, bisa dikirim ke rekan, dan
   kembali persis sama saat dibuka lagi. Tanpa JS juga berarti ia tetap
   bekerja di ponsel gudang yang jaringannya putus-putus. */ ?>
<form method="get" class="card mb16 dash-saring">
  <div class="saring-baris">
    <div class="saring-grup">
      <span class="saring-lbl">Periode</span>
      <div class="saring-pil">
        <?php foreach ($PERIODE as $kunci => $p):
          $aktif = $kunci === $periode;
          $q = http_build_query(array_filter(['periode' => $kunci, 'lokasi' => $lokasi])); ?>
          <a class="pil<?= $aktif ? ' aktif' : '' ?>" href="?<?= e($q) ?>"
             <?= $aktif ? 'aria-current="true"' : '' ?>><?= e($p['label']) ?></a>
        <?php endforeach; ?>
      </div>
    </div>

    <div class="saring-grup">
      <span class="saring-lbl">Lokasi</span>
      <?php /* Hanya kirim `periode` sebagai hidden — `lokasi` datang dari
         select di bawah. Dua-duanya hidden akan saling menimpa. */ ?>
      <input type="hidden" name="periode" value="<?= e($periode) ?>">
      <select name="lokasi" onchange="this.form.submit()">
        <option value="">— Seluruh lingkup saya —</option>
        <?php
        $grupTerakhir = null;
        foreach ($opsiLokasi as $kunci => $o):
          if ($o['grup'] !== $grupTerakhir):
            if ($grupTerakhir !== null) echo '</optgroup>';
            echo '<optgroup label="' . e($o['grup']) . '">';
            $grupTerakhir = $o['grup'];
          endif; ?>
          <option value="<?= e($kunci) ?>"<?= $kunci === $lokasi ? ' selected' : '' ?>>
            <?= e($o['nama']) ?></option>
        <?php endforeach;
        if ($grupTerakhir !== null) echo '</optgroup>'; ?>
      </select>
      <noscript><button type="submit" class="btn btn-sm">Terapkan</button></noscript>
      <?php if ($lokasi !== ''): ?>
        <a class="btn btn-sm btn-ghost"
           href="?periode=<?= e($periode) ?>">Semua lokasi</a>
      <?php endif; ?>
    </div>
  </div>
  <?php /* Peringatan jujur: kalau tidak ditulis, orang akan menyangka
     penyaring periodenya rusak karena Total Stok tidak ikut bergerak.
     Disebut Masuk dan Keluar saja — halaman ini tidak punya grafik
     berbasis waktu, dan menyebut "grafik" di sini akan menjanjikan
     sesuatu yang tidak ada. */ ?>
  <p class="saring-ket">Periode mengubah angka <b>arus</b> — Masuk dan
     Keluar. Angka <b>persediaan</b> seperti Total Stok, Hampir Habis, dan
     Batch Kedaluwarsa selalu menunjukkan keadaan <b>saat ini</b>.
     Pilihan <b>lokasi</b> mengubah keduanya.</p>
</form>

<div class="kpi">
  <?php
  /* Angka diberi warna HANYA bila menuntut tindakan dan nilainya di atas
     nol. "Sudah Expired: 0" tidak perlu merah — nol justru kabar baik,
     dan mewarnainya membuat kartu yang benar-benar mendesak jadi tenggelam. */
  $perluTindakan = ['Menunggu Setuju', 'Perlu Diterima', 'Hampir Habis',
                    'Mendekati Exp', 'Sudah Expired', 'Pesanan Baru'];
  foreach ($KPI as $k):
    [$lb, $v, $d, $ik, $warna] = $k;
    $kecil  = $k[5] ?? false;
    $sorot  = in_array($lb, $perluTindakan, true) && (int) preg_replace('/\D/', '', (string) $v) > 0;
  ?>
    <div class="kpi-card<?= $sorot ? ' perlu' : '' ?>" style="--kc:<?= $warna ?>">
      <div class="kpi-top">
        <span class="kpi-l"><?= e($lb) ?></span>
        <span class="kpi-ic"><svg aria-hidden="true"><use href="#<?= $ik ?>"></use></svg></span>
      </div>
      <div class="kpi-v<?= $kecil ? ' kecil' : '' ?>"><?= $kecil ? $v : e($v) ?></div>
      <div class="kpi-d"><?= e($d) ?></div>
    </div>
  <?php endforeach; ?>
</div>

<?php /* ================================= PRODUK DI LOKASI YANG DIPILIH */ ?>
<?php if ($lokasi !== ''): ?>
  <div class="card mb16">
    <h3>Produk di <?= e($lokNama) ?></h3>
    <div class="sub">
      <?= count($produkLokasi) ?> jenis · diurutkan dari yang paling banyak
      <?= count($produkLokasi) >= 25 ? ' · 25 teratas' : '' ?>
    </div>
    <?php if (!$produkLokasi): ?>
      <div class="empty">Belum ada persediaan di <?= e($lokNama) ?>.</div>
    <?php else: $maksQ = max(array_map('intval', array_column($produkLokasi, 'qty'))) ?: 1; ?>
      <div class="tbl-wrap"><table class="tbl-kartu">
        <thead><tr>
          <th>Kode</th><th>Nama Barang</th><th>Kategori</th>
          <th class="num">Batch</th><th class="num">Qty</th>
          <th>Exp Terdekat</th><th>Sebaran</th>
        </tr></thead>
        <tbody>
        <?php foreach ($produkLokasi as $p): ?>
          <tr>
            <td data-label="Kode"><code><?= e($p['kode']) ?></code></td>
            <td data-label="Nama Barang"><b><?= e($p['nama']) ?></b></td>
            <td data-label="Kategori"><?= e($p['kategori']) ?></td>
            <td data-label="Batch" class="num"><?= angka($p['batch']) ?></td>
            <td data-label="Qty" class="num">
              <b><?= angka($p['qty']) ?></b> <small><?= e($p['satuan']) ?></small>
            </td>
            <td data-label="Exp Terdekat">
              <?php if (empty($p['exp_terdekat'])): ?>
                <small>—</small>
              <?php else:
                /* Penanda warnanya mengikuti kebiasaan halaman ini:
                   <span class="tag t-red|t-orange">, sama seperti daftar
                   Batch Kedaluwarsa di bawah. Tanggal telanjang di tengah
                   tabel tidak memanggil perhatian siapa pun, dan yang
                   lewat tanggal justru satu-satunya baris di sini yang
                   menuntut tindakan hari ini. */
                $sisaHari = (int) floor(
                    (strtotime($p['exp_terdekat']) - strtotime(date('Y-m-d'))) / 86400);
                $clsExp = $sisaHari < 0 ? 't-red' : ($sisaHari <= 30 ? 't-orange' : ''); ?>
                <?php if ($clsExp): ?>
                  <span class="tag <?= $clsExp ?>"><?= e(tgl_id($p['exp_terdekat'])) ?></span>
                <?php else: ?>
                  <?= e(tgl_id($p['exp_terdekat'])) ?>
                <?php endif; ?>
              <?php endif; ?>
            </td>
            <td data-label="Sebaran"><?= bar_mini((int) $p['qty'], $maksQ, 'var(--primary-2)') ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table></div>
    <?php endif; ?>
  </div>
<?php endif; ?>

<?php /* ============================ ANTREAN PERSETUJUAN — Pimpinan dulu */ ?>
<?php if ($antrean): ?>
  <div class="card mb16">
    <h3>Antrean Persetujuan</h3>
    <div class="sub"><?= angka($menunggu) ?> dokumen menunggu · diurutkan dari yang paling lama</div>
    <div class="tbl-wrap"><table class="tbl-kartu">
      <thead><tr><th>No. Dokumen</th><th>Jenis</th><th>Dari → Ke</th>
        <th class="num">Item</th><th class="num">Qty</th><th>Dibuat oleh</th><th>Tanggal</th><th></th></tr></thead>
      <tbody>
      <?php foreach ($antrean as $a): [$jl, $jc] = label_jenis($a['jenis']); ?>
        <tr>
          <td data-label="No. Dokumen"><b><?= e($a['no_surat_jalan'] ?: $a['no_perpindahan']) ?></b></td>
          <td data-label="Jenis"><span class="tag <?= $jc ?>"><?= e($jl) ?></span></td>
          <td data-label="Dari → Ke"><?= e($a['asal_nama'] ?: '—') ?>
            <span class="mut2">→</span> <?= e($a['tujuan_nama'] ?: '—') ?></td>
          <td data-label="Item" class="num"><?= angka($a['jml_item']) ?></td>
          <td data-label="Qty" class="num"><b><?= angka($a['total_qty']) ?></b></td>
          <td data-label="Dibuat oleh"><?= e($a['pembuat'] ?: '—') ?></td>
          <td data-label="Tanggal"><?= e(tgl_id($a['tanggal'])) ?></td>
          <td class="aksi"><a class="btn btn-primary btn-sm" href="persetujuan.php">Periksa</a></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table></div>
  </div>
<?php endif; ?>

<?php /* ======================================================================
   DONASI MASUK — kunjungan, jemputan, dan paket

   Yayasan wajib melaporkan donasi kunjungan terpisah dari donasi paket;
   kartu ini pintu masuknya, tiap angka menuju laporan yang sudah tersaring.

   TIDAK BERLINGKUP GUDANG, dan itu bukan kelalaian: tabel `donasi` memang
   tidak punya kaitan ke gudang atau rak mana pun. Donasi adalah catatan
   LEMBAGA — siapa memberi apa, kapan, lewat jalur mana — sementara ke rak
   mana barangnya akhirnya diletakkan dicatat terpisah lewat penerimaan.

   Karena itu kartunya hanya dipasang untuk peran yang memang mengurus
   donasi: pengawas lembaga dan Admin Gudang & Donasi. Admin Gudang biasa
   tidak mengurus donatur, dan memberinya angka se-lembaga di dasbor yang
   baru saja disempitkan ke gudangnya sendiri hanya akan membingungkan.

   Nama donatur TIDAK ditampilkan di sini. Yang dibutuhkan dasbor cuma
   jumlahnya; namanya data pribadi, dan tempatnya di laporan yang memang
   dibuka dengan niat memeriksanya.
   ====================================================================== */
$lihatDonasi = $lihatRantai || is_admin_terpadu();
if ($lihatDonasi):
    $donasiRingkas = [];
    foreach (CARA_TERIMA_DONASI as $kCara => $defCara) {
        $r = baris($pdo,
            "SELECT COUNT(*) AS n,
                    COALESCE((SELECT SUM(dd.qty) FROM donasi_detail dd
                               JOIN donasi d2 ON d2.id = dd.donasi_id
                              WHERE d2.cara_terima = " . $pdo->quote($kCara) . "
                                AND DATE(d2.tanggal) >= $sejak),0) AS unit
             FROM donasi d
             WHERE d.cara_terima = " . $pdo->quote($kCara) . "
               AND DATE(d.tanggal) >= $sejak");
        $donasiRingkas[$kCara] = [
            'n'    => (int) ($r[0]['n'] ?? 0),
            'unit' => (int) ($r[0]['unit'] ?? 0),
            'def'  => $defCara,
        ];
    }
    $donasiTanpaResi = (int) skalar($pdo,
        "SELECT COUNT(*) FROM donasi
          WHERE cara_terima = 'dikirim' AND DATE(tanggal) >= $sejak
            AND (no_resi IS NULL OR no_resi = '')");
    $adaDonasi = array_sum(array_column($donasiRingkas, 'n')) > 0;
?>
<div class="seksi">
  <svg aria-hidden="true"><use href="#i-paket"></use></svg>
  <h4>Donasi Masuk · <?= e($labelPeriode) ?></h4>
  <span class="ket">Catatan lembaga — tidak disaring per gudang</span>
</div>

<div class="kpi">
  <?php foreach ($donasiRingkas as $kCara => $d):
    $warna = $kCara === 'diantar' ? 'var(--green)'
           : ($kCara === 'dikirim' ? 'var(--info)' : 'var(--amber)'); ?>
    <a class="kpi-card" style="--kc:<?= $warna ?>;text-decoration:none"
       href="laporan_donasi.php?cara=<?= e($kCara) ?>&amp;periode=<?= e($periode === 'hari' ? 'minggu' : $periode) ?>">
      <div class="kpi-top"><span class="kpi-l"><?= e($d['def'][0]) ?></span></div>
      <div class="kpi-v"><?= angka($d['n']) ?></div>
      <div class="kpi-d"><?= angka($d['unit']) ?> unit</div>
    </a>
  <?php endforeach; ?>
</div>

<?php if ($donasiTanpaResi > 0): ?>
  <div class="flash flash-err" style="margin-bottom:16px">
    <b><?= angka($donasiTanpaResi) ?> donasi paket belum punya nomor resi.</b>
    Paket tidak bertatap muka — resi satu-satunya bukti barangnya benar dikirim dan tiba.
    <a href="laporan_donasi.php?cara=dikirim" style="text-decoration:underline">Lengkapi</a>
  </div>
<?php elseif (!$adaDonasi): ?>
  <div class="card mb16">
    <div class="empty">Belum ada donasi tercatat pada periode ini.</div>
  </div>
<?php endif; ?>
<?php endif; /* $lihatDonasi */ ?>

<?php /* ==================================== SEBARAN STOK PER LOKASI */ ?>
<div class="seksi">
  <svg aria-hidden="true"><use href="#i-layers"></use></svg>
  <h4>Persediaan</h4>
  <span class="ket">Di mana barang berada saat ini</span>
</div>

<?php /* Kartu gudang disembunyikan saat yang dipilih justru toko atau
   supplier. Kalau dibiarkan, tabelnya kosong dan keadaan kosongnya
   berbunyi "Belum ada gudang · Tambahkan gudang dan raknya" — kalimat
   yang salah total di lembaga yang punya lima gudang, dan menakutkan
   bagi yang membacanya. Yang tidak relevan lebih baik tidak ada
   daripada ada dan berbohong. */
$tampilGudang = $lokTipe === '' || $lokTipe === 'gudang'; ?>
<div class="dash-grid">
  <?php if ($tampilGudang): ?>
  <div class="card">
    <h3>Stok per Gudang</h3>
    <div class="sub"><?= count($stokGudang) ?> gudang · unit &amp; nilai persediaan</div>
    <?php if (!$stokGudang): ?>
      <div class="empty-state">
        <div class="ic"><svg aria-hidden="true"><use href="#i-gudang"></use></svg></div>
        <b>Belum ada gudang</b>
        <p>Tambahkan gudang dan raknya agar stok punya tempat.</p>
        <div class="aksi"><a class="btn btn-primary btn-sm" href="gudang.php">+ Tambah Gudang</a></div>
      </div>
    <?php else: $maksG = max(array_map('intval', array_column($stokGudang, 'qty'))) ?: 1; ?>
      <div class="tbl-wrap"><table class="tbl-kartu">
        <thead><tr><th>Gudang</th><th class="num">Rak</th><th class="num">Jenis</th>
          <th class="num">Unit</th><th>Sebaran</th><th class="num">Nilai</th></tr></thead>
        <tbody>
        <?php foreach ($stokGudang as $g): ?>
          <tr>
            <?php /* Nama gudang jadi tautan ke penyaringnya sendiri.
               Tabel ini menjawab BERAPA; satu ketukan membawa ke daftar
               APA saja di dalamnya, tanpa perlu mencari kotak penyaring
               di atas lalu menebak nama gudangnya. */ ?>
            <td data-label="Gudang">
              <a href="?periode=<?= e($periode) ?>&amp;lokasi=gudang:<?= (int) $g['id'] ?>">
                <b><?= e($g['nama']) ?></b></a><br>
              <small class="mono mut2"><?= e($g['kode']) ?></small></td>
            <td data-label="Rak" class="num"><?= angka($g['jml_rak']) ?></td>
            <td data-label="Jenis" class="num"><?= angka($g['jenis']) ?></td>
            <td data-label="Unit" class="num"><b><?= angka($g['qty']) ?></b></td>
            <td data-label="Sebaran"><?= bar_mini((int) $g['qty'], $maksG) ?></td>
            <td data-label="Nilai" class="num"><?= rupiah($g['nilai']) ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
        <tfoot><tr><td>Total</td><td class="num"></td><td class="num"></td>
          <td class="num"><?= angka(array_sum(array_column($stokGudang, 'qty'))) ?></td><td></td>
          <td class="num"><?= rupiah(array_sum(array_column($stokGudang, 'nilai'))) ?></td></tr></tfoot>
      </table></div>
    <?php endif; ?>
  </div>
  <?php endif; /* $tampilGudang */ ?>

  <div class="card">
    <h3>Distribusi Kategori</h3>
    <div class="sub"><?= count($kat) ?> kategori · jumlah produk</div>
    <?php if (!$kat): ?>
      <div class="empty">Belum ada kategori.</div>
    <?php else: ?>
      <div style="position:relative;height:236px"><canvas id="chKat"></canvas></div>
    <?php endif; ?>
  </div>
</div>

<?php if ($lihatRantai): ?>
  <div class="dash-grid">
    <div class="card">
      <h3>Stok per Toko</h3>
      <div class="sub"><?= count($stokToko) ?> toko · persediaan di gerai</div>
      <?php if (!$stokToko): ?>
        <div class="empty">Belum ada toko terdaftar.</div>
      <?php else: $maksT = max(array_map('intval', array_column($stokToko, 'qty'))) ?: 1; ?>
        <div class="tbl-wrap"><table class="tbl-kartu">
          <thead><tr><th>Toko</th><th>Kanal</th><th class="num">Jenis</th>
            <th class="num">Unit</th><th>Sebaran</th></tr></thead>
          <tbody>
          <?php foreach ($stokToko as $t): [$mp, $mpc] = label_marketplace($t['marketplace']); ?>
            <tr>
              <td data-label="Toko">
                <a href="?periode=<?= e($periode) ?>&amp;lokasi=toko:<?= (int) $t['id'] ?>">
                  <b><?= e($t['nama']) ?></b></a></td>
              <td data-label="Kanal"><span class="tag <?= $mpc ?>"><?= e($mp) ?></span></td>
              <td data-label="Jenis" class="num"><?= angka($t['jenis']) ?></td>
              <td data-label="Unit" class="num"><b><?= angka($t['qty']) ?></b></td>
              <td data-label="Sebaran"><?= bar_mini((int) $t['qty'], $maksT, 'var(--green)') ?></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table></div>
      <?php endif; ?>
    </div>

    <div class="card">
      <h3>Supplier &amp; Kinerja Kiriman</h3>
      <div class="sub"><?= count($stokSupplier) ?> supplier · stok siap kirim &amp; riwayat</div>
      <?php if (!$stokSupplier): ?>
        <div class="empty">Belum ada supplier terdaftar.</div>
      <?php else: ?>
        <div class="tbl-wrap"><table class="tbl-kartu">
          <thead><tr><th>Supplier</th><th class="num">Jenis</th><th class="num">Stok</th>
            <th class="num">Kiriman</th><th class="num">Diterima</th></tr></thead>
          <tbody>
          <?php foreach ($stokSupplier as $s):
            $kir = (int) $s['kiriman']; $dit = (int) $s['diterima']; ?>
            <tr>
              <td data-label="Supplier">
                <a href="?periode=<?= e($periode) ?>&amp;lokasi=supplier:<?= (int) $s['id'] ?>">
                  <b><?= e($s['nama']) ?></b></a><br>
                <small class="mono mut2"><?= e($s['kode']) ?></small></td>
              <td data-label="Jenis" class="num"><?= angka($s['jenis']) ?></td>
              <td data-label="Stok" class="num"><b><?= angka($s['qty']) ?></b></td>
              <td data-label="Kiriman" class="num"><?= angka($kir) ?></td>
              <td data-label="Diterima" class="num">
                <?php if ($kir > 0): ?>
                  <span class="tag <?= $dit === $kir ? 't-green' : 't-amber' ?>">
                    <?= $dit ?>/<?= $kir ?></span>
                <?php else: ?><span class="mut2">—</span><?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table></div>
      <?php endif; ?>
    </div>
  </div>
<?php endif; ?>

<?php /* ================================== STATUS & JENIS DOKUMEN */ ?>
<div class="seksi">
  <svg aria-hidden="true"><use href="#i-truck"></use></svg>
  <h4>Perpindahan &amp; Dokumen</h4>
  <span class="ket">Perjalanan barang antar lokasi</span>
</div>

<div class="dash-grid">
  <div class="card">
    <h3>Status Dokumen Perpindahan</h3>
    <div class="sub"><?= angka($totalDok) ?> dokumen tercatat seluruhnya</div>
    <?php if (!$totalDok): ?>
      <div class="empty">Belum ada dokumen perpindahan.</div>
    <?php else: ?>
      <div class="tbl-wrap"><table class="tbl-kartu">
        <thead><tr><th>Status</th><th class="num">Dokumen</th><th>Porsi</th><th class="num">Total Qty</th></tr></thead>
        <tbody>
        <?php foreach ($STATUS_URUT as $sk):
          $d = $dokStatus[$sk] ?? null;
          if (!$d) continue;
          [$sl, $sc] = label_status($sk);
          $petaWrn = [
              'selesai'               => 'var(--green)',
              'diterima'              => 'var(--green)',
              'menunggu_persetujuan'  => 'var(--amber)',
              'dikirim'               => 'var(--info)',
              'ditolak'               => 'var(--red)',
          ];
          $wrn = $petaWrn[$sk] ?? 'var(--muted)'; ?>
          <tr>
            <td data-label="Status"><span class="tag <?= $sc ?>"><?= e($sl) ?></span></td>
            <td data-label="Dokumen" class="num"><b><?= angka($d['jml']) ?></b></td>
            <td data-label="Porsi"><?= bar_mini((int) $d['jml'], $totalDok, $wrn) ?>
              <small class="mut2"><?= round($d['jml'] / $totalDok * 100) ?>%</small></td>
            <td data-label="Total Qty" class="num"><?= angka($d['qty']) ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table></div>
    <?php endif; ?>
  </div>

  <div class="card">
    <h3>Jenis Perpindahan</h3>
    <div class="sub">Rute yang dipakai &amp; tingkat penyelesaiannya</div>
    <?php if (!$dokJenis): ?>
      <div class="empty">Belum ada data.</div>
    <?php else: ?>
      <div class="tbl-wrap"><table class="tbl-kartu">
        <thead><tr><th>Jenis</th><th class="num">Total</th><th class="num">Tuntas</th><th class="num">Di Jalan</th></tr></thead>
        <tbody>
        <?php foreach ($dokJenis as $j): [$jl, $jc] = label_jenis($j['jenis']); ?>
          <tr>
            <td data-label="Jenis"><span class="tag <?= $jc ?>"><?= e($jl) ?></span></td>
            <td data-label="Total" class="num"><b><?= angka($j['jml']) ?></b></td>
            <td data-label="Tuntas" class="num" style="color:var(--green-t)"><?= angka($j['tuntas']) ?></td>
            <td data-label="Di Jalan" class="num" style="color:var(--info-t)"><?= angka($j['jalan']) ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table></div>
    <?php endif; ?>
  </div>
</div>

<?php if ($lihatRantai && ($jualToko || $topProduk)): ?>
  <div class="seksi">
    <svg aria-hidden="true"><use href="#i-uang"></use></svg>
    <h4>Penjualan</h4>
    <span class="ket">Hasil dari kanal toko</span>
  </div>
  <div class="dash-grid">
    <div class="card">
      <h3>Penjualan per Toko</h3>
      <div class="sub">Pesanan, unit terjual, dan omzet</div>
      <?php if (!$jualToko): ?>
        <div class="empty">Belum ada penjualan tercatat.</div>
      <?php else: ?>
        <div class="tbl-wrap"><table class="tbl-kartu">
          <thead><tr><th>Toko</th><th>Kanal</th><th class="num">Pesanan</th>
            <th class="num">Baru</th><th class="num">Unit</th><th class="num">Omzet</th></tr></thead>
          <tbody>
          <?php foreach ($jualToko as $j): [$mp, $mpc] = label_marketplace($j['marketplace']); ?>
            <tr>
              <td data-label="Toko"><b><?= e($j['nama']) ?></b></td>
              <td data-label="Kanal"><span class="tag <?= $mpc ?>"><?= e($mp) ?></span></td>
              <td data-label="Pesanan" class="num"><?= angka($j['pesanan']) ?></td>
              <td data-label="Baru" class="num">
                <?php if ((int) $j['baru'] > 0): ?>
                  <span class="tag t-amber"><?= angka($j['baru']) ?></span>
                <?php else: ?><span class="mut2">0</span><?php endif; ?>
              </td>
              <td data-label="Unit" class="num"><?= angka($j['unit']) ?></td>
              <td data-label="Omzet" class="num"><b><?= rupiah($j['omzet']) ?></b></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
          <tfoot><tr><td>Total</td><td></td>
            <td class="num"><?= angka(array_sum(array_column($jualToko, 'pesanan'))) ?></td><td></td>
            <td class="num"><?= angka(array_sum(array_column($jualToko, 'unit'))) ?></td>
            <td class="num"><?= rupiah(array_sum(array_column($jualToko, 'omzet'))) ?></td></tr></tfoot>
        </table></div>
      <?php endif; ?>
    </div>

    <div class="card">
      <h3>Produk Paling Banyak Terjual</h3>
      <div class="sub">Berdasarkan seluruh penjualan tercatat</div>
      <?php if (!$topProduk): ?>
        <div class="empty">Belum ada penjualan tercatat.</div>
      <?php else: $maksP = max(array_map('intval', array_column($topProduk, 'terjual'))) ?: 1; ?>
        <div class="tbl-wrap"><table class="tbl-kartu">
          <thead><tr><th>Produk</th><th class="num">Terjual</th><th>Sebaran</th></tr></thead>
          <tbody>
          <?php foreach ($topProduk as $tp): ?>
            <tr>
              <td data-label="Produk"><b><?= e($tp['nama']) ?></b><br>
                <small class="mono mut2"><?= e($tp['kode']) ?></small></td>
              <td data-label="Terjual" class="num"><b><?= angka($tp['terjual']) ?></b>
                <small class="mut2"><?= e($tp['satuan']) ?></small></td>
              <td data-label="Sebaran"><?= bar_mini((int) $tp['terjual'], $maksP, 'var(--accent)') ?></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table></div>
      <?php endif; ?>
    </div>
  </div>
<?php endif; ?>

<?php /* ================================== DOKUMEN TERBARU */ ?>
<div class="card mb16">
  <h3>Dokumen Terbaru</h3>
  <div class="sub">8 perpindahan terakhir di seluruh sistem</div>
  <?php if (!$dokTerbaru): ?>
    <div class="empty-state">
      <div class="ic"><svg aria-hidden="true"><use href="#i-doc"></use></svg></div>
      <b>Belum ada dokumen perpindahan</b>
      <p>Dokumen muncul di sini begitu ada penerimaan, pengiriman, atau transfer.</p>
      <?php if (!$isPimpin): ?>
        <div class="aksi"><a class="btn btn-primary btn-sm" href="perpindahan_form.php">+ Buat Surat Jalan</a></div>
      <?php endif; ?>
    </div>
  <?php else: ?>
    <div class="tbl-wrap"><table class="tbl-kartu">
      <thead><tr><th>No. Dokumen</th><th>Jenis</th><th>Dari → Ke</th>
        <th class="num">Item</th><th class="num">Qty</th><th>Resi</th><th>Status</th><th>Tanggal</th></tr></thead>
      <tbody>
      <?php foreach ($dokTerbaru as $d):
        [$jl, $jc] = label_jenis($d['jenis']);
        [$sl, $sc] = label_status($d['status']); ?>
        <tr>
          <td data-label="No. Dokumen"><b><?= e($d['no_surat_jalan'] ?: $d['no_perpindahan']) ?></b></td>
          <td data-label="Jenis"><span class="tag <?= $jc ?>"><?= e($jl) ?></span></td>
          <td data-label="Dari → Ke"><?= e($d['asal_nama'] ?: '—') ?>
            <span class="mut2">→</span> <?= e($d['tujuan_nama'] ?: '—') ?></td>
          <td data-label="Item" class="num"><?= angka($d['jml_item']) ?></td>
          <td data-label="Qty" class="num"><b><?= angka($d['total_qty']) ?></b></td>
          <td data-label="Resi"><?= $d['no_resi'] ? '<code>' . e($d['no_resi']) . '</code>'
                : '<span class="mut2">—</span>' ?></td>
          <td data-label="Status"><span class="tag <?= $sc ?>"><?= e($sl) ?></span></td>
          <td data-label="Tanggal"><?= e(tgl_id($d['tanggal'])) ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table></div>
    <a class="btn btn-ghost btn-sm" href="perpindahan.php" style="margin-top:14px">
      <svg aria-hidden="true"><use href="#i-panah-kanan"></use></svg> Lihat semua dokumen
    </a>
  <?php endif; ?>
</div>

<?php /* ================================== PERLU PERHATIAN */ ?>
<div class="seksi">
  <svg aria-hidden="true"><use href="#i-alert"></use></svg>
  <h4>Perlu Perhatian</h4>
  <span class="ket">Yang sebaiknya ditindak lebih dulu</span>
</div>

<div class="dash-grid-3">
  <div class="card">
    <h3>Mendekati Expired</h3>
    <div class="sub"><?= angka($mendekati + $expExpired) ?> batch · terdekat lebih dulu</div>
    <?php if (!$expiredDekat): ?>
      <div class="empty">Tidak ada batch mendekati kedaluwarsa 🎉</div>
    <?php else: ?>
      <ul class="tl">
      <?php foreach ($expiredDekat as $x):
        $sisa = (int) $x['sisa_hari'];
        $cls  = $sisa < 0 ? 't-red' : ($sisa <= 30 ? 't-orange' : 't-amber');
        $wrn  = $sisa < 0 ? 'var(--red)' : 'var(--amber)'; ?>
        <li>
          <span class="tl-ic" style="--tc:<?= $wrn ?>"><svg aria-hidden="true"><use href="#i-cal"></use></svg></span>
          <div class="tl-tx"><b><?= e($x['nama_barang']) ?></b>
            <small><?= e($x['nomor_batch']) ?> · <?= angka($x['qty_tersisa']) ?> unit
              · <?= e($x['lokasi_nama'] ?: '—') ?></small></div>
          <span class="tag <?= $cls ?>"><?= $sisa < 0 ? 'Expired' : $sisa . ' hr' ?></span>
        </li>
      <?php endforeach; ?>
      </ul>
      <a class="btn btn-ghost btn-sm" href="monitoring.php" style="margin-top:12px">
        <svg aria-hidden="true"><use href="#i-panah-kanan"></use></svg> Semua batch
      </a>
    <?php endif; ?>
  </div>

  <div class="card">
    <h3>Stok Rendah &amp; Habis</h3>
    <div class="sub"><?= angka($hampirHabis) ?> hampir habis · <?= angka($habis) ?> habis</div>
    <?php if (!$stokRendah): ?>
      <div class="empty">Semua stok di atas minimum 🎉</div>
    <?php else: ?>
      <ul class="tl">
      <?php foreach ($stokRendah as $b): $s = (int) $b['stok']; ?>
        <li>
          <span class="tl-ic" style="--tc:<?= $s === 0 ? 'var(--red)' : 'var(--amber)' ?>">
            <svg aria-hidden="true"><use href="#i-alert"></use></svg></span>
          <div class="tl-tx"><b><?= e($b['nama_barang']) ?></b>
            <small><?= e($b['kategori'] ?: '—') ?> · minimum <?= angka($b['stok_minimum']) ?></small></div>
          <span class="tag <?= $s === 0 ? 't-red' : 't-amber' ?>">
            <?= $s === 0 ? 'Habis' : angka($s) ?></span>
        </li>
      <?php endforeach; ?>
      </ul>
      <a class="btn btn-ghost btn-sm" href="barang.php?status=hampir_habis" style="margin-top:12px">
        <svg aria-hidden="true"><use href="#i-panah-kanan"></use></svg> Semua produk
      </a>
    <?php endif; ?>
  </div>

  <div class="card">
    <h3>Aktivitas Terbaru</h3>
    <div class="sub">Jejak perubahan data · <a href="log_aktivitas.php">lihat semua</a></div>
    <?php if (!$aktivitas): ?>
      <div class="empty">Belum ada aktivitas tercatat.</div>
    <?php else: ?>
      <ul class="tl">
      <?php foreach ($aktivitas as $a): [$ik, $wrn] = gaya_aktivitas($a['aksi']); ?>
        <li>
          <span class="tl-ic" style="--tc:<?= $wrn ?>"><svg aria-hidden="true"><use href="#<?= $ik ?>"></use></svg></span>
          <div class="tl-tx"><b><?= e($a['deskripsi'] ?: $a['aksi'] . ' ' . $a['modul']) ?></b>
            <small><?= e($a['petugas'] ?: 'Sistem') ?></small></div>
          <time><?= e(tgl_jam_id($a['created_at'])) ?></time>
        </li>
      <?php endforeach; ?>
      </ul>
    <?php endif; ?>
  </div>
</div>

<div class="card">
  <h3>Aksi Cepat</h3>
  <div class="sub">Tugas yang paling sering dikerjakan peran Anda</div>
  <div class="qa">
    <?php foreach ($AKSI_CEPAT as [$lb, $href, $ik, $warna]): ?>
      <a href="<?= e($href) ?>" style="--qc:<?= $warna ?>">
        <span class="qa-ic"><svg aria-hidden="true"><use href="#<?= $ik ?>"></use></svg></span>
        <?= e($lb) ?>
      </a>
    <?php endforeach; ?>
  </div>
</div>

<!-- Chart.js dari folder assets, bukan CDN — grafik tetap tampil saat
     komputer gudang tidak terhubung internet. -->
<script src="assets/chart.umd.js"></script>
<script>
const K_NAMA = <?= json_encode(array_column($kat, 'nama'), JSON_UNESCAPED_UNICODE) ?>;
const K_JML  = <?= json_encode(array_map('intval', array_column($kat, 'jml'))) ?>;

if (window.Chart) {
  const css = getComputedStyle(document.documentElement);
  Chart.defaults.color = css.getPropertyValue('--muted').trim();
  Chart.defaults.font.family = '"Segoe UI",system-ui,sans-serif';
  Chart.defaults.font.size = 11.5;

  /* Warna mengikuti palet sistem agar kategori terlihat konsisten. */
  const WARNA = ['#3B82F6','#06B6D4','#10B981','#F59E0B','#EF4444','#3B82F6','#93C5FD','#22D3EE',
                 '#34D399','#FBBF24'];

  const elK = document.getElementById('chKat');
  if (elK) new Chart(elK, {
    type: 'doughnut',
    data: { labels: K_NAMA, datasets: [{ data: K_JML, backgroundColor: WARNA,
            borderWidth: 0, hoverOffset: 6 }] },
    options: { maintainAspectRatio: false, cutout: '64%',
               plugins: { legend: { position: 'right',
                          labels: { boxWidth: 10, boxHeight: 10, usePointStyle: true,
                                    pointStyle: 'circle', padding: 8 } } } }
  });
}

/* ------------------------------------------------------------------ jam
   Jam dan tanggal di kepala halaman digambar server saat halaman dimuat, jadi
   keduanya BERHENTI di situ. Dasbor adalah halaman yang paling sering
   dibiarkan terbuka sepanjang hari — tanpa ini, jam 08:00 masih terpampang
   saat sudah lewat tengah hari, dan tanggalnya masih kemarin setelah lewat
   tengah malam.

   Waktunya dihitung dalam zona Asia/Jakarta, bukan zona perangkat: labelnya
   menyebut WIB, jadi harus WIB — termasuk bila ada yang membukanya dari luar
   negeri atau dari perangkat yang zonanya salah setel. */
(function () {
  var elJam = document.getElementById('jamWib');
  var elTgl = document.getElementById('tglHariIni');
  if (!elJam) { return; }

  var HARI  = ['Minggu','Senin','Selasa','Rabu','Kamis','Jumat','Sabtu'];
  var BULAN = ['Januari','Februari','Maret','April','Mei','Juni','Juli',
               'Agustus','September','Oktober','November','Desember'];

  function bagianWib() {
    /* en-CA memberi bentuk YYYY-MM-DD yang bisa diurai pasti. Memakai
       toLocaleString berbahasa Indonesia lalu menguraikannya kembali akan
       bergantung pada format yang bisa berbeda antar peramban. */
    try {
      var t = new Date();
      var tgl = t.toLocaleDateString('en-CA', { timeZone: 'Asia/Jakarta' });
      var jam = t.toLocaleTimeString('en-GB', {
        timeZone: 'Asia/Jakarta', hour: '2-digit', minute: '2-digit', hour12: false });
      var p = tgl.split('-');
      return { jam: jam, y: +p[0], m: +p[1], d: +p[2],
               hari: new Date(p[0] + '-' + p[1] + '-' + p[2] + 'T12:00:00Z').getUTCDay() };
    } catch (e) { return null; }   // zona tak dikenal — biarkan nilai server
  }

  function perbarui() {
    var w = bagianWib();
    if (!w) { return; }
    elJam.textContent = w.jam;
    if (elTgl) {
      elTgl.textContent = HARI[w.hari] + ', ' + w.d + ' ' + BULAN[w.m - 1] + ' ' + w.y;
    }
  }

  perbarui();
  // Tiap 15 detik, bukan tiap menit: kalau menitnya berganti sesaat setelah
  // pemeriksaan, jamnya akan tampak tertinggal hampir satu menit penuh.
  setInterval(perbarui, 15000);
})();
</script>
<?php require __DIR__ . '/_footer.php'; ?>
