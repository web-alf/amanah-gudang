<?php
/**
 * AMANAH — Sprite ikon
 *
 * Ikon bergaya Lucide, ditanam langsung di halaman — BUKAN dari CDN.
 * Alasannya penting: gudang sering tanpa internet, dan scanner sudah
 * dirancang bekerja luring. Kalau ikon diambil dari jaringan, seluruh
 * antarmuka jadi buta huruf begitu koneksi mati.
 *
 * Hanya ikon yang benar-benar dipakai yang dimuat (±36 buah, ~7 KB),
 * bukan 1.500 ikon pustaka penuh.
 *
 * Pakai:  <svg class="ikon"><use href="#i-box"></use></svg>
 *
 * Tiap ikon adalah <symbol viewBox="0 0 24 24"> — BUKAN <g>. Tanpa viewBox,
 * path 24x24 digambar 1:1 di dalam kotak 18x18 dan sudutnya terpotong.
 * Warna mengikuti currentColor lewat stroke di CSS.
 */
?>
<svg width="0" height="0" style="position:absolute;overflow:hidden" aria-hidden="true" focusable="false"><defs>

<symbol id="i-box" viewBox="0 0 24 24"><path d="M21 8v8a2 2 0 0 1-1 1.73l-7 4a2 2 0 0 1-2 0l-7-4A2 2 0 0 1 3 16V8a2 2 0 0 1 1-1.73l7-4a2 2 0 0 1 2 0l7 4A2 2 0 0 1 21 8z"/><path d="m3.3 7 8.7 5 8.7-5M12 22V12"/></symbol>
<symbol id="i-layers" viewBox="0 0 24 24"><path d="m12 2 9 5-9 5-9-5 9-5z"/><path d="m3 12 9 5 9-5M3 17l9 5 9-5"/></symbol>
<symbol id="i-masuk" viewBox="0 0 24 24"><path d="M12 3v12M7 10l5 5 5-5M4 21h16"/></symbol>
<symbol id="i-keluar" viewBox="0 0 24 24"><path d="M12 21V9M7 14l5-5 5 5M4 3h16"/></symbol>
<symbol id="i-doc" viewBox="0 0 24 24"><path d="M15 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V7z"/><path d="M14 2v5h6M8 13h8M8 17h5"/></symbol>
<symbol id="i-truck" viewBox="0 0 24 24"><path d="M14 17V5a1 1 0 0 0-1-1H2v13M14 9h5l3 3v5h-3"/><circle cx="6.5" cy="18" r="2"/><circle cx="17.5" cy="18" r="2"/></symbol>
<symbol id="i-clock" viewBox="0 0 24 24"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3.5 2"/></symbol>
<?php /* Segitiga peringatan disempitkan dari 20,9 ke ±19,5 satuan dan
        ditegakkan — sebelumnya lebarnya 20,9 tapi tingginya hanya 18,
        jadi ia terbaca gepeng di samping ikon lain yang persegi. */ ?>
<symbol id="i-alert" viewBox="0 0 24 24"><path d="M10.6 4.2 2.5 17.9a1.9 1.9 0 0 0 1.6 2.9h15.8a1.9 1.9 0 0 0 1.6-2.9L13.4 4.2a1.6 1.6 0 0 0-2.8 0z"/><path d="M12 9.2v4.1M12 17.1h.01"/></symbol>
<symbol id="i-cal" viewBox="0 0 24 24"><rect x="3" y="5" width="18" height="16" rx="2"/><path d="M8 3v4M16 3v4M3 11h18"/></symbol>
<symbol id="i-uang" viewBox="0 0 24 24"><rect x="2" y="6" width="20" height="12" rx="2"/><circle cx="12" cy="12" r="2.5"/><path d="M6 12h.01M18 12h.01"/></symbol>
<symbol id="i-scan" viewBox="0 0 24 24"><path d="M3 7V5a2 2 0 0 1 2-2h2M17 3h2a2 2 0 0 1 2 2v2M21 17v2a2 2 0 0 1-2 2h-2M7 21H5a2 2 0 0 1-2-2v-2"/><path d="M7 12h10"/></symbol>
<symbol id="i-qr" viewBox="0 0 24 24"><rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/><path d="M14 14h3v3h-3zM20 14h1M14 20h1M18 20h3"/></symbol>
<symbol id="i-kamera" viewBox="0 0 24 24"><path d="M3 9a2 2 0 0 1 2-2h1.5l1.2-1.8A1 1 0 0 1 8.5 5h7a1 1 0 0 1 .8.4L17.5 7H19a2 2 0 0 1 2 2v8a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><circle cx="12" cy="13" r="3.2"/></symbol>
<symbol id="i-cek" viewBox="0 0 24 24"><path d="m20 6-11 11-5-5"/></symbol>
<symbol id="i-cek-bulat" viewBox="0 0 24 24"><circle cx="12" cy="12" r="9"/><path d="m8.5 12 2.5 2.5 4.5-5"/></symbol>
<symbol id="i-x" viewBox="0 0 24 24"><path d="M18 6 6 18M6 6l12 12"/></symbol>
<symbol id="i-info" viewBox="0 0 24 24"><circle cx="12" cy="12" r="9"/><path d="M12 11v5M12 8h.01"/></symbol>
<symbol id="i-grafik" viewBox="0 0 24 24"><path d="M3 3v18h18"/><path d="m7 15 4-5 4 3 4-7"/></symbol>
<symbol id="i-grafik-bar" viewBox="0 0 24 24"><path d="M3 3v18h18"/><rect x="7" y="12" width="3" height="6" rx=".5"/><rect x="12.5" y="8" width="3" height="10" rx=".5"/><rect x="18" y="14" width="3" height="4" rx=".5"/></symbol>
<symbol id="i-users" viewBox="0 0 24 24"><path d="M16 20v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 20v-2a4 4 0 0 0-3-3.87"/></symbol>
<?php /* Gigi roda digambar ulang. Yang lama berukuran optis 22 satuan —
        luasnya 49% lebih besar dari mayoritas ikon sidebar (18 satuan),
        jadi ia menonjol sendiri di dalam kolom menu. Jalurnya juga
        sepanjang 700 karakter penuh lengkung 1.65 satuan yang berubah
        jadi bubur begitu digambar pada 16px.
        Yang baru: delapan gigi tegas, 19 satuan, terbaca sampai kecil. */ ?>
<symbol id="i-gear" viewBox="0 0 24 24"><circle cx="12" cy="12" r="3.1"/><path d="M19.3 13.5a7.5 7.5 0 0 0 0-3l2-1.6-2-3.4-2.4 1a7.5 7.5 0 0 0-2.6-1.5L13.9 2.5h-3.8l-.4 2.5a7.5 7.5 0 0 0-2.6 1.5l-2.4-1-2 3.4 2 1.6a7.5 7.5 0 0 0 0 3l-2 1.6 2 3.4 2.4-1a7.5 7.5 0 0 0 2.6 1.5l.4 2.5h3.8l.4-2.5a7.5 7.5 0 0 0 2.6-1.5l2.4 1 2-3.4z"/></symbol>
<symbol id="i-toko" viewBox="0 0 24 24"><path d="M3 9V6a1 1 0 0 1 1-1h16a1 1 0 0 1 1 1v3"/><path d="M3 9h18l-1.2 11.1a1 1 0 0 1-1 .9H5.2a1 1 0 0 1-1-.9L3 9z"/><path d="M9 13h6"/></symbol>
<symbol id="i-gudang" viewBox="0 0 24 24"><path d="M3 21V8l9-5 9 5v13"/><path d="M3 21h18M7 21v-7h10v7M10 14v7M14 14v7"/></symbol>
<symbol id="i-paket" viewBox="0 0 24 24"><path d="M16 16v4a1 1 0 0 1-1 1H5a1 1 0 0 1-1-1V8a1 1 0 0 1 1-1h4"/><rect x="9" y="3" width="11" height="11" rx="1"/><path d="M12.5 3v4l2-1 2 1V3"/></symbol>
<symbol id="i-bantuan" viewBox="0 0 24 24"><circle cx="12" cy="12" r="9"/><path d="M9.1 9.2A3 3 0 0 1 15 10c0 2-3 2.5-3 4.2"/><path d="M12 17.5h.01"/></symbol>
<symbol id="i-cari" viewBox="0 0 24 24"><circle cx="11" cy="11" r="7"/><path d="m20.5 20.5-4.2-4.2"/></symbol>
<symbol id="i-menu" viewBox="0 0 24 24"><path d="M4 6h16M4 12h16M4 18h16"/></symbol>
<symbol id="i-bulan" viewBox="0 0 24 24"><path d="M20.5 14.4A8.5 8.5 0 1 1 9.6 3.5a6.6 6.6 0 0 0 10.9 10.9z"/></symbol>
<symbol id="i-matahari" viewBox="0 0 24 24"><circle cx="12" cy="12" r="4"/><path d="M12 2v2M12 20v2M4.9 4.9l1.4 1.4M17.7 17.7l1.4 1.4M2 12h2M20 12h2M4.9 19.1l1.4-1.4M17.7 6.3l1.4-1.4"/></symbol>
<symbol id="i-bel" viewBox="0 0 24 24"><path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.7 21a2 2 0 0 1-3.4 0"/></symbol>
<symbol id="i-kanan" viewBox="0 0 24 24"><path d="m9 18 6-6-6-6"/></symbol>
<symbol id="i-bawah" viewBox="0 0 24 24"><path d="m6 9 6 6 6-6"/></symbol>
<symbol id="i-panah-kanan" viewBox="0 0 24 24"><path d="M5 12h14M13 6l6 6-6 6"/></symbol>
<?php /* Pasangan tegak dari i-panah-kanan, dipakai tombol "kembali ke atas".
         Bentuknya dibuat sebangun: batang sepanjang 14 dan kepala 6 unit,
         supaya kedua ikon terlihat berasal dari satu keluarga. */ ?>
<symbol id="i-panah-atas" viewBox="0 0 24 24"><path d="M12 19V5M6 11l6-6 6 6"/></symbol>
<symbol id="i-plus" viewBox="0 0 24 24"><path d="M12 5v14M5 12h14"/></symbol>
<symbol id="i-cetak" viewBox="0 0 24 24"><path d="M6 9V3h12v6M6 18H4a2 2 0 0 1-2-2v-3a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v3a2 2 0 0 1-2 2h-2"/><rect x="6" y="14" width="12" height="8" rx="1"/></symbol>
<symbol id="i-unduh" viewBox="0 0 24 24"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4M7 10l5 5 5-5M12 15V3"/></symbol>
<symbol id="i-ubah" viewBox="0 0 24 24"><path d="M17 3a2.85 2.85 0 1 1 4 4L7.5 20.5 2 22l1.5-5.5z"/></symbol>
<symbol id="i-hapus" viewBox="0 0 24 24"><path d="M3 6h18M8 6V4a1 1 0 0 1 1-1h6a1 1 0 0 1 1 1v2M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/><path d="M10 11v6M14 11v6"/></symbol>
<symbol id="i-mata" viewBox="0 0 24 24"><path d="M2 12s3.6-7 10-7 10 7 10 7-3.6 7-10 7-10-7-10-7z"/><circle cx="12" cy="12" r="3"/></symbol>
<symbol id="i-saring" viewBox="0 0 24 24"><path d="M22 3H2l8 9.5V19l4 2v-8.5z"/></symbol>
<symbol id="i-clipboard" viewBox="0 0 24 24"><rect x="8" y="2" width="8" height="4" rx="1"/><path d="M16 4h2a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h2"/><path d="m9 14 2 2 4-4"/></symbol>
<symbol id="i-putar" viewBox="0 0 24 24"><path d="M3 12a9 9 0 0 1 15.5-6.2L21 8M21 3v5h-5"/><path d="M21 12a9 9 0 0 1-15.5 6.2L3 16M3 21v-5h5"/></symbol>
<symbol id="i-keluar-pintu" viewBox="0 0 24 24"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><path d="M16 17l5-5-5-5M21 12H9"/></symbol>
<symbol id="i-impor" viewBox="0 0 24 24"><path d="M12 3v12M7 10l5 5 5-5"/><path d="M4 17v2a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2v-2"/></symbol>
<symbol id="i-simpan" viewBox="0 0 24 24"><path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"/><path d="M17 21v-8H7v8M7 3v5h8"/></symbol>
<symbol id="i-tolak" viewBox="0 0 24 24"><circle cx="12" cy="12" r="9"/><path d="m5.6 5.6 12.8 12.8"/></symbol>
<symbol id="i-kunci" viewBox="0 0 24 24"><circle cx="8" cy="15" r="4"/><path d="m10.9 12.1 8.1-8.1M17 3l4 4-2.5 2.5-3-3"/></symbol>
<symbol id="i-lokasi" viewBox="0 0 24 24"><path d="M20 10c0 6-8 12-8 12s-8-6-8-12a8 8 0 0 1 16 0z"/><circle cx="12" cy="10" r="3"/></symbol>
<symbol id="i-minus" viewBox="0 0 24 24"><path d="M5 12h14"/></symbol>
<symbol id="i-kirim" viewBox="0 0 24 24"><path d="M22 2 11 13M22 2l-7 20-4-9-9-4 20-7z"/></symbol>
<?php /* Lipat/lebarkan sidebar. Ukuran optis 18 satuan — sama dengan
        mayoritas ikon di sidebar, supaya tidak menonjol sendiri. */ ?>
<symbol id="i-lipat" viewBox="0 0 24 24"><rect x="3" y="4" width="18" height="16" rx="2.5"/><path d="M9.5 4v16"/><path d="m16.8 9.6-2.4 2.4 2.4 2.4"/></symbol>

</defs></svg>
