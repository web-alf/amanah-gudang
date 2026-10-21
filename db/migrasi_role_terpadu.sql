-- =====================================================================
-- AMANAH — Peran terpadu: gudang dan penerimaan donasi di satu tangan
-- =====================================================================
-- Sistem ini semula memisahkan supplier dan gudang sebagai DUA PIHAK:
-- supplier mencatat barang di portalnya, menerbitkan surat jalan, lalu
-- gudang menerima dan menghitung fisiknya. Pemisahan itu benar ketika
-- pemasoknya memang pihak luar.
--
-- Tapi pada lembaga yang gudang dan penerimaan titipannya berada di SATU
-- TEMPAT dan SATU LEMBAGA, rangkaian itu menjadi upacara: orang yang sama,
-- di ruangan yang sama, menerbitkan surat jalan untuk dirinya sendiri lalu
-- menerimanya sendiri. Barangnya tidak berpindah ke mana pun. Yang
-- dihasilkan hanya dua dokumen yang harus dijaga tetap konsisten, dan
-- setiap dokumen yang tidak mewakili perpindahan nyata cepat atau lambat
-- akan diisi sekenanya.
--
-- Peran ini memampatkan rangkaian itu: barang dicatat sekali, langsung
-- masuk stok gudang, lengkap dengan donatur dan kondisinya.
--
-- YANG TIDAK DIUBAH: peran `supplier` tetap ada dan tetap berfungsi. Ada
-- lembaga yang memang bermitra dengan pemasok luar, dan mencabut perannya
-- akan mematikan alur yang sah bagi mereka. Peran ini DITAMBAHKAN, bukan
-- mengganti.
--
-- BERJALAN DI MySQL MAUPUN MariaDB, dan aman dijalankan berulang kali.
-- =====================================================================

-- INSERT IGNORE aman diulang: kolom `nama` sudah unik, jadi eksekusi kedua
-- tidak membuat baris kembar dan tidak menimpa keterangan yang mungkin
-- sudah disunting operator.
INSERT IGNORE INTO `roles` (`nama`, `label`, `keterangan`) VALUES
('admin_terpadu', 'Admin Gudang & Donasi',
 'Gudang dan penerimaan donasi di satu tempat: catat titipan beserta donaturnya, kelola stok, salurkan. Tanpa surat jalan antar pihak.');
