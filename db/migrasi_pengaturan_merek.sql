-- =====================================================================
-- AMANAH — Pengaturan aplikasi (logo, favicon, gambar share)
-- =====================================================================
-- Disimpan di basis data, bukan di berkas konfigurasi, dengan dua alasan:
--   • config.php ikut tertimpa setiap kali kode di-deploy ulang; pengaturan
--     yang diisi operator tidak boleh hilang karena itu.
--   • pengaturan berubah dari dalam aplikasi, dan menulis ke config.php dari
--     PHP berarti berkas kode harus bisa ditulisi web server — celah yang
--     tidak sebanding dengan manfaatnya.
--
-- Bentuknya kunci/nilai, bukan satu kolom per pengaturan: menambah
-- pengaturan baru nanti tidak perlu ALTER TABLE di hosting.
-- =====================================================================

CREATE TABLE IF NOT EXISTS `pengaturan` (
  `kunci` varchar(60) NOT NULL,
  `nilai` varchar(500) NOT NULL DEFAULT '',
  `diubah_oleh` bigint(20) unsigned DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`kunci`),
  KEY `ix_pengaturan_oleh` (`diubah_oleh`),
  -- SET NULL: akun yang dihapus tidak boleh menghapus pengaturannya.
  CONSTRAINT `fk_pengaturan_user` FOREIGN KEY (`diubah_oleh`)
      REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Barisnya disiapkan dengan nilai kosong supaya halaman pengaturan tidak
-- perlu membedakan "belum pernah diisi" dari "sengaja dikosongkan".
INSERT INTO `pengaturan` (`kunci`, `nilai`) VALUES
  ('logo_utama',    ''),
  ('logo_favicon',  ''),
  ('gambar_share',  ''),
  ('gambar_hero',   ''),
  ('share_judul',   ''),
  ('share_teks',    '')
ON DUPLICATE KEY UPDATE `kunci` = `kunci`;

-- SELECT ringkasan sengaja DIHAPUS — runner migrasi.php menjalankan tiap
-- pernyataan lewat PDO::exec(), yang gagal (MySQL 2014) kalau diberi
-- SELECT berdiri sendiri. Untuk memeriksa manual:
-- SELECT `kunci`, IF(`nilai` = '', '(belum diisi)', `nilai`) AS `nilai` FROM `pengaturan` ORDER BY `kunci`;
