-- =====================================================================
-- Revisi tim gudang, butir 1 — sambungan penerimaan ↔ catatan donasi
-- 11 Oktober 2026
-- =====================================================================
-- Satu penerimaan donasi lewat Barang Masuk menulis DUA kepala: baris di
-- `transaksi_masuk` (stok) dan baris di `donasi` (donatur + jenis donasi).
-- Sampai sekarang keduanya tidak saling menunjuk. Kolom baru ini
-- menyambungkannya, supaya Jenis Donasi bisa ditampilkan & disaring di
-- riwayat penerimaan, dijumlahkan di Laporan Harian Donasi, dan supaya
-- donatur sebuah penerimaan bisa diganti tanpa menebak.
--
-- AMAN UNTUK DATA LAMA
--   * Hanya MENAMBAH satu kolom yang boleh kosong. Tidak ada baris yang
--     dihapus, dan tidak ada jenis donasi yang diisi/ditebak.
--   * Penerimaan lama disambungkan ke catatan donasinya dengan mencocokkan
--     petugas, supplier, dan detik penyimpanan yang sama (keduanya ditulis
--     dalam satu transaksi). Yang tidak menemukan pasangan dibiarkan kosong
--     dan tampil sebagai "Belum diklasifikasi".
--   * Pembatalan: db/rollback/rollback_tim_gudang_01_jenis_donasi_masuk.sql
--
-- BACKUP DATABASE DULU sebelum menjalankan berkas ini di server.
-- =====================================================================

ALTER TABLE transaksi_masuk
  ADD COLUMN donasi_id BIGINT UNSIGNED NULL DEFAULT NULL AFTER supplier_id,
  ADD KEY idx_tm_donasi (donasi_id);

UPDATE transaksi_masuk tm
   SET tm.donasi_id = (
         SELECT d.id FROM donasi d
          WHERE d.dibuat_oleh <=> tm.user_id
            AND d.supplier_id <=> tm.supplier_id
            AND ABS(TIMESTAMPDIFF(SECOND, d.created_at, tm.created_at)) <= 5
          ORDER BY ABS(TIMESTAMPDIFF(SECOND, d.created_at, tm.created_at)), d.id
          LIMIT 1)
 WHERE tm.donasi_id IS NULL
   AND EXISTS (SELECT 1 FROM transaksi_masuk_detail x
                 JOIN batch_barang b ON b.id = x.batch_id
                WHERE x.transaksi_masuk_id = tm.id AND b.sumber = 'donasi');
