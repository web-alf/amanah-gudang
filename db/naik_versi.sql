-- =====================================================================
-- AMANAH — Naik versi ke terbaru, TANPA menghapus data
-- =====================================================================
-- BERJALAN DI MySQL MAUPUN MariaDB. Aman dijalankan berulang kali.
--
-- KAPAN MEMAKAI BERKAS INI, dan kapan tidak:
--
--   Pemasangan PERTAMA          -> pakai instal_database.sql
--   Basis data yang SUDAH ISI   -> pakai berkas ini
--
-- instal_database.sql menghapus seluruh tabel lalu membuatnya ulang. Itu
-- benar untuk pemasangan pertama, tetapi menghancurkan data pada basis
-- data yang sudah dipakai. Berkas ini hanya MENAMBAH dan MENYESUAIKAN.
--
-- Sudah diperiksa: instal_database.sql menghasilkan skema dan data yang
-- IDENTIK dengan hasil berkas ini. Jadi setelah memasang dari pemasang,
-- berkas ini tidak perlu dijalankan.
--
-- URUTAN DI DALAM BERKAS INI PENTING dan sudah terpasang:
-- bagian terakhir membuang kolom yang dibuat bagian sebelumnya (kolom
-- generated, yang ditolak MySQL dengan galat #1901). Menukar urutannya
-- membuat kolom itu tertinggal.
--
-- Sebelum menjalankan pada basis data berisi: CADANGKAN DULU.
-- =====================================================================

SET NAMES utf8mb4;
SET SQL_MODE = 'NO_AUTO_VALUE_ON_ZERO';

-- #####################################################################
-- BAGIAN 1 — Admin Gudang per gudang
-- sumber: migrasi_admin_per_gudang.sql
-- #####################################################################

-- BERJALAN DI MySQL MAUPUN MariaDB, dan aman dijalankan berulang kali.
-- Setiap perubahan diperiksa dulu ke information_schema.
-- =====================================================================
--  AMANAH — Admin Gudang dibatasi per gudang
-- =====================================================================
--  Sebelumnya setiap Admin Gudang melihat SELURUH gudang. Di lembaga
--  dengan satu gudang itu tidak terasa; begitu ada dua atau lebih, ia
--  jadi masalah nyata:
--
--    - petugas Gudang A bisa mengeluarkan stok Gudang B, dan catatannya
--      tetap sah menurut sistem
--    - dasbor menjumlahkan gudang yang bukan tanggung jawabnya, jadi
--      angka "stok hampir habis" tidak bisa ditindaklanjuti siapa pun
--    - laporan tidak bisa menjawab "gudang saya sendiri bagaimana?"
--
--  Sekarang tiap akun Admin Gudang menunjuk SATU gudang, mengikuti pola
--  yang sudah dipakai akun supplier (users.supplier_id). Satu kolom,
--  bukan tabel penghubung — karena yang diminta memang satu petugas satu
--  gudang, dan tabel penghubung untuk hubungan satu-ke-satu hanya
--  menambah tempat data bisa jadi tidak sinkron.
--
--  CATATAN: akun Admin Gudang yang SUDAH ADA otomatis diarahkan ke
--  gudang pertama. Tanpa itu, menjalankan migrasi ini akan langsung
--  mengunci petugas yang sedang bekerja di luar sistemnya sendiri.
-- =====================================================================


-- Ketiga perubahan di bawah DIPERIKSA DULU sebelum dijalankan.
-- Sebelumnya ia berupa ALTER biasa, sehingga berkas ini gagal dengan
-- "Duplicate column name" begitu dijalankan dua kali — atau dijalankan
-- pada basis data yang dipasang dari instal_database.sql, yang kolomnya
-- sudah ada sejak awal.

-- kolom users.gudang_id
SET @s := IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'gudang_id') = 0,
  'ALTER TABLE `users` ADD COLUMN `gudang_id` BIGINT UNSIGNED NULL COMMENT ''gudang yang dipegang akun Admin Gudang; NULL untuk peran lain'' AFTER supplier_id',
  'DO 0');
PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p;

-- indeks users.ix_users_gudang
SET @s := IF((SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND INDEX_NAME = 'ix_users_gudang') = 0,
  'ALTER TABLE `users` ADD INDEX ix_users_gudang (gudang_id)',
  'DO 0');
PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p;

-- ON DELETE SET NULL, bukan CASCADE: menghapus gudang tidak boleh ikut
-- menghapus akun petugasnya. Akunnya tetap ada, hanya kehilangan
-- penugasan — dan Super Admin bisa menugaskannya ke gudang lain.
-- foreign key users.fk_users_gudang
SET @s := IF((SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND CONSTRAINT_NAME = 'fk_users_gudang' AND CONSTRAINT_TYPE = 'FOREIGN KEY') = 0,
  'ALTER TABLE `users` ADD CONSTRAINT `fk_users_gudang` FOREIGN KEY (gudang_id) REFERENCES gudang(id) ON DELETE SET NULL',
  'DO 0');
PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p;

-- ---------------------------------------------------------------------
-- Akun yang sudah ada jangan sampai terkunci.
-- ---------------------------------------------------------------------
UPDATE users u
  JOIN roles r ON r.id = u.role_id
   SET u.gudang_id = (
         SELECT g.id FROM gudang g
          WHERE g.deleted_at IS NULL AND g.is_active = 1
          ORDER BY (g.jenis = 'pusat') DESC, g.kode
          LIMIT 1)
 WHERE r.nama = 'admin_gudang'
   AND u.gudang_id IS NULL
   AND u.deleted_at IS NULL;

-- ---------------------------------------------------------------------
-- Ringkasan siapa memegang gudang mana — dipakai halaman Pengguna.
-- ---------------------------------------------------------------------
CREATE OR REPLACE
SQL SECURITY INVOKER
VIEW v_petugas_gudang AS
SELECT
  g.id            AS gudang_id,
  g.kode          AS kode_gudang,
  g.nama          AS nama_gudang,
  g.jenis,
  COUNT(u.id)     AS jml_petugas,
  GROUP_CONCAT(u.nama ORDER BY u.nama SEPARATOR ', ') AS petugas
FROM gudang g
LEFT JOIN users u
       ON u.gudang_id = g.id
      AND u.deleted_at IS NULL
      AND u.is_active = 1
WHERE g.deleted_at IS NULL
GROUP BY g.id;

SELECT 'Admin Gudang kini terikat per gudang.' AS hasil;
SELECT u.nama, u.email, g.kode, g.nama AS gudang
  FROM users u
  JOIN roles r  ON r.id = u.role_id
  LEFT JOIN gudang g ON g.id = u.gudang_id
 WHERE r.nama = 'admin_gudang' AND u.deleted_at IS NULL;


-- #####################################################################
-- BAGIAN 2 — Pengaturan logo, favicon, gambar share
-- sumber: migrasi_pengaturan_merek.sql
-- #####################################################################

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

SELECT `kunci`, IF(`nilai` = '', '(belum diisi)', `nilai`) AS `nilai` FROM `pengaturan` ORDER BY `kunci`;


-- #####################################################################
-- BAGIAN 3 — Gudang & rak untuk supplier, view rantai
-- sumber: migrasi_rak_supplier.sql
-- #####################################################################

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
SELECT `g`.`kode`, `g`.`nama`, `s`.`nama` AS `pemilik`,
       (SELECT COUNT(*) FROM `lokasi_rak` `lr`
         WHERE `lr`.`gudang_id` = `g`.`id` AND `lr`.`deleted_at` IS NULL) AS `jml_rak`
FROM `gudang` `g`
LEFT JOIN `supplier` `s` ON `s`.`id` = `g`.`supplier_id`
WHERE `g`.`jenis` = 'supplier' AND `g`.`deleted_at` IS NULL
ORDER BY `g`.`kode`;


-- #####################################################################
-- BAGIAN 4 — Pelacakan kiriman & pembayaran marketplace
-- sumber: migrasi_marketplace.sql
-- #####################################################################

-- ============================== 1. KIRIMAN

-- Status kiriman dipisah dari status pesanan. Keduanya bisa berbeda:
-- pesanan bisa 'selesai' menurut marketplace sementara kirimannya sudah
-- lama 'sampai' — dan sebaliknya, pesanan batal setelah barang dikirim.

-- kolom penjualan.no_resi
SET @s := IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'penjualan' AND COLUMN_NAME = 'no_resi') = 0,
  'ALTER TABLE `penjualan` ADD COLUMN `no_resi` varchar(80) DEFAULT NULL COMMENT ''nomor resi dari marketplace'' AFTER `no_pesanan`',
  'DO 0');
PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p;

-- kolom penjualan.kurir
SET @s := IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'penjualan' AND COLUMN_NAME = 'kurir') = 0,
  'ALTER TABLE `penjualan` ADD COLUMN `kurir` varchar(80) DEFAULT NULL COMMENT ''jasa kirim'' AFTER `no_resi`',
  'DO 0');
PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p;

-- kolom penjualan.status_kirim
SET @s := IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'penjualan' AND COLUMN_NAME = 'status_kirim') = 0,
  'ALTER TABLE `penjualan` ADD COLUMN `status_kirim` enum(''belum'',''diproses'',''dikirim'',''sampai'',''gagal'',''kembali'') NOT NULL DEFAULT ''belum'' AFTER `kurir`',
  'DO 0');
PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p;

-- kolom penjualan.tgl_kirim
SET @s := IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'penjualan' AND COLUMN_NAME = 'tgl_kirim') = 0,
  'ALTER TABLE `penjualan` ADD COLUMN `tgl_kirim` datetime DEFAULT NULL AFTER `status_kirim`',
  'DO 0');
PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p;

-- kolom penjualan.tgl_sampai
SET @s := IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'penjualan' AND COLUMN_NAME = 'tgl_sampai') = 0,
  'ALTER TABLE `penjualan` ADD COLUMN `tgl_sampai` datetime DEFAULT NULL AFTER `tgl_kirim`',
  'DO 0');
PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p;

-- ============================== 2. PEMBAYARAN
-- Semua DECIMAL(15,2), sama dengan kolom uang yang sudah ada, supaya
-- penjumlahan lintas kolom tidak pernah kehilangan presisi.

-- kolom penjualan.escrow_amount
SET @s := IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'penjualan' AND COLUMN_NAME = 'escrow_amount') = 0,
  'ALTER TABLE `penjualan` ADD COLUMN `escrow_amount` decimal(15,2) DEFAULT NULL COMMENT ''yang benar-benar diterima penjual setelah semua potongan''',
  'DO 0');
PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p;

-- kolom penjualan.komisi
SET @s := IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'penjualan' AND COLUMN_NAME = 'komisi') = 0,
  'ALTER TABLE `penjualan` ADD COLUMN `komisi` decimal(15,2) NOT NULL DEFAULT 0.00 COMMENT ''komisi marketplace''',
  'DO 0');
PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p;

-- kolom penjualan.biaya_layanan
SET @s := IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'penjualan' AND COLUMN_NAME = 'biaya_layanan') = 0,
  'ALTER TABLE `penjualan` ADD COLUMN `biaya_layanan` decimal(15,2) NOT NULL DEFAULT 0.00',
  'DO 0');
PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p;

-- kolom penjualan.biaya_transaksi
SET @s := IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'penjualan' AND COLUMN_NAME = 'biaya_transaksi') = 0,
  'ALTER TABLE `penjualan` ADD COLUMN `biaya_transaksi` decimal(15,2) NOT NULL DEFAULT 0.00',
  'DO 0');
PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p;

-- kolom penjualan.voucher
SET @s := IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'penjualan' AND COLUMN_NAME = 'voucher') = 0,
  'ALTER TABLE `penjualan` ADD COLUMN `voucher` decimal(15,2) NOT NULL DEFAULT 0.00 COMMENT ''potongan voucher yang ditanggung penjual''',
  'DO 0');
PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p;

-- kolom penjualan.status_bayar
SET @s := IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'penjualan' AND COLUMN_NAME = 'status_bayar') = 0,
  'ALTER TABLE `penjualan` ADD COLUMN `status_bayar` enum(''belum'',''ditahan'',''cair'',''sebagian'',''batal'') NOT NULL DEFAULT ''belum'' COMMENT ''ditahan = masih di escrow marketplace; cair = sudah masuk saldo penjual''',
  'DO 0');
PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p;

-- kolom penjualan.tgl_settlement
SET @s := IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'penjualan' AND COLUMN_NAME = 'tgl_settlement') = 0,
  'ALTER TABLE `penjualan` ADD COLUMN `tgl_settlement` date DEFAULT NULL COMMENT ''tanggal dana dilepas marketplace''',
  'DO 0');
PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p;

-- ============================== 3. SINKRONISASI
-- Status asli marketplace disimpan APA ADANYA, di samping status
-- internal. Marketplace punya status yang lebih rinci dan bisa menambah
-- status baru kapan saja; memaksakannya ke enum internal berarti
-- kehilangan informasi yang tidak bisa dipulihkan.

-- kolom penjualan.status_marketplace
SET @s := IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'penjualan' AND COLUMN_NAME = 'status_marketplace') = 0,
  'ALTER TABLE `penjualan` ADD COLUMN `status_marketplace` varchar(40) DEFAULT NULL COMMENT ''status mentah dari marketplace, mis. READY_TO_SHIP''',
  'DO 0');
PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p;

-- kolom penjualan.ditarik_at
SET @s := IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'penjualan' AND COLUMN_NAME = 'ditarik_at') = 0,
  'ALTER TABLE `penjualan` ADD COLUMN `ditarik_at` timestamp NULL DEFAULT NULL COMMENT ''kapan terakhir data ini ditarik dari marketplace''',
  'DO 0');
PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p;

-- ============================== indeks

-- indeks penjualan.ix_penjualan_resi
SET @s := IF((SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'penjualan' AND INDEX_NAME = 'ix_penjualan_resi') = 0,
  'ALTER TABLE `penjualan` ADD KEY `ix_penjualan_resi` (`no_resi`)',
  'DO 0');
PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p;

-- indeks penjualan.ix_penjualan_kirim
SET @s := IF((SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'penjualan' AND INDEX_NAME = 'ix_penjualan_kirim') = 0,
  'ALTER TABLE `penjualan` ADD KEY `ix_penjualan_kirim` (`status_kirim`)',
  'DO 0');
PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p;

-- indeks penjualan.ix_penjualan_bayar
SET @s := IF((SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'penjualan' AND INDEX_NAME = 'ix_penjualan_bayar') = 0,
  'ALTER TABLE `penjualan` ADD KEY `ix_penjualan_bayar` (`status_bayar`)',
  'DO 0');
PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p;

-- Penarikan berikutnya perlu tahu "mana yang paling lama tidak diperbarui".
SET @s := IF((SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'penjualan' AND INDEX_NAME = 'ix_penjualan_tarik') = 0,
  'ALTER TABLE `penjualan` ADD KEY `ix_penjualan_tarik` (`toko_id`, `ditarik_at`)',
  'DO 0');
PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p;

-- Satu nomor pesanan marketplace tidak boleh masuk dua kali untuk toko
-- yang sama. Tanpa ini, penarikan berulang menggandakan penjualan — dan
-- stok ikut terpotong dua kali.
--
-- Di sini justru perilaku NULL MySQL yang dibutuhkan: beberapa baris
-- ber-no_pesanan NULL tidak dianggap duplikat, sehingga penjualan
-- offline (yang memang tak punya nomor pesanan) tetap bisa dicatat
-- sebanyak apa pun, sementara pesanan marketplace yang bernomor
-- tertahan satu kali.

-- indeks penjualan.uq_penjualan_pesanan
SET @s := IF((SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'penjualan' AND INDEX_NAME = 'uq_penjualan_pesanan') = 0,
  'ALTER TABLE `penjualan` ADD UNIQUE KEY `uq_penjualan_pesanan` (`toko_id`, `marketplace`, `no_pesanan`)',
  'DO 0');
PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p;

-- ============================== jejak penarikan mentah
-- Balasan API disimpan apa adanya. Ini bukan pemborosan: ketika angka
-- pembayaran di sistem berbeda dari dashboard marketplace, satu-satunya cara
-- menyelesaikannya adalah melihat apa yang SEBENARNYA dikirim API pada saat
-- itu — bukan menebak dari hasil olahannya.
--
-- CREATE TABLE IF NOT EXISTS portabel di MySQL maupun MariaDB, jadi tidak
-- perlu dijaga.
CREATE TABLE IF NOT EXISTS `marketplace_tarikan` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `toko_id` bigint(20) unsigned NOT NULL,
  `platform` varchar(30) NOT NULL,
  `no_pesanan` varchar(80) DEFAULT NULL,
  `endpoint` varchar(120) NOT NULL COMMENT 'jalur API yang dipanggil',
  `kode_http` smallint(6) DEFAULT NULL,
  `sukses` tinyint(1) NOT NULL DEFAULT 0,
  `pesan` varchar(500) DEFAULT NULL,
  `data_mentah` mediumtext DEFAULT NULL COMMENT 'balasan API apa adanya',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `ix_tarikan_toko` (`toko_id`, `created_at`),
  KEY `ix_tarikan_pesanan` (`no_pesanan`),
  CONSTRAINT `fk_tarikan_toko` FOREIGN KEY (`toko_id`)
      REFERENCES `toko` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================== penyempurnaan akun API

-- kolom marketplace_akun.nama_shop
SET @s := IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'marketplace_akun' AND COLUMN_NAME = 'nama_shop') = 0,
  'ALTER TABLE `marketplace_akun` ADD COLUMN `nama_shop` varchar(150) DEFAULT NULL COMMENT ''nama toko sebagaimana tercatat di marketplace''',
  'DO 0');
PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p;

-- kolom marketplace_akun.terakhir_tarik_at
SET @s := IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'marketplace_akun' AND COLUMN_NAME = 'terakhir_tarik_at') = 0,
  'ALTER TABLE `marketplace_akun` ADD COLUMN `terakhir_tarik_at` timestamp NULL DEFAULT NULL',
  'DO 0');
PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p;

-- kolom marketplace_akun.terakhir_segar_at
SET @s := IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'marketplace_akun' AND COLUMN_NAME = 'terakhir_segar_at') = 0,
  'ALTER TABLE `marketplace_akun` ADD COLUMN `terakhir_segar_at` timestamp NULL DEFAULT NULL COMMENT ''kapan access_token terakhir diperbarui''',
  'DO 0');
PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p;

-- kolom marketplace_akun.aktif
SET @s := IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'marketplace_akun' AND COLUMN_NAME = 'aktif') = 0,
  'ALTER TABLE `marketplace_akun` ADD COLUMN `aktif` tinyint(1) NOT NULL DEFAULT 1 COMMENT ''penarikan otomatis dijalankan atau tidak''',
  'DO 0');
PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p;

-- indeks marketplace_akun.ix_mpakun_tarik
SET @s := IF((SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'marketplace_akun' AND INDEX_NAME = 'ix_mpakun_tarik') = 0,
  'ALTER TABLE `marketplace_akun` ADD KEY `ix_mpakun_tarik` (`aktif`, `terakhir_tarik_at`)',
  'DO 0');
PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p;

-- Kunci unik akun API TIDAK dipasang di sini.
-- Ia diatur migrasi_tokopedia.sql, yang menetapkan aturan
-- "satu toko satu kredensial" — memasangnya di dua tempat berarti
-- dua berkas bisa saling bertentangan.


-- #####################################################################
-- BAGIAN 5 — Tokopedia + satu toko satu kredensial
-- sumber: migrasi_tokopedia.sql
-- #####################################################################

-- ============================== 1. satu toko satu kredensial

-- Kunci lama (toko_id, platform) dibuang — terlalu longgar.
SET @s := IF((SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'marketplace_akun' AND INDEX_NAME = 'uq_mpakun') > 0,
  'ALTER TABLE `marketplace_akun` DROP INDEX `uq_mpakun`',
  'DO 0');
PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p;

-- Kunci dari skema awal juga dibuang: sudah tercakup kunci baru, jadi
-- membiarkannya hanya menambah indeks yang tak pernah dipakai — dan
-- menyesatkan pembaca skema, karena tampak seolah dua aturan berlaku.
SET @s := IF((SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'marketplace_akun' AND INDEX_NAME = 'uq_mp_toko_platform') > 0,
  'ALTER TABLE `marketplace_akun` DROP INDEX `uq_mp_toko_platform`',
  'DO 0');
PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p;

-- SATU TOKO SATU KREDENSIAL.
SET @s := IF((SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'marketplace_akun' AND INDEX_NAME = 'uq_mpakun_toko') = 0,
  'ALTER TABLE `marketplace_akun` ADD UNIQUE KEY `uq_mpakun_toko` (`toko_id`)',
  'DO 0');
PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p;

-- ============================== 2. kolom khusus Tokopedia

-- kolom marketplace_akun.fs_id
SET @s := IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'marketplace_akun' AND COLUMN_NAME = 'fs_id') = 0,
  'ALTER TABLE `marketplace_akun` ADD COLUMN `fs_id` varchar(40) DEFAULT NULL COMMENT ''Fulfillment Service ID — khusus Tokopedia, tidak ada padanannya di Shopee'' AFTER `shop_id`',
  'DO 0');
PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p;

-- Platform yang bisa disambungkan diselaraskan dengan enum toko.marketplace.
-- Nilai 'offline' dan 'lainnya' TIDAK ikut: keduanya bukan marketplace yang
-- punya API, dan menaruhnya di sini hanya menawarkan sambungan yang tidak
-- mungkin ada.
-- MODIFY tidak perlu dijaga: menjalankannya dua kali menghasilkan hal sama.
ALTER TABLE `marketplace_akun`
  MODIFY `platform` enum('shopee','tokopedia','tiktok','lazada') NOT NULL;


-- #####################################################################
-- BAGIAN 6 — Buang kolom generated (wajib TERAKHIR)
-- sumber: migrasi_portabel_mysql.sql
-- #####################################################################

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


-- #####################################################################
-- RINGKASAN — semua angka harus sesuai keterangannya
-- #####################################################################
SELECT
  (SELECT COUNT(*) FROM information_schema.TABLES
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_TYPE = 'BASE TABLE')  AS tabel_harus_38,
  (SELECT COUNT(*) FROM information_schema.VIEWS
    WHERE TABLE_SCHEMA = DATABASE())                               AS view_harus_12,
  (SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND EXTRA LIKE '%GENERATED%')   AS generated_harus_0,
  (SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'penjualan'
      AND COLUMN_NAME IN ('no_resi','kurir','status_kirim','tgl_kirim','tgl_sampai',
        'escrow_amount','komisi','biaya_layanan','biaya_transaksi','voucher',
        'status_bayar','tgl_settlement','status_marketplace','ditarik_at'))  AS kolom_mp_harus_14,
  (SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users'
      AND COLUMN_NAME = 'gudang_id')                               AS users_gudang_harus_1,
  (SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'marketplace_akun'
      AND COLUMN_NAME = 'fs_id')                                   AS fs_id_harus_1;