-- =====================================================================
-- AMANAH — Donasi: cara barang sampai, beserta resi paketnya
-- =====================================================================
-- Yayasan wajib melaporkan donasi KUNJUNGAN terpisah dari donasi PAKET.
-- Keduanya berbeda pertanggungjawabannya: yang pertama ada tatap muka dan
-- tanda terima langsung, yang kedua hanya punya nomor resi sebagai bukti
-- bahwa barangnya benar-benar dikirim dan benar-benar tiba.
--
-- Kolom `cara_terima` sudah ada di tabel `donasi` sejak awal —
-- enum('diantar','dijemput','dikirim') — tetapi TIDAK PERNAH DITANYAKAN.
-- masuk.php menuliskannya mati sebagai 'diantar' untuk setiap donasi,
-- termasuk paket yang jelas-jelas datang lewat ekspedisi. Jadi kolomnya
-- ada, isinya seragam, dan laporannya mustahil disusun.
--
-- Yang kurang untuk paket: identitas kirimannya. Tanpa nomor resi, donasi
-- paket tidak bisa ditelusuri balik ke bukti pengirimannya — dan itu
-- justru satu-satunya bukti yang dipunyai donasi yang tidak bertatap muka.
--
-- Dua kolom ditambahkan, keduanya BOLEH KOSONG: donasi yang diantar
-- langsung memang tidak punya resi, dan memaksakan kolom wajib akan
-- membuat petugas mengarang isi.
-- =====================================================================

ALTER TABLE donasi
  ADD COLUMN ekspedisi VARCHAR(60) NULL
      COMMENT 'Nama kurir/ekspedisi: JNE, J&T, GoSend, Grab, dsb. Kosong bila diantar/dijemput'
      AFTER cara_terima;

ALTER TABLE donasi
  ADD COLUMN no_resi VARCHAR(60) NULL
      COMMENT 'Nomor resi paket. Kosong bila donasi tidak dikirim lewat ekspedisi'
      AFTER ekspedisi;

-- Laporan donasi selalu disaring dua hal bersamaan: rentang tanggal dan
-- cara terimanya. Indeks gabungan, bukan dua indeks terpisah — yang
-- terpisah hanya membantu salah satu sisi saringan.
ALTER TABLE donasi
  ADD INDEX idx_donasi_cara_tanggal (cara_terima, tanggal);

-- Resi dicari sendirian saat ada donatur menanyakan paketnya sudah tiba
-- atau belum. Tidak unik: satu resi bisa memuat beberapa donasi, dan
-- pengirim yang berbeda bisa memakai penomoran yang kebetulan sama.
ALTER TABLE donasi
  ADD INDEX idx_donasi_resi (no_resi);
