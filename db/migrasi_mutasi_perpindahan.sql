-- =====================================================================
-- Jejak mutasi stok per dokumen perpindahan — 6 Oktober 2026
-- =====================================================================
-- Setiap kali terapkan_perpindahan() memotong atau menambah batch, satu
-- baris dicatat di sini: batch asal yang dipotong, batch tujuan yang
-- ditambah, dan jumlahnya. Dengan jejak ini sebuah dokumen yang SUDAH
-- selesai bisa diubah oleh Super Admin (perpindahan_ubah.php): stoknya
-- dibalikkan persis seperti saat diterapkan, lalu diterapkan ulang dengan
-- isi yang baru. Dokumen yang selesai sebelum tabel ini ada tidak punya
-- jejak, jadi jumlah/lokasinya tidak bisa diubah — hanya catatannya.
-- Aman dijalankan berulang.
CREATE TABLE IF NOT EXISTS perpindahan_mutasi (
  id               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  perpindahan_id   BIGINT UNSIGNED NOT NULL,
  detail_id        BIGINT UNSIGNED NULL,
  barang_id        BIGINT UNSIGNED NOT NULL,
  batch_asal_id    BIGINT UNSIGNED NULL,
  batch_tujuan_id  BIGINT UNSIGNED NULL,
  qty              INT NOT NULL,
  created_at       TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_mutasi_pp (perpindahan_id),
  CONSTRAINT fk_mutasi_pp FOREIGN KEY (perpindahan_id) REFERENCES perpindahan (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
