    </div><!-- /content -->
    <footer style="padding:18px 32px 30px;color:var(--muted-2);font-size:11.5px;text-align:center;
                   border-top:1px solid var(--border)">
      <?= e(ident(APP_NAMA . ' v' . APP_VERSI, APP_KEPANJANGAN, APP_LEMBAGA)) ?>
    </footer>
  </div><!-- /main -->
</div><!-- /app -->

<div id="toast-wrap" aria-live="polite" aria-atomic="false"></div>

<?php /* Lapisan gelap di belakang formulir popup. Digambar sekali di sini,
         bukan di tiap halaman: tujuh halaman memakainya, dan satu halaman
         yang lupa menuliskannya akan punya popup tanpa latar — cacat yang
         hanya kelihatan di halaman itu saja. Tetap ada walau tak ada popup
         di halamannya; CSS yang menyembunyikannya, dan bobotnya nihil. */ ?>
<div class="lapis-modal" id="lapisModal" aria-hidden="true"></div>

<!-- Penanda versi dari filemtime() — lihat aset() di _header.php. -->
<script src="<?= e(aset('assets/scan.js')) ?>"></script>
<script>
/* =====================================================================
   AMANAH — perilaku antarmuka
   Sengaja tanpa pustaka: seluruhnya di bawah 3 KB dan bekerja luring.
   ===================================================================== */

/* ------------------------------------------------- ANGKA KPI PAS MUAT
   Angka besar di kartu KPI ditulis sebesar mungkin, lalu DIKECILKAN
   secukupnya kalau tidak muat.

   Kenapa perlu: "Rp1.284.750.900" butuh 240px, sedangkan kartu KPI hanya
   menyediakan 174px di layar 1440px — angkanya terpotong. Ini bukan
   akibat ukuran huruf yang baru; dengan ukuran lama pun tetap meluber,
   hanya belum terlihat karena data uji masih kecil.

   Diselesaikan di sini, bukan di CSS, karena yang menentukan bukan lebar
   kartunya melainkan panjang angkanya — dan CSS tidak bisa mengukur itu.
   Nilai yang pendek tetap tampil besar; hanya yang panjang yang menyusut. */
function paskanAngkaKPI(){
  var v = document.querySelectorAll('.kpi-v, .kpi .v');
  for (var i = 0; i < v.length; i++) {
    var el = v[i];
    el.style.fontSize = '';                       // kembali ke ukuran CSS
    if (!el.clientWidth) continue;                // tersembunyi — lewati
    var uk = parseFloat(getComputedStyle(el).fontSize);
    var batas = 13;                               // jangan sampai tak terbaca
    while (el.scrollWidth > el.clientWidth + 1 && uk > batas) {
      uk -= 1;
      el.style.fontSize = uk + 'px';
    }
  }
}
document.addEventListener('DOMContentLoaded', paskanAngkaKPI);
window.addEventListener('load', paskanAngkaKPI);  // setelah huruf termuat
var _tundaPas;
window.addEventListener('resize', function(){
  clearTimeout(_tundaPas);
  _tundaPas = setTimeout(paskanAngkaKPI, 120);
});

/* ---------------------------------------------------------- SIDEBAR */
function toggleSidebar(){
  var s = document.getElementById('sidebar'), o = document.getElementById('overlay');
  var buka = !s.classList.contains('open');
  s.classList.toggle('open', buka);
  o.classList.toggle('show', buka);
}

/* --------------------------------------------- LIPAT MENU JADI REL
   Menyimpan pilihan di cookie, bukan localStorage: _header.php
   membacanya DI SERVER supaya halaman langsung tergambar pada lebar
   yang benar. Dengan localStorage, sidebar akan berkedip lebar lalu
   menyempit setiap kali pindah halaman. */
function toggleRail(){
  var h = document.documentElement;
  var rapat = h.getAttribute('data-nav') !== 'rapat';
  if (rapat) { h.setAttribute('data-nav', 'rapat'); }
  else       { h.removeAttribute('data-nav'); }
  document.cookie = 'amanah_nav=' + (rapat ? 'rapat' : 'lebar')
                  + ';path=/;max-age=31536000;samesite=Lax';
  var b = document.getElementById('railBtn');
  if (b) b.setAttribute('aria-pressed', rapat ? 'true' : 'false');
  /* Lebar kartu KPI berubah, jadi angkanya perlu dipaskan ulang.
     Ditunggu sampai animasi lebar sidebar selesai (220ms di CSS). */
  setTimeout(paskanAngkaKPI, 260);
}

/* ------------------------------------------------- BARIS RINCIAN
   Membuka rincian isi sebuah dokumen tepat di bawah barisnya.
   Dipasang sekali di sini dengan pendengar tunggal di dokumen, bukan satu
   pendengar per tombol: baris tabel bisa digambar ulang oleh penyaring
   atau penomoran halaman, dan pendengar yang menempel pada tombol lama
   ikut hilang bersamanya. */
document.addEventListener('click', function(e){
  var t = e.target.closest ? e.target.closest('[data-rinci]') : null;
  if (!t) return;
  var baris = document.getElementById(t.getAttribute('data-rinci'));
  if (!baris) return;
  var akanBuka = baris.hasAttribute('hidden');
  if (akanBuka) { baris.removeAttribute('hidden'); }
  else          { baris.setAttribute('hidden',''); }
  t.setAttribute('aria-expanded', akanBuka ? 'true' : 'false');
});

/* Ctrl+B — jalan pintas baku untuk melipat panel samping di hampir
   semua editor & alat kerja, jadi tidak perlu dihafal. */
document.addEventListener('keydown', function(e){
  if ((e.ctrlKey || e.metaKey) && (e.key === 'b' || e.key === 'B')) {
    if (window.innerWidth <= 900) return;   /* di ponsel tidak berlaku */
    e.preventDefault();
    toggleRail();
  }
});

/* ------------------------------------------------------------- TEMA */
/* Terang adalah bawaan (sama dengan aplikasi Android); pilihan pengguna disimpan di cookie agar tidak
   ikut hilang saat pindah halaman. */
function setTheme(t){
  document.documentElement.setAttribute('data-theme', t);
  var u = document.querySelector('#themeBtn use');
  if (u) u.setAttribute('href', t === 'dark' ? '#i-bulan' : '#i-matahari');
  var meta = document.querySelector('meta[name=theme-color]');
  if (meta) meta.setAttribute('content', t === 'dark' ? '#0B1020' : '#F7F8FC');
  document.cookie = 'amanah_theme=' + t + ';path=/;max-age=31536000;samesite=Lax';
}
function toggleTheme(){
  setTheme(document.documentElement.getAttribute('data-theme') === 'dark' ? 'light' : 'dark');
}
(function(){   /* selaraskan ikon dengan tema yang sudah dirender server */
  var t = document.documentElement.getAttribute('data-theme');
  var u = document.querySelector('#themeBtn use');
  if (u) u.setAttribute('href', t === 'dark' ? '#i-bulan' : '#i-matahari');
})();

/* ---------------------------------------------------- MENU PENGGUNA */
/* Keluar tinggal di sini, bukan di sidebar. */
function toggleUserMenu(paksaTutup){
  var m = document.getElementById('usermenu');
  if (!m) return;
  var buka = paksaTutup === true ? false : m.dataset.buka !== '1';
  m.dataset.buka = buka ? '1' : '0';
  var chip = m.querySelector('.userchip');
  if (chip) chip.setAttribute('aria-expanded', buka ? 'true' : 'false');
}
/* Klik di luar menutup menu; Escape juga. */
document.addEventListener('click', function(e){
  var m = document.getElementById('usermenu');
  if (m && m.dataset.buka === '1' && !m.contains(e.target)) toggleUserMenu(true);
});
document.addEventListener('keydown', function(e){
  if (e.key === 'Escape') toggleUserMenu(true);
});

/* -------------------------------------------------------- PENCARIAN */
/* Ctrl+/ memfokuskan kotak cari dari halaman mana pun — nomor resi dan
   surat jalan adalah hal yang paling sering dicari petugas. */
document.addEventListener('keydown', function(e){
  if ((e.ctrlKey || e.metaKey) && e.key === '/'){
    var i = document.getElementById('cariGlobal');
    if (i){ e.preventDefault(); i.focus(); i.select(); }
  }
  if (e.key === 'Escape'){
    var s = document.getElementById('sidebar');
    if (s && s.classList.contains('open')) toggleSidebar();
  }
});

/* ------------------------------------------------------------ TOAST */
/* Menggantikan flash yang menggeser tata letak. Dipanggil dari halaman:
   toast('ok', 'Barang ditambahkan', 'BRG-016'); */
var TOAST_IKON = {ok:'#i-cek-bulat', err:'#i-x', warn:'#i-alert', info:'#i-info'};
var TOAST_WARNA = {ok:'var(--green)', err:'var(--red)', warn:'var(--amber)', info:'var(--info)'};
function toast(tipe, judul, rinci, detik){
  var w = document.getElementById('toast-wrap');
  if (!w) return;
  tipe = TOAST_IKON[tipe] ? tipe : 'info';
  var el = document.createElement('div');
  el.className = 'toast';
  el.style.setProperty('--tc', TOAST_WARNA[tipe]);
  el.innerHTML = '<svg aria-hidden="true"><use href="' + TOAST_IKON[tipe] + '"></use></svg>' +
    '<div><b></b><small></small></div>' +
    '<button type="button" aria-label="Tutup">&times;</button>';
  el.querySelector('b').textContent = judul || '';
  el.querySelector('small').textContent = rinci || '';
  var tutup = function(){
    el.classList.add('tutup');
    setTimeout(function(){ el.remove(); }, 220);
  };
  el.querySelector('button').onclick = tutup;
  w.appendChild(el);
  setTimeout(tutup, (detik || 5) * 1000);
}

/* Flash dari server diubah jadi toast supaya tidak mendorong konten.
   Hanya dilakukan bila halaman tidak sedang dicetak. */
(function(){
  var f = document.querySelector('.content > .flash');
  if (!f || f.dataset.tetap) return;
  var teks = f.textContent.trim();
  if (!teks) return;
  toast(f.classList.contains('flash-ok') ? 'ok' : 'err', teks, '', 6);
  f.remove();
})();

/* ------------------------------------------------- KONFIRMASI HAPUS */
/* Mengganti confirm() bawaan browser: dialog ini bisa menyebut APA yang
   dihapus beserta akibatnya. Dipakai dengan menandai form:
     <form method="post" data-konfirm="Hapus produk ini?"
           data-obj="Pampers M" data-info="BRG-004 · stok 8 unit"
           data-akibat="Riwayat transaksinya tetap tersimpan.">  */
document.addEventListener('submit', function(e){
  var f = e.target;
  if (!f.dataset || !f.dataset.konfirm || f.dataset.sudah) return;
  e.preventDefault();
  /* Tombol yang menekan DIINGAT: formulir dengan beberapa tombol bernama
     (name="aksi" value="jalankan"/"tandai") mengirim nilainya lewat tombol
     itu, dan form.submit() membuangnya — server menerima aksi kosong. */
  var penekan = e.submitter || null;

  var d = document.createElement('dialog');
  d.className = 'modal';
  d.style.setProperty('--mc', 'var(--red)');
  d.innerHTML =
    '<div class="modal-h">' +
      '<span class="modal-ic"><svg aria-hidden="true"><use href="#i-alert"></use></svg></span>' +
      '<div><b class="j"></b><small>Tindakan ini tidak bisa dibatalkan.</small></div>' +
    '</div>' +
    '<div class="modal-b">' +
      '<div class="modal-obj" hidden><b class="o"></b><small class="i"></small></div>' +
      '<p class="a" style="margin:0"></p>' +
    '</div>' +
    '<div class="modal-f">' +
      '<button type="button" class="btn btn-ghost btn-sm batal">Batal</button>' +
      '<button type="button" class="btn btn-red btn-sm lanjut">Ya, hapus</button>' +
    '</div>';

  /* Tombol dan warnanya mengikuti tindakannya. Dialog ini dulu selalu
     berkata "Ya, hapus" berwarna merah — salah kaprah untuk "Setujui
     dokumen" atau "Nonaktifkan akun". Formulir boleh menyebut sendiri lewat
     data-tombol="Ya, setujui" dan data-warna="green|amber|primary"; tanpa
     itu, yang berawalan "Hapus" tetap merah dan sisanya memakai warna utama. */
  var teksK  = f.dataset.konfirm || '';
  var hapus  = /^\s*hapus/i.test(teksK);
  var tombol = f.dataset.tombol || (hapus ? 'Ya, hapus' : 'Ya, lanjutkan');
  var warna  = f.dataset.warna  || (hapus ? 'red' : 'primary');
  var btnL = d.querySelector('.lanjut');
  btnL.textContent = tombol;
  btnL.className = 'btn btn-sm lanjut ' + (warna === 'red' ? 'btn-red' : 'btn-primary');
  d.style.setProperty('--mc', 'var(--' + warna + ')');
  d.querySelector('.modal-h small').textContent = hapus ? 'Tindakan ini tidak bisa dibatalkan.' : 'Periksa sekali lagi sebelum melanjutkan.';

  d.querySelector('.j').textContent = f.dataset.konfirm;
  if (f.dataset.obj){
    var box = d.querySelector('.modal-obj');
    box.hidden = false;
    box.querySelector('.o').textContent = f.dataset.obj;
    box.querySelector('.i').textContent = f.dataset.info || '';
  }
  d.querySelector('.a').textContent = f.dataset.akibat || '';

  /* --------------------------------------------------------------------
     TINDAKAN BERAT: NAMANYA HARUS DIKETIK ULANG

     Sebagian tindakan terlalu besar untuk dijaga satu klik. Mengosongkan
     gudang misalnya: tombolnya ada di baris tabel, bersebelahan dengan
     gudang lain, dan menekan "Ya, lanjutkan" adalah refleks — sedangkan
     mengetik "Gudang Depo" bukan. Penjaga ini khusus melawan satu
     kesalahan yang paling mahal: menekan tombol di BARIS YANG SALAH.

     Formulir memintanya dengan data-ketik="Gudang Depo". Tanpa atribut
     itu, dialognya berperilaku persis seperti sebelumnya.
     -------------------------------------------------------------------- */
  var harusKetik = f.dataset.ketik || '';
  if (harusKetik) {
    var bungkus = document.createElement('div');
    bungkus.style.marginTop = '14px';
    bungkus.innerHTML =
      '<label style="display:block;font-size:12.5px;margin-bottom:6px">' +
        'Ketik <b class="ketik-nama"></b> untuk melanjutkan</label>' +
      '<input type="text" class="ketik-isi" autocomplete="off" ' +
        'style="width:100%;padding:9px 11px;border:1px solid var(--border);' +
        'border-radius:9px;background:var(--surface);color:var(--text)">';
    bungkus.querySelector('.ketik-nama').textContent = harusKetik;
    d.querySelector('.modal-b').appendChild(bungkus);

    var isi = bungkus.querySelector('.ketik-isi');
    btnL.disabled = true;
    isi.addEventListener('input', function(){
      btnL.disabled = isi.value.trim() !== harusKetik;
    });
    /* Enter di kotak ini menjalankan tombolnya bila teksnya sudah cocok —
       tanpa itu, Enter menutup <dialog> tanpa melakukan apa pun, dan orang
       menyangka tombolnya rusak. */
    isi.addEventListener('keydown', function(ev){
      if (ev.key === 'Enter') {
        ev.preventDefault();
        if (!btnL.disabled) { btnL.click(); }
      }
    });
    setTimeout(function(){ isi.focus(); }, 30);
  }

  document.body.appendChild(d);
  d.showModal();
  d.querySelector('.batal').onclick = function(){ d.close(); d.remove(); };
  d.querySelector('.lanjut').onclick = function(){
    f.dataset.sudah = '1';
    d.close(); d.remove();
    if (typeof f.requestSubmit === 'function') {
      // Mengirim ulang LEWAT peristiwa submit, jadi nilai tombolnya ikut.
      f.requestSubmit(penekan || undefined);
    } else {
      if (penekan && penekan.name) {
        var h = document.createElement('input');
        h.type = 'hidden'; h.name = penekan.name; h.value = penekan.value;
        f.appendChild(h);
      }
      f.submit();
    }
  };
}, true);

/* ------------------------------------------------ JUMLAH TIDAK WAJAR */
/* Barcode yang tertembak ke kotak Jumlah menghasilkan angka belasan digit.
   Server sudah menolaknya (MAKS_QTY_BARIS di _lib.php); penjaga ini
   menghentikannya SEBELUM formulir terkirim dan menyorot kotaknya, supaya
   petugas tidak kehilangan isi keranjang hanya untuk tahu satu angka salah. */
document.addEventListener('submit', function(e){
  var f = e.target, salah = null;
  if (!f || !f.querySelectorAll) return;
  f.querySelectorAll('input[type="number"]').forEach(function(i){
    var n = (i.name || '').toLowerCase();
    if (salah || !/qty|jumlah/.test(n)) return;
    if (parseFloat(i.value) > 1000000) salah = i;
  });
  if (!salah) return;
  e.preventDefault(); e.stopPropagation();
  salah.focus(); salah.select();
  salah.style.outline = '2px solid var(--red)';
  setTimeout(function(){ salah.style.outline = ''; }, 4000);
  if (typeof toast === 'function') {
    toast('err', 'Jumlah tidak wajar', 'Angka ' + salah.value + ' terlalu besar — sepertinya barcode tertembak ke kotak Jumlah. Betulkan lalu simpan lagi.', 8);
  } else { alert('Jumlah ' + salah.value + ' tidak wajar. Periksa lagi.'); }
}, true);

/* ------------------------------------------------ KEADAAN SIBUK */
/* Setiap formulir POST mengunci tombol kirimnya begitu terkirim. Tanpa
   ini, persetujuan (yang menggerakkan stok) dan impor bisa terkirim dua
   kali oleh satu klik ganda — dan yang kedua tidak selalu ditolak server.
   Dikunci lewat setTimeout supaya nilai tombol yang menekan (name/value)
   sudah terbaca ke dalam data formulir sebelum tombolnya dinonaktifkan.
   Formulir yang tidak ingin dikunci menandai diri dengan data-tanpa-kunci. */
document.addEventListener('submit', function(e){
  var f = e.target;
  if (e.defaultPrevented || !f || f.dataset.tanpaKunci !== undefined) return;
  if ((f.getAttribute('method') || 'get').toLowerCase() !== 'post') return;
  setTimeout(function(){
    f.setAttribute('aria-busy', 'true');
    f.querySelectorAll('button[type="submit"],button:not([type]),input[type="submit"]').forEach(function(b){
      if (b.disabled) return;
      b.disabled = true; b.dataset.sibuk = '1'; b.classList.add('sibuk');
    });
  }, 0);
  /* Bila halaman tidak jadi berpindah (mis. unduhan CSV), kuncinya dibuka
     lagi setelah beberapa detik supaya tombolnya tidak mati. */
  setTimeout(function(){
    f.removeAttribute('aria-busy');
    f.querySelectorAll('[data-sibuk]').forEach(function(b){ b.disabled = false; b.classList.remove('sibuk'); delete b.dataset.sibuk; });
  }, 8000);
});
window.addEventListener('pageshow', function(){
  document.querySelectorAll('[data-sibuk]').forEach(function(b){ b.disabled = false; b.classList.remove('sibuk'); delete b.dataset.sibuk; });
});

/* ------------------------------------------------- FORMULIR POPUP */
/* Formulir tambah/edit muncul sebagai popup. Seluruh mesinnya di sini, satu
   tempat — tujuh halaman memakai pola yang sama, dan tujuh salinan skrip
   yang mirip akan cepat berbeda perilakunya. */

/** Panel popup di halaman ini, apa pun namanya. */
function panelForm(){
  /* Dua id dicoba: halaman master data memakai formTambah, pengguna.php
     memakai formAkun. */
  return document.getElementById('formTambah') || document.getElementById('formAkun');
}

var fokusSebelum = null;

function bukaFormTambah(ev){
  if (ev) ev.preventDefault();
  var f = panelForm();
  if (!f) return;

  /* Elemen yang tadi dipegang keyboard diingat, supaya saat popup ditutup
     fokusnya kembali ke tombol Tambah — bukan terlempar ke awal halaman.
     Bagi yang menavigasi dengan Tab saja, itu bedanya antara melanjutkan
     dari tempat semula dan mengulang dari atas. */
  fokusSebelum = document.activeElement;

  f.classList.add('terbuka');
  document.body.classList.add('modal-buka');
  f.scrollTop = 0;

  var inp = f.querySelector('input:not([type=hidden]):not([type=checkbox]), select, textarea');
  // Ditunda satu putaran: elemen yang baru berubah dari display:none belum
  // bisa menerima fokus di sebagian peramban.
  if (inp) setTimeout(function(){ inp.focus(); }, 60);
}

function tutupFormTambah(ev){
  var f = panelForm();
  /* Diperiksa DULU, baru preventDefault. Kalau dibalik, tombol Batal di
     halaman penuh (barang_form.php — tidak punya popup sama sekali) akan
     kehilangan aksi tautannya dan tampak tidak berfungsi. */
  if (!f) return;
  if (ev) ev.preventDefault();
  f.classList.remove('terbuka');
  document.body.classList.remove('modal-buka');

  /* :target juga bisa membuka panel (jaring pengaman tanpa JavaScript).
     Kalau hash-nya dibiarkan, panel yang baru ditutup akan langsung terbuka
     lagi oleh CSS. Dibersihkan dengan replaceState supaya tidak menambah
     satu langkah di tombol Kembali peramban. */
  if (location.hash === '#formTambah' || location.hash === '#formAkun') {
    if (history.replaceState) { history.replaceState(null, '', location.pathname + location.search); }
    else { location.hash = ''; }
  }
  if (fokusSebelum && fokusSebelum.focus) { fokusSebelum.focus(); }
  fokusSebelum = null;
}

/** Panel ini sedang MENYUNTING baris yang sudah ada? */
function panelModeSunting(f){
  /* Ketujuh formulir memakai <input type="hidden" name="id">; nilainya 0 saat
     menambah dan berisi id saat menyunting. Dibaca dari situ, bukan dari URL:
     ?q=… (pencarian) juga membuat URL berisi parameter, dan itu bukan mode
     sunting — menutup popup tidak boleh menghapus kata pencarian orang. */
  var id = f.querySelector('input[type=hidden][name=id]');
  return !!id && parseInt(id.value, 10) > 0;
}

/**
 * Boleh ditutup? Meminta konfirmasi bila ada yang sudah diketik.
 * Satu tempat, dipakai tombol tutup, klik di luar, dan Esc — supaya
 * ketiganya tidak bisa berbeda perilaku.
 */
function bolehTutup(f){
  if (!f || !formTerisi(f)) { return true; }
  return confirm('Tutup formulir? Isian yang sudah diketik akan hilang.');
}

document.addEventListener('click', function(ev){
  var tombol = ev.target.closest('[data-tutup-modal]');
  if (tombol) {
    var f = panelForm();
    /* Tanpa popup di halaman ini (mis. barang_form.php), tautannya dibiarkan
       berjalan apa adanya — itu memang tombol Batal biasa. */
    if (!f || !f.classList.contains('terbuka')) { return; }
    if (!bolehTutup(f)) { ev.preventDefault(); return; }

    /* Saat menyunting, tombol tutup dibiarkan BERJALAN sebagai tautan biasa
       ke halaman polos. Menyembunyikan panel saja akan meninggalkan URL yang
       masih ?edit=…, sehingga muat ulang membuka formulir yang sama lagi —
       dan orang menyangka penutupannya tidak tersimpan. */
    if (f && panelModeSunting(f)) { return; }

    tutupFormTambah(ev);
    return;
  }
  // Klik pada lapisan gelap = klik di luar formulir.
  if (ev.target.classList && ev.target.classList.contains('lapis-modal')) {
    var p = panelForm();
    if (!bolehTutup(p)) { return; }
    if (p && panelModeSunting(p)) { location.href = location.pathname; return; }
    tutupFormTambah(ev);
  }
});

document.addEventListener('keydown', function(ev){
  if (ev.key !== 'Escape' || !document.body.classList.contains('modal-buka')) return;
  var f = panelForm();
  /* Esc minta konfirmasi kalau ada yang sudah diketik. Formulir ini bisa
     berisi belasan isian; satu tekan Esc yang salah sasaran menghapus semuanya
     tanpa bisa dibatalkan. Yang belum disentuh ditutup begitu saja. */
  if (!bolehTutup(f)) return;
  if (f && panelModeSunting(f)) { location.href = location.pathname; return; }
  tutupFormTambah(ev);
});

/** Apakah ada isian yang berubah dari nilai awalnya? */
function formTerisi(f){
  var isian = f.querySelectorAll('input:not([type=hidden]), select, textarea');
  for (var i = 0; i < isian.length; i++) {
    var el = isian[i];
    if (el.type === 'checkbox' || el.type === 'radio') {
      if (el.checked !== el.defaultChecked) return true;
    } else if (el.tagName === 'SELECT') {
      // defaultSelected menandai <option> yang ditulis server.
      for (var j = 0; j < el.options.length; j++) {
        if (el.options[j].selected !== el.options[j].defaultSelected) return true;
      }
    } else if (el.value !== el.defaultValue) {
      return true;
    }
  }
  return false;
}

/* Panel yang dibuka server (sedang menyunting, atau ada galat validasi)
   perlu ikut menyalakan lapisan gelap dan kunci gulir — kelas .terbuka-nya
   sudah dipasang PHP, tapi <body> belum tahu apa-apa. */
if (document.querySelector('.form-lipat.terbuka')) {
  document.body.classList.add('modal-buka');
}

/* ---------------------------------------------------------------------
   Tombol tanggal cepat: "Hari ini" / "Kemarin"

   Di sini, bukan di skrip satu halaman. Markup .pilih-tanggal kini
   muncul di tiga tempat — formulir Perpindahan (popup dan halaman
   penuh), dan distribusi di Dasbor Toko — dan tiga salinan penangan
   yang sama berarti tombol yang berhenti bekerja di halaman yang lupa
   dimuati skripnya.

   Didelegasikan dari document, jadi panel yang baru dibuat JavaScript
   pun ikut bekerja tanpa perlu mendaftarkan ulang.

   Tombolnya HANYA menulis ke kolom tanggal di dalam pembungkusnya
   sendiri — bukan ke #tanggalDok global. Satu halaman bisa memuat lebih
   dari satu formulir bertanggal, dan menulis ke id global berarti tombol
   di formulir pertama mengubah tanggal formulir kedua.
   --------------------------------------------------------------------- */
document.addEventListener('click', function (ev) {
  var tbl = ev.target.closest ? ev.target.closest('.pilih-tanggal [data-tgl]') : null;
  if (!tbl) { return; }
  var isian = tbl.closest('.pilih-tanggal').querySelector('input[type=date]');
  if (isian) {
    isian.value = tbl.getAttribute('data-tgl');
    /* Kejadian 'change' dibangkitkan sendiri: menulis .value lewat skrip
       TIDAK memicunya, dan isian yang punya penanganan sendiri (mis.
       menghitung ulang sesuatu) tidak akan pernah tahu nilainya berubah. */
    isian.dispatchEvent(new Event('change', { bubbles: true }));
  }
});
</script>
<?php
/* Cadangan otomatis tanpa cron: bila setelan otomatis menyala dan cadangan
   hari ini belum dibuat, Super Admin yang sedang membuka halaman apa pun
   memicu pembuatannya di latar. Pengguna lain tidak ikut (butuh hak unduh
   cadangan), dan pemicunya sekali sehari saja — lihat cadangan_perlu_otomatis(). */
if (isset($pdo) && ($_SESSION['role'] ?? '') === 'super_admin') {
    require_once __DIR__ . '/_cadangan.php';
    if (cadangan_perlu_otomatis($pdo)): ?>
<script>setTimeout(function(){ fetch('cron_cadangan.php?otomatis=1', {credentials:'same-origin'}).catch(function(){}); }, 3000);</script>
<?php endif;
}
?>
</body>
</html>
