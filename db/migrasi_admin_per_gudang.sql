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

SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;

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

-- Dua SELECT pelapor sengaja DIHAPUS dari sini (bukan dikomentari) —
-- runner migrasi.php mengeksekusi tiap pernyataan lewat PDO::exec(),
-- yang tidak dirancang untuk SELECT: result set-nya tidak pernah
-- di-fetch/ditutup, sehingga pernyataan berikutnya gagal dengan MySQL
-- 2014 "unbuffered queries are active". Untuk melihat hasilnya, jalankan
-- manual: SELECT u.nama, u.email, g.kode, g.nama AS gudang FROM users u
-- JOIN roles r ON r.id = u.role_id LEFT JOIN gudang g ON g.id = u.gudang_id
-- WHERE r.nama = 'admin_gudang' AND u.deleted_at IS NULL;
