# Peta Lainnya — Basis Data, Cron, Alat CLI, Integrasi

Pelengkap `PETA_API.md` dan `PETA_HALAMAN.md`: bagian aplikasi yang bukan halaman web
maupun endpoint API. Dibuat dari pembacaan kode langsung (`28 Oktober 2026`).
Sumber skema: `db/migrasi_skema.sql` (lebih lengkap & mutakhir dibanding `db/instal_database.sql`,
yang belum memuat tabel donatur/donasi).

---

## Basis data — 43 tabel

Dikelompokkan per domain, bukan urutan abjad murni.

| Domain | Tabel |
|---|---|
| Pengguna & akses | `users`, `roles`, `user_gudang`, `user_toko`, `sesi_perangkat` |
| Master lokasi | `gudang`, `lokasi_rak`, `toko`, `supplier`, `donatur`, `penerima` |
| Master barang | `barang`, `kategori`, `batch_barang`, `stok`, `stok_supplier` |
| Donasi | `donasi`, `donasi_detail` |
| Barang masuk | `transaksi_masuk`, `transaksi_masuk_detail` |
| Barang keluar | `transaksi_keluar`, `transaksi_keluar_detail` |
| Perpindahan antar lokasi | `perpindahan`, `perpindahan_detail` |
| Pengiriman supplier | `pengiriman_supplier`, `pengiriman_supplier_detail`, `resi_scan_log` |
| Penjualan | `penjualan`, `penjualan_detail` |
| Retur | `retur` |
| Stok — koreksi & opname | `penyesuaian_stok`, `stock_opname`, `stock_opname_detail`, `mutasi_barang` |
| Pelepasan (rusak/kedaluwarsa/perbaikan) | `pelepasan_stok` |
| Marketplace | `marketplace_akun`, `marketplace_sku`, `marketplace_sync_log`, `marketplace_tarikan` |
| Notifikasi & aktivitas | `notifikasi`, `activity_log` |
| Lain-lain | `pengaturan`, `dokumen_counter` |

## Tampilan (view) — 14 view

Semua `v_*` adalah query baca-saja yang meringkas gabungan beberapa tabel untuk laporan/dasbor.
Tidak ada logika tulis di dalamnya.

| View | Dipakai untuk |
|---|---|
| `v_stok_per_gudang` | Ringkasan stok per gudang |
| `v_stok_toko` | Ringkasan stok per toko |
| `v_stok_kondisi` | Stok dipecah menurut kondisi barang |
| `v_stok_rantai` | Stok di seluruh rantai gudang→toko |
| `v_status_stok` | Status stok (aman/menipis/habis) |
| `v_status_expired` | Status kedaluwarsa per batch |
| `v_expired_rantai` | Barang kedaluwarsa di seluruh rantai |
| `v_perpindahan_ringkas` | Ringkasan dokumen perpindahan |
| `v_pelepasan` | Catatan pelepasan/perbaikan barang — dipakai `pelepasan.php` & `api/pelepasan.php` |
| `v_penjualan_harian` | Rekap penjualan harian |
| `v_penyaluran_penerima` | Rekap penyaluran ke penerima bantuan |
| `v_peringkat_donatur` | Peringkat donatur berdasarkan kontribusi |
| `v_petugas_gudang` | Daftar petugas per gudang |
| `v_perangkat_aktif` | Perangkat yang sedang aktif login — dipakai `perangkat.php` |

---

## Tugas terjadwal (cron)

| Berkas | Pemicu | Fungsi |
|---|---|---|
| `cron_marketplace.php` | **CLI saja**, dijadwalkan lewat cron hosting | Tarik pesanan baru dari Shopee/Tokopedia secara berkala |
| `cron_cadangan.php` | HTTP, dengan kunci rahasia di query string ATAU sesi `super_admin` | Jalankan cadangan data terjadwal — dipicu cron hosting lewat `wget`/`curl` ke URL-nya |

## Alat command-line (pengujian & utilitas)

Seluruhnya di `tests/`, ditolak kalau diakses lewat HTTP (gerbang `php_sapi_name() === 'cli'`),
butuh koneksi database hidup, cakupan hanya logika domain gudang.

| Berkas | Fungsi |
|---|---|
| `tests/uji_fefo.php` | Uji algoritma FEFO (First-Expired-First-Out) |
| `tests/uji_opname.php` | Uji alur stock opname |
| `tests/uji_pelepasan.php` | Uji alur pelepasan/perbaikan barang |
| `tests/uji_perpindahan.php` | Uji alur perpindahan antar lokasi |
| `tests/uji_stok.php` | Uji perhitungan stok |

Alat serupa di root (bukan `tests/`, juga CLI-only): `reset_data.php`, `data_contoh.php`,
`alat_isi_lokasi.php`, `alat_nolkan_stok.php` — lihat `PETA_HALAMAN.md` bagian "Impor & migrasi data" dan "Alat bantu".

## Integrasi eksternal

| Pustaka | Mitra | Fungsi |
|---|---|---|
| `_shopee.php` | Shopee Open Platform | Tarik pesanan, sinkron SKU, OAuth toko Shopee |
| `_tokopedia.php` | Tokopedia Seller API | Tarik pesanan, sinkron SKU |
| `_marketplace.php` | — | Lapisan abstraksi umum di atas `_shopee.php`/`_tokopedia.php`, dipakai `marketplace.php` & `cron_marketplace.php` |
| `cadangan_google.php` | Google Drive (OAuth) | Unggah cadangan basis data otomatis ke Drive milik admin |

## Pustaka inti (dipakai lintas halaman, bukan halaman itu sendiri)

| Berkas | Isi |
|---|---|
| `_lib.php` | Logika domain inti: lingkup akses, FEFO, stok, pelepasan, aktivitas, dll. |
| `_header.php` / `_footer.php` | Bingkai tampilan HTML bersama |
| `auth.php` | Sesi, cookie, CSRF, header keamanan |
| `config.php` / `config.local.php` | Konfigurasi koneksi database |

