<?php
/**
 * GET  /api/barang         — daftar barang dengan stok
 * GET  /api/barang?id=N    — detail barang
 * POST /api/barang         — tambah barang
 * PUT  /api/barang?id=N    — edit barang
 */
require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../_lib.php';   // label_kondisi(), nama_donatur_tampil()

$payload = auth_required();

/* foto_url_penuh() kini ada di _bootstrap.php — satu salinan untuk semua endpoint. */

$method  = $_SERVER['REQUEST_METHOD'];
$id      = isset($_GET['id']) ? (int) $_GET['id'] : null;

// catat_aktivitas() membaca pelakunya dari sesi; API bekerja dari token.
$_SESSION['user_id'] = (int) $payload['user_id'];

/* Lingkup — angka stok, daftar batch, dan sebaran lokasi dihitung dalam
   lingkup akun (lihat catatan di api/_lingkup.php). Riwayat pergerakan
   (mutasi) memuat transaksi lembaga beserta nama penerima penyaluran,
   jadi hanya untuk akun lembaga dan admin gudang. */
require_once __DIR__ . '/_lingkup.php';
$L         = api_lingkup_stok($pdo, $payload);
$subStok   = api_sub_stok($pdo, $payload, 'b.id');
$syaratBb  = api_syarat_batch($pdo, $payload, 'bb');
$lihatMutasi = $L['semua'] || api_terikat_gudang($payload);

// ---- GET ----
if ($method === 'GET') {
    if ($id) {
        /* Detail satu barang: keterangan, batch, sebaran lokasi, dan
           riwayat pergerakannya. Keempatnya sekali jalan — layar detail
           di ponsel menampilkan ketiganya sebagai tab, dan memuatnya satu
           per satu berarti tiga kali menunggu di jaringan gudang.

           Kueri batchnya sebelumnya memakai bb.no_batch dan
           bb.tgl_kadaluarsa. Keduanya tidak ada; nama sebenarnya
           nomor_batch dan tanggal_expired. Karena kegagalannya ditangkap
           lalu diubah jadi array kosong, layar detail selalu tampak
           "produk ini tidak punya batch" — padahal batchnya ada. */
        try {
            $st = $pdo->prepare(
                "SELECT b.*, k.nama AS kategori_nama,
                        sp.nama AS supplier_nama,
                        ($subStok) AS stok_total
                 FROM barang b
                 LEFT JOIN kategori k  ON k.id  = b.kategori_id
                 LEFT JOIN supplier sp ON sp.id = b.supplier_id
                 WHERE b.id = ? AND b.deleted_at IS NULL"
            );
            $st->execute([$id]);
            $barang = $st->fetch();
            if (!$barang) { err('Barang tidak ditemukan.', 404); }

            $stBatch = $pdo->prepare(
                "SELECT bb.id, bb.nomor_batch, bb.tanggal_expired, bb.qty_tersisa,
                        bb.kondisi, bb.sumber,
                        DATEDIFF(bb.tanggal_expired, CURDATE()) AS sisa_hari,
                        lr.kode AS rak_kode, g.nama AS gudang_nama, tk.nama AS toko_nama,
                        d.nama AS donatur_nama, d.anonim AS donatur_anonim,
                        d.wilayah AS donatur_wilayah
                 FROM batch_barang bb
                 LEFT JOIN lokasi_rak lr ON lr.id = bb.lokasi_rak_id
                 LEFT JOIN gudang g      ON g.id  = lr.gudang_id
                 LEFT JOIN toko tk       ON tk.id = bb.toko_id
                 LEFT JOIN donatur d     ON d.id  = bb.donatur_id
                 WHERE bb.barang_id = ? AND bb.qty_tersisa > 0 AND $syaratBb
                 ORDER BY (bb.tanggal_expired IS NULL), bb.tanggal_expired ASC, bb.id ASC"
            );
            $stBatch->execute([$id]);
            $batch = [];
            foreach ($stBatch->fetchAll() as $r) {
                [$lblK] = label_kondisi($r['kondisi'] ?? 'baru');
                $batch[] = [
                    'id'              => (int) $r['id'],
                    'nomor_batch'     => $r['nomor_batch'],
                    'tanggal_expired' => $r['tanggal_expired'],
                    'sisa_hari'       => $r['sisa_hari'] !== null ? (int) $r['sisa_hari'] : null,
                    'qty_tersisa'     => (int) $r['qty_tersisa'],
                    'kondisi'         => $r['kondisi'],
                    'kondisi_label'   => $lblK,
                    'sumber'          => $r['sumber'],
                    'rak_kode'        => $r['rak_kode'],
                    'gudang_nama'     => $r['gudang_nama'],
                    'toko_nama'       => $r['toko_nama'],
                    // Nama donatur lewat nama_donatur_tampil(): yang minta
                    // dirahasiakan tidak boleh bocor lewat layar detail.
                    'donatur'         => $r['donatur_nama']
                        ? nama_donatur_tampil(['nama' => $r['donatur_nama'],
                                               'anonim' => $r['donatur_anonim'],
                                               'wilayah' => $r['donatur_wilayah']])
                        : null,
                ];
            }

            // Sebaran per lokasi — menjawab "barangnya ada di mana".
            $stLok = $pdo->prepare(
                "SELECT COALESCE(tk.nama, g.nama, 'Tanpa lokasi') AS nama,
                        SUM(bb.qty_tersisa) AS qty
                 FROM batch_barang bb
                 LEFT JOIN lokasi_rak lr ON lr.id = bb.lokasi_rak_id
                 LEFT JOIN gudang g      ON g.id  = lr.gudang_id
                 LEFT JOIN toko tk       ON tk.id = bb.toko_id
                 WHERE bb.barang_id = ? AND bb.qty_tersisa > 0 AND $syaratBb
                 GROUP BY COALESCE(tk.nama, g.nama, 'Tanpa lokasi')
                 ORDER BY qty DESC"
            );
            $stLok->execute([$id]);
            $lokasi = array_map(fn($r) => [
                'nama' => $r['nama'], 'qty' => (int) $r['qty'],
            ], $stLok->fetchAll());

            /* Stok yang sudah terikat surat jalan tapi fisiknya belum
               keluar (status 'dikirim'). Ditampilkan terpisah dari stok
               tersedia: petugas yang melihat angka gabungan akan
               menjanjikan barang yang sebenarnya sudah dipesan. */
            $stRes = $pdo->prepare(
                "SELECT COALESCE(SUM(pd.qty),0)
                 FROM perpindahan_detail pd
                 JOIN perpindahan p ON p.id = pd.perpindahan_id
                 WHERE pd.barang_id = ? AND p.status = 'dikirim'"
            );
            $stRes->execute([$id]);
            $reserved = (int) $stRes->fetchColumn();

            /* Riwayat pergerakan, digabung dari empat sumber. UNION ALL,
               bukan empat panggilan terpisah: yang dicari petugas adalah
               urutan waktunya, dan mengurutkan gabungan itu di aplikasi
               berarti mengambil semuanya lalu membuang sebagian besar. */
            /* COLLATE dipasang eksplisit di SETIAP kolom teks.

               Tabel-tabelnya tidak seragam: pelepasan_stok memakai
               utf8mb4_general_ci sementara transaksi_masuk/keluar dan
               perpindahan memakai utf8mb4_unicode_ci. MySQL menolak
               UNION yang menggabungkan kolom berbeda kolasi dengan
               "Illegal mix of collations", dan yang gagal adalah seluruh
               kueri, bukan satu cabangnya.

               Menyeragamkan kolasi tabelnya lebih benar, tapi itu
               perubahan skema yang perlu diputuskan dan dicadangkan
               lebih dulu. Sampai itu terjadi, penyeragaman di sini yang
               menahan gejalanya. */
            $stMut = $pdo->prepare(
                "SELECT * FROM (
                    SELECT CAST('masuk' AS CHAR) COLLATE utf8mb4_unicode_ci AS jenis,
                           CAST(h.no_transaksi AS CHAR) COLLATE utf8mb4_unicode_ci AS nomor,
                           h.tanggal AS waktu,
                           d.qty AS perubahan,
                           CAST(COALESCE(sp.nama,'') AS CHAR) COLLATE utf8mb4_unicode_ci AS pihak
                      FROM transaksi_masuk_detail d
                      JOIN transaksi_masuk h ON h.id = d.transaksi_masuk_id
                      LEFT JOIN supplier sp  ON sp.id = h.supplier_id
                     WHERE d.barang_id = :b1
                    UNION ALL
                    SELECT CAST('keluar' AS CHAR) COLLATE utf8mb4_unicode_ci,
                           CAST(h.no_transaksi AS CHAR) COLLATE utf8mb4_unicode_ci,
                           h.tanggal, -d.qty,
                           CAST(COALESCE(h.penerima,'') AS CHAR) COLLATE utf8mb4_unicode_ci
                      FROM transaksi_keluar_detail d
                      JOIN transaksi_keluar h ON h.id = d.transaksi_keluar_id
                     WHERE d.barang_id = :b2
                    UNION ALL
                    SELECT CAST('perpindahan' AS CHAR) COLLATE utf8mb4_unicode_ci,
                           CAST(p.no_perpindahan AS CHAR) COLLATE utf8mb4_unicode_ci,
                           p.tanggal, COALESCE(pd.qty_diterima, pd.qty),
                           CAST(CONCAT(p.jenis,' · ',p.status) AS CHAR) COLLATE utf8mb4_unicode_ci
                      FROM perpindahan_detail pd
                      JOIN perpindahan p ON p.id = pd.perpindahan_id
                     WHERE pd.barang_id = :b3 AND p.status IN ('selesai','dikirim')
                    UNION ALL
                    SELECT CAST('pelepasan' AS CHAR) COLLATE utf8mb4_unicode_ci,
                           CAST(ps.no_pelepasan AS CHAR) COLLATE utf8mb4_unicode_ci,
                           ps.tanggal, -ps.qty,
                           CAST(ps.jenis AS CHAR) COLLATE utf8mb4_unicode_ci
                      FROM pelepasan_stok ps
                     WHERE ps.barang_id = :b4
                 ) x ORDER BY waktu DESC, nomor DESC LIMIT 40"
            );
            $mutasi = [];
            if ($lihatMutasi) {
            $stMut->execute([':b1' => $id, ':b2' => $id, ':b3' => $id, ':b4' => $id]);
            $mutasi = array_map(fn($r) => [
                'jenis'     => $r['jenis'],
                'nomor'     => $r['nomor'],
                'waktu'     => $r['waktu'],
                'perubahan' => (int) $r['perubahan'],
                'pihak'     => $r['pihak'],
            ], $stMut->fetchAll());
            }

            /* Detail ikut membawa URL fotonya. Tanpa ini layar detail
               produk di ponsel harus menebak alamatnya dari nama berkas,
               dan tebakan itu salah begitu aplikasi dipasang di folder
               yang berbeda antara lokal dan hosting. */
            $barang['foto_url'] = foto_url_penuh($barang['foto'] ?? null);

            ok([
                'barang'   => $barang,
                'batch'    => $batch,
                'lokasi'   => $lokasi,
                'mutasi'   => $mutasi,
                'reserved' => $reserved,
            ]);
        } catch (Throwable $e) {
            error_log('[AMANAH API] barang detail: ' . $e->getMessage());
            err('Gagal mengambil detail barang.', 500);
        }
    }

    // Daftar barang
    [$limit, $offset] = paginate(30);
    $cari   = trim($_GET['q'] ?? '');
    $katId  = (int) ($_GET['kategori_id'] ?? 0);
    $status = $_GET['status'] ?? '';   // habis | hampir_habis

    $where  = ['b.deleted_at IS NULL'];
    $params = [];

    if ($cari !== '') {
        $where[]  = '(b.nama LIKE ? OR b.kode LIKE ?)';
        $like     = '%' . $cari . '%';
        $params[] = $like;
        $params[] = $like;
    }
    if ($katId) {
        $where[]  = 'b.kategori_id = ?';
        $params[] = $katId;
    }
    if ($status === 'habis') {
        $where[] = "($subStok) = 0";
    } elseif ($status === 'hampir_habis') {
        $where[] = "($subStok) > 0 AND ($subStok) <= b.stok_minimum";
    }

    /* -----------------------------------------------------------------
       URUTAN — kendali "Urutkan" pada rancangan "09"
       -----------------------------------------------------------------
       Nilai dari luar TIDAK PERNAH masuk ke SQL apa adanya; ia hanya
       dipakai memilih salah satu kalimat ORDER BY yang sudah ditulis di
       sini. Menempelkan nama kolom dari query string langsung ke ORDER BY
       adalah lubang injeksi yang tidak tertutup oleh prepared statement,
       karena bagian itu bukan parameter. */
    $URUT = [
        'nama'        => 'b.nama ASC',
        'nama_z'      => 'b.nama DESC',
        'kode'        => 'b.kode ASC',
        'stok_besar'  => "($subStok) DESC, b.nama ASC",
        'stok_kecil'  => "($subStok) ASC, b.nama ASC",
        'terbaru'     => 'b.created_at DESC, b.id DESC',
        // Berkelompok per kategori — nama kategori dulu, yang kosong paling akhir.
        'kategori'    => '(k.nama IS NULL), k.nama ASC, b.nama ASC',
    ];
    $urut = array_key_exists($_GET['urut'] ?? '', $URUT) ? $_GET['urut'] : 'nama';
    $urutSql = $URUT[$urut];

    $wSql = implode(' AND ', $where);
    try {
        /* Satu kali hitung, bukan tiga.

           Versi sebelumnya menyiapkan kueri COUNT yang sama tiga kali —
           dua di antaranya di dalam operator ternary yang hasilnya selalu
           1, jadi cabang `: 0`-nya tidak pernah tercapai. Setiap pembukaan
           daftar produk membayar tiga kali kerja untuk satu angka. */
        $stCount = $pdo->prepare(
            "SELECT COUNT(*) FROM barang b
              WHERE $wSql");
        $stCount->execute($params);
        $total = (int) $stCount->fetchColumn();

        /* barcode, foto, dan is_active ikut dikirim — ketiganya dipakai
           rancangan "09 Data Barang" pada tiap baris daftar.

           `foto` saat ini KOSONG untuk seluruh produk di basis data. Ia
           tetap dikirim, dan aplikasi menggambar petak berikon sebagai
           gantinya. Mengirimkan field yang selalu null lebih baik daripada
           tidak mengirimkannya: begitu foto mulai diunggah, gambarnya
           muncul tanpa perlu mengubah aplikasi maupun API lagi. */
        $st = $pdo->prepare(
            "SELECT b.id, b.kode, b.barcode, b.nama, b.satuan, b.harga,
                    b.stok_minimum, b.foto, b.is_active,
                    k.nama AS kategori,
                    ($subStok) AS stok
             FROM barang b
             LEFT JOIN kategori k ON k.id = b.kategori_id
             WHERE $wSql
             ORDER BY $urutSql
             LIMIT $limit OFFSET $offset"
        );
        $st->execute($params);
        $list = $st->fetchAll();

        /* Nama berkas TETAP dikirim di `foto`, dan URL penuhnya
           ditambahkan sebagai `foto_url`. Keduanya, bukan salah satu:
           nama berkas dipakai formulir web, URL dipakai ponsel. */
        foreach ($list as &$brg) {
            $brg['foto_url'] = foto_url_penuh($brg['foto'] ?? null);
        }
        unset($brg);

        /* ---------------------------------------------------------------
           RINGKASAN — empat angka di atas daftar
           ---------------------------------------------------------------
           Dihitung dengan SYARAT YANG SAMA seperti daftarnya, bukan atas
           seluruh tabel. Jadi saat orang mencari "susu", keempat kartunya
           menerangkan hasil pencarian itu — bukan angka lembaga yang tidak
           ada hubungannya dengan yang sedang tampil di layar.

           Kartu yang tidak sepadan dengan daftarnya adalah cara paling
           halus membuat orang salah membaca: angkanya benar, tapi
           menjawab pertanyaan yang tidak sedang diajukan. */
        $stRing = $pdo->prepare(
            "SELECT COUNT(*) AS total,
                    COALESCE(SUM(b.is_active = 1), 0) AS aktif,
                    COALESCE(SUM(($subStok) <= 0), 0) AS habis,
                    COALESCE(SUM(($subStok) > 0
                              AND ($subStok) <= b.stok_minimum), 0) AS stok_rendah
               FROM barang b
              WHERE $wSql");
        $stRing->execute($params);
        $ring = $stRing->fetch() ?: [];

    } catch (Throwable $e) {
        /* Pesan aslinya DICATAT, bukan dikirim. Versi sebelumnya
           menempelkan getMessage() ke jawaban, sehingga nama tabel dan
           kolom ikut sampai ke siapa pun yang memegang token — termasuk
           yang memanggil endpoint ini di luar aplikasi. */
        error_log('[AMANAH API] barang daftar: ' . $e->getMessage());
        err('Gagal mengambil data.', 500);
    }

    ok([
        'barang' => $list,
        'total'  => $total,
        'limit'  => $limit,
        'ringkasan' => [
            'total'       => (int) ($ring['total'] ?? 0),
            'aktif'       => (int) ($ring['aktif'] ?? 0),
            'stok_rendah' => (int) ($ring['stok_rendah'] ?? 0),
            'habis'       => (int) ($ring['habis'] ?? 0),
        ],
        'urut' => $urut,
    ]);
}

// ---- POST (tambah) ----
if ($method === 'POST') {
    if (!in_array($payload['role'], ['super_admin', 'admin_gudang', 'admin_terpadu'], true)) {
        err('Hak akses tidak cukup.', 403);
    }
    $b = body();
    $nama   = trim($b['nama'] ?? '');
    $kode   = trim($b['kode'] ?? '');
    $satuan = trim($b['satuan'] ?? '');
    if ($nama === '' || $satuan === '') {
        err('Nama dan satuan wajib diisi.');
    }
    try {
        /* Tabel `barang` tidak punya kolom `dibuat_oleh` — menyebutkannya
           membuat setiap penambahan barang gagal. Jejak pembuatnya tetap
           ada lewat catat_aktivitas(), yang memang tempatnya. */
        $st = $pdo->prepare(
            "INSERT INTO barang (kode, barcode, nama, satuan, harga, stok_minimum,
                                 kategori_id, merek, ukuran, keterangan)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
        );
        $st->execute([
            $kode ?: null,
            trim($b['barcode'] ?? '') ?: null,
            $nama,
            $satuan,
            (float) ($b['harga'] ?? 0),
            (int)   ($b['stok_minimum'] ?? 0),
            $b['kategori_id'] ? (int) $b['kategori_id'] : null,
            /* Merek dan ukuran memang sudah ada kolomnya di tabel, tapi
               sebelumnya tidak pernah ditulis endpoint ini — jadi apa pun
               yang diketik di formulir hilang tanpa kabar saat disimpan. */
            trim($b['merek'] ?? '') ?: null,
            trim($b['ukuran'] ?? '') ?: null,
            $b['keterangan'] ?? '',
        ]);
        $baruId = (int) $pdo->lastInsertId();
        catat_aktivitas($pdo, 'create', 'barang', $baruId, "Tambah produk: $nama");
        ok(['id' => $baruId, 'pesan' => "Produk $nama berhasil ditambahkan."]);
    } catch (Throwable $e) {
        if (str_contains($e->getMessage(), 'Duplicate')) {
            err('Kode atau barcode itu sudah dipakai produk lain.');
        }
        error_log('[AMANAH API] barang tambah: ' . $e->getMessage());
        err('Gagal menyimpan produk.', 500);
    }
}

// ---- PUT (edit) ----
if ($method === 'PUT') {
    if (!$id) { err('Parameter id wajib diisi.'); }
    if (!in_array($payload['role'], ['super_admin', 'admin_gudang', 'admin_terpadu'], true)) {
        err('Hak akses tidak cukup.', 403);
    }
    $b = body();
    try {
        $pdo->prepare(
            /* TIDAK ada `diubah_oleh` maupun `diubah_at` — kedua kolom itu
               tidak pernah ada di tabel `barang`.

               Sebelumnya keduanya disebut di sini, dan akibatnya SETIAP
               penyuntingan barang gagal dengan "Unknown column 'diubah_oleh'".
               Fitur ubah barang tidak pernah sekali pun berhasil, dan
               gejalanya tersembunyi karena galatnya hanya muncul saat
               tombol simpan ditekan.

               Jejak pengubahnya tetap ada lewat catat_aktivitas() di bawah —
               tempat yang memang disediakan untuk itu, sama seperti pada
               penambahan dan penghapusan. */
            "UPDATE barang SET nama=?, satuan=?, harga=?, stok_minimum=?,
                               kategori_id=?, merek=?, ukuran=?, keterangan=?
             WHERE id=? AND deleted_at IS NULL"
        )->execute([
            $b['nama'] ?? '',
            $b['satuan'] ?? '',
            (float) ($b['harga'] ?? 0),
            (int)   ($b['stok_minimum'] ?? 0),
            $b['kategori_id'] ? (int) $b['kategori_id'] : null,
            trim($b['merek'] ?? '') ?: null,
            trim($b['ukuran'] ?? '') ?: null,
            $b['keterangan'] ?? '',
            $id,
        ]);
        /* Jejak pengubah dicatat di sini, bukan sebagai kolom di tabel —
           sama seperti pada penambahan dan penghapusan. */
        catat_aktivitas($pdo, 'update', 'barang', (int) $id,
            'Ubah produk: ' . ($b['nama'] ?? ''));
        ok(['pesan' => 'Barang berhasil diperbarui.']);
    } catch (Throwable $e) {
        error_log('[AMANAH API] barang ubah: ' . $e->getMessage()); err('Gagal memperbarui barang.', 500);
    }
}

// ---- DELETE (hapus, lunak) ----
/**
 * Penghapusan LUNAK: baris `barang` tidak pernah benar-benar dibuang, hanya
 * ditandai `deleted_at`.
 *
 * Alasannya bukan kehati-hatian umum. Seluruh riwayat transaksi menunjuk ke
 * `barang.id` — surat jalan, penerimaan, stok opname, laporan tahunan.
 * Menghapus barisnya secara fisik membuat setiap catatan lama kehilangan
 * nama barangnya dan berubah jadi baris kosong bernomor. Di lembaga yang
 * mengelola titipan orang, riwayat itu justru bagian yang paling tidak boleh
 * hilang.
 *
 * Alasan dan catatan penghapusan ikut disimpan lewat catat_aktivitas(),
 * supaya pertanyaan "kenapa data ini hilang" punya jawaban yang tercatat
 * sejak hari kejadian — bukan dikarang belakangan.
 */
if ($method === 'DELETE') {
    if (!$id) { err('Parameter id wajib diisi.'); }
    if (!in_array($payload['role'], ['super_admin', 'admin_gudang', 'admin_terpadu'], true)) {
        err('Hak akses tidak cukup.', 403);
    }
    /* Hanya manager yang boleh menghapus. Staff tetap bisa menambah dan
       mengubah — kekeliruan di sana masih bisa dibetulkan, sedangkan
       penghapusan menyembunyikan barang dari seluruh transaksi baru. */
    if (($payload['tingkat'] ?? 'staff') !== 'manager'
        && $payload['role'] !== 'super_admin') {
        err('Hanya manager yang bisa menghapus barang.', 403);
    }

    /* Dibaca dari query string MAUPUN badan permintaan.
       Klien Android mengirim DELETE tanpa badan — sebagian server dan proxy
       menolak DELETE yang membawa badan, dan yang terjadi bukan galat yang
       jelas melainkan permintaan yang hilang di tengah jalan. Menerima
       keduanya membuat endpoint ini tetap bisa dipakai dari curl maupun
       peramban tanpa berubah perilaku. */
    $b       = body();
    $alasan  = trim($_GET['alasan']  ?? $b['alasan']  ?? '');
    $catatan = trim($_GET['catatan'] ?? $b['catatan'] ?? '');
    if ($alasan === '') {
        err('Alasan penghapusan wajib diisi.');
    }

    try {
        $st = $pdo->prepare(
            "SELECT nama, deleted_at FROM barang WHERE id = ? LIMIT 1"
        );
        $st->execute([$id]);
        $brg = $st->fetch();
        if (!$brg) { err('Barang tidak ditemukan.', 404); }
        if ($brg['deleted_at'] !== null) { err('Barang ini sudah dihapus sebelumnya.'); }

        /* Stok yang belum kosong menahan penghapusan.
           Master barang yang hilang sementara butirnya masih di rak
           meninggalkan stok tanpa induk — dan itu baru ketahuan saat opname
           berikutnya, berbulan-bulan kemudian. */
        /* Dihitung dari `batch_barang`, BUKAN dari `stok`.
           Tabel `stok` menyimpan angka ringkasan yang diperbarui belakangan;
           `batch_barang` adalah butir fisiknya. Kalau keduanya sempat
           berselisih, yang boleh menahan penghapusan adalah butir yang
           benar-benar masih ada di rak. */
        $stStok = $pdo->prepare(
            "SELECT COALESCE(SUM(qty_tersisa), 0) FROM batch_barang
             WHERE barang_id = ? AND qty_tersisa > 0"
        );
        $stStok->execute([$id]);
        $sisa = (int) $stStok->fetchColumn();
        if ($sisa > 0) {
            err("Tidak bisa dihapus — masih ada $sisa unit tercatat. "
              . "Keluarkan atau salurkan dulu lewat Barang Keluar.");
        }

        $pdo->prepare("UPDATE barang SET deleted_at = NOW() WHERE id = ?")
            ->execute([$id]);

        $jejak = "Hapus produk: {$brg['nama']} — alasan: $alasan"
               . ($catatan !== '' ? " ($catatan)" : '');
        catat_aktivitas($pdo, 'delete', 'barang', (int) $id, $jejak);

        ok(['pesan' => "Produk {$brg['nama']} berhasil dihapus."]);
    } catch (Throwable $e) {
        error_log('[AMANAH API] barang hapus: ' . $e->getMessage());
        err('Gagal menghapus produk.', 500);
    }
}

err('Method tidak diizinkan.', 405);
