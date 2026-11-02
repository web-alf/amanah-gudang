# Rencana Perbaikan Keamanan — Rincian Implementasi

**Pendamping `AUDIT_KEAMANAN_2026-10-29.md` — 29 Oktober 2026**

Dokumen itu menjawab *apa yang salah dan kenapa*. Dokumen ini menjawab *bagaimana
mengerjakannya*: urutan sprint, langkah per item, kriteria lulus uji, risiko rollout,
dan Definition of Done. Nomor baris dan nama fungsi merujuk ke bagian yang sama di
`AUDIT_KEAMANAN_2026-10-29.md` — baca itu dulu kalau belum.

## Cara pakai dokumen ini

Setiap item kerja punya enam bagian tetap: **Prioritas & estimasi**, **Bergantung
pada**, **Langkah**, **Kriteria lulus uji**, **Risiko rollout**, **Definition of
Done**. Centang DoD di PR masing-masing; jangan gabung item dari sprint berbeda ke
satu PR kecuali disebutkan boleh digabung.

---

## Peta sprint

| Sprint | Fokus | Item | Kenapa dikelompokkan begini |
|---|---|---|---|
| **1** | Kebocoran lintas-gudang bernilai tinggi | P1–P4 | Severity tinggi, berkas API saja, tidak saling bergantung |
| **2** | Siklus hidup akun & sesi | P5–P6 | Butuh pemahaman yang sama (identitas perangkat, hitung super admin); jebakannya mirip |
| **3** | Kapabilitas & lingkup sisa | P7–P9 | Severity sedang/rendah, satu–dua baris per item, bisa paralel |
| **4** | Migrasi skema | P10 | Butuh migrasi DB — pisahkan dari sprint berisiko rendah supaya rollback-nya jelas |
| **5** | Perlu reproduksi dulu | P11 | Race condition — buktikan dengan harness sebelum menulis fix, supaya fix-nya menutup kondisi yang benar |
| **Paralel, non-teknis** | Jawaban tim | — | 8 butir di bagian 6 `AUDIT_KEAMANAN_2026-10-29.md`, bisa dikerjakan kapan saja, tidak menunggu kode |

Sprint 1–3 bisa dikerjakan satu per satu oleh satu orang dalam waktu yang wajar untuk
basis kode ini. Sprint 4 dan 5 masing-masing butuh jendela terpisah karena
konsekuensinya berbeda sifat (migrasi data vs. bukti konkurensi).

---

## Sprint 1 — Kebocoran lintas-gudang

### P1. Pasang `izin_pengguna` ke API

*Menutup: pencabutan hak tidak berlaku di API, `pimpinan` bisa menulis lewat API.*

**Prioritas & estimasi.** Tinggi · 3–4 jam. Satu perbaikan menutup dua temuan —
kerjakan ini lebih dulu dari yang lain di sprint ini.

**Bergantung pada.** Tidak ada.

**Langkah.**

1. Tambahkan `api_boleh_kelola_master()` dan `api_boleh_hapus()` di
   `api/_lingkup.php`, persis seperti kode di `AUDIT_KEAMANAN_2026-10-29.md` bagian 1.1.
2. Di `api/kategori.php:34,49`: ganti `in_array($payload['role'], [...])` dengan
   `api_boleh_kelola_master($pdo, $payload)`. **Hapus `'pimpinan'` dari array itu** —
   itu memang isi perbaikannya.
3. Ulangi untuk `api/master.php:170,173`, `api/donatur.php:47-48,78`,
   `api/pelepasan.php:32-33`.
4. `api/barang.php` **tidak perlu** menghapus `pimpinan` — sudah benar tidak
   menyertakannya. Tambahkan saja panggilan `api_boleh_hapus()` di `:459` menggantikan
   cek tingkat manual yang ada sekarang, supaya pencabutan `hapus` ikut dihormati.
5. Cari sisa pemanggilan manual `in_array($payload['role'], ...)` untuk keputusan
   tulis/hapus di seluruh `api/` — pastikan tidak ada yang terlewat.

**Kriteria lulus uji.** Untuk tiap endpoint yang diubah, dengan DB berisi data uji:

- Akun `admin_gudang` tingkat `staff`, tanpa override `izin_pengguna` → POST/PUT
  ditolak 403 (sebelumnya: diterima).
- Akun `admin_gudang` tingkat `manager`, dengan `izin_pengguna.kelola_master = 0`
  eksplisit → POST/PUT ditolak 403 (ini kasus pencabutan — yang paling penting diuji).
- Akun `pimpinan` → POST/PUT ditolak 403 di `kategori`, `master`, `donatur`, `pelepasan`.
- Akun `admin_gudang` tingkat `manager`, tanpa override → POST/PUT tetap **diterima**
  (regresi — pastikan jalur yang sah tidak ikut tertutup).

**Risiko rollout.** Rendah — murni perubahan logika otorisasi, tidak menyentuh skema
atau data. Risiko utama adalah **lupa satu endpoint** dalam langkah 5; mitigasinya
grep `in_array($payload\['role'\]` setelah selesai dan tinjau tiap hasilnya.

**Definition of Done.**

- [ ] Dua helper baru ditambahkan dan diuji unit terpisah (cakup: role bukan manager,
      tingkat staff, override 0, override 1, tanpa override)
- [ ] Kelima berkas endpoint diubah, `pimpinan` dihapus dari semua allow-list tulis/hapus
- [ ] Empat skenario di atas diuji manual dan hasilnya sesuai
- [ ] Grep `in_array($payload['role']` ditinjau, tidak ada sisa yang terlewat
- [ ] Dicatat di `CATATAN_REVISI_<tanggal berikutnya>.md`

---

### P2. `api/rak.php` + `api/gudang.php` — dua guard, bukan satu

*Menutup: `api_gudang_id` null-unscoped — temuan tinggi tertinggi di seluruh audit.*

**Prioritas & estimasi.** Tinggi · 2–3 jam, termasuk pengujian manual karena ada dua
guard independen yang harus diperbaiki bersamaan.

**Bergantung pada.** Tidak ada. **Tapi jangan dikerjakan sambil terburu-buru** — ini
item di mana rekomendasi awal audit sendiri hampir tidak menutup kerentanannya.

**Langkah.**

1. Di `api/gudang.php`, ganti blok `$gidSaya`/`$pagar` di `:56-64` dengan kode
   `api_in_gudang()` dari `AUDIT_KEAMANAN_2026-10-29.md` bagian 1.2.
2. **Verifikasi eksplisit**: grep `$gidSaya` di seluruh `api/gudang.php` setelah
   perubahan. Harus **nol** sisa pemakaian — kalau masih ada, berarti ada cabang yang
   terlewat (kemungkinan besar cabang `isi=stok` di `:146-218` atau blok rincian
   `:221-346`).
3. Ulangi untuk `api/rak.php:50-64` dan `:140-160`. Grep `$gidSaya` di berkas itu juga
   harus nol.
4. Ubah cek `?id=` dari kesetaraan (`$id !== $gidSaya`) menjadi keanggotaan
   (`!in_array($id, $lingkupSaya, true)`) — ini bukan sekadar mengikuti pola lama,
   karena akun `toko` bisa sah terikat ke lebih dari satu gudang.

**Kriteria lulus uji.** Login sebagai akun `toko` yang terikat ke Gudang A saja:

- `GET /api/gudang.php` (tanpa id) → hanya Gudang A muncul di daftar (sebelumnya:
  semua gudang).
- `GET /api/gudang.php?id=<Gudang B>` → 403 (sebelumnya: 200 dengan alamat, nama
  penanggung jawab, telepon).
- `GET /api/gudang.php?id=<Gudang B>&isi=stok` → 403 (sebelumnya: 200 dengan
  inventaris penuh — **ini cabang yang paling mudah terlewat, uji secara eksplisit**).
- `GET /api/rak.php?id=<rak milik Gudang B>` → 403.
- Login sebagai akun `supplier` → ulangi keempat uji di atas, hasil sama.
- Login sebagai `super_admin` → semua tetap 200 dengan data lengkap (regresi).

**Risiko rollout.** Rendah-sedang. Perubahan murni otorisasi baca, tidak ada tulis
yang terdampak. Risiko nyatanya adalah regresi fungsional untuk akun `toko` yang sah
— kalau `api_in_gudang()` salah menghitung lingkupnya, toko bisa kehilangan akses ke
gudangnya sendiri. Uji dengan akun toko yang **benar-benar** terikat sebelum deploy.

**Definition of Done.**

- [ ] `$gidSaya` nol sisa pemakaian di kedua berkas (dicek dengan grep, bukan diingat)
- [ ] Cabang `isi=stok` diuji eksplisit, bukan cuma cabang daftar
- [ ] Keempat skenario 403 di atas diuji manual dengan akun `toko` dan `supplier`
- [ ] Regresi `super_admin` diuji — tetap melihat semua gudang
- [ ] Dicatat di `CATATAN_REVISI_<tanggal berikutnya>.md`, sebut eksplisit bahwa ini
      memperbaiki kedua guard bukan hanya satu

---

### P3. `api/keluar.php` — predikat lingkup di cabang detail

**Prioritas & estimasi.** Tinggi · 1 jam. Patch siap pakai di
`AUDIT_KEAMANAN_2026-10-29.md` bagian 1.3.

**Bergantung pada.** Tidak ada.

**Langkah.** Tempelkan cek `$cek` persis setelah `$header` diambil, sebelum detail
dikembalikan. Pola ini meniru `api/perpindahan.php:17-33` — baca fungsi itu sekali
sebelum menulis, supaya pesan error dan struktur 404-nya konsisten.

**Kriteria lulus uji.** Akun `admin_gudang` Gudang A:

- `GET /api/keluar.php?id=<transaksi Gudang B>` → 404 "Transaksi tidak ditemukan."
  (sama persis dengan pesan untuk id yang benar-benar tidak ada — jangan sampai
  pesannya berbeda, karena itu membuka oracle keberadaan baru).
- `GET /api/keluar.php?id=<transaksi Gudang A>` → 200, data lengkap (regresi).
- `GET /api/keluar.php?id=999999` (id tidak ada) → 404, pesan identik dengan kasus
  pertama.

**Risiko rollout.** Rendah. Satu cabang, satu kondisi tambahan, tidak menyentuh tulis.

**Definition of Done.**

- [ ] Pesan 404 untuk id-di-luar-lingkup dan id-tidak-ada **identik** (dicek
      byte-by-byte, bukan sekilas)
- [ ] Regresi akun pemilik gudang diuji
- [ ] Dicatat di catatan revisi

---

### P4. `api/masuk.php` — sama seperti P3

**Prioritas & estimasi.** Tinggi · 1 jam. Identik strukturnya dengan P3, bisa
dikerjakan langsung setelahnya dalam satu sesi — tapi **PR terpisah**, supaya kalau
satu perlu di-revert yang lain tidak ikut.

**Bergantung pada.** Tidak ada — independen dari P3 meski polanya sama.

**Langkah.** Sama seperti P3, pakai `rak_boleh()` yang sudah didefinisikan di
`:38-45` dan sudah dipakai query daftar di `:126`.

**Kriteria lulus uji.** Sama seperti P3, ditambah: pastikan field `harga` dan
`subtotal` di respons juga ikut tertutup untuk id di luar lingkup (bukan cuma field
donatur) — ini yang membedakan dampaknya dari `keluar.php`.

**Risiko rollout.** Rendah, sama seperti P3.

**Definition of Done.** Sama seperti P3.

---

## Sprint 2 — Siklus hidup akun & sesi

### P5. Identitas perangkat API + kuota + pencabutan sesi

*Menutup: kuota 3 perangkat tidak berlaku di API, tombol Akhiri Sesi tidak berlaku
untuk mobile.*

**Prioritas & estimasi.** Sedang · 4–6 jam. Lebih besar dari item lain karena butuh
kolom baru dan menyentuh dua berkas inti (`api/login.php`, `api/_bootstrap.php`).

**Bergantung pada.** Tidak ada secara teknis, tapi kerjakan **setelah** memahami
penuh jebakannya di bawah — item ini satu-satunya di seluruh rencana yang kalau
diimplementasikan "dengan cara paling jelas" justru mematikan kontrol yang sedang
diperbaiki, secara diam-diam.

> ### ⚠️ Baca ini sebelum menulis kode
>
> Jalur API **tidak pernah** memanggil `session_start()`. Kalau `catat_perangkat()`
> dipanggil apa adanya dari `api/login.php`, `sid_sekarang()` akan mem-hash
> `session_id()` yang kosong — **nilai yang sama persis setiap kali**. Lewat
> `INSERT ... ON DUPLICATE KEY UPDATE`, setiap login API akan menimpa baris
> `sesi_perangkat` yang sama, sehingga `boleh_tambah_perangkat()` **selalu**
> melihat tepat 1 perangkat — berapa pun token yang sebenarnya aktif. Kuota jebol
> secara diam-diam, dan baris uji manual Anda ("login 4 kali, keempat ditolak")
> akan **lulus** padahal implementasinya salah, karena pengujian manual satu per
> satu tidak memicu tabrakan kunci yang sama.

**Langkah.**

1. Migrasi skema: tambahkan kolom `device_id` (string, nullable) ke payload JWT —
   **bukan** ke tabel, karena nilainya hidup di token, bukan di DB. Lihat butir 3.
2. Di `api/login.php`, sebelum `jwt_encode()`:
   ```php
   [$bolehMasuk, $perangkatHidup] = boleh_tambah_perangkat($pdo, (int) $user['id']);
   if (!$bolehMasuk) {
       err('Akun ini sudah aktif di ' . count($perangkatHidup) . ' perangkat.', 409);
   }
   $deviceId = bin2hex(random_bytes(16));
   ```
3. Tambahkan fungsi baru di `_lib.php` — **jangan pakai `sid_sekarang()`**:
   ```php
   function sid_dari_perangkat_api(string $deviceId): string {
       return hash('sha256', 'api:' . $deviceId);
   }
   ```
   lalu panggil `catat_perangkat()`-equivalent dengan hash ini sebagai kunci,
   bukan `sid_sekarang()`.
4. Sisipkan `'device_id' => $deviceId` ke payload sebelum `jwt_encode()`.
5. Di `api/_bootstrap.php`, dalam `auth_required()`, setelah validasi akun yang
   sudah ada: baca `$payload['device_id']`, hitung hash yang sama
   (`sid_dari_perangkat_api()`), query `sesi_perangkat.diakhiri_at` untuk hash
   itu. Kalau `diakhiri_at` terisi → tolak 401 "Sesi ini sudah diakhiri."
6. Perbarui `perangkat.php` dan `api/profil.php?sub=perangkat` supaya baris yang
   berasal dari login API ikut tampil di daftar — sebelumnya tidak ada baris sama
   sekali untuk sesi mobile, sekarang harus ada.

**Kriteria lulus uji — WAJIB dengan dua token aktif sekaligus, bukan satu per satu.**

- Login API 4 kali berturut-turut (akun dengan kuota default 3). Simpan keempat
  token. Login ke-4 harus ditolak 409 **sebelum** mengubah apa pun.
- Dengan 3 token yang berhasil didapat, cek `GET /api/profil.php?sub=perangkat`
  (atau `perangkat.php` di web untuk akun yang sama) — **harus menampilkan 3 baris
  berbeda**, bukan 1. Ini uji yang menangkap jebakan `sid_sekarang()` kalau
  implementasinya salah.
- Akhiri salah satu sesi lewat `perangkat.php`. Panggil API dengan token milik
  sesi yang baru diakhiri itu → 401. Panggil API dengan **token lain** yang masih
  aktif → tetap 200 (regresi — pastikan mengakhiri satu sesi tidak ikut mematikan
  yang lain).
- Nonaktifkan seluruh akun lewat Kelola Pengguna. Semua token akun itu → 401 di
  permintaan berikutnya (kontrol lama yang sudah bekerja — pastikan tidak rusak).

**Risiko rollout.** Sedang. Token yang **sudah diterbitkan sebelum perubahan ini**
tidak akan punya `device_id` di payload-nya — pastikan kode baru menangani
`$payload['device_id']` yang `null` dengan aman (lewati pengecekan pencabutan,
jangan error) supaya pengguna yang sedang login tidak mendadak logout massal saat
deploy. Token lama akan otomatis dapat perlindungan baru begitu mereka login ulang
dalam 7 hari ke depan (masa berlaku token).

**Definition of Done.**

- [ ] Uji "3 token berbeda tampil sebagai 3 baris" lulus — ini satu-satunya cara
      membuktikan jebakan `sid_sekarang()` tidak terjadi
- [ ] Uji akhiri-satu-sesi-tidak-pengaruhi-sesi-lain lulus
- [ ] Token lama (tanpa `device_id`) tidak menyebabkan error atau logout massal
- [ ] `perangkat.php` dan `api/profil.php?sub=perangkat` menampilkan sesi mobile
- [ ] Dicatat di catatan revisi dengan penekanan khusus pada perubahan skema JWT
      (device_id baru di payload) — ini mengubah kontrak token

---

### P6. Logika hitung super admin — kedua permukaan sekaligus

**Prioritas & estimasi.** Informasional di laporan audit, tapi **jangan diabaikan** —
Sedang · 2 jam. Kerjakan setelah P5 karena sama-sama menyentuh alur akun.

**Bergantung pada.** Tidak ada.

**Langkah.**

1. Tambahkan `jml_super_aktif_total()` di `_lib.php` (kode di
   `AUDIT_KEAMANAN_2026-10-29.md` bagian 3.2) — hitung **tanpa** klausa `id <> ?`.
2. Di `pengguna.php`, ganti ketiga pemanggilan `jml_super_aktif($pdo, $id)`
   (`:139`, `:294`, `:321`) dengan pola: *ambil total sebelum perubahan*, *tentukan
   apakah perubahan ini akan mengeluarkan akun dari kumpulan aktif*, *kalau ya dan
   total sebelumnya hanya 1, tolak*.
3. Tambahkan cek yang sama di `PUT /api/pengguna.php` dan `DELETE /api/pengguna.php`.
4. Tambahkan cek diri-sendiri di `PUT /api/pengguna.php` — sisi web sudah punya di
   `:135-138`, API belum punya sama sekali.

**Kriteria lulus uji.** Buat 2 akun `super_admin` di lingkungan uji:

- Sebagai Admin A, coba turunkan peran Admin B lewat **web** → berhasil (karena
  masih ada Admin A yang aktif setelahnya).
- Sebagai Admin A, coba turunkan peran Admin B **lagi**, kali ini saat Admin A
  adalah satu-satunya yang tersisa aktif (skenario: sudah ada upaya sebelumnya yang
  menonaktifkan admin lain) → **ditolak**. *Ini kasus yang sebelumnya lolos tanpa
  disadari — pastikan diuji eksplisit, bukan diasumsikan.*
- Ulangi kedua skenario lewat API — hasilnya harus identik dengan web.
- Sebagai satu-satunya super admin, coba nonaktifkan diri sendiri lewat API → ditolak
  (cek diri-sendiri yang baru ditambahkan).

**Risiko rollout.** Rendah — murni logika baca sebelum tulis, tidak ada migrasi
skema. Risiko utamanya adalah false-positive kalau kueri hitungnya salah menghitung
akun yang sedang diubah itu sendiri — uji skenario "Admin A menurunkan dirinya
sendiri padahal masih ada Admin B aktif" untuk pastikan **tidak** salah ditolak.

**Definition of Done.**

- [ ] Skenario "satu-satunya admin tersisa, aksi web ditolak" diuji dan lulus —
      sebelumnya ini **tidak pernah** tertolak
- [ ] Skenario sama diuji di API, hasil identik dengan web
- [ ] Cek diri-sendiri di `PUT /api/pengguna.php` diuji
- [ ] Regresi: aksi yang sah (masih ada admin lain) tidak ikut tertolak
- [ ] Dicatat di catatan revisi

---

## Sprint 3 — Kapabilitas & lingkup sisa (bisa paralel)

Ketiga item ini independen satu sama lain — bisa dikerjakan tiga orang sekaligus,
atau satu orang berurutan dalam satu sesi.

### P7. `barang_form.php` + `barang_hapus.php`

**Prioritas & estimasi.** Sedang · 30 menit. Dua baris, tapi **uji seksama** karena
ini mengubah hak akses operasional sehari-hari staf gudang.

**Langkah.** Tempelkan dua baris dari `AUDIT_KEAMANAN_2026-10-29.md` bagian 2.1.

**Kriteria lulus uji.**

- Akun tingkat `staff` → ubah/hapus barang ditolak 403 (sebelumnya: diterima).
- Akun tingkat `manager` tanpa override → tetap diterima (regresi — **ini yang
  paling penting**, karena mayoritas staf operasional sehari-hari memakai jalur ini).
- Akun dengan `izin_pengguna.hapus = 0` eksplisit → hapus ditolak meski tingkat `manager`.

**Risiko rollout.** **Sedang**, lebih tinggi dari ukuran perubahannya — kalau ada
akun `staff` yang selama ini mengandalkan celah ini untuk kerja sehari-hari (tidak
mustahil, karena celahnya sudah lama ada), mereka akan mendadak terblokir begitu
deploy. **Cek dulu aktivitas log** (`log_aktivitas.php`) untuk akun tingkat `staff`
yang pernah mengubah/menghapus barang, sebelum deploy — kalau ada, koordinasikan
dengan mereka atau naikkan tingkatnya dulu.

**Definition of Done.**

- [ ] Log aktivitas ditinjau untuk akun `staff` yang terdampak, dikoordinasikan bila ada
- [ ] Ketiga skenario uji di atas lulus
- [ ] Dicatat di catatan revisi

### P8. `stok_asal.php` — cabang `toko`

**Prioritas & estimasi.** Rendah · 15 menit. Satu baris.

**Langkah.** Tambahkan cabang `toko` dari `AUDIT_KEAMANAN_2026-10-29.md` bagian 2.2,
persis mengikuti pola dua cabang yang sudah ada (`gudang`, `rak`).

**Kriteria lulus uji.** Akun `toko` A → `asal_tipe=toko&asal_id=<toko B>` ditolak;
`asal_id=<toko A>` tetap diterima.

**Risiko rollout.** Rendah. Hanya peran `toko` yang terdampak; empat peran lain
memang sengaja diberi visibilitas penuh oleh desain yang ada, jadi tidak ada
regresi untuk mereka.

**Definition of Done.** Satu skenario di atas diuji, dicatat di catatan revisi.

### P9. `api/penerimaan.php` + kiriman supplier

**Prioritas & estimasi.** Sedang · 1.5 jam — dua perubahan berkaitan erat, kerjakan
sekaligus karena satu PR untuk memudahkan review.

**Langkah.**

1. Ganti `syarat_tujuan_saya()` dengan versi gagal-tertutup dari
   `AUDIT_KEAMANAN_2026-10-29.md` bagian 2.3.
2. Tambahkan predikat `gudang_tujuan_id` ke keempat statement di bagian 2.4 —
   `api/kiriman.php`, `kiriman_supplier.php` (dua tempat), `scan_resi.php`.
3. Saring juga query daftar di `kiriman_supplier.php` supaya staf berhenti melihat
   kiriman gudang lain sama sekali, bukan cuma tidak bisa mengubahnya.
4. Tambahkan cek status `!= 'dibatalkan'` ke query prefill di
   `_perpindahan_form.php:487` — celah terkait yang ditemukan audit tapi akar
   masalahnya beda (lihat catatan di `AUDIT_KEAMANAN_2026-10-29.md` bagian 2.4).

**Kriteria lulus uji.**

- Akun `supplier` A → `GET /api/penerimaan.php` hanya menampilkan dokumen miliknya
  sendiri (sebelumnya: semua dokumen sistem).
- Akun `admin_gudang` Gudang A → coba tandai `tiba`/`batal` kiriman untuk Gudang B
  → ditolak (sebelumnya: berhasil).
- Kiriman yang sudah `dibatalkan` → coba proses lewat URL langsung (`?kiriman=`)
  → ditolak, bukan cuma tombolnya yang hilang dari tampilan.

**Risiko rollout.** Rendah-sedang. Perubahan `syarat_tujuan_saya()` ke gagal-tertutup
berarti peran yang **belum teridentifikasi** di masa depan otomatis tidak melihat
apa-apa alih-alih melihat semua — ini perilaku yang diinginkan, tapi pastikan tidak
ada peran yang sah saat ini yang kebetulan jatuh ke cabang fallthrough lama.

**Definition of Done.**

- [ ] Ketiga skenario di atas diuji
- [ ] Grep peran yang ada saat ini terhadap `syarat_tujuan_saya()` — pastikan semua
      peran sah punya cabang eksplisit, tidak ada yang diam-diam jatuh ke default baru
- [ ] Dicatat di catatan revisi

---

## Sprint 4 — Migrasi skema (jendela terpisah)

### P10. Jangkar lokasi di `pelepasan_stok`

*Menutup: riwayat pelepasan org-wide di API dan web.*

**Prioritas & estimasi.** Tinggi secara severity, tapi butuh jendela deploy
tersendiri karena melibatkan `ALTER TABLE` · 3–4 jam termasuk migrasi dan verifikasi
data pasca-migrasi.

**Bergantung pada.** Tidak ada secara teknis, tapi **lakukan setelah** P1–P9 karena
ini satu-satunya item yang mengubah skema — pisahkan risikonya dari perubahan logika
murni supaya kalau perlu rollback, yang di-rollback jelas satu hal.

**Langkah.**

1. **Cadangkan database penuh** sebelum migrasi apa pun — lewat `cadangan.php`,
   bukan dump manual, supaya konsisten dengan mekanisme pemulihan yang sudah ada.
2. Tambahkan migrasi baru di `db/migrasi_pelepasan_lokasi_rak_<tanggal>.sql` (ikuti
   konvensi penamaan `migrasi_*.sql` yang sudah ada, supaya `migrasi.php` otomatis
   mendeteksinya) — isi DDL dari `AUDIT_KEAMANAN_2026-10-29.md` bagian 1.3.
3. Di `_lib.php:856-861` (`lepas_stok()`), tambahkan `$batch['lokasi_rak_id']` ke
   parameter INSERT.
4. Perbarui `v_pelepasan` di `db/migrasi_pelepasan_stok.sql` **dan** di dump skema
   terbarukan (`db/migrasi_skema.sql`) supaya kolom baru ikut terproyeksi — audit
   menemukan kedua salinan DDL itu harus identik, jangan hanya ubah satu.
5. Tambahkan predikat lingkup ke `api/pelepasan.php:220-226` dan
   `pelepasan.php:160-165`, memakai kolom baru.
6. **Putuskan kebijakan untuk baris lama** yang `lokasi_rak_id`-nya akan `NULL`
   setelah migrasi (baris pelepasan yang `batch_id`-nya sudah ter-`NULL`-kan
   sebelumnya). Dua opsi — pilih satu secara sadar, jangan biarkan default implisit:
   - **Sembunyikan dari semua** kecuali `super_admin`/`pimpinan`, atau
   - **Tampilkan ke semua** seperti sekarang (status quo untuk baris lama saja).

**Kriteria lulus uji.**

- Jalankan migrasi di **salinan** database (bukan produksi) dulu. Hitung baris
  `pelepasan_stok` yang `batch_id IS NULL` sebelum memutuskan kebijakan di langkah 6
  — kalau jumlahnya nol, keputusannya tidak berdampak praktis dan bisa diputuskan
  cepat.
- Buat pelepasan baru di Gudang A, lalu login sebagai admin Gudang B → riwayat
  pelepasan Gudang A **tidak** muncul (sebelumnya: muncul).
- Riwayat pelepasan Gudang A **tetap muncul** untuk admin Gudang A sendiri (regresi).
- `super_admin`/`pimpinan` tetap melihat semua gudang (regresi).

**Risiko rollout.** Sedang-tinggi karena menyentuh skema produksi. Mitigasi:

- Jalankan di jam senggang, bukan jam sibuk donasi masuk/keluar.
- `ALTER TABLE ... ADD COLUMN` dengan `DEFAULT NULL` aman untuk tabel yang sedang
  dipakai — tidak mengunci tabel lama di MySQL/MariaDB modern, tapi **verifikasi
  versi server dulu** kalau hosting-nya lama.
- Siapkan skrip rollback (`DROP COLUMN`) sebelum mulai, bukan ditulis saat panik.

**Definition of Done.**

- [ ] Cadangan database terbaru terkonfirmasi ada sebelum migrasi dimulai
- [ ] Migrasi diuji di salinan database dulu, bukan langsung produksi
- [ ] Kedua salinan DDL `v_pelepasan` (migrasi + dump skema) diperbarui bersamaan
- [ ] Jumlah baris `batch_id IS NULL` dihitung dan kebijakannya diputuskan secara sadar
- [ ] Ketiga skenario lingkup di atas diuji
- [ ] Skrip rollback siap sebelum migrasi dijalankan di produksi
- [ ] Dicatat di `CATATAN_REVISI_<tanggal>.md` **dan** di
      `docs/produk/spesifikasi_migrasi/02_SKEMA_DB.md` (perubahan skema harus
      tercermin di dokumen sumber kebenaran skema)

---

## Sprint 5 — Perlu dibuktikan dulu

### P11. Race condition di `api/persetujuan.php`

**Prioritas & estimasi.** Tinggi secara severity, tapi **jangan langsung menulis
fix** · 2 jam untuk pembuktian + 1 jam untuk fix setelah terbukti.

**Bergantung pada.** Tidak ada, tapi urutannya penting: **buktikan dulu, baru
perbaiki** — audit ini tidak bisa menguji konkurensi tanpa basis data, jadi fix yang
ditulis tanpa pembuktian berisiko menutup kondisi yang salah.

**Langkah pembuktian (di database uji, bukan produksi):**

1. Siapkan satu dokumen di status `menunggu_persetujuan`.
2. Buka dua koneksi PDO terpisah dalam satu skrip PHP CLI sekali pakai.
3. Koneksi A: jalankan `SELECT` persis seperti `api/persetujuan.php:143-146`,
   simpan hasilnya, **jangan commit apa pun**.
4. Koneksi B: jalankan fungsi `setujui` sampai selesai dan commit — status jadi
   `dikirim`/`selesai`, efek stok sudah diterapkan.
5. Koneksi A: jalankan `UPDATE` persis seperti `:201-205` (aksi tolak) memakai
   nilai yang disimpan di langkah 3.
6. Periksa baris akhir: kalau statusnya berubah jadi `ditolak` **meski** stok sudah
   bergerak di langkah 4, race-nya terbukti nyata.

**Langkah perbaikan (setelah terbukti).** Tambahkan predikat status ke kedua
`UPDATE` (`kirim` dan `tolak`) persis seperti pola di `persetujuan.php:61-65` dan
`:146-151`, plus cek `rowCount()` dan pesan "sudah diproses" kalau nol baris
terdampak.

**Kriteria lulus uji.** Ulangi harness pembuktian di atas **setelah** fix — langkah
5 sekarang harus gagal dengan pesan "sudah diproses", status tetap seperti hasil
langkah 4.

**Risiko rollout.** Rendah untuk fix-nya sendiri (menambah predikat WHERE adalah
perubahan kecil dan aman). Risiko ada di **melewatkan langkah pembuktian** — kalau
fix ditulis berdasarkan dugaan saja, ada kemungkinan kondisi race yang sebenarnya
berbeda dari yang diasumsikan.

**Definition of Done.**

- [ ] Harness pembuktian dijalankan **sebelum** fix, hasilnya didokumentasikan
      (berhasil memicu race atau tidak)
- [ ] Fix ditulis **setelah** race terbukti, bukan berdasarkan dugaan
- [ ] Harness yang sama dijalankan **setelah** fix, hasilnya negatif (race tidak
      lagi bisa dipicu)
- [ ] Dicatat di catatan revisi, termasuk ringkasan hasil pembuktian

---

## Pola gagal yang ditemukan audit — hindari saat menulis fix baru

Audit ini sempat **salah pada rekomendasinya sendiri** dua kali sebelum ketahuan di
lapis verifikasi terakhir. Keduanya pola yang bisa terulang kalau perbaikan di luar
daftar ini ditulis dengan tergesa:

1. **Memperbaiki sumber variabel, bukan semua pemakainya.** Di P2, mengganti sumber
   `$pagar` saja tidak cukup karena ada guard kedua (`$gidSaya`) yang terpisah dan
   tidak ikut tersentuh. **Kebiasaan yang disarankan:** sebelum menutup PR perbaikan
   otorisasi, `grep` nama variabel lama di seluruh berkas yang diubah — kalau masih
   ada sisa pemakaian, perbaikannya belum selesai.
2. **Menyalin helper tanpa membaca asumsi tersembunyinya.** Dua kali di rencana ini
   (P5 dan — kalau dikerjakan ceroboh — P6), fungsi yang kelihatan bisa dipakai
   ulang ternyata punya asumsi yang tidak berlaku di konteks baru (`session_id()`
   kosong di API; `jml_super_aktif()` mengecualikan target bukan pelaku).
   **Kebiasaan yang disarankan:** sebelum memanggil fungsi yang ditulis untuk web
   dari kode API (atau sebaliknya), baca badan fungsinya sekali — bukan cuma nama
   dan signature-nya.

---

## Metrik keberhasilan

| Metrik | Target |
|---|---|
| Temuan severity tinggi dari `AUDIT_KEAMANAN_2026-10-29.md` yang masih terbuka | 0 setelah Sprint 1 |
| Temuan severity sedang yang masih terbuka | 0 setelah Sprint 3 |
| Butir "perlu jawaban tim" yang terjawab | 8/8, independen dari jadwal kode |
| Endpoint API yang diperiksa paritasnya dengan kembaran web-nya | Seluruh endpoint yang disentuh rencana ini, dicatat sebagai item checklist PR |
| Jebakan implementasi (pola gagal di atas) yang terulang pada fix baru | 0 |

## Mencegah berulang — bukan hanya menambal

Rencana ini menutup temuan yang sudah ada. Yang mencegahnya terulang pada kode yang
ditulis **setelahnya** ada di `AUDIT_KEAMANAN_2026-10-29.md` bagian 4 — ringkasnya:
daftar periksa paritas web↔API di tiap PR yang menyentuh otorisasi, dan kebiasaan
menulis default gagal-tertutup (`1=0`) alih-alih gagal-terbuka (`1=1`) untuk peran
yang belum ditangani eksplisit.

---

*Dokumen ini berorientasi implementasi: langkah kerja, kriteria lulus uji, dan
risiko rollout — tanpa mengulang kode patch yang sudah ada lengkap di
`AUDIT_KEAMANAN_2026-10-29.md`. Tidak ada data donatur, penerima, hash sandi, atau
alamat email yang direproduksi di mana pun dalam dokumen ini.*
