<?php
/**
 * Master data endpoint: Supplier, Toko, Gudang, Rak, Penerima
 *
 * GET  /api/master.php?entity=supplier|toko|gudang|rak|penerima
 * GET  /api/master.php?entity=rak&gudang_id=N
 * POST /api/master.php?entity=...
 * PUT  /api/master.php?entity=...&id=N
 * DELETE /api/master.php?entity=...&id=N
 */
require_once __DIR__ . '/_bootstrap.php';
$payload = auth_required();
$method  = $_SERVER['REQUEST_METHOD'];
$entity  = $_GET['entity'] ?? '';
$id      = isset($_GET['id']) ? (int) $_GET['id'] : null;

if (!in_array($entity, ['supplier', 'toko', 'gudang', 'rak', 'penerima'], true)) {
    err("Entity '$entity' tidak dikenal. Pilihan: supplier, toko, gudang, rak, penerima.");
}

$role = $payload['role'];
$cari = trim($_GET['q'] ?? '');
[$limit, $offset] = paginate(50);

/* =====================================================================
   LINGKUP — siapa melihat master siapa
   =====================================================================
   Endpoint ini dulu menjawab daftar LENGKAP untuk token apa pun: supplier
   bisa membaca seluruh rak dan gudang lembaga, toko bisa membaca alamat
   dan telepon setiap penerima amanah. Aplikasi memang tidak menggambar
   semuanya, tapi yang memegang token bisa memanggil endpoint ini sendiri.

   Pagarnya, per entitas:
     gudang / rak   gudang milik akun — api_in_gudang()
     supplier       supplier: dirinya sendiri; toko: tidak ada
     toko           toko: tokonya sendiri (user_toko); supplier: tidak ada
     penerima       akun eksternal hanya menerima id, kode, nama, jenis,
                    wilayah — cukup untuk memilih tujuan penyaluran, tanpa
                    alamat dan telepon (sepadan dengan daftar pilihan di
                    _perpindahan_form.php)
   Penulisan (POST/PUT) ikut dipagari: admin gudang tidak bisa menyunting
   gudang atau rak cabang lain hanya dengan menebak id-nya.
   ===================================================================== */
require_once __DIR__ . '/_lingkup.php';
$inGudang    = api_in_gudang($pdo, $payload);            // '' = tak dibatasi
$pagarGudang = $inGudang === '' ? '' : " AND g.id IN ($inGudang)";
$pagarRak    = $inGudang === '' ? '' : " AND lr.gudang_id IN ($inGudang)";
if ($role === 'supplier') {
    $sidSaya       = (int) ($payload['supplier_id'] ?? 0);
    $pagarSupplier = ' AND id = ' . ($sidSaya > 0 ? $sidSaya : 0);
    $pagarToko     = ' AND 1=0';
} elseif ($role === 'toko') {
    $idsToko       = api_toko_saya($pdo, $payload);
    $pagarSupplier = ' AND 1=0';
    $pagarToko     = ' AND id IN (' . ($idsToko ? implode(',', $idsToko) : '0') . ')';
} else {
    $pagarSupplier = '';
    $pagarToko     = '';
}
$kolomPenerima = api_eksternal($payload)
    ? 'id, kode, nama, jenis, wilayah'
    : 'id, kode, nama, jenis, alamat, telepon, wilayah, keterangan';

/** Hentikan bila gudang $gid di luar lingkup akun ini. */
$wajibGudangSaya = function (int $gid) use ($inGudang): void {
    if ($inGudang === '') { return; }
    if (!in_array((string) $gid, explode(',', $inGudang), true)) {
        err('Gudang itu di luar wewenang akun Anda.', 403);
    }
};

// =====================================================================
// GET — LIST
// =====================================================================
if ($method === 'GET') {
    switch ($entity) {
        case 'supplier':
            try {
                /* deleted_at IS NULL WAJIB ada di sini.
                   Sebelumnya penyaring ini tidak dipasang, jadi supplier yang
                   sudah dihapus tetap muncul di daftar dan tetap bisa dipilih
                   pada transaksi baru — penghapusan yang tampak berhasil tapi
                   tidak mengubah apa pun yang terlihat. */
                $w = $cari ? "deleted_at IS NULL AND (nama LIKE ? OR kode LIKE ?)"
                           : "deleted_at IS NULL";
                $params = $cari ? ['%'.$cari.'%', '%'.$cari.'%'] : [];
                $st = $pdo->prepare(
                    "SELECT id, kode, nama, kontak_pic AS kontak, telepon, email, alamat
                     FROM supplier WHERE $w$pagarSupplier ORDER BY nama LIMIT $limit OFFSET $offset"
                );
                $st->execute($params);
                ok(['data' => $st->fetchAll()]);
            } catch (Throwable $e) { error_log("[AMANAH API] " . basename(__FILE__) . ": " . $e->getMessage()); err("Terjadi kesalahan server.", 500); }

        case 'toko':
            try {
                $where = $cari ? "WHERE (nama LIKE ? OR kode LIKE ?)" : "";
                $params = $cari ? ['%'.$cari.'%', '%'.$cari.'%'] : [];
                $w = $cari ? "deleted_at IS NULL AND (nama LIKE ? OR kode LIKE ?)" : "deleted_at IS NULL";
                $st = $pdo->prepare(
                    "SELECT id, kode, nama, alamat, telepon, marketplace, is_active
                     FROM toko WHERE $w$pagarToko ORDER BY kode LIMIT $limit OFFSET $offset"
                );
                $st->execute($params);
                ok(['data' => $st->fetchAll()]);
            } catch (Throwable $e) { error_log("[AMANAH API] " . basename(__FILE__) . ": " . $e->getMessage()); err("Terjadi kesalahan server.", 500); }

        case 'gudang':
            try {
                $w = $cari ? "deleted_at IS NULL AND (nama LIKE ? OR kode LIKE ?)" : "deleted_at IS NULL";
                $p = $cari ? ['%'.$cari.'%','%'.$cari.'%'] : [];
                $st = $pdo->prepare(
                    "SELECT g.id, g.kode, g.nama, g.jenis, g.alamat, g.penanggung_jawab, g.is_active,
                            COUNT(lr.id) AS jml_rak
                     FROM gudang g
                     LEFT JOIN lokasi_rak lr ON lr.gudang_id=g.id AND lr.deleted_at IS NULL
                     WHERE g.$w$pagarGudang
                     GROUP BY g.id ORDER BY g.kode LIMIT $limit OFFSET $offset"
                );
                $st->execute($p);
                ok(['data' => $st->fetchAll()]);
            } catch (Throwable $e) { error_log("[AMANAH API] " . basename(__FILE__) . ": " . $e->getMessage()); err("Terjadi kesalahan server.", 500); }

        case 'rak':
            $gudangId = (int) ($_GET['gudang_id'] ?? 0);
            try {
                $gWhere = $gudangId ? "AND lr.gudang_id = $gudangId" : '';
                $pWhere = $cari ? "AND lr.kode LIKE ?" : '';
                $p = $cari ? ['%'.$cari.'%'] : [];
                $st = $pdo->prepare(
                    "SELECT lr.id, lr.kode, lr.nama, lr.gudang_id, g.nama AS gudang_nama,
                            lr.kapasitas, lr.keterangan,
                            COALESCE(SUM(bb.qty_tersisa),0) AS total_stok
                     FROM lokasi_rak lr
                     JOIN gudang g ON g.id=lr.gudang_id
                     LEFT JOIN batch_barang bb ON bb.lokasi_rak_id=lr.id AND bb.qty_tersisa>0
                     WHERE lr.deleted_at IS NULL $gWhere $pWhere$pagarRak
                     GROUP BY lr.id ORDER BY g.kode, lr.kode
                     LIMIT $limit OFFSET $offset"
                );
                $st->execute($p);
                ok(['data' => $st->fetchAll()]);
            } catch (Throwable $e) { error_log("[AMANAH API] " . basename(__FILE__) . ": " . $e->getMessage()); err("Terjadi kesalahan server.", 500); }

        case 'penerima':
            try {
                $w = $cari ? "deleted_at IS NULL AND (nama LIKE ? OR kode LIKE ?)" : "deleted_at IS NULL";
                $p = $cari ? ['%'.$cari.'%','%'.$cari.'%'] : [];
                $st = $pdo->prepare(
                    "SELECT $kolomPenerima
                     FROM penerima WHERE $w ORDER BY nama LIMIT $limit OFFSET $offset"
                );
                $st->execute($p);
                ok(['data' => $st->fetchAll()]);
            } catch (Throwable $e) { error_log("[AMANAH API] " . basename(__FILE__) . ": " . $e->getMessage()); err("Terjadi kesalahan server.", 500); }
    }
}

// =====================================================================
// POST — CREATE
// =====================================================================
if ($method === 'POST') {
    /* switch, bukan match: hosting memakai PHP 7.4 dan ekspresi match baru
       ada sejak PHP 8.0. Pemetaannya sama persis dengan versi sebelumnya. */
    switch ($entity) {
        case 'supplier':
        case 'toko':
        case 'gudang':
        case 'rak':
            $allowedRoles = ['super_admin', 'admin_gudang', 'admin_terpadu'];
            break;
        case 'penerima':
            $allowedRoles = ['super_admin', 'pimpinan'];
            break;
        default:
            $allowedRoles = ['super_admin'];
    }
    if (!in_array($role, $allowedRoles, true)) { err('Hak akses tidak cukup.', 403); }

    $b = body();
    $nama = trim($b['nama'] ?? '');
    if (!$nama) { err('Nama wajib diisi.'); }

    try {
        switch ($entity) {
            case 'supplier':
                $pdo->prepare("INSERT INTO supplier (kode,nama,kontak_pic,telepon,email,alamat) VALUES (?,?,?,?,?,?)")
                    ->execute([$b['kode']??'', $nama, $b['kontak']??'', $b['telepon']??'', $b['email']??'', $b['alamat']??'']);
                break;
            case 'toko':
                // Tabel `toko` tidak punya kolom dibuat_oleh — menyebutkannya
                // membuat setiap penambahan toko gagal.
                $pdo->prepare("INSERT INTO toko (kode,nama,alamat,telepon,marketplace) VALUES (?,?,?,?,?)")
                    ->execute([$b['kode']??'', $nama, $b['alamat']??'', $b['telepon']??'', $b['marketplace']??'']);
                break;
            case 'gudang':
                $pdo->prepare("INSERT INTO gudang (kode,nama,jenis,alamat,penanggung_jawab) VALUES (?,?,?,?,?)")
                    ->execute([$b['kode']??'', $nama, $b['jenis']??'umum', $b['alamat']??'', $b['penanggung_jawab']??'']);
                break;
            case 'rak':
                $gudangId = (int) ($b['gudang_id'] ?? 0);
                if (!$gudangId) { err('gudang_id wajib diisi untuk rak.'); }
                $wajibGudangSaya($gudangId);
                /* kapasitas & keterangan ikut disimpan — kolomnya sudah ada
                   di tabel sejak awal, hanya belum pernah diisi lewat API.
                   Kapasitas 0 disimpan NULL, bukan 0: NULL berarti "belum
                   diatur" dan layar ponsel tidak menggambar batangnya,
                   sedangkan 0 akan tergambar sebagai rak yang selalu penuh. */
                $kap = (int) ($b['kapasitas'] ?? 0);
                $pdo->prepare("INSERT INTO lokasi_rak (kode,nama,gudang_id,kapasitas,keterangan) VALUES (?,?,?,?,?)")
                    ->execute([$b['kode']??'', $nama, $gudangId, $kap > 0 ? $kap : null,
                               trim((string) ($b['keterangan'] ?? ''))]);
                break;
            case 'penerima':
                $pdo->prepare("INSERT INTO penerima (kode,nama,jenis,alamat,telepon,keterangan) VALUES (?,?,?,?,?,?)")
                    ->execute([$b['kode']??'', $nama, $b['jenis']??'individu', $b['alamat']??'', $b['telepon']??'', $b['keterangan']??'']);
                break;
        }
        ok(['id' => (int) $pdo->lastInsertId(), 'pesan' => ucfirst($entity) . ' berhasil ditambahkan.']);
    } catch (Throwable $e) { error_log("[AMANAH API] " . basename(__FILE__) . ": " . $e->getMessage()); err("Terjadi kesalahan server.", 500); }
}

// =====================================================================
// PUT — UPDATE
// =====================================================================
if ($method === 'PUT') {
    if (!$id) { err('id wajib diisi.'); }
    if (!in_array($role, ['super_admin','admin_gudang','admin_terpadu'], true)) { err('Hak akses tidak cukup.', 403); }

    $b    = body();
    $nama = trim($b['nama'] ?? '');
    if (!$nama) { err('Nama wajib diisi.'); }

    /* Gudang dan rak yang disunting harus berada di lingkup akun. */
    if ($entity === 'gudang') {
        $wajibGudangSaya($id);
    } elseif ($entity === 'rak') {
        $st = $pdo->prepare("SELECT gudang_id FROM lokasi_rak WHERE id = ? AND deleted_at IS NULL");
        $st->execute([$id]);
        $gidRak = (int) $st->fetchColumn();
        if (!$gidRak) { err('Rak tidak ditemukan.', 404); }
        $wajibGudangSaya($gidRak);
    }

    try {
        switch ($entity) {
            case 'supplier':
                $pdo->prepare("UPDATE supplier SET kode=?,nama=?,kontak_pic=?,telepon=?,email=?,alamat=? WHERE id=?")
                    ->execute([$b['kode']??'', $nama, $b['kontak']??'', $b['telepon']??'', $b['email']??'', $b['alamat']??'', $id]);
                break;
            case 'toko':
                $pdo->prepare("UPDATE toko SET kode=?,nama=?,alamat=?,telepon=?,marketplace=?,is_active=? WHERE id=? AND deleted_at IS NULL")
                    ->execute([$b['kode']??'', $nama, $b['alamat']??'', $b['telepon']??'', $b['marketplace']??'', (int)($b['is_active']??1), $id]);
                break;
            case 'gudang':
                $pdo->prepare("UPDATE gudang SET kode=?,nama=?,jenis=?,alamat=?,penanggung_jawab=?,is_active=? WHERE id=? AND deleted_at IS NULL")
                    ->execute([$b['kode']??'', $nama, $b['jenis']??'umum', $b['alamat']??'', $b['penanggung_jawab']??'', (int)($b['is_active']??1), $id]);
                break;
            case 'rak':
                $pdo->prepare("UPDATE lokasi_rak SET kode=?,nama=? WHERE id=? AND deleted_at IS NULL")
                    ->execute([$b['kode']??'', $nama, $id]);
                /* Kapasitas dan keterangan hanya disentuh bila kuncinya
                   DIKIRIM. Klien lama yang cuma mengirim kode & nama tidak
                   boleh diam-diam mengosongkan kapasitas yang sudah diatur. */
                if (array_key_exists('kapasitas', $b)) {
                    $kap = (int) $b['kapasitas'];
                    $pdo->prepare("UPDATE lokasi_rak SET kapasitas=? WHERE id=?")
                        ->execute([$kap > 0 ? $kap : null, $id]);
                }
                if (array_key_exists('keterangan', $b)) {
                    $pdo->prepare("UPDATE lokasi_rak SET keterangan=? WHERE id=?")
                        ->execute([trim((string) $b['keterangan']), $id]);
                }
                break;
            case 'penerima':
                $pdo->prepare("UPDATE penerima SET kode=?,nama=?,jenis=?,alamat=?,telepon=?,keterangan=? WHERE id=? AND deleted_at IS NULL")
                    ->execute([$b['kode']??'', $nama, $b['jenis']??'individu', $b['alamat']??'', $b['telepon']??'', $b['keterangan']??'', $id]);
                break;
        }
        ok(['pesan' => ucfirst($entity) . ' berhasil diperbarui.']);
    } catch (Throwable $e) { error_log("[AMANAH API] " . basename(__FILE__) . ": " . $e->getMessage()); err("Terjadi kesalahan server.", 500); }
}

// =====================================================================
// DELETE — SOFT DELETE
// =====================================================================
if ($method === 'DELETE') {
    if (!$id) { err('id wajib diisi.'); }
    if ($role !== 'super_admin') { err('Hanya Super Admin yang bisa menghapus.', 403); }

    /* ------------------------------------------------------------------
       PENGAMAN KETERKAITAN

       Sebelumnya penghapusan langsung dijalankan tanpa memeriksa apa pun.
       Gudang yang masih berisi rak, dan rak yang masih menyimpan stok,
       bisa hilang dari daftar begitu saja — sementara butir fisiknya tetap
       di tempatnya, kini tanpa induk data. Kerusakan seperti itu tidak
       memunculkan galat: ia baru ketahuan saat stok opname berikutnya,
       berbulan-bulan kemudian, sebagai selisih yang tidak bisa dijelaskan.

       Pemeriksaannya memakai jumlah, bukan sekadar ada-tidaknya, supaya
       pesannya bisa menyebut angka — "masih ada 4 rak" jauh lebih mudah
       ditindaklanjuti daripada "tidak bisa dihapus".
       ------------------------------------------------------------------ */
    $hitung = function (string $sql, array $par = []) use ($pdo): int {
        $st = $pdo->prepare($sql);
        $st->execute($par);
        return (int) $st->fetchColumn();
    };

    try {
        switch ($entity) {
            case 'gudang':
                $rak = $hitung(
                    "SELECT COUNT(*) FROM lokasi_rak WHERE gudang_id = ? AND deleted_at IS NULL",
                    [$id]
                );
                if ($rak > 0) {
                    err("Tidak bisa dihapus — masih ada $rak rak di gudang ini. "
                      . "Pindahkan atau hapus raknya lebih dulu.");
                }
                $pdo->prepare("UPDATE gudang SET deleted_at=NOW() WHERE id=?")->execute([$id]);
                break;

            case 'rak':
                $sisa = $hitung(
                    "SELECT COALESCE(SUM(qty_tersisa),0) FROM batch_barang
                     WHERE lokasi_rak_id = ? AND qty_tersisa > 0",
                    [$id]
                );
                if ($sisa > 0) {
                    err("Tidak bisa dihapus — masih ada $sisa unit tersimpan di rak ini. "
                      . "Pindahkan barangnya lebih dulu.");
                }
                $pdo->prepare("UPDATE lokasi_rak SET deleted_at=NOW() WHERE id=?")->execute([$id]);
                break;

            case 'toko':
                $pdo->prepare("UPDATE toko SET deleted_at=NOW() WHERE id=?")->execute([$id]);
                break;

            case 'penerima':
                $pdo->prepare("UPDATE penerima SET deleted_at=NOW() WHERE id=?")->execute([$id]);
                break;

            /* Supplier memakai deleted_at, BUKAN is_active.
               Tabel `supplier` tidak punya kolom is_active sama sekali —
               versi sebelumnya menulis ke kolom itu, dan akibatnya setiap
               penghapusan supplier gagal dengan "Unknown column 'is_active'".
               Fitur itu tidak pernah sekali pun berhasil.

               Riwayat barang masuk tetap menunjuk ke barisnya dan tetap
               terbaca; yang berubah hanya ia tidak lagi muncul di daftar
               pilihan. */
            case 'supplier':
                $pdo->prepare("UPDATE supplier SET deleted_at=NOW() WHERE id=?")->execute([$id]);
                break;

            /* Kategori TIDAK ditangani di sini. Ia punya endpoint sendiri
               beserta pengamannya (menolak bila masih ada barang di
               dalamnya). Membiarkannya jatuh ke default berarti server
               menjawab "berhasil dihapus" tanpa menghapus apa pun —
               jawaban yang salah dan tidak mungkin dicurigai. */
            default:
                err('Jenis data ini tidak bisa dihapus lewat endpoint ini.', 400);
        }
        ok(['pesan' => ucfirst($entity) . ' berhasil dihapus.']);
    } catch (Throwable $e) { error_log("[AMANAH API] " . basename(__FILE__) . ": " . $e->getMessage()); err("Terjadi kesalahan server.", 500); }
}

err('Method tidak diizinkan.', 405);
