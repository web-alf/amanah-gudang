/* =============================================================================
   AMANAH — Ambil foto langsung dari kamera untuk isian <input type="file">
   =============================================================================
   Dipakai isian Foto Produk. Sebelumnya satu-satunya cara adalah "Pilih File",
   yang berarti: fotonya harus sudah ada lebih dulu. Petugas yang memegang
   barangnya di gudang harus memfoto pakai ponsel, memindahkannya ke komputer,
   baru mengunggahnya — tiga langkah yang cukup untuk membuat orang berhenti
   melengkapi data. Padahal foto produk WAJIB untuk barang baru.

   -----------------------------------------------------------------------------
   HASILNYA MASUK SEBAGAI BERKAS UNGGAHAN SUNGGUHAN
   -----------------------------------------------------------------------------
   Foto dari kanvas dipasang ke <input type="file"> lewat DataTransfer, bukan
   dikirim sebagai teks base64 di kolom tersembunyi.

   Itu keputusan keamanan, bukan selera. Server memeriksa unggahan dengan
   is_uploaded_file() — pemeriksaan yang memastikan berkas yang dibaca benar
   berasal dari unggahan permintaan ini. Jalur base64 tidak bisa melewati
   pemeriksaan itu, jadi menambahkannya berarti membuka jalur kedua ke folder
   unggahan yang lolos dari seluruh pemeriksaan yang sudah ada: batas ukuran,
   getimagesize(), dan daftar putih format. Dengan DataTransfer, tidak ada
   satu baris pun kode server yang perlu berubah.

   -----------------------------------------------------------------------------
   KALAU KAMERANYA TIDAK BISA DIPAKAI
   -----------------------------------------------------------------------------
   getUserMedia butuh HTTPS (atau localhost), izin pemakai, dan kamera yang
   ada. Bila salah satunya tidak terpenuhi, tombolnya JATUH KE isian berkas
   ber-atribut `capture` — di ponsel itu membuka aplikasi kamera bawaan.
   Jadi jalur "foto langsung" tetap ada bahkan di tempat getUserMedia ditolak.

   Cara pakai (deklaratif, seperti data-scan-ke di scan.js):

     <button type="button" data-kamera-untuk="fotoBarang">Foto dengan Kamera</button>
     <input type="file" id="fotoBarang" name="foto" accept="image/*"
            data-kamera-pratinjau="kotakPratinjau">
     <div id="kotakPratinjau">…</div>

   `data-kamera-pratinjau` menempel pada ISIAN BERKASnya, bukan tombolnya:
   pratinjau juga disegarkan saat berkas dipilih lewat "Pilih File", dan
   jalur itu tidak melewati tombol mana pun.
   ============================================================================= */
(function () {
  'use strict';

  /* Dimuat bersama markup-nya, dan markup itu bisa muncul dua kali di satu
     halaman (formulir penuh + popup). Berkas yang dieksekusi dua kali akan
     memasang dua penangan klik, dan satu klik membuka dua panel kamera —
     dua permintaan izin, dua lampu kamera menyala. */
  if (window.__kameraFotoSiap) { return; }
  window.__kameraFotoSiap = true;

  var MAKS_SISI  = 1600;              // sisi terpanjang hasil foto, piksel
  var MAKS_BYTE  = 2 * 1024 * 1024;   // sepadan dengan MAKS_UNGGAHAN di _lib.php
  var MUTU       = [0.85, 0.7, 0.55, 0.4];

  var panel = null, video = null, stream = null;
  var sasaran = null, pesanEl = null;
  var kameraDepan = false;

  /* --------------------------------------------------------------- utilitas */

  function lolos(s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
  }

  function pesan(teks, jenis) {
    if (!pesanEl) { return; }
    pesanEl.textContent = teks || '';
    pesanEl.className = 'kamera-pesan' + (jenis ? ' kamera-' + jenis : '');
  }

  function matikanStream() {
    if (stream) {
      /* WAJIB dihentikan per-track. Membuang elemen <video> saja tidak
         melepas kameranya: lampu indikator tetap menyala dan pemakai
         menyimpulkan aplikasinya merekam diam-diam. */
      stream.getTracks().forEach(function (t) { t.stop(); });
      stream = null;
    }
    if (video) { video.srcObject = null; }
  }

  /* ----------------------------------------------------------------- panel */

  function bangunPanel() {
    var p = document.createElement('div');
    p.className = 'panel-kamera';
    p.hidden = true;
    p.innerHTML =
      '<div class="panel-kamera-kepala">' +
        '<b>Ambil Foto Produk</b>' +
        '<button type="button" class="btn btn-ghost btn-sm" data-kamera-tutup>Tutup</button>' +
      '</div>' +
      '<div class="panel-kamera-bingkai">' +
        '<video playsinline muted autoplay></video>' +
      '</div>' +
      '<p class="kamera-pesan" data-kamera-pesan></p>' +
      '<div class="panel-kamera-kaki">' +
        '<button type="button" class="btn btn-ghost btn-sm" data-kamera-balik>' +
          '<svg aria-hidden="true"><use href="#i-putar"></use></svg> Kamera depan/belakang</button>' +
        '<button type="button" class="btn btn-primary btn-sm" data-kamera-jepret>' +
          '<svg aria-hidden="true"><use href="#i-kamera"></use></svg> Jepret</button>' +
      '</div>';

    video  = p.querySelector('video');
    pesanEl = p.querySelector('[data-kamera-pesan]');
    p.querySelector('[data-kamera-tutup]').addEventListener('click', tutup);
    p.querySelector('[data-kamera-jepret]').addEventListener('click', jepret);
    p.querySelector('[data-kamera-balik]').addEventListener('click', balikKamera);
    return p;
  }

  function buka(inputEl, tombol) {
    sasaran = inputEl;
    if (!panel) { panel = bangunPanel(); }

    /* Diselipkan MENEMPEL di bawah tombolnya, bukan mengapung di atas
       halaman. Formulir ini juga tampil sebagai popup di halaman daftar;
       panel mengapung di dalam popup berarti berurusan dengan lapisan,
       kunci gulir, dan penutupan bertumpuk — tiga sumber cacat untuk
       masalah yang tidak ada kalau panelnya duduk di alirannya sendiri.

       Dipindahkan setiap kali dibuka: satu panel dipakai bersama, dan
       halaman bisa memuat isian ini dua kali. */
    var titik = tombol.parentNode;
    if (titik) { titik.insertBefore(panel, tombol.nextSibling); }

    panel.hidden = false;
    pesan('Menyiapkan kamera…');
    mulaiStream();
  }

  function tutup() {
    matikanStream();
    if (panel) { panel.hidden = true; }
  }

  function mulaiStream() {
    matikanStream();
    var pilihan = {
      video: {
        facingMode: kameraDepan ? 'user' : 'environment',
        /* Diminta besar supaya foto produk masih terbaca saat diperbesar;
           kanvas nanti mengecilkannya ke MAKS_SISI. Ini permintaan ideal,
           bukan syarat — peramban memberi yang terdekat yang ia punya. */
        width:  { ideal: 1920 },
        height: { ideal: 1440 }
      },
      audio: false
    };

    if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
      jatuhKeBerkas('Peramban ini tidak mendukung kamera langsung.');
      return;
    }

    navigator.mediaDevices.getUserMedia(pilihan)
      .then(function (s) {
        stream = s;
        video.srcObject = s;
        pesan('Arahkan ke produk, lalu tekan Jepret.');
      })
      .catch(function (e) {
        /* Sebabnya disebut apa adanya. "Gagal membuka kamera" membuat orang
           mencoba lagi berulang untuk masalah yang tidak akan berubah —
           izin yang diblokir tidak sembuh dengan diklik lagi. */
        var n = e && e.name;
        if (n === 'NotAllowedError' || n === 'SecurityError') {
          jatuhKeBerkas('Izin kamera ditolak. Aktifkan izin kamera untuk situs ini di pengaturan peramban.');
        } else if (n === 'NotFoundError' || n === 'DevicesNotFoundError') {
          jatuhKeBerkas('Tidak ada kamera yang terdeteksi di perangkat ini.');
        } else if (window.isSecureContext === false) {
          jatuhKeBerkas('Kamera langsung hanya bisa dipakai lewat HTTPS.');
        } else {
          jatuhKeBerkas('Kamera tidak bisa dibuka' + (n ? ' (' + n + ')' : '') + '.');
        }
      });
  }

  function balikKamera() {
    kameraDepan = !kameraDepan;
    pesan('Mengganti kamera…');
    mulaiStream();
  }

  /* ------------------------------------------------------ jalur cadangan */

  /**
   * Buka kamera bawaan perangkat lewat <input capture>.
   *
   * Bukan pesan galat yang berhenti di situ: di ponsel, inilah cara yang
   * paling sering berhasil, dan hasilnya justru lebih baik daripada
   * getUserMedia (aplikasi kamera bawaan punya fokus & HDR-nya sendiri).
   */
  var isianCadangan = null;

  function jatuhKeBerkas(sebab) {
    tutup();
    var inputEl = sasaran;
    if (!inputEl) { return; }

    /* SATU isian, dibuat sekali dan dipakai terus.
       Versi pertama membuat isian baru tiap kali dan membuangnya di
       penangan 'change' — padahal membatalkan aplikasi kamera tidak
       membangkitkan 'change' sama sekali. Setiap pembatalan meninggalkan
       satu <input> tersembunyi di halaman, menumpuk selama halaman itu
       dibuka. Satu isian tetap membuat masalahnya tidak ada, bukan
       ditangani. */
    if (!isianCadangan) {
      isianCadangan = document.createElement('input');
      isianCadangan.type = 'file';
      isianCadangan.accept = 'image/*';
      isianCadangan.setAttribute('capture', 'environment');
      isianCadangan.style.display = 'none';
      document.body.appendChild(isianCadangan);

      isianCadangan.addEventListener('change', function () {
        if (isianCadangan.files && isianCadangan.files.length && sasaran) {
          pasangBerkas(sasaran, isianCadangan.files[0]);
        }
        /* Dikosongkan supaya memfoto ulang berkas dengan nama yang sama
           tetap membangkitkan 'change' — isian berkas tidak berubah nilai
           bila berkas yang dipilih sama dengan sebelumnya. */
        isianCadangan.value = '';
      });
    }

    /* Sebabnya dicatat, bukan dipampangkan sebagai penghalang: di ponsel
       jalur ini justru yang berhasil, dan pesan galat di depan kamera yang
       sebenarnya terbuka hanya membuat orang berhenti mencoba. */
    if (sebab) { console.info('[AMANAH] kamera langsung: ' + sebab); }
    isianCadangan.click();
  }

  /* ----------------------------------------------------------- pengambilan */

  function jepret() {
    if (!stream || !video || !video.videoWidth) {
      pesan('Kameranya belum siap. Tunggu sebentar lalu coba lagi.', 'galat');
      return;
    }

    var w = video.videoWidth, h = video.videoHeight;
    var skala = Math.min(1, MAKS_SISI / Math.max(w, h));
    var kanvas = document.createElement('canvas');
    kanvas.width  = Math.round(w * skala);
    kanvas.height = Math.round(h * skala);
    kanvas.getContext('2d').drawImage(video, 0, 0, kanvas.width, kanvas.height);

    pesan('Memproses foto…');
    kodekan(kanvas, 0);
  }

  /**
   * Encode berulang sampai muat di bawah batas server.
   *
   * Bertingkat, bukan sekali di mutu rendah: kamera 12 MP menghasilkan
   * berkas jauh di bawah 2 MB pada mutu 0,85 setelah dikecilkan, jadi
   * menurunkan mutu sejak awal hanya membuang ketajaman yang justru
   * diperlukan untuk mencocokkan produk di rak.
   */
  function kodekan(kanvas, i) {
    kanvas.toBlob(function (blob) {
      if (!blob) {
        pesan('Peramban gagal mengubah gambar. Coba pakai Pilih File.', 'galat');
        return;
      }
      if (blob.size > MAKS_BYTE && i + 1 < MUTU.length) {
        kodekan(kanvas, i + 1);
        return;
      }
      if (blob.size > MAKS_BYTE) {
        pesan('Foto masih ' + (blob.size / 1048576).toFixed(2) + ' MB, di atas batas 2 MB. '
            + 'Coba mundur sedikit atau kurangi cahaya latar.', 'galat');
        return;
      }

      var nama = 'kamera-' + Date.now() + '.jpg';
      var berkas;
      try {
        berkas = new File([blob], nama, { type: 'image/jpeg' });
      } catch (e) {
        /* Safari lama tidak punya konstruktor File. Blob diberi nama
           supaya tetap bisa dipasang lewat DataTransfer. */
        berkas = blob;
        berkas.name = nama;
      }
      if (pasangBerkas(sasaran, berkas)) { tutup(); }
    }, 'image/jpeg', MUTU[i]);
  }

  /**
   * Pasang berkas ke <input type="file"> dan segarkan pratinjaunya.
   * Mengembalikan false bila gagal — supaya panelnya TIDAK ditutup dan
   * fotonya tidak hilang tanpa jejak.
   */
  function pasangBerkas(inputEl, berkas) {
    if (!inputEl) { return false; }
    try {
      var dt = new DataTransfer();
      dt.items.add(berkas);
      inputEl.files = dt.files;
    } catch (e) {
      pesan('Peramban ini tidak mengizinkan foto dipasang ke formulir. '
          + 'Pakai tombol Pilih File.', 'galat');
      return false;
    }

    /* Diperiksa, bukan diandaikan. Sebagian peramban menerima penugasan
       .files tanpa galat lalu mengabaikannya — dan gagal begitu berarti
       orang menekan Simpan dengan yakin, lalu ditolak "foto wajib". */
    if (!inputEl.files || inputEl.files.length !== 1) {
      pesan('Foto gagal dipasang ke formulir. Pakai tombol Pilih File.', 'galat');
      return false;
    }

    /* Pratinjaunya TIDAK digambar dari sini.
       'change' di bawah sudah menggambarnya untuk setiap perubahan isian —
       termasuk lewat "Pilih File" — jadi memanggilnya di sini berarti dua
       kali kerja, dua objek URL, dan satu perlombaan: URL pertama dilepas
       saat gambarnya termuat, tepat ketika src-nya sedang diganti ke URL
       kedua. Terlihat di log jaringan sebagai dua permintaan blob gagal. */
    inputEl.dispatchEvent(new Event('change', { bubbles: true }));
    return true;
  }

  function segarkanPratinjau(inputEl, berkas) {
    /* Satu sumber: atribut pada ISIAN BERKASnya sendiri. Sebelumnya nilai
       ini bisa datang dari dua tempat — tombol dan isian — yang boleh saja
       berbeda. Dua sumber untuk satu jawaban berarti suatu hari keduanya
       tidak sama, dan pratinjaunya menggambar di kotak yang salah. */
    var kotak = inputEl.dataset.kameraPratinjau
      ? document.getElementById(inputEl.dataset.kameraPratinjau) : null;
    if (!kotak) { return; }

    var url = URL.createObjectURL(berkas);
    kotak.innerHTML = '<img src="' + lolos(url) + '" alt="Pratinjau foto" ' +
                      'style="width:100%;height:100%;object-fit:cover">';
    /* Objek URL dilepas setelah gambarnya termuat. Tanpa ini, mengambil
       foto berulang kali menahan setiap bidikan lama di memori sampai
       halamannya ditutup. */
    var img = kotak.querySelector('img');
    img.addEventListener('load', function () { URL.revokeObjectURL(url); });
  }

  /* ------------------------------------------------------------ pemasangan */

  document.addEventListener('click', function (ev) {
    var t = ev.target.closest ? ev.target.closest('[data-kamera-untuk]') : null;
    if (!t) { return; }
    ev.preventDefault();

    var inputEl = document.getElementById(t.getAttribute('data-kamera-untuk'));
    if (!inputEl) { return; }
    buka(inputEl, t);
  });

  /* Foto yang dipilih lewat "Pilih File" juga menyegarkan pratinjaunya.
     Dulu pratinjaunya hanya menampilkan foto yang SUDAH tersimpan, jadi
     setelah memilih berkas tidak ada cara memastikan yang terpilih benar
     sampai halamannya disimpan. */
  document.addEventListener('change', function (ev) {
    var inputEl = ev.target;
    if (!inputEl || inputEl.type !== 'file' || !inputEl.dataset.kameraPratinjau) { return; }
    if (inputEl.files && inputEl.files.length) {
      segarkanPratinjau(inputEl, inputEl.files[0]);
    }
  });

  /* Kamera dimatikan saat halaman ditinggalkan, termasuk lewat tombol
     Kembali — navigasi tidak selalu menjalankan pembersihan sendiri. */
  window.addEventListener('pagehide', matikanStream);

  document.addEventListener('keydown', function (ev) {
    if (ev.key === 'Escape' && panel && !panel.hidden) { tutup(); }
  });
})();
