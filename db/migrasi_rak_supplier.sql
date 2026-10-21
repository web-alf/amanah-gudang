-- =====================================================================
-- AMANAH — Rak untuk Supplier
-- =====================================================================
-- Supplier sebelumnya tidak punya lokasi apa pun: stoknya adalah daftar
-- batch datar di stok_supplier. Migrasi ini memberinya gudang & rak,
-- sehingga "Pindah Antar Rak" punya arti di sisi supplier juga.
--
-- Sifatnya ADITIF, bukan pemindahan model:
--   • stok_supplier TETAP menjadi rumah stok supplier
--   • 12 rujukan yang ada di 4 berkas PHP tetap berjalan tanpa diubah
--   • tidak ada baris yang dipindahkan ke batch_barang
--
-- Alasannya: alur supplier (catat masuk → siap kirim → kirim) sudah utuh
-- dan benar. Memindahkannya ke batch_barang berarti membongkar alur yang
-- berjalan, dan itu risiko yang tidak perlu dibayar untuk sekadar
-- menambahkan lokasi.
--
-- BERJALAN DI MySQL MAUPUN MariaDB. Bentuk "SET @s := IF(...) / PREPARE"
-- di bawah menggantikan "ADD COLUMN IF NOT EXISTS" yang hanya dikenal
-- MariaDB — di MySQL klausa itu galat sintaks, dan galat sintaks
-- menghentikan SELURUH berkas. Aman dijalankan berulang kali.
-- =====================================================================

SET FOREIGN_KEY_CHECKS = 0;

-- ------------------------------------------------ 1. jenis gudang baru
ALTER TABLE `gudang`
  MODIFY `jenis` ENUM('pusat','toko','supplier') NOT NULL DEFAULT 'pusat'
  COMMENT 'pusat = gudang lembaga; toko = penampung sisi toko; supplier = gudang milik supplier';

-- --------------------------------- 2. gudang milik supplier yang mana
-- SET NULL, bukan CASCADE. lokasi_rak → gudang berlaku RESTRICT, jadi
-- CASCADE dari supplier akan mencoba menghapus gudang yang masih punya
-- rak lalu GAGAL dengan galat FK yang tidak bisa dibaca operator.
-- SET NULL tidak pernah memblokir penghapusan dan tidak pernah menyapu
-- rak beserta stoknya secara tak terduga.
-- kolom gudang.supplier_id
SET @s := IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'gudang' AND COLUMN_NAME = 'supplier_id') = 0,
  'ALTER TABLE `gudang` ADD COLUMN `supplier_id` BIGINT(20) UNSIGNED NULL DEFAULT NULL COMMENT ''pemilik gudang bila jenis = supplier; NULL untuk gudang lembaga'' AFTER `jenis`',
  'DO 0');
PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p;

-- indeks gudang.ix_gudang_supplier
SET @s := IF((SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'gudang' AND INDEX_NAME = 'ix_gudang_supplier') = 0,
  'ALTER TABLE `gudang` ADD KEY `ix_gudang_supplier` (`supplier_id`)',
  'DO 0');
PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p;

-- foreign key gudang.fk_gudang_supplier
SET @s := IF((SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'gudang' AND CONSTRAINT_NAME = 'fk_gudang_supplier' AND CONSTRAINT_TYPE = 'FOREIGN KEY') = 0,
  'ALTER TABLE `gudang` ADD CONSTRAINT `fk_gudang_supplier` FOREIGN KEY (`supplier_id`) REFERENCES `supplier` (`id`) ON DELETE SET NULL ON UPDATE CASCADE',
  'DO 0');
PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p;

-- ------------------------------------------- 3. lokasi rak pada stok
-- kolom stok_supplier.lokasi_rak_id
SET @s := IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'stok_supplier' AND COLUMN_NAME = 'lokasi_rak_id') = 0,
  'ALTER TABLE `stok_supplier` ADD COLUMN `lokasi_rak_id` BIGINT(20) UNSIGNED NULL DEFAULT NULL COMMENT ''rak tempat batch ini berada, di dalam gudang supplier'' AFTER `barang_id`',
  'DO 0');
PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p;

-- Kolom generated STORED, mengikuti pola yang sudah dipakai batch_barang
-- (rak_key / toko_key / exp_key). NULL di dalam kunci unik tidak dianggap
-- duplikat oleh MySQL, jadi tanpa coalesce() baris tanpa rak bisa
-- menumpuk berkali-kali tanpa tertahan.
-- Kolom generated rak_key TIDAK dipakai lagi.
--
-- MySQL menolaknya dengan galat #1901 ("Function or expression
-- 'lokasi_rak_id' cannot be used in the GENERATED ALWAYS AS clause"), dan
-- galat itu menghentikan seluruh berkas. Kunci unik di bawah memakai kolom
-- ASLI sebagai gantinya.
--
-- Yang hilang, dan ini harus disebut terang-terangan: pada kolom yang NULL,
-- MySQL maupun MariaDB tidak menganggap dua baris sebagai duplikat. Jadi
-- pencegahan batch ganda kini bertumpu pada pemeriksaan di aplikasi
-- (SELECT lalu INSERT/UPDATE), bukan pada basis data.
--
-- Kunci yang MEMAKAI rak_key dibuang lebih dulu. Urutan ini bukan selera:
-- DROP COLUMN pada kolom yang masih dipakai indeks gagal dengan
-- "Key column rak_key doesn''t exist in table" — dan galat itu
-- menghentikan seluruh berkas.
SET @s := IF((SELECT COUNT(*) FROM information_schema.STATISTICS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'stok_supplier'
                AND INDEX_NAME = 'uq_stoksup') > 0,
  'ALTER TABLE `stok_supplier` DROP INDEX `uq_stoksup`',
  'DO 0');
PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p;

-- Kolom generated yang mungkin sudah terpasang dari versi sebelumnya ikut
-- dibuang, supaya skemanya sama di semua lingkungan.
SET @s := IF((SELECT COUNT(*) FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'stok_supplier'
                AND COLUMN_NAME = 'rak_key') > 0,
  'ALTER TABLE `stok_supplier` DROP COLUMN `rak_key`',
  'DO 0');
PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p;

-- Tanpa FOREIGN KEY, dan itu bukan kelalaian: MariaDB menolak FK pada kolom
-- dasar dari kolom generated STORED (galat 138). batch_barang.lokasi_rak_id
-- juga tidak punya FK karena rak_key di sana lahir dari alasan yang sama.
-- Konsekuensinya sama seperti yang sudah berlaku di batch_barang: rak yang
-- dihapus bisa meninggalkan rujukan menggantung, jadi rak.php menahan
-- penghapusan rak yang masih terpakai.
-- indeks stok_supplier.ix_ss_rak
SET @s := IF((SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'stok_supplier' AND INDEX_NAME = 'ix_ss_rak') = 0,
  'ALTER TABLE `stok_supplier` ADD KEY `ix_ss_rak` (`lokasi_rak_id`)',
  'DO 0');
PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p;

-- Rak masuk ke kunci unik.
--
-- Ini mengubah satu perilaku dengan sengaja: INSERT ... ON DUPLICATE KEY
-- di dasbor supplier sebelumnya menggabungkan pemasukan batch yang sama
-- ke baris yang sudah ada, apa pun raknya. Setelah rak ada, penggabungan
-- seperti itu berarti stok DIPINDAHKAN ke rak lain tanpa ada yang
-- memintanya. Dengan rak di dalam kunci, tiap rak memegang barisnya
-- sendiri — dan satu batch bisa tersebar di beberapa rak, sebagaimana
-- barang sungguhan memang bisa.
-- kunci lama dibuang lebih dulu
SET @s := IF((SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'stok_supplier' AND INDEX_NAME = 'uq_stoksup') > 0,
  'ALTER TABLE `stok_supplier` DROP INDEX `uq_stoksup`',
  'DO 0');
PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p;

-- indeks stok_supplier.uq_stoksup
SET @s := IF((SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'stok_supplier' AND INDEX_NAME = 'uq_stoksup') = 0,
  'ALTER TABLE `stok_supplier` ADD UNIQUE KEY `uq_stoksup` (`supplier_id`,`barang_id`,`nomor_batch`,`kondisi`,`tanggal_expired`,`lokasi_rak_id`)',
  'DO 0');
PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p;

SET FOREIGN_KEY_CHECKS = 1;

-- ============================================ 4. gudang tiap supplier
-- Satu gudang per supplier — bukan gudang bersama. Supplier adalah pihak
-- luar yang berbeda-beda; menaruh stok mereka di satu gudang berarti
-- mereka saling melihat isi gudang satu sama lain.
SET @kode := (SELECT COALESCE(MAX(CAST(SUBSTRING_INDEX(`kode`, '-', -1) AS UNSIGNED)), 0)
              FROM (SELECT `kode` FROM `gudang`) AS `g`);

INSERT INTO `gudang` (`kode`, `nama`, `jenis`, `supplier_id`, `penanggung_jawab`, `keterangan`, `is_active`)
SELECT CONCAT('GD-', LPAD(@kode := @kode + 1, 3, '0')),
       CONCAT('Gudang ', `s`.`nama`),
       'supplier', `s`.`id`, `s`.`nama`,
       'Dibuat otomatis saat rak supplier diaktifkan.', 1
FROM `supplier` `s`
WHERE `s`.`deleted_at` IS NULL
  AND `s`.`id` NOT IN (SELECT `supplier_id` FROM (
        SELECT `supplier_id` FROM `gudang`
        WHERE `supplier_id` IS NOT NULL AND `deleted_at` IS NULL) AS `ada`);

-- ------------------------------------------------- 5. satu rak bawaan
-- Stok tersimpan per rak. Gudang tanpa rak tampak sudah jadi padahal
-- barang tidak bisa masuk ke mana pun.
SET @rak := (SELECT COALESCE(MAX(CAST(SUBSTRING_INDEX(`kode`, '-', -1) AS UNSIGNED)), 0)
             FROM (SELECT `kode` FROM `lokasi_rak`) AS `r`);

INSERT INTO `lokasi_rak` (`kode`, `nama`, `gudang_id`, `gudang`, `keterangan`)
SELECT CONCAT('RAK-', LPAD(@rak := @rak + 1, 3, '0')),
       'Area Siap Kirim', `g`.`id`, `g`.`nama`,
       'Rak bawaan gudang supplier — barang yang dicatat masuk mendarat di sini.'
FROM `gudang` `g`
WHERE `g`.`jenis` = 'supplier' AND `g`.`deleted_at` IS NULL
  AND `g`.`id` NOT IN (SELECT `gudang_id` FROM (
        SELECT `gudang_id` FROM `lokasi_rak`
        WHERE `gudang_id` IS NOT NULL AND `deleted_at` IS NULL) AS `ada`);

-- ------------------------------- 6. baris stok lama dapat rak bawaan
-- Baris tanpa rak akan hilang dari laporan berbasis rak. Di basis data
-- yang masih kosong ini tidak melakukan apa pun, tapi tetap perlu ada
-- supaya migrasi ini benar juga di lingkungan yang sudah berisi.
UPDATE `stok_supplier` `ss`
  JOIN `gudang` `g`      ON `g`.`supplier_id` = `ss`.`supplier_id`
                        AND `g`.`jenis` = 'supplier' AND `g`.`deleted_at` IS NULL
  JOIN `lokasi_rak` `lr` ON `lr`.`gudang_id` = `g`.`id` AND `lr`.`deleted_at` IS NULL
   SET `ss`.`lokasi_rak_id` = `lr`.`id`
 WHERE `ss`.`lokasi_rak_id` IS NULL;


-- =====================================================================
-- VIEW RANTAI — stok & kedaluwarsa dari SELURUH simpul
-- =====================================================================
-- Stok di sistem ini hidup di dua tabel: batch_barang (gudang & toko) dan
-- stok_supplier (supplier). Tanpa view gabungan, setiap halaman yang ingin
-- "stok sesuai peran" harus menulis dua query lalu menggabungkannya
-- sendiri — dan dua halaman yang bersebelahan di menu akan cepat berbeda
-- angkanya, tanpa cara memberi tahu pemakainya yang mana yang benar.
--
-- v_status_expired ikut dibuat ulang: ia kini mengekspos lokasi_rak_id,
-- toko_id, gudang_id, jenis_gudang, dan supplier_id, sehingga bisa
-- disaring per lingkup. Kolom lamanya tidak ada yang dihapus.
--
-- v_stok_rantai SENGAJA menyertakan baris ber-qty NOL: batch yang sudah
-- terkuras adalah satu-satunya jejak bahwa barang ini memang disimpan di
-- lokasi itu. Tanpa baris nol, barang yang HABIS lenyap dari peringatan
-- stok — persis keadaan yang paling perlu diperingatkan.
--
-- Tanpa DEFINER, dan SQL SECURITY INVOKER: view yang menyebut
-- DEFINER=root@localhost gagal diimpor di hosting bersama.
-- =====================================================================
DROP VIEW IF EXISTS `v_status_expired`;
CREATE SQL SECURITY INVOKER VIEW `v_status_expired` AS select `bb`.`id` AS `batch_id`,`bb`.`barang_id` AS `barang_id`,`b`.`nama` AS `nama_barang`,`bb`.`nomor_batch` AS `nomor_batch`,`bb`.`tanggal_expired` AS `tanggal_expired`,`bb`.`qty_tersisa` AS `qty_tersisa`,to_days(`bb`.`tanggal_expired`) - to_days(curdate()) AS `sisa_hari`,case when `bb`.`tanggal_expired` is null then 'tanpa_expired' when to_days(`bb`.`tanggal_expired`) - to_days(curdate()) < 0 then 'expired' when to_days(`bb`.`tanggal_expired`) - to_days(curdate()) <= 30 then 'segera_expired' when to_days(`bb`.`tanggal_expired`) - to_days(curdate()) <= 90 then 'waspada' else 'aman' end AS `status_expired`,case when `bb`.`toko_id` is not null then 'toko' else 'gudang' end AS `lokasi_tipe`,coalesce(`t`.`nama`,concat(coalesce(`g`.`nama`,'—'),' › ',coalesce(`lr`.`nama`,'—'))) AS `lokasi_nama`,`bb`.`lokasi_rak_id` AS `lokasi_rak_id`,`bb`.`toko_id` AS `toko_id`,`lr`.`gudang_id` AS `gudang_id`,`g`.`jenis` AS `jenis_gudang`,`g`.`supplier_id` AS `supplier_id` from ((((`batch_barang` `bb` join `barang` `b` on(`b`.`id` = `bb`.`barang_id`)) left join `toko` `t` on(`t`.`id` = `bb`.`toko_id`)) left join `lokasi_rak` `lr` on(`lr`.`id` = `bb`.`lokasi_rak_id`)) left join `gudang` `g` on(`g`.`id` = `lr`.`gudang_id`)) where `bb`.`qty_tersisa` > 0;

DROP VIEW IF EXISTS `v_expired_rantai`;
CREATE SQL SECURITY INVOKER VIEW `v_expired_rantai` AS select 'batch' AS `sumber`,`ve`.`batch_id` AS `ref_id`,`ve`.`barang_id` AS `barang_id`,`ve`.`nama_barang` AS `nama_barang`,`ve`.`nomor_batch` AS `nomor_batch`,`ve`.`tanggal_expired` AS `tanggal_expired`,`ve`.`qty_tersisa` AS `qty_tersisa`,`ve`.`sisa_hari` AS `sisa_hari`,`ve`.`status_expired` AS `status_expired`,`ve`.`lokasi_tipe` AS `lokasi_tipe`,`ve`.`lokasi_nama` AS `lokasi_nama`,`ve`.`lokasi_rak_id` AS `lokasi_rak_id`,`ve`.`toko_id` AS `toko_id`,NULL AS `supplier_id` from `v_status_expired` `ve` union all select 'supplier' AS `supplier`,`ss`.`id` AS `id`,`ss`.`barang_id` AS `barang_id`,`b`.`nama` AS `nama`,`ss`.`nomor_batch` AS `nomor_batch`,`ss`.`tanggal_expired` AS `tanggal_expired`,`ss`.`qty` AS `qty`,to_days(`ss`.`tanggal_expired`) - to_days(curdate()) AS `TO_DAYS(ss.tanggal_expired) - TO_DAYS(CURDATE())`,case when `ss`.`tanggal_expired` is null then 'tanpa_expired' when to_days(`ss`.`tanggal_expired`) - to_days(curdate()) < 0 then 'expired' when to_days(`ss`.`tanggal_expired`) - to_days(curdate()) <= 30 then 'segera_expired' when to_days(`ss`.`tanggal_expired`) - to_days(curdate()) <= 90 then 'waspada' else 'aman' end AS `Name_exp_9`,'supplier' AS `supplier`,concat(coalesce(`g`.`nama`,'—'),' › ',coalesce(`lr`.`nama`,'—')) AS `CONCAT(COALESCE(g.nama,'—'), ' › ', COALESCE(lr.nama,'—'))`,`ss`.`lokasi_rak_id` AS `lokasi_rak_id`,NULL AS `NULL`,`ss`.`supplier_id` AS `supplier_id` from (((`stok_supplier` `ss` join `barang` `b` on(`b`.`id` = `ss`.`barang_id`)) left join `lokasi_rak` `lr` on(`lr`.`id` = `ss`.`lokasi_rak_id`)) left join `gudang` `g` on(`g`.`id` = `lr`.`gudang_id`)) where `ss`.`qty` > 0;

DROP VIEW IF EXISTS `v_stok_rantai`;
CREATE SQL SECURITY INVOKER VIEW `v_stok_rantai` AS select 'batch' AS `sumber`,`bb`.`barang_id` AS `barang_id`,`b`.`kode` AS `kode_barang`,`b`.`nama` AS `nama_barang`,`b`.`stok_minimum` AS `stok_minimum`,`bb`.`qty_tersisa` AS `qty`,`bb`.`lokasi_rak_id` AS `lokasi_rak_id`,`bb`.`toko_id` AS `toko_id`,NULL AS `supplier_id` from (`batch_barang` `bb` join `barang` `b` on(`b`.`id` = `bb`.`barang_id`)) where `b`.`deleted_at` is null union all select 'supplier' AS `supplier`,`ss`.`barang_id` AS `barang_id`,`b`.`kode` AS `kode`,`b`.`nama` AS `nama`,`b`.`stok_minimum` AS `stok_minimum`,`ss`.`qty` AS `qty`,`ss`.`lokasi_rak_id` AS `lokasi_rak_id`,NULL AS `NULL`,`ss`.`supplier_id` AS `supplier_id` from (`stok_supplier` `ss` join `barang` `b` on(`b`.`id` = `ss`.`barang_id`)) where `b`.`deleted_at` is null;

-- ------------------------------------------------------- 7. ringkasan
-- SELECT ringkasan sengaja DIHAPUS — runner migrasi.php menjalankan tiap
-- pernyataan lewat PDO::exec(), yang gagal (MySQL 2014) kalau diberi
-- SELECT berdiri sendiri. Untuk memeriksa manual:
-- SELECT `g`.`kode`, `g`.`nama`, `s`.`nama` AS `pemilik`,
--        (SELECT COUNT(*) FROM `lokasi_rak` `lr`
--          WHERE `lr`.`gudang_id` = `g`.`id` AND `lr`.`deleted_at` IS NULL) AS `jml_rak`
-- FROM `gudang` `g`
-- LEFT JOIN `supplier` `s` ON `s`.`id` = `g`.`supplier_id`
-- WHERE `g`.`jenis` = 'supplier' AND `g`.`deleted_at` IS NULL
-- ORDER BY `g`.`kode`;
