# Peta API — amanah-gudang

Daftar seluruh endpoint REST di `api/`, dipakai aplikasi Android. Autentikasi JWT
lewat header `Authorization: Bearer <token>`, diterbitkan oleh `POST /api/login.php`.

Dibuat dari pembacaan kode langsung (`28 Oktober 2026`), bukan ditulis ulang dari
dokumentasi lama — kalau ada perbedaan dengan catatan lain, kode ini yang benar.

## Cara kerja umum

- **Base path:** `/api/`
- **Autentikasi:** header `Authorization: Bearer <JWT>`, kecuali `login.php` dan
  `versi_aplikasi.php` yang sengaja publik.
- **Peran & lingkup token selalu dibaca ulang dari database setiap permintaan** —
  bukan dipercaya dari isi token. Akun yang dinonaktifkan kehilangan akses di
  permintaan berikutnya, bukan menunggu token kedaluwarsa.
- **Masa berlaku token:** 7 hari (`JWT_TTL`, `api/_jwt.php`).
- **CORS:** `Access-Control-Allow-Origin: *` — aman di sini karena autentikasi
  berbasis token, bukan cookie sesi.
- **Paginasi:** sebagian besar endpoint daftar memakai `?page=&limit=` lewat
  `paginate()` (`api/_bootstrap.php`), dibatasi maksimum 100 per halaman.

## Berkas pustaka (bukan endpoint, jangan dipanggil langsung)

| Berkas | Isi |
|---|---|
| `_bootstrap.php` | Header CORS, muat konfigurasi, helper `only()`/`paginate()`/`err()`/`ok()` |
| `_jwt.php` | Terbit & verifikasi JWT (HMAC-SHA256) |
| `_lingkup.php` | Fungsi penyaring lingkup gudang/toko/supplier per peran — padanan API dari `_lib.php` sisi web |

---

## Daftar endpoint

| Endpoint | Metode | Login? | Ringkasan |
|---|---|---|---|
| `alur.php` | GET | Ya | Alur kerja & peta modul aplikasi |
| `barang.php` | GET, POST, PUT, DELETE | Ya | Master data barang — daftar, detail, tambah, ubah, hapus |
| `dashboard.php` | GET | Ya | Ringkasan dasbor sadar-peran (stok, aktivitas, kedaluwarsa) |
| `donatur.php` | GET, POST, PUT, DELETE | Ya | Master donatur |
| `gudang.php` | GET | Ya | Master gudang + opsi `?isi=stok` untuk inventaris satu gudang |
| `kategori.php` | GET, POST, PUT, DELETE | Ya | Master kategori barang |
| `keluar.php` | GET, POST | Ya | Transaksi barang keluar — daftar, detail, catat baru |
| `kiriman.php` | GET, PUT | Ya | Kiriman dari supplier — daftar & ubah status (tiba/batal) |
| `laporan.php` | GET | Ya | Laporan stok, kedaluwarsa, arus masuk/keluar |
| `login.php` | POST | **Tidak** | Autentikasi — terbitkan JWT |
| `master.php` | GET, POST, PUT, DELETE | Ya | Master data gabungan: supplier, toko, gudang, rak, penerima (lewat `?entity=`) |
| `masuk.php` | GET, POST | Ya | Transaksi barang masuk — daftar, detail, catat baru |
| `monitoring.php` | GET | Ya | Monitoring stok kritis & kedaluwarsa |
| `notifikasi.php` | GET | Ya | Daftar hal yang menunggu tindakan pemegang token |
| `opname.php` | GET, POST | Ya | Stock opname per rak — daftar rak, riwayat, simpan hitungan |
| `pelepasan.php` | GET, POST (aksi: lepas, selesai) | Ya | Pelepasan/perbaikan barang rusak — kandidat, proses, riwayat |
| `penerimaan.php` | GET, POST | Ya | Penerimaan barang dari dokumen perpindahan |
| `pengawasan.php` | GET | Ya | Mode lihat-saja untuk mitra & lokasi (super_admin/pimpinan) |
| `pengguna.php` | GET, POST, PUT, DELETE | Ya | Kelola akun pengguna (super_admin) |
| `perpindahan.php` | GET, POST | Ya | Perpindahan barang antar lokasi — daftar, detail, buat baru |
| `persetujuan.php` | GET, POST (aksi: kirim, setujui, tolak, batal) | Ya | Alur persetujuan dokumen perpindahan |
| `profil.php` | GET, POST, PUT, DELETE | Ya | Profil sendiri + sub-resource `?sub=perangkat` (daftar/akhiri sesi) |
| `rak.php` | GET | Ya | Master rak/lokasi dalam gudang |
| `scan.php` | GET | Ya | Pencarian cepat lewat barcode/QR — produk, batch, dokumen |
| `versi_aplikasi.php` | GET | **Tidak** | Info versi APK terbaru untuk pengecekan pembaruan |

---

## Endpoint multi-aksi (satu URL, beberapa operasi)

Beberapa endpoint menerima POST dengan field `aksi` untuk menentukan operasinya,
alih-alih satu URL per operasi:

| Endpoint | Aksi yang didukung |
|---|---|
| `persetujuan.php` | `kirim`, `setujui`, `tolak`, `batal` |
| `pelepasan.php` | `lepas`, `selesai` |

