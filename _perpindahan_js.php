<?php
/**
 * AMANAH — Skrip formulir Perpindahan Barang (partial)
 *
 * Dipisah dari markupnya karena isinya menyuntikkan data PHP ke JavaScript
 * (daftar barang, gudang, rak, toko, penerima). Dipakai kedua layar, jadi
 * dropdown bertingkat dan pemeriksaan rute di sisi klien berperilaku sama.
 */
?>
<?php /* Mesin draf bersama, dimuat DI SINI dan bukan di kedua halaman
         pemanggil. Berkas ini satu-satunya yang membutuhkannya, jadi ia
         yang bertanggung jawab memuatnya — kalau ditaruh di pemanggil,
         menambah pemanggil ketiga berarti draf diam-diam mati di sana
         tanpa satu pun galat yang terlihat. */ ?>
<script src="<?= e(aset('assets/draf-formulir.js')) ?>"></script>
<script>
<?php /* 'k' (kode) dan 'n' (nama) ikut dikirim, bukan hanya 't' yang sudah
         digabung. Tempel-daftar-kode mencocokkan berdasarkan KODE, dan
         mengurainya kembali dari teks "Nama (KODE)" akan salah pada nama
         barang yang memang memuat tanda kurung. */ ?>
const BARANG   = <?= json_encode(array_map(fn($b) => [
    'id' => (int) $b['id'],
    't'  => $b['nama'] . ' (' . $b['kode'] . ')',
    'k'  => $b['kode'],
    'n'  => $b['nama'],
    's'  => $b['satuan'],
], $barangList), JSON_UNESCAPED_UNICODE) ?>;

<?php /* Dulu di sini ada `const STOK` — stok per barang beserta lokasinya,
         disisipkan ke halaman dari _perpindahan_form.php.

         Dibuang. Panel "Pilih Banyak Barang" kini mengambil stoknya dari
         cari_stok.php, sebab penyaring sumber dan rentang tanggal masuk
         membuat isinya berubah-ubah — tidak ada satu kumpulan data yang
         bisa disisipkan lebih dulu. Yang tertinggal hanyalah satu kueri
         empat-JOIN atas v_stok_rantai yang dijalankan setiap kali
         formulir dimuat, hasilnya diserialkan ke setiap halaman, dan
         tidak pernah dibaca oleh satu baris kode pun. */ ?>
const SUPPLIER =<?= json_encode(array_map(fn($s) => ['id'=>(int)$s['id'],'t'=>$s['nama']], $supplierList), JSON_UNESCAPED_UNICODE) ?>;
const GUDANG   = <?= json_encode(array_map(fn($g) => ['id'=>(int)$g['id'],'t'=>$g['nama'].' ('.$g['kode'].')'], $gudangList), JSON_UNESCAPED_UNICODE) ?>;
const RAK      = <?= json_encode(array_map(fn($r) => ['id'=>(int)$r['id'],'t'=>($r['nama_gudang'] ? $r['nama_gudang'].' › ' : '').$r['nama']], $rakList), JSON_UNESCAPED_UNICODE) ?>;
/* Daftar TUJUAN berisi seluruh gudang, bukan hanya milik sendiri —
   mengirim ke gudang lain memang boleh, mengambil dari sana tidak.
   Dua daftar terpisah supaya pembatasan asal tidak ikut longgar. */
const GUDANG_TUJUAN = <?= json_encode(array_map(fn($g) => ['id'=>(int)$g['id'],'t'=>$g['nama'].' ('.$g['kode'].')'], $gudangTujuanList), JSON_UNESCAPED_UNICODE) ?>;
const RAK_TUJUAN    = <?= json_encode(array_map(fn($r) => ['id'=>(int)$r['id'],'t'=>($r['nama_gudang'] ? $r['nama_gudang'].' › ' : '').$r['nama']], $rakTujuanList), JSON_UNESCAPED_UNICODE) ?>;
const TOKO     = <?= json_encode(array_map(fn($t) => ['id'=>(int)$t['id'],'t'=>$t['nama'].' ('.$t['kode'].')'], $tokoList), JSON_UNESCAPED_UNICODE) ?>;
const PENERIMA = <?= json_encode(array_map(fn($p) => ['id'=>(int)$p['id'],'t'=>$p['nama'].($p['wilayah'] ? ' — '.$p['wilayah'] : '')], $penerimaList), JSON_UNESCAPED_UNICODE) ?>;
<?php
/**
 * Isi baris saat halaman dimuat.
 *
 * Bila penyimpanan GAGAL validasi, yang dikembalikan adalah baris yang
 * BARU SAJA diketik pengguna — bukan daftar kosong. Sebelumnya $items
 * hanya hidup di dalam blok POST, jadi setiap galat (mis. "nomor batch
 * wajib diisi") menghapus seluruh keranjang dan pengguna harus memindai
 * ulang semua barang dari nol.
 */
$isiAwal = $errors && !empty($items)
    ? array_map(fn($it) => [
        'barang_id'       => $it['barang_id'],
        'qty'             => $it['qty'],
        'nomor_batch'     => $it['nomor_batch'],
        'tanggal_expired' => $it['tanggal_expired'],
        'harga'           => $it['harga'],
        'kardus_idx'      => $it['kardus_idx'] ?? 0,
      ], $items)
    : ($prefill['items'] ?? []);
$kardusAwal = ($errors && !empty($kardusKepala)) ? $kardusKepala : [];
?>
const PREFILL  = <?= json_encode($isiAwal, JSON_UNESCAPED_UNICODE) ?>;
const KARDUS_AWAL = <?= json_encode($kardusAwal ?? [], JSON_UNESCAPED_UNICODE) ?>;
const KARDUS_SIAP = <?= json_encode(kardus_siap_daftar($pdo), JSON_UNESCAPED_UNICODE) ?>;
const JENIS_KARDUS_SIAP = <?= json_encode(array_values(array_filter(array_keys(rute_sah()), 'jenis_ambil_kardus_siap'))) ?>;
const JENIS_KARDUS = <?= json_encode(array_values(array_filter(array_keys(rute_sah()), 'jenis_boleh_kardus'))) ?>;

/* Rute yang masuk akal untuk tiap jenis perpindahan.
   Harus SAMA dengan rute_sah() di _lib.php — server memvalidasi ulang. */
const RUTE = {
  penerimaan:      {asal:['supplier','eksternal'], tujuan:['rak','gudang'],
                    hint:'Barang masuk dari supplier. Pilih rak tujuan agar lokasi penyimpanannya tercatat.'},
  kirim_toko:      {asal:['gudang','rak'], tujuan:['toko'],
                    hint:'Barang dikirim dari gudang ke toko. Setelah disetujui, surat jalan terbit dan toko memverifikasi saat barang tiba.'},
  retur_toko:      {asal:['toko'], tujuan:['rak','gudang'],
                    hint:'Barang dikembalikan dari toko ke gudang — mis. tidak laku atau salah kirim.'},
  antar_toko:      {asal:['toko'], tujuan:['toko'],
                    hint:'Pindah stok antar toko, mis. dari Shopee ke Tokopedia.'},
  transfer_gudang: {asal:['gudang','rak'], tujuan:['rak','gudang'],
                    hint:'Barang dipindah antar gudang. Sistem mengambil batch dengan kedaluwarsa terdekat lebih dulu (FEFO).'},
  mutasi_rak:      {asal:['rak'], tujuan:['rak'],
                    hint:'Pindah rak di dalam gudang yang sama atau berbeda.'},
  penyaluran:      {asal:['gudang','rak','toko'], tujuan:['penerima'],
                    hint:'Barang keluar ke penerima manfaat. Stok berkurang setelah disetujui.'},
  retur:           {asal:['gudang','rak'], tujuan:['supplier'],
                    hint:'Barang dikembalikan ke supplier. Stok berkurang setelah disetujui.'},
};

/* `untuk` membedakan sisi asal dan sisi tujuan. Gudang dan rak punya dua
   daftar yang berbeda; sisanya sama untuk keduanya. */
function opsiUntuk(tipe, untuk){
  const tujuan = (untuk === 'tujuan');
  if (tipe === 'supplier') return SUPPLIER;
  if (tipe === 'gudang')   return tujuan ? GUDANG_TUJUAN : GUDANG;
  if (tipe === 'rak')      return tujuan ? RAK_TUJUAN    : RAK;
  if (tipe === 'toko')     return TOKO;
  if (tipe === 'penerima') return PENERIMA;
  return [];
}

function isiSelect(sel, data, terpilih){
  sel.innerHTML = '<option value="">— pilih —</option>';
  data.forEach(function(o){
    const op = document.createElement('option');
    op.value = o.id; op.textContent = o.t;
    if (String(terpilih) === String(o.id)) op.selected = true;
    sel.appendChild(op);
  });
}

function batasiTipe(selectEl, izinkan){
  const sekarang = selectEl.value;
  Array.from(selectEl.options).forEach(function(op){
    const boleh = izinkan.includes(op.value);
    op.hidden = !boleh; op.disabled = !boleh;
  });
  if (!izinkan.includes(sekarang)) selectEl.value = izinkan[0] || '';
}

function segarkanAsal(){
  const tipe = document.getElementById('asal_tipe').value;
  const manual = (tipe === 'eksternal');
  document.getElementById('wrap_asal_id').style.display   = manual ? 'none' : '';
  document.getElementById('wrap_asal_teks').style.display = manual ? '' : 'none';
  document.getElementById('asal_id').required   = !manual;
  document.getElementById('asal_teks').required = manual;
  if (!manual) isiSelect(document.getElementById('asal_id'), opsiUntuk(tipe, 'asal'), document.getElementById('asal_id').value);
}

function segarkanTujuan(){
  const tipe = document.getElementById('tujuan_tipe').value;
  const penerima = (tipe === 'penerima');

  /* Penyaluran menampilkan KEDUANYA: daftar penerima terdaftar, dan kotak
     teks untuk penerima sekali-pakai yang belum sempat didaftarkan.
     Sebelumnya hanya kotak teks, sehingga penyaluran ke keluarga yang sama
     tidak pernah bisa dijumlahkan — tiap kali jadi nama baru. */
  document.getElementById('wrap_tujuan_id').style.display   = '';
  document.getElementById('wrap_tujuan_teks').style.display = penerima ? '' : 'none';
  document.getElementById('tujuan_id').required   = !penerima;
  document.getElementById('tujuan_teks').required = false;

  const opsi = opsiUntuk(tipe, 'tujuan');
  isiSelect(document.getElementById('tujuan_id'), opsi, document.getElementById('tujuan_id').value);

  if (penerima) {
    // Baris kosong di puncak daftar supaya "belum terdaftar" jadi pilihan
    // yang sah, bukan sekadar tidak memilih apa-apa.
    const sel = document.getElementById('tujuan_id');
    const kosong = new Option(opsi.length ? '— belum terdaftar, ketik di bawah —'
                                          : '— belum ada penerima terdaftar —', '');
    sel.insertBefore(kosong, sel.firstChild);
    sel.value = sel.value || '';
  }
}

/* Jenis yang sah begitu dibuat, tanpa persetujuan. Harus SAMA dengan
   langsung_sah() di _lib.php — kalau berbeda, tombolnya menjanjikan satu
   hal dan servernya melakukan hal lain. */
const LANGSUNG_SAH = ['mutasi_rak'];

function segarkanTombolKirim(j){
  const langsung = LANGSUNG_SAH.indexOf(j) !== -1;
  const teks = document.getElementById('tblKirimTeks');
  const ket  = document.getElementById('tblKirimKet');
  if (teks) teks.textContent = langsung ? 'Pindahkan Sekarang' : 'Kirim untuk Persetujuan';
  if (ket)  ket.textContent  = langsung
    ? 'Barang berpindah rak seketika — tidak perlu persetujuan.'
    : 'Stok berubah setelah dokumen ini disetujui.';
}

function segarkanJenis(){
  const j = document.getElementById('jenis').value;
  const r = RUTE[j];
  document.getElementById('hintRute').textContent = r ? r.hint : '';
  segarkanTombolKirim(j);
  if (r){
    batasiTipe(document.getElementById('asal_tipe'),   r.asal);
    batasiTipe(document.getElementById('tujuan_tipe'), r.tujuan);
  }
  /* Kolom harga hanya relevan saat penerimaan — di dokumen keluar harganya
     mengikuti batch asalnya, bukan diketik ulang. */
  const penerimaan = (j === 'penerimaan');
  document.querySelectorAll('.col-harga').forEach(function(el){
    el.style.display = penerimaan ? '' : 'none';
  });
  segarkanKardusUntukJenis();
  /* Kolom batch & expired punya aturannya sendiri: ikut penerimaan, TAPI
     juga muncul di dokumen keluar bila ada baris yang batch-nya dipilih
     sendiri lewat panel Pilih Banyak Barang. */
  sesuaikanKolomBatch();
  segarkanAsal(); segarkanTujuan(); segarkanKonteksScan();
}

let nBaris = 0;
/* =====================================================================
   BARIS BARANG RINGAN (revisi 18 Okt 2026)

   Dulu tiap baris berisi <select> SELURUH katalog (±550 produk). Sepuluh
   baris = 5.500 pilihan di halaman, dan setiap perubahan isian membuat
   peramban menggambar ulang semuanya — laptop gudang macet/blank begitu
   barangnya 10 jenis atau lebih. Sekarang barisnya hanya nama barang +
   isian tersembunyi barang_id[]; mengganti barang lewat kotak cari.
   ===================================================================== */
function barangDariId(id){ return BARANG.find(function(x){ return String(x.id) === String(id); }) || null; }

function tambahBaris(data){
  /* Tanpa data = tombol "Tambah Baris": pilih barangnya dulu lewat kotak cari. */
  if (!data || !data.barang_id) {
    cariBarangPP('Tambah baris — pilih barang', function (b) { tambahBarisAtauTambahJumlah(b.id, 1); });
    return;
  }
  const i = nBaris++;
  const tr = document.createElement('tr');
  const penerimaan = (document.getElementById('jenis').value === 'penerimaan');
  const b = barangDariId(data.barang_id) || { id: data.barang_id, n: data.nama || ('Barang #' + data.barang_id), k: data.kode || '', s: data.satuan || '' };
  const kunci = !!data.kunci;   // isi kardus siap kirim: barang & jumlah mengikuti kardusnya
  const kIdx = data.kardus_idx !== undefined ? (parseInt(data.kardus_idx, 10) || 0) : kardusAktif;
  if (kunci) { tr.dataset.kunci = '1'; }
  /* data-label wajib ikut ditulis di sini: di ponsel tabel ini berubah
     jadi kartu, dan tanpa label tiap isian kehilangan keterangannya. */
  tr.innerHTML =
    '<td data-label="Barang"><input type="hidden" name="barang_id[]" value="' + lolos(b.id) + '">' +
      '<b class="b-nama">' + lolos(b.n || b.t) + '</b> <small class="mut2 b-kode">' + lolos(b.k || '') + '</small>' +
      (kunci ? '' : ' <button type="button" class="btn btn-ghost btn-sm" style="padding:2px 8px" onclick="gantiBarangBaris(this)">ganti</button>') + '</td>' +
    '<td data-label="Jumlah"><input name="qty[]" type="number" min="1" value="' + (data.qty ? parseInt(data.qty, 10) : 1) + '" class="inp-mini"' + (kunci ? ' readonly' : '') + '>' +
      '<small class="stok-baris mut2" style="display:block;font-size:11px"></small></td>' +
    '<td data-label="Batch" class="col-batch"><input name="nomor_batch[]" value="' + lolos(data.nomor_batch || '') + '" placeholder="B' + new Date().toISOString().slice(2,10).replace(/-/g,'') + '" class="inp-mini"' + (kunci ? ' readonly' : '') + '></td>' +
    '<td data-label="Expired" class="col-exp"><input name="tanggal_expired[]" type="date" value="' + lolos(data.tanggal_expired || '') + '" class="inp-mini"></td>' +
    '<td data-label="Harga" class="col-harga' + (penerimaan ? '' : ' hide') + '"><input name="harga[]" type="number" step="0.01" min="0" value="' + (data.harga ? data.harga : 0) + '" class="inp-mini"></td>' +
    '<td data-label="Keterangan"><input name="ket_item[]" placeholder="opsional" class="inp-mini" value="' + lolos(data.ket_item || '') + '"' + (kunci ? ' readonly' : '') + '></td>' +
    '<td data-label="Kardus" class="col-kardus">' + (kunci
        ? '<input type="hidden" name="kardus_idx[]" value="' + kIdx + '"><small><b>Kardus ' + kIdx + '</b><br>siap kirim</small>'
        : '<select name="kardus_idx[]" class="inp-mini sel-kardus" onchange="segarkanRingkasanKardus()">' + opsiKardus(kIdx) + '</select>') + '</td>' +
    '<td class="aksi">' + (kunci ? '' : '<button type="button" class="btn btn-red btn-sm" onclick="this.closest(\'tr\').remove(); segarkanRingkasanKardus(); segarkanStokBaris()"><svg aria-hidden="true"><use href="#i-x"></use></svg> Hapus</button>') + '</td>';
  document.getElementById('tbodyItem').appendChild(tr);
  tr.querySelector('.col-kardus').style.display = kardusBolehUntukJenis() ? '' : 'none';
  const hc = tr.querySelector('.col-harga'); if (hc) { hc.style.display = penerimaan ? '' : 'none'; }
  segarkanRingkasanKardus();
  segarkanStokBaris();
  /* Kolom batch/expired diputuskan satu fungsi yang melihat seluruh tabel. */
  sesuaikanKolomBatch();
}

/** Ganti barang sebuah baris lewat kotak cari. */
function gantiBarangBaris(btn){
  const tr = btn.closest('tr');
  cariBarangPP('Ganti barang baris ini', function (b) {
    tr.querySelector('input[name="barang_id[]"]').value = b.id;
    tr.querySelector('.b-nama').textContent = b.n || b.t;
    tr.querySelector('.b-kode').textContent = b.k || '';
    segarkanRingkasanKardus(); segarkanStokBaris();
    if (typeof window.drafSegarkan === 'function') { window.drafSegarkan(); }
  });
}

/* Kotak cari barang: ketik nama/kode, paling banyak 40 hasil, Enter = yang pertama. */
function cariBarangPP(judul, pilih){
  let d = document.getElementById('dlgCariBarangPP');
  if (!d) {
    d = document.createElement('dialog');
    d.id = 'dlgCariBarangPP'; d.className = 'modal';
    d.innerHTML =
      '<div class="modal-h"><div><b class="cb-judul"></b><small>Ketik nama atau kode barang — Enter memilih yang pertama</small></div></div>' +
      '<div class="modal-b">' +
        '<input type="text" class="cb-cari" autocomplete="off" placeholder="mis. susu, BBY-0001" style="width:100%">' +
        '<div class="cb-hasil" style="max-height:320px;overflow:auto;margin-top:10px"></div>' +
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
                      : BARANG.filter(function(b){ return (b.t + ' ' + (b.k || '')).toLowerCase().indexOf(q) !== -1; }).slice(0, 40));
    hasil.innerHTML = cocok.length
      ? cocok.map(function(b, i){ return '<button type="button" class="btn btn-ghost btn-sm cb-item" data-i="' + i + '" style="display:block;width:100%;text-align:left;margin:0 0 4px">' +
          lolos(b.n || b.t) + ' <small class="mut2">' + lolos(b.k || '') + (b.s ? ' · ' + lolos(b.s) : '') + '</small></button>'; }).join('') +
        (BARANG.length > 40 && q === '' ? '<small class="mut2">Ketik untuk mencari di seluruh katalog.</small>' : '')
      : '<small class="mut2">Tidak ada barang yang cocok.</small>';
  };
  hasil.onclick = function(e){ const t = e.target.closest('.cb-item'); if (!t) return; d.close(); pilih(cocok[+t.dataset.i]); };
  inp.oninput = gambar;
  inp.onkeydown = function(e){ if (e.key === 'Enter') { e.preventDefault(); if (cocok.length) { d.close(); pilih(cocok[0]); } } };
  inp.value = ''; gambar();
  d.showModal();
  setTimeout(function(){ inp.focus(); }, 30);
}

/* =====================================================================
   STOK TERSEDIA PER BARIS (revisi 18 Okt 2026)
   "tersedia N" = stok lokasi asal − pesanan surat jalan lain yang menunggu
   persetujuan atau sedang dikirim (stok_asal.php, aturan yang sama dengan
   server). Jumlah di formulir dijumlah per barang di semua baris — isi
   kardus + tanpa kardus — dan baris yang melebihi ditandai merah. Simpan
   ditahan selama masih ada yang melebihi.
   ===================================================================== */
let stokAsal = {}, kunciStokAsal = '', tStokBaris = null;
function asalSekarang(){
  return { tipe: document.getElementById('asal_tipe').value, id: document.getElementById('asal_id').value, jenis: document.getElementById('jenis').value };
}
function segarkanStokBaris(){ clearTimeout(tStokBaris); tStokBaris = setTimeout(muatStokBaris, 350); }
function muatStokBaris(){
  const a = asalSekarang();
  const kunci = a.tipe + ':' + a.id + ':' + a.jenis;
  if (['gudang', 'rak', 'toko'].indexOf(a.tipe) === -1 || !a.id || !a.jenis || a.jenis === 'penerimaan') {
    stokAsal = {}; kunciStokAsal = ''; tandaiStokBaris(); return;
  }
  const ids = {};
  document.querySelectorAll('#tbodyItem input[name="barang_id[]"]').forEach(function(i){ if (i.value) ids[i.value] = 1; });
  const daftar = Object.keys(ids);
  if (kunci !== kunciStokAsal) { stokAsal = {}; kunciStokAsal = kunci; }
  const perlu = daftar.filter(function(x){ return !stokAsal[x]; });
  if (!perlu.length) { tandaiStokBaris(); return; }
  fetch('stok_asal.php?asal_tipe=' + encodeURIComponent(a.tipe) + '&asal_id=' + encodeURIComponent(a.id) + '&jenis=' + encodeURIComponent(a.jenis) + '&b=' + perlu.join(','), { credentials: 'same-origin' })
    .then(function(r){ return r.json(); })
    .then(function(j){
      if (kunci !== kunciStokAsal || !j || !j.ok) { return; }
      Object.keys(j.barang || {}).forEach(function(k){ stokAsal[k] = j.barang[k]; });
      tandaiStokBaris();
    }).catch(function(){});
}
function totalPerBarangForm(){
  const t = {};
  document.querySelectorAll('#tbodyItem tr').forEach(function(tr){
    const b = tr.querySelector('input[name="barang_id[]"]'); if (!b || !b.value) return;
    t[b.value] = (t[b.value] || 0) + (parseInt(tr.querySelector('input[name="qty[]"]').value, 10) || 0);
  });
  return t;
}
function tandaiStokBaris(){
  const tot = totalPerBarangForm();
  document.querySelectorAll('#tbodyItem tr').forEach(function(tr){
    const b = tr.querySelector('input[name="barang_id[]"]'), el = tr.querySelector('.stok-baris'), q = tr.querySelector('input[name="qty[]"]');
    if (!b || !el) return;
    const info = stokAsal[b.value];
    if (!info) { el.textContent = ''; q.style.borderColor = ''; return; }
    const lebih = (tot[b.value] || 0) > info.bisa;
    el.textContent = 'tersedia ' + info.bisa + (info.pesan ? ' (stok ' + info.stok + ', dipesan SJ lain ' + info.pesan + ')' : '') +
                     (lebih ? ' — di formulir ' + tot[b.value] + ', MELEBIHI' : '');
    el.style.color = lebih ? 'var(--red)' : '';
    q.style.borderColor = lebih ? 'var(--red)' : '';
  });
}
function barisMelebihiStok(){
  const tot = totalPerBarangForm(), keluar = [];
  Object.keys(tot).forEach(function(bid){
    const info = stokAsal[bid]; if (!info) return;
    if (tot[bid] > info.bisa) {
      const b = barangDariId(bid);
      keluar.push((b ? (b.n || b.t) : ('Barang #' + bid)) + ': di formulir ' + tot[bid] + ', tersedia ' + info.bisa);
    }
  });
  return keluar;
}
/* Hasil scan langsung menjadi baris baru. Bila barangnya sudah ada di
   daftar, jumlahnya ditambahkan — bukan membuat baris kembar. */
function scanTambahBaris(barang, jumlah){
  var sel = Array.from(document.querySelectorAll('[name="barang_id[]"]'))
                 .filter(function(s){ return barisDiKardus(s.closest('tr'), kardusAktif); })
                 .find(function(s){ return String(s.value) === String(barang.id); });
  if (sel){
    var q = sel.closest('tr').querySelector('input[name="qty[]"]');
    q.value = (parseInt(q.value,10) || 0) + jumlah;
    q.style.background = 'var(--primary-lembut)';
    setTimeout(function(){ q.style.background = ''; }, 900);
    return;
  }
  tambahBaris({barang_id: barang.id, qty: jumlah});
  /* Baris kosong bawaan dibersihkan supaya daftarnya rapi. */
  document.querySelectorAll('#tbodyItem tr').forEach(function(tr){
    var s2 = tr.querySelector('[name="barang_id[]"]');
    if (s2 && !s2.value) tr.remove();
  });
}

/* Lokasi asal dipakai untuk menampilkan sisa stok saat scan. */
function segarkanKonteksScan(){
  var box = document.getElementById('scanPP');
  if (!box) return;
  var tipe = document.getElementById('asal_tipe').value;
  var id   = document.getElementById('asal_id').value;
  box.dataset.lokasi   = (tipe === 'supplier' || tipe === 'eksternal') ? '' : tipe;
  box.dataset.lokasiId = id || '';
}

/* =====================================================================
   KARDUS DI DALAM PERPINDAHAN (revisi 12 Okt 2026)
   Kardus = kartu di #daftarKardusBaru bernomor 1..n; isi kardus = baris
   tabel barang yang kolom Kardus-nya menunjuk nomor itu. kardusAktif =
   kelompok tempat barang baru dimasukkan (0 = tanpa kardus).
   ===================================================================== */
let kardusAktif = 0;
let nKardus = 0;

function kardusBolehUntukJenis(){
  return JENIS_KARDUS.indexOf(document.getElementById('jenis').value) !== -1;
}
function daftarNomorKardus(){
  return Array.from(document.querySelectorAll('#daftarKardusBaru .kartu-kardus')).map(function(k){ return parseInt(k.dataset.idx, 10); });
}
function daftarNomorKardusBaru(){
  return Array.from(document.querySelectorAll('#daftarKardusBaru .kartu-kardus:not([data-ada])')).map(function(k){ return parseInt(k.dataset.idx, 10); });
}
function namaKardus(n){
  var ks = document.querySelector('#daftarKardusBaru .kartu-kardus[data-idx="' + n + '"][data-ada]');
  if (ks) { var kk = KARDUS_SIAP.find(function(x){ return String(x.id) === ks.dataset.ada; }); return kk ? ' — ' + kk.kode : ''; }
  var el = document.querySelector('#daftarKardusBaru .kartu-kardus[data-idx="' + n + '"] input[name^="kardus_nama"]');
  return (el && el.value.trim()) ? ' — ' + el.value.trim() : '';
}
function opsiKardus(terpilih){
  var h = '<option value="0"' + (!terpilih ? ' selected' : '') + '>Tanpa kardus</option>';
  daftarNomorKardusBaru().forEach(function(n){
    h += '<option value="' + n + '"' + (terpilih === n ? ' selected' : '') + '>Kardus ' + n + lolos(namaKardus(n)) + '</option>';
  });
  return h;
}
function barisDiKardus(tr, n){
  var s = tr ? (tr.querySelector('.sel-kardus') || tr.querySelector('input[name="kardus_idx[]"]')) : null;
  return (s ? (parseInt(s.value, 10) || 0) : 0) === (n || 0);
}
function segarkanOpsiKardus(){
  document.querySelectorAll('#tbodyItem .sel-kardus').forEach(function(s){
    var v = parseInt(s.value, 10) || 0;
    if (v && daftarNomorKardusBaru().indexOf(v) === -1) v = 0;   // kardusnya sudah dihapus
    s.innerHTML = opsiKardus(v);
  });
  var p = document.getElementById('pilihKardusAktif');
  if (p) {
    p.innerHTML = daftarNomorKardusBaru().map(function(n){ return '<option value="' + n + '"' + (kardusAktif === n ? ' selected' : '') + '>Kardus ' + n + lolos(namaKardus(n)) + '</option>'; }).join('');
  }
}
function buatKardusBaru(){
  var n = ++nKardus;
  var kartu = document.createElement('div');
  kartu.className = 'kartu-kardus'; kartu.dataset.idx = n;
  kartu.style.cssText = 'border:1px solid var(--border);border-radius:10px;padding:10px 12px;background:var(--surface-2)';
  kartu.innerHTML =
    '<div style="display:flex;justify-content:space-between;align-items:center;gap:8px">' +
      '<b class="judul-kardus">Kardus ' + n + '</b>' +
      '<small class="mut2">kode KRD-… dibuat saat disimpan</small>' +
    '</div>' +
    '<input name="kardus_nama[' + n + ']" placeholder="nama / keterangan kardus (opsional)" maxlength="150" class="inp-mini" style="width:100%;margin-top:8px" oninput="segarkanOpsiKardus(); segarkanRingkasanKardus()">' +
    '<details style="margin-top:6px"><summary class="petunjuk" style="cursor:pointer">Berat, dimensi, catatan (opsional)</summary>' +
      '<div style="display:flex;gap:6px;margin-top:6px;flex-wrap:wrap">' +
        '<input name="kardus_berat[' + n + ']" type="number" min="0" step="0.01" placeholder="berat kg" class="inp-mini" style="width:90px">' +
        '<input name="kardus_p[' + n + ']" type="number" min="0" step="0.1" placeholder="P cm" class="inp-mini" style="width:70px">' +
        '<input name="kardus_l[' + n + ']" type="number" min="0" step="0.1" placeholder="L cm" class="inp-mini" style="width:70px">' +
        '<input name="kardus_t[' + n + ']" type="number" min="0" step="0.1" placeholder="T cm" class="inp-mini" style="width:70px">' +
      '</div>' +
      '<input name="kardus_catatan[' + n + ']" placeholder="catatan kardus" maxlength="500" class="inp-mini" style="width:100%;margin-top:6px">' +
    '</details>' +
    '<div class="isi-kardus-ringkas petunjuk" style="margin-top:6px">kosong</div>' +
    '<div style="display:flex;gap:6px;flex-wrap:wrap;margin-top:8px">' +
      '<button type="button" class="btn btn-primary btn-sm" onclick="setKardusAktif(' + n + ')">Tambah barang ke kardus ini</button>' +
      '<button type="button" class="btn btn-ghost btn-sm" onclick="setKardusAktif(0)">Selesai mengisi</button>' +
      '<button type="button" class="btn btn-ghost btn-sm" onclick="hapusKardus(' + n + ')" title="Barangnya menjadi tanpa kardus">Hapus kardus</button>' +
    '</div>';
  document.getElementById('daftarKardusBaru').appendChild(kartu);
  segarkanOpsiKardus();
  setKardusAktif(n);
  return n;
}
function hapusKardus(n, tanpaTanya){
  var kartu = document.querySelector('#daftarKardusBaru .kartu-kardus[data-idx="' + n + '"]');
  if (!kartu) return;
  if (kartu.dataset.ada) {
    document.querySelectorAll('#tbodyItem tr').forEach(function(tr){
      var h = tr.querySelector('input[name="kardus_idx[]"]');
      if (h && (parseInt(h.value, 10) || 0) === n) tr.remove();
    });
    kartu.remove();
    if (kardusAktif === n) kardusAktif = 0;
    segarkanOpsiKardus(); setKardusAktif(kardusAktif); segarkanKardusSiap(); segarkanStokBaris();
    return;
  }
  var isi = Array.from(document.querySelectorAll('#tbodyItem tr')).filter(function(tr){ return barisDiKardus(tr, n); }).length;
  if (isi && !confirm('Kardus ' + n + ' berisi ' + isi + ' baris. Hapus kardusnya? Barangnya tetap ada sebagai barang tanpa kardus.')) return;
  kartu.remove();
  if (kardusAktif === n) kardusAktif = 0;
  segarkanOpsiKardus();
  if (!daftarNomorKardus().length) { setKardusAktif(0); }
  else { setKardusAktif(kardusAktif); }
}
function setKardusAktif(n){
  n = n || 0;
  if (n && daftarNomorKardusBaru().indexOf(n) === -1) n = 0;
  kardusAktif = n;
  var rKardus = document.querySelector('input[name="cara_kirim"][value="kardus"]');
  var rTanpa  = document.querySelector('input[name="cara_kirim"][value="tanpa"]');
  if (rKardus && rTanpa) { rKardus.checked = n > 0; rTanpa.checked = n === 0; }
  var wrap = document.getElementById('wrapPilihKardus');
  if (wrap) wrap.style.display = (n > 0 || (rKardus && rKardus.checked)) ? '' : 'none';
  document.querySelectorAll('#daftarKardusBaru .kartu-kardus').forEach(function(k){
    var aktif = parseInt(k.dataset.idx, 10) === n;
    k.style.borderColor = aktif ? 'var(--primary)' : 'var(--border)';
    k.style.boxShadow   = aktif ? '0 0 0 3px var(--primary-lembut)' : 'none';
  });
  var p = document.getElementById('pilihKardusAktif'); if (p && n) p.value = String(n);
  var ket = document.getElementById('ketKardusAktif');
  if (ket) ket.textContent = n ? 'Barang baru masuk ke Kardus ' + n + namaKardus(n) : 'Barang baru masuk sebagai barang tanpa kardus';
  segarkanRingkasanKardus();
}
function pilihCaraKirim(cara){
  if (cara === 'kardus') {
    var ada = daftarNomorKardusBaru();
    if (!ada.length) { buatKardusBaru(); } else { setKardusAktif(kardusAktif || ada[ada.length - 1]); }
    document.getElementById('wrapPilihKardus').style.display = '';
  } else {
    setKardusAktif(0);
  }
}
/* Kotak cara kirim & kolom Kardus hanya untuk jenis yang boleh berkardus;
   untuk jenis lain semua baris dipaksa tanpa kardus. */
function segarkanKardusUntukJenis(){
  var boleh = kardusBolehUntukJenis();
  var kotak = document.getElementById('kotakCaraKirim');
  if (kotak) kotak.hidden = !boleh;
  document.querySelectorAll('.col-kardus').forEach(function(el){ el.style.display = boleh ? '' : 'none'; });
  if (!boleh) {
    document.querySelectorAll('#tbodyItem .sel-kardus').forEach(function(s){ s.value = '0'; });
    kardusAktif = 0;
  }
  segarkanRingkasanKardus();
}
function kardusKosong(){
  return daftarNomorKardus().filter(function(n){
    return !Array.from(document.querySelectorAll('#tbodyItem tr')).some(function(tr){
      var s = tr.querySelector('[name="barang_id[]"]');
      return s && s.value && barisDiKardus(tr, n);
    });
  });
}
function teksPilihan(id){
  var s = document.getElementById(id); if (!s) return '';
  if (s.tagName === 'SELECT') { var o = s.options[s.selectedIndex]; return o ? o.textContent.trim() : ''; }
  return s.value || '';
}
/* Ringkasan: kelompok per kardus (bisa dibuka/tutup), barang tanpa kardus,
   lalu angka: jumlah kardus, jenis unik, total per satuan, asal → tujuan. */
function segarkanRingkasanKardus(){
  var wadah = document.getElementById('ringkasKelompok'), angka = document.getElementById('ringkasAngka');
  if (!wadah) return;
  var boleh = kardusBolehUntukJenis();
  var kelompok = {}; var unik = {}; var perSatuan = {}; var total = 0;
  Array.from(document.querySelectorAll('#tbodyItem tr')).forEach(function(tr){
    var s = tr.querySelector('[name="barang_id[]"]'); if (!s || !s.value) return;
    var b = BARANG.find(function(x){ return String(x.id) === String(s.value); }) || {n: ((tr.querySelector('.b-nama') || {}).textContent || ('#' + s.value)), s: 'unit', k: ''};
    var q = parseInt(tr.querySelector('input[name="qty[]"]').value, 10) || 0;
    var n = boleh ? (parseInt((tr.querySelector('.sel-kardus') || tr.querySelector('input[name="kardus_idx[]"]') || {}).value, 10) || 0) : 0;
    (kelompok[n] = kelompok[n] || []).push({nama: b.n || b.t, kode: b.k || '', qty: q, sat: b.s || 'unit'});
    unik[s.value] = true; perSatuan[b.s || 'unit'] = (perSatuan[b.s || 'unit'] || 0) + q; total += q;
  });
  var nomor = daftarNomorKardus();
  var h = '';
  nomor.forEach(function(n){
    var isi = kelompok[n] || [];
    var tot = {}; isi.forEach(function(i){ tot[i.sat] = (tot[i.sat] || 0) + i.qty; });
    h += '<details open class="ringkas-grup"><summary style="cursor:pointer;font-weight:700;padding:6px 0">KARDUS ' + n + lolos(namaKardus(n)) + ' <small class="mut2">· ' + isi.length + ' jenis · ' + (Object.keys(tot).map(function(k){ return tot[k] + ' ' + k; }).join(' + ') || 'kosong') + '</small></summary>' +
         (isi.length ? '<ul style="margin:0 0 6px 18px;padding:0;font-size:13px">' + isi.map(function(i){ return '<li>' + lolos(i.nama) + ': <b>' + i.qty + '</b> ' + lolos(i.sat) + '</li>'; }).join('') + '</ul>'
                     : '<p class="petunjuk" style="margin:0 0 6px 18px;color:var(--red)">Kardus ini masih kosong.</p>') +
         '</details>';
    var r = document.querySelector('#daftarKardusBaru .kartu-kardus[data-idx="' + n + '"] .isi-kardus-ringkas');
    if (r) r.textContent = isi.length ? isi.map(function(i){ return i.nama + ' ' + i.qty + ' ' + i.sat; }).join(', ') : 'kosong';
  });
  var lepas = kelompok[0] || [];
  if (lepas.length || nomor.length) {
    h += '<details open class="ringkas-grup"><summary style="cursor:pointer;font-weight:700;padding:6px 0">BARANG TANPA KARDUS <small class="mut2">· ' + lepas.length + ' jenis</small></summary>' +
         (lepas.length ? '<ul style="margin:0 0 6px 18px;padding:0;font-size:13px">' + lepas.map(function(i){ return '<li>' + lolos(i.nama) + ': <b>' + i.qty + '</b> ' + lolos(i.sat) + '</li>'; }).join('') + '</ul>' : '<p class="petunjuk" style="margin:0 0 6px 18px">tidak ada</p>') + '</details>';
  }
  wadah.innerHTML = h;
  var jml = Object.keys(unik).length;
  angka.innerHTML = jml
    ? (boleh ? '<b>' + nomor.length + ' kardus</b> · ' : '') + '<b>' + jml + ' jenis barang</b> · ' + Object.keys(perSatuan).map(function(k){ return perSatuan[k] + ' ' + k; }).join(' + ') +
      ' · ' + lolos(teksPilihan('asal_id') || teksPilihan('asal_teks')) + ' → ' + lolos(teksPilihan('tujuan_id') || teksPilihan('tujuan_teks'))
    : 'Belum ada barang.';
}
document.getElementById('tbodyItem').addEventListener('input', function(ev){
  if (ev.target.name === 'qty[]' || ev.target.name === 'barang_id[]') segarkanRingkasanKardus();
});
document.getElementById('tbodyItem').addEventListener('change', function(ev){
  if (ev.target.name === 'barang_id[]') segarkanRingkasanKardus();
});
['asal_id','tujuan_id','asal_tipe','tujuan_tipe'].forEach(function(id){ document.getElementById(id).addEventListener('change', segarkanRingkasanKardus); });
/* Kepala kardus dari kiriman yang gagal divalidasi — kartunya dibangun ulang. */
/* =====================================================================
   KARDUS SIAP KIRIM (revisi 18 Okt 2026)
   Kardus yang sudah dikemas lewat Pindah Antar Rak (mutasi rak ke rak
   siap kirim) tinggal dicentang. Isinya masuk sebagai baris terkunci;
   server membaca ulang isi aslinya, jadi yang tampil hanya pratinjau.
   ===================================================================== */
function kardusSiapUntukAsal(){
  const a = asalSekarang();
  if (JENIS_KARDUS_SIAP.indexOf(a.jenis) === -1 || !a.id) return [];
  const id = parseInt(a.id, 10);
  return KARDUS_SIAP.filter(function(k){
    if (a.tipe === 'gudang') return k.g === id;
    if (a.tipe === 'rak') return k.r === id;
    return false;
  });
}
function idxKardusAda(kid){
  const el = document.querySelector('#daftarKardusBaru .kartu-kardus[data-ada="' + kid + '"]');
  return el ? parseInt(el.dataset.idx, 10) : 0;
}
function segarkanKardusSiap(){
  const wadah = document.getElementById('daftarKardusSiap'), blok = document.getElementById('blokKardusSiap');
  if (!wadah || !blok) return;
  const daftar = kardusSiapUntukAsal();
  /* Kardus siap yang sudah dicentang tetapi asalnya kemudian diganti: dilepas. */
  document.querySelectorAll('#daftarKardusBaru .kartu-kardus[data-ada]').forEach(function(k){
    if (!daftar.some(function(x){ return String(x.id) === k.dataset.ada; })) { hapusKardus(parseInt(k.dataset.idx, 10), true); }
  });
  blok.style.display = daftar.length ? '' : 'none';
  wadah.innerHTML = daftar.map(function(k){
    const tot = {}; k.isi.forEach(function(i){ tot[i.s || 'unit'] = (tot[i.s || 'unit'] || 0) + i.q; });
    return '<label style="display:flex;gap:8px;align-items:flex-start;padding:8px 10px;border:1px solid var(--border);border-radius:9px;cursor:pointer">' +
      '<input type="checkbox" value="' + k.id + '" onchange="centangKardusSiap(this)"' + (idxKardusAda(k.id) ? ' checked' : '') + ' style="margin-top:3px">' +
      '<span><code>' + lolos(k.kode) + '</code>' + (k.nama ? ' <b>' + lolos(k.nama) + '</b>' : '') +
      '<br><small class="mut2">' + lolos(k.rak) + ' · ' + k.isi.length + ' jenis · ' + Object.keys(tot).map(function(s){ return tot[s] + ' ' + lolos(s); }).join(' + ') +
      '<br>' + lolos(k.isi.slice(0, 3).map(function(i){ return i.n + ' ' + i.q; }).join(', ') + (k.isi.length > 3 ? ', +' + (k.isi.length - 3) + ' lainnya' : '')) + '</small></span></label>';
  }).join('');
}
function centangKardusSiap(cb){
  const k = KARDUS_SIAP.find(function(x){ return String(x.id) === cb.value; });
  if (!k) return;
  if (cb.checked) { tambahKardusSiap(k, true); }
  else { const n = idxKardusAda(k.id); if (n) hapusKardus(n, true); }
}
function tambahKardusSiap(k, denganBaris){
  if (idxKardusAda(k.id)) return idxKardusAda(k.id);
  const n = ++nKardus;
  const kartu = document.createElement('div');
  kartu.className = 'kartu-kardus'; kartu.dataset.idx = n; kartu.dataset.ada = k.id;
  kartu.style.cssText = 'border:1px solid var(--border);border-radius:10px;padding:10px 12px;background:var(--surface-2)';
  kartu.innerHTML = '<input type="hidden" name="kardus_ada[' + n + ']" value="' + k.id + '">' +
    '<div style="display:flex;justify-content:space-between;gap:8px"><b class="judul-kardus">Kardus ' + n + ' — ' + lolos(k.kode) + '</b><small class="mut2">siap kirim · ' + lolos(k.rak) + '</small></div>' +
    (k.nama ? '<div class="mut2" style="font-size:12px">' + lolos(k.nama) + '</div>' : '') +
    '<div class="isi-kardus-ringkas petunjuk" style="margin-top:6px"></div>' +
    '<div style="margin-top:8px"><button type="button" class="btn btn-ghost btn-sm" onclick="hapusKardus(' + n + ', true)">Lepas kardus ini</button></div>';
  document.getElementById('daftarKardusBaru').appendChild(kartu);
  if (denganBaris) {
    bersihkanBarisKosong();
    k.isi.forEach(function(i){ tambahBaris({ barang_id: i.b, nama: i.n, kode: i.k, satuan: i.s, qty: i.q, kardus_idx: n, kunci: true }); });
  }
  const wrap = document.getElementById('wrapPilihKardus'); if (wrap) wrap.style.display = '';
  const rK = document.querySelector('input[name="cara_kirim"][value="kardus"]'); if (rK) rK.checked = true;
  segarkanOpsiKardus(); segarkanRingkasanKardus();
  return n;
}
/* Baris dari draf / kiriman gagal yang milik kardus siap kirim: dikunci lagi. */
function kunciBarisKardusSiap(){
  document.querySelectorAll('#daftarKardusBaru .kartu-kardus[data-ada]').forEach(function(k){
    const n = parseInt(k.dataset.idx, 10);
    document.querySelectorAll('#tbodyItem tr').forEach(function(tr){
      const s = tr.querySelector('.sel-kardus');
      if (s && (parseInt(s.value, 10) || 0) === n) {
        tr.dataset.kunci = '1';
        tr.querySelector('input[name="qty[]"]').readOnly = true;
        const btn = tr.querySelector('.aksi button'); if (btn) btn.remove();
        const g = tr.querySelector('button[onclick^="gantiBarangBaris"]'); if (g) g.remove();
        const sel = s; const td = sel.closest('td');
        td.innerHTML = '<input type="hidden" name="kardus_idx[]" value="' + n + '"><small><b>Kardus ' + n + '</b><br>siap kirim</small>';
      }
    });
  });
}
function pulihkanKardusAwal(kepala){
  Object.keys(kepala || {}).sort(function(a,b){ return a-b; }).forEach(function(ix){
    ix = parseInt(ix, 10); if (!ix) return;
    var k = kepala[String(ix)] || kepala[ix] || {};
    if (k.ada) {
      var ks = KARDUS_SIAP.find(function(x){ return String(x.id) === String(k.ada); });
      while (nKardus < ix - 1) { buatKardusBaru(); }
      if (ks) { tambahKardusSiap(ks, false); return; }
    }
    while (nKardus < ix) { buatKardusBaru(); }
    var set = function(n, v){ var el = document.querySelector('[name="' + n + '[' + ix + ']"]'); if (el && v) el.value = v; };
    set('kardus_nama', k.nama); set('kardus_berat', k.berat_kg); set('kardus_p', k.panjang_cm); set('kardus_l', k.lebar_cm); set('kardus_t', k.tinggi_cm); set('kardus_catatan', k.catatan);
  });
  segarkanOpsiKardus(); setKardusAktif(0);
}

document.getElementById('jenis').addEventListener('change', segarkanJenis);
document.getElementById('asal_id').addEventListener('change', segarkanKonteksScan);
['jenis', 'asal_tipe', 'asal_id'].forEach(function(id){ document.getElementById(id).addEventListener('change', function(){ segarkanStokBaris(); segarkanKardusSiap(); }); });
document.getElementById('tbodyItem').addEventListener('input', function(ev){ if (ev.target.name === 'qty[]') tandaiStokBaris(); });
document.getElementById('asal_tipe').addEventListener('change', segarkanAsal);
document.getElementById('tujuan_tipe').addEventListener('change', segarkanTujuan);

/* Cegah kirim form tanpa satu pun baris barang terisi. */
document.getElementById('frm').addEventListener('submit', function(ev){
  const adaIsi = Array.from(document.querySelectorAll('[name="barang_id[]"]')).some(function(s){ return s.value; });
  if (!adaIsi){
    ev.preventDefault();
    alert('Isi minimal satu baris barang sebelum menyimpan.');
    return;
  }
  // Jumlah melebihi stok yang masih bisa dikirim (revisi 18 Okt 2026).
  var lebihStok = barisMelebihiStok();
  if (lebihStok.length){
    ev.preventDefault();
    alert('Jumlah melebihi stok yang masih bisa dikirim dari lokasi asal:\n• ' + lebihStok.join('\n• ') + '\n\nKurangi jumlahnya, atau cek surat jalan lain yang masih menunggu.');
    return;
  }
  // Kardus tanpa isi tidak boleh ikut tersimpan (revisi kardus 12 Okt 2026).
  var kosong = kardusKosong();
  if (kosong.length){
    ev.preventDefault();
    alert('Kardus ' + kosong.join(', ') + ' masih kosong. Tambahkan barangnya atau hapus kardus itu.');
  }
});

segarkanJenis();
segarkanKonteksScan();
if (Object.keys(KARDUS_AWAL).length){ pulihkanKardusAwal(KARDUS_AWAL); }
if (PREFILL.length){
  PREFILL.forEach(function(it){ tambahBaris(it); });
}
kunciBarisKardusSiap();
segarkanOpsiKardus(); segarkanRingkasanKardus(); segarkanKardusSiap(); segarkanStokBaris();

/* =====================================================================
   EMPAT CARA MENAMBAHKAN BARANG

   Yang sudah ada: tembak barcode (scanTambahBaris) dan tambah baris
   kosong (tambahBaris). Dua di bawah menutup jarak di antaranya.

   Keduanya berujung ke tambahBarisAtauTambahJumlah() — barang yang sudah
   ada di daftar TIDAK dibuat baris kembar, jumlahnya ditambahkan. Itu
   aturan yang sama dengan hasil scan, dan menyeragamkannya penting:
   petugas yang menempel daftar lalu menembak barcode barang yang sama
   tidak boleh mendapat dua baris untuk satu barang.
   ===================================================================== */

/**
 * Loloskan teks yang akan masuk innerHTML.
 *
 * WAJIB dipakai untuk nama & kode barang: keduanya datang dari basis
 * data, dan nama produk yang memuat < atau & akan memecah markup panel —
 * bukan hanya jelek, tapi membuat sisa daftarnya hilang.
 *
 * Didefinisikan di sini, bukan diambil dari berkas lain: keranjang
 * barang masuk punya fungsi bernama sama, tetapi berkas itu TIDAK
 * dimuat di halaman perpindahan. Memanggil yang tidak ada berarti
 * ReferenceError, dan panelnya mati tanpa satu pun tanda di layar.
 */
function lolos(s) {
  return String(s === null || s === undefined ? '' : s)
    .replace(/&/g, '&amp;').replace(/</g, '&lt;')
    .replace(/>/g, '&gt;').replace(/"/g, '&quot;');
}

/** Satu pintu untuk semua cara. Mengembalikan true bila baris BARU dibuat. */
function tambahBarisAtauTambahJumlah(barangId, jumlah, tambahan) {
  /* Baris dengan batch yang DIPILIH SENDIRI tidak digabungkan ke baris
     barang yang sama. Dua batch berbeda dari satu barang adalah dua
     tumpukan fisik dengan kedaluwarsa berbeda; menjumlahkannya menjadi
     satu baris membuang informasi yang justru jadi alasan orang memilih
     batch-nya. Barisnya sengaja dibiarkan kembar — server memeriksa
     ketersediaan atas TOTAL per barang, jadi kembarannya aman. */
  if (tambahan && tambahan.nomor_batch) {
    tambahBaris({
      barang_id:       barangId,
      qty:             jumlah,
      nomor_batch:     tambahan.nomor_batch,
      tanggal_expired: tambahan.tanggal_expired || ''
    });
    return true;
  }

  var sel = Array.prototype.slice
    .call(document.querySelectorAll('[name="barang_id[]"]'))
    .filter(function (s) {
      /* Hanya baris yang batch-nya kosong. Menambahkan jumlah ke baris
         yang batch-nya sudah ditentukan akan mengirim lebih banyak dari
         batch itu daripada isinya. Dan hanya baris di kelompok kardus yang
         sedang aktif — barang yang sama di kardus lain adalah baris lain. */
      var b = s.closest('tr').querySelector('input[name="nomor_batch[]"]');
      return (!b || b.value.trim() === '') && barisDiKardus(s.closest('tr'), kardusAktif);
    })
    .find(function (s) { return String(s.value) === String(barangId); });

  if (sel) {
    var q = sel.closest('tr').querySelector('input[name="qty[]"]');
    q.value = (parseInt(q.value, 10) || 0) + jumlah;
    q.style.background = 'var(--primary-lembut)';
    setTimeout(function () { q.style.background = ''; }, 900);
    /* Menyetel .value lewat kode TIDAK membangkitkan 'input', dan baris
       yang sudah ada tidak mengubah struktur tabel — jadi jalur ini luput
       dari kedua pengait draf. Tanpa panggilan ini, menambah jumlah lewat
       "Pilih Banyak Barang" tidak pernah tersimpan. */
    if (typeof window.drafSegarkan === 'function') { window.drafSegarkan(); }
    return false;
  }
  tambahBaris({ barang_id: barangId, qty: jumlah });
  return true;
}

/** Buang baris yang barangnya belum dipilih — supaya daftarnya rapi. */
function bersihkanBarisKosong() {
  document.querySelectorAll('#tbodyItem tr').forEach(function (tr) {
    var s = tr.querySelector('[name="barang_id[]"]');
    if (s && !s.value) { tr.remove(); }
  });
}

/* ------------------------------------------------- panel: pilih banyak
   Isinya diambil dari cari_stok.php, BUKAN dari daftar BARANG yang sudah
   dimuat halaman. Sebabnya penyaring "barang masuk kapan": tanggal tiba
   tiap batch tidak ada di data halaman, dan memuatnya di awal berarti
   mengirim seluruh isi gudang setiap kali formulir dibuka.
   -------------------------------------------------------------------- */

var stokPanel  = [];      // hasil terakhir dari server
var stokMemuat = false;

function tutupPanelTambah() {
  ['panelPilihBanyak', 'panelTempelKode'].forEach(function (id) {
    var p = document.getElementById(id);
    if (p) { p.hidden = true; }
  });
}

function bukaPilihBanyak() {
  tutupPanelTambah();
  document.getElementById('panelPilihBanyak').hidden = false;
  /* Sumber panel mengikuti Asal dokumen bila cocok (rak/gudang/toko), supaya
     yang tampil langsung isi lokasi asalnya. Untuk penyaluran & kirim toko,
     penyaring siap salur dikunci menyala — stok lain memang tidak bisa dibawa. */
  var sel = document.getElementById('sumberPilih');
  var asalNilai = document.getElementById('asal_tipe').value + ':' + document.getElementById('asal_id').value;
  if (sel && [...sel.options].some(function (o) { return o.value === asalNilai; })) { sel.value = asalNilai; }
  var cb = document.getElementById('hanyaLayak'), ket = document.getElementById('ketHanyaLayak');
  var jenisKini = (document.getElementById('jenis') || {}).value || '';
  var wajibLayak = ['penyaluran', 'kirim_toko', 'antar_toko'].indexOf(jenisKini) !== -1;
  if (cb) { if (wajibLayak) { cb.checked = true; } cb.disabled = wajibLayak; }
  if (ket) { ket.textContent = wajibLayak
    ? 'Jenis dokumen ini hanya boleh membawa stok siap salur — Rusak/Bocor, Expired, dan tidak layak tidak ikut.'
    : 'Rusak/Bocor, Expired, dan tidak layak disembunyikan bila dicentang.'; }
  /* Dimuat setiap kali dibuka, tidak di-cache. Stok berubah karena orang
     lain juga bekerja, dan panel yang menampilkan angka lama membuat
     orang menyusun surat jalan atas barang yang sudah pergi. */
  muatStokPanel();
  document.getElementById('cariPilihBanyak').focus();
}

/** Setel kedua kotak tanggal dari tombol cepat, lalu muat ulang. */
function rentangCepat(nama) {
  var d = new Date();
  function iso(x) {
    /* Dibentuk dari komponen lokal, BUKAN toISOString(). toISOString
       mengubah ke UTC lebih dulu, dan di WIB (+7) itu menggeser tanggal
       ke hari sebelumnya untuk sepanjang pagi — "Hari ini" menampilkan
       barang kemarin sampai pukul tujuh pagi. */
    var b = ('0' + (x.getMonth() + 1)).slice(-2);
    var t = ('0' + x.getDate()).slice(-2);
    return x.getFullYear() + '-' + b + '-' + t;
  }
  var dari = '', ke = '';
  if (nama === 'hari-ini') { dari = ke = iso(d); }
  else if (nama === 'kemarin') {
    var y = new Date(d.getTime() - 86400000);
    dari = ke = iso(y);
  } else if (nama === '7hari') {
    var w = new Date(d.getTime() - 6 * 86400000);
    dari = iso(w); ke = iso(d);
  }
  document.getElementById('stokDari').value = dari;
  document.getElementById('stokKe').value   = ke;
  muatStokPanel();
}

function muatStokPanel() {
  if (stokMemuat) { return; }
  stokMemuat = true;

  var sumber = document.getElementById('sumberPilih').value || 'semua';
  var pecah  = sumber.split(':');
  var q = 'sumber_tipe=' + encodeURIComponent(pecah[0]);
  if (pecah[1]) { q += '&sumber_id=' + encodeURIComponent(pecah[1]); }
  var cbLayak = document.getElementById('hanyaLayak');
  if (cbLayak && cbLayak.checked) { q += '&layak=1'; }
  var dari = document.getElementById('stokDari').value;
  var ke   = document.getElementById('stokKe').value;
  if (dari) { q += '&dari=' + encodeURIComponent(dari); }
  if (ke)   { q += '&ke='   + encodeURIComponent(ke); }

  var wadah  = document.getElementById('daftarPilihBanyak');
  var ringkas = document.getElementById('ringkasStok');
  ringkas.textContent = 'Memuat…';

  fetch('cari_stok.php?' + q, { credentials: 'same-origin' })
    .then(function (r) { return r.json(); })
    .then(function (j) {
      stokMemuat = false;
      if (!j || !j.ok) {
        ringkas.textContent = (j && j.error) ? j.error : 'Gagal membaca stok.';
        wadah.innerHTML = '';
        return;
      }
      stokPanel = j.barang || [];
      gambarStokPanel();
    })
    .catch(function () {
      /* Kegagalan jaringan disebutkan apa adanya. Panel yang diam saja
         terbaca sebagai "gudangnya memang kosong" — kesimpulan yang
         justru berbahaya saat menyusun surat jalan. */
      stokMemuat = false;
      ringkas.textContent = 'Tidak dapat menghubungi server. Periksa koneksi, lalu coba lagi.';
      wadah.innerHTML = '';
    });
}

function gambarStokPanel() {
  var wadah   = document.getElementById('daftarPilihBanyak');
  var ringkas = document.getElementById('ringkasStok');

  if (!stokPanel.length) {
    wadah.innerHTML = '';
    ringkas.textContent = 'Tidak ada stok yang cocok dengan penyaring ini.';
    hitungTerpilih();
    return;
  }

  var totUnit = stokPanel.reduce(function (a, b) { return a + b.total; }, 0);
  ringkas.textContent = stokPanel.length + ' jenis barang · ' + totUnit + ' unit';

  var diForm = totalPerBarangForm();
  wadah.innerHTML = stokPanel.map(function (b) {
    /* Yang masih bisa dipilih: stok sumber − dipesan surat jalan lain − yang
       sudah ada di formulir ini (revisi 18 Okt 2026). */
    var bisa = Math.max(0, b.total - (b.pesan || 0) - (diForm[b.id] || 0));
    var lok = (b.lokasi || []).map(function (l) {
      return '<span class="lok-stok">' + lolos(l.n) + ' <b>' + l.q + '</b></span>';
    }).join('');
    if (b.lain) { lok += '<span class="lok-stok">+' + b.lain + ' lokasi lain</span>'; }
    /* Tanggal masuk terakhir ikut ditampilkan — itu yang membedakan
       "stok lama yang menumpuk" dari "kiriman yang baru tiba", dan
       keduanya diperlakukan berbeda saat menyusun pengiriman. */
    if (b.masuk) { lok += '<span class="lok-stok">masuk ' + lolos(b.masuk) + '</span>'; }

    /* ------------------------------------------------------- pilihan batch
       Urutannya sudah FEFO dari server, jadi pilihan pertama sesudah
       "otomatis" adalah batch yang memang akan diambil sistem. Nomor,
       kedaluwarsa, jumlah, dan lokasinya ditulis lengkap pada satu baris:
       memilih batch tanpa melihat tanggal kedaluwarsanya bukan pilihan,
       hanya tebakan. */
    var opsiBatch = '<option value="" data-q="' + bisa + '">' +
                    'Otomatis (FEFO — kedaluwarsa terdekat dulu)</option>';
    (b.batch || []).forEach(function (bt) {
      var teks = bt.b || '(tanpa nomor)';
      if (bt.e) { teks += ' · exp ' + bt.e; }
      teks += ' · ' + bt.q + ' ' + (b.s || 'unit');
      if (bt.l) { teks += ' · ' + bt.l; }
      opsiBatch += '<option value="' + lolos(bt.b) + '" data-q="' + bt.q +
                   '" data-exp="' + lolos(bt.e || '') + '">' + lolos(teks) + '</option>';
    });

    /* Pengatur jumlah & batch berada DI LUAR <label>. Kalau ia di dalam,
       setiap klik pada kotak jumlah atau daftar batch akan ikut mematikan
       centangnya — barisnya hilang justru saat sedang diisi. */
    return '<div class="baris-centang" data-cari="' +
      (b.n + ' ' + b.k).toLowerCase().replace(/"/g, '') + '">' +
      '<label class="pilih-barang">' +
        '<input type="checkbox" value="' + b.id + '" data-maks="' + bisa + '"' + (bisa <= 0 ? ' disabled' : '') + ' data-x="' + b.total +
          '" data-satuan="' + lolos(b.s || 'unit') + '" onchange="pilihBarisStok(this)">' +
        '<span class="isi-centang">' +
          '<span class="judul-centang">' + lolos(b.n) +
            ' <small>' + lolos(b.k) + '</small> ' +
            '<span class="tag ' + (bisa > 0 ? 't-green' : 't-red') + '">tersedia ' + bisa + ' ' + lolos(b.s || 'unit') + '</span>' +
            ((b.pesan || diForm[b.id]) ? ' <small class="mut2">stok ' + b.total + (b.pesan ? ' · dipesan SJ lain ' + b.pesan : '') + (diForm[b.id] ? ' · sudah di formulir ' + diForm[b.id] : '') + '</small>' : '') +
          '</span>' +
          (lok ? '<span class="baris-lok">' + lok + '</span>' : '') +
        '</span>' +
      '</label>' +
      '<div class="atur-kirim" hidden>' +
        '<div class="field"><label>Jumlah dikirim</label>' +
          '<div class="baris-jml-maks">' +
            '<input type="number" class="qty-pilih" min="1" max="' + bisa +
              '" value="1" oninput="this.dataset.disunting=1; hitungTerpilih()">' +
            '<button type="button" class="btn btn-ghost btn-sm btn-semua-stok" ' +
              'onclick="isiSemuaStok(this)">Semua ' + bisa + '</button>' +
          '</div>' +
        '</div>' +
        '<div class="field"><label>Dari batch</label>' +
          '<select class="batch-pilih" onchange="pilihBatchStok(this)">' + opsiBatch + '</select>' +
        '</div>' +
      '</div>' +
    '</div>';
  }).join('');

  saringPilihBanyak();
  hitungTerpilih();
}

/** Baris yang sedang terlihat saja — hasil penyaring & pencarian. */
function barisStokTerlihat() {
  return Array.prototype.filter.call(
    document.querySelectorAll('#daftarPilihBanyak .baris-centang'),
    function (b) { return !b.hidden; });
}

/**
 * Centang / batalkan seluruh barang yang sedang terlihat.
 *
 * Satu tombol dua arah, bukan dua tombol berdampingan. Saat semuanya sudah
 * tercentang, "Pilih semua" tidak punya pekerjaan lagi — tombol yang diam
 * ketika ditekan membuat orang menekannya berulang untuk memastikan.
 */
function pilihSemuaStok() {
  var baris = barisStokTerlihat();
  if (!baris.length) { return; }

  var semuaSudah = baris.every(function (b) {
    return b.querySelector('input[type=checkbox]').checked;
  });

  baris.forEach(function (b) {
    var cb = b.querySelector('input[type=checkbox]');
    if (cb.disabled) { return; }   // stok tersedia 0
    cb.checked = !semuaSudah;
    if (!semuaSudah && jumlahOtomatis()) { var qa = b.querySelector('.qty-pilih'); qa.value = qa.getAttribute('max'); qa.dataset.disunting = 1; }
    b.querySelector('.atur-kirim').hidden = semuaSudah;
    if (semuaSudah) {
      /* Dibatalkan berarti dikembalikan ke keadaan awal, termasuk jumlah
         dan batch-nya. Menyisakan angka dari centang sebelumnya membuat
         centang berikutnya membawa jumlah yang tidak pernah diketik
         orang untuk barang itu. */
      var q = b.querySelector('.qty-pilih');
      q.value = 1;
      delete q.dataset.disunting;
      b.querySelector('.batch-pilih').selectedIndex = 0;
    }
  });
  hitungTerpilih();
}

/** Label tombol mengikuti keadaan: memilih, atau membatalkan. */
function segarkanTombolPilihSemua() {
  var tb = document.getElementById('tombolPilihSemua');
  if (!tb) { return; }
  var baris = barisStokTerlihat();

  tb.disabled = !baris.length;
  if (!baris.length) { tb.textContent = 'Pilih semua'; return; }

  var terpilih = baris.filter(function (b) {
    return b.querySelector('input[type=checkbox]').checked;
  }).length;

  /* Angkanya disebut. "Pilih semua" tanpa angka tidak memberi tahu
     seberapa banyak yang akan masuk — dan sesudah disaring, jumlahnya
     hampir selalu bukan seluruh katalog. */
  tb.textContent = (terpilih === baris.length)
    ? 'Batalkan semua (' + baris.length + ')'
    : 'Pilih semua (' + baris.length + ')';
}

/** Centang dinyalakan/dimatikan — tampilkan atau sembunyikan pengaturnya. */
function pilihBarisStok(cb) {
  var baris = cb.closest('.baris-centang');
  var atur  = baris.querySelector('.atur-kirim');
  atur.hidden = !cb.checked;
  if (cb.checked && jumlahOtomatis()) {
    var qo = atur.querySelector('.qty-pilih'); qo.value = qo.getAttribute('max'); qo.dataset.disunting = 1;
    hitungTerpilih(); return;
  }
  if (cb.checked) {
    /* Fokus langsung ke kotak jumlah. Itu keputusan berikutnya yang harus
       diambil orang, dan tanpa fokus ia harus mengarahkan tetikus ke
       kotak yang baru saja muncul. */
    var q = atur.querySelector('.qty-pilih');
    q.focus();
    q.select();
  }
  hitungTerpilih();
}

/** Kotak "Jumlah otomatis = seluruh stok tersedia" di panel (revisi 18 Okt 2026). */
function jumlahOtomatis() { var c = document.getElementById('jumlahOtomatis'); return !!(c && c.checked); }
/** Kotak itu dicentang/dilepas: barang yang sudah dicentang ikut diisi seluruh stoknya. */
function terapkanJumlahOtomatis() {
  if (!jumlahOtomatis()) { return; }
  document.querySelectorAll('#daftarPilihBanyak input[type=checkbox]:checked').forEach(function (c) {
    var q = c.closest('.baris-centang').querySelector('.qty-pilih'); q.value = q.getAttribute('max'); q.dataset.disunting = 1;
  });
  hitungTerpilih();
}

/** Tombol "Semua N" — isi jumlah sebanyak stok yang tersedia. */
function isiSemuaStok(btn) {
  var baris = btn.closest('.baris-centang');
  var q     = baris.querySelector('.qty-pilih');
  q.value = q.getAttribute('max');
  q.dataset.disunting = 1;
  hitungTerpilih();
}

/** Batch dipilih — batas jumlahnya menyusut ke isi batch itu. */
function pilihBatchStok(sel) {
  var baris = sel.closest('.baris-centang');
  var q     = baris.querySelector('.qty-pilih');
  var opsi  = sel.options[sel.selectedIndex];
  var maks  = parseInt(opsi.getAttribute('data-q'), 10) || 0;

  if (maks > 0) {
    q.setAttribute('max', maks);
    /* Jumlah yang sudah MELEBIHI isi batch dipangkas — batasnya berubah,
       dan membiarkan angka lama berarti dokumen dikirim dengan jumlah
       yang tidak mungkin dipenuhi dari batch yang dipilih sendiri. */
    if ((parseInt(q.value, 10) || 0) > maks) { q.value = maks; }
    /* Kalau jumlahnya belum pernah disunting orang, diisikan seisi batch.
       Memilih batch tertentu hampir selalu berarti "kirim batch ini";
       tapi begitu orang mengetik angkanya sendiri, angka itu miliknya dan
       tidak ditimpa lagi. */
    if (!q.dataset.disunting) { q.value = maks; }
  }
  var tb = baris.querySelector('.btn-semua-stok');
  if (tb) { tb.textContent = 'Semua ' + maks; }
  hitungTerpilih();
}

/** Sembunyikan kolom batch/expired bila tak seorang pun memakainya. */
function sesuaikanKolomBatch() {
  var penerimaan = (document.getElementById('jenis').value === 'penerimaan');
  /* Pada dokumen KELUAR kolom batch biasanya tidak relevan — batch-nya
     ditentukan FEFO saat disetujui. Tapi begitu ada satu baris yang batch-nya
     dipilih sendiri, kolomnya harus terlihat: nilai yang mempengaruhi barang
     mana yang benar-benar keluar tidak boleh tersembunyi dari orang yang
     menandatangani surat jalannya. */
  var adaPilihan = Array.prototype.some.call(
    document.querySelectorAll('input[name="nomor_batch[]"]'),
    function (i) { return i.value.trim() !== ''; });

  var tampil = penerimaan || adaPilihan;
  document.querySelectorAll('.col-batch, .col-exp').forEach(function (el) {
    el.style.display = tampil ? '' : 'none';
    el.classList.toggle('hide', !tampil);
  });
}

function saringPilihBanyak() {
  var q = document.getElementById('cariPilihBanyak').value.toLowerCase().trim();
  document.querySelectorAll('#daftarPilihBanyak .baris-centang').forEach(function (l) {
    /* Yang SUDAH dicentang tetap terlihat meski tidak cocok pencarian.
       Kalau disembunyikan, orang mencari barang kedua, tidak melihat
       centang pertamanya, dan mengira centangnya hilang. */
    var cocok = !q || l.dataset.cari.indexOf(q) !== -1;
    l.hidden = !cocok && !l.querySelector('input[type=checkbox]').checked;
  });
  /* Tombol "Pilih semua" berlaku atas yang terlihat, jadi labelnya harus
     ikut berubah begitu pencariannya menyempit — "Pilih semua (30)" yang
     ternyata hanya menyentang 3 adalah tombol yang berbohong. */
  segarkanTombolPilihSemua();
}

function hitungTerpilih() {
  var dipilih = document.querySelectorAll('#daftarPilihBanyak input[type=checkbox]:checked');
  var el = document.getElementById('jmlTerpilih');
  if (el) { el.textContent = dipilih.length; }
  segarkanTombolPilihSemua();

  /* Total unit ikut dihitung. Dulu jumlahnya seragam sehingga angkanya bisa
     dikira-kira sendiri; sekarang tiap baris punya jumlahnya masing-masing,
     dan tanpa total ini tidak ada tempat untuk memeriksa "sebenarnya berapa
     banyak yang akan saya kirim" sebelum menekan Tambahkan. */
  var ring = document.getElementById('ringkasPilih');
  if (!ring) { return; }
  if (!dipilih.length) {
    ring.textContent = 'Belum ada yang dicentang';
    return;
  }
  var unit = 0, adaBatch = 0;
  Array.prototype.forEach.call(dipilih, function (c) {
    var baris = c.closest('.baris-centang');
    unit += parseInt(baris.querySelector('.qty-pilih').value, 10) || 0;
    if (baris.querySelector('.batch-pilih').value) { adaBatch++; }
  });
  ring.textContent = dipilih.length + ' barang · ' + unit + ' unit' +
    (adaBatch ? ' · ' + adaBatch + ' batch dipilih sendiri' : '');
}

function terapkanPilihBanyak() {
  var dipilih = document.querySelectorAll('#daftarPilihBanyak input[type=checkbox]:checked');
  if (!dipilih.length) { return; }

  bersihkanBarisKosong();
  dipilih.forEach(function (c) {
    var baris = c.closest('.baris-centang');
    var inQty = baris.querySelector('.qty-pilih');
    var selBt = baris.querySelector('.batch-pilih');
    var opsi  = selBt.options[selBt.selectedIndex];

    /* Jumlah dibatasi stok yang benar-benar ada — batas batch bila satu
       batch dipilih, batas total bila otomatis. Server akan menolaknya
       juga, tetapi menolak SETELAH orang mengisi tiga puluh baris berarti
       ia harus mencari sendiri baris mana yang melebihi. */
    var maks = parseInt(inQty.getAttribute('max'), 10) || 0;
    var q    = parseInt(inQty.value, 10) || 1;
    if (q < 1) { q = 1; }
    if (maks > 0 && q > maks) { q = maks; }

    tambahBarisAtauTambahJumlah(parseInt(c.value, 10), q, {
      nomor_batch:     selBt.value || '',
      tanggal_expired: (opsi && opsi.getAttribute('data-exp')) || ''
    });

    // Dikembalikan ke keadaan awal supaya panel siap dipakai lagi.
    c.checked = false;
    baris.querySelector('.atur-kirim').hidden = true;
    inQty.value = 1;
    delete inQty.dataset.disunting;
    selBt.selectedIndex = 0;
  });
  hitungTerpilih();
  sesuaikanKolomBatch();
  tutupPanelTambah();
}

/* Tombol rentang cepat. Didelegasikan supaya tetap bekerja meski
   panelnya digambar ulang. */
document.addEventListener('click', function (ev) {
  var b = ev.target.closest ? ev.target.closest('.baris-cepat-tgl [data-rentang]') : null;
  if (b) { rentangCepat(b.getAttribute('data-rentang')); }
});


/* ------------------------------------------------ panel: tempel kode */

function bukaTempelKode() {
  tutupPanelTambah();
  document.getElementById('panelTempelKode').hidden = false;
  document.getElementById('isiTempelKode').focus();
}

/**
 * Urai satu baris tempelan menjadi {kode, jumlah}.
 *
 * Pemisahnya spasi, koma, titik koma, atau tab — keempatnya, karena
 * sumber tempelannya berbeda-beda: Excel memberi tab, WhatsApp memberi
 * spasi, dan catatan yang diketik ulang memberi koma. Menuntut satu
 * bentuk berarti separuh tempelan gagal tanpa sebab yang jelas.
 *
 * Jumlah yang tidak ada berarti 1.
 */
function uraiBarisKode(baris) {
  var bagian = baris.trim().split(/[\s,;\t]+/).filter(function (x) { return x !== ''; });
  if (!bagian.length) { return null; }
  var kode = bagian[0];
  var jml  = bagian.length > 1 ? parseInt(bagian[bagian.length - 1], 10) : 1;
  if (!jml || jml < 1) { jml = 1; }
  return { kode: kode, jumlah: jml };
}

function terapkanTempelKode() {
  var teks  = document.getElementById('isiTempelKode').value;
  var hasil = document.getElementById('hasilTempel');
  var baris = teks.split(/\r?\n/);

  var ketemu = 0, gagal = [];
  bersihkanBarisKosong();

  baris.forEach(function (b) {
    var u = uraiBarisKode(b);
    if (!u) { return; }
    /* Pencocokan kode TIDAK memandang besar-kecil huruf dan mengabaikan
       spasi di tepi — kode yang disalin dari Excel sering membawa spasi
       ikutan yang tidak terlihat siapa pun. */
    var cari = u.kode.toUpperCase();
    var b2 = BARANG.find(function (x) { return String(x.k).toUpperCase() === cari; });
    if (b2) { tambahBarisAtauTambahJumlah(b2.id, u.jumlah); ketemu++; }
    else    { gagal.push(u.kode); }
  });

  /* Yang tidak ketemu DISEBUTKAN, tidak dibuang diam-diam. Daftar yang
     ditempel biasanya memuat kode yang salah ketik atau barang yang
     belum terdaftar, dan orang harus tahu mana yang tidak masuk —
     bukan menghitung ulang barisnya sendiri. */
  if (gagal.length) {
    hasil.innerHTML = '<b>' + ketemu + '</b> barang masuk daftar. ' +
      '<span style="color:var(--amber)">' + gagal.length + ' kode tidak dikenali: ' +
      gagal.slice(0, 8).map(function (k) { return k.replace(/[<>&]/g, ''); }).join(', ') +
      (gagal.length > 8 ? ', …' : '') + '</span>';
  } else if (ketemu) {
    hasil.innerHTML = '<b>' + ketemu + '</b> barang masuk daftar. Semua kode dikenali.';
    document.getElementById('isiTempelKode').value = '';
  } else {
    hasil.textContent = 'Tidak ada kode yang bisa dibaca dari tempelan itu.';
  }
}



/* =====================================================================
   DRAF OTOMATIS
   ---------------------------------------------------------------------
   Mesinnya pindah ke assets/draf-formulir.js dan kini dipakai bertiga:
   formulir ini, Barang Masuk gudang, dan Barang Masuk mitra. Yang
   tertinggal di sini hanya yang memang khusus formulir Perpindahan —
   kunci per JENIS dokumen, dan urutan penyegaran dropdown saat memulihkan.

   Alasan memindahkannya: disalin tiga kali, ketiganya akan berselisih
   pada perbaikan pertama yang hanya disentuh di salah satunya — dan yang
   berselisih diam-diam pada fitur penyelamat pekerjaan adalah yang paling
   mahal, karena kegagalannya baru ketahuan saat pekerjaan sudah hilang.
   ===================================================================== */
(function () {
  'use strict';
  if (typeof pasangDrafFormulir !== 'function') { return; }
  if (!document.getElementById('frm')) { return; }

  /** Label jenis yang terbaca manusia, diambil dari pilihan yang ada. */
  function labelJenis(nilai) {
    if (!nilai) { return ''; }
    var o = document.querySelector('#jenis option[value="' + nilai + '"]');
    /* Emoji di depan label dibuang — di dalam kalimat ia jadi hiasan yang
       memutus bacaan, bukan penanda seperti di daftar pilihan. */
    var t = o ? o.textContent.trim().replace(/^[^A-Za-z]+/, '') : nilai;
    return t.split('—')[0].trim();
  }

  var draf = pasangDrafFormulir({
    form:   'frm',
    tbody:  'tbodyItem',
    awalan: 'amanah_draf_pp_',

    /* Kunci dibedakan per JENIS DOKUMEN, bukan satu kunci untuk semua.
       Petugas yang meninggalkan draf penerimaan setengah jadi lalu membuat
       mutasi rak tidak boleh mendapati formulirnya terisi barang dari
       pekerjaan yang lain. */
    varian: function () {
      return (document.getElementById('jenis') || {}).value || '';
    },

    /* Prefill dari kiriman supplier menang atas draf: ia datang dari
       tautan yang baru saja diklik, jadi itulah niat terbarunya. */
    lewati: (typeof PREFILL !== 'undefined' && PREFILL.length > 0),

    /* Jenis dokumennya disebut di tawaran. Draf bisa berasal dari
       pekerjaan lain yang ditinggalkan, dan "12 baris barang" tanpa
       keterangan apa pun tidak cukup untuk memutuskan pulihkan atau
       buang. */
    ringkas: function (d) { return labelJenis((d.isi || {}).jenis); },

    /* Jenis & rute disegarkan LEBIH DULU, baru asal/tujuan diisi ulang.
       Urutan itu penting: segarkanJenis() mengisi ulang dropdown asal &
       tujuan dari nol, jadi nilai yang dipulihkan sebelum ia berjalan
       akan terhapus olehnya. */
    sebelumPulih: function (d, frm) {
      if (typeof segarkanJenis === 'function') { segarkanJenis(); }
      ['asal_tipe', 'tujuan_tipe'].forEach(function (n) {
        var el = frm.querySelector('[name="' + n + '"]');
        if (el && d.isi[n]) { el.value = d.isi[n]; }
      });
      if (typeof segarkanAsal === 'function')   { segarkanAsal(); }
      if (typeof segarkanTujuan === 'function') { segarkanTujuan(); }
      ['asal_id', 'tujuan_id'].forEach(function (n) {
        var el = frm.querySelector('[name="' + n + '"]');
        if (el && d.isi[n]) { el.value = d.isi[n]; }
      });
      /* Kartu kardus belum ada saat isian dipulihkan — dibangun dari kunci
         kardus_nama[n] yang tersimpan, lalu nilainya diisi ulang. */
      var kepala = {};
      Object.keys(d.isi || {}).forEach(function (n) {
        var m = n.match(/^kardus_(nama|berat|p|l|t|catatan|ada)\[(\d+)\]$/);
        if (!m) { return; }
        var peta = {nama: 'nama', berat: 'berat_kg', p: 'panjang_cm', l: 'lebar_cm', t: 'tinggi_cm', catatan: 'catatan', ada: 'ada'};
        (kepala[m[2]] = kepala[m[2]] || {})[peta[m[1]]] = d.isi[n];
      });
      if (typeof pulihkanKardusAwal === 'function' && Object.keys(kepala).length) { pulihkanKardusAwal(kepala); }
    },
    sesudahPulih: function () {
      if (typeof kunciBarisKardusSiap === 'function') { kunciBarisKardusSiap(); }
      if (typeof segarkanOpsiKardus === 'function') { segarkanOpsiKardus(); segarkanRingkasanKardus(); segarkanKardusSiap(); segarkanStokBaris(); }
    },

    tambahBaris: function (b) {
      if (typeof tambahBaris === 'function') { tambahBaris(b); }
    },
  });

  /* Dibuka untuk jalur yang mengubah nilai baris yang SUDAH ada — itu
     tidak mengubah struktur tabel, jadi pengamat di dalam mesin tidak
     melihatnya. Nama ini sudah dipanggil dari beberapa tempat. */
  if (draf) { window.drafSegarkan = draf.segarkan; }
})();

</script>
