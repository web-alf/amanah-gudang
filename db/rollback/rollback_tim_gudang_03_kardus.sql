-- Pembatalan migrasi_tim_gudang_03_kardus.sql
--
-- SYARAT: tidak boleh ada stok yang masih berada di dalam kardus. Periksa:
--   SELECT COUNT(*) FROM batch_barang WHERE kardus_id IS NOT NULL AND qty_tersisa > 0;
-- Bila hasilnya bukan 0, buka kembali (atau batalkan) kardus-kardus itu lewat
-- aplikasi dulu — isinya kembali menjadi stok lepas — baru jalankan ini.
-- Batch kardus yang sudah kosong dihapus; data stok lepas tidak tersentuh.

DELETE FROM batch_barang WHERE kardus_id IS NOT NULL AND qty_tersisa = 0;

ALTER TABLE perpindahan_detail DROP INDEX idx_ppd_kardus, DROP COLUMN kardus_id;

ALTER TABLE batch_barang
  DROP INDEX uq_batch_lokasi,
  ADD UNIQUE KEY uq_batch_lokasi (barang_id, nomor_batch, kondisi, sumber, donatur_id, lokasi_rak_id, toko_id, tanggal_expired);
ALTER TABLE batch_barang DROP INDEX idx_bb_kardus, DROP COLUMN kardus_id;

DROP TABLE IF EXISTS kardus_riwayat;
DROP TABLE IF EXISTS kardus_isi;
DROP TABLE IF EXISTS kardus;

DELETE FROM migrasi_terpasang WHERE nama = 'migrasi_tim_gudang_03_kardus.sql';
