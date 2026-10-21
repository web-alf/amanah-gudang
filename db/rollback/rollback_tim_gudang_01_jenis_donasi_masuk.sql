-- Pembatalan migrasi_tim_gudang_01_jenis_donasi_masuk.sql
-- Menghapus kolom sambungan saja; data penerimaan, donasi, dan stok tidak
-- tersentuh. Setelah dijalankan, hapus juga catatannya dari daftar migrasi:
ALTER TABLE transaksi_masuk DROP KEY idx_tm_donasi, DROP COLUMN donasi_id;
DELETE FROM migrasi_terpasang WHERE nama = 'migrasi_tim_gudang_01_jenis_donasi_masuk.sql';
