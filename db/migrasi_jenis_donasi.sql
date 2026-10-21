-- =====================================================================
-- AMANAH — Jenis donasi
-- =====================================================================
-- `cara_terima` sudah ada, tapi ia menjawab pertanyaan yang berbeda:
-- BAGAIMANA barangnya sampai (diantar, dijemput, dikirim). Yang belum
-- terjawab adalah APA bentuk pemberiannya, dan itu dua hal berlainan —
-- donasi paket bisa saja diantar sendiri, donasi kunjungan bisa saja
-- dikirim ekspedisi menyusul.
--
--   kunjungan — donatur datang berkunjung dan membawa barang. Yang penting
--               dicatat terpisah karena kunjungan biasanya berulang dan
--               punya hubungan personal yang perlu dijaga.
--   paket     — pemberian sudah berbentuk paket siap salur (mis. paket
--               sembako, paket sekolah). Isinya tidak dipecah-pecah di
--               gudang, jadi penanganannya memang berbeda.
--   umum      — pemberian barang biasa. Ini yang menjadi nilai bawaan.
--
-- Baris lama dipetakan ke 'umum', BUKAN ke salah satu dari dua jenis yang
-- baru. Donasi yang tercatat sebelum kolom ini ada memang tidak diketahui
-- jenisnya; menebaknya berarti menaruh angka karangan ke dalam laporan
-- yang nanti dibaca sebagai fakta.
--
-- BERJALAN DI MySQL MAUPUN MariaDB, dan aman dijalankan berulang kali.
-- =====================================================================

SET FOREIGN_KEY_CHECKS = 0;

-- kolom donasi.jenis
SET @s := IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'donasi' AND COLUMN_NAME = 'jenis') = 0,
  'ALTER TABLE `donasi` ADD COLUMN `jenis` ENUM(''umum'',''kunjungan'',''paket'') NOT NULL DEFAULT ''umum'' COMMENT ''bentuk pemberian; berbeda dari cara_terima yang menjawab bagaimana barangnya sampai'' AFTER `tanggal`',
  'DO 0');
PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p;

SET @s := IF((SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'donasi' AND INDEX_NAME = 'ix_donasi_jenis') = 0,
  'ALTER TABLE `donasi` ADD KEY `ix_donasi_jenis` (`jenis`)',
  'DO 0');
PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p;

-- ---------------------------------------------------------------------
-- Peringkat donatur ikut membawa rincian per jenis
-- ---------------------------------------------------------------------
-- Ditambahkan, bukan menggantikan: halaman Donatur sudah membaca kolom
-- lama, dan mengubah namanya akan memutus halaman yang sudah berjalan.
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
  -- Berapa kali datang berkunjung, dan berapa kali berupa paket.
  COUNT(DISTINCT CASE WHEN `dn`.`jenis` = 'kunjungan' THEN `dn`.`id` END) AS `jml_kunjungan`,
  COUNT(DISTINCT CASE WHEN `dn`.`jenis` = 'paket'     THEN `dn`.`id` END) AS `jml_paket`,
  MIN(`dn`.`tanggal`)                        AS `donasi_pertama`,
  MAX(`dn`.`tanggal`)                        AS `donasi_terakhir`,
  DATEDIFF(CURDATE(), MAX(`dn`.`tanggal`))   AS `hari_sejak_terakhir`,
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
