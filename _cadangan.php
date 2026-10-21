<?php
/**
 * AMANAH — Cadangan basis data (12 Okt 2026)
 *
 * Membuat berkas SQL lengkap (struktur + isi + view) dari basis data yang
 * sedang dipakai, memampatkannya menjadi .sql.gz, menyimpannya di folder
 * cadangan_berkas/ (tertutup dari HTTP), dan — bila diatur — mengunggahnya ke
 * Google Drive.
 *
 * MENGAPA DITULIS SENDIRI, BUKAN MEMANGGIL mysqldump
 *   Di hosting bersama, exec() sering dimatikan dan mysqldump tidak ada di
 *   PATH. Pembuat SQL di bawah hanya memakai PDO yang memang sudah dipakai
 *   aplikasi, jadi jalan di mana pun aplikasinya jalan. Hasilnya bisa
 *   diimpor lewat phpMyAdmin seperti dump biasa.
 *
 * TIGA CARA PEMBUATAN
 *   manual    tombol di halaman Cadangan Data
 *   cron      cron hosting memanggil cron_cadangan.php?kunci=… tiap jam
 *   otomatis  cadangan ketinggalan (lihat cadangan_perlu_otomatis) dipicu
 *             diam-diam dari halaman yang dibuka Super Admin — pengganti
 *             cron untuk hosting yang tidak menyediakannya
 *
 * Dipakai cadangan.php, cron_cadangan.php, dan _footer.php.
 */

const CADANGAN_DIR = 'cadangan_berkas';   // bukan 'cadangan': hosting membuang .php, jadi /cadangan.php → /cadangan bertabrakan dengan folder ini

/** Folder cadangan, dibuat (beserta .htaccess penutup) bila belum ada. */
function cadangan_dir(): string {
    $dir = __DIR__ . '/' . CADANGAN_DIR;
    if (!is_dir($dir)) { @mkdir($dir, 0755, true); }
    if (!is_file($dir . '/.htaccess')) {
        @file_put_contents($dir . '/.htaccess',
            "# Berkas cadangan berisi seluruh isi basis data. Jangan pernah bisa diminta lewat HTTP.\n"
          . "<IfModule mod_authz_core.c>\n    Require all denied\n</IfModule>\n"
          . "<IfModule !mod_authz_core.c>\n    Order allow,deny\n    Deny from all\n</IfModule>\n");
    }
    return $dir;
}

/** Tabel catatan cadangan — dibuat bila belum ada (di luar transaksi). */
function cadangan_pastikan_tabel(PDO $pdo): void {
    static $sudah = false;
    if ($sudah) { return; }
    $sudah = true;
    try {
        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS cadangan_log (
               id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
               berkas      VARCHAR(120) NOT NULL,
               ukuran      BIGINT UNSIGNED NOT NULL DEFAULT 0,
               cara        ENUM('manual','cron','otomatis') NOT NULL DEFAULT 'manual',
               user_id     BIGINT UNSIGNED NULL,
               jml_tabel   INT NOT NULL DEFAULT 0,
               jml_baris   BIGINT NOT NULL DEFAULT 0,
               drive_id    VARCHAR(120) NULL,
               drive_pesan VARCHAR(255) NULL,
               created_at  TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
               PRIMARY KEY (id), KEY idx_cad_waktu (created_at)
             ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    } catch (Throwable $e) { error_log('[AMANAH] cadangan_log: ' . $e->getMessage()); }
}

/**
 * Tulis seluruh basis data sebagai SQL ke sebuah handle gzip.
 * @return array{tabel:int, baris:int}
 */
function cadangan_tulis_sql(PDO $pdo, $gz, string $namaDb): array {
    $tulis = function (string $s) use ($gz): void { gzwrite($gz, $s); };
    $tulis("-- AMANAH — cadangan basis data `$namaDb`\n-- Dibuat " . date('Y-m-d H:i:s') . " oleh aplikasi (bukan mysqldump)\n"
         . "-- Impor lewat phpMyAdmin (Import) atau: mysql -u USER -p DB < berkas.sql\n\n"
         . "SET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS=0;\nSET SQL_MODE='NO_AUTO_VALUE_ON_ZERO';\nSET time_zone='+00:00';\n\n");

    $tabel = $pdo->query("SELECT TABLE_NAME FROM information_schema.tables WHERE table_schema = DATABASE() AND TABLE_TYPE = 'BASE TABLE' ORDER BY TABLE_NAME")->fetchAll(PDO::FETCH_COLUMN);
    $view  = $pdo->query("SELECT TABLE_NAME FROM information_schema.views  WHERE table_schema = DATABASE() ORDER BY TABLE_NAME")->fetchAll(PDO::FETCH_COLUMN);
    $totalBaris = 0;

    foreach ($tabel as $t) {
        $buat = $pdo->query("SHOW CREATE TABLE `$t`")->fetch(PDO::FETCH_NUM);
        $tulis("-- ---------------------------------------------------------------\n-- Tabel `$t`\n-- ---------------------------------------------------------------\n"
             . "DROP TABLE IF EXISTS `$t`;\n" . $buat[1] . ";\n\n");

        /* Baris diambil bertahap supaya memori tetap kecil walau tabelnya
           besar; INSERT digabung per 200 baris supaya impornya cepat. */
        $kolom = $pdo->query("SELECT COLUMN_NAME FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = " . $pdo->quote($t) . " ORDER BY ORDINAL_POSITION")->fetchAll(PDO::FETCH_COLUMN);
        $kepala = "INSERT INTO `$t` (`" . implode('`, `', $kolom) . "`) VALUES\n";
        $offset = 0; $per = 500; $buf = []; $n = 0;
        while (true) {
            $rows = $pdo->query("SELECT * FROM `$t` LIMIT $per OFFSET $offset")->fetchAll(PDO::FETCH_NUM);
            if (!$rows) { break; }
            foreach ($rows as $r) {
                $nilai = [];
                foreach ($r as $v) {
                    if ($v === null) { $nilai[] = 'NULL'; }
                    elseif (is_int($v) || is_float($v)) { $nilai[] = (string) $v; }
                    else { $nilai[] = $pdo->quote((string) $v); }
                }
                $buf[] = '(' . implode(',', $nilai) . ')';
                $n++;
                if (count($buf) >= 200) { $tulis($kepala . implode(",\n", $buf) . ";\n"); $buf = []; }
            }
            if (count($rows) < $per) { break; }
            $offset += $per;
        }
        if ($buf) { $tulis($kepala . implode(",\n", $buf) . ";\n"); }
        if ($n) { $tulis("\n"); }
        $totalBaris += $n;
    }

    /* View ditulis SESUDAH tabel, dan DEFINER dibuang: nama pengguna MySQL
       hosting berbeda dengan lokal, dan DEFINER yang tidak ada membuat
       impor gagal. */
    /* URUTAN VIEW: view yang membaca view lain harus ditulis SESUDAHNYA.
       Urutan abjad tidak menjamin itu (v_status_stok bisa membaca
       v_status_expired), dan impor berhenti dengan "Table doesn't exist".
       Diurutkan menurut rujukan: yang tidak merujuk view lain dulu. */
    $def = [];
    foreach ($view as $v) {
        $buat = $pdo->query("SHOW CREATE VIEW `$v`")->fetch(PDO::FETCH_NUM);
        $sql  = preg_replace('/\sDEFINER=`[^`]*`@`[^`]*`/', '', (string) $buat[1]);
        $def[$v] = preg_replace('/^CREATE (ALGORITHM=\w+ )?/', 'CREATE OR REPLACE $1', $sql, 1);
    }
    $sisa = $view; $urut = [];
    while ($sisa) {
        $maju = false;
        foreach ($sisa as $i => $v) {
            $butuhLain = false;
            foreach ($sisa as $w) {
                if ($w !== $v && preg_match('/`' . preg_quote($w, '/') . '`/', $def[$v])) { $butuhLain = true; break; }
            }
            if (!$butuhLain) { $urut[] = $v; unset($sisa[$i]); $maju = true; }
        }
        if (!$maju) { foreach ($sisa as $v) { $urut[] = $v; } break; }   // rujukan melingkar: tulis apa adanya
    }
    foreach ($urut as $v) {
        $tulis("-- ---------------------------------------------------------------\n-- View `$v`\n-- ---------------------------------------------------------------\n"
             . "DROP TABLE IF EXISTS `$v`;\n" . $def[$v] . ";\n\n");
    }
    $tulis("SET FOREIGN_KEY_CHECKS=1;\n-- selesai\n");
    return ['tabel' => count($tabel) + count($view), 'baris' => $totalBaris];
}

/**
 * Buat satu cadangan lengkap. Mengembalikan catatan hasilnya.
 * @return array{berkas:string, ukuran:int, tabel:int, baris:int, drive_id:?string, drive_pesan:?string}
 */
function cadangan_buat(PDO $pdo, string $cara = 'manual', ?int $userId = null): array {
    cadangan_pastikan_tabel($pdo);
    $dir    = cadangan_dir();
    $namaDb = (string) ($GLOBALS['DB_NAME'] ?? 'amanah');
    $berkas = 'amanah_' . date('Ymd_His') . '.sql.gz';
    $path   = $dir . '/' . $berkas;
    @set_time_limit(300);

    $gz = gzopen($path, 'wb6');
    if (!$gz) { throw new RuntimeException('Folder cadangan_berkas/ tidak bisa ditulisi. Periksa izin foldernya di hosting.'); }
    try {
        $h = cadangan_tulis_sql($pdo, $gz, $namaDb);
    } finally { gzclose($gz); }
    $ukuran = (int) filesize($path);
    if ($ukuran < 200) { @unlink($path); throw new RuntimeException('Berkas cadangan kosong — pembuatan gagal.'); }

    $driveId = null; $drivePesan = null;
    if (cadangan_drive_siap($pdo)) {
        try {
            $driveId = cadangan_drive_unggah($pdo, $path, $berkas);
            $drivePesan = 'Terunggah ke Google Drive.';
            try { $dihapus = cadangan_drive_rapikan($pdo); if ($dihapus) { $drivePesan .= " $dihapus cadangan lama dihapus dari Drive."; } }
            catch (Throwable $e) { error_log('[AMANAH] rapikan drive: ' . $e->getMessage()); }
        } catch (Throwable $e) {
            // Gagal ke Drive tidak menggagalkan cadangannya — berkas lokal tetap ada.
            $drivePesan = 'Drive gagal: ' . mb_substr($e->getMessage(), 0, 220);
            error_log('[AMANAH] cadangan drive: ' . $e->getMessage());
        }
    }

    try {
        $pdo->prepare("INSERT INTO cadangan_log (berkas, ukuran, cara, user_id, jml_tabel, jml_baris, drive_id, drive_pesan) VALUES (?,?,?,?,?,?,?,?)")
            ->execute([$berkas, $ukuran, $cara, $userId, $h['tabel'], $h['baris'], $driveId, $drivePesan]);
        if ($cara !== 'manual') { simpan_pengaturan($pdo, 'cadangan_terakhir_otomatis', date('Y-m-d H:i:s')); }
        simpan_pengaturan($pdo, 'cadangan_terakhir', date('Y-m-d H:i:s'));
    } catch (Throwable $e) { error_log('[AMANAH] catat cadangan: ' . $e->getMessage()); }

    cadangan_rapikan($pdo);
    return ['berkas' => $berkas, 'ukuran' => $ukuran, 'tabel' => $h['tabel'], 'baris' => $h['baris'],
            'drive_id' => $driveId, 'drive_pesan' => $drivePesan];
}

/** Hapus cadangan lokal yang lebih lama dari N terakhir (setelan cadangan_simpan, bawaan 14). */
function cadangan_rapikan(PDO $pdo): void {
    $simpan = max(1, (int) pengaturan($pdo, 'cadangan_simpan', '14'));
    $daftar = cadangan_daftar();
    foreach (array_slice($daftar, $simpan) as $b) {
        @unlink(cadangan_dir() . '/' . $b['berkas']);
    }
}

/** Daftar berkas cadangan lokal, terbaru dulu. */
function cadangan_daftar(): array {
    $h = [];
    foreach (glob(cadangan_dir() . '/amanah_*.sql.gz') ?: [] as $p) {
        $h[] = ['berkas' => basename($p), 'ukuran' => (int) filesize($p), 'waktu' => (int) filemtime($p)];
    }
    usort($h, fn($a, $b) => $b['waktu'] <=> $a['waktu']);
    return $h;
}

/** Nama berkas cadangan yang sah — hanya pola kita sendiri, tanpa jalur. */
function cadangan_nama_sah(string $nama): bool {
    return (bool) preg_match('/^amanah_\d{8}_\d{6}\.sql\.gz$/', $nama);
}

/* =========================================================================
   JADWAL OTOMATIS — jarak antar cadangan pada jam aktif, satu kali di luarnya
   -------------------------------------------------------------------------
   Setelan: cadangan_otomatis (1/0), cadangan_interval_jam (1,2,3,6,12,24),
   cadangan_jam_mulai / cadangan_jam_selesai (bawaan 05:00–22:30; rentang
   boleh melewati tengah malam), cadangan_malam_sekali (1 = di luar jam aktif
   cukup satu kali). Semua pemicu (cron tiap jam, pemicu diam-diam dari
   halaman) memakai cadangan_perlu_otomatis() yang sama.
   ========================================================================= */

function cadangan_jadwal(PDO $pdo): array {
    $interval = (int) pengaturan($pdo, 'cadangan_interval_jam', '1');
    if (!in_array($interval, [1, 2, 3, 6, 12, 24], true)) { $interval = 1; }
    $jamSah = function (string $jam, string $bawaan): string {
        return preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $jam) ? $jam : $bawaan;
    };
    return [
        'interval'     => $interval,
        'jam_mulai'    => $jamSah(pengaturan($pdo, 'cadangan_jam_mulai', '05:00'), '05:00'),
        'jam_selesai'  => $jamSah(pengaturan($pdo, 'cadangan_jam_selesai', '22:30'), '22:30'),
        'malam_sekali' => pengaturan($pdo, 'cadangan_malam_sekali', '1') === '1',
    ];
}

/** Sekarang berada di jam aktif? (rentang boleh melewati tengah malam) */
function cadangan_di_jam_aktif(array $j, ?int $saat = null): bool {
    $t = date('H:i', $saat ?? time());
    [$m, $s] = [$j['jam_mulai'], $j['jam_selesai']];
    return $m <= $s ? ($t >= $m && $t <= $s) : ($t >= $m || $t <= $s);
}

/** Saat jam aktif terakhir berakhir (awal masa "malam" yang sedang berjalan), sebagai timestamp. */
function cadangan_awal_malam(array $j, ?int $saat = null): int {
    $saat = $saat ?? time();
    [$jam, $menit] = array_map('intval', explode(':', $j['jam_selesai']));
    $akhir = mktime($jam, $menit, 0, (int) date('n', $saat), (int) date('j', $saat), (int) date('Y', $saat));
    return $akhir > $saat ? $akhir - 86400 : $akhir;
}

/** Sudah waktunya cadangan otomatis berikutnya? Dihitung dari cadangan terakhir (cara apa pun). */
function cadangan_perlu_otomatis(PDO $pdo): bool {
    if (pengaturan($pdo, 'cadangan_otomatis') !== '1') { return false; }
    $terakhir = pengaturan($pdo, 'cadangan_terakhir');
    if ($terakhir === '') { return true; }
    $t = strtotime($terakhir); if ($t === false) { return true; }
    $j = cadangan_jadwal($pdo);
    if ($j['malam_sekali'] && !cadangan_di_jam_aktif($j)) {
        return $t < cadangan_awal_malam($j);           // di luar jam aktif: sekali sejak jam aktif berakhir
    }
    return $t + $j['interval'] * 3600 - 120 <= time();  // toleransi 2 menit untuk cron yang jalan pas
}

/** Kalimat jadwal untuk ditampilkan. */
function cadangan_teks_jadwal(array $j): string {
    $t = 'tiap ' . $j['interval'] . ' jam';
    if ($j['malam_sekali']) { $t .= ' pada ' . $j['jam_mulai'] . '–' . $j['jam_selesai'] . ', di luar itu satu kali'; }
    return $t;
}

/* =========================================================================
   GOOGLE DRIVE — lewat akun Google pengguna (OAuth), izin drive.file
   -------------------------------------------------------------------------
   Dulu memakai akun layanan (service account). Sejak 2025 akun layanan
   tidak punya kuota penyimpanan, jadi unggahan ke folder "Drive Saya" milik
   siapa pun ditolak Google (403 "Service Accounts do not have storage
   quota"). Sekarang Super Admin menghubungkan akun Google-nya sendiri
   (sekali, lewat tombol di halaman Cadangan Data); berkas diunggah atas nama
   akun itu, memakai kuotanya, dengan izin terbatas drive.file — aplikasi
   hanya melihat berkas/folder yang dibuatnya sendiri. Karena itu folder
   tujuan dibuat lewat tombol "Buat folder di Drive" (boleh dipindahkan /
   diganti nama setelahnya). Tidak ada pustaka Google: cURL saja.

   Setelan (tabel pengaturan): google_client_id, google_client_secret,
   google_refresh_token, google_akun, google_scope, google_access_token +
   google_access_exp (cache token), cadangan_drive_folder.
   ========================================================================= */

const GDRIVE_SCOPE = 'https://www.googleapis.com/auth/drive.file';
const GDRIVE_TANDA = 'amanah-cadangan';   // appProperties penanda berkas buatan aplikasi

function gdrive_terkonfigurasi(PDO $pdo): bool {
    return pengaturan($pdo, 'google_client_id') !== '' && pengaturan($pdo, 'google_client_secret') !== '';
}
function gdrive_terhubung(PDO $pdo): bool {
    return gdrive_terkonfigurasi($pdo) && pengaturan($pdo, 'google_refresh_token') !== '';
}
function gdrive_akun(PDO $pdo): string { return pengaturan($pdo, 'google_akun'); }
function gdrive_redirect_uri(): string { return url_penuh('cadangan_google.php'); }

/** URL persetujuan Google. $state disimpan di sesi oleh pemanggil. */
function gdrive_url_otorisasi(PDO $pdo, string $state): string {
    return 'https://accounts.google.com/o/oauth2/v2/auth?' . http_build_query([
        'client_id' => pengaturan($pdo, 'google_client_id'), 'redirect_uri' => gdrive_redirect_uri(),
        'response_type' => 'code', 'scope' => GDRIVE_SCOPE . ' https://www.googleapis.com/auth/userinfo.email',
        'access_type' => 'offline', 'prompt' => 'consent', 'include_granted_scopes' => 'true', 'state' => $state,
    ]);
}

/** Permintaan HTTP sederhana. @return array{0:int,1:string,2:array} kode, badan, header-respons */
function gdrive_http(string $metode, string $url, array $header = [], $badan = null, int $timeout = 60): array {
    if (!function_exists('curl_init')) { throw new RuntimeException('Hosting tidak menyediakan ekstensi curl yang dibutuhkan untuk Google Drive.'); }
    $ch = curl_init($url);
    $hdr = [];
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => $timeout, CURLOPT_CUSTOMREQUEST => $metode,
        CURLOPT_HTTPHEADER => $header, CURLOPT_HEADERFUNCTION => function ($c, $baris) use (&$hdr) {
            $p = explode(':', $baris, 2); if (count($p) === 2) { $hdr[strtolower(trim($p[0]))] = trim($p[1]); } return strlen($baris); }]);
    if ($badan !== null) { curl_setopt($ch, CURLOPT_POSTFIELDS, $badan); }
    $res = curl_exec($ch); $kode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE); $err = curl_error($ch); curl_close($ch);
    if ($res === false) { throw new RuntimeException('Tidak bisa menghubungi Google: ' . $err); }
    return [$kode, (string) $res, $hdr];
}

/** Tukar kode OAuth → refresh token; simpan beserta email akun. Mengembalikan email. */
function gdrive_tukar_kode(PDO $pdo, string $kode): string {
    [$st, $res] = gdrive_http('POST', 'https://oauth2.googleapis.com/token', ['Content-Type: application/x-www-form-urlencoded'],
        http_build_query(['code' => $kode, 'client_id' => pengaturan($pdo, 'google_client_id'), 'client_secret' => pengaturan($pdo, 'google_client_secret'),
                          'redirect_uri' => gdrive_redirect_uri(), 'grant_type' => 'authorization_code']), 30);
    $j = json_decode($res, true) ?: [];
    if ($st >= 300 || empty($j['refresh_token'])) {
        throw new RuntimeException('Google menolak kode otorisasi: ' . ($j['error_description'] ?? $j['error'] ?? "HTTP $st")
            . '. Pastikan Redirect URI di Google Cloud sama persis (' . gdrive_redirect_uri() . ') dan coba hubungkan lagi.');
    }
    simpan_pengaturan($pdo, 'google_refresh_token', (string) $j['refresh_token']);
    simpan_pengaturan($pdo, 'google_scope', (string) ($j['scope'] ?? ''));
    simpan_pengaturan($pdo, 'google_access_token', (string) ($j['access_token'] ?? ''));
    simpan_pengaturan($pdo, 'google_access_exp', (string) (time() + max(60, (int) ($j['expires_in'] ?? 3600) - 60)));
    $email = '-';
    try {
        [$st2, $res2] = gdrive_http('GET', 'https://www.googleapis.com/oauth2/v3/userinfo', ['Authorization: Bearer ' . $j['access_token']], null, 15);
        $email = (string) ((json_decode($res2, true) ?: [])['email'] ?? '-');
    } catch (Throwable $e) {}
    simpan_pengaturan($pdo, 'google_akun', $email);
    return $email;
}

/** Access token yang masih berlaku (disegarkan dari refresh token bila perlu). */
function gdrive_access_token(PDO $pdo): string {
    if (!gdrive_terhubung($pdo)) { throw new RuntimeException('Akun Google belum dihubungkan — tekan "Hubungkan akun Google" di halaman Cadangan Data.'); }
    $ada = pengaturan($pdo, 'google_access_token'); $exp = (int) pengaturan($pdo, 'google_access_exp', '0');
    if ($ada !== '' && $exp > time()) { return $ada; }
    [$st, $res] = gdrive_http('POST', 'https://oauth2.googleapis.com/token', ['Content-Type: application/x-www-form-urlencoded'],
        http_build_query(['client_id' => pengaturan($pdo, 'google_client_id'), 'client_secret' => pengaturan($pdo, 'google_client_secret'),
                          'refresh_token' => pengaturan($pdo, 'google_refresh_token'), 'grant_type' => 'refresh_token']), 30);
    $j = json_decode($res, true) ?: [];
    if ($st >= 300 || empty($j['access_token'])) {
        throw new RuntimeException('Google menolak penyegaran token (' . ($j['error_description'] ?? $j['error'] ?? "HTTP $st") . '). Hubungkan ulang akun Google.');
    }
    simpan_pengaturan($pdo, 'google_access_token', (string) $j['access_token']);
    simpan_pengaturan($pdo, 'google_access_exp', (string) (time() + max(60, (int) ($j['expires_in'] ?? 3600) - 60)));
    return (string) $j['access_token'];
}

/** Putuskan akun Google (token dicabut di Google bila bisa; setelan kita dikosongkan). */
function gdrive_putus(PDO $pdo): void {
    $token = pengaturan($pdo, 'google_refresh_token');
    if ($token !== '') { try { gdrive_http('POST', 'https://oauth2.googleapis.com/revoke', ['Content-Type: application/x-www-form-urlencoded'], http_build_query(['token' => $token]), 10); } catch (Throwable $e) {} }
    foreach (['google_refresh_token', 'google_akun', 'google_scope', 'google_access_token', 'google_access_exp'] as $k) { simpan_pengaturan($pdo, $k, ''); }
}

function gdrive_galat(int $kode, string $res, string $apa): RuntimeException {
    $j = json_decode($res, true) ?: [];
    $pesan = (string) ($j['error']['message'] ?? $j['error_description'] ?? $res);
    return new RuntimeException("Google Drive menolak (HTTP $kode) $apa: " . mb_substr($pesan, 0, 200));
}

/** Nama folder Drive (memastikan ID benar dan bisa diakses aplikasi). */
function gdrive_folder(PDO $pdo, string $folderId): array {
    [$st, $res] = gdrive_http('GET', 'https://www.googleapis.com/drive/v3/files/' . rawurlencode($folderId) . '?fields=id,name,mimeType,webViewLink&supportsAllDrives=true',
        ['Authorization: Bearer ' . gdrive_access_token($pdo)], null, 20);
    if ($st === 404) {
        throw new RuntimeException('Folder itu tidak bisa diakses aplikasi. Izin Drive yang diberikan hanya untuk folder/berkas buatan aplikasi ini — tekan "Buat folder di Drive" supaya foldernya dibuat oleh aplikasi (boleh dipindahkan ke mana pun setelahnya).');
    }
    if ($st >= 300) { throw gdrive_galat($st, $res, 'saat membuka folder'); }
    $j = json_decode($res, true) ?: [];
    if (($j['mimeType'] ?? '') !== 'application/vnd.google-apps.folder') { throw new RuntimeException('ID itu bukan folder Google Drive.'); }
    return $j;
}

/** Buat folder di Drive Saya akun yang terhubung; mengembalikan id, name, webViewLink. */
function gdrive_buat_folder(PDO $pdo, string $nama): array {
    [$st, $res] = gdrive_http('POST', 'https://www.googleapis.com/drive/v3/files?fields=id,name,webViewLink&supportsAllDrives=true',
        ['Authorization: Bearer ' . gdrive_access_token($pdo), 'Content-Type: application/json; charset=UTF-8'],
        json_encode(['name' => $nama, 'mimeType' => 'application/vnd.google-apps.folder', 'appProperties' => ['aplikasi' => GDRIVE_TANDA]]), 30);
    $j = json_decode($res, true) ?: [];
    if ($st >= 300 || empty($j['id'])) { throw gdrive_galat($st, $res, 'saat membuat folder'); }
    return $j;
}

/** Unggah satu berkas ke folder tujuan (multipart). Mengembalikan id berkas di Drive. */
function cadangan_drive_unggah(PDO $pdo, string $path, string $nama): string {
    $folder = trim(pengaturan($pdo, 'cadangan_drive_folder'));
    if ($folder === '') { throw new RuntimeException('Folder Google Drive tujuan belum dibuat — tekan "Buat folder di Drive".'); }
    $token = gdrive_access_token($pdo);
    $batas = 'amanah' . bin2hex(random_bytes(8));
    $meta  = json_encode(['name' => $nama, 'parents' => [$folder], 'mimeType' => 'application/gzip', 'appProperties' => ['aplikasi' => GDRIVE_TANDA]]);
    $badan = "--$batas\r\nContent-Type: application/json; charset=UTF-8\r\n\r\n$meta\r\n"
           . "--$batas\r\nContent-Type: application/gzip\r\n\r\n" . file_get_contents($path) . "\r\n--$batas--";
    [$st, $res] = gdrive_http('POST', 'https://www.googleapis.com/upload/drive/v3/files?uploadType=multipart&supportsAllDrives=true&fields=id,name',
        ["Authorization: Bearer $token", "Content-Type: multipart/related; boundary=$batas", 'Content-Length: ' . strlen($badan)], $badan, 300);
    $j = json_decode($res, true) ?: [];
    if ($st >= 300 || empty($j['id'])) { throw gdrive_galat($st, $res, 'saat mengunggah'); }
    return (string) $j['id'];
}

/** Daftar berkas cadangan buatan aplikasi di folder tujuan, terbaru dulu. */
function gdrive_daftar(PDO $pdo, string $folder): array {
    $hasil = []; $halaman = '';
    do {
        $q = ['q' => "'" . str_replace("'", "\\'", $folder) . "' in parents and trashed = false and appProperties has { key='aplikasi' and value='" . GDRIVE_TANDA . "' }",
              'orderBy' => 'createdTime desc', 'pageSize' => 200, 'fields' => 'nextPageToken,files(id,name,size,createdTime)',
              'supportsAllDrives' => 'true', 'includeItemsFromAllDrives' => 'true'];
        if ($halaman !== '') { $q['pageToken'] = $halaman; }
        [$st, $res] = gdrive_http('GET', 'https://www.googleapis.com/drive/v3/files?' . http_build_query($q), ['Authorization: Bearer ' . gdrive_access_token($pdo)], null, 30);
        if ($st >= 300) { throw gdrive_galat($st, $res, 'saat membaca daftar berkas'); }
        $j = json_decode($res, true) ?: [];
        $hasil = array_merge($hasil, $j['files'] ?? []);
        $halaman = (string) ($j['nextPageToken'] ?? '');
    } while ($halaman !== '');
    return $hasil;
}

function gdrive_hapus(PDO $pdo, string $fileId): void {
    [$st, $res] = gdrive_http('DELETE', 'https://www.googleapis.com/drive/v3/files/' . rawurlencode($fileId) . '?supportsAllDrives=true',
        ['Authorization: Bearer ' . gdrive_access_token($pdo)], null, 30);
    if ($st >= 300 && $st !== 404) { throw gdrive_galat($st, $res, 'saat menghapus berkas'); }
}

/** Hapus cadangan di Drive yang lebih lama dari N terakhir (setelan cadangan_simpan). Mengembalikan jumlah yang dihapus. */
function cadangan_drive_rapikan(PDO $pdo): int {
    $folder = trim(pengaturan($pdo, 'cadangan_drive_folder'));
    if ($folder === '') { return 0; }
    $simpan = max(1, (int) pengaturan($pdo, 'cadangan_simpan', '48'));
    $n = 0;
    foreach (array_slice(gdrive_daftar($pdo, $folder), $simpan) as $b) { gdrive_hapus($pdo, (string) $b['id']); $n++; }
    return $n;
}

/** Drive siap dipakai: akun terhubung dengan izin Drive dan folder tujuan terisi. */
function cadangan_drive_siap(PDO $pdo): bool {
    return gdrive_terhubung($pdo) && strpos(pengaturan($pdo, 'google_scope'), GDRIVE_SCOPE) !== false && trim(pengaturan($pdo, 'cadangan_drive_folder')) !== '';
}
/** Alasan Drive belum siap (untuk ditampilkan), '' bila siap. */
function cadangan_drive_alasan(PDO $pdo): string {
    if (!gdrive_terkonfigurasi($pdo)) { return 'Client ID & Client Secret Google belum diisi.'; }
    if (!gdrive_terhubung($pdo)) { return 'Akun Google belum dihubungkan.'; }
    if (strpos(pengaturan($pdo, 'google_scope'), GDRIVE_SCOPE) === false) { return 'Akun terhubung tanpa izin Google Drive — hubungkan ulang dan setujui izin Drive.'; }
    if (trim(pengaturan($pdo, 'cadangan_drive_folder')) === '') { return 'Folder tujuan belum dibuat — tekan "Buat folder di Drive".'; }
    return '';
}

/** Uji sambungan Drive tanpa mengunggah apa pun: nama folder tujuan. */
function cadangan_drive_uji(PDO $pdo): string {
    $alasan = cadangan_drive_alasan($pdo);
    if ($alasan !== '') { throw new RuntimeException($alasan); }
    return (string) gdrive_folder($pdo, trim(pengaturan($pdo, 'cadangan_drive_folder')))['name'];
}
