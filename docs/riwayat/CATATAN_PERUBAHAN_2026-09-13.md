# Catatan Perubahan — 13 September 2026

Perapian besar web AMANAH mengikuti `ANALISIS_KEKURANGAN.md`. Semua diuji di
XAMPP lokal: 43 halaman × 3 peran (super admin, admin gudang, toko) tanpa galat,
seluruh endpoint API dengan 4 peran, `php tests/uji_fefo.php` 13 lulus.

## 1. Keamanan

- **Dihapus dari kode**: `api/cek.php` (bocor error log tanpa login), `versi.php`,
  `cek_toko.php`. `.htaccess` kini juga memblokir nama-nama itu, jadi salinan lama
  di hosting ikut mati begitu `.htaccess` baru diunggah. **Hapus juga manual.**
- **Rahasia keluar dari kode**: `config.php` tidak lagi memuat `root`/kosong;
  kredensial DB, `$JWT_SECRET`, dan `$KUNCI_CEK` (kunci `cek_sistem.php`) dibaca
  dari `config.local.php` (contoh: `config.local.php.contoh`). Tanpa berkas itu
  aplikasi berhenti dengan pesan yang menyebut sebabnya. Kunci JWT yang kosong
  diganti kunci acak per server di `sesi/.kunci_jwt` — tidak ada lagi kunci
  bawaan yang tertulis di `api/_jwt.php`.
- **Semua `.sql` dipindah ke `db/`** (dengan `.htaccess` tolak semua), termasuk
  `arsip_sql/`.
- **Pembatas percobaan masuk berbasis tabel** (`_pembatas.php`, tabel `login_gagal`
  dibuat sendiri): 5 gagal per email atau 30 per IP dalam 10 menit → kunci 10
  menit. Berlaku untuk web DAN API (API sebelumnya tanpa pembatas).
- **Peran & status akun dibaca ulang tiap permintaan**: web (penjaga sesi di
  `_lib.php`) dan API (`auth_required()`). Akun yang dinonaktifkan langsung keluar
  (`login.php?nonaktif=1` / 401), peran yang diubah langsung berlaku.
- **Lingkup API ditegakkan** di `master.php` (gudang/rak/supplier/toko per akun;
  penerima tanpa alamat & telepon untuk akun eksternal, termasuk pada POST/PUT),
  `monitoring.php`, `alur.php` (daftar & detail), `scan.php`, `barang.php`
  (stok/batch/lokasi dalam lingkup, riwayat mutasi hanya akun lembaga & gudang).
  Pembantunya: `api_syarat_batch()`, `api_sub_stok()`, `api_in_gudang()` di
  `api/_lingkup.php`.
- `api/kiriman.php` PUT: staf gudang saja; supplier hanya membatalkan kirimannya
  sendiri. `laporan_export.php` & `laporan.php`: izin `lihat_laporan` ditegakkan
  (bawaannya terbuka; pencabutan kini berlaku dan menunya hilang), kolom rupiah
  hilang bila tidak berhak.
- Header keamanan dari PHP (`auth.php`): CSP (`script-src 'self' 'unsafe-inline'`,
  `object-src 'none'`, `frame-ancestors 'self'`, dsb.), HSTS saat HTTPS, cookie
  `Secure` sadar proksi (`koneksi_https()`).
- 5 titik XSS `innerHTML` dilolosi; 31 kebocoran `getMessage()` di API diganti
  pesan umum; `state` pada OAuth Shopee; CSRF pada POST `cek_surat.php`.

## 2. Bug & basis data

- `fefo_sql()` — satu pembangun kueri untuk `ambil_fefo()` dan `pratinjau_fefo()`;
  pratinjau kini menghormati kondisi layak & batch pilihan. Diuji `tests/uji_fefo.php`.
- Variabel CSS `--line` dan `--r-md` didefinisikan; `nama_lokasi()` dihafal per
  permintaan (menghilangkan N+1 di daftar perpindahan/penerimaan).
- `db/migrasi_indeks_2026_09.sql`: indeks `deleted_at` di 9 tabel,
  `perpindahan(tujuan_tipe,tujuan_id,status)`, `(asal_tipe,asal_id,status)`,
  `batch_barang(lokasi_rak_id,qty_tersisa)`, `(toko_id,qty_tersisa)`.
- `db/instal_database.sql` **dibangkitkan ulang dari skema hidup**: 47 tabel,
  14 view (tanpa DEFINER), peran (termasuk `admin_terpadu`), kategori, akun,
  mitra & toko. Versi lama kehilangan 8 tabel dan belasan kolom. Diuji impor ke
  basis data kosong.
- **Halaman `migrasi.php`** (Pengaturan › Migrasi Basis Data): menjalankan
  `db/migrasi_*.sql` yang belum terpasang dan mencatatnya di `migrasi_terpasang`.
  Tabel/kolom/indeks yang sudah ada dilewati, bukan gagal.

## 3. Fitur baru di web

| Halaman | Isi |
|---|---|
| `opname.php` | Stock opname per rak (hitung fisik, selisih wajib beralasan, riwayat) |
| `notifikasi.php` | Pusat notifikasi; logikanya di `_notifikasi.php`, dipakai API juga; lonceng topbar selalu menuju ke sini |
| `barang_detail.php`, `gudang_detail.php`, `rak_detail.php` | Detail baca-saja dalam lingkup akun (nama di daftar kini bisa diklik) |
| `log_aktivitas.php` | Jejak aktivitas lengkap, bisa dicari/disaring/dipaginasi (Super Admin & Pimpinan; tautan "lihat semua" di dasbor) |
| `reset_sandi.php` | Tautan reset kata sandi sekali pakai 60 menit, dibuat dari tombol **Reset** di Kelola Pengguna |
| `persetujuan.php` | **Batalkan dokumen** (draft/menunggu/dikirim, alasan wajib) dan **Hapus draft**; status baru `dibatalkan` (`db/migrasi_status_dibatalkan.sql`); API `aksi: batal` / `hapus` |
| `perpindahan.php` | Saringan status mati (`disetujui`, `diterima`) dihapus, `dibatalkan` ditambah |

## 4. Tampilan (UX)

- **Tema disamakan dengan aplikasi Android**: terang bawaan, aksen biru `#2563EB`,
  huruf Poppins (swa-host di `assets/fonts`, tanpa CDN). Gelap tetap ada lewat
  tombol tema, dengan palet gelap Android. Halaman masuk ikut.
- Kotak cari + paginasi di Gudang, Supplier, Toko, Kategori, Penerima, Donatur;
  kotak cari di Pengguna dan antrian Persetujuan (`cari_bar()`, `paginasi_nav()`).
- Tombol kirim terkunci saat formulir dikirim (mencegah klik ganda); 16
  `confirm()` bawaan peramban diganti dialog konfirmasi bersama yang menyebut
  objek & akibatnya, dengan label tombol sesuai tindakannya.
- Judul halaman menjadi `<h1>` (satu per halaman).

## 5. Yang belum dikerjakan (sengaja ditunda)

- Pemecahan `_lib.php` (4.600 baris) — penanda bagiannya tidak seragam, perlu
  dipetakan dulu supaya potongannya benar. `api/_lingkup.php` tetap terpisah,
  tetapi web kini bisa memakainya lewat `principal_sesi()` (arah penyatuan).
- Label `for=` pada formulir lama; dasbor Toko/Supplier yang timpang; `<style>`
  di `alat_scan.php`, `barang_stok.php`, `surat_jalan.php` (warnanya sudah biru).
- Aplikasi Android belum memakai aksi `batal`/`hapus` di layar persetujuan.

## 6. Cara memasang di hosting (kelolaaset.space)

1. Di `config.local.php` hosting tambahkan (sekali): `$JWT_SECRET = '<32+ karakter acak>';`
   dan `$KUNCI_CEK = '';`. Kalau `$JWT_SECRET` dikosongkan, kunci acak dibuat sendiri
   di `sesi/.kunci_jwt`. **Semua ponsel harus masuk ulang** setelah ini.
2. Unggah berkas di bagian 7. Unggah `.htaccess` dan `api/_lingkup.php` lebih dulu.
3. **Hapus** dari hosting: `api/cek.php`, `versi.php`, `cek_toko.php`, semua `*.sql`
   dan folder `arsip_sql/` di akar (pindahkan ke `db/` bila ingin disimpan).
4. Masuk sebagai Super Admin → Pengaturan › **Migrasi Basis Data** → jalankan yang
   menunggu: `migrasi_indeks_2026_09.sql`, `migrasi_login_gagal.sql`,
   `migrasi_reset_sandi.sql`, `migrasi_status_dibatalkan.sql`. Berkas migrasi lama
   yang dulu sudah dijalankan tangan cukup **Tandai sudah**.
5. Buka Pengaturan › Jejak Aktivitas dan Notifikasi untuk memastikan halaman baru hidup.

## 7. Daftar unggah (94 berkas)

Baru:
`_notifikasi.php` `_pembatas.php` `api/_lingkup.php` `api/gudang.php` `api/rak.php` `api/notifikasi.php`
`assets/font.css` `assets/fonts/poppins_{regular,medium,semibold,bold}.ttf` `assets/keranjang-masuk.js`
`config.local.php.contoh` `db/.htaccess` `db/migrasi_indeks_2026_09.sql` `db/migrasi_login_gagal.sql`
`db/migrasi_reset_sandi.sql` `db/migrasi_status_dibatalkan.sql` `db/instal_database.sql`
`barang_detail.php` `gudang_detail.php` `rak_detail.php` `log_aktivitas.php` `migrasi.php`
`notifikasi.php` `opname.php` `reset_sandi.php` `tests/.htaccess` `tests/uji_fefo.php`

Berubah:
`.htaccess` `_footer.php` `_header.php` `_lib.php` `_perpindahan_js.php` `alat_scan.php`
`api/_bootstrap.php` `api/_jwt.php` `api/alur.php` `api/barang.php` `api/kategori.php` `api/kiriman.php`
`api/login.php` `api/master.php` `api/monitoring.php` `api/pengguna.php` `api/perpindahan.php`
`api/persetujuan.php` `api/scan.php` `assets/amanah.css` `auth.php` `barang.php` `barang_form.php`
`cek_sistem.php` `cek_surat.php` `config.php` `dasbor_supplier.php` `dasbor_toko.php` `dashboard.php`
`donatur.php` `ganti_password.php` `gudang.php` `impor_data.php` `impor_pesanan.php` `index.php`
`kategori.php` `keluar.php` `kiriman_supplier.php` `laporan.php` `laporan_export.php` `login.php`
`marketplace.php` `marketplace_auth.php` `masuk.php` `monitoring.php` `pelepasan.php` `penerima.php`
`penerimaan.php` `pengaturan.php` `pengaturan_merek.php` `pengawasan.php` `pengguna.php` `perangkat.php`
`perpindahan.php` `perpindahan_form.php` `persetujuan.php` `profil.php` `rak.php` `reset_data.php`
`scan_resi.php` `supplier.php` `surat_jalan.php` `toko.php`

Tidak diunggah: `config.local.php` (milik tiap server), folder `sesi/`, `Gudang_extracted/`
(salinan lama 28 Agu — sebaiknya dihapus dari komputer ini juga, isinya kode lama dengan
celah yang sudah ditutup).
