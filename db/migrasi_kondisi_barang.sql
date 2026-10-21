-- =====================================================================
-- AMANAH — Kondisi barang: baru / bekas layak / bekas tidak layak
-- =====================================================================
-- Sebelumnya kondisi hanya 'baru' atau 'bekas'. "Bekas" saja tidak cukup:
-- barang bekas yang masih layak pakai boleh disalurkan ke penerima,
-- sedangkan yang tidak layak harus dipisahkan untuk disortir atau
-- dimusnahkan. Digabung dalam satu nilai, keduanya terhitung sebagai stok
-- yang siap disalurkan — dan itu membuat angka persediaan berbohong.
--
-- Penilaian dilakukan SUPPLIER saat mencatat barang, bukan gudang saat
-- menerima. Supplier yang memegang barangnya secara fisik lebih dulu, dan
-- kalau gudang menilai ulang, dua pihak bisa memberi penilaian berbeda
-- untuk barang yang sama tanpa ada yang tahu mana yang benar.
--
-- Karena itu kolom kondisi juga DITAMBAHKAN ke pengiriman_supplier_detail:
-- penilaian ikut menempel pada dokumen kiriman, sehingga gudang membacanya,
-- bukan mengarangnya lagi.
--
-- BERJALAN DI MySQL MAUPUN MariaDB, dan aman dijalankan berulang kali.
-- Bentuk "SET @s := IF(...) / PREPARE" menggantikan "ADD COLUMN IF NOT
-- EXISTS" yang hanya dikenal MariaDB.
-- =====================================================================

SET FOREIGN_KEY_CHECKS = 0;

-- ---------------------------------------------------------------------
-- 1. Pelebaran enum sementara
-- ---------------------------------------------------------------------
-- Nilai lama 'bekas' harus tetap sah selama baris lama dipindahkan. Kalau
-- enum langsung dipersempit ke tiga nilai baru, MySQL mengubah setiap
-- baris 'bekas' menjadi string kosong TANPA memberi galat — datanya hilang
-- diam-diam. Jadi: lebarkan, pindahkan, baru persempit.
ALTER TABLE `stok_supplier`
  MODIFY `kondisi` ENUM('baru','bekas','bekas_layak','bekas_tidak_layak')
  NOT NULL DEFAULT 'baru';

ALTER TABLE `batch_barang`
  MODIFY `kondisi` ENUM('baru','bekas','bekas_layak','bekas_tidak_layak')
  NOT NULL DEFAULT 'baru';

ALTER TABLE `perpindahan_detail`
  MODIFY `kondisi` ENUM('baru','bekas','bekas_layak','bekas_tidak_layak')
  NOT NULL DEFAULT 'baru';

-- ---------------------------------------------------------------------
-- 2. Pemindahan baris lama
-- ---------------------------------------------------------------------
-- Baris 'bekas' yang ada sudah diterima masuk ke stok dan sudah ikut
-- dihitung sebagai persediaan, jadi dalam praktiknya ia dianggap layak
-- pakai. Karena itu dipetakan ke 'bekas_layak', bukan ke 'tidak layak'.
UPDATE `stok_supplier`      SET `kondisi` = 'bekas_layak' WHERE `kondisi` = 'bekas';
UPDATE `batch_barang`       SET `kondisi` = 'bekas_layak' WHERE `kondisi` = 'bekas';
UPDATE `perpindahan_detail` SET `kondisi` = 'bekas_layak' WHERE `kondisi` = 'bekas';

-- ---------------------------------------------------------------------
-- 3. Enum final
-- ---------------------------------------------------------------------
-- Urutan nilainya sengaja dari paling baik ke paling buruk, supaya
-- ORDER BY kondisi menghasilkan urutan yang masuk akal tanpa CASE.
ALTER TABLE `stok_supplier`
  MODIFY `kondisi` ENUM('baru','bekas_layak','bekas_tidak_layak')
  NOT NULL DEFAULT 'baru'
  COMMENT 'dinilai supplier saat mencatat barang; gudang tidak menilai ulang';

ALTER TABLE `batch_barang`
  MODIFY `kondisi` ENUM('baru','bekas_layak','bekas_tidak_layak')
  NOT NULL DEFAULT 'baru'
  COMMENT 'diwarisi dari penilaian supplier; bekas_tidak_layak tidak boleh disalurkan';

ALTER TABLE `perpindahan_detail`
  MODIFY `kondisi` ENUM('baru','bekas_layak','bekas_tidak_layak')
  NOT NULL DEFAULT 'baru'
  COMMENT 'ikut terbawa dari batch asalnya';

-- ---------------------------------------------------------------------
-- 4. Kondisi menempel pada dokumen kiriman supplier
-- ---------------------------------------------------------------------
-- kolom pengiriman_supplier_detail.kondisi
SET @s := IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'pengiriman_supplier_detail' AND COLUMN_NAME = 'kondisi') = 0,
  'ALTER TABLE `pengiriman_supplier_detail` ADD COLUMN `kondisi` ENUM(''baru'',''bekas_layak'',''bekas_tidak_layak'') NOT NULL DEFAULT ''baru'' COMMENT ''penilaian supplier, dibaca gudang saat penerimaan'' AFTER `nomor_batch`',
  'ALTER TABLE `pengiriman_supplier_detail` MODIFY `kondisi` ENUM(''baru'',''bekas_layak'',''bekas_tidak_layak'') NOT NULL DEFAULT ''baru'' COMMENT ''penilaian supplier, dibaca gudang saat penerimaan''');
PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p;

-- ---------------------------------------------------------------------
-- 5. Indeks
-- ---------------------------------------------------------------------
-- batch_barang.kondisi sudah terindeks sejak awal; stok_supplier belum,
-- padahal halaman stok supplier akan menyaring berdasarkan kondisi.
SET @s := IF((SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'stok_supplier' AND INDEX_NAME = 'ix_stok_supplier_kondisi') = 0,
  'ALTER TABLE `stok_supplier` ADD KEY `ix_stok_supplier_kondisi` (`kondisi`)',
  'DO 0');
PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p;

SET @s := IF((SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'pengiriman_supplier_detail' AND INDEX_NAME = 'ix_psd_kondisi') = 0,
  'ALTER TABLE `pengiriman_supplier_detail` ADD KEY `ix_psd_kondisi` (`kondisi`)',
  'DO 0');
PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p;

-- ---------------------------------------------------------------------
-- 6. View stok per kondisi
-- ---------------------------------------------------------------------
-- Dibuat ulang karena tipe kolom enum pada view direkam saat view dibuat,
-- jadi tanpa ini information_schema masih melaporkan enum yang lama.
--
-- Kolom `layak_salur` ditambahkan: inilah yang membedakan stok yang boleh
-- disalurkan dari stok yang hanya menunggu disortir. Tanpa kolom ini
-- setiap halaman harus mengulang daftar nilai enum sendiri, dan begitu
-- ada nilai baru, sebagian halaman akan ketinggalan.
CREATE OR REPLACE VIEW `v_stok_kondisi` AS
SELECT
  `bb`.`barang_id`                                            AS `barang_id`,
  `b`.`kode`                                                  AS `kode_barang`,
  `b`.`nama`                                                  AS `nama_barang`,
  `b`.`satuan`                                                AS `satuan`,
  `bb`.`kondisi`                                              AS `kondisi`,
  (`bb`.`kondisi` <> 'bekas_tidak_layak')                     AS `layak_salur`,
  CASE WHEN `bb`.`toko_id` IS NOT NULL THEN 'toko' ELSE 'gudang' END AS `lokasi_tipe`,
  COALESCE(`t`.`nama`, `g`.`nama`, '—')                       AS `lokasi_nama`,
  COUNT(0)                                                    AS `jml_batch`,
  SUM(`bb`.`qty_tersisa`)                                     AS `qty`,
  SUM(`bb`.`qty_tersisa` * `b`.`harga`)                       AS `nilai`
FROM `batch_barang` `bb`
  JOIN `barang` `b`         ON `b`.`id`  = `bb`.`barang_id`
  LEFT JOIN `toko` `t`      ON `t`.`id`  = `bb`.`toko_id`
  LEFT JOIN `lokasi_rak` `lr` ON `lr`.`id` = `bb`.`lokasi_rak_id`
  LEFT JOIN `gudang` `g`    ON `g`.`id`  = `lr`.`gudang_id`
WHERE `bb`.`qty_tersisa` > 0
GROUP BY
  `bb`.`barang_id`, `bb`.`kondisi`,
  CASE WHEN `bb`.`toko_id` IS NOT NULL THEN 'toko' ELSE 'gudang' END,
  COALESCE(`t`.`nama`, `g`.`nama`, '—');

SET FOREIGN_KEY_CHECKS = 1;
