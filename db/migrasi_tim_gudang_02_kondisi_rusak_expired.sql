-- =====================================================================
-- Revisi tim gudang, butir 3 — kondisi "Rusak/Bocor" dan "Expired"
-- 11 Oktober 2026
-- =====================================================================
-- Tim gudang butuh membedakan barang rusak/bocor dari barang kedaluwarsa;
-- selama ini keduanya terpaksa dicatat sebagai satu nilai, bekas_tidak_layak.
--
-- Pilihan untuk input BARU menjadi: Baru, Layak, Rusak/Bocor, Expired.
--   baru         -> "Baru"          layak salur
--   bekas_layak  -> "Layak"         layak salur   (hanya labelnya berganti)
--   rusak        -> "Rusak/Bocor"   TIDAK layak salur   (baru)
--   expired      -> "Expired"       TIDAK layak salur   (baru)
--   bekas_tidak_layak               TIDAK layak salur   (tetap ada untuk data
--                                   lama, tampil "Tidak layak (lama)", tidak
--                                   ditawarkan lagi untuk input baru)
--
-- AMAN UNTUK DATA LAMA
--   * Hanya MENAMBAH dua nilai ke daftar enum lima tabel. Tidak ada baris
--     yang diubah; semua nilai lama tetap sah dan tetap berarti sama.
--   * View v_stok_kondisi dibuat ulang: kolom layak_salur yang dulu berbunyi
--     "bukan bekas_tidak_layak" kini "baru atau bekas_layak", supaya rusak
--     dan expired ikut terhitung tidak layak.
--   * Pembatalan: db/rollback/rollback_tim_gudang_02_kondisi_rusak_expired.sql
--     (nilai rusak/expired yang sudah dipakai dikembalikan menjadi
--     bekas_tidak_layak — pembedaannya hilang, stoknya tidak).
--
-- BACKUP DATABASE DULU sebelum menjalankan berkas ini di server.
-- =====================================================================

ALTER TABLE batch_barang
  MODIFY kondisi ENUM('baru','bekas_layak','bekas_tidak_layak','rusak','expired') NOT NULL DEFAULT 'baru';
ALTER TABLE donasi_detail
  MODIFY kondisi ENUM('baru','bekas_layak','bekas_tidak_layak','rusak','expired') NOT NULL DEFAULT 'baru';
ALTER TABLE perpindahan_detail
  MODIFY kondisi ENUM('baru','bekas_layak','bekas_tidak_layak','rusak','expired') NOT NULL DEFAULT 'baru';
ALTER TABLE stok_supplier
  MODIFY kondisi ENUM('baru','bekas_layak','bekas_tidak_layak','rusak','expired') NOT NULL DEFAULT 'baru';
ALTER TABLE pengiriman_supplier_detail
  MODIFY kondisi ENUM('baru','bekas_layak','bekas_tidak_layak','rusak','expired') NOT NULL DEFAULT 'baru';

CREATE OR REPLACE VIEW v_stok_kondisi AS
SELECT bb.barang_id,
       b.kode   AS kode_barang,
       b.nama   AS nama_barang,
       b.satuan AS satuan,
       bb.kondisi,
       (bb.kondisi IN ('baru','bekas_layak')) AS layak_salur,
       CASE WHEN bb.toko_id IS NOT NULL THEN 'toko' ELSE 'gudang' END AS lokasi_tipe,
       COALESCE(t.nama, g.nama, '?') AS lokasi_nama,
       COUNT(*)                      AS jml_batch,
       SUM(bb.qty_tersisa)           AS qty,
       SUM(bb.qty_tersisa * b.harga) AS nilai
FROM batch_barang bb
JOIN barang b            ON b.id  = bb.barang_id
LEFT JOIN toko t         ON t.id  = bb.toko_id
LEFT JOIN lokasi_rak lr  ON lr.id = bb.lokasi_rak_id
LEFT JOIN gudang g       ON g.id  = lr.gudang_id
WHERE bb.qty_tersisa > 0
GROUP BY bb.barang_id, bb.kondisi,
         CASE WHEN bb.toko_id IS NOT NULL THEN 'toko' ELSE 'gudang' END,
         COALESCE(t.nama, g.nama, '?');
