-- Catatan galat sistem — 1 Oktober 2026. Lihat catat_galat() di _lib.php dan
-- halaman Pengaturan > Galat Sistem (galat.php). Tabel ini juga dibuat
-- otomatis saat galat pertama tercatat; berkas ini dokumentasinya.
CREATE TABLE IF NOT EXISTS galat_sistem (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    kode CHAR(8) NOT NULL,
    konteks VARCHAR(100) NULL,
    pesan TEXT NULL,
    berkas VARCHAR(255) NULL,
    halaman VARCHAR(255) NULL,
    user_id BIGINT UNSIGNED NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id), KEY ix_galat_kode (kode), KEY ix_galat_waktu (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
