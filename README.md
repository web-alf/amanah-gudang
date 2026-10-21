# AMANAH — Aplikasi Manajemen Gudang Donasi (web)

Aplikasi PHP prosedural (PDO + MySQL/MariaDB), tanpa framework dan tanpa
Composer. Produksi: https://kelolaaset.space (unggah manual lewat File
Manager / FTP). Aplikasi Android ada di repositori terpisah (`C:\amanah_android`).

## Kebutuhan

- PHP 7.4+ (diuji 8.x) dengan ekstensi `pdo_mysql`, `mbstring`, `gd`, `zlib`,
  `curl`, `openssl`.
- MySQL 5.7+ / MariaDB 10.4+.
- Apache/LiteSpeed dengan `.htaccess` aktif.

## Struktur folder

| Lokasi | Isi |
|---|---|
| `*.php` (folder utama) | Halaman. Berawalan `_` = pustaka/partial, tertutup dari web (`.htaccess`). |
| `_lib.php` | Pustaka inti (stok, FEFO, perpindahan, hak akses, menu). Memuat `_kardus.php`, `_stok_pesan.php`, `_rincian_keluar.php`, `_lacak.php`, `_perpindahan_ubah.php` di ujungnya. |
| `api/` | API JSON untuk aplikasi Android (JWT). |
| `assets/` | CSS, JS, font, ikon. |
| `db/` | `instal_database.sql` (skema awal), `migrasi_*.sql` (dijalankan dari halaman Migrasi), `rollback/` (pembatalan migrasi). Tertutup dari web. |
| `docs/` | Catatan revisi, panduan instalasi & unggah, `BACA_DULU.txt`. Tertutup dari web. |
| `cadangan_berkas/` | Berkas cadangan SQL (`.sql.gz`) — tertutup dari web, tidak ikut repo. |
| `unggahan/` | Gambar unggahan (logo, foto barang, foto penerimaan) — tidak ikut repo. |
| `sesi/` | Berkas sesi/kunci internal — tidak ikut repo. |
| `unduhan/` | APK Android untuk diunduh petugas. |
| `tests/` | Uji FEFO baris perintah (`php tests/uji_fefo.php`). |
| `config.php` + `config.local.php` | Koneksi DB & rahasia. `config.local.php` dibuat dari `config.local.php.contoh` dan **tidak** ikut repo. |

## Instalasi baru (ringkas)

1. Salin seluruh isi ke folder web; buat `config.local.php` dari contohnya.
2. Impor `db/instal_database.sql` ke database kosong.
3. Masuk sebagai Super Admin › Pengaturan › Migrasi Basis Data › jalankan
   migrasi yang belum terpasang (urut nama).
4. Rincian: `docs/PANDUAN_INSTALASI.md`. Pembaruan hosting: `docs/BACA_DULU.txt`.

## Alur utama

Lihat *Booklet Alur AMANAH* (PDF) di paket serah terima: Barang Masuk →
Surat Jalan (perpindahan, kardus, persetujuan) → Penerimaan & Lacak →
Barang Keluar → Stock Opname → Laporan → Cadangan Data.
