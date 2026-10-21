-- =====================================================================
-- Status 'dibatalkan' untuk dokumen perpindahan — 13 September 2026
-- =====================================================================
-- Sebelumnya tidak ada cara membatalkan surat jalan: draft yang salah
-- tinggal jadi sampah permanen, dan penerimaan.php menyuruh "hubungi
-- pengirim agar dokumennya dibatalkan" tanpa ada tombolnya.
-- Aman dijalankan berulang.
ALTER TABLE perpindahan
  MODIFY status ENUM('draft','menunggu_persetujuan','disetujui','ditolak',
                     'dikirim','diterima','selesai','dibatalkan')
         NOT NULL DEFAULT 'draft';
