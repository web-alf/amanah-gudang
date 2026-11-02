# Audit Keamanan AMANAH — 29 Oktober 2026

Disusun dengan membaca seluruh kode saat ini (146 berkas PHP, ±51.000 baris, 30 endpoint
API, 34 berkas migrasi) pada commit `e2b776d`, dengan worktree kotor — 5 berkas sedang
termodifikasi saat audit berjalan, dan yang dibaca adalah keadaan di disk.

Berbeda dari `ANALISIS_KEKURANGAN*.md` yang membahas kelengkapan fitur dan kualitas kode,
dokumen ini **hanya** membahas batas keamanan: siapa boleh membaca dan mengubah apa.

## Cara audit ini dikerjakan, dan batasnya

Setiap klaim di bawah sudah lewat tiga lapis: pemburu yang menemukannya, verifier
independen yang **mencoba menjatuhkannya**, lalu verifier kedua yang memeriksa ulang
catatan jadinya. Dari 28 kandidat awal, **6 gugur** karena tidak memenuhi standar bukti —
daftarnya ada di bagian akhir supaya tidak dilaporkan ulang nanti.

**Batas yang perlu diketahui:** tidak ada basis data yang terjangkau saat audit berjalan
(`config.local.php` kosong di mesin audit). Jadi seluruh bukti berbasis pembacaan kode —
tidak ada permintaan HTTP yang dikirim, tidak ada hasil runtime yang diamati, dan tidak ada
sistem produksi yang disentuh. Untuk cacat yang sifatnya struktural (predikat SQL yang
hilang, fungsi yang tidak dipanggil) pembacaan kode sudah menentukan. Untuk hal yang
bergantung konfigurasi hosting, lihat bagian "Butuh jawaban Anda".

---

## Ringkasan

Basis kode ini **jauh di atas rata-rata** aplikasi PHP flat-file. Tidak ditemukan SQL
injection, XSS, path traversal, SSRF, maupun open redirect yang dapat dieksploitasi di
seluruh 51.000 baris. Cakupan CSRF lengkap. JWT menghindari dua kesalahan paling umum.
Daftar lengkap yang diperiksa dan terbukti aman ada di bagian 5.

Hampir seluruh temuan berasal dari **satu akar struktural**: web dan API menduplikasi
aturan otorisasi alih-alih membagikannya. `api/_lingkup.php` sudah memperingatkan ini di
kepalanya sendiri — dua salinan pagar yang melenceng adalah mode kegagalan paling
berbahaya. Itulah yang terjadi, berulang kali.

**14 temuan terkonfirmasi** — 6 tinggi, 5 sedang, 2 rendah, 1 informasional.
**8 hal** butuh jawaban yang hanya bisa diberikan tim, bukan dari kode.

---

## 1. Perbaiki lebih dulu — rasio hasil tertinggi

### 1.1 Pasang `izin_pengguna` ke API — satu perbaikan, dua temuan tertutup

**Masalah.** `api_izin()` (`api/_lingkup.php:100-107`) sudah ada dan sudah benar, tapi
hanya terpasang untuk 2 dari 7 kunci katalog: `lihat_nilai` dan `lihat_laporan`. Kunci
`kelola_master` dan `hapus` **tidak pernah dibaca satu pun berkas di `api/`** — string
`kelola_master` nol kemunculan di seluruh direktori itu.

Akibatnya dua hal:

1. Hak yang **dicabut** lewat Kelola Pengguna tetap hidup penuh lewat aplikasi mobile.
   Dan `pengguna.php:754` memberi tahu admin: *"Berlaku untuk semua peran dan semua
   tingkat — tidak ada pembatasan selain yang Anda tetapkan di sini."* Untuk kanal API,
   kalimat itu tidak benar — sehingga celahnya tidak mungkin ditemukan admin tanpa
   membaca kode.
2. Peran `pimpinan` — yang `boleh_edit()` (`_lib.php:1936-1938`) kecualikan secara
   sengaja — bisa menulis master data, data donatur, dan melepas stok lewat API, karena
   `api/kategori.php:34,49`, `api/pelepasan.php:32-33`, `api/donatur.php:47-48`, dan
   `api/master.php:173` menuliskan `pimpinan` langsung ke allow-list mereka sendiri.

**Perbaikan.** Tambahkan dua helper di `api/_lingkup.php`:

```php
/** Padanan boleh_kelola_master() untuk jalur API. */
function api_boleh_kelola_master(PDO $pdo, array $p): bool {
    $bolehEdit = in_array($p['role'] ?? '', ['super_admin', 'admin_gudang', 'admin_terpadu'], true);
    return api_izin($pdo, $p, 'kelola_master', $bolehEdit && api_manager($p));
}

/** Padanan boleh_hapus(). */
function api_boleh_hapus(PDO $pdo, array $p): bool {
    $bolehEdit = in_array($p['role'] ?? '', ['super_admin', 'admin_gudang', 'admin_terpadu'], true);
    return api_izin($pdo, $p, 'hapus', $bolehEdit && api_manager($p));
}
```

Lalu ganti setiap `in_array($payload['role'], [...])` di lima berkas endpoint dengan
panggilan ke helper itu. Perhatikan `pimpinan` hilang dari daftar — itu memang tujuannya.

**Catatan:** `api/barang.php` sudah benar mengecualikan `pimpinan` di ketiga jalur
tulisnya (`:350`, `:399`, `:459`) dan bahkan menambah cek tingkat di `:465-468`. Berkas
itu tetap perlu diubah untuk `izin_pengguna`, tapi bukan untuk `pimpinan`.

---

### 1.2 `api/rak.php` + `api/gudang.php` — wajib diperbaiki di DUA tempat

**Masalah.** `api_gudang_id()` (`api/_lingkup.php:124-128`) mengembalikan `null` untuk
setiap peran selain `admin_gudang`/`admin_terpadu` — termasuk `toko` dan `supplier`.
Kedua berkas memperlakukan `null` itu sebagai "tak terbatas", dengan komentar
`// null = boleh lihat semua`. Tidak ada gate peran di kedua berkas.

Akun mitra eksternal bisa membaca seluruh jaringan gudang: `g.alamat`,
`g.penanggung_jawab`, `g.telepon` (`gudang.php:103-104`), inventaris penuh lewat cabang
`isi=stok`, dan `u.nama AS oleh` (`:282`, `:291`) yang menamai staf pemroses tiap transaksi.

> ### ⚠️ Jangan hanya mengganti `$pagar`
>
> `$pagar` **hanya dipakai cabang daftar** — `gudang.php:91,124,130,131` dan `rak.php:85`.
> Cabang `?id=` dan `isi=stok` dijaga pemeriksaan **`$gidSaya` yang terpisah** di
> `gudang.php:63` dan `rak.php:158`. Kalau hanya `$pagar` yang diperbaiki, inventaris
> penuh dan riwayat aktivitas bernama staf **tetap terbuka** — dan tim akan mengira sudah
> aman. Perbaiki keduanya.

**Perbaikan.** Pakai `api_in_gudang()` (sudah ada di `api/_lingkup.php:402-435`, sudah
menangani toko dan supplier dengan benar), dan ubah cek `?id=` dari **kesetaraan** menjadi
**keanggotaan** — satu akun toko bisa sah memegang lebih dari satu gudang, sehingga cek
kesetaraan yang sekarang salah untuk peran itu bahkan tanpa masalah `null`:

```php
$inGudang    = api_in_gudang($pdo, $payload);   // '' = tak dibatasi (super_admin/pimpinan)
$pagar       = $inGudang === '' ? '' : ' AND g.id IN (' . $inGudang . ')';
$lingkupSaya = $inGudang === '' ? null : array_map('intval', explode(',', $inGudang));

if ($id > 0 && $lingkupSaya !== null && !in_array($id, $lingkupSaya, true)) {
    err('Gudang ini di luar wewenang akun Anda.', 403);
}
```

Pola sama untuk `api/rak.php`, memakai `lr.gudang_id` dan memeriksa `$r['gudang_id']`
pada guard rincian rak.

**Preseden di kode sendiri:** `api/dashboard.php:129-145` mendokumentasikan kelas bug ini
persis dan sudah diperbaiki di sana. Perbaikannya tidak tersapu ke dua berkas ini.

---

### 1.3 Empat endpoint dengan detail/riwayat tak ter-scope

Polanya identik di keempatnya: **query daftar ter-scope dengan benar, query detail atau
riwayat di berkas yang sama tidak.**

| Berkas | Baris bermasalah | Yang bocor |
|---|---|---|
| `api/keluar.php` | `:47-53`, `:57-71` | `penerima`, `untuk_bayi`, nama & wilayah donatur |
| `api/masuk.php` | `:51-58`, `:65-81` | `total`, `harga`, `subtotal`, donatur, supplier |
| `api/pelepasan.php` | `:220-226` | donatur, `nilai_jual`, `biaya`, `no_bukti_setor`, petugas |
| `pelepasan.php` | `:160-165` | sama, tampil langsung di layar |

**Perbaikan untuk dua yang pertama.** `api/perpindahan.php:17-33` sudah menunjukkan pola
yang benar — komentarnya bahkan mendeskripsikan bug yang sama persis sebagai sudah
ditutup. Tiru itu: setelah header diambil, verifikasi minimal satu baris detail jatuh di
lingkup pemanggil sebelum mengembalikan data, dan pertahankan pesan 404 yang sama untuk
id di luar lingkup supaya keberadaannya tidak bisa disimpulkan.

```php
// api/keluar.php, setelah $header diambil
if ($gudangSaya !== null) {
    $cek = $pdo->prepare(
        "SELECT 1 FROM transaksi_keluar_detail d
         JOIN batch_barang bb ON bb.id = d.batch_id
         WHERE d.transaksi_keluar_id = ?
           AND bb.lokasi_rak_id IN (SELECT lr.id FROM lokasi_rak lr
                                    WHERE lr.gudang_id = ? AND lr.deleted_at IS NULL)
         LIMIT 1"
    );
    $cek->execute([$id, $gudangSaya]);
    if (!$cek->fetch()) { err('Transaksi tidak ditemukan.', 404); }
}
```

`api/masuk.php` sama, memakai `rak_boleh()` yang sudah didefinisikan di `:38-45` dan
sudah dipakai query daftarnya di `:126`.

**Perbaikan untuk `pelepasan`** — ini butuh perubahan skema, dan alasannya penting:

View `v_pelepasan` (`db/migrasi_pelepasan_stok.sql:95-126`) **tidak punya kolom
`lokasi_rak_id` maupun `gudang_id` sama sekali**. Ia hanya menggabungkan `pelepasan_stok`
ke `barang`, `batch_barang`, `donatur`, `users`. Jadi tidak ada predikat lingkup yang bisa
dilekatkan ke query itu, bahkan kalau mau.

Perbaikan lengkapnya: simpan jangkar lokasi ke `pelepasan_stok` **saat tulis**, persis
seperti `donatur_id` sudah disimpan di sana dengan alasan yang sama — komentar di `:54`
berbunyi *"disalin dari batch agar tetap terlacak setelah batchnya hilang"*. Baris batch
sudah ada di memori di `lepas_stok()` (`_lib.php:839` memakai `SELECT *`), jadi tidak
perlu kueri tambahan:

```sql
ALTER TABLE `pelepasan_stok`
  ADD COLUMN `lokasi_rak_id` BIGINT(20) UNSIGNED NULL DEFAULT NULL
    COMMENT 'disalin dari batch agar tetap terlacak setelah batchnya hilang — sama seperti donatur_id'
    AFTER `donatur_id`,
  ADD KEY `ix_pelepasan_lokasi_rak` (`lokasi_rak_id`);
```

lalu tambahkan `$batch['lokasi_rak_id']` ke INSERT di `_lib.php:856-861`, masukkan kolom
itu ke `v_pelepasan`, dan saring kedua query di `api/pelepasan.php` serta `pelepasan.php`.

> **Catatan untuk baris lama.** Baris `pelepasan_stok` yang `batch_id`-nya sudah `NULL`
> tidak bisa di-backfill — datanya memang sudah hilang. Jumlahnya kemungkinan nol di
> produksi (satu-satunya jalur yang meng-NULL-kan adalah `data_contoh.php --hapus`, skrip
> CLI untuk data demo), tapi putuskan secara sadar mau diapakan: disembunyikan dari semua
> orang, atau hanya terlihat super admin.

---

## 2. Perbaiki berikutnya

### 2.1 `barang_form.php` dan `barang_hapus.php` — dua baris

Keduanya hanya memakai `require_role()`. Tidak ada `boleh_kelola_master()`, tidak ada cek
tingkat, tidak ada konsultasi `izin_pengguna`. Padahal **kelima saudara master-data
lain** — `kategori.php:12,19`, `supplier.php:18,29,80`, `toko.php:17,30,85`,
`rak.php:13,26,87,121`, `penerima.php:25,39` — semuanya memakainya, begitu juga jalur
popup-tambah di `barang.php:26,33`.

Akibatnya akun ber-tingkat `staff`, atau akun yang hak `kelola_master`-nya sudah dicabut,
tetap bisa mengubah dan menghapus katalog barang — yang **org-wide**, karena tabel
`barang` tidak punya kolom `gudang_id`.

```php
// barang_form.php, tepat setelah require_role()
if (!boleh_kelola_master($pdo)) { http_response_code(403); die('403'); }

// barang_hapus.php — pakai boleh_hapus(), karena penghapusan lebih sensitif
if (!boleh_hapus($pdo)) { http_response_code(403); die('403'); }
```

`_barang.php` tidak perlu diubah — ia memang sengaja mempercayai pemanggilnya.

### 2.2 `stok_asal.php` — satu baris

Flag `$boleh` diinisialisasi `true` di `:30`, lalu hanya dipersempit untuk `asal_tipe`
bernilai `gudang` dan `rak` (`:31-32`). Cabang untuk `toko` tidak pernah ditulis.

```php
if ($tipe === 'toko') { $boleh = in_array($id, array_map('intval', toko_saya($pdo)), true) || is_super(); }
```

Hanya peran `toko` yang terdampak nyata — empat peran lain memang sudah diberi visibilitas
penuh oleh desain (`_lib.php:2846`: *"Staf internal boleh melihat semua toko"*).

### 2.3 `api/penerimaan.php` — ganti default gagal-terbuka

`syarat_tujuan_saya()` (`:50-64`) bercabang untuk `toko` dan peran terikat gudang, lalu
**jatuh ke `return '1=1'`** untuk sisanya — termasuk `supplier`, yang tidak pernah
disebut. Komentarnya mengklaim cabang itu untuk Super Admin dan Pimpinan, tapi tidak ada
kode yang menegakkannya.

Bandingkan `api_syarat_perpindahan()` (`api/_lingkup.php:278-314`) yang menangani model
data sama dengan benar: ada cabang `supplier` eksplisit di `:296-298`, dan default-nya
**`1=0`**, bukan `1=1`.

```php
if ($role === 'supplier') {
    $sid = (int) ($p['supplier_id'] ?? 0);
    return $sid > 0 ? "({$a}asal_tipe = 'supplier' AND {$a}asal_id = $sid)" : '1=0';
}
if (in_array($role, ['super_admin', 'pimpinan'], true)) { return '1=1'; }
return '1=0';   // default gagal-tertutup
```

### 2.4 Kiriman supplier — predikat `gudang_tujuan_id`

Kolom `gudang_tujuan_id` ada di `pengiriman_supplier` (`db/instal_database.sql:426`) dan
dibaca untuk tampilan, tapi **tidak pernah dipakai sebagai predikat** di keempat statement
yang mengubah status: `api/kiriman.php:81-85`, `kiriman_supplier.php:32-33` dan `:39-40`,
`scan_resi.php:41-42`. Otorisasinya hanya berbasis peran.

Admin gudang A bisa menandai `tiba` atau membatalkan permanen kiriman untuk gudang B —
dan query daftarnya juga tidak menyaring, jadi tidak perlu menebak id.

Dampaknya terbatas: status ini **bukan** dokumen yang memindahkan stok, dan
`api/penerimaan.php:178` memvalidasi ulang lingkup tujuan di dalam transaksi sebelum stok
berubah. Tapi `dibatalkan` bersifat **terminal** — grep seluruh repo menemukan tepat lima
statement yang menulis status itu dan **tidak satu pun** mengembalikannya.

Tambahkan predikat gudang ke keempatnya, dan saring juga query daftarnya supaya staf
berhenti melihat kiriman di luar gudangnya.

**Celah terkait, akar berbeda:** `_perpindahan_form.php:487` hanya memeriksa
`ps.perpindahan_id IS NULL`, tidak pernah status — jadi kiriman yang sudah dibatalkan
masih bisa diproses lewat URL langsung meski tombolnya disembunyikan di
`kiriman_supplier.php:180`. Tambahkan cek status di query prefill itu.

---

## 3. Siklus hidup sesi dan akun

### 3.1 Tombol "Akhiri Sesi" tidak berlaku untuk sesi mobile

`perangkat.php:1-22` menjanjikan dua hal: akun dibatasi 3 perangkat aktif, dan login
keempat ditolak. Sisi web menegakkan keduanya. Sisi API **tidak menyentuh keduanya sama
sekali**:

- `api/login.php` menerbitkan token 7 hari tanpa memanggil `boleh_tambah_perangkat()`
  maupun `catat_perangkat()` — jadi tidak pernah ada baris `sesi_perangkat` untuk sesi
  mobile.
- `auth_required()` (`api/_bootstrap.php:41-97`) membaca ulang `is_active`, `deleted_at`,
  dan `role` setiap permintaan, tapi **tidak pernah** menanyakan `sesi_perangkat`.

Akibatnya dua hal yang terpisah: satu kredensial bisa memegang token mobile tak terbatas,
dan sesi itu **tidak pernah muncul** di halaman Perangkat untuk diakhiri admin. Komentar
di `_lib.php:5022-5031` menulis invariannya sendiri: tanpa pemeriksaan per-permintaan,
tombol Akhiri Sesi *"hanya menghapus baris di basis data sementara orangnya tetap bisa
bekerja — janji yang tidak ditepati."*

**Yang masih bekerja:** menonaktifkan akun sepenuhnya tetap mencabut token di permintaan
berikutnya, karena `auth_required()` membaca `is_active` tiap kali. Yang hilang adalah
kemampuan mengakhiri **satu** perangkat tanpa mengunci pengguna sah dari semua perangkatnya.

> ### ⚠️ Jebakan perbaikan: jangan salin `catat_perangkat()` apa adanya
>
> Jalur API **tidak pernah memanggil `session_start()`** — grep mengembalikan nol di
> seluruh `api/`. Jadi `sid_sekarang()` (`_lib.php:1263`) akan mem-hash `session_id()`
> yang **kosong dan identik setiap kali**. Lewat `ON DUPLICATE KEY UPDATE`, seluruh sesi
> mobile satu akun akan runtuh ke satu baris, dan `boleh_tambah_perangkat()` akan selalu
> melihat maksimal 1 perangkat — **batasnya justru mati diam-diam** padahal panggilannya
> sudah dipasang.
>
> API butuh identitas perangkatnya sendiri: terbitkan `device_id` acak saat login,
> sisipkan ke payload JWT, dan pakai `hash('sha256', 'api:' . $deviceId)` sebagai kunci —
> bukan `sid_sekarang()`.

### 3.2 Pengaman super admin terakhir — perbaiki di KEDUA permukaan

`api/pengguna.php` tidak punya padanan cek `jml_super_aktif()` yang dipakai web. Tapi
audit ini menemukan sesuatu yang lebih penting saat memverifikasinya:

**Cek di web pun sebenarnya tidak pernah menyala.** `jml_super_aktif()`
(`pengguna.php:43-50`) menghitung super admin aktif dengan `AND u.id <> ?`, dan dipanggil
dengan id **target** di ketiga tempat (`:139`, `:294`, `:321`) — **tidak pernah dengan id
pelaku**. Karena pelaku wajib super admin aktif untuk sampai ke sana, barisnya sendiri
selalu ikut terhitung, sehingga untuk aksi terhadap super admin lain hitungannya tidak
akan pernah mencapai nol.

Artinya menyalin fungsi itu apa adanya ke API **tidak menutup apa pun**. Perbaikannya:

```php
function jml_super_aktif_total(PDO $pdo): int {
    return (int) $pdo->query(
        "SELECT COUNT(*) FROM users u JOIN roles r ON r.id = u.role_id
         WHERE r.nama = 'super_admin' AND u.is_active = 1 AND u.deleted_at IS NULL"
    )->fetchColumn();
}
// Sebelum perubahan apa pun yang mengeluarkan target dari kumpulan super admin aktif,
// syaratkan jml_super_aktif_total($pdo) > 1. Terapkan di web DAN di API.
```

Tambahkan juga cek diri-sendiri di `PUT /api/pengguna.php`, yang sisi web sudah punya di
`:135-138`.

### 3.3 Race pada dua aksi persetujuan — perlu diuji dulu

`api/persetujuan.php` aksi `kirim` (`:153-163`) dan `tolak` (`:196-208`) memakai satu
`SELECT` **tanpa kunci dan tanpa transaksi** di `:143-146`, lalu menulis
`UPDATE ... WHERE id=?` **tanpa predikat status** dan tanpa cek `rowCount()`. Grep untuk
`beginTransaction` di berkas itu mengembalikan nol.

Sisi web melakukannya dengan benar untuk kedua aksi yang sama — `persetujuan.php:61-64`
dan `:146-151` memakai `WHERE id=? AND status=...` plus cek `rowCount()`. Dan aksi
`setujui`/`batal`/`hapus` di API yang sama juga benar, karena mendelegasikan ke fungsi
yang melakukan `SELECT ... FOR UPDATE` di dalam transaksinya sendiri.

Perbaikannya mengikuti pola yang sudah ada di kode: tambahkan predikat status ke `UPDATE`
dan periksa `rowCount()`. **Tapi sebaiknya reproduksi dulu** — lihat bagian 6, karena
audit ini tidak bisa menguji konkurensi tanpa basis data.

---

## 4. Perbaikan akar — yang mencegah ini terulang

Delapan dari sembilan temuan di atas adalah gejala dari satu hal yang sama: **web dan API
menduplikasi aturan otorisasi alih-alih membagikannya.**

Yang paling menohok, sisi web **sudah tahu** bahayanya. Komentar `gudang_saya()` di
`_lib.php:2697` berbunyi:

> *"Cabang di bawah mengembalikan SELURUH gudang. Peran apa pun yang seharusnya terikat
> satu gudang tapi tidak disebut di sini akan jatuh ke sana dan diam-diam mendapat akses
> ke semua gudang — jadi yang diperiksa `terikat_satu_gudang()`, bukan satu nama peran."*

`api_gudang_id()` jatuh tepat ke dalam jebakan itu. Pengetahuannya ada di satu sisi dan
tidak tersalin ke sisi lain.

Dua langkah proses yang nilainya lebih besar dari patch mana pun:

**1. Daftar periksa paritas web↔API.** Setiap kali satu permukaan mendapat perbaikan
otorisasi, kembarannya diperiksa di PR yang sama. Tiga temuan di dokumen ini adalah
perbaikan yang **sudah pernah dilakukan** tapi tidak disapukan ke kembarannya —
`api/perpindahan.php` dan `api/dashboard.php` sudah benar, `api/keluar.php`,
`api/masuk.php`, `api/rak.php`, `api/gudang.php` terlewat.

**2. Ubah default dari gagal-terbuka ke gagal-tertutup.** `api_gudang_id()` mengembalikan
`null` yang diartikan "tak terbatas"; `syarat_tujuan_saya()` jatuh ke `1=1`;
`stok_asal.php` menginisialisasi flag-nya `true`. Bandingkan `syarat_lingkup()` dan
`api_syarat_perpindahan()` yang default-nya `1=0` — itu polanya, dan kode ini sudah
memilikinya di tempat lain.

Satu pengujian murah yang menangkap seluruh kelas ini: untuk tiap endpoint, pastikan ada
kasus uji yang login sebagai peran **paling rendah** yang bisa mencapainya dan menegaskan
respons **kosong atau 403**, bukan sekadar "tidak error".

---

## 5. Yang diperiksa dan terbukti AMAN

Bagian ini sama pentingnya dengan daftar temuan — supaya tidak ada yang melaporkan ulang
hal yang sudah diperiksa, dan supaya terlihat apa yang sudah dikerjakan dengan baik.

**Kelas serangan klasik — nihil:**

| Kelas | Hasil |
|---|---|
| SQL injection | **Nihil.** Setiap `ORDER BY` dinamis lewat whitelist statis; setiap `IN(...)` di-cast `(int)` atau di-`quote()`; `LIMIT`/`OFFSET` selalu dari `paginasi_hitung()` atau `paginate()`; nama tabel dari array definisi kode |
| XSS | **Nihil.** Helper `e()` di 1.484 titik. Grep untuk echo langsung `$_GET`/`$_POST`/`$_SERVER`/`$_COOKIE` mengembalikan **nol**. Flash message di-escape di satu-satunya titik render (`_header.php:313`) |
| Breakout `</script>` | **Mustahil.** `json_encode` meng-escape `/` jadi `\/` kecuali `JSON_UNESCAPED_SLASHES` diberikan — dan satu-satunya pemakaian flag itu (`api/_bootstrap.php:27`) adalah respons JSON murni, bukan HTML |
| Path traversal | **Nihil.** `terima_gambar()` (`_lib.php:1419-1476`) memvalidasi lewat `getimagesize()` bukan ekstensi, menolak SVG, dan nama berkasnya `bin2hex(random_bytes(8))` — nama asli **tidak pernah** menyentuh filesystem. `cadangan_nama_sah()` regex-nya berjangkar penuh |
| SSRF | **Nihil.** Semua host tujuan konstanta `define()`. TLS di-pin eksplisit di kedua klien marketplace |
| Open redirect | **Nihil.** Semua `Location:` memakai nama skrip hardcoded |
| CSRF | **Lengkap.** 39 berkas memanggil `wajib_csrf()`; 8 berkas lain yang memproses POST diperiksa satu per satu dan semuanya tercakup lewat jalur lain |

**Kontrol yang diverifikasi bekerja benar:**

- **JWT.** `jwt_decode()` tidak pernah membaca `alg` dari header — selalu menghitung ulang
  HMAC-SHA256 dan membandingkan dengan `hash_equals`. Dua kesalahan JWT paling umum
  dihindari sekaligus.
- **Token hanya membawa identitas.** `auth_required()` membaca ulang peran, status, dan
  lingkup dari DB **setiap permintaan**. Akun yang dinonaktifkan kehilangan akses di
  permintaan berikutnya.
- **Wildcard CORS aman di sini.** Nol `Access-Control-Allow-Credentials` dan **nol
  pembacaan `$_SESSION`** di seluruh `api/` — diverifikasi tiga kali. Jalur API secara
  struktural tidak bisa jatuh ke identitas berbasis sesi.
- **Klaim di `dasbor_toko.php:14-15` benar.** Diuji, bukan diterima: lingkup toko
  diverifikasi ulang di waktu baca **dan** di waktu tulis lewat `wajib_akses_toko()`.
  Untuk akun supplier, parameter `?supplier=` bahkan **tidak pernah dibaca sama sekali**.
- **`tolak_aksi_pengawasan()`** adalah gate tunggal di awal penanganan POST, bukan
  per-cabang — otomatis menutup ketujuh aksi di `dasbor_toko.php` dan keempat di
  `dasbor_supplier.php`, termasuk aksi yang ditambahkan nanti.
- **`dashboard.php` tidak punya bug fallthrough.** Baris 23 mengalihkan peran eksternal
  **sebelum** variabel lingkup apa pun dihitung.
- **Matematika stok bersih.** `FOR UPDATE` di `ambil_fefo()`, urutan penguncian terurut
  lewat `kunci_stok()`, qty di-clamp di setiap pemanggil. Tidak ada double-spend, dan qty
  negatif tidak bisa menambah stok.
- **Pola *balik-lalu-terapkan-ulang*** di `perpindahan_ubah.php` benar: satu transaksi,
  dokumen dikunci `FOR UPDATE` sepanjang keduanya, dan kegagalan di tahap kedua
  membatalkan tahap pertama juga. Stok tidak bisa hilang.
- **`dokumen_masuk_boleh_ubah()`** memverifikasi wewenang terhadap baris detail **asli
  dari database**, bukan id yang dikirim pemanggil.
- **Tidak ada fitur upload/restore cadangan** sama sekali — tidak ada permukaan serangan restore.
- **`migrasi.php` tidak bisa menjalankan `instal_database.sql`** yang destruktif.
- **`reset_data.php` dan `data_contoh.php` CLI-only**, gate-nya pernyataan pertama yang dieksekusi.
- **`cek_sistem.php` mati secara default** — `$KUNCI_CEK === ''` adalah suku pertama dalam OR.
- **`impor_pesanan.php` bersih.** Toko target diikat lewat `wajib_akses_toko()` **sebelum**
  CSV dibuka; FEFO di-scope ke batch toko itu saja.
- **`cek_surat.php` bukan IDOR** — memeriksa ulang `boleh_lihat_perpindahan()` sebelum mengungkap isi.
- **Rate limiter** berbasis tabel (bukan sesi), memakai `REMOTE_ADDR` yang tidak bisa
  dipalsukan, dan mencatat kegagalan **tanpa syarat** — sehingga probing akun tak-ada pun
  ikut dibatasi.

---

## 6. Butuh jawaban tim — bukan dari kode

Delapan hal berikut jejak kodenya nyata, tapi satu fakta penentunya ada di luar
repositori. **Tidak diberi severity** — memberinya angka akan menyiratkan kepastian yang
tidak ada.

| Hal | Yang perlu dipastikan | Caranya |
|---|---|---|
| Hash sandi 5 akun ter-commit di `db/instal_database.sql:1322-1326` | Repo GitHub publik atau privat? | Lihat indikator visibilitas repo + peringatan secret-scanning. **Terlepas hasilnya**, rotasi kelima sandi lewat Pengaturan › Pengguna |
| `sesi/`, `db/`, `cadangan_berkas/`, `docs/` hanya dilindungi `.htaccess` | Apakah hosting menghormati `AllowOverride`? | **Tanya penyedia hosting tertulis**, pakai checklist milik proyek sendiri di `SPESIFIKASI_HOSTING.md` bagian E butir 4 |
| Reklasifikasi `jenis` gudang menggeser batas kepercayaan toko | Ada akun toko tanpa `user_gudang`? Gudang yang dikonversi sudah berisi stok? | Periksa ekspor basis data read-only |
| Impor CSV mencocokkan kode rak lintas gudang **dan** lintas status hapus | Deployment ini jalan >1 gudang dengan rak terisi? | Daftar kode rak per gudang dan status hapus |
| Race pada `kirim`/`tolak` di API persetujuan | Jendela konkurensinya benar-benar bisa terjadi? | Harness dua koneksi PDO di DB sekali pakai — detail di `NEEDS-VALIDATION.md` |
| Kunci cron hidup permanen di query string | Log akses hosting mencatat query string penuh? Siapa yang bisa baca? | Periksa format log, siapa yang bisa membacanya, dan apakah perintah cron mengalihkan stderr |
| Ekspor CSV tidak menetralkan karakter formula | Perangkat lunak spreadsheet apa yang staf pakai? | Tanyakan perangkat lunak, versi, dan apakah staf terbiasa mengklik lewat prompt keamanan |
| Saluran APK tanpa checksum | Produksi pernah melayani origin ini tanpa enkripsi? | Periksa setelan force-HTTPS di panel hosting atau baca vhost terpasang |

> ### ⚠️ Jangan menguji dua hal ini dengan membukanya di browser
>
> Untuk direktori terlindungi dan endpoint versi: **jangan** mengirim permintaan ke
> sistem produksi untuk mengujinya. Kalau celahnya nyata, permintaan itu justru akan
> **mengirimkan hash sandi, dump basis data, dan isi sesi hidup melewati jaringan** —
> ujinya sendiri menyebabkan kebocoran yang hendak dideteksi. Pastikan lewat pemeriksaan
> konfigurasi saja.

---

## 7. Yang DIBANTAH — jangan dilaporkan ulang

Enam kandidat gugur setelah verifikasi independen. Dicatat supaya audit berikutnya tidak
mengulang klaim yang sama tanpa bukti baru.

| Klaim | Kenapa gugur |
|---|---|
| Oracle timing di login (`login.php:65`, `api/login.php:68`) | Nyata secara teknis, tapi rate limiter **tidak** dikalahkan — `pembatas_login_gagal()` dipanggil tanpa syarat sehingga probing akun tak-ada ikut dibatasi. Pesan galat identik. Alamat yang bisa disimpulkan sudah mudah ditebak |
| View UNION `v_stok_rantai`/`v_expired_rantai` memaksa materialisasi penuh | Benar, tapi `super_admin` dan `pimpinan` mendapat `'1=1'` — peran **paling tinggi** sudah memicu komputasi penuh yang sama. Tidak ada korelasi biaya dengan privilese, jadi tidak ada amplifikasi. Ini karakteristik performa |
| `cron_cadangan.php?paksa=1` tanpa cooldown | `super_admin` yang sama sudah bisa memicu `cadangan_buat()` tanpa throttle lewat tombol UI, tanpa kunci apa pun. Prinsipal sama, kapabilitas sama. `cadangan_rapikan()` juga sudah membatasi pertumbuhan disk |
| Kunci `cek_sistem.php` di query string | Gate-nya kokoh, kunci kosong secara default di **setiap** konfigurasi yang dikirim repo, dan berkas itu sendiri sudah menginstruksikan mengosongkannya kembali setelah pemasangan |
| Jangkar lingkup hilang untuk baris `pelepasan_stok` ber-`batch_id` NULL | Mengkritik kelengkapan perbaikan yang **belum ada**. Satu-satunya pemicunya skrip data demo CLI. Isinya dipertahankan sebagai catatan di bagian 1.3 |
| Inversi urutan kunci di `ubah_dokumen_masuk()` | Inversinya **nyata** — tapi akibatnya hanya InnoDB membatalkan satu dari dua transaksi staf yang sama-sama berwenang, lalu di-rollback bersih. Tidak ada batas yang dilintasi. **Tetap layak diperbaiki** di backlog kebenaran: urutkan kunci dokumen sebelum stok, menyamai empat fungsi saudaranya |

---

## 8. Catatan hardening — bukan temuan

- `_cadangan.php:312-323` `gdrive_http()` tidak menyetel `CURLOPT_SSL_VERIFYPEER`/
  `VERIFYHOST`, berbeda dari kedua klien marketplace. Default cURL adalah verifikasi
  aktif, jadi bukan kerentanan — tapi fungsi itu membawa `client_secret` dan
  `refresh_token` Google, dan konsistensi layak dipulihkan.
- Respons token marketplace mentah disimpan ke `marketplace_tarikan.data_mentah` termasuk
  token dalam teks biasa. Tidak ada prinsipal di bawah `super_admin` yang bisa membacanya,
  jadi bukan pelanggaran batas — tapi pertimbangkan meredaksi field token sebelum menulis.
- Cadangan penuh memuat **setiap** rahasia sistem. Itu sifat wajar dump penuh, tapi
  berarti berkas di `cadangan_berkas/` adalah artefak paling sensitif yang dimiliki
  yayasan dan layak diperlakukan demikian.
- Situs `<script>` inline aman dari breakout hanya karena tidak satu pun memakai
  `JSON_UNESCAPED_SLASHES` — invarian implisit yang mudah patah. `JSON_HEX_TAG |
  JSON_HEX_APOS` membuatnya eksplisit.
- `url_penuh()` (`_lib.php:1384-1401`) menyaring karakter `HTTP_HOST` tapi tidak
  membandingkannya dengan domain kanonik. Tidak ada eksploitasi terjangkau, tapi
  `marketplace.php:26-28` menduplikasinya **tanpa filter sama sekali** dan sebaiknya
  memanggil `url_penuh()` saja.
- `pengaturan_aplikasi.php:33` menerima `apk_url` berskema `http://`. Perketat ke `^https://`.
- `periksa_qty_wajar()` (`_lib.php:565-571`) hanya menegakkan batas atas dan mengandalkan
  setiap pemanggil meng-clamp qty positif. Semua pemanggil saat ini benar, tapi invariannya
  sebaiknya tidak bergantung pada ingatan tiap call site.
- Donatur yang di-soft-delete tetap tampil namanya di layar provenance. Ini **keputusan
  produk yang terdokumentasi**, bukan bug — `donatur.php:514` memberi tahu operator bahwa
  riwayat tetap tersimpan, dan pengembang terbukti tahu cara menambahkan filter itu karena
  memakainya di `v_peringkat_donatur`. Mekanisme yang benar untuk permintaan privasi
  donatur adalah flag `anonim`, bukan hapus. Pertimbangkan menambahkan petunjuk itu di
  dialog hapus.

---

## Lampiran — urutan pengerjaan yang disarankan

| Urutan | Pekerjaan | Perkiraan |
|---|---|---|
| 1 | Pasang `izin_pengguna` ke API (bagian 1.1) | Menutup 2 temuan sekaligus |
| 2 | `api/rak.php` + `api/gudang.php`, **dua tempat** (bagian 1.2) | Hati-hati pada peringatan |
| 3 | `api/keluar.php` + `api/masuk.php` (bagian 1.3) | Pola sudah ada di `api/perpindahan.php` |
| 4 | Jangkar lokasi `pelepasan_stok` + 2 query (bagian 1.3) | Butuh migrasi skema |
| 5 | `barang_form.php` + `barang_hapus.php` (bagian 2.1) | 2 baris |
| 6 | `stok_asal.php` (bagian 2.2) | 1 baris |
| 7 | `api/penerimaan.php` default gagal-tertutup (bagian 2.3) | ~6 baris |
| 8 | Predikat gudang di kiriman supplier (bagian 2.4) | 4 statement + query daftar |
| 9 | Kuota & pencabutan sesi API (bagian 3.1) | **Baca jebakannya dulu** |
| 10 | Logika hitung super admin, kedua permukaan (bagian 3.2) | |

Sepuluh butir pertama semuanya perubahan kode. Delapan hal di bagian 6 bisa dikerjakan
paralel karena tidak menunggu kode — dan dua di antaranya bisa diselesaikan hari ini.

---

*Dokumen ini sengaja berorientasi perbaikan: berisi apa yang salah, di baris berapa, dan
patch-nya — tanpa resep eksploitasi atau string payload, karena berkas ini ikut ter-commit.
Bukti lengkap, jejak verifikasi per kandidat, dan rencana validasi terperinci ada di luar
repositori. Tidak ada data donatur, penerima, hash sandi, atau alamat email yang
direproduksi di mana pun dalam dokumen ini.*
