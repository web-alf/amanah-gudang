-- =====================================================================
-- Indeks yang selama ini tidak ada — 13 September 2026
-- =====================================================================
-- Hampir setiap kueri daftar menyaring `deleted_at IS NULL`, dan _header.php
-- menghitung lencana penerimaan lewat perpindahan(tujuan_tipe, tujuan_id,
-- status) pada SETIAP pemuatan halaman. Tanpa indeks, keduanya memindai
-- seluruh tabel. Aman dijalankan pada basis data yang sudah berisi data.
--
-- Bila sebuah indeks sudah ada, MySQL membalas "Duplicate key name" —
-- abaikan baris itu dan lanjutkan; sisanya tetap terpasang.
ALTER TABLE barang       ADD INDEX ix_barang_deleted      (deleted_at);
ALTER TABLE kategori     ADD INDEX ix_kategori_deleted    (deleted_at);
ALTER TABLE supplier     ADD INDEX ix_supplier_deleted    (deleted_at);
ALTER TABLE toko         ADD INDEX ix_toko_deleted        (deleted_at);
ALTER TABLE gudang       ADD INDEX ix_gudang_deleted      (deleted_at);
ALTER TABLE lokasi_rak   ADD INDEX ix_rak_deleted         (deleted_at);
ALTER TABLE penerima     ADD INDEX ix_penerima_deleted    (deleted_at);
ALTER TABLE users        ADD INDEX ix_users_deleted       (deleted_at);
ALTER TABLE donatur      ADD INDEX ix_donatur_deleted     (deleted_at);
ALTER TABLE perpindahan  ADD INDEX ix_pp_tujuan_status    (tujuan_tipe, tujuan_id, status);
ALTER TABLE perpindahan  ADD INDEX ix_pp_asal_status      (asal_tipe, asal_id, status);
ALTER TABLE batch_barang ADD INDEX ix_batch_lokasi_sisa   (lokasi_rak_id, qty_tersisa);
ALTER TABLE batch_barang ADD INDEX ix_batch_toko_sisa     (toko_id, qty_tersisa);
