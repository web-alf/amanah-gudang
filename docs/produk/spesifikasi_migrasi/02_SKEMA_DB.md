# 02 — Skema DB: Semantik

DDL lengkap: `02_skema_db.sql` (dump MariaDB 10.11, 55 tabel + 14 view). Berkas ini menjelaskan **arti** kolom yang tidak terbaca dari DDL dan aturan yang harus dijaga aplikasi.

## Peta tabel per domain

| Domain | Tabel |
|---|---|
| Master | `barang`, `kategori`, `gudang`, `lokasi_rak`, `toko`, `supplier`, `penerima`, `donatur`, `roles`, `users`, `user_gudang`, `user_toko`, `izin_pengguna`, `pengaturan` |
| Stok | `batch_barang` (sumber kebenaran), `stok` (cache per barang), `stok_supplier`, `stok_harian` (snapshot), `mutasi_barang` (jurnal) |
| Masuk/Keluar langsung | `transaksi_masuk(+_detail)`, `transaksi_keluar(+_detail)`, `donasi(+_detail)` |
| Perpindahan | `perpindahan`, `perpindahan_detail`, `perpindahan_mutasi` (jejak untuk pembalikan), `perpindahan_titik` (transit), `kardus`, `kardus_isi`, `kardus_riwayat` |
| Supplier | `pengiriman_supplier(+_detail)`, `retur` |
| Toko | `penjualan(+_detail)`, `marketplace_*` |
| Koreksi | `stock_opname(+_detail)`, `penyesuaian_stok`, `pelepasan_stok` |
| Sistem | `dokumen_counter`, `activity_log`, `galat_sistem`, `notifikasi`, `login_gagal`, `sesi_perangkat`, `reset_sandi`, `resi_scan_log`, `cadangan_log`, `migrasi_terpasang` |

## Aturan invariant (aplikasi wajib jaga)

1. **`stok.qty_total` = `SUM(batch_barang.qty_tersisa) WHERE barang_id=? AND toko_id IS NULL`**. Stok toko (`toko_id NOT NULL`) tidak ikut. Hitung ulang via `recalc_stok(barang_id)` setelah setiap mutasi. Lihat kasus uji "recalc_stok mengabaikan stok toko (26, bukan 46)".
2. **`batch_barang.qty_tersisa >= 0`** selalu. Pengurangan dicek dulu (`qty > qty_tersisa` → tolak), bukan dibiarkan negatif lalu dikoreksi.
3. **Batch ada di tepat satu lokasi**: `lokasi_rak_id` XOR `toko_id` (satu terisi, satu NULL). Keduanya kosong atau keduanya terisi → tolak (`tambah_ke_lokasi`).
4. **`uq_batch_lokasi`** = `(barang_id, nomor_batch, kondisi, sumber, donatur_id, lokasi_rak_id, toko_id, tanggal_expired, kardus_id)`. Barang masuk dengan kombinasi sama → **gabung** (tambah qty_tersisa & qty_awal), bukan baris baru. `harga_beli` tidak ditimpa bila sudah >0.
5. **Kondisi layak salur** (`kondisi_layak_salur()`): `baru`, `bekas_layak`. `bekas_tidak_layak`, `rusak`, `expired` tidak boleh keluar lewat penyaluran/keluar — hanya lewat `pelepasan_stok` atau `penyesuaian_stok`.
6. **`sumber`** (`pembelian`|`donasi`) terpisah dari `kondisi`. Batch berdonatur dipaksa `sumber='donasi'`.
7. **Nomor dokumen unik** per tabel: `uq_pp_no`, `uq_penyesuaian_no`, `uq_pelepasan_no`, dst. Dihasilkan dari `dokumen_counter` (lihat 03).
8. **Soft delete**: `deleted_at` pada `barang`, `gudang`, `lokasi_rak`, `toko`, `supplier`, `users`, `penerima`, `donatur`. Query master selalu `deleted_at IS NULL`. Gudang/barang juga punya `is_active`.

## Kolom kunci yang perlu dijelaskan

### `batch_barang`
| Kolom | Arti |
|---|---|
| `nomor_batch` | Bebas, dari supplier/petugas. Default masuk langsung: `'B' . date('ymd')`. Hasil perbaikan: `<asal>-R`. |
| `kondisi` | Lihat enum `KONDISI_BARANG` (04). |
| `kardus_id` | Batch yang sedang dikemas dalam kardus (status kardus `siap_dikirim`). Query stok umum pakai `AND bb.kardus_id IS NULL` agar barang terkemas tidak dihitung bebas. |
| `qr_payload` | Teks QR label batch. |
| `donatur_id` | Diwarisi dari `stok_supplier` / donasi. Dipakai laporan donasi & peringkat donatur. |

### `perpindahan`
| Kolom | Arti |
|---|---|
| `jenis` | 8 nilai; rute sah per jenis di 04. |
| `asal_tipe/asal_id/asal_teks` | `eksternal` → `asal_id` NULL, `asal_teks` wajib. |
| `tujuan_tipe/tujuan_id/tujuan_teks` | `penerima` → boleh `tujuan_id` (terdaftar) ATAU `tujuan_teks` (sekali pakai). |
| `no_resi` | **Tidak pernah NULL** saat dibuat: kosong → diisi `no_perpindahan`. Scan resi mencari `WHERE no_resi = ?`. |
| `no_surat_jalan` | Terisi bila dokumen lahir dari `pengiriman_supplier`. |
| `status` | `draft` → `menunggu_persetujuan` → (`dikirim` → `selesai`) \| `selesai` \| `ditolak`; `dibatalkan` dari menunggu/dikirim. `disetujui`, `diterima` **legacy** — baca sebagai `dikirim`/`selesai` tapi jangan tulis. |
| `approved_by/approved_at` | Diisi saat setujui **dan** saat tolak. |
| `diterima_oleh/diterima_at/catatan_terima` | Diisi saat penerimaan. |
| `printed_at/print_count` | Surat jalan dicetak. |

### `perpindahan_detail`
| Kolom | Arti |
|---|---|
| `qty` | Jumlah di surat jalan. |
| `qty_diterima` | NULL sampai diterima. Stok tujuan bertambah **sebesar `qty_diterima`**, stok asal berkurang **sebesar `qty_diterima`** (sisa `qty - qty_diterima` dikembalikan ke asal; lihat 03 `terapkan_perpindahan`). |
| `selisih_catatan` | Wajib bila `qty_diterima < qty`. |
| `batch_id`, `kondisi`, `donatur_id`, `sumber` | Diisi saat `sahkan_perpindahan` dari batch FEFO yang dipotong; dibawa ke batch tujuan. |
| `kardus_id` | Kelompok kardus baris ini. |

### `perpindahan_mutasi`
Jejak per potongan batch saat sah (`batch_asal_id`, `qty`) dan saat terima (`batch_tujuan_id`). Dipakai `balikkan_mutasi_perpindahan()` untuk pembatalan/ubah dokumen agar stok kembali ke batch yang **sama persis**, bukan batch baru.

### `perpindahan_titik`
Transit maks 3 (`MAKS_TITIK_TRANSIT`). `lokasi_tipe` `gudang`|`toko`. `tiba_at/tiba_oleh`, `berangkat_at/berangkat_oleh`, `kendaraan(60)`, `no_polisi(20)`, `pengemudi(100)`.

### `penyesuaian_stok`
Hasil opname / koreksi manual. `jenis`: `rusak`, `hilang`, `kedaluwarsa`, `koreksi_tambah`, `koreksi_kurang`. `qty` selalu positif; arah dari `jenis`. `lokasi_tipe` + (`lokasi_rak_id` | `toko_id`).

### `pelepasan_stok`
Barang keluar **bukan** penyaluran: `perbaikan` (status `proses` sampai ditutup), `dibuang`, `dijual` (wajib `no_bukti_setor`). `hasil` ujung perbaikan: `layak` (→ `batch_kembali_id`), `dibuang`, `dijual`.

### `kardus`
`kode` = `KRD-...` otomatis. `status` lihat state machine 04. `perpindahan_id` dokumen yang membawanya. `foto_terima` path relatif `unggahan/`.

### `users`
| Kolom | Arti |
|---|---|
| `tingkat` | `staff`\|`manager`; diabaikan untuk `super_admin`/`pimpinan` (selalu manager). |
| `idle_menit` | Default 30; `43200` = 30 hari (pilihan "ingat saya"). |
| `gudang_id` | Legacy gudang utama; **penugasan aktual di `user_gudang`** (banyak gudang). |
| `supplier_id` | Akun peran `supplier`. |
| `password` | bcrypt `$2y$`. |

### `izin_pengguna`
`(user_id, kunci, nilai 0|1)`. Kunci katalog: `kelola_master`, `hapus`, `lihat_nilai`, `lihat_laporan`, `kontak_donatur`, `masuk_gudang_toko`, `setujui`. Tidak ada baris = pakai default (04 §Izin). Simpan: `DELETE WHERE user_id` lalu `INSERT` semua kunci katalog.

### `dokumen_counter`
`kunci` = `"<tabel>.<kolom>.<PREFIX>-<YYYYMMDD>-"`, `urut` counter. Lihat 03.

### `sesi_perangkat`
Satu baris per login aktif (`user_id`, `sesi_id`, `perangkat`, `ip`, `terakhir_at`). Maks `MAKS_PERANGKAT = 3` per user; login ke-4 mencabut yang paling lama.

### `login_gagal`
Throttle: `(email, ip, created_at)`. Jendela 600 dtk; maks 5 per email, 30 per IP.

## Urutan lock (WAJIB, cegah deadlock)

```
1. kunci_stok(barang_ids)      -- SELECT ... FROM stok WHERE barang_id IN (...) ORDER BY barang_id FOR UPDATE
                               -- (INSERT IGNORE baris stok dulu bila belum ada)
2. perpindahan / penyesuaian_stok / pelepasan_stok  FOR UPDATE (baris dokumen)
3. batch_barang FOR UPDATE (urut id ASC / FEFO)
```
Bug freeze lama: opname mengunci `batch_barang` sebelum `stok` → deadlock dengan `keluar.php`. Sudah dibetulkan; jangan diulang.

## Kolom opsional (kode lama cek `kolom_ada()`)

Di build baru semua **dianggap ada** (sudah di DDL): `perpindahan_detail.kardus_id`, `batch_barang.kardus_id`, `transaksi_keluar.keperluan`, `donasi.cara_terima`, `donasi.jenis`, `transaksi_masuk.donasi_id`. Hapus logika kondisional.

## View

14 view (`v_*`) hanya untuk laporan/baca; tidak ada logika tulis bergantung view. Boleh diganti query aplikasi, tetapi pertahankan untuk kompatibilitas laporan SQL eksternal: `v_stok_rantai`, `v_stok_per_gudang`, `v_stok_toko`, `v_stok_kondisi`, `v_status_stok`, `v_status_expired`, `v_expired_rantai`, `v_perpindahan_ringkas`, `v_penyaluran_penerima`, `v_pelepasan`, `v_penjualan_harian`, `v_peringkat_donatur`, `v_perangkat_aktif`, `v_petugas_gudang`.

## Migrasi skema

Kode lama: `migrasi.php` + `db/*.sql` + tabel `migrasi_terpasang(nama, dipasang_at)`. Build baru: pakai tool migrasi framework target; `02_skema_db.sql` adalah **baseline v3.0**. Isi `migrasi_terpasang` dengan semua nama berkas `db/*.sql` saat cutover agar `migrasi.php` lama (bila masih dijalankan) tidak mengulang.
