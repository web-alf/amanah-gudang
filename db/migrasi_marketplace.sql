-- =====================================================================
-- AMANAH — Sambungan Marketplace: pelacakan kiriman & pembayaran
-- =====================================================================
-- BERJALAN DI MySQL MAUPUN MariaDB.
--
-- Bentuknya bertele-tele, dan itu disengaja. "ALTER TABLE ... ADD COLUMN
-- IF NOT EXISTS" hanya dikenal MariaDB; di MySQL ia GALAT SINTAKS, dan
-- galat sintaks menghentikan SELURUH berkas — jadi satu klausa saja
-- membuat migrasi panjang tidak mengerjakan apa pun.
--
-- Penggantinya: setiap perubahan diperiksa dulu ke information_schema,
-- lalu dijalankan lewat pernyataan yang disiapkan. Hanya menuntut hak
-- ALTER, tidak menuntut CREATE ROUTINE.
--
-- Aman dijalankan berulang kali.
-- =====================================================================
--
-- Tabel penjualan sebelumnya hanya menyimpan total dan ongkir. Itu cukup
-- untuk pencatatan manual, tapi tidak cukup untuk menjawab dua pertanyaan
-- yang paling sering ditanyakan toko: "sudah sampai belum?" dan "uangnya
-- sudah masuk berapa?".
--
-- Angka pembayaran di sini adalah SALINAN data marketplace, bukan catatan
-- kas. Untuk pembukuan tetap harus dicocokkan dengan mutasi bank; sistem
-- ini mencatat, bukan menggantikan rekonsiliasi.
-- =====================================================================

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

-- ============================== ringkasan
-- SELECT ringkasan sengaja DIHAPUS — runner migrasi.php menjalankan tiap
-- pernyataan lewat PDO::exec(), yang gagal (MySQL 2014) kalau diberi
-- SELECT berdiri sendiri. Untuk memeriksa manual, harus terbaca:
-- kiriman 5, pembayaran 7, sinkronisasi 2.
-- SELECT 'penjualan' AS tabel,
--        SUM(COLUMN_NAME IN ('no_resi','kurir','status_kirim','tgl_kirim','tgl_sampai')) AS kiriman,
--        SUM(COLUMN_NAME IN ('escrow_amount','komisi','biaya_layanan','biaya_transaksi',
--                            'voucher','status_bayar','tgl_settlement'))                 AS pembayaran,
--        SUM(COLUMN_NAME IN ('status_marketplace','ditarik_at'))                         AS sinkronisasi
-- FROM information_schema.COLUMNS
-- WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'penjualan';