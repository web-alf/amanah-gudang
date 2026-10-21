# Unggah Lanjutan — hanya yang berubah sesudah unggahan Anda

> **PEMBARUAN 13 September 2026.** `api/cek.php`, `versi.php`, dan `cek_toko.php`
> sudah DIHAPUS dari kode (membocorkan error log & versi server tanpa login);
> jangan diunggah lagi, dan hapus dari hosting bila masih ada. Seluruh berkas
> `.sql` kini berada di folder `db/` (tertutup dari peramban). Migrasi dijalankan
> dari menu **Pengaturan › Migrasi Basis Data**, bukan lagi tangan lewat phpMyAdmin.
> Daftar unggah terbaru ada di `CATATAN_PERUBAHAN_2026-09-13.md`.


Anda sudah mengunggah satu putaran. Berkas di bawah adalah yang berubah
**sesudah itu** — tidak perlu mengulang 48 berkas.

---

## Dulu, pastikan versi mana yang sudah ada di hosting

Buka **Kelola Pengguna → ubah salah satu akun**, lalu hitung baris pada
panel *Izin Khusus Akun Ini*:

| Yang Anda lihat | Artinya | Yang harus diunggah |
|---|---|---|
| Panel izin **tidak ada sama sekali** | Unggahan belum memuat fitur izin | Seluruh daftar di `DAFTAR_UNGGAH.md` (48 berkas) |
| **6 baris**, sebagian "Izinkan" berwarna pudar/mati | Versi berpagar peran | 48 berkas — versi ini sudah usang |
| **7 baris** (ada *Setujui Dokumen*), tidak ada yang mati | Versi terbaru sebelum perbaikan menu | **Cukup 31 berkas di bawah** |

Kalau ragu, mengunggah 48 berkas tidak merusak apa pun — hanya lebih lama.

---

## 31 berkas (±640 KB) + satu migrasi tambahan

### Tahap 1 — pembantu dulu

`_lib.php` memuat mesin menu & penjaga halaman yang baru. Halaman di
Tahap 2 memanggilnya dengan argumen yang **belum dikenal versi lama**,
jadi kalau urutannya dibalik, halaman-halaman itu mati dengan
*fatal error* sampai `_lib.php` menyusul.

- [ ] `_lib.php`
- [ ] `_header.php`

### Tahap 2 — halaman

- [ ] `barang.php`
- [ ] `barang_stok.php`
- [ ] `kategori.php`
- [ ] `gudang.php`
- [ ] `rak.php`
- [ ] `supplier.php`
- [ ] `toko.php`
- [ ] `penerima.php`
- [ ] `pelepasan.php`
- [ ] `impor_pesanan.php`
- [ ] `dasbor_toko.php`
- [ ] `dasbor_supplier.php`
- [ ] `penerimaan.php`

### Tahap 3 — keranjang barang masuk (baru)

`assets/keranjang-masuk.js` adalah mesin keranjang yang kini dipakai
BERSAMA oleh Barang Masuk gudang dan Barang Masuk toko. Unggah berkas JS
lebih dulu: kedua halaman di bawah memanggilnya, dan tanpa berkas itu
keranjangnya tidak terbentuk sama sekali.

- [ ] `assets/keranjang-masuk.js`  ← **baru**
- [ ] `masuk.php`  ← blok keranjangnya dipindah ke berkas bersama
- [ ] `_masuk_gudang_toko.php`  ← keranjang + scan resi

### Tahap 4 — batas perangkat per akun

Jalankan migrasinya **lebih dulu**, lalu berkasnya:

    arsip_sql/migrasi_maks_perangkat.sql

- [ ] `login.php`  ← pesan penolakan menyebut batas akun itu
- [ ] `perangkat.php`  ← lencana & hitungan penuh per akun

### Tahap 5 — sesi tidak lagi keluar sendiri

- [ ] `auth.php`  ← umur sesi, folder sesi sendiri, cookie berumur
- [ ] `login.php`  ← ceklis "Ingat saya" (sudah ada di Tahap 4)
- [ ] `logout.php`  ← hapus cookie berumur dengan atribut yang sama
- [ ] `cek_sistem.php`  ← pemeriksa folder sesi & batas PHP 7.4

### Tahap 6 — Perpindahan: tanggal & cara menambah barang

Tidak ada migrasi. `_lib.php` memuat `tanggal_dokumen_sah()` yang dipakai
ketiga jalur di bawah — unggah `_lib.php` LEBIH DULU, kalau tidak ketiganya
mati dengan *fatal error* fungsi tak dikenal.

- [ ] `_lib.php`  ← `tanggal_dokumen_sah()` (sudah di Tahap 1, pastikan TERBARU)
- [ ] `_footer.php`  ← penangan tombol Hari ini / Kemarin
- [ ] `assets/amanah.css`  ← gaya panel & pilihan tanggal (sudah di Tahap 2)
- [ ] `cari_stok.php`  ← **BERKAS BARU**, sumber data panel Pilih Banyak Barang
- [ ] `_perpindahan_form.php`
- [ ] `_perpindahan_isian.php`
- [ ] `_perpindahan_js.php`
- [ ] `dasbor_toko.php`  ← tanggal pada distribusi (sudah di Tahap 2)
- [ ] `dasbor_supplier.php`  ← tanggal pada surat jalan mitra (sudah di Tahap 2)

Isi Tahap 6, supaya jelas apa yang harus diuji sesudah unggah:

| Yang berubah | Akibatnya |
|---|---|
| Tanggal surat jalan **tanpa batas** | Boleh tanggal lampau maupun mendatang. Yang bukan hari ini tercatat di riwayat beserta tanggal pembuatannya. |
| Panel **Pilih Banyak Barang** disaring | Bisa dipilih dari gudang / toko / mitra mana, dan barang yang **masuk** hari ini, kemarin, 7 hari, atau rentang tanggal bebas. Stok dan lokasinya ikut tampil. |
| **Pilih semua** | Tombol di atas daftar, menyentang seluruh barang yang **sedang terlihat** (hasil penyaring sumber, tanggal, dan pencarian) — bukan seluruh katalog. Labelnya menyebut angkanya, mis. "Pilih semua (12)", dan berbalik jadi "Batalkan semua (12)". Jumlah tiap barang tetap masing-masing. |
| **Jumlah & batch per barang** | Isian "Jumlah tiap barang" yang berlaku untuk semua sudah dihapus. Tiap barang yang dicentang punya kotak jumlahnya sendiri (dengan tombol "Semua N") dan pilihan **dari batch mana** — lengkap dengan tanggal kedaluwarsa, jumlah, dan lokasinya. Batch yang dipilih benar-benar **didahulukan** saat stok dipotong; bila isinya kurang, sisanya diambil FEFO seperti biasa. |
| **Draf otomatis** | Isian formulir tersimpan di peramban. Menekan Kembali atau menutup tab tidak lagi menghapus keranjang; saat dibuka lagi muncul tawaran **Pulihkan / Buang**. |

`cari_stok.php` memakai sesi login web, bukan token — jadi ia harus
berada di folder aplikasi yang sama dengan `perpindahan_form.php`, bukan
di dalam `api/`. Kalau panelnya berbunyi "Tidak dapat menghubungi
server", berkas inilah yang belum naik.

**`_lib.php` wajib ikut naik untuk pilihan batch.** Yang menghormati
pilihan itu ada di `ambil_fefo()` / `ambil_fefo_supplier()` di dalamnya.
Bila hanya tampilannya yang diunggah, panelnya tetap bisa memilih batch —
tetapi stok yang dipotong tetap FEFO, dan tidak ada satu pun tanda di
layar bahwa pilihannya diabaikan. Itu jenis kesalahan yang baru
ketahuan berbulan-bulan kemudian saat stok fisik tidak cocok.

Cara memeriksanya sesudah unggah: buat satu dokumen keluar, pilih batch
yang **bukan** paling dekat kedaluwarsa, setujui dan terima. Lalu buka
menu **Batch & Expired** — batch yang Anda pilih itulah yang jumlahnya
berkurang, bukan batch yang kedaluwarsa lebih dulu.

`_perpindahan_form.php` juga ikut **menyusut 50 baris**: kueri stok
per-lokasi yang dulu disisipkan ke halaman sebagai `const STOK` sudah
dibuang. Panelnya kini membacanya dari `cari_stok.php`, jadi kueri
empat-JOIN atas `v_stok_rantai` itu berjalan di setiap pemuatan formulir
tanpa pernah dibaca. Tidak ada perilaku yang berubah — hanya satu kueri
yang hilang dari tiap pembukaan halaman.

**Folder `sesi/` JANGAN diunggah.** Ia dibuat sendiri oleh `auth.php` pada
permintaan pertama, beserta `.htaccess` penjaganya, dan isinya adalah sesi
login yang sedang hidup. Mengunggah folder itu dari komputer berarti
mengirim sesi lokal Anda ke server.

Kalau folder itu gagal dibuat di hosting (izin folder aplikasi tidak
mengizinkan), buat manual lewat File Manager cPanel: folder bernama `sesi`
di dalam folder aplikasi, izin **0755**. Jalankan `cek_sistem.php?kunci=…`
untuk memastikan — baris **Folder sesi** harus berbunyi "folder sendiri
siap".

`_lib.php` dan `pengguna.php` sudah ada di Tahap 1 & 2 — versi yang Anda
unggah harus yang TERBARU, karena keduanya ikut berubah untuk fitur ini.

Tanpa migrasinya sistem tetap jalan: kode membaca kolom yang belum ada
sebagai "ikut bawaan", persis perilaku lama. Yang tidak jalan hanya
penyimpanan pilihannya.

**Tidak ada berkas `api/` yang berubah** di putaran ini — seluruh perubahan
ada di sisi web.

---

## Jangan lupa migrasinya

Kalau belum dijalankan, editor izin akan tampil tetapi **tidak menyimpan
apa pun**:

    arsip_sql/migrasi_izin_pengguna.sql

Jalankan lewat phpMyAdmin. Aman diulang.

Cara memastikan sudah jalan: beri satu izin pada satu akun, simpan, lalu
buka lagi akun yang sama. Kalau pilihannya kembali ke "Ikut tingkat",
berarti tabelnya belum ada.

---

## Sesudah unggah — pemeriksaan

1. Masuk sebagai akun **toko** yang sudah Anda beri Kelola Master Data →
   di sidebar muncul kelompok **Izin Khusus** berisi Data Barang,
   Kategori, Gudang, Rak / Lokasi, Supplier, Toko / Outlet, Penerima
   Amanah.
2. Klik salah satunya → halamannya **terbuka**, bukan 403.
3. Cabut izin itu di Kelola Pengguna → kelompok **Izin Khusus** hilang
   dari sidebar, dan halamannya kembali menolak 403.
4. Menu lama toko (Stok Toko, Gudang Toko, Laporan Stok, dst.) **tidak
   berubah sama sekali** — izin hanya menambah, tidak menggeser.

Kalau ada halaman yang blank putih sesudah unggah, hampir pasti
`_lib.php` belum naik sementara halamannya sudah. Unggah `_lib.php` lalu
muat ulang.

---

## Tahap 7 — Foto produk langsung dari kamera

Tidak ada migrasi, dan **tidak ada satu baris kode server yang berubah**.

- [ ] `assets/kamera-foto.js`  ← **BERKAS BARU**
- [ ] `_barang_isian.php`  ← tombol "Foto dengan Kamera" + pratinjau
- [ ] `_ikon.php`  ← ikon `i-kamera`
- [ ] `assets/amanah.css`  ← gaya panel kamera (sudah di Tahap 6, pastikan TERBARU)

Hasil jepretan dipasang ke `<input type="file">` lewat `DataTransfer`, jadi
ia tiba di server sebagai unggahan biasa dan melewati seluruh pemeriksaan
yang sudah ada: `is_uploaded_file()`, batas 2 MB, `getimagesize()`, dan
daftar putih format. Itulah sebabnya `_barang.php` dan `_lib.php` tidak
perlu disentuh — dan sebabnya jalur base64 sengaja TIDAK dipakai: ia akan
menjadi pintu kedua ke folder unggahan yang lolos dari semua pemeriksaan itu.

**Kamera langsung menuntut HTTPS.** Di `http://` biasa (bukan localhost)
peramban memblokir `getUserMedia` tanpa bisa dinegosiasikan. Kalau itu
terjadi, tombolnya tidak mati — ia jatuh ke kamera bawaan perangkat lewat
`<input capture>`, yang di ponsel justru hasilnya lebih baik. Jadi pastikan
SSL-nya hidup; kalau tidak, di komputer desktop tombol itu hanya akan
membuka pemilih berkas.

Foto dari kamera otomatis dikecilkan ke sisi terpanjang 1600 px dan
dikodekan JPEG bertingkat (0,85 → 0,4) sampai muat di bawah 2 MB. Diuji
dengan derau acak 1920×1440 — gambar tersulit untuk JPEG — hasilnya
1,13 MB pada langkah pertama. Foto produk sungguhan jauh lebih kecil.

Cara memeriksa sesudah unggah:

1. Buka **Produk → Tambah Barang**, tekan **Foto dengan Kamera**.
2. Izinkan kamera → panel video terbuka di bawah tombolnya.
3. Tekan **Jepret** → panel tertutup, lampu kamera **mati**, kotak
   pratinjau terisi, dan di sebelah "Pilih File" muncul nama berkas
   `kamera-<angka>.jpg`.
4. Simpan → foto tersimpan seperti unggahan biasa.

Kalau langkah 3 memunculkan pesan "Foto gagal dipasang ke formulir",
peramban itu tidak mendukung `DataTransfer`; pakai **Pilih File**. Pesan itu
sengaja ditampilkan alih-alih menutup panel diam-diam — foto yang hilang
tanpa pemberitahuan jauh lebih buruk daripada satu langkah manual.

---

## Tahap 8 — Gudang & rak: banyak gudang per akun, rak yang bisa ditemukan

Tidak ada migrasi. Tabel `user_gudang` yang dipakai fitur ini **sudah ada
sejak awal** di basis data — sebelumnya hanya tidak pernah dipakai sebagai
sumber lingkup.

- [ ] `_lib.php`  ← lingkup banyak gudang + menu Rak (pastikan TERBARU)
- [ ] `pengguna.php`  ← daftar centang gudang (pastikan TERBARU)
- [ ] `gudang.php`  ← jenis gudang bisa dipindahkan
- [ ] `dasbor_toko.php`  ← pengelola rak gudang toko (pastikan TERBARU)
- [ ] `keluar.php`  ← isian Gudang Asal untuk akun bergudang banyak
- [ ] `barang_stok.php`  ← saringan rak ikut semua gudang (pastikan TERBARU)

### Yang berubah

| | Sebelum | Sesudah |
|---|---|---|
| **Gudang per akun** | Satu dropdown, satu gudang | Daftar centang, boleh banyak. Yang pertama jadi **gudang utama** (pilihan bawaan formulir); seluruh yang dicentang menjadi lingkupnya. |
| **Menu Rak** | Tidak ada di menu mana pun — hanya satu tautan teks di tengah halaman Gudang | Tab **Rak / Lokasi** di sebelah Gudang, untuk Super Admin, Admin Gudang, dan Admin Terpadu |
| **Jenis gudang** | Terkunci mati; Gudang Toko tidak punya jalan kembali | Bisa dipindahkan **pusat ⇄ toko** lewat satu ceklis sengaja |
| **Rak gudang toko** | Hanya satu rak bawaan, selamanya — akun toko ditolak Master Rak | Kartu **Rak Gudang Toko** di *Dasbor Toko › Gudang*: tambah, ubah, hapus |

### Yang perlu diperiksa sesudah unggah

1. **Kelola Pengguna → ubah satu Admin Gudang** → kotak *Gudang yang Dipegang*
   kini daftar centang. Centang dua gudang, simpan, buka lagi → keduanya
   masih tercentang.
2. Masuk sebagai akun itu → **Laporan Stok** dan **Barang Masuk** menampilkan
   rak dari **kedua** gudang.
3. **Barang Keluar** pada akun bergudang banyak → muncul isian **Gudang Asal**
   yang wajib. Pada akun bergudang tunggal isian itu **tidak muncul** dan
   tidak ada yang berubah.
4. **Master Data → Gudang** → ada tab **Rak / Lokasi** di sebelahnya.
5. **Dasbor Toko → Gudang** → kartu **Rak Gudang Toko** dengan tombol Tambah Rak.

### Catatan penting

**Akun lama tidak kehilangan aksesnya.** Bila `user_gudang` kosong untuk
sebuah akun — keadaan semua akun yang dibuat sebelum ini — lingkupnya jatuh
ke `users.gudang_id`, persis perilaku lama. Jadi urutan unggahnya tidak
kritis dan tidak ada akun yang mendadak melihat halaman kosong.

**Gudang toko tetap SATU.** Memindahkan sebuah gudang pusat menjadi gudang
toko akan **ditolak** bila sudah ada gudang toko lain — aturan yang sama
dengan yang ditegakkan dasbor toko. Kalau tidak, stok terpecah ke gudang
yang tidak dilihat siapa pun.

**Menyunting alamat tidak mengubah jenis.** Isian jenis hanya dibaca bila
ceklis *"Pindahkan jenis gudang ini"* ikut terkirim. Itu penting: dulu
jenisnya dikunci justru supaya menyunting alamat sebuah Gudang Toko tidak
diam-diam menjadikannya gudang pusat, dan sifat itu dipertahankan.

**Rak berisi barang tidak bisa dihapus**, di Master Rak maupun di dasbor
toko. Menghapusnya membuat batch menunjuk rak yang tidak ada lagi: stoknya
tetap terhitung di total tetapi hilang dari semua lokasi — selisih yang
tidak bisa dijelaskan siapa pun saat opname.

---

## Tahap 9 — Gudang untuk akun toko, dan surat jalan yang tidak terbuka ke semua

Tidak ada migrasi.

- [ ] `_lib.php`  ← `gudang_toko_saya()`, lingkup surat jalan, `boleh_lihat_perpindahan()`
- [ ] `pengguna.php`  ← kotak gudang ikut muncul untuk peran toko
- [ ] `dasbor_toko.php`  ← gudang & rak mengikuti penugasan
- [ ] `surat_jalan.php`  ← pemeriksaan lingkup sebelum mencetak
- [ ] `cek_surat.php`  ← memakai pemeriksaan yang sama
- [ ] `api/_lingkup.php`  ← sisi API disamakan
- [ ] `api/dashboard.php`  ← arus gudang toko mengikuti penugasan

### 1. Akun toko bisa dibatasi ke gudang yang dipilihkan

Di **Kelola Pengguna**, akun berperan **toko** sekarang ikut mendapat kotak
*Gudang yang Dipegang*. Sifatnya berbeda dari Admin Gudang:

| | Admin Gudang | Toko |
|---|---|---|
| Penugasan gudang | **wajib**, minimal satu | **opsional** |
| Dibiarkan kosong | ditolak simpan | berarti **seluruh gudang toko** — perilaku lama |

Bila dicentang, akun toko itu hanya mengelola gudang tersebut: stok, rak,
dan barang masuknya. Aplikasi Android ikut menyempit lewat token yang sama —
kalau tidak, dasbor aplikasi akan menampilkan arus gudang lain sementara
daftar stoknya sudah menyempit.

### 2. Surat jalan hanya untuk pengirim dan penerima

| Peran | Sebelum | Sesudah |
|---|---|---|
| **Super Admin** | semua dokumen | **semua dokumen** (tidak berubah) |
| **Pimpinan** | **semua dokumen** | tidak ada — menu Surat Jalan pun dilepas |
| **Admin Gudang** | daftarnya tersaring, tetapi **halaman cetak tidak** — cukup ganti angka di alamat untuk mencetak surat jalan gudang lain | hanya dokumen yang menyentuh gudangnya, di daftar **maupun** saat mencetak |
| **Toko / Supplier** | hanya miliknya | hanya miliknya (tidak berubah) |

Aturan itu dulu ditulis **tiga kali** dengan tiga bentuk berbeda — di daftar
dokumen, di halaman cetak, dan di Cek Surat Jalan — dan dua di antaranya
melepas peran internal tanpa diperiksa. Sekarang satu fungsi,
`boleh_lihat_perpindahan()`, dipakai ketiganya.

**Yang TIDAK diubah:** halaman **Persetujuan**. Penyetuju tetap melihat
dokumen yang harus disetujuinya, termasuk Pimpinan — kalau ikut ditutup,
persetujuan berhenti bekerja. Yang ditutup hanya daftar dan cetak surat
jalannya. Kalau Anda ingin Pimpinan juga bisa membuka surat jalan dokumen
yang ia setujui, beri tahu saya.

### Pemeriksaan sesudah unggah

1. Masuk sebagai **Pimpinan** → menu **Surat Jalan** sudah tidak ada;
   **Mitra & Lokasi** dan **Laporan** tetap ada.
2. Masuk sebagai **Admin Gudang** → buka Surat Jalan, catat sebuah nomor
   dokumen milik gudang lain dari Super Admin, lalu coba
   `surat_jalan.php?id=<id itu>` → **403**.
3. **Kelola Pengguna → akun toko** → kotak *Gudang yang Dipegang* muncul,
   keterangannya berbunyi "opsional".
4. Centang satu gudang, simpan, masuk sebagai akun itu → **Dasbor Toko →
   Gudang** hanya menampilkan gudang tersebut beserta raknya.

---

## Tahap 10 — Dasbor web difokuskan ke gudang masing-masing

**Berkas:** `dashboard.php`

Seluruh angka di dasbor dihitung untuk **satu lembaga**, lalu dipajang
dengan label yang berbunyi seolah tentang gudang pembacanya — "Total Stok ·
Unit di gudang", "Hampir Habis · Produk", "Mendekati Exp · Batch ≤ 90 hari".
Admin Gudang Ramah membuka dasbornya dan membaca stok, dokumen, serta
aktivitas Gudang Depo sebagai miliknya sendiri.

Diukur di basis data lokal, akun Admin Gudang **Ramah** (gudang kosong):

| Angka | Sebelum | Sesudah |
|---|---|---|
| Total Stok | 700 (isi Depo) | **0** |
| Menunggu Setuju | 4 (dokumen Depo) | **0** |
| Aktivitas | 162 baris seluruh lembaga | **0** |
| Perlu Diterima | 1 (angka global) | **1** (kiriman yang memang menuju Ramah) |
| Nama gudang lain di halaman | semuanya | **tidak ada** |

Super Admin dan Pimpinan **tidak berubah lingkupnya**: `gudang_saya()`
mengembalikan seluruh gudang bagi mereka, jadi penyaring yang sama tidak
mempersempit apa pun. Tidak dibuat cabang "kalau super admin, lewati" —
cabang seperti itu tempat kebocoran berikutnya bersembunyi.

**Angka yang akan terlihat berubah bagi Super Admin:** Total Stok kini
dihitung dari `batch_barang`, bukan dari tabel singgahan `stok` yang tidak
punya kolom gudang sama sekali. Di data lokal 700 → 701; selisihnya batch
yang menginap di toko dan tidak tercatat di singgahannya. Sekarang angka
besar di atas dan tabel sebaran di bawahnya bersumber sama, jadi keduanya
tidak bisa lagi berselisih.

**Dua hal yang tidak punya kolom gudang sama sekali**, dan cara menyiasatinya:

- `transaksi_masuk` / `transaksi_keluar` — yang tahu tempatnya baris
  rinciannya (`lokasi_rak_id` untuk masuk, batch yang diambil untuk keluar).
  Penyaringan dilakukan di sana.
- `activity_log` — hanya mencatat siapa yang berbuat, bukan di mana. Disaring
  per **rekan segudang** plus diri sendiri. Itu pendekatan, bukan jawaban
  tepat: Super Admin yang berbuat di gudang lain tidak tersaring.

### Pemeriksaan sesudah unggah

1. Masuk sebagai **Admin Gudang** → "Total Stok" hanya isi gudangnya;
   tabel Sebaran Stok hanya memuat gudang yang ditugaskan padanya.
2. Halaman yang sama → tidak ada nama gudang lain di mana pun, termasuk di
   Dokumen Terbaru dan Aktivitas Terakhir.
3. Masuk sebagai **Pimpinan** → **Antrean Persetujuan tetap berisi**.
   Sempat kosong saat perubahan ini ditulis, tertangkap waktu diuji per
   peran: syarat "boleh mencetak surat jalan" bukan syarat "boleh mengawasi
   dokumen", dan Pimpinan justru peran yang menyetujuinya.
4. Masuk sebagai **Super Admin** → jumlah gudang, dokumen, dan angka
   "Habis" sama seperti sebelumnya.

---

## Tahap 11 — Penyaring periode & lokasi di Ringkasan

**Berkas:** `dashboard.php`, `assets/amanah.css`

Bilah penyaring baru di atas kartu Ringkasan, berisi dua kendali:

- **Periode** — Hari Ini / 7 Hari / Bulan Ini
- **Lokasi** — satu daftar bergrup: Gudang, Toko / Outlet, Supplier

Ditambah kartu baru **"Produk di <lokasi>"**: daftar barang beserta jumlah
batch, qty, dan kedaluwarsa terdekat di tempat yang dipilih. Tabel Stok per
Gudang / Toko / Supplier yang sudah ada kini namanya bisa diketuk dan
langsung membuka daftar itu.

### Dua hal yang perlu diketahui sebelum memakainya

**1. Periode hanya mengubah angka ARUS.** Masuk dan Keluar ikut berubah.
Halaman ini tidak punya grafik berbasis waktu — satu-satunya diagramnya
Distribusi Kategori, dan itu mengikuti **lokasi**, bukan periode. Total Stok, Hampir Habis, dan Batch Kedaluwarsa **tidak** — ketiganya
potret keadaan saat ini, dan "stok bulan ini" tidak berarti apa-apa.
Kartunya diberi keterangan `· saat ini`, dan ada satu kalimat penjelas di
bawah bilah penyaring. Tanpa itu, orang akan menyangka penyaringnya rusak.

**2. Pilihan lokasi TIDAK BISA dipakai menembus lingkup.** Daftar pilihannya
dibangun dari lingkup akun, lalu pilihan yang datang dari alamat dicocokkan
ke daftar itu — bukan sebaliknya. Pilihan yang tidak ada di daftarnya jatuh
diam-diam kembali ke "seluruh lingkup saya", tanpa halaman galat yang
memberi tahu penebak bahwa gudang itu ada.

Diuji sebagai Admin Gudang Ramah:

| Alamat yang dicoba | Hasil |
|---|---|
| `?lokasi=gudang:6` (miliknya) | diterima, kartu "Produk di Gudang Ramah" muncul |
| `?lokasi=gudang:5` (Gudang Depo) | **ditolak diam-diam**, kembali ke lingkup sendiri |
| `?lokasi=toko:4` | **ditolak** — Admin Gudang tidak punya pilihan toko |
| `?periode=xxx` | jatuh ke `hari` |

Di keempat percobaan itu, **tidak ada satu pun nama gudang lain yang muncul
di halaman**.

### Pemeriksaan sesudah unggah

1. Klik pil **7 Hari** lalu **Bulan Ini** → angka **Masuk** dan **Keluar**
   berubah; **Total Stok** tetap.
2. Pilih satu **toko** di daftar Lokasi → muncul kartu **Produk di <toko>**
   berisi nama barang, bukan hanya angka unit. Kartu **Stok per Gudang**
   menghilang (ia tidak relevan, dan kalau dibiarkan keadaan kosongnya
   berbunyi "Belum ada gudang" — salah total).
3. Klik nama gudang di tabel **Stok per Gudang** → halaman tersaring ke
   gudang itu.
4. Sebagai **Admin Gudang**, ganti angka di `?lokasi=gudang:<id lain>` →
   halaman kembali ke lingkup sendiri, bukan menampilkan gudang itu.

---

## Tahap 12 — Jalan pintas "Kirim ke Toko" di dasbor

**Berkas:** `dashboard.php`

Fiturnya **sudah ada sejak awal**, yang tidak ada petunjuk menuju ke sana.
Menu bernama **Surat Jalan**, dan delapan jenis dokumen baru terlihat setelah
formulirnya dibuka — tidak ada satu pun kata "toko" sebelum itu. Orang yang
mencari cara membagi barang ke gerai tidak punya alasan menduga ia di sana.

Ditambahkan satu kartu Aksi Cepat: **Kirim ke Toko** →
`perpindahan_form.php?jenis=kirim_toko`, yang membuka formulir yang sama
dengan jenis dokumen sudah terisi. Parameter `?jenis=` bukan hal baru —
ia sudah divalidasi di `_perpindahan_form.php` terhadap daftar jenis yang
sah, dan peran toko tetap dipaksa ke `mutasi_rak`.

**Dua jalur distribusi yang berbeda, jangan tertukar:**

| | Dari → Ke | Siapa | Bentuk |
|---|---|---|---|
| **Surat Jalan · Kirim ke Toko** | gudang pusat → toko | Super Admin, Admin Gudang, Admin Gudang & Donasi | satu dokumen, banyak barang sekaligus |
| **Dasbor Toko · Distribusikan ke Toko** | gudang toko → gerai | Admin Toko tingkat **manager** | satu barang per pengiriman |

### Pemeriksaan sesudah unggah

1. Dasbor → Aksi Cepat → **Kirim ke Toko** → formulir terbuka dengan jenis
   "🏪 Kirim ke Toko — gudang ke toko" sudah terpilih.
2. Tujuan bisa diisi **Toko**, dan daftar tokonya muncul.

---

## Tahap 13 — Kirim ke gudang mana pun

**Berkas:** `_perpindahan_form.php`, `_perpindahan_js.php`

Dulu satu daftar gudang dipakai untuk **asal dan tujuan sekaligus**,
disaring ke gudang sendiri. Akibatnya Admin Gudang Depo tidak bisa
mengirim apa pun ke Gudang Ramah — gudang tujuan yang sah pun tidak
muncul di pilihannya, dan POST-nya ditolak server.

Keduanya sekarang dipisah, karena dua pertanyaannya memang berbeda:

| | Pertanyaan | Aturan |
|---|---|---|
| **Asal** | stok siapa yang **berkurang** | tetap dibatasi ke gudang sendiri |
| **Tujuan** | stok siapa yang **bertambah** | dibuka ke seluruh gudang aktif |

Mengambil dari rak gudang lain berarti memotong persediaan yang bukan
tanggung jawabnya, tanpa sepengetahuan yang mengurusnya. Mengirim tidak
mengurangi apa pun milik penerima — dan barangnya baru benar-benar masuk
setelah gudang tujuan memverifikasi fisiknya lewat Penerimaan Barang.

Tujuan tetap diperiksa **ada dan aktif**, supaya POST yang diubah tangan
tidak bisa menitipkan barang ke gudang yang sudah dihapus.

**Supplier tidak perlu diubah:** dasbor mitra sejak awal menawarkan
seluruh gudang aktif sebagai tujuan, tanpa penyaring apa pun.

### Diuji sebagai Admin Gudang berlingkup Gudang Ramah

| Percobaan | Hasil |
|---|---|
| Daftar **asal** | hanya Gudang Ramah |
| Daftar **tujuan** | kelima gudang |
| POST rak Ramah → Gudang Depo | **dokumen PP terbit**, status menunggu persetujuan |
| POST rak Depo → Gudang Ramah | ditolak: *"Rak asal berada di gudang yang bukan tanggung jawab Anda."* |

### Pemeriksaan sesudah unggah

1. Surat Jalan → Buat → jenis **Transfer Gudang** → daftar **Tujuan** memuat
   semua gudang, daftar **Asal** hanya gudang Anda.
2. Simpan satu transfer ke gudang lain → dokumen terbit.
3. Masuk sebagai admin gudang **tujuan** → dokumen itu muncul di
   **Penerimaan Barang** dan terhitung di kartu "Perlu Diterima".

---

## Tahap 14 — Simpan draf saat mencatat barang masuk

**Berkas baru:** `assets/draf-formulir.js`
**Berkas berubah:** `masuk.php`, `dasbor_supplier.php`, `_perpindahan_js.php`

Draf otomatis kini ada di **tiga** formulir keranjang, bukan satu:

| Formulir | Peran | Awalan kunci |
|---|---|---|
| Barang Masuk | Admin Gudang, Super Admin | `amanah_draf_masuk_` |
| Barang Masuk Mitra | Supplier | `amanah_draf_masuk_mitra_` |
| Surat Jalan / Perpindahan | internal | `amanah_draf_pp_` *(sudah ada)* |

Cara kerjanya sama seperti yang sudah berjalan di Surat Jalan: isian
tersimpan sendiri ke perangkat 400 ms setelah ketikan berhenti, termasuk
baris keranjang yang dibuat pemindai dan tombol "Pilih Banyak Barang".
Saat halaman dibuka lagi, drafnya **ditawarkan**, tidak dipulihkan
diam-diam — orang yang membuka formulir untuk pekerjaan baru tidak boleh
menemukannya sudah terisi barang kemarin lalu mengirimkannya tanpa sadar.

Draf hangus sendiri setelah **3 hari**, dan dibuang begitu formulirnya
berhasil dikirim.

### Kenapa mesinnya dipindah ke berkas sendiri

Mesin ini lahir di dalam `_perpindahan_js.php`. Menyalinnya dua kali berarti
tiga salinan yang akan berselisih pada perbaikan pertama yang hanya
disentuh di salah satunya — dan yang berselisih diam-diam pada fitur
penyelamat pekerjaan adalah yang paling mahal, karena kegagalannya baru
ketahuan saat pekerjaan sudah hilang.

`_perpindahan_js.php` berkurang **231 baris**; yang tertinggal di sana hanya
yang memang khas formulir itu — kunci per jenis dokumen, dan urutan
penyegaran dropdown asal/tujuan saat memulihkan.

**Awalan kuncinya sengaja dibedakan.** Mitra dan gudang bisa memakai
komputer kantor yang sama, dan draf titipan mitra tidak boleh ditawarkan
di formulir gudang. Diuji: draf Surat Jalan tidak muncul di formulir Barang
Masuk, dan tidak ikut terhapus olehnya.

### Diuji

24 pemeriksaan otomatis pada mesinnya (`node uji_draf.js`, DOM tiruan):
penyimpanan isian & baris, csrf tidak ikut tersimpan, formulir kosong tidak
meninggalkan draf, draf kedaluwarsa dibuang, tawaran & pemulihan, tombol
Buang, penghapusan saat terkirim, pemisahan antar awalan, dan perpindahan
kunci saat jenis dokumen berganti. **24 lulus, 0 gagal.**

### Pemeriksaan sesudah unggah

1. **Barang Masuk** → isi beberapa baris → tutup tab → buka lagi: muncul
   kotak "Ada catatan barang masuk yang belum terkirim". Tekan **Pulihkan**
   → barisnya kembali lengkap dengan batch, tanggal, dan rak.
2. Tekan **Buang** → kotaknya hilang dan tidak muncul lagi saat dimuat ulang.
3. Simpan satu transaksi sungguhan → buka formulir lagi: tidak ada tawaran
   draf (drafnya sudah dibuang saat terkirim).
4. Masuk sebagai **Supplier** → **Barang Masuk** → hal yang sama berlaku.
5. **Surat Jalan** → pastikan draf di sana masih bekerja seperti sebelumnya.

---

## Tahap 15 — Alat lihat isi lokasi (baca saja)

**Berkas baru:** `alat_isi_lokasi.php` — hanya command line, hanya membaca.

Dijalankan sebelum mengosongkan sebuah lokasi, supaya yang memutuskan tahu
persis apa yang akan tersentuh. Mengosongkan gudang tidak bisa dibatalkan;
menebak isinya dari ingatan bukan dasar yang cukup.

```
php alat_isi_lokasi.php                 daftar semua gudang, toko, supplier
php alat_isi_lokasi.php gudang 6        isi satu gudang
php alat_isi_lokasi.php toko 4
php alat_isi_lokasi.php supplier 4
```

Yang dilaporkan dua lapis:

- **ISI** — batch yang masih bersisa, per barang.
- **KETERKAITAN** — berapa baris riwayat (transaksi masuk/keluar, perpindahan,
  penjualan, opname, penyesuaian, mutasi, retur) yang menunjuk batch di situ.

Lapis kedua yang menentukan caranya: kalau **nol**, batchnya boleh dihapus
tuntas — khas data uji yang belum pernah dipakai. Kalau **tidak nol**,
menghapus akan memutus riwayat, dan yang benar adalah menolkan lewat
penyesuaian stok.

Alat ini menolak dijalankan lewat peramban (`PHP_SAPI !== 'cli'` → 403).

---

## Tahap 16 — Alat nolkan stok per lokasi

**Berkas baru:** `alat_nolkan_stok.php` — hanya command line.

Menolkan, **bukan menghapus**. Tiap batch di lokasi yang dipilih diturunkan ke
nol lewat `sesuaikan_batch()` — fungsi yang sama persis dengan Stock Opname
dan halaman Sesuaikan Stok. Tiga jalur, satu fungsi, sehingga tidak mungkin
menghasilkan penyesuaian yang berbeda bentuknya.

Setelahnya `recalc_stok()` dijalankan per barang yang tersentuh, sama seperti
`opname.php` dan `barang_stok.php`. Tanpa itu, daftar produk dan seluruh
peringatan stok akan menampilkan angka lama.

```
# 1. lihat dulu — tidak mengubah apa pun
php alat_nolkan_stok.php gudang 6 --alasan="Opname awal, rak kosong"

# 2. jalankan sungguhan
php alat_nolkan_stok.php gudang 6 --alasan="Opname awal, rak kosong" \
    --oleh=admin@alfatihah.com --ya="Gudang Ramah" --terapkan
```

### Tiga penjaga

| Penjaga | Melawan |
|---|---|
| Pratinjau adalah bawaannya | perintah salah ketik berakhir sebagai laporan, bukan gudang yang terlanjur kosong |
| `--ya="Nama Lokasi"` harus diketik persis | salah angka id → gudang lain yang dikosongkan |
| `--oleh=email` wajib, perannya diperiksa | penyesuaian stok anonim atas barang titipan orang |

Ditambah `--alasan` wajib, satu transaksi untuk seluruhnya (gagal di tengah =
tidak ada yang berubah), dan kode keluar 1 saat ditolak.

**Supplier tidak dilayani** — stoknya di `stok_supplier`, bukan `batch_barang`,
jadi `sesuaikan_batch()` tidak berlaku. Alatnya menolak dengan pesan jelas.

### Diuji di data lokal

Dua batch uji (30 + 12 unit) di Gudang Ramah:

- keempat penjaga menolak sesuai perannya masing-masing
- setelah `--terapkan`: `qty_tersisa` jadi 0, **`qty_awal` tetap 30 dan 12** —
  jejak apa yang pernah ada tidak hilang
- terbit `PS-20260928-0001` dan `-0002`, jenis `koreksi_kurang`, beralasan,
  bernomor rak, atas nama akun yang disebut di `--oleh`
- `stok.qty_total` cocok dengan jumlah batch sungguhan sesudahnya
- data uji dibersihkan kembali

---

## Tahap 17 — Tombol Kosongkan per rak & per gudang

**Berkas berubah:** `_lib.php`, `_footer.php`, `rak.php`, `gudang.php`,
`alat_nolkan_stok.php`

Tombol **Kosongkan** di tiap baris halaman **Rak / Lokasi** dan **Gudang**.
Hanya muncul kalau lokasinya memang berisi — tombol yang tidak melakukan
apa-apa pada rak kosong hanya menambah satu benda merah di baris yang sudah
padat.

Mesinnya `nolkan_stok_lokasi()` di `_lib.php`, dipakai bertiga: tombol Rak,
tombol Gudang, dan `alat_nolkan_stok.php`. Di dalamnya `sesuaikan_batch()` —
jalur yang sama dengan Stock Opname — lalu `recalc_stok()` per barang. Ditulis
tiga kali, salah satunya akan lupa memanggil recalc_stok(), dan gejalanya
bukan galat melainkan angka stok yang diam-diam salah.

### Nama lokasinya harus diketik ulang

Dialog konfirmasi bersama di `_footer.php` dapat atribut baru
`data-ketik="RAK-002"`: tombol lanjutnya mati sampai teksnya diketik persis.
Tanpa atribut itu, dialognya berperilaku persis seperti sebelumnya.

Sekali klik terlalu ringan untuk ini: tombolnya ada di baris tabel,
bersebelahan dengan rak lain, dan menekan "Ya, lanjutkan" adalah refleks —
mengetik "RAK-002" bukan. Yang dilawan satu kesalahan tertentu: **menekan
tombol di baris yang salah.**

### Wewenangnya beda dari Hapus, dan itu disengaja

| Tindakan | Siapa |
|---|---|
| **Hapus** gudang | hanya Super Admin — ini perubahan struktur lembaga |
| **Kosongkan** gudang/rak | Admin Gudang atas gudangnya sendiri — ini pekerjaan gudang biasa, sama saja dengan opname seluruh rak lalu menulis nol |

Yang dijaga bukan perannya melainkan lingkupnya: `wajib_akses_rak()` dan
`wajib_akses_gudang()`.

### Cacat yang ketahuan justru karena tombol ini

`rak.php` **tidak menyaring daftar raknya sama sekali** — hanya
`deleted_at IS NULL`. Admin Gudang Ramah melihat seluruh rak lembaga
lengkap dengan gudang induk dan isinya. Dropdown penyaringnya sudah
disaring sejak dulu; daftarnya tidak. Ketahuan saat tombol Kosongkan muncul
di RAK-001 milik Gudang Depo pada akun yang lingkupnya Gudang Ramah.
Sekarang daftarnya ikut `in_gudang_saya()`.

### Diuji

Sebagai Admin Gudang berlingkup Gudang Ramah (tingkat manager):

- daftar rak: **RAK-002, 008, 009, 010** saja — empat rak Ramah, bukan 13
- tombol Kosongkan hanya di RAK-002 (satu-satunya yang berisi), dialognya
  menyebut "42 unit dari 2 jenis barang" dan meminta ketik `RAK-002`
- POST `act=kosongkan&id=10` (rak Gudang Depo) → **403**
- POST untuk RAK-002 → 2 batch dinolkan, `qty_awal` tetap 30 dan 12,
  terbit `PS-20260928-0006` dan `-0007` beralasan

---

## Tahap 18 — Perbaiki keterangan batch

**Berkas baru:** `batch_edit.php`
**Berkas berubah:** `monitoring.php`, `barang_detail.php`

Sampai sekarang keterangan batch hanya bisa ditulis **sekali**, saat barang
dicatat masuk. Tidak ada satu pun tempat di aplikasi yang bisa mengubahnya —
seluruh `UPDATE batch_barang` yang ada hanya menyentuh `qty_tersisa`.

Akibatnya satu salah ketik tanggal kedaluwarsa jadi permanen. Dan itu bukan
kesalahan kecil: tanggal kedaluwarsa menentukan urutan FEFO dan seluruh
peringatan di halaman Batch & Expired.

### Yang boleh diperbaiki, dan yang sengaja tidak

| | Di mana |
|---|---|
| Nomor batch, tanggal kedaluwarsa, kondisi | **di sini** — ketiganya diketik orang, dan yang diketik bisa salah ketik |
| Jumlah sekarang | Sesuaikan Stok / Stock Opname — supaya tetap beralasan dan bernomor |
| Lokasi | Perpindahan Barang, jenis Mutasi Rak — memindahkan kardus itu kejadian fisik |
| Jumlah awal | tidak pernah — ia jejak berapa yang dulu benar-benar masuk |

Halaman ini menampilkan ketiganya di kartu sebelah kanan lengkap dengan
tautan ke jalur yang benar, jadi yang mencari "ubah jumlah" tidak berakhir
kebingungan.

**Alasan perbaikan wajib diisi**, dan tercatat di riwayat aktivitas bersama
nilai lama → nilai barunya, per medan yang benar-benar berubah.

### DUA jalan masuk, dan yang kedua justru yang penting

1. **Batch & Expired** → tombol *Perbaiki* di tiap baris.
2. **Detail Produk → daftar batch** → tombol *Perbaiki* di tiap baris.

Yang kedua bukan tambahan: tabel di Batch & Expired **hanya memuat batch yang
sudah terflag mendekati kedaluwarsa**. Batch yang tanggalnya salah ketik jadi
tahun 2035, atau yang lupa diisi sama sekali, tidak pernah muncul di sana —
padahal justru itulah yang paling perlu diperbaiki. Ketahuan saat diuji: di
basis data lokal **tidak satu pun batch punya tanggal kedaluwarsa**, sehingga
jalan masuk pertama tidak menampilkan apa pun.

### Diuji

- tanggal kedaluwarsa lebih awal daripada tanggal masuk → **ditolak**
- perbaikan sah → ketiga medan berubah, **`qty_awal` dan `qty_tersisa` tidak
  tersentuh**, riwayat berbunyi `nomor batch: "BUG" -> "BUG-REV1"; kedaluwarsa:
  tanpa tanggal -> 15 Mar 2027; kondisi: baru -> bekas_layak · <alasan>`
- Admin Gudang Ramah membuka batch milik Gudang Depo → **403**
- batch yang dipakai menguji sudah dikembalikan ke keadaan semula

---

## Tahap 19 — Kolom aksi tidak lagi keluar tepi + Batch tanpa tanggal

**Berkas berubah:** `assets/amanah.css`, `monitoring.php`

### 1. Tombol ketiga mendorong tombol terakhir keluar tabel

Sel aksi ditulis `<td class="nowrap aksi">`, dan `.nowrap` memaksanya satu
baris. Selama isinya dua tombol (Edit, Hapus) itu muat. Begitu **Kosongkan**
masuk di Tahap 17, tombol **Hapus** terdorong keluar tepi dan hanya terlihat
setelah tabel digulir mendatar — dan tombol yang tidak terlihat itu, bagi
yang memakainya, sama saja dengan tidak ada.

Aturan mobile sudah membungkus barisnya sejak dulu; yang tidak ada padanannya
untuk layar lebar. Sekarang ada, di `@media (min-width:769px)` — titik patah
yang sama dengan aturan mobile-nya (768px), supaya tidak ada celah lebar yang
tidak kebagian aturan mana pun.

Dibuat dengan `inline-flex` per anak, **bukan** `display:flex` pada `<td>`:
`<td>` yang dijadikan flex container keluar dari tata letak tabel dan lebar
kolomnya berhenti sepadan dengan kepala tabelnya.

Ditambah lebar minimum 160px untuk kolom nama, supaya "Gudang Depo" tidak
patah jadi dua baris sementara kolom kode di sebelahnya menyisakan ruang.

### 2. Bagian "Batch Belum Punya Tanggal Kedaluwarsa"

Tabel "Kedaluwarsa Terdekat" hanya memuat batch yang **sudah punya tanggal
dan sudah mendekat**. Batch yang tanggalnya lupa diisi tidak pernah muncul di
sana — padahal justru itu yang perlu dibetulkan, dan justru itu yang membuat
halaman ini tampak kosong: di basis data lokal **seluruh 8 batch belum
bertanggal**, sehingga tombol Perbaiki dari Tahap 18 tidak pernah terlihat.

Bagian baru ini berlingkup (`syarat_lingkup`), dibatasi 50 baris dengan
jumlah totalnya disebut, dan tiap baris punya tombol **Perbaiki**.

Bahasanya "belum diisi", bukan "bermasalah" — mainan, perlengkapan, dan
pakaian memang tidak punya masa kedaluwarsa. Yang memutuskan orang yang tahu
barangnya.

### Diuji

- Super Admin → bagian baru muncul dengan **7 tombol Perbaiki** (7 batch
  bersisa tanpa tanggal)
- Admin Gudang Ramah (gudang kosong) → bagian itu **tidak muncul sama
  sekali**, batch Gudang Depo tidak bocor
- `gudang.php`, `rak.php`, `kategori.php` tetap dirender tanpa galat
- kurung CSS seimbang (1064/1064)

---

## Tahap 20 — Tanggal kedaluwarsa mustahil + daftar dipendekkan

**Berkas berubah:** `_lib.php`, `monitoring.php`, `batch_edit.php`,
`assets/amanah.css`

### 1. `1 Jan 0001` · `−739886 hari`

Terlihat di data sungguhan. Akibatnya bukan sekadar angka ganjil: daftar
**Kedaluwarsa Terdekat** diurutkan dari yang paling mendesak, jadi baris
sampah itu menempati puncaknya — dan barang yang benar-benar kedaluwarsa
pekan ini terdorong ke bawah olehnya.

Sebabnya `tambah_ke_lokasi()` meneruskan tanggal **apa adanya ke basis
data, tanpa satu pun pemeriksaan**. MySQL menerima `0001-01-01` tanpa
mengeluh, dan tidak ada yang tahu sampai ada yang membaca layarnya.

Tiga lapis perbaikan:

| Lapis | Apa |
|---|---|
| Pintu masuk | `tanggal_expired_sah()` di `_lib.php`, dipakai `tambah_ke_lokasi()` — yang mustahil jadi `NULL`, bukan ditolak dengan galat (barang fisiknya sudah tiba; menolak seluruh penerimaan karena satu kolom salah ketik membuat petugas kehilangan seluruh pekerjaannya) |
| Tampilan | tanggal sebelum `EXP_PALING_TUA` (2000-01-01) dikeluarkan dari Kedaluwarsa Terdekat |
| Perbaikan | bagian baru **"Tanggal Kedaluwarsa Tidak Masuk Akal"**, bergaris merah, tiap baris bertombol Perbaiki |

Yang **tetap diterima**: tanggal yang sudah lewat. Stok yang memang
kedaluwarsa wajib terlihat — itu inti halaman ini. 10 kasus diuji, semua
lulus, termasuk `2027-02-31` (bentuk benar, tanggalnya tidak ada).

`batch_edit.php` memakai fungsi yang sama, supaya yang lolos di satu pintu
tidak ditolak di pintu lain tanpa alasan yang terbaca.

### 2. Daftarnya tidak lagi tak berujung

`$expList` dulu **tanpa LIMIT sama sekali**. Di gudang berisi 974 jenis
barang, halaman ini jadi gulungan ratusan baris. Sekarang 40 teratas, dengan
jumlah seluruhnya disebut di subjudul.

### 3. Baris setinggi 170px jadi dua baris

Kolom Lokasi dulu: lencana ikon berdiri sendiri, lalu "Gudang Apotek › Area
Penampung" sebagai satu untai panjang yang patah jadi **empat baris**.
Sekarang rak tebal di depan, gudang induknya kecil di bawahnya.

Nama rak & gudang diambil **lewat join, bukan dipecah dari untai view**.
Percobaan pertama memang memecah pada tanda `›` — dan gagal tanpa sebab yang
terlihat dari kode: di basis data lokal pemisah itu tersimpan sebagai
mojibake `ÔÇ║`, sisa pengodean rusak saat view-nya dulu dibuat. Memecah untai
yang bentuknya bergantung pada pengodean basis data adalah kerapuhan yang
tidak perlu ditanggung; kolomnya sendiri sudah ada.

Ditambah lebar minimum kolom dan `nowrap` untuk kolom angka/tanggal, supaya
"1 Jan" tidak terpisah dari "2027".

### Diuji

Dengan dua batch tiruan (satu `0001-01-01`, satu kedaluwarsa 5 hari lagi):

- puncak **Kedaluwarsa Terdekat** kini **"Mouse · 3 Okt 2026"** — yang
  benar-benar mendesak, bukan yang bertanggal sampah
- batch `0001-01-01` pindah ke bagiannya sendiri, bertombol Perbaiki
- kolom Lokasi terbaca `🏭 RAK SUSU` / `Gudang Depo` — dua baris, tanpa mojibake

---

## Tahap 21 — Setujui sekelompok + Daftar Batch

**Berkas baru:** `batch.php`
**Berkas berubah:** `persetujuan.php`, `monitoring.php`, `_lib.php`,
`assets/amanah.css`

### 1. Antrian persetujuan dikelompokkan per tujuan

Penjualan daring menerbitkan **satu dokumen kirim per pesanan**. Satu toko
yang ramai menghasilkan puluhan dokumen menunggu persetujuan dalam sehari —
seluruhnya dari rak yang sama ke toko yang sama, masing-masing satu jenis
barang. Menyetujuinya satu per satu berarti puluhan kali membuka, membaca,
menekan, kembali.

Kartu baru di atas antrian mengelompokkannya per **jenis + tujuan**, dengan
tombol *Setujui N dokumen*. Kelompok berisi satu dokumen tidak ditampilkan.

**Yang digabung PERSETUJUANNYA, bukan dokumennya.** Tiap dokumen tetap
berdiri sendiri karena masing-masing mewakili satu pesanan pembeli dengan
surat jalannya sendiri — menggabungkannya jadi satu dokumen memutus jejak
antara pesanan dan kirimannya, dan itu justru yang dicari saat ada pembeli
mengaku barangnya belum sampai.

Tiga hal yang dijaga:

- **Id tidak diambil dari formulir.** Yang dikirim hanya kunci kelompoknya;
  daftar dokumennya disusun ulang di server dengan syarat lingkup yang sama
  seperti antrian. Kalau id dikirim dari formulir, satu angka yang diubah
  tangan cukup untuk mengesahkan dokumen gudang lain.
- **Wewenang ditimbang per dokumen** dengan `boleh_setujui_dokumen()` —
  fungsi yang sama dengan jalur satuan. Penjaga 403 di atas dikecualikan
  untuk aksi ini karena ia memang tidak punya satu id; wewenangnya dipindah,
  bukan dilonggarkan.
- **Satu transaksi PER DOKUMEN**, bukan satu untuk semuanya. Dibungkus satu
  transaksi besar, satu kegagalan membatalkan puluhan pengesahan yang sudah
  benar.

Diuji dengan lima dokumen ke satu toko → kelima disetujui, masing-masing
terbit surat jalannya sendiri, tercatat "(borongan)". Diuji kegagalan
sebagian dengan penyaluran 9999 unit dari stok 253 → pesannya berbunyi
*"2 dokumen disetujui sekaligus… 1 dokumen GAGAL dan stoknya tidak berubah
sama sekali… Yang gagal masih menunggu di antrian."*

### 2. Daftar Batch — `batch.php`

Halaman Batch & Expired menjawab "mana yang bermasalah" dan memang tidak
pernah memuat batch yang baik-baik saja. Akibatnya batch yang perlu
dibetulkan **tanpa sedang bermasalah** — nomornya tertukar, kondisinya salah
pilih — tidak punya tempat untuk dicari.

Halaman baru: seluruh batch dalam lingkup, dengan pencarian (nomor batch,
nama, kode barang), enam pil penyaring berangka (semua / masih bersisa /
sudah habis / belum bertanggal / sudah kedaluwarsa / tanggal tidak masuk
akal), paginasi 30 baris, dan tombol **Perbaiki** tiap baris.

Masuk grup menu **Batch & Expired** bersama Perbaikan & Pelepasan — ketiganya
pekerjaan yang sama dari tiga sudut.

### Pemeriksaan sesudah unggah

1. Persetujuan → kartu kelompok muncul bila ada ≥2 dokumen ke tujuan sama.
2. Tekan *Setujui N dokumen* → ketik konfirmasi → N surat jalan terbit.
3. Menu **Batch & Expired → Daftar Batch** → cari nomor batch, tekan
   Perbaiki.
4. Masuk sebagai Admin Gudang → daftar batch hanya memuat gudangnya.

---

## Tahap 22 — Donasi: cara terima, resi paket, dan laporannya

**Migrasi:** `db/migrasi_donatur_cara_terima.sql`
**Berkas baru:** `laporan_donasi.php`
**Berkas berubah:** `_lib.php`, `masuk.php`, `dashboard.php`

### Masalahnya: kolomnya ada, isinya seragam

`donasi.cara_terima` — enum `diantar|dijemput|dikirim` — **sudah ada sejak
awal**. Tetapi `masuk.php` menuliskannya **mati sebagai 'diantar'** untuk
setiap donasi, termasuk paket yang jelas datang lewat kurir. Jadi laporan
donasi kunjungan vs donasi paket yang diwajibkan yayasan mustahil disusun:
kolomnya terisi semua dengan nilai yang sama.

Dan untuk paket, identitas kirimannya tidak ada tempatnya sama sekali —
padahal **nomor resi satu-satunya bukti** yang dipunyai donasi yang tidak
bertatap muka.

### Yang ditambahkan

| | |
|---|---|
| Migrasi | kolom `ekspedisi` + `no_resi` di `donasi`, dua indeks untuk laporan |
| `_lib.php` | `CARA_TERIMA_DONASI` + `label_cara_terima()`, `cara_terima_sah()`, `cara_terima_butuh_resi()` |
| `masuk.php` | pilihan **Cara Terima**; isian Ekspedisi & No. Resi yang muncul hanya untuk kiriman kurir |
| `laporan_donasi.php` | laporan bersaring periode × cara terima × jenis, dengan pencarian resi |
| `dashboard.php` | kartu **Donasi Masuk** per cara terima, tiap angka menuju laporan yang sudah tersaring |

**Dua sumbu yang mudah tertukar, dan sengaja dipisah:**

- `JENIS_DONASI` = **bentuk** pemberiannya (barang biasa / paket siap salur)
- `CARA_TERIMA_DONASI` = **bagaimana** ia sampai (diantar / dijemput / dikirim)

Keduanya bersilangan bebas: paket siap salur bisa diantar langsung, barang
biasa bisa datang lewat ekspedisi. Digabung satu kolom, salah satu
pertanyaan selalu kehilangan jawabannya.

### Keputusan yang perlu diketahui

**Kartu donasi di dasbor TIDAK berlingkup gudang** — dan itu bukan
kelalaian. Tabel `donasi` memang tidak punya kaitan ke gudang atau rak mana
pun; ia catatan lembaga (siapa memberi apa, kapan, lewat jalur mana),
sementara ke rak mana barangnya diletakkan dicatat terpisah lewat
penerimaan. Karena itu kartunya hanya dipasang untuk peran yang memang
mengurus donasi: pengawas lembaga dan **Admin Gudang & Donasi**. Admin
Gudang biasa tidak melihatnya.

**Nama donatur tidak ditampilkan di dasbor** — hanya jumlahnya. Namanya data
pribadi; tempatnya di laporan yang dibuka dengan niat memeriksanya.

**Kiriman tanpa resi tidak ditolak.** Resi kadang baru diketahui belakangan,
dan paket yang sudah tiba tetap harus bisa dicatat hari itu. Yang kosong
ditandai merah di laporan sebagai *"resi belum ditulis"*, lengkap dengan
peringatan berangka di puncak halaman dan di dasbor.

**Resi diabaikan bila caranya bukan kiriman.** Nilai yang terlanjur diketik
lalu caranya diganti tidak ikut tersimpan — donasi "diantar donatur"
bernomor resi adalah catatan yang menyesatkan pemeriksanya.

### Cacat yang tertangkap saat diuji

INSERT donasi berada **di dalam closure** `dengan_nomor_dokumen(...)`, dan
tiga variabel baru tidak ikut di `use (...)`. Akibatnya ketiganya null di
dalam, dan penyimpanan gagal dengan *"Column 'cara_terima' cannot be null"* —
yang sampai ke petugas sebagai kode galat, bukan sebab yang bisa dibaca.
Ketahuan hanya karena penyimpanannya diuji lewat formulir sungguhan, bukan
lewat kuerinya saja.

### Diuji

- 5 donasi tiruan (1 diantar, 1 dijemput, 3 dikirim termasuk satu tanpa resi)
- kartu ringkasan: Diantar 1 · 3 unit, Dijemput 1 · 7 unit, Dikirim 3 · 17 unit
- saringan: semua 5, `cara=dikirim` 3, `cara=diantar` 1, cari "JNE123" 1
- peringatan *"1 donasi paket belum punya nomor resi"* muncul
- simpan lewat formulir → `DN-20261003-0001` jenis=paket cara=dikirim
  ekspedisi=SiCepat resi=SC-UJI-20261003
- POST `cara=diantar` dengan resi disertakan → **resi & ekspedisi diabaikan**
- Admin Gudang biasa → kartu donasi **tidak muncul**
- seluruh data uji dibersihkan (donasi kembali 0 baris)

### Urutan unggah

1. `db/migrasi_donatur_cara_terima.sql` → jalankan lewat **migrasi.php**
2. `_lib.php`
3. `masuk.php`, `laporan_donasi.php`, `dashboard.php`

Migrasinya **wajib lebih dulu**: tanpa kolom `ekspedisi`/`no_resi`, halaman
penerimaan akan gagal menyimpan setiap donasi.

---

## Tahap 23 — Rentang tanggal sendiri di Laporan Donasi

**Berkas berubah:** `laporan_donasi.php`, `assets/amanah.css`

Empat pil periode menjawab pertanyaan yang sering diulang. Yang tidak
terjawab olehnya justru yang paling sering dibutuhkan laporan: **satu bulan
tertentu yang sudah lewat**, atau periode kepengurusan yang tidak jatuh di
batas bulan. Memilih "Tahun Ini" lalu menghitung sendiri bagian yang
diinginkan adalah cara paling mudah membuat angka laporan meleset.

Sekarang ada dua kotak tanggal — **Dari … s/d …** — plus tombol Terapkan dan
Hapus rentang.

### Tiga keputusan di baliknya

**Rentang MENIMPA pil, bukan menambahinya.** Dua saringan waktu yang
berlaku bersamaan menghasilkan irisan yang tidak diminta siapa pun, dan yang
membacanya tidak punya cara tahu mana yang sedang menang. Selama rentang
berlaku, **tidak ada pil periode yang ditandai aktif** — pil terpilih
sementara yang mengerjakan rentangnya adalah penyaring yang berbohong
tentang dirinya sendiri. Menekan pil mana pun membuang rentangnya.

**Salah satu boleh kosong.** Isi `dari` saja berarti "sejak tanggal itu";
isi `sampai` saja berarti "sampai tanggal itu". Memaksa keduanya membuat
orang mengarang batas yang tidak dimaksudkannya.

**Tanggal terbalik dibalik, dan DISEBUTKAN.** Membetulkannya diam-diam
menyembunyikan salah ketik yang mungkin juga terjadi di tempat lain pada
laporan yang sama.

Formulirnya **terpisah dari kotak cari**, bukan ditempel: keduanya dikirim
pada saat yang berbeda — orang memilih rentang sekali lalu mencari
berkali-kali di dalamnya.

### Diuji

Empat donasi tersebar Jul-5, Agu-12, Sep-20, Okt-2:

| Saringan | Hasil |
|---|---|
| `periode=semua` | 4 baris · judul "Semua Waktu" |
| `dari=2026-08-01&sampai=2026-09-30` | 2 baris · "1 Agu 2026 – 30 Sep 2026" |
| `dari=2026-09-01` | 2 baris · "sejak 1 Sep 2026" |
| `sampai=2026-07-31` | 1 baris · "sampai 31 Jul 2026" |
| terbalik `dari=09-30&sampai=08-01` | dibalik jadi 1 Agu – 30 Sep, pemberitahuannya muncul |
| rentang + `cara=dikirim` + cari "JT-SEP" | 1 baris |

Kartu ringkasan ikut rentang (Dikirim 2 · 12 unit, sisanya 0), tidak ada pil
periode yang aktif, dan tautan tiap pil memang tanpa `dari`/`sampai`.

---

## Tahap 24 — Daftar ekspedisi diperbanyak + penyaring per ekspedisi

**Berkas berubah:** `_lib.php`, `masuk.php`, `laporan_donasi.php`

### Daftar saran: 10 → 24

`EKSPEDISI_SARAN` di `_lib.php`, satu tempat, dipakai formulir penerimaan.
Isinya tiga kelompok: ekspedisi reguler (JNE, J&T, SiCepat, Anteraja, Ninja,
POS, TIKI, Wahana, Lion Parcel, ID Express, SAP, Indah Cargo, Dakota), kurir
instan (GoSend, Grab Express, Lalamove, Deliveree, Paxel, Maxim, ShopeeFood),
dan jalur lain yang nyata dipakai donatur (Travel/Titipan Bus, Kurir Pribadi,
Ojek Online, Diambil Rekan).

**Tetap kotak teks bebas dengan `<datalist>`, BUKAN `<select>`.** Terlihat di
data sungguhan: ada donasi lewat **"RANS"**, kurir yang tidak akan pernah ada
di daftar mana pun yang kami susun. Kurir daerah, ojek titipan, dan rekan
yang kebetulan lewat adalah kenyataan sehari-hari — daftar tertutup memaksa
semuanya jadi "lain-lain" dan justru menghapus keterangan yang dicari.
Daftarnya hanya mempercepat yang sering dipakai dan menyeragamkan ejaannya:
"J&T" yang diketik tangan bisa jadi "JnT", "J & T", atau "jnt", dan ketiganya
terhitung tiga ekspedisi berbeda di laporan.

### Penyaring ekspedisi di laporan

Barisan pil baru, **dibangun dari isi datanya sendiri** — bukan dari daftar
saran. Kalau dibangun dari daftar saran, kurir seperti "RANS" tidak akan
punya pilihannya sendiri dan donasinya hanya bisa ditemukan lewat pencarian
teks — padahal justru itu yang perlu dilaporkan.

Tiap pil membawa jumlahnya. Dihitung dalam periode yang sedang berlaku tapi
**mengabaikan saringan ekspedisi itu sendiri**, supaya memilih satu kurir
tidak membuat pilihan kurir lain hilang dari daftarnya.

Ditambah pil **"Belum ada resi"** berwarna merah: yang menyusun laporan perlu
bisa MEMBUKA daftarnya, bukan sekadar tahu jumlahnya dari peringatan di atas.

Barisannya berdiri sendiri, tidak disisipkan ke baris periode/jenis —
jumlahnya tumbuh mengikuti kurir yang dipakai, dan sepuluh pil berbaur
dengan pil lain akan terbaca sebagai satu kumpulan pilihan yang sama.

### Diuji

Enam donasi: JNE ×2, GoSend, **RANS**, satu tanpa resi, satu diantar.

| Saringan | Hasil |
|---|---|
| (semua) | 6 |
| `eks=JNE` | 2 |
| `eks=GoSend` | 1 |
| `eks=RANS` | **1** — kurir di luar daftar saran tetap bisa disaring |
| `eks=(kosong)` | 1 |
| `eks=JNE` + `cara=dikirim` | 2 |
| `eks=JNE` + cari "002" | 1 |

Pilnya terbaca: Semua · JNE 2 · GoSend 1 · RANS 1 · Belum ada resi 1.
Saringan ikut terbawa saat mencari dan berpindah halaman. Formulir
penerimaan memuat 24 saran. Data uji dibersihkan (donasi kembali 0 baris).

---

## Tahap 25 — Cetak Laporan Donasi memuat donatur & barangnya

**Berkas berubah:** `laporan_donasi.php`, `_lib.php`, `masuk.php`,
`assets/amanah.css`

Lembar cetak dulu hanya memuat ringkasan tiap donasi — "3 unit · 1 jenis".
Lembar itu dibaca orang yang **tidak membuka aplikasinya**: pengurus,
pemeriksa, donatur yang minta bukti. "3 unit" tanpa menyebut barangnya apa
tidak bisa dipertanggungjawabkan oleh siapa pun yang memegangnya.

### Rincian barang per donasi

Tiap baris kini punya baris rincian: nama & kode barang, kondisi, nomor
batch, kedaluwarsa, jumlah beserta satuannya, dan keterangannya.

Di layar **tertutup** sampai diketuk — tiga puluh donasi yang seluruh
rinciannya terbuka adalah gulungan tanpa ujung. Di kertas **selalu
terbuka**, dipaksa oleh `@media print` yang melawan atribut `hidden`.

Rinciannya diambil dalam **satu kueri** lalu dikelompokkan, dan **selalu
diambil** — bukan hanya saat dicetak. Halaman cetak dibuat peramban dari
halaman yang sudah tergambar; tidak ada kesempatan mengambil data tambahan
setelah tombol Cetak ditekan.

### Keterangan donatur

Nama kini disertai **jenis donatur dan wilayahnya** — nama sendirian sering
tidak cukup membedakan dua donatur bernama sama. Donatur anonim tetap
tampil sebagai "Hamba Allah", dan jenis donaturnya tidak ikut disebut.

Label jenis donatur dipindah ke `JENIS_DONATUR` di `_lib.php`; sebelumnya
ditulis tangan di `<select>` masuk.php tanpa padanan di tempat lain.

**NOMOR TELEPON SENGAJA TIDAK DIMUAT.** Ia tidak dibutuhkan untuk
mempertanggungjawabkan apa pun, sementara laporan cetak berpindah tangan
dan difoto. Diperiksa: ketiga nomor donatur uji tidak muncul di halaman.
Yang perlu menghubungi donatur membukanya di halaman Donatur.

### Aturan cetak lain yang ikut dirapikan

Tombol pembuka rincian, bilah penyaring, kotak cari, dan navigasi halaman
disembunyikan saat cetak — tidak satu pun berarti di kertas. Lencana
berwarna dicetak sebagai kotak bergaris hitam-putih. Satu donasi beserta
rinciannya dijaga tidak terpotong dua halaman: pembaca yang menemukan
daftar barang tanpa kepala barisnya harus membalik kembali untuk tahu itu
donasi siapa.

### Diuji

Tiga donasi (satu dua-baris-barang, satu paket berekspedisi, satu donatur
anonim):

- rincian tergambar lengkap, mis. `MamyPoko Pants BBY-0001 | Baru |
  BATCH-A | 1 Mei 2027 | 12 set | titipan rutin`
- kolom donatur: `[contoh] Ibu Huzaifah · Perorangan · Semarang`
- donatur anonim: `Hamba Allah · Kudus`, jenis donaturnya tidak disebut
- ketiga nomor telepon **tidak ada** di halaman
- data uji dibersihkan (donasi kembali 0 baris)

---

## Tahap 26 — Cetak diganti tabel datar

**Berkas berubah:** `laporan_donasi.php`, `assets/amanah.css`

Perbaikan Tahap 25 salah bentuk. Rincian barangnya memang ikut tercetak,
tetapi **sebagai kartu bertumpuk** — satu barang per kotak besar,
berhalaman-halaman, dengan ruang kosong di mana-mana.

Sebabnya: tabel layar memakai `.tbl-kartu`, yang berubah jadi kartu di lebar
sempit — dan **pratinjau cetak memang memakai lebar sempit**, sehingga
aturan ponsel ikut berlaku di kertas. Tahap 25 memaksa baris rinciannya
terbuka tanpa menyadari seluruh tabelnya sudah bukan tabel lagi.

### Lembar cetak kini punya tabelnya sendiri

Bukan tabel layar yang dipaksa-paksa. Datar, satu baris per barang:

| Tanggal | No. Donasi | Donatur | Barang | Jumlah | Cara Terima |
|---|---|---|---|---|---|
| 3 Okt 2026 | DN-CTK-2 | Majelis Peduli | Mouse (BBY-0003 · batch BATCH-C · exp 15 Jan 2027) | 30 pcs | Dikirim ekspedisi — JNE / JNE-CTK-99 |

Ditutup baris total: jumlah donasi dan jumlah unit.

**Keterangan donasi DIULANG tiap baris, bukan digabung dengan rowspan.** Sel
bergabung yang terpotong pergantian halaman meninggalkan daftar barang tanpa
kepala barisnya di halaman berikutnya — pembacanya harus membalik kembali
untuk tahu itu donasi siapa. Pengulangan memakan tinta, tetapi tiap baris
jadi bisa dibaca berdiri sendiri.

**Donasi tanpa rincian tetap dapat satu baris** bertulis *"tanpa rincian
barang"*. Kalau dilewati, jumlah baris di lembar tidak cocok dengan angka
ringkasan di atasnya dan pemeriksanya mencari yang hilang.

Kepala tabel diulang tiap halaman (`display:table-header-group`) — tabel
berhalaman-halaman tanpa kepala memaksa pembacanya menghitung kolom dari
ingatan.

### Kaki lembar menyebutkan saringannya

`Bulan Ini · Dikirim ekspedisi · Donasi paket · dicetak 3 Okt 2026 22:03`

Lembar cetak berpindah tangan tanpa konteks apa pun. Tanpa baris ini, dua
lembar dari saringan berbeda terlihat sama persis dan tidak ada cara
membedakannya. Bila laporannya berhalaman, nomor halaman dan jumlah donasi
yang tercetak ikut disebut.

### Diuji

Empat donasi (satu dua-baris-barang, satu paket berekspedisi, satu donatur
anonim, satu tanpa rincian):

- tabel cetak: 6 baris — kepala, 4 baris barang, 1 baris total
- `DN-CTK-1` muncul **dua kali**, satu per barangnya
- total **50 unit** (3 + 30 + 12 + 5) cocok
- donasi tanpa rincian: *"tanpa rincian barang"* · jumlah `—`
- kaki mengikuti saringan yang sedang aktif
- tabel cetak tersembunyi di layar, tabel layar tersembunyi saat cetak
