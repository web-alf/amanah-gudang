-- =====================================================================
-- AMANAH — Menyelaraskan skema agar bisa diimpor MySQL
-- =====================================================================
-- BERJALAN DI MySQL MAUPUN MariaDB. Aman dijalankan berulang kali.
--
-- LATAR BELAKANG
-- Hosting membalas impor dengan:
--
--   #1901 — Function or expression 'lokasi_rak_id' cannot be used in the
--           GENERATED ALWAYS AS clause of `rak_key`
--
-- Itu galat MySQL, dan ia menghentikan impor pada tabel keenam sehingga
-- tidak ada satu pun tabel sesudahnya yang terbentuk. Skema lokal memakai
-- kolom generated karena dikembangkan di MariaDB, yang menerimanya.
--
-- Berkas ini membuang kolom generated dari basis data yang SUDAH ADA,
-- supaya skema lokal dan skema hosting sama persis. Skema yang berbeda
-- antara tempat pengembangan dan tempat pemakaian adalah sumber kegagalan
-- yang paling sulit dilacak: kodenya benar di satu tempat dan salah di
-- tempat lain, tanpa ada perubahan kode sama sekali.
--
-- APA YANG HILANG — disebut terang-terangan
-- Kolom rak_key/toko_key/exp_key hanya ada untuk membuat kunci unik tahan
-- NULL. Setelah dibuang, yang menjaga adalah `uq_batch_lokasi` yang memakai
-- kolom aslinya. Pada kolom yang NULL, MySQL maupun MariaDB TIDAK
-- menganggap dua baris sebagai duplikat — jadi pencegahan batch ganda
-- berpindah ke lapisan aplikasi, yang memang sudah memeriksa dengan
-- SELECT sebelum menulis.
--
-- Tidak ada kode PHP yang membaca ketiga kolom itu (sudah diperiksa), jadi
-- membuangnya tidak mengubah perilaku aplikasi.
-- =====================================================================

-- --------------------------------------------------------- batch_barang
-- Kunci yang MEMAKAI kolom generated dibuang LEBIH DULU. Urutan ini bukan
-- selera: DROP COLUMN pada kolom yang masih dipakai indeks gagal dengan
-- "Key column ... doesn't exist in table", dan galat itu menghentikan
-- seluruh berkas.
SET @s := IF((SELECT COUNT(*) FROM information_schema.STATISTICS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'batch_barang'
                AND INDEX_NAME = 'uq_batch_kunci') > 0,
  'ALTER TABLE `batch_barang` DROP INDEX `uq_batch_kunci`',
  'DO 0');
PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p;

SET @s := IF((SELECT COUNT(*) FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'batch_barang'
                AND COLUMN_NAME = 'rak_key') > 0,
  'ALTER TABLE `batch_barang` DROP COLUMN `rak_key`',
  'DO 0');
PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p;

SET @s := IF((SELECT COUNT(*) FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'batch_barang'
                AND COLUMN_NAME = 'toko_key') > 0,
  'ALTER TABLE `batch_barang` DROP COLUMN `toko_key`',
  'DO 0');
PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p;

SET @s := IF((SELECT COUNT(*) FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'batch_barang'
                AND COLUMN_NAME = 'exp_key') > 0,
  'ALTER TABLE `batch_barang` DROP COLUMN `exp_key`',
  'DO 0');
PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p;

-- Pengganti uq_batch_kunci. Dipastikan ada, sebab di sebagian basis data
-- ia mungkin belum pernah terbentuk.
SET @s := IF((SELECT COUNT(*) FROM information_schema.STATISTICS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'batch_barang'
                AND INDEX_NAME = 'uq_batch_lokasi') = 0,
  'ALTER TABLE `batch_barang` ADD UNIQUE KEY `uq_batch_lokasi` (`barang_id`,`nomor_batch`,`kondisi`,`lokasi_rak_id`,`toko_id`,`tanggal_expired`)',
  'DO 0');
PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p;

-- -------------------------------------------------------- stok_supplier
-- exp_key di sini berasal dari skema ASLI, bukan dari migrasi rak
-- supplier — karena itu ia tidak ikut dibuang di sana, dan sempat
-- terlewat. Kuncinya sudah memakai kolom asli, jadi kolom ini kini
-- tidak dipakai apa pun.
SET @s := IF((SELECT COUNT(*) FROM information_schema.STATISTICS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'stok_supplier'
                AND INDEX_NAME = 'uq_stoksup'
                AND COLUMN_NAME = 'exp_key') > 0,
  'ALTER TABLE `stok_supplier` DROP INDEX `uq_stoksup`',
  'DO 0');
PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p;

SET @s := IF((SELECT COUNT(*) FROM information_schema.STATISTICS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'stok_supplier'
                AND INDEX_NAME = 'uq_stoksup') = 0,
  'ALTER TABLE `stok_supplier` ADD UNIQUE KEY `uq_stoksup` (`supplier_id`,`barang_id`,`nomor_batch`,`kondisi`,`tanggal_expired`,`lokasi_rak_id`)',
  'DO 0');
PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p;

SET @s := IF((SELECT COUNT(*) FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'stok_supplier'
                AND COLUMN_NAME = 'exp_key') > 0,
  'ALTER TABLE `stok_supplier` DROP COLUMN `exp_key`',
  'DO 0');
PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p;

-- -------------------------------------------------- stock_opname_detail
-- Kolom hitungan, tanpa kunci yang bergantung padanya, dan tabelnya belum
-- punya halaman sama sekali. Nilainya cukup dihitung saat dibaca.
SET @s := IF((SELECT COUNT(*) FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'stock_opname_detail'
                AND COLUMN_NAME = 'selisih') > 0,
  'ALTER TABLE `stock_opname_detail` DROP COLUMN `selisih`',
  'DO 0');
PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p;

-- ------------------------------------------------------------ ringkasan
-- SELECT ringkasan sengaja DIHAPUS — runner migrasi.php menjalankan tiap
-- pernyataan lewat PDO::exec(), yang gagal (MySQL 2014) kalau diberi
-- SELECT berdiri sendiri. Untuk memeriksa manual, ketiga angka harus 0:
-- SELECT
--   (SELECT COUNT(*) FROM information_schema.COLUMNS
--     WHERE TABLE_SCHEMA = DATABASE() AND EXTRA LIKE '%GENERATED%')   AS sisa_kolom_generated,
--   (SELECT COUNT(*) FROM information_schema.STATISTICS
--     WHERE TABLE_SCHEMA = DATABASE() AND INDEX_NAME = 'uq_batch_kunci') AS sisa_kunci_lama,
--   (SELECT COUNT(*) FROM information_schema.STATISTICS
--     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'batch_barang'
--       AND INDEX_NAME = 'uq_batch_lokasi') AS kunci_pengganti_ada;
