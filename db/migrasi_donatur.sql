-- =====================================================================
-- AMANAH — Donatur dan catatan donasi
-- =====================================================================
-- Barang yang masuk lewat supplier sebagian besar berasal dari donatur.
-- Sebelumnya asal itu hilang: yang tercatat hanya "dari supplier X", jadi
-- tidak ada cara menjawab siapa yang memberi, sudah berapa kali, dan kapan
-- terakhir — padahal itu justru yang dibutuhkan untuk berterima kasih dan
-- menjaga hubungan dengan donatur.
--
-- Modelnya berkepala dan berbaris (donasi + donasi_detail), bukan satu
-- kolom donatur_id yang ditempel ke stok. Alasannya ada di pertanyaannya
-- sendiri: "paling sering" dan "paling banyak" adalah dua hal berbeda.
-- Satu donatur yang datang sekali membawa sepuluh jenis barang bukan
-- sepuluh kali memberi. Tanpa kepala donasi, kedua angka itu tertukar dan
-- peringkat "paling rajin" jadi salah.
--
-- ------------------------------- DATA PRIBADI -------------------------
-- Tabel ini menyimpan nama dan nomor telepon orang. Yang WAJIB dijaga di
-- lapisan aplikasi, bukan di sini:
--   • nomor telepon hanya untuk yang berkepentingan, disamarkan bagi
--     yang lain
--   • kolom `anonim` dihormati di SEMUA tampilan dan laporan, bukan
--     hanya laporan publik
--   • laporan yang keluar dari sistem memakai nama samaran secukupnya
--     ("Hamba Allah", inisial, atau kota) kecuali diminta sebaliknya
--     oleh yang berwenang
-- Kolom `anonim` ada sejak awal, bukan ditambahkan belakangan, supaya
-- permintaan donatur untuk tidak disebut namanya tidak pernah tercatat
-- sebagai catatan bebas yang gampang terlewat.
--
-- BERJALAN DI MySQL MAUPUN MariaDB, dan aman dijalankan berulang kali.
-- =====================================================================

SET FOREIGN_KEY_CHECKS = 0;

-- ---------------------------------------------------------------------
-- 1. Donatur
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `donatur` (
  `id`          BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  `kode`        VARCHAR(20)  NOT NULL,
  `nama`        VARCHAR(150) NOT NULL,
  `jenis`       ENUM('pribadi','komunitas','lembaga') NOT NULL DEFAULT 'pribadi',
  -- Supplier yang mendaftarkan donatur ini. Dipakai untuk membatasi
  -- pandangan: mitra tidak perlu melihat daftar donatur mitra lain.
  `supplier_id` BIGINT(20) UNSIGNED NULL DEFAULT NULL,
  `telepon`     VARCHAR(30)  NULL DEFAULT NULL COMMENT 'data pribadi; disamarkan bagi yang tidak berkepentingan',
  `email`       VARCHAR(150) NULL DEFAULT NULL COMMENT 'data pribadi',
  `wilayah`     VARCHAR(100) NULL DEFAULT NULL COMMENT 'kota atau kecamatan; dipakai laporan yang disamarkan',
  `alamat`      VARCHAR(255) NULL DEFAULT NULL COMMENT 'data pribadi',
  `kontak_pic`  VARCHAR(150) NULL DEFAULT NULL COMMENT 'penanggung jawab, untuk komunitas dan lembaga',
  `jml_anggota` INT(10) UNSIGNED NULL DEFAULT NULL COMMENT 'perkiraan anggota komunitas',
  `anonim`      TINYINT(1)   NOT NULL DEFAULT 0 COMMENT 'donatur minta tidak disebut namanya; wajib dihormati di semua tampilan',
  `keterangan`  VARCHAR(255) NULL DEFAULT NULL,
  `is_active`   TINYINT(1)   NOT NULL DEFAULT 1,
  `created_at`  TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`  TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
  `deleted_at`  TIMESTAMP NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_donatur_kode` (`kode`),
  KEY `ix_donatur_nama` (`nama`),
  KEY `ix_donatur_jenis` (`jenis`),
  KEY `ix_donatur_wilayah` (`wilayah`),
  KEY `ix_donatur_supplier` (`supplier_id`),
  CONSTRAINT `fk_donatur_supplier` FOREIGN KEY (`supplier_id`) REFERENCES `supplier` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ---------------------------------------------------------------------
-- 2. Kepala donasi — satu kali memberi
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `donasi` (
  `id`          BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  `no_donasi`   VARCHAR(40) NOT NULL,
  `donatur_id`  BIGINT(20) UNSIGNED NOT NULL,
  `supplier_id` BIGINT(20) UNSIGNED NULL DEFAULT NULL COMMENT 'mitra yang menerima titipan ini',
  `tanggal`     DATE NOT NULL,
  `cara_terima` ENUM('diantar','dijemput','dikirim') NOT NULL DEFAULT 'diantar',
  `catatan`     VARCHAR(500) NULL DEFAULT NULL,
  `dibuat_oleh` BIGINT(20) UNSIGNED NULL DEFAULT NULL,
  `created_at`  TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`  TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_donasi_no` (`no_donasi`),
  KEY `ix_donasi_donatur` (`donatur_id`),
  KEY `ix_donasi_tanggal` (`tanggal`),
  KEY `ix_donasi_supplier` (`supplier_id`),
  CONSTRAINT `fk_donasi_donatur`  FOREIGN KEY (`donatur_id`)  REFERENCES `donatur`  (`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT `fk_donasi_supplier` FOREIGN KEY (`supplier_id`) REFERENCES `supplier` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_donasi_user`     FOREIGN KEY (`dibuat_oleh`) REFERENCES `users`    (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- RESTRICT pada donatur, bukan CASCADE: menghapus donatur tidak boleh
-- ikut menghapus riwayat pemberiannya. Riwayat itu yang menjadi dasar
-- laporan penyaluran, dan laporan yang barisnya bisa hilang karena satu
-- penghapusan di halaman lain tidak bisa dipercaya.

-- ---------------------------------------------------------------------
-- 3. Baris donasi
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `donasi_detail` (
  `id`               BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  `donasi_id`        BIGINT(20) UNSIGNED NOT NULL,
  -- barang_id boleh NULL: donatur sering membawa barang yang belum ada di
  -- katalog, dan mencatatnya apa adanya lebih baik daripada memaksa
  -- petugas membuat master barang dadakan supaya formulirnya mau tersimpan.
  `barang_id`        BIGINT(20) UNSIGNED NULL DEFAULT NULL,
  `nama_barang_teks` VARCHAR(150) NULL DEFAULT NULL COMMENT 'dipakai bila barangnya belum ada di katalog',
  `kondisi`          ENUM('baru','bekas_layak','bekas_tidak_layak') NOT NULL DEFAULT 'baru',
  `nomor_batch`      VARCHAR(50) NULL DEFAULT NULL,
  `tanggal_expired`  DATE NULL DEFAULT NULL,
  `qty`              INT(11) NOT NULL DEFAULT 0,
  `nilai_taksir`     DECIMAL(15,2) NOT NULL DEFAULT 0.00 COMMENT 'taksiran internal untuk pencatatan; BUKAN nilai untuk keperluan pajak',
  `keterangan`       VARCHAR(255) NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `ix_dd_donasi` (`donasi_id`),
  KEY `ix_dd_barang` (`barang_id`),
  KEY `ix_dd_kondisi` (`kondisi`),
  CONSTRAINT `fk_dd_donasi` FOREIGN KEY (`donasi_id`) REFERENCES `donasi` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_dd_barang` FOREIGN KEY (`barang_id`) REFERENCES `barang` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ---------------------------------------------------------------------
-- 4. Jejak donatur menempel pada stok
-- ---------------------------------------------------------------------
-- Supaya pertanyaan "barang dari donatur ini sudah sampai ke siapa" bisa
-- dijawab sampai ke penerima akhir, bukan berhenti di gudang.
SET @s := IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'stok_supplier' AND COLUMN_NAME = 'donatur_id') = 0,
  'ALTER TABLE `stok_supplier` ADD COLUMN `donatur_id` BIGINT(20) UNSIGNED NULL DEFAULT NULL COMMENT ''asal barang bila dari donatur'' AFTER `supplier_id`',
  'DO 0');
PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p;

SET @s := IF((SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'stok_supplier' AND INDEX_NAME = 'ix_stok_supplier_donatur') = 0,
  'ALTER TABLE `stok_supplier` ADD KEY `ix_stok_supplier_donatur` (`donatur_id`)',
  'DO 0');
PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p;

SET @s := IF((SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'stok_supplier' AND CONSTRAINT_NAME = 'fk_stok_supplier_donatur' AND CONSTRAINT_TYPE = 'FOREIGN KEY') = 0,
  'ALTER TABLE `stok_supplier` ADD CONSTRAINT `fk_stok_supplier_donatur` FOREIGN KEY (`donatur_id`) REFERENCES `donatur` (`id`) ON DELETE SET NULL ON UPDATE CASCADE',
  'DO 0');
PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p;

SET @s := IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'batch_barang' AND COLUMN_NAME = 'donatur_id') = 0,
  'ALTER TABLE `batch_barang` ADD COLUMN `donatur_id` BIGINT(20) UNSIGNED NULL DEFAULT NULL COMMENT ''diwarisi dari stok supplier saat barang diterima gudang'' AFTER `supplier_id`',
  'DO 0');
PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p;

SET @s := IF((SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'batch_barang' AND INDEX_NAME = 'ix_batch_donatur') = 0,
  'ALTER TABLE `batch_barang` ADD KEY `ix_batch_donatur` (`donatur_id`)',
  'DO 0');
PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p;

SET @s := IF((SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'batch_barang' AND CONSTRAINT_NAME = 'fk_batch_donatur' AND CONSTRAINT_TYPE = 'FOREIGN KEY') = 0,
  'ALTER TABLE `batch_barang` ADD CONSTRAINT `fk_batch_donatur` FOREIGN KEY (`donatur_id`) REFERENCES `donatur` (`id`) ON DELETE SET NULL ON UPDATE CASCADE',
  'DO 0');
PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p;

-- Dokumen perpindahan juga harus membawanya. terapkan_perpindahan() di
-- _lib.php meneruskan donatur_id dari baris dokumen ke batch di gudang;
-- tanpa kolom ini atribusi donatur putus tepat di pintu gudang, justru di
-- langkah yang seharusnya menyambungkannya.
SET @s := IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'perpindahan_detail' AND COLUMN_NAME = 'donatur_id') = 0,
  'ALTER TABLE `perpindahan_detail` ADD COLUMN `donatur_id` BIGINT(20) UNSIGNED NULL DEFAULT NULL COMMENT ''ikut terbawa dari stok asalnya'' AFTER `kondisi`',
  'DO 0');
PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p;

SET @s := IF((SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'perpindahan_detail' AND INDEX_NAME = 'ix_pd_donatur') = 0,
  'ALTER TABLE `perpindahan_detail` ADD KEY `ix_pd_donatur` (`donatur_id`)',
  'DO 0');
PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p;

SET @s := IF((SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'perpindahan_detail' AND CONSTRAINT_NAME = 'fk_pd_donatur' AND CONSTRAINT_TYPE = 'FOREIGN KEY') = 0,
  'ALTER TABLE `perpindahan_detail` ADD CONSTRAINT `fk_pd_donatur` FOREIGN KEY (`donatur_id`) REFERENCES `donatur` (`id`) ON DELETE SET NULL ON UPDATE CASCADE',
  'DO 0');
PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p;

SET @s := IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'pengiriman_supplier_detail' AND COLUMN_NAME = 'donatur_id') = 0,
  'ALTER TABLE `pengiriman_supplier_detail` ADD COLUMN `donatur_id` BIGINT(20) UNSIGNED NULL DEFAULT NULL AFTER `kondisi`',
  'DO 0');
PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p;

-- ---------------------------------------------------------------------
-- 5. Peringkat donatur
-- ---------------------------------------------------------------------
-- Tiga pertanyaan berbeda dijawab tiga kolom berbeda, jadi halaman tinggal
-- memilih ORDER BY:
--   paling sering  -> jml_donasi
--   paling banyak  -> total_qty atau total_nilai
--   paling rajin   -> rata_per_bulan, dan hari_sejak_terakhir untuk
--                     melihat siapa yang mulai jarang
--
-- LEFT JOIN, bukan JOIN: donatur yang baru didaftarkan dan belum pernah
-- memberi tetap harus muncul di daftar dengan angka nol, bukan hilang.
CREATE OR REPLACE VIEW `v_peringkat_donatur` AS
SELECT
  `d`.`id`                                   AS `donatur_id`,
  `d`.`kode`                                 AS `kode`,
  `d`.`nama`                                 AS `nama`,
  `d`.`jenis`                                AS `jenis`,
  `d`.`wilayah`                              AS `wilayah`,
  `d`.`anonim`                               AS `anonim`,
  `d`.`supplier_id`                          AS `supplier_id`,
  `d`.`is_active`                            AS `is_active`,
  COUNT(DISTINCT `dn`.`id`)                  AS `jml_donasi`,
  COALESCE(SUM(`dd`.`qty`), 0)               AS `total_qty`,
  COALESCE(SUM(`dd`.`nilai_taksir`), 0)      AS `total_nilai`,
  COUNT(DISTINCT `dd`.`barang_id`)           AS `jml_jenis_barang`,
  MIN(`dn`.`tanggal`)                        AS `donasi_pertama`,
  MAX(`dn`.`tanggal`)                        AS `donasi_terakhir`,
  DATEDIFF(CURDATE(), MAX(`dn`.`tanggal`))   AS `hari_sejak_terakhir`,
  -- Rata-rata per bulan dihitung sejak donasi PERTAMA, bukan sejak
  -- donatur didaftarkan: orang yang baru memberi sekali bulan lalu tidak
  -- pantas kalah dari yang memberi sekali lima tahun lalu.
  -- GREATEST(...,1) menahan pembagian dengan nol pada bulan yang sama.
  ROUND(COUNT(DISTINCT `dn`.`id`) /
        GREATEST(TIMESTAMPDIFF(MONTH, MIN(`dn`.`tanggal`), CURDATE()) + 1, 1), 2)
                                             AS `rata_per_bulan`
FROM `donatur` `d`
  LEFT JOIN `donasi` `dn`        ON `dn`.`donatur_id` = `d`.`id`
  LEFT JOIN `donasi_detail` `dd` ON `dd`.`donasi_id`  = `dn`.`id`
WHERE `d`.`deleted_at` IS NULL
GROUP BY `d`.`id`, `d`.`kode`, `d`.`nama`, `d`.`jenis`, `d`.`wilayah`,
         `d`.`anonim`, `d`.`supplier_id`, `d`.`is_active`;

SET FOREIGN_KEY_CHECKS = 1;
