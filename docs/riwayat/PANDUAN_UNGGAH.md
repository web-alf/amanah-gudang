# Yang harus dilakukan di hosting

> **PEMBARUAN 13 September 2026.** `api/cek.php`, `versi.php`, dan `cek_toko.php`
> sudah DIHAPUS dari kode (membocorkan error log & versi server tanpa login);
> jangan diunggah lagi, dan hapus dari hosting bila masih ada. Seluruh berkas
> `.sql` kini berada di folder `db/` (tertutup dari peramban). Migrasi dijalankan
> dari menu **Pengaturan › Migrasi Basis Data**, bukan lagi tangan lewat phpMyAdmin.
> Daftar unggah terbaru ada di `CATATAN_PERUBAHAN_2026-09-13.md`.


Urutannya penting: migrasi basis data dulu, berkas kedua, pemeriksaan terakhir.

## 1. Jalankan `migrasi_skema.sql`

phpMyAdmin → pilih database `kelw9817_gudang_bayi` → tab **Import** →
pilih berkas `migrasi_skema.sql` → **Go**.

Berkas ini menggantikan `migrasi_view.sql` yang sebelumnya. Yang dulu itu
gagal di tengah jalan bukan karena viewnya salah, melainkan karena tabel
yang dirujuknya belum ada di hosting.

**Yang diperbaiki**, sesuai hasil probe `cek.php`:

| Hilang di hosting | Akibatnya |
|---|---|
| tabel `donatur`, `donasi`, `donasi_detail` | Donatur 500; detail Barang Keluar ikut gagal karena menyambung ke donatur |
| tabel `pelepasan_stok` | Perbaikan & Pelepasan 500 |
| kolom `batch_barang`.`sumber` | daftar Barang Masuk 500 |
| view `v_peringkat_donatur`, `v_pelepasan` | keduanya gagal terbuat karena tabel di atas belum ada |

**Berkas ini tidak menghapus apa pun.** Tidak ada satu pun `DROP TABLE`,
`DELETE`, atau `TRUNCATE` di dalamnya. Tabel yang sudah ada dilewati
beserta seluruh isinya; kolom hanya ditambahkan bila belum ada.
Menjalankannya dua kali sama hasilnya dengan sekali.

Sudah saya uji terhadap tiruan keadaan hosting Anda: 39 tabel jadi 43,
12 view jadi 14, dan seluruh baris data tetap utuh.

## 2. Unggah berkas (timpa yang lama)

| Berkas | Taruh di | Kenapa |
|---|---|---|
| `api/donatur.php` | `public_html/api/` | Tambah/ubah/hapus donatur |
| `api/cek.php` | `public_html/api/` | Alat pemeriksa — dipakai di langkah 3 |

`notifikasi.php`, `opname.php`, dan `_lib.php` sudah terunggah — hasil
`cek.php` memastikannya.

## 3. Periksa hasilnya

Buka: `https://kelolaaset.space/api/cek.php`

Yang harus terbaca:

- `"kesimpulan": "SEHAT ..."`
- `"view_hilang": []` dan `"view_terpasang": "14 dari 14"`
- `"probe"` — keempatnya `"OK"`
- `"masalah": []`

## 4. Hapus alat pemeriksanya

Setelah semua hijau, **hapus `api/cek.php` dari hosting.** Ia tidak
menampilkan kata sandi maupun nama database, tapi alat pemeriksa di server
produksi tetap memberi tahu penyerang lebih banyak daripada yang perlu.
