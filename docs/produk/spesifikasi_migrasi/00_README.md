# Spesifikasi Migrasi AMANAH — Build Ulang dari Nol

Folder ini cukup untuk membangun ulang AMANAH v3.0 di bahasa/framework apa pun **tanpa membaca kode PHP lama**. PRD (`../PRD_AMANAH.md`) menjawab *apa & mengapa*; folder ini menjawab *persis bagaimana*.

## Urutan baca

| # | Berkas | Isi | Wajib untuk |
|---|--------|-----|-------------|
| 01 | `01_PRD.md` | Pointer ke PRD + perbedaan PRD vs spec | Semua |
| 02 | `02_skema_db.sql` + `02_SKEMA_DB.md` | DDL 55 tabel + 14 view (dump MariaDB), semantik kolom, urutan kunci | Backend, DBA |
| 03 | `03_ALGORITMA_INTI.md` | Pseudocode + SQL verbatim: FEFO, kunci stok, nomor dokumen, sahkan/terapkan perpindahan, reservasi, penerimaan, pelepasan, transit, kardus | Backend |
| 04 | `04_ATURAN_BISNIS.md` | State machine, rute sah, enum, matriks peran×izin×tingkat, lingkup, notifikasi | Backend, QA, PM |
| 05 | `05_KONTRAK_API.md` | Endpoint REST, JWT, pagination, format error | Backend, Mobile |
| 06 | `06_LAYAR_UI.md` | Inventaris halaman & aksi POST per peran, komponen UI wajib | Frontend |
| 07 | `07_NONFUNGSIONAL_DAN_KEAMANAN.md` | Sesi, CSRF, CSP, throttle, perangkat, backup, cron, konkurensi | Backend, DevOps |
| 08 | `08_KASUS_UJI.md` | Kasus uji wajib lulus (dari `tests/uji_*.php` + tambahan) | QA |
| 09 | `09_PETA_FILE_LAMA.md` | PHP lama → bagian spec | Reviewer |

## Aturan kompatibilitas (TIDAK BOLEH DILANGGAR)

Data produksi (`kelolaaset.space`) akan dimigrasi apa adanya. Maka:

1. **Skema DB dipakai ulang persis** — nama tabel/kolom/enum sama. Boleh tambah kolom, jangan ubah/hapus.
2. **Format nomor dokumen sama**: `PREFIX-YYYYMMDD-NNNN` (reset harian; lihat 03 §Nomor Dokumen). Counter di `dokumen_counter`.
3. **Nilai enum status sama**, termasuk nilai legacy `disetujui`/`diterima` di `perpindahan.status` yang masih bisa ada di data lama.
4. **String pesan error ke pengguna sama persis** — tim gudang sudah hafal, dokumentasi internal merujuk teks ini. Semua tercantum di 03/04.
5. **Hash password**: `password_hash()` PHP = bcrypt `$2y$`. Library target harus verifikasi `$2y$` (umumnya kompatibel `$2b$`/`$2a$`).
6. **Nama cookie sesi `AMANAHSESS`** boleh berubah (sesi lama otomatis gugur saat cutover).
7. **JWT secret & format** boleh berubah bila aplikasi mobile dirilis ulang bersamaan; kalau tidak, pertahankan (lihat 05).

## Prinsip desain yang harus dibawa

- **Stok = SUM(batch_barang.qty_tersisa)** per barang; tabel `stok` hanya cache yang dihitung ulang (`recalc_stok`). Jangan pernah update `stok` langsung tanpa recalc.
- **Semua mutasi stok dalam transaksi DB** dengan lock urut: `kunci_stok()` (barang_id ASC) → baris lain. Reverse order = deadlock (bug freeze yang sudah diperbaiki).
- **Lingkup data dari penugasan** (`user_gudang`, `user_toko`, `supplier_id`), **izin dari `izin_pengguna`**. Izin tidak pernah memperluas lingkup.
- **FEFO** (kedaluwarsa terdekat keluar dulu; NULL expired paling akhir).
- **Dokumen dulu, stok kemudian**: perpindahan hanya menyentuh stok saat `sahkan_perpindahan()` (disetujui) dan `terapkan_perpindahan()` (diterima).

## Cara pakai untuk tim build

1. Pasang DB dari `02_skema_db.sql`, muat data contoh (`data_contoh.php` lama → ekspor SQL).
2. Implementasi 03 dulu sebagai modul murni + jalankan 08 §Unit (FEFO, stok, perpindahan, opname, pelepasan). Lulus semua sebelum sentuh UI/API.
3. Implementasi 04 (otorisasi) + 07 (sesi/keamanan).
4. API (05) lalu UI (06).
5. Uji integrasi 08 §Alur.

## Yang sengaja tidak dispesifikasikan

- Tampilan visual persis (lihat `DESIGN.md` lama dan `assets/amanah.css` untuk warna/tipografi bila ingin sama).
- Integrasi marketplace Shopee/Tokopedia (`_shopee.php`, `_tokopedia.php`) — stub di kode lama, belum produksi. Tabel tetap ada di skema.
- Google Drive backup (`cadangan_google.php`) — opsional, cukup backup lokal.
