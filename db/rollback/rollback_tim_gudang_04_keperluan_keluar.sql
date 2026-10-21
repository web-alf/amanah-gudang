-- Pembatalan migrasi_tim_gudang_04_keperluan_keluar.sql
-- Kolom keperluan dihapus (isinya hilang). Jenis gudang yang sudah diisi
-- 'pusat' TIDAK dikembalikan menjadi kosong — nilai kosong memang keliru.
ALTER TABLE transaksi_keluar DROP INDEX idx_tk_keperluan, DROP COLUMN keperluan;
DELETE FROM migrasi_terpasang WHERE nama = 'migrasi_tim_gudang_04_keperluan_keluar.sql';
