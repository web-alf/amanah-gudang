# Akun Dummy — Pengetesan Tiap Peran

Dibuat/dihapus lewat `akun_dummy.php` di root aplikasi (CLI-only, sama seperti
`data_contoh.php`). **Hanya untuk dev/testing lokal** — jangan dijalankan di
hosting asli.

```bash
php akun_dummy.php          # buat / perbarui semua akun dummy (aman diulang)
php akun_dummy.php --hapus  # hapus semua akun dummy
```

Semua akun memakai domain `@dummy.test` (domain reservasi RFC 2606, tidak
pernah ada di internet sungguhan) agar gampang dibedakan dari akun asli dan
gampang dibersihkan sebelum go-live.

## Daftar Akun

| Peran | Email | Password | Keterangan |
|---|---|---|---|
| Super Admin | `superadmin@dummy.test` | `Dummy#2026` | — |
| Pimpinan | `pimpinan@dummy.test` | `Dummy#2026` | — |
| Admin Gudang | `gudang1@dummy.test` | `Dummy#2026` | scope: gudang pertama di database |
| Admin Gudang | `gudang2@dummy.test` | `Dummy#2026` | scope: gudang kedua di database |
| Supplier | `supplier1@dummy.test` | `Dummy#2026` | scope: supplier pertama di database |
| Supplier | `supplier2@dummy.test` | `Dummy#2026` | scope: supplier kedua di database |
| Toko | `toko1@dummy.test` | `Dummy#2026` | pegang 1 toko |
| Toko | `toko2@dummy.test` | `Dummy#2026` | pegang 2 toko (uji fitur "Pemilih toko") |

Supplier/gudang/toko yang dipakai diambil otomatis dari data master yang
**sudah ada** di database saat skrip dijalankan (dua/tiga baris pertama tiap
jenis) — bukan id yang dikarang — jadi nama master di atas bisa berbeda
tergantung isi database Anda. Jalankan skrip untuk melihat nama persisnya.

## Kenapa 2 Akun untuk Admin Gudang / Supplier / Toko

Supaya bisa langsung menguji **isolasi lingkup** antar akun dengan peran
sama — hal yang paling sering jadi bug keamanan:

- Admin Gudang 1 **tidak boleh** melihat data gudang milik Admin Gudang 2.
- Supplier 1 **tidak boleh** melihat kiriman/harga milik Supplier 2.
- Toko 2 dipakai untuk menguji "Pemilih toko" (satu akun, berpindah antar
  lapak tanpa login ulang).

## Cara Menjalankan

**Docker:**
```bash
docker exec amanah-gudang-app-1 php /var/www/html/akun_dummy.php
```

**XAMPP / PHP lokal** (dari folder aplikasi, `config.local.php` harus sudah
terisi):
```bash
php akun_dummy.php
```

Menjalankan lewat peramban (`http://.../akun_dummy.php`) **ditolak otomatis**
(403) — berkas ini sengaja dikunci CLI-only.
