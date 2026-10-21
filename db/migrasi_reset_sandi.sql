-- =====================================================================
-- Tautan reset kata sandi sekali pakai — 13 September 2026
-- =====================================================================
-- Sistem ini tidak punya pengirim email, jadi "lupa kata sandi" ditangani
-- Super Admin: ia membuat tautan reset dari Kelola Pengguna dan mengirimnya
-- lewat WhatsApp. Tautan berlaku 60 menit dan hanya bisa dipakai sekali.
-- Tabel ini juga dibuat otomatis oleh aplikasi; berkas ini dokumentasinya.
CREATE TABLE IF NOT EXISTS reset_sandi (
    token_hash  CHAR(64) NOT NULL,
    user_id     BIGINT UNSIGNED NOT NULL,
    dibuat_oleh BIGINT UNSIGNED NULL,
    sampai      DATETIME NOT NULL,
    dipakai_at  DATETIME NULL,
    created_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (token_hash),
    KEY ix_reset_user (user_id, sampai)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
