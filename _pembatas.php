<?php
/**
 * AMANAH — Pembatas percobaan masuk, berbasis tabel.
 *
 * Dipakai login.php (web) DAN api/login.php (Android). Keduanya dulu
 * berbeda: web menghitung kegagalan di $_SESSION — yang berarti cukup
 * membuang cookie untuk mulai dari nol — dan API tidak menghitung sama
 * sekali. Menebak sandi lewat API karena itu tidak pernah terhambat.
 *
 * Hitungannya kini disimpan di tabel `login_gagal`, per PASANGAN email + IP:
 *
 *   - per email     : sandi satu akun tidak bisa ditebak dari banyak IP
 *                     tanpa terhambat
 *   - per IP        : satu IP tidak bisa menebak banyak akun (ambangnya
 *                     lebih longgar, karena satu kantor bisa berbagi IP)
 *
 * Ambang: 5 kegagalan per email dalam 10 menit → kunci 10 menit.
 *         30 kegagalan per IP dalam 10 menit → kunci 10 menit.
 *
 * Tabelnya DIBUAT SENDIRI bila belum ada, supaya hosting yang belum
 * sempat menjalankan migrasinya tetap terlindungi — bukan jatuh ke
 * "tanpa pembatas" secara diam-diam. Skema yang sama ada di
 * db/migrasi_login_gagal.sql untuk dokumentasi.
 */

const PEMBATAS_JENDELA   = 600;   // detik — 10 menit
const PEMBATAS_MAKS_EMAIL = 5;
const PEMBATAS_MAKS_IP    = 30;

function _pembatas_ip(): string {
    return substr((string) ($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45);
}

function _pembatas_siapkan(PDO $pdo): void {
    static $siap = false;
    if ($siap) { return; }
    $siap = true;
    try {
        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS login_gagal (
                id        BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                email     VARCHAR(150) NOT NULL,
                ip        VARCHAR(45)  NOT NULL,
                sumber    ENUM('web','api') NOT NULL DEFAULT 'web',
                waktu     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                KEY ix_gagal_email (email, waktu),
                KEY ix_gagal_ip (ip, waktu)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
        // Bersihkan jejak lama sesekali — cukup satu dari ±50 permintaan.
        if (random_int(1, 50) === 1) {
            $pdo->exec("DELETE FROM login_gagal WHERE waktu < DATE_SUB(NOW(), INTERVAL 1 DAY)");
        }
    } catch (Throwable $e) {
        error_log('[AMANAH] pembatas siapkan: ' . $e->getMessage());
    }
}

/**
 * Sisa detik penguncian untuk email+IP ini, atau 0 bila boleh mencoba.
 */
function pembatas_login_terkunci(PDO $pdo, string $email): int {
    _pembatas_siapkan($pdo);
    $email = mb_strtolower(trim($email));
    try {
        $st = $pdo->prepare(
            "SELECT COUNT(*) AS n, MAX(waktu) AS terakhir
               FROM login_gagal
              WHERE email = ? AND waktu > DATE_SUB(NOW(), INTERVAL " . PEMBATAS_JENDELA . " SECOND)"
        );
        $st->execute([$email]);
        $e = $st->fetch();

        $st = $pdo->prepare(
            "SELECT COUNT(*) AS n, MAX(waktu) AS terakhir
               FROM login_gagal
              WHERE ip = ? AND waktu > DATE_SUB(NOW(), INTERVAL " . PEMBATAS_JENDELA . " SECOND)"
        );
        $st->execute([_pembatas_ip()]);
        $i = $st->fetch();

        $sisa = 0;
        foreach ([[$e, PEMBATAS_MAKS_EMAIL], [$i, PEMBATAS_MAKS_IP]] as [$baris, $maks]) {
            if ((int) ($baris['n'] ?? 0) >= $maks && !empty($baris['terakhir'])) {
                $sisa = max($sisa, strtotime($baris['terakhir']) + PEMBATAS_JENDELA - time());
            }
        }
        return max(0, $sisa);
    } catch (Throwable $ex) {
        /* Gagal membaca berarti tidak tahu — dan "tidak tahu" tidak boleh
           mengunci semua orang dari aplikasinya sendiri. Dicatat, lalu
           dilewatkan. */
        error_log('[AMANAH] pembatas baca: ' . $ex->getMessage());
        return 0;
    }
}

/** Catat satu kegagalan. Dipanggil hanya bila sandi/email salah. */
function pembatas_login_gagal(PDO $pdo, string $email, string $sumber = 'web'): void {
    _pembatas_siapkan($pdo);
    try {
        $pdo->prepare("INSERT INTO login_gagal (email, ip, sumber) VALUES (?,?,?)")
            ->execute([mb_substr(mb_strtolower(trim($email)), 0, 150), _pembatas_ip(),
                       $sumber === 'api' ? 'api' : 'web']);
    } catch (Throwable $ex) {
        error_log('[AMANAH] pembatas catat: ' . $ex->getMessage());
    }
}

/** Hapus jejak kegagalan email ini — dipanggil setelah masuk berhasil. */
function pembatas_login_bersih(PDO $pdo, string $email): void {
    try {
        $pdo->prepare("DELETE FROM login_gagal WHERE email = ?")
            ->execute([mb_strtolower(trim($email))]);
    } catch (Throwable $ex) { /* tidak penting */ }
}

/** Kalimat untuk pengguna: "Coba lagi dalam N menit." */
function pembatas_pesan(int $sisaDetik): string {
    $menit = max(1, (int) ceil($sisaDetik / 60));
    return 'Terlalu banyak percobaan gagal. Coba lagi dalam ' . $menit . ' menit.';
}
