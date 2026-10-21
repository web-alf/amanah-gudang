-- =====================================================================
--  AMANAH — penyelarasan skema basis data hosting
-- =====================================================================
--  Jalankan SATU KALI di phpMyAdmin hosting:
--    pilih database  ->  tab Import  ->  pilih berkas ini  ->  Go
--
--  ---------------------------------------------------------------
--  APA YANG DIPERBAIKI
--  ---------------------------------------------------------------
--  Basis data di hosting tertinggal dari yang dipakai mengembangkan
--  aplikasi. Yang hilang di sana, menurut hasil pemeriksaan cek.php:
--
--    * tabel `donatur` (beserta `donasi` dan `donasi_detail`)
--        -> halaman Donatur menjawab 500, dan detail Barang Keluar
--           ikut gagal karena ia menyambung ke tabel donatur
--    * tabel `pelepasan_stok`
--        -> halaman Perbaikan & Pelepasan menjawab 500
--    * kolom `batch_barang`.`sumber`
--        -> daftar Barang Masuk menjawab 500
--
--  Dua view sebelumnya gagal dibuat BUKAN karena viewnya salah,
--  melainkan karena tabel yang dirujuknya belum ada. Keduanya dibuat
--  ulang di bagian akhir berkas ini, sesudah tabelnya tersedia.
--
--  ---------------------------------------------------------------
--  BERKAS INI TIDAK MENGHAPUS APA PUN
--  ---------------------------------------------------------------
--  Tidak ada satu pun DROP TABLE, DELETE, atau TRUNCATE di sini.
--
--    * tabel dibuat dengan CREATE TABLE IF NOT EXISTS -- yang sudah
--      ada dilewati apa adanya, beserta seluruh isinya
--    * kolom ditambahkan hanya bila belum ada, lewat pemeriksaan ke
--      information_schema sebelum tiap ALTER
--    * view dibuat ulang dengan CREATE OR REPLACE
--
--  Menjalankannya dua kali menghasilkan keadaan yang sama dengan
--  menjalankannya sekali.
--
--  Pemeriksaan kunci asing dimatikan selama proses lalu dinyalakan
--  kembali di baris terakhir. Tanpa itu, tabel yang dibuat lebih awal
--  ditolak karena menunjuk tabel yang baru dibuat sesudahnya.
-- =====================================================================

SET FOREIGN_KEY_CHECKS = 0;
SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;

-- =====================================================================
--  BAGIAN 1 - TABEL
-- =====================================================================

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE IF NOT EXISTS `activity_log` (
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
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE IF NOT EXISTS `barang` (
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
  CONSTRAINT `fk_barang_kategori` FOREIGN KEY (`kategori_id`) REFERENCES `kategori` (`id`) ON UPDATE CASCADE,
  CONSTRAINT `fk_barang_rak` FOREIGN KEY (`lokasi_rak_id`) REFERENCES `lokasi_rak` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_barang_supplier` FOREIGN KEY (`supplier_id`) REFERENCES `supplier` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE IF NOT EXISTS `batch_barang` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `barang_id` bigint(20) unsigned NOT NULL,
  `nomor_batch` varchar(50) NOT NULL,
  `kondisi` enum('baru','bekas_layak','bekas_tidak_layak') NOT NULL DEFAULT 'baru' COMMENT 'diwarisi dari penilaian supplier; bekas_tidak_layak tidak boleh disalurkan',
  `sumber` enum('pembelian','donasi') NOT NULL DEFAULT 'pembelian' COMMENT 'asal barang; terpisah dari keadaan fisik di kolom kondisi',
  `lokasi_rak_id` bigint(20) unsigned DEFAULT NULL,
  `toko_id` bigint(20) unsigned DEFAULT NULL,
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
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_batch_lokasi` (`barang_id`,`nomor_batch`,`kondisi`,`sumber`,`donatur_id`,`lokasi_rak_id`,`toko_id`,`tanggal_expired`),
  KEY `idx_batch_barang` (`barang_id`),
  KEY `idx_batch_expired` (`tanggal_expired`),
  KEY `idx_batch_rak` (`lokasi_rak_id`),
  KEY `fk_batch_supplier` (`supplier_id`),
  KEY `idx_batch_toko` (`toko_id`),
  KEY `ix_batch_kondisi` (`kondisi`,`qty_tersisa`),
  KEY `ix_batch_donatur` (`donatur_id`),
  KEY `ix_batch_sumber` (`sumber`),
  CONSTRAINT `fk_batch_barang` FOREIGN KEY (`barang_id`) REFERENCES `barang` (`id`) ON UPDATE CASCADE,
  CONSTRAINT `fk_batch_donatur` FOREIGN KEY (`donatur_id`) REFERENCES `donatur` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_batch_rak` FOREIGN KEY (`lokasi_rak_id`) REFERENCES `lokasi_rak` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_batch_supplier` FOREIGN KEY (`supplier_id`) REFERENCES `supplier` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_batch_toko` FOREIGN KEY (`toko_id`) REFERENCES `toko` (`id`) ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE IF NOT EXISTS `dokumen_counter` (
  `kunci` varchar(120) NOT NULL,
  `urut` bigint(20) unsigned NOT NULL DEFAULT 0,
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`kunci`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE IF NOT EXISTS `donasi` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `no_donasi` varchar(40) NOT NULL,
  `donatur_id` bigint(20) unsigned NOT NULL,
  `supplier_id` bigint(20) unsigned DEFAULT NULL COMMENT 'mitra yang menerima titipan ini',
  `tanggal` date NOT NULL,
  `jenis` enum('umum','kunjungan','paket') NOT NULL DEFAULT 'umum' COMMENT 'bentuk pemberian; berbeda dari cara_terima yang menjawab bagaimana barangnya sampai',
  `cara_terima` enum('diantar','dijemput','dikirim') NOT NULL DEFAULT 'diantar',
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
  KEY `ix_donasi_jenis` (`jenis`),
  CONSTRAINT `fk_donasi_donatur` FOREIGN KEY (`donatur_id`) REFERENCES `donatur` (`id`) ON UPDATE CASCADE,
  CONSTRAINT `fk_donasi_supplier` FOREIGN KEY (`supplier_id`) REFERENCES `supplier` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_donasi_user` FOREIGN KEY (`dibuat_oleh`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE IF NOT EXISTS `donasi_detail` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `donasi_id` bigint(20) unsigned NOT NULL,
  `barang_id` bigint(20) unsigned DEFAULT NULL,
  `nama_barang_teks` varchar(150) DEFAULT NULL COMMENT 'dipakai bila barangnya belum ada di katalog',
  `kondisi` enum('baru','bekas_layak','bekas_tidak_layak') NOT NULL DEFAULT 'baru',
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
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE IF NOT EXISTS `donatur` (
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
  CONSTRAINT `fk_donatur_supplier` FOREIGN KEY (`supplier_id`) REFERENCES `supplier` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE IF NOT EXISTS `gudang` (
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
  CONSTRAINT `fk_gudang_supplier` FOREIGN KEY (`supplier_id`) REFERENCES `supplier` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE IF NOT EXISTS `kategori` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `kode` varchar(20) NOT NULL,
  `nama` varchar(100) NOT NULL,
  `ikon` varchar(50) DEFAULT NULL,
  `keterangan` varchar(255) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_kategori_kode` (`kode`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE IF NOT EXISTS `lokasi_rak` (
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
  CONSTRAINT `fk_rak_gudang` FOREIGN KEY (`gudang_id`) REFERENCES `gudang` (`id`) ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE IF NOT EXISTS `marketplace_akun` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `toko_id` bigint(20) unsigned NOT NULL,
  `platform` enum('shopee','tokopedia','tiktok','lazada') NOT NULL,
  `partner_id` varchar(100) DEFAULT NULL,
  `partner_key` varchar(255) DEFAULT NULL,
  `shop_id` varchar(100) DEFAULT NULL,
  `fs_id` varchar(40) DEFAULT NULL COMMENT 'Fulfillment Service ID ÔÇö khusus Tokopedia, tidak ada padanannya di Shopee',
  `access_token` varchar(512) DEFAULT NULL,
  `refresh_token` varchar(512) DEFAULT NULL,
  `token_expired_at` timestamp NULL DEFAULT NULL,
  `status` enum('belum_tersambung','tersambung','kedaluwarsa','error') NOT NULL DEFAULT 'belum_tersambung',
  `pesan_terakhir` varchar(500) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `nama_shop` varchar(150) DEFAULT NULL COMMENT 'nama toko sebagaimana tercatat di marketplace',
  `terakhir_tarik_at` timestamp NULL DEFAULT NULL,
  `terakhir_segar_at` timestamp NULL DEFAULT NULL COMMENT 'kapan access_token terakhir diperbarui',
  `aktif` tinyint(1) NOT NULL DEFAULT 1 COMMENT 'penarikan otomatis dijalankan atau tidak',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_mpakun_toko` (`toko_id`),
  KEY `ix_mpakun_tarik` (`aktif`,`terakhir_tarik_at`),
  CONSTRAINT `fk_mp_toko` FOREIGN KEY (`toko_id`) REFERENCES `toko` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE IF NOT EXISTS `marketplace_sku` (
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
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE IF NOT EXISTS `marketplace_sync_log` (
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
/*!40101 SET character_set_client = utf8 */;
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
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `ix_tarikan_toko` (`toko_id`,`created_at`),
  KEY `ix_tarikan_pesanan` (`no_pesanan`),
  CONSTRAINT `fk_tarikan_toko` FOREIGN KEY (`toko_id`) REFERENCES `toko` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE IF NOT EXISTS `mutasi_barang` (
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
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE IF NOT EXISTS `notifikasi` (
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
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE IF NOT EXISTS `pelepasan_stok` (
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
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE IF NOT EXISTS `penerima` (
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
  KEY `ix_penerima_wilayah` (`wilayah`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE IF NOT EXISTS `pengaturan` (
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
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE IF NOT EXISTS `pengiriman_supplier` (
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
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE IF NOT EXISTS `pengiriman_supplier_detail` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `pengiriman_supplier_id` bigint(20) unsigned NOT NULL,
  `barang_id` bigint(20) unsigned DEFAULT NULL,
  `nama_barang_teks` varchar(150) DEFAULT NULL,
  `nomor_batch` varchar(50) DEFAULT NULL,
  `kondisi` enum('baru','bekas_layak','bekas_tidak_layak') NOT NULL DEFAULT 'baru' COMMENT 'penilaian supplier, dibaca gudang saat penerimaan',
  `donatur_id` bigint(20) unsigned DEFAULT NULL,
  `sumber` enum('pembelian','donasi') NOT NULL DEFAULT 'pembelian',
  `tanggal_expired` date DEFAULT NULL,
  `qty` int(11) NOT NULL,
  `harga` decimal(15,2) NOT NULL DEFAULT 0.00,
  `keterangan` varchar(255) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_psd_header` (`pengiriman_supplier_id`),
  KEY `fk_psd_barang` (`barang_id`),
  KEY `ix_psd_kondisi` (`kondisi`),
  CONSTRAINT `fk_psd_barang` FOREIGN KEY (`barang_id`) REFERENCES `barang` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_psd_header` FOREIGN KEY (`pengiriman_supplier_id`) REFERENCES `pengiriman_supplier` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE IF NOT EXISTS `penjualan` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `no_penjualan` varchar(40) NOT NULL,
  `toko_id` bigint(20) unsigned NOT NULL,
  `tanggal` datetime NOT NULL,
  `marketplace` enum('shopee','tokopedia','tiktok','lazada','offline','lainnya') NOT NULL DEFAULT 'offline',
  `no_pesanan` varchar(80) DEFAULT NULL,
  `no_resi` varchar(80) DEFAULT NULL COMMENT 'nomor resi dari marketplace',
  `kurir` varchar(80) DEFAULT NULL COMMENT 'jasa kirim',
  `status_kirim` enum('belum','diproses','dikirim','sampai','gagal','kembali') NOT NULL DEFAULT 'belum',
  `tgl_kirim` datetime DEFAULT NULL,
  `tgl_sampai` datetime DEFAULT NULL,
  `nama_pembeli` varchar(150) DEFAULT NULL,
  `total` decimal(15,2) NOT NULL DEFAULT 0.00,
  `ongkir` decimal(15,2) NOT NULL DEFAULT 0.00,
  `status` enum('baru','dikemas','dikirim','selesai','batal') NOT NULL DEFAULT 'selesai',
  `sumber` enum('manual','impor_csv','api') NOT NULL DEFAULT 'manual',
  `catatan` varchar(500) DEFAULT NULL,
  `user_id` bigint(20) unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `escrow_amount` decimal(15,2) DEFAULT NULL COMMENT 'yang benar-benar diterima penjual setelah semua potongan',
  `komisi` decimal(15,2) NOT NULL DEFAULT 0.00 COMMENT 'komisi marketplace',
  `biaya_layanan` decimal(15,2) NOT NULL DEFAULT 0.00,
  `biaya_transaksi` decimal(15,2) NOT NULL DEFAULT 0.00,
  `voucher` decimal(15,2) NOT NULL DEFAULT 0.00 COMMENT 'potongan voucher yang ditanggung penjual',
  `status_bayar` enum('belum','ditahan','cair','sebagian','batal') NOT NULL DEFAULT 'belum' COMMENT 'ditahan = masih di escrow marketplace; cair = sudah masuk saldo penjual',
  `tgl_settlement` date DEFAULT NULL COMMENT 'tanggal dana dilepas marketplace',
  `status_marketplace` varchar(40) DEFAULT NULL COMMENT 'status mentah dari marketplace, mis. READY_TO_SHIP',
  `ditarik_at` timestamp NULL DEFAULT NULL COMMENT 'kapan terakhir data ini ditarik dari marketplace',
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
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE IF NOT EXISTS `penjualan_detail` (
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
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE IF NOT EXISTS `penyesuaian_stok` (
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
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE IF NOT EXISTS `perpindahan` (
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
  CONSTRAINT `fk_pp_approver` FOREIGN KEY (`approved_by`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_pp_penerima` FOREIGN KEY (`diterima_oleh`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_pp_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE IF NOT EXISTS `perpindahan_detail` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `perpindahan_id` bigint(20) unsigned NOT NULL,
  `barang_id` bigint(20) unsigned NOT NULL,
  `batch_id` bigint(20) unsigned DEFAULT NULL,
  `nomor_batch` varchar(50) DEFAULT NULL,
  `kondisi` enum('baru','bekas_layak','bekas_tidak_layak') NOT NULL DEFAULT 'baru' COMMENT 'ikut terbawa dari batch asalnya',
  `donatur_id` bigint(20) unsigned DEFAULT NULL COMMENT 'ikut terbawa dari stok asalnya',
  `sumber` enum('pembelian','donasi') NOT NULL DEFAULT 'pembelian' COMMENT 'ikut terbawa dari batch asalnya',
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
  KEY `ix_pd_donatur` (`donatur_id`),
  CONSTRAINT `fk_pd_donatur` FOREIGN KEY (`donatur_id`) REFERENCES `donatur` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_ppd_barang` FOREIGN KEY (`barang_id`) REFERENCES `barang` (`id`) ON UPDATE CASCADE,
  CONSTRAINT `fk_ppd_batch` FOREIGN KEY (`batch_id`) REFERENCES `batch_barang` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_ppd_header` FOREIGN KEY (`perpindahan_id`) REFERENCES `perpindahan` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE IF NOT EXISTS `resi_scan_log` (
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
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE IF NOT EXISTS `retur` (
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
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE IF NOT EXISTS `roles` (
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
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE IF NOT EXISTS `sesi_perangkat` (
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
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE IF NOT EXISTS `stock_opname` (
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
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE IF NOT EXISTS `stock_opname_detail` (
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
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE IF NOT EXISTS `stok` (
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
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE IF NOT EXISTS `stok_supplier` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `supplier_id` bigint(20) unsigned NOT NULL,
  `donatur_id` bigint(20) unsigned DEFAULT NULL COMMENT 'asal barang bila dari donatur',
  `barang_id` bigint(20) unsigned NOT NULL,
  `lokasi_rak_id` bigint(20) unsigned DEFAULT NULL COMMENT 'rak tempat batch ini berada, di dalam gudang supplier',
  `nomor_batch` varchar(50) NOT NULL,
  `kondisi` enum('baru','bekas_layak','bekas_tidak_layak') NOT NULL DEFAULT 'baru' COMMENT 'dinilai supplier saat mencatat barang; gudang tidak menilai ulang',
  `sumber` enum('pembelian','donasi') NOT NULL DEFAULT 'pembelian' COMMENT 'asal barang; terpisah dari keadaan fisik di kolom kondisi',
  `tanggal_expired` date DEFAULT NULL,
  `qty` int(11) NOT NULL DEFAULT 0,
  `harga` decimal(15,2) NOT NULL DEFAULT 0.00,
  `tanggal_masuk` date NOT NULL,
  `keterangan` varchar(255) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_stoksup` (`supplier_id`,`barang_id`,`nomor_batch`,`kondisi`,`sumber`,`donatur_id`,`tanggal_expired`,`lokasi_rak_id`),
  KEY `idx_ss_supplier` (`supplier_id`),
  KEY `fk_ss_barang` (`barang_id`),
  KEY `ix_ss_rak` (`lokasi_rak_id`),
  KEY `ix_stok_supplier_kondisi` (`kondisi`),
  KEY `ix_stok_supplier_donatur` (`donatur_id`),
  KEY `ix_stok_supplier_sumber` (`sumber`),
  CONSTRAINT `fk_ss_barang` FOREIGN KEY (`barang_id`) REFERENCES `barang` (`id`) ON UPDATE CASCADE,
  CONSTRAINT `fk_ss_supplier` FOREIGN KEY (`supplier_id`) REFERENCES `supplier` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_stok_supplier_donatur` FOREIGN KEY (`donatur_id`) REFERENCES `donatur` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE IF NOT EXISTS `supplier` (
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
  UNIQUE KEY `uq_supplier_kode` (`kode`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE IF NOT EXISTS `toko` (
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
  KEY `idx_toko_marketplace` (`marketplace`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE IF NOT EXISTS `transaksi_keluar` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `no_transaksi` varchar(30) NOT NULL,
  `tanggal` datetime NOT NULL,
  `user_id` bigint(20) unsigned NOT NULL,
  `penerima` varchar(150) NOT NULL,
  `untuk_bayi` varchar(150) DEFAULT NULL,
  `divisi_cabang` varchar(150) DEFAULT NULL,
  `catatan` varchar(255) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_tk_no` (`no_transaksi`),
  KEY `idx_tk_tanggal` (`tanggal`),
  KEY `fk_tk_user` (`user_id`),
  CONSTRAINT `fk_tk_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE IF NOT EXISTS `transaksi_keluar_detail` (
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
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE IF NOT EXISTS `transaksi_masuk` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `no_transaksi` varchar(30) NOT NULL,
  `tanggal` datetime NOT NULL,
  `supplier_id` bigint(20) unsigned DEFAULT NULL,
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
  CONSTRAINT `fk_tm_supplier` FOREIGN KEY (`supplier_id`) REFERENCES `supplier` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_tm_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE IF NOT EXISTS `transaksi_masuk_detail` (
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
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE IF NOT EXISTS `users` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `role_id` bigint(20) unsigned NOT NULL,
  `tingkat` enum('staff','manager') NOT NULL DEFAULT 'staff' COMMENT 'wewenang di dalam perannya; diabaikan untuk super_admin dan pimpinan',
  `supplier_id` bigint(20) unsigned DEFAULT NULL,
  `gudang_id` bigint(20) unsigned DEFAULT NULL COMMENT 'gudang yang dipegang akun Admin Gudang; NULL untuk peran lain',
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
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_users_email` (`email`),
  KEY `idx_users_role` (`role_id`),
  KEY `idx_users_supplier` (`supplier_id`),
  KEY `ix_users_gudang` (`gudang_id`),
  KEY `ix_users_tingkat` (`role_id`,`tingkat`),
  CONSTRAINT `fk_users_gudang` FOREIGN KEY (`gudang_id`) REFERENCES `gudang` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_users_role` FOREIGN KEY (`role_id`) REFERENCES `roles` (`id`) ON UPDATE CASCADE,
  CONSTRAINT `fk_users_supplier` FOREIGN KEY (`supplier_id`) REFERENCES `supplier` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE IF NOT EXISTS `user_gudang` (
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
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE IF NOT EXISTS `user_toko` (
  `user_id` bigint(20) unsigned NOT NULL,
  `toko_id` bigint(20) unsigned NOT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`user_id`,`toko_id`),
  KEY `idx_ut_toko` (`toko_id`),
  CONSTRAINT `fk_ut_toko` FOREIGN KEY (`toko_id`) REFERENCES `toko` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_ut_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

-- =====================================================================
--  BAGIAN 2 - KOLOM
-- =====================================================================
--  Tiap kolom diperiksa lebih dahulu ke information_schema. Bila sudah
--  ada, yang dijalankan hanyalah DO 0 -- perintah yang secara sengaja
--  tidak mengerjakan apa-apa.
--
--  Pola tiga barisnya dipakai karena ADD COLUMN IF NOT EXISTS hanya ada
--  di MariaDB; MySQL menolaknya. Cara ini berjalan di keduanya.
-- =====================================================================

-- ---------------- activity_log
SET @s := (SELECT IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'activity_log' AND COLUMN_NAME = 'user_id'),
  'DO 0', 'ALTER TABLE `activity_log` ADD COLUMN `user_id` bigint(20) unsigned DEFAULT NULL'));
PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p;
SET @s := (SELECT IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'activity_log' AND COLUMN_NAME = 'aksi'),
  'DO 0', 'ALTER TABLE `activity_log` ADD COLUMN `aksi` varchar(100) NOT NULL'));
PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p;
SET @s := (SELECT IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'activity_log' AND COLUMN_NAME = 'modul'),
  'DO 0', 'ALTER TABLE `activity_log` ADD COLUMN `modul` varchar(50) NOT NULL'));
PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p;
SET @s := (SELECT IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'activity_log' AND COLUMN_NAME = 'referensi_id'),
  'DO 0', 'ALTER TABLE `activity_log` ADD COLUMN `referensi_id` bigint(20) unsigned DEFAULT NULL'));
PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p;
SET @s := (SELECT IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'activity_log' AND COLUMN_NAME = 'deskripsi'),
  'DO 0', 'ALTER TABLE `activity_log` ADD COLUMN `deskripsi` varchar(500) DEFAULT NULL'));
PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p;
SET @s := (SELECT IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'activity_log' AND COLUMN_NAME = 'data_lama'),
  'DO 0', 'ALTER TABLE `activity_log` ADD COLUMN `data_lama` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`data_lama`))'));
PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p;
SET @s := (SELECT IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'activity_log' AND COLUMN_NAME = 'data_baru'),
  'DO 0', 'ALTER TABLE `activity_log` ADD COLUMN `data_baru` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`data_baru`))'));
PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p;
SET @s := (SELECT IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'activity_log' AND COLUMN_NAME = 'ip_address'),
  'DO 0', 'ALTER TABLE `activity_log` ADD COLUMN `ip_address` varchar(45) DEFAULT NULL'));
PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p;
SET @s := (SELECT IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'activity_log' AND COLUMN_NAME = 'user_agent'),
  'DO 0', 'ALTER TABLE `activity_log` ADD COLUMN `user_agent` varchar(255) DEFAULT NULL'));
PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p;
SET @s := (SELECT IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'activity_log' AND COLUMN_NAME = 'created_at'),
  'DO 0', 'ALTER TABLE `activity_log` ADD COLUMN `created_at` timestamp NULL DEFAULT current_timestamp()'));
PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p;
-- ---------------- barang
SET @s := (SELECT IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'barang' AND COLUMN_NAME = 'kode'),
  'DO 0', 'ALTER TABLE `barang` ADD COLUMN `kode` varchar(30) NOT NULL'));
PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p;
SET @s := (SELECT IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'barang' AND COLUMN_NAME = 'barcode'),
  'DO 0', 'ALTER TABLE `barang` ADD COLUMN `barcode` varchar(50) DEFAULT NULL'));
PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p;
SET @s := (SELECT IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'barang' AND COLUMN_NAME = 'nama'),
  'DO 0', 'ALTER TABLE `barang` ADD COLUMN `nama` varchar(150) NOT NULL'));
PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p;
SET @s := (SELECT IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'barang' AND COLUMN_NAME = 'kategori_id'),
  'DO 0', 'ALTER TABLE `barang` ADD COLUMN `kategori_id` bigint(20) unsigned NOT NULL'));
PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p;
SET @s := (SELECT IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'barang' AND COLUMN_NAME = 'merek'),
  'DO 0', 'ALTER TABLE `barang` ADD COLUMN `merek` varchar(100) DEFAULT NULL'));
PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p;
SET @s := (SELECT IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'barang' AND COLUMN_NAME = 'ukuran'),
  'DO 0', 'ALTER TABLE `barang` ADD COLUMN `ukuran` varchar(50) DEFAULT NULL'));
PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p;
SET @s := (SELECT IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'barang' AND COLUMN_NAME = 'satuan'),
  'DO 0', 'ALTER TABLE `barang` ADD COLUMN `satuan` enum(''pcs'',''box'',''pack'',''kaleng'',''lusin'',''set'') NOT NULL DEFAULT ''pcs'''));
PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p;
SET @s := (SELECT IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'barang' AND COLUMN_NAME = 'foto'),
  'DO 0', 'ALTER TABLE `barang` ADD COLUMN `foto` varchar(255) DEFAULT NULL'));
PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p;
SET @s := (SELECT IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'barang' AND COLUMN_NAME = 'harga'),
  'DO 0', 'ALTER TABLE `barang` ADD COLUMN `harga` decimal(15,2) NOT NULL DEFAULT 0.00'));
PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p;
SET @s := (SELECT IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'barang' AND COLUMN_NAME = 'stok_minimum'),
  'DO 0', 'ALTER TABLE `barang` ADD COLUMN `stok_minimum` int(11) NOT NULL DEFAULT 0'));
PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p;
SET @s := (SELECT IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'barang' AND COLUMN_NAME = 'supplier_id'),
  'DO 0', 'ALTER TABLE `barang` ADD COLUMN `supplier_id` bigint(20) unsigned DEFAULT NULL'));
PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p;
SET @s := (SELECT IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'barang' AND COLUMN_NAME = 'lokasi_rak_id'),
  'DO 0', 'ALTER TABLE `barang` ADD COLUMN `lokasi_rak_id` bigint(20) unsigned DEFAULT NULL'));
PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p;
SET @s := (SELECT IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'barang' AND COLUMN_NAME = 'keterangan'),
  'DO 0', 'ALTER TABLE `barang` ADD COLUMN `keterangan` text DEFAULT NULL'));
PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p;
SET @s := (SELECT IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'barang' AND COLUMN_NAME = 'is_active'),
  'DO 0', 'ALTER TABLE `barang` ADD COLUMN `is_active` tinyint(1) NOT NULL DEFAULT 1'));
PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p;
SET @s := (SELECT IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'barang' AND COLUMN_NAME = 'created_at'),
  'DO 0', 'ALTER TABLE `barang` ADD COLUMN `created_at` timestamp NULL DEFAULT current_timestamp()'));
PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p;
SET @s := (SELECT IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'barang' AND COLUMN_NAME = 'updated_at'),
  'DO 0', 'ALTER TABLE `barang` ADD COLUMN `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()'));
PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p;
SET @s := (SELECT IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'barang' AND COLUMN_NAME = 'deleted_at'),
  'DO 0', 'ALTER TABLE `barang` ADD COLUMN `deleted_at` timestamp NULL DEFAULT NULL'));
PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p;
-- ---------------- batch_barang
SET @s := (SELECT IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'batch_barang' AND COLUMN_NAME = 'barang_id'),
  'DO 0', 'ALTER TABLE `batch_barang` ADD COLUMN `barang_id` bigint(20) unsigned NOT NULL'));
PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p;
SET @s := (SELECT IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'batch_barang' AND COLUMN_NAME = 'nomor_batch'),
  'DO 0', 'ALTER TABLE `batch_barang` ADD COLUMN `nomor_batch` varchar(50) NOT NULL'));
PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p;
SET @s := (SELECT IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'batch_barang' AND COLUMN_NAME = 'kondisi'),
  'DO 0', 'ALTER TABLE `batch_barang` ADD COLUMN `kondisi` enum(''baru'',''bekas_layak'',''bekas_tidak_layak'') NOT NULL DEFAULT ''baru'' COMMENT ''diwarisi dari penilaian supplier; bekas_tidak_layak tidak boleh disalurkan'''));
PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p;
SET @s := (SELECT IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'batch_barang' AND COLUMN_NAME = 'sumber'),
  'DO 0', 'ALTER TABLE `batch_barang` ADD COLUMN `sumber` enum(''pembelian'',''donasi'') NOT NULL DEFAULT ''pembelian'' COMMENT ''asal barang; terpisah dari keadaan fisik di kolom kondisi'''));
PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p;
SET @s := (SELECT IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'batch_barang' AND COLUMN_NAME = 'lokasi_rak_id'),
  'DO 0', 'ALTER TABLE `batch_barang` ADD COLUMN `lokasi_rak_id` bigint(20) unsigned DEFAULT NULL'));
PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p;
SET @s := (SELECT IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'batch_barang' AND COLUMN_NAME = 'toko_id'),
  'DO 0', 'ALTER TABLE `batch_barang` ADD COLUMN `toko_id` bigint(20) unsigned DEFAULT NULL'));
PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p;
SET @s := (SELECT IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'batch_barang' AND COLUMN_NAME = 'qr_payload'),
  'DO 0', 'ALTER TABLE `batch_barang` ADD COLUMN `qr_payload` varchar(255) DEFAULT NULL'));
PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p;
SET @s := (SELECT IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'batch_barang' AND COLUMN_NAME = 'tanggal_masuk'),
  'DO 0', 'ALTER TABLE `batch_barang` ADD COLUMN `tanggal_masuk` date NOT NULL'));
PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p;
SET @s := (SELECT IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'batch_barang' AND COLUMN_NAME = 'tanggal_expired'),
  'DO 0', 'ALTER TABLE `batch_barang` ADD COLUMN `tanggal_expired` date DEFAULT NULL'));
PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p;
SET @s := (SELECT IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'batch_barang' AND COLUMN_NAME = 'qty_awal'),
  'DO 0', 'ALTER TABLE `batch_barang` ADD COLUMN `qty_awal` int(11) NOT NULL DEFAULT 0'));
PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p;
SET @s := (SELECT IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'batch_barang' AND COLUMN_NAME = 'qty_tersisa'),
  'DO 0', 'ALTER TABLE `batch_barang` ADD COLUMN `qty_tersisa` int(11) NOT NULL DEFAULT 0'));
PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p;
SET @s := (SELECT IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'batch_barang' AND COLUMN_NAME = 'harga_beli'),
  'DO 0', 'ALTER TABLE `batch_barang` ADD COLUMN `harga_beli` decimal(15,2) NOT NULL DEFAULT 0.00'));
PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p;
SET @s := (SELECT IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'batch_barang' AND COLUMN_NAME = 'supplier_id'),
  'DO 0', 'ALTER TABLE `batch_barang` ADD COLUMN `supplier_id` bigint(20) unsigned DEFAULT NULL'));
PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p;
SET @s := (SELECT IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'batch_barang' AND COLUMN_NAME = 'donatur_id'),
  'DO 0', 'ALTER TABLE `batch_barang` ADD COLUMN `donatur_id` bigint(20) unsigned DEFAULT NULL COMMENT ''diwarisi dari stok supplier saat barang diterima gudang'''));
PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p;
SET @s := (SELECT IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'batch_barang' AND COLUMN_NAME = 'created_at'),
  'DO 0', 'ALTER TABLE `batch_barang` ADD COLUMN `created_at` timestamp NULL DEFAULT current_timestamp()'));
PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p;
SET @s := (SELECT IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'batch_barang' AND COLUMN_NAME = 'updated_at'),
  'DO 0', 'ALTER TABLE `batch_barang` ADD COLUMN `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()'));
PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p;
-- ---------------- dokumen_counter
SET @s := (SELECT IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'dokumen_counter' AND COLUMN_NAME = 'kunci'),
  'DO 0', 'ALTER TABLE `dokumen_counter` ADD COLUMN `kunci` varchar(120) NOT NULL'));
PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p;
SET @s := (SELECT IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'dokumen_counter' AND COLUMN_NAME = 'urut'),
  'DO 0', 'ALTER TABLE `dokumen_counter` ADD COLUMN `urut` bigint(20) unsigned NOT NULL DEFAULT 0'));
PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p;
SET @s := (SELECT IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'dokumen_counter' AND COLUMN_NAME = 'updated_at'),
  'DO 0', 'ALTER TABLE `dokumen_counter` ADD COLUMN `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()'));
PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p;
-- ---------------- donasi
SET @s := (SELECT IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'donasi' AND COLUMN_NAME = 'no_donasi'),
  'DO 0', 'ALTER TABLE `donasi` ADD COLUMN `no_donasi` varchar(40) NOT NULL'));
PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p;
SET @s := (SELECT IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'donasi' AND COLUMN_NAME = 'donatur_id'),
  'DO 0', 'ALTER TABLE `donasi` ADD COLUMN `donatur_id` bigint(20) unsigned NOT NULL'));
PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p;
SET @s := (SELECT IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'donasi' AND COLUMN_NAME = 'supplier_id'),
  'DO 0', 'ALTER TABLE `donasi` ADD COLUMN `supplier_id` bigint(20) unsigned DEFAULT NULL COMMENT ''mitra yang menerima titipan ini'''));
PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p;
SET @s := (SELECT IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'donasi' AND COLUMN_NAME = 'tanggal'),
  'DO 0', 'ALTER TABLE `donasi` ADD COLUMN `tanggal` date NOT NULL'));
PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p;
SET @s := (SELECT IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'donasi' AND COLUMN_NAME = 'jenis'),
  'DO 0', 'ALTER TABLE `donasi` ADD COLUMN `jenis` enum(''umum'',''kunjungan'',''paket'') NOT NULL DEFAULT ''umum'' COMMENT ''bentuk pemberian; berbeda dari cara_terima yang menjawab bagaimana barangnya sampai'''));
PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p;
SET @s := (SELECT IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'donasi' AND COLUMN_NAME = 'cara_terima'),
  'DO 0', 'ALTER TABLE `donasi` ADD COLUMN `cara_terima` enum(''diantar'',''dijemput'',''dikirim'') NOT NULL DEFAULT ''diantar'''));
PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p;
SET @s := (SELECT IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'donasi' AND COLUMN_NAME = 'catatan'),
  'DO 0', 'ALTER TABLE `donasi` ADD COLUMN `catatan` varchar(500) DEFAULT NULL'));
PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p;
SET @s := (SELECT IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'donasi' AND COLUMN_NAME = 'dibuat_oleh'),
  'DO 0', 'ALTER TABLE `donasi` ADD COLUMN `dibuat_oleh` bigint(20) unsigned DEFAULT NULL'));
PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p;
SET @s := (SELECT IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'donasi' AND COLUMN_NAME = 'created_at'),
  'DO 0', 'ALTER TABLE `donasi` ADD COLUMN `created_at` timestamp NULL DEFAULT current_timestamp()'));
PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p;
SET @s := (SELECT IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'donasi' AND COLUMN_NAME = 'updated_at'),
  'DO 0', 'ALTER TABLE `donasi` ADD COLUMN `updated_at` timestamp NULL DEFAULT current_timestamp()'));
PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p;
-- ---------------- donasi_detail
SET @s := (SELECT IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'donasi_detail' AND COLUMN_NAME = 'donasi_id'),
  'DO 0', 'ALTER TABLE `donasi_detail` ADD COLUMN `donasi_id` bigint(20) unsigned NOT NULL'));
PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p;
SET @s := (SELECT IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'donasi_detail' AND COLUMN_NAME = 'barang_id'),
  'DO 0', 'ALTER TABLE `donasi_detail` ADD COLUMN `barang_id` bigint(20) unsigned DEFAULT NULL'));
PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p;
SET @s := (SELECT IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'donasi_detail' AND COLUMN_NAME = 'nama_barang_teks'),
  'DO 0', 'ALTER TABLE `donasi_detail` ADD COLUMN `nama_barang_teks` varchar(150) DEFAULT NULL COMMENT ''dipakai bila barangnya belum ada di katalog'''));
PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p;
SET @s := (SELECT IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'donasi_detail' AND COLUMN_NAME = 'kondisi'),
  'DO 0', 'ALTER TABLE `donasi_detail` ADD COLUMN `kondisi` enum(''baru'',''bekas_layak'',''bekas_tidak_layak'') NOT NULL DEFAULT ''baru'''));
PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p;
SET @s := (SELECT IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'donasi_detail' AND COLUMN_NAME = 'nomor_batch'),
  'DO 0', 'ALTER TABLE `donasi_detail` ADD COLUMN `nomor_batch` varchar(50) DEFAULT NULL'));
PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p;
SET @s := (SELECT IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'donasi_detail' AND COLUMN_NAME = 'tanggal_expired'),
  'DO 0', 'ALTER TABLE `donasi_detail` ADD COLUMN `tanggal_expired` date DEFAULT NULL'));
PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p;
SET @s := (SELECT IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'donasi_detail' AND COLUMN_NAME = 'qty'),
  'DO 0', 'ALTER TABLE `donasi_detail` ADD COLUMN `qty` int(11) NOT NULL DEFAULT 0'));
PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p;
SET @s := (SELECT IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'donasi_detail' AND COLUMN_NAME = 'nilai_taksir'),
  'DO 0', 'ALTER TABLE `donasi_detail` ADD COLUMN `nilai_taksir` decimal(15,2) NOT NULL DEFAULT 0.00 COMMENT ''taksiran internal untuk pencatatan; BUKAN nilai untuk keperluan pajak'''));
PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p;
SET @s := (SELECT IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'donasi_detail' AND COLUMN_NAME = 'keterangan'),
  'DO 0', 'ALTER TABLE `donasi_detail` ADD COLUMN `keterangan` varchar(255) DEFAULT NULL'));
PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p;
-- ---------------- donatur
SET @s := (SELECT IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'donatur' AND COLUMN_NAME = 'kode'),
  'DO 0', 'ALTER TABLE `donatur` ADD COLUMN `kode` varchar(20) NOT NULL'));
PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p;
SET @s := (SELECT IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'donatur' AND COLUMN_NAME = 'nama'),
  'DO 0', 'ALTER TABLE `donatur` ADD COLUMN `nama` varchar(150) NOT NULL'));
PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p;
SET @s := (SELECT IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'donatur' AND COLUMN_NAME = 'jenis'),
  'DO 0', 'ALTER TABLE `donatur` ADD COLUMN `jenis` enum(''pribadi'',''komunitas'',''lembaga'') NOT NULL DEFAULT ''pribadi'''));
PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p;
SET @s := (SELECT IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'donatur' AND COLUMN_NAME = 'supplier_id'),
  'DO 0', 'ALTER TABLE `donatur` ADD COLUMN `supplier_id` bigint(20) unsigned DEFAULT NULL'));
PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p;
SET @s := (SELECT IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'donatur' AND COLUMN_NAME = 'telepon'),
  'DO 0', 'ALTER TABLE `donatur` ADD COLUMN `telepon` varchar(30) DEFAULT NULL COMMENT ''data pribadi; disamarkan bagi yang tidak berkepentingan'''));
PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p;
SET @s := (SELECT IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'donatur' AND COLUMN_NAME = 'email'),
  'DO 0', 'ALTER TABLE `donatur` ADD COLUMN `email` varchar(150) DEFAULT NULL COMMENT ''data pribadi'''));
PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p;
SET @s := (SELECT IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'donatur' AND COLUMN_NAME = 'wilayah'),
  'DO 0', 'ALTER TABLE `donatur` ADD COLUMN `wilayah` varchar(100) DEFAULT NULL COMMENT ''kota atau kecamatan; dipakai laporan yang disamarkan'''));
PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p;
SET @s := (SELECT IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'donatur' AND COLUMN_NAME = 'alamat'),
  'DO 0', 'ALTER TABLE `donatur` ADD COLUMN `alamat` varchar(255) DEFAULT NULL COMMENT ''data pribadi'''));
PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p;
SET @s := (SELECT IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'donatur' AND COLUMN_NAME = 'kontak_pic'),
  'DO 0', 'ALTER TABLE `donatur` ADD COLUMN `kontak_pic` varchar(150) DEFAULT NULL COMMENT ''penanggung jawab, untuk komunitas dan lembaga'''));
PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p;
SET @s := (SELECT IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'donatur' AND COLUMN_NAME = 'jml_anggota'),
  'DO 0', 'ALTER TABLE `donatur` ADD COLUMN `jml_anggota` int(10) unsigned DEFAULT NULL COMMENT ''perkiraan anggota komunitas'''));
PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p;
SET @s := (SELECT IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'donatur' AND COLUMN_NAME = 'anonim'),
  'DO 0', 'ALTER TABLE `donatur` ADD COLUMN `anonim` tinyint(1) NOT NULL DEFAULT 0 COMMENT ''donatur minta tidak disebut namanya; wajib dihormati di semua tampilan'''));
PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p;
SET @s := (SELECT IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'donatur' AND COLUMN_NAME = 'keterangan'),
  'DO 0', 'ALTER TABLE `donatur` ADD COLUMN `keterangan` varchar(255) DEFAULT NULL'));
PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p;
SET @s := (SELECT IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'donatur' AND COLUMN_NAME = 'is_active'),
  'DO 0', 'ALTER TABLE `donatur` ADD COLUMN `is_active` tinyint(1) NOT NULL DEFAULT 1'));
PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p;
SET @s := (SELECT IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'donatur' AND COLUMN_NAME = 'created_at'),
  'DO 0', 'ALTER TABLE `donatur` ADD COLUMN `created_at` timestamp NULL DEFAULT current_timestamp()'));
PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p;
SET @s := (SELECT IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'donatur' AND COLUMN_NAME = 'updated_at'),
  'DO 0', 'ALTER TABLE `donatur` ADD COLUMN `updated_at` timestamp NULL DEFAULT current_timestamp()'));
PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p;
SET @s := (SELECT IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'donatur' AND COLUMN_NAME = 'deleted_at'),
  'DO 0', 'ALTER TABLE `donatur` ADD COLUMN `deleted_at` timestamp NULL DEFAULT NULL'));
PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p;
-- ---------------- gudang
SET @s := (SELECT IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'gudang' AND COLUMN_NAME = 'kode'),
  'DO 0', 'ALTER TABLE `gudang` ADD COLUMN `kode` varchar(20) NOT NULL'));
PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p;
SET @s := (SELECT IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'gudang' AND COLUMN_NAME = 'nama'),
  'DO 0', 'ALTER TABLE `gudang` ADD COLUMN `nama` varchar(150) NOT NULL'));
PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p;
SET @s := (SELECT IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'gudang' AND COLUMN_NAME = 'jenis'),
  'DO 0', 'ALTER TABLE `gudang` ADD COLUMN `jenis` enum(''pusat'',''toko'',''supplier'') NOT NULL DEFAULT ''pusat'' COMMENT ''pusat = gudang lembaga; toko = penampung sisi toko; supplier = gudang milik supplier'''));
PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p;
SET @s := (SELECT IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'gudang' AND COLUMN_NAME = 'supplier_id'),
  'DO 0', 'ALTER TABLE `gudang` ADD COLUMN `supplier_id` bigint(20) unsigned DEFAULT NULL COMMENT ''pemilik gudang bila jenis = supplier; NULL untuk gudang lembaga'''));
PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p;
SET @s := (SELECT IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'gudang' AND COLUMN_NAME = 'alamat'),
  'DO 0', 'ALTER TABLE `gudang` ADD COLUMN `alamat` varchar(255) DEFAULT NULL'));
PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p;
SET @s := (SELECT IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'gudang' AND COLUMN_NAME = 'penanggung_jawab'),
  'DO 0', 'ALTER TABLE `gudang` ADD COLUMN `penanggung_jawab` varchar(150) DEFAULT NULL'));
PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p;
SET @s := (SELECT IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'gudang' AND COLUMN_NAME = 'telepon'),
  'DO 0', 'ALTER TABLE `gudang` ADD COLUMN `telepon` varchar(30) DEFAULT NULL'));
PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p;
SET @s := (SELECT IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'gudang' AND COLUMN_NAME = 'keterangan'),
  'DO 0', 'ALTER TABLE `gudang` ADD COLUMN `keterangan` varchar(255) DEFAULT NULL'));
PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p;
SET @s := (SELECT IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'gudang' AND COLUMN_NAME = 'is_active'),
  'DO 0', 'ALTER TABLE `gudang` ADD COLUMN `is_active` tinyint(1) NOT NULL DEFAULT 1'));
PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p;
SET @s := (SELECT IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'gudang' AND COLUMN_NAME = 'created_at'),
  'DO 0', 'ALTER TABLE `gudang` ADD COLUMN `created_at` timestamp NULL DEFAULT current_timestamp()'));
PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p;
SET @s := (SELECT IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'gudang' AND COLUMN_NAME = 'updated_at'),
  'DO 0', 'ALTER TABLE `gudang` ADD COLUMN `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()'));
PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p;
SET @s := (SELECT IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'gudang' AND COLUMN_NAME = 'deleted_at'),
  'DO 0', 'ALTER TABLE `gudang` ADD COLUMN `deleted_at` timestamp NULL DEFAULT NULL'));
PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p;
-- ---------------- kategori
SET @s := (SELECT IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'kategori' AND COLUMN_NAME = 'kode'),
  'DO 0', 'ALTER TABLE `kategori` ADD COLUMN `kode` varchar(20) NOT NULL'));
PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p;
SET @s := (SELECT IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'kategori' AND COLUMN_NAME = 'nama'),
  'DO 0', 'ALTER TABLE `kategori` ADD COLUMN `nama` varchar(100) NOT NULL'));
PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p;
SET @s := (SELECT IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'kategori' AND COLUMN_NAME = 'ikon'),
  'DO 0', 'ALTER TABLE `kategori` ADD COLUMN `ikon` varchar(50) DEFAULT NULL'));
PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p;
SET @s := (SELECT IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'kategori' AND COLUMN_NAME = 'keterangan'),
  'DO 0', 'ALTER TABLE `kategori` ADD COLUMN `keterangan` varchar(255) DEFAULT NULL'));
PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p;
SET @s := (SELECT IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'kategori' AND COLUMN_NAME = 'created_at'),
  'DO 0', 'ALTER TABLE `kategori` ADD COLUMN `created_at` timestamp NULL DEFAULT current_timestamp()'));
PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p;
SET @s := (SELECT IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'kategori' AND COLUMN_NAME = 'updated_at'),
  'DO 0', 'ALTER TABLE `kategori` ADD COLUMN `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()'));
PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p;
SET @s := (SELECT IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'kategori' AND COLUMN_NAME = 'deleted_at'),
  'DO 0', 'ALTER TABLE `kategori` ADD COLUMN `deleted_at` timestamp NULL DEFAULT NULL'));
PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p;
-- ---------------- lokasi_rak
SET @s := (SELECT IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'lokasi_rak' AND COLUMN_NAME = 'kode'),
  'DO 0', 'ALTER TABLE `lokasi_rak` ADD COLUMN `kode` varchar(20) NOT NULL'));
PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p;
SET @s := (SELECT IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'lokasi_rak' AND COLUMN_NAME = 'nama'),
  'DO 0', 'ALTER TABLE `lokasi_rak` ADD COLUMN `nama` varchar(100) NOT NULL'));
PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p;
SET @s := (SELECT IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'lokasi_rak' AND COLUMN_NAME = 'gudang_id'),
  'DO 0', 'ALTER TABLE `lokasi_rak` ADD COLUMN `gudang_id` bigint(20) unsigned DEFAULT NULL'));
PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p;
SET @s := (SELECT IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'lokasi_rak' AND COLUMN_NAME = 'gudang'),
  'DO 0', 'ALTER TABLE `lokasi_rak` ADD COLUMN `gudang` varchar(100) NOT NULL DEFAULT ''Gudang Utama'''));
PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p;
SET @s := (SELECT IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'lokasi_rak' AND COLUMN_NAME = 'kapasitas'),
  'DO 0', 'ALTER TABLE `lokasi_rak` ADD COLUMN `kapasitas` int(10) unsigned DEFAULT NULL'));
PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p;
SET @s := (SELECT IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'lokasi_rak' AND COLUMN_NAME = 'keterangan'),
  'DO 0', 'ALTER TABLE `lokasi_rak` ADD COLUMN `keterangan` varchar(255) DEFAULT NULL'));
PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p;
SET @s := (SELECT IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'lokasi_rak' AND COLUMN_NAME = 'created_at'),
  'DO 0', 'ALTER TABLE `lokasi_rak` ADD COLUMN `created_at` timestamp NULL DEFAULT current_timestamp()'));
PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p;
SET @s := (SELECT IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'lokasi_rak' AND COLUMN_NAME = 'updated_at'),
  'DO 0', 'ALTER TABLE `lokasi_rak` ADD COLUMN `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()'));
PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p;
SET @s := (SELECT IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'lokasi_rak' AND COLUMN_NAME = 'deleted_at'),
  'DO 0', 'ALTER TABLE `lokasi_rak` ADD COLUMN `deleted_at` timestamp NULL DEFAULT NULL'));
PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p;
-- ---------------- marketplace_akun
SET @s := (SELECT IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'marketplace_akun' AND COLUMN_NAME = 'toko_id'),
  'DO 0', 'ALTER TABLE `marketplace_akun` ADD COLUMN `toko_id` bigint(20) unsigned NOT NULL'));
PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p;
SET @s := (SELECT IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'marketplace_akun' AND COLUMN_NAME = 'platform'),
  'DO 0', 'ALTER TABLE `marketplace_akun` ADD COLUMN `platform` enum(''shopee'',''tokopedia'',''tiktok'',''lazada'') NOT NULL'));
PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p;
SET @s := (SELECT IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'marketplace_akun' AND COLUMN_NAME = 'partner_id'),
  'DO 0', 'ALTER TABLE `marketplace_akun` ADD COLUMN `partner_id` varchar(100) DEFAULT NULL'));
PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p;
SET @s := (SELECT IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'marketplace_akun' AND COLUMN_NAME = 'partner_key'),
  'DO 0', 'ALTER TABLE `marketplace_akun` ADD COLUMN `partner_key` varchar(255) DEFAULT NULL'));
PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p;
SET @s := (SELECT IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'marketplace_akun' AND COLUMN_NAME = 'shop_id'),
  'DO 0', 'ALTER TABLE `marketplace_akun` ADD COLUMN `shop_id` varchar(100) DEFAULT NULL'));
PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p;
SET @s := (SELECT IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'marketplace_akun' AND COLUMN_NAME = 'fs_id'),
  'DO 0', 'ALTER TABLE `marketplace_akun` ADD COLUMN `fs_id` varchar(40) DEFAULT NULL COMMENT ''Fulfillment Service ID ÔÇö khusus Tokopedia, tidak ada padanannya di Shopee'''));
PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p;
SET @s := (SELECT IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'marketplace_akun' AND COLUMN_NAME = 'access_token'),
  'DO 0', 'ALTER TABLE `marketplace_akun` ADD COLUMN `access_token` varchar(512) DEFAULT NULL'));
PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p;
SET @s := (SELECT IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'marketplace_akun' AND COLUMN_NAME = 'refresh_token'),
  'DO 0', 'ALTER TABLE `marketplace_akun` ADD COLUMN `refresh_token` varchar(512) DEFAULT NULL'));
PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p;
SET @s := (SELECT IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'marketplace_akun' AND COLUMN_NAME = 'token_expired_at'),
  'DO 0', 'ALTER TABLE `marketplace_akun` ADD COLUMN `token_expired_at` timestamp NULL DEFAULT NULL'));
PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p;
SET @s := (SELECT IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'marketplace_akun' AND COLUMN_NAME = 'status'),
  'DO 0', 'ALTER TABLE `marketplace_akun` ADD COLUMN `status` enum(''belum_tersambung'',''tersambung'',''kedaluwarsa'',''error'') NOT NULL DEFAULT ''belum_tersambung'''));
PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p;
SET @s := (SELECT IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'marketplace_akun' AND COLUMN_NAME = 'pesan_terakhir'),
  'DO 0', 'ALTER TABLE `marketplace_akun` ADD COLUMN `pesan_terakhir` varchar(500) DEFAULT NULL'));
PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p;
SET @s := (SELECT IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'marketplace_akun' AND COLUMN_NAME = 'created_at'),
  'DO 0', 'ALTER TABLE `marketplace_akun` ADD COLUMN `created_at` timestamp NULL DEFAULT current_timestamp()'));
PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p;
SET @s := (SELECT IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'marketplace_akun' AND COLUMN_NAME = 'updated_at'),
  'DO 0', 'ALTER TABLE `marketplace_akun` ADD COLUMN `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()'));
PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p;
SET @s := (SELECT IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'marketplace_akun' AND COLUMN_NAME = 'nama_shop'),
  'DO 0', 'ALTER TABLE `marketplace_akun` ADD COLUMN `nama_shop` varchar(150) DEFAULT NULL COMMENT ''nama toko sebagaimana tercatat di marketplace'''));
PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p;
SET @s := (SELECT IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'marketplace_akun' AND COLUMN_NAME = 'terakhir_tarik_at'),
  'DO 0', 'ALTER TABLE `marketplace_akun` ADD COLUMN `terakhir_tarik_at` timestamp NULL DEFAULT NULL'));
PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p;
SET @s := (SELECT IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'marketplace_akun' AND COLUMN_NAME = 'terakhir_segar_at'),
  'DO 0', 'ALTER TABLE `marketplace_akun` ADD COLUMN `terakhir_segar_at` timestamp NULL DEFAULT NULL COMMENT ''kapan access_token terakhir diperbarui'''));
PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p;
SET @s := (SELECT IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'marketplace_akun' AND COLUMN_NAME = 'aktif'),
  'DO 0', 'ALTER TABLE `marketplace_akun` ADD COLUMN `aktif` tinyint(1) NOT NULL DEFAULT 1 COMMENT ''penarikan otomatis dijalankan atau tidak'''));
PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p;
-- ---------------- marketplace_sku
SET @s := (SELECT IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'marketplace_sku' AND COLUMN_NAME = 'toko_id'),
  'DO 0', 'ALTER TABLE `marketplace_sku` ADD COLUMN `toko_id` bigint(20) unsigned NOT NULL'));
PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p;
SET @s := (SELECT IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'marketplace_sku' AND COLUMN_NAME = 'barang_id'),
  'DO 0', 'ALTER TABLE `marketplace_sku` ADD COLUMN `barang_id` bigint(20) unsigned NOT NULL'));
PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p;
SET @s := (SELECT IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'marketplace_sku' AND COLUMN_NAME = 'sku_marketplace'),
  'DO 0', 'ALTER TABLE `marketplace_sku` ADD COLUMN `sku_marketplace` varchar(80) NOT NULL'));
PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p;
SET @s := (SELECT IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'marketplace_sku' AND COLUMN_NAME = 'nama_di_marketplace'),
  'DO 0', 'ALTER TABLE `marketplace_sku` ADD COLUMN `nama_di_marketplace` varchar(200) DEFAULT NULL'));
PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p;
SET @s := (SELECT IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'marketplace_sku' AND COLUMN_NAME = 'created_at'),
  'DO 0', 'ALTER TABLE `marketplace_sku` ADD COLUMN `created_at` timestamp NULL DEFAULT current_timestamp()'));
PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p;
-- ---------------- marketplace_sync_log
SET @s := (SELECT IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'marketplace_sync_log' AND COLUMN_NAME = 'toko_id'),
  'DO 0', 'ALTER TABLE `marketplace_sync_log` ADD COLUMN `toko_id` bigint(20) unsigned DEFAULT NULL'));
PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p;
SET @s := (SELECT IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'marketplace_sync_log' AND COLUMN_NAME = 'platform'),
  'DO 0', 'ALTER TABLE `marketplace_sync_log` ADD COLUMN `platform` varchar(30) DEFAULT NULL'));
PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p;
SET @s := (SELECT IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'marketplace_sync_log' AND COLUMN_NAME = 'jenis'),
  'DO 0', 'ALTER TABLE `marketplace_sync_log` ADD COLUMN `jenis` enum(''impor_csv'',''tarik_pesanan'',''dorong_stok'') NOT NULL'));
PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p;
SET @s := (SELECT IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'marketplace_sync_log' AND COLUMN_NAME = 'nama_berkas'),
  'DO 0', 'ALTER TABLE `marketplace_sync_log` ADD COLUMN `nama_berkas` varchar(255) DEFAULT NULL'));
PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p;
SET @s := (SELECT IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'marketplace_sync_log' AND COLUMN_NAME = 'jml_baris'),
  'DO 0', 'ALTER TABLE `marketplace_sync_log` ADD COLUMN `jml_baris` int(11) NOT NULL DEFAULT 0'));
PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p;
SET @s := (SELECT IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'marketplace_sync_log' AND COLUMN_NAME = 'jml_sukses'),
  'DO 0', 'ALTER TABLE `marketplace_sync_log` ADD COLUMN `jml_sukses` int(11) NOT NULL DEFAULT 0'));
PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p;
SET @s := (SELECT IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'marketplace_sync_log' AND COLUMN_NAME = 'jml_gagal'),
  'DO 0', 'ALTER TABLE `marketplace_sync_log` ADD COLUMN `jml_gagal` int(11) NOT NULL DEFAULT 0'));
PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p;
SET @s := (SELECT IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'marketplace_sync_log' AND COLUMN_NAME = 'status'),
  'DO 0', 'ALTER TABLE `marketplace_sync_log` ADD COLUMN `status` enum(''sukses'',''sebagian'',''gagal'') NOT NULL DEFAULT ''sukses'''));
PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p;
SET @s := (SELECT IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'marketplace_sync_log' AND COLUMN_NAME = 'pesan'),
  'DO 0', 'ALTER TABLE `marketplace_sync_log` ADD COLUMN `pesan` text DEFAULT NULL'));
PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p;
SET @s := (SELECT IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'marketplace_sync_log' AND COLUMN_NAME = 'user_id'),
  'DO 0', 'ALTER TABLE `marketplace_sync_log` ADD COLUMN `user_id` bigint(20) unsigned DEFAULT NULL'));
PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p;
SET @s := (SELECT IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'marketplace_sync_log' AND COLUMN_NAME = 'created_at'),
  'DO 0', 'ALTER TABLE `marketplace_sync_log` ADD COLUMN `created_at` timestamp NULL DEFAULT current_timestamp()'));
PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p;
-- ---------------- marketplace_tarikan
SET @s := (SELECT IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'marketplace_tarikan' AND COLUMN_NAME = 'toko_id'),
  'DO 0', 'ALTER TABLE `marketplace_tarikan` ADD COLUMN `toko_id` bigint(20) unsigned NOT NULL'));
PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p;
SET @s := (SELECT IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'marketplace_tarikan' AND COLUMN_NAME = 'platform'),
  'DO 0', 'ALTER TABLE `marketplace_tarikan` ADD COLUMN `platform` varchar(30) NOT NULL'));
PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p;
SET @s := (SELECT IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'marketplace_tarikan' AND COLUMN_NAME = 'no_pesanan'),
  'DO 0', 'ALTER TABLE `marketplace_tarikan` ADD COLUMN `no_pesanan` varchar(80) DEFAULT NULL'));
PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p;
SET @s := (SELECT IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'marketplace_tarikan' AND COLUMN_NAME = 'endpoint'),
  'DO 0', 'ALTER TABLE `marketplace_tarikan` ADD COLUMN `endpoint` varchar(120) NOT NULL COMMENT ''jalur API yang dipanggil'''));
PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p;
SET @s := (SELECT IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'marketplace_tarikan' AND COLUMN_NAME = 'kode_http'),
  'DO 0', 'ALTER TABLE `marketplace_tarikan` ADD COLUMN `kode_http` smallint(6) DEFAULT NULL'));
PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p;
SET @s := (SELECT IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'marketplace_tarikan' AND COLUMN_NAME = 'sukses'),
  'DO 0', 'ALTER TABLE `marketplace_tarikan` ADD COLUMN `sukses` tinyint(1) NOT NULL DEFAULT 0'));
PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p;
SET @s := (SELECT IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'marketplace_tarikan' AND COLUMN_NAME = 'pesan'),
  'DO 0', 'ALTER TABLE `marketplace_tarikan` ADD COLUMN `pesan` varchar(500) DEFAULT NULL'));
PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p;
SET @s := (SELECT IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'marketplace_tarikan' AND COLUMN_NAME = 'data_mentah'),
  'DO 0', 'ALTER TABLE `marketplace_tarikan` ADD COLUMN `data_mentah` mediumtext DEFAULT NULL COMMENT ''balasan API apa adanya'''));
PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p;
SET @s := (SELECT IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'marketplace_tarikan' AND COLUMN_NAME = 'created_at'),
  'DO 0', 'ALTER TABLE `marketplace_tarikan` ADD COLUMN `created_at` timestamp NULL DEFAULT current_timestamp()'));
PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p;
-- ---------------- mutasi_barang
SET @s := (SELECT IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'mutasi_barang' AND COLUMN_NAME = 'no_mutasi'),
  'DO 0', 'ALTER TABLE `mutasi_barang` ADD COLUMN `no_mutasi` varchar(30) NOT NULL'));
PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p;
SET @s := (SELECT IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'mutasi_barang' AND COLUMN_NAME = 'tanggal'),
  'DO 0', 'ALTER TABLE `mutasi_barang` ADD COLUMN `tanggal` datetime NOT NULL'));
PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p;
SET @s := (SELECT IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'mutasi_barang' AND COLUMN_NAME = 'barang_id'),
  'DO 0', 'ALTER TABLE `mutasi_barang` ADD COLUMN `barang_id` bigint(20) unsigned NOT NULL'));
PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p;
SET @s := (SELECT IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'mutasi_barang' AND COLUMN_NAME = 'batch_id'),
  'DO 0', 'ALTER TABLE `mutasi_barang` ADD COLUMN `batch_id` bigint(20) unsigned DEFAULT NULL'));
PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p;
SET @s := (SELECT IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'mutasi_barang' AND COLUMN_NAME = 'rak_asal_id'),
  'DO 0', 'ALTER TABLE `mutasi_barang` ADD COLUMN `rak_asal_id` bigint(20) unsigned DEFAULT NULL'));
PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p;
SET @s := (SELECT IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'mutasi_barang' AND COLUMN_NAME = 'rak_tujuan_id'),
  'DO 0', 'ALTER TABLE `mutasi_barang` ADD COLUMN `rak_tujuan_id` bigint(20) unsigned NOT NULL'));
PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p;
SET @s := (SELECT IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'mutasi_barang' AND COLUMN_NAME = 'qty'),
  'DO 0', 'ALTER TABLE `mutasi_barang` ADD COLUMN `qty` int(11) NOT NULL'));
PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p;
SET @s := (SELECT IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'mutasi_barang' AND COLUMN_NAME = 'catatan'),
  'DO 0', 'ALTER TABLE `mutasi_barang` ADD COLUMN `catatan` varchar(255) DEFAULT NULL'));
PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p;
SET @s := (SELECT IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'mutasi_barang' AND COLUMN_NAME = 'user_id'),
  'DO 0', 'ALTER TABLE `mutasi_barang` ADD COLUMN `user_id` bigint(20) unsigned NOT NULL'));
PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p;
SET @s := (SELECT IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'mutasi_barang' AND COLUMN_NAME = 'created_at'),
  'DO 0', 'ALTER TABLE `mutasi_barang` ADD COLUMN `created_at` timestamp NULL DEFAULT current_timestamp()'));
PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p;
SET @s := (SELECT IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'mutasi_barang' AND COLUMN_NAME = 'updated_at'),
  'DO 0', 'ALTER TABLE `mutasi_barang` ADD COLUMN `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()'));
PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p;
-- ---------------- notifikasi
SET @s := (SELECT IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'notifikasi' AND COLUMN_NAME = 'tipe'),
  'DO 0', 'ALTER TABLE `notifikasi` ADD COLUMN `tipe` enum(''stok_habis'',''stok_hampir_habis'',''expired_h90'',''expired_h30'',''expired_h7'',''expired_hari_h'',''opname'',''sistem'') NOT NULL'));
PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p;
SET @s := (SELECT IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'notifikasi' AND COLUMN_NAME = 'judul'),
  'DO 0', 'ALTER TABLE `notifikasi` ADD COLUMN `judul` varchar(150) NOT NULL'));
PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p;
SET @s := (SELECT IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'notifikasi' AND COLUMN_NAME = 'pesan'),
  'DO 0', 'ALTER TABLE `notifikasi` ADD COLUMN `pesan` varchar(500) NOT NULL'));
PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p;
SET @s := (SELECT IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'notifikasi' AND COLUMN_NAME = 'barang_id'),
  'DO 0', 'ALTER TABLE `notifikasi` ADD COLUMN `barang_id` bigint(20) unsigned DEFAULT NULL'));
PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p;
SET @s := (SELECT IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'notifikasi' AND COLUMN_NAME = 'batch_id'),
  'DO 0', 'ALTER TABLE `notifikasi` ADD COLUMN `batch_id` bigint(20) unsigned DEFAULT NULL'));
PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p;
SET @s := (SELECT IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'notifikasi' AND COLUMN_NAME = 'target_role'),
  'DO 0', 'ALTER TABLE `notifikasi` ADD COLUMN `target_role` varchar(50) DEFAULT NULL'));
PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p;
SET @s := (SELECT IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'notifikasi' AND COLUMN_NAME = 'user_id'),
  'DO 0', 'ALTER TABLE `notifikasi` ADD COLUMN `user_id` bigint(20) unsigned DEFAULT NULL'));
PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p;
SET @s := (SELECT IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'notifikasi' AND COLUMN_NAME = 'kanal'),
  'DO 0', 'ALTER TABLE `notifikasi` ADD COLUMN `kanal` set(''in_app'',''whatsapp'',''email'') NOT NULL DEFAULT ''in_app'''));
PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p;
SET @s := (SELECT IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'notifikasi' AND COLUMN_NAME = 'is_read'),
  'DO 0', 'ALTER TABLE `notifikasi` ADD COLUMN `is_read` tinyint(1) NOT NULL DEFAULT 0'));
PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p;
SET @s := (SELECT IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'notifikasi' AND COLUMN_NAME = 'is_sent'),
  'DO 0', 'ALTER TABLE `notifikasi` ADD COLUMN `is_sent` tinyint(1) NOT NULL DEFAULT 0'));
PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p;
SET @s := (SELECT IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'notifikasi' AND COLUMN_NAME = 'created_at'),
  'DO 0', 'ALTER TABLE `notifikasi` ADD COLUMN `created_at` timestamp NULL DEFAULT current_timestamp()'));
PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p;
-- ---------------- pelepasan_stok
SET @s := (SELECT IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'pelepasan_stok' AND COLUMN_NAME = 'no_pelepasan'),
  'DO 0', 'ALTER TABLE `pelepasan_stok` ADD COLUMN `no_pelepasan` varchar(40) NOT NULL'));
PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p;
SET @s := (SELECT IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'pelepasan_stok' AND COLUMN_NAME = 'tanggal'),
  'DO 0', 'ALTER TABLE `pelepasan_stok` ADD COLUMN `tanggal` date NOT NULL'));
PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p;
SET @s := (SELECT IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'pelepasan_stok' AND COLUMN_NAME = 'batch_id'),
  'DO 0', 'ALTER TABLE `pelepasan_stok` ADD COLUMN `batch_id` bigint(20) unsigned DEFAULT NULL'));
PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p;
SET @s := (SELECT IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'pelepasan_stok' AND COLUMN_NAME = 'barang_id'),
  'DO 0', 'ALTER TABLE `pelepasan_stok` ADD COLUMN `barang_id` bigint(20) unsigned NOT NULL'));
PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p;
SET @s := (SELECT IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'pelepasan_stok' AND COLUMN_NAME = 'donatur_id'),
  'DO 0', 'ALTER TABLE `pelepasan_stok` ADD COLUMN `donatur_id` bigint(20) unsigned DEFAULT NULL COMMENT ''disalin dari batch agar tetap terlacak setelah batchnya hilang'''));
PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p;
SET @s := (SELECT IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'pelepasan_stok' AND COLUMN_NAME = 'qty'),
  'DO 0', 'ALTER TABLE `pelepasan_stok` ADD COLUMN `qty` int(11) NOT NULL'));
PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p;
SET @s := (SELECT IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'pelepasan_stok' AND COLUMN_NAME = 'jenis'),
  'DO 0', 'ALTER TABLE `pelepasan_stok` ADD COLUMN `jenis` enum(''perbaikan'',''dibuang'',''dijual'') NOT NULL'));
PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p;
SET @s := (SELECT IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'pelepasan_stok' AND COLUMN_NAME = 'alasan'),
  'DO 0', 'ALTER TABLE `pelepasan_stok` ADD COLUMN `alasan` varchar(255) NOT NULL COMMENT ''wajib; ini barang titipan orang'''));
PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p;
SET @s := (SELECT IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'pelepasan_stok' AND COLUMN_NAME = 'status'),
  'DO 0', 'ALTER TABLE `pelepasan_stok` ADD COLUMN `status` enum(''proses'',''selesai'') NOT NULL DEFAULT ''selesai'''));
PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p;
SET @s := (SELECT IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'pelepasan_stok' AND COLUMN_NAME = 'hasil'),
  'DO 0', 'ALTER TABLE `pelepasan_stok` ADD COLUMN `hasil` enum(''layak'',''dibuang'',''dijual'') DEFAULT NULL COMMENT ''ujung sebuah perbaikan'''));
PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p;
SET @s := (SELECT IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'pelepasan_stok' AND COLUMN_NAME = 'biaya'),
  'DO 0', 'ALTER TABLE `pelepasan_stok` ADD COLUMN `biaya` decimal(15,2) DEFAULT NULL COMMENT ''biaya perbaikan; boleh kosong bila dikerjakan sendiri'''));
PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p;
SET @s := (SELECT IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'pelepasan_stok' AND COLUMN_NAME = 'tanggal_selesai'),
  'DO 0', 'ALTER TABLE `pelepasan_stok` ADD COLUMN `tanggal_selesai` date DEFAULT NULL'));
PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p;
SET @s := (SELECT IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'pelepasan_stok' AND COLUMN_NAME = 'batch_kembali_id'),
  'DO 0', 'ALTER TABLE `pelepasan_stok` ADD COLUMN `batch_kembali_id` bigint(20) unsigned DEFAULT NULL COMMENT ''batch baru bila hasil perbaikannya layak pakai'''));
PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p;
SET @s := (SELECT IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'pelepasan_stok' AND COLUMN_NAME = 'nilai_jual'),
  'DO 0', 'ALTER TABLE `pelepasan_stok` ADD COLUMN `nilai_jual` decimal(15,2) DEFAULT NULL'));
PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p;
SET @s := (SELECT IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'pelepasan_stok' AND COLUMN_NAME = 'no_bukti_setor'),
  'DO 0', 'ALTER TABLE `pelepasan_stok` ADD COLUMN `no_bukti_setor` varchar(60) DEFAULT NULL COMMENT ''nomor bukti setor/transfer hasil penjualan'''));
PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p;
SET @s := (SELECT IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'pelepasan_stok' AND COLUMN_NAME = 'user_id'),
  'DO 0', 'ALTER TABLE `pelepasan_stok` ADD COLUMN `user_id` bigint(20) unsigned DEFAULT NULL'));
PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p;
SET @s := (SELECT IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'pelepasan_stok' AND COLUMN_NAME = 'created_at'),
  'DO 0', 'ALTER TABLE `pelepasan_stok` ADD COLUMN `created_at` timestamp NULL DEFAULT current_timestamp()'));
PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p;
SET @s := (SELECT IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'pelepasan_stok' AND COLUMN_NAME = 'updated_at'),
  'DO 0', 'ALTER TABLE `pelepasan_stok` ADD COLUMN `updated_at` timestamp NULL DEFAULT current_timestamp()'));
PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p;
-- ---------------- penerima
SET @s := (SELECT IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'penerima' AND COLUMN_NAME = 'kode'),
  'DO 0', 'ALTER TABLE `penerima` ADD COLUMN `kode` varchar(20) NOT NULL'));
PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p;
SET @s := (SELECT IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'penerima' AND COLUMN_NAME = 'nama'),
  'DO 0', 'ALTER TABLE `penerima` ADD COLUMN `nama` varchar(150) NOT NULL'));
PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p;
SET @s := (SELECT IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'penerima' AND COLUMN_NAME = 'jenis'),
  'DO 0', 'ALTER TABLE `penerima` ADD COLUMN `jenis` enum(''individu'',''keluarga'',''lembaga'',''lainnya'') NOT NULL DEFAULT ''keluarga'' COMMENT ''lembaga = panti, masjid, sekolah'''));
PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p;
SET @s := (SELECT IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'penerima' AND COLUMN_NAME = 'wilayah'),
  'DO 0', 'ALTER TABLE `penerima` ADD COLUMN `wilayah` varchar(100) DEFAULT NULL COMMENT ''kelurahan / kecamatan / kota'''));
PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p;
SET @s := (SELECT IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'penerima' AND COLUMN_NAME = 'alamat'),
  'DO 0', 'ALTER TABLE `penerima` ADD COLUMN `alamat` varchar(255) DEFAULT NULL'));
PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p;
SET @s := (SELECT IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'penerima' AND COLUMN_NAME = 'telepon'),
  'DO 0', 'ALTER TABLE `penerima` ADD COLUMN `telepon` varchar(30) DEFAULT NULL'));
PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p;
SET @s := (SELECT IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'penerima' AND COLUMN_NAME = 'penanggung_jawab'),
  'DO 0', 'ALTER TABLE `penerima` ADD COLUMN `penanggung_jawab` varchar(150) DEFAULT NULL COMMENT ''nama yang bisa dihubungi'''));
PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p;
SET @s := (SELECT IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'penerima' AND COLUMN_NAME = 'jml_jiwa'),
  'DO 0', 'ALTER TABLE `penerima` ADD COLUMN `jml_jiwa` int(10) unsigned DEFAULT NULL COMMENT ''berapa orang yang ditanggung'''));
PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p;
SET @s := (SELECT IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'penerima' AND COLUMN_NAME = 'keterangan'),
  'DO 0', 'ALTER TABLE `penerima` ADD COLUMN `keterangan` varchar(255) DEFAULT NULL'));
PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p;
SET @s := (SELECT IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'penerima' AND COLUMN_NAME = 'is_active'),
  'DO 0', 'ALTER TABLE `penerima` ADD COLUMN `is_active` tinyint(1) NOT NULL DEFAULT 1'));
PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p;
SET @s := (SELECT IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'penerima' AND COLUMN_NAME = 'created_at'),
  'DO 0', 'ALTER TABLE `penerima` ADD COLUMN `created_at` timestamp NULL DEFAULT current_timestamp()'));
PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p;
SET @s := (SELECT IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'penerima' AND COLUMN_NAME = 'updated_at'),
  'DO 0', 'ALTER TABLE `penerima` ADD COLUMN `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()'));
PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p;
SET @s := (SELECT IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'penerima' AND COLUMN_NAME = 'deleted_at'),
  'DO 0', 'ALTER TABLE `penerima` ADD COLUMN `deleted_at` timestamp NULL DEFAULT NULL'));
PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p;
-- ---------------- pengaturan
SET @s := (SELECT IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'pengaturan' AND COLUMN_NAME = 'kunci'),
  'DO 0', 'ALTER TABLE `pengaturan` ADD COLUMN `kunci` varchar(60) NOT NULL'));
PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p;
SET @s := (SELECT IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'pengaturan' AND COLUMN_NAME = 'nilai'),
  'DO 0', 'ALTER TABLE `pengaturan` ADD COLUMN `nilai` varchar(500) NOT NULL DEFAULT '''''));
PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p;
SET @s := (SELECT IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'pengaturan' AND COLUMN_NAME = 'diubah_oleh'),
  'DO 0', 'ALTER TABLE `pengaturan` ADD COLUMN `diubah_oleh` bigint(20) unsigned DEFAULT NULL'));
PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p;
SET @s := (SELECT IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'pengaturan' AND COLUMN_NAME = 'updated_at'),
  'DO 0', 'ALTER TABLE `pengaturan` ADD COLUMN `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()'));
PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p;
-- ---------------- pengiriman_supplier
SET @s := (SELECT IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'pengiriman_supplier' AND COLUMN_NAME = 'no_pengiriman'),
  'DO 0', 'ALTER TABLE `pengiriman_supplier` ADD COLUMN `no_pengiriman` varchar(40) NOT NULL'));
PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p;
SET @s := (SELECT IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'pengiriman_supplier' AND COLUMN_NAME = 'supplier_id'),
  'DO 0', 'ALTER TABLE `pengiriman_supplier` ADD COLUMN `supplier_id` bigint(20) unsigned NOT NULL'));
PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p;
SET @s := (SELECT IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'pengiriman_supplier' AND COLUMN_NAME = 'gudang_tujuan_id'),
  'DO 0', 'ALTER TABLE `pengiriman_supplier` ADD COLUMN `gudang_tujuan_id` bigint(20) unsigned DEFAULT NULL'));
PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p;
SET @s := (SELECT IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'pengiriman_supplier' AND COLUMN_NAME = 'no_resi'),
  'DO 0', 'ALTER TABLE `pengiriman_supplier` ADD COLUMN `no_resi` varchar(60) DEFAULT NULL'));
PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p;
SET @s := (SELECT IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'pengiriman_supplier' AND COLUMN_NAME = 'ekspedisi'),
  'DO 0', 'ALTER TABLE `pengiriman_supplier` ADD COLUMN `ekspedisi` varchar(100) DEFAULT NULL'));
PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p;
SET @s := (SELECT IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'pengiriman_supplier' AND COLUMN_NAME = 'tanggal_kirim'),
  'DO 0', 'ALTER TABLE `pengiriman_supplier` ADD COLUMN `tanggal_kirim` date DEFAULT NULL'));
PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p;
SET @s := (SELECT IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'pengiriman_supplier' AND COLUMN_NAME = 'estimasi_tiba'),
  'DO 0', 'ALTER TABLE `pengiriman_supplier` ADD COLUMN `estimasi_tiba` date DEFAULT NULL'));
PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p;
SET @s := (SELECT IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'pengiriman_supplier' AND COLUMN_NAME = 'status'),
  'DO 0', 'ALTER TABLE `pengiriman_supplier` ADD COLUMN `status` enum(''dijadwalkan'',''dikirim'',''tiba'',''diterima'',''dibatalkan'') NOT NULL DEFAULT ''dijadwalkan'''));
PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p;
SET @s := (SELECT IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'pengiriman_supplier' AND COLUMN_NAME = 'perpindahan_id'),
  'DO 0', 'ALTER TABLE `pengiriman_supplier` ADD COLUMN `perpindahan_id` bigint(20) unsigned DEFAULT NULL'));
PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p;
SET @s := (SELECT IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'pengiriman_supplier' AND COLUMN_NAME = 'catatan'),
  'DO 0', 'ALTER TABLE `pengiriman_supplier` ADD COLUMN `catatan` varchar(500) DEFAULT NULL'));
PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p;
SET @s := (SELECT IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'pengiriman_supplier' AND COLUMN_NAME = 'dibuat_oleh'),
  'DO 0', 'ALTER TABLE `pengiriman_supplier` ADD COLUMN `dibuat_oleh` bigint(20) unsigned DEFAULT NULL'));
PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p;
SET @s := (SELECT IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'pengiriman_supplier' AND COLUMN_NAME = 'diterima_oleh'),
  'DO 0', 'ALTER TABLE `pengiriman_supplier` ADD COLUMN `diterima_oleh` bigint(20) unsigned DEFAULT NULL'));
PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p;
SET @s := (SELECT IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'pengiriman_supplier' AND COLUMN_NAME = 'diterima_at'),
  'DO 0', 'ALTER TABLE `pengiriman_supplier` ADD COLUMN `diterima_at` timestamp NULL DEFAULT NULL'));
PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p;
SET @s := (SELECT IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'pengiriman_supplier' AND COLUMN_NAME = 'created_at'),
  'DO 0', 'ALTER TABLE `pengiriman_supplier` ADD COLUMN `created_at` timestamp NULL DEFAULT current_timestamp()'));
PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p;
SET @s := (SELECT IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'pengiriman_supplier' AND COLUMN_NAME = 'updated_at'),
  'DO 0', 'ALTER TABLE `pengiriman_supplier` ADD COLUMN `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()'));
PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p;
-- ---------------- pengiriman_supplier_detail
SET @s := (SELECT IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'pengiriman_supplier_detail' AND COLUMN_NAME = 'pengiriman_supplier_id'),
  'DO 0', 'ALTER TABLE `pengiriman_supplier_detail` ADD COLUMN `pengiriman_supplier_id` bigint(20) unsigned NOT NULL'));
PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p;
SET @s := (SELECT IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'pengiriman_supplier_detail' AND COLUMN_NAME = 'barang_id'),
  'DO 0', 'ALTER TABLE `pengiriman_supplier_detail` ADD COLUMN `barang_id` bigint(20) unsigned DEFAULT NULL'));
PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p;
SET @s := (SELECT IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'pengiriman_supplier_detail' AND COLUMN_NAME = 'nama_barang_teks'),
  'DO 0', 'ALTER TABLE `pengiriman_supplier_detail` ADD COLUMN `nama_barang_teks` varchar(150) DEFAULT NULL'));
PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p;
SET @s := (SELECT IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'pengiriman_supplier_detail' AND COLUMN_NAME = 'nomor_batch'),
  'DO 0', 'ALTER TABLE `pengiriman_supplier_detail` ADD COLUMN `nomor_batch` varchar(50) DEFAULT NULL'));
PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p;
SET @s := (SELECT IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'pengiriman_supplier_detail' AND COLUMN_NAME = 'kondisi'),
  'DO 0', 'ALTER TABLE `pengiriman_supplier_detail` ADD COLUMN `kondisi` enum(''baru'',''bekas_layak'',''bekas_tidak_layak'') NOT NULL DEFAULT ''baru'' COMMENT ''penilaian supplier, dibaca gudang saat penerimaan'''));
PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p;
SET @s := (SELECT IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'pengiriman_supplier_detail' AND COLUMN_NAME = 'donatur_id'),
  'DO 0', 'ALTER TABLE `pengiriman_supplier_detail` ADD COLUMN `donatur_id` bigint(20) unsigned DEFAULT NULL'));
PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p;
SET @s := (SELECT IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'pengiriman_supplier_detail' AND COLUMN_NAME = 'sumber'),
  'DO 0', 'ALTER TABLE `pengiriman_supplier_detail` ADD COLUMN `sumber` enum(''pembelian'',''donasi'') NOT NULL DEFAULT ''pembelian'''));
PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p;
SET @s := (SELECT IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'pengiriman_supplier_detail' AND COLUMN_NAME = 'tanggal_expired'),
  'DO 0', 'ALTER TABLE `pengiriman_supplier_detail` ADD COLUMN `tanggal_expired` date DEFAULT NULL'));
PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p;
SET @s := (SELECT IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'pengiriman_supplier_detail' AND COLUMN_NAME = 'qty'),
  'DO 0', 'ALTER TABLE `pengiriman_supplier_detail` ADD COLUMN `qty` int(11) NOT NULL'));
PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p;
SET @s := (SELECT IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'pengiriman_supplier_detail' AND COLUMN_NAME = 'harga'),
  'DO 0', 'ALTER TABLE `pengiriman_supplier_detail` ADD COLUMN `harga` decimal(15,2) NOT NULL DEFAULT 0.00'));
PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p;
SET @s := (SELECT IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'pengiriman_supplier_detail' AND COLUMN_NAME = 'keterangan'),
  'DO 0', 'ALTER TABLE `pengiriman_supplier_detail` ADD COLUMN `keterangan` varchar(255) DEFAULT NULL'));
PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p;
-- ---------------- penjualan
SET @s := (SELECT IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'penjualan' AND COLUMN_NAME = 'no_penjualan'),
  'DO 0', 'ALTER TABLE `penjualan` ADD COLUMN `no_penjualan` varchar(40) NOT NULL'));
PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p;
SET @s := (SELECT IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'penjualan' AND COLUMN_NAME = 'toko_id'),
  'DO 0', 'ALTER TABLE `penjualan` ADD COLUMN `toko_id` bigint(20) unsigned NOT NULL'));
PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p;
SET @s := (SELECT IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'penjualan' AND COLUMN_NAME = 'tanggal'),
  'DO 0', 'ALTER TABLE `penjualan` ADD COLUMN `tanggal` datetime NOT NULL'));
PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p;
SET @s := (SELECT IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'penjualan' AND COLUMN_NAME = 'marketplace'),
  'DO 0', 'ALTER TABLE `penjualan` ADD COLUMN `marketplace` enum(''shopee'',''tokopedia'',''tiktok'',''lazada'',''offline'',''lainnya'') NOT NULL DEFAULT ''offline'''));
PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p;
SET @s := (SELECT IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'penjualan' AND COLUMN_NAME = 'no_pesanan'),
  'DO 0', 'ALTER TABLE `penjualan` ADD COLUMN `no_pesanan` varchar(80) DEFAULT NULL'));
PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p;
SET @s := (SELECT IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'penjualan' AND COLUMN_NAME = 'no_resi'),
  'DO 0', 'ALTER TABLE `penjualan` ADD COLUMN `no_resi` varchar(80) DEFAULT NULL COMMENT ''nomor resi dari marketplace'''));
PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p;
SET @s := (SELECT IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'penjualan' AND COLUMN_NAME = 'kurir'),
  'DO 0', 'ALTER TABLE `penjualan` ADD COLUMN `kurir` varchar(80) DEFAULT NULL COMMENT ''jasa kirim'''));
PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p;
SET @s := (SELECT IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'penjualan' AND COLUMN_NAME = 'status_kirim'),
  'DO 0', 'ALTER TABLE `penjualan` ADD COLUMN `status_kirim` enum(''belum'',''diproses'',''dikirim'',''sampai'',''gagal'',''kembali'') NOT NULL DEFAULT ''belum'''));
PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p;
SET @s := (SELECT IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'penjualan' AND COLUMN_NAME = 'tgl_kirim'),
  'DO 0', 'ALTER TABLE `penjualan` ADD COLUMN `tgl_kirim` datetime DEFAULT NULL'));
PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p;
SET @s := (SELECT IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'penjualan' AND COLUMN_NAME = 'tgl_sampai'),
  'DO 0', 'ALTER TABLE `penjualan` ADD COLUMN `tgl_sampai` datetime DEFAULT NULL'));
PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p;
SET @s := (SELECT IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'penjualan' AND COLUMN_NAME = 'nama_pembeli'),
  'DO 0', 'ALTER TABLE `penjualan` ADD COLUMN `nama_pembeli` varchar(150) DEFAULT NULL'));
PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p;
SET @s := (SELECT IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'penjualan' AND COLUMN_NAME = 'total'),
  'DO 0', 'ALTER TABLE `penjualan` ADD COLUMN `total` decimal(15,2) NOT NULL DEFAULT 0.00'));
PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p;
SET @s := (SELECT IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'penjualan' AND COLUMN_NAME = 'ongkir'),
  'DO 0', 'ALTER TABLE `penjualan` ADD COLUMN `ongkir` decimal(15,2) NOT NULL DEFAULT 0.00'));
PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p;
SET @s := (SELECT IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'penjualan' AND COLUMN_NAME = 'status'),
  'DO 0', 'ALTER TABLE `penjualan` ADD COLUMN `status` enum(''baru'',''dikemas'',''dikirim'',''selesai'',''batal'') NOT NULL DEFAULT ''selesai'''));
PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p;
SET @s := (SELECT IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'penjualan' AND COLUMN_NAME = 'sumber'),
  'DO 0', 'ALTER TABLE `penjualan` ADD COLUMN `sumber` enum(''manual'',''impor_csv'',''api'') NOT NULL DEFAULT ''manual'''));
PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p;
SET @s := (SELECT IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'penjualan' AND COLUMN_NAME = 'catatan'),
  'DO 0', 'ALTER TABLE `penjualan` ADD COLUMN `catatan` varchar(500) DEFAULT NULL'));
PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p;
SET @s := (SELECT IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'penjualan' AND COLUMN_NAME = 'user_id'),
  'DO 0', 'ALTER TABLE `penjualan` ADD COLUMN `user_id` bigint(20) unsigned DEFAULT NULL'));
PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p;
SET @s := (SELECT IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'penjualan' AND COLUMN_NAME = 'created_at'),
  'DO 0', 'ALTER TABLE `penjualan` ADD COLUMN `created_at` timestamp NULL DEFAULT current_timestamp()'));
PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p;
SET @s := (SELECT IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'penjualan' AND COLUMN_NAME = 'updated_at'),
  'DO 0', 'ALTER TABLE `penjualan` ADD COLUMN `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()'));
PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p;
SET @s := (SELECT IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'penjualan' AND COLUMN_NAME = 'escrow_amount'),
  'DO 0', 'ALTER TABLE `penjualan` ADD COLUMN `escrow_amount` decimal(15,2) DEFAULT NULL COMMENT ''yang benar-benar diterima penjual setelah semua potongan'''));
PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p;
SET @s := (SELECT IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'penjualan' AND COLUMN_NAME = 'komisi'),
  'DO 0', 'ALTER TABLE `penjualan` ADD COLUMN `komisi` decimal(15,2) NOT NULL DEFAULT 0.00 COMMENT ''komisi marketplace'''));
PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p;
SET @s := (SELECT IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'penjualan' AND COLUMN_NAME = 'biaya_layanan'),
  'DO 0', 'ALTER TABLE `penjualan` ADD COLUMN `biaya_layanan` decimal(15,2) NOT NULL DEFAULT 0.00'));
PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p;
SET @s := (SELECT IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'penjualan' AND COLUMN_NAME = 'biaya_transaksi'),
  'DO 0', 'ALTER TABLE `penjualan` ADD COLUMN `biaya_transaksi` decimal(15,2) NOT NULL DEFAULT 0.00'));
PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p;
SET @s := (SELECT IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'penjualan' AND COLUMN_NAME = 'voucher'),
  'DO 0', 'ALTER TABLE `penjualan` ADD COLUMN `voucher` decimal(15,2) NOT NULL DEFAULT 0.00 COMMENT ''potongan voucher yang ditanggung penjual'''));
PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p;
SET @s := (SELECT IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'penjualan' AND COLUMN_NAME = 'status_bayar'),
  'DO 0', 'ALTER TABLE `penjualan` ADD COLUMN `status_bayar` enum(''belum'',''ditahan'',''cair'',''sebagian'',''batal'') NOT NULL DEFAULT ''belum'' COMMENT ''ditahan = masih di escrow marketplace; cair = sudah masuk saldo penjual'''));
PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p;
SET @s := (SELECT IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'penjualan' AND COLUMN_NAME = 'tgl_settlement'),
  'DO 0', 'ALTER TABLE `penjualan` ADD COLUMN `tgl_settlement` date DEFAULT NULL COMMENT ''tanggal dana dilepas marketplace'''));
PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p;
SET @s := (SELECT IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'penjualan' AND COLUMN_NAME = 'status_marketplace'),
  'DO 0', 'ALTER TABLE `penjualan` ADD COLUMN `status_marketplace` varchar(40) DEFAULT NULL COMMENT ''status mentah dari marketplace, mis. READY_TO_SHIP'''));
PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p;
SET @s := (SELECT IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'penjualan' AND COLUMN_NAME = 'ditarik_at'),
  'DO 0', 'ALTER TABLE `penjualan` ADD COLUMN `ditarik_at` timestamp NULL DEFAULT NULL COMMENT ''kapan terakhir data ini ditarik dari marketplace'''));
PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p;
-- ---------------- penjualan_detail
SET @s := (SELECT IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'penjualan_detail' AND COLUMN_NAME = 'penjualan_id'),
  'DO 0', 'ALTER TABLE `penjualan_detail` ADD COLUMN `penjualan_id` bigint(20) unsigned NOT NULL'));
PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p;
SET @s := (SELECT IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'penjualan_detail' AND COLUMN_NAME = 'barang_id'),
  'DO 0', 'ALTER TABLE `penjualan_detail` ADD COLUMN `barang_id` bigint(20) unsigned NOT NULL'));
PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p;
SET @s := (SELECT IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'penjualan_detail' AND COLUMN_NAME = 'batch_id'),
  'DO 0', 'ALTER TABLE `penjualan_detail` ADD COLUMN `batch_id` bigint(20) unsigned DEFAULT NULL'));
PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p;
SET @s := (SELECT IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'penjualan_detail' AND COLUMN_NAME = 'sku_marketplace'),
  'DO 0', 'ALTER TABLE `penjualan_detail` ADD COLUMN `sku_marketplace` varchar(80) DEFAULT NULL'));
PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p;
SET @s := (SELECT IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'penjualan_detail' AND COLUMN_NAME = 'qty'),
  'DO 0', 'ALTER TABLE `penjualan_detail` ADD COLUMN `qty` int(11) NOT NULL'));
PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p;
SET @s := (SELECT IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'penjualan_detail' AND COLUMN_NAME = 'harga_jual'),
  'DO 0', 'ALTER TABLE `penjualan_detail` ADD COLUMN `harga_jual` decimal(15,2) NOT NULL DEFAULT 0.00'));
PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p;
SET @s := (SELECT IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'penjualan_detail' AND COLUMN_NAME = 'subtotal'),
  'DO 0', 'ALTER TABLE `penjualan_detail` ADD COLUMN `subtotal` decimal(15,2) NOT NULL DEFAULT 0.00'));
PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p;
SET @s := (SELECT IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'penjualan_detail' AND COLUMN_NAME = 'keterangan'),
  'DO 0', 'ALTER TABLE `penjualan_detail` ADD COLUMN `keterangan` varchar(255) DEFAULT NULL'));
PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p;
-- ---------------- penyesuaian_stok
SET @s := (SELECT IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'penyesuaian_stok' AND COLUMN_NAME = 'no_penyesuaian'),
  'DO 0', 'ALTER TABLE `penyesuaian_stok` ADD COLUMN `no_penyesuaian` varchar(40) NOT NULL'));
PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p;
SET @s := (SELECT IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'penyesuaian_stok' AND COLUMN_NAME = 'tanggal'),
  'DO 0', 'ALTER TABLE `penyesuaian_stok` ADD COLUMN `tanggal` datetime NOT NULL'));
PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p;
SET @s := (SELECT IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'penyesuaian_stok' AND COLUMN_NAME = 'lokasi_tipe'),
  'DO 0', 'ALTER TABLE `penyesuaian_stok` ADD COLUMN `lokasi_tipe` enum(''gudang'',''toko'') NOT NULL'));
PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p;
SET @s := (SELECT IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'penyesuaian_stok' AND COLUMN_NAME = 'toko_id'),
  'DO 0', 'ALTER TABLE `penyesuaian_stok` ADD COLUMN `toko_id` bigint(20) unsigned DEFAULT NULL'));
PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p;
SET @s := (SELECT IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'penyesuaian_stok' AND COLUMN_NAME = 'lokasi_rak_id'),
  'DO 0', 'ALTER TABLE `penyesuaian_stok` ADD COLUMN `lokasi_rak_id` bigint(20) unsigned DEFAULT NULL'));
PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p;
SET @s := (SELECT IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'penyesuaian_stok' AND COLUMN_NAME = 'barang_id'),
  'DO 0', 'ALTER TABLE `penyesuaian_stok` ADD COLUMN `barang_id` bigint(20) unsigned NOT NULL'));
PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p;
SET @s := (SELECT IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'penyesuaian_stok' AND COLUMN_NAME = 'batch_id'),
  'DO 0', 'ALTER TABLE `penyesuaian_stok` ADD COLUMN `batch_id` bigint(20) unsigned DEFAULT NULL'));
PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p;
SET @s := (SELECT IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'penyesuaian_stok' AND COLUMN_NAME = 'jenis'),
  'DO 0', 'ALTER TABLE `penyesuaian_stok` ADD COLUMN `jenis` enum(''rusak'',''hilang'',''kedaluwarsa'',''koreksi_tambah'',''koreksi_kurang'') NOT NULL'));
PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p;
SET @s := (SELECT IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'penyesuaian_stok' AND COLUMN_NAME = 'qty'),
  'DO 0', 'ALTER TABLE `penyesuaian_stok` ADD COLUMN `qty` int(11) NOT NULL'));
PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p;
SET @s := (SELECT IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'penyesuaian_stok' AND COLUMN_NAME = 'alasan'),
  'DO 0', 'ALTER TABLE `penyesuaian_stok` ADD COLUMN `alasan` varchar(255) DEFAULT NULL'));
PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p;
SET @s := (SELECT IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'penyesuaian_stok' AND COLUMN_NAME = 'user_id'),
  'DO 0', 'ALTER TABLE `penyesuaian_stok` ADD COLUMN `user_id` bigint(20) unsigned NOT NULL'));
PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p;
SET @s := (SELECT IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'penyesuaian_stok' AND COLUMN_NAME = 'created_at'),
  'DO 0', 'ALTER TABLE `penyesuaian_stok` ADD COLUMN `created_at` timestamp NULL DEFAULT current_timestamp()'));
PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p;
-- ---------------- perpindahan
SET @s := (SELECT IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'perpindahan' AND COLUMN_NAME = 'no_perpindahan'),
  'DO 0', 'ALTER TABLE `perpindahan` ADD COLUMN `no_perpindahan` varchar(40) NOT NULL'));
PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p;
SET @s := (SELECT IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'perpindahan' AND COLUMN_NAME = 'no_surat_jalan'),
  'DO 0', 'ALTER TABLE `perpindahan` ADD COLUMN `no_surat_jalan` varchar(40) DEFAULT NULL'));
PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p;
SET @s := (SELECT IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'perpindahan' AND COLUMN_NAME = 'jenis'),
  'DO 0', 'ALTER TABLE `perpindahan` ADD COLUMN `jenis` enum(''penerimaan'',''transfer_gudang'',''mutasi_rak'',''penyaluran'',''retur'',''kirim_toko'',''retur_toko'',''antar_toko'') NOT NULL'));
PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p;
SET @s := (SELECT IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'perpindahan' AND COLUMN_NAME = 'tanggal'),
  'DO 0', 'ALTER TABLE `perpindahan` ADD COLUMN `tanggal` datetime NOT NULL'));
PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p;
SET @s := (SELECT IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'perpindahan' AND COLUMN_NAME = 'asal_tipe'),
  'DO 0', 'ALTER TABLE `perpindahan` ADD COLUMN `asal_tipe` enum(''supplier'',''gudang'',''rak'',''toko'',''eksternal'') NOT NULL'));
PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p;
SET @s := (SELECT IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'perpindahan' AND COLUMN_NAME = 'asal_id'),
  'DO 0', 'ALTER TABLE `perpindahan` ADD COLUMN `asal_id` bigint(20) unsigned DEFAULT NULL'));
PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p;
SET @s := (SELECT IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'perpindahan' AND COLUMN_NAME = 'asal_teks'),
  'DO 0', 'ALTER TABLE `perpindahan` ADD COLUMN `asal_teks` varchar(150) DEFAULT NULL'));
PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p;
SET @s := (SELECT IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'perpindahan' AND COLUMN_NAME = 'tujuan_tipe'),
  'DO 0', 'ALTER TABLE `perpindahan` ADD COLUMN `tujuan_tipe` enum(''gudang'',''rak'',''toko'',''penerima'',''supplier'') NOT NULL'));
PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p;
SET @s := (SELECT IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'perpindahan' AND COLUMN_NAME = 'tujuan_id'),
  'DO 0', 'ALTER TABLE `perpindahan` ADD COLUMN `tujuan_id` bigint(20) unsigned DEFAULT NULL'));
PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p;
SET @s := (SELECT IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'perpindahan' AND COLUMN_NAME = 'tujuan_teks'),
  'DO 0', 'ALTER TABLE `perpindahan` ADD COLUMN `tujuan_teks` varchar(150) DEFAULT NULL'));
PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p;
SET @s := (SELECT IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'perpindahan' AND COLUMN_NAME = 'no_resi'),
  'DO 0', 'ALTER TABLE `perpindahan` ADD COLUMN `no_resi` varchar(60) DEFAULT NULL'));
PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p;
SET @s := (SELECT IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'perpindahan' AND COLUMN_NAME = 'ekspedisi'),
  'DO 0', 'ALTER TABLE `perpindahan` ADD COLUMN `ekspedisi` varchar(100) DEFAULT NULL'));
PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p;
SET @s := (SELECT IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'perpindahan' AND COLUMN_NAME = 'kendaraan'),
  'DO 0', 'ALTER TABLE `perpindahan` ADD COLUMN `kendaraan` varchar(100) DEFAULT NULL'));
PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p;
SET @s := (SELECT IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'perpindahan' AND COLUMN_NAME = 'no_polisi'),
  'DO 0', 'ALTER TABLE `perpindahan` ADD COLUMN `no_polisi` varchar(20) DEFAULT NULL'));
PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p;
SET @s := (SELECT IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'perpindahan' AND COLUMN_NAME = 'pengirim'),
  'DO 0', 'ALTER TABLE `perpindahan` ADD COLUMN `pengirim` varchar(150) DEFAULT NULL'));
PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p;
SET @s := (SELECT IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'perpindahan' AND COLUMN_NAME = 'penerima'),
  'DO 0', 'ALTER TABLE `perpindahan` ADD COLUMN `penerima` varchar(150) DEFAULT NULL'));
PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p;
SET @s := (SELECT IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'perpindahan' AND COLUMN_NAME = 'untuk_program'),
  'DO 0', 'ALTER TABLE `perpindahan` ADD COLUMN `untuk_program` varchar(150) DEFAULT NULL'));
PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p;
SET @s := (SELECT IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'perpindahan' AND COLUMN_NAME = 'status'),
  'DO 0', 'ALTER TABLE `perpindahan` ADD COLUMN `status` enum(''draft'',''menunggu_persetujuan'',''disetujui'',''ditolak'',''dikirim'',''diterima'',''selesai'') NOT NULL DEFAULT ''draft'''));
PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p;
SET @s := (SELECT IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'perpindahan' AND COLUMN_NAME = 'alasan_tolak'),
  'DO 0', 'ALTER TABLE `perpindahan` ADD COLUMN `alasan_tolak` varchar(255) DEFAULT NULL'));
PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p;
SET @s := (SELECT IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'perpindahan' AND COLUMN_NAME = 'user_id'),
  'DO 0', 'ALTER TABLE `perpindahan` ADD COLUMN `user_id` bigint(20) unsigned NOT NULL'));
PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p;
SET @s := (SELECT IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'perpindahan' AND COLUMN_NAME = 'approved_by'),
  'DO 0', 'ALTER TABLE `perpindahan` ADD COLUMN `approved_by` bigint(20) unsigned DEFAULT NULL'));
PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p;
SET @s := (SELECT IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'perpindahan' AND COLUMN_NAME = 'diterima_oleh'),
  'DO 0', 'ALTER TABLE `perpindahan` ADD COLUMN `diterima_oleh` bigint(20) unsigned DEFAULT NULL'));
PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p;
SET @s := (SELECT IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'perpindahan' AND COLUMN_NAME = 'approved_at'),
  'DO 0', 'ALTER TABLE `perpindahan` ADD COLUMN `approved_at` timestamp NULL DEFAULT NULL'));
PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p;
SET @s := (SELECT IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'perpindahan' AND COLUMN_NAME = 'diterima_at'),
  'DO 0', 'ALTER TABLE `perpindahan` ADD COLUMN `diterima_at` timestamp NULL DEFAULT NULL'));
PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p;
SET @s := (SELECT IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'perpindahan' AND COLUMN_NAME = 'printed_at'),
  'DO 0', 'ALTER TABLE `perpindahan` ADD COLUMN `printed_at` timestamp NULL DEFAULT NULL'));
PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p;
SET @s := (SELECT IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'perpindahan' AND COLUMN_NAME = 'print_count'),
  'DO 0', 'ALTER TABLE `perpindahan` ADD COLUMN `print_count` int(10) unsigned NOT NULL DEFAULT 0'));
PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p;
SET @s := (SELECT IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'perpindahan' AND COLUMN_NAME = 'catatan'),
  'DO 0', 'ALTER TABLE `perpindahan` ADD COLUMN `catatan` varchar(500) DEFAULT NULL'));
PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p;
SET @s := (SELECT IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'perpindahan' AND COLUMN_NAME = 'catatan_terima'),
  'DO 0', 'ALTER TABLE `perpindahan` ADD COLUMN `catatan_terima` varchar(500) DEFAULT NULL'));
PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p;
SET @s := (SELECT IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'perpindahan' AND COLUMN_NAME = 'created_at'),
  'DO 0', 'ALTER TABLE `perpindahan` ADD COLUMN `created_at` timestamp NULL DEFAULT current_timestamp()'));
PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p;
SET @s := (SELECT IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'perpindahan' AND COLUMN_NAME = 'updated_at'),
  'DO 0', 'ALTER TABLE `perpindahan` ADD COLUMN `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()'));
PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p;
-- ---------------- perpindahan_detail
SET @s := (SELECT IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'perpindahan_detail' AND COLUMN_NAME = 'perpindahan_id'),
  'DO 0', 'ALTER TABLE `perpindahan_detail` ADD COLUMN `perpindahan_id` bigint(20) unsigned NOT NULL'));
PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p;
SET @s := (SELECT IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'perpindahan_detail' AND COLUMN_NAME = 'barang_id'),
  'DO 0', 'ALTER TABLE `perpindahan_detail` ADD COLUMN `barang_id` bigint(20) unsigned NOT NULL'));
PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p;
SET @s := (SELECT IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'perpindahan_detail' AND COLUMN_NAME = 'batch_id'),
  'DO 0', 'ALTER TABLE `perpindahan_detail` ADD COLUMN `batch_id` bigint(20) unsigned DEFAULT NULL'));
PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p;
SET @s := (SELECT IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'perpindahan_detail' AND COLUMN_NAME = 'nomor_batch'),
  'DO 0', 'ALTER TABLE `perpindahan_detail` ADD COLUMN `nomor_batch` varchar(50) DEFAULT NULL'));
PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p;
SET @s := (SELECT IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'perpindahan_detail' AND COLUMN_NAME = 'kondisi'),
  'DO 0', 'ALTER TABLE `perpindahan_detail` ADD COLUMN `kondisi` enum(''baru'',''bekas_layak'',''bekas_tidak_layak'') NOT NULL DEFAULT ''baru'' COMMENT ''ikut terbawa dari batch asalnya'''));
PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p;
SET @s := (SELECT IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'perpindahan_detail' AND COLUMN_NAME = 'donatur_id'),
  'DO 0', 'ALTER TABLE `perpindahan_detail` ADD COLUMN `donatur_id` bigint(20) unsigned DEFAULT NULL COMMENT ''ikut terbawa dari stok asalnya'''));
PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p;
SET @s := (SELECT IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'perpindahan_detail' AND COLUMN_NAME = 'sumber'),
  'DO 0', 'ALTER TABLE `perpindahan_detail` ADD COLUMN `sumber` enum(''pembelian'',''donasi'') NOT NULL DEFAULT ''pembelian'' COMMENT ''ikut terbawa dari batch asalnya'''));
PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p;
SET @s := (SELECT IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'perpindahan_detail' AND COLUMN_NAME = 'tanggal_expired'),
  'DO 0', 'ALTER TABLE `perpindahan_detail` ADD COLUMN `tanggal_expired` date DEFAULT NULL'));
PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p;
SET @s := (SELECT IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'perpindahan_detail' AND COLUMN_NAME = 'qty'),
  'DO 0', 'ALTER TABLE `perpindahan_detail` ADD COLUMN `qty` int(11) NOT NULL'));
PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p;
SET @s := (SELECT IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'perpindahan_detail' AND COLUMN_NAME = 'qty_diterima'),
  'DO 0', 'ALTER TABLE `perpindahan_detail` ADD COLUMN `qty_diterima` int(11) DEFAULT NULL'));
PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p;
SET @s := (SELECT IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'perpindahan_detail' AND COLUMN_NAME = 'harga'),
  'DO 0', 'ALTER TABLE `perpindahan_detail` ADD COLUMN `harga` decimal(15,2) NOT NULL DEFAULT 0.00'));
PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p;
SET @s := (SELECT IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'perpindahan_detail' AND COLUMN_NAME = 'subtotal'),
  'DO 0', 'ALTER TABLE `perpindahan_detail` ADD COLUMN `subtotal` decimal(15,2) NOT NULL DEFAULT 0.00'));
PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p;
SET @s := (SELECT IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'perpindahan_detail' AND COLUMN_NAME = 'keterangan'),
  'DO 0', 'ALTER TABLE `perpindahan_detail` ADD COLUMN `keterangan` varchar(255) DEFAULT NULL'));
PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p;
SET @s := (SELECT IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'perpindahan_detail' AND COLUMN_NAME = 'selisih_catatan'),
  'DO 0', 'ALTER TABLE `perpindahan_detail` ADD COLUMN `selisih_catatan` varchar(255) DEFAULT NULL'));
PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p;
-- ---------------- resi_scan_log
SET @s := (SELECT IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'resi_scan_log' AND COLUMN_NAME = 'no_resi'),
  'DO 0', 'ALTER TABLE `resi_scan_log` ADD COLUMN `no_resi` varchar(60) NOT NULL'));
PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p;
SET @s := (SELECT IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'resi_scan_log' AND COLUMN_NAME = 'sumber'),
  'DO 0', 'ALTER TABLE `resi_scan_log` ADD COLUMN `sumber` enum(''kamera'',''scanner'',''manual'') NOT NULL DEFAULT ''manual'''));
PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p;
SET @s := (SELECT IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'resi_scan_log' AND COLUMN_NAME = 'hasil'),
  'DO 0', 'ALTER TABLE `resi_scan_log` ADD COLUMN `hasil` enum(''cocok'',''tidak_ditemukan'') NOT NULL'));
PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p;
SET @s := (SELECT IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'resi_scan_log' AND COLUMN_NAME = 'pengiriman_supplier_id'),
  'DO 0', 'ALTER TABLE `resi_scan_log` ADD COLUMN `pengiriman_supplier_id` bigint(20) unsigned DEFAULT NULL'));
PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p;
SET @s := (SELECT IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'resi_scan_log' AND COLUMN_NAME = 'perpindahan_id'),
  'DO 0', 'ALTER TABLE `resi_scan_log` ADD COLUMN `perpindahan_id` bigint(20) unsigned DEFAULT NULL'));
PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p;
SET @s := (SELECT IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'resi_scan_log' AND COLUMN_NAME = 'user_id'),
  'DO 0', 'ALTER TABLE `resi_scan_log` ADD COLUMN `user_id` bigint(20) unsigned DEFAULT NULL'));
PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p;
SET @s := (SELECT IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'resi_scan_log' AND COLUMN_NAME = 'ip_address'),
  'DO 0', 'ALTER TABLE `resi_scan_log` ADD COLUMN `ip_address` varchar(45) DEFAULT NULL'));
PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p;
SET @s := (SELECT IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'resi_scan_log' AND COLUMN_NAME = 'created_at'),
  'DO 0', 'ALTER TABLE `resi_scan_log` ADD COLUMN `created_at` timestamp NULL DEFAULT current_timestamp()'));
PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p;
-- ---------------- retur
SET @s := (SELECT IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'retur' AND COLUMN_NAME = 'no_retur'),
  'DO 0', 'ALTER TABLE `retur` ADD COLUMN `no_retur` varchar(30) NOT NULL'));
PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p;
SET @s := (SELECT IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'retur' AND COLUMN_NAME = 'tanggal'),
  'DO 0', 'ALTER TABLE `retur` ADD COLUMN `tanggal` datetime NOT NULL'));
PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p;
SET @s := (SELECT IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'retur' AND COLUMN_NAME = 'jenis'),
  'DO 0', 'ALTER TABLE `retur` ADD COLUMN `jenis` enum(''supplier'',''internal'') NOT NULL'));
PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p;
SET @s := (SELECT IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'retur' AND COLUMN_NAME = 'barang_id'),
  'DO 0', 'ALTER TABLE `retur` ADD COLUMN `barang_id` bigint(20) unsigned NOT NULL'));
PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p;
SET @s := (SELECT IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'retur' AND COLUMN_NAME = 'batch_id'),
  'DO 0', 'ALTER TABLE `retur` ADD COLUMN `batch_id` bigint(20) unsigned DEFAULT NULL'));
PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p;
SET @s := (SELECT IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'retur' AND COLUMN_NAME = 'supplier_id'),
  'DO 0', 'ALTER TABLE `retur` ADD COLUMN `supplier_id` bigint(20) unsigned DEFAULT NULL'));
PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p;
SET @s := (SELECT IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'retur' AND COLUMN_NAME = 'qty'),
  'DO 0', 'ALTER TABLE `retur` ADD COLUMN `qty` int(11) NOT NULL'));
PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p;
SET @s := (SELECT IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'retur' AND COLUMN_NAME = 'alasan'),
  'DO 0', 'ALTER TABLE `retur` ADD COLUMN `alasan` varchar(255) DEFAULT NULL'));
PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p;
SET @s := (SELECT IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'retur' AND COLUMN_NAME = 'user_id'),
  'DO 0', 'ALTER TABLE `retur` ADD COLUMN `user_id` bigint(20) unsigned NOT NULL'));
PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p;
SET @s := (SELECT IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'retur' AND COLUMN_NAME = 'created_at'),
  'DO 0', 'ALTER TABLE `retur` ADD COLUMN `created_at` timestamp NULL DEFAULT current_timestamp()'));
PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p;
SET @s := (SELECT IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'retur' AND COLUMN_NAME = 'updated_at'),
  'DO 0', 'ALTER TABLE `retur` ADD COLUMN `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()'));
PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p;
-- ---------------- roles
SET @s := (SELECT IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'roles' AND COLUMN_NAME = 'nama'),
  'DO 0', 'ALTER TABLE `roles` ADD COLUMN `nama` varchar(50) NOT NULL'));
PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p;
SET @s := (SELECT IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'roles' AND COLUMN_NAME = 'label'),
  'DO 0', 'ALTER TABLE `roles` ADD COLUMN `label` varchar(100) NOT NULL'));
PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p;
SET @s := (SELECT IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'roles' AND COLUMN_NAME = 'keterangan'),
  'DO 0', 'ALTER TABLE `roles` ADD COLUMN `keterangan` varchar(255) DEFAULT NULL'));
PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p;
SET @s := (SELECT IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'roles' AND COLUMN_NAME = 'created_at'),
  'DO 0', 'ALTER TABLE `roles` ADD COLUMN `created_at` timestamp NULL DEFAULT current_timestamp()'));
PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p;
SET @s := (SELECT IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'roles' AND COLUMN_NAME = 'updated_at'),
  'DO 0', 'ALTER TABLE `roles` ADD COLUMN `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()'));
PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p;
-- ---------------- sesi_perangkat
SET @s := (SELECT IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'sesi_perangkat' AND COLUMN_NAME = 'user_id'),
  'DO 0', 'ALTER TABLE `sesi_perangkat` ADD COLUMN `user_id` bigint(20) unsigned NOT NULL'));
PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p;
SET @s := (SELECT IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'sesi_perangkat' AND COLUMN_NAME = 'sid_hash'),
  'DO 0', 'ALTER TABLE `sesi_perangkat` ADD COLUMN `sid_hash` char(64) NOT NULL'));
PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p;
SET @s := (SELECT IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'sesi_perangkat' AND COLUMN_NAME = 'perangkat'),
  'DO 0', 'ALTER TABLE `sesi_perangkat` ADD COLUMN `perangkat` varchar(120) DEFAULT NULL COMMENT ''ringkasan terbaca: Windows Â· Chrome'''));
PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p;
SET @s := (SELECT IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'sesi_perangkat' AND COLUMN_NAME = 'user_agent'),
  'DO 0', 'ALTER TABLE `sesi_perangkat` ADD COLUMN `user_agent` varchar(255) DEFAULT NULL'));
PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p;
SET @s := (SELECT IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'sesi_perangkat' AND COLUMN_NAME = 'ip'),
  'DO 0', 'ALTER TABLE `sesi_perangkat` ADD COLUMN `ip` varchar(45) DEFAULT NULL'));
PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p;
SET @s := (SELECT IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'sesi_perangkat' AND COLUMN_NAME = 'dibuat_at'),
  'DO 0', 'ALTER TABLE `sesi_perangkat` ADD COLUMN `dibuat_at` timestamp NULL DEFAULT current_timestamp()'));
PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p;
SET @s := (SELECT IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'sesi_perangkat' AND COLUMN_NAME = 'terakhir_at'),
  'DO 0', 'ALTER TABLE `sesi_perangkat` ADD COLUMN `terakhir_at` timestamp NULL DEFAULT current_timestamp()'));
PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p;
SET @s := (SELECT IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'sesi_perangkat' AND COLUMN_NAME = 'diakhiri_at'),
  'DO 0', 'ALTER TABLE `sesi_perangkat` ADD COLUMN `diakhiri_at` timestamp NULL DEFAULT NULL'));
PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p;
SET @s := (SELECT IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'sesi_perangkat' AND COLUMN_NAME = 'diakhiri_oleh'),
  'DO 0', 'ALTER TABLE `sesi_perangkat` ADD COLUMN `diakhiri_oleh` bigint(20) unsigned DEFAULT NULL COMMENT ''siapa yang mengakhiri, bila bukan pemiliknya'''));
PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p;
-- ---------------- stock_opname
SET @s := (SELECT IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'stock_opname' AND COLUMN_NAME = 'no_opname'),
  'DO 0', 'ALTER TABLE `stock_opname` ADD COLUMN `no_opname` varchar(30) NOT NULL'));
PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p;
SET @s := (SELECT IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'stock_opname' AND COLUMN_NAME = 'tanggal'),
  'DO 0', 'ALTER TABLE `stock_opname` ADD COLUMN `tanggal` date NOT NULL'));
PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p;
SET @s := (SELECT IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'stock_opname' AND COLUMN_NAME = 'status'),
  'DO 0', 'ALTER TABLE `stock_opname` ADD COLUMN `status` enum(''draft'',''menunggu_persetujuan'',''disetujui'',''ditolak'') NOT NULL DEFAULT ''draft'''));
PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p;
SET @s := (SELECT IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'stock_opname' AND COLUMN_NAME = 'user_id'),
  'DO 0', 'ALTER TABLE `stock_opname` ADD COLUMN `user_id` bigint(20) unsigned NOT NULL'));
PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p;
SET @s := (SELECT IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'stock_opname' AND COLUMN_NAME = 'approved_by'),
  'DO 0', 'ALTER TABLE `stock_opname` ADD COLUMN `approved_by` bigint(20) unsigned DEFAULT NULL'));
PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p;
SET @s := (SELECT IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'stock_opname' AND COLUMN_NAME = 'approved_at'),
  'DO 0', 'ALTER TABLE `stock_opname` ADD COLUMN `approved_at` timestamp NULL DEFAULT NULL'));
PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p;
SET @s := (SELECT IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'stock_opname' AND COLUMN_NAME = 'catatan'),
  'DO 0', 'ALTER TABLE `stock_opname` ADD COLUMN `catatan` varchar(255) DEFAULT NULL'));
PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p;
SET @s := (SELECT IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'stock_opname' AND COLUMN_NAME = 'created_at'),
  'DO 0', 'ALTER TABLE `stock_opname` ADD COLUMN `created_at` timestamp NULL DEFAULT current_timestamp()'));
PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p;
SET @s := (SELECT IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'stock_opname' AND COLUMN_NAME = 'updated_at'),
  'DO 0', 'ALTER TABLE `stock_opname` ADD COLUMN `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()'));
PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p;
-- ---------------- stock_opname_detail
SET @s := (SELECT IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'stock_opname_detail' AND COLUMN_NAME = 'stock_opname_id'),
  'DO 0', 'ALTER TABLE `stock_opname_detail` ADD COLUMN `stock_opname_id` bigint(20) unsigned NOT NULL'));
PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p;
SET @s := (SELECT IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'stock_opname_detail' AND COLUMN_NAME = 'barang_id'),
  'DO 0', 'ALTER TABLE `stock_opname_detail` ADD COLUMN `barang_id` bigint(20) unsigned NOT NULL'));
PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p;
SET @s := (SELECT IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'stock_opname_detail' AND COLUMN_NAME = 'batch_id'),
  'DO 0', 'ALTER TABLE `stock_opname_detail` ADD COLUMN `batch_id` bigint(20) unsigned DEFAULT NULL'));
PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p;
SET @s := (SELECT IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'stock_opname_detail' AND COLUMN_NAME = 'qty_sistem'),
  'DO 0', 'ALTER TABLE `stock_opname_detail` ADD COLUMN `qty_sistem` int(11) NOT NULL'));
PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p;
SET @s := (SELECT IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'stock_opname_detail' AND COLUMN_NAME = 'qty_fisik'),
  'DO 0', 'ALTER TABLE `stock_opname_detail` ADD COLUMN `qty_fisik` int(11) NOT NULL'));
PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p;
SET @s := (SELECT IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'stock_opname_detail' AND COLUMN_NAME = 'catatan'),
  'DO 0', 'ALTER TABLE `stock_opname_detail` ADD COLUMN `catatan` varchar(255) DEFAULT NULL'));
PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p;
-- ---------------- stok
SET @s := (SELECT IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'stok' AND COLUMN_NAME = 'barang_id'),
  'DO 0', 'ALTER TABLE `stok` ADD COLUMN `barang_id` bigint(20) unsigned NOT NULL'));
PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p;
SET @s := (SELECT IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'stok' AND COLUMN_NAME = 'qty_total'),
  'DO 0', 'ALTER TABLE `stok` ADD COLUMN `qty_total` int(11) NOT NULL DEFAULT 0'));
PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p;
SET @s := (SELECT IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'stok' AND COLUMN_NAME = 'updated_at'),
  'DO 0', 'ALTER TABLE `stok` ADD COLUMN `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()'));
PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p;
-- ---------------- stok_supplier
SET @s := (SELECT IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'stok_supplier' AND COLUMN_NAME = 'supplier_id'),
  'DO 0', 'ALTER TABLE `stok_supplier` ADD COLUMN `supplier_id` bigint(20) unsigned NOT NULL'));
PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p;
SET @s := (SELECT IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'stok_supplier' AND COLUMN_NAME = 'donatur_id'),
  'DO 0', 'ALTER TABLE `stok_supplier` ADD COLUMN `donatur_id` bigint(20) unsigned DEFAULT NULL COMMENT ''asal barang bila dari donatur'''));
PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p;
SET @s := (SELECT IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'stok_supplier' AND COLUMN_NAME = 'barang_id'),
  'DO 0', 'ALTER TABLE `stok_supplier` ADD COLUMN `barang_id` bigint(20) unsigned NOT NULL'));
PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p;
SET @s := (SELECT IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'stok_supplier' AND COLUMN_NAME = 'lokasi_rak_id'),
  'DO 0', 'ALTER TABLE `stok_supplier` ADD COLUMN `lokasi_rak_id` bigint(20) unsigned DEFAULT NULL COMMENT ''rak tempat batch ini berada, di dalam gudang supplier'''));
PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p;
SET @s := (SELECT IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'stok_supplier' AND COLUMN_NAME = 'nomor_batch'),
  'DO 0', 'ALTER TABLE `stok_supplier` ADD COLUMN `nomor_batch` varchar(50) NOT NULL'));
PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p;
SET @s := (SELECT IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'stok_supplier' AND COLUMN_NAME = 'kondisi'),
  'DO 0', 'ALTER TABLE `stok_supplier` ADD COLUMN `kondisi` enum(''baru'',''bekas_layak'',''bekas_tidak_layak'') NOT NULL DEFAULT ''baru'' COMMENT ''dinilai supplier saat mencatat barang; gudang tidak menilai ulang'''));
PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p;
SET @s := (SELECT IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'stok_supplier' AND COLUMN_NAME = 'sumber'),
  'DO 0', 'ALTER TABLE `stok_supplier` ADD COLUMN `sumber` enum(''pembelian'',''donasi'') NOT NULL DEFAULT ''pembelian'' COMMENT ''asal barang; terpisah dari keadaan fisik di kolom kondisi'''));
PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p;
SET @s := (SELECT IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'stok_supplier' AND COLUMN_NAME = 'tanggal_expired'),
  'DO 0', 'ALTER TABLE `stok_supplier` ADD COLUMN `tanggal_expired` date DEFAULT NULL'));
PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p;
SET @s := (SELECT IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'stok_supplier' AND COLUMN_NAME = 'qty'),
  'DO 0', 'ALTER TABLE `stok_supplier` ADD COLUMN `qty` int(11) NOT NULL DEFAULT 0'));
PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p;
SET @s := (SELECT IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'stok_supplier' AND COLUMN_NAME = 'harga'),
  'DO 0', 'ALTER TABLE `stok_supplier` ADD COLUMN `harga` decimal(15,2) NOT NULL DEFAULT 0.00'));
PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p;
SET @s := (SELECT IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'stok_supplier' AND COLUMN_NAME = 'tanggal_masuk'),
  'DO 0', 'ALTER TABLE `stok_supplier` ADD COLUMN `tanggal_masuk` date NOT NULL'));
PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p;
SET @s := (SELECT IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'stok_supplier' AND COLUMN_NAME = 'keterangan'),
  'DO 0', 'ALTER TABLE `stok_supplier` ADD COLUMN `keterangan` varchar(255) DEFAULT NULL'));
PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p;
SET @s := (SELECT IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'stok_supplier' AND COLUMN_NAME = 'created_at'),
  'DO 0', 'ALTER TABLE `stok_supplier` ADD COLUMN `created_at` timestamp NULL DEFAULT current_timestamp()'));
PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p;
SET @s := (SELECT IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'stok_supplier' AND COLUMN_NAME = 'updated_at'),
  'DO 0', 'ALTER TABLE `stok_supplier` ADD COLUMN `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()'));
PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p;
-- ---------------- supplier
SET @s := (SELECT IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'supplier' AND COLUMN_NAME = 'kode'),
  'DO 0', 'ALTER TABLE `supplier` ADD COLUMN `kode` varchar(20) NOT NULL'));
PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p;
SET @s := (SELECT IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'supplier' AND COLUMN_NAME = 'nama'),
  'DO 0', 'ALTER TABLE `supplier` ADD COLUMN `nama` varchar(150) NOT NULL'));
PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p;
SET @s := (SELECT IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'supplier' AND COLUMN_NAME = 'telepon'),
  'DO 0', 'ALTER TABLE `supplier` ADD COLUMN `telepon` varchar(30) DEFAULT NULL'));
PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p;
SET @s := (SELECT IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'supplier' AND COLUMN_NAME = 'email'),
  'DO 0', 'ALTER TABLE `supplier` ADD COLUMN `email` varchar(150) DEFAULT NULL'));
PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p;
SET @s := (SELECT IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'supplier' AND COLUMN_NAME = 'alamat'),
  'DO 0', 'ALTER TABLE `supplier` ADD COLUMN `alamat` varchar(255) DEFAULT NULL'));
PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p;
SET @s := (SELECT IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'supplier' AND COLUMN_NAME = 'kontak_pic'),
  'DO 0', 'ALTER TABLE `supplier` ADD COLUMN `kontak_pic` varchar(100) DEFAULT NULL'));
PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p;
SET @s := (SELECT IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'supplier' AND COLUMN_NAME = 'keterangan'),
  'DO 0', 'ALTER TABLE `supplier` ADD COLUMN `keterangan` varchar(255) DEFAULT NULL'));
PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p;
SET @s := (SELECT IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'supplier' AND COLUMN_NAME = 'created_at'),
  'DO 0', 'ALTER TABLE `supplier` ADD COLUMN `created_at` timestamp NULL DEFAULT current_timestamp()'));
PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p;
SET @s := (SELECT IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'supplier' AND COLUMN_NAME = 'updated_at'),
  'DO 0', 'ALTER TABLE `supplier` ADD COLUMN `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()'));
PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p;
SET @s := (SELECT IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'supplier' AND COLUMN_NAME = 'deleted_at'),
  'DO 0', 'ALTER TABLE `supplier` ADD COLUMN `deleted_at` timestamp NULL DEFAULT NULL'));
PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p;
-- ---------------- toko
SET @s := (SELECT IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'toko' AND COLUMN_NAME = 'kode'),
  'DO 0', 'ALTER TABLE `toko` ADD COLUMN `kode` varchar(20) NOT NULL'));
PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p;
SET @s := (SELECT IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'toko' AND COLUMN_NAME = 'nama'),
  'DO 0', 'ALTER TABLE `toko` ADD COLUMN `nama` varchar(150) NOT NULL'));
PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p;
SET @s := (SELECT IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'toko' AND COLUMN_NAME = 'marketplace'),
  'DO 0', 'ALTER TABLE `toko` ADD COLUMN `marketplace` enum(''shopee'',''tokopedia'',''tiktok'',''lazada'',''offline'',''lainnya'') NOT NULL DEFAULT ''offline'''));
PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p;
SET @s := (SELECT IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'toko' AND COLUMN_NAME = 'url_toko'),
  'DO 0', 'ALTER TABLE `toko` ADD COLUMN `url_toko` varchar(255) DEFAULT NULL'));
PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p;
SET @s := (SELECT IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'toko' AND COLUMN_NAME = 'penanggung_jawab'),
  'DO 0', 'ALTER TABLE `toko` ADD COLUMN `penanggung_jawab` varchar(150) DEFAULT NULL'));
PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p;
SET @s := (SELECT IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'toko' AND COLUMN_NAME = 'telepon'),
  'DO 0', 'ALTER TABLE `toko` ADD COLUMN `telepon` varchar(30) DEFAULT NULL'));
PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p;
SET @s := (SELECT IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'toko' AND COLUMN_NAME = 'alamat'),
  'DO 0', 'ALTER TABLE `toko` ADD COLUMN `alamat` varchar(255) DEFAULT NULL'));
PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p;
SET @s := (SELECT IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'toko' AND COLUMN_NAME = 'keterangan'),
  'DO 0', 'ALTER TABLE `toko` ADD COLUMN `keterangan` varchar(255) DEFAULT NULL'));
PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p;
SET @s := (SELECT IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'toko' AND COLUMN_NAME = 'is_active'),
  'DO 0', 'ALTER TABLE `toko` ADD COLUMN `is_active` tinyint(1) NOT NULL DEFAULT 1'));
PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p;
SET @s := (SELECT IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'toko' AND COLUMN_NAME = 'created_at'),
  'DO 0', 'ALTER TABLE `toko` ADD COLUMN `created_at` timestamp NULL DEFAULT current_timestamp()'));
PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p;
SET @s := (SELECT IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'toko' AND COLUMN_NAME = 'updated_at'),
  'DO 0', 'ALTER TABLE `toko` ADD COLUMN `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()'));
PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p;
SET @s := (SELECT IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'toko' AND COLUMN_NAME = 'deleted_at'),
  'DO 0', 'ALTER TABLE `toko` ADD COLUMN `deleted_at` timestamp NULL DEFAULT NULL'));
PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p;
-- ---------------- transaksi_keluar
SET @s := (SELECT IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'transaksi_keluar' AND COLUMN_NAME = 'no_transaksi'),
  'DO 0', 'ALTER TABLE `transaksi_keluar` ADD COLUMN `no_transaksi` varchar(30) NOT NULL'));
PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p;
SET @s := (SELECT IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'transaksi_keluar' AND COLUMN_NAME = 'tanggal'),
  'DO 0', 'ALTER TABLE `transaksi_keluar` ADD COLUMN `tanggal` datetime NOT NULL'));
PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p;
SET @s := (SELECT IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'transaksi_keluar' AND COLUMN_NAME = 'user_id'),
  'DO 0', 'ALTER TABLE `transaksi_keluar` ADD COLUMN `user_id` bigint(20) unsigned NOT NULL'));
PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p;
SET @s := (SELECT IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'transaksi_keluar' AND COLUMN_NAME = 'penerima'),
  'DO 0', 'ALTER TABLE `transaksi_keluar` ADD COLUMN `penerima` varchar(150) NOT NULL'));
PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p;
SET @s := (SELECT IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'transaksi_keluar' AND COLUMN_NAME = 'untuk_bayi'),
  'DO 0', 'ALTER TABLE `transaksi_keluar` ADD COLUMN `untuk_bayi` varchar(150) DEFAULT NULL'));
PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p;
SET @s := (SELECT IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'transaksi_keluar' AND COLUMN_NAME = 'divisi_cabang'),
  'DO 0', 'ALTER TABLE `transaksi_keluar` ADD COLUMN `divisi_cabang` varchar(150) DEFAULT NULL'));
PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p;
SET @s := (SELECT IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'transaksi_keluar' AND COLUMN_NAME = 'catatan'),
  'DO 0', 'ALTER TABLE `transaksi_keluar` ADD COLUMN `catatan` varchar(255) DEFAULT NULL'));
PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p;
SET @s := (SELECT IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'transaksi_keluar' AND COLUMN_NAME = 'created_at'),
  'DO 0', 'ALTER TABLE `transaksi_keluar` ADD COLUMN `created_at` timestamp NULL DEFAULT current_timestamp()'));
PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p;
SET @s := (SELECT IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'transaksi_keluar' AND COLUMN_NAME = 'updated_at'),
  'DO 0', 'ALTER TABLE `transaksi_keluar` ADD COLUMN `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()'));
PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p;
-- ---------------- transaksi_keluar_detail
SET @s := (SELECT IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'transaksi_keluar_detail' AND COLUMN_NAME = 'transaksi_keluar_id'),
  'DO 0', 'ALTER TABLE `transaksi_keluar_detail` ADD COLUMN `transaksi_keluar_id` bigint(20) unsigned NOT NULL'));
PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p;
SET @s := (SELECT IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'transaksi_keluar_detail' AND COLUMN_NAME = 'barang_id'),
  'DO 0', 'ALTER TABLE `transaksi_keluar_detail` ADD COLUMN `barang_id` bigint(20) unsigned NOT NULL'));
PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p;
SET @s := (SELECT IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'transaksi_keluar_detail' AND COLUMN_NAME = 'batch_id'),
  'DO 0', 'ALTER TABLE `transaksi_keluar_detail` ADD COLUMN `batch_id` bigint(20) unsigned DEFAULT NULL'));
PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p;
SET @s := (SELECT IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'transaksi_keluar_detail' AND COLUMN_NAME = 'qty'),
  'DO 0', 'ALTER TABLE `transaksi_keluar_detail` ADD COLUMN `qty` int(11) NOT NULL'));
PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p;
-- ---------------- transaksi_masuk
SET @s := (SELECT IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'transaksi_masuk' AND COLUMN_NAME = 'no_transaksi'),
  'DO 0', 'ALTER TABLE `transaksi_masuk` ADD COLUMN `no_transaksi` varchar(30) NOT NULL'));
PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p;
SET @s := (SELECT IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'transaksi_masuk' AND COLUMN_NAME = 'tanggal'),
  'DO 0', 'ALTER TABLE `transaksi_masuk` ADD COLUMN `tanggal` datetime NOT NULL'));
PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p;
SET @s := (SELECT IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'transaksi_masuk' AND COLUMN_NAME = 'supplier_id'),
  'DO 0', 'ALTER TABLE `transaksi_masuk` ADD COLUMN `supplier_id` bigint(20) unsigned DEFAULT NULL'));
PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p;
SET @s := (SELECT IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'transaksi_masuk' AND COLUMN_NAME = 'user_id'),
  'DO 0', 'ALTER TABLE `transaksi_masuk` ADD COLUMN `user_id` bigint(20) unsigned NOT NULL'));
PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p;
SET @s := (SELECT IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'transaksi_masuk' AND COLUMN_NAME = 'nota_foto'),
  'DO 0', 'ALTER TABLE `transaksi_masuk` ADD COLUMN `nota_foto` varchar(255) DEFAULT NULL'));
PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p;
SET @s := (SELECT IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'transaksi_masuk' AND COLUMN_NAME = 'total'),
  'DO 0', 'ALTER TABLE `transaksi_masuk` ADD COLUMN `total` decimal(15,2) NOT NULL DEFAULT 0.00'));
PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p;
SET @s := (SELECT IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'transaksi_masuk' AND COLUMN_NAME = 'catatan'),
  'DO 0', 'ALTER TABLE `transaksi_masuk` ADD COLUMN `catatan` varchar(255) DEFAULT NULL'));
PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p;
SET @s := (SELECT IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'transaksi_masuk' AND COLUMN_NAME = 'created_at'),
  'DO 0', 'ALTER TABLE `transaksi_masuk` ADD COLUMN `created_at` timestamp NULL DEFAULT current_timestamp()'));
PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p;
SET @s := (SELECT IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'transaksi_masuk' AND COLUMN_NAME = 'updated_at'),
  'DO 0', 'ALTER TABLE `transaksi_masuk` ADD COLUMN `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()'));
PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p;
-- ---------------- transaksi_masuk_detail
SET @s := (SELECT IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'transaksi_masuk_detail' AND COLUMN_NAME = 'transaksi_masuk_id'),
  'DO 0', 'ALTER TABLE `transaksi_masuk_detail` ADD COLUMN `transaksi_masuk_id` bigint(20) unsigned NOT NULL'));
PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p;
SET @s := (SELECT IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'transaksi_masuk_detail' AND COLUMN_NAME = 'barang_id'),
  'DO 0', 'ALTER TABLE `transaksi_masuk_detail` ADD COLUMN `barang_id` bigint(20) unsigned NOT NULL'));
PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p;
SET @s := (SELECT IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'transaksi_masuk_detail' AND COLUMN_NAME = 'batch_id'),
  'DO 0', 'ALTER TABLE `transaksi_masuk_detail` ADD COLUMN `batch_id` bigint(20) unsigned DEFAULT NULL'));
PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p;
SET @s := (SELECT IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'transaksi_masuk_detail' AND COLUMN_NAME = 'nomor_batch'),
  'DO 0', 'ALTER TABLE `transaksi_masuk_detail` ADD COLUMN `nomor_batch` varchar(50) DEFAULT NULL'));
PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p;
SET @s := (SELECT IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'transaksi_masuk_detail' AND COLUMN_NAME = 'tanggal_expired'),
  'DO 0', 'ALTER TABLE `transaksi_masuk_detail` ADD COLUMN `tanggal_expired` date DEFAULT NULL'));
PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p;
SET @s := (SELECT IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'transaksi_masuk_detail' AND COLUMN_NAME = 'lokasi_rak_id'),
  'DO 0', 'ALTER TABLE `transaksi_masuk_detail` ADD COLUMN `lokasi_rak_id` bigint(20) unsigned DEFAULT NULL'));
PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p;
SET @s := (SELECT IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'transaksi_masuk_detail' AND COLUMN_NAME = 'qty'),
  'DO 0', 'ALTER TABLE `transaksi_masuk_detail` ADD COLUMN `qty` int(11) NOT NULL'));
PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p;
SET @s := (SELECT IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'transaksi_masuk_detail' AND COLUMN_NAME = 'harga'),
  'DO 0', 'ALTER TABLE `transaksi_masuk_detail` ADD COLUMN `harga` decimal(15,2) NOT NULL DEFAULT 0.00'));
PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p;
SET @s := (SELECT IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'transaksi_masuk_detail' AND COLUMN_NAME = 'subtotal'),
  'DO 0', 'ALTER TABLE `transaksi_masuk_detail` ADD COLUMN `subtotal` decimal(15,2) NOT NULL DEFAULT 0.00'));
PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p;
-- ---------------- users
SET @s := (SELECT IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'role_id'),
  'DO 0', 'ALTER TABLE `users` ADD COLUMN `role_id` bigint(20) unsigned NOT NULL'));
PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p;
SET @s := (SELECT IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'tingkat'),
  'DO 0', 'ALTER TABLE `users` ADD COLUMN `tingkat` enum(''staff'',''manager'') NOT NULL DEFAULT ''staff'' COMMENT ''wewenang di dalam perannya; diabaikan untuk super_admin dan pimpinan'''));
PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p;
SET @s := (SELECT IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'supplier_id'),
  'DO 0', 'ALTER TABLE `users` ADD COLUMN `supplier_id` bigint(20) unsigned DEFAULT NULL'));
PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p;
SET @s := (SELECT IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'gudang_id'),
  'DO 0', 'ALTER TABLE `users` ADD COLUMN `gudang_id` bigint(20) unsigned DEFAULT NULL COMMENT ''gudang yang dipegang akun Admin Gudang; NULL untuk peran lain'''));
PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p;
SET @s := (SELECT IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'nama'),
  'DO 0', 'ALTER TABLE `users` ADD COLUMN `nama` varchar(150) NOT NULL'));
PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p;
SET @s := (SELECT IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'email'),
  'DO 0', 'ALTER TABLE `users` ADD COLUMN `email` varchar(150) NOT NULL'));
PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p;
SET @s := (SELECT IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'telepon'),
  'DO 0', 'ALTER TABLE `users` ADD COLUMN `telepon` varchar(30) DEFAULT NULL'));
PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p;
SET @s := (SELECT IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'password'),
  'DO 0', 'ALTER TABLE `users` ADD COLUMN `password` varchar(255) NOT NULL'));
PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p;
SET @s := (SELECT IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'foto'),
  'DO 0', 'ALTER TABLE `users` ADD COLUMN `foto` varchar(255) DEFAULT NULL'));
PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p;
SET @s := (SELECT IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'is_active'),
  'DO 0', 'ALTER TABLE `users` ADD COLUMN `is_active` tinyint(1) NOT NULL DEFAULT 1'));
PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p;
SET @s := (SELECT IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'idle_menit'),
  'DO 0', 'ALTER TABLE `users` ADD COLUMN `idle_menit` int(10) unsigned NOT NULL DEFAULT 30 COMMENT ''menit menganggur sebelum sesi berakhir; 43200 = 30 hari'''));
PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p;
SET @s := (SELECT IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'last_login_at'),
  'DO 0', 'ALTER TABLE `users` ADD COLUMN `last_login_at` timestamp NULL DEFAULT NULL'));
PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p;
SET @s := (SELECT IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'remember_token'),
  'DO 0', 'ALTER TABLE `users` ADD COLUMN `remember_token` varchar(100) DEFAULT NULL'));
PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p;
SET @s := (SELECT IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'created_at'),
  'DO 0', 'ALTER TABLE `users` ADD COLUMN `created_at` timestamp NULL DEFAULT current_timestamp()'));
PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p;
SET @s := (SELECT IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'updated_at'),
  'DO 0', 'ALTER TABLE `users` ADD COLUMN `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()'));
PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p;
SET @s := (SELECT IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'deleted_at'),
  'DO 0', 'ALTER TABLE `users` ADD COLUMN `deleted_at` timestamp NULL DEFAULT NULL'));
PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p;
-- ---------------- user_gudang
SET @s := (SELECT IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'user_gudang' AND COLUMN_NAME = 'user_id'),
  'DO 0', 'ALTER TABLE `user_gudang` ADD COLUMN `user_id` bigint(20) unsigned NOT NULL'));
PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p;
SET @s := (SELECT IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'user_gudang' AND COLUMN_NAME = 'gudang_id'),
  'DO 0', 'ALTER TABLE `user_gudang` ADD COLUMN `gudang_id` bigint(20) unsigned NOT NULL'));
PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p;
SET @s := (SELECT IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'user_gudang' AND COLUMN_NAME = 'created_at'),
  'DO 0', 'ALTER TABLE `user_gudang` ADD COLUMN `created_at` timestamp NULL DEFAULT current_timestamp()'));
PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p;
-- ---------------- user_toko
SET @s := (SELECT IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'user_toko' AND COLUMN_NAME = 'user_id'),
  'DO 0', 'ALTER TABLE `user_toko` ADD COLUMN `user_id` bigint(20) unsigned NOT NULL'));
PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p;
SET @s := (SELECT IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'user_toko' AND COLUMN_NAME = 'toko_id'),
  'DO 0', 'ALTER TABLE `user_toko` ADD COLUMN `toko_id` bigint(20) unsigned NOT NULL'));
PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p;
SET @s := (SELECT IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'user_toko' AND COLUMN_NAME = 'created_at'),
  'DO 0', 'ALTER TABLE `user_toko` ADD COLUMN `created_at` timestamp NULL DEFAULT current_timestamp()'));
PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p;

-- =====================================================================
--  BAGIAN 3 - VIEW
-- =====================================================================
--  Klausa DEFINER sudah dibuang. Ekspor phpMyAdmin menuliskannya sebagai
--  DEFINER=`root`@`localhost`; di hosting bersama pengguna basis datanya
--  bukan root, jadi baris itu ditolak dan viewnya tidak pernah terbuat --
--  sementara impor keseluruhan tetap dilaporkan berhasil.
--
--  ---------------------------------------------------------------
--  URUTANNYA MENURUT KETERGANTUNGAN, BUKAN ABJAD
--  ---------------------------------------------------------------
--  `v_expired_rantai` membaca dari `v_status_expired`. Menurut abjad,
--  yang membaca justru datang lebih dahulu -- sehingga ia dibuat di atas
--  view LAMA yang masih tertinggal di hosting.
--
--  View lama itu dibuat pada sesi yang collation sambungannya berbeda,
--  jadi kolom hasil CONCAT di dalamnya bercollation lain pula. Ketika
--  `v_expired_rantai` menyatukannya lewat UNION dengan cabang yang
--  memakai tabel sekarang, MySQL menolak dengan galat 1271, "Illegal mix
--  of collations". Yang salah bukan viewnya, melainkan urutan
--  pembuatannya.
--
--  Dengan `v_status_expired` dibuat lebih dahulu, keduanya lahir pada
--  sesi yang sama dan collationnya pasti sepadan.
-- =====================================================================

-- ---------------------------------------------------------------- v_status_expired
CREATE OR REPLACE VIEW `v_status_expired` AS select `bb`.`id` AS `batch_id`,`bb`.`barang_id` AS `barang_id`,`b`.`nama` AS `nama_barang`,`bb`.`nomor_batch` AS `nomor_batch`,`bb`.`tanggal_expired` AS `tanggal_expired`,`bb`.`qty_tersisa` AS `qty_tersisa`,to_days(`bb`.`tanggal_expired`) - to_days(curdate()) AS `sisa_hari`,case when `bb`.`tanggal_expired` is null then 'tanpa_expired' when to_days(`bb`.`tanggal_expired`) - to_days(curdate()) < 0 then 'expired' when to_days(`bb`.`tanggal_expired`) - to_days(curdate()) <= 30 then 'segera_expired' when to_days(`bb`.`tanggal_expired`) - to_days(curdate()) <= 90 then 'waspada' else 'aman' end AS `status_expired`,case when `bb`.`toko_id` is not null then 'toko' else 'gudang' end AS `lokasi_tipe`,coalesce(`t`.`nama`,concat(coalesce(`g`.`nama`,'—'),' › ',coalesce(`lr`.`nama`,'—'))) AS `lokasi_nama`,`bb`.`lokasi_rak_id` AS `lokasi_rak_id`,`bb`.`toko_id` AS `toko_id`,`lr`.`gudang_id` AS `gudang_id`,`g`.`jenis` AS `jenis_gudang`,`g`.`supplier_id` AS `supplier_id` from ((((`batch_barang` `bb` join `barang` `b` on(`b`.`id` = `bb`.`barang_id`)) left join `toko` `t` on(`t`.`id` = `bb`.`toko_id`)) left join `lokasi_rak` `lr` on(`lr`.`id` = `bb`.`lokasi_rak_id`)) left join `gudang` `g` on(`g`.`id` = `lr`.`gudang_id`)) where `bb`.`qty_tersisa` > 0;

-- ---------------------------------------------------------------- v_expired_rantai
CREATE OR REPLACE VIEW `v_expired_rantai` AS select convert('batch' using utf8mb4) AS `sumber`,`ve`.`batch_id` AS `ref_id`,`ve`.`barang_id` AS `barang_id`,convert(`ve`.`nama_barang` using utf8mb4) AS `nama_barang`,convert(`ve`.`nomor_batch` using utf8mb4) AS `nomor_batch`,`ve`.`tanggal_expired` AS `tanggal_expired`,`ve`.`qty_tersisa` AS `qty_tersisa`,`ve`.`sisa_hari` AS `sisa_hari`,convert(`ve`.`status_expired` using utf8mb4) AS `status_expired`,convert(`ve`.`lokasi_tipe` using utf8mb4) AS `lokasi_tipe`,convert(`ve`.`lokasi_nama` using utf8mb4) AS `lokasi_nama`,`ve`.`lokasi_rak_id` AS `lokasi_rak_id`,`ve`.`toko_id` AS `toko_id`,NULL AS `supplier_id` from `v_status_expired` `ve` union all select convert('supplier' using utf8mb4) AS `CONVERT('supplier' USING utf8mb4)`,`ss`.`id` AS `id`,`ss`.`barang_id` AS `barang_id`,convert(`b`.`nama` using utf8mb4) AS `CONVERT(``b``.``nama`` USING utf8mb4)`,convert(`ss`.`nomor_batch` using utf8mb4) AS `CONVERT(``ss``.``nomor_batch`` USING utf8mb4)`,`ss`.`tanggal_expired` AS `tanggal_expired`,`ss`.`qty` AS `qty`,to_days(`ss`.`tanggal_expired`) - to_days(curdate()) AS `TO_DAYS(``ss``.``tanggal_expired``) - TO_DAYS(CURDATE())`,convert(case when `ss`.`tanggal_expired` is null then 'tanpa_expired' when to_days(`ss`.`tanggal_expired`) - to_days(curdate()) < 0 then 'expired' when to_days(`ss`.`tanggal_expired`) - to_days(curdate()) <= 30 then 'segera_expired' when to_days(`ss`.`tanggal_expired`) - to_days(curdate()) <= 90 then 'waspada' else 'aman' end using utf8mb4) AS `Name_exp_9`,convert('supplier' using utf8mb4) AS `CONVERT('supplier' USING utf8mb4)`,convert(concat(coalesce(`g`.`nama`,'?'),' ? ',coalesce(`lr`.`nama`,'?')) using utf8mb4) AS `Name_exp_11`,`ss`.`lokasi_rak_id` AS `lokasi_rak_id`,NULL AS `NULL`,`ss`.`supplier_id` AS `supplier_id` from (((`stok_supplier` `ss` join `barang` `b` on(`b`.`id` = `ss`.`barang_id`)) left join `lokasi_rak` `lr` on(`lr`.`id` = `ss`.`lokasi_rak_id`)) left join `gudang` `g` on(`g`.`id` = `lr`.`gudang_id`)) where `ss`.`qty` > 0;

-- ---------------------------------------------------------------- v_pelepasan
CREATE OR REPLACE VIEW `v_pelepasan` AS select `p`.`id` AS `id`,`p`.`no_pelepasan` AS `no_pelepasan`,`p`.`tanggal` AS `tanggal`,`p`.`jenis` AS `jenis`,`p`.`status` AS `status`,`p`.`hasil` AS `hasil`,`p`.`qty` AS `qty`,`p`.`alasan` AS `alasan`,`p`.`biaya` AS `biaya`,`p`.`nilai_jual` AS `nilai_jual`,`p`.`no_bukti_setor` AS `no_bukti_setor`,`p`.`tanggal_selesai` AS `tanggal_selesai`,`b`.`kode` AS `kode_barang`,`b`.`nama` AS `nama_barang`,`b`.`satuan` AS `satuan`,`bb`.`nomor_batch` AS `nomor_batch`,`bb`.`tanggal_expired` AS `tanggal_expired`,`bb`.`kondisi` AS `kondisi`,`d`.`nama` AS `nama_donatur`,`d`.`anonim` AS `donatur_anonim`,`d`.`wilayah` AS `donatur_wilayah`,`u`.`nama` AS `petugas`,to_days(coalesce(`p`.`tanggal_selesai`,curdate())) - to_days(`p`.`tanggal`) AS `lama_hari` from ((((`pelepasan_stok` `p` join `barang` `b` on(`b`.`id` = `p`.`barang_id`)) left join `batch_barang` `bb` on(`bb`.`id` = `p`.`batch_id`)) left join `donatur` `d` on(`d`.`id` = `p`.`donatur_id`)) left join `users` `u` on(`u`.`id` = `p`.`user_id`));

-- ---------------------------------------------------------------- v_penjualan_harian
CREATE OR REPLACE VIEW `v_penjualan_harian` AS select `p`.`toko_id` AS `toko_id`,`t`.`nama` AS `nama_toko`,`t`.`marketplace` AS `marketplace`,cast(`p`.`tanggal` as date) AS `tanggal`,count(distinct `p`.`id`) AS `jml_pesanan`,coalesce(sum(`d`.`qty`),0) AS `jml_unit`,coalesce(sum(`d`.`subtotal`),0) AS `omzet` from ((`penjualan` `p` join `toko` `t` on(`t`.`id` = `p`.`toko_id`)) left join `penjualan_detail` `d` on(`d`.`penjualan_id` = `p`.`id`)) where `p`.`status` <> 'batal' group by `p`.`toko_id`,cast(`p`.`tanggal` as date);

-- ---------------------------------------------------------------- v_penyaluran_penerima
CREATE OR REPLACE VIEW `v_penyaluran_penerima` AS select `pn`.`id` AS `penerima_id`,`pn`.`kode` AS `kode`,`pn`.`nama` AS `nama`,`pn`.`jenis` AS `jenis`,`pn`.`wilayah` AS `wilayah`,count(distinct `p`.`id`) AS `jml_penyaluran`,coalesce(sum(`d`.`qty`),0) AS `total_unit`,coalesce(sum(`d`.`qty` * `b`.`harga`),0) AS `total_nilai`,max(`p`.`tanggal`) AS `terakhir_disalurkan` from (((`penerima` `pn` left join `perpindahan` `p` on(`p`.`tujuan_tipe` = 'penerima' and `p`.`tujuan_id` = `pn`.`id` and `p`.`status` in ('selesai','diterima'))) left join `perpindahan_detail` `d` on(`d`.`perpindahan_id` = `p`.`id`)) left join `barang` `b` on(`b`.`id` = `d`.`barang_id`)) where `pn`.`deleted_at` is null group by `pn`.`id`;

-- ---------------------------------------------------------------- v_perangkat_aktif
CREATE OR REPLACE VIEW `v_perangkat_aktif` AS select `u`.`id` AS `user_id`,`u`.`nama` AS `nama`,`u`.`email` AS `email`,`r`.`label` AS `peran`,`u`.`idle_menit` AS `idle_menit`,count(`s`.`id`) AS `jml_perangkat`,max(`s`.`terakhir_at`) AS `paling_baru` from ((`users` `u` join `roles` `r` on(`r`.`id` = `u`.`role_id`)) left join `sesi_perangkat` `s` on(`s`.`user_id` = `u`.`id` and `s`.`diakhiri_at` is null and `s`.`terakhir_at` >= current_timestamp() - interval `u`.`idle_menit` minute)) where `u`.`deleted_at` is null group by `u`.`id`;

-- ---------------------------------------------------------------- v_peringkat_donatur
CREATE OR REPLACE VIEW `v_peringkat_donatur` AS select `d`.`id` AS `donatur_id`,`d`.`kode` AS `kode`,`d`.`nama` AS `nama`,`d`.`jenis` AS `jenis`,`d`.`wilayah` AS `wilayah`,`d`.`anonim` AS `anonim`,`d`.`supplier_id` AS `supplier_id`,`d`.`is_active` AS `is_active`,count(distinct `dn`.`id`) AS `jml_donasi`,coalesce(sum(`dd`.`qty`),0) AS `total_qty`,coalesce(sum(`dd`.`nilai_taksir`),0) AS `total_nilai`,count(distinct `dd`.`barang_id`) AS `jml_jenis_barang`,count(distinct case when `dn`.`jenis` = 'kunjungan' then `dn`.`id` end) AS `jml_kunjungan`,count(distinct case when `dn`.`jenis` = 'paket' then `dn`.`id` end) AS `jml_paket`,min(`dn`.`tanggal`) AS `donasi_pertama`,max(`dn`.`tanggal`) AS `donasi_terakhir`,to_days(curdate()) - to_days(max(`dn`.`tanggal`)) AS `hari_sejak_terakhir`,round(count(distinct `dn`.`id`) / greatest(timestampdiff(MONTH,min(`dn`.`tanggal`),curdate()) + 1,1),2) AS `rata_per_bulan` from ((`donatur` `d` left join `donasi` `dn` on(`dn`.`donatur_id` = `d`.`id`)) left join `donasi_detail` `dd` on(`dd`.`donasi_id` = `dn`.`id`)) where `d`.`deleted_at` is null group by `d`.`id`,`d`.`kode`,`d`.`nama`,`d`.`jenis`,`d`.`wilayah`,`d`.`anonim`,`d`.`supplier_id`,`d`.`is_active`;

-- ---------------------------------------------------------------- v_perpindahan_ringkas
CREATE OR REPLACE VIEW `v_perpindahan_ringkas` AS select `p`.`id` AS `id`,`p`.`no_perpindahan` AS `no_perpindahan`,`p`.`no_surat_jalan` AS `no_surat_jalan`,`p`.`jenis` AS `jenis`,`p`.`tanggal` AS `tanggal`,`p`.`status` AS `status`,`p`.`no_resi` AS `no_resi`,`p`.`tujuan_teks` AS `tujuan_teks`,`p`.`penerima` AS `penerima`,case `p`.`asal_tipe` when 'supplier' then (select `supplier`.`nama` from `supplier` where `supplier`.`id` = `p`.`asal_id`) when 'gudang' then (select `gudang`.`nama` from `gudang` where `gudang`.`id` = `p`.`asal_id`) when 'rak' then (select `lokasi_rak`.`nama` from `lokasi_rak` where `lokasi_rak`.`id` = `p`.`asal_id`) else `p`.`asal_teks` end AS `asal_nama`,case `p`.`tujuan_tipe` when 'gudang' then (select `gudang`.`nama` from `gudang` where `gudang`.`id` = `p`.`tujuan_id`) when 'rak' then (select `lokasi_rak`.`nama` from `lokasi_rak` where `lokasi_rak`.`id` = `p`.`tujuan_id`) when 'supplier' then (select `supplier`.`nama` from `supplier` where `supplier`.`id` = `p`.`tujuan_id`) else `p`.`tujuan_teks` end AS `tujuan_nama`,(select coalesce(sum(`d`.`qty`),0) from `perpindahan_detail` `d` where `d`.`perpindahan_id` = `p`.`id`) AS `total_qty`,(select count(0) from `perpindahan_detail` `d` where `d`.`perpindahan_id` = `p`.`id`) AS `jml_item`,`u`.`nama` AS `pembuat`,`ua`.`nama` AS `penyetuju` from ((`perpindahan` `p` left join `users` `u` on(`u`.`id` = `p`.`user_id`)) left join `users` `ua` on(`ua`.`id` = `p`.`approved_by`));

-- ---------------------------------------------------------------- v_petugas_gudang
CREATE OR REPLACE VIEW `v_petugas_gudang` AS select `g`.`id` AS `gudang_id`,`g`.`kode` AS `kode_gudang`,`g`.`nama` AS `nama_gudang`,`g`.`jenis` AS `jenis`,count(`u`.`id`) AS `jml_petugas`,group_concat(`u`.`nama` order by `u`.`nama` ASC separator ', ') AS `petugas` from (`gudang` `g` left join `users` `u` on(`u`.`gudang_id` = `g`.`id` and `u`.`deleted_at` is null and `u`.`is_active` = 1)) where `g`.`deleted_at` is null group by `g`.`id`;

-- ---------------------------------------------------------------- v_status_stok
CREATE OR REPLACE VIEW `v_status_stok` AS select `b`.`id` AS `barang_id`,`b`.`kode` AS `kode_barang`,`b`.`nama` AS `nama_barang`,`k`.`nama` AS `kategori`,coalesce(`s`.`qty_total`,0) AS `stok`,`b`.`stok_minimum` AS `stok_minimum`,case when coalesce(`s`.`qty_total`,0) = 0 then 'habis' when coalesce(`s`.`qty_total`,0) <= `b`.`stok_minimum` then 'hampir_habis' else 'aman' end AS `status_stok` from ((`barang` `b` left join `stok` `s` on(`s`.`barang_id` = `b`.`id`)) left join `kategori` `k` on(`k`.`id` = `b`.`kategori_id`)) where `b`.`deleted_at` is null;

-- ---------------------------------------------------------------- v_stok_kondisi
CREATE OR REPLACE VIEW `v_stok_kondisi` AS select `bb`.`barang_id` AS `barang_id`,`b`.`kode` AS `kode_barang`,`b`.`nama` AS `nama_barang`,`b`.`satuan` AS `satuan`,`bb`.`kondisi` AS `kondisi`,`bb`.`kondisi` <> 'bekas_tidak_layak' AS `layak_salur`,case when `bb`.`toko_id` is not null then 'toko' else 'gudang' end AS `lokasi_tipe`,coalesce(`t`.`nama`,`g`.`nama`,'?') AS `lokasi_nama`,count(0) AS `jml_batch`,sum(`bb`.`qty_tersisa`) AS `qty`,sum(`bb`.`qty_tersisa` * `b`.`harga`) AS `nilai` from ((((`batch_barang` `bb` join `barang` `b` on(`b`.`id` = `bb`.`barang_id`)) left join `toko` `t` on(`t`.`id` = `bb`.`toko_id`)) left join `lokasi_rak` `lr` on(`lr`.`id` = `bb`.`lokasi_rak_id`)) left join `gudang` `g` on(`g`.`id` = `lr`.`gudang_id`)) where `bb`.`qty_tersisa` > 0 group by `bb`.`barang_id`,`bb`.`kondisi`,case when `bb`.`toko_id` is not null then 'toko' else 'gudang' end,coalesce(`t`.`nama`,`g`.`nama`,'?');

-- ---------------------------------------------------------------- v_stok_per_gudang
CREATE OR REPLACE VIEW `v_stok_per_gudang` AS select `g`.`id` AS `gudang_id`,`g`.`nama` AS `nama_gudang`,`b`.`id` AS `barang_id`,`b`.`kode` AS `kode_barang`,`b`.`nama` AS `nama_barang`,`b`.`satuan` AS `satuan`,coalesce(sum(`bb`.`qty_tersisa`),0) AS `qty` from (((`gudang` `g` join `lokasi_rak` `lr` on(`lr`.`gudang_id` = `g`.`id` and `lr`.`deleted_at` is null)) join `batch_barang` `bb` on(`bb`.`lokasi_rak_id` = `lr`.`id` and `bb`.`qty_tersisa` > 0)) join `barang` `b` on(`b`.`id` = `bb`.`barang_id` and `b`.`deleted_at` is null)) where `g`.`deleted_at` is null group by `g`.`id`,`b`.`id`;

-- ---------------------------------------------------------------- v_stok_rantai
CREATE OR REPLACE VIEW `v_stok_rantai` AS select 'batch' AS `sumber`,`bb`.`barang_id` AS `barang_id`,`b`.`kode` AS `kode_barang`,`b`.`nama` AS `nama_barang`,`b`.`stok_minimum` AS `stok_minimum`,`bb`.`qty_tersisa` AS `qty`,`bb`.`lokasi_rak_id` AS `lokasi_rak_id`,`bb`.`toko_id` AS `toko_id`,NULL AS `supplier_id` from (`batch_barang` `bb` join `barang` `b` on(`b`.`id` = `bb`.`barang_id`)) where `b`.`deleted_at` is null union all select 'supplier' AS `supplier`,`ss`.`barang_id` AS `barang_id`,`b`.`kode` AS `kode`,`b`.`nama` AS `nama`,`b`.`stok_minimum` AS `stok_minimum`,`ss`.`qty` AS `qty`,`ss`.`lokasi_rak_id` AS `lokasi_rak_id`,NULL AS `NULL`,`ss`.`supplier_id` AS `supplier_id` from (`stok_supplier` `ss` join `barang` `b` on(`b`.`id` = `ss`.`barang_id`)) where `b`.`deleted_at` is null;

-- ---------------------------------------------------------------- v_stok_toko
CREATE OR REPLACE VIEW `v_stok_toko` AS select `t`.`id` AS `toko_id`,`t`.`nama` AS `nama_toko`,`t`.`marketplace` AS `marketplace`,`b`.`id` AS `barang_id`,`b`.`kode` AS `kode_barang`,`b`.`nama` AS `nama_barang`,`b`.`satuan` AS `satuan`,`b`.`stok_minimum` AS `stok_minimum`,coalesce(sum(`bb`.`qty_tersisa`),0) AS `qty`,min(`bb`.`tanggal_expired`) AS `exp_terdekat` from ((`toko` `t` join `batch_barang` `bb` on(`bb`.`toko_id` = `t`.`id` and `bb`.`qty_tersisa` > 0)) join `barang` `b` on(`b`.`id` = `bb`.`barang_id` and `b`.`deleted_at` is null)) where `t`.`deleted_at` is null group by `t`.`id`,`b`.`id`;

SET FOREIGN_KEY_CHECKS = 1;
