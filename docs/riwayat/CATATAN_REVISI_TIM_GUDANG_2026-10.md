# Revisi Masukan Tim Gudang — Oktober 2026

Ringkasan per butir: apa yang berubah, cara mengujinya, dan apa yang perlu
disampaikan ke tim gudang. Kode dikelola dengan git (satu commit per butir)
di `C:\xampp\htdocs\Gudang`; hosting diperbarui lewat FTP dari folder
`C:\xampp\htdocs\Gudang_UNGGAH_revisi_tim_gudang`.

## Sebelum diunggah ke server

1. **Backup database** lewat phpMyAdmin (Export → SQL) dan simpan berkasnya.
2. Unggah seluruh isi folder unggah (timpa berkas lama; `db/` ke `db/`).
3. Buka **Pengaturan › Migrasi Basis Data** dan jalankan dua migrasi baru:
   - `migrasi_tim_gudang_01_jenis_donasi_masuk.sql` — kolom sambungan penerimaan ↔ catatan donasi
   - `migrasi_tim_gudang_02_kondisi_rusak_expired.sql` — kondisi Rusak/Bocor dan Expired
4. Jalankan query pemeriksaan di bawah dan simpan hasilnya.

Pembatalan: `db/rollback/rollback_tim_gudang_01_*.sql` dan `..._02_*.sql`
(dijalankan manual lewat phpMyAdmin, lihat komentar di dalamnya).
Rollback 02 mengembalikan nilai Rusak/Bocor dan Expired yang sudah terpakai
menjadi "Tidak layak (lama)" — pembedaannya hilang, jumlah stok tidak.

Query pemeriksaan sesudah migrasi (hanya membaca):

```sql
SELECT COUNT(*) total, SUM(donasi_id IS NOT NULL) tersambung FROM transaksi_masuk;
SELECT jenis, COUNT(*) FROM donasi GROUP BY jenis;
SELECT COLUMN_TYPE FROM information_schema.columns
 WHERE table_schema = DATABASE() AND table_name = 'batch_barang' AND column_name = 'kondisi';
```

## Butir 1 — Jenis Donasi di Barang Masuk

**Berubah**
- Keterangan Penerimaan: pilihan Jenis Donasi hanya **Donasi Kunjungan** /
  **Donasi Paket**, tanpa pilihan bawaan. Wajib bila Asal Barang = Donasi;
  tersembunyi untuk Pembelian. Server ikut menolak bila kosong.
- Riwayat Barang Masuk: kolom **Asal / Jenis** (Pembelian, Donasi Kunjungan,
  Donasi Paket, Belum diklasifikasi + nama donatur) dan filter **Asal / jenis
  donasi**.
- Data lama: tidak diubah. Penerimaan donasi yang jenisnya `umum` tampil
  sebagai **Belum diklasifikasi**. Mengklasifikasikannya menunggu keputusan
  pemilik sistem.
- Aplikasi Android versi lama masih bisa menyimpan (jenisnya tercatat
  "Belum diklasifikasi") — tidak ditolak supaya lapangan tidak berhenti.

**Cara uji**: Barang Masuk → Asal Barang = Donasi → simpan tanpa memilih
jenis → ditolak dengan pesan "Pilih Jenis Donasi". Pilih Kunjungan → simpan →
di Riwayat Barang Masuk tampil tag "Donasi Kunjungan"; filter "Donasi Paket"
tidak menampilkannya.

**Untuk tim gudang**: setiap penerimaan donasi harus memilih Kunjungan atau
Paket. Satu pilihan berlaku untuk seluruh isi keranjang; kalau satu kedatangan
berisi keduanya, buat dua penerimaan.

## Butir 3 — Pisah baris & opsi kondisi (prioritas)

**Opsi kondisi** (sebelumnya: Baru / Bekas — layak pakai / Bekas — tidak layak):
| Nilai | Label | Siap salur |
|---|---|---|
| baru | Baru | ya |
| bekas_layak | Layak | ya |
| rusak (baru) | Rusak/Bocor | tidak |
| expired (baru) | Expired | tidak |
| bekas_tidak_layak (lama) | Tidak layak (lama) | tidak — tidak ditawarkan lagi untuk input baru |

**Berubah**
- Tombol **Pisah** di tiap baris Keranjang Penerimaan: isi jumlah yang
  dipisah (1 s.d. jumlah−1), baris baru muncul di bawahnya dengan barang,
  batch, harga, dan expired yang sama; ubah kondisi / rak / expired / batch
  di baris baru. Total tidak berubah; tiap baris minimal 1.
- Setelah dipisah, tembakan barcode untuk barang yang sama membuat baris
  baru (tidak menambah ke baris hasil pisah).
- Tiap baris disimpan sebagai batch stok tersendiri (sudah begitu di
  server; yang baru tombolnya).
- Menu Perbaikan & Pelepasan menampilkan batch Rusak/Bocor dan Expired.
- Aplikasi Android: daftar kondisinya masih tiga pilihan lama (nilai lama
  tetap diterima server). Memperbarui layar Android butuh APK baru — belum
  dikerjakan.

**Cara uji** (ketiga skenario lolos di lokal):
- a. Scan 10 → Pisah 1 → baris baru kondisi Rusak/Bocor → simpan → dua batch
  (9 Layak, 1 Rusak); stok siap salur 9.
- b. Scan 10 → Pisah 4 → rak baris baru "Rak Gudang Rumah Anak Surga" →
  simpan → 6 di Area Siap Kirim, 4 di rak itu.
- c. Scan 5 Sun Marie → Pisah 2 → baris baru kondisi Expired + tanggal
  expired sebenarnya → simpan → 3 siap salur; 2 muncul di Perbaikan &
  Pelepasan dan tidak bisa dipilih di surat jalan penyaluran.

**Untuk tim gudang**: gunakan Kondisi Bawaan untuk keadaan yang paling
banyak, lalu Pisah hanya untuk pengecualian. Barang yang sudah lewat
tanggal: pilih **Expired** dan tetap isi tanggal expired aslinya.

## Butir 7 — Filter gudang di Riwayat Barang Masuk

**Berubah**: filter **Gudang** (daftar dari master gudang, jadi Gudang
Widuri otomatis muncul). Gudang dibaca dari rak tiap baris: dokumen tampil
bila ada barisnya di gudang itu; unit dan nilai hanya menjumlahkan baris
gudang itu (disebutkan di bawah judul).

**Catatan**: penerimaan tidak menyimpan gudang; web dan Android selalu
mewajibkan rak, jadi data lama semestinya punya gudang. Pastikan dengan
`SELECT COUNT(*) FROM transaksi_masuk_detail WHERE lokasi_rak_id IS NULL;`
di server — bila hasilnya bukan 0, baris itu tidak masuk filter gudang mana pun.

**Cara uji**: buat penerimaan dengan dua baris ke rak gudang berbeda →
filter gudang A menampilkan dokumen dengan unit baris A saja.

## Butir 2 — Laporan Harian Donasi

**Berubah**: menu **Laporan › Laporan Harian Donasi** (`laporan_harian_donasi.php`).
Pilih tanggal dan gudang → kartu Donasi kunjungan / paket hari itu dan
akumulasi tanggal 1 s.d. tanggal laporan (item = total unit; nominal =
jumlah × harga). Tombol **Salin untuk WhatsApp** menghasilkan teks persis
format contoh (pemisah ribuan titik, termasuk spasi-spasinya).

**Asumsi (perlu konfirmasi tim)**: semua barang donasi yang diterima
dihitung, termasuk Rusak/Bocor, Expired, dan tidak layak.

**Catatan**: penerimaan "Belum diklasifikasi" tampil sebagai kotak kuning di
halaman, tidak masuk teks WhatsApp. Halaman **Laporan Donasi** yang lama
masih memisahkan kunjungan/paket menurut *Cara Terima* (diantar vs
dikirim), bukan Jenis Donasi — menunggu keputusan definisi mana yang dipakai.

**Cara uji**: buat penerimaan kunjungan dan paket hari ini → buka laporan →
angka dan teks sesuai; filter gudang mengecualikan baris gudang lain.

## Butir 5 — Surat jalan: sumber per rak, hanya stok siap salur

**Berubah**
- Panel **Pilih Banyak Barang**: Sumber bisa dipilih per **rak** (daftar
  dari master rak, dikelompokkan per gudang; "Area Siap Kirim" dsb.). Sumber
  otomatis mengikuti Asal dokumen.
- Centang **Hanya stok siap salur** (bawaan menyala). Untuk Penyaluran,
  Kirim ke Toko, dan Antar Toko centangnya terkunci: jenis ini hanya boleh
  membawa stok siap salur — pemeriksaan ketersediaan, batch tercetak, dan
  pemotongan stok memakai kondisi layak saja.
- Transfer Antar Gudang, Mutasi Rak, dan Retur **tidak** disaring: itulah
  jalur memindahkan barang rusak/expired (mis. ke Gudang Widuri).
- Sebelumnya FEFO surat jalan mengambil batch **expired lebih dulu**; kini
  tidak lagi untuk penyaluran/kirim toko.

**Catatan**: "Penerima Amanah" bukan rak, jadi tidak ada di daftar sumber;
ia adalah tujuan penyaluran.

**Cara uji**: rak berisi 3 Baru + 2 Expired → surat jalan penyaluran 4 dari
rak itu ditolak ("stok siap salur hanya 3"); 3 disetujui dan yang dipotong
batch Baru. Transfer gudang 2 dari rak yang sama tetap boleh.

## Butir 4 & 9 — Edit riwayat penerimaan, donatur

**Berubah** (halaman Ubah dari Riwayat Barang Masuk):
- **Donatur penerimaan ini**: ganti ke donatur lain / baru (nama + nomor HP;
  nama dan nomor yang sama dengan donatur lama = donatur itu). Catatan
  donasi dan batch stoknya ikut berpindah; batch yang juga dipakai penerimaan
  lain dipisah dulu supaya penerimaan lain tidak ikut berganti. Tautan
  **betulkan ejaan nama / nomor** membuka master Donatur (butir 9: sudah ada
  di menu Donatur untuk Manager dan Supplier).
- **Tambah baris barang**: barang, jumlah, kondisi, rak, batch, kedaluwarsa,
  harga. Masuk ke penerimaan ini sebagai donasi atas donatur yang sama;
  stok, batch, baris donasi, dan total dokumen ikut.
- Hak edit: **Super Admin**, dan **Admin Gudang / Admin Gudang & Donasi
  tingkat Manager** (izin Kelola master) untuk gudangnya sendiri. Staff
  mendapat 403.
- Log: **Pengaturan › Jejak Aktivitas**, modul *Transaksi masuk* — siapa,
  kapan, "Ganti donatur TM-…: A (DN-001) → B (DN-002)", "Tambah baris TM-…: + Mouse 4".
- Halaman ini sebelumnya sudah bisa mengubah jumlah/harga dan menghapus
  baris (jumlah 0). Kemampuan itu **dibiarkan** — belum ada keputusan untuk
  menonaktifkannya.

**Cara uji**: dua penerimaan donatur yang sama → ganti donatur penerimaan
pertama → donasi dan batch penerimaan kedua tetap donatur lama. Tambah baris
4 Mouse (Rusak) → stok Mouse +4, baris donasi bertambah, laporan harian ikut.

## Butir 6 — Gudang Widuri

Master gudang dikelola lewat data, bukan kode: **Mitra & Lokasi › Gudang →
Tambah** (kode mis. `GD-007`, nama *Gudang Widuri*, jenis *Gudang Pusat*),
lalu **Rak → Tambah** minimal satu rak (mis. "Area Drop Barang Bekas") —
Barang Masuk dan surat jalan selalu menunjuk rak, jadi tanpa rak gudang ini
tidak bisa menerima barang. Setelah itu Widuri otomatis muncul di filter
gudang, laporan harian, dan daftar sumber surat jalan. Tidak ada perubahan kode.

## Butir 8 — Kondisi Bawaan & Rak Bawaan (ditunda)

Tidak dikerjakan. Dampak tiap pilihan:
- **Dihapus**: setiap baris baru memakai kondisi Baru dan rak pertama di
  daftar; menerima 30 barang ke satu rak berarti mengubah rak 30 kali.
- **Dipertahankan dengan label jelas** (rekomendasi): misalnya "Kondisi
  untuk barang berikutnya" / "Rak untuk barang berikutnya" + keterangan
  "baris yang sudah ada tidak berubah". Dengan tombol Pisah, nilai bawaan
  justru makin berguna: baris hasil scan langsung tepat, pengecualian dipisah.

## Yang belum / menunggu keputusan

- Definisi kunjungan/paket di **Laporan Donasi** lama (Cara Terima vs Jenis Donasi).
- Perlakuan data lama "Belum diklasifikasi" (dibiarkan / diklasifikasi tangan).
- Aplikasi Android: jenis donasi wajib dan opsi kondisi baru belum ada di layarnya.
- Butir 8.
