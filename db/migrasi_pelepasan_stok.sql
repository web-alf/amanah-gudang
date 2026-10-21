-- =====================================================================
-- AMANAH — Perbaikan, pembuangan, dan penjualan barang
-- =====================================================================
-- Sampai sekarang barang hanya punya SATU jalan keluar dari gudang:
-- disalurkan. Padahal kenyataannya ada tiga jalan lain, dan ketiganya
-- tidak punya tempat di sistem:
--
--   • barang bekas yang perlu DIPERBAIKI dulu sebelum layak disalurkan
--   • barang yang harus DIBUANG — kedaluwarsa, atau rusak tak terperbaiki
--   • barang yang DIJUAL, biasanya setelah diperbaiki
--
-- Akibatnya halaman Batch & Expired hanya bisa MELIHAT batch kedaluwarsa
-- tanpa bisa berbuat apa pun terhadapnya — bahkan dokumentasi sistem ini
-- sendiri menyebut "perlu keputusan pemusnahan" untuk sesuatu yang tidak
-- pernah dibuatkan mekanismenya. Barang kedaluwarsa pun tetap terhitung
-- sebagai persediaan, sehingga angka stok melebih-lebihkan apa yang
-- benar-benar bisa disalurkan.
--
-- SATU TABEL, BUKAN TIGA. Ketiganya adalah peristiwa yang sama —
-- sejumlah barang keluar dari stok tersedia dengan sebuah alasan — dan
-- perbedaannya cuma pada apa yang terjadi sesudahnya. Dipisah tiga tabel,
-- pertanyaan "barang ini ke mana perginya" harus ditanyakan tiga kali.
--
-- ------------------------- BARANG MILIK DONATUR ----------------------
-- Sebagian besar barang di sini titipan orang. Membuang atau menjualnya
-- adalah tindakan atas milik orang lain, jadi:
--
--   • `alasan` WAJIB diisi — kolomnya NOT NULL tanpa nilai bawaan, supaya
--     tidak bisa dilewati dengan mengosongkan formulir
--   • `donatur_id` DISALIN ke sini, tidak sekadar diambil lewat batch.
--     Batch habis lalu terhapus, dan bersamanya hilang pula jawaban atas
--     "barang siapa yang tadi dibuang". Salinan ini yang membuat
--     pertanggungjawabannya bertahan
--   • `user_id` memakai ON DELETE SET NULL, bukan CASCADE: menghapus akun
--     petugas tidak boleh ikut menghapus catatan perbuatannya
--
-- Siapa yang boleh melakukannya diatur di lapisan aplikasi
-- (boleh_kelola_master / is_manager), bukan di sini.
--
-- BERJALAN DI MySQL MAUPUN MariaDB, dan aman dijalankan berulang kali.
-- =====================================================================

SET FOREIGN_KEY_CHECKS = 0;

CREATE TABLE IF NOT EXISTS `pelepasan_stok` (
  `id`            BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  `no_pelepasan`  VARCHAR(40) NOT NULL,
  `tanggal`       DATE NOT NULL,

  -- Batch boleh NULL setelah terhapus; barang TIDAK, karena tanpa barang
  -- catatannya tidak bisa dibaca lagi sebagai apa pun.
  `batch_id`      BIGINT(20) UNSIGNED NULL DEFAULT NULL,
  `barang_id`     BIGINT(20) UNSIGNED NOT NULL,
  `donatur_id`    BIGINT(20) UNSIGNED NULL DEFAULT NULL COMMENT 'disalin dari batch agar tetap terlacak setelah batchnya hilang',
  `qty`           INT(11) NOT NULL,

  `jenis`         ENUM('perbaikan','dibuang','dijual') NOT NULL,
  `alasan`        VARCHAR(255) NOT NULL COMMENT 'wajib; ini barang titipan orang',

  -- ----------------------------------------------------------- perbaikan
  -- 'proses' hanya dipakai jenis = perbaikan. Selama berstatus proses,
  -- barangnya sudah TIDAK terhitung stok tersedia tapi juga belum hilang.
  `status`          ENUM('proses','selesai') NOT NULL DEFAULT 'selesai',
  `hasil`           ENUM('layak','dibuang','dijual') NULL DEFAULT NULL COMMENT 'ujung sebuah perbaikan',
  `biaya`           DECIMAL(15,2) NULL DEFAULT NULL COMMENT 'biaya perbaikan; boleh kosong bila dikerjakan sendiri',
  `tanggal_selesai` DATE NULL DEFAULT NULL,
  `batch_kembali_id` BIGINT(20) UNSIGNED NULL DEFAULT NULL COMMENT 'batch baru bila hasil perbaikannya layak pakai',

  -- ----------------------------------------------------------- penjualan
  `nilai_jual`     DECIMAL(15,2) NULL DEFAULT NULL,
  `no_bukti_setor` VARCHAR(60) NULL DEFAULT NULL COMMENT 'nomor bukti setor/transfer hasil penjualan',

  `user_id`    BIGINT(20) UNSIGNED NULL DEFAULT NULL,
  `created_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,

  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_pelepasan_no` (`no_pelepasan`),
  KEY `ix_pelepasan_jenis` (`jenis`, `status`),
  KEY `ix_pelepasan_tanggal` (`tanggal`),
  KEY `ix_pelepasan_barang` (`barang_id`),
  KEY `ix_pelepasan_batch` (`batch_id`),
  KEY `ix_pelepasan_donatur` (`donatur_id`),
  CONSTRAINT `fk_pelepasan_barang`  FOREIGN KEY (`barang_id`)  REFERENCES `barang`       (`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT `fk_pelepasan_batch`   FOREIGN KEY (`batch_id`)   REFERENCES `batch_barang` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_pelepasan_donatur` FOREIGN KEY (`donatur_id`) REFERENCES `donatur`      (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_pelepasan_user`    FOREIGN KEY (`user_id`)    REFERENCES `users`        (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ---------------------------------------------------------------------
-- Ringkasan untuk halaman dan laporan
-- ---------------------------------------------------------------------
-- Menyatukan tiga pertanyaan yang selalu ditanyakan bersamaan: apa yang
-- sedang diperbaiki, apa yang sudah dibuang, dan berapa hasil penjualannya.
CREATE OR REPLACE VIEW `v_pelepasan` AS
SELECT
  `p`.`id`                AS `id`,
  `p`.`no_pelepasan`      AS `no_pelepasan`,
  `p`.`tanggal`           AS `tanggal`,
  `p`.`jenis`             AS `jenis`,
  `p`.`status`            AS `status`,
  `p`.`hasil`             AS `hasil`,
  `p`.`qty`               AS `qty`,
  `p`.`alasan`            AS `alasan`,
  `p`.`biaya`             AS `biaya`,
  `p`.`nilai_jual`        AS `nilai_jual`,
  `p`.`no_bukti_setor`    AS `no_bukti_setor`,
  `p`.`tanggal_selesai`   AS `tanggal_selesai`,
  `b`.`kode`              AS `kode_barang`,
  `b`.`nama`              AS `nama_barang`,
  `b`.`satuan`            AS `satuan`,
  `bb`.`nomor_batch`      AS `nomor_batch`,
  `bb`.`tanggal_expired`  AS `tanggal_expired`,
  `bb`.`kondisi`          AS `kondisi`,
  `d`.`nama`              AS `nama_donatur`,
  `d`.`anonim`            AS `donatur_anonim`,
  `d`.`wilayah`           AS `donatur_wilayah`,
  `u`.`nama`              AS `petugas`,
  -- Lama sebuah barang tertahan di perbaikan. Yang masih berjalan dihitung
  -- sampai hari ini, supaya barang yang terlupakan di bengkel kelihatan.
  DATEDIFF(COALESCE(`p`.`tanggal_selesai`, CURDATE()), `p`.`tanggal`) AS `lama_hari`
FROM `pelepasan_stok` `p`
  JOIN `barang` `b`            ON `b`.`id`  = `p`.`barang_id`
  LEFT JOIN `batch_barang` `bb` ON `bb`.`id` = `p`.`batch_id`
  LEFT JOIN `donatur` `d`      ON `d`.`id`  = `p`.`donatur_id`
  LEFT JOIN `users` `u`        ON `u`.`id`  = `p`.`user_id`;

SET FOREIGN_KEY_CHECKS = 1;
