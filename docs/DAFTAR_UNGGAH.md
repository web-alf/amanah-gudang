# Daftar Unggah ke kelolaaset.space

> **PEMBARUAN 13 September 2026.** `api/cek.php`, `versi.php`, dan `cek_toko.php`
> sudah DIHAPUS dari kode (membocorkan error log & versi server tanpa login);
> jangan diunggah lagi, dan hapus dari hosting bila masih ada. Seluruh berkas
> `.sql` kini berada di folder `db/` (tertutup dari peramban). Migrasi dijalankan
> dari menu **Pengaturan › Migrasi Basis Data**, bukan lagi tangan lewat phpMyAdmin.
> Daftar unggah terbaru ada di `CATATAN_PERUBAHAN_2026-09-13.md`.


Disusun 1 September 2026. Dasar pembandingnya `Gudang_extracted/` — salinan
tertanggal 28 Agustus 2026. **Kalau sejak tanggal itu ada yang sudah Anda
unggah manual, daftar ini bisa memuat berkas yang sebenarnya sudah sama.**
Mengunggahnya ulang tidak merusak apa pun, hanya jadi pekerjaan sia-sia.

- Total: **48 berkas, ±1,1 MB**, ditambah satu migrasi SQL.
- **SATU migrasi basis data dibutuhkan**, dan hanya satu:
  `arsip_sql/migrasi_izin_pengguna.sql` — membuat tabel `izin_pengguna`.
  Jalankan lewat phpMyAdmin **sebelum** mengunggah berkas PHP-nya. Aman
  diulang, tidak menyentuh satu baris data pun.

  Tanpa tabel itu sistem tetap jalan: kode membaca "gagal baca" sebagai
  "ikut peran & tingkat", persis perilaku sebelum fitur izin ada. Tapi
  editor izinnya tidak akan menyimpan apa pun.

- Selebihnya tidak ada migrasi. `penyesuaian_stok`, `dokumen_counter`,
  `sesi_perangkat`, `user_toko`, dan enum `koreksi_tambah`/`koreksi_kurang`
  sudah dibuat `migrasi_amanah_v3.sql` yang sudah dijalankan.
- `config.php`, `_footer.php`, dan `.htaccess` akar **identik** — jangan
  diunggah. `assets/amanah.css` **berubah** (gaya editor izin) — ikut naik.
- Sudah diperiksa: tidak ada satu pun sintaks khusus PHP 8 di 48 berkas ini
  (`match()`, `?->`, `readonly`, `array_is_list()`). Aman untuk PHP 7.4.

---

## Urutannya penting

Halaman yang memanggil berkas pembantu akan mati dengan *fatal error* kalau
pembantunya belum ada. Jadi pembantu dulu, halaman kemudian. Kalau urutannya
dibalik, ada jeda beberapa detik saat Barang Masuk toko benar-benar rusak
bagi siapa pun yang sedang membukanya.

### Tahap 1 — pembantu bersama (aman, belum mengubah tampilan apa pun)

- [ ] `_lib.php`  ← memuat katalog & mesin izin per pengguna
- [ ] `_masuk_gudang_toko.php`  ← **baru**

Setelah tahap ini situs masih berjalan seperti sebelumnya. Fungsi baru di
dalamnya hanya menganggur sampai halaman pemakainya diunggah.

### Tahap 2 — penjaga API lebih dulu, baru isinya

- [ ] `api/.htaccess`  ← **unggah SEBELUM `_lingkup.php`**

Alasannya: `.htaccess` inilah yang memblokir berkas berawalan `_` dari
peramban. Kalau `_lingkup.php` naik lebih dulu, ada jeda saat berkas itu bisa
diminta langsung lewat URL.

- [ ] `api/_lingkup.php`  ← **baru**

### Tahap 3 — sisa API

Baru:

- [ ] `api/cek.php`
- [ ] `api/donatur.php`
- [ ] `api/notifikasi.php`
- [ ] `api/opname.php`
- [ ] `api/pelepasan.php`

Berubah:

- [ ] `api/barang.php`
- [ ] `api/dashboard.php`
- [ ] `api/keluar.php`
- [ ] `api/laporan.php`
- [ ] `api/login.php`
- [ ] `api/master.php`
- [ ] `api/masuk.php`
- [ ] `api/monitoring.php`
- [ ] `api/penerimaan.php`
- [ ] `api/pengguna.php`
- [ ] `api/perpindahan.php`
- [ ] `api/persetujuan.php`
- [ ] `api/profil.php`
- [ ] `api/scan.php`

### Tahap 4 — halaman web

Baru:

- [ ] `barang_stok.php`  ← halaman edit stok per produk

Berubah:

- [ ] `assets/amanah.css`  ← gaya editor izin
- [ ] `laporan.php`  ← kolom rupiah kini ikut izin
- [ ] `_header.php`  ← lencana persetujuan ikut izin
- [ ] `perpindahan.php`  ← tombol setujui ikut izin
- [ ] `_barang.php`
- [ ] `_barang_isian.php`
- [ ] `barang.php`
- [ ] `dasbor_supplier.php`
- [ ] `dasbor_toko.php`
- [ ] `donatur.php`
- [ ] `keluar.php`
- [ ] `masuk.php`
- [ ] `penerimaan.php`
- [ ] `pengguna.php`
- [ ] `perangkat.php`
- [ ] `persetujuan.php`

Halaman master data — pintunya kini dibuka izin `kelola_master`:

- [ ] `kategori.php`
- [ ] `gudang.php`
- [ ] `rak.php`
- [ ] `supplier.php`
- [ ] `toko.php`
- [ ] `penerima.php`
- [ ] `pelepasan.php`
- [ ] `impor_pesanan.php`

---

## `reset_data.php` — sebaiknya JANGAN diunggah

Berkas ini menghapus seluruh data transaksi. Ia sudah dikunci hanya untuk
CLI (`php reset_data.php --ya`) dan menolak dengan 403 bila diminta lewat
peramban, tapi menaruh skrip penghapus massal di server publik tetap satu
risiko yang tidak perlu ditanggung.

Untuk mereset data di hosting, pakai `arsip_sql/reset_data.sql` lewat
phpMyAdmin. Hasilnya sama dan tidak ada apa pun yang tertinggal di server.

Kalau Anda tetap memilih jalur CLI, hapus berkasnya segera setelah dipakai.

---

## Sesudah diunggah — 9 pemeriksaan cepat

1. Masuk sebagai **toko manager** → menu **Barang Masuk** → tombol
   *Tambah Barang Masuk* muncul di kanan judul.
2. Masuk sebagai **toko staff** → tombol itu **tidak ada**.
3. Coba satu barang masuk kecil (mis. 1 unit) sebagai manager → dapat nomor
   `TM-…`, stok gudang toko bertambah.
4. Barang itu langsung muncul di pilihan **Gudang Toko → Distribusi**.
5. Buka **Pengguna** sebagai super admin → kolom *Perangkat* bisa diklik
   untuk semua akun, termasuk yang sedang tidak online.
6. Buka **Barang → salah satu produk → Stok** → halaman `barang_stok.php`
   terbuka, riwayat penyesuaian tampil.
7. **Izin per pengguna** — buka **Pengguna → ubah satu akun staff gudang**.
   Panel *Izin Khusus Akun Ini* muncul dengan tujuh baris, semuanya pada
   "Ikut tingkat". Beri *Izinkan* pada **Lihat Nilai Rupiah**, simpan, lalu
   masuk sebagai akun itu → kartu **Nilai Stok** dan kolom **Nilai** di
   Laporan Stok kini tampil, padahal tingkatnya tetap staff.
8. Panel izin punya **tujuh** baris dan **tidak ada satu pun yang mati** —
   setiap wewenang bisa diberikan ke peran dan tingkat apa pun.
9. Beri *Izinkan* pada **Setujui Dokumen** untuk satu akun toko, lalu masuk
   sebagai akun itu → halaman **Persetujuan** terbuka, tetapi yang terlihat
   HANYA dokumen tokonya. Izin membuka wewenang, bukan lingkup.

Kalau ada halaman yang blank putih, itu hampir selalu berkas pembantu yang
belum naik — periksa `error_log` hosting, dan pastikan Tahap 1 sudah selesai.
