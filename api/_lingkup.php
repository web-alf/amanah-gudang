<?php
/**
 * Lingkup akses untuk API — versi yang bekerja dari ISI TOKEN.
 *
 * ---------------------------------------------------------------------
 * MENGAPA TIDAK LANGSUNG MEMAKAI _lib.php
 * ---------------------------------------------------------------------
 * _lib.php sudah punya lingkup_stok(), syarat_perpindahan(),
 * boleh_setujui_dokumen(), dan is_manager(). Semuanya membaca $_SESSION,
 * karena aplikasi web memang punya sesi. API tidak: identitas pemanggil
 * ada di dalam JWT. Memanggil fungsi-fungsi itu dari API akan membacanya
 * sebagai "tidak ada yang login" — dan beberapa di antaranya menjawab
 * dengan die() berisi HTML, sehingga balasan API berubah jadi halaman web.
 *
 * Jadi berkas ini menyalin ATURANNYA, bukan memanggil fungsinya, dan
 * setiap fungsi di bawah menyebut padanannya di _lib.php supaya kalau satu
 * berubah, yang lain ketahuan harus ikut berubah.
 *
 * Berkas berawalan "_" diblokir .htaccess, jadi tidak bisa diakses langsung.
 *
 * ---------------------------------------------------------------------
 * SATU PENGECUALIAN: KATALOG IZIN
 * ---------------------------------------------------------------------
 * katalog_izin() di _lib.php TIDAK disalin ke sini, melainkan dipakai
 * langsung — ia daftar tetap, tanpa $_SESSION dan tanpa die().
 *
 * Menyalinnya justru berbahaya. Katalog itu memuat PAGAR: peran mana yang
 * boleh diberi wewenang apa. Dua salinan pagar yang perlahan berbeda
 * berarti aplikasi memperbolehkan apa yang halamannya larang — dan itu
 * tidak akan terlihat sampai ada yang memakainya.
 *
 * Dimuat di sini, bukan diserahkan ke tiap endpoint: _bootstrap.php tidak
 * memuat _lib.php, sehingga endpoint yang lupa memuatnya sendiri akan
 * membaca "tidak ada katalog" dan diam-diam mengabaikan izin yang
 * DIBERIKAN — gagal tanpa satu pun pesan.
 */
require_once __DIR__ . '/../_lib.php';

/** Padanan is_eksternal() — mitra dan toko, bukan orang dalam gudang. */
function api_eksternal(array $p): bool {
    return in_array($p['role'] ?? '', ['supplier', 'toko'], true);
}

/** Padanan boleh_setujui() — siapa penyetuju umum. */
function api_boleh_setujui(array $p): bool {
    return in_array($p['role'] ?? '', ['super_admin', 'pimpinan'], true);
}

/** Padanan is_manager() — tingkat dibaca dari token, diisi login.php. */
function api_manager(array $p): bool {
    return ($p['tingkat'] ?? 'staff') === 'manager'
        || in_array($p['role'] ?? '', ['super_admin', 'pimpinan'], true);
}

/**
 * Padanan izin_saya(): penyimpangan izin milik pemegang token.
 *
 * TIDAK dibaca dari token, melainkan dari basis data setiap permintaan.
 * Sengaja: token berumur panjang, dan izin yang dicabut hari ini harus
 * berlaku hari ini juga — bukan menunggu pemiliknya kebetulan masuk lagi.
 * Satu kueri kecil berkunci primer, sekali per permintaan.
 */
function api_izin_saya(PDO $pdo, array $p): array {
    static $hafal = null;
    if ($hafal !== null) { return $hafal; }

    // Super Admin TIDAK dikecualikan — cerminan izin_saya() di _lib.php,
    // yang kini juga berlaku untuk semua peran tanpa kecuali.
    $uid = (int) ($p['user_id'] ?? 0);
    if ($uid <= 0) { return $hafal = []; }

    try {
        $st = $pdo->prepare("SELECT kunci, nilai FROM izin_pengguna WHERE user_id = ?");
        $st->execute([$uid]);
        $peta = [];
        foreach ($st->fetchAll() as $r) { $peta[$r['kunci']] = ((int) $r['nilai']) === 1; }
        return $hafal = $peta;
    } catch (Throwable $e) {
        /* Tabelnya belum ada di server ini. Jatuh ke BAWAAN — perilaku
           persis seperti sebelum fitur ini ada, yang sudah teruji — bukan
           menolak semuanya, yang akan mematikan aplikasi di hosting yang
           belum sempat dimigrasi. */
        error_log('[AMANAH API] izin: ' . $e->getMessage());
        return $hafal = [];
    }
}

/**
 * Padanan izin(): bawaan dari peran & tingkat, disimpangkan per akun.
 *
 * Aturannya sama persis dengan sisi web, dan itu bukan kebetulan: dua
 * penafsir izin yang boleh berbeda adalah cara paling mudah membuat
 * aplikasi memperbolehkan apa yang halamannya larang.
 *
 * Pagarnya dibaca dari katalog_izin() di _lib.php bila tersedia. Endpoint
 * API memuat _lib.php lewat _bootstrap.php, jadi dalam keadaan normal ia
 * selalu ada; pemeriksaan function_exists hanya menjaga agar berkas ini
 * tetap bisa dipakai sendiri tanpa mati.
 */
function api_izin(PDO $pdo, array $p, string $kunci, bool $bawaan): bool {
    $peta = api_izin_saya($pdo, $p);
    if (!array_key_exists($kunci, $peta)) { return $bawaan; }
    // Kunci di luar katalog tidak diperiksa fungsi mana pun; menghormatinya
    // hanya menghasilkan izin yang terlihat aktif tapi tidak berarti apa-apa.
    if (!isset(katalog_izin()[$kunci])) { return $bawaan; }
    return $peta[$kunci];
}

/** Padanan boleh_lihat_nilai(): angka rupiah agregat. */
function api_lihat_nilai(PDO $pdo, array $p): bool {
    return api_izin($pdo, $p, 'lihat_nilai', api_manager($p));
}

/** Padanan boleh_lihat_laporan(). */
function api_lihat_laporan(PDO $pdo, array $p): bool {
    return api_izin($pdo, $p, 'lihat_laporan', api_manager($p));
}

/** Padanan terikat_satu_gudang() — admin gudang dan admin terpadu. */
function api_terikat_gudang(array $p): bool {
    return in_array($p['role'] ?? '', ['admin_gudang', 'admin_terpadu'], true);
}

/** Id gudang yang mengikat akun ini, atau null bila melihat seluruh rantai. */
function api_gudang_id(array $p): ?int {
    if (!api_terikat_gudang($p)) { return null; }
    return $p['gudang_id'] !== null ? (int) $p['gudang_id'] : 0;
}

/** Daftar id rak milik gudang akun ini, untuk klausa IN(...). */
function api_in_rak(PDO $pdo, array $p): string {
    $gid = api_gudang_id($p);
    if ($gid === null) { return ''; }          // tak dibatasi
    if ($gid <= 0)     { return '0'; }         // terikat tapi tanpa gudang
    $ids = array_map('intval', $pdo->query(
        "SELECT id FROM lokasi_rak WHERE gudang_id = $gid AND deleted_at IS NULL"
    )->fetchAll(PDO::FETCH_COLUMN));
    return $ids ? implode(',', $ids) : '0';
}

/**
 * Padanan toko_saya(): toko yang dipegang akun ini.
 *
 * Kaitannya lewat tabel penghubung `user_toko` — satu akun bisa memegang
 * beberapa toko. Untuk staf internal, seluruh toko dikembalikan.
 */
function api_toko_saya(PDO $pdo, array $p): array {
    if (($p['role'] ?? '') !== 'toko') {
        return array_map('intval', $pdo->query(
            "SELECT id FROM toko WHERE deleted_at IS NULL ORDER BY kode"
        )->fetchAll(PDO::FETCH_COLUMN));
    }
    $st = $pdo->prepare(
        "SELECT t.id FROM user_toko ut
         JOIN toko t ON t.id = ut.toko_id AND t.deleted_at IS NULL AND t.is_active = 1
         WHERE ut.user_id = ? ORDER BY t.kode"
    );
    $st->execute([(int) ($p['user_id'] ?? 0)]);
    return array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
}

/**
 * Padanan lingkup_stok(): batas persediaan yang boleh dilihat akun ini.
 *
 * Bentuknya sengaja sama dengan lingkup_stok() di _lib.php — 'semua',
 * 'rak', 'toko', 'supplier' — supaya keduanya bisa dibandingkan baris per
 * baris ketika aturannya berubah. Aturan lingkup yang menyimpang antara web
 * dan API adalah cara paling mudah untuk membocorkan data tanpa ada yang
 * menyadarinya: halamannya rapi, tapi endpoint-nya menjawab lebih banyak
 * daripada yang digambar di layar.
 */
function api_lingkup_stok(PDO $pdo, array $p): array {
    $dasar = ['semua' => false, 'rak' => [], 'toko' => [], 'supplier' => null];
    $role  = $p['role'] ?? '';

    if ($role === 'super_admin' || $role === 'pimpinan') {
        return array_merge($dasar, ['semua' => true]);
    }

    if (api_terikat_gudang($p)) {
        $inRak = api_in_rak($pdo, $p);
        $rak   = ($inRak === '' || $inRak === '0')
            ? [] : array_map('intval', explode(',', $inRak));
        return array_merge($dasar, ['rak' => $rak]);
    }

    if ($role === 'toko') {
        /* Toko punya DUA tempat stok — gudang penampung bersama, dan stok
           yang sudah berada di masing-masing tokonya. Keduanya masuk,
           persis seperti lingkup_stok(). Menyembunyikan salah satunya
           membuat total di aplikasi tidak pernah cocok dengan barang yang
           benar-benar dipegang toko, dan itu terbaca sebagai data hilang. */
        /* Id akun ikut dikirim supaya penugasan gudangnya dihormati —
           sepadan dengan gudang_toko_saya() di sisi web. */
        return array_merge($dasar, [
            'rak'  => api_rak_gudang_toko($pdo, (int) ($p['user_id'] ?? 0)),
            'toko' => api_toko_saya($pdo, $p),
        ]);
    }

    if ($role === 'supplier') {
        $sid = isset($p['supplier_id']) ? (int) $p['supplier_id'] : 0;
        return array_merge($dasar, ['supplier' => $sid ?: null]);
    }

    // Peran tak dikenal tidak diberi apa pun. Menganggapnya 'semua' berarti
    // satu peran baru yang lupa didaftarkan langsung melihat seluruh lembaga.
    return $dasar;
}

/**
 * Rak gudang sisi toko yang dipegang akun ini.
 *
 * $uid ikut dibawa karena API tidak punya sesi: penugasan gudang dibaca
 * dari user_gudang menurut id akun pada tokennya.
 *
 * Kalau akun itu punya penugasan gudang, hanya rak gudang ITU yang masuk.
 * Kalau tidak, seluruh rak gudang berjenis 'toko' — perilaku lama. Aturan
 * yang sama dengan gudang_toko_saya() di _lib.php; kalau keduanya berbeda,
 * aplikasi Android dan web akan menampilkan stok yang tidak sama untuk
 * akun yang sama.
 */
function api_rak_gudang_toko(PDO $pdo, int $uid = 0): array {
    if ($uid > 0) {
        try {
            $st = $pdo->prepare(
                "SELECT lr.id FROM lokasi_rak lr
                   JOIN user_gudang ug ON ug.gudang_id = lr.gudang_id
                   JOIN gudang g       ON g.id = lr.gudang_id
                  WHERE ug.user_id = ? AND g.deleted_at IS NULL AND g.is_active = 1
                    AND lr.deleted_at IS NULL");
            $st->execute([$uid]);
            $pilih = array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
            if ($pilih) { return $pilih; }
        } catch (Throwable $e) {
            error_log('[AMANAH] api_rak_gudang_toko: ' . $e->getMessage());
        }
    }
    return array_map('intval', $pdo->query(
        "SELECT lr.id FROM lokasi_rak lr
         JOIN gudang g ON g.id = lr.gudang_id
         WHERE g.jenis = 'toko' AND g.deleted_at IS NULL AND lr.deleted_at IS NULL"
    )->fetchAll(PDO::FETCH_COLUMN));
}

/**
 * Padanan syarat_lingkup(): klausa WHERE untuk view v_stok_rantai dan
 * v_expired_rantai, yang ketiganya membawa lokasi_rak_id, toko_id, dan
 * supplier_id.
 *
 * Mengembalikan '1=0' bila lingkupnya kosong — BUKAN '1=1'. Akun yang
 * belum ditugaskan ke mana pun harus melihat nol, bukan segalanya.
 */
function api_syarat_lingkup(PDO $pdo, array $p, string $alias = ''): string {
    $L = api_lingkup_stok($pdo, $p);
    if ($L['semua']) { return '1=1'; }

    $a = $alias !== '' ? $alias . '.' : '';
    $bagian = [];
    if ($L['rak']) {
        $bagian[] = "{$a}lokasi_rak_id IN (" . implode(',', $L['rak']) . ')';
    }
    if ($L['toko']) {
        $bagian[] = "{$a}toko_id IN (" . implode(',', $L['toko']) . ')';
    }
    if ($L['supplier']) {
        $bagian[] = "{$a}supplier_id = " . (int) $L['supplier'];
    }
    return $bagian ? '(' . implode(' OR ', $bagian) . ')' : '1=0';
}

/**
 * Padanan syarat_perpindahan(): dokumen dianggap milik lingkup bila ASAL
 * ATAU TUJUAN-nya menyentuhnya. Dipakai supaya Surat Jalan, Persetujuan,
 * dan Penerimaan memakai batas yang sama — kalau berbeda, satu halaman
 * menyembunyikan dokumen yang halaman lain tampilkan.
 */
function api_syarat_perpindahan(PDO $pdo, array $p, string $alias = 'p'): string {
    $role = $p['role'] ?? '';
    /* HANYA super admin. Pimpinan dikeluarkan, sepadan dengan
       syarat_perpindahan() di _lib.php: surat jalan memuat nama penerima
       manfaat dan alamat pengirimannya, dan itu hanya urusan pengirim dan
       penerimanya. Kalau daftar ini tidak ikut disempitkan, membatasi sisi
       web tidak ada gunanya — aplikasi Android membacanya lewat token yang
       sama. */
    if ($role === 'super_admin') { return '1=1'; }

    $a = $alias !== '' ? $alias . '.' : '';
    $bagian = [];
    $pasang = function (string $tipe, string $daftar) use ($a, &$bagian) {
        if ($daftar === '' || $daftar === '0') { return; }
        $bagian[] = "({$a}asal_tipe = '$tipe' AND {$a}asal_id IN ($daftar))";
        $bagian[] = "({$a}tujuan_tipe = '$tipe' AND {$a}tujuan_id IN ($daftar))";
    };

    if ($role === 'supplier') {
        $sid = $p['supplier_id'] !== null ? (int) $p['supplier_id'] : 0;
        $pasang('supplier', (string) $sid);
    } elseif ($role === 'toko') {
        /* Satu akun toko bisa memegang lebih dari satu toko, jadi kaitannya
           ada di tabel penghubung user_toko — bukan sebuah kolom di users.
           Dibaca ulang tiap permintaan, tidak dititipkan ke token: token
           berlaku tujuh hari dan penugasan toko bisa berubah di tengah itu. */
        $ids = api_toko_saya($pdo, $p);
        $pasang('toko', $ids ? implode(',', $ids) : '0');
    } else {
        $rak = api_in_rak($pdo, $p);
        if ($rak !== '') { $pasang('rak', $rak); }
        $gid = api_gudang_id($p);
        if ($gid) { $pasang('gudang', (string) $gid); }
    }

    return $bagian ? '(' . implode(' OR ', $bagian) . ')' : '1=0';
}

/**
 * Padanan syarat_persetujuan_saya(): dokumen yang WAJIB terlihat oleh
 * penyetujunya meski di luar lingkup gudangnya.
 *
 * Ada karena batas lingkup di atas pernah menyembunyikan dokumen dari
 * manager yang justru ditunjuk menyetujuinya — penyaluran langsung dari
 * mitra ke penerima amanah tidak menyentuh gudang mana pun.
 */
function api_syarat_persetujuan_saya(PDO $pdo, array $p, string $alias = 'p'): string {
    if (api_boleh_setujui($p)) { return '1=1'; }
    $a = $alias !== '' ? $alias . '.' : '';
    if (!api_eksternal($p) && api_manager($p)) {
        return "({$a}jenis = 'penyaluran' AND {$a}asal_tipe = 'supplier')";
    }
    return '1=0';
}

/** Padanan boleh_setujui_dokumen() untuk satu dokumen konkret. */
function api_boleh_setujui_dokumen(array $p, array $pp): bool {
    if (api_boleh_setujui($p)) { return true; }
    if (($pp['jenis'] ?? '') === 'penyaluran' && ($pp['asal_tipe'] ?? '') === 'supplier') {
        // Mitra tidak boleh menyetujui pengeluarannya sendiri — itu
        // menghapus gunanya persetujuan.
        return !api_eksternal($p) && api_manager($p);
    }
    return false;
}

/* =====================================================================
   LINGKUP UNTUK TABEL batch_barang DAN ANGKA STOK — ditambahkan 13 Sep 2026
   =====================================================================
   Sebelum ini master.php, monitoring.php, scan.php, alur.php, dan
   barang.php membaca tanpa lingkup sama sekali: token toko atau supplier
   bisa menarik batch, rak, dan stok seluruh lembaga. Tiga pembantu di
   bawah dipakai kelima berkas itu supaya aturannya ditulis sekali.
   ===================================================================== */

/**
 * Klausa WHERE untuk batch_barang menurut lingkup akun.
 *
 * Supplier mendapat '1=0': stoknya hidup di stok_supplier, bukan di
 * batch_barang — dan batch_barang.supplier_id hanya mencatat dari siapa
 * lembaga membeli, bukan siapa yang memegang barangnya.
 */
function api_syarat_batch(PDO $pdo, array $p, string $alias = 'bb'): string {
    $L = api_lingkup_stok($pdo, $p);
    if ($L['semua']) { return '1=1'; }
    $a = $alias !== '' ? $alias . '.' : '';
    $bagian = [];
    if ($L['rak'])  { $bagian[] = "{$a}lokasi_rak_id IN (" . implode(',', $L['rak']) . ')'; }
    if ($L['toko']) { $bagian[] = "{$a}toko_id IN (" . implode(',', $L['toko']) . ')'; }
    return $bagian ? '(' . implode(' OR ', $bagian) . ')' : '1=0';
}

/**
 * Ekspresi SQL "stok barang ini dalam lingkup akun" — untuk SELECT & WHERE.
 * $barangRef adalah kolom id barang pada kueri luar, mis. 'b.id'.
 *
 * Akun lembaga memakai tabel `stok` (total, sudah dihitung). Yang lain
 * menjumlahkan batch/stok_supplier miliknya sendiri — angka yang benar
 * untuk mereka, bukan total yayasan yang kebetulan disembunyikan di layar.
 */
function api_sub_stok(PDO $pdo, array $p, string $barangRef = 'b.id'): string {
    $L = api_lingkup_stok($pdo, $p);
    if ($L['semua']) {
        return "COALESCE((SELECT s.qty_total FROM stok s WHERE s.barang_id = $barangRef), 0)";
    }
    if ($L['supplier']) {
        return "COALESCE((SELECT SUM(ss.qty) FROM stok_supplier ss
                 WHERE ss.barang_id = $barangRef AND ss.supplier_id = " . (int) $L['supplier'] . "), 0)";
    }
    $sy = api_syarat_batch($pdo, $p, 'bb');
    return "COALESCE((SELECT SUM(bb.qty_tersisa) FROM batch_barang bb
             WHERE bb.barang_id = $barangRef AND $sy), 0)";
}

/**
 * Daftar id gudang yang boleh dilihat akun ini, untuk IN(...).
 * '' = tak dibatasi; '0' = terikat tapi tidak punya gudang.
 *
 *   super_admin/pimpinan   semua
 *   admin_gudang/terpadu   gudangnya sendiri
 *   toko                   gudang toko yang ditugaskan (user_gudang), atau
 *                          seluruh gudang berjenis 'toko' bila belum ditugaskan
 *   supplier               gudang miliknya (gudang.supplier_id)
 */
function api_in_gudang(PDO $pdo, array $p): string {
    $role = $p['role'] ?? '';
    if ($role === 'super_admin' || $role === 'pimpinan') { return ''; }
    if (api_terikat_gudang($p)) {
        $gid = api_gudang_id($p);
        return $gid ? (string) $gid : '0';
    }
    try {
        if ($role === 'toko') {
            $st = $pdo->prepare(
                "SELECT g.id FROM user_gudang ug JOIN gudang g ON g.id = ug.gudang_id
                  WHERE ug.user_id = ? AND g.deleted_at IS NULL AND g.is_active = 1");
            $st->execute([(int) ($p['user_id'] ?? 0)]);
            $ids = array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
            if (!$ids) {
                $ids = array_map('intval', $pdo->query(
                    "SELECT id FROM gudang WHERE jenis = 'toko' AND deleted_at IS NULL AND is_active = 1"
                )->fetchAll(PDO::FETCH_COLUMN));
            }
            return $ids ? implode(',', $ids) : '0';
        }
        if ($role === 'supplier') {
            $sid = (int) ($p['supplier_id'] ?? 0);
            if ($sid <= 0) { return '0'; }
            $st = $pdo->prepare("SELECT id FROM gudang WHERE supplier_id = ? AND deleted_at IS NULL");
            $st->execute([$sid]);
            $ids = array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
            return $ids ? implode(',', $ids) : '0';
        }
    } catch (Throwable $e) {
        error_log('[AMANAH API] api_in_gudang: ' . $e->getMessage());
    }
    return '0';
}
