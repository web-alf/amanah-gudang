-- Pembatalan migrasi_tim_gudang_02_kondisi_rusak_expired.sql
-- Nilai 'rusak' dan 'expired' yang sudah terpakai dikembalikan menjadi
-- 'bekas_tidak_layak' (sama-sama tidak layak salur; pembedaannya hilang,
-- jumlah stoknya tidak berubah). Lalu enum dikembalikan ke tiga nilai.
UPDATE batch_barang               SET kondisi = 'bekas_tidak_layak' WHERE kondisi IN ('rusak','expired');
UPDATE donasi_detail              SET kondisi = 'bekas_tidak_layak' WHERE kondisi IN ('rusak','expired');
UPDATE perpindahan_detail         SET kondisi = 'bekas_tidak_layak' WHERE kondisi IN ('rusak','expired');
UPDATE stok_supplier              SET kondisi = 'bekas_tidak_layak' WHERE kondisi IN ('rusak','expired');
UPDATE pengiriman_supplier_detail SET kondisi = 'bekas_tidak_layak' WHERE kondisi IN ('rusak','expired');

ALTER TABLE batch_barang               MODIFY kondisi ENUM('baru','bekas_layak','bekas_tidak_layak') NOT NULL DEFAULT 'baru';
ALTER TABLE donasi_detail              MODIFY kondisi ENUM('baru','bekas_layak','bekas_tidak_layak') NOT NULL DEFAULT 'baru';
ALTER TABLE perpindahan_detail         MODIFY kondisi ENUM('baru','bekas_layak','bekas_tidak_layak') NOT NULL DEFAULT 'baru';
ALTER TABLE stok_supplier              MODIFY kondisi ENUM('baru','bekas_layak','bekas_tidak_layak') NOT NULL DEFAULT 'baru';
ALTER TABLE pengiriman_supplier_detail MODIFY kondisi ENUM('baru','bekas_layak','bekas_tidak_layak') NOT NULL DEFAULT 'baru';

CREATE OR REPLACE VIEW v_stok_kondisi AS
SELECT bb.barang_id, b.kode AS kode_barang, b.nama AS nama_barang, b.satuan AS satuan, bb.kondisi,
       (bb.kondisi <> 'bekas_tidak_layak') AS layak_salur,
       CASE WHEN bb.toko_id IS NOT NULL THEN 'toko' ELSE 'gudang' END AS lokasi_tipe,
       COALESCE(t.nama, g.nama, '?') AS lokasi_nama,
       COUNT(*) AS jml_batch, SUM(bb.qty_tersisa) AS qty, SUM(bb.qty_tersisa * b.harga) AS nilai
FROM batch_barang bb
JOIN barang b ON b.id = bb.barang_id
LEFT JOIN toko t ON t.id = bb.toko_id
LEFT JOIN lokasi_rak lr ON lr.id = bb.lokasi_rak_id
LEFT JOIN gudang g ON g.id = lr.gudang_id
WHERE bb.qty_tersisa > 0
GROUP BY bb.barang_id, bb.kondisi, CASE WHEN bb.toko_id IS NOT NULL THEN 'toko' ELSE 'gudang' END, COALESCE(t.nama, g.nama, '?');

DELETE FROM migrasi_terpasang WHERE nama = 'migrasi_tim_gudang_02_kondisi_rusak_expired.sql';
