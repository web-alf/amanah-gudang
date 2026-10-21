-- Pembatas percobaan masuk (web & API). Lihat _pembatas.php.
-- Tabel ini juga dibuat otomatis oleh aplikasi bila belum ada; berkas ini
-- hanya mendokumentasikan skemanya. Aman dijalankan berulang.
CREATE TABLE IF NOT EXISTS login_gagal (
    id        BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    email     VARCHAR(150) NOT NULL,
    ip        VARCHAR(45)  NOT NULL,
    sumber    ENUM('web','api') NOT NULL DEFAULT 'web',
    waktu     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY ix_gagal_email (email, waktu),
    KEY ix_gagal_ip (ip, waktu)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
