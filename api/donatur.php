<?php
/**
 * GET /api/donatur.php
 *   ?q=          cari nama / kode / wilayah
 *   ?jenis=      pribadi | komunitas | lembaga
 *   ?wilayah=
 *   ?urut=       nama | sering | banyak | nilai | rajin | kunjung | baru | lama
 *   ?page= &limit=
 *
 * GET /api/donatur.php?id=123      satu donatur beserta riwayat donasinya
 *
 * ---------------------------------------------------------------------
 * DATA PRIBADI
 * ---------------------------------------------------------------------
 * Endpoint ini mengembalikan nama dan nomor telepon orang. Dua aturan yang
 * SAMA PERSIS dengan aplikasi web berlaku di sini, dan keduanya dijalankan
 * di server — bukan diserahkan kepada aplikasi untuk menyembunyikannya:
 *
 *   • kolom `anonim` dihormati: namanya diganti "Hamba Allah" untuk SEMUA
 *     pembaca. Aplikasi tidak pernah menerima nama aslinya, jadi tangkapan
 *     layar dari ponsel pun tidak bisa membocorkannya.
 *   • nomor telepon hanya dikirim kepada yang berkepentingan; selain itu
 *     disamarkan. Menyaring di aplikasi tidak ada gunanya — siapa pun yang
 *     memegang token bisa memanggil endpoint ini langsung.
 *
 * Mitra hanya melihat donatur yang ia daftarkan sendiri, ditambah yang
 * tidak terikat mitra mana pun.
 */
require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../_lib.php';
/* GET dibuka untuk semua peran yang berhak; tambah/ubah/hapus
   dijaga masing-masing di jalur tulisnya, di bawah. */
only('GET', 'POST', 'PUT', 'DELETE');

$method = $_SERVER['REQUEST_METHOD'];

$p = auth_required();

// Toko tidak berurusan dengan donatur — barang sudah jadi stok jauh
// sebelum sampai ke toko.
if (($p['role'] ?? '') === 'toko') {
    err('Halaman donatur tidak termasuk lingkup akun toko.', 403);
}

$isSupplier  = ($p['role'] ?? '') === 'supplier';
$supplierId  = $p['supplier_id'] !== null ? (int) $p['supplier_id'] : null;
$isManager   = ($p['tingkat'] ?? 'staff') === 'manager'
            || in_array($p['role'] ?? '', ['super_admin', 'pimpinan'], true);

/* Siapa yang boleh melihat kontak. Sengaja disusun ulang di sini, bukan
   memanggil boleh_lihat_kontak_donatur() dari _lib.php: fungsi itu membaca
   $_SESSION, sedangkan API bekerja dari isi token. */
$lihatKontak = in_array($p['role'] ?? '', ['super_admin', 'pimpinan'], true)
            || $isSupplier
            || $isManager;

/* ======================================================================
   JALUR TULIS — tambah, ubah, hapus
   ======================================================================
   Aturan wewenangnya CERMINAN halaman web `donatur.php`, bukan aturan
   baru. Menyusun aturan kedua yang mirip-tapi-tidak-sama berarti dua
   kebijakan privasi yang harus tetap seragam selamanya — dan yang jarang
   dipakai selalu yang tertinggal saat salah satunya diperbaiki.

   Yang dicerminkan:
     - mengelola  → manager (super admin, pimpinan, atau tingkat manager),
                    DITAMBAH mitra supplier untuk donatur miliknya sendiri
     - menghapus  → manager; mitra boleh menghapus miliknya sendiri
     - mitra hanya menyentuh donatur yang ia daftarkan. Tanpa ini,
       menebak angka di ?id= sudah cukup untuk membuka dan mengubah data
       pribadi donatur mitra lain.
     - hapusnya LUNAK. Riwayat pemberian jadi dasar laporan
       pertanggungjawaban, dan laporan yang barisnya bisa hilang karena
       satu penghapusan di layar lain tidak bisa dipercaya.
   ====================================================================== */

/** Boleh menambah & mengubah. */
$bolehKelola = $isManager || $isSupplier;

/** Pastikan donatur ini memang boleh disentuh akun mitra. */
function pastikan_milik_mitra(PDO $pdo, int $id, bool $isSupplier, ?int $supplierId): void {
    if (!$isSupplier) { return; }
    $st = $pdo->prepare("SELECT supplier_id FROM donatur WHERE id = ? AND deleted_at IS NULL");
    $st->execute([$id]);
    $pemilik = $st->fetchColumn();
    if ($pemilik === false) { err('Donatur tidak ditemukan.', 404); }
    if ($pemilik === null || (int) $pemilik !== (int) $supplierId) {
        err('Donatur ini didaftarkan mitra lain.', 403);
    }
}

/** Baca dan periksa isian yang dikirim aplikasi. */
function isian_donatur(array $b, PDO $pdo, int $idSaatIni = 0): array {
    $JENIS = ['pribadi', 'komunitas', 'lembaga'];

    $nama = trim($b['nama'] ?? '');
    if ($nama === '') { err('Nama donatur wajib diisi.'); }

    $email = trim($b['email'] ?? '');
    if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        err('Format email tidak sah.');
    }

    /* Kode boleh dikosongkan; nomor berikutnya dibuatkan. Memaksa petugas
       mengarang kode sendiri adalah cara paling mudah menghasilkan dua
       donatur berkode sama, dan kolomnya UNIQUE — yang kalah baru tahu
       setelah menekan simpan dan kehilangan seluruh isian formulirnya. */
    $kode = trim($b['kode'] ?? '');
    if ($kode === '') {
        if ($idSaatIni > 0) {
            /* MENYUNTING: kode yang tidak dikirim berarti "jangan diubah",
               bukan "buatkan yang baru".

               Membuatkan yang baru di sini pernah terjadi, dan akibatnya
               halus sekali: menyunting nomor telepon seorang donatur
               diam-diam mengganti kodenya dari DN-001 menjadi DN-002.
               Kode itu tercetak pada kuitansi dan dipakai mencocokkan
               laporan pertanggungjawaban — begitu berganti, dokumen
               kertas yang sudah beredar tidak lagi menunjuk siapa pun. */
            $st = $pdo->prepare("SELECT kode FROM donatur WHERE id = ?");
            $st->execute([$idSaatIni]);
            $kode = (string) $st->fetchColumn();
        }
        if ($kode === '') { $kode = kode_berikut($pdo, 'donatur', 'DN'); }
    }

    $c = $pdo->prepare("SELECT id FROM donatur WHERE kode = ? AND id <> ?");
    $c->execute([$kode, $idSaatIni]);
    if ($c->fetch()) { err('Kode itu sudah dipakai donatur lain.'); }

    $jenis = in_array($b['jenis'] ?? '', $JENIS, true) ? $b['jenis'] : 'pribadi';

    return [
        'kode'        => $kode,
        'nama'        => $nama,
        'jenis'       => $jenis,
        'wilayah'     => trim($b['wilayah'] ?? '') ?: null,
        'alamat'      => trim($b['alamat'] ?? '') ?: null,
        'telepon'     => trim($b['telepon'] ?? '') ?: null,
        'email'       => $email ?: null,
        'kontak_pic'  => trim($b['kontak_pic'] ?? '') ?: null,
        'jml_anggota' => ((int) ($b['jml_anggota'] ?? 0)) ?: null,
        'anonim'      => !empty($b['anonim']) ? 1 : 0,
        'keterangan'  => trim($b['keterangan'] ?? '') ?: null,
        'is_active'   => array_key_exists('is_active', $b) ? (int) !empty($b['is_active']) : 1,
    ];
}

if ($method === 'POST') {
    if (!$bolehKelola) { err('Menambah donatur adalah wewenang manager.', 403); }
    $d = isian_donatur(body(), $pdo);

    try {
        $pdo->prepare(
            "INSERT INTO donatur (kode, nama, jenis, supplier_id, wilayah, alamat,
                    telepon, email, kontak_pic, jml_anggota, anonim, keterangan, is_active)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)"
        )->execute([
            $d['kode'], $d['nama'], $d['jenis'],
            /* Mitra otomatis jadi pemilik donatur yang ia daftarkan. Akun
               lembaga mendaftarkan donatur tanpa pemilik, yang berarti
               semua mitra boleh melihatnya. */
            $isSupplier ? $supplierId : null,
            $d['wilayah'], $d['alamat'], $d['telepon'], $d['email'],
            $d['kontak_pic'], $d['jml_anggota'], $d['anonim'],
            $d['keterangan'], $d['is_active'],
        ]);
        $baru = (int) $pdo->lastInsertId();

        /* Nama donatur TIDAK ikut dicatat di log. Log aktivitas dibaca
           banyak orang, dan menuliskan namanya di sana membocorkan justru
           yang sedang dijaga endpoint ini. */
        catat_aktivitas($pdo, 'create', 'donatur', $baru, "Tambah donatur #$baru");

        ok([
            'id'    => $baru,
            'kode'  => $d['kode'],
            'pesan' => "Donatur ditambahkan dengan kode {$d['kode']}.",
        ]);
    } catch (Throwable $e) {
        error_log('[AMANAH API] donatur tambah: ' . $e->getMessage());
        err('Gagal menambah donatur.', 500);
    }
}

if ($method === 'PUT') {
    if (!$bolehKelola) { err('Mengubah donatur adalah wewenang manager.', 403); }
    $idU = (int) ($_GET['id'] ?? 0);
    if ($idU <= 0) { err('id wajib diisi.'); }
    pastikan_milik_mitra($pdo, $idU, $isSupplier, $supplierId);

    $d = isian_donatur(body(), $pdo, $idU);

    try {
        /* supplier_id sengaja TIDAK ikut diubah. Memindahkan kepemilikan
           donatur berarti memindahkan siapa yang boleh melihat nomor
           teleponnya, dan itu keputusan tersendiri — bukan efek samping
           dari menyunting alamat. */
        $st = $pdo->prepare(
            "UPDATE donatur SET kode=?, nama=?, jenis=?, wilayah=?, alamat=?, telepon=?,
                    email=?, kontak_pic=?, jml_anggota=?, anonim=?, keterangan=?, is_active=?
             WHERE id=? AND deleted_at IS NULL"
        );
        $st->execute([
            $d['kode'], $d['nama'], $d['jenis'], $d['wilayah'], $d['alamat'],
            $d['telepon'], $d['email'], $d['kontak_pic'], $d['jml_anggota'],
            $d['anonim'], $d['keterangan'], $d['is_active'], $idU,
        ]);

        if ($st->rowCount() === 0) {
            $cek = $pdo->prepare("SELECT id FROM donatur WHERE id=? AND deleted_at IS NULL");
            $cek->execute([$idU]);
            if (!$cek->fetch()) { err('Donatur tidak ditemukan.', 404); }
        }

        catat_aktivitas($pdo, 'update', 'donatur', $idU, "Ubah donatur #$idU");
        ok(['id' => $idU, 'pesan' => 'Data donatur diperbarui.']);
    } catch (Throwable $e) {
        error_log('[AMANAH API] donatur ubah: ' . $e->getMessage());
        err('Gagal memperbarui donatur.', 500);
    }
}

if ($method === 'DELETE') {
    if (!$isManager && !$isSupplier) {
        err('Penghapusan donatur adalah wewenang manager.', 403);
    }
    $idH = (int) ($_GET['id'] ?? 0);
    if ($idH <= 0) { err('id wajib diisi.'); }
    pastikan_milik_mitra($pdo, $idH, $isSupplier, $supplierId);

    try {
        $st = $pdo->prepare(
            "UPDATE donatur SET deleted_at = NOW(), is_active = 0
             WHERE id = ? AND deleted_at IS NULL"
        );
        $st->execute([$idH]);
        if ($st->rowCount() === 0) { err('Donatur tidak ditemukan.', 404); }

        catat_aktivitas($pdo, 'delete', 'donatur', $idH, "Hapus donatur #$idH");
        ok(['id' => $idH, 'pesan' => 'Donatur dihapus. Riwayat pemberiannya tetap tersimpan.']);
    } catch (Throwable $e) {
        error_log('[AMANAH API] donatur hapus: ' . $e->getMessage());
        err('Gagal menghapus donatur.', 500);
    }
}

/** Susun satu baris donatur untuk dikirim, dengan aturan privasi diterapkan. */
function baris_donatur(array $d, bool $lihatKontak): array {
    $anonim = !empty($d['anonim']);
    return [
        'id'          => (int) $d['donatur_id'] ?? (int) $d['id'],
        'kode'        => $d['kode'],
        // nama_donatur_tampil() dari _lib.php: menghormati kolom anonim.
        'nama'        => nama_donatur_tampil($d),
        'anonim'      => $anonim,
        'jenis'       => $d['jenis'],
        'jenis_label' => ucfirst((string) $d['jenis']),
        'wilayah'     => $d['wilayah'],
        'telepon'     => $lihatKontak ? ($d['telepon'] ?? null)
                                      : samarkan_telepon($d['telepon'] ?? ''),
        'jml_donasi'     => (int) ($d['jml_donasi'] ?? 0),
        'jml_kunjungan'  => (int) ($d['jml_kunjungan'] ?? 0),
        'jml_paket'      => (int) ($d['jml_paket'] ?? 0),
        'total_qty'      => (int) ($d['total_qty'] ?? 0),
        'total_nilai'    => (float) ($d['total_nilai'] ?? 0),
        'rata_per_bulan' => (float) ($d['rata_per_bulan'] ?? 0),
        'donasi_terakhir'     => $d['donasi_terakhir'] ?? null,
        'hari_sejak_terakhir' => $d['hari_sejak_terakhir'] !== null
                               ? (int) $d['hari_sejak_terakhir'] : null,
        'is_active'   => (int) ($d['is_active'] ?? 1) === 1,
    ];
}

/* Batasan pandangan mitra, dipakai daftar maupun detail. */
$batas = '';
$par   = [];
if ($isSupplier) {
    $batas = ' AND (d.supplier_id = ? OR d.supplier_id IS NULL)';
    $par[] = $supplierId;
}

/* Semua jalur tulis di atas berakhir pada ok() atau err(), dan
   keduanya memanggil exit. Baris ini bukan pengulangan yang mubazir
   melainkan jaring pengaman: bila kelak ada cabang tulis baru yang
   lupa mengakhiri dirinya, permintaan POST akan jatuh ke kode baca di
   bawah dan membalas 200 dengan daftar donatur — seolah
   penyimpanannya berhasil, padahal tidak ada yang tersimpan. */
if ($method !== 'GET') { err('Method tidak diizinkan.', 405); }

// ----------------------------------------------------------- SATU DONATUR
$id = (int) ($_GET['id'] ?? 0);
if ($id > 0) {
    try {
        $st = $pdo->prepare(
            "SELECT d.*, v.jml_donasi, v.jml_kunjungan, v.jml_paket, v.total_qty,
                    v.total_nilai, v.rata_per_bulan, v.donasi_terakhir,
                    v.hari_sejak_terakhir, v.donatur_id
             FROM donatur d
             LEFT JOIN v_peringkat_donatur v ON v.donatur_id = d.id
             WHERE d.id = ? AND d.deleted_at IS NULL" . $batas
        );
        $st->execute(array_merge([$id], $par));
        $d = $st->fetch();
        if (!$d) { err('Donatur tidak ditemukan, atau bukan dalam lingkup Anda.', 404); }

        // Riwayat pemberiannya.
        $stR = $pdo->prepare(
            "SELECT dn.id, dn.no_donasi, dn.tanggal, dn.jenis, dn.cara_terima, dn.catatan,
                    (SELECT COALESCE(SUM(dd.qty),0) FROM donasi_detail dd WHERE dd.donasi_id = dn.id) AS qty,
                    (SELECT COUNT(*)                FROM donasi_detail dd WHERE dd.donasi_id = dn.id) AS jml_baris
             FROM donasi dn WHERE dn.donatur_id = ? ORDER BY dn.tanggal DESC, dn.id DESC LIMIT 50"
        );
        $stR->execute([$id]);
        $riwayat = [];
        foreach ($stR->fetchAll() as $r) {
            [$lbl] = label_jenis_donasi($r['jenis']);
            $riwayat[] = [
                'id'          => (int) $r['id'],
                'no_donasi'   => $r['no_donasi'],
                'tanggal'     => $r['tanggal'],
                'jenis'       => $r['jenis'],
                'jenis_label' => $lbl,
                'cara_terima' => $r['cara_terima'],
                'qty'         => (int) $r['qty'],
                'jml_baris'   => (int) $r['jml_baris'],
                'catatan'     => $r['catatan'],
            ];
        }

        ok(['donatur' => baris_donatur($d, $lihatKontak), 'riwayat' => $riwayat]);
    } catch (Throwable $e) {
        error_log('[AMANAH API] donatur detail: ' . $e->getMessage());
        err('Terjadi kesalahan server.', 500);
    }
}

// --------------------------------------------------------------- DAFTAR
$URUT = [
    'nama'    => 'd.nama ASC',
    'sering'  => 'v.jml_donasi DESC, v.total_qty DESC',
    'banyak'  => 'v.total_qty DESC, v.jml_donasi DESC',
    'nilai'   => 'v.total_nilai DESC, v.total_qty DESC',
    'rajin'   => 'v.rata_per_bulan DESC, v.jml_donasi DESC',
    'kunjung' => 'v.jml_kunjungan DESC, v.jml_donasi DESC',
    'baru'    => 'v.donasi_terakhir DESC',
    'lama'    => 'v.donasi_terakhir ASC',
];
$urut = array_key_exists($_GET['urut'] ?? '', $URUT) ? $_GET['urut'] : 'nama';

$where = ['d.deleted_at IS NULL'];
if ($batas !== '') { $where[] = ltrim(str_replace(' AND ', '', $batas)); }

$q = trim($_GET['q'] ?? '');
if ($q !== '') {
    $where[] = "(d.nama LIKE ? OR d.kode LIKE ? OR d.wilayah LIKE ?)";
    $like = '%' . like_escape($q) . '%';
    array_push($par, $like, $like, $like);
}
$jenis = $_GET['jenis'] ?? '';
if (in_array($jenis, ['pribadi', 'komunitas', 'lembaga'], true)) {
    $where[] = 'd.jenis = ?'; $par[] = $jenis;
}
$wilayah = trim($_GET['wilayah'] ?? '');
if ($wilayah !== '') { $where[] = 'd.wilayah = ?'; $par[] = $wilayah; }

[$limit, $offset, $page] = paginate();

try {
    $sqlWhere = 'WHERE ' . implode(' AND ', $where);

    $stC = $pdo->prepare("SELECT COUNT(*) FROM donatur d $sqlWhere");
    $stC->execute($par);
    $total = (int) $stC->fetchColumn();

    $st = $pdo->prepare(
        "SELECT d.*, v.jml_donasi, v.jml_kunjungan, v.jml_paket, v.total_qty,
                v.total_nilai, v.rata_per_bulan, v.donasi_terakhir,
                v.hari_sejak_terakhir, v.donatur_id
         FROM donatur d
         LEFT JOIN v_peringkat_donatur v ON v.donatur_id = d.id
         $sqlWhere
         ORDER BY {$URUT[$urut]}
         LIMIT $limit OFFSET $offset"
    );
    $st->execute($par);

    $data = [];
    foreach ($st->fetchAll() as $d) { $data[] = baris_donatur($d, $lihatKontak); }

    ok([
        'data'  => $data,
        'total' => $total,
        'page'  => $page,
        'limit' => $limit,
        // Aplikasi memakai ini untuk memutuskan apakah kolom kontak
        // ditampilkan sama sekali, bukan untuk menyaring isinya.
        'lihat_kontak' => $lihatKontak,
        'urut'  => $urut,
    ]);
} catch (Throwable $e) {
    error_log('[AMANAH API] donatur: ' . $e->getMessage());
    err('Terjadi kesalahan server.', 500);
}
