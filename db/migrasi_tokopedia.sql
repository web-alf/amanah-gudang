-- =====================================================================
-- AMANAH — Tokopedia, dan aturan "satu toko satu kredensial"
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
-- ALTER, tidak menuntut CREATE ROUTINE — hak yang tidak selalu diberikan
-- di hosting bersama.
--
-- Aman dijalankan berulang kali.
-- =====================================================================
--
-- Dua hal dikerjakan berkas ini.
--
-- 1. MEMPERBAIKI KUNCI UNIK YANG TERLALU LONGGAR.
--    Kunci sebelumnya (toko_id, platform) mengizinkan satu baris toko
--    memegang kredensial Shopee DAN Tokopedia sekaligus. Itu bertentangan
--    dengan model datanya sendiri: kolom toko.marketplace hanya memuat
--    SATU nilai, jadi satu baris toko memang satu lapak.
--    Di data yang ada pun begitu — "S STARKIDS" (shopee) dan "T STARKIDS"
--    (tokopedia) adalah dua baris toko terpisah untuk merek yang sama.
--
-- 2. MENAMBAH KOLOM YANG DIBUTUHKAN TOKOPEDIA.
--    Kolom kredensial yang ada dipakai bersama, karena isinya sejenis
--    walau namanya berbeda di masing-masing konsol:
--      partner_id  = Client ID     (Tokopedia) / Partner ID  (Shopee)
--      partner_key = Client Secret (Tokopedia) / Partner Key (Shopee)
--      shop_id     = Shop ID       (keduanya)
--    Yang benar-benar baru hanya fs_id.
-- =====================================================================

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

-- ============================== ringkasan
-- SELECT ringkasan sengaja DIHAPUS dari sini (bukan dikomentari) — runner
-- migrasi.php menjalankan tiap pernyataan lewat PDO::exec(), yang tidak
-- dirancang untuk SELECT: result set-nya tidak pernah di-fetch/ditutup,
-- sehingga MySQL menolak dengan galat 2014 "unbuffered queries are
-- active". Untuk memeriksa hasilnya, jalankan manual — ketiga angka di
-- bawah harus 1, 1, dan jumlah toko yang bisa disambung:
-- SELECT
--   (SELECT COUNT(*) FROM information_schema.STATISTICS
--     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'marketplace_akun'
--       AND INDEX_NAME = 'uq_mpakun_toko')            AS kunci_satu_toko,
--   (SELECT COUNT(*) FROM information_schema.COLUMNS
--     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'marketplace_akun'
--       AND COLUMN_NAME = 'fs_id')                    AS kolom_fs_id,
--   (SELECT COUNT(*) FROM `toko` WHERE `deleted_at` IS NULL
--       AND `marketplace` IN ('shopee','tokopedia'))  AS toko_bisa_disambung;