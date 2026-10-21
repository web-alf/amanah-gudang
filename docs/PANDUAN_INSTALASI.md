# AMANAH v3.0 — Panduan Instalasi & Penggunaan

> **v3 menambahkan simpul ketiga: TOKO.**
> Rantainya kini **Supplier → Gudang → Toko**, dengan tiga dasbor terpisah.
> Jalankan `migrasi_amanah_v2.sql` **lalu** `migrasi_amanah_v3.sql`.

---

## Data Contoh — Coba Dulu Sebelum Pakai Sungguhan

Jalankan **`data_contoh.sql`** setelah kedua migrasi. Isinya satu skenario utuh yang membuat
**setiap halaman ada isinya**: supplier mencatat barang masuk → mengirim ke gudang → gudang
menerima → mengirim ke toko → toko menjual.

Yang dimuat: 7 barang tambahan, 3 toko, 2 gudang, 8 rak, batch dengan **keempat status
kedaluwarsa** (aman, waspada, segera, sudah lewat), 8 dokumen perpindahan di **semua status**
(draft, menunggu persetujuan, dalam perjalanan, selesai, ditolak), 9 penjualan, pemetaan SKU,
jejak scan resi, notifikasi, dan riwayat aktivitas.

### Akun untuk mencoba

| Peran | Email | Password |
|---|---|---|
| Super Admin | `superadmin@contoh.com` | `Admin#2026` |
| Admin Gudang | `gudang@contoh.com` | `Gudang#2026` |
| Pimpinan | `pimpinan@contoh.com` | `Pimpinan#2026` |
| Supplier — PT Bayi Sehat | `ratna@bayisehat.demo` | `Supplier#2026` |
| Supplier — CV Buah Hati | `andi@buahhati.demo` | `Supplier#2026` |
| Toko — pegang 2 toko | `dewi@contoh.demo` | `Toko#2026` |
| Toko — gerai fisik | `agus@contoh.demo` | `Toko#2026` |

⚠️ **Ganti semua password ini sebelum dipakai sungguhan.** Akun `.demo` sebaiknya dinonaktifkan.

### Alur yang layak dicoba

1. Login sebagai **Ratna** → tab *Siap Kirim* → centang barang → terbitkan surat jalan.
2. Login sebagai **Admin Gudang** → *Scan Resi* → ketik `JNE0081234567` → *Proses jadi Penerimaan*,
   atau langsung ke menu *Penerimaan*. Coba ubah angka hitung fisik lebih kecil untuk melihat
   penanganan selisih.
3. Login sebagai **Pimpinan** → *Persetujuan* → setujui dokumen kirim ke toko. Perhatikan stok
   **belum** berubah, statusnya jadi *Dalam Perjalanan*.
4. Login sebagai **Dewi** → *Penerimaan* → terima. Barulah stok gudang turun dan stok toko naik.
5. Masih sebagai Dewi → *Impor Pesanan* → unggah **`contoh_pesanan_shopee.csv`** yang disertakan.
   Unggah dua kali untuk membuktikan pesanan ganda ditolak.

### Membersihkan kembali

Jalankan **`hapus_data_contoh.sql`**. Data contoh hilang, data Anda sendiri tetap utuh — sudah
diuji: database kembali persis ke kondisi sebelum data contoh dimuat.

---

## Input Gaya Scan — Di Mana Saja

Setiap formulir yang memasukkan barang punya kotak scan di atasnya. Tiga cara memasukkan kode,
semuanya berujung ke tempat yang sama:

- **Scanner barcode USB** — colok, klik kotaknya sekali, lalu tembak. Sistem mengenali scanner
  dari kecepatan ketiknya, jadi tidak perlu setelan apa pun.
- **Kamera HP / laptop** — tombol 📷 Kamera, memakai pembaca barcode bawaan browser. Tidak butuh
  internet. ⚠️ Hanya bisa lewat `localhost` atau HTTPS; kalau dibuka dari komputer lain memakai
  alamat IP, browser akan memblokir kameranya.
- **Ketik manual** — selalu tersedia.

Yang dicocokkan, berurutan dari paling pasti: **barcode** → **kode barang** → **nomor batch** →
**nama/merek sebagian**. Kalau hasilnya lebih dari satu, muncul daftar untuk dipilih.

| Halaman | Yang terjadi setelah scan |
|---|---|
| **Perpindahan Barang** | Barang jadi baris baru. Kalau sudah ada di daftar, jumlahnya ditambahkan — bukan baris kembar. Sisa stok di lokasi asal ikut ditampilkan. |
| **Barang Masuk Hari Ini** (supplier) | Baris baru + harga terisi otomatis dari master barang. |
| **Penjualan** (toko) | Baris baru + harga acuan terisi, dan sisa stok toko ditampilkan saat scan. |
| **Barang Masuk / Keluar cepat** | Formulir terisi, fokus langsung pindah ke kotak jumlah. |
| **Penerimaan** | Barisnya disorot dan kotak hitung fisik difokuskan — petugas tinggal mengetik angka saat membongkar. |

---

## Satu Tampilan untuk Semua Peran

Supplier, Gudang, Toko, Pimpinan, dan Super Admin kini memakai kerangka tampilan yang **sama
persis** — sidebar, topbar, kartu, dan tabel yang identik. Sebelumnya pihak eksternal memakai tata
letak tab tersendiri sehingga terasa seperti dua aplikasi berbeda.

Yang membedakan hanya **isi menunya** dan label di bawah nama aplikasi ("Dasbor Gudang",
"Dasbor Supplier", dan seterusnya), jadi selalu jelas sedang berada di dasbor mana.

---

## Siapa Melihat Apa

Setiap peran punya **dasbor dan menunya sendiri**. Begitu login, masing-masing langsung
mendarat di dasbornya — tidak perlu memilih.

| Peran | Mendarat di | Menunya |
|---|---|---|
| **Supplier** | Dasbor Supplier | Barang Masuk Hari Ini · Siap Kirim & Surat Jalan · Riwayat Pengiriman |
| **Admin Gudang** | Dasbor Gudang | Operasional gudang, transaksi cepat, master data, monitoring, laporan |
| **Admin Toko** | Dasbor Toko | Ringkasan · Penerimaan · Stok Toko · Penjualan · Impor Pesanan |
| **Pimpinan** | Dasbor Pimpinan | Persetujuan, Semua Dasbor, monitoring, laporan — tanpa menu input |
| **Super Admin** | Dasbor Gudang | Semuanya, plus **Semua Dasbor** dan pengelolaan akun |

Datanya tetap satu dan saling terhubung: surat jalan yang diterbitkan supplier langsung muncul
di menu Penerimaan gudang, dan kiriman gudang muncul di menu Penerimaan toko.

### Semua Dasbor (Super Admin & Pimpinan)

Menu **👁️ Semua Dasbor** memperlihatkan seluruh rantai dalam satu layar: stok tiap gudang, stok
dan omzet tiap toko, serta kolam siap-kirim tiap supplier. Dari sana ada tombol **Lihat Dasbor**
untuk membuka dasbor supplier atau toko mana pun — persis seperti yang mereka lihat sendiri.

Mode itu **hanya untuk melihat**. Tombol tindakannya sengaja disembunyikan, dan bila dipaksa lewat
POST tetap ditolak. Alasannya: kalau Super Admin bisa mencatat penjualan atas nama toko, laporan
akan menunjukkan toko yang menjual padahal bukan. Jejak audit harus menunjuk pelaku yang
sebenarnya. Kalau Anda ingin Super Admin juga bisa bertindak atas nama pihak lain, itu bisa
diubah — cukup beri tahu saya.

### Tombol Tambah

Setiap halaman master — Barang, Kategori, Gudang, Rak, Supplier, Toko — punya tombol
**＋ Tambah** di kanan atas. Menekannya menggulir ke formulir, menyorotnya sebentar, lalu
memfokuskan isian pertama. Ini terutama membantu di layar kecil, karena di sana formulirnya
berada di bawah tabel.

---

## Tiga Dasbor

### 1. Dasbor Supplier (`dasbor_supplier.php`)
- **Barang Masuk Hari Ini** — supplier mencatat berapa barang yang masuk ke tempatnya hari ini,
  lengkap dengan nomor batch dan tanggal kedaluwarsa. Ini menjadi kolam **Siap Kirim**.
- **Siap Kirim & Surat Jalan** — centang barang, isi jumlah kirim, pilih gudang tujuan, lalu
  **surat jalan langsung terbit** tanpa menunggu persetujuan siapa pun. Kode resinya inilah yang
  dipindai gudang saat barang tiba. Bila supplier tidak memakai ekspedisi, nomor surat jalan
  otomatis menjadi kode resinya.
- **Riwayat Pengiriman** — termasuk kolom selisih bila gudang menerima jumlah berbeda.

Stok gudang **tidak** bertambah saat surat jalan terbit — hanya saat gudang memverifikasi fisiknya.

### 2. Dasbor Gudang (`dashboard.php` + `penerimaan.php`)
- **Penerimaan Barang** — daftar kiriman yang menunggu, lengkap dengan daftar barangnya.
  Petugas menghitung fisik; **angka hasil hitung itulah yang masuk ke stok**, bukan angka di surat
  jalan. Selisihnya wajib diberi catatan.
- **Scan Resi** — scanner USB, kamera, atau ketik manual.
- Sisanya seperti v2: perpindahan, persetujuan, monitoring, laporan.

### 3. Dasbor Toko (`dasbor_toko.php`)
- **Pemilih toko** di kanan atas — satu akun bisa memegang beberapa toko (Shopee, Tokopedia, dst.)
  dan berpindah tanpa login ulang.
- **Penerimaan** — menerima surat jalan dari gudang, dengan verifikasi fisik yang sama.
- **Stok Toko** — dilacak sampai tingkat batch & kedaluwarsa, plus penyesuaian rusak/hilang.
- **Penjualan** — harga jual, nomor pesanan, nama pembeli; stok terpotong FEFO.
- **Impor Pesanan** — unggah CSV dari Shopee/Tokopedia, stok terpotong otomatis.

---

## Soal Integrasi Marketplace — Baca Ini

Anda memilih **integrasi API penuh**. Yang terpasang sekarang adalah **fondasinya**, bukan
sambungan API-nya. Alasannya konkret, bukan keengganan:

- **Shopee** mensyaratkan pendaftaran di Shopee Open Platform untuk memperoleh `partner_id`
  dan `partner_key`, lalu tiap toko memberi izin lewat OAuth.
- **Tokopedia** sejak diakuisisi TikTok tidak lagi punya API sendiri — integrasinya lewat
  **TikTok Shop Partner Center**, dengan pendaftaran partner dan persetujuan aplikasi.
- Keduanya butuh **URL callback HTTPS yang terjangkau dari internet**. XAMPP di `localhost`
  tidak bisa dipanggil balik oleh server Shopee/TikTok, jadi alur OAuth-nya tidak akan pernah
  selesai.
- Kredensialnya hanya bisa didaftarkan oleh pemilik usaha. Tanpa itu, kode integrasi apa pun
  tidak bisa diuji — dan menyerahkan kode yang belum pernah jalan bukan hal yang pantas.

**Yang berfungsi hari ini:** impor CSV. Unduh laporan pesanan dari Seller Center, unggah di menu
Impor Pesanan, stok toko terpotong otomatis. Pesanan yang sudah pernah diimpor dilewati, jadi
aman bila berkas yang sama diunggah dua kali.

**Yang sudah disiapkan untuk nanti:** tabel `marketplace_akun` (kredensial & token per toko),
`marketplace_sku` (pemetaan SKU marketplace ke kode barang Anda), dan `marketplace_sync_log`.
Saat kredensial dan hosting siap, penyambungan tinggal mengisi lapisan konektor.

---

## Aturan Penting yang Berubah di v3

**Stok hanya berpindah di SATU titik.** Untuk perpindahan yang punya pihak penerima
(supplier→gudang, gudang→toko, toko→gudang, antar toko, antar gudang), persetujuan hanya
menerbitkan surat jalan dan menandai barang **dalam perjalanan**. Stok baru berpindah saat pihak
tujuan memverifikasi fisiknya. Untuk yang tidak punya penerima (penyaluran ke penerima manfaat,
retur ke supplier, geser rak), stok diterapkan langsung saat disetujui.

**Angka `stok` sekarang berarti stok GUDANG saja.** Barang yang sudah di toko dihitung terpisah.
Tanpa pemisahan ini, peringatan "stok habis" di gudang tidak akan pernah berbunyi selama
barangnya menumpuk di toko.

**Jumlah terima dibatasi maksimum jumlah di surat jalan.** Bila barang datang lebih banyak,
terima sesuai surat jalan lalu catat kelebihannya sebagai penyesuaian stok — supaya tidak ada
stok yang bisa diciptakan tanpa jejak.

---

# Referensi v2 (masih berlaku)

**Aplikasi Manajemen Amanah & Hibah** · Sistem Gudang & Rantai Pasok
*"Setiap titipan tercatat, setiap penyaluran terlacak"*

---

## ⚠️ LANGKAH PERTAMA — Jalankan Migrasi Database

Aplikasi **tidak akan berfungsi** sebelum langkah ini dijalankan.

1. Nyalakan **Apache** dan **MySQL** di XAMPP Control Panel
2. Buka `http://localhost/phpmyadmin`
3. **Backup dulu**: pilih database `gudang_bayi` → tab **Export** → **Go** → simpan file hasilnya
4. Masih di database `gudang_bayi` → tab **Import** → pilih file `migrasi_amanah_v2.sql` → **Go**
5. Pastikan muncul pesan sukses tanpa error merah

Migrasi ini bersifat **penambahan saja** — tidak ada tabel atau data lama yang dihapus.

Setelah itu buka `http://localhost/Gudang/` dan login seperti biasa.

---

## Yang Berubah dari Versi Sebelumnya

### Nama & Identitas
Sistem sekarang bernama **AMANAH** — *Aplikasi Manajemen Amanah & Hibah*. Nama ini dipilih karena
mencerminkan inti pekerjaan lembaga: barang donasi adalah titipan yang harus dijaga dan
dipertanggungjawabkan. Warna sistem berubah dari biru ke hijau-tosca agar berbeda dari tampilan lama
dan terasa lebih tenang.

Ingin mengganti nama, alamat, atau nomor telepon yang tercetak di surat jalan?
Edit bagian atas file **`_lib.php`** (baris `const APP_NAMA`, `SURAT_ALAMAT`, `SURAT_TELEPON`, dst).

### Fitur Baru

| Menu | Fungsi |
|---|---|
| **🏭 Gudang** | Master gudang fisik. Sebelumnya gudang hanya kolom teks bebas di data rak — sekarang jadi data tersendiri dengan penanggung jawab, alamat, dan perhitungan isi. |
| **🔄 Perpindahan Barang** | Satu modul untuk **semua** pergerakan barang: penerimaan, transfer antar gudang, mutasi rak, penyaluran, dan retur. Bisa banyak barang dalam satu dokumen. |
| **✅ Persetujuan** | Antrian dokumen yang menunggu disetujui. **Stok tidak berubah sebelum dokumen disetujui.** |
| **🖨️ Surat Jalan** | Terbit otomatis saat dokumen disetujui. Format A4 dengan kop lembaga, kolom tanda tangan tiga pihak, dan kode verifikasi. Bisa dicetak rangkap (penerima + arsip). |
| **📷 Scan Resi** | Menerima nomor resi dari scanner USB, kamera, atau ketik manual. Otomatis mencocokkan ke kiriman supplier. |
| **🚛 Kiriman Supplier** | Daftar rencana kiriman yang diinput sendiri oleh supplier lewat portal mereka. |
| **Portal Supplier** | Halaman terpisah untuk mitra pemasok — mereka login sendiri dan menginput rencana kiriman beserta nomor resi. |

---

## Alur Kerja Sehari-hari

### A. Barang datang dari supplier

```
Supplier input di portal (resi + daftar barang)
        ↓
Barang tiba → petugas buka menu Scan Resi → tembak barcode resi
        ↓
Sistem menampilkan detail kiriman → klik "Proses jadi Penerimaan"
        ↓
Form terisi otomatis → petugas cek fisik & perbaiki jumlah bila beda
        ↓
Kirim untuk Persetujuan
        ↓
Pimpinan / Super Admin menyetujui  →  STOK BERTAMBAH + Surat Jalan terbit
        ↓
Cetak surat jalan
```

### B. Barang pindah antar gudang

```
Perpindahan Barang → Buat Perpindahan → jenis "Transfer Gudang"
Dari: Gudang A     Ke: Rak di Gudang B
Isi daftar barang → Kirim untuk Persetujuan
        ↓
Disetujui → stok pindah (FEFO: kedaluwarsa terdekat diambil lebih dulu)
        ↓
Cetak surat jalan → dibawa pengemudi → ditandatangani penerima
```

### C. Penyaluran ke penerima manfaat

Sama seperti di atas, pilih jenis **Penyaluran**, tujuan **Penerima manfaat**,
lalu ketik nama penerima / posyandu / cabang.

> **Catatan:** menu **Barang Masuk** dan **Barang Keluar** yang lama tetap ada untuk transaksi
> cepat satu barang tanpa surat jalan. Gunakan **Perpindahan Barang** bila butuh dokumen resmi.

---

## Membuat Akun Login untuk Supplier

Hanya **Super Admin** yang bisa melakukannya.

1. Buka menu **Supplier**
2. Klik tombol **🔑 Akun** pada baris supplier yang dituju
3. Isi nama petugas, email login, dan password awal (minimal 8 karakter)
4. Klik **Buat Akun**

Supplier lalu login di halaman login yang sama (`http://<alamat-server>/Gudang/`) dan otomatis
diarahkan ke Portal Supplier.

**Penting soal keamanan:** akun supplier dikunci ke `supplier_id` masing-masing di sisi server,
jadi satu pemasok tidak bisa melihat kiriman, harga, atau data pemasok lain. Mereka juga tidak
bisa membuka halaman internal mana pun — semua halaman gudang menolak role supplier.

🔒 Sampaikan password lewat kanal pribadi (telepon atau chat langsung ke PIC).
**Jangan kirim lewat grup WhatsApp terbuka.** Minta supplier menggantinya setelah login pertama.

---

## Scan Resi — Cara Pakai

### Scanner barcode USB (paling cepat)
Colok scanner ke komputer gudang. Buka **Scan Resi**, klik kotak isian sekali, lalu tembak
barcode. Form terkirim sendiri. Sistem mengenali scanner dari kecepatan ketiknya, jadi tidak
perlu setting apa pun.

### Kamera HP / laptop
Klik **📷 Pakai Kamera**, arahkan ke barcode resi.

⚠️ **Batasan penting:** browser hanya mengizinkan akses kamera lewat `localhost` atau HTTPS.
Kalau halaman dibuka dari komputer lain memakai alamat IP (`http://192.168.1.5/Gudang/`),
kamera **akan diblokir browser** — ini aturan browser, bukan kesalahan aplikasi.
Untuk komputer lain di jaringan, gunakan scanner USB atau ketik manual.

Fitur kamera memakai `BarcodeDetector` bawaan browser (Chrome/Edge terbaru), jadi tidak perlu
koneksi internet dan tidak ada pustaka luar yang diunduh.

---

## Perbaikan Bug dari Versi Lama

| Masalah lama | Perbaikan |
|---|---|
| `setup_password.php` bisa dibuka siapa saja tanpa login dan mereset password Super Admin | File dipindah ke folder `_arsip` |
| `database_gudang_bayi.sql` bisa diunduh lewat browser | Dipindah ke `_arsip` + diblokir `.htaccess` |
| Hint password contoh tercetak di halaman login | Dihapus |
| Pesan error database mentah tampil ke pengguna (bocor nama tabel/kolom) | Diganti pesan aman + kode rujukan; detail masuk ke error log server |
| `kategori.php` error "Undefined array key keterangan" setiap validasi gagal | Diperbaiki (dulu `compact()` memakai nama variabel yang salah) |
| Nomor transaksi dibuat dari `COUNT(*)` → bentrok bila ada transaksi dihapus atau dua admin menyimpan bersamaan | Diganti `MAX()` + pengulangan otomatis saat bentrok |
| `keluar.php` diam-diam menyimpan jumlah lebih sedikit dari yang diminta, tapi tetap bilang sukses | Sekarang dikunci `FOR UPDATE` di dalam transaksi dan **gagal dengan pesan jelas** bila stok kurang |
| Trigger MySQL & PHP sama-sama menghitung stok (rawan melenceng) | Trigger dihapus; stok dihitung satu pintu lewat `recalc_stok()` |
| Karakter `%` dan `_` di kotak cari jadi wildcard | Di-escape |
| `dashboard.php` menyalin seluruh layout (menu harus diedit 2×) | Sekarang memakai `_header.php` / `_footer.php` yang sama |
| Chart.js diambil dari internet → grafik blank saat offline | File JS disimpan lokal di folder `assets/` |
| Tanggal campur Inggris ("August 2026") | Semua tanggal Bahasa Indonesia |
| Kotak "Cari" di header hanya hiasan | Sekarang berfungsi (mencari ke Data Barang) |
| Mode gelap reset tiap pindah halaman | Disimpan di cookie |
| Tidak ada pagination | Data Barang & Perpindahan sudah berhalaman |
| Sesi tanpa HttpOnly/SameSite, tanpa batas waktu | Cookie diperketat + auto-logout 30 menit + pembatasan 5× salah password |

---

## Struktur File

```
Gudang/
├── .htaccess                  ← WAJIB ada. Memblokir unduhan file sensitif.
├── index.php                  ← Pengarah otomatis sesuai peran
├── config.php                 ← Kredensial database
├── auth.php                   ← Sesi, login, CSRF
├── _lib.php                   ← Fungsi bersama + identitas aplikasi (edit di sini)
├── _header.php / _footer.php  ← Tampilan bersama
│
├── login.php  logout.php  dashboard.php
│
├── perpindahan.php            ← Daftar perpindahan
├── perpindahan_form.php       ← Buat perpindahan
├── persetujuan.php            ← Antrian & aksi setujui/tolak
├── surat_jalan.php            ← Cetak A4
├── scan_resi.php              ← Scan resi
├── kiriman_supplier.php       ← Kiriman dari supplier (internal)
├── portal_supplier.php        ← Portal mitra pemasok
│
├── barang.php  barang_form.php  barang_hapus.php
├── kategori.php  gudang.php  rak.php  supplier.php
├── masuk.php  keluar.php
├── monitoring.php  laporan.php  laporan_export.php
│
├── assets/chart.umd.js        ← Chart.js lokal (offline)
├── migrasi_amanah_v2.sql      ← Migrasi database
│
├── _arsip/                    ← File lama berisiko (aman dihapus setelah dicek)
└── _backup_v1_20260810/       ← Cadangan seluruh file versi lama
```

---

## Bila Ada Masalah

**Halaman putih atau error 500**
Buka `C:\xampp\apache\logs\error.log`, cari baris berawalan `[AMANAH]`.

**"Tabel perpindahan belum ada"**
Migrasi belum dijalankan. Ulangi langkah di bagian paling atas panduan ini.

**Pengguna melaporkan kode error seperti `A3F91B2C`**
Cari kode itu di `error.log` — di sana ada pesan aslinya. Kode ini sengaja dipakai agar detail
teknis tidak tampil ke pengguna tapi tetap bisa dilacak.

**Gudang tujuan belum punya rak**
Perpindahan butuh rak konkret untuk menaruh barang. Tambahkan minimal satu rak di gudang
tersebut lewat menu **Rak / Lokasi**.

---

## Yang Belum Dikerjakan

Jujur soal cakupan — hal-hal berikut **belum** ada dan perlu tahap berikutnya:

- **Manajemen user internal** — menambah/menonaktifkan staf masih lewat phpMyAdmin
  (akun *supplier* sudah bisa lewat menu Supplier)
- **Stock opname** — tabelnya sudah ada di database, halamannya belum
- **Retur & mutasi lama** — tabel `retur` dan `mutasi_barang` versi 1 tidak dipakai lagi;
  fungsinya sudah tercakup di modul Perpindahan
- **Notifikasi WhatsApp** — kolom `kanal` di tabel notifikasi sudah menyiapkan tempatnya,
  integrasi ke CekatAI belum dibuat
- **Halaman baca activity log** — tercatat otomatis dan tampil ringkas di Dashboard,
  tapi belum ada halaman penelusuran lengkap
- **QR code pada surat jalan** — saat ini memakai kode verifikasi teks agar tetap bisa
  dicetak tanpa internet

---

## Catatan Pengujian

Seluruh file lolos pemeriksaan sintaks PHP 8.4 (`php -l`). Fungsi pembantu di `_lib.php`
(format tanggal Indonesia, rupiah, escape pencarian, label status) diuji otomatis dan lulus.

Mesin perpindahan stok melewati **empat putaran review adversarial** yang dijalankan pada
**MySQL 8.0.46 dan MariaDB 10.11** sungguhan, bukan sekadar pembacaan kode. Setiap temuan
dibuktikan dengan mereproduksi kegagalannya lebih dulu, lalu diverifikasi ulang setelah
diperbaiki. Yang ditemukan dan sudah ditutup:

| Putaran | Temuan | Status |
|---|---|---|
| 1 | Perpindahan antar rak **menimpa tanggal kedaluwarsa** barang (batch 100 unit exp Sep-2026 berubah jadi Jan-2030) | ✅ ditutup — tanggal kedaluwarsa kini bagian dari kunci batch |
| 1 | Stok bisa mendarat di batch **tanpa lokasi rak** lalu lenyap dari semua laporan gudang | ✅ ditutup |
| 1 | Rute tidak divalidasi di server — POST manipulasi bisa **melipatgandakan stok** atau menguras semua gudang | ✅ ditutup lewat `periksa_rute()` |
| 1 | "Perpindahan hantu": rak tujuan bisa terpilih sama dengan rak asal, `qty_awal` rusak, surat jalan tetap terbit | ✅ ditutup |
| 1 | Validasi stok per baris, bukan per barang → dokumen zombi yang tak pernah bisa disetujui | ✅ ditutup |
| 1 | Harga beli & supplier hilang setiap kali barang dipindah | ✅ ditutup |
| 2 | Migrasi **berhenti dengan ERROR 1553** dan meninggalkan trigger stok v1 tetap aktif | ✅ ditutup — `DROP`+`ADD` disatukan dalam satu `ALTER` |
| 2 | Konsolidasi stok ke satu rak ditolak keras | ✅ ditutup — batch yang sudah di tujuan kini dilewati |
| 2 | Kolom `keterangan` meluap 255 karakter → persetujuan gagal | ✅ ditutup |
| 3 | Nomor surat jalan **tidak pernah berubah** saat diulang (snapshot MVCC) → persetujuan bersamaan selalu gagal | ✅ ditutup lewat tabel `dokumen_counter` |
| 3 | Deadlock `recalc_stok` (20–39% kegagalan pada beban campur) | ✅ ditutup lewat `kunci_stok()` — terukur **0 dari 100** |
| 4 | `INSERT IGNORE` di jalur panas memicu deadlock naik-kunci pada konkurensi tinggi | ✅ diperbaiki — baris `stok` disemai di migrasi, jalur panas hanya `FOR UPDATE` |

Hasil terukur setelah perbaikan putaran 3, di kedua engine: stres 4 proses × 25 dokumen
(masuk & keluar bercampur) → **100/100 sukses, 0 deadlock, stok konsisten sempurna**;
6 dokumen multi-barang dengan urutan penguncian sengaja dibalik → **6/6 sukses, 0 deadlock**.

**Perbaikan putaran 4 belum sempat diverifikasi ulang di database sungguhan** (kuota sesi
agen penguji habis). Perubahannya kecil dan terisolasi — pembalikan urutan dua pernyataan
di `kunci_stok()` plus dua baris seed di migrasi — dan varian tanpa `INSERT IGNORE` sudah
terbukti menghasilkan 0 deadlock dari 24 percobaan di putaran sebelumnya. Tetap perlu
dicoba sendiri.

**Yang tetap harus Anda uji langsung sebelum dipakai transaksi sungguhan:** alur end-to-end
di komputer Anda — buat satu perpindahan, setujui, periksa apakah angka stok berubah sesuai
harapan, dan surat jalannya tercetak rapi di printer Anda. Lakukan di data uji dulu.

### Catatan teknis yang perlu diketahui

- **Nomor dokumen bisa berlubang.** Nomor yang terlanjur diambil lalu dibatalkan tidak
  dikembalikan (mis. PP-0001, lalu PP-0003). Ini disengaja — jauh lebih aman daripada
  memakai ulang nomor. Pastikan auditor lembaga tidak mengharapkan penomoran rapat.
- **Persetujuan atas barang yang sama kini mengantre.** Bila dua orang menyetujui dokumen
  yang menyentuh barang sama, yang kedua menunggu sampai yang pertama selesai. Ini yang
  membuat angka stok selalu benar.
- **Surat jalan tetap terbit walau tidak ada barang yang bergerak**, misalnya bila seluruh
  batch ternyata sudah berada di rak tujuan. Kolom keterangan mencatatnya apa adanya
  ("sudah di rak tujuan").

---

*AMANAH v2.0 · Sistem Gudang & Rantai Pasok · Barakallahu fiikum*
