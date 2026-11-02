# Analisis Kekurangan AMANAH — Pembaruan 28 Oktober 2026

Lanjutan `ANALISIS_KEKURANGAN.md` (13 Sep 2026). Disusun dengan membaca ulang
kode saat ini, membandingkan ke 4 catatan perubahan yang terbit sesudahnya
(`CATATAN_PERUBAHAN_2026-09-13.md`, `CATATAN_REVISI_TIM_GUDANG_2026-10.md`,
`CATATAN_KARDUS_DAN_CADANGAN_2026-10.md`, `CATATAN_REVISI_2026-10-18.md`), dan
memverifikasi tiap klaim "sudah fix" langsung ke kode — bukan menyalin isi
catatan. Dokumen lama **tidak diubah**; ini daftar baru supaya tidak ada yang
melaporkan ulang sesuatu yang sudah selesai.

Hanya 3 kategori di bawah. Yang sudah fix tidak dibahas lagi di sini — cek
`ANALISIS_KEKURANGAN.md` + 4 catatan perubahan untuk itu.

---

## 1. Masih terbuka dari audit lama

| # | Temuan | Bukti saat ini | Catatan |
|---|---|---|---|
| 1 | `_lib.php` masih monolitik, dan **membesar**, bukan mengecil | 5.320 baris (dulu 4.337) | Fitur baru (kardus, tim gudang, stok pesan) ditambahkan ke berkas yang sama, bukan dipisah |
| 2 | `<label>` tanpa `for=` | 309 `<label>` total vs **19 saja** berpasangan `for=` di seluruh berkas akar (`grep -c`) | Audit lama bilang 437/438; sekarang total label lebih sedikit tapi rasio tak-berpasangan masih dominan |
| 3 | `<style>` inline meski dilarang `_header.php` | `alat_scan.php:73`, `barang_stok.php:431`, `surat_jalan.php:133` | `surat_jalan.php` warnanya sudah disamakan ke biru tema baru, tapi tag `<style>`-nya sendiri belum dibongkar |
| 4 | Kredensial marketplace teks polos | `_shopee.php` — `partner_key`, `access_token`, `refresh_token` kolom DB biasa | Tidak disebut di 4 catatan perubahan manapun — belum disentuh |
| 5 | Dasbor Toko/Supplier timpang | Diklaim di `CATATAN_PERUBAHAN_2026-09-13.md` §5 sebagai belum dikerjakan | Belum diverifikasi ulang KPI/grafiknya di sesi ini — **perlu baca ulang `dasbor_toko.php`/`dasbor_supplier.php` sebelum ditindaklanjuti** |
| 6 | Android tertinggal fitur web | Jenis donasi wajib, kondisi Rusak/Bocor/Expired, kardus, aksi batal/hapus dokumen — semua baru di web, belum ada di app Android (diakui di catatan Okt) | Risiko: data yang diinput lewat Android makin tidak lengkap dibanding web |
| 7 | `filter_bar()` tetap jarang dipakai | 5 berkas memakainya (naik dari 1), dari target "8 halaman daftar tanpa cari/filter" di audit lama | Membaik tapi belum tuntas — tidak diperinci berkas mana yang masih polos di sesi ini |

## 2. Keputusan tertunda dari tim (bukan bug, tapi berisiko dibiarkan tanpa tenggat)

- **Definisi "kunjungan"/"paket" tidak konsisten.** `laporan_harian_donasi.php` menghitung dari kolom `jenis` (`kunjungan`/`paket`, lihat baris 53), sedang `laporan_donasi.php` menghitung dari kolom `cara_terima` yang berbeda makna (baris 92-96). Dua laporan donasi bisa menampilkan angka yang tidak saling cocok untuk periode yang sama tanpa penjelasan di UI.
- **Data lama "Belum diklasifikasi"** — belum ada keputusan apakah/bagaimana direklasifikasi ke skema `jenis_donasi` baru.
- **Butir 8 (Kondisi Bawaan & Rak Bawaan)** — diminta tim gudang, dianalisis di `CATATAN_REVISI_TIM_GUDANG_2026-10.md`, tidak diimplementasi. Disebut ulang lagi di `CATATAN_KARDUS_DAN_CADANGAN_2026-10.md` — dua catatan berbeda sama-sama menunggu keputusan yang sama, tanda ini mandek.

## 3. Gap baru dari fitur Oktober 2026 (belum ada di audit lama)

- **Tanpa test untuk 3 fitur yang memindahkan/mengunci stok**: kardus (`kardus.php`, `kardus_detail.php`), reservasi stok (`_stok_pesan.php`), gabung-draft. `tests/` masih cuma 5 berkas lama (`uji_fefo.php`, `uji_opname.php`, `uji_pelepasan.php`, `uji_perpindahan.php`, `uji_stok.php`) — tidak satupun menyentuh ketiganya. `stok_dipesan()` (`_stok_pesan.php:40-57`) adalah query agregat tanpa lock baris sendiri — mengandalkan `FOR UPDATE` di tempat lain saat approval; kalau asumsi itu berubah, race condition kembali tanpa ada test yang akan menangkapnya.
- **Risiko stok ganda di Gudang Apotek** — dijelaskan di `CATATAN_REVISI_2026-10-18.md` sebagai catatan proses, bukan bug: barang yang di-input lewat Barang Masuk langsung (bukan surat jalan antar-gudang) membuat stok gudang asal tidak pernah berkurang. Perbaikannya sekarang murni "jangan lakukan itu" di SOP; tidak ada guard sistem yang mencegah pola ini. **Usul**: peringatan di `masuk.php` saat gudang tujuan bukan gudang utama dan tidak ada surat jalan terkait, atau laporan rutin pembanding stok lintas gudang.
- **26 berkas migrasi tanpa urutan penomoran konsisten.** `migrasi.php` (baru) menjalankan berkas `db/migrasi_*.sql` urut nama (`daftar_migrasi()`, `ksort(..., SORT_NATURAL)`) dan mencatat yang sudah jalan di `migrasi_terpasang` — ini menyelesaikan masalah lama "mana yang sudah jalan di server ini". Yang **belum** diselesaikan: urutan penulisan migrasi baru. Hanya 4 berkas (`migrasi_tim_gudang_01..04`) diberi nomor eksplisit; sisanya urut nama file apa adanya, yang kebetulan masih benar sekarang tapi tidak dijamin untuk migrasi berikutnya yang punya dependensi DDL (mis. `ALTER TABLE` yang butuh kolom dari migrasi lain). Konvensi penamaan `migrasi_NNNN_nama.sql` untuk SEMUA berkas baru akan mencegah ini.
- **`cron_cadangan.php` melacak "terakhir jalan" hanya di respons JSON**, bukan di UI. `pengaturan($pdo, 'cadangan_terakhir')` disimpan dan dikembalikan sebagai teks dalam pesan `"Belum jatuh tempo (...)"` saat dipanggil manual/cron, tapi belum dikonfirmasi apakah `cadangan.php` menampilkannya sebagai peringatan visual (banner "cadangan terlambat X hari") di halamannya sendiri — kalau tidak, operator harus tahu untuk memanggil endpoint manual untuk mengecek, bukan dikasih tahu di layar. **Perlu baca `cadangan.php` baris render untuk pastikan.**
- **`cron_marketplace.php` tanpa pemantauan "terakhir berhasil"** sama sekali — hanya dokumentasi pemasangan cron di komentar kepala berkas, tidak ada pencatatan timestamp/status run terakhir seperti yang dimiliki cadangan. Kalau cron berhenti (kuota habis, server pindah), tidak ada sinyal di UI yang memberi tahu — pesanan marketplace diam-diam berhenti tertarik.

---

### Ringkas prioritas
1. Guard/laporan stok ganda Gudang Apotek — risiko data nyata, biaya kecil (peringatan UI).
2. Test untuk kardus/stok-pesan/gabung-draft — ketiganya high-risk stok tanpa jaring pengaman.
3. Pemantauan "terakhir berhasil" `cron_marketplace.php` — konsisten dengan yang sudah ada di cadangan.
4. Definisi kunjungan/paket vs cara_terima — rapikan sebelum dipakai untuk laporan ke pihak luar.
5. Sisanya (label `for=`, `<style>` inline, kredensial Shopee, `_lib.php` split) — debt lama, tidak mendesak tapi terus membesar kalau tidak dicicil.
