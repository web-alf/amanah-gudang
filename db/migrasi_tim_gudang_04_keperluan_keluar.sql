-- =====================================================================
-- Revisi 18 Oktober 2026 — Rincian Barang Keluar
-- =====================================================================
-- 1. Barang Keluar punya KEPERLUAN terstruktur (Bayi, Karyawan, Dapur,
--    Ruang Kesehatan, Finance, Lainnya) supaya laporan bulanan bisa
--    dikelompokkan. Kolom lama untuk_bayi / divisi_cabang tetap ada.
-- 2. Gudang yang jenisnya kosong ('' — terjadi di hosting yang tidak
--    strict saat nilai enum tidak dikenal) dijadikan 'pusat'. Gudang Depo
--    di salinan produksi 12 Okt berjenis kosong.
--
-- AMAN UNTUK DATA LAMA: hanya menambah kolom kosong dan mengisi jenis
-- gudang yang kosong. Pembatalan: db/rollback/rollback_tim_gudang_04_keperluan_keluar.sql
-- BACKUP DATABASE DULU (Pengaturan › Cadangan Data) sebelum menjalankan.
-- =====================================================================

ALTER TABLE transaksi_keluar
  ADD COLUMN keperluan VARCHAR(50) NULL DEFAULT NULL AFTER divisi_cabang,
  ADD KEY idx_tk_keperluan (keperluan);

UPDATE gudang SET jenis = 'pusat' WHERE jenis = '' OR jenis IS NULL;
