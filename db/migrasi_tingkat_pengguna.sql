-- =====================================================================
-- AMANAH — Tingkat pengguna: staff dan manager
-- =====================================================================
-- Peran (supplier / admin gudang / toko) menjawab "di bagian mana orang
-- ini bekerja". Tingkat menjawab "sampai mana wewenangnya di bagian itu".
-- Keduanya dipisah, bukan digabung menjadi peran-peran baru seperti
-- "gudang_staff" dan "gudang_manager".
--
-- Alasannya: nama peran dibandingkan sebagai string di banyak tempat di
-- seluruh aplikasi. Menambah enam peran baru berarti menyentuh setiap
-- perbandingan itu, dan setiap satu yang terlewat menjadi lubang izin
-- yang tidak kelihatan sampai ada yang menemukannya. Menambah satu kolom
-- membuat semua pemeriksaan peran yang sudah ada tetap benar, lalu
-- pembatasan tingkat ditumpuk di atasnya di satu tempat.
--
-- Tingkat TIDAK berlaku untuk super_admin dan pimpinan — keduanya bukan
-- jabatan lapangan. Nilainya tetap terisi supaya kolomnya tidak perlu
-- NULL, tapi kode mengabaikannya untuk dua peran itu.
--
-- BERJALAN DI MySQL MAUPUN MariaDB, dan aman dijalankan berulang kali.
-- =====================================================================

SET FOREIGN_KEY_CHECKS = 0;

-- ---------------------------------------------------------------------
-- 1. Kolom tingkat
-- ---------------------------------------------------------------------
-- Keberadaan kolom diperiksa DULU dan disimpan, karena hasil pemeriksaan
-- itu masih dibutuhkan setelah kolomnya ditambahkan. Pengisian awal di
-- langkah 2 hanya boleh berjalan pada pemasangan pertama: kalau ia ikut
-- berjalan setiap kali berkas ini dieksekusi, semua staff yang dibuat
-- sesudahnya akan dinaikkan menjadi manager secara diam-diam.
SET @tingkat_baru := (SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'tingkat') = 0;

-- Bawaannya 'staff', yang paling sedikit wewenangnya. Pengguna baru yang
-- dibuat lewat jalur mana pun — termasuk skrip impor yang lupa mengisi
-- kolom ini — jatuh ke wewenang paling kecil, bukan paling besar.
SET @s := IF(@tingkat_baru,
  'ALTER TABLE `users` ADD COLUMN `tingkat` ENUM(''staff'',''manager'') NOT NULL DEFAULT ''staff'' COMMENT ''wewenang di dalam perannya; diabaikan untuk super_admin dan pimpinan'' AFTER `role_id`',
  'DO 0');
PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p;

SET @s := IF((SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND INDEX_NAME = 'ix_users_tingkat') = 0,
  'ALTER TABLE `users` ADD KEY `ix_users_tingkat` (`role_id`,`tingkat`)',
  'DO 0');
PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p;

-- ---------------------------------------------------------------------
-- 2. Pengisian awal
-- ---------------------------------------------------------------------
-- Pengguna yang sudah ada hari ini memegang seluruh wewenang perannya.
-- Kalau mereka diturunkan menjadi staff, migrasi ini MENCABUT kemampuan
-- yang tadinya mereka punya — orang yang tiap hari menyetujui dokumen
-- tiba-tiba tidak bisa lagi, tanpa ada yang mengubah apa pun secara
-- sadar. Jadi semuanya dinaikkan ke manager, dan penurunan ke staff
-- dilakukan manual lewat halaman Kelola Pengguna.
SET @s := IF(@tingkat_baru,
  'UPDATE `users` SET `tingkat` = ''manager''',
  'DO 0');
PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p;

-- ---------------------------------------------------------------------
-- 3. Wilayah kerja: gudang bisa lebih dari satu
-- ---------------------------------------------------------------------
-- users.gudang_id tetap ada dan tetap berarti "gudang utama". Tabel ini
-- menambah kemungkinan seorang manager mengawasi beberapa gudang
-- sekaligus, mengikuti pola user_toko yang sudah dipakai sisi toko.
--
-- Kolom lama tidak dibuang: seluruh kode yang membacanya masih benar
-- untuk staff, yang memang hanya bertugas di satu gudang.
CREATE TABLE IF NOT EXISTS `user_gudang` (
  `user_id`    BIGINT(20) UNSIGNED NOT NULL,
  `gudang_id`  BIGINT(20) UNSIGNED NOT NULL,
  `created_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`user_id`, `gudang_id`),
  KEY `ix_user_gudang_gudang` (`gudang_id`),
  CONSTRAINT `fk_user_gudang_user`   FOREIGN KEY (`user_id`)   REFERENCES `users`  (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_user_gudang_gudang` FOREIGN KEY (`gudang_id`) REFERENCES `gudang` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
  COMMENT='wilayah kerja tambahan; gudang utama tetap di users.gudang_id';

-- Gudang utama yang sudah tercatat disalin ke sini supaya penugasan
-- pengguna lama terbaca lengkap dari satu tempat. INSERT IGNORE membuat
-- langkah ini aman diulang.
INSERT IGNORE INTO `user_gudang` (`user_id`, `gudang_id`)
SELECT `id`, `gudang_id` FROM `users`
WHERE `gudang_id` IS NOT NULL AND `deleted_at` IS NULL;

SET FOREIGN_KEY_CHECKS = 1;
