# Analisis Kekurangan Aplikasi Web AMANAH (folder `Gudang`)

Disusun 13 September 2026 dari pembacaan seluruh kode (71 berkas PHP, ±30.900
baris, CSS 2.880 baris, API ±6.900 baris), pemeriksaan skema `instal_database.sql`,
dan pengujian langsung di peramban (desktop dan lebar ponsel) memakai data lokal.

**Kesimpulan singkat.** Fondasinya di atas rata-rata aplikasi PHP prosedural:
prepared statement konsisten (tidak ditemukan SQL injection), CSRF hampir
menyeluruh, unggah gambar divalidasi ketat, sistem desain CSS sungguhan dan
responsif di ponsel. Kekurangannya terkumpul di empat tempat: (1) **API** yang
kurang gerbang peran/lingkup dan beberapa berkas diagnostik yang tertinggal di
produksi, (2) **alur bisnis yang sepihak** — ada skema/API tanpa halaman web
(opname), ada perintah "batalkan dokumen" tanpa cara membatalkan, (3) **tema web
dan Android sudah berbeda produk** (ungu/Segoe/gelap vs biru/Poppins/terang), dan
(4) `_lib.php` 4.337 baris dengan logika FEFO yang ditulis tiga kali dan sudah
menyimpang.

---

## 1. Keamanan — perbaiki dulu

### Kritis / tinggi
| # | Temuan | Bukti | Perbaikan |
|---|---|---|---|
| 1 | Endpoint diagnostik tanpa autentikasi mengirim 25 baris terakhir `error_log` server (nama DB, user, pesan PDO) | `api/cek.php:406-435` | Hapus dari produksi (PANDUAN_UNGGAH sudah menyuruh, tapi tidak ditegakkan) |
| 2 | `JWT_SECRET` jatuh ke nilai hard-coded bila env kosong; siapa pun yang membaca kode bisa menempa token super_admin | `api/_jwt.php:9` | Baca dari `config.local.php`, `die()` bila kosong |
| 3 | Login API tanpa pembatas percobaan; login web membatasi lewat `$_SESSION` (cukup buang cookie untuk lolos) | `api/login.php:22`, `login.php:45-63` | Simpan hitungan gagal di tabel (email + IP) dengan jendela waktu |
| 4 | Token 7 hari tanpa pencabutan; peran & lingkup dibekukan di token dan di `$_SESSION['role']` — akun yang dinonaktifkan/diturunkan tetap berjalan sampai sesi habis | `api/_jwt.php:10`, `_lib.php:1264`, `_lib.php:1126` | Verifikasi `is_active` + `role` dari DB di `auth_required()` dan `segarkan_perangkat()`; perpendek TTL |
| 5 | GET `master.php` (semua entitas termasuk penerima manfaat), `monitoring.php`, `alur.php?id=`, `scan.php`, `barang.php?id=` tidak menyaring lingkup; token toko/supplier bisa membaca data gudang lain | `api/master.php:19-110`, `api/monitoring.php:12-48`, `api/alur.php:24`, `api/scan.php:12-124` | Pakai `api_syarat_lingkup()` / `api_syarat_perpindahan()` seperti `api/laporan.php:37` |
| 6 | `PUT api/kiriman.php` tanpa gerbang peran maupun lingkup — token supplier lain bisa membatalkan kiriman siapa pun | `api/kiriman.php:41-61` | Batasi peran gudang + `AND supplier_id = ?` untuk supplier |
| 7 | Ekspor CSV laporan hanya `require_login()`; staff/toko/supplier bisa mengunduh harga & nilai persediaan yang di layar disembunyikan | `laporan_export.php:8` | Terapkan `boleh_lihat_laporan()` dan buang kolom rupiah bila `!boleh_lihat_nilai()` |
| 8 | Alat diagnostik & dump SQL di akar web: `versi.php` (publik, membocorkan versi PHP/OS), `cek_sistem.php` (kunci bawaan belum diganti), `cek_toko.php` (yatim, "berkas sementara"), 17 berkas `.sql` + `arsip_sql/` berisi hash sandi; `arsip_sql/` tanpa `.htaccess` sendiri | `versi.php`, `cek_sistem.php:32`, `cek_toko.php:6`, `.htaccess:33` | Hapus alat diagnostik; pindahkan `.sql` ke luar document root |
| 9 | Kredensial DB bawaan `root`/kosong ikut terunggah di `config.php`; bila `config.local.php` absen, aplikasi tetap mencoba `root` | `config.php:20-24` | Kosongkan nilai bawaan dan `die()` tanpa `config.local.php` |

### Sedang
- Lima sink `innerHTML` menyisipkan nama barang mentah → stored XSS lintas pengguna: `_perpindahan_js.php:195`, `dasbor_supplier.php:1213`, `dasbor_toko.php:2035`, `keluar.php:466,488`, `assets/keranjang-masuk.js:58` (helper `lolos()` sudah ada, tinggal dipakai).
- Tidak ada `Content-Security-Policy` maupun HSTS; header keamanan hanya lewat `mod_headers` (hilang diam-diam bila modul tak ada) — `.htaccess:92`.
- Flag `Secure` cookie ditentukan dari `$_SERVER['HTTPS']` saja, padahal `_lib.php:975` sudah tahu hosting-nya di belakang proksi TLS — `auth.php:86,137`.
- Folder sesi di dalam akar web (`Gudang/sesi/`), bergantung `.htaccess` — `auth.php:49-63`.
- 31 lokasi API mengirim `err($e->getMessage())` (bocor nama tabel/kolom); pola benar sudah ada di `api/gudang.php:352`.
- Callback OAuth Shopee tanpa parameter `state` (CSRF penyambungan toko) — `marketplace.php:148-165`.
- `cek_surat.php` satu-satunya penangan POST tanpa `wajib_csrf()` — `cek_surat.php:108-120`.
- Kredensial marketplace (`partner_key`, token) tersimpan teks polos — `_shopee.php:193`.

### Yang sudah baik (jangan diregresi)
PDO prepared statement di seluruh kode; `ORDER BY`/`LIMIT` dinamis lewat whitelist dan `(int)`; `e()` konsisten di sisi server; `terima_gambar()` memakai `getimagesize()`, nama acak, tolak SVG, dan `unggahan/.htaccess` tiga lapis; `jwt_decode()` kebal algorithm confusion; `display_errors` mati; `pesan_error_aman()` ada.

---

## 2. Fitur & alur bisnis yang kurang

### Skema/API sudah ada, halaman web belum
- **Stock opname**: tabel `stock_opname*` dan `api/opname.php` (dipakai Android) ada; web hanya `barang_stok.php` per-produk yang bahkan tidak ada di menu. `alur.php:291` mengakui "halamannya belum ada".
- **Pusat notifikasi**: `api/notifikasi.php` membangun daftar turunan yang dipakai Android; web hanya lonceng berangka di topbar (`_header.php:225`).
- **Detail produk, detail gudang + stok per gudang, detail rak**: `api/barang.php?id`, `api/gudang.php?id`, `api/rak.php?id` ada; web tanpa drill-down (`gudang.php`, `rak.php` hanya daftar).
- **Edit/hapus draft surat jalan**: `PUT api/perpindahan.php` ada; di web draft hanya bisa dikirim atau dibiarkan jadi sampah permanen.
- **Analitik** sebagai layar sendiri: web hanya menumpang `dashboard.php`; `laporan.php` tanpa satu pun parameter (tanpa rentang tanggal/filter gudang).
- **Surat jalan PDF**: web hanya HTML `window.print()`; Android sudah PDF + QR.

### Alur yang sepihak / buntu
- **Tidak ada cara membatalkan atau membalik dokumen perpindahan** — padahal `penerimaan.php:169` menyuruh "hubungi pengirim agar dokumennya dibatalkan". Enum status pun tak punya `batal`. Kiriman supplier justru bisa dibatalkan (`kiriman_supplier.php:38`) — tidak konsisten.
- **Viewer audit log** hanya 7 baris di dasbor dan 15 baris di profil; tidak ada halaman yang bisa dicari per modul/tanggal/pengguna — padahal argumen desain sistem ("barang titipan, angka harus bisa dijelaskan") bergantung padanya.
- **Reset password** tidak ada (teks mati "Hubungi admin"); Super Admin yang lupa harus menjalankan SQL manual (`ganti_password.php:96` menunjuk berkas di lokasi yang salah).
- **Retur** hanya ke supplier; `retur_toko`/`antar_toko` sah di aturan tapi tidak ditawarkan di dropdown form (`_perpindahan_isian.php:62`).
- **Kuitansi donatur & detail per donatur** (riwayat donasi) tidak ada; `api/donatur.php?id` justru sudah menyediakannya. Riwayat penerima amanah hanya agregat, tanpa drill-down dokumen.
- **Ekspor** hanya satu (CSV laporan stok). Tidak ada ekspor perpindahan, penerimaan, donatur, penerima, pelepasan, penyesuaian stok, audit log, penjualan toko.
- **Backup/restore** tanpa UI; cron marketplace tanpa pemantauan "terakhir jalan & berhasil?".
- Filter status `disetujui` dan `diterima` di `perpindahan.php:186` tidak pernah menghasilkan baris (status itu tidak pernah ditulis).
- Izin `lihat_laporan` di editor pengguna **tidak berefek** di web (`laporan.php` tidak memanggil `boleh_lihat_laporan()`).
- Menu "Barang Keluar" ditampilkan ke peran yang ditolak halamannya (`_lib.php:2427` vs `keluar.php:21`).
- Admin gudang dengan 2+ gudang tetap dianggap "satu gudang" (`_lib.php:1557`); transfer antar gudang hanya bisa lewat URL manual.
- `instal_database.sql` **tidak lengkap**: `donatur`, `donasi`, `donasi_detail`, `pelepasan_stok`, `user_gudang`, `izin_pengguna` (yang terakhir hanya di `arsip_sql/`) dan peran `admin_terpadu` hanya ada di berkas migrasi — instalasi bersih menghasilkan sistem rusak tanpa urutan migrasi yang terdokumentasi.
- Tabel mati: `mutasi_barang` (tak pernah ditulis → tidak ada kartu stok per barang), `retur`, `notifikasi`, `stock_opname*` (API opname pun menulis ke `penyesuaian_stok`). Kolom `printed_at` ditulis tapi tak pernah dibaca; `kendaraan`/`no_polisi` hanya bisa diisi supplier.

### Kekuatan web yang belum ada di Android (agar seimbang)
Impor CSV master & pesanan marketplace, sambungan Shopee/Tokopedia + cron, dasbor Toko dan Supplier yang lengkap, scan resi paket, halaman Cek Surat Jalan, editor izin per akun, pengaturan merek, pembuatan akun mitra.

---

## 3. UX / UI

- **Dua produk visual.** Web: `--primary:#7C3AED` ungu, font Segoe UI, bawaan gelap (`amanah.css:73,151`, `_header.php:46`). Android: biru `#2563EB`, Poppins, bawaan terang — dengan alasan tertulis (silau gudang). Tangga permukaan dan garis sudah hampir sama; yang berbeda hanya aksen, font, dan mode bawaan. Sarannya: samakan ke biru + Poppins + terang.
- **Tiga variabel CSS dipakai tapi tak pernah didefinisikan** → cacat tampak: `--line` (32 pemakaian di halaman publik; garis kartu jadi sewarna teks) dan `--r-md` (`amanah.css:1153`, kotak donatur jadi kotak tanpa lengkung).
- **Delapan halaman daftar tanpa cari/filter/paginasi** dan memuat seluruh tabel: `gudang`, `supplier`, `toko`, `pengguna`, `persetujuan`, `kategori`, `rak`, `masuk`, `keluar`; `penerima` dan `donatur` punya cari tapi tanpa `LIMIT`. Komponen `filter_bar()` (`_lib.php:259`) sudah ada tapi hanya dipakai satu halaman.
- **Tanpa keadaan sibuk**: tidak ada tombol yang dinonaktifkan saat submit → persetujuan (yang menggerakkan stok) dan impor bisa terkirim dua kali. Kerangka `.sk` didefinisikan tapi tidak dipakai siapa pun.
- **Dialog konfirmasi dua sistem**: modal `data-konfirm` yang bagus dipakai 3 berkas, 16 lokasi lain masih `confirm()` bawaan browser.
- **Aksesibilitas**: 437 dari 438 `<label>` tanpa `for=`; tidak ada `<h1>` di halaman aplikasi; menu `role="menu"` tanpa navigasi panah; label kapital 10,5px terlalu kecil untuk operator bersarung tangan.
- **`<style>` di halaman** meski `_header.php:10` melarangnya: `login.php` (token warnanya sudah menyimpang dari `amanah.css` dan terkunci gelap), `surat_jalan.php` (hard-code `#7C3AED`), `cek_sistem.php`, `alat_scan.php`, `barang_stok.php`.
- Dasbor timpang: `dashboard.php` 7 KPI + 2 grafik; `dasbor_toko.php` 10 KPI tanpa grafik; `dasbor_supplier.php` tanpa KPI dan grafik sama sekali.
- Blok galat `$errors[]` disalin ±25 kali (satu salinan di `impor_data.php:570` tanpa `e()`); `die('403')` polos di ±20 tempat tanpa kerangka halaman.

Yang sudah baik: 30 blok `@media`, `@media(pointer:coarse)` dengan sasaran 44px, tabel → kartu di ponsel (`.tbl-kartu`), `:focus-visible`, `prefers-reduced-motion`, cache-busting aset, menu terpusat.

---

## 4. Kualitas kode & operasional

- **`_lib.php` 4.337 baris** berisi 14 urusan (format, filter UI, stok, sesi, izin, menu, penomoran, FEFO, perpindahan, log) dan **efek samping tingkat atas** yang bisa `header('Location')` saat di-include (`_lib.php:4318`). Pecah menjadi `lib/format.php`, `lib/izin.php`, `lib/lingkup.php`, `lib/menu.php`, `lib/stok.php`, `lib/perpindahan.php`.
- **Bug nyata dari duplikasi FEFO**: `pratinjau_fefo()` (`_lib.php:3647`) tidak menerapkan filter kondisi layak dan preferensi batch yang dipakai `ambil_fefo()` (`:3374`) → pratinjau yang dilihat pengguna bisa berbeda dari batch yang benar-benar dipotong. Satu pembangun SQL bersama menyelesaikannya.
- **`api/_lingkup.php` adalah salinan paralel** 15 fungsi lingkup/izin `_lib.php` — dan keduanya dimuat bersamaan. Satu perubahan aturan yang lupa disalin berarti web dan ponsel menampilkan data berbeda untuk akun yang sama. Parameterkan fungsi `_lib.php` dengan `$principal`, hapus salinannya.
- `foto_url_penuh()` disalin tiga kali di `api/` (`barang.php:30`, `gudang.php:439`, `rak.php:236`); pemeriksaan "stok tidak cukup" disalin tiga kali (`keluar.php:88`, `_perpindahan_form.php:245`, `dasbor_toko.php:408`).
- **Performa**: `nama_lokasi()` N+1 dua kali per baris di `perpindahan.php:214` dan `penerimaan.php:499` (tanpa `LIMIT`); `pengguna.php:382` empat subkueri berkorelasi per baris tanpa `LIMIT`; `dashboard.php` ±41 kueri berurutan (30 `COUNT`); badge topbar dihitung ulang di dasbor; **tidak ada indeks `deleted_at`** di mana pun, dan tidak ada indeks `perpindahan(tujuan_tipe, tujuan_id)` padahal dipakai di setiap muat halaman (`_header.php:92`).
- **Tanpa router**: 71 titik masuk masing-masing menyusun gerbangnya sendiri; halaman baru yang lupa satu gerbang diam-diam terbuka (sudah pernah terjadi pada `perangkat.php`, `_lib.php:69`).
- **Deploy manual FTP** dengan daftar berkas tulisan tangan; **migrasi 20+ berkas `.sql` lepas** tanpa tabel pencatat migrasi. **Nol tes** (`_qr.php:33` menunjuk `uji_qr.php` yang tidak ada), padahal `terapkan_perpindahan()` dan FEFO memindahkan stok di dalam transaksi terkunci.
- Dokumentasi `alur.php` sudah basi (menyatakan marketplace belum tersambung, padahal sudah).

---

## 5. Urutan pengerjaan yang disarankan

1. **Hari ini (keamanan, tanpa risiko regresi):** hapus `api/cek.php`, `versi.php`, `cek_sistem.php`, `cek_toko.php` dari produksi; pindahkan `.sql` & `arsip_sql/` ke luar akar web; pindahkan `JWT_SECRET` dan kredensial DB ke `config.local.php`; gerbang peran di `laporan_export.php` dan `api/kiriman.php`.
2. **Minggu ini:** lingkup di `api/master.php`, `monitoring.php`, `alur.php`, `scan.php`, `barang.php`; pembatas login berbasis tabel untuk web dan API; segarkan `role`/`is_active` per permintaan; lima sink `innerHTML` → `lolos()`; CSP + HSTS + header dari PHP.
3. **Bug fungsional:** samakan `pratinjau_fefo()` dengan `ambil_fefo()`; definisikan `--line` dan `--r-md`; memoisasi `nama_lokasi()`; indeks `deleted_at` dan `perpindahan(tujuan_tipe, tujuan_id, status)`; lengkapi `instal_database.sql` + buat tabel `migrasi` dan runner-nya.
4. **Fitur yang menutup celah dengan Android:** halaman opname, pusat notifikasi, detail produk/gudang/rak, edit & hapus draft, pembatalan dokumen perpindahan, viewer audit log yang bisa dicari, reset password.
5. **UX:** satukan tema dengan Android (biru, Poppins, terang), paginasi + `filter_bar()` di delapan daftar, tombol sibuk saat submit, satu sistem dialog konfirmasi, `halaman_403()` dan `render_errors()` bersama, `for=` pada label, `h1` per halaman.
6. **Struktur jangka menengah:** pecah `_lib.php`, hapus `api/_lingkup.php`, prelude `_awal.php` dengan tabel rute → izin, tes pertama untuk FEFO dan matriks rute perpindahan.
