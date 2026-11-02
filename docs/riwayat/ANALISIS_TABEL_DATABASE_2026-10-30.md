# Analisis Tabel Database — 30 Oktober 2026

## Ringkasan eksekutif

| Ukuran | Nilai |
|---|---|
| Total tabel yang didefinisikan di repo | **55** |
| Dipakai aktif (ditulis dan/atau dibaca oleh kode) | **52** |
| Tidak dipakai (tidak pernah ditulis) | **3** — `mutasi_barang`, `stock_opname`, `stock_opname_detail` |
| Ada di `db/instal_database.sql` | 40 |
| **Tidak** ada di `instal_database.sql` | 15 |
| Mesin penyimpanan | InnoDB (semua tabel di skema dasar) |

**Kesimpulan:** jumlah tabel **bukan** masalah performa. Tabel kosong nyaris tidak membebani MySQL. Yang perlu dibenahi adalah **kebersihan skema**: 3 tabel mati, skema tersebar di tiga tempat, dan sebagian log yang tumbuh tanpa batas. **Optimasi database besar-besaran belum perlu**, kecuali pengukuran ukuran tabel di produksi menunjukkan sebaliknya (lihat bagian 6).

> **Batasan analisis.** Ini analisis statis terhadap kode dan file SQL. Saya **tidak** terhubung ke database, jadi jumlah baris, ukuran tabel, dan isi data produksi **belum diketahui**. Rekomendasi di bagian 5 dan 6 perlu diverifikasi dengan data nyata sebelum dieksekusi.

---

## 1. Metode

1. Mengumpulkan semua `CREATE TABLE [IF NOT EXISTS] <nama>` dari file `.sql` dan `.php` (di luar `tests/`, `rollback/`, `.git`). Hasilnya 59 kandidat; 4 di antaranya salah tangkap dari kata biasa (`akan`, `di`, `if`, `portabel`), sehingga **55 tabel nyata**.
2. Untuk tiap tabel, menghitung baris kode PHP yang merujuknya (di luar `CREATE`/`DROP TABLE`) dan jumlah file yang merujuk.
3. Tabel dengan referensi sedikit diperiksa satu per satu: apakah ada `INSERT`/`UPDATE` (ditulis), `SELECT` (dibaca), atau hanya muncul di alat reset/hitung data.
4. Mengecek lokasi definisi tiap tabel: skema dasar, file migrasi, atau kode PHP.

Keterbatasan metode: pencarian berbasis nama. Nama tabel yang dirangkai dinamis di PHP (misalnya `"tabel_" . $x`) tidak terdeteksi. Dari pemeriksaan sampel, tidak ada tabel yang tampak diakses dengan cara itu.

---

## 2. Tabel yang tidak dipakai (3)

| Tabel | Bukti | Referensi yang ada |
|---|---|---|
| `mutasi_barang` | Tidak ada `INSERT`/`UPDATE`/`SELECT` fungsional | `alat_isi_lokasi.php:165` (hitung baris), `reset_data.php:88` (kosongkan), komentar di `api/dashboard.php:632`, catatan di `alur.php:292`: *"tidak ditulisi maupun dibaca"* |
| `stock_opname` | Tidak ada `INSERT` di mana pun | `koreksi_angka.php:63,70` (hanya `JOIN` untuk membaca), `reset_data.php:77`, `alur.php:291` |
| `stock_opname_detail` | Tidak ada `INSERT` di mana pun | `koreksi_angka.php:59-70` (membaca `qty_sistem`/`qty_fisik`), `alat_isi_lokasi.php:163`, `reset_data.php:76` |

**Catatan penting tentang opname.** Halaman [opname.php](../../opname.php) dan [api/opname.php](../../api/opname.php) **tidak memakai** `stock_opname*`. Keduanya bekerja di atas `penyesuaian_stok`, `batch_barang`, `lokasi_rak`, `gudang`, `users`, dan `donatur`. Jadi tabel `stock_opname*` adalah sisa rancangan awal yang tidak diteruskan. Teks di `alur.php:291` ("halamannya belum ada") juga sudah usang karena halaman opname kini ada.

**Dampak bila dihapus:**

- `koreksi_angka.php` masih punya dua entri (`opname_sistem`, `opname_fisik`) yang menjalankan query ke `stock_opname*`. Entri itu harus dihapus **sebelum** tabelnya di-`DROP`, kalau tidak halamannya error.
- `reset_data.php` dan `alat_isi_lokasi.php` memiliki daftar tabel yang harus ikut dibersihkan.
- `alur.php` perlu diperbarui.

---

## 3. Tabel dipakai tetapi tipis (perlu verifikasi di produksi)

Tabel-tabel ini aktif ditulis, tetapi hanya oleh sedikit file. Kemungkinan besar kosong atau hampir kosong bila fiturnya belum dipakai.

| Tabel | Ditulis oleh | Catatan |
|---|---|---|
| `marketplace_akun` | modul marketplace | 7 file merujuk |
| `marketplace_sku` | `impor_pesanan.php`, `data_contoh.php` | Pemetaan SKU marketplace ke barang |
| `marketplace_sync_log` | `impor_pesanan.php:238`, `cron_marketplace.php:98` | Jejak sinkronisasi |
| `marketplace_tarikan` | `_shopee.php:165`, `_tokopedia.php:193` | Balasan mentah API; **berpotensi besar** bila cron aktif |
| `resi_scan_log` | `scan_resi.php:105`, `cek_surat.php:120` | Log audit pemindaian resi |
| `stok_harian` | `api/dashboard.php:678` | Snapshot harian, 1 baris per hari |
| `perpindahan_mutasi` | `_perpindahan_ubah.php:73` | Jejak untuk pembatalan/ubah dokumen perpindahan |
| `perpindahan_titik` | `_lacak.php` | Titik transit pelacakan |
| `mutasi_barang` | — | **Mati**, lihat bagian 2 |

Bila fitur marketplace belum dipakai di produksi, kelompok `marketplace_*` bisa dipertahankan (kodenya masih aktif), tetapi tidak perlu dioptimasi.

---

## 4. Inventaris lengkap tabel dipakai (52)

Kolom "Ref" = jumlah baris kode PHP yang merujuk (kasar, termasuk komentar); "File" = jumlah file PHP yang merujuk.

### 4.1 Inti stok dan master data
| Tabel | Ref | File |
|---|---:|---:|
| `barang` | 1124 | 94 |
| `gudang` | 1233 | 95 |
| `toko` | 838 | 76 |
| `supplier` | 445 | 69 |
| `stok` | 600 | 77 |
| `batch_barang` | 248 | 51 |
| `lokasi_rak` | 175 | 55 |
| `kategori` | 161 | 23 |
| `penerima` | 238 | 41 |
| `stok_supplier` | 69 | 16 |
| `stok_harian` | 8 | 4 |
| `penyesuaian_stok` | 38 | 14 |

### 4.2 Transaksi dan perpindahan
| Tabel | Ref | File |
|---|---:|---:|
| `perpindahan` | 302 | 49 |
| `perpindahan_detail` | 92 | 31 |
| `perpindahan_mutasi` | 7 | 1 |
| `perpindahan_titik` | 15 | 2 |
| `transaksi_masuk` | 71 | 20 |
| `transaksi_masuk_detail` | 57 | 18 |
| `transaksi_keluar` | 37 | 14 |
| `transaksi_keluar_detail` | 25 | 14 |
| `pengiriman_supplier` | 27 | 14 |
| `pengiriman_supplier_detail` | 9 | 6 |
| `penjualan` | 85 | 19 |
| `penjualan_detail` | 23 | 9 |
| `retur` | 31 | 15 |
| `pelepasan_stok` | 22 | 10 |
| `dokumen_counter` | 5 | 3 |

### 4.3 Donasi dan kardus
| Tabel | Ref | File |
|---|---:|---:|
| `donatur` | 283 | 23 |
| `donasi` | 214 | 18 |
| `donasi_detail` | 21 | 9 |
| `kardus` | 230 | 20 |
| `kardus_isi` | 6 | 4 |
| `kardus_riwayat` | 4 | 2 |

### 4.4 Pengguna, akses, keamanan
| Tabel | Ref | File |
|---|---:|---:|
| `users` | 165 | 56 |
| `roles` | 43 | 18 |
| `user_gudang` | 21 | 6 |
| `user_toko` | 25 | 11 |
| `izin_pengguna` | 6 | 3 |
| `sesi_perangkat` | 20 | 7 |
| `login_gagal` | 6 | 1 |
| `reset_sandi` | 5 | 2 |

### 4.5 Marketplace
| Tabel | Ref | File |
|---|---:|---:|
| `marketplace_akun` | 22 | 7 |
| `marketplace_sku` | 10 | 4 |
| `marketplace_sync_log` | 5 | 3 |
| `marketplace_tarikan` | 7 | 4 |

### 4.6 Sistem, log, dan konfigurasi
| Tabel | Ref | File |
|---|---:|---:|
| `pengaturan` | 116 | 25 |
| `activity_log` | 15 | 8 |
| `galat_sistem` | 7 | 3 |
| `notifikasi` | 19 | 8 |
| `cadangan_log` | 3 | 2 |
| `migrasi_terpasang` | 5 | 1 |
| `resi_scan_log` | 7 | 4 |

---

## 5. Temuan struktur skema

### 5.1 Skema tersebar di tiga tempat (temuan utama)

Hanya **40 dari 55** tabel ada di `db/instal_database.sql`. Instalasi baru yang hanya menjalankan file itu **tidak akan punya 15 tabel** berikut:

| Tabel | Dibuat oleh |
|---|---|
| `donatur`, `donasi`, `donasi_detail` | `db/migrasi_donatur.sql`, `db/migrasi_skema.sql` |
| `pelepasan_stok` | `db/migrasi_pelepasan_stok.sql`, `db/migrasi_skema.sql` |
| `user_gudang` | `db/migrasi_tingkat_pengguna.sql`, `db/migrasi_skema.sql` |
| `kardus`, `kardus_isi`, `kardus_riwayat` | `db/migrasi_tim_gudang_03_kardus.sql` |
| `galat_sistem` | `db/migrasi_galat_sistem.sql` **dan** `CREATE` di `_lib.php` |
| `login_gagal` | `db/migrasi_login_gagal.sql` **dan** `CREATE` di `_pembatas.php` |
| `reset_sandi` | `db/migrasi_reset_sandi.sql` **dan** `CREATE` di `pengguna.php` |
| `cadangan_log` | hanya `CREATE` di `_cadangan.php` |
| `stok_harian` | hanya `CREATE` di `api/dashboard.php` |
| `migrasi_terpasang` | hanya `CREATE` di `migrasi.php` |
| `izin_pengguna` | **tidak ada di `db/`**; hanya di `docs/produk/spesifikasi_migrasi/02_skema_db.sql`. Menurut `docs/riwayat/DAFTAR_UNGGAH.md` berkas `arsip_sql/migrasi_izin_pengguna.sql` yang membuatnya, dan berkas itu tidak ada di repo |

Catatan: `db/migrasi_skema.sql` kemungkinan menjadi jalur resmi untuk melengkapi skema. Untuk 15 tabel di atas, tidak satu pun ada di `instal_database.sql`.

**Risiko:**

- Instalasi baru dan database lama bisa berbeda skemanya.
- `izin_pengguna` dibaca di `_lib.php:2240` dan `api/_lingkup.php:73`. Tanpa tabelnya, pembacaan gagal dan akses bisa jatuh ke perilaku default. Perlu dipastikan penanganan error-nya aman (**fail-closed**).
- DDL yang dijalankan di dalam request web butuh hak `CREATE` pada user database aplikasi (melanggar prinsip hak minimum) dan menambah satu query pengecekan di jalur request.

### 5.2 Indeks

- `db/migrasi_indeks_2026_09.sql` menambah **13 indeks** (`deleted_at` pada 9 tabel master, 2 indeks `perpindahan`, 2 indeks `batch_barang`). Rancangannya masuk akal untuk pola query yang ada.
- Migrasi itu memakai `ALTER TABLE ... ADD INDEX` biasa. Bila diulang, MySQL membalas "Duplicate key name"; ini tidak berbahaya tetapi bukan idempoten.
- Belum diverifikasi apakah migrasi itu sudah terpasang di produksi (cek `migrasi_terpasang`).

### 5.3 Pertumbuhan log tanpa batas

| Tabel | Retensi otomatis |
|---|---|
| `galat_sistem` | **Ada**, hapus data > 30 hari (`galat.php:30`) |
| `login_gagal` | **Ada**, hapus data > 1 hari (`_pembatas.php:53`) |
| `reset_sandi` | Ada, hapus yang kedaluwarsa saat membuat token baru (`pengguna.php:269`) |
| `activity_log` | **Tidak ditemukan** |
| `resi_scan_log` | **Tidak ditemukan** |
| `marketplace_tarikan` | **Tidak ditemukan**; menyimpan balasan mentah API, paling berisiko membengkak |
| `marketplace_sync_log` | **Tidak ditemukan** |
| `cadangan_log` | **Tidak ditemukan** (kecil, 1 baris per backup) |
| `notifikasi` | Tidak diperiksa |

---

## 6. Perlukah optimasi database?

**Belum, dan jangan mulai dari jumlah tabel.** Urutan yang disarankan:

### Langkah 0 — Ukur dulu (wajib, 5 menit)
```sql
SELECT table_name,
       table_rows,
       ROUND(data_length  / 1024 / 1024, 2) AS data_mb,
       ROUND(index_length / 1024 / 1024, 2) AS indeks_mb
FROM information_schema.tables
WHERE table_schema = DATABASE()
ORDER BY (data_length + index_length) DESC;
```
`table_rows` untuk InnoDB hanya perkiraan. Cukup untuk menentukan skala. Bila tabel terbesar masih di bawah beberapa ratus MB, tidak ada optimasi performa yang mendesak.

### Prioritas rekomendasi

| # | Tindakan | Manfaat | Risiko | Usaha |
|---|---|---|---|---|
| 1 | Lengkapi `instal_database.sql` dengan 15 tabel yang hilang (atau jadikan satu berkas skema dasar bersih) | Instalasi baru konsisten | Rendah | Sedang |
| 2 | Pastikan `izin_pengguna` punya migrasi di `db/`, dan uji perilaku saat tabelnya tidak ada | Menutup celah akses | Rendah | Kecil |
| 3 | Tambah retensi untuk `activity_log`, `resi_scan_log`, `marketplace_tarikan`, `marketplace_sync_log` (misalnya hapus > 90 hari, selaraskan dengan kebutuhan audit yayasan) | Mencegah pembengkakan | Sedang: data audit hilang bila retensi terlalu pendek | Kecil |
| 4 | Hapus entri `opname_sistem`/`opname_fisik` di `koreksi_angka.php`, lalu `DROP` 3 tabel mati lewat migrasi bernomor, plus rollback | Skema bersih | Sedang: `DROP` tidak bisa dibatalkan tanpa backup | Kecil |
| 5 | Pindahkan DDL runtime (`CREATE TABLE IF NOT EXISTS` di `_lib.php`, `pengguna.php`, `_pembatas.php`, `_cadangan.php`, `api/dashboard.php`) ke migrasi, lalu cabut hak `CREATE` dari user aplikasi | Hak minimum, request lebih ringan | Sedang: harus pastikan semua tabel sudah ada sebelum kode lama dihapus | Sedang |
| 6 | Jalankan `EXPLAIN` pada query dasbor/laporan terberat setelah data nyata terukur | Optimasi tepat sasaran | Rendah | Sedang |

### Yang sebaiknya **tidak** dilakukan
- Menggabungkan tabel hanya demi mengurangi jumlah tabel.
- Menambah indeks tanpa bukti query lambat; setiap indeks memperlambat penulisan.
- Menjalankan `DROP TABLE` di produksi sebelum backup terverifikasi (lewat fitur Cadangan di aplikasi) dan sebelum kode yang merujuknya dibersihkan.

---

## 7. Daftar periksa sebelum menghapus tabel mati

- [ ] Backup penuh database, dan buktikan bisa dipulihkan.
- [ ] Jalankan `SELECT COUNT(*)` pada ketiga tabel; pastikan benar-benar kosong. Bila berisi data, **jangan hapus** sebelum ada keputusan lead operasional.
- [ ] Hapus rujukan di `koreksi_angka.php`, `reset_data.php`, `alat_isi_lokasi.php`, `alur.php`, komentar `api/dashboard.php`.
- [ ] Buat migrasi `db/migrasi_hapus_tabel_mati.sql` dan `db/rollback/rollback_hapus_tabel_mati.sql`.
- [ ] Jalankan `tests/` (khususnya `uji_opname.php`) setelah perubahan.
- [ ] Catat di `migrasi_terpasang`.

---

## 8. Catatan data sensitif

Tabel `donatur`, `donasi`, `donasi_detail`, `users`, `reset_sandi`, `sesi_perangkat`, `login_gagal`, dan `activity_log` memuat data pribadi atau kredensial turunan. Saat berbagi hasil query atau tangkapan layar dari tabel-tabel ini ke tim, **anonimkan** donatur dan jangan kirim ke grup terbuka. Backup SQL (`cadangan_berkas/`) juga berisi data ini dan harus diperlakukan sama.

---

## 9. Tambahan: View (`v_*`) dan beban server

Bagian ini menjawab dua pertanyaan: *kenapa ada objek berawalan `v_`*, dan *apakah makin banyak penulisan akan membebani hosting biasa*. Bagian 1–8 hanya menghitung `CREATE TABLE`, jadi 14 view di bawah **tidak termasuk** hitungan 55 tabel.

### 9.1 Apa itu view

View adalah `SELECT` tersimpan yang diberi nama. View **bukan tabel**: tidak menyimpan data, tidak punya baris sendiri, dan tidak menambah biaya saat `INSERT`/`UPDATE`. Setiap kali dipanggil, MySQL menjalankan ulang `SELECT`-nya terhadap tabel asli.

Tujuannya di repo ini: menulis query gabungan yang rumit sekali saja (misalnya gabungan stok batch dan stok supplier), lalu dipakai oleh banyak halaman.

### 9.2 Inventaris view (14)

| View | Referensi di PHP | File | Status |
|---|---:|---:|---|
| `v_stok_rantai` | 17 | 7 | Dipakai, **UNION ALL** |
| `v_expired_rantai` | 15 | 6 | Dipakai, **UNION ALL** |
| `v_status_stok` | 8 | 5 | Dipakai |
| `v_status_expired` | 8 | 3 | Dipakai (juga dasar `v_expired_rantai`) |
| `v_peringkat_donatur` | 5 | 2 | Dipakai |
| `v_pelepasan` | 4 | 2 | Dipakai |
| `v_perpindahan_ringkas` | 3 | 1 | Dipakai |
| `v_penyaluran_penerima` | 2 | 1 | Dipakai |
| `v_stok_toko` | 1 | 1 | Hanya disebut di komentar `_lib.php:3922`; fungsinya diganti `stok_di_toko()` |
| `v_penjualan_harian` | 0 | 0 | **Tidak dipakai** |
| `v_perangkat_aktif` | 0 | 0 | **Tidak dipakai** |
| `v_petugas_gudang` | 0 | 0 | **Tidak dipakai** |
| `v_stok_kondisi` | 0 | 0 | **Tidak dipakai** |
| `v_stok_per_gudang` | 0 | 0 | **Tidak dipakai** |

Ringkasan: **8 dipakai, 1 praktis tidak dipakai (`v_stok_toko`), 5 tidak dipakai sama sekali.** Total 6 view layak dihapus.

Catatan pengelolaan: definisi view diulang di banyak berkas (`instal_database.sql`, `migrasi_skema.sql`, `migrasi_rak_supplier.sql`, `naik_versi.sql`, dan lainnya), dengan sebagian memakai `CREATE VIEW` dan sebagian `CREATE OR REPLACE VIEW`. Versi di berkas lama bisa berbeda dari versi terbaru, jadi sumber kebenarannya perlu ditetapkan satu.

### 9.3 Beban dari view: ada di pembacaan, bukan penulisan

| Aspek | Penjelasan |
|---|---|
| Biaya tulis | **Nol**. View tidak menggandakan data. |
| Biaya baca | View dijalankan ulang di tiap pemanggilan. |
| `UNION ALL` | `v_stok_rantai` dan `v_expired_rantai` memakai `UNION ALL`. MySQL umumnya tidak bisa "meleburkan" view seperti itu ke query pemanggil, sehingga memprosesnya lewat tabel sementara (*temptable*) dan filter di luar view tidak memakai indeks tabel asal. |
| Dampak | Tidak terasa pada data kecil. Pada hosting bersama dengan puluhan ribu batch, halaman laporan dan monitoring bisa melambat dan menahan koneksi. |
| Bukti di kode | `_perpindahan_js.php:36` mencatat adanya "empat-JOIN atas v_stok_rantai" yang dijalankan berulang. Halaman pengguna utama: `laporan.php`, `monitoring.php`. |

Ini perlu dibuktikan dengan `EXPLAIN` pada data nyata sebelum disimpulkan sebagai masalah.

### 9.4 Apakah penulisan yang bertambah membebani hosting biasa?

Penulisan transaksi (stok masuk, keluar, perpindahan) dalam sistem gudang bervolume sedang umumnya **ringan** bagi hosting bersama. Yang lebih patut diwaspadai adalah penulisan atau pekerjaan yang berulang tanpa batas. Hasil pengecekan kode:

| Pola | Lokasi | Penjelasan | Tingkat perhatian |
|---|---|---|---|
| **Satu `SELECT` + satu `UPDATE` pada `sesi_perangkat` di setiap pemuatan halaman** | `_lib.php:1528-1553` (`segarkan_perangkat`, "dipanggil sekali per pemuatan halaman", dipanggil dari `_lib.php:5080`) | `UPDATE sesi_perangkat SET terakhir_at = NOW()` tidak dibatasi frekuensinya. Jika 20 petugas membuka halaman bergantian, itu puluhan penulisan kecil per menit. | **Sedang.** Mudah dikurangi dengan membatasi pembaruan, misalnya hanya bila `terakhir_at` lebih tua dari 60 detik. |
| `tingkat_saat_ini()` membaca tingkat pengguna dari database di tiap permintaan | komentar `_lib.php:1538-1540` | Satu query tambahan per request. Wajar demi keamanan (tingkat tidak basi). | Rendah |
| `INSERT INTO activity_log` untuk tiap aksi | `_lib.php:4919-4922` (`catat_aktivitas`) | Wajar untuk audit, tetapi **tanpa retensi** (bagian 5.3). Tabel terus tumbuh. | Rendah sekarang, naik seiring waktu |
| `CREATE TABLE IF NOT EXISTS` di dalam request | `_lib.php`, `pengguna.php`, `_pembatas.php`, `_cadangan.php`, `api/dashboard.php` | Satu pengecekan metadata tambahan di jalur request; juga menuntut hak `CREATE`. | Rendah untuk performa, **sedang untuk keamanan** |
| `login_gagal`: `INSERT` per percobaan, `DELETE` pembersihan | `_pembatas.php:53,103,114` | Sudah memiliki pembersihan harian. | Rendah |
| `marketplace_tarikan`: simpan balasan mentah API | `_shopee.php:165`, `_tokopedia.php:193` | Bila cron aktif dan balasan besar, tabel membengkak cepat. | **Sedang** bila marketplace dipakai |
| Penguncian baris (`SELECT ... FOR UPDATE`) | `_perpindahan_ubah.php:100`, dan jalur stok | Wajar untuk konsistensi stok. Menjadi masalah hanya bila banyak petugas mengubah batch yang sama bersamaan. | Rendah |

**Kesimpulan 9.4:** untuk hosting bersama, kekhawatiran yang masuk akal adalah (a) pembacaan lewat view `UNION ALL` pada data besar, dan (b) penulisan `sesi_perangkat` yang terjadi di setiap halaman. Penulisan transaksi gudang biasa bukan penyebab utama.

### 9.5 Rekomendasi tambahan (urut prioritas)

| # | Tindakan | Manfaat | Risiko | Usaha |
|---|---|---|---|---|
| 1 | Batasi `UPDATE sesi_perangkat SET terakhir_at` agar hanya berjalan bila nilai lama lebih dari ±60 detik | Mengurangi penulisan per halaman | Rendah: batas idle tetap akurat dalam hitungan menit; uji dulu dengan `idle_menit` terkecil | Kecil |
| 2 | Jalankan `EXPLAIN` pada query `v_stok_rantai`/`v_expired_rantai` di `laporan.php` dan `monitoring.php` dengan data produksi | Bukti sebelum bertindak | Tidak ada | Kecil |
| 3 | Bila terbukti lambat: ganti dengan `JOIN` langsung atau tabel ringkasan harian (pola `stok_harian`) | Pembacaan lebih cepat | Sedang: logika harus tetap sama dengan angka yang kini ditampilkan; cek silang dengan data lama | Sedang |
| 4 | Hapus 6 view yang tidak terpakai lewat migrasi bernomor beserta rollback-nya | Skema lebih bersih | Rendah, tetapi `_cadangan.php:114-119` mengekspor view dengan `SHOW CREATE VIEW` dan memperhatikan urutan ketergantungan; uji alur backup/impor setelahnya | Kecil |
| 5 | Tetapkan satu sumber definisi view di `db/` | Menghindari definisi bercabang | Rendah | Kecil |
| 6 | Aktifkan *slow query log* di hosting (bila tersedia) selama 1–2 minggu | Data nyata untuk keputusan optimasi | Tidak ada | Kecil |

### 9.6 Catatan batasan

- Semua temuan di bagian 9 berasal dari pembacaan kode. Belum ada pengukuran waktu query, jumlah baris, atau pemakaian CPU hosting.
- Pernyataan tentang algoritma view (*temptable*) adalah perilaku umum MySQL untuk view berisi `UNION`; versi MySQL/MariaDB yang dipakai hosting bisa berbeda dan perlu dikonfirmasi lewat `EXPLAIN`.
