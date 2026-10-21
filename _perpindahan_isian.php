<?php
/**
 * AMANAH — Markup formulir Perpindahan Barang (partial)
 *
 * Dipakai popup di perpindahan.php DAN halaman penuh perpindahan_form.php.
 * Satu berkas supaya kolom yang ditambahkan nanti muncul di kedua layar.
 *
 * Yang harus sudah terisi sebelum berkas ini disertakan:
 *   $aksiUrl  tujuan action pada <form>
 *   $ppAksi   nilai <input name="act">
 *   plus seluruh variabel dari _perpindahan_form.php
 */
?>
<form method="post" id="frm" action="<?= e($aksiUrl) ?>">
<input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
<?php /* Penanda aksi. Halaman daftar menampung popup ini DAN penyaringnya
         sendiri, jadi penangan POST perlu tahu kiriman ini miliknya. */ ?>
<input type="hidden" name="act" value="<?= e($ppAksi) ?>">

<?php /* ---------------------------------------------------------------
         TAWARAN PULIHKAN DRAF

         DITAWARKAN, bukan dipulihkan sendiri. Memulihkan otomatis terasa
         pintar sampai salah: orang membuka formulir untuk pekerjaan baru,
         menemukannya sudah terisi barang dari kemarin, lalu mengirimkannya
         tanpa sadar. Tawaran membuat keputusannya milik orang.

         Disembunyikan lewat atribut `hidden`, bukan kelas — JavaScript
         yang gagal dimuat menyisakan kotak yang tetap tersembunyi, bukan
         kotak tawaran yang tombolnya tidak melakukan apa-apa.
         --------------------------------------------------------------- */ ?>
<div class="card mb16 tawaran-draf" id="tawaranDraf" hidden>
  <div>
    <b>Ada draf yang belum terkirim</b>
    <div class="sub" id="drafKet"></div>
  </div>
  <div class="baris">
    <button type="button" class="btn btn-primary btn-sm" id="drafPulihkan">
      <svg aria-hidden="true"><use href="#i-putar"></use></svg> Pulihkan</button>
    <button type="button" class="btn btn-ghost btn-sm" id="drafBuang">Buang</button>
  </div>
</div>

<div class="card mb16">
  <h3>1. Jenis & Rute Perpindahan</h3>
  <div class="sub">Tentukan barang berpindah dari mana ke mana</div>

  <div class="field">
    <label>Jenis Perpindahan <span class="req">*</span></label>
    <?php /* $jenisAwal disiapkan _perpindahan_form.php dari ?jenis= atau dari
             kiriman supplier, supaya jenis yang sudah dipilih lewat menu tidak
             perlu dipilih ulang di sini. */
          $jenisAwal = $jenisAwal ?? ($prefill ? 'penerimaan' : '');
          $opsiJenis = [
              'penerimaan'      => '⬇️ Penerimaan — barang masuk dari supplier',
              'kirim_toko'      => '🏪 Kirim ke Toko — gudang ke toko',
              'retur_toko'      => '↩️ Retur dari Toko — toko kembali ke gudang',
              'antar_toko'      => '🔁 Antar Toko — pindah antar toko',
              'transfer_gudang' => '🔄 Transfer Gudang — antar gudang',
              'mutasi_rak'      => '↔️ Mutasi Rak — pindah rak dalam gudang',
              'penyaluran'      => '⬆️ Penyaluran — keluar ke penerima manfaat',
              'retur'           => '↩️ Retur Supplier — dikembalikan ke supplier',
          ]; ?>
    <?php /* Hanya jenis yang peran ini memang boleh buat. Menawarkan yang
             akan ditolak server berarti membiarkan orang mengisi seluruh
             keranjang lebih dulu, baru ditolak. */
          $bolehJenis = jenis_boleh_peran(); ?>
    <select name="jenis" id="jenis" required>
      <option value="">— pilih jenis —</option>
      <?php foreach ($opsiJenis as $nilai => $label):
              if (!in_array($nilai, $bolehJenis, true)) { continue; } ?>
        <option value="<?= e($nilai) ?>" <?= $jenisAwal === $nilai ? 'selected' : '' ?>><?= $label ?></option>
      <?php endforeach; ?>
    </select>
  </div>

  <div class="form-grid">
    <!-- ASAL -->
    <div>
      <div class="field">
        <label>Dari (Asal) <span class="req">*</span></label>
        <select name="asal_tipe" id="asal_tipe" required>
          <option value="supplier">Supplier</option>
          <option value="gudang">Gudang</option>
          <option value="rak">Rak tertentu</option>
          <option value="toko">Toko</option>
          <option value="eksternal">Pihak lain (ketik manual)</option>
        </select>
      </div>
      <div class="field" id="wrap_asal_id">
        <label>Pilih Asal <span class="req">*</span></label>
        <select name="asal_id" id="asal_id"></select>
      </div>
      <div class="field" id="wrap_asal_teks" class="hide">
        <label>Nama Asal <span class="req">*</span></label>
        <input name="asal_teks" id="asal_teks" placeholder="cth: Donatur perorangan / Dinas Sosial">
      </div>
    </div>

    <!-- TUJUAN -->
    <div>
      <div class="field">
        <label>Ke (Tujuan) <span class="req">*</span></label>
        <select name="tujuan_tipe" id="tujuan_tipe" required>
          <option value="rak">Rak tertentu</option>
          <option value="gudang">Gudang</option>
          <option value="toko">Toko</option>
          <option value="penerima">Penerima manfaat (ketik manual)</option>
          <option value="supplier">Supplier (retur)</option>
        </select>
      </div>
      <div class="field" id="wrap_tujuan_id">
        <label>Pilih Tujuan <span class="req">*</span></label>
        <select name="tujuan_id" id="tujuan_id"></select>
      </div>
      <div class="field" id="wrap_tujuan_teks" class="hide">
        <label>Nama Penerima / Tujuan <span class="req">*</span></label>
        <input name="tujuan_teks" id="tujuan_teks" placeholder="cth: Posyandu Melati, Bekasi">
      </div>
    </div>
  </div>
  <p style="color:var(--muted);font-size:12px;margin-top:-4px" id="hintRute"></p>

  <?php /* -------------------------------------------------------------
           TITIK TRANSIT — gudang yang disinggahi sebelum tujuan akhir.
           Hanya untuk dokumen yang barangnya menempuh perjalanan
           (transfer, kirim toko, penyaluran); JS menyembunyikannya untuk
           jenis lain. Stok tidak singgah di gudang transit — lihat _lacak.php.
           ------------------------------------------------------------- */
        $gudangTransit = gudang_pilihan_transit($pdo);
        $transitIsi = array_values(array_filter(array_map('intval', (array) ($_POST['transit_id'] ?? [])))); ?>
  <div class="field" id="wrapTransit" style="margin-top:12px" hidden>
    <label>Singgah di gudang transit <span class="mut2">(opsional, urut)</span></label>
    <div class="form-grid" style="gap:8px 12px">
      <?php for ($i = 0; $i < MAKS_TITIK_TRANSIT; $i++): ?>
        <select name="transit_id[]" aria-label="Transit <?= $i + 1 ?>">
          <option value="">— transit <?= $i + 1 ?>: tidak ada —</option>
          <?php foreach ($gudangTransit as $g): ?>
            <option value="<?= (int) $g['id'] ?>" <?= ($transitIsi[$i] ?? 0) === (int) $g['id'] ? 'selected' : '' ?>>
              <?= e($g['nama']) ?> (<?= e($g['kode']) ?>)</option>
          <?php endforeach; ?>
        </select>
      <?php endfor; ?>
    </div>
    <small style="color:var(--muted);font-size:11.5px">
      Di tiap gudang transit, petugasnya menandai <b>tiba</b> dan <b>berangkat</b> dari menu
      Lacak Kiriman. Stok tetap milik pengirim sampai diterima di tujuan akhir.
    </small>
  </div>
  <script>
  /* Kotak transit hanya untuk jenis yang menempuh perjalanan. Dipasang di
     sini, bukan di _perpindahan_js.php, supaya daftar jenisnya satu sumber
     dengan jenis_boleh_transit() di PHP. */
  (function(){
    var boleh = <?= json_encode(array_values(array_filter(array_keys(rute_sah()), 'jenis_boleh_transit'))) ?>;
    var sel = document.getElementById('jenis'), kotak = document.getElementById('wrapTransit');
    function segarkanTransit(){ kotak.hidden = boleh.indexOf(sel.value) === -1; }
    sel.addEventListener('change', segarkanTransit); segarkanTransit();
  })();
  </script>

  <?php /* -------------------------------------------------------------
           TANGGAL DOKUMEN

           Dulu tidak ada isian ini sama sekali — tanggalnya dipatok
           waktu penyimpanan. Padahal barang sering baru dicatat malam
           atau besok paginya, sedangkan surat jalannya bertanggal hari
           kejadian, dan laporan bulanan jadi tidak pernah cocok dengan
           buku fisik.

           Tiga tombol cepat karena tiga itulah yang nyata dipakai:
           hari ini (paling sering), kemarin (pencatatan menyusul), dan
           tanggal bebas (menyusun tunggakan pencatatan).
           ------------------------------------------------------------- */
  $tglHariIni = date('Y-m-d');
  $tglKemarin = date('Y-m-d', strtotime('-1 day'));
  $tglTerpilih = $prefill['tanggal'] ?? ($_POST['tanggal'] ?? $tglHariIni);
  ?>
  <div class="field">
    <label>Tanggal Dokumen <span class="req">*</span></label>
    <div class="pilih-tanggal">
      <button type="button" class="btn btn-ghost btn-sm" data-tgl="<?= $tglHariIni ?>">
        Hari ini</button>
      <button type="button" class="btn btn-ghost btn-sm" data-tgl="<?= $tglKemarin ?>">
        Kemarin</button>
      <?php /* TANPA min/max. Batas tanggal dicabut atas permintaan pemilik
               sistem — surat jalan boleh bertanggal kapan saja. Atribut
               min/max di sini akan membuat peramban menolak nilai yang
               justru diterima server, dan itu jenis penolakan yang tidak
               menjelaskan apa pun. */ ?>
      <input type="date" name="tanggal" id="tanggalDok"
             value="<?= e($tglTerpilih) ?>">
    </div>
    <small style="color:var(--muted);font-size:11.5px">
      Tanggal yang tercetak di surat jalan — <b>bebas, kapan saja</b>, termasuk
      tanggal lampau maupun mendatang. Tanggal yang bukan hari ini tercatat di
      riwayat aktivitas beserta tanggal pembuatannya.
    </small>
  </div>
</div>

<div class="card mb16">
  <h3>2. Rincian Barang</h3>
  <div class="sub">Boleh lebih dari satu jenis barang dalam satu surat jalan</div>

  <?php /* Penanda "draf tersimpan". Kecil dan tidak mengganggu, tapi
           penting: tanpa isyarat apa pun, orang tidak punya alasan
           mempercayai bahwa isiannya akan selamat — dan tetap takut
           menekan Kembali. */ ?>
  <p class="petunjuk penanda-draf" id="penandaDraf" hidden>
    <svg aria-hidden="true"><use href="#i-cek"></use></svg>
    Draf tersimpan di perangkat ini — aman kalau halaman tertutup atau Anda menekan Kembali.
  </p>

  <?php /* -------------------------------------------------------------
           KARDUS DI DALAM PERPINDAHAN (revisi 12 Okt 2026)

           Sebelum menambahkan barang, petugas memilih: masuk kardus atau
           tanpa kardus. Pilihan berlaku untuk barang yang sedang ditambahkan
           — satu dokumen boleh berisi beberapa kardus sekaligus barang tanpa
           kardus. Kardus dibuat di sini (kode KRD-… diberikan server saat
           dokumen disimpan); isinya adalah baris tabel barang di bawah yang
           kolom Kardus-nya menunjuk kardus itu, jadi barang bisa dipindah
           antar kardus / keluar kardus tanpa kehilangan data. Hanya untuk
           jenis yang membawa barang keluar gudang (jenis_boleh_kardus). */ ?>
  <div id="kotakCaraKirim" class="panel-tambah" style="margin-bottom:12px" hidden>
    <div style="display:flex;gap:14px;flex-wrap:wrap;align-items:center">
      <b>Bagaimana barang ini dikirim?</b>
      <label class="baris" style="font-weight:500;color:var(--text)"><input type="radio" name="cara_kirim" value="kardus" onchange="pilihCaraKirim('kardus')"> Masuk kardus</label>
      <label class="baris" style="font-weight:500;color:var(--text)"><input type="radio" name="cara_kirim" value="tanpa" onchange="pilihCaraKirim('tanpa')" checked> Tanpa kardus</label>
      <span class="petunjuk" id="ketKardusAktif" style="margin-left:auto"></span>
    </div>
      <div id="blokKardusSiap" style="display:none;margin-top:10px">
        <label style="font-size:12.5px;font-weight:600">Kardus siap kirim di lokasi asal <small class="mut2" style="font-weight:400">(dikemas lewat Pindah Antar Rak — tinggal dicentang)</small></label>
        <div id="daftarKardusSiap" style="display:grid;grid-template-columns:repeat(auto-fill,minmax(260px,1fr));gap:8px;margin-top:6px"></div>
      </div>
    <div id="wrapPilihKardus" style="display:none;margin-top:10px">
      <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center">
        <label style="font-size:12.5px">Pilih kardus:</label>
        <select id="pilihKardusAktif" onchange="setKardusAktif(parseInt(this.value,10)||0)" style="min-width:180px"></select>
        <button type="button" class="btn btn-ghost btn-sm" onclick="buatKardusBaru()"><svg aria-hidden="true"><use href="#i-plus"></use></svg> Buat kardus baru</button>
      </div>
      <div id="daftarKardusBaru" style="display:grid;grid-template-columns:repeat(auto-fill,minmax(280px,1fr));gap:10px;margin-top:10px"></div>
      <p class="petunjuk" style="margin:8px 0 0">Barang yang ditambahkan (scan, pilih banyak, tempel kode, tambah baris) masuk ke kardus yang sedang dipilih. Kolom <b>Kardus</b> di tabel bisa diubah per baris.</p>
    </div>
  </div>

  <div data-scan data-judul="Scan atau ketik kode barang" id="scanPP"></div>

  <?php /* -------------------------------------------------------------
           EMPAT CARA MENAMBAHKAN BARANG

           Sebelumnya hanya dua: tembak barcode satu per satu, atau
           tambah baris kosong lalu cari barangnya di dropdown panjang.
           Keduanya masuk akal untuk satu-dua barang, dan menyiksa untuk
           tiga puluh — dan tiga puluh jenis dalam satu surat jalan
           adalah hal biasa saat mengirim ke toko.

           Yang ditambahkan bukan sekadar tombol lain, melainkan dua cara
           yang bentuk pekerjaannya berbeda:

             Pilih Banyak  daftar berpencarian dengan kotak centang —
                           untuk yang tahu barangnya tapi tidak memegang
                           barcode-nya
             Tempel Kode   tempel daftar kode dari WhatsApp, Excel, atau
                           catatan tangan yang diketik ulang. Ini yang
                           paling sering: daftar kirimannya SUDAH ada
                           dalam bentuk teks, dan mengetiknya ulang satu
                           per satu ke dropdown adalah pekerjaan sia-sia.
           ------------------------------------------------------------- */ ?>
  <div class="baris-cara-tambah">
    <button type="button" class="btn btn-ghost btn-sm" onclick="bukaPilihBanyak()">
      <svg aria-hidden="true"><use href="#i-layers"></use></svg> Pilih Banyak Barang</button>
    <button type="button" class="btn btn-ghost btn-sm" onclick="bukaTempelKode()">
      <svg aria-hidden="true"><use href="#i-doc"></use></svg> Tempel Daftar Kode</button>
  </div>

  <!-- Panel: pilih banyak lewat centang -->
  <div id="panelPilihBanyak" class="panel-tambah" hidden>
    <div class="panel-tambah-kepala">
      <b>Pilih Banyak Barang</b>
      <button type="button" class="btn btn-ghost btn-sm" onclick="tutupPanelTambah()">Tutup</button>
    </div>
    <?php /* -------------------------------------------------------------
             PENYARING: DARI MANA, DAN MASUK KAPAN

             Panel ini semula hanya daftar seluruh katalog. Untuk menyusun
             surat jalan, dua pertanyaan itulah yang sebenarnya ditanyakan
             orang: "barang apa saja yang ada di gudang ini", dan "apa saja
             yang baru masuk hari ini / kemarin / rentang tertentu".

             Menyaringnya di SERVER, bukan di peramban, karena penyaring
             tanggal masuk butuh data yang tidak ada di halaman: tanggal
             tiba tiap batch. Memuat semuanya di awal berarti mengirim
             seluruh isi gudang setiap kali formulir dibuka.
             ------------------------------------------------------------- */ ?>
    <div class="saring-stok">
      <div class="field">
        <label>Sumber</label>
        <select id="sumberPilih" onchange="muatStokPanel()">
          <option value="semua">— semua lokasi dalam lingkup Anda —</option>
          <?php if ($gudangList): ?>
            <optgroup label="Gudang">
              <?php foreach ($gudangList as $g): ?>
                <option value="gudang:<?= (int) $g['id'] ?>"><?= e($g['nama']) ?></option>
              <?php endforeach; ?>
            </optgroup>
          <?php endif; ?>
          <?php /* Per RAK (butir 5 revisi tim gudang): "Area Siap Kirim", rak
                   gudang lain, dst. Daftarnya dari master rak dalam lingkup
                   akun, dikelompokkan per gudang — bukan tiga nama tetap. */ ?>
          <?php if (!empty($rakList)): $gKini = null; foreach ($rakList as $r):
                    $gNama = $r['nama_gudang'] ?: '—';
                    if ($gNama !== $gKini) { if ($gKini !== null) echo '</optgroup>'; echo '<optgroup label="Rak — ' . e($gNama) . '">'; $gKini = $gNama; } ?>
                <option value="rak:<?= (int) $r['id'] ?>"><?= e($r['nama']) ?> (<?= e($r['kode']) ?>)</option>
          <?php endforeach; if ($gKini !== null) echo '</optgroup>'; endif; ?>
          <?php if ($tokoList): ?>
            <optgroup label="Toko">
              <?php foreach ($tokoList as $t): ?>
                <option value="toko:<?= (int) $t['id'] ?>"><?= e($t['nama']) ?></option>
              <?php endforeach; ?>
            </optgroup>
          <?php endif; ?>
          <?php if ($supplierList): ?>
            <optgroup label="Supplier / Mitra">
              <?php foreach ($supplierList as $s): ?>
                <option value="supplier:<?= (int) $s['id'] ?>"><?= e($s['nama']) ?></option>
              <?php endforeach; ?>
            </optgroup>
          <?php endif; ?>
        </select>
      </div>

      <div class="field">
        <label>Kondisi</label>
        <label class="baris" style="font-weight:500;color:var(--text)">
          <input type="checkbox" id="hanyaLayak" checked onchange="muatStokPanel()">
          Hanya stok siap salur
        </label>
        <small class="petunjuk" id="ketHanyaLayak">Rusak/Bocor, Expired, dan tidak layak disembunyikan.</small>
      </div>
      <div class="field">
        <label>Jumlah</label>
        <label class="baris" style="font-weight:500;color:var(--text)">
          <input type="checkbox" id="jumlahOtomatis" onchange="terapkanJumlahOtomatis()">
          Otomatis = seluruh stok tersedia
        </label>
        <small class="petunjuk">Barang yang dicentang langsung diisi jumlah seluruh stoknya (dikurangi yang sudah dipesan surat jalan lain).</small>
      </div>
      <div class="field">
        <label>Barang masuk</label>
        <div class="baris-cepat-tgl">
          <button type="button" class="btn btn-ghost btn-sm" data-rentang="semua">Semua</button>
          <button type="button" class="btn btn-ghost btn-sm" data-rentang="hari-ini">Hari ini</button>
          <button type="button" class="btn btn-ghost btn-sm" data-rentang="kemarin">Kemarin</button>
          <button type="button" class="btn btn-ghost btn-sm" data-rentang="7hari">7 hari</button>
        </div>
      </div>

      <div class="field">
        <label>Rentang tanggal</label>
        <div class="baris-rentang">
          <input type="date" id="stokDari" onchange="muatStokPanel()">
          <span>s/d</span>
          <input type="date" id="stokKe" onchange="muatStokPanel()">
        </div>
      </div>
    </div>

    <input type="text" id="cariPilihBanyak" placeholder="Cari nama atau kode barang…"
           autocomplete="off" oninput="saringPilihBanyak()">
    <?php /* -------------------------------------------------------------
             PILIH SEMUA

             Menyentang enam barang satu per satu wajar; tiga puluh tidak.
             Tombolnya berlaku hanya pada barang yang SEDANG TERLIHAT —
             yaitu hasil penyaring sumber, tanggal, dan pencarian di atas.
             Kalau ia menyentang yang tersembunyi juga, orang mengetik
             "susu", menekan Pilih semua, dan tanpa sadar ikut membawa
             seluruh isi gudang; kesalahan yang tidak terlihat di layar
             sampai keranjangnya sudah terkirim.

             Jumlah tiap barang TIDAK diseragamkan oleh tombol ini —
             masing-masing tetap punya kotaknya sendiri. Itu justru
             keluhan yang membuat isian "Jumlah tiap barang" dihapus.
             ------------------------------------------------------------- */ ?>
    <div class="baris-pilih-semua">
      <button type="button" class="btn btn-ghost btn-sm" id="tombolPilihSemua"
              onclick="pilihSemuaStok()">Pilih semua</button>
      <span class="petunjuk" id="ringkasStok"></span>
    </div>
    <div id="daftarPilihBanyak" class="daftar-centang"></div>
    <?php /* -------------------------------------------------------------
             JUMLAH & BATCH ADA DI TIAP BARIS, BUKAN DI KAKI PANEL

             Sebelumnya kaki panel ini berisi satu isian "Jumlah tiap
             barang" yang berlaku untuk semua yang dicentang. Itu hampir
             selalu salah: sepuluh barang jarang dikirim sepuluh-sepuluh,
             dan nilainya yang bawaan 1 membuat seluruh keranjang terisi
             satu unit — lalu harus disunting satu per satu di tabel di
             bawah. Isian yang menghemat satu ketikan menciptakan sepuluh.

             Batch juga per baris, sebab pilihannya memang per barang:
             batch berumur panjang untuk toko yang jauh, batch yang
             mendekati kedaluwarsa dihabiskan lebih dulu.
             ------------------------------------------------------------- */ ?>
    <div class="panel-tambah-kaki">
      <span class="petunjuk" id="ringkasPilih">Belum ada yang dicentang</span>
      <button type="button" class="btn btn-primary btn-sm" onclick="terapkanPilihBanyak()">
        <svg aria-hidden="true"><use href="#i-plus"></use></svg>
        Tambahkan <span id="jmlTerpilih">0</span> barang</button>
    </div>
  </div>

  <!-- Panel: tempel daftar kode -->
  <div id="panelTempelKode" class="panel-tambah" hidden>
    <div class="panel-tambah-kepala">
      <b>Tempel Daftar Kode</b>
      <button type="button" class="btn btn-ghost btn-sm" onclick="tutupPanelTambah()">Tutup</button>
    </div>
    <p class="petunjuk" style="margin:0 0 8px">
      Satu baris satu barang. Boleh <b>kode saja</b>, atau <b>kode dan jumlah</b>
      dipisah spasi, koma, titik koma, atau tab — jadi hasil salin dari Excel
      dan WhatsApp keduanya bisa ditempel apa adanya.
    </p>
    <textarea id="isiTempelKode" rows="7" placeholder="BBY-0001 12
BBY-0002, 5
BBY-0003"></textarea>
    <div class="panel-tambah-kaki">
      <span class="petunjuk" id="hasilTempel"></span>
      <button type="button" class="btn btn-primary btn-sm" onclick="terapkanTempelKode()">
        <svg aria-hidden="true"><use href="#i-plus"></use></svg> Tambahkan ke Daftar</button>
    </div>
  </div>

  <div class="scrollx">
    <table id="tblItem" class="tbl-kartu">
      <thead><tr>
        <th style="min-width:220px">Barang <span class="req">*</span></th>
        <th style="width:110px">Jumlah <span class="req">*</span></th>
        <th style="width:150px" class="col-batch">No. Batch</th>
        <th style="width:150px" class="col-exp">Expired</th>
        <th style="width:130px" class="col-harga">Harga/unit</th>
        <th style="min-width:140px">Keterangan</th>
        <th style="width:150px" class="col-kardus">Kardus</th>
        <th style="width:44px"></th>
      </tr></thead>
      <tbody id="tbodyItem"></tbody>
    </table>
  </div>
  <button type="button" class="btn btn-ghost btn-sm" style="margin-top:12px" onclick="tambahBaris()"><svg aria-hidden="true"><use href="#i-plus"></use></svg> Tambah Baris</button>
</div>

<?php /* -------------------------------------------------------------
         RINGKASAN PERPINDAHAN (revisi 12 Okt 2026) — digambar JS dari tabel
         barang: tiap kardus sebagai kelompok yang bisa dibuka/tutup, lalu
         barang tanpa kardus, lalu angka ringkas. Untuk memeriksa susunan
         kiriman sebelum disimpan. */ ?>
<div class="card mb16" id="kartuRingkasan">
  <h3>Ringkasan Perpindahan</h3>
  <div class="sub" id="ringkasAngka">Belum ada barang.</div>
  <div id="ringkasKelompok"></div>
</div>

<div class="card mb16">
  <h3>3. Data Pengiriman & Surat Jalan</h3>
  <div class="sub">Dicetak pada surat jalan — isi seperlunya</div>
  <div class="form-grid">
    <div class="field"><label>Nomor Resi / Tracking</label>
      <input name="no_resi" id="no_resi" value="<?= e($prefill['no_resi'] ?? '') ?>" placeholder="Scan atau ketik nomor resi">
      <small style="color:var(--muted);font-size:11.5px">Bisa juga discan di menu <a href="scan_resi.php" style="text-decoration:underline">Scan Resi</a></small>
    </div>
    <div class="field"><label>Ekspedisi</label>
      <input name="ekspedisi" value="<?= e($prefill['ekspedisi'] ?? '') ?>" placeholder="cth: JNE, J&T, kurir sendiri"></div>
    <div class="field"><label>Kendaraan</label>
      <input name="kendaraan" placeholder="cth: Mobil box"></div>
    <div class="field"><label>Nomor Polisi</label>
      <input name="no_polisi" placeholder="cth: B 1234 XYZ"></div>
    <div class="field"><label>Nama Pengirim / Petugas</label>
      <input name="pengirim" value="<?= e($u['nama']) ?>"></div>
    <div class="field"><label>Nama Penerima</label>
      <input name="penerima" placeholder="Yang menerima di tujuan"></div>
    <div class="field span-all"><label>Untuk Program / Keperluan</label>
      <input name="untuk_program" placeholder="cth: Paket Bayi Sehat Juli 2026"></div>
    <div class="field span-all"><label>Catatan</label>
      <textarea name="catatan" rows="2" placeholder="opsional"></textarea></div>
  </div>
</div>

<div class="card" style="display:flex;gap:10px;flex-wrap:wrap;align-items:center">
  <?php /* Label tombol utama mengikuti jenis yang sedang dipilih, dan
           diperbarui oleh JS saat dropdown jenisnya diubah.

           Untuk mutasi rak, "Kirim untuk Persetujuan" bukan sekadar kurang
           tepat — ia berbohong: dokumennya justru sah seketika dan stoknya
           langsung berpindah. Tombol yang menjanjikan hal berbeda dari yang
           dilakukannya adalah cara tercepat membuat orang berhenti percaya
           pada layarnya sendiri. */ ?>
  <button class="btn btn-primary" type="submit" name="kirim" value="1" id="tblKirim">
    <svg aria-hidden="true"><use href="#i-kirim"></use></svg>
    <span id="tblKirimTeks"><?= langsung_sah($jenisAwal) ? 'Pindahkan Sekarang' : 'Kirim untuk Persetujuan' ?></span></button>
  <button class="btn btn-ghost" type="submit" name="simpan_draft" value="1"><svg aria-hidden="true"><use href="#i-simpan"></use></svg> Simpan sebagai Draft</button>
  <small class="mut" id="tblKirimKet" style="font-size:12px">
    <?= langsung_sah($jenisAwal)
        ? 'Barang berpindah rak seketika — tidak perlu persetujuan.'
        : 'Stok berubah setelah dokumen ini disetujui.' ?></small>
  <?php /* data-tutup-modal membuat tombol ini menutup popup di tempat saat
           dipakai di perpindahan.php; di halaman penuh ia tetap tautan biasa
           ke daftar. Satu markup, dua perilaku yang keduanya benar. */ ?>
  <a class="btn btn-ghost" href="perpindahan.php" data-tutup-modal>Batal</a>
  <span style="color:var(--muted);font-size:12px;margin-left:auto">
    Semua perpindahan wajib disetujui sebelum stok berubah dan surat jalan bisa dicetak.
  </span>
</div>
</form>

