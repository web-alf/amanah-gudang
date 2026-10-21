/* =====================================================================
   AMANAH — Input gaya scan
   ---------------------------------------------------------------------
   Satu komponen dipakai di SEMUA form yang memasukkan barang: perpindahan,
   barang masuk supplier, penjualan toko, barang masuk/keluar cepat, dan
   penerimaan.

   Tiga cara memasukkan kode, semuanya berujung ke tempat yang sama:
     1. Scanner barcode — USB berkabel, Bluetooth, maupun nirkabel
        ber-dongle. Ketiganya menyamar sebagai KEYBOARD di mata sistem
        operasi, jadi tidak ada pemasangan driver, pemasangan pustaka,
        atau setelan apa pun di aplikasi ini. Yang membedakannya dari
        tangan manusia cuma kecepatan, dan itulah yang dideteksi.
        Akhiran Enter, Tab, atau tanpa akhiran sama-sama ditangani, dan
        tembakan diterima tanpa perlu mengklik kotaknya lebih dulu.
     2. Kamera HP/laptop — memakai BarcodeDetector bawaan browser,
        tanpa pustaka luar, jadi tetap jalan saat komputer gudang offline.
     3. Ketik manual     — selalu tersedia sebagai cadangan.

   Catatan tentang Bluetooth: yang perlu dilakukan hanya memasangkan
   scanner ke komputer/HP lewat setelan Bluetooth sistem, dalam mode HID
   (kadang tertulis "Keyboard mode" atau "HID mode" di buku manualnya).
   Sesudah terpasang ia bekerja persis seperti scanner USB. Aplikasi tidak
   pernah berbicara langsung ke perangkat Bluetooth — dan memang tidak
   perlu, karena Web Bluetooth tidak diizinkan membaca perangkat HID.

   Cara pakai di halaman:

     <div data-scan
          data-lokasi="toko" data-lokasi-id="3"
          data-target="tbodyItem"></div>

     AmanahScan.pasang(el, function(barang, jumlah){ ... });

   Bila callback tidak diberikan, komponen mencari fungsi global
   `scanTambahBaris(barang, jumlah)` yang disediakan halaman.
   ===================================================================== */
(function (global) {
  'use strict';

  var Scan = {};

  /* Ambang jeda antar karakter, dalam milidetik.
   *
   * Dulu 35 ms. Itu benar untuk scanner USB berkabel, tapi terlalu ketat
   * untuk Bluetooth: sambungan BLE mengirim laporan tombol secara
   * berkelompok, dan jeda 40–60 ms per karakter sama sekali biasa —
   * terutama pada scanner hemat daya atau saat baterainya menipis.
   * Akibatnya rentetan dari scanner Bluetooth tidak pernah dikenali
   * sebagai scanner.
   *
   * 90 ms masih jauh di bawah kecepatan tangan. Pengetik cepat pun
   * berkisar 120–200 ms per karakter, dan tidak pernah rata: jedanya
   * melompat-lompat. Karena itu yang dipakai bukan satu jeda, melainkan
   * BERAPA KALI berturut-turut jedanya rapat. */
  var AMBANG_CEPAT = 90;

  /* Panjang minimum sebuah kode supaya dianggap hasil tembakan. Barcode
   * terpendek yang lazim (EAN-8) delapan angka; kode internal bisa lebih
   * pendek, jadi dipatok longgar di 3. */
  var MIN_PANJANG = 3;

  // ------------------------------------------------------------ Utilitas
  function el(tag, attrs, html) {
    var n = document.createElement(tag);
    if (attrs) Object.keys(attrs).forEach(function (k) { n.setAttribute(k, attrs[k]); });
    if (html != null) n.innerHTML = html;
    return n;
  }

  function esc(s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
  }

  // Gaya .scanbox tinggal di assets/amanah.css bersama komponen lain.
  // Dulu disuntikkan dari sini dengan warna tema terang yang dipatok
  // (#166534, #fef2f2) — tidak terbaca begitu tema gelap jadi bawaan.

  // ------------------------------------------------------------- Pasang
  Scan.pasang = function (wadah, onPilih) {
    if (!wadah || wadah.dataset.scanSiap === '1') return;
    wadah.dataset.scanSiap = '1';

    var lokasi   = wadah.dataset.lokasi   || '';
    var lokasiId = wadah.dataset.lokasiId || '';
    var judul    = wadah.dataset.judul    || 'Tambah barang dengan scan';
    var pakaiJml = wadah.dataset.jumlah !== 'tidak';

    wadah.className = (wadah.className + ' scanbox').trim();
    wadah.innerHTML =
      '<div class="baris">' +
        '<div class="kotak">' +
          '<label>' + esc(judul) + '</label>' +
          '<input class="kode" type="text" autocomplete="off" spellcheck="false" ' +
                 'placeholder="Tembak barcode, atau ketik kode / nama barang…">' +
        '</div>' +
        (pakaiJml
          ? '<div><label>Jumlah</label><input class="jml" type="number" min="1" value="1"></div>'
          : '') +
        '<button type="button" class="btn btn-primary" data-aksi="cari">Tambahkan</button>' +
        '<button type="button" class="btn btn-ghost" data-aksi="kamera">' +
          '<svg aria-hidden="true"><use href="#i-scan"></use></svg> Kamera</button>' +
      '</div>' +
      '<video playsinline muted></video>' +
      '<div class="status"></div>' +
      '<div class="petunjuk">' +
        '<b>Scanner USB / Bluetooth:</b> langsung tembak barcode-nya — tidak perlu mengklik kotak ini dulu. ' +
        'Akhiran Enter, Tab, maupun tanpa akhiran sama-sama dikenali. ' +
        '<b>Kamera:</b> hanya bisa lewat <code>localhost</code> atau HTTPS; dari alamat IP browser akan memblokirnya.' +
      '</div>';

    var inp    = wadah.querySelector('input.kode');
    var inpJml = wadah.querySelector('input.jml');
    var video  = wadah.querySelector('video');
    var status = wadah.querySelector('.status');

    /* Pewaktu pembersih disimpan dan DIBATALKAN tiap ada pesan baru. Dulu
       pesan "ditambahkan" memasang pewaktu 3,5 detik yang tidak pernah
       dibatalkan — sehingga galat pada tembakan berikutnya ikut terhapus
       oleh pewaktu milik tembakan sebelumnya, dan petugas tidak sempat
       membacanya. */
    var tBersih = null;
    function pesan(kelas, html) {
      if (tBersih) { clearTimeout(tBersih); tBersih = null; }
      status.className = 'status ' + kelas;
      status.innerHTML = html;
    }
    function bersih() { status.className = 'status'; status.innerHTML = ''; }

    function ambilJumlah() {
      var n = inpJml ? parseInt(inpJml.value, 10) : 1;
      return (isNaN(n) || n < 1) ? 1 : n;
    }

    function serahkan(barang) {
      var jml = ambilJumlah();
      /* Urutan pencarian penangannya: yang diberikan saat pasang(), lalu
         fungsi yang disebut kotaknya sendiri lewat data-fungsi="nama", baru
         scanTambahBaris global. data-fungsi ada supaya dua formulir di satu
         halaman tidak berebut satu nama fungsi global. */
      var cb  = onPilih || (wadah.dataset.fungsi && global[wadah.dataset.fungsi]) || global.scanTambahBaris;
      if (typeof cb !== 'function') {
        pesan('err', 'Halaman ini belum menyiapkan penanganan hasil scan.');
        return;
      }
      /* Penangan boleh MENOLAK dengan mengembalikan teks alasannya — mis.
         barangnya tidak ada di gudang asal, atau jumlahnya sudah mentok
         stok. Tanpa ini kotak scan tetap berkata "ditambahkan" untuk
         barang yang sebenarnya ditolak formulirnya. */
      var hasil = cb(barang, jml);
      if (typeof hasil === 'string' && hasil !== '') {
        pesan('err', esc(hasil));
        inp.select();
        return;
      }

      /* data-stok="tidak": formulir yang punya hitungan stoknya sendiri
         (mis. Distribusi, yang berbicara tentang stok GUDANG TOKO) meminta
         angka stok lembaga tidak ikut disebut — dua angka stok berbeda di
         satu formulir hanya membingungkan. */
      var stokTeks = (wadah.dataset.stok === 'tidak' || barang.stok === null || barang.stok === undefined)
        ? '' : ' · stok ' + barang.stok + ' ' + esc(barang.satuan || '');
      pesan('ok', '<b>' + esc(barang.nama) + '</b> × ' + jml + stokTeks + ' ditambahkan.');

      inp.value = '';
      if (inpJml) inpJml.value = 1;
      inp.focus();
      tBersih = setTimeout(bersih, 3500);
    }

    function tampilkanPilihan(daftar) {
      var html = 'Ditemukan ' + daftar.length + ' barang — pilih salah satu:<div class="pilihan">';
      daftar.forEach(function (b, i) {
        var stok = (b.stok === null || b.stok === undefined) ? '' : ' — stok ' + b.stok + ' ' + esc(b.satuan || '');
        html += '<button type="button" data-i="' + i + '"><b>' + esc(b.nama) + '</b> ' +
                '<span style="color:var(--muted)">' + esc(b.kode) +
                (b.merek ? ' · ' + esc(b.merek) : '') + stok + '</span></button>';
      });
      html += '</div>';
      pesan('pilih', html);
      status.querySelectorAll('button[data-i]').forEach(function (btn) {
        btn.addEventListener('click', function () { serahkan(daftar[+btn.dataset.i]); });
      });
    }

    function cari() {
      var q = inp.value.trim();
      if (!q) { inp.focus(); return; }

      pesan('pilih', 'Mencari <code>' + esc(q) + '</code>…');

      var url = 'cari_barang.php?q=' + encodeURIComponent(q) +
                '&lokasi=' + encodeURIComponent(lokasi) +
                '&id=' + encodeURIComponent(lokasiId);

      fetch(url, { credentials: 'same-origin' })
        .then(function (r) { return r.json(); })
        .then(function (j) {
          if (!j.ok) {
            pesan('err', '' + esc(j.pesan || 'Barang tidak ditemukan.') +
                         (j.saran ? '<br><span style="opacity:.8">' + esc(j.saran) + '</span>' : ''));
            inp.select();
            return;
          }
          if (j.pasti || j.data.length === 1) serahkan(j.data[0]);
          else tampilkanPilihan(j.data);
        })
        .catch(function () {
          pesan('err', 'Gagal menghubungi server. Periksa koneksi lalu coba lagi.');
        });
    }

    /* ------------------------------------- Akhiran scanner yang beragam
       Hampir semua scanner — USB berkabel, Bluetooth, maupun genggam
       nirkabel dengan dongle — menyamar sebagai KEYBOARD. Ia mengetik
       kodenya lalu mengirim satu karakter penutup. Yang berbeda-beda
       justru penutupnya, dan itu tergantung setelan pabrik tiap merek:

         Enter (CR)  paling umum
         Tab         lazim pada scanner yang disetel untuk mengisi formulir
         tidak ada   sebagian scanner dikirim tanpa akhiran sama sekali

       Dulu hanya Enter yang ditangani, jadi scanner ber-akhiran Tab
       memindahkan fokus ke kolom Jumlah tanpa mencari apa pun, dan
       scanner tanpa akhiran diam saja sampai penggunanya menekan Enter
       sendiri. Ketiganya sekarang bermuara ke cari(). */
    var terakhir = 0, cepat = 0, tungguDiam = null;

    function batalTunggu() {
      if (tungguDiam) { clearTimeout(tungguDiam); tungguDiam = null; }
    }

    inp.addEventListener('keydown', function (ev) {
      var now = Date.now(), jeda = now - terakhir;
      terakhir = now;

      if (ev.key === 'Enter' || ev.key === 'Tab') {
        // Tab hanya ditahan bila memang datang di tengah rentetan scanner.
        // Kalau orang menekan Tab untuk berpindah kolom, biarkan berpindah.
        if (ev.key === 'Tab' && cepat < 3) { return; }
        ev.preventDefault();
        batalTunggu();
        cepat = 0;
        cari();
        return;
      }

      if (ev.key.length === 1) {
        cepat = jeda < AMBANG_CEPAT ? cepat + 1 : 0;

        /* Scanner tanpa akhiran: begitu rentetannya berhenti dan kodenya
           sudah cukup panjang, pencarian dijalankan sendiri. Jedanya jauh
           lebih lama daripada jeda antar karakter scanner, sehingga orang
           yang mengetik tangan tidak pernah ikut terpicu — ia belum selesai
           mengetik dalam 320 ms. */
        batalTunggu();
        if (cepat >= 4) {
          tungguDiam = setTimeout(function () {
            tungguDiam = null;
            if (inp.value.trim().length >= 3) { cepat = 0; cari(); }
          }, 320);
        }
      }
    });

    // Dipakai penangkap global di bawah untuk menyalurkan hasil tembakan
    // ke kotak ini tanpa pengguna harus mengkliknya lebih dulu.
    wadah._scanTerima = function (kode) {
      batalTunggu();
      inp.value = kode;
      cari();
    };

    wadah.querySelector('[data-aksi="cari"]').addEventListener('click', cari);
    if (inpJml) {
      inpJml.addEventListener('keydown', function (ev) {
        if (ev.key === 'Enter') { ev.preventDefault(); cari(); }
      });
    }

    // ------------------------------------------------------- Kamera
    var stream = null, loop = null;
    var tblKamera = wadah.querySelector('[data-aksi="kamera"]');

    function hentikan() {
      if (loop) { clearInterval(loop); loop = null; }
      if (stream) { stream.getTracks().forEach(function (t) { t.stop(); }); stream = null; }
      video.classList.remove('on');
      tblKamera.innerHTML = '<svg aria-hidden="true"><use href="#i-scan"></use></svg> Kamera';
    }

    tblKamera.addEventListener('click', function () {
      if (stream) { hentikan(); return; }

      if (!global.isSecureContext) {
        pesan('err', 'Kamera diblokir browser karena halaman ini tidak dibuka lewat ' +
                     'localhost atau HTTPS. Gunakan scanner USB atau ketik manual.');
        return;
      }
      if (!('BarcodeDetector' in global)) {
        pesan('err', 'Browser ini belum mendukung pembacaan barcode lewat kamera. ' +
                     'Coba Chrome/Edge terbaru, atau gunakan scanner USB.');
        return;
      }

      navigator.mediaDevices.getUserMedia({ video: { facingMode: 'environment' } })
        .then(function (s) {
          stream = s;
          video.srcObject = s;
          video.classList.add('on');
          tblKamera.innerHTML = '<svg aria-hidden="true"><use href="#i-x"></use></svg> Hentikan';
          pesan('pilih', 'Arahkan kamera ke barcode…');
          return video.play();
        })
        .then(function () {
          var detector = new global.BarcodeDetector();
          loop = setInterval(function () {
            detector.detect(video).then(function (hasil) {
              if (hasil && hasil.length) {
                var nilai = (hasil[0].rawValue || '').trim();
                if (nilai) { inp.value = nilai; hentikan(); cari(); }
              }
            }).catch(function () { /* frame gagal dibaca, coba berikutnya */ });
          }, 350);
        })
        .catch(function (e) {
          pesan('err', 'Tidak bisa mengakses kamera: ' + esc(e.message) +
                       '. Gunakan scanner USB atau ketik manual.');
          hentikan();
        });
    });

    global.addEventListener('beforeunload', hentikan);

    /* Kotak yang terakhir disentuh diingat. Halaman yang punya dua kotak scan
       (mis. Barang Masuk dan Distribusi di tab Gudang Toko) harus menyalurkan
       tembakan tanpa-fokus ke kotak yang sedang dipakai, bukan selalu ke yang
       pertama di halaman. */
    wadah.addEventListener('focusin', function () { Scan._aktif = wadah; });
    wadah.addEventListener('click',   function () { Scan._aktif = wadah; });

    // Fokus awal supaya scanner langsung tersambung begitu halaman terbuka.
    setTimeout(function () { inp.focus(); }, 200);
  };

  // Pasang otomatis semua elemen ber-atribut data-scan.
  document.addEventListener('DOMContentLoaded', function () {
    document.querySelectorAll('[data-scan]').forEach(function (n) { Scan.pasang(n); });
  });

  /* ===================================================================
     TEMBAK DARI MANA SAJA
     -------------------------------------------------------------------
     Sebelumnya kotak scan harus DIKLIK lebih dulu supaya tembakan masuk.
     Di meja penerimaan itu tidak masuk akal: petugas memegang scanner di
     satu tangan dan barang di tangan lain, dan setiap klik berarti
     meletakkan barangnya. Lebih buruk lagi, satu klik di tempat lain —
     menutup pesan, menggulir tabel — membuat tembakan berikutnya jatuh
     entah ke mana tanpa ada tanda apa pun.

     Karena itu rentetan tombol ditangkap di tingkat dokumen, lalu
     disalurkan ke kotak scan halaman ini. Yang diambil hanya rentetan
     yang berperilaku seperti mesin.

     TIDAK mengganggu pengetikan: begitu fokus berada di kolom isian mana
     pun, penangkap ini melepaskan diri sepenuhnya. Orang yang sedang
     mengisi nama donatur atau jumlah barang tetap mengetik seperti biasa,
     dan kotak scan sendiri sudah punya penanganannya sendiri di atas.
     =================================================================== */
  (function pasangTangkapGlobal() {
    var sedangDiisi = function (n) {
      if (!n) return false;
      var tag = (n.tagName || '').toUpperCase();
      return tag === 'INPUT' || tag === 'TEXTAREA' || tag === 'SELECT' || n.isContentEditable;
    };

    var buf = '', terakhir = 0, rapat = 0, tunggu = null;

    function bersihkan() {
      buf = ''; rapat = 0;
      if (tunggu) { clearTimeout(tunggu); tunggu = null; }
    }

    function salurkan() {
      var kode = buf.trim();
      bersihkan();
      if (kode.length < MIN_PANJANG) { return; }

      // Kotak yang terakhir dipakai bila masih terlihat; kalau belum ada,
      // kotak scan pertama yang benar-benar terlihat di halaman ini.
      var kotak = (Scan._aktif && Scan._aktif._scanTerima && Scan._aktif.offsetParent !== null) ? Scan._aktif : null;
      document.querySelectorAll('[data-scan]').forEach(function (n) {
        if (kotak || !n._scanTerima) { return; }
        if (n.offsetParent !== null) { kotak = n; }
      });
      if (kotak) { kotak._scanTerima(kode); }
    }

    document.addEventListener('keydown', function (ev) {
      // Kombinasi tombol tidak pernah datang dari scanner.
      if (ev.ctrlKey || ev.altKey || ev.metaKey) { bersihkan(); return; }
      if (sedangDiisi(document.activeElement)) { return; }
      if (!document.querySelector('[data-scan]')) { return; }

      var now = Date.now(), jeda = now - terakhir;
      terakhir = now;

      if (ev.key === 'Enter' || ev.key === 'Tab') {
        if (rapat >= 3 && buf.length >= MIN_PANJANG) {
          /* Ditahan supaya Enter tidak ikut mengirim formulir di
             belakangnya, dan Tab tidak melompat ke elemen lain. */
          ev.preventDefault();
          salurkan();
        } else {
          bersihkan();
        }
        return;
      }

      if (ev.key.length !== 1) { return; }

      if (jeda < AMBANG_CEPAT) { rapat++; } else { rapat = 0; buf = ''; }
      buf += ev.key;

      /* Rentetan yang sudah jelas dari mesin ditahan karakter demi
         karakter, supaya angka dan huruf tembakan tidak nyasar memicu
         pintasan halaman atau tergambar di tempat yang tidak diinginkan. */
      if (rapat >= 3) { ev.preventDefault(); }

      // Cadangan untuk scanner tanpa akhiran.
      if (tunggu) { clearTimeout(tunggu); }
      tunggu = setTimeout(function () {
        tunggu = null;
        if (rapat >= 3) { salurkan(); } else { bersihkan(); }
      }, 320);
    });
  }());


  /* ===================================================================
     SCAN LANGSUNG KE SEBUAH KOLOM
     -------------------------------------------------------------------
     Berbeda dari Scan.pasang() di atas. Yang itu dipakai untuk MENCARI
     barang dari kodenya lalu memasukkannya ke keranjang. Yang ini cuma
     menuliskan kode hasil scan ke sebuah kolom isian — dipakai saat
     kodenya justru sedang DIDAFTARKAN, jadi belum ada apa pun untuk
     dicari.

     Cara pakai di halaman — tanpa JavaScript tambahan:

         <input name="barcode" id="barcode">
         <button type="button" data-scan-ke="barcode">Scan</button>

     Tiga cara mengisi, semuanya berujung ke kolom yang sama:
       1. Kamera HP    — tombol di atas
       2. Scanner USB  — arahkan saja ke kolomnya; Enter dari scanner
                         ditahan supaya tidak mengirim formulir setengah isi
       3. Ketik manual — selalu tersedia
     =================================================================== */

  /** Panel kamera dibuat saat dibutuhkan, bukan disiapkan di tiap halaman. */
  function panelKamera(sesudah) {
    var p = el('div', { class: 'scan-kamera' });
    p.innerHTML =
      '<video playsinline muted></video>' +
      '<div class="scan-kamera-bar">' +
        '<span class="scan-kamera-tx">Arahkan kamera ke barcode…</span>' +
        '<button type="button" class="btn btn-ghost btn-sm" data-tutup>' +
          '<svg aria-hidden="true"><use href="#i-x"></use></svg> Tutup</button>' +
      '</div>';
    sesudah.parentNode.insertBefore(p, sesudah.nextSibling);
    return p;
  }

  /**
   * Sambungkan sebuah tombol ke sebuah kolom isian.
   * @param {HTMLElement} tombol  tombol ber-atribut data-scan-ke
   */
  Scan.keIsian = function (tombol) {
    var idIsian = tombol.getAttribute('data-scan-ke');
    var isian   = document.getElementById(idIsian);
    if (!isian) { return; }

    var panel = null, video = null, stream = null, loop = null;

    function tulisPesan(teks, gagal) {
      if (!panel) { return; }
      var tx = panel.querySelector('.scan-kamera-tx');
      tx.textContent = teks;
      tx.className = 'scan-kamera-tx' + (gagal ? ' gagal' : '');
    }

    function hentikan() {
      if (loop)   { clearInterval(loop); loop = null; }
      if (stream) { stream.getTracks().forEach(function (t) { t.stop(); }); stream = null; }
      if (panel)  { panel.remove(); panel = null; video = null; }
      tombol.innerHTML = '<svg aria-hidden="true"><use href="#i-scan"></use></svg> Scan';
      tombol.setAttribute('aria-pressed', 'false');
    }

    function berhasil(nilai) {
      isian.value = nilai;
      hentikan();
      isian.focus();
      // Beri tahu halaman: kode masuk lewat scan, bukan diketik. Halaman
      // lain bisa menanggapinya tanpa harus tahu soal kamera.
      isian.dispatchEvent(new Event('input',  { bubbles: true }));
      isian.dispatchEvent(new Event('change', { bubbles: true }));
      if (typeof global.toast === 'function') {
        global.toast('ok', 'Barcode terbaca', nilai, 4);
      }
      isian.classList.add('baru-discan');
      setTimeout(function () { isian.classList.remove('baru-discan'); }, 1600);
    }

    tombol.addEventListener('click', function () {
      if (stream || panel) { hentikan(); return; }

      panel = panelKamera(tombol.closest('.isian-scan') || tombol);
      video = panel.querySelector('video');
      panel.querySelector('[data-tutup]').addEventListener('click', hentikan);
      tombol.innerHTML = '<svg aria-hidden="true"><use href="#i-x"></use></svg> Batal';
      tombol.setAttribute('aria-pressed', 'true');

      // Kamera hanya diizinkan browser di halaman aman. Ini sering jadi
      // sumber kebingungan saat aplikasi diakses lewat http:// biasa,
      // jadi pesannya menyebut sebabnya, bukan cuma "gagal".
      if (!global.isSecureContext) {
        tulisPesan('Kamera diblokir browser karena halaman ini tidak dibuka lewat HTTPS '
                 + 'atau localhost. Pakai scanner USB, atau ketik kodenya.', true);
        return;
      }
      if (!('BarcodeDetector' in global)) {
        tulisPesan('Browser ini belum bisa membaca barcode lewat kamera. Coba Chrome '
                 + 'atau Edge terbaru di HP, atau pakai scanner USB.', true);
        return;
      }

      navigator.mediaDevices.getUserMedia({ video: { facingMode: 'environment' } })
        .then(function (s) {
          if (!panel) { s.getTracks().forEach(function (t) { t.stop(); }); return null; }
          stream = s;
          video.srcObject = s;
          return video.play();
        })
        .then(function () {
          if (!stream) { return; }
          var detector = new global.BarcodeDetector();
          loop = setInterval(function () {
            if (!video) { return; }
            detector.detect(video).then(function (hasil) {
              if (hasil && hasil.length) {
                var nilai = (hasil[0].rawValue || '').trim();
                if (nilai) { berhasil(nilai); }
              }
            }).catch(function () { /* satu frame gagal — coba berikutnya */ });
          }, 300);
        })
        .catch(function (e) {
          tulisPesan('Tidak bisa mengakses kamera: ' + (e && e.message ? e.message : 'ditolak')
                   + '. Pakai scanner USB, atau ketik kodenya.', true);
        });
    });

    /* Scanner USB mengetik sangat cepat lalu menekan Enter. Enter itu akan
       MENGIRIM formulir yang baru terisi sebagian — jadi di kolom ini ia
       ditahan, dan fokus dipindahkan ke kolom berikutnya seperti yang
       diharapkan orang saat menembak barcode. */
    var ketikTerakhir = 0, cepat = 0;
    isian.addEventListener('keydown', function (ev) {
      var kini = Date.now();
      if (ev.key === 'Enter') {
        if (cepat >= 4) {          // pola khas scanner, bukan tangan manusia
          ev.preventDefault();
          cepat = 0;
          var semua = Array.prototype.slice.call(
            isian.form ? isian.form.querySelectorAll(
              'input:not([type=hidden]):not([disabled]), select, textarea') : []);
          var i = semua.indexOf(isian);
          if (i > -1 && semua[i + 1]) { semua[i + 1].focus(); }
          if (typeof global.toast === 'function') {
            global.toast('ok', 'Barcode terbaca', isian.value, 3);
          }
        }
        return;
      }
      if (ev.key.length === 1) {
        cepat = (kini - ketikTerakhir) < 35 ? cepat + 1 : 0;
        ketikTerakhir = kini;
      }
    });

    global.addEventListener('beforeunload', hentikan);
  };

  // Pasang otomatis semua tombol ber-atribut data-scan-ke.
  document.addEventListener('DOMContentLoaded', function () {
    document.querySelectorAll('[data-scan-ke]').forEach(function (t) { Scan.keIsian(t); });
  });

  global.AmanahScan = Scan;
})(window);
