/* ===========================================================================
   DRAF FORMULIR — satu mesin, dipakai beberapa formulir
   ===========================================================================
   Formulir keranjang di aplikasi ini panjang: belasan baris barang, masing-
   masing dengan qty, batch, dan tanggal. Mengisinya makan waktu, dan
   seluruhnya hilang begitu petugas menekan Kembali atau salah menutup tab.
   Peramban memang memulihkan isian sederhana saat Kembali, tetapi TIDAK
   memulihkan baris tabel yang dibuat JavaScript — dan di formulir ini
   justru itulah seluruh isinya.

   Disimpan di localStorage, bukan di server. Alasannya: draf ini milik satu
   orang di satu perangkat, belum tentu akan diselesaikan, dan menyimpannya
   di server berarti tabel penuh dokumen setengah jadi yang tidak pernah ada
   yang membersihkan.

   ---------------------------------------------------------------------------
   KENAPA BERKAS TERSENDIRI

   Mesin ini lahir di dalam _perpindahan_js.php dan bekerja baik di sana.
   Ketika formulir Barang Masuk (gudang & admin) dan Barang Masuk Mitra
   (supplier) ikut membutuhkannya, pilihannya dua: menyalinnya dua kali, atau
   memindahkannya ke sini. Disalin, tiga salinan akan berselisih pada
   perbaikan pertama yang hanya disentuh di salah satunya — dan yang
   berselisih diam-diam pada fitur penyelamat pekerjaan adalah yang paling
   mahal, karena kegagalannya baru ketahuan saat pekerjaan sudah hilang.

   Yang berbeda antar formulir diserahkan lewat opsi; yang sama tinggal di
   sini.
   ---------------------------------------------------------------------------

   Pemakaian:

     pasangDrafFormulir({
       form:        'formMasuk',          // id <form>
       tbody:       'isiKeranjang',       // id <tbody> baris keranjang
       awalan:      'amanah_draf_masuk_', // awalan kunci localStorage
       tambahBaris: function (b) {...},   // membangun ulang satu baris
       idTawaran:   'tawaranDraf', idKet: 'drafKet',
       idPulihkan:  'drafPulihkan', idBuang: 'drafBuang',
       idPenanda:   'penandaDraf'
     });

   Opsi lain yang boleh dihilangkan:
     varian()        -> akhiran kunci, untuk formulir yang satu berkas tapi
                        beberapa jenis pekerjaan (dipakai Perpindahan)
     layak(d)        -> apakah draf ini pantas disimpan; bawaannya "ada
                        barangnya atau ada catatannya"
     ringkas(d)      -> kalimat keterangan di kotak tawaran
     sebelumPulih(d) -> dijalankan sebelum baris dibangun ulang
     lewati          -> true berarti jangan menawarkan pemulihan sama sekali
   =========================================================================== */
(function () {
  'use strict';

  /* Umur draf. Draf yang ditinggalkan berhari-hari hampir pasti sudah tidak
     relevan — barangnya mungkin sudah dicatat lewat dokumen lain — dan
     memulihkannya diam-diam lebih berbahaya daripada membuangnya. */
  var UMUR_DRAF_MS = 3 * 24 * 60 * 60 * 1000;   // 3 hari

  /* localStorage MELEMPAR di mode privat sebagian peramban dan saat kuotanya
     penuh. Draf yang gagal disimpan tidak boleh menjatuhkan formulirnya —
     kehilangan draf jauh lebih ringan daripada halaman yang berhenti
     bekerja. */
  function amanSimpan(k, v) {
    try { window.localStorage.setItem(k, v); } catch (e) { /* diabaikan */ }
  }
  function amanBaca(k) {
    try { return window.localStorage.getItem(k); } catch (e) { return null; }
  }
  function amanHapus(k) {
    try { window.localStorage.removeItem(k); } catch (e) { /* diabaikan */ }
  }

  function waktuLalu(ms) {
    var d = Math.floor((Date.now() - ms) / 1000);
    if (d < 60)    { return 'beberapa detik lalu'; }
    if (d < 3600)  { return Math.floor(d / 60) + ' menit lalu'; }
    if (d < 86400) { return Math.floor(d / 3600) + ' jam lalu'; }
    return Math.floor(d / 86400) + ' hari lalu';
  }

  window.pasangDrafFormulir = function (o) {
    var frm = typeof o.form === 'string' ? document.getElementById(o.form) : o.form;
    if (!frm) { return null; }

    var AWALAN = o.awalan;
    var varian = o.varian || function () { return ''; };

    function kunci() { return AWALAN + (varian() || 'umum'); }

    /* Kunci yang sedang ditulisi. Dipakai supaya mengganti jenis pekerjaan di
       tengah pengisian tidak meninggalkan draf yatim di kunci yang lama —
       orang itu tidak membuat dua dokumen, ia berubah pikiran tentang satu. */
    var kunciAktif = null;

    function tbodyEl() {
      return o.tbody ? document.getElementById(o.tbody) : null;
    }

    /** Semua draf milik formulir ini, terbaru dulu, yang kedaluwarsa dibuang. */
    function semuaDraf() {
      var keluar = [], kunciLS = [];
      try {
        /* Kuncinya dikumpulkan LEBIH DULU, baru dibaca. Menghapus sambil
           menyusuri localStorage lewat key(i) menggeser indeksnya, dan
           satu draf akan terlewat tanpa ada yang tahu. */
        for (var i = 0; i < window.localStorage.length; i++) {
          var k = window.localStorage.key(i);
          if (k && k.indexOf(AWALAN) === 0) { kunciLS.push(k); }
        }
      } catch (e) { return keluar; }

      kunciLS.forEach(function (k) {
        var d;
        try { d = JSON.parse(amanBaca(k)); } catch (e) { d = null; }
        if (!d || !d._t || (Date.now() - d._t) > UMUR_DRAF_MS) {
          amanHapus(k);
          return;
        }
        keluar.push({ k: k, d: d });
      });
      keluar.sort(function (a, b) { return b.d._t - a.d._t; });
      return keluar;
    }

    /** Kumpulkan seluruh isian formulir, kecuali csrf. */
    function kumpulkan() {
      var d = { _t: Date.now(), item: [], isi: {} };

      frm.querySelectorAll('input, select, textarea').forEach(function (el) {
        if (!el.name || el.name === 'csrf' || el.type === 'file') { return; }
        /* Isian bernama akhiran [] adalah baris keranjang — dikumpulkan
           terpisah supaya urutan antar kolomnya tetap sepadan. */
        if (el.name.slice(-2) === '[]') { return; }
        if (el.type === 'checkbox' || el.type === 'radio') {
          if (el.checked) { d.isi[el.name] = el.value; }
        } else {
          d.isi[el.name] = el.value;
        }
      });

      /* Baris keranjang dibaca PER BARIS, bukan per nama isian. Membaca per
         nama menghasilkan beberapa larik terpisah yang harus dicocokkan ulang
         menurut indeks — dan satu baris yang dihapus di tengah membuat
         seluruh pencocokan bergeser. */
      var tb = tbodyEl();
      if (tb) {
        tb.querySelectorAll('tr').forEach(function (tr) {
          var b = {};
          tr.querySelectorAll('input, select').forEach(function (el) {
            if (el.name && el.name.slice(-2) === '[]') {
              b[el.name.slice(0, -2)] = el.value;
            }
          });
          if (b.barang_id) { d.item.push(b); }
        });
      }

      return d;
    }

    /* Bawaan "pantas disimpan": ada barangnya, atau ada yang diketik di
       catatan. Formulir yang benar-benar kosong tidak disimpan — kalau
       disimpan, membuka formulir lalu menutupnya tanpa mengisi apa pun akan
       meninggalkan draf yang memicu tawaran pemulihan kosong. */
    var layak = o.layak || function (d) {
      return !!(d.item.length || d.isi.catatan || d.isi.no_resi);
    };

    function tampilkanPenanda(ada) {
      var p = o.idPenanda ? document.getElementById(o.idPenanda) : null;
      if (p) { p.hidden = !ada; }
    }

    var tundaan = null;
    function simpanDraf() {
      /* Ditunda 400 ms. Tanpa itu, setiap ketikan menulis ke localStorage —
         dan menulis localStorage bersifat sinkron, jadi mengetik catatan
         panjang mulai terasa tersendat. */
      clearTimeout(tundaan);
      tundaan = setTimeout(function () {
        var d = kumpulkan();
        if (!layak(d)) { return; }

        var k = kunci();
        if (kunciAktif && kunciAktif !== k) { amanHapus(kunciAktif); }
        kunciAktif = k;

        amanSimpan(k, JSON.stringify(d));
        tampilkanPenanda(true);
      }, 400);
    }

    /** Pulihkan draf ke formulir. */
    function pulihkan(d) {
      Object.keys(d.isi || {}).forEach(function (n) {
        var el = frm.querySelector('[name="' + n + '"]');
        if (!el) { return; }
        if (el.type === 'checkbox' || el.type === 'radio') { el.checked = true; }
        else { el.value = d.isi[n]; }
      });

      /* Kesempatan bagi formulir yang dropdown-nya saling bergantung untuk
         menyegarkan dirinya SEBELUM baris dibangun — kalau sesudah, isian
         yang baru dipulihkan akan terhapus oleh penyegaran itu. */
      if (typeof o.sebelumPulih === 'function') { o.sebelumPulih(d, frm); }

      // Baris keranjang: kosongkan dulu, lalu bangun ulang dari draf.
      var tb = tbodyEl();
      if (tb) { tb.innerHTML = ''; }
      if (typeof o.tambahBaris === 'function') {
        (d.item || []).forEach(function (b) { o.tambahBaris(b); });
      }
      /* Formulir yang perlu merapikan diri SESUDAH baris dibangun (mis. pilihan
         kardus tiap baris mengikuti kartu kardus yang baru dipulihkan). */
      if (typeof o.sesudahPulih === 'function') { o.sesudahPulih(d, frm); }

      tampilkanPenanda(false);
    }

    /* --------------------------------------------------------- pemasangan */

    /* Draf DICARI di seluruh kunci berawalan ini, bukan dibaca dari kunci()
       saja.

       Ini bukan kerumitan yang dicari-cari — versi pertama memang membaca
       satu kunci, dan akibatnya tawaran pemulihan tidak pernah muncul sekali
       pun. Sebabnya: kuncinya ikut jenis pekerjaan, sedangkan saat halaman
       baru dibuka jenisnya masih kosong. Draf tersimpan di "…_kirim_toko",
       dicari di "…_umum". Fitur yang tampak jalan saat disimpan, dan
       diam-diam mati justru pada saat ia dibutuhkan. */
    var draf = null, kunciDraf = null;
    var tersimpan = semuaDraf();          // dijalankan juga saat dilewati,
    if (!o.lewati && tersimpan.length) {  // supaya yang kedaluwarsa dibersihkan
      draf      = tersimpan[0].d;
      kunciDraf = tersimpan[0].k;
    }

    if (draf && o.idTawaran) {
      /* TIDAK dipulihkan otomatis — DITAWARKAN.

         Memulihkan sendiri terasa seperti sihir sampai salah: orang membuka
         formulir untuk pekerjaan baru dan menemukannya sudah terisi barang
         dari kemarin, lalu mengirimkannya tanpa sadar. Tawaran membuat
         keputusannya milik orang, bukan milik kode. */
      var kotak = document.getElementById(o.idTawaran);
      if (kotak) {
        var ket = o.idKet ? document.getElementById(o.idKet) : null;
        if (ket) {
          var bagian = [];
          if (typeof o.ringkas === 'function') {
            var r = o.ringkas(draf);
            if (r) { bagian.push(r); }
          }
          if ((draf.item || []).length) {
            bagian.push(draf.item.length + ' baris barang');
          }
          bagian.push('tersimpan ' + waktuLalu(draf._t));
          ket.textContent = bagian.join(' · ');
        }

        kotak.hidden = false;
        var bPulih = o.idPulihkan ? document.getElementById(o.idPulihkan) : null;
        var bBuang = o.idBuang    ? document.getElementById(o.idBuang)    : null;
        if (bPulih) {
          bPulih.addEventListener('click', function () {
            pulihkan(draf);
            kunciAktif = kunciDraf;
            kotak.hidden = true;
          });
        }
        if (bBuang) {
          bBuang.addEventListener('click', function () {
            amanHapus(kunciDraf);
            kotak.hidden = true;
          });
        }
      }
    }

    /* Disimpan pada 'input' DAN 'change' — 'input' menangkap ketikan,
       'change' menangkap dropdown dan pemilih tanggal yang tidak
       membangkitkan 'input' di semua peramban. */
    frm.addEventListener('input', simpanDraf);
    frm.addEventListener('change', simpanDraf);

    /* ----------------------------------------------------------------------
       BARIS YANG DITAMBAH/DIHAPUS LEWAT KODE

       Kedua pengait di atas hanya menangkap apa yang diketik orang. Padahal
       baris keranjang justru paling banyak masuk lewat kode: "Pilih Banyak
       Barang", "Tempel Kode", pemindai kamera, dan tombol hapus baris —
       tidak satu pun membangkitkan input/change pada formulir.

       Akibatnya nyata dan terukur: mencentang tiga barang di panel
       menghasilkan tiga baris di layar, tetapi draf tetap berisi satu baris
       lama. Justru pekerjaan terbesar — keranjang tiga puluh baris hasil
       tempel kode — yang paling tidak terlindungi.

       Diamati lewat MutationObserver, bukan dengan memanggil simpanDraf()
       dari tiap pemanggil. Pemanggilnya banyak dan akan bertambah; satu
       pengamat pada tabelnya berlaku untuk semuanya, termasuk yang belum
       ditulis. Aturannya jadi sederhana: apa pun yang ada di layar, itulah
       yang tersimpan.
       ---------------------------------------------------------------------- */
    var tb = tbodyEl();
    if (tb && typeof MutationObserver === 'function') {
      new MutationObserver(simpanDraf).observe(tb, { childList: true });
    }

    /* Draf dibuang saat formulir BERHASIL dikirim.
       Dibuang di 'submit', bukan sesudah balasan server: halaman ini
       berpindah alamat setelah simpan berhasil, jadi tidak ada lagi
       kesempatan menjalankan kode. Kalau server menolak, formulirnya dirender
       ulang berisi isian yang sama dan draf tersimpan lagi pada ketikan
       berikutnya. */
    frm.addEventListener('submit', function () {
      clearTimeout(tundaan);
      /* Kunci aktif DAN kunci menurut keadaan sekarang — keduanya, karena
         jenis pekerjaan bisa berubah setelah simpanan terakhir dan sebelum
         kiriman, sehingga draf yang tertinggal belum tentu yang aktif. Draf
         berkunci LAIN sengaja tidak disentuh: itu pekerjaan lain yang masih
         ditinggalkan setengah jadi. */
      amanHapus(kunci());
      if (kunciAktif) { amanHapus(kunciAktif); }
    });

    /* Dibuka untuk jalur yang mengubah nilai baris yang SUDAH ada — itu tidak
       mengubah struktur tabel, jadi pengamat di atas tidak melihatnya. */
    return { segarkan: simpanDraf, buang: function () { amanHapus(kunci()); } };
  };
})();
