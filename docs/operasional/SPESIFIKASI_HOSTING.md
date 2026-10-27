# Spesifikasi Hosting AMANAH — Web + Aplikasi Android

Disusun 2 September 2026, dari **pengukuran sistem ini**, bukan saran umum.
Setiap angka di bawah bisa dilacak ke asalnya; yang berupa perkiraan
disebut perkiraan.

---

## A. Yang WAJIB — tanpa ini ada fitur yang mati

Delapan hal di bawah bukan preferensi. Masing-masing sudah pernah
menyebabkan gejala nyata di proyek ini, atau akan menyebabkannya.

### A1. HTTPS dengan sertifikat sah — bukan opsional

**Scan barcode lewat kamera MATI tanpa HTTPS.** Peramban hanya mengizinkan
`getUserMedia` di *secure context*; di HTTP biasa tombol Scan tidak
melakukan apa pun. Ini menyentuh empat tempat: Barang Masuk gudang, Barang
Masuk toko, Scan Resi, dan Cek Surat Jalan.

Aplikasi Android juga mengirim **JWT** di header `Authorization`. Di HTTP,
token itu terbaca siapa pun di jaringan yang sama — dan token AMANAH
berumur panjang.

Let's Encrypt gratis dan tersedia di hampir semua cPanel. Pastikan juga
**redirect HTTP → HTTPS** aktif.

### A2. PHP 8.1 atau lebih baru

| Versi | Status |
|---|---|
| 8.1 – 8.3 | **dianjurkan** |
| 8.0 | jalan, tapi sudah habis masa dukungan keamanan |
| 7.4 | jalan — kodenya sengaja dijaga kompatibel — tetapi **tidak lagi menerima tambalan keamanan sejak November 2022** |
| ≤ 7.3 | **tidak jalan** — `fn()` gagal diurai |

Kode ini **tidak memakai satu pun fitur khusus PHP 8**: tidak ada `match()`,
`?->`, `readonly`, atau `enum`. `str_contains()` dan kerabatnya dipolyfill
di `config.php`. Jadi menaikkan versi aman, dan menurunkannya sampai 7.4
juga aman — yang tidak aman adalah **tetap di 7.4 selamanya**, karena
lubang keamanan PHP tidak lagi ditutup di sana.

### A3. `max_input_vars` minimal 3000 — inilah yang paling mudah terlewat

Keranjang Barang Masuk mengirim **7 isian per baris barang**
(`barang_id[]`, `qty[]`, `nomor_batch[]`, `tanggal_expired[]`,
`lokasi_rak_id[]`, `kondisi[]`, `harga[]`).

Bawaan PHP `max_input_vars = 1000`. Artinya:

    1000 ÷ 7 ≈ 142 baris  →  batas praktis sekitar 140 jenis barang

Lewat dari itu, PHP **membuang isian sisanya tanpa satu pun pesan galat**.
Yang terlihat: petugas memasukkan 200 jenis barang, menekan Simpan,
dokumennya tersimpan — hanya berisi 140. Tidak ada yang tahu, dan
selisihnya baru ketahuan saat stok opname.

Ini kegagalan yang paling berbahaya di seluruh daftar ini, karena ia
**berhasil dengan tenang**.

    max_input_vars = 3000     → sekitar 420 baris, cukup untuk kiriman besar

### A4. Modul Apache

Dibutuhkan `.htaccess` aplikasi dan `api/.htaccess`:

- `mod_rewrite` — routing API
- `mod_authz_core` **atau** `mod_access_compat` — memblokir berkas `_*.php`
- `mod_headers` — header keamanan
- `mod_setenvif`, `mod_expires` — cache aset

Kalau `AllowOverride` dimatikan hosting, seluruh `.htaccess` diabaikan dan
**berkas pembantu API bisa diminta langsung lewat URL**. Pastikan
`AllowOverride All` untuk folder aplikasi.

Uji cepat: buka `namadomain/Gudang/api/_jwt.php` di peramban. Harus **403**.
Kalau ia menampilkan kode PHP atau halaman kosong, `.htaccess` tidak aktif.

### A5. Folder sesi milik aplikasi sendiri

Folder `Gudang/sesi` harus bisa dibuat & ditulis (izin **0755**).

Alasannya bukan kerapian. Di hosting bersama, satu folder sesi dipakai
banyak situs, dan **pemungut sampah situs lain memindai folder yang sama
memakai ambangnya sendiri** — yang biasanya 24 menit. Berkas sesi AMANAH
ikut terhapus meski setelan kita benar, dan pemakainya keluar sendiri
tanpa sebab yang bisa dilihat.

Ini penyebab yang mustahil direproduksi di komputer sendiri.

Periksa lewat `cek_sistem.php?kunci=…` → baris **Folder sesi** harus
berbunyi *"folder sendiri siap"*. Kalau berbunyi *"akan jatuh ke folder
bersama"*, gejalanya akan kembali.

### A6. `session.gc_maxlifetime` minimal sepanjang ambang menganggur

Bawaan 1440 detik (24 menit). Batas menganggur di AMANAH bisa disetel
sampai 30 hari per akun — dan PHP membuang berkas sesinya jauh sebelum itu.
`auth.php` sudah menyetelnya sendiri lewat `ini_set()`, tetapi sebagian
hosting mengunci nilai ini. Kalau terkunci, mintakan:

    session.gc_maxlifetime = 2592000

### A7. Ekstensi PHP

`pdo_mysql`, `mbstring`, `json`. Ketiganya lazim aktif; `mbstring`
menentukan pemotongan teks Indonesia benar atau berantakan.

### A8. Backup harian otomatis, dengan pemulihan yang pernah diuji

Basis data ini memegang catatan amanah orang: siapa menitipkan apa, dan ke
siapa disalurkan. Kehilangannya bukan kerugian teknis.

Yang perlu: **backup harian basis data + folder `unggahan/`**, disimpan
**di luar server yang sama**, retensi minimal 30 hari.

Dan satu hal yang sering dilewatkan: **pulihkan satu kali ke basis data
percobaan** untuk memastikan backup-nya benar-benar bisa dipakai. Backup
yang belum pernah diuji bukan backup, hanya berkas.

---

## B. Ukuran yang tepat — dari pengukuran

### B1. Ruang disk

| Bagian | Terukur |
|---|---|
| Berkas aplikasi | **3,7 MB** (127 berkas) |
| Folder `unggahan/` sekarang | **6,7 MB** |
| Basis data kosong (45 tabel, 14 view) | **3,33 MB** |

**Pertumbuhan basis data — diukur, bukan ditaksir.** Satu dokumen barang
masuk berisi 3 jenis barang menghasilkan: 1 baris `transaksi_masuk`,
3 baris detail, 3 baris `batch_barang`, 1 baris `activity_log`.

Diuji dengan menulis 1.000 dokumen seperti itu ke basis data percobaan:

    2.912 KB untuk 1.000 dokumen  →  2,91 KB per dokumen

| Volume | Basis data per tahun |
|---|---|
| 500 dokumen/bulan | **17 MB** |
| 1.000 dokumen/bulan | **34 MB** |
| 5.000 dokumen/bulan | **171 MB** |
| 20.000 dokumen/bulan | **682 MB** |

Yang tumbuh jauh lebih cepat dari basis datanya adalah **foto produk dan
nota** di `unggahan/`. Satu foto ponsel 2 MB; seratus produk berfoto = 200
MB, dua puluh kali basis datanya. Rencanakan disk dari sisi gambar, bukan
dari sisi basis data.

**Anjuran: mulai dari 5 GB.** Itu memberi ruang bertahun-tahun untuk
lembaga sebesar ini, termasuk foto.

### B2. Basis data

- **MariaDB 10.4+** atau **MySQL 5.7+** (lokal: MariaDB 10.4)
- Charset **utf8mb4**, collation **utf8mb4_unicode_ci**
- Fitur yang dipakai: view (14), foreign key, `ON DUPLICATE KEY UPDATE`,
  transaksi InnoDB dengan `SELECT … FOR UPDATE`
- **Tidak** memakai window function, CTE, atau kolom JSON — jadi tidak ada
  syarat versi yang tinggi

Pastikan hosting mengizinkan **jumlah basis data ≥ 2** — satu produksi,
satu untuk uji pemulihan backup.

### B3. Memori & waktu proses

| Setelan | Nilai | Alasan |
|---|---|---|
| `memory_limit` | **256 MB** | laporan menyusun ribuan baris di memori |
| `max_execution_time` | **60 detik** | ekspor CSV & laporan kedaluwarsa |
| `post_max_size` | **32 MB** | keranjang besar + foto nota |
| `upload_max_filesize` | **8 MB** | aplikasi membatasi gambar 2 MB, tetapi batas PHP harus di atasnya |

`upload_max_filesize` **harus lebih kecil atau sama dengan**
`post_max_size` — kalau dibalik, unggahan gagal tanpa pesan yang berguna.

### B4. Jenis hosting

| Volume | Cukup dengan |
|---|---|
| 1 gudang, < 10 pengguna aktif | **shared hosting** yang layak, asal A1–A8 terpenuhi |
| 2–5 gudang + toko, 10–30 pengguna | **cloud/VPS kecil**: 2 vCPU, 2 GB RAM, 20 GB SSD |
| > 5 lokasi atau aplikasi dipakai seharian | **VPS**: 2–4 vCPU, 4 GB RAM, 40 GB SSD |

Yang menentukan bukan jumlah pengguna, melainkan **berapa banyak yang
memindai barcode bersamaan** — setiap tembakan adalah satu permintaan HTTP.

Kalau memilih shared hosting, yang paling penting bukan angka disk-nya,
melainkan apakah A3 (`max_input_vars`), A5 (folder sesi), dan A6
(`gc_maxlifetime`) bisa disetel. **Hosting murah yang mengunci ketiganya
akan membuat gejala keluar-sendiri dan keranjang terpotong kembali** —
dan keduanya sudah pernah terjadi di sistem ini.

---

## C. Untuk aplikasi Android

| Kebutuhan | Keterangan |
|---|---|
| **HTTPS wajib** | Android 9+ memblokir HTTP biasa (`cleartextTrafficPermitted=false` adalah bawaannya) |
| Sertifikat **rantai lengkap** | Android lebih ketat dari peramban desktop. Sertifikat yang kurang intermediate tetap tampil hijau di Chrome tetapi **gagal di Android** dengan pesan yang tidak menyebutkan sebabnya |
| Endpoint `.php` tidak boleh dialihkan | Server yang mengalihkan `api/login.php` → `api/login` membuat OkHttp mengubah POST jadi GET, dan **badan permintaannya hilang di jalan**. Inilah gunanya `api/.htaccess` |
| Jam server benar (NTP) | JWT punya `iat`/`exp`. Jam server yang meleset beberapa menit membuat token ditolak seketika sesudah diterbitkan |
| Kompresi gzip untuk JSON | Balasan laporan bisa ratusan KB; di jaringan gudang yang lemah, gzip memotongnya jauh |

---

## D. Yang sebaiknya dibereskan — bukan mendesak

### D1. Collation tidak seragam

6 dari 45 tabel memakai `utf8mb4_general_ci`, sisanya
`utf8mb4_unicode_ci`:

    donasi, donasi_detail, donatur, pelepasan_stok, stok_harian, user_gudang

Ini **sudah pernah menyebabkan galat nyata**: `Illegal mix of collations`
pada kueri UNION di dasbor, yang harus ditambal dengan `CAST(… COLLATE …)`
di setiap kolom teks. Tambalannya bekerja, tetapi setiap UNION baru yang
menyentuh tabel-tabel itu akan menabrak masalah yang sama.

Perbaikan sesungguhnya: satu `ALTER TABLE … CONVERT TO CHARACTER SET
utf8mb4 COLLATE utf8mb4_unicode_ci` untuk keenamnya, dikerjakan saat
jendela pemeliharaan — bukan di jam kerja, karena ia menulis ulang
tabelnya.

### D2. Cron untuk marketplace

`cron_marketplace.php` hanya jalan lewat CLI. Kalau fitur marketplace
dipakai, jadwalkan di cPanel → Cron Jobs:

    /usr/local/bin/php /home/AKUN/public_html/Gudang/cron_marketplace.php

Frekuensi 15 menit cukup.

### D3. Hapus berkas pemasangan setelah selesai

- `cek_sistem.php` — menyebut versi PHP, nama basis data, dan jalur berkas
- `instal_database.sql` — memuat hash password
- `reset_data.php` — jangan diunggah sama sekali

Ganti dulu `KUNCI_CEK` di `cek_sistem.php` sebelum diunggah, lalu hapus
berkasnya begitu aplikasi berjalan.

### D4. Batasi jumlah perangkat & ambang menganggur per akun

Sudah tersedia di **Kelola Pengguna**. Anjuran:

| Jenis akun | Perangkat | Menganggur |
|---|---|---|
| Petugas gudang (HP di tangan) | 2 | 8 jam – 1 hari |
| Akun bersama di konter toko | **1** | 30 menit – 2 jam |
| Super Admin | 3 – 5 | 8 jam |

Ambang menganggur yang panjang di komputer yang dipakai bergantian berarti
siapa pun yang duduk di sana masuk sebagai orang terakhir yang login.

---

## E. Ringkasan satu halaman untuk ditanyakan ke penyedia hosting

Salin bagian ini apa adanya saat bertanya:

1. HTTPS + Let's Encrypt, dengan redirect HTTP → HTTPS — **ada?**
2. PHP **8.1 atau lebih baru**, bisa dipilih sendiri — **ada?**
3. Bisa mengubah `php.ini` / `.user.ini` sendiri, khususnya
   **`max_input_vars`**, `memory_limit`, `session.gc_maxlifetime` — **bisa?**
4. `AllowOverride All` — `.htaccess` benar-benar aktif — **ya?**
5. Modul `mod_rewrite` + (`mod_authz_core` atau `mod_access_compat`) — **aktif?**
6. Ekstensi `pdo_mysql`, `mbstring`, `json` — **aktif?**
7. MariaDB 10.4+ / MySQL 5.7+, minimal **2 basis data** — **ada?**
8. Disk minimal **5 GB** — **ada?**
9. **Backup harian otomatis** dengan retensi ≥ 30 hari, disimpan di luar
   server — **ada?**
10. **Cron job** bisa dijadwalkan sendiri — **bisa?**

Kalau nomor **3** dijawab tidak, cari penyedia lain. Ketiga setelan itulah
yang menentukan sistem ini berperilaku benar atau menipu dengan tenang.

---

## F. Pilihan penyedia & biaya — diambil 2 September 2026

> **Harga hosting berubah terus.** Angka di bawah diambil langsung dari
> halaman resmi masing-masing pada tanggal di atas. Verifikasi lagi sebelum
> membeli, dan lihat kolom **perpanjangan** — bukan kolom promo.

### F0. Sebelum membeli: periksa hosting yang SUDAH ada

Sistem ini sekarang butuh **13,7 MB** (aplikasi + unggahan + basis data),
133 berkas, 6 akun aktif, 5 gudang, 9 toko. Itu jauh di bawah paket
termurah mana pun.

Jadi pertanyaannya bukan "paket berapa GB", melainkan **apakah hosting
sekarang memenuhi A1–A8**. Jalankan:

    namadomain/Gudang/cek_sistem.php?kunci=...

Kalau semua baris hijau dan `max_input_vars` bisa diubah, **tidak perlu
pindah** — pindah hosting berarti memindahkan basis data, berkas, dan
sertifikat, dengan risiko yang tidak sebanding.

### F1. Harga yang ditemukan

**Rumahweb** — shared hosting, cPanel, backup **mingguan**

| Paket | Disk | Promo tahun ke-1 | **Perpanjangan/tahun** |
|---|---|---|---|
| Entry | 1 GB | Rp 180.000 | **Rp 300.000** |
| Small | Unlimited* | Rp 214.800 | **Rp 600.000** |
| Medium | Unlimited* | Rp 358.800 | **Rp 1.080.000** |
| Large | Unlimited* | Rp 598.800 | **Rp 1.680.000** |

\* dibatasi **250.000 inode** (jumlah berkas, bukan ukuran). Sistem ini
133 berkas — tidak akan menyentuh batas itu.

**WarnaHost** — shared hosting NVMe, cPanel, LiteSpeed, backup **harian**

| Paket | Disk | CPU/RAM | DB | Harga (3 tahun, diskon 50%) | ≈ per tahun |
|---|---|---|---|---|---|
| Hemat | 2 GB | 1 core / 1 GB | 5 | Rp 594.000 | **Rp 198.000** |
| Starter | Unlimited | 1 core / 2 GB | ∞ | Rp 1.422.000 | **Rp 474.000** |
| Bisnis | Unlimited | 2 core / 4 GB | ∞ | Rp 1.980.000 | **Rp 660.000** |
| Enterprise | Unlimited | 3 core / 6 GB | ∞ | Rp 2.700.000 | **Rp 900.000** |

### F2. Perbandingan yang benar-benar menentukan

Harga bukan pembedanya — semuanya murah untuk sistem sebesar ini. Yang
membedakan hanya tiga hal:

| | Rumahweb | WarnaHost |
|---|---|---|
| Ubah `max_input_vars` (A3) | ya, cPanel | ya, cPanel |
| **Backup** | **mingguan** | **harian** |
| Disk paket termurah | 1 GB | 2 GB |
| CPU/RAM dipublikasikan | tidak | ya |

**Backup adalah pembeda yang sesungguhnya.** Basis data ini memegang
catatan titipan orang. Backup mingguan berarti kehilangan terburuknya
**tujuh hari pencatatan**; harian berarti satu hari. Untuk lembaga amanah,
selisih itu bukan soal teknis.

### F3. Anjuran

**Sekarang (1 lembaga, 5 gudang, 9 toko, 6 akun):**

> **WarnaHost Hemat** — 2 GB, backup harian, 5 basis data, ≈ **Rp 198.000
> /tahun** (komitmen 3 tahun).

Alasannya bukan harga, melainkan backup harian dan CPU/RAM yang disebutkan
terang-terangan. 2 GB memberi ruang bertahun-tahun: basis datanya 34 MB
per tahun pada 1.000 dokumen/bulan, dan sisanya untuk foto.

Kalau memilih **Rumahweb**, ambil **Entry** (Rp 300.000/tahun setelah
promo) dan **tambahkan backup sendiri** — jadwalkan cron dump basis data
ke Google Drive atau unduh mingguan secara manual. Jangan mengandalkan
backup mingguan penyedia sebagai satu-satunya salinan.

**Kalau kelak > 5 lokasi atau aplikasi dipakai seharian penuh:**
naik ke **WarnaHost Bisnis** (2 core / 4 GB, ≈ Rp 660.000/tahun) atau VPS
kecil. Yang menentukan bukan jumlah pengguna, melainkan berapa yang
memindai barcode bersamaan.

### F4. Jebakan harga yang harus dihindari

1. **Promo tahun pertama menyesatkan.** Rumahweb Small: Rp 214.800 tahun
   pertama, lalu **Rp 600.000** — hampir tiga kali. Bandingkan yang
   dibayar di tahun kedua, bukan yang pertama.
2. **"Unlimited" bukan tanpa batas.** Rumahweb membatasi 250.000 inode;
   penyedia lain membatasi CPU. Untuk sistem ini tidak masalah, tapi
   jangan membeli paket mahal karena kata "unlimited".
3. **Diskon 50% untuk 3 tahun berarti uang keluar di depan.** WarnaHost
   Hemat Rp 594.000 dibayar sekali untuk tiga tahun. Murah per tahun,
   tetapi terikat — pastikan A1–A8 terpenuhi **sebelum** berkomitmen tiga
   tahun.
4. **Jangan beli VPS kalau tidak ada yang mengelolanya.** VPS tanpa
   pengurus berarti PHP dan sistem operasinya tidak pernah ditambal, dan
   itu lebih berbahaya daripada shared hosting yang dirawat penyedianya.

### F5. Yang harus diuji di hari pertama, sebelum masa refund habis

1. Jalankan `cek_sistem.php?kunci=...` — semua baris harus hijau
2. Buka `Gudang/api/_jwt.php` di peramban — harus **403**
3. cPanel → MultiPHP INI Editor → setel `max_input_vars = 3000`, simpan,
   muat ulang halaman itu — nilainya harus **tetap 3000**
4. Buka Barang Masuk lewat HTTPS, tekan **Scan** — kamera harus terbuka
5. Masuk dari aplikasi Android — harus berhasil, bukan "tidak dapat
   terhubung"
6. Tinggalkan satu sesi menganggur **satu jam**, lalu muat ulang — harus
   **masih login**

Nomor 3 dan 6 adalah dua hal yang paling sering gagal, dan keduanya sudah
pernah terjadi di sistem ini.
