<?php
/**
 * AMANAH — Kontrak formulir Barang
 *
 * Satu tempat untuk validasi dan penyimpanan data barang, dipakai DUA layar:
 *
 *   • barang.php       — popup "Tambah Barang" di atas daftar
 *   • barang_form.php  — halaman penuh, untuk menyunting dan sebagai jalan
 *                        cadangan bila JavaScript tidak jalan
 *
 * Kenapa dipisah ke berkas ini: sebelumnya seluruh logikanya hanya ada di
 * barang_form.php. Menyalinnya ke barang.php akan melahirkan dua validasi
 * yang perlahan berbeda — dan yang paling sering menyimpang justru
 * pemeriksaan yang jarang terpicu, mis. kode ganda. Formulirnya boleh dua;
 * aturannya harus satu.
 *
 * Isian yang TIDAK ada di sini: jumlah stok. Stok berubah hanya lewat
 * Perpindahan, Barang Masuk, dan Barang Keluar — bukan diketik di master
 * data. Menaruhnya di sini akan memberi dua sumber angka yang berbeda.
 */

/** Satuan yang sah — cocok dengan enum kolom barang.satuan. */
const BARANG_SATUAN = ['pcs', 'box', 'pack', 'kaleng', 'lusin', 'set'];

/** Baris barang kosong, kodenya sudah terisi saran berikutnya. */
function barang_kosong(PDO $pdo): array
{
    return [
        'kode' => kode_berikut($pdo, 'barang', 'BBY', 4),
        'barcode' => '', 'nama' => '', 'kategori_id' => '', 'merek' => '',
        'ukuran' => '', 'satuan' => 'pcs', 'harga' => '0', 'stok_minimum' => '0',
        'supplier_id' => '', 'lokasi_rak_id' => '', 'keterangan' => '', 'is_active' => 1,
        'foto' => '', 'hapus_foto' => 0,
    ];
}

/**
 * Daftar pilihan untuk dropdown formulir.
 *
 * Rak dibatasi ke gudang yang jadi tanggung jawab akun ini — Admin Gudang
 * tidak boleh menaruh barang di rak gudang lain, dan membatasinya di sini
 * berarti kedua layar terbatas sama.
 */
function barang_pilihan(PDO $pdo): array
{
    return [
        'kategori' => $pdo->query(
            "SELECT id, nama FROM kategori WHERE deleted_at IS NULL ORDER BY nama")->fetchAll(),
        'supplier' => $pdo->query(
            "SELECT id, nama FROM supplier WHERE deleted_at IS NULL ORDER BY nama")->fetchAll(),
        'rak' => $pdo->query(
            "SELECT lr.id, lr.nama, g.nama AS nama_gudang
             FROM lokasi_rak lr LEFT JOIN gudang g ON g.id = lr.gudang_id
             WHERE lr.deleted_at IS NULL AND lr.gudang_id IN (" . in_gudang_saya($pdo) . ")
             ORDER BY g.kode, lr.kode")->fetchAll(),
        'satuan' => BARANG_SATUAN,
    ];
}

/** Ambil isi POST ke bentuk baris barang. */
function barang_dari_post(array $post): array
{
    $b = [];
    foreach (['kode', 'barcode', 'nama', 'merek', 'ukuran', 'satuan', 'keterangan'] as $f) {
        $b[$f] = trim($post[$f] ?? '');
    }
    $b['kategori_id']   = (int) ($post['kategori_id'] ?? 0);
    $b['supplier_id']   = (int) ($post['supplier_id'] ?? 0) ?: null;
    $b['lokasi_rak_id'] = (int) ($post['lokasi_rak_id'] ?? 0) ?: null;
    $b['harga']         = (float) ($post['harga'] ?? 0);
    $b['stok_minimum']  = (int) ($post['stok_minimum'] ?? 0);
    $b['is_active']     = isset($post['is_active']) ? 1 : 0;
    /* Kotak centang: hanya terkirim saat dicentang, jadi ketiadaannya
       berarti "jangan hapus" — bukan "tidak diketahui". */
    $b['hapus_foto']    = isset($post['hapus_foto']) ? 1 : 0;
    return $b;
}

/** Periksa isian. Mengembalikan daftar galat (kosong = lolos). */
function barang_periksa(PDO $pdo, array $b, int $id): array
{
    $galat = [];
    if ($b['kode'] === '')  { $galat[] = 'Kode barang wajib diisi.'; }
    if ($b['nama'] === '')  { $galat[] = 'Nama barang wajib diisi.'; }
    if (!$b['kategori_id']) { $galat[] = 'Kategori wajib dipilih.'; }
    if (!in_array($b['satuan'], BARANG_SATUAN, true)) { $galat[] = 'Satuan tidak valid.'; }

    /* Foto WAJIB untuk barang baru, tidak untuk yang sudah ada.

       Alasannya bukan setengah hati. Mewajibkannya juga saat menyunting
       akan mengunci setiap produk lama yang belum berfoto: membetulkan
       harganya pun mustahil sebelum seseorang menyiapkan gambarnya, dan
       yang terjadi berikutnya selalu sama — orang berhenti membetulkan
       data. Untuk barang baru tidak ada beban warisan, jadi di sanalah
       aturannya ditegakkan.

       Diperiksa dari $_FILES, bukan dari $b: berkas unggahan tidak
       pernah lewat POST biasa. */
    if ($id === 0) {
        $adaBerkas = isset($_FILES['foto'])
            && ($_FILES['foto']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE;
        if (!$adaBerkas) {
            $galat[] = 'Foto produk wajib diunggah untuk barang baru. '
                     . 'PNG, JPG, atau WebP, maksimal 2 MB.';
        }
    }
    if ($galat) { return $galat; }

    /* Kode unik diperiksa TANPA memandang deleted_at pada barisnya sendiri?
       Tidak — di sini justru memandangnya, mengikuti perilaku lama halaman
       ini: kode barang yang sudah dihapus lunak boleh dipakai lagi. */
    $c = $pdo->prepare("SELECT id FROM barang WHERE kode = ? AND id <> ? AND deleted_at IS NULL");
    $c->execute([$b['kode'], $id]);
    if ($c->fetch()) { $galat[] = 'Kode barang sudah dipakai barang lain.'; }

    return $galat;
}

/**
 * Simpan barang. Mengembalikan [id, pesan].
 * Pemanggil yang menangani flash & pengalihan — dua layar mengarahkan
 * ke tempat berbeda setelah simpan.
 */
function barang_simpan(PDO $pdo, array $b, int $id): array
{
    /* ------------------------------------------------------------------
       FOTO PRODUK
       ------------------------------------------------------------------
       Tiga keadaan yang harus dibedakan, dan menyamakan salah satunya
       menghasilkan kehilangan data yang tidak pernah dilaporkan:

         1. berkas baru diunggah  → simpan yang baru, buang yang lama
         2. kotak "hapus foto" dicentang → buang yang lama, kosongkan
         3. tidak keduanya → PERTAHANKAN yang lama

       Keadaan ketiga yang paling mudah terlewat. Formulir edit tidak
       mengirim ulang berkas fotonya — peramban memang tidak bisa mengisi
       <input type=file> dengan berkas yang sudah ada — jadi menyimpan
       tanpa memilih berkas akan mengosongkan kolomnya, dan fotonya lenyap
       hanya karena seseorang membetulkan harga.
    */
    $fotoLama = '';
    if ($id > 0) {
        $st = $pdo->prepare("SELECT foto FROM barang WHERE id = ?");
        $st->execute([$id]);
        $fotoLama = (string) ($st->fetchColumn() ?: '');
    }

    $foto = $fotoLama;
    [$fotoBaru, $galatFoto] = terima_gambar('foto', 'barang');
    if ($galatFoto !== '') {
        throw new RuntimeException($galatFoto);
    }
    if ($fotoBaru !== '') {
        $foto = $fotoBaru;
    } elseif (!empty($b['hapus_foto'])) {
        $foto = '';
    }

    $nilai = [
        $b['kode'], $b['barcode'] ?: null, $b['nama'], $b['kategori_id'], $b['merek'] ?: null,
        $b['ukuran'] ?: null, $b['satuan'], $b['harga'], $b['stok_minimum'], $b['supplier_id'],
        $b['lokasi_rak_id'], $b['keterangan'] ?: null, $b['is_active'], $foto ?: null,
    ];

    if ($id > 0) {
        $nilai[] = $id;
        $pdo->prepare(
            "UPDATE barang SET kode=?,barcode=?,nama=?,kategori_id=?,merek=?,ukuran=?,satuan=?,
                    harga=?,stok_minimum=?,supplier_id=?,lokasi_rak_id=?,keterangan=?,is_active=?,
                    foto=?
             WHERE id=?"
        )->execute($nilai);

        /* Berkas lama dihapus SESUDAH basis data berhasil diperbarui.
           Kalau urutannya dibalik dan UPDATE-nya gagal, barisnya masih
           menunjuk berkas yang sudah tidak ada — dan yang terlihat adalah
           produk yang fotonya tiba-tiba rusak tanpa ada yang menyentuhnya. */
        if ($fotoLama !== '' && $fotoLama !== $foto) {
            hapus_unggahan($fotoLama);
        }

        catat_aktivitas($pdo, 'update', 'barang', $id, $b['nama']);
        return [$id, 'Barang berhasil diperbarui.'];
    }

    $pdo->prepare(
        "INSERT INTO barang (kode,barcode,nama,kategori_id,merek,ukuran,satuan,harga,
                             stok_minimum,supplier_id,lokasi_rak_id,keterangan,is_active,foto)
         VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)"
    )->execute($nilai);
    $baru = (int) $pdo->lastInsertId();

    /* Baris stok dibuat menyusul, dengan qty 0. Tanpa baris ini barang baru
       tidak muncul di laporan stok sampai ada transaksi pertamanya — terlihat
       seperti barangnya gagal tersimpan. */
    try {
        $pdo->prepare("INSERT INTO stok (barang_id, qty_total) VALUES (?,0)")->execute([$baru]);
    } catch (Throwable $e) { /* baris stok sudah ada */ }

    catat_aktivitas($pdo, 'create', 'barang', $baru, $b['nama']);
    return [$baru, 'Barang baru berhasil ditambahkan.'];
}
