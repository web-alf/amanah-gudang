<?php
/**
 * AMANAH — Isian formulir Barang (partial)
 *
 * Dipakai popup di barang.php DAN halaman penuh barang_form.php. Satu berkas
 * supaya isian yang ditambahkan nanti otomatis ada di kedua layar; dua salinan
 * markup pasti berbeda isinya dalam beberapa bulan.
 *
 * Yang harus sudah terisi sebelum berkas ini disertakan:
 *   $b        baris barang (lihat barang_kosong() di _barang.php)
 *   $pilihan  daftar dropdown (lihat barang_pilihan())
 *   $isEdit   true bila sedang menyunting
 *   $aksiUrl  tujuan action pada <form>
 *   $aksiNama (opsional) nilai untuk <input name="act"> — dipakai barang.php,
 *             yang menangani beberapa jenis kiriman di satu halaman
 */
?>
<?php /* enctype WAJIB: tanpa itu peramban mengirim nama berkasnya saja,
         bukan isinya, dan $_FILES datang kosong tanpa satu pun galat. */ ?>
<form method="post" action="<?= e($aksiUrl) ?>" enctype="multipart/form-data">
  <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
  <input type="hidden" name="id" value="<?= (int) ($b['id'] ?? 0) ?>">
  <?php if (!empty($aksiNama)): ?>
    <input type="hidden" name="act" value="<?= e($aksiNama) ?>">
  <?php endif; ?>
  <div class="form-grid">
    <div class="field"><label>Kode Barang <span class="req">*</span></label>
      <input name="kode" value="<?= e($b['kode']) ?>" required></div>

    <?php /* Barcode boleh diisi tiga cara: tembak pakai kamera HP, tembak
             pakai scanner USB (arahkan saja ke kolomnya), atau ketik. Kolom
             ini paling sering diisi sambil memegang barangnya, jadi mengetik
             ulang 13 angka EAN adalah pekerjaan yang paling mudah salah di
             seluruh formulir ini. */ ?>
    <div class="field"><label>Barcode (EAN/UPC)</label>
      <div class="isian-scan">
        <input name="barcode" id="isianBarcode" value="<?= e($b['barcode']) ?>"
               inputmode="numeric" autocomplete="off"
               placeholder="tembak barcode-nya, atau ketik">
        <button type="button" class="btn btn-ghost" data-scan-ke="isianBarcode"
                aria-pressed="false" title="Buka kamera untuk membaca barcode">
          <svg aria-hidden="true"><use href="#i-scan"></use></svg> Scan
        </button>
      </div>
      <small class="petunjuk">Boleh dikosongkan. Scanner USB juga bisa langsung
        ditembakkan ke kolom ini.</small></div>

    <div class="field span-all"><label>Nama Barang <span class="req">*</span></label>
      <input name="nama" value="<?= e($b['nama']) ?>" required placeholder="cth: MamyPoko Pants L"></div>

    <div class="field"><label>Kategori <span class="req">*</span></label>
      <select name="kategori_id" required>
        <option value="">— pilih —</option>
        <?php foreach ($pilihan['kategori'] as $k): ?>
          <option value="<?= (int) $k['id'] ?>" <?= (int) $b['kategori_id'] === (int) $k['id'] ? 'selected' : '' ?>><?= e($k['nama']) ?></option>
        <?php endforeach; ?>
      </select></div>
    <div class="field"><label>Merek</label><input name="merek" value="<?= e($b['merek']) ?>"></div>

    <div class="field"><label>Ukuran</label>
      <input name="ukuran" value="<?= e($b['ukuran']) ?>" placeholder="cth: L, M, 400g, 0-3bln"></div>
    <div class="field"><label>Satuan <span class="req">*</span></label>
      <select name="satuan">
        <?php foreach ($pilihan['satuan'] as $so): ?>
          <option value="<?= $so ?>" <?= $b['satuan'] === $so ? 'selected' : '' ?>><?= $so ?></option>
        <?php endforeach; ?>
      </select></div>

    <div class="field"><label>Harga (Rp)</label>
      <input name="harga" type="number" step="0.01" min="0" value="<?= e((string) $b['harga']) ?>"></div>
    <div class="field"><label>Stok Minimum (ambang "hampir habis")</label>
      <input name="stok_minimum" type="number" min="0" value="<?= e((string) $b['stok_minimum']) ?>"></div>

    <div class="field"><label>Supplier Utama</label>
      <select name="supplier_id">
        <option value="">— tidak ada —</option>
        <?php foreach ($pilihan['supplier'] as $s): ?>
          <option value="<?= (int) $s['id'] ?>" <?= (int) $b['supplier_id'] === (int) $s['id'] ? 'selected' : '' ?>><?= e($s['nama']) ?></option>
        <?php endforeach; ?>
      </select></div>
    <div class="field"><label>Lokasi Rak Utama</label>
      <select name="lokasi_rak_id">
        <option value="">— tidak ada —</option>
        <?php foreach ($pilihan['rak'] as $r): ?>
          <option value="<?= (int) $r['id'] ?>" <?= (int) $b['lokasi_rak_id'] === (int) $r['id'] ? 'selected' : '' ?>>
            <?= e($r['nama_gudang'] ? $r['nama_gudang'] . ' › ' : '') ?><?= e($r['nama']) ?>
          </option>
        <?php endforeach; ?>
      </select></div>

    <div class="field span-all"><label>Keterangan</label>
      <textarea name="keterangan" rows="2" placeholder="opsional"><?= e($b['keterangan']) ?></textarea></div>

    <div class="field span-all">
      <label style="display:flex;align-items:center;gap:8px;cursor:pointer">
        <input type="checkbox" name="is_active" <?= (int) $b['is_active'] === 1 ? 'checked' : '' ?>>
        Barang aktif
      </label></div>

    <?php /* ------------------------------------------------------------
             FOTO PRODUK
             ------------------------------------------------------------
             Menempati dua kolom karena pratinjaunya perlu ruang: petugas
             gudang mencocokkan barang di tangan dengan gambar di layar,
             dan gambar sebesar perangko tidak menolong siapa pun.

             Kolom `foto` menyimpan NAMA BERKAS saja, bukan jalurnya.
             Memindahkan folder unggahan kelak cukup mengubah satu
             konstanta, bukan menyunting ribuan baris di basis data.
          */ ?>
    <div class="field" style="grid-column:1/-1">
      <label>Foto Produk</label>
      <?php $fotoUrl = url_unggahan((string) ($b['foto'] ?? '')); ?>
      <div style="display:flex;gap:14px;align-items:flex-start;flex-wrap:wrap">
        <div id="pratinjauFoto"
             style="width:96px;height:96px;border-radius:12px;overflow:hidden;
                    border:1px solid var(--garis,#e2e7f0);background:#f3f5fa;
                    display:flex;align-items:center;justify-content:center;flex:0 0 auto">
          <?php if ($fotoUrl !== ''): ?>
            <img src="<?= e($fotoUrl) ?>" alt="Foto <?= e($b['nama']) ?>"
                 style="width:100%;height:100%;object-fit:cover">
          <?php else: ?>
            <svg width="34" height="34" aria-hidden="true" style="opacity:.35">
              <use href="#i-barang"></use></svg>
          <?php endif; ?>
        </div>
        <div style="flex:1 1 240px;min-width:220px">
          <?php /* accept="image/*" — BUKAN daftar tiga format seperti dulu.
                   Kamera ponsel menyimpan HEIC/HEIF, dan daftar yang hanya
                   menyebut png/jpeg/webp membuat foto hasil jepretan sendiri
                   tampak "tidak didukung" di pemilih berkas iOS. Servernya
                   tetap penentu: getimagesize() dan daftar putih format di
                   terima_gambar() menolak yang tidak bisa dibaca, dengan
                   pesan yang menyebut format apa yang diterima. */ ?>
          <input type="file" name="foto" id="fotoBarang" accept="image/*"
                 data-kamera-pratinjau="pratinjauFoto">
          <?php /* -----------------------------------------------------------
                   FOTO LANGSUNG DARI KAMERA

                   Foto produk WAJIB untuk barang baru, sementara satu-satunya
                   cara mengisinya adalah berkas yang sudah ada. Bagi petugas
                   yang sedang memegang barangnya di gudang, itu berarti:
                   foto pakai ponsel, pindahkan ke komputer, baru unggah.
                   Tiga langkah yang cukup untuk membuat orang menunda, dan
                   barang tanpa foto tidak bisa disimpan sama sekali.

                   Tombolnya TERPISAH, atribut `capture` tidak ditempelkan ke
                   isian berkas di atas: di sebagian peramban ponsel, `capture`
                   pada isian utama justru menghapus pilihan "ambil dari
                   galeri" — foto produk yang sudah disiapkan tim lain jadi
                   tidak bisa dipilih lagi. Dua tombol, dua niat.
                   ----------------------------------------------------------- */ ?>
          <button type="button" class="btn btn-ghost btn-sm" style="margin-top:8px"
                  data-kamera-untuk="fotoBarang">
            <svg aria-hidden="true"><use href="#i-kamera"></use></svg>
            Foto dengan Kamera</button>
          <p class="petunjuk" style="margin-top:6px">
            PNG, JPG, atau WebP. Maksimal 2 MB. Foto dari kamera otomatis
            dikecilkan agar muat.
            <?php if ($fotoUrl !== ''): ?>
              <br>Biarkan kosong bila foto tidak diganti.
            <?php endif; ?>
          </p>
          <?php if ($fotoUrl !== ''): ?>
            <label style="display:flex;gap:8px;align-items:center;margin-top:6px">
              <input type="checkbox" name="hapus_foto" value="1"> Hapus foto ini
            </label>
          <?php endif; ?>
        </div>
      </div>
    </div>

  </div>

  <div class="tombol-baris" style="margin-top:8px">
    <button class="btn btn-primary" type="submit"><svg aria-hidden="true"><use href="#i-simpan"></use></svg>
      <?= $isEdit ? 'Simpan Perubahan' : 'Simpan Barang' ?></button>
    <a class="btn btn-ghost" href="barang.php" data-tutup-modal>Batal</a>
  </div>
  <p class="petunjuk" style="margin-top:12px">
    Jumlah stok tidak diatur di sini — stok berubah lewat Perpindahan Barang, Barang Masuk, atau Barang Keluar.
    Lokasi rak di atas hanya lokasi <i>default</i>; lokasi sebenarnya mengikuti batch masing-masing.
  </p>
</form>
<?php /* Dimuat di sini, bukan di _footer.php: berkas ini satu-satunya yang
         memakainya, dan menaruhnya di footer berarti setiap halaman ikut
         mengunduh kode kamera yang tidak pernah dipanggil. Berkasnya sendiri
         berjaga terhadap pemuatan ganda — halaman daftar memuat formulir ini
         sebagai popup DAN halaman penuh memuatnya sebagai isi utama. */ ?>
<?php /* Lewat aset(): penanda cache dari waktu ubah berkas. Tanpa itu,
         perbaikan pada berkas ini tidak akan pernah sampai ke peramban yang
         sudah menyimpan salinannya — kejadian yang sudah pernah menimpa
         berkas scan. */ ?>
<script src="<?= e(aset('assets/kamera-foto.js')) ?>"></script>
