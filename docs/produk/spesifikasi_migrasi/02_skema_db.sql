/*M!999999\- enable the sandbox mode */ 

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
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
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
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_log_user` (`user_id`),
  KEY `idx_log_modul` (`modul`),
  KEY `idx_log_created` (`created_at`),
  CONSTRAINT `fk_log_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
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
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_barang_kode` (`kode`),
  KEY `idx_barang_kategori` (`kategori_id`),
  KEY `idx_barang_supplier` (`supplier_id`),
  KEY `idx_barang_rak` (`lokasi_rak_id`),
  KEY `idx_barang_nama` (`nama`),
  KEY `ix_barang_deleted` (`deleted_at`),
  CONSTRAINT `fk_barang_kategori` FOREIGN KEY (`kategori_id`) REFERENCES `kategori` (`id`) ON UPDATE CASCADE,
  CONSTRAINT `fk_barang_rak` FOREIGN KEY (`lokasi_rak_id`) REFERENCES `lokasi_rak` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_barang_supplier` FOREIGN KEY (`supplier_id`) REFERENCES `supplier` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `batch_barang` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `barang_id` bigint(20) unsigned NOT NULL,
  `nomor_batch` varchar(50) NOT NULL,
  `kondisi` enum('baru','bekas_layak','bekas_tidak_layak','rusak','expired') NOT NULL DEFAULT 'baru',
  `lokasi_rak_id` bigint(20) unsigned DEFAULT NULL,
  `toko_id` bigint(20) unsigned DEFAULT NULL,
  `kardus_id` bigint(20) unsigned DEFAULT NULL,
  `qr_payload` varchar(255) DEFAULT NULL,
  `tanggal_masuk` date NOT NULL,
  `tanggal_expired` date DEFAULT NULL,
  `qty_awal` int(11) NOT NULL DEFAULT 0,
  `qty_tersisa` int(11) NOT NULL DEFAULT 0,
  `harga_beli` decimal(15,2) NOT NULL DEFAULT 0.00,
  `supplier_id` bigint(20) unsigned DEFAULT NULL,
  `donatur_id` bigint(20) unsigned DEFAULT NULL COMMENT 'diwarisi dari stok supplier saat barang diterima gudang',
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `sumber` enum('pembelian','donasi') NOT NULL DEFAULT 'pembelian' COMMENT 'asal barang; terpisah dari keadaan fisik di kolom kondisi',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_batch_lokasi` (`barang_id`,`nomor_batch`,`kondisi`,`sumber`,`donatur_id`,`lokasi_rak_id`,`toko_id`,`tanggal_expired`,`kardus_id`),
  KEY `idx_batch_barang` (`barang_id`),
  KEY `idx_batch_expired` (`tanggal_expired`),
  KEY `idx_batch_rak` (`lokasi_rak_id`),
  KEY `fk_batch_supplier` (`supplier_id`),
  KEY `idx_batch_toko` (`toko_id`),
  KEY `ix_batch_kondisi` (`kondisi`,`qty_tersisa`),
  KEY `ix_batch_donatur` (`donatur_id`),
  KEY `ix_batch_lokasi_sisa` (`lokasi_rak_id`,`qty_tersisa`),
  KEY `ix_batch_toko_sisa` (`toko_id`,`qty_tersisa`),
  KEY `ix_batch_sumber` (`sumber`),
  KEY `idx_bb_kardus` (`kardus_id`),
  CONSTRAINT `fk_batch_barang` FOREIGN KEY (`barang_id`) REFERENCES `barang` (`id`) ON UPDATE CASCADE,
  CONSTRAINT `fk_batch_donatur` FOREIGN KEY (`donatur_id`) REFERENCES `donatur` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_batch_rak` FOREIGN KEY (`lokasi_rak_id`) REFERENCES `lokasi_rak` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_batch_supplier` FOREIGN KEY (`supplier_id`) REFERENCES `supplier` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_batch_toko` FOREIGN KEY (`toko_id`) REFERENCES `toko` (`id`) ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `dokumen_counter` (
  `kunci` varchar(120) NOT NULL,
  `urut` bigint(20) unsigned NOT NULL DEFAULT 0,
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`kunci`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `donasi` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `no_donasi` varchar(40) NOT NULL,
  `donatur_id` bigint(20) unsigned NOT NULL,
  `supplier_id` bigint(20) unsigned DEFAULT NULL COMMENT 'mitra yang menerima titipan ini',
  `tanggal` date NOT NULL,
  `jenis` enum('umum','kunjungan','paket') NOT NULL DEFAULT 'umum' COMMENT 'bentuk pemberian; berbeda dari cara_terima yang menjawab bagaimana barangnya sampai',
  `cara_terima` enum('diantar','dijemput','dikirim') NOT NULL DEFAULT 'diantar',
  `ekspedisi` varchar(60) DEFAULT NULL COMMENT 'Nama kurir/ekspedisi: JNE, J&T, GoSend, Grab, dsb. Kosong bila diantar/dijemput',
  `no_resi` varchar(60) DEFAULT NULL COMMENT 'Nomor resi paket. Kosong bila donasi tidak dikirim lewat ekspedisi',
  `catatan` varchar(500) DEFAULT NULL,
  `dibuat_oleh` bigint(20) unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_donasi_no` (`no_donasi`),
  KEY `ix_donasi_donatur` (`donatur_id`),
  KEY `ix_donasi_tanggal` (`tanggal`),
  KEY `ix_donasi_supplier` (`supplier_id`),
  KEY `fk_donasi_user` (`dibuat_oleh`),
  KEY `idx_donasi_cara_tanggal` (`cara_terima`,`tanggal`),
  KEY `idx_donasi_resi` (`no_resi`),
  KEY `ix_donasi_jenis` (`jenis`),
  CONSTRAINT `fk_donasi_donatur` FOREIGN KEY (`donatur_id`) REFERENCES `donatur` (`id`) ON UPDATE CASCADE,
  CONSTRAINT `fk_donasi_supplier` FOREIGN KEY (`supplier_id`) REFERENCES `supplier` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_donasi_user` FOREIGN KEY (`dibuat_oleh`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `donasi_detail` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `donasi_id` bigint(20) unsigned NOT NULL,
  `barang_id` bigint(20) unsigned DEFAULT NULL,
  `nama_barang_teks` varchar(150) DEFAULT NULL COMMENT 'dipakai bila barangnya belum ada di katalog',
  `kondisi` enum('baru','bekas_layak','bekas_tidak_layak','rusak','expired') NOT NULL DEFAULT 'baru',
  `nomor_batch` varchar(50) DEFAULT NULL,
  `tanggal_expired` date DEFAULT NULL,
  `qty` int(11) NOT NULL DEFAULT 0,
  `nilai_taksir` decimal(15,2) NOT NULL DEFAULT 0.00 COMMENT 'taksiran internal untuk pencatatan; BUKAN nilai untuk keperluan pajak',
  `keterangan` varchar(255) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `ix_dd_donasi` (`donasi_id`),
  KEY `ix_dd_barang` (`barang_id`),
  KEY `ix_dd_kondisi` (`kondisi`),
  CONSTRAINT `fk_dd_barang` FOREIGN KEY (`barang_id`) REFERENCES `barang` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_dd_donasi` FOREIGN KEY (`donasi_id`) REFERENCES `donasi` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `donatur` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `kode` varchar(20) NOT NULL,
  `nama` varchar(150) NOT NULL,
  `jenis` enum('pribadi','komunitas','lembaga') NOT NULL DEFAULT 'pribadi',
  `supplier_id` bigint(20) unsigned DEFAULT NULL,
  `telepon` varchar(30) DEFAULT NULL COMMENT 'data pribadi; disamarkan bagi yang tidak berkepentingan',
  `email` varchar(150) DEFAULT NULL COMMENT 'data pribadi',
  `wilayah` varchar(100) DEFAULT NULL COMMENT 'kota atau kecamatan; dipakai laporan yang disamarkan',
  `alamat` varchar(255) DEFAULT NULL COMMENT 'data pribadi',
  `kontak_pic` varchar(150) DEFAULT NULL COMMENT 'penanggung jawab, untuk komunitas dan lembaga',
  `jml_anggota` int(10) unsigned DEFAULT NULL COMMENT 'perkiraan anggota komunitas',
  `anonim` tinyint(1) NOT NULL DEFAULT 0 COMMENT 'donatur minta tidak disebut namanya; wajib dihormati di semua tampilan',
  `keterangan` varchar(255) DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp(),
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_donatur_kode` (`kode`),
  KEY `ix_donatur_nama` (`nama`),
  KEY `ix_donatur_jenis` (`jenis`),
  KEY `ix_donatur_wilayah` (`wilayah`),
  KEY `ix_donatur_supplier` (`supplier_id`),
  KEY `ix_donatur_deleted` (`deleted_at`),
  CONSTRAINT `fk_donatur_supplier` FOREIGN KEY (`supplier_id`) REFERENCES `supplier` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `galat_sistem` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `kode` char(8) NOT NULL,
  `konteks` varchar(100) DEFAULT NULL,
  `pesan` text DEFAULT NULL,
  `berkas` varchar(255) DEFAULT NULL,
  `halaman` varchar(255) DEFAULT NULL,
  `user_id` bigint(20) unsigned DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `ix_galat_kode` (`kode`),
  KEY `ix_galat_waktu` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
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
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_gudang_kode` (`kode`),
  KEY `ix_gudang_jenis` (`jenis`),
  KEY `ix_gudang_supplier` (`supplier_id`),
  KEY `ix_gudang_deleted` (`deleted_at`),
  CONSTRAINT `fk_gudang_supplier` FOREIGN KEY (`supplier_id`) REFERENCES `supplier` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `kardus` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `kode` varchar(30) NOT NULL,
  `nama` varchar(150) DEFAULT NULL,
  `gudang_id` bigint(20) unsigned NOT NULL,
  `lokasi_rak_id` bigint(20) unsigned DEFAULT NULL,
  `tanggal_kemas` date NOT NULL,
  `petugas_id` bigint(20) unsigned DEFAULT NULL,
  `berat_kg` decimal(8,2) DEFAULT NULL,
  `panjang_cm` decimal(7,1) DEFAULT NULL,
  `lebar_cm` decimal(7,1) DEFAULT NULL,
  `tinggi_cm` decimal(7,1) DEFAULT NULL,
  `catatan` varchar(500) DEFAULT NULL,
  `status` enum('draft','dikemas','siap_dikirim','dalam_perjalanan','diterima','dibatalkan') NOT NULL DEFAULT 'draft',
  `kondisi` enum('baik','rusak','hilang','perlu_pemeriksaan') NOT NULL DEFAULT 'baik',
  `perpindahan_id` bigint(20) unsigned DEFAULT NULL,
  `no_resi` varchar(60) DEFAULT NULL,
  `ditutup_at` datetime DEFAULT NULL,
  `diterima_at` datetime DEFAULT NULL,
  `diterima_oleh` bigint(20) unsigned DEFAULT NULL,
  `catatan_terima` varchar(500) DEFAULT NULL,
  `foto_terima` varchar(255) DEFAULT NULL,
  `alasan_batal` varchar(255) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_kardus_kode` (`kode`),
  KEY `idx_kardus_status` (`status`),
  KEY `idx_kardus_gudang` (`gudang_id`),
  KEY `idx_kardus_pp` (`perpindahan_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `kardus_isi` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `kardus_id` bigint(20) unsigned NOT NULL,
  `barang_id` bigint(20) unsigned NOT NULL,
  `batch_id` bigint(20) unsigned DEFAULT NULL COMMENT 'batch lepas sumber (dipilih saat mengemas)',
  `batch_kardus_id` bigint(20) unsigned DEFAULT NULL COMMENT 'batch bertanda kardus_id sesudah ditutup',
  `qty` int(11) NOT NULL,
  `qty_diterima` int(11) DEFAULT NULL,
  `catatan` varchar(255) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_kisi_kardus` (`kardus_id`),
  KEY `idx_kisi_barang` (`barang_id`),
  CONSTRAINT `fk_kisi_kardus` FOREIGN KEY (`kardus_id`) REFERENCES `kardus` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `kardus_riwayat` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `kardus_id` bigint(20) unsigned NOT NULL,
  `aksi` varchar(30) NOT NULL,
  `keterangan` varchar(500) DEFAULT NULL,
  `user_id` bigint(20) unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_kriw_kardus` (`kardus_id`),
  CONSTRAINT `fk_kriw_kardus` FOREIGN KEY (`kardus_id`) REFERENCES `kardus` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `kategori` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `kode` varchar(20) NOT NULL,
  `nama` varchar(100) NOT NULL,
  `ikon` varchar(50) DEFAULT NULL,
  `keterangan` varchar(255) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_kategori_kode` (`kode`),
  KEY `ix_kategori_deleted` (`deleted_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `login_gagal` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `email` varchar(150) NOT NULL,
  `ip` varchar(45) NOT NULL,
  `sumber` enum('web','api') NOT NULL DEFAULT 'web',
  `waktu` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `ix_gagal_email` (`email`,`waktu`),
  KEY `ix_gagal_ip` (`ip`,`waktu`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `lokasi_rak` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `kode` varchar(20) NOT NULL,
  `nama` varchar(100) NOT NULL,
  `gudang_id` bigint(20) unsigned DEFAULT NULL,
  `gudang` varchar(100) NOT NULL DEFAULT 'Gudang Utama',
  `kapasitas` int(10) unsigned DEFAULT NULL,
  `keterangan` varchar(255) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_rak_kode` (`kode`),
  KEY `idx_rak_gudang` (`gudang_id`),
  KEY `ix_rak_deleted` (`deleted_at`),
  CONSTRAINT `fk_rak_gudang` FOREIGN KEY (`gudang_id`) REFERENCES `gudang` (`id`) ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
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
  `nama_shop` varchar(150) DEFAULT NULL COMMENT 'nama toko sebagaimana tercatat di marketplace',
  `terakhir_tarik_at` timestamp NULL DEFAULT NULL,
  `terakhir_segar_at` timestamp NULL DEFAULT NULL COMMENT 'kapan access_token terakhir diperbarui',
  `aktif` tinyint(1) NOT NULL DEFAULT 1 COMMENT 'penarikan otomatis dijalankan atau tidak',
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_mpakun_toko` (`toko_id`),
  KEY `ix_mpakun_tarik` (`aktif`,`terakhir_tarik_at`),
  CONSTRAINT `fk_mp_toko` FOREIGN KEY (`toko_id`) REFERENCES `toko` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `marketplace_sku` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `toko_id` bigint(20) unsigned NOT NULL,
  `barang_id` bigint(20) unsigned NOT NULL,
  `sku_marketplace` varchar(80) NOT NULL,
  `nama_di_marketplace` varchar(200) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_mps` (`toko_id`,`sku_marketplace`),
  KEY `idx_mps_barang` (`barang_id`),
  CONSTRAINT `fk_mps_barang` FOREIGN KEY (`barang_id`) REFERENCES `barang` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_mps_toko` FOREIGN KEY (`toko_id`) REFERENCES `toko` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
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
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_sync_toko` (`toko_id`),
  KEY `idx_sync_created` (`created_at`),
  KEY `fk_sync_user` (`user_id`),
  CONSTRAINT `fk_sync_toko` FOREIGN KEY (`toko_id`) REFERENCES `toko` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_sync_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `marketplace_tarikan` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `toko_id` bigint(20) unsigned NOT NULL,
  `platform` varchar(30) NOT NULL,
  `no_pesanan` varchar(80) DEFAULT NULL,
  `endpoint` varchar(120) NOT NULL COMMENT 'jalur API yang dipanggil',
  `kode_http` smallint(6) DEFAULT NULL,
  `sukses` tinyint(1) NOT NULL DEFAULT 0,
  `pesan` varchar(500) DEFAULT NULL,
  `data_mentah` mediumtext DEFAULT NULL COMMENT 'balasan API apa adanya',
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `ix_tarikan_toko` (`toko_id`,`created_at`),
  KEY `ix_tarikan_pesanan` (`no_pesanan`),
  CONSTRAINT `fk_tarikan_toko` FOREIGN KEY (`toko_id`) REFERENCES `toko` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `migrasi_terpasang` (
  `nama` varchar(120) NOT NULL,
  `dijalankan_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `oleh` bigint(20) unsigned DEFAULT NULL,
  `catatan` varchar(255) DEFAULT NULL,
  PRIMARY KEY (`nama`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
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
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
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
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
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
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_notif_tipe` (`tipe`),
  KEY `idx_notif_read` (`is_read`),
  KEY `fk_notif_barang` (`barang_id`),
  KEY `fk_notif_batch` (`batch_id`),
  CONSTRAINT `fk_notif_barang` FOREIGN KEY (`barang_id`) REFERENCES `barang` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_notif_batch` FOREIGN KEY (`batch_id`) REFERENCES `batch_barang` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `pelepasan_stok` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `no_pelepasan` varchar(40) NOT NULL,
  `tanggal` date NOT NULL,
  `batch_id` bigint(20) unsigned DEFAULT NULL,
  `barang_id` bigint(20) unsigned NOT NULL,
  `donatur_id` bigint(20) unsigned DEFAULT NULL COMMENT 'disalin dari batch agar tetap terlacak setelah batchnya hilang',
  `qty` int(11) NOT NULL,
  `jenis` enum('perbaikan','dibuang','dijual') NOT NULL,
  `alasan` varchar(255) NOT NULL COMMENT 'wajib; ini barang titipan orang',
  `status` enum('proses','selesai') NOT NULL DEFAULT 'selesai',
  `hasil` enum('layak','dibuang','dijual') DEFAULT NULL COMMENT 'ujung sebuah perbaikan',
  `biaya` decimal(15,2) DEFAULT NULL COMMENT 'biaya perbaikan; boleh kosong bila dikerjakan sendiri',
  `tanggal_selesai` date DEFAULT NULL,
  `batch_kembali_id` bigint(20) unsigned DEFAULT NULL COMMENT 'batch baru bila hasil perbaikannya layak pakai',
  `nilai_jual` decimal(15,2) DEFAULT NULL,
  `no_bukti_setor` varchar(60) DEFAULT NULL COMMENT 'nomor bukti setor/transfer hasil penjualan',
  `user_id` bigint(20) unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_pelepasan_no` (`no_pelepasan`),
  KEY `ix_pelepasan_jenis` (`jenis`,`status`),
  KEY `ix_pelepasan_tanggal` (`tanggal`),
  KEY `ix_pelepasan_barang` (`barang_id`),
  KEY `ix_pelepasan_batch` (`batch_id`),
  KEY `ix_pelepasan_donatur` (`donatur_id`),
  KEY `fk_pelepasan_user` (`user_id`),
  CONSTRAINT `fk_pelepasan_barang` FOREIGN KEY (`barang_id`) REFERENCES `barang` (`id`) ON UPDATE CASCADE,
  CONSTRAINT `fk_pelepasan_batch` FOREIGN KEY (`batch_id`) REFERENCES `batch_barang` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_pelepasan_donatur` FOREIGN KEY (`donatur_id`) REFERENCES `donatur` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_pelepasan_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
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
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_penerima_kode` (`kode`),
  KEY `ix_penerima_nama` (`nama`),
  KEY `ix_penerima_wilayah` (`wilayah`),
  KEY `ix_penerima_deleted` (`deleted_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `pengaturan` (
  `kunci` varchar(60) NOT NULL,
  `nilai` varchar(500) NOT NULL DEFAULT '',
  `diubah_oleh` bigint(20) unsigned DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`kunci`),
  KEY `ix_pengaturan_oleh` (`diubah_oleh`),
  CONSTRAINT `fk_pengaturan_user` FOREIGN KEY (`diubah_oleh`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
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
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `pengiriman_supplier_detail` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `pengiriman_supplier_id` bigint(20) unsigned NOT NULL,
  `barang_id` bigint(20) unsigned DEFAULT NULL,
  `nama_barang_teks` varchar(150) DEFAULT NULL,
  `nomor_batch` varchar(50) DEFAULT NULL,
  `kondisi` enum('baru','bekas_layak','bekas_tidak_layak','rusak','expired') NOT NULL DEFAULT 'baru',
  `tanggal_expired` date DEFAULT NULL,
  `qty` int(11) NOT NULL,
  `harga` decimal(15,2) NOT NULL DEFAULT 0.00,
  `keterangan` varchar(255) DEFAULT NULL,
  `donatur_id` bigint(20) unsigned DEFAULT NULL,
  `sumber` enum('pembelian','donasi') NOT NULL DEFAULT 'pembelian',
  PRIMARY KEY (`id`),
  KEY `idx_psd_header` (`pengiriman_supplier_id`),
  KEY `fk_psd_barang` (`barang_id`),
  KEY `ix_psd_kondisi` (`kondisi`),
  CONSTRAINT `fk_psd_barang` FOREIGN KEY (`barang_id`) REFERENCES `barang` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_psd_header` FOREIGN KEY (`pengiriman_supplier_id`) REFERENCES `pengiriman_supplier` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
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
  `no_resi` varchar(80) DEFAULT NULL COMMENT 'nomor resi dari marketplace',
  `kurir` varchar(80) DEFAULT NULL COMMENT 'jasa kirim',
  `status_kirim` enum('belum','diproses','dikirim','sampai','gagal','kembali') NOT NULL DEFAULT 'belum',
  `tgl_kirim` datetime DEFAULT NULL,
  `tgl_sampai` datetime DEFAULT NULL,
  `escrow_amount` decimal(15,2) DEFAULT NULL COMMENT 'yang benar-benar diterima penjual setelah semua potongan',
  `komisi` decimal(15,2) NOT NULL DEFAULT 0.00 COMMENT 'komisi marketplace',
  `biaya_layanan` decimal(15,2) NOT NULL DEFAULT 0.00,
  `biaya_transaksi` decimal(15,2) NOT NULL DEFAULT 0.00,
  `voucher` decimal(15,2) NOT NULL DEFAULT 0.00 COMMENT 'potongan voucher yang ditanggung penjual',
  `status_bayar` enum('belum','ditahan','cair','sebagian','batal') NOT NULL DEFAULT 'belum' COMMENT 'ditahan = masih di escrow marketplace; cair = sudah masuk saldo penjual',
  `tgl_settlement` date DEFAULT NULL COMMENT 'tanggal dana dilepas marketplace',
  `status_marketplace` varchar(40) DEFAULT NULL COMMENT 'status mentah dari marketplace, mis. READY_TO_SHIP',
  `ditarik_at` timestamp NULL DEFAULT NULL COMMENT 'kapan terakhir data ini ditarik dari marketplace',
  `catatan` varchar(500) DEFAULT NULL,
  `user_id` bigint(20) unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_penjualan_no` (`no_penjualan`),
  UNIQUE KEY `uq_penjualan_pesanan` (`toko_id`,`no_pesanan`),
  KEY `idx_jual_tanggal` (`tanggal`),
  KEY `idx_jual_toko` (`toko_id`),
  KEY `fk_jual_user` (`user_id`),
  KEY `ix_penjualan_resi` (`no_resi`),
  KEY `ix_penjualan_kirim` (`status_kirim`),
  KEY `ix_penjualan_bayar` (`status_bayar`),
  KEY `ix_penjualan_tarik` (`toko_id`,`ditarik_at`),
  CONSTRAINT `fk_jual_toko` FOREIGN KEY (`toko_id`) REFERENCES `toko` (`id`) ON UPDATE CASCADE,
  CONSTRAINT `fk_jual_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
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
  `created_at` timestamp NULL DEFAULT current_timestamp(),
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
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
  `status` enum('draft','menunggu_persetujuan','disetujui','ditolak','dikirim','diterima','selesai','dibatalkan') NOT NULL DEFAULT 'draft',
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
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
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
  KEY `ix_pp_tujuan_status` (`tujuan_tipe`,`tujuan_id`,`status`),
  KEY `ix_pp_asal_status` (`asal_tipe`,`asal_id`,`status`),
  CONSTRAINT `fk_pp_approver` FOREIGN KEY (`approved_by`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_pp_penerima` FOREIGN KEY (`diterima_oleh`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_pp_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `perpindahan_detail` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `perpindahan_id` bigint(20) unsigned NOT NULL,
  `barang_id` bigint(20) unsigned NOT NULL,
  `batch_id` bigint(20) unsigned DEFAULT NULL,
  `kardus_id` bigint(20) unsigned DEFAULT NULL,
  `nomor_batch` varchar(50) DEFAULT NULL,
  `kondisi` enum('baru','bekas_layak','bekas_tidak_layak','rusak','expired') NOT NULL DEFAULT 'baru',
  `donatur_id` bigint(20) unsigned DEFAULT NULL COMMENT 'ikut terbawa dari stok asalnya',
  `tanggal_expired` date DEFAULT NULL,
  `qty` int(11) NOT NULL,
  `qty_diterima` int(11) DEFAULT NULL,
  `harga` decimal(15,2) NOT NULL DEFAULT 0.00,
  `subtotal` decimal(15,2) NOT NULL DEFAULT 0.00,
  `keterangan` varchar(255) DEFAULT NULL,
  `selisih_catatan` varchar(255) DEFAULT NULL,
  `sumber` enum('pembelian','donasi') NOT NULL DEFAULT 'pembelian' COMMENT 'ikut terbawa dari batch asalnya',
  PRIMARY KEY (`id`),
  KEY `idx_ppd_header` (`perpindahan_id`),
  KEY `idx_ppd_barang` (`barang_id`),
  KEY `fk_ppd_batch` (`batch_id`),
  KEY `ix_pd_donatur` (`donatur_id`),
  KEY `idx_ppd_kardus` (`kardus_id`),
  CONSTRAINT `fk_pd_donatur` FOREIGN KEY (`donatur_id`) REFERENCES `donatur` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_ppd_barang` FOREIGN KEY (`barang_id`) REFERENCES `barang` (`id`) ON UPDATE CASCADE,
  CONSTRAINT `fk_ppd_batch` FOREIGN KEY (`batch_id`) REFERENCES `batch_barang` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_ppd_header` FOREIGN KEY (`perpindahan_id`) REFERENCES `perpindahan` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `perpindahan_mutasi` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `perpindahan_id` bigint(20) unsigned NOT NULL,
  `detail_id` bigint(20) unsigned DEFAULT NULL,
  `barang_id` bigint(20) unsigned NOT NULL,
  `batch_asal_id` bigint(20) unsigned DEFAULT NULL,
  `batch_tujuan_id` bigint(20) unsigned DEFAULT NULL,
  `qty` int(11) NOT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_mutasi_pp` (`perpindahan_id`),
  CONSTRAINT `fk_mutasi_pp` FOREIGN KEY (`perpindahan_id`) REFERENCES `perpindahan` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `perpindahan_titik` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `perpindahan_id` bigint(20) unsigned NOT NULL,
  `urutan` tinyint(3) unsigned NOT NULL DEFAULT 1,
  `lokasi_tipe` enum('gudang','toko') NOT NULL DEFAULT 'gudang',
  `lokasi_id` bigint(20) unsigned NOT NULL,
  `tiba_at` datetime DEFAULT NULL,
  `tiba_oleh` bigint(20) unsigned DEFAULT NULL,
  `berangkat_at` datetime DEFAULT NULL,
  `berangkat_oleh` bigint(20) unsigned DEFAULT NULL,
  `catatan` varchar(255) DEFAULT NULL,
  `kendaraan` varchar(60) DEFAULT NULL,
  `no_polisi` varchar(20) DEFAULT NULL,
  `pengemudi` varchar(100) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_titik_pp` (`perpindahan_id`,`urutan`),
  KEY `idx_titik_lokasi` (`lokasi_tipe`,`lokasi_id`),
  CONSTRAINT `fk_titik_pp` FOREIGN KEY (`perpindahan_id`) REFERENCES `perpindahan` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `reset_sandi` (
  `token_hash` char(64) NOT NULL,
  `user_id` bigint(20) unsigned NOT NULL,
  `dibuat_oleh` bigint(20) unsigned DEFAULT NULL,
  `sampai` datetime NOT NULL,
  `dipakai_at` datetime DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`token_hash`),
  KEY `ix_reset_user` (`user_id`,`sampai`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `resi_scan_log` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `no_resi` varchar(60) NOT NULL,
  `sumber` enum('kamera','scanner','manual') NOT NULL DEFAULT 'manual',
  `hasil` enum('cocok','tidak_ditemukan') NOT NULL,
  `pengiriman_supplier_id` bigint(20) unsigned DEFAULT NULL,
  `perpindahan_id` bigint(20) unsigned DEFAULT NULL,
  `user_id` bigint(20) unsigned DEFAULT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_scan_resi` (`no_resi`),
  KEY `idx_scan_created` (`created_at`),
  KEY `fk_scan_ps` (`pengiriman_supplier_id`),
  KEY `fk_scan_pp` (`perpindahan_id`),
  KEY `fk_scan_user` (`user_id`),
  CONSTRAINT `fk_scan_pp` FOREIGN KEY (`perpindahan_id`) REFERENCES `perpindahan` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_scan_ps` FOREIGN KEY (`pengiriman_supplier_id`) REFERENCES `pengiriman_supplier` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_scan_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
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
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
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
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `roles` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `nama` varchar(50) NOT NULL,
  `label` varchar(100) NOT NULL,
  `keterangan` varchar(255) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_roles_nama` (`nama`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `sesi_perangkat` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint(20) unsigned NOT NULL,
  `sid_hash` char(64) NOT NULL,
  `perangkat` varchar(120) DEFAULT NULL COMMENT 'ringkasan terbaca: Windows Â· Chrome',
  `user_agent` varchar(255) DEFAULT NULL,
  `ip` varchar(45) DEFAULT NULL,
  `dibuat_at` timestamp NULL DEFAULT current_timestamp(),
  `terakhir_at` timestamp NULL DEFAULT current_timestamp(),
  `diakhiri_at` timestamp NULL DEFAULT NULL,
  `diakhiri_oleh` bigint(20) unsigned DEFAULT NULL COMMENT 'siapa yang mengakhiri, bila bukan pemiliknya',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_sesi_sid` (`sid_hash`),
  KEY `ix_sesi_user_hidup` (`user_id`,`diakhiri_at`,`terakhir_at`),
  CONSTRAINT `fk_sesi_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `stock_opname` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `no_opname` varchar(30) NOT NULL,
  `tanggal` date NOT NULL,
  `status` enum('draft','menunggu_persetujuan','disetujui','ditolak') NOT NULL DEFAULT 'draft',
  `user_id` bigint(20) unsigned NOT NULL,
  `approved_by` bigint(20) unsigned DEFAULT NULL,
  `approved_at` timestamp NULL DEFAULT NULL,
  `catatan` varchar(255) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_opname_no` (`no_opname`),
  KEY `fk_opname_user` (`user_id`),
  KEY `fk_opname_approver` (`approved_by`),
  CONSTRAINT `fk_opname_approver` FOREIGN KEY (`approved_by`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_opname_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `stock_opname_detail` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `stock_opname_id` bigint(20) unsigned NOT NULL,
  `barang_id` bigint(20) unsigned NOT NULL,
  `batch_id` bigint(20) unsigned DEFAULT NULL,
  `qty_sistem` int(11) NOT NULL,
  `qty_fisik` int(11) NOT NULL,
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
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `stok` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `barang_id` bigint(20) unsigned NOT NULL,
  `qty_total` int(11) NOT NULL DEFAULT 0,
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_stok_barang` (`barang_id`),
  CONSTRAINT `fk_stok_barang` FOREIGN KEY (`barang_id`) REFERENCES `barang` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `stok_supplier` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `supplier_id` bigint(20) unsigned NOT NULL,
  `donatur_id` bigint(20) unsigned DEFAULT NULL COMMENT 'asal barang bila dari donatur',
  `barang_id` bigint(20) unsigned NOT NULL,
  `lokasi_rak_id` bigint(20) unsigned DEFAULT NULL COMMENT 'rak tempat batch ini berada, di dalam gudang supplier',
  `nomor_batch` varchar(50) NOT NULL,
  `kondisi` enum('baru','bekas_layak','bekas_tidak_layak','rusak','expired') NOT NULL DEFAULT 'baru',
  `tanggal_expired` date DEFAULT NULL,
  `qty` int(11) NOT NULL DEFAULT 0,
  `harga` decimal(15,2) NOT NULL DEFAULT 0.00,
  `tanggal_masuk` date NOT NULL,
  `keterangan` varchar(255) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `sumber` enum('pembelian','donasi') NOT NULL DEFAULT 'pembelian' COMMENT 'asal barang; terpisah dari keadaan fisik di kolom kondisi',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_stoksup` (`supplier_id`,`barang_id`,`nomor_batch`,`kondisi`,`sumber`,`donatur_id`,`tanggal_expired`,`lokasi_rak_id`),
  KEY `idx_ss_supplier` (`supplier_id`),
  KEY `ix_ss_rak` (`lokasi_rak_id`),
  KEY `fk_ss_barang` (`barang_id`),
  KEY `ix_stok_supplier_donatur` (`donatur_id`),
  KEY `ix_stok_supplier_kondisi` (`kondisi`),
  KEY `ix_stok_supplier_sumber` (`sumber`),
  CONSTRAINT `fk_ss_barang` FOREIGN KEY (`barang_id`) REFERENCES `barang` (`id`) ON UPDATE CASCADE,
  CONSTRAINT `fk_ss_supplier` FOREIGN KEY (`supplier_id`) REFERENCES `supplier` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_stok_supplier_donatur` FOREIGN KEY (`donatur_id`) REFERENCES `donatur` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `supplier` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `kode` varchar(20) NOT NULL,
  `nama` varchar(150) NOT NULL,
  `telepon` varchar(30) DEFAULT NULL,
  `email` varchar(150) DEFAULT NULL,
  `alamat` varchar(255) DEFAULT NULL,
  `kontak_pic` varchar(100) DEFAULT NULL,
  `keterangan` varchar(255) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_supplier_kode` (`kode`),
  KEY `ix_supplier_deleted` (`deleted_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
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
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_toko_kode` (`kode`),
  KEY `idx_toko_marketplace` (`marketplace`),
  KEY `ix_toko_deleted` (`deleted_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `transaksi_keluar` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `no_transaksi` varchar(30) NOT NULL,
  `tanggal` datetime NOT NULL,
  `user_id` bigint(20) unsigned NOT NULL,
  `penerima` varchar(150) NOT NULL,
  `untuk_bayi` varchar(150) DEFAULT NULL,
  `divisi_cabang` varchar(150) DEFAULT NULL,
  `keperluan` varchar(50) DEFAULT NULL,
  `catatan` varchar(255) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_tk_no` (`no_transaksi`),
  KEY `idx_tk_tanggal` (`tanggal`),
  KEY `fk_tk_user` (`user_id`),
  KEY `idx_tk_keperluan` (`keperluan`),
  CONSTRAINT `fk_tk_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `transaksi_masuk` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `no_transaksi` varchar(30) NOT NULL,
  `tanggal` datetime NOT NULL,
  `supplier_id` bigint(20) unsigned DEFAULT NULL,
  `donasi_id` bigint(20) unsigned DEFAULT NULL,
  `user_id` bigint(20) unsigned NOT NULL,
  `nota_foto` varchar(255) DEFAULT NULL,
  `total` decimal(15,2) NOT NULL DEFAULT 0.00,
  `catatan` varchar(255) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_tm_no` (`no_transaksi`),
  KEY `idx_tm_tanggal` (`tanggal`),
  KEY `idx_tm_supplier` (`supplier_id`),
  KEY `fk_tm_user` (`user_id`),
  KEY `idx_tm_donasi` (`donasi_id`),
  CONSTRAINT `fk_tm_supplier` FOREIGN KEY (`supplier_id`) REFERENCES `supplier` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_tm_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `user_gudang` (
  `user_id` bigint(20) unsigned NOT NULL,
  `gudang_id` bigint(20) unsigned NOT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`user_id`,`gudang_id`),
  KEY `ix_user_gudang_gudang` (`gudang_id`),
  CONSTRAINT `fk_user_gudang_gudang` FOREIGN KEY (`gudang_id`) REFERENCES `gudang` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_user_gudang_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='wilayah kerja tambahan; gudang utama tetap di users.gudang_id';
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `user_toko` (
  `user_id` bigint(20) unsigned NOT NULL,
  `toko_id` bigint(20) unsigned NOT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`user_id`,`toko_id`),
  KEY `idx_ut_toko` (`toko_id`),
  CONSTRAINT `fk_ut_toko` FOREIGN KEY (`toko_id`) REFERENCES `toko` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_ut_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
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
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `deleted_at` timestamp NULL DEFAULT NULL,
  `tingkat` enum('staff','manager') NOT NULL DEFAULT 'staff' COMMENT 'wewenang di dalam perannya; diabaikan untuk super_admin dan pimpinan',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_users_email` (`email`),
  KEY `idx_users_role` (`role_id`),
  KEY `idx_users_supplier` (`supplier_id`),
  KEY `ix_users_gudang` (`gudang_id`),
  KEY `ix_users_deleted` (`deleted_at`),
  KEY `ix_users_tingkat` (`role_id`,`tingkat`),
  CONSTRAINT `fk_users_gudang` FOREIGN KEY (`gudang_id`) REFERENCES `gudang` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_users_role` FOREIGN KEY (`role_id`) REFERENCES `roles` (`id`) ON UPDATE CASCADE,
  CONSTRAINT `fk_users_supplier` FOREIGN KEY (`supplier_id`) REFERENCES `supplier` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
SET @saved_cs_client     = @@character_set_client;
SET character_set_client = utf8mb4;
/*!50001 CREATE VIEW `v_expired_rantai` AS SELECT
 NULL AS `sumber`,
 NULL AS `ref_id`,
 NULL AS `barang_id`,
 NULL AS `nama_barang`,
 NULL AS `nomor_batch`,
 NULL AS `tanggal_expired`,
 NULL AS `qty_tersisa`,
 NULL AS `sisa_hari`,
 NULL AS `status_expired`,
 NULL AS `lokasi_tipe`,
 NULL AS `lokasi_nama`,
 NULL AS `lokasi_rak_id`,
 NULL AS `toko_id`,
 NULL AS `supplier_id` */;
SET character_set_client = @saved_cs_client;
SET @saved_cs_client     = @@character_set_client;
SET character_set_client = utf8mb4;
/*!50001 CREATE VIEW `v_pelepasan` AS SELECT
 NULL AS `id`,
 NULL AS `no_pelepasan`,
 NULL AS `tanggal`,
 NULL AS `jenis`,
 NULL AS `status`,
 NULL AS `hasil`,
 NULL AS `qty`,
 NULL AS `alasan`,
 NULL AS `biaya`,
 NULL AS `nilai_jual`,
 NULL AS `no_bukti_setor`,
 NULL AS `tanggal_selesai`,
 NULL AS `kode_barang`,
 NULL AS `nama_barang`,
 NULL AS `satuan`,
 NULL AS `nomor_batch`,
 NULL AS `tanggal_expired`,
 NULL AS `kondisi`,
 NULL AS `nama_donatur`,
 NULL AS `donatur_anonim`,
 NULL AS `donatur_wilayah`,
 NULL AS `petugas`,
 NULL AS `lama_hari` */;
SET character_set_client = @saved_cs_client;
SET @saved_cs_client     = @@character_set_client;
SET character_set_client = utf8mb4;
/*!50001 CREATE VIEW `v_penjualan_harian` AS SELECT
 NULL AS `toko_id`,
 NULL AS `nama_toko`,
 NULL AS `marketplace`,
 NULL AS `tanggal`,
 NULL AS `jml_pesanan`,
 NULL AS `jml_unit`,
 NULL AS `omzet` */;
SET character_set_client = @saved_cs_client;
SET @saved_cs_client     = @@character_set_client;
SET character_set_client = utf8mb4;
/*!50001 CREATE VIEW `v_penyaluran_penerima` AS SELECT
 NULL AS `penerima_id`,
 NULL AS `kode`,
 NULL AS `nama`,
 NULL AS `jenis`,
 NULL AS `wilayah`,
 NULL AS `jml_penyaluran`,
 NULL AS `total_unit`,
 NULL AS `total_nilai`,
 NULL AS `terakhir_disalurkan` */;
SET character_set_client = @saved_cs_client;
SET @saved_cs_client     = @@character_set_client;
SET character_set_client = utf8mb4;
/*!50001 CREATE VIEW `v_perangkat_aktif` AS SELECT
 NULL AS `user_id`,
 NULL AS `nama`,
 NULL AS `email`,
 NULL AS `peran`,
 NULL AS `idle_menit`,
 NULL AS `jml_perangkat`,
 NULL AS `paling_baru` */;
SET character_set_client = @saved_cs_client;
SET @saved_cs_client     = @@character_set_client;
SET character_set_client = utf8mb4;
/*!50001 CREATE VIEW `v_peringkat_donatur` AS SELECT
 NULL AS `donatur_id`,
 NULL AS `kode`,
 NULL AS `nama`,
 NULL AS `jenis`,
 NULL AS `wilayah`,
 NULL AS `anonim`,
 NULL AS `supplier_id`,
 NULL AS `is_active`,
 NULL AS `jml_donasi`,
 NULL AS `total_qty`,
 NULL AS `total_nilai`,
 NULL AS `jml_jenis_barang`,
 NULL AS `jml_kunjungan`,
 NULL AS `jml_paket`,
 NULL AS `donasi_pertama`,
 NULL AS `donasi_terakhir`,
 NULL AS `hari_sejak_terakhir`,
 NULL AS `rata_per_bulan` */;
SET character_set_client = @saved_cs_client;
SET @saved_cs_client     = @@character_set_client;
SET character_set_client = utf8mb4;
/*!50001 CREATE VIEW `v_perpindahan_ringkas` AS SELECT
 NULL AS `id`,
 NULL AS `no_perpindahan`,
 NULL AS `no_surat_jalan`,
 NULL AS `jenis`,
 NULL AS `tanggal`,
 NULL AS `status`,
 NULL AS `no_resi`,
 NULL AS `tujuan_teks`,
 NULL AS `penerima`,
 NULL AS `asal_nama`,
 NULL AS `tujuan_nama`,
 NULL AS `total_qty`,
 NULL AS `jml_item`,
 NULL AS `pembuat`,
 NULL AS `penyetuju` */;
SET character_set_client = @saved_cs_client;
SET @saved_cs_client     = @@character_set_client;
SET character_set_client = utf8mb4;
/*!50001 CREATE VIEW `v_petugas_gudang` AS SELECT
 NULL AS `gudang_id`,
 NULL AS `kode_gudang`,
 NULL AS `nama_gudang`,
 NULL AS `jenis`,
 NULL AS `jml_petugas`,
 NULL AS `petugas` */;
SET character_set_client = @saved_cs_client;
SET @saved_cs_client     = @@character_set_client;
SET character_set_client = utf8mb4;
/*!50001 CREATE VIEW `v_status_expired` AS SELECT
 NULL AS `batch_id`,
 NULL AS `barang_id`,
 NULL AS `nama_barang`,
 NULL AS `nomor_batch`,
 NULL AS `tanggal_expired`,
 NULL AS `qty_tersisa`,
 NULL AS `sisa_hari`,
 NULL AS `status_expired`,
 NULL AS `lokasi_tipe`,
 NULL AS `lokasi_nama`,
 NULL AS `lokasi_rak_id`,
 NULL AS `toko_id`,
 NULL AS `gudang_id`,
 NULL AS `jenis_gudang`,
 NULL AS `supplier_id` */;
SET character_set_client = @saved_cs_client;
SET @saved_cs_client     = @@character_set_client;
SET character_set_client = utf8mb4;
/*!50001 CREATE VIEW `v_status_stok` AS SELECT
 NULL AS `barang_id`,
 NULL AS `kode_barang`,
 NULL AS `nama_barang`,
 NULL AS `kategori`,
 NULL AS `stok`,
 NULL AS `stok_minimum`,
 NULL AS `status_stok` */;
SET character_set_client = @saved_cs_client;
SET @saved_cs_client     = @@character_set_client;
SET character_set_client = utf8mb4;
/*!50001 CREATE VIEW `v_stok_kondisi` AS SELECT
 NULL AS `barang_id`,
 NULL AS `kode_barang`,
 NULL AS `nama_barang`,
 NULL AS `satuan`,
 NULL AS `kondisi`,
 NULL AS `layak_salur`,
 NULL AS `lokasi_tipe`,
 NULL AS `lokasi_nama`,
 NULL AS `jml_batch`,
 NULL AS `qty`,
 NULL AS `nilai` */;
SET character_set_client = @saved_cs_client;
SET @saved_cs_client     = @@character_set_client;
SET character_set_client = utf8mb4;
/*!50001 CREATE VIEW `v_stok_per_gudang` AS SELECT
 NULL AS `gudang_id`,
 NULL AS `nama_gudang`,
 NULL AS `barang_id`,
 NULL AS `kode_barang`,
 NULL AS `nama_barang`,
 NULL AS `satuan`,
 NULL AS `qty` */;
SET character_set_client = @saved_cs_client;
SET @saved_cs_client     = @@character_set_client;
SET character_set_client = utf8mb4;
/*!50001 CREATE VIEW `v_stok_rantai` AS SELECT
 NULL AS `sumber`,
 NULL AS `barang_id`,
 NULL AS `kode_barang`,
 NULL AS `nama_barang`,
 NULL AS `stok_minimum`,
 NULL AS `qty`,
 NULL AS `lokasi_rak_id`,
 NULL AS `toko_id`,
 NULL AS `supplier_id` */;
SET character_set_client = @saved_cs_client;
SET @saved_cs_client     = @@character_set_client;
SET character_set_client = utf8mb4;
/*!50001 CREATE VIEW `v_stok_toko` AS SELECT
 NULL AS `toko_id`,
 NULL AS `nama_toko`,
 NULL AS `marketplace`,
 NULL AS `barang_id`,
 NULL AS `kode_barang`,
 NULL AS `nama_barang`,
 NULL AS `satuan`,
 NULL AS `stok_minimum`,
 NULL AS `qty`,
 NULL AS `exp_terdekat` */;
SET character_set_client = @saved_cs_client;
/*!50001 DROP VIEW IF EXISTS `v_expired_rantai`*/;
/*!50001 SET @saved_cs_client          = @@character_set_client */;
/*!50001 SET @saved_cs_results         = @@character_set_results */;
/*!50001 SET @saved_col_connection     = @@collation_connection */;
/*!50001 SET character_set_client      = utf8mb4 */;
/*!50001 SET character_set_results     = utf8mb4 */;
/*!50001 SET collation_connection      = utf8mb4_unicode_ci */;
/*!50001 CREATE ALGORITHM=UNDEFINED */
/*!50013 DEFINER=`root`@`%` SQL SECURITY DEFINER */
/*!50001 VIEW `v_expired_rantai` AS select convert('batch' using utf8mb4) AS `sumber`,`ve`.`batch_id` AS `ref_id`,`ve`.`barang_id` AS `barang_id`,convert(`ve`.`nama_barang` using utf8mb4) AS `nama_barang`,convert(`ve`.`nomor_batch` using utf8mb4) AS `nomor_batch`,`ve`.`tanggal_expired` AS `tanggal_expired`,`ve`.`qty_tersisa` AS `qty_tersisa`,`ve`.`sisa_hari` AS `sisa_hari`,convert(`ve`.`status_expired` using utf8mb4) AS `status_expired`,convert(`ve`.`lokasi_tipe` using utf8mb4) AS `lokasi_tipe`,convert(`ve`.`lokasi_nama` using utf8mb4) AS `lokasi_nama`,`ve`.`lokasi_rak_id` AS `lokasi_rak_id`,`ve`.`toko_id` AS `toko_id`,NULL AS `supplier_id` from `v_status_expired` `ve` union all select convert('supplier' using utf8mb4) AS `CONVERT('supplier' USING utf8mb4)`,`ss`.`id` AS `id`,`ss`.`barang_id` AS `barang_id`,convert(`b`.`nama` using utf8mb4) AS `CONVERT(``b``.``nama`` USING utf8mb4)`,convert(`ss`.`nomor_batch` using utf8mb4) AS `CONVERT(``ss``.``nomor_batch`` USING utf8mb4)`,`ss`.`tanggal_expired` AS `tanggal_expired`,`ss`.`qty` AS `qty`,to_days(`ss`.`tanggal_expired`) - to_days(curdate()) AS `TO_DAYS(``ss``.``tanggal_expired``) - TO_DAYS(CURDATE())`,convert(case when `ss`.`tanggal_expired` is null then 'tanpa_expired' when to_days(`ss`.`tanggal_expired`) - to_days(curdate()) < 0 then 'expired' when to_days(`ss`.`tanggal_expired`) - to_days(curdate()) <= 30 then 'segera_expired' when to_days(`ss`.`tanggal_expired`) - to_days(curdate()) <= 90 then 'waspada' else 'aman' end using utf8mb4) AS `Name_exp_9`,convert('supplier' using utf8mb4) AS `CONVERT('supplier' USING utf8mb4)`,convert(concat(coalesce(`g`.`nama`,'?'),' ? ',coalesce(`lr`.`nama`,'?')) using utf8mb4) AS `Name_exp_11`,`ss`.`lokasi_rak_id` AS `lokasi_rak_id`,NULL AS `NULL`,`ss`.`supplier_id` AS `supplier_id` from (((`stok_supplier` `ss` join `barang` `b` on(`b`.`id` = `ss`.`barang_id`)) left join `lokasi_rak` `lr` on(`lr`.`id` = `ss`.`lokasi_rak_id`)) left join `gudang` `g` on(`g`.`id` = `lr`.`gudang_id`)) where `ss`.`qty` > 0 */;
/*!50001 SET character_set_client      = @saved_cs_client */;
/*!50001 SET character_set_results     = @saved_cs_results */;
/*!50001 SET collation_connection      = @saved_col_connection */;
/*!50001 DROP VIEW IF EXISTS `v_pelepasan`*/;
/*!50001 SET @saved_cs_client          = @@character_set_client */;
/*!50001 SET @saved_cs_results         = @@character_set_results */;
/*!50001 SET @saved_col_connection     = @@collation_connection */;
/*!50001 SET character_set_client      = utf8mb4 */;
/*!50001 SET character_set_results     = utf8mb4 */;
/*!50001 SET collation_connection      = utf8mb4_unicode_ci */;
/*!50001 CREATE ALGORITHM=UNDEFINED */
/*!50013 DEFINER=`root`@`%` SQL SECURITY DEFINER */
/*!50001 VIEW `v_pelepasan` AS select `p`.`id` AS `id`,`p`.`no_pelepasan` AS `no_pelepasan`,`p`.`tanggal` AS `tanggal`,`p`.`jenis` AS `jenis`,`p`.`status` AS `status`,`p`.`hasil` AS `hasil`,`p`.`qty` AS `qty`,`p`.`alasan` AS `alasan`,`p`.`biaya` AS `biaya`,`p`.`nilai_jual` AS `nilai_jual`,`p`.`no_bukti_setor` AS `no_bukti_setor`,`p`.`tanggal_selesai` AS `tanggal_selesai`,`b`.`kode` AS `kode_barang`,`b`.`nama` AS `nama_barang`,`b`.`satuan` AS `satuan`,`bb`.`nomor_batch` AS `nomor_batch`,`bb`.`tanggal_expired` AS `tanggal_expired`,`bb`.`kondisi` AS `kondisi`,`d`.`nama` AS `nama_donatur`,`d`.`anonim` AS `donatur_anonim`,`d`.`wilayah` AS `donatur_wilayah`,`u`.`nama` AS `petugas`,to_days(coalesce(`p`.`tanggal_selesai`,curdate())) - to_days(`p`.`tanggal`) AS `lama_hari` from ((((`pelepasan_stok` `p` join `barang` `b` on(`b`.`id` = `p`.`barang_id`)) left join `batch_barang` `bb` on(`bb`.`id` = `p`.`batch_id`)) left join `donatur` `d` on(`d`.`id` = `p`.`donatur_id`)) left join `users` `u` on(`u`.`id` = `p`.`user_id`)) */;
/*!50001 SET character_set_client      = @saved_cs_client */;
/*!50001 SET character_set_results     = @saved_cs_results */;
/*!50001 SET collation_connection      = @saved_col_connection */;
/*!50001 DROP VIEW IF EXISTS `v_penjualan_harian`*/;
/*!50001 SET @saved_cs_client          = @@character_set_client */;
/*!50001 SET @saved_cs_results         = @@character_set_results */;
/*!50001 SET @saved_col_connection     = @@collation_connection */;
/*!50001 SET character_set_client      = utf8mb4 */;
/*!50001 SET character_set_results     = utf8mb4 */;
/*!50001 SET collation_connection      = utf8mb4_unicode_ci */;
/*!50001 CREATE ALGORITHM=UNDEFINED */
/*!50013 DEFINER=`root`@`%` SQL SECURITY DEFINER */
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
/*!50001 CREATE ALGORITHM=UNDEFINED */
/*!50013 DEFINER=`root`@`%` SQL SECURITY DEFINER */
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
/*!50001 CREATE ALGORITHM=UNDEFINED */
/*!50013 DEFINER=`root`@`%` SQL SECURITY DEFINER */
/*!50001 VIEW `v_perangkat_aktif` AS select `u`.`id` AS `user_id`,`u`.`nama` AS `nama`,`u`.`email` AS `email`,`r`.`label` AS `peran`,`u`.`idle_menit` AS `idle_menit`,count(`s`.`id`) AS `jml_perangkat`,max(`s`.`terakhir_at`) AS `paling_baru` from ((`users` `u` join `roles` `r` on(`r`.`id` = `u`.`role_id`)) left join `sesi_perangkat` `s` on(`s`.`user_id` = `u`.`id` and `s`.`diakhiri_at` is null and `s`.`terakhir_at` >= current_timestamp() - interval `u`.`idle_menit` minute)) where `u`.`deleted_at` is null group by `u`.`id` */;
/*!50001 SET character_set_client      = @saved_cs_client */;
/*!50001 SET character_set_results     = @saved_cs_results */;
/*!50001 SET collation_connection      = @saved_col_connection */;
/*!50001 DROP VIEW IF EXISTS `v_peringkat_donatur`*/;
/*!50001 SET @saved_cs_client          = @@character_set_client */;
/*!50001 SET @saved_cs_results         = @@character_set_results */;
/*!50001 SET @saved_col_connection     = @@collation_connection */;
/*!50001 SET character_set_client      = utf8mb4 */;
/*!50001 SET character_set_results     = utf8mb4 */;
/*!50001 SET collation_connection      = utf8mb4_unicode_ci */;
/*!50001 CREATE ALGORITHM=UNDEFINED */
/*!50013 DEFINER=`root`@`%` SQL SECURITY DEFINER */
/*!50001 VIEW `v_peringkat_donatur` AS select `d`.`id` AS `donatur_id`,`d`.`kode` AS `kode`,`d`.`nama` AS `nama`,`d`.`jenis` AS `jenis`,`d`.`wilayah` AS `wilayah`,`d`.`anonim` AS `anonim`,`d`.`supplier_id` AS `supplier_id`,`d`.`is_active` AS `is_active`,count(distinct `dn`.`id`) AS `jml_donasi`,coalesce(sum(`dd`.`qty`),0) AS `total_qty`,coalesce(sum(`dd`.`nilai_taksir`),0) AS `total_nilai`,count(distinct `dd`.`barang_id`) AS `jml_jenis_barang`,count(distinct case when `dn`.`jenis` = 'kunjungan' then `dn`.`id` end) AS `jml_kunjungan`,count(distinct case when `dn`.`jenis` = 'paket' then `dn`.`id` end) AS `jml_paket`,min(`dn`.`tanggal`) AS `donasi_pertama`,max(`dn`.`tanggal`) AS `donasi_terakhir`,to_days(curdate()) - to_days(max(`dn`.`tanggal`)) AS `hari_sejak_terakhir`,round(count(distinct `dn`.`id`) / greatest(timestampdiff(MONTH,min(`dn`.`tanggal`),curdate()) + 1,1),2) AS `rata_per_bulan` from ((`donatur` `d` left join `donasi` `dn` on(`dn`.`donatur_id` = `d`.`id`)) left join `donasi_detail` `dd` on(`dd`.`donasi_id` = `dn`.`id`)) where `d`.`deleted_at` is null group by `d`.`id`,`d`.`kode`,`d`.`nama`,`d`.`jenis`,`d`.`wilayah`,`d`.`anonim`,`d`.`supplier_id`,`d`.`is_active` */;
/*!50001 SET character_set_client      = @saved_cs_client */;
/*!50001 SET character_set_results     = @saved_cs_results */;
/*!50001 SET collation_connection      = @saved_col_connection */;
/*!50001 DROP VIEW IF EXISTS `v_perpindahan_ringkas`*/;
/*!50001 SET @saved_cs_client          = @@character_set_client */;
/*!50001 SET @saved_cs_results         = @@character_set_results */;
/*!50001 SET @saved_col_connection     = @@collation_connection */;
/*!50001 SET character_set_client      = utf8mb4 */;
/*!50001 SET character_set_results     = utf8mb4 */;
/*!50001 SET collation_connection      = utf8mb4_unicode_ci */;
/*!50001 CREATE ALGORITHM=UNDEFINED */
/*!50013 DEFINER=`root`@`%` SQL SECURITY DEFINER */
/*!50001 VIEW `v_perpindahan_ringkas` AS select `p`.`id` AS `id`,`p`.`no_perpindahan` AS `no_perpindahan`,`p`.`no_surat_jalan` AS `no_surat_jalan`,`p`.`jenis` AS `jenis`,`p`.`tanggal` AS `tanggal`,`p`.`status` AS `status`,`p`.`no_resi` AS `no_resi`,`p`.`tujuan_teks` AS `tujuan_teks`,`p`.`penerima` AS `penerima`,case `p`.`asal_tipe` when 'supplier' then (select `supplier`.`nama` from `supplier` where `supplier`.`id` = `p`.`asal_id`) when 'gudang' then (select `gudang`.`nama` from `gudang` where `gudang`.`id` = `p`.`asal_id`) when 'rak' then (select `lokasi_rak`.`nama` from `lokasi_rak` where `lokasi_rak`.`id` = `p`.`asal_id`) else `p`.`asal_teks` end AS `asal_nama`,case `p`.`tujuan_tipe` when 'gudang' then (select `gudang`.`nama` from `gudang` where `gudang`.`id` = `p`.`tujuan_id`) when 'rak' then (select `lokasi_rak`.`nama` from `lokasi_rak` where `lokasi_rak`.`id` = `p`.`tujuan_id`) when 'supplier' then (select `supplier`.`nama` from `supplier` where `supplier`.`id` = `p`.`tujuan_id`) else `p`.`tujuan_teks` end AS `tujuan_nama`,(select coalesce(sum(`d`.`qty`),0) from `perpindahan_detail` `d` where `d`.`perpindahan_id` = `p`.`id`) AS `total_qty`,(select count(0) from `perpindahan_detail` `d` where `d`.`perpindahan_id` = `p`.`id`) AS `jml_item`,`u`.`nama` AS `pembuat`,`ua`.`nama` AS `penyetuju` from ((`perpindahan` `p` left join `users` `u` on(`u`.`id` = `p`.`user_id`)) left join `users` `ua` on(`ua`.`id` = `p`.`approved_by`)) */;
/*!50001 SET character_set_client      = @saved_cs_client */;
/*!50001 SET character_set_results     = @saved_cs_results */;
/*!50001 SET collation_connection      = @saved_col_connection */;
/*!50001 DROP VIEW IF EXISTS `v_petugas_gudang`*/;
/*!50001 SET @saved_cs_client          = @@character_set_client */;
/*!50001 SET @saved_cs_results         = @@character_set_results */;
/*!50001 SET @saved_col_connection     = @@collation_connection */;
/*!50001 SET character_set_client      = utf8mb4 */;
/*!50001 SET character_set_results     = utf8mb4 */;
/*!50001 SET collation_connection      = utf8mb4_unicode_ci */;
/*!50001 CREATE ALGORITHM=UNDEFINED */
/*!50013 DEFINER=`root`@`%` SQL SECURITY DEFINER */
/*!50001 VIEW `v_petugas_gudang` AS select `g`.`id` AS `gudang_id`,`g`.`kode` AS `kode_gudang`,`g`.`nama` AS `nama_gudang`,`g`.`jenis` AS `jenis`,count(`u`.`id`) AS `jml_petugas`,group_concat(`u`.`nama` order by `u`.`nama` ASC separator ', ') AS `petugas` from (`gudang` `g` left join `users` `u` on(`u`.`gudang_id` = `g`.`id` and `u`.`deleted_at` is null and `u`.`is_active` = 1)) where `g`.`deleted_at` is null group by `g`.`id` */;
/*!50001 SET character_set_client      = @saved_cs_client */;
/*!50001 SET character_set_results     = @saved_cs_results */;
/*!50001 SET collation_connection      = @saved_col_connection */;
/*!50001 DROP VIEW IF EXISTS `v_status_expired`*/;
/*!50001 SET @saved_cs_client          = @@character_set_client */;
/*!50001 SET @saved_cs_results         = @@character_set_results */;
/*!50001 SET @saved_col_connection     = @@collation_connection */;
/*!50001 SET character_set_client      = utf8mb4 */;
/*!50001 SET character_set_results     = utf8mb4 */;
/*!50001 SET collation_connection      = utf8mb4_unicode_ci */;
/*!50001 CREATE ALGORITHM=UNDEFINED */
/*!50013 DEFINER=`root`@`%` SQL SECURITY DEFINER */
/*!50001 VIEW `v_status_expired` AS select `bb`.`id` AS `batch_id`,`bb`.`barang_id` AS `barang_id`,`b`.`nama` AS `nama_barang`,`bb`.`nomor_batch` AS `nomor_batch`,`bb`.`tanggal_expired` AS `tanggal_expired`,`bb`.`qty_tersisa` AS `qty_tersisa`,to_days(`bb`.`tanggal_expired`) - to_days(curdate()) AS `sisa_hari`,case when `bb`.`tanggal_expired` is null then 'tanpa_expired' when to_days(`bb`.`tanggal_expired`) - to_days(curdate()) < 0 then 'expired' when to_days(`bb`.`tanggal_expired`) - to_days(curdate()) <= 30 then 'segera_expired' when to_days(`bb`.`tanggal_expired`) - to_days(curdate()) <= 90 then 'waspada' else 'aman' end AS `status_expired`,case when `bb`.`toko_id` is not null then 'toko' else 'gudang' end AS `lokasi_tipe`,coalesce(`t`.`nama`,concat(coalesce(`g`.`nama`,'—'),' › ',coalesce(`lr`.`nama`,'—'))) AS `lokasi_nama`,`bb`.`lokasi_rak_id` AS `lokasi_rak_id`,`bb`.`toko_id` AS `toko_id`,`lr`.`gudang_id` AS `gudang_id`,`g`.`jenis` AS `jenis_gudang`,`g`.`supplier_id` AS `supplier_id` from ((((`batch_barang` `bb` join `barang` `b` on(`b`.`id` = `bb`.`barang_id`)) left join `toko` `t` on(`t`.`id` = `bb`.`toko_id`)) left join `lokasi_rak` `lr` on(`lr`.`id` = `bb`.`lokasi_rak_id`)) left join `gudang` `g` on(`g`.`id` = `lr`.`gudang_id`)) where `bb`.`qty_tersisa` > 0 */;
/*!50001 SET character_set_client      = @saved_cs_client */;
/*!50001 SET character_set_results     = @saved_cs_results */;
/*!50001 SET collation_connection      = @saved_col_connection */;
/*!50001 DROP VIEW IF EXISTS `v_status_stok`*/;
/*!50001 SET @saved_cs_client          = @@character_set_client */;
/*!50001 SET @saved_cs_results         = @@character_set_results */;
/*!50001 SET @saved_col_connection     = @@collation_connection */;
/*!50001 SET character_set_client      = utf8mb4 */;
/*!50001 SET character_set_results     = utf8mb4 */;
/*!50001 SET collation_connection      = utf8mb4_unicode_ci */;
/*!50001 CREATE ALGORITHM=UNDEFINED */
/*!50013 DEFINER=`root`@`%` SQL SECURITY DEFINER */
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
/*!50001 CREATE ALGORITHM=UNDEFINED */
/*!50013 DEFINER=`root`@`%` SQL SECURITY DEFINER */
/*!50001 VIEW `v_stok_kondisi` AS select `bb`.`barang_id` AS `barang_id`,`b`.`kode` AS `kode_barang`,`b`.`nama` AS `nama_barang`,`b`.`satuan` AS `satuan`,`bb`.`kondisi` AS `kondisi`,`bb`.`kondisi` in ('baru','bekas_layak') AS `layak_salur`,case when `bb`.`toko_id` is not null then 'toko' else 'gudang' end AS `lokasi_tipe`,coalesce(`t`.`nama`,`g`.`nama`,'?') AS `lokasi_nama`,count(0) AS `jml_batch`,sum(`bb`.`qty_tersisa`) AS `qty`,sum(`bb`.`qty_tersisa` * `b`.`harga`) AS `nilai` from ((((`batch_barang` `bb` join `barang` `b` on(`b`.`id` = `bb`.`barang_id`)) left join `toko` `t` on(`t`.`id` = `bb`.`toko_id`)) left join `lokasi_rak` `lr` on(`lr`.`id` = `bb`.`lokasi_rak_id`)) left join `gudang` `g` on(`g`.`id` = `lr`.`gudang_id`)) where `bb`.`qty_tersisa` > 0 group by `bb`.`barang_id`,`bb`.`kondisi`,case when `bb`.`toko_id` is not null then 'toko' else 'gudang' end,coalesce(`t`.`nama`,`g`.`nama`,'?') */;
/*!50001 SET character_set_client      = @saved_cs_client */;
/*!50001 SET character_set_results     = @saved_cs_results */;
/*!50001 SET collation_connection      = @saved_col_connection */;
/*!50001 DROP VIEW IF EXISTS `v_stok_per_gudang`*/;
/*!50001 SET @saved_cs_client          = @@character_set_client */;
/*!50001 SET @saved_cs_results         = @@character_set_results */;
/*!50001 SET @saved_col_connection     = @@collation_connection */;
/*!50001 SET character_set_client      = utf8mb4 */;
/*!50001 SET character_set_results     = utf8mb4 */;
/*!50001 SET collation_connection      = utf8mb4_unicode_ci */;
/*!50001 CREATE ALGORITHM=UNDEFINED */
/*!50013 DEFINER=`root`@`%` SQL SECURITY DEFINER */
/*!50001 VIEW `v_stok_per_gudang` AS select `g`.`id` AS `gudang_id`,`g`.`nama` AS `nama_gudang`,`b`.`id` AS `barang_id`,`b`.`kode` AS `kode_barang`,`b`.`nama` AS `nama_barang`,`b`.`satuan` AS `satuan`,coalesce(sum(`bb`.`qty_tersisa`),0) AS `qty` from (((`gudang` `g` join `lokasi_rak` `lr` on(`lr`.`gudang_id` = `g`.`id` and `lr`.`deleted_at` is null)) join `batch_barang` `bb` on(`bb`.`lokasi_rak_id` = `lr`.`id` and `bb`.`qty_tersisa` > 0)) join `barang` `b` on(`b`.`id` = `bb`.`barang_id` and `b`.`deleted_at` is null)) where `g`.`deleted_at` is null group by `g`.`id`,`b`.`id` */;
/*!50001 SET character_set_client      = @saved_cs_client */;
/*!50001 SET character_set_results     = @saved_cs_results */;
/*!50001 SET collation_connection      = @saved_col_connection */;
/*!50001 DROP VIEW IF EXISTS `v_stok_rantai`*/;
/*!50001 SET @saved_cs_client          = @@character_set_client */;
/*!50001 SET @saved_cs_results         = @@character_set_results */;
/*!50001 SET @saved_col_connection     = @@collation_connection */;
/*!50001 SET character_set_client      = utf8mb4 */;
/*!50001 SET character_set_results     = utf8mb4 */;
/*!50001 SET collation_connection      = utf8mb4_unicode_ci */;
/*!50001 CREATE ALGORITHM=UNDEFINED */
/*!50013 DEFINER=`root`@`%` SQL SECURITY DEFINER */
/*!50001 VIEW `v_stok_rantai` AS select 'batch' AS `sumber`,`bb`.`barang_id` AS `barang_id`,`b`.`kode` AS `kode_barang`,`b`.`nama` AS `nama_barang`,`b`.`stok_minimum` AS `stok_minimum`,`bb`.`qty_tersisa` AS `qty`,`bb`.`lokasi_rak_id` AS `lokasi_rak_id`,`bb`.`toko_id` AS `toko_id`,NULL AS `supplier_id` from (`batch_barang` `bb` join `barang` `b` on(`b`.`id` = `bb`.`barang_id`)) where `b`.`deleted_at` is null union all select 'supplier' AS `supplier`,`ss`.`barang_id` AS `barang_id`,`b`.`kode` AS `kode`,`b`.`nama` AS `nama`,`b`.`stok_minimum` AS `stok_minimum`,`ss`.`qty` AS `qty`,`ss`.`lokasi_rak_id` AS `lokasi_rak_id`,NULL AS `NULL`,`ss`.`supplier_id` AS `supplier_id` from (`stok_supplier` `ss` join `barang` `b` on(`b`.`id` = `ss`.`barang_id`)) where `b`.`deleted_at` is null */;
/*!50001 SET character_set_client      = @saved_cs_client */;
/*!50001 SET character_set_results     = @saved_cs_results */;
/*!50001 SET collation_connection      = @saved_col_connection */;
/*!50001 DROP VIEW IF EXISTS `v_stok_toko`*/;
/*!50001 SET @saved_cs_client          = @@character_set_client */;
/*!50001 SET @saved_cs_results         = @@character_set_results */;
/*!50001 SET @saved_col_connection     = @@collation_connection */;
/*!50001 SET character_set_client      = utf8mb4 */;
/*!50001 SET character_set_results     = utf8mb4 */;
/*!50001 SET collation_connection      = utf8mb4_unicode_ci */;
/*!50001 CREATE ALGORITHM=UNDEFINED */
/*!50013 DEFINER=`root`@`%` SQL SECURITY DEFINER */
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
-- Tabel yang dibuat oleh kode saat runtime (CREATE TABLE IF NOT EXISTS),
-- tidak ikut dump karena belum pernah terpicu di DB dev. WAJIB ada.
-- =====================================================================

-- _cadangan.php
CREATE TABLE IF NOT EXISTS `cadangan_log` (
  `id`          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `berkas`      VARCHAR(120) NOT NULL,
  `ukuran`      BIGINT UNSIGNED NOT NULL DEFAULT 0,
  `cara`        ENUM('manual','cron','otomatis') NOT NULL DEFAULT 'manual',
  `user_id`     BIGINT UNSIGNED NULL,
  `jml_tabel`   INT NOT NULL DEFAULT 0,
  `jml_baris`   BIGINT NOT NULL DEFAULT 0,
  `drive_id`    VARCHAR(120) NULL,
  `drive_pesan` VARCHAR(255) NULL,
  `created_at`  TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`), KEY `idx_cad_waktu` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- api/dashboard.php (snapshot harian, upsert per CURDATE())
CREATE TABLE IF NOT EXISTS `stok_harian` (
  `tanggal`     DATE NOT NULL PRIMARY KEY,
  `total_qty`   BIGINT NOT NULL DEFAULT 0,
  `total_nilai` DECIMAL(18,2) NOT NULL DEFAULT 0,
  `dicatat_at`  TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- pengguna.php / _lib.php izin_saya(). DDL tidak ada di kode lama;
-- ditulis dari pola pakai: DELETE per user lalu INSERT per kunci katalog.
CREATE TABLE IF NOT EXISTS `izin_pengguna` (
  `user_id`    INT UNSIGNED NOT NULL,
  `kunci`      VARCHAR(40) NOT NULL,
  `nilai`      TINYINT(1) NOT NULL DEFAULT 0,
  `diubah_oleh` INT UNSIGNED NULL,
  `diubah_at`  TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`user_id`,`kunci`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
