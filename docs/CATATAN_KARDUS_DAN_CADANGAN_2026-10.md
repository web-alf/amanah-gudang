# Fitur Kardus & Cadangan Data — Oktober 2026

Lanjutan dari `CATATAN_REVISI_TIM_GUDANG_2026-10.md`. Dua fitur:
**Kardus** (dibuat di dalam transaksi Perpindahan; label QR; satu surat
jalan untuk kardus + barang lepas; diterima per kardus) dan **Cadangan
Data** (backup SQL yang bisa diunduh, otomatis harian, dikirim ke Google
Drive).

Kode dikelola dengan git di `C:\xampp\htdocs\Gudang`. Hosting diperbarui lewat
FTP / File Manager dari folder `C:\xampp\htdocs\Gudang_UNGGAH_kardus_cadangan`.

## Sebelum diunggah ke server

1. **Backup database** lewat phpMyAdmin (Export → SQL) atau Pengaturan ›
   Cadangan Data bila sudah terpasang. Simpan berkasnya.
2. Unggah seluruh isi folder unggah (timpa berkas lama; `db/` ke `db/`,
   `cadangan_berkas/` ke `cadangan_berkas/`). `config.local.php` tidak ikut.
3. **Hapus di hosting** (sisa rancangan lama): `kardus_form.php`,
   `kardus_batch.php`, `assets/kardus.js`, dan folder `cadangan/` (bukan
   `cadangan_berkas/`).
4. Buka **Pengaturan › Migrasi Basis Data**, jalankan migrasi yang belum
   terpasang, urut: `migrasi_tim_gudang_01_…`, `…_02_…`, lalu
   `migrasi_tim_gudang_03_kardus.sql`. Sebelum migrasi 03 dijalankan,
   pilihan kardus di formulir perpindahan menolak dengan pesan "Fitur kardus
   belum aktif" — tidak ada yang rusak.
5. Pastikan folder `cadangan_berkas/` bisa ditulis PHP (chmod 755/775 lewat
   panel hosting). Berkas `cadangan_berkas/.htaccess` ikut terunggah.
6. Buka **Pengaturan › Cadangan Data** (`cadangan.php`), tekan **Buat
   cadangan sekarang**, unduh berkasnya — uji pertama sekaligus backup
   sesudah migrasi.

Pembatalan: `db/rollback/rollback_tim_gudang_03_kardus.sql` (baca syarat di
dalamnya).

Query pemeriksaan sesudah migrasi (hanya membaca):

```sql
SELECT COUNT(*) FROM kardus;
SELECT COLUMN_NAME FROM information_schema.columns
 WHERE table_schema = DATABASE() AND table_name = 'perpindahan_detail' AND column_name = 'kardus_id';
```

## Kardus — cara kerja (revisi 12 Okt 2026)

**Kardus dibuat di dalam formulir Perpindahan** (`perpindahan.php` ›
Buat Perpindahan, atau `perpindahan_form.php`), bukan di menu terpisah.
Berlaku untuk jenis transfer gudang, kirim ke toko, dan penyaluran.

Alur di formulir:
1. Isi jenis, asal, tujuan, tanggal, data pengiriman seperti biasa.
2. Di atas kotak scan ada pilihan **"Bagaimana barang ini dikirim?"**:
   ○ Masuk kardus ○ Tanpa kardus. Pilihan berlaku untuk barang yang sedang
   ditambahkan, bukan seluruh dokumen.
3. **Masuk kardus** → kartu "Kardus 1" dibuat (nama/berat/dimensi/catatan
   opsional; kode KRD-… diberikan saat disimpan). Tombol di kartu:
   *Tambah barang ke kardus ini*, *Selesai mengisi*, *Hapus kardus*
   (barangnya menjadi tanpa kardus). *Buat kardus baru* untuk kardus
   berikutnya; dropdown "Pilih kardus" untuk kembali ke kardus yang ada.
4. Barang ditambahkan dengan cara yang sudah ada (scan/kamera, Pilih
   Banyak, Tempel Kode, Tambah Baris) dan masuk ke kelompok yang sedang
   aktif. Kolom **Kardus** di tabel barang bisa diubah per baris, jadi barang
   bisa dipindah antar kardus / keluar kardus tanpa kehilangan data.
   Barang yang sama ditembak lagi menambah jumlah baris di kardus yang sama;
   di kardus lain menjadi baris sendiri.
5. Kartu **Ringkasan Perpindahan**: KARDUS 1 (isi…), KARDUS 2 (…), BARANG
   TANPA KARDUS, lalu jumlah kardus, jenis barang, total per satuan, asal →
   tujuan. Tiap kelompok bisa dibuka/tutup.
6. Simpan (kirim untuk persetujuan / draft). Kardus kosong ditolak.

**Stok.** Isi kardus adalah baris perpindahan biasa bertanda kardus. Stok
dipotong **sekali**, lewat FEFO dokumen, saat disahkan (penyaluran) atau
diterima (transfer/kirim toko) — sama dengan barang tanpa kardus.
Validasi stok menghitung total per barang di seluruh dokumen (semua kardus
+ tanpa kardus). Mencetak label / surat jalan tidak mengubah stok.

**Status kardus mengikuti dokumen**: Dikemas (draft/menunggu persetujuan)
→ Dalam perjalanan (disahkan) → Diterima (penerimaan dikonfirmasi, atau
penyaluran disahkan). Dokumen ditolak/dibatalkan → kardus Dibatalkan; draft
dihapus → kardus ikut terhapus. Kode kardus tidak pernah dipakai ulang.

**Label** (`kardus_label.php`): tombol *Label Kardus* di halaman dokumen
(Persetujuan) mencetak semua label satu perpindahan urut "Kardus 1 dari 2";
dari Folder Kardus bisa satu/banyak. Isi label: logo/nama lembaga, kode
besar, QR (→ detail kardus, alamat stabil), nomor perpindahan, nomor surat
jalan (bila sudah terbit), asal, tujuan, penerima, tanggal, berat bila ada,
ringkasan isi ("+n jenis lainnya — pindai QR"). Ukuran 100×150 mm / A6 /
A4 (4 label). Simpan PDF lewat dialog cetak. Sebelum disetujui label
bertanda air "BELUM DISETUJUI"; kode tetap sama saat dicetak ulang.

**Surat jalan** (`surat_jalan.php`): satu nomor untuk seluruh barang.
Bagian **A. Barang dalam kardus** (tiap kardus satu tabel berjudul kode
kardusnya, tidak terpisah dari isinya saat ganti halaman), **B. Barang
tanpa kardus**, kaki: total kardus, jenis barang, total kuantitas per
satuan, catatan, tanda tangan. Bagian yang kosong tidak dicetak.

**Folder Kardus** (menu Transaksi › Kardus, `kardus.php`): cari (kode,
nama, barang, nomor perpindahan/surat jalan/resi), saring (gudang asal,
status, kondisi, periode), kotak pindai/ketik kode, kolom lokasi terakhir,
cetak ulang label (centang → cetak). Detail (`kardus_detail.php?k=KRD-…`):
perpindahan & surat jalan, asal/tujuan/penerima, resi ekspedisi (dibedakan
dari kode kardus), angkutan, isi (+ jumlah diterima), penerimaan (kondisi,
catatan, foto), riwayat. QR yang dipindai tanpa login → halaman login →
kembali ke kardus itu.

**Penerimaan** (`penerimaan.php`): kotak *Pindai label kardus* menyorot
kelompok kardusnya (tidak menandai diterima). Tiap kardus punya baris
kepala "Kardus 1 dari 2 — KRD-…": kondisi (Baik/Rusak/Hilang/Perlu
pemeriksaan), catatan, foto. Jumlah aktual per item dihitung seperti
biasa; selisih pada kardus "Baik" otomatis "Perlu pemeriksaan"; "Hilang"
mencatat seluruh isinya 0. Barang tanpa kardus diterima di tabel yang sama.
Konfirmasi tetap lewat tombol Terima.

**Ubah dokumen** (Super Admin, `perpindahan_ubah.php`): baris isi kardus
tetap berada di kardusnya; jumlahnya boleh diubah seperti baris lain; baris
baru masuk sebagai barang tanpa kardus.

Cara uji cepat: Perpindahan › Buat › transfer gudang › Masuk kardus →
tembak 2 barcode → Buat kardus baru → tembak 1 → Tanpa kardus → tembak 1 →
periksa Ringkasan → Kirim. Di Persetujuan: Label Kardus (2 label), setujui,
Cetak Surat Jalan (A per kardus + B). Pindai QR label → detail kardus.
Penerimaan di tujuan: pindai kardus, kondisi, terima → status Diterima,
stok tujuan bertambah sekali.

## Perubahan lain (12 Okt 2026)

- **Barang Masuk**: kotak Kondisi Bawaan dan Rak Bawaan dihilangkan dari
  Keterangan Penerimaan; tiap baris keranjang tetap punya pilihan kondisi
  dan raknya sendiri (baris baru mulai dari Baru dan rak pertama).
- **Ubah Dokumen Masuk › Data donasi**: kartunya selalu tampil. Dokumen
  yang sudah tersambung: jenis donasi (kunjungan/paket) bisa diubah dan
  donatur bisa diganti. Dokumen lama yang belum punya catatan donasi, atau
  yang tercatat pembelian: isi donatur + jenis donasi → catatan donasi
  dibuat dari seluruh baris dokumen, batch stoknya menjadi donasi atas
  donatur itu, dan dokumen masuk laporan donasi.
- **Alamat berakhiran garis miring** (hosting membuang `.php`): halaman
  yang terbuka dari `/nama/` dialihkan ke `/nama`.

## Cadangan Data — cara kerja (revisi 12 Okt 2026 malam)

Menu **Pengaturan › Cadangan Data** (Super Admin), berkas `_cadangan.php`,
`cadangan.php`, `cadangan_google.php`, `cadangan_unduh.php`,
`cron_cadangan.php`. Berkas backup disimpan di `cadangan_berkas/` (tertutup
dari URL).

- **Buat cadangan sekarang**: dump SQL lengkap (struktur + data + view,
  tanpa `DEFINER`) ke `cadangan_berkas/amanah_YYYYmmdd_HHMMSS.sql.gz`, bisa
  diunduh; diimpor kembali lewat phpMyAdmin (Import) atau `mysql < berkas.sql`.
- **Jadwal otomatis**: *Jarak antar cadangan* (tiap 1/2/3/6/12/24 jam) pada
  *jam aktif* (bawaan 05:00–22:30); *Di luar jam aktif cukup satu kali*
  (satu cadangan pada pemicu pertama setelah jam aktif berakhir). Dipicu
  oleh cron hosting (cPanel › Cron Jobs, **Once Per Hour**, perintah
  `curl -s "…/cron_cadangan.php?kunci=…" > /dev/null`; `&paksa=1` =
  selalu buat) atau, tanpa cron, diam-diam saat Super Admin membuka halaman
  ketika sudah jatuh tempo. *Simpan berapa cadangan terakhir* berlaku di
  server dan di Drive (yang lebih lama dihapus otomatis).
- **Google Drive lewat akun Google pengguna (OAuth, izin drive.file)**.
  Akun layanan tidak dipakai lagi (tidak punya kuota). Persiapan sekali:
  Google Cloud › aktifkan Drive API › OAuth consent screen (External, Test
  users = email yang dihubungkan) › Credentials › OAuth client ID (Web
  application) dengan Authorized redirect URI persis
  `https://kelolaaset.space/cadangan_google.php` (dan versi tanpa `.php`).
  Di aplikasi: isi Client ID & Secret › Simpan › **Hubungkan akun Google**
  (login & setujui) › **Buat folder di Drive** (aplikasi hanya bisa memakai
  folder buatannya sendiri; folder boleh dipindah/diganti nama) › **Uji
  Google Drive**. Setiap cadangan baru diunggah ke folder itu; token
  disimpan di tabel pengaturan (`google_*`).

## Yang belum / perlu diketahui

- Android belum berubah (kardus dan cadangan hanya di web).
- Unggah Google Drive (OAuth) belum diuji ke akun Google sungguhan — uji lewat tombol Hubungkan › Buat folder › Uji Google Drive.
- Nomor halaman pada surat jalan multi-halaman mengandalkan `@page` CSS
  (didukung Chrome/Edge saat cetak); header tabel diulang otomatis.
- Butir 8 revisi tim gudang (kondisi & rak bawaan) masih menunggu keputusan.
