# Peta Halaman Web — amanah-gudang

Daftar seluruh halaman web di root aplikasi (sesi berbasis cookie, bukan API).
Dibuat dari pembacaan kode langsung (`28 Oktober 2026`).

## Legenda

| Simbol | Arti |
|---|---|
| 🔓 | Tidak butuh login sama sekali |
| 🔒 | Butuh login (peran apa saja) |
| 👑 | Dibatasi peran tertentu — lihat kolom Gate |
| ⌨️ | **Hanya CLI** — ditolak kalau diakses lewat browser/HTTP |

---

## Masuk & akun sendiri

| Halaman | Akses | Gate | Ringkasan |
|---|---|---|---|
| `index.php` | 🔓 | — | Beranda, arahkan ke dashboard atau login |
| `login.php` | 🔓 | — | Form masuk |
| `logout.php` | 🔓 | — | Keluar dari sesi |
| `reset_sandi.php` | 🔓 | — | Setel ulang kata sandi lewat tautan sekali pakai (dibuat admin) |
| `ganti_password.php` | 🔒 | — | Ganti kata sandi sendiri |
| `profil.php` | 🔒 | — | Profil pengguna sendiri |
| `perangkat.php` | 🔒 | — | Perangkat yang sedang login, bisa diakhiri |
| `notifikasi.php` | 🔒 | — | Pusat notifikasi |

## Dasbor

| Halaman | Akses | Gate | Ringkasan |
|---|---|---|---|
| `dashboard.php` | 🔒 | — | Dasbor utama, tampilan sesuai peran |
| `dasbor_supplier.php` | 🔒 | — | Dasbor khusus akun supplier |
| `dasbor_toko.php` | 🔒 | — | Dasbor khusus akun toko |
| `pengawasan.php` | 🔒 | — | Mode lihat-saja mitra & lokasi, untuk super_admin/pimpinan |
| `monitoring.php` | 🔒 | — | Monitoring stok kritis & kedaluwarsa |

## Master data

| Halaman | Akses | Gate | Ringkasan |
|---|---|---|---|
| `barang.php` | 🔒 | — | Daftar barang |
| `barang_detail.php` | 🔒 | — | Detail satu barang |
| `barang_form.php` | 👑 | super_admin, admin_gudang, admin_terpadu | Tambah/ubah barang |
| `barang_hapus.php` | 👑 | super_admin, admin_gudang, admin_terpadu | Hapus (soft delete) barang |
| `kategori.php` | 🔒 | — | Master kategori barang |
| `gudang.php` | 🔒 | — | Master gudang |
| `gudang_detail.php` | 🔒 | — | Detail satu gudang |
| `rak.php` | 🔒 | — | Master rak/lokasi |
| `rak_detail.php` | 🔒 | — | Detail satu rak |
| `supplier.php` | 🔒 | — | Master supplier |
| `toko.php` | 🔒 | — | Master toko |
| `donatur.php` | 🔒 | — | Master donatur |
| `penerima.php` | 🔒 | — | Master penerima bantuan |
| `batch.php` | 🔒 | — | Daftar batch barang |
| `batch_edit.php` | 🔒 | — | Perbaiki keterangan satu batch |

## Transaksi gudang — masuk, keluar, perpindahan

| Halaman | Akses | Gate | Ringkasan |
|---|---|---|---|
| `masuk.php` | 🔒 | — | Barang masuk (gaya kasir) |
| `masuk_riwayat.php` | 🔒 | — | Riwayat semua dokumen barang masuk |
| `masuk_ubah.php` | 🔒 | — | Ubah dokumen barang masuk yang sudah tercatat |
| `keluar.php` | 👑 | super_admin, admin_gudang, admin_terpadu | Barang keluar (gaya kasir) |
| `perpindahan.php` | 🔒 | — | Daftar perpindahan barang antar lokasi |
| `perpindahan_form.php` | 🔒 | — | Buat perpindahan baru (halaman penuh) |
| `perpindahan_ubah.php` | 👑 | super_admin | Ubah dokumen perpindahan |
| `persetujuan.php` | 🔒 | — | Alur persetujuan dokumen perpindahan |
| `penerimaan.php` | 🔒 | — | Penerimaan barang dari dokumen perpindahan |

## Stok — opname, koreksi, pelepasan

| Halaman | Akses | Gate | Ringkasan |
|---|---|---|---|
| `opname.php` | 🔒 | — | Stock opname (hitung fisik per rak) |
| `barang_stok.php` | 🔒 | — | Sesuaikan stok satu produk |
| `koreksi_angka.php` | 👑 | super_admin | Koreksi angka stok tidak wajar |
| `penyesuaian_ubah.php` | 🔒 | — | Ubah satu catatan penyesuaian stok |
| `pelepasan.php` | 🔒 | — | Perbaikan & pelepasan barang rusak/kedaluwarsa |
| `stok_asal.php` | 🔒 | — | Cek stok yang masih bisa dikirim dari satu lokasi (JSON) |

## Kardus & pengiriman

| Halaman | Akses | Gate | Ringkasan |
|---|---|---|---|
| `kardus.php` | 🔒 | — | Folder kardus |
| `kardus_detail.php` | 🔒 | — | Detail satu kardus |
| `kardus_label.php` | 🔒 | — | Cetak label kardus |
| `surat_jalan.php` | 🔒 | — | Cetak surat jalan |
| `lacak.php` | 🔒 | — | Lacak kiriman |
| `kiriman_supplier.php` | 🔒 | — | Kiriman dari supplier, sisi internal |
| `scan_resi.php` | 🔒 | — | Scan resi barang |
| `cek_surat.php` | 🔒 | — | Cek surat jalan lewat nomor dokumen |

## Laporan

| Halaman | Akses | Gate | Ringkasan |
|---|---|---|---|
| `laporan.php` | 🔒 | — | Laporan umum |
| `laporan_donasi.php` | 🔒 | — | Laporan donasi |
| `laporan_harian_donasi.php` | 🔒 | — | Laporan harian donasi |
| `laporan_keluar.php` | 🔒 | — | Rincian barang keluar |
| `laporan_export.php` | 🔒 | — | Ekspor laporan stok ke CSV |
| `log_aktivitas.php` | 👑 | super_admin, pimpinan | Jejak aktivitas (audit log) |

## Marketplace & integrasi

| Halaman | Akses | Gate | Ringkasan |
|---|---|---|---|
| `marketplace.php` | 👑 | super_admin | Sambungan & pengaturan akun marketplace (Shopee/Tokopedia) |
| `marketplace_auth.php` | 👑 | super_admin | Pengalihan OAuth ke Shopee |
| `impor_pesanan.php` | 🔒 | — | Impor pesanan marketplace dari CSV |
| `cari_barang.php` | 🔒 | — | Pencarian barang gaya scan (dipakai fitur lain) |
| `cari_stok.php` | 🔒 | — | Cari stok untuk panel pilih banyak barang |

## Impor & migrasi data

| Halaman | Akses | Gate | Ringkasan |
|---|---|---|---|
| `impor_data.php` | 🔒 | — | Impor master data dari CSV |
| `migrasi.php` | 👑 | super_admin | Jalankan migrasi basis data (whitelist berkas) |
| `reset_data.php` | ⌨️ | — | **CLI saja** — kosongkan stok & transaksi, master data dipertahankan |
| `data_contoh.php` | ⌨️ | — | **CLI saja** — isi data contoh untuk demo/pengujian |

## Cadangan & pemeliharaan

| Halaman | Akses | Gate | Ringkasan |
|---|---|---|---|
| `cadangan.php` | 👑 | super_admin | Cadangan data manual, pengaturan jadwal |
| `cadangan_google.php` | 👑 | super_admin | Hubungkan akun Google untuk cadangan (OAuth) |
| `cadangan_unduh.php` | 👑 | super_admin | Unduh satu berkas cadangan |
| `cron_cadangan.php` | 🔓 | kunci rahasia / sesi super_admin | Pemicu cadangan otomatis — dipanggil cron hosting |
| `cron_marketplace.php` | ⌨️ | — | **CLI saja** — tarik pesanan marketplace terjadwal |
| `cek_sistem.php` | 🔓 | kunci rahasia `$KUNCI_CEK` | Pemeriksa kesiapan server — mati kalau kunci kosong (default) |
| `galat.php` | 👑 | super_admin | Lihat detail kode galat sistem |

## Pengaturan & admin

| Halaman | Akses | Gate | Ringkasan |
|---|---|---|---|
| `pengaturan.php` | 🔒 | — | Pengaturan umum, menu masuk ke sub-halaman lain |
| `pengaturan_aplikasi.php` | 👑 | super_admin | Kelola info pembaruan aplikasi Android |
| `pengaturan_merek.php` | 👑 | super_admin | Logo & identitas tampilan |
| `pengguna.php` | 🔒 | — | Kelola pengguna — halaman sendiri membatasi aksi lewat `require_role()` di tiap cabang |

## Alat bantu

| Halaman | Akses | Gate | Ringkasan |
|---|---|---|---|
| `alur.php` | 🔒 | — | Peta alur kerja & isi sistem, lengkap |
| `alat_scan.php` | 🔒 | — | Alat uji scanner |
| `alat_isi_lokasi.php` | ⌨️ | — | **CLI saja** — lihat isi satu lokasi |
| `alat_nolkan_stok.php` | ⌨️ | — | **CLI saja** — nolkan seluruh stok di satu lokasi |
| `aplikasi.php` | 🔓 | — | Halaman publik unduh aplikasi Android + QR code |

---

## Berkas konfigurasi (bukan halaman)

| Berkas | Fungsi |
|---|---|
| `config.php` | Konfigurasi koneksi database, baca `config.local.php` |
| `config.local.php` | Kredensial per-pemasangan — **tidak ikut git**, wajib diisi manual |
| `auth.php` | Pustaka sesi, cookie, CSRF, header keamanan — dipakai hampir semua halaman |

