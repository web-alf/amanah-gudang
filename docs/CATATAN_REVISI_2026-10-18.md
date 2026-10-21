# Revisi Tambahan Tim Gudang — 18 Oktober 2026

Lanjutan `CATATAN_REVISI_TIM_GUDANG_2026-10.md` dan
`CATATAN_KARDUS_DAN_CADANGAN_2026-10.md`. Commit `744420c` … `5f6988c`.

## Sebelum diunggah

1. Backup database (Pengaturan › Cadangan Data › Buat cadangan sekarang, unduh).
2. Unggah isi folder unggah; hapus berkas lama yang disebut di `BACA_DULU.txt`.
3. Pengaturan › Migrasi Basis Data › jalankan `migrasi_tim_gudang_04_keperluan_keluar.sql`
   (kolom Keperluan di Barang Keluar + jenis gudang kosong → `pusat`).
   Pembatalan: `db/rollback/rollback_tim_gudang_04_keperluan_keluar.sql`.

## Yang berubah

| Masukan tim | Perubahan |
|---|---|
| Surat jalan macet/blank bila 10+ jenis barang | Baris barang tidak lagi memuat dropdown seluruh katalog (±550 produk per baris). Baris = nama barang + tombol *ganti*; *Tambah Baris* membuka kotak cari. Uji lokal: 15 baris ± 0,7 detik. |
| Jumlah bisa melebihi stok; barang yang sudah dipilih tidak mengurangi stok | Stok yang sudah **dipesan** surat jalan lain (menunggu persetujuan / dikirim) dikurangkan dari stok lokasi asal (`_stok_pesan.php`). Tiap baris menampilkan *tersedia N* (merah bila melebihi) dan simpan ditahan. Server memeriksa ulang saat simpan, saat draft dikirim, dan saat disetujui (yang disetujui lebih dulu yang mendapat stoknya). |
| Filter rak: pilih jenis barang & jumlah seluruh stok otomatis | Panel *Pilih Banyak Barang*: angka *tersedia* sudah dikurangi pesanan & yang sudah di formulir; kotak **Otomatis = seluruh stok tersedia** + *Pilih semua* langsung mengisi jumlah penuh. Sumber rak juga menghormati pesanan tingkat gudang. |
| Penerimaan tidak sesuai, tidak tahu barang mana | Halaman penerimaan menampilkan kolom **Stok asal** per barang dan peringatan barang yang akan gagal; galat potong stok menyebut nama barang; Riwayat Penerimaan menyebut tiap barang yang selisih (dikirim → diterima, catatan). |
| Beberapa draft jadi satu surat jalan | Daftar Surat Jalan: centang 2+ draft (jenis, asal, tujuan sama) › **Gabungkan draft**. Baris & kardus pindah ke draft tertua, baris kembar dijumlah, catatan digabung. |
| Kode kardus di mutasi rak; transfer tinggal centang kardus | Pindah Antar Rak boleh *Masuk kardus*: kardus dikemas sambil dipindah ke rak siap kirim, status **Siap dikirim**. Formulir transfer/kirim toko/penyaluran menampilkan **Kardus siap kirim di lokasi asal** — tinggal dicentang; isi dibaca ulang dari basis data, stok diambil dari rak kardusnya. Ditolak/dibatalkan/draft dihapus → kardus kembali siap kirim. |
| Rincian barang keluar per bulan (bayi, karyawan, dapur, ruang kesehatan, finance) & per tujuan (tim penjualan Bekasi, Gudang Rans, …) | Barang Keluar punya pilihan **Keperluan**. Laporan › **Rincian Barang Keluar**: per bulan & gudang — A pemakaian per keperluan, B surat jalan per tujuan (terkirim / dalam perjalanan), C tren 6 bulan. |

## Pertanyaan Gudang Apotek

- Stok berpindah dari gudang asal **hanya saat penerimaan dikonfirmasi** di
  gudang tujuan. Bila surat jalan Gudang Apotek → Gudang Depo masih berstatus
  *Dikirim* (badge Penerimaan Barang), stok tetap tercatat di Gudang Apotek.
  Konfirmasi lewat Penerimaan Barang di akun Gudang Depo.
- Bila barangnya dimasukkan ke Depo lewat **Barang Masuk** (bukan surat
  jalan), Gudang Apotek tidak pernah dikurangi → stok tercatat dua kali.
  Betulkan lewat Stock Opname / penyesuaian di Gudang Apotek.
- Gudang Apotek tampil di sebuah akun karena akun itu ditugaskan memegang
  Gudang Apotek (Pengaturan › Pengguna › gudang yang dipegang). Lepas
  penugasannya bila tidak diinginkan.
- Di salinan data 12 Okt, Gudang Depo berjenis kosong — diperbaiki migrasi 04.
