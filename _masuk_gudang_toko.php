<?php
/**
 * =============================================================================
 * BARANG MASUK KE GUDANG TOKO — logika bersama
 * =============================================================================
 * Dipakai DUA halaman:
 *
 *   penerimaan.php          menu "Barang Masuk" sisi toko — tempat orang
 *                           mencarinya, karena di sanalah kiriman masuk
 *                           juga ditangani
 *   dasbor_toko.php?tab=gudang  bersebelahan dengan formulir distribusi,
 *                           mengikuti urutan pekerjaannya
 *
 * Diletakkan di berkas sendiri, BUKAN disalin ke keduanya. Aturannya memuat
 * hal-hal yang tidak boleh menyimpang: siapa yang berwenang, rak mana yang
 * sah, dan bahwa penambahannya dicatat sebagai transaksi_masuk sungguhan.
 * Dua salinan berarti cepat atau lambat salah satunya lupa memeriksa raknya,
 * dan barang bisa mendarat di gudang yang bukan milik toko itu.
 *
 * -----------------------------------------------------------------------------
 * MENGAPA MASUK KE GUDANG TOKO DULU, BUKAN LANGSUNG KE TOKO
 * -----------------------------------------------------------------------------
 * Satu kiriman biasanya dipecah ke beberapa toko. Kalau ia langsung masuk ke
 * satu toko, memecahnya menuntut memindahkan barang yang sudah tercatat
 * sebagai milik toko itu — dan riwayatnya jadi berbunyi "toko A pernah
 * memiliki barang yang sebenarnya tidak pernah sampai ke sana".
 */

/**
 * Wewenang: tingkat manager, dan bukan sedang mengawasi dasbor orang lain.
 *
 * Bisa disimpangkan per akun lewat izin 'masuk_gudang_toko' — staff toko
 * tertentu boleh diberi wewenang ini tanpa perlu dijadikan manager, yang
 * kalau tidak akan sekaligus membuka penghapusan data dan nilai rupiah.
 *
 * Penyimpangan itu TIDAK berlaku saat sedang mengawasi: pengawas membaca
 * dasbor milik orang lain, dan menulis dari sana berarti mencatat atas
 * nama orang yang tidak melakukannya.
 */
function boleh_masuk_gudang_toko(PDO $pdo, bool $sedangMengawasi = false): bool {
    if ($sedangMengawasi) { return false; }
    return izin($pdo, 'masuk_gudang_toko', is_manager($pdo));
}

/**
 * Pilihan untuk formulirnya: rak gudang toko, katalog produk, dan mitra.
 *
 * Katalog produk diambil SELURUHNYA, bukan hanya yang sudah ada stoknya di
 * gudang toko — barang yang baru pertama kali masuk justru belum punya stok
 * di sana, dan itu tepatnya keadaan yang paling sering.
 */
function pilihan_masuk_gudang_toko(PDO $pdo): array {
    $gd = array_column(gudang_toko($pdo), 'id');
    if (!$gd) { return ['rak' => [], 'barang' => [], 'supplier' => []]; }
    $inGd = implode(',', array_map('intval', $gd));

    try {
        return [
            'rak' => $pdo->query(
                "SELECT lr.id, lr.kode, lr.nama, g.nama AS gudang
                 FROM lokasi_rak lr JOIN gudang g ON g.id = lr.gudang_id
                 WHERE lr.gudang_id IN ($inGd) AND lr.deleted_at IS NULL
                 ORDER BY g.nama, lr.kode")->fetchAll(),
            'barang' => $pdo->query(
                "SELECT id, kode, nama, satuan, harga FROM barang
                 WHERE deleted_at IS NULL AND is_active = 1 ORDER BY nama")->fetchAll(),
            'supplier' => $pdo->query(
                "SELECT id, nama FROM supplier WHERE deleted_at IS NULL
                 ORDER BY nama")->fetchAll(),
        ];
    } catch (Throwable $e) {
        error_log('[AMANAH] pilihan masuk gudang toko: ' . $e->getMessage());
        return ['rak' => [], 'barang' => [], 'supplier' => []];
    }
}

/**
 * Simpan barang masuk ke gudang toko.
 *
 * Mengembalikan ['ok' => bool, 'errors' => string[], 'pesan' => string].
 * TIDAK melempar dan TIDAK mengalihkan halaman — pemanggilnya yang
 * memutuskan apa yang terjadi sesudahnya, karena dua halaman yang
 * memakainya kembali ke tempat yang berbeda.
 */
function simpan_masuk_gudang_toko(PDO $pdo, array $in, array $u): array {
    $errors = [];

    $sumberM  = sumber_sah($in['m_sumber'] ?? 'pembelian');
    $supId    = (int) ($in['m_supplier'] ?? 0) ?: null;
    $noResi   = trim($in['m_resi'] ?? '');
    $catatanM = trim($in['m_catatan'] ?? '');

    /* Rak sah dibaca SEKALI, lalu dipakai memeriksa setiap baris.
       Membacanya di dalam perulangan berarti satu kueri per barang. */
    $rakSah = array_map('intval', rak_gudang_toko($pdo));

    /* Baris keranjang. Nama isiannya SAMA dengan masuk.php — barang_id[],
       qty[], nomor_batch[], tanggal_expired[], lokasi_rak_id[], kondisi[],
       harga[] — supaya kedua halaman bisa dibaca dengan satu cara.

       Baris kosong dilewati diam-diam: orang yang mengosongkan isi sebuah
       baris tidak sedang membuat kesalahan, ia sedang membatalkan baris
       itu. Menolaknya dengan pesan galat menghukum niat yang benar. */
    $items = [];
    foreach (($in['barang_id'] ?? []) as $i => $bid) {
        $bid = (int) $bid;
        $q   = (int) ($in['qty'][$i] ?? 0);
        if ($bid <= 0 || $q <= 0) { continue; }

        $rak = (int) ($in['lokasi_rak_id'][$i] ?? 0);
        /* Diperiksa terhadap daftar rak gudang toko yang SAH, bukan sekadar
           "ada isinya". Tanpa ini, mengganti satu angka di formulir cukup
           untuk menaruh barang di rak gudang pusat. Nomor barisnya
           disebutkan supaya yang salah bisa langsung ditemukan. */
        if (!in_array($rak, $rakSah, true)) {
            $errors[] = 'Baris ' . ($i + 1) . ': rak tujuan bukan milik gudang toko.';
            continue;
        }

        $items[] = [
            'barang_id' => $bid,
            'qty'       => $q,
            'rak'       => $rak,
            'batch'     => trim($in['nomor_batch'][$i] ?? '') ?: ('B' . date('ymd')),
            'exp'       => trim($in['tanggal_expired'][$i] ?? '') ?: null,
            'kondisi'   => kondisi_sah($in['kondisi'][$i] ?? 'baru'),
            'harga'     => (float) ($in['harga'][$i] ?? 0),
        ];
    }

    if (!$items && !$errors) {
        $errors[] = 'Keranjang masih kosong. Tembak barcode atau tambahkan barisnya lebih dulu.';
    }
    if ($errors) { return ['ok' => false, 'errors' => $errors, 'pesan' => '']; }

    $totalQty = array_sum(array_column($items, 'qty'));

    try {
        $hasil = dengan_nomor_dokumen($pdo, 'transaksi_masuk', 'no_transaksi', 'TM',
            function (string $noTrx) use ($pdo, $items, $sumberM, $supId, $noResi,
                                          $catatanM, $u) {
                $pdo->beginTransaction();
                /* Seluruh barang dikunci SEKALIGUS dan berurutan, bukan satu
                   per satu di dalam perulangan. Dua petugas yang menyimpan
                   bersamaan dengan urutan kunci berbeda akan saling menunggu
                   selamanya — dan MySQL memutusnya sebagai deadlock. */
                kunci_stok($pdo, array_column($items, 'barang_id'));

                /* Nomor resi ikut ke catatan, bukan ke kolom sendiri:
                   transaksi_masuk tidak punya kolom resi — yang punya adalah
                   perpindahan. Menambah kolom hanya untuk ini berarti migrasi
                   di hosting demi satu keterangan yang sudah bisa dibaca. */
                $ket = $catatanM;
                if ($noResi !== '') {
                    $ket = ($ket !== '' ? $ket . ' · ' : '') . 'Resi ' . $noResi;
                }

                $total = 0.0;
                foreach ($items as $it) { $total += $it['qty'] * $it['harga']; }

                $pdo->prepare(
                    "INSERT INTO transaksi_masuk
                       (no_transaksi, tanggal, supplier_id, user_id, total, catatan)
                     VALUES (?, NOW(), ?, ?, ?, ?)"
                )->execute([$noTrx, $supId, $u['id'], $total,
                            $ket !== '' ? $ket : 'Masuk ke gudang toko']);
                $trxId = (int) $pdo->lastInsertId();

                $insD = $pdo->prepare(
                    "INSERT INTO transaksi_masuk_detail
                       (transaksi_masuk_id, barang_id, batch_id, nomor_batch,
                        tanggal_expired, lokasi_rak_id, qty, harga, subtotal)
                     VALUES (?,?,?,?,?,?,?,?,?)"
                );

                foreach ($items as $it) {
                    // tambah_ke_rak() yang sama dipakai Barang Masuk gudang
                    // pusat — kondisi, sumber, dan penggabungan batch sejenis
                    // mengikuti aturan yang identik.
                    $batchId = tambah_ke_rak($pdo, $it['barang_id'], $it['rak'],
                        $it['batch'], $it['exp'], $it['qty'], $it['harga'],
                        $supId, $it['kondisi'], $sumberM, null);

                    $insD->execute([$trxId, $it['barang_id'], $batchId, $it['batch'],
                        $it['exp'], $it['rak'], $it['qty'], $it['harga'],
                        $it['qty'] * $it['harga']]);
                }

                /* recalc_stok() sekali per BARANG, bukan sekali per baris:
                   dua baris berisi barang yang sama (kondisi berbeda) hanya
                   perlu satu penghitungan ulang. */
                foreach (array_unique(array_column($items, 'barang_id')) as $bid) {
                    recalc_stok($pdo, $bid);
                }

                $pdo->commit();
                return ['id' => $trxId, 'no' => $noTrx];
            });

        catat_aktivitas($pdo, 'create', 'transaksi_masuk', $hasil['id'],
            "Barang masuk gudang toko {$hasil['no']}: " . count($items)
            . ' jenis, ' . $totalQty . ' unit');

        return [
            'ok' => true,
            'errors' => [],
            'pesan' => 'Barang masuk ' . $hasil['no'] . ' tercatat — '
                     . count($items) . ' jenis, ' . angka($totalQty)
                     . ' unit masuk gudang toko. Sekarang bisa didistribusikan ke '
                     . 'toko lewat Gudang Toko.',
        ];

    } catch (RuntimeException $e) {
        if ($pdo->inTransaction()) { $pdo->rollBack(); }
        return ['ok' => false, 'errors' => [pesan_runtime($e, '_masuk_gudang_toko')], 'pesan' => ''];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) { $pdo->rollBack(); }
        return ['ok' => false,
                'errors' => [pesan_error_aman($e, 'barang masuk gudang toko')],
                'pesan' => ''];
    }
}


/**
 * Gambar formulirnya — keranjang, sama seperti Barang Masuk sisi gudang.
 *
 * ---------------------------------------------------------------------
 * MENGAPA KERANJANG, BUKAN SATU BARANG PER SIMPAN
 * ---------------------------------------------------------------------
 * Versi pertama formulir ini satu barang sekali simpan. Itu salah untuk
 * pekerjaan yang sebenarnya: satu kiriman ekspedisi berisi banyak jenis
 * barang, dan mencatatnya satu per satu menghasilkan sepuluh dokumen
 * transaksi_masuk untuk satu kotak yang sama — nomor resinya terulang di
 * sepuluh baris, dan riwayatnya tidak lagi menunjukkan bahwa itu satu
 * kedatangan.
 *
 * Sekarang bentuknya sama dengan masuk.php: tembak barcode berkali-kali,
 * ubah jumlah di barisnya, simpan sekali.
 *
 * Mesinnya assets/keranjang-masuk.js — berkas yang SAMA dengan yang
 * dipakai masuk.php, bukan salinannya. Yang berbeda hanya datanya: rak
 * yang boleh dipilih di sini hanya rak gudang toko.
 *
 * @param string $aksiUrl berbeda per halaman, karena masing-masing
 *                        menangani kirimannya sendiri.
 */
function form_masuk_gudang_toko(array $pilihan, string $aksiUrl): void {
    $batchBawaan = 'B' . date('ymd');
    ?>
    <form method="post" action="<?= e($aksiUrl) ?>" id="formMasukToko" class="mt6">
      <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
      <input type="hidden" name="aksi" value="masuk_gudang_toko">

      <?php /* Kotak scan yang sama dengan masuk.php dan keluar.php.
               Menangani scanner USB/Bluetooth, kamera, dan ketik manual —
               ketiganya berujung menambah baris ke keranjang. */ ?>
      <div data-scan data-judul="Tembak barcode barang — langsung masuk keranjang"></div>

      <div class="tbl-wrap"><table class="tbl-kartu tbl-keranjang">
        <thead><tr>
          <th class="k-barang">Barang</th><th class="k-kondisi">Kondisi</th>
          <th class="k-batch">Batch</th><th class="k-exp">Expired</th>
          <th class="num k-qty">Jumlah</th><th class="k-rak">Rak</th>
          <th class="num k-harga">Harga</th><th class="num k-sub">Subtotal</th>
          <th class="k-aksi"></th>
        </tr></thead>
        <tbody id="isiKeranjang"></tbody>
        <tfoot><tr>
          <td colspan="4">Total</td>
          <td class="num"><b id="totQty">0</b></td>
          <td></td><td></td>
          <td class="num"><b id="totNilai">Rp0</b></td>
          <td></td>
        </tr></tfoot>
      </table></div>

      <div id="kosongKeranjang" class="empty-state">
        <div class="ic"><svg aria-hidden="true"><use href="#i-scan"></use></svg></div>
        <b>Keranjang masih kosong</b>
        <p>Tembak barcode di kotak atas, atau tambahkan barisnya sendiri.</p>
        <div class="aksi">
          <button type="button" class="btn btn-ghost btn-sm" onclick="tambahBarisKosong()">
            <svg aria-hidden="true"><use href="#i-plus"></use></svg> Tambah Baris Manual
          </button>
        </div>
      </div>

      <div class="baris" style="margin-top:14px">
        <button type="button" class="btn btn-ghost btn-sm" onclick="tambahBarisKosong()">
          <svg aria-hidden="true"><use href="#i-plus"></use></svg> Tambah Baris</button>
        <button type="button" class="btn btn-ghost btn-sm" onclick="kosongkanKeranjang()">
          <svg aria-hidden="true"><use href="#i-x"></use></svg> Kosongkan</button>
      </div>

      <?php /* Keterangan yang berlaku untuk SELURUH keranjang, di BAWAH —
               mengikuti urutan kerja: kumpulkan dulu barangnya sambil
               memegang scanner, baru catat keterangannya. Urutan yang sama
               dengan masuk.php. */ ?>
      <div class="setelan-atas setelan-bawah" style="margin-top:18px">
        <h3>Keterangan Kedatangan</h3>
        <div class="form-grid">
          <div class="field"><label>Rak Tujuan Bawaan <span class="req">*</span></label>
            <select id="rakBawaan" onchange="terapkanRakBawaan()">
              <?php foreach ($pilihan['rak'] as $r): ?>
                <option value="<?= (int) $r['id'] ?>">
                  <?= e($r['kode']) ?> — <?= e($r['gudang']) ?></option>
              <?php endforeach; ?>
            </select>
            <small class="petunjuk">Dipakai baris baru. Tiap baris tetap bisa diubah sendiri.</small></div>

          <div class="field"><label>Kondisi Bawaan</label>
            <select id="kondisiBawaan">
              <?php foreach (kondisi_tawaran() as $k => $v): ?>
                <option value="<?= e($k) ?>"><?= e($v[0]) ?></option>
              <?php endforeach; ?>
            </select></div>

          <div class="field"><label>Mitra Pengirim</label>
            <select name="m_supplier">
              <option value="">— diantar langsung / tanpa mitra —</option>
              <?php foreach ($pilihan['supplier'] as $sp): ?>
                <option value="<?= (int) $sp['id'] ?>"><?= e($sp['nama']) ?></option>
              <?php endforeach; ?>
            </select></div>

          <div class="field"><label>Asal Barang</label>
            <select name="m_sumber">
              <option value="pembelian">Pembelian</option>
              <option value="donasi">Titipan / Donasi</option>
            </select></div>

          <?php /* Nomor resi berlaku untuk seluruh kedatangan, bukan per
                   baris — satu kotak ekspedisi berisi banyak jenis barang.
                   Memakai komponen data-scan-ke di assets/scan.js, bukan
                   kode kamera sendiri. */ ?>
          <div class="field span-all"><label>Nomor Resi</label>
            <div class="isian-scan">
              <input name="m_resi" id="mResi" autocomplete="off"
                     placeholder="tembak scanner, ketik, atau pakai kamera">
              <button type="button" class="btn btn-ghost" data-scan-ke="mResi"
                      aria-pressed="false" title="Buka kamera untuk membaca barcode resi">
                <svg aria-hidden="true"><use href="#i-scan"></use></svg> Scan
              </button>
            </div>
            <small class="petunjuk">Opsional — yang diantar langsung memang tidak punya resi.</small></div>

          <div class="field span-all"><label>Catatan</label>
            <input name="m_catatan" placeholder="opsional"></div>
        </div>
      </div>

      <button class="btn btn-primary" type="submit" style="margin-top:16px">
        <svg aria-hidden="true"><use href="#i-plus"></use></svg> Catat Barang Masuk
      </button>
    </form>

    <script>
    /* Data untuk keranjang bersama. Mesinnya assets/keranjang-masuk.js —
       berkas yang SAMA dengan yang dipakai masuk.php.

       Bedanya hanya di sini: `rak` berisi rak gudang toko saja, sehingga
       barang tidak mungkin dipilihkan rak gudang pusat. Sisi server tetap
       memeriksanya lagi lewat rak_gudang_toko() — daftar yang dipersempit
       di layar hanyalah kemudahan, bukan penjagaan. */
    window.KERANJANG = {
      barang: <?= json_encode(array_map(fn($b) => [
          'id' => (int) $b['id'], 'kode' => $b['kode'], 'nama' => $b['nama'],
          'satuan' => $b['satuan'], 'harga' => (float) $b['harga'],
      ], $pilihan['barang']), JSON_UNESCAPED_UNICODE) ?>,
      rak: <?= json_encode(array_map(fn($r) => [
          'id' => (int) $r['id'], 'gudang' => $r['gudang'] ?: '—', 'nama' => $r['kode'],
      ], $pilihan['rak']), JSON_UNESCAPED_UNICODE) ?>,
      kondisi: <?= json_encode(array_map(
          fn($k, $v) => ['v' => $k, 't' => $v[0], 'p' => label_kondisi_pendek($k)],
          array_keys(kondisi_tawaran()), kondisi_tawaran()), JSON_UNESCAPED_UNICODE) ?>,
      batch: <?= json_encode($batchBawaan) ?>,
      form:  'formMasukToko'
    };
    </script>
    <script src="<?= e(aset('assets/keranjang-masuk.js')) ?>"></script>
    <?php
}
