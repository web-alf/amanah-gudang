# Dokumentasi AMANAH

Folder ini tertutup dari web (`.htaccess`). Mulai dari sini.

| Butuh apa | Buka |
|---|---|
| Memperbarui hosting (urutan unggah + migrasi terakhir) | `BACA_DULU.txt` |
| Instalasi baru / cara pakai | `operasional/PANDUAN_INSTALASI.md` |
| Syarat server (PHP, ekstensi, cron, kuota) | `operasional/SPESIFIKASI_HOSTING.md` |
| Apa & mengapa produk ini (visi, peran, modul) | `produk/PRD_AMANAH.md` |
| Build ulang di stack lain (skema DB, algoritma FEFO/lock) | `produk/spesifikasi_migrasi/00_README.md` |
| Sejarah perubahan per rilis | `riwayat/` (lihat di bawah) |

## Struktur

```
docs/
├── README.md                 ← indeks ini
├── BACA_DULU.txt             ← pembaruan hosting TERAKHIR (selalu diperbarui tiap rilis)
├── produk/                   ← sumber kebenaran produk; bila beda dengan kode, kode yang benar
│   ├── PRD_AMANAH.md
│   └── spesifikasi_migrasi/  ← 00_README, 01_PRD, 02_SKEMA_DB(.sql), 03_ALGORITMA_INTI
├── operasional/              ← dipakai saat pasang / pindah server
│   ├── PANDUAN_INSTALASI.md
│   └── SPESIFIKASI_HOSTING.md
└── riwayat/                  ← arsip; tidak diubah lagi, urut tanggal
    ├── DAFTAR_UNGGAH.md                      2026-09-01
    ├── DELTA_UNGGAH_LANJUTAN.md              2026-09-01 (lanjutan)
    ├── PANDUAN_UNGGAH.md                     2026-09-01
    ├── ANALISIS_KEKURANGAN.md                2026-09-13 (audit)
    ├── CATATAN_PERUBAHAN_2026-09-13.md       2026-09-13
    ├── CATATAN_REVISI_TIM_GUDANG_2026-10.md  2026-10
    ├── CATATAN_KARDUS_DAN_CADANGAN_2026-10.md 2026-10
    └── CATATAN_REVISI_2026-10-18.md          2026-10-18
```

## Aturan

- **Rilis baru** → tulis `riwayat/CATATAN_REVISI_<tanggal>.md`, lalu timpa `BACA_DULU.txt` dengan urutan unggah terbaru. `BACA_DULU.txt` hanya memuat rilis terakhir.
- **Ubah perilaku produk** → perbarui `produk/PRD_AMANAH.md` dan bagian terkait di `spesifikasi_migrasi/`.
- **Ubah syarat server** → perbarui `operasional/`.
- Berkas di `riwayat/` tidak disunting; catatan lama yang keliru dikoreksi di catatan baru.
- Rujuk berkas dengan path relatif dari root repo (`docs/operasional/...`).
