# 01 — PRD

PRD lengkap ada di **`../PRD_AMANAH.md`** (15 bagian: visi, persona, alur per peran, modul, non-fungsional). Tidak diduplikasi di sini.

## Yang PRD jawab vs yang folder ini jawab

| Pertanyaan | PRD | Spec ini |
|---|---|---|
| Siapa pengguna, alur kerja harian | ✅ | — |
| Modul apa saja, nilai bisnisnya | ✅ | — |
| Skema DB persis | ringkas | ✅ 02 |
| Algoritma FEFO/lock/nomor dokumen baris per baris | — | ✅ 03 |
| State machine + string error persis | ringkas | ✅ 04 |
| Kontrak API per endpoint | daftar | ✅ 05 |
| Aksi POST per halaman | — | ✅ 06 |
| Kasus uji | — | ✅ 08 |

## Identitas aplikasi (konstanta)

```
APP_NAMA        = "AMANAH"
APP_KEPANJANGAN = "Aplikasi Manajemen Amanah & Hibah"
APP_TAGLINE     = "Setiap titipan tercatat, setiap penyaluran terlacak"
APP_VERSI       = "3.0"
```

## Peran (roles.kode)

`super_admin`, `pimpinan`, `admin_gudang`, `admin_terpadu`, `toko`, `supplier`.

- **Eksternal** (`is_eksternal()`): `toko`, `supplier`.
- **Terikat satu gudang** (`terikat_satu_gudang()`): `admin_gudang`, `admin_terpadu`.
- **Tingkat** (`users.tingkat`): `manager` | `staff`. `super_admin` & `pimpinan` selalu `manager`.
