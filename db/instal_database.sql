-- =====================================================================
--  AMANAH — Aplikasi Manajemen Amanah & Hibah
--  PASANG DATABASE DARI AWAL — satu berkas, sekali jalan
-- =====================================================================
--
--  Berkas ini menggantikan seluruh berkas migrasi yang terpisah. Ia
--  membangun basis data lengkap dari kosong: 36 tabel, 9 view, data
--  acuan, dan master data yang sudah terisi.
--
--  ---------------------------------------------------------------
--  PERINGATAN — BERKAS INI MENGHAPUS TABEL YANG SUDAH ADA
--  ---------------------------------------------------------------
--  Setiap tabel dibuat ulang dengan DROP TABLE IF EXISTS. Kalau
--  dijalankan pada basis data yang SUDAH berisi data pemakaian,
--  seluruh isinya hilang. Ini disengaja: berkas pasang harus
--  menghasilkan keadaan yang sama persis setiap kali dijalankan,
--  bukan setengah lama setengah baru yang mustahil ditelusuri.
--
--  Untuk memasang PERTAMA KALI di hosting: aman, tidak ada yang hilang.
--  Untuk MEMPERBARUI sistem yang sudah dipakai: cadangkan dulu.
--
--  ---------------------------------------------------------------
--  CARA MEMASANG
--  ---------------------------------------------------------------
--  A. Lewat phpMyAdmin (paling umum di hosting)
--     1. Buat basis data kosong lewat cPanel, catat namanya.
--     2. Buka phpMyAdmin, PILIH basis data itu di panel kiri.
--     3. Tab Import, pilih berkas ini, klik Import.
--     Berkas ini sengaja TIDAK memuat CREATE DATABASE atau USE, supaya
--     ia masuk ke basis data mana pun yang Anda pilih — nama basis data
--     di hosting biasanya diawali nama akun cPanel dan tidak bisa
--     ditentukan sendiri.
--
--  B. Lewat baris perintah
--     mysql -u PENGGUNA -p NAMA_DATABASE < instal_database.sql
--
--  ---------------------------------------------------------------
--  SETELAH MEMASANG
--  ---------------------------------------------------------------
--  1. Buat config.local.php di folder aplikasi (JANGAN ubah config.php):
--
--         <?php
--         $DB_HOST = 'localhost';
--         $DB_NAME = 'nama_database_di_hosting';
--         $DB_USER = 'pengguna_database';
--         $DB_PASS = 'password_database';
--
--     Berkas itu otomatis dipakai bila ada, dan sudah diblokir dari
--     akses web lewat .htaccess.
--
--  2. GANTI SEMUA PASSWORD lewat menu Pengaturan › Pengguna. Hash yang
--     ikut di berkas ini berasal dari pemakaian lokal — anggap sudah
--     diketahui orang lain begitu berkas ini berpindah tangan.
--
--  3. Pastikan .htaccess ikut terunggah. Tanpa itu, berkas .sql di
--     folder aplikasi bisa diunduh siapa pun yang tahu alamatnya —
--     termasuk berkas ini, beserta seluruh hash password di dalamnya.
--
--  ---------------------------------------------------------------
--  CATATAN TEKNIS
--  ---------------------------------------------------------------
--  * View dibuat tanpa DEFINER dan dengan SQL SECURITY INVOKER.
--    Dump bawaan mysqldump menyisipkan DEFINER=`root`@`localhost`, dan
--    di hosting yang penggunanya bukan root impor akan berhenti dengan
--    "Access denied; you need SUPER privilege". Itu sudah dibereskan
--    di berkas ini.
--  * Jejak aktivitas, sesi perangkat, dan log scan TIDAK ikut. Ketiganya
--    catatan pemakaian lokal. Sesi lokal yang terbawa akan tampil sebagai
--    slot perangkat yang terpakai padahal tidak ada yang memakainya.
--  * Nomor dokumen (dokumen_counter) IKUT, supaya penomoran berikutnya
--    menyambung dan tidak menabrak dokumen yang sudah ada.
-- =====================================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;
SET SQL_MODE = 'NO_AUTO_VALUE_ON_ZERO';
SET time_zone = '+07:00';

-- Kalau memasang di komputer sendiri dan ingin sekalian membuat basis
-- datanya, hapus tanda komentar pada dua baris di bawah.
-- CREATE DATABASE IF NOT EXISTS gudang_bayi
--   DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
-- USE gudang_bayi;

-- =====================================================================
--  BAGIAN 1 — STRUKTUR: tabel & view
-- =====================================================================

/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;
/*!40103 SET @OLD_TIME_ZONE=@@TIME_ZONE */;
/*!40103 SET TIME_ZONE='+00:00' */;
/*!40014 SET @OLD_UNIQUE_CHECKS=@@UNIQUE_CHECKS, UNIQUE_CHECKS=0 */;
/*!40014 SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0 */;
/*!40101 SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO' */;
/*!40111 SET @OLD_SQL_NOTES=@@SQL_NOTES, SQL_NOTES=0 */;
DROP TABLE IF EXISTS `activity_log`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `activity_log` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint(20) unsigned DEFAULT NULL,
  `aksi` varchar(100) NOT NULL,
  `modul` varchar(50) NOT NULL,
  `referensi_id` bigint(20) unsigned DEFAULT NULL,
  `deskripsi` varchar(500) DEFAULT NULL,
  `data_lama` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`data_lama`)),
  `data_baru` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`data_baru`)),
  `ip_address` varchar(45) DEFAULT NULL,
  `user_agent` varchar(255) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_log_user` (`user_id`),
  KEY `idx_log_modul` (`modul`),
  KEY `idx_log_created` (`created_at`),
  CONSTRAINT `fk_log_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=386 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `barang`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `barang` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `kode` varchar(30) NOT NULL,
  `barcode` varchar(50) DEFAULT NULL,
  `nama` varchar(150) NOT NULL,
  `kategori_id` bigint(20) unsigned NOT NULL,
  `merek` varchar(100) DEFAULT NULL,
  `ukuran` varchar(50) DEFAULT NULL,
  `satuan` enum('pcs','box','pack','kaleng','lusin','set') NOT NULL DEFAULT 'pcs',
  `foto` varchar(255) DEFAULT NULL,
  `harga` decimal(15,2) NOT NULL DEFAULT 0.00,
  `stok_minimum` int(11) NOT NULL DEFAULT 0,
  `supplier_id` bigint(20) unsigned DEFAULT NULL,
  `lokasi_rak_id` bigint(20) unsigned DEFAULT NULL,
  `keterangan` text DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_barang_kode` (`kode`),
  KEY `idx_barang_kategori` (`kategori_id`),
  KEY `idx_barang_supplier` (`supplier_id`),
  KEY `idx_barang_rak` (`lokasi_rak_id`),
  KEY `idx_barang_nama` (`nama`),
  CONSTRAINT `fk_barang_kategori` FOREIGN KEY (`kategori_id`) REFERENCES `kategori` (`id`) ON UPDATE CASCADE,
  CONSTRAINT `fk_barang_rak` FOREIGN KEY (`lokasi_rak_id`) REFERENCES `lokasi_rak` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_barang_supplier` FOREIGN KEY (`supplier_id`) REFERENCES `supplier` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=22 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `batch_barang`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `batch_barang` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `barang_id` bigint(20) unsigned NOT NULL,
  `nomor_batch` varchar(50) NOT NULL,
  `kondisi` enum('baru','bekas') NOT NULL DEFAULT 'baru' COMMENT 'baru = pembelian; bekas = donasi/second',
  `lokasi_rak_id` bigint(20) unsigned DEFAULT NULL,
  `toko_id` bigint(20) unsigned DEFAULT NULL,
  `qr_payload` varchar(255) DEFAULT NULL,
  `tanggal_masuk` date NOT NULL,
  `tanggal_expired` date DEFAULT NULL,
  `qty_awal` int(11) NOT NULL DEFAULT 0,
  `qty_tersisa` int(11) NOT NULL DEFAULT 0,
  `harga_beli` decimal(15,2) NOT NULL DEFAULT 0.00,
  `supplier_id` bigint(20) unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  -- Kolom rak_key/toko_key/exp_key DIBUANG. Ketiganya kolom generated,
  -- dan MySQL menolaknya di sini dengan galat #1901 — yang menghentikan
  -- seluruh impor. Kunci unik uq_batch_lokasi di bawah memakai kolom
  -- aslinya sebagai gantinya.
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_batch_lokasi` (`barang_id`,`nomor_batch`,`kondisi`,`lokasi_rak_id`,`toko_id`,`tanggal_expired`),
  KEY `idx_batch_barang` (`barang_id`),
  KEY `idx_batch_expired` (`tanggal_expired`),
  KEY `idx_batch_rak` (`lokasi_rak_id`),
  KEY `fk_batch_supplier` (`supplier_id`),
  KEY `idx_batch_toko` (`toko_id`),
  KEY `ix_batch_kondisi` (`kondisi`,`qty_tersisa`),
  CONSTRAINT `fk_batch_barang` FOREIGN KEY (`barang_id`) REFERENCES `barang` (`id`) ON UPDATE CASCADE,
  CONSTRAINT `fk_batch_rak` FOREIGN KEY (`lokasi_rak_id`) REFERENCES `lokasi_rak` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_batch_supplier` FOREIGN KEY (`supplier_id`) REFERENCES `supplier` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_batch_toko` FOREIGN KEY (`toko_id`) REFERENCES `toko` (`id`) ON UPDATE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=41 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `dokumen_counter`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `dokumen_counter` (
  `kunci` varchar(120) NOT NULL,
  `urut` bigint(20) unsigned NOT NULL DEFAULT 0,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`kunci`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `gudang`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `gudang` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `kode` varchar(20) NOT NULL,
  `nama` varchar(150) NOT NULL,
  `jenis` enum('pusat','toko','supplier') NOT NULL DEFAULT 'pusat' COMMENT 'pusat = gudang lembaga; toko = penampung sisi toko; supplier = gudang milik supplier',
  `supplier_id` bigint(20) unsigned DEFAULT NULL COMMENT 'pemilik gudang bila jenis = supplier; NULL untuk gudang lembaga',
  `alamat` varchar(255) DEFAULT NULL,
  `penanggung_jawab` varchar(150) DEFAULT NULL,
  `telepon` varchar(30) DEFAULT NULL,
  `keterangan` varchar(255) DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_gudang_kode` (`kode`),
  KEY `ix_gudang_jenis` (`jenis`),
  KEY `ix_gudang_supplier` (`supplier_id`),
  -- SET NULL, bukan CASCADE: lokasi_rak -> gudang berlaku RESTRICT, jadi
  -- CASCADE dari supplier akan mencoba menghapus gudang yang masih punya rak
  -- lalu gagal dengan galat FK yang tidak terbaca operator.
  CONSTRAINT `fk_gudang_supplier` FOREIGN KEY (`supplier_id`) REFERENCES `supplier` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=7 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `kategori`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `kategori` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `kode` varchar(20) NOT NULL,
  `nama` varchar(100) NOT NULL,
  `ikon` varchar(50) DEFAULT NULL,
  `keterangan` varchar(255) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_kategori_kode` (`kode`)
) ENGINE=InnoDB AUTO_INCREMENT=23 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `lokasi_rak`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `lokasi_rak` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `kode` varchar(20) NOT NULL,
  `nama` varchar(100) NOT NULL,
  `gudang_id` bigint(20) unsigned DEFAULT NULL,
  `gudang` varchar(100) NOT NULL DEFAULT 'Gudang Utama',
  `kapasitas` int(10) unsigned DEFAULT NULL,
  `keterangan` varchar(255) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_rak_kode` (`kode`),
  KEY `idx_rak_gudang` (`gudang_id`),
  CONSTRAINT `fk_rak_gudang` FOREIGN KEY (`gudang_id`) REFERENCES `gudang` (`id`) ON UPDATE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=13 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `marketplace_akun`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `marketplace_akun` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `toko_id` bigint(20) unsigned NOT NULL,
  `platform` enum('shopee','tokopedia','tiktok','lazada') NOT NULL,
  `partner_id` varchar(100) DEFAULT NULL,
  `partner_key` varchar(255) DEFAULT NULL,
  `shop_id` varchar(100) DEFAULT NULL,
  `fs_id` varchar(40) DEFAULT NULL COMMENT 'Fulfillment Service ID — khusus Tokopedia',
  `access_token` varchar(512) DEFAULT NULL,
  `refresh_token` varchar(512) DEFAULT NULL,
  `token_expired_at` timestamp NULL DEFAULT NULL,
  `status` enum('belum_tersambung','tersambung','kedaluwarsa','error') NOT NULL DEFAULT 'belum_tersambung',
  `pesan_terakhir` varchar(500) DEFAULT NULL,
  `nama_shop` varchar(150) DEFAULT NULL
      COMMENT 'nama toko sebagaimana tercatat di marketplace',
  `terakhir_tarik_at` timestamp NULL DEFAULT NULL,
  `terakhir_segar_at` timestamp NULL DEFAULT NULL
      COMMENT 'kapan access_token terakhir diperbarui',
  `aktif` tinyint(1) NOT NULL DEFAULT 1
      COMMENT 'penarikan otomatis dijalankan atau tidak',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  -- SATU TOKO SATU KREDENSIAL. Bukan per platform: kolom
  -- toko.marketplace hanya memuat satu nilai, jadi satu baris toko
  -- memang satu lapak. Merek yang berjualan di dua marketplace
  -- dicatat sebagai dua baris toko terpisah.
  UNIQUE KEY `uq_mpakun_toko` (`toko_id`),
  KEY `ix_mpakun_tarik` (`aktif`, `terakhir_tarik_at`),
  CONSTRAINT `fk_mp_toko` FOREIGN KEY (`toko_id`) REFERENCES `toko` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `marketplace_sku`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `marketplace_sku` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `toko_id` bigint(20) unsigned NOT NULL,
  `barang_id` bigint(20) unsigned NOT NULL,
  `sku_marketplace` varchar(80) NOT NULL,
  `nama_di_marketplace` varchar(200) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_mps` (`toko_id`,`sku_marketplace`),
  KEY `idx_mps_barang` (`barang_id`),
  CONSTRAINT `fk_mps_barang` FOREIGN KEY (`barang_id`) REFERENCES `barang` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_mps_toko` FOREIGN KEY (`toko_id`) REFERENCES `toko` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=8 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `marketplace_sync_log`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `marketplace_sync_log` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `toko_id` bigint(20) unsigned DEFAULT NULL,
  `platform` varchar(30) DEFAULT NULL,
  `jenis` enum('impor_csv','tarik_pesanan','dorong_stok') NOT NULL,
  `nama_berkas` varchar(255) DEFAULT NULL,
  `jml_baris` int(11) NOT NULL DEFAULT 0,
  `jml_sukses` int(11) NOT NULL DEFAULT 0,
  `jml_gagal` int(11) NOT NULL DEFAULT 0,
  `status` enum('sukses','sebagian','gagal') NOT NULL DEFAULT 'sukses',
  `pesan` text DEFAULT NULL,
  `user_id` bigint(20) unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_sync_toko` (`toko_id`),
  KEY `idx_sync_created` (`created_at`),
  KEY `fk_sync_user` (`user_id`),
  CONSTRAINT `fk_sync_toko` FOREIGN KEY (`toko_id`) REFERENCES `toko` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_sync_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `mutasi_barang`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `mutasi_barang` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `no_mutasi` varchar(30) NOT NULL,
  `tanggal` datetime NOT NULL,
  `barang_id` bigint(20) unsigned NOT NULL,
  `batch_id` bigint(20) unsigned DEFAULT NULL,
  `rak_asal_id` bigint(20) unsigned DEFAULT NULL,
  `rak_tujuan_id` bigint(20) unsigned NOT NULL,
  `qty` int(11) NOT NULL,
  `catatan` varchar(255) DEFAULT NULL,
  `user_id` bigint(20) unsigned NOT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_mutasi_no` (`no_mutasi`),
  KEY `idx_mutasi_barang` (`barang_id`),
  KEY `fk_mutasi_batch` (`batch_id`),
  KEY `fk_mutasi_rak_asal` (`rak_asal_id`),
  KEY `fk_mutasi_rak_tujuan` (`rak_tujuan_id`),
  KEY `fk_mutasi_user` (`user_id`),
  CONSTRAINT `fk_mutasi_barang` FOREIGN KEY (`barang_id`) REFERENCES `barang` (`id`) ON UPDATE CASCADE,
  CONSTRAINT `fk_mutasi_batch` FOREIGN KEY (`batch_id`) REFERENCES `batch_barang` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_mutasi_rak_asal` FOREIGN KEY (`rak_asal_id`) REFERENCES `lokasi_rak` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_mutasi_rak_tujuan` FOREIGN KEY (`rak_tujuan_id`) REFERENCES `lokasi_rak` (`id`) ON UPDATE CASCADE,
  CONSTRAINT `fk_mutasi_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `notifikasi`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `notifikasi` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `tipe` enum('stok_habis','stok_hampir_habis','expired_h90','expired_h30','expired_h7','expired_hari_h','opname','sistem') NOT NULL,
  `judul` varchar(150) NOT NULL,
  `pesan` varchar(500) NOT NULL,
  `barang_id` bigint(20) unsigned DEFAULT NULL,
  `batch_id` bigint(20) unsigned DEFAULT NULL,
  `target_role` varchar(50) DEFAULT NULL,
  `user_id` bigint(20) unsigned DEFAULT NULL,
  `kanal` set('in_app','whatsapp','email') NOT NULL DEFAULT 'in_app',
  `is_read` tinyint(1) NOT NULL DEFAULT 0,
  `is_sent` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_notif_tipe` (`tipe`),
  KEY `idx_notif_read` (`is_read`),
  KEY `fk_notif_barang` (`barang_id`),
  KEY `fk_notif_batch` (`batch_id`),
  CONSTRAINT `fk_notif_barang` FOREIGN KEY (`barang_id`) REFERENCES `barang` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_notif_batch` FOREIGN KEY (`batch_id`) REFERENCES `batch_barang` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=5 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `penerima`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `penerima` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `kode` varchar(20) NOT NULL,
  `nama` varchar(150) NOT NULL,
  `jenis` enum('individu','keluarga','lembaga','lainnya') NOT NULL DEFAULT 'keluarga' COMMENT 'lembaga = panti, masjid, sekolah',
  `wilayah` varchar(100) DEFAULT NULL COMMENT 'kelurahan / kecamatan / kota',
  `alamat` varchar(255) DEFAULT NULL,
  `telepon` varchar(30) DEFAULT NULL,
  `penanggung_jawab` varchar(150) DEFAULT NULL COMMENT 'nama yang bisa dihubungi',
  `jml_jiwa` int(10) unsigned DEFAULT NULL COMMENT 'berapa orang yang ditanggung',
  `keterangan` varchar(255) DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_penerima_kode` (`kode`),
  KEY `ix_penerima_nama` (`nama`),
  KEY `ix_penerima_wilayah` (`wilayah`)
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `pengiriman_supplier`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `pengiriman_supplier` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `no_pengiriman` varchar(40) NOT NULL,
  `supplier_id` bigint(20) unsigned NOT NULL,
  `gudang_tujuan_id` bigint(20) unsigned DEFAULT NULL,
  `no_resi` varchar(60) DEFAULT NULL,
  `ekspedisi` varchar(100) DEFAULT NULL,
  `tanggal_kirim` date DEFAULT NULL,
  `estimasi_tiba` date DEFAULT NULL,
  `status` enum('dijadwalkan','dikirim','tiba','diterima','dibatalkan') NOT NULL DEFAULT 'dijadwalkan',
  `perpindahan_id` bigint(20) unsigned DEFAULT NULL,
  `catatan` varchar(500) DEFAULT NULL,
  `dibuat_oleh` bigint(20) unsigned DEFAULT NULL,
  `diterima_oleh` bigint(20) unsigned DEFAULT NULL,
  `diterima_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_ps_no` (`no_pengiriman`),
  KEY `idx_ps_supplier` (`supplier_id`),
  KEY `idx_ps_resi` (`no_resi`),
  KEY `idx_ps_status` (`status`),
  KEY `fk_ps_gudang` (`gudang_tujuan_id`),
  KEY `fk_ps_pp` (`perpindahan_id`),
  KEY `fk_ps_pembuat` (`dibuat_oleh`),
  KEY `fk_ps_penerima` (`diterima_oleh`),
  CONSTRAINT `fk_ps_gudang` FOREIGN KEY (`gudang_tujuan_id`) REFERENCES `gudang` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_ps_pembuat` FOREIGN KEY (`dibuat_oleh`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_ps_penerima` FOREIGN KEY (`diterima_oleh`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_ps_pp` FOREIGN KEY (`perpindahan_id`) REFERENCES `perpindahan` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_ps_supplier` FOREIGN KEY (`supplier_id`) REFERENCES `supplier` (`id`) ON UPDATE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `pengiriman_supplier_detail`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `pengiriman_supplier_detail` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `pengiriman_supplier_id` bigint(20) unsigned NOT NULL,
  `barang_id` bigint(20) unsigned DEFAULT NULL,
  `nama_barang_teks` varchar(150) DEFAULT NULL,
  `nomor_batch` varchar(50) DEFAULT NULL,
  `tanggal_expired` date DEFAULT NULL,
  `qty` int(11) NOT NULL,
  `harga` decimal(15,2) NOT NULL DEFAULT 0.00,
  `keterangan` varchar(255) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_psd_header` (`pengiriman_supplier_id`),
  KEY `fk_psd_barang` (`barang_id`),
  CONSTRAINT `fk_psd_barang` FOREIGN KEY (`barang_id`) REFERENCES `barang` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_psd_header` FOREIGN KEY (`pengiriman_supplier_id`) REFERENCES `pengiriman_supplier` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=7 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `penjualan`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `penjualan` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `no_penjualan` varchar(40) NOT NULL,
  `toko_id` bigint(20) unsigned NOT NULL,
  `tanggal` datetime NOT NULL,
  `marketplace` enum('shopee','tokopedia','tiktok','lazada','offline','lainnya') NOT NULL DEFAULT 'offline',
  `no_pesanan` varchar(80) DEFAULT NULL,
  `nama_pembeli` varchar(150) DEFAULT NULL,
  `total` decimal(15,2) NOT NULL DEFAULT 0.00,
  `ongkir` decimal(15,2) NOT NULL DEFAULT 0.00,
  `status` enum('baru','dikemas','dikirim','selesai','batal') NOT NULL DEFAULT 'selesai',
  `sumber` enum('manual','impor_csv','api') NOT NULL DEFAULT 'manual',

  -- ---------- KIRIMAN ----------
  -- status_kirim DIPISAH dari status: keduanya bisa berbeda. Pesanan bisa
  -- 'selesai' menurut marketplace sementara kirimannya sudah lama 'sampai',
  -- dan sebaliknya pesanan batal setelah barang dikirim.
  `no_resi` varchar(80) DEFAULT NULL COMMENT 'nomor resi dari marketplace',
  `kurir` varchar(80) DEFAULT NULL COMMENT 'jasa kirim',
  `status_kirim` enum('belum','diproses','dikirim','sampai','gagal','kembali')
      NOT NULL DEFAULT 'belum',
  `tgl_kirim` datetime DEFAULT NULL,
  `tgl_sampai` datetime DEFAULT NULL,

  -- ---------- PEMBAYARAN ----------
  -- Semua DECIMAL(15,2), sama dengan kolom uang lainnya, supaya penjumlahan
  -- lintas kolom tidak pernah kehilangan presisi.
  `escrow_amount` decimal(15,2) DEFAULT NULL
      COMMENT 'yang benar-benar diterima penjual setelah semua potongan',
  `komisi` decimal(15,2) NOT NULL DEFAULT 0.00 COMMENT 'komisi marketplace',
  `biaya_layanan` decimal(15,2) NOT NULL DEFAULT 0.00,
  `biaya_transaksi` decimal(15,2) NOT NULL DEFAULT 0.00,
  `voucher` decimal(15,2) NOT NULL DEFAULT 0.00
      COMMENT 'potongan voucher yang ditanggung penjual',
  `status_bayar` enum('belum','ditahan','cair','sebagian','batal') NOT NULL DEFAULT 'belum'
      COMMENT 'ditahan = masih di escrow marketplace; cair = sudah masuk saldo penjual',
  `tgl_settlement` date DEFAULT NULL COMMENT 'tanggal dana dilepas marketplace',

  -- ---------- SINKRONISASI ----------
  -- Status asli marketplace disimpan APA ADANYA di samping status internal.
  -- Marketplace bisa menambah status baru kapan saja; memaksakannya ke enum
  -- internal berarti kehilangan informasi yang tak bisa dipulihkan.
  `status_marketplace` varchar(40) DEFAULT NULL
      COMMENT 'status mentah dari marketplace, mis. READY_TO_SHIP',
  `ditarik_at` timestamp NULL DEFAULT NULL
      COMMENT 'kapan terakhir data ini ditarik dari marketplace',
  `catatan` varchar(500) DEFAULT NULL,
  `user_id` bigint(20) unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_penjualan_no` (`no_penjualan`),
  UNIQUE KEY `uq_penjualan_pesanan` (`toko_id`,`no_pesanan`),
  KEY `idx_jual_tanggal` (`tanggal`),
  KEY `idx_jual_toko` (`toko_id`),
  KEY `fk_jual_user` (`user_id`),
  KEY `ix_penjualan_resi` (`no_resi`),
  KEY `ix_penjualan_kirim` (`status_kirim`),
  KEY `ix_penjualan_bayar` (`status_bayar`),
  -- Penarikan berikutnya perlu tahu "mana yang paling lama tidak diperbarui".
  KEY `ix_penjualan_tarik` (`toko_id`, `ditarik_at`),
  CONSTRAINT `fk_jual_toko` FOREIGN KEY (`toko_id`) REFERENCES `toko` (`id`) ON UPDATE CASCADE,
  CONSTRAINT `fk_jual_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=10 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `penjualan_detail`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `penjualan_detail` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `penjualan_id` bigint(20) unsigned NOT NULL,
  `barang_id` bigint(20) unsigned NOT NULL,
  `batch_id` bigint(20) unsigned DEFAULT NULL,
  `sku_marketplace` varchar(80) DEFAULT NULL,
  `qty` int(11) NOT NULL,
  `harga_jual` decimal(15,2) NOT NULL DEFAULT 0.00,
  `subtotal` decimal(15,2) NOT NULL DEFAULT 0.00,
  `keterangan` varchar(255) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_jd_header` (`penjualan_id`),
  KEY `idx_jd_barang` (`barang_id`),
  KEY `fk_jd_batch` (`batch_id`),
  CONSTRAINT `fk_jd_barang` FOREIGN KEY (`barang_id`) REFERENCES `barang` (`id`) ON UPDATE CASCADE,
  CONSTRAINT `fk_jd_batch` FOREIGN KEY (`batch_id`) REFERENCES `batch_barang` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_jd_header` FOREIGN KEY (`penjualan_id`) REFERENCES `penjualan` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=12 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `penyesuaian_stok`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `penyesuaian_stok` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `no_penyesuaian` varchar(40) NOT NULL,
  `tanggal` datetime NOT NULL,
  `lokasi_tipe` enum('gudang','toko') NOT NULL,
  `toko_id` bigint(20) unsigned DEFAULT NULL,
  `lokasi_rak_id` bigint(20) unsigned DEFAULT NULL,
  `barang_id` bigint(20) unsigned NOT NULL,
  `batch_id` bigint(20) unsigned DEFAULT NULL,
  `jenis` enum('rusak','hilang','kedaluwarsa','koreksi_tambah','koreksi_kurang') NOT NULL,
  `qty` int(11) NOT NULL,
  `alasan` varchar(255) DEFAULT NULL,
  `user_id` bigint(20) unsigned NOT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_penyesuaian_no` (`no_penyesuaian`),
  KEY `idx_ps_barang` (`barang_id`),
  KEY `idx_ps_tanggal` (`tanggal`),
  KEY `fk_ps_batch` (`batch_id`),
  KEY `fk_ps_toko` (`toko_id`),
  KEY `fk_ps_user` (`user_id`),
  CONSTRAINT `fk_ps_barang` FOREIGN KEY (`barang_id`) REFERENCES `barang` (`id`) ON UPDATE CASCADE,
  CONSTRAINT `fk_ps_batch` FOREIGN KEY (`batch_id`) REFERENCES `batch_barang` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_ps_toko` FOREIGN KEY (`toko_id`) REFERENCES `toko` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_ps_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON UPDATE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `perpindahan`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `perpindahan` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `no_perpindahan` varchar(40) NOT NULL,
  `no_surat_jalan` varchar(40) DEFAULT NULL,
  `jenis` enum('penerimaan','transfer_gudang','mutasi_rak','penyaluran','retur','kirim_toko','retur_toko','antar_toko') NOT NULL,
  `tanggal` datetime NOT NULL,
  `asal_tipe` enum('supplier','gudang','rak','toko','eksternal') NOT NULL,
  `asal_id` bigint(20) unsigned DEFAULT NULL,
  `asal_teks` varchar(150) DEFAULT NULL,
  `tujuan_tipe` enum('gudang','rak','toko','penerima','supplier') NOT NULL,
  `tujuan_id` bigint(20) unsigned DEFAULT NULL,
  `tujuan_teks` varchar(150) DEFAULT NULL,
  `no_resi` varchar(60) DEFAULT NULL,
  `ekspedisi` varchar(100) DEFAULT NULL,
  `kendaraan` varchar(100) DEFAULT NULL,
  `no_polisi` varchar(20) DEFAULT NULL,
  `pengirim` varchar(150) DEFAULT NULL,
  `penerima` varchar(150) DEFAULT NULL,
  `untuk_program` varchar(150) DEFAULT NULL,
  `status` enum('draft','menunggu_persetujuan','disetujui','ditolak','dikirim','diterima','selesai') NOT NULL DEFAULT 'draft',
  `alasan_tolak` varchar(255) DEFAULT NULL,
  `user_id` bigint(20) unsigned NOT NULL,
  `approved_by` bigint(20) unsigned DEFAULT NULL,
  `diterima_oleh` bigint(20) unsigned DEFAULT NULL,
  `approved_at` timestamp NULL DEFAULT NULL,
  `diterima_at` timestamp NULL DEFAULT NULL,
  `printed_at` timestamp NULL DEFAULT NULL,
  `print_count` int(10) unsigned NOT NULL DEFAULT 0,
  `catatan` varchar(500) DEFAULT NULL,
  `catatan_terima` varchar(500) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_pp_no` (`no_perpindahan`),
  UNIQUE KEY `uq_pp_sj` (`no_surat_jalan`),
  KEY `idx_pp_status` (`status`),
  KEY `idx_pp_tanggal` (`tanggal`),
  KEY `idx_pp_jenis` (`jenis`),
  KEY `idx_pp_resi` (`no_resi`),
  KEY `fk_pp_user` (`user_id`),
  KEY `fk_pp_approver` (`approved_by`),
  KEY `fk_pp_penerima` (`diterima_oleh`),
  CONSTRAINT `fk_pp_approver` FOREIGN KEY (`approved_by`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_pp_penerima` FOREIGN KEY (`diterima_oleh`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_pp_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON UPDATE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=17 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `perpindahan_detail`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `perpindahan_detail` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `perpindahan_id` bigint(20) unsigned NOT NULL,
  `barang_id` bigint(20) unsigned NOT NULL,
  `batch_id` bigint(20) unsigned DEFAULT NULL,
  `nomor_batch` varchar(50) DEFAULT NULL,
  `kondisi` enum('baru','bekas') NOT NULL DEFAULT 'baru',
  `tanggal_expired` date DEFAULT NULL,
  `qty` int(11) NOT NULL,
  `qty_diterima` int(11) DEFAULT NULL,
  `harga` decimal(15,2) NOT NULL DEFAULT 0.00,
  `subtotal` decimal(15,2) NOT NULL DEFAULT 0.00,
  `keterangan` varchar(255) DEFAULT NULL,
  `selisih_catatan` varchar(255) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_ppd_header` (`perpindahan_id`),
  KEY `idx_ppd_barang` (`barang_id`),
  KEY `fk_ppd_batch` (`batch_id`),
  CONSTRAINT `fk_ppd_barang` FOREIGN KEY (`barang_id`) REFERENCES `barang` (`id`) ON UPDATE CASCADE,
  CONSTRAINT `fk_ppd_batch` FOREIGN KEY (`batch_id`) REFERENCES `batch_barang` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_ppd_header` FOREIGN KEY (`perpindahan_id`) REFERENCES `perpindahan` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=24 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `resi_scan_log`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `resi_scan_log` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `no_resi` varchar(60) NOT NULL,
  `sumber` enum('kamera','scanner','manual') NOT NULL DEFAULT 'manual',
  `hasil` enum('cocok','tidak_ditemukan') NOT NULL,
  `pengiriman_supplier_id` bigint(20) unsigned DEFAULT NULL,
  `perpindahan_id` bigint(20) unsigned DEFAULT NULL,
  `user_id` bigint(20) unsigned DEFAULT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_scan_resi` (`no_resi`),
  KEY `idx_scan_created` (`created_at`),
  KEY `fk_scan_ps` (`pengiriman_supplier_id`),
  KEY `fk_scan_pp` (`perpindahan_id`),
  KEY `fk_scan_user` (`user_id`),
  CONSTRAINT `fk_scan_pp` FOREIGN KEY (`perpindahan_id`) REFERENCES `perpindahan` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_scan_ps` FOREIGN KEY (`pengiriman_supplier_id`) REFERENCES `pengiriman_supplier` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_scan_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=14 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `retur`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `retur` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `no_retur` varchar(30) NOT NULL,
  `tanggal` datetime NOT NULL,
  `jenis` enum('supplier','internal') NOT NULL,
  `barang_id` bigint(20) unsigned NOT NULL,
  `batch_id` bigint(20) unsigned DEFAULT NULL,
  `supplier_id` bigint(20) unsigned DEFAULT NULL,
  `qty` int(11) NOT NULL,
  `alasan` varchar(255) DEFAULT NULL,
  `user_id` bigint(20) unsigned NOT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_retur_no` (`no_retur`),
  KEY `idx_retur_barang` (`barang_id`),
  KEY `fk_retur_batch` (`batch_id`),
  KEY `fk_retur_supplier` (`supplier_id`),
  KEY `fk_retur_user` (`user_id`),
  CONSTRAINT `fk_retur_barang` FOREIGN KEY (`barang_id`) REFERENCES `barang` (`id`) ON UPDATE CASCADE,
  CONSTRAINT `fk_retur_batch` FOREIGN KEY (`batch_id`) REFERENCES `batch_barang` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_retur_supplier` FOREIGN KEY (`supplier_id`) REFERENCES `supplier` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_retur_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `roles`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `roles` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `nama` varchar(50) NOT NULL,
  `label` varchar(100) NOT NULL,
  `keterangan` varchar(255) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_roles_nama` (`nama`)
) ENGINE=InnoDB AUTO_INCREMENT=6 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `sesi_perangkat`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `sesi_perangkat` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint(20) unsigned NOT NULL,
  `sid_hash` char(64) NOT NULL,
  `perangkat` varchar(120) DEFAULT NULL COMMENT 'ringkasan terbaca: Windows Â· Chrome',
  `user_agent` varchar(255) DEFAULT NULL,
  `ip` varchar(45) DEFAULT NULL,
  `dibuat_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `terakhir_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `diakhiri_at` timestamp NULL DEFAULT NULL,
  `diakhiri_oleh` bigint(20) unsigned DEFAULT NULL COMMENT 'siapa yang mengakhiri, bila bukan pemiliknya',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_sesi_sid` (`sid_hash`),
  KEY `ix_sesi_user_hidup` (`user_id`,`diakhiri_at`,`terakhir_at`),
  CONSTRAINT `fk_sesi_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=38 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `stock_opname`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `stock_opname` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `no_opname` varchar(30) NOT NULL,
  `tanggal` date NOT NULL,
  `status` enum('draft','menunggu_persetujuan','disetujui','ditolak') NOT NULL DEFAULT 'draft',
  `user_id` bigint(20) unsigned NOT NULL,
  `approved_by` bigint(20) unsigned DEFAULT NULL,
  `approved_at` timestamp NULL DEFAULT NULL,
  `catatan` varchar(255) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_opname_no` (`no_opname`),
  KEY `fk_opname_user` (`user_id`),
  KEY `fk_opname_approver` (`approved_by`),
  CONSTRAINT `fk_opname_approver` FOREIGN KEY (`approved_by`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_opname_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `stock_opname_detail`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `stock_opname_detail` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `stock_opname_id` bigint(20) unsigned NOT NULL,
  `barang_id` bigint(20) unsigned NOT NULL,
  `batch_id` bigint(20) unsigned DEFAULT NULL,
  `qty_sistem` int(11) NOT NULL,
  `qty_fisik` int(11) NOT NULL,
  -- selisih dibuang: kolom generated, ditolak MySQL (#1901). Nilainya
  -- cukup dihitung saat dibaca (qty_fisik - qty_sistem).
  `catatan` varchar(255) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_sod_header` (`stock_opname_id`),
  KEY `idx_sod_barang` (`barang_id`),
  KEY `fk_sod_batch` (`batch_id`),
  CONSTRAINT `fk_sod_barang` FOREIGN KEY (`barang_id`) REFERENCES `barang` (`id`) ON UPDATE CASCADE,
  CONSTRAINT `fk_sod_batch` FOREIGN KEY (`batch_id`) REFERENCES `batch_barang` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_sod_header` FOREIGN KEY (`stock_opname_id`) REFERENCES `stock_opname` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `stok`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `stok` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `barang_id` bigint(20) unsigned NOT NULL,
  `qty_total` int(11) NOT NULL DEFAULT 0,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_stok_barang` (`barang_id`),
  CONSTRAINT `fk_stok_barang` FOREIGN KEY (`barang_id`) REFERENCES `barang` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=33 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `stok_supplier`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `stok_supplier` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `supplier_id` bigint(20) unsigned NOT NULL,
  `barang_id` bigint(20) unsigned NOT NULL,
  `lokasi_rak_id` bigint(20) unsigned DEFAULT NULL COMMENT 'rak tempat batch ini berada, di dalam gudang supplier',
  `nomor_batch` varchar(50) NOT NULL,
  `kondisi` enum('baru','bekas') NOT NULL DEFAULT 'baru' COMMENT 'baru = pembelian; bekas = donasi/second',
  `tanggal_expired` date DEFAULT NULL,
  `qty` int(11) NOT NULL DEFAULT 0,
  `harga` decimal(15,2) NOT NULL DEFAULT 0.00,
  `tanggal_masuk` date NOT NULL,
  `keterangan` varchar(255) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  -- exp_key dibuang: kolom generated, ditolak MySQL (#1901).
  -- Kolom generated STORED, pola yang sama dengan batch_barang.rak_key. NULL
  -- di dalam kunci unik tidak dianggap duplikat oleh MySQL, jadi tanpa
  -- coalesce() baris tanpa rak bisa menumpuk tanpa tertahan.
  -- rak_key dibuang: kolom generated, ditolak MySQL (#1901).
  PRIMARY KEY (`id`),
  -- rak_key ikut kunci: setelah rak ada, pemasukan batch yang sama tidak
  -- boleh diam-diam tergabung ke baris di rak lain (= stok berpindah tanpa
  -- ada yang memintanya). Satu batch boleh tersebar di beberapa rak.
  -- Memakai kolom ASLI, bukan kolom generated. Batasannya: pada kolom yang
  -- NULL, MySQL tidak menganggap dua baris sebagai duplikat — jadi
  -- pencegahan duplikat bertumpu pada pemeriksaan di aplikasi.
  UNIQUE KEY `uq_stoksup` (`supplier_id`,`barang_id`,`nomor_batch`,`kondisi`,`tanggal_expired`,`lokasi_rak_id`),
  KEY `idx_ss_supplier` (`supplier_id`),
  -- Tanpa FOREIGN KEY ke lokasi_rak, dan itu bukan kelalaian: MariaDB menolak
  -- FK pada kolom dasar dari kolom generated STORED (galat 138).
  -- batch_barang.lokasi_rak_id juga tidak punya FK karena alasan yang sama.
  KEY `ix_ss_rak` (`lokasi_rak_id`),
  KEY `fk_ss_barang` (`barang_id`),
  CONSTRAINT `fk_ss_barang` FOREIGN KEY (`barang_id`) REFERENCES `barang` (`id`) ON UPDATE CASCADE,
  CONSTRAINT `fk_ss_supplier` FOREIGN KEY (`supplier_id`) REFERENCES `supplier` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=7 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `supplier`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `supplier` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `kode` varchar(20) NOT NULL,
  `nama` varchar(150) NOT NULL,
  `telepon` varchar(30) DEFAULT NULL,
  `email` varchar(150) DEFAULT NULL,
  `alamat` varchar(255) DEFAULT NULL,
  `kontak_pic` varchar(100) DEFAULT NULL,
  `keterangan` varchar(255) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_supplier_kode` (`kode`)
) ENGINE=InnoDB AUTO_INCREMENT=6 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `toko`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `toko` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `kode` varchar(20) NOT NULL,
  `nama` varchar(150) NOT NULL,
  `marketplace` enum('shopee','tokopedia','tiktok','lazada','offline','lainnya') NOT NULL DEFAULT 'offline',
  `url_toko` varchar(255) DEFAULT NULL,
  `penanggung_jawab` varchar(150) DEFAULT NULL,
  `telepon` varchar(30) DEFAULT NULL,
  `alamat` varchar(255) DEFAULT NULL,
  `keterangan` varchar(255) DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_toko_kode` (`kode`),
  KEY `idx_toko_marketplace` (`marketplace`)
) ENGINE=InnoDB AUTO_INCREMENT=13 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `transaksi_keluar`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `transaksi_keluar` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `no_transaksi` varchar(30) NOT NULL,
  `tanggal` datetime NOT NULL,
  `user_id` bigint(20) unsigned NOT NULL,
  `penerima` varchar(150) NOT NULL,
  `untuk_bayi` varchar(150) DEFAULT NULL,
  `divisi_cabang` varchar(150) DEFAULT NULL,
  `catatan` varchar(255) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_tk_no` (`no_transaksi`),
  KEY `idx_tk_tanggal` (`tanggal`),
  KEY `fk_tk_user` (`user_id`),
  CONSTRAINT `fk_tk_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON UPDATE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=5 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `transaksi_keluar_detail`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `transaksi_keluar_detail` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `transaksi_keluar_id` bigint(20) unsigned NOT NULL,
  `barang_id` bigint(20) unsigned NOT NULL,
  `batch_id` bigint(20) unsigned DEFAULT NULL,
  `qty` int(11) NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_tkd_header` (`transaksi_keluar_id`),
  KEY `idx_tkd_barang` (`barang_id`),
  KEY `fk_tkd_batch` (`batch_id`),
  CONSTRAINT `fk_tkd_barang` FOREIGN KEY (`barang_id`) REFERENCES `barang` (`id`) ON UPDATE CASCADE,
  CONSTRAINT `fk_tkd_batch` FOREIGN KEY (`batch_id`) REFERENCES `batch_barang` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_tkd_header` FOREIGN KEY (`transaksi_keluar_id`) REFERENCES `transaksi_keluar` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=6 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `transaksi_masuk`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `transaksi_masuk` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `no_transaksi` varchar(30) NOT NULL,
  `tanggal` datetime NOT NULL,
  `supplier_id` bigint(20) unsigned DEFAULT NULL,
  `user_id` bigint(20) unsigned NOT NULL,
  `nota_foto` varchar(255) DEFAULT NULL,
  `total` decimal(15,2) NOT NULL DEFAULT 0.00,
  `catatan` varchar(255) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_tm_no` (`no_transaksi`),
  KEY `idx_tm_tanggal` (`tanggal`),
  KEY `idx_tm_supplier` (`supplier_id`),
  KEY `fk_tm_user` (`user_id`),
  CONSTRAINT `fk_tm_supplier` FOREIGN KEY (`supplier_id`) REFERENCES `supplier` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_tm_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON UPDATE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=6 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `transaksi_masuk_detail`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `transaksi_masuk_detail` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `transaksi_masuk_id` bigint(20) unsigned NOT NULL,
  `barang_id` bigint(20) unsigned NOT NULL,
  `batch_id` bigint(20) unsigned DEFAULT NULL,
  `nomor_batch` varchar(50) DEFAULT NULL,
  `tanggal_expired` date DEFAULT NULL,
  `lokasi_rak_id` bigint(20) unsigned DEFAULT NULL,
  `qty` int(11) NOT NULL,
  `harga` decimal(15,2) NOT NULL DEFAULT 0.00,
  `subtotal` decimal(15,2) NOT NULL DEFAULT 0.00,
  PRIMARY KEY (`id`),
  KEY `idx_tmd_header` (`transaksi_masuk_id`),
  KEY `idx_tmd_barang` (`barang_id`),
  KEY `fk_tmd_batch` (`batch_id`),
  CONSTRAINT `fk_tmd_barang` FOREIGN KEY (`barang_id`) REFERENCES `barang` (`id`) ON UPDATE CASCADE,
  CONSTRAINT `fk_tmd_batch` FOREIGN KEY (`batch_id`) REFERENCES `batch_barang` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_tmd_header` FOREIGN KEY (`transaksi_masuk_id`) REFERENCES `transaksi_masuk` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=8 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `user_toko`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `user_toko` (
  `user_id` bigint(20) unsigned NOT NULL,
  `toko_id` bigint(20) unsigned NOT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`user_id`,`toko_id`),
  KEY `idx_ut_toko` (`toko_id`),
  CONSTRAINT `fk_ut_toko` FOREIGN KEY (`toko_id`) REFERENCES `toko` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_ut_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `users`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `users` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `role_id` bigint(20) unsigned NOT NULL,
  `supplier_id` bigint(20) unsigned DEFAULT NULL,
  `gudang_id` bigint(20) unsigned DEFAULT NULL COMMENT 'gudang yang dipegang akun admin_gudang; NULL untuk peran lain',
  `nama` varchar(150) NOT NULL,
  `email` varchar(150) NOT NULL,
  `telepon` varchar(30) DEFAULT NULL,
  `password` varchar(255) NOT NULL,
  `foto` varchar(255) DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `idle_menit` int(10) unsigned NOT NULL DEFAULT 30 COMMENT 'menit menganggur sebelum sesi berakhir; 43200 = 30 hari',
  `last_login_at` timestamp NULL DEFAULT NULL,
  `remember_token` varchar(100) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_users_email` (`email`),
  KEY `idx_users_role` (`role_id`),
  KEY `idx_users_supplier` (`supplier_id`),
  KEY `ix_users_gudang` (`gudang_id`),
  CONSTRAINT `fk_users_role` FOREIGN KEY (`role_id`) REFERENCES `roles` (`id`) ON UPDATE CASCADE,
  CONSTRAINT `fk_users_supplier` FOREIGN KEY (`supplier_id`) REFERENCES `supplier` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  -- SET NULL, bukan CASCADE: gudang yang dihapus tidak boleh ikut menghapus
  -- akun petugasnya. Akunnya kehilangan lingkup, lalu Super Admin
  -- menugaskannya ulang.
  CONSTRAINT `fk_users_gudang` FOREIGN KEY (`gudang_id`) REFERENCES `gudang` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=41 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `v_penjualan_harian`;
/*!50001 DROP VIEW IF EXISTS `v_penjualan_harian`*/;
SET @saved_cs_client     = @@character_set_client;
SET character_set_client = utf8;
/*!50001 CREATE VIEW `v_penjualan_harian` AS SELECT
 1 AS `toko_id`,
  1 AS `nama_toko`,
  1 AS `marketplace`,
  1 AS `tanggal`,
  1 AS `jml_pesanan`,
  1 AS `jml_unit`,
  1 AS `omzet` */;
SET character_set_client = @saved_cs_client;
DROP TABLE IF EXISTS `v_penyaluran_penerima`;
/*!50001 DROP VIEW IF EXISTS `v_penyaluran_penerima`*/;
SET @saved_cs_client     = @@character_set_client;
SET character_set_client = utf8;
/*!50001 CREATE VIEW `v_penyaluran_penerima` AS SELECT
 1 AS `penerima_id`,
  1 AS `kode`,
  1 AS `nama`,
  1 AS `jenis`,
  1 AS `wilayah`,
  1 AS `jml_penyaluran`,
  1 AS `total_unit`,
  1 AS `total_nilai`,
  1 AS `terakhir_disalurkan` */;
SET character_set_client = @saved_cs_client;
DROP TABLE IF EXISTS `v_perangkat_aktif`;
/*!50001 DROP VIEW IF EXISTS `v_perangkat_aktif`*/;
SET @saved_cs_client     = @@character_set_client;
SET character_set_client = utf8;
/*!50001 CREATE VIEW `v_perangkat_aktif` AS SELECT
 1 AS `user_id`,
  1 AS `nama`,
  1 AS `email`,
  1 AS `peran`,
  1 AS `idle_menit`,
  1 AS `jml_perangkat`,
  1 AS `paling_baru` */;
SET character_set_client = @saved_cs_client;
DROP TABLE IF EXISTS `v_perpindahan_ringkas`;
/*!50001 DROP VIEW IF EXISTS `v_perpindahan_ringkas`*/;
SET @saved_cs_client     = @@character_set_client;
SET character_set_client = utf8;
/*!50001 CREATE VIEW `v_perpindahan_ringkas` AS SELECT
 1 AS `id`,
  1 AS `no_perpindahan`,
  1 AS `no_surat_jalan`,
  1 AS `jenis`,
  1 AS `tanggal`,
  1 AS `status`,
  1 AS `no_resi`,
  1 AS `tujuan_teks`,
  1 AS `penerima`,
  1 AS `asal_nama`,
  1 AS `tujuan_nama`,
  1 AS `total_qty`,
  1 AS `jml_item`,
  1 AS `pembuat`,
  1 AS `penyetuju` */;
SET character_set_client = @saved_cs_client;
DROP TABLE IF EXISTS `v_status_expired`;
/*!50001 DROP VIEW IF EXISTS `v_status_expired`*/;
SET @saved_cs_client     = @@character_set_client;
SET character_set_client = utf8;
/*!50001 CREATE VIEW `v_status_expired` AS SELECT
 1 AS `batch_id`,
  1 AS `barang_id`,
  1 AS `nama_barang`,
  1 AS `nomor_batch`,
  1 AS `tanggal_expired`,
  1 AS `qty_tersisa`,
  1 AS `sisa_hari`,
  1 AS `status_expired`,
  1 AS `lokasi_tipe`,
  1 AS `lokasi_nama` */;
SET character_set_client = @saved_cs_client;
DROP TABLE IF EXISTS `v_status_stok`;
/*!50001 DROP VIEW IF EXISTS `v_status_stok`*/;
SET @saved_cs_client     = @@character_set_client;
SET character_set_client = utf8;
/*!50001 CREATE VIEW `v_status_stok` AS SELECT
 1 AS `barang_id`,
  1 AS `kode_barang`,
  1 AS `nama_barang`,
  1 AS `kategori`,
  1 AS `stok`,
  1 AS `stok_minimum`,
  1 AS `status_stok` */;
SET character_set_client = @saved_cs_client;
DROP TABLE IF EXISTS `v_stok_kondisi`;
/*!50001 DROP VIEW IF EXISTS `v_stok_kondisi`*/;
SET @saved_cs_client     = @@character_set_client;
SET character_set_client = utf8;
/*!50001 CREATE VIEW `v_stok_kondisi` AS SELECT
 1 AS `barang_id`,
  1 AS `kode_barang`,
  1 AS `nama_barang`,
  1 AS `satuan`,
  1 AS `kondisi`,
  1 AS `lokasi_tipe`,
  1 AS `lokasi_nama`,
  1 AS `jml_batch`,
  1 AS `qty`,
  1 AS `nilai` */;
SET character_set_client = @saved_cs_client;
DROP TABLE IF EXISTS `v_stok_per_gudang`;
/*!50001 DROP VIEW IF EXISTS `v_stok_per_gudang`*/;
SET @saved_cs_client     = @@character_set_client;
SET character_set_client = utf8;
/*!50001 CREATE VIEW `v_stok_per_gudang` AS SELECT
 1 AS `gudang_id`,
  1 AS `nama_gudang`,
  1 AS `barang_id`,
  1 AS `kode_barang`,
  1 AS `nama_barang`,
  1 AS `satuan`,
  1 AS `qty` */;
SET character_set_client = @saved_cs_client;
DROP TABLE IF EXISTS `v_stok_toko`;
/*!50001 DROP VIEW IF EXISTS `v_stok_toko`*/;
SET @saved_cs_client     = @@character_set_client;
SET character_set_client = utf8;
/*!50001 CREATE VIEW `v_stok_toko` AS SELECT
 1 AS `toko_id`,
  1 AS `nama_toko`,
  1 AS `marketplace`,
  1 AS `barang_id`,
  1 AS `kode_barang`,
  1 AS `nama_barang`,
  1 AS `satuan`,
  1 AS `stok_minimum`,
  1 AS `qty`,
  1 AS `exp_terdekat` */;
SET character_set_client = @saved_cs_client;
/*!50001 DROP VIEW IF EXISTS `v_penjualan_harian`*/;
/*!50001 SET @saved_cs_client          = @@character_set_client */;
/*!50001 SET @saved_cs_results         = @@character_set_results */;
/*!50001 SET @saved_col_connection     = @@collation_connection */;
/*!50001 SET character_set_client      = utf8mb4 */;
/*!50001 SET character_set_results     = utf8mb4 */;
/*!50001 SET collation_connection      = utf8mb4_general_ci */;
/*!50001 CREATE OR REPLACE ALGORITHM=UNDEFINED */

/*!50001 VIEW `v_penjualan_harian` AS select `p`.`toko_id` AS `toko_id`,`t`.`nama` AS `nama_toko`,`t`.`marketplace` AS `marketplace`,cast(`p`.`tanggal` as date) AS `tanggal`,count(distinct `p`.`id`) AS `jml_pesanan`,coalesce(sum(`d`.`qty`),0) AS `jml_unit`,coalesce(sum(`d`.`subtotal`),0) AS `omzet` from ((`penjualan` `p` join `toko` `t` on(`t`.`id` = `p`.`toko_id`)) left join `penjualan_detail` `d` on(`d`.`penjualan_id` = `p`.`id`)) where `p`.`status` <> 'batal' group by `p`.`toko_id`,cast(`p`.`tanggal` as date) */;
/*!50001 SET character_set_client      = @saved_cs_client */;
/*!50001 SET character_set_results     = @saved_cs_results */;
/*!50001 SET collation_connection      = @saved_col_connection */;
/*!50001 DROP VIEW IF EXISTS `v_penyaluran_penerima`*/;
/*!50001 SET @saved_cs_client          = @@character_set_client */;
/*!50001 SET @saved_cs_results         = @@character_set_results */;
/*!50001 SET @saved_col_connection     = @@collation_connection */;
/*!50001 SET character_set_client      = utf8mb4 */;
/*!50001 SET character_set_results     = utf8mb4 */;
/*!50001 SET collation_connection      = utf8mb4_unicode_ci */;
/*!50001 CREATE OR REPLACE ALGORITHM=UNDEFINED */

/*!50001 VIEW `v_penyaluran_penerima` AS select `pn`.`id` AS `penerima_id`,`pn`.`kode` AS `kode`,`pn`.`nama` AS `nama`,`pn`.`jenis` AS `jenis`,`pn`.`wilayah` AS `wilayah`,count(distinct `p`.`id`) AS `jml_penyaluran`,coalesce(sum(`d`.`qty`),0) AS `total_unit`,coalesce(sum(`d`.`qty` * `b`.`harga`),0) AS `total_nilai`,max(`p`.`tanggal`) AS `terakhir_disalurkan` from (((`penerima` `pn` left join `perpindahan` `p` on(`p`.`tujuan_tipe` = 'penerima' and `p`.`tujuan_id` = `pn`.`id` and `p`.`status` in ('selesai','diterima'))) left join `perpindahan_detail` `d` on(`d`.`perpindahan_id` = `p`.`id`)) left join `barang` `b` on(`b`.`id` = `d`.`barang_id`)) where `pn`.`deleted_at` is null group by `pn`.`id` */;
/*!50001 SET character_set_client      = @saved_cs_client */;
/*!50001 SET character_set_results     = @saved_cs_results */;
/*!50001 SET collation_connection      = @saved_col_connection */;
/*!50001 DROP VIEW IF EXISTS `v_perangkat_aktif`*/;
/*!50001 SET @saved_cs_client          = @@character_set_client */;
/*!50001 SET @saved_cs_results         = @@character_set_results */;
/*!50001 SET @saved_col_connection     = @@collation_connection */;
/*!50001 SET character_set_client      = utf8mb4 */;
/*!50001 SET character_set_results     = utf8mb4 */;
/*!50001 SET collation_connection      = utf8mb4_unicode_ci */;
/*!50001 CREATE OR REPLACE ALGORITHM=UNDEFINED */

/*!50001 VIEW `v_perangkat_aktif` AS select `u`.`id` AS `user_id`,`u`.`nama` AS `nama`,`u`.`email` AS `email`,`r`.`label` AS `peran`,`u`.`idle_menit` AS `idle_menit`,count(`s`.`id`) AS `jml_perangkat`,max(`s`.`terakhir_at`) AS `paling_baru` from ((`users` `u` join `roles` `r` on(`r`.`id` = `u`.`role_id`)) left join `sesi_perangkat` `s` on(`s`.`user_id` = `u`.`id` and `s`.`diakhiri_at` is null and `s`.`terakhir_at` >= CURRENT_TIMESTAMP - interval `u`.`idle_menit` minute)) where `u`.`deleted_at` is null group by `u`.`id` */;
/*!50001 SET character_set_client      = @saved_cs_client */;
/*!50001 SET character_set_results     = @saved_cs_results */;
/*!50001 SET collation_connection      = @saved_col_connection */;
/*!50001 DROP VIEW IF EXISTS `v_perpindahan_ringkas`*/;
/*!50001 SET @saved_cs_client          = @@character_set_client */;
/*!50001 SET @saved_cs_results         = @@character_set_results */;
/*!50001 SET @saved_col_connection     = @@collation_connection */;
/*!50001 SET character_set_client      = utf8mb4 */;
/*!50001 SET character_set_results     = utf8mb4 */;
/*!50001 SET collation_connection      = utf8mb4_general_ci */;
/*!50001 CREATE OR REPLACE ALGORITHM=UNDEFINED */

/*!50001 VIEW `v_perpindahan_ringkas` AS select `p`.`id` AS `id`,`p`.`no_perpindahan` AS `no_perpindahan`,`p`.`no_surat_jalan` AS `no_surat_jalan`,`p`.`jenis` AS `jenis`,`p`.`tanggal` AS `tanggal`,`p`.`status` AS `status`,`p`.`no_resi` AS `no_resi`,`p`.`tujuan_teks` AS `tujuan_teks`,`p`.`penerima` AS `penerima`,case `p`.`asal_tipe` when 'supplier' then (select `supplier`.`nama` from `supplier` where `supplier`.`id` = `p`.`asal_id`) when 'gudang' then (select `gudang`.`nama` from `gudang` where `gudang`.`id` = `p`.`asal_id`) when 'rak' then (select `lokasi_rak`.`nama` from `lokasi_rak` where `lokasi_rak`.`id` = `p`.`asal_id`) else `p`.`asal_teks` end AS `asal_nama`,case `p`.`tujuan_tipe` when 'gudang' then (select `gudang`.`nama` from `gudang` where `gudang`.`id` = `p`.`tujuan_id`) when 'rak' then (select `lokasi_rak`.`nama` from `lokasi_rak` where `lokasi_rak`.`id` = `p`.`tujuan_id`) when 'supplier' then (select `supplier`.`nama` from `supplier` where `supplier`.`id` = `p`.`tujuan_id`) else `p`.`tujuan_teks` end AS `tujuan_nama`,(select coalesce(sum(`d`.`qty`),0) from `perpindahan_detail` `d` where `d`.`perpindahan_id` = `p`.`id`) AS `total_qty`,(select count(0) from `perpindahan_detail` `d` where `d`.`perpindahan_id` = `p`.`id`) AS `jml_item`,`u`.`nama` AS `pembuat`,`ua`.`nama` AS `penyetuju` from ((`perpindahan` `p` left join `users` `u` on(`u`.`id` = `p`.`user_id`)) left join `users` `ua` on(`ua`.`id` = `p`.`approved_by`)) */;
/*!50001 SET character_set_client      = @saved_cs_client */;
/*!50001 SET character_set_results     = @saved_cs_results */;
/*!50001 SET collation_connection      = @saved_col_connection */;
/*!50001 DROP VIEW IF EXISTS `v_status_expired`*/;
/*!50001 SET @saved_cs_client          = @@character_set_client */;
/*!50001 SET @saved_cs_results         = @@character_set_results */;
/*!50001 SET @saved_col_connection     = @@collation_connection */;
/*!50001 SET character_set_client      = utf8mb4 */;
/*!50001 SET character_set_results     = utf8mb4 */;
/*!50001 SET collation_connection      = utf8mb4_general_ci */;
/*!50001 CREATE OR REPLACE ALGORITHM=UNDEFINED */

/*!50001 VIEW `v_status_expired` AS select `bb`.`id` AS `batch_id`,`bb`.`barang_id` AS `barang_id`,`b`.`nama` AS `nama_barang`,`bb`.`nomor_batch` AS `nomor_batch`,`bb`.`tanggal_expired` AS `tanggal_expired`,`bb`.`qty_tersisa` AS `qty_tersisa`,to_days(`bb`.`tanggal_expired`) - to_days(curdate()) AS `sisa_hari`,case when `bb`.`tanggal_expired` is null then 'tanpa_expired' when to_days(`bb`.`tanggal_expired`) - to_days(curdate()) < 0 then 'expired' when to_days(`bb`.`tanggal_expired`) - to_days(curdate()) <= 30 then 'segera_expired' when to_days(`bb`.`tanggal_expired`) - to_days(curdate()) <= 90 then 'waspada' else 'aman' end AS `status_expired`,case when `bb`.`toko_id` is not null then 'toko' else 'gudang' end AS `lokasi_tipe`,coalesce(`t`.`nama`,concat(coalesce(`g`.`nama`,'—'),' › ',coalesce(`lr`.`nama`,'—'))) AS `lokasi_nama` from ((((`batch_barang` `bb` join `barang` `b` on(`b`.`id` = `bb`.`barang_id`)) left join `toko` `t` on(`t`.`id` = `bb`.`toko_id`)) left join `lokasi_rak` `lr` on(`lr`.`id` = `bb`.`lokasi_rak_id`)) left join `gudang` `g` on(`g`.`id` = `lr`.`gudang_id`)) where `bb`.`qty_tersisa` > 0 */;
/*!50001 SET character_set_client      = @saved_cs_client */;
/*!50001 SET character_set_results     = @saved_cs_results */;
/*!50001 SET collation_connection      = @saved_col_connection */;
/*!50001 DROP VIEW IF EXISTS `v_status_stok`*/;
/*!50001 SET @saved_cs_client          = @@character_set_client */;
/*!50001 SET @saved_cs_results         = @@character_set_results */;
/*!50001 SET @saved_col_connection     = @@collation_connection */;
/*!50001 SET character_set_client      = utf8mb4 */;
/*!50001 SET character_set_results     = utf8mb4 */;
/*!50001 SET collation_connection      = utf8mb4_general_ci */;
/*!50001 CREATE OR REPLACE ALGORITHM=UNDEFINED */

/*!50001 VIEW `v_status_stok` AS select `b`.`id` AS `barang_id`,`b`.`kode` AS `kode_barang`,`b`.`nama` AS `nama_barang`,`k`.`nama` AS `kategori`,coalesce(`s`.`qty_total`,0) AS `stok`,`b`.`stok_minimum` AS `stok_minimum`,case when coalesce(`s`.`qty_total`,0) = 0 then 'habis' when coalesce(`s`.`qty_total`,0) <= `b`.`stok_minimum` then 'hampir_habis' else 'aman' end AS `status_stok` from ((`barang` `b` left join `stok` `s` on(`s`.`barang_id` = `b`.`id`)) left join `kategori` `k` on(`k`.`id` = `b`.`kategori_id`)) where `b`.`deleted_at` is null */;
/*!50001 SET character_set_client      = @saved_cs_client */;
/*!50001 SET character_set_results     = @saved_cs_results */;
/*!50001 SET collation_connection      = @saved_col_connection */;
/*!50001 DROP VIEW IF EXISTS `v_stok_kondisi`*/;
/*!50001 SET @saved_cs_client          = @@character_set_client */;
/*!50001 SET @saved_cs_results         = @@character_set_results */;
/*!50001 SET @saved_col_connection     = @@collation_connection */;
/*!50001 SET character_set_client      = utf8mb4 */;
/*!50001 SET character_set_results     = utf8mb4 */;
/*!50001 SET collation_connection      = utf8mb4_unicode_ci */;
/*!50001 CREATE OR REPLACE ALGORITHM=UNDEFINED */

/*!50001 VIEW `v_stok_kondisi` AS select `bb`.`barang_id` AS `barang_id`,`b`.`kode` AS `kode_barang`,`b`.`nama` AS `nama_barang`,`b`.`satuan` AS `satuan`,`bb`.`kondisi` AS `kondisi`,case when `bb`.`toko_id` is not null then 'toko' else 'gudang' end AS `lokasi_tipe`,coalesce(`t`.`nama`,`g`.`nama`,'—') AS `lokasi_nama`,count(0) AS `jml_batch`,sum(`bb`.`qty_tersisa`) AS `qty`,sum(`bb`.`qty_tersisa` * `b`.`harga`) AS `nilai` from ((((`batch_barang` `bb` join `barang` `b` on(`b`.`id` = `bb`.`barang_id`)) left join `toko` `t` on(`t`.`id` = `bb`.`toko_id`)) left join `lokasi_rak` `lr` on(`lr`.`id` = `bb`.`lokasi_rak_id`)) left join `gudang` `g` on(`g`.`id` = `lr`.`gudang_id`)) where `bb`.`qty_tersisa` > 0 group by `bb`.`barang_id`,`bb`.`kondisi`,case when `bb`.`toko_id` is not null then 'toko' else 'gudang' end,coalesce(`t`.`nama`,`g`.`nama`,'—') */;
/*!50001 SET character_set_client      = @saved_cs_client */;
/*!50001 SET character_set_results     = @saved_cs_results */;
/*!50001 SET collation_connection      = @saved_col_connection */;
/*!50001 DROP VIEW IF EXISTS `v_stok_per_gudang`*/;
/*!50001 SET @saved_cs_client          = @@character_set_client */;
/*!50001 SET @saved_cs_results         = @@character_set_results */;
/*!50001 SET @saved_col_connection     = @@collation_connection */;
/*!50001 SET character_set_client      = utf8mb4 */;
/*!50001 SET character_set_results     = utf8mb4 */;
/*!50001 SET collation_connection      = utf8mb4_general_ci */;
/*!50001 CREATE OR REPLACE ALGORITHM=UNDEFINED */

/*!50001 VIEW `v_stok_per_gudang` AS select `g`.`id` AS `gudang_id`,`g`.`nama` AS `nama_gudang`,`b`.`id` AS `barang_id`,`b`.`kode` AS `kode_barang`,`b`.`nama` AS `nama_barang`,`b`.`satuan` AS `satuan`,coalesce(sum(`bb`.`qty_tersisa`),0) AS `qty` from (((`gudang` `g` join `lokasi_rak` `lr` on(`lr`.`gudang_id` = `g`.`id` and `lr`.`deleted_at` is null)) join `batch_barang` `bb` on(`bb`.`lokasi_rak_id` = `lr`.`id` and `bb`.`qty_tersisa` > 0)) join `barang` `b` on(`b`.`id` = `bb`.`barang_id` and `b`.`deleted_at` is null)) where `g`.`deleted_at` is null group by `g`.`id`,`b`.`id` */;
/*!50001 SET character_set_client      = @saved_cs_client */;
/*!50001 SET character_set_results     = @saved_cs_results */;
/*!50001 SET collation_connection      = @saved_col_connection */;
/*!50001 DROP VIEW IF EXISTS `v_stok_toko`*/;
/*!50001 SET @saved_cs_client          = @@character_set_client */;
/*!50001 SET @saved_cs_results         = @@character_set_results */;
/*!50001 SET @saved_col_connection     = @@collation_connection */;
/*!50001 SET character_set_client      = utf8mb4 */;
/*!50001 SET character_set_results     = utf8mb4 */;
/*!50001 SET collation_connection      = utf8mb4_general_ci */;
/*!50001 CREATE OR REPLACE ALGORITHM=UNDEFINED */

/*!50001 VIEW `v_stok_toko` AS select `t`.`id` AS `toko_id`,`t`.`nama` AS `nama_toko`,`t`.`marketplace` AS `marketplace`,`b`.`id` AS `barang_id`,`b`.`kode` AS `kode_barang`,`b`.`nama` AS `nama_barang`,`b`.`satuan` AS `satuan`,`b`.`stok_minimum` AS `stok_minimum`,coalesce(sum(`bb`.`qty_tersisa`),0) AS `qty`,min(`bb`.`tanggal_expired`) AS `exp_terdekat` from ((`toko` `t` join `batch_barang` `bb` on(`bb`.`toko_id` = `t`.`id` and `bb`.`qty_tersisa` > 0)) join `barang` `b` on(`b`.`id` = `bb`.`barang_id` and `b`.`deleted_at` is null)) where `t`.`deleted_at` is null group by `t`.`id`,`b`.`id` */;
/*!50001 SET character_set_client      = @saved_cs_client */;
/*!50001 SET character_set_results     = @saved_cs_results */;
/*!50001 SET collation_connection      = @saved_col_connection */;
/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */;

/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;
/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;
/*!40014 SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS */;
/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
/*!40111 SET SQL_NOTES=@OLD_SQL_NOTES */;



-- =====================================================================
--  BAGIAN 2 — DATA
-- ---------------------------------------------------------------------
--  roles WAJIB ada: tanpa baris peran, tidak ada yang bisa masuk.
--
--  Sisanya adalah master data & stok yang sudah terisi. Kalau Anda ingin
--  memasang sistem KOSONG dan mengisi sendiri dari nol, hapus seluruh
--  bagian ini KECUALI blok INSERT untuk `roles` dan `users` — keduanya
--  dibutuhkan supaya masih ada yang bisa masuk dan membuat data.
-- =====================================================================


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;
/*!40103 SET @OLD_TIME_ZONE=@@TIME_ZONE */;
/*!40103 SET TIME_ZONE='+00:00' */;
/*!40014 SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0 */;
/*!40101 SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO' */;
/*!40111 SET @OLD_SQL_NOTES=@@SQL_NOTES, SQL_NOTES=0 */;

/*!40000 ALTER TABLE `roles` DISABLE KEYS */;
INSERT INTO `roles` (`id`, `nama`, `label`, `keterangan`, `created_at`, `updated_at`) VALUES (1,'super_admin','Super Admin','Akses penuh seluruh sistem','2026-07-20 12:55:14','2026-07-20 12:55:14');
INSERT INTO `roles` (`id`, `nama`, `label`, `keterangan`, `created_at`, `updated_at`) VALUES (2,'admin_gudang','Admin Gudang','Input, scan, kelola stok, buat laporan','2026-07-20 12:55:14','2026-07-20 12:55:14');
INSERT INTO `roles` (`id`, `nama`, `label`, `keterangan`, `created_at`, `updated_at`) VALUES (3,'pimpinan','Pimpinan','Lihat dashboard, laporan, dan grafik','2026-07-20 12:55:14','2026-07-20 12:55:14');
INSERT INTO `roles` (`id`, `nama`, `label`, `keterangan`, `created_at`, `updated_at`) VALUES (4,'supplier','Supplier','Mitra pemasok — input rencana kiriman & nomor resi','2026-08-10 12:23:16','2026-08-10 12:23:16');
INSERT INTO `roles` (`id`, `nama`, `label`, `keterangan`, `created_at`, `updated_at`) VALUES (5,'toko','Admin Toko','Menerima barang dari gudang & mengelola penjualan toko','2026-08-10 13:15:57','2026-08-10 13:15:57');
/*!40000 ALTER TABLE `roles` ENABLE KEYS */;

/*!40000 ALTER TABLE `users` DISABLE KEYS */;
INSERT INTO `users` (`id`, `role_id`, `supplier_id`, `nama`, `email`, `telepon`, `password`, `foto`, `is_active`, `idle_menit`, `last_login_at`, `remember_token`, `created_at`, `updated_at`, `deleted_at`) VALUES (1,1,NULL,'Super Admin','admin@alfatihah.com','081200000001','$2y$10$/7gI4vRHBqzBkKl4drbhrOCLdfZCM3ZM5jirBPRKw/9E24ShPhWba',NULL,1,30,'2026-08-20 12:27:54',NULL,'2026-07-20 12:55:14','2026-08-20 12:27:54',NULL);
INSERT INTO `users` (`id`, `role_id`, `supplier_id`, `nama`, `email`, `telepon`, `password`, `foto`, `is_active`, `idle_menit`, `last_login_at`, `remember_token`, `created_at`, `updated_at`, `deleted_at`) VALUES (2,2,NULL,'Admin Gudang','gudang@alfatihah.com','081200000002','$2y$10$DBIZDS8x2WWVJf/jcaqkWu.sbPEZfwJdLpHSKfM0qtY/BttYfj8Gq',NULL,1,30,'2026-08-12 06:08:05',NULL,'2026-07-20 12:55:14','2026-08-12 06:13:11',NULL);
INSERT INTO `users` (`id`, `role_id`, `supplier_id`, `nama`, `email`, `telepon`, `password`, `foto`, `is_active`, `idle_menit`, `last_login_at`, `remember_token`, `created_at`, `updated_at`, `deleted_at`) VALUES (3,3,NULL,'Pimpinan Yayasan','pimpinan@alfatihah.com','081200000003','$2y$10$ouprWH1Yg3rZR4aAkydQfO9OUUGGioFRAJtxnAVQAsfq2ezC9GkXC',NULL,1,30,'2026-08-12 06:08:07',NULL,'2026-07-20 12:55:14','2026-08-12 06:13:19',NULL);
INSERT INTO `users` (`id`, `role_id`, `supplier_id`, `nama`, `email`, `telepon`, `password`, `foto`, `is_active`, `idle_menit`, `last_login_at`, `remember_token`, `created_at`, `updated_at`, `deleted_at`) VALUES (9,4,4,'Riki Ariyandi','rikiiiariyandii@gmail.com',NULL,'$2y$10$5TnwM58er55fyDj0v7uRqeS8jnImCXDOQVmoX2h1.Uqq3KKO3DDBe',NULL,1,30,'2026-08-12 06:08:59',NULL,'2026-08-12 06:08:49','2026-08-12 06:08:59',NULL);
INSERT INTO `users` (`id`, `role_id`, `supplier_id`, `nama`, `email`, `telepon`, `password`, `foto`, `is_active`, `idle_menit`, `last_login_at`, `remember_token`, `created_at`, `updated_at`, `deleted_at`) VALUES (10,5,NULL,'Toko','toko@alfatihah.com',NULL,'$2y$10$PGLSfmBe0larTOOtvhAyLe3vSHEev6N/yHpwElgwsyn.FejG.ouWq',NULL,1,30,'2026-08-20 11:45:07',NULL,'2026-08-12 06:13:52','2026-08-20 11:45:07',NULL);
/*!40000 ALTER TABLE `users` ENABLE KEYS */;

/*!40000 ALTER TABLE `kategori` DISABLE KEYS */;
INSERT INTO `kategori` (`id`, `kode`, `nama`, `ikon`, `keterangan`, `created_at`, `updated_at`, `deleted_at`) VALUES (12,'KAT-009','SUSU','🍼','Susu formula bayi; susu pertumbuhan/UHT anak; susu ibu hamil & menyusui; susu kental manis/UHT keluarga','2026-08-11 04:22:09','2026-08-11 04:22:09',NULL);
INSERT INTO `kategori` (`id`, `kode`, `nama`, `ikon`, `keterangan`, `created_at`, `updated_at`, `deleted_at`) VALUES (13,'KAT-010','PAMPERS','🧷','Popok kain; popok perekat; popok celana; popok dewasa; alas/perlak','2026-08-11 04:22:09','2026-08-11 04:22:09',NULL);
INSERT INTO `kategori` (`id`, `kode`, `nama`, `ikon`, `keterangan`, `created_at`, `updated_at`, `deleted_at`) VALUES (14,'KAT-011','SKINCARE','🧴','Sabun mandi bayi; shampoo; lotion/baby oil/cream; minyak telon; bedak; sunblock anak; cologne; diaper cream; cotton buds & tisu basah','2026-08-11 04:22:09','2026-08-11 04:22:09',NULL);
INSERT INTO `kategori` (`id`, `kode`, `nama`, `ikon`, `keterangan`, `created_at`, `updated_at`, `deleted_at`) VALUES (15,'KAT-012','OBAT JAMU','💊','Obat demam/batuk/pilek anak; vitamin & suplemen; minyak kayu putih; jamu tradisional bayi; P3K dasar; probiotik/prebiotik','2026-08-11 04:22:09','2026-08-11 04:22:09',NULL);
INSERT INTO `kategori` (`id`, `kode`, `nama`, `ikon`, `keterangan`, `created_at`, `updated_at`, `deleted_at`) VALUES (16,'KAT-013','MAKANAN','🥣','MPASI instan; bahan MPASI; snack/biskuit bayi & anak; minyak untuk masak; madu bayi','2026-08-11 04:22:09','2026-08-11 04:22:09',NULL);
INSERT INTO `kategori` (`id`, `kode`, `nama`, `ikon`, `keterangan`, `created_at`, `updated_at`, `deleted_at`) VALUES (17,'KAT-014','SEMBAKO','🌾','Beras, minyak goreng, gula, telur; mie instan, kecap, bumbu dapur','2026-08-11 04:22:09','2026-08-11 04:22:09',NULL);
INSERT INTO `kategori` (`id`, `kode`, `nama`, `ikon`, `keterangan`, `created_at`, `updated_at`, `deleted_at`) VALUES (18,'KAT-015','PERLENGKAPAN BAYI','🍶','Botol susu, dot, sterilizer; perlengkapan mandi; stroller, car seat, gendongan; bantal/kasur, kelambu; breast pump & botol ASI; peralatan makan; baby monitor','2026-08-11 04:22:09','2026-08-11 04:22:09',NULL);
INSERT INTO `kategori` (`id`, `kode`, `nama`, `ikon`, `keterangan`, `created_at`, `updated_at`, `deleted_at`) VALUES (19,'KAT-016','PAKAIAN','👕','Baju bayi/anak; kaos kaki, sarung tangan, topi; selimut, bedong; sepatu/sandal anak; underwear anak','2026-08-11 04:22:09','2026-08-11 04:22:09',NULL);
INSERT INTO `kategori` (`id`, `kode`, `nama`, `ikon`, `keterangan`, `created_at`, `updated_at`, `deleted_at`) VALUES (20,'KAT-017','MAINAN','🧩','Mainan edukasi; mainan motorik; boneka, mobil-mobilan; buku cerita/aktivitas; playmat/matras bermain','2026-08-11 04:22:09','2026-08-11 04:22:09',NULL);
INSERT INTO `kategori` (`id`, `kode`, `nama`, `ikon`, `keterangan`, `created_at`, `updated_at`, `deleted_at`) VALUES (21,'KAT-018','PEMBERSIH','🧼','Deterjen khusus bayi; sabun cuci botol; disinfektan/pembersih mainan; tisu basah; pewangi pakaian bayi','2026-08-11 04:22:09','2026-08-11 04:22:09',NULL);
INSERT INTO `kategori` (`id`, `kode`, `nama`, `ikon`, `keterangan`, `created_at`, `updated_at`, `deleted_at`) VALUES (22,'KAT-019','LAIN LAIN','📦','Merchandise/gimmick promosi; kemasan/packing; produk campuran tak jelas kategori; ATK/kebutuhan operasional toko','2026-08-11 04:22:09','2026-08-11 04:22:09',NULL);
/*!40000 ALTER TABLE `kategori` ENABLE KEYS */;

/*!40000 ALTER TABLE `barang` DISABLE KEYS */;
INSERT INTO `barang` (`id`, `kode`, `barcode`, `nama`, `kategori_id`, `merek`, `ukuran`, `satuan`, `foto`, `harga`, `stok_minimum`, `supplier_id`, `lokasi_rak_id`, `keterangan`, `is_active`, `created_at`, `updated_at`, `deleted_at`) VALUES (19,'BBY-0001','8993189271021','MamyPoko Pants',13,'MamyPoko','XL','set',NULL,49000.00,0,NULL,NULL,NULL,1,'2026-08-12 02:49:06','2026-08-12 02:49:06',NULL);
INSERT INTO `barang` (`id`, `kode`, `barcode`, `nama`, `kategori_id`, `merek`, `ukuran`, `satuan`, `foto`, `harga`, `stok_minimum`, `supplier_id`, `lokasi_rak_id`, `keterangan`, `is_active`, `created_at`, `updated_at`, `deleted_at`) VALUES (20,'BBY-0002','222B22072778','Sound',22,'OKey','100','box',NULL,10000.00,10,4,NULL,NULL,1,'2026-08-12 04:25:47','2026-08-12 04:25:47',NULL);
INSERT INTO `barang` (`id`, `kode`, `barcode`, `nama`, `kategori_id`, `merek`, `ukuran`, `satuan`, `foto`, `harga`, `stok_minimum`, `supplier_id`, `lokasi_rak_id`, `keterangan`, `is_active`, `created_at`, `updated_at`, `deleted_at`) VALUES (21,'BBY-0003','8990049007905','Mouse',20,'Olike','L','pcs',NULL,30000.00,10,4,NULL,NULL,1,'2026-08-12 04:26:46','2026-08-12 04:26:46',NULL);
/*!40000 ALTER TABLE `barang` ENABLE KEYS */;

/*!40000 ALTER TABLE `supplier` DISABLE KEYS */;
INSERT INTO `supplier` (`id`, `kode`, `nama`, `telepon`, `email`, `alamat`, `kontak_pic`, `keterangan`, `created_at`, `updated_at`, `deleted_at`) VALUES (4,'SUP-004','Rumah Anak Surga',NULL,NULL,NULL,NULL,'Supplier (sheet DASHBOARD UTAMA)','2026-08-11 04:22:10','2026-08-12 04:23:27',NULL);
INSERT INTO `supplier` (`id`, `kode`, `nama`, `telepon`, `email`, `alamat`, `kontak_pic`, `keterangan`, `created_at`, `updated_at`, `deleted_at`) VALUES (5,'SUP-005','Rumah Anak Alfatihah',NULL,NULL,NULL,NULL,'Supplier (sheet DASHBOARD UTAMA)','2026-08-11 04:22:10','2026-08-12 04:23:37',NULL);
/*!40000 ALTER TABLE `supplier` ENABLE KEYS */;

/*!40000 ALTER TABLE `gudang` DISABLE KEYS */;
INSERT INTO `gudang` (`id`, `kode`, `nama`, `jenis`, `alamat`, `penanggung_jawab`, `telepon`, `keterangan`, `is_active`, `created_at`, `updated_at`, `deleted_at`) VALUES (5,'GD-003','Gudang Depo','pusat',NULL,NULL,NULL,'Gudang distribusi barang (sheet DASHBOARD UTAMA)',1,'2026-08-11 04:22:10','2026-08-12 04:23:06',NULL);
INSERT INTO `gudang` (`id`, `kode`, `nama`, `jenis`, `alamat`, `penanggung_jawab`, `telepon`, `keterangan`, `is_active`, `created_at`, `updated_at`, `deleted_at`) VALUES (6,'GD-004','Gudang Ramah','pusat',NULL,NULL,NULL,'Gudang distribusi barang (sheet DASHBOARD UTAMA)',1,'2026-08-11 04:22:10','2026-08-20 11:37:40',NULL);
/*!40000 ALTER TABLE `gudang` ENABLE KEYS */;

/*!40000 ALTER TABLE `lokasi_rak` DISABLE KEYS */;
INSERT INTO `lokasi_rak` (`id`, `kode`, `nama`, `gudang_id`, `gudang`, `kapasitas`, `keterangan`, `created_at`, `updated_at`, `deleted_at`) VALUES (10,'RAK-001','RAK SUSU',5,'Gudang Depo',1000,NULL,'2026-08-12 03:44:44','2026-08-12 03:44:44',NULL);
INSERT INTO `lokasi_rak` (`id`, `kode`, `nama`, `gudang_id`, `gudang`, `kapasitas`, `keterangan`, `created_at`, `updated_at`, `deleted_at`) VALUES (12,'RAK-002','RAK SEMBAKO',6,'Gudang Ramah',NULL,NULL,'2026-08-12 06:39:51','2026-08-12 06:39:51',NULL);
/*!40000 ALTER TABLE `lokasi_rak` ENABLE KEYS */;

/*!40000 ALTER TABLE `toko` DISABLE KEYS */;
INSERT INTO `toko` (`id`, `kode`, `nama`, `marketplace`, `url_toko`, `penanggung_jawab`, `telepon`, `alamat`, `keterangan`, `is_active`, `created_at`, `updated_at`, `deleted_at`) VALUES (4,'TK-004','S LITTLE DINO','shopee',NULL,NULL,NULL,NULL,'Dari KELENGKAPAN DATA GUDANG.xlsx',1,'2026-08-11 04:22:10','2026-08-11 04:22:10',NULL);
INSERT INTO `toko` (`id`, `kode`, `nama`, `marketplace`, `url_toko`, `penanggung_jawab`, `telepon`, `alamat`, `keterangan`, `is_active`, `created_at`, `updated_at`, `deleted_at`) VALUES (5,'TK-005','S COCO BABY SHOP','shopee',NULL,NULL,NULL,NULL,'Dari KELENGKAPAN DATA GUDANG.xlsx',1,'2026-08-11 04:22:10','2026-08-11 04:22:10',NULL);
INSERT INTO `toko` (`id`, `kode`, `nama`, `marketplace`, `url_toko`, `penanggung_jawab`, `telepon`, `alamat`, `keterangan`, `is_active`, `created_at`, `updated_at`, `deleted_at`) VALUES (6,'TK-006','S BAEBOO BABY SHOP','shopee',NULL,NULL,NULL,NULL,'Dari KELENGKAPAN DATA GUDANG.xlsx',1,'2026-08-11 04:22:10','2026-08-11 04:22:10',NULL);
INSERT INTO `toko` (`id`, `kode`, `nama`, `marketplace`, `url_toko`, `penanggung_jawab`, `telepon`, `alamat`, `keterangan`, `is_active`, `created_at`, `updated_at`, `deleted_at`) VALUES (7,'TK-007','S BAYI MILENIAL','shopee',NULL,NULL,NULL,NULL,'Dari KELENGKAPAN DATA GUDANG.xlsx',1,'2026-08-11 04:22:10','2026-08-11 04:22:10',NULL);
INSERT INTO `toko` (`id`, `kode`, `nama`, `marketplace`, `url_toko`, `penanggung_jawab`, `telepon`, `alamat`, `keterangan`, `is_active`, `created_at`, `updated_at`, `deleted_at`) VALUES (8,'TK-008','S CICO BABY SHOP','shopee',NULL,NULL,NULL,NULL,'Dari KELENGKAPAN DATA GUDANG.xlsx',1,'2026-08-11 04:22:10','2026-08-11 04:22:10',NULL);
INSERT INTO `toko` (`id`, `kode`, `nama`, `marketplace`, `url_toko`, `penanggung_jawab`, `telepon`, `alamat`, `keterangan`, `is_active`, `created_at`, `updated_at`, `deleted_at`) VALUES (9,'TK-009','S BABYKIDS','shopee',NULL,NULL,NULL,NULL,'Dari KELENGKAPAN DATA GUDANG.xlsx',1,'2026-08-11 04:22:10','2026-08-11 04:22:10',NULL);
INSERT INTO `toko` (`id`, `kode`, `nama`, `marketplace`, `url_toko`, `penanggung_jawab`, `telepon`, `alamat`, `keterangan`, `is_active`, `created_at`, `updated_at`, `deleted_at`) VALUES (10,'TK-010','S STARKIDS','shopee',NULL,NULL,NULL,NULL,'Dari KELENGKAPAN DATA GUDANG.xlsx',1,'2026-08-11 04:22:10','2026-08-11 04:22:10',NULL);
INSERT INTO `toko` (`id`, `kode`, `nama`, `marketplace`, `url_toko`, `penanggung_jawab`, `telepon`, `alamat`, `keterangan`, `is_active`, `created_at`, `updated_at`, `deleted_at`) VALUES (11,'TK-011','S PELANGI SI KECIL','shopee',NULL,NULL,NULL,NULL,'Dari KELENGKAPAN DATA GUDANG.xlsx',1,'2026-08-11 04:22:10','2026-08-11 04:22:10',NULL);
INSERT INTO `toko` (`id`, `kode`, `nama`, `marketplace`, `url_toko`, `penanggung_jawab`, `telepon`, `alamat`, `keterangan`, `is_active`, `created_at`, `updated_at`, `deleted_at`) VALUES (12,'TK-012','T STARKIDS','tokopedia',NULL,NULL,NULL,NULL,'Dari KELENGKAPAN DATA GUDANG.xlsx',1,'2026-08-11 04:22:10','2026-08-11 04:22:10',NULL);
/*!40000 ALTER TABLE `toko` ENABLE KEYS */;

/*!40000 ALTER TABLE `user_toko` DISABLE KEYS */;
INSERT INTO `user_toko` (`user_id`, `toko_id`, `created_at`) VALUES (10,4,'2026-08-12 06:13:52');
INSERT INTO `user_toko` (`user_id`, `toko_id`, `created_at`) VALUES (10,5,'2026-08-12 06:13:52');
INSERT INTO `user_toko` (`user_id`, `toko_id`, `created_at`) VALUES (10,6,'2026-08-12 06:13:52');
INSERT INTO `user_toko` (`user_id`, `toko_id`, `created_at`) VALUES (10,7,'2026-08-12 06:13:52');
INSERT INTO `user_toko` (`user_id`, `toko_id`, `created_at`) VALUES (10,8,'2026-08-12 06:13:52');
INSERT INTO `user_toko` (`user_id`, `toko_id`, `created_at`) VALUES (10,9,'2026-08-12 06:13:52');
INSERT INTO `user_toko` (`user_id`, `toko_id`, `created_at`) VALUES (10,10,'2026-08-12 06:13:52');
INSERT INTO `user_toko` (`user_id`, `toko_id`, `created_at`) VALUES (10,11,'2026-08-12 06:13:52');
INSERT INTO `user_toko` (`user_id`, `toko_id`, `created_at`) VALUES (10,12,'2026-08-12 06:13:52');
/*!40000 ALTER TABLE `user_toko` ENABLE KEYS */;

/*!40000 ALTER TABLE `stok` DISABLE KEYS */;
INSERT INTO `stok` (`id`, `barang_id`, `qty_total`, `updated_at`) VALUES (19,19,0,'2026-08-12 04:33:16');
INSERT INTO `stok` (`id`, `barang_id`, `qty_total`, `updated_at`) VALUES (23,20,1,'2026-08-12 04:28:40');
INSERT INTO `stok` (`id`, `barang_id`, `qty_total`, `updated_at`) VALUES (24,21,85,'2026-08-20 11:39:58');
/*!40000 ALTER TABLE `stok` ENABLE KEYS */;

/*!40000 ALTER TABLE `batch_barang` DISABLE KEYS */;
INSERT INTO `batch_barang` (`id`, `barang_id`, `nomor_batch`, `kondisi`, `lokasi_rak_id`, `toko_id`, `qr_payload`, `tanggal_masuk`, `tanggal_expired`, `qty_awal`, `qty_tersisa`, `harga_beli`, `supplier_id`, `created_at`, `updated_at`) VALUES (32,21,'B260812','baru',10,NULL,NULL,'2026-08-12',NULL,85,85,30000.00,4,'2026-08-12 04:28:40','2026-08-12 07:32:14');
INSERT INTO `batch_barang` (`id`, `barang_id`, `nomor_batch`, `kondisi`, `lokasi_rak_id`, `toko_id`, `qr_payload`, `tanggal_masuk`, `tanggal_expired`, `qty_awal`, `qty_tersisa`, `harga_beli`, `supplier_id`, `created_at`, `updated_at`) VALUES (33,20,'B260812','baru',10,NULL,NULL,'2026-08-12',NULL,1,1,10000.00,NULL,'2026-08-12 04:28:40','2026-08-12 04:28:40');
/*!40000 ALTER TABLE `batch_barang` ENABLE KEYS */;

/*!40000 ALTER TABLE `stok_supplier` DISABLE KEYS */;
/*!40000 ALTER TABLE `stok_supplier` ENABLE KEYS */;

/*!40000 ALTER TABLE `transaksi_masuk` DISABLE KEYS */;
INSERT INTO `transaksi_masuk` (`id`, `no_transaksi`, `tanggal`, `supplier_id`, `user_id`, `nota_foto`, `total`, `catatan`, `created_at`, `updated_at`) VALUES (5,'TM-20260812-0002','2026-08-12 11:28:40',NULL,1,NULL,1900000.00,NULL,'2026-08-12 04:28:40','2026-08-12 04:28:40');
/*!40000 ALTER TABLE `transaksi_masuk` ENABLE KEYS */;

/*!40000 ALTER TABLE `transaksi_masuk_detail` DISABLE KEYS */;
INSERT INTO `transaksi_masuk_detail` (`id`, `transaksi_masuk_id`, `barang_id`, `batch_id`, `nomor_batch`, `tanggal_expired`, `lokasi_rak_id`, `qty`, `harga`, `subtotal`) VALUES (6,5,21,32,'B260812',NULL,10,63,30000.00,1890000.00);
INSERT INTO `transaksi_masuk_detail` (`id`, `transaksi_masuk_id`, `barang_id`, `batch_id`, `nomor_batch`, `tanggal_expired`, `lokasi_rak_id`, `qty`, `harga`, `subtotal`) VALUES (7,5,20,33,'B260812',NULL,10,1,10000.00,10000.00);
/*!40000 ALTER TABLE `transaksi_masuk_detail` ENABLE KEYS */;

/*!40000 ALTER TABLE `transaksi_keluar` DISABLE KEYS */;
INSERT INTO `transaksi_keluar` (`id`, `no_transaksi`, `tanggal`, `user_id`, `penerima`, `untuk_bayi`, `divisi_cabang`, `catatan`, `created_at`, `updated_at`) VALUES (4,'TK-20260812-0001','2026-08-12 11:29:16',1,'Riki',NULL,NULL,NULL,'2026-08-12 04:29:16','2026-08-12 04:29:16');
/*!40000 ALTER TABLE `transaksi_keluar` ENABLE KEYS */;

/*!40000 ALTER TABLE `transaksi_keluar_detail` DISABLE KEYS */;
INSERT INTO `transaksi_keluar_detail` (`id`, `transaksi_keluar_id`, `barang_id`, `batch_id`, `qty`) VALUES (5,4,21,32,20);
/*!40000 ALTER TABLE `transaksi_keluar_detail` ENABLE KEYS */;

/*!40000 ALTER TABLE `dokumen_counter` DISABLE KEYS */;
INSERT INTO `dokumen_counter` (`kunci`, `urut`, `updated_at`) VALUES ('penyesuaian_stok.no_penyesuaian.PS-20260820-',1,'2026-08-20 07:36:05');
INSERT INTO `dokumen_counter` (`kunci`, `urut`, `updated_at`) VALUES ('perpindahan.no_perpindahan.PP-20260820-',1,'2026-08-20 11:29:28');
INSERT INTO `dokumen_counter` (`kunci`, `urut`, `updated_at`) VALUES ('perpindahan.no_surat_jalan.SJ-20260820-',1,'2026-08-20 11:35:58');
/*!40000 ALTER TABLE `dokumen_counter` ENABLE KEYS */;

/*!40000 ALTER TABLE `penerima` DISABLE KEYS */;
/*!40000 ALTER TABLE `penerima` ENABLE KEYS */;

/*!40000 ALTER TABLE `perpindahan` DISABLE KEYS */;
/*!40000 ALTER TABLE `perpindahan` ENABLE KEYS */;

/*!40000 ALTER TABLE `perpindahan_detail` DISABLE KEYS */;
/*!40000 ALTER TABLE `perpindahan_detail` ENABLE KEYS */;

/*!40000 ALTER TABLE `pengiriman_supplier` DISABLE KEYS */;
/*!40000 ALTER TABLE `pengiriman_supplier` ENABLE KEYS */;

/*!40000 ALTER TABLE `pengiriman_supplier_detail` DISABLE KEYS */;
/*!40000 ALTER TABLE `pengiriman_supplier_detail` ENABLE KEYS */;

/*!40000 ALTER TABLE `penjualan` DISABLE KEYS */;
/*!40000 ALTER TABLE `penjualan` ENABLE KEYS */;

/*!40000 ALTER TABLE `penjualan_detail` DISABLE KEYS */;
/*!40000 ALTER TABLE `penjualan_detail` ENABLE KEYS */;

/*!40000 ALTER TABLE `penyesuaian_stok` DISABLE KEYS */;
/*!40000 ALTER TABLE `penyesuaian_stok` ENABLE KEYS */;

/*!40000 ALTER TABLE `retur` DISABLE KEYS */;
/*!40000 ALTER TABLE `retur` ENABLE KEYS */;

/*!40000 ALTER TABLE `mutasi_barang` DISABLE KEYS */;
/*!40000 ALTER TABLE `mutasi_barang` ENABLE KEYS */;

/*!40000 ALTER TABLE `notifikasi` DISABLE KEYS */;
/*!40000 ALTER TABLE `notifikasi` ENABLE KEYS */;

/*!40000 ALTER TABLE `marketplace_akun` DISABLE KEYS */;
/*!40000 ALTER TABLE `marketplace_akun` ENABLE KEYS */;

/*!40000 ALTER TABLE `marketplace_sku` DISABLE KEYS */;
/*!40000 ALTER TABLE `marketplace_sku` ENABLE KEYS */;

/*!40000 ALTER TABLE `marketplace_sync_log` DISABLE KEYS */;
/*!40000 ALTER TABLE `marketplace_sync_log` ENABLE KEYS */;

/*!40000 ALTER TABLE `stock_opname` DISABLE KEYS */;
/*!40000 ALTER TABLE `stock_opname` ENABLE KEYS */;

/*!40000 ALTER TABLE `stock_opname_detail` DISABLE KEYS */;
/*!40000 ALTER TABLE `stock_opname_detail` ENABLE KEYS */;
/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */;

/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;
/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;
/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
/*!40111 SET SQL_NOTES=@OLD_SQL_NOTES */;



-- =====================================================================
-- LINGKUP GUDANG PER PETUGAS
-- =====================================================================
-- Tanpa DEFINER, dan SQL SECURITY INVOKER: view yang menyebut
-- DEFINER=root@localhost akan gagal diimpor di hosting bersama, karena
-- pengguna basis datanya bukan root dan tidak boleh mengaku sebagai root.
DROP VIEW IF EXISTS `v_petugas_gudang`;
CREATE SQL SECURITY INVOKER VIEW `v_petugas_gudang` AS
SELECT `g`.`id`    AS `gudang_id`,
       `g`.`kode`  AS `kode_gudang`,
       `g`.`nama`  AS `nama_gudang`,
       `g`.`jenis` AS `jenis`,
       COUNT(`u`.`id`) AS `jml_petugas`,
       GROUP_CONCAT(`u`.`nama` ORDER BY `u`.`nama` ASC SEPARATOR ', ') AS `petugas`
FROM `gudang` `g`
LEFT JOIN `users` `u`
       ON `u`.`gudang_id` = `g`.`id`
      AND `u`.`deleted_at` IS NULL
      AND `u`.`is_active` = 1
WHERE `g`.`deleted_at` IS NULL
GROUP BY `g`.`id`;

-- Akun Admin Gudang yang belum punya gudang akan melihat semua daftar
-- kosong — halaman yang benar, tetapi terlihat seperti rusak. Jadi setiap
-- akun langsung ditugaskan; gudang pusat lebih diutamakan.
UPDATE `users` `u`
   SET `u`.`gudang_id` = (
        SELECT `g`.`id` FROM `gudang` `g`
        WHERE `g`.`deleted_at` IS NULL AND `g`.`is_active` = 1 AND `g`.`jenis` = 'pusat'
        ORDER BY `g`.`kode` ASC
        LIMIT 1)
 WHERE `u`.`gudang_id` IS NULL
   AND `u`.`deleted_at` IS NULL
   AND `u`.`role_id` = (SELECT `id` FROM `roles` WHERE `nama` = 'admin_gudang');

-- =====================================================================
-- GUDANG & RAK UNTUK SETIAP SUPPLIER
-- =====================================================================
-- Satu gudang per supplier, BUKAN gudang bersama: supplier adalah pihak luar
-- yang berbeda-beda, dan menaruh stok mereka di satu gudang berarti mereka
-- saling melihat isi gudang satu sama lain.
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

-- Stok tersimpan per rak. Gudang tanpa rak tampak sudah jadi padahal barang
-- tidak bisa masuk ke mana pun.
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

-- Baris stok supplier yang belum punya rak diberi rak bawaan gudangnya.
-- Baris tanpa rak hilang dari laporan yang bertolak dari lokasi.
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


-- =====================================================================
-- PENGATURAN APLIKASI (logo, favicon, gambar share)
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


-- Kolom & indeks marketplace TIDAK ditambahkan lewat ALTER di sini.
-- Semuanya sudah menjadi bagian CREATE TABLE `penjualan` dan
-- `marketplace_akun` di atas. Pemasang ini membuat setiap tabel dari
-- nol, jadi ALTER sesudahnya hanya pekerjaan yang tidak perlu ada —
-- dan bentuk "ADD COLUMN IF NOT EXISTS" yang dipakai sebelumnya
-- adalah sintaks khusus MariaDB yang membuat berkas ini GAGAL TOTAL
-- di MySQL.
-- Tabel marketplace_tarikan tetap dibuat di bawah.

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

SET FOREIGN_KEY_CHECKS = 1;

-- Ringkasan supaya langsung terlihat apakah impornya utuh.
SELECT 'Database AMANAH siap dipakai.' AS status,
       (SELECT COUNT(*) FROM roles)  AS peran,
       (SELECT COUNT(*) FROM users)  AS pengguna,
       (SELECT COUNT(*) FROM barang) AS produk,
       (SELECT COUNT(*) FROM toko)   AS toko;

-- JANGAN LUPA: ganti semua password lewat Pengaturan > Pengguna.

-- Titik transit surat jalan (6 Okt 2026) — lihat db/migrasi_titik_transit.sql
CREATE TABLE IF NOT EXISTS perpindahan_titik (
  id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  perpindahan_id BIGINT UNSIGNED NOT NULL,
  urutan         TINYINT UNSIGNED NOT NULL DEFAULT 1,
  lokasi_tipe    ENUM('gudang','toko') NOT NULL DEFAULT 'gudang',
  lokasi_id      BIGINT UNSIGNED NOT NULL,
  tiba_at        DATETIME NULL,
  tiba_oleh      BIGINT UNSIGNED NULL,
  berangkat_at   DATETIME NULL,
  berangkat_oleh BIGINT UNSIGNED NULL,
  catatan        VARCHAR(255) NULL,
  kendaraan      VARCHAR(60) NULL,
  no_polisi      VARCHAR(20) NULL,
  pengemudi      VARCHAR(100) NULL,
  created_at     TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_titik_pp (perpindahan_id, urutan),
  KEY idx_titik_lokasi (lokasi_tipe, lokasi_id),
  CONSTRAINT fk_titik_pp FOREIGN KEY (perpindahan_id) REFERENCES perpindahan (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Jejak mutasi stok per dokumen perpindahan (6 Okt 2026) — lihat db/migrasi_mutasi_perpindahan.sql
CREATE TABLE IF NOT EXISTS perpindahan_mutasi (
  id               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  perpindahan_id   BIGINT UNSIGNED NOT NULL,
  detail_id        BIGINT UNSIGNED NULL,
  barang_id        BIGINT UNSIGNED NOT NULL,
  batch_asal_id    BIGINT UNSIGNED NULL,
  batch_tujuan_id  BIGINT UNSIGNED NULL,
  qty              INT NOT NULL,
  created_at       TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_mutasi_pp (perpindahan_id),
  CONSTRAINT fk_mutasi_pp FOREIGN KEY (perpindahan_id) REFERENCES perpindahan (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
