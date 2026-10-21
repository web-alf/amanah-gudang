/* =====================================================================
   AMANAH — Keranjang Barang Masuk
   ---------------------------------------------------------------------
   Satu keranjang, DUA halaman:

     masuk.php                    Barang Masuk gudang lembaga
     penerimaan.php (sisi toko)   Barang Masuk gudang toko

   Sebelumnya seluruh kode di bawah tinggal di dalam <script> milik
   masuk.php. Ketika sisi toko meminta keranjang yang sama, menyalinnya
   berarti dua salinan berisi tiga belas fungsi — dan satu perbaikan
   (misalnya penggabungan baris yang barang & kondisinya sama) hanya akan
   sampai di salah satunya. Yang jarang dipakai selalu yang ketinggalan.

   ---------------------------------------------------------------------
   CARA MEMAKAI DARI HALAMAN
   ---------------------------------------------------------------------
   Halaman menyiapkan datanya lebih dulu, lalu memuat berkas ini:

     <script>
     window.KERANJANG = {
       barang:  [{id, kode, nama, satuan, harga}, ...],
       rak:     [{id, gudang, nama}, ...],
       kondisi: [{v, p}, ...],          // nilai & label enum kondisi
       batch:   'B260901',              // nomor batch bawaan
       form:    'formMasuk'             // id <form> pembungkusnya
     };
     </script>
     <script src="assets/keranjang-masuk.js"></script>

   Markup yang harus disediakan halaman — id-nya dipakai apa adanya:

     #isiKeranjang      <tbody> tempat baris ditambahkan
     #kosongKeranjang   keadaan kosong (disembunyikan saat ada isi)
     #totQty #totNilai  ringkasan di <tfoot>
     #rakBawaan         <select> rak bawaan untuk baris baru
     #kondisiBawaan     <select> kondisi bawaan untuk baris baru

   Nama isian yang dikirim SAMA di kedua halaman — barang_id[], qty[],
   nomor_batch[], tanggal_expired[], lokasi_rak_id[], kondisi[], harga[] —
   supaya sisi PHP-nya bisa membaca keduanya dengan satu cara.
   ===================================================================== */
(function () {
  'use strict';

  var K = window.KERANJANG || {};
  var BARANG        = K.barang  || [];
  var RAK           = K.rak     || [];
  var KONDISI       = K.kondisi || [];
  var BATCH_BAWAAN  = K.batch   || '';
  var ID_FORM       = K.form    || 'formMasuk';
  var nomorBaris    = 0;

  function rupiah(n){ return 'Rp' + Math.round(n).toLocaleString('id-ID'); }
  
  /* ---------------------------------------------------------------------
     KOLOM BARANG — ringan (12 Okt 2026)

     Dulu tiap baris memuat <select> berisi SELURUH katalog (±1.250 produk).
     Lima puluh baris berarti 60.000 elemen <option>, dan setiap tembakan
     barcode membangun satu daftar penuh lagi — laptop lapangan yang hemat
     daya membeku. Sekarang baris hanya menyimpan id di <input hidden> dan
     menampilkan namanya; mengganti barang lewat kotak cari kecil yang
     menggambar paling banyak 40 hasil. Nama isian tetap barang_id[], jadi
     server, draf, dan Pisah tidak perlu tahu bedanya.
     --------------------------------------------------------------------- */
  function selBarang(barangId){
    const b = BARANG.find(x => +x.id === +barangId) || {};
    return '<input type="hidden" class="b-barang" name="barang_id[]" value="' + (+barangId || 0) + '">' +
      '<div class="b-barang-nama"><b class="b-nama">' + lolos(b.nama || '—') + '</b> ' +
      '<small class="mut2 b-kode">' + lolos(b.kode || '') + '</small> ' +
      '<button type="button" class="btn btn-ghost btn-sm b-ganti" onclick="gantiBarang(this)" title="Ganti barang">ganti</button></div>';
  }
  /* Dipakai saat barang baris diganti lewat kotak cari. */
  function isiSelBarang(tr, b){
    tr.querySelector('input.b-barang').value = b.id;
    tr.querySelector('.b-nama').textContent = b.nama;
    tr.querySelector('.b-kode').textContent = b.kode || '';
  }
  function opsiRak(terpilih){
    /* Dikelompokkan per gudang. Teks opsinya hanya nama rak, sehingga yang
       tampil di kotak tertutup selalu muat — sementara nama gudang tetap
       terbaca saat dropdown dibuka, sebagai label grupnya.
  
       Urutan RAK sudah diurutkan per gudang dari SQL-nya, jadi cukup
       memperhatikan perpindahan nama gudang; tidak perlu mengurutkan ulang
       di sini. */
    let html = '', gudangKini = null;
    RAK.forEach(r => {
      if (r.gudang !== gudangKini) {
        if (gudangKini !== null) { html += '</optgroup>'; }
        html += '<optgroup label="' + lolos(r.gudang) + '">';
        gudangKini = r.gudang;
      }
      html += '<option value="' + r.id + '"' +
              (r.id === terpilih ? ' selected' : '') + '>' + lolos(r.nama) + '</option>';
    });
    if (gudangKini !== null) { html += '</optgroup>'; }
    return html;
  }
  
  /* Nama rak & gudang berasal dari basis data dan disisipkan sebagai HTML,
     jadi harus dilolosi. Tanpa ini, satu nama rak yang memuat tanda kutip
     sudah cukup merusak seluruh dropdown — dan nama yang memuat < atau >
     membuka jalan menyuntikkan markup. */
  function lolos(s){
    return String(s).replace(/&/g, '&amp;').replace(/</g, '&lt;')
                    .replace(/>/g, '&gt;').replace(/"/g, '&quot;');
  }
  

  function tambahBaris(barangId, jumlah, harga, kondisi){
    barangId = +barangId;
    /* Barang yang TIDAK ada di daftar pilihan tidak boleh dibuatkan baris.
       <select> yang tidak punya opsi terpilih menampilkan opsi PERTAMA —
       jadi baris untuk barang tak dikenal diam-diam berubah menjadi barang
       pertama menurut abjad, sementara kotak scan berkata barang yang benar
       "ditambahkan" (4 Okt 2026: Promina ditembak, ABC Sarden yang masuk
       keranjang). Mengembalikan false supaya pemanggil tahu tidak ada baris. */
    if (!BARANG.some(x => +x.id === barangId)) { return false; }

    const kond = kondisi || document.getElementById('kondisiBawaan').value;
    const tbody = document.getElementById('isiKeranjang');

    /* Barang yang sama ditembak dua kali menambah jumlah pada baris yang
       sudah ada — bukan membuat baris kembar yang harus dijumlah manual. */
    /* Digabung hanya bila barang DAN kondisinya sama — popok baru dan popok
       bekas harus tetap jadi dua baris terpisah. */
    /* Baris hasil Pisah (data-pisah) dikecualikan: jumlahnya sudah ditetapkan
       orang dengan sengaja — kondisi/rak/expired-nya berbeda — dan tembakan
       berikutnya tidak boleh diam-diam menambah salah satunya. Barang yang
       sama ditembak lagi sesudah dipisah membuat baris baru. */
    const adaSama = [...tbody.querySelectorAll('tr')].find(tr =>
      !tr.dataset.pisah &&
      +tr.querySelector('input.b-barang').value === barangId &&
      tr.querySelector('select[name="kondisi[]"]').value === kond);
    if (adaSama) {
      const q = adaSama.querySelector('input.b-qty');
      q.value = (+q.value || 0) + (jumlah || 1);
      sorot(adaSama);
      hitung();
      return true;
    }
  
    const i   = nomorBaris++;
    const rak = +document.getElementById('rakBawaan').value;
    const b   = BARANG.find(x => x.id === barangId) || {};
    const tr  = document.createElement('tr');
  
    tr.innerHTML =
      '<td data-label="Barang">' + selBarang(barangId) + '</td>' +
      /* Opsinya dibangun dari KONDISI yang dikirim PHP, bukan ditulis ulang
         di sini. Dua daftar terpisah akan berbeda pada perubahan berikutnya,
         dan yang di layar inilah yang dilihat petugas. */
      '<td data-label="Kondisi"><select class="sel-tbl" name="kondisi[]">' +
        KONDISI.map(k => '<option value="' + lolos(k.v) + '"' +
          (kond === k.v ? ' selected' : '') + '>' + lolos(k.p) + '</option>').join('') +
        '</select></td>' +
      '<td data-label="Batch"><input class="inp-tbl" name="nomor_batch[]" value="' + BATCH_BAWAAN + '"></td>' +
      '<td data-label="Expired"><input class="inp-tbl" type="date" name="tanggal_expired[]"></td>' +
      '<td data-label="Jumlah" class="num"><input class="inp-tbl b-qty num" type="number" min="1" ' +
        'name="qty[]" value="' + (jumlah || 1) + '" oninput="hitung()"></td>' +
      '<td data-label="Rak"><select class="sel-tbl" name="lokasi_rak_id[]">' + opsiRak(rak) + '</select></td>' +
      '<td data-label="Harga" class="num"><input class="inp-tbl b-harga num" type="number" min="0" step="1" ' +
        'name="harga[]" value="' + (harga != null ? harga : (b.harga || 0)) + '" oninput="hitung()"></td>' +
      '<td data-label="Subtotal" class="num b-sub">Rp0</td>' +
      '<td class="aksi"><button type="button" class="btn btn-ghost btn-sm" onclick="pisahBaris(this)" ' +
        'title="Pisah sebagian ke baris baru — kondisi, rak, atau expired berbeda">Pisah</button> ' +
        '<button type="button" class="btn btn-red btn-sm" onclick="hapusBaris(this)" title="Hapus baris">' +
        '<svg aria-hidden="true"><use href="#i-x"></use></svg></button></td>';
  
    tbody.appendChild(tr);
    sorot(tr);
    hitung();
    return true;
  }
  
  function sorot(tr){
    tr.style.transition = 'background .35s';
    tr.style.background = 'var(--primary-lembut)';
    setTimeout(() => { tr.style.background = ''; }, 700);
  }
  
  function gantiBarang(btn){
    const tr = btn.closest('tr');
    cariBarangDialog('Ganti barang baris ini', function (b) {
      isiSelBarang(tr, b);
      const h = tr.querySelector('input.b-harga');
      if (h && (+h.value === 0)) { h.value = b.harga || 0; }
      sorot(tr); hitung();
    });
  }

  /* Tambah Baris = pilih barangnya dulu lewat kotak cari, bukan baris
     berisi produk pertama menurut abjad yang lalu harus diganti. */
  function tambahBarisKosong(){
    if (!BARANG.length) return;
    cariBarangDialog('Tambah baris — pilih barang', function (b) { tambahBaris(b.id, 1, null); });
  }

  /* Kotak cari barang: ketik nama/kode, hasil digambar paling banyak 40
     baris, Enter memilih yang pertama. Satu <dialog> dipakai bergantian. */
  function cariBarangDialog(judul, pilih){
    let d = document.getElementById('dlgCariBarang');
    if (!d) {
      d = document.createElement('dialog');
      d.id = 'dlgCariBarang'; d.className = 'modal';
      d.innerHTML =
        '<div class="modal-h"><div><b class="cb-judul"></b><small>Ketik nama atau kode barang</small></div></div>' +
        '<div class="modal-b">' +
          '<input type="text" class="cb-cari" autocomplete="off" placeholder="mis. susu, BBY-0001" style="width:100%">' +
          '<div class="cb-hasil" style="max-height:300px;overflow:auto;margin-top:10px"></div>' +
        '</div>' +
        '<div class="modal-f"><button type="button" class="btn btn-ghost btn-sm cb-batal">Batal</button></div>';
      document.body.appendChild(d);
      d.querySelector('.cb-batal').addEventListener('click', function(){ d.close(); });
    }
    const inp = d.querySelector('.cb-cari'), hasil = d.querySelector('.cb-hasil');
    d.querySelector('.cb-judul').textContent = judul;
    let cocok = [];
    const gambar = function(){
      const q = inp.value.trim().toLowerCase();
      cocok = (q === '' ? BARANG.slice(0, 40)
                        : BARANG.filter(b => (b.nama + ' ' + (b.kode || '')).toLowerCase().indexOf(q) !== -1).slice(0, 40));
      hasil.innerHTML = cocok.length
        ? cocok.map((b, i) => '<button type="button" class="btn btn-ghost btn-sm cb-item" data-i="' + i + '" ' +
            'style="display:block;width:100%;text-align:left;margin:0 0 4px">' + lolos(b.nama) +
            ' <small class="mut2">' + lolos(b.kode || '') + '</small></button>').join('') +
          (BARANG.length > 40 && q === '' ? '<small class="mut2">Ketik untuk mencari di seluruh katalog.</small>' : '')
        : '<small class="mut2">Tidak ada barang yang cocok.</small>';
    };
    hasil.onclick = function (e) {
      const t = e.target.closest('.cb-item'); if (!t) return;
      d.close(); pilih(cocok[+t.dataset.i]);
    };
    inp.oninput = gambar;
    inp.onkeydown = function (e) {
      if (e.key === 'Enter') { e.preventDefault(); if (cocok.length) { d.close(); pilih(cocok[0]); } }
    };
    inp.value = ''; gambar();
    d.showModal();
    setTimeout(function(){ inp.focus(); }, 30);
  }
  function hapusBaris(btn){ btn.closest('tr').remove(); hitung(); }

  /* ------------------------------------------------------------ PISAH BARIS
     Revisi tim gudang, Oktober 2026 (butir 3). Satu penerimaan sering berisi
     barang yang sama dengan keadaan berbeda: 9 layak + 1 bocor, 6 ke Area
     Siap Kirim + 4 ke rak lain, 3 masih bagus + 2 sudah expired. Dulu satu
     baris hanya punya satu kondisi, satu rak, satu tanggal — jadi yang
     berbeda terpaksa diinput terpisah atau dicatat salah.

     Pisah MEMINDAHKAN sebagian jumlah ke baris baru di bawahnya: barang,
     batch, harga, dan tanggal expired ikut disalin, lalu petugas mengubah
     yang berbeda di baris baru. Jumlah total tidak pernah berubah oleh
     tindakan ini (asal = awal − dipisah, baru = dipisah), dan tiap baris
     minimal 1. Kedua baris ditandai data-pisah supaya tembakan barcode
     berikutnya tidak menambah salah satunya diam-diam (lihat tambahBaris). */
  function pisahBaris(btn){
    const trAsal = btn.closest('tr');
    const inpQty = trAsal.querySelector('input.b-qty');
    const total  = parseInt(inpQty.value, 10) || 0;
    if (total < 2) {
      if (typeof toast === 'function') toast('err', 'Tidak bisa dipisah', 'Jumlah baris ini ' + total + ' — perlu minimal 2 untuk dipisah.', 5);
      else alert('Jumlah baris ini ' + total + ' — perlu minimal 2 untuk dipisah.');
      return;
    }
    const nama = trAsal.querySelector('.b-nama').textContent + ' (' + trAsal.querySelector('.b-kode').textContent + ')';
    tanyaJumlahPisah(nama, total, function (dipisah) {
      const sisa = total - dipisah;
      inpQty.value = sisa;
      trAsal.dataset.pisah = '1';

      const trBaru = trAsal.cloneNode(true);
      trBaru.dataset.pisah = '1';
      /* cloneNode menyalin markup, bukan nilai yang sedang dipilih — select
         dan input date harus disamakan tangan. */
      trAsal.querySelectorAll('select').forEach(function (s, i) {
        trBaru.querySelectorAll('select')[i].value = s.value;
      });
      trAsal.querySelectorAll('input').forEach(function (inp, i) {
        trBaru.querySelectorAll('input')[i].value = inp.value;
      });
      trBaru.querySelector('input.b-qty').value = dipisah;
      trAsal.insertAdjacentElement('afterend', trBaru);
      sorot(trBaru);
      hitung();
      // Yang paling sering berbeda adalah kondisi — langsung ke sana.
      const k = trBaru.querySelector('select[name="kondisi[]"]');
      if (k) k.focus();
    });
  }

  /* Kotak tanya jumlah yang dipisah. <dialog> bawaan peramban, bergaya sama
     dengan dialog konfirmasi di _footer.php; alert()/prompt() tidak dipakai
     karena tidak bisa membatasi angkanya. */
  function tanyaJumlahPisah(nama, total, lanjut){
    let d = document.getElementById('dlgPisah');
    if (!d) {
      d = document.createElement('dialog');
      d.id = 'dlgPisah'; d.className = 'modal';
      d.innerHTML =
        '<form method="dialog">' +
          '<div class="modal-h"><div><b>Pisah baris</b><small class="pisah-nama"></small></div></div>' +
          '<div class="modal-b">' +
            '<label style="display:block;font-size:12.5px;margin-bottom:6px">Berapa yang dipisah ke baris baru?</label>' +
            '<input type="number" class="pisah-jml" min="1" step="1" inputmode="numeric" style="width:120px" required> ' +
            '<span class="pisah-ket mut" style="font-size:12.5px"></span>' +
          '</div>' +
          '<div class="modal-f">' +
            '<button type="button" class="btn btn-ghost btn-sm pisah-batal">Batal</button>' +
            '<button type="submit" class="btn btn-primary btn-sm">Pisah</button>' +
          '</div>' +
        '</form>';
      document.body.appendChild(d);
      d.querySelector('.pisah-batal').addEventListener('click', function(){ d.close('batal'); });
    }
    const inp = d.querySelector('.pisah-jml'), ket = d.querySelector('.pisah-ket');
    d.querySelector('.pisah-nama').textContent = nama + ' — jumlah sekarang ' + total;
    inp.max = total - 1; inp.value = 1;
    const perbarui = function(){
      const n = parseInt(inp.value, 10) || 0;
      ket.textContent = (n >= 1 && n <= total - 1)
        ? 'Baris asal jadi ' + (total - n) + ', baris baru ' + n + '.'
        : 'Isi 1 sampai ' + (total - 1) + '.';
    };
    inp.oninput = perbarui; perbarui();
    d.querySelector('form').onsubmit = function (e) {
      e.preventDefault();
      const n = parseInt(inp.value, 10) || 0;
      if (n < 1 || n > total - 1) { perbarui(); inp.focus(); return; }
      d.close('ok');
      lanjut(n);
    };
    d.showModal();
    setTimeout(function(){ inp.focus(); inp.select(); }, 30);
  }
  function kosongkanKeranjang(){ document.getElementById('isiKeranjang').innerHTML = ''; hitung(); }
  
  function terapkanRakBawaan(){
    /* Hanya baris yang belum disentuh yang ikut berubah — kalau petugas sudah
       memilih rak khusus untuk satu baris, pilihannya tidak boleh ditimpa. */
    const rak = document.getElementById('rakBawaan').value;
    document.querySelectorAll('#isiKeranjang select[name="lokasi_rak_id[]"]').forEach(s => {
      if (!s.dataset.diubah) s.value = rak;
    });
  }
  document.addEventListener('change', e => {
    if (e.target.name === 'lokasi_rak_id[]') e.target.dataset.diubah = '1';
  });
  
  function hitung(){
    let qty = 0, nilai = 0, jenis = 0;
    document.querySelectorAll('#isiKeranjang tr').forEach(tr => {
      const q = +tr.querySelector('input.b-qty').value || 0;
      const h = +tr.querySelector('input.b-harga').value || 0;
      tr.querySelector('.b-sub').textContent = rupiah(q * h);
      qty += q; nilai += q * h; jenis++;
    });
    document.getElementById('totQty').textContent      = qty.toLocaleString('id-ID');
    document.getElementById('totNilai').textContent    = rupiah(nilai);
    document.getElementById('ringkasJenis').textContent = jenis;
    document.getElementById('ringkasQty').textContent   = qty.toLocaleString('id-ID');
    document.getElementById('ringkasNilai').textContent = rupiah(nilai);
    document.getElementById('tblSimpan').disabled       = (jenis === 0);
    document.getElementById('kosongKeranjang').style.display = jenis ? 'none' : '';
    document.getElementById('tblKeranjang').style.display     = jenis ? '' : 'none';
  }
  
  /* Dipanggil scan.js setiap kali barcode berhasil dicocokkan. */
  /* Dipanggil scan.js setiap kali barcode berhasil dicocokkan. Kondisi
     diambil dari pemilih bawaan, jadi petugas cukup menyetelnya sekali di
     awal lalu menembak terus. */
  function scanTambahBaris(barang, jumlah){
    /* Daftar pilihan dibuat saat halaman dimuat dan hanya berisi produk AKTIF,
       sedangkan pencarian scan membaca basis data saat itu juga. Dua sebab
       barang hasil scan bisa tidak ada di daftar:
         - produknya dinonaktifkan  -> ditolak, dengan alasannya;
         - produknya baru dibuat setelah halaman ini dibuka -> disusulkan ke
           daftar, supaya petugas tidak perlu memuat ulang dan kehilangan
           keranjangnya. */
    if (!BARANG.some(x => +x.id === +barang.id)) {
      if (barang.aktif === false) {
        return barang.nama + ' berstatus NONAKTIF, jadi tidak bisa diterima. Aktifkan dulu di Data Barang.';
      }
      BARANG.push({ id: +barang.id, kode: barang.kode, nama: barang.nama,
                    satuan: barang.satuan, harga: barang.harga });
    }
    if (tambahBaris(barang.id, jumlah, barang.harga,
                    document.getElementById('kondisiBawaan').value) === false) {
      return barang.nama + ' tidak bisa dimasukkan ke keranjang. Muat ulang halaman lalu coba lagi.';
    }
  }
  
  /* Cegah kehilangan keranjang karena tidak sengaja menutup tab. */
  window.addEventListener('beforeunload', e => {
    if (document.querySelectorAll('#isiKeranjang tr').length && !window.__simpan) {
      e.preventDefault(); e.returnValue = '';
    }
  });

  /* Fungsi yang dipanggil dari atribut onclick di markup harus global —
     modul ini dibungkus IIFE, jadi keduanya disambungkan di sini. */
  window.tambahBaris        = tambahBaris;
  window.tambahBarisKosong  = tambahBarisKosong;
  window.hapusBaris         = hapusBaris;
  window.pisahBaris         = pisahBaris;
  window.kosongkanKeranjang = kosongkanKeranjang;
  window.terapkanRakBawaan  = terapkanRakBawaan;
  window.gantiBarang        = gantiBarang;
  window.hitung             = hitung;
  /* Dicari AmanahScan saat callback tidak diberikan — lihat scan.js. */
  window.scanTambahBaris    = scanTambahBaris;

  var frm = document.getElementById(ID_FORM);
  if (frm) { frm.addEventListener('submit', function () { window.__simpan = 1; }); }

  hitung();
})();
