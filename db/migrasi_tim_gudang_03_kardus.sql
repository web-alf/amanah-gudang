-- =====================================================================
-- Fitur KARDUS — 12 Oktober 2026
-- =====================================================================
-- Satu kardus berisi banyak jenis barang, punya kode sendiri (KRD-…),
-- label QR, status proses, kondisi fisik, dan riwayat. Menutup kardus
-- MEMINDAHKAN isinya ke batch tersendiri yang ditandai kardus_id — tetap di
-- rak gudang asal, jadi total stok gudang tidak berubah; yang berubah hanya
-- stok lepas yang boleh dipakai pengiriman langsung (batch dengan
-- kardus_id tidak lagi diambil FEFO).
--
-- AMAN UNTUK DATA LAMA: hanya menambah tabel dan kolom kosong. Kunci unik
-- batch ditambah kolom kardus_id supaya batch lepas dan batch dalam kardus
-- dengan identitas sama boleh hidup berdampingan.
-- Pembatalan: db/rollback/rollback_tim_gudang_03_kardus.sql (baca syaratnya).
-- BACKUP DATABASE DULU (Pengaturan › Cadangan Data) sebelum menjalankan.
-- =====================================================================

CREATE TABLE IF NOT EXISTS kardus (
  id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  kode            VARCHAR(30) NOT NULL,
  nama            VARCHAR(150) NULL,
  gudang_id       BIGINT UNSIGNED NOT NULL,
  lokasi_rak_id   BIGINT UNSIGNED NULL,
  tanggal_kemas   DATE NOT NULL,
  petugas_id      BIGINT UNSIGNED NULL,
  berat_kg        DECIMAL(8,2) NULL,
  panjang_cm      DECIMAL(7,1) NULL,
  lebar_cm        DECIMAL(7,1) NULL,
  tinggi_cm       DECIMAL(7,1) NULL,
  catatan         VARCHAR(500) NULL,
  status          ENUM('draft','dikemas','siap_dikirim','dalam_perjalanan','diterima','dibatalkan') NOT NULL DEFAULT 'draft',
  kondisi         ENUM('baik','rusak','hilang','perlu_pemeriksaan') NOT NULL DEFAULT 'baik',
  perpindahan_id  BIGINT UNSIGNED NULL,
  no_resi         VARCHAR(60) NULL,
  ditutup_at      DATETIME NULL,
  diterima_at     DATETIME NULL,
  diterima_oleh   BIGINT UNSIGNED NULL,
  catatan_terima  VARCHAR(500) NULL,
  foto_terima     VARCHAR(255) NULL,
  alasan_batal    VARCHAR(255) NULL,
  created_at      TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at      TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_kardus_kode (kode),
  KEY idx_kardus_status (status),
  KEY idx_kardus_gudang (gudang_id),
  KEY idx_kardus_pp (perpindahan_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS kardus_isi (
  id               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  kardus_id        BIGINT UNSIGNED NOT NULL,
  barang_id        BIGINT UNSIGNED NOT NULL,
  batch_id         BIGINT UNSIGNED NULL     COMMENT 'batch lepas sumber (dipilih saat mengemas)',
  batch_kardus_id  BIGINT UNSIGNED NULL     COMMENT 'batch bertanda kardus_id sesudah ditutup',
  qty              INT NOT NULL,
  qty_diterima     INT NULL,
  catatan          VARCHAR(255) NULL,
  PRIMARY KEY (id),
  KEY idx_kisi_kardus (kardus_id),
  KEY idx_kisi_barang (barang_id),
  CONSTRAINT fk_kisi_kardus FOREIGN KEY (kardus_id) REFERENCES kardus (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS kardus_riwayat (
  id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  kardus_id   BIGINT UNSIGNED NOT NULL,
  aksi        VARCHAR(30) NOT NULL,
  keterangan  VARCHAR(500) NULL,
  user_id     BIGINT UNSIGNED NULL,
  created_at  TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_kriw_kardus (kardus_id),
  CONSTRAINT fk_kriw_kardus FOREIGN KEY (kardus_id) REFERENCES kardus (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

ALTER TABLE batch_barang
  ADD COLUMN kardus_id BIGINT UNSIGNED NULL DEFAULT NULL AFTER toko_id,
  ADD KEY idx_bb_kardus (kardus_id);

ALTER TABLE batch_barang
  DROP INDEX uq_batch_lokasi,
  ADD UNIQUE KEY uq_batch_lokasi (barang_id, nomor_batch, kondisi, sumber, donatur_id, lokasi_rak_id, toko_id, tanggal_expired, kardus_id);

ALTER TABLE perpindahan_detail
  ADD COLUMN kardus_id BIGINT UNSIGNED NULL DEFAULT NULL AFTER batch_id,
  ADD KEY idx_ppd_kardus (kardus_id);
