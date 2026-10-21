-- =====================================================================
-- Titik transit surat jalan — 6 Oktober 2026
-- =====================================================================
-- Satu surat jalan bisa singgah di gudang lain sebelum sampai tujuan.
-- Tiap titik singgah dicatat di sini: kapan tiba, kapan berangkat lagi,
-- dan siapa yang mencatatnya — sehingga posisi kiriman ("sampai mana")
-- bisa dilacak di halaman Lacak Kiriman (lacak.php).
-- Stok TIDAK berpindah ke gudang transit; ia tetap milik pengirim sampai
-- diterima di tujuan akhir.
-- Aman dijalankan berulang.
CREATE TABLE IF NOT EXISTS perpindahan_titik (
  id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  perpindahan_id BIGINT UNSIGNED NOT NULL,
  urutan         TINYINT UNSIGNED NOT NULL DEFAULT 1,
  lokasi_tipe    ENUM('gudang','toko') NOT NULL DEFAULT 'gudang',
  lokasi_id      BIGINT UNSIGNED NOT NULL,
  tiba_at        DATETIME NULL,
  tiba_oleh      BIGINT UNSIGNED NULL,
  berangkat_at   DATETIME NULL,
  berangkat_oleh BIGINT UNSIGNED NULL,
  catatan        VARCHAR(255) NULL,
  kendaraan      VARCHAR(60) NULL,
  no_polisi      VARCHAR(20) NULL,
  pengemudi      VARCHAR(100) NULL,
  created_at     TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_titik_pp (perpindahan_id, urutan),
  KEY idx_titik_lokasi (lokasi_tipe, lokasi_id),
  CONSTRAINT fk_titik_pp FOREIGN KEY (perpindahan_id) REFERENCES perpindahan (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
