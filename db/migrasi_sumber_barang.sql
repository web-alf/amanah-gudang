-- =====================================================================
-- AMANAH — Sumber barang: pembelian atau donasi
-- =====================================================================
-- Kolom `kondisi` selama ini memikul DUA arti sekaligus. Komentar kolomnya
-- sendiri mengakuinya: "baru = pembelian; bekas = donasi/second"
-- (instal_database.sql:161), dan alur.php menuliskannya sebagai aturan —
-- "barang donasi dan barang pembelian tidak boleh tercampur dalam satu
-- angka".
--
-- Dua arti itu tidak sejalan:
--   • baru / bekas          = keadaan fisik barang
--   • pembelian / donasi    = dari mana barang itu datang
--
-- Barang donasi yang masih tersegel adalah 'baru' secara fisik sekaligus
-- 'donasi' secara sumber. Selama keduanya berbagi satu kolom, barang
-- seperti itu tidak bisa dicatat tanpa berbohong pada salah satu sumbu —
-- dan begitu kondisi dipakai untuk menilai kelayakan pakai, seluruh angka
-- "berapa banyak stok kita berasal dari donasi" ikut rusak tanpa suara.
--
-- Karena itu sumbunya dipisah:
--   kondisi -> baru | bekas_layak | bekas_tidak_layak   (keadaan fisik)
--   sumber  -> pembelian | donasi                        (asal barang)
--
-- Jalankan SESUDAH migrasi_kondisi_barang.sql dan migrasi_donatur.sql.
-- BERJALAN DI MySQL MAUPUN MariaDB, dan aman dijalankan berulang kali.
-- =====================================================================

SET FOREIGN_KEY_CHECKS = 0;

-- ---------------------------------------------------------------------
-- 1. Kolom sumber
-- ---------------------------------------------------------------------
-- Keberadaan kolom disimpan lebih dulu, karena pengisian awal di langkah 2
-- hanya boleh berjalan sekali. Kalau ia ikut berjalan setiap eksekusi, tiap
-- barang donasi yang dicatat sesudahnya akan dikembalikan ke 'pembelian'.
SET @sumber_baru_bb := (SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'batch_barang' AND COLUMN_NAME = 'sumber') = 0;
SET @sumber_baru_ss := (SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'stok_supplier' AND COLUMN_NAME = 'sumber') = 0;
SET @sumber_baru_pd := (SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'perpindahan_detail' AND COLUMN_NAME = 'sumber') = 0;

SET @s := IF(@sumber_baru_bb,
  'ALTER TABLE `batch_barang` ADD COLUMN `sumber` ENUM(''pembelian'',''donasi'') NOT NULL DEFAULT ''pembelian'' COMMENT ''asal barang; terpisah dari keadaan fisik di kolom kondisi'' AFTER `kondisi`',
  'DO 0');
PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p;

SET @s := IF(@sumber_baru_ss,
  'ALTER TABLE `stok_supplier` ADD COLUMN `sumber` ENUM(''pembelian'',''donasi'') NOT NULL DEFAULT ''pembelian'' COMMENT ''asal barang; terpisah dari keadaan fisik di kolom kondisi'' AFTER `kondisi`',
  'DO 0');
PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p;

SET @s := IF(@sumber_baru_pd,
  'ALTER TABLE `perpindahan_detail` ADD COLUMN `sumber` ENUM(''pembelian'',''donasi'') NOT NULL DEFAULT ''pembelian'' COMMENT ''ikut terbawa dari batch asalnya'' AFTER `kondisi`',
  'DO 0');
PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p;

SET @s := IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'pengiriman_supplier_detail' AND COLUMN_NAME = 'sumber') = 0,
  'ALTER TABLE `pengiriman_supplier_detail` ADD COLUMN `sumber` ENUM(''pembelian'',''donasi'') NOT NULL DEFAULT ''pembelian'' AFTER `kondisi`',
  'DO 0');
PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p;

-- ---------------------------------------------------------------------
-- 2. Pengisian awal — memindahkan arti lama tanpa kehilangan apa pun
-- ---------------------------------------------------------------------
-- Baris yang kini berkondisi 'bekas_layak' adalah baris yang SEBELUM
-- migrasi_kondisi_barang.sql bernilai 'bekas', dan di arti lama 'bekas'
-- berarti donasi. Jadi merekalah — dan hanya mereka — yang menjadi
-- sumber = 'donasi'. Sisanya 'pembelian', mengikuti arti lama 'baru'.
--
-- Langkah ini HARUS dijalankan sebelum ada data baru yang memakai arti
-- baru, karena sesudah itu 'bekas_layak' tidak lagi menandakan donasi.
SET @s := IF(@sumber_baru_bb,
  'UPDATE `batch_barang` SET `sumber` = ''donasi'' WHERE `kondisi` = ''bekas_layak''',
  'DO 0');
PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p;

SET @s := IF(@sumber_baru_ss,
  'UPDATE `stok_supplier` SET `sumber` = ''donasi'' WHERE `kondisi` = ''bekas_layak''',
  'DO 0');
PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p;

SET @s := IF(@sumber_baru_pd,
  'UPDATE `perpindahan_detail` SET `sumber` = ''donasi'' WHERE `kondisi` = ''bekas_layak''',
  'DO 0');
PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p;

-- Barang yang punya donatur sudah pasti donasi, apa pun keadaan fisiknya.
-- Aman dijalankan berulang: ia hanya menegaskan yang memang sudah benar.
UPDATE `batch_barang`  SET `sumber` = 'donasi' WHERE `donatur_id` IS NOT NULL;
UPDATE `stok_supplier` SET `sumber` = 'donasi' WHERE `donatur_id` IS NOT NULL;

-- ---------------------------------------------------------------------
-- 3. Indeks
-- ---------------------------------------------------------------------
SET @s := IF((SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'batch_barang' AND INDEX_NAME = 'ix_batch_sumber') = 0,
  'ALTER TABLE `batch_barang` ADD KEY `ix_batch_sumber` (`sumber`)',
  'DO 0');
PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p;

SET @s := IF((SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'stok_supplier' AND INDEX_NAME = 'ix_stok_supplier_sumber') = 0,
  'ALTER TABLE `stok_supplier` ADD KEY `ix_stok_supplier_sumber` (`sumber`)',
  'DO 0');
PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p;

-- ---------------------------------------------------------------------
-- 4. Kondisi dan sumber jadi bagian identitas batch
-- ---------------------------------------------------------------------
-- batch_barang digabung berdasarkan kunci unik uq_batch_lokasi
-- (barang_id, nomor_batch, kondisi, lokasi_rak_id, toko_id, tanggal_expired),
-- dan stok_supplier lewat uq_stoksup. Sumber HARUS ikut ke dalam kunci itu.
-- Tanpa itu, satu dus popok hasil pembelian dan satu dus popok hasil donasi
-- dengan nomor batch sama akan melebur menjadi satu baris — pemisahan yang
-- baru saja dibuat hilang lagi di pemakaian pertama.
--
-- donatur_id juga ikut, supaya titipan dari dua donatur berbeda tidak
-- bertumpuk jadi satu dan kehilangan atribusinya. NULL pada donatur_id
-- (barang pembelian) tidak pernah menghalangi penggabungan: penggabungan
-- terjadi lewat SELECT-lalu-UPDATE di tambah_ke_lokasi(), dan kunci unik
-- baru berperan saat benar-benar ada INSERT.
--
-- Menambah kolom ke kunci unik hanya bisa MENGURANGI tabrakan, jadi tidak
-- ada risiko data lama menjadi duplikat dan menolak perubahan ini.

SET @s := IF((SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'batch_barang' AND INDEX_NAME = 'uq_batch_lokasi' AND COLUMN_NAME = 'sumber') = 0,
  'ALTER TABLE `batch_barang` DROP INDEX `uq_batch_lokasi`, ADD UNIQUE KEY `uq_batch_lokasi` (`barang_id`,`nomor_batch`,`kondisi`,`sumber`,`donatur_id`,`lokasi_rak_id`,`toko_id`,`tanggal_expired`)',
  'DO 0');
PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p;

SET @s := IF((SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'stok_supplier' AND INDEX_NAME = 'uq_stoksup' AND COLUMN_NAME = 'sumber') = 0,
  'ALTER TABLE `stok_supplier` DROP INDEX `uq_stoksup`, ADD UNIQUE KEY `uq_stoksup` (`supplier_id`,`barang_id`,`nomor_batch`,`kondisi`,`sumber`,`donatur_id`,`tanggal_expired`,`lokasi_rak_id`)',
  'DO 0');
PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p;

SET FOREIGN_KEY_CHECKS = 1;
