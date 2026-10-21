<?php
/**
 * AMANAH — Logika formulir Perpindahan Barang
 *
 * Satu tempat untuk penjaga peran, daftar pilihan, penangan POST, dan prefill
 * kiriman supplier. Dipakai DUA layar:
 *
 *   • perpindahan.php       — popup "Buat Perpindahan" di atas daftar
 *   • perpindahan_form.php  — halaman penuh (tautan bisa disalin & dibagikan,
 *                             dan jalan cadangan bila JavaScript mati)
 *
 * Kenapa dipisah dan tidak disalin: berkas ini memuat pemeriksaan rute
 * (jenis ↔ asal ↔ tujuan) dan pemeriksaan ulang lingkup gudang di SISI
 * SERVER. Dua salinan berarti dua tempat yang harus ikut berubah setiap kali
 * ada jenis dokumen baru — dan yang terlupa biasanya justru pemeriksaan
 * keamanannya, karena ia tidak pernah terlihat saat dipakai sehari-hari.
 *
 * Yang harus disetel pemanggil SEBELUM menyertakan berkas ini:
 *   $ppAksi  nama nilai <input name="act"> yang menandai kiriman formulir ini.
 *            POST tanpa penanda itu dilewati, supaya halaman daftar yang
 *            menyertakan berkas ini tidak ikut menangkap kiriman lain.
 */
if (!isset($ppAksi)) { $ppAksi = 'buat_perpindahan'; }

/* Penjaga peran dijalankan di sini juga, bukan hanya di halaman penuh.
   perpindahan.php boleh dibuka Pimpinan (memantau), jadi pemanggil yang
   memutuskan apakah berkas ini disertakan — tapi kalau tersertakan, aturannya
   sama di kedua layar. */
require_role(['super_admin', 'admin_gudang', 'admin_terpadu', 'toko']);

/* Toko boleh memindahkan barang ANTAR RAK di gudang penampungnya — itu
   pekerjaan gudang toko sehari-hari. Tapi jenis dokumen lain (penerimaan
   dari supplier, penyaluran, retur) adalah alur sisi gudang lembaga, dan
   toko punya jalurnya sendiri di dasbornya. Dibatasi di sini, bukan cuma
   disembunyikan di formulir, karena ?jenis= datang dari URL. */
if (is_toko()) {
    $jenisDiminta = $_GET['jenis'] ?? $_POST['jenis'] ?? 'mutasi_rak';
    // Daftarnya ada di jenis_boleh_peran() di _lib.php, dipakai bersama
    // dropdown jenis di _perpindahan_isian.php supaya yang ditawarkan dan
    // yang diterima selalu sama.
    if (!in_array($jenisDiminta, jenis_boleh_peran(), true)) {
        http_response_code(403);
        die('<p style="font-family:sans-serif;padding:30px">403 — Dari sini toko hanya dapat '
          . 'memindahkan barang antar rak di gudang toko, atau mengirimkannya ke gudang lembaga. '
          . '<a href="dasbor_toko.php?tab=gudang">Kembali ke Gudang Toko</a></p>');
    }
}
$u = current_user();

$errors = [];

// Prefill dari kiriman supplier yang sudah discan/diterima.
$dariKiriman = (int) ($_GET['kiriman'] ?? 0);

$barangList   = $pdo->query("SELECT id, kode, nama, satuan FROM barang WHERE deleted_at IS NULL AND is_active = 1 ORDER BY nama")->fetchAll();

$supplierList = $pdo->query("SELECT id, nama FROM supplier WHERE deleted_at IS NULL ORDER BY nama")->fetchAll();
/* Gudang & rak yang bisa dipilih dibatasi ke lingkup akun ini — baik
   sebagai ASAL maupun TUJUAN. Pembatasan di dropdown saja tidak cukup;
   lihat pemeriksaan ulang di sisi server setelah formulir dikirim. */
/* Gudang yang boleh dipilih, per peran.
   Sengaja bertolak dari GUDANG, bukan dari daftar rak: gudang yang belum
   punya rak pun harus tetap bisa dipilih sebagai tujuan — kalau tidak,
   gudang baru tidak akan pernah bisa menerima barang pertamanya. */
if (is_toko()) {
    $gdToko   = array_column(gudang_toko($pdo), 'id');
    $inGudang = $gdToko ? implode(',', array_map('intval', $gdToko)) : '0';
} else {
    $inGudang = in_gudang_saya($pdo);
}

/* ----------------------------------------------------------------------
   ASAL DIBATASI, TUJUAN TIDAK — DAN ITU BUKAN KELALAIAN

   Dulu satu daftar dipakai untuk keduanya, disaring ke gudang sendiri.
   Akibatnya Admin Gudang Depo tidak bisa mengirim apa pun ke Gudang
   Ramah: gudang tujuan yang sah pun tidak muncul di pilihannya.

   Keduanya sekarang dipisah, karena dua pertanyaannya memang berbeda:

     ASAL   — "stok siapa yang berkurang". Mengambil dari rak gudang lain
              berarti memotong persediaan yang bukan tanggung jawab saya,
              tanpa sepengetahuan yang mengurusnya. Tetap dibatasi.

     TUJUAN — "stok siapa yang bertambah". Mengirim tidak mengurangi apa
              pun milik penerima, dan barangnya baru benar-benar masuk
              setelah gudang tujuan MEMVERIFIKASI fisiknya lewat
              Penerimaan Barang. Jadi tidak ada yang perlu dilindungi
              dengan menyembunyikan namanya. Dibuka ke semua gudang.

   Nama gudang bukan data sensitif — dasbor mitra sudah menawarkan
   seluruh gudang kepada supplier sejak awal, dengan alasan yang sama.
   ---------------------------------------------------------------------- */
$gudangList   = $pdo->query(
    "SELECT id, kode, nama FROM gudang
     WHERE deleted_at IS NULL AND is_active = 1
       AND id IN ($inGudang) ORDER BY kode")->fetchAll();
$rakList      = $pdo->query(
    "SELECT lr.id, lr.kode, lr.nama, lr.gudang_id, g.nama AS nama_gudang
     FROM lokasi_rak lr LEFT JOIN gudang g ON g.id = lr.gudang_id
     WHERE lr.deleted_at IS NULL AND lr.gudang_id IN ($inGudang)
     ORDER BY g.kode, lr.kode"
)->fetchAll();

$gudangTujuanList = $pdo->query(
    "SELECT id, kode, nama FROM gudang
     WHERE deleted_at IS NULL AND is_active = 1 ORDER BY kode")->fetchAll();
$rakTujuanList    = $pdo->query(
    "SELECT lr.id, lr.kode, lr.nama, lr.gudang_id, g.nama AS nama_gudang
     FROM lokasi_rak lr LEFT JOIN gudang g ON g.id = lr.gudang_id
     WHERE lr.deleted_at IS NULL AND g.deleted_at IS NULL AND g.is_active = 1
     ORDER BY g.kode, lr.kode"
)->fetchAll();
$penerimaList = [];
try {
    $penerimaList = $pdo->query(
        "SELECT id, kode, nama, wilayah FROM penerima
         WHERE deleted_at IS NULL AND is_active = 1 ORDER BY nama"
    )->fetchAll();
} catch (Throwable $e) { /* migrasi penerima belum dijalankan */ }

$tokoList     = [];
try {
    $tokoList = $pdo->query(
        "SELECT id, kode, nama, marketplace FROM toko
         WHERE deleted_at IS NULL AND is_active = 1 ORDER BY kode"
    )->fetchAll();
} catch (Throwable $e) { /* migrasi v3 belum dijalankan */ }


// ---------------------------------------------------------------- SIMPAN
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['act'] ?? '') === $ppAksi) {
    wajib_csrf($_POST['csrf'] ?? null);

    $jenis      = $_POST['jenis'] ?? '';
    $asalTipe   = $_POST['asal_tipe'] ?? '';
    $asalId     = (int) ($_POST['asal_id'] ?? 0) ?: null;
    $asalTeks   = trim($_POST['asal_teks'] ?? '');
    $tujuanTipe = $_POST['tujuan_tipe'] ?? '';
    $tujuanId   = (int) ($_POST['tujuan_id'] ?? 0) ?: null;
    $tujuanTeks = trim($_POST['tujuan_teks'] ?? '');

    $noResi     = trim($_POST['no_resi'] ?? '');
    $ekspedisi  = trim($_POST['ekspedisi'] ?? '');
    $kendaraan  = trim($_POST['kendaraan'] ?? '');
    $noPolisi   = trim($_POST['no_polisi'] ?? '');
    $pengirim   = trim($_POST['pengirim'] ?? '');
    $penerima   = trim($_POST['penerima'] ?? '');
    $program    = trim($_POST['untuk_program'] ?? '');
    $catatan    = trim($_POST['catatan'] ?? '');

    /* Tanggal dokumen. Aturannya di tanggal_dokumen_sah() pada _lib.php,
       dipakai BERSAMA dengan dasbor supplier dan dasbor toko — batas
       tanggal yang berbeda antar jalur berarti dokumen yang sama bisa
       sah di satu halaman dan ditolak di halaman lain. */
    $tglCek    = tanggal_dokumen_sah($_POST['tanggal'] ?? null);
    if ($tglCek['galat']) { $errors[] = $tglCek['galat']; }
    $tglDok    = $tglCek['tgl'];
    $tglSimpan = $tglCek['simpan'];
    $simpanDraft = isset($_POST['simpan_draft']);

    $jenisValid = array_keys(rute_sah());
    if (!in_array($jenis, $jenisValid, true))                 $errors[] = 'Jenis perpindahan tidak valid.';
    if (!in_array($asalTipe, ['supplier','gudang','rak','toko','eksternal'], true))   $errors[] = 'Asal tidak valid.';
    if (!in_array($tujuanTipe, ['gudang','rak','toko','penerima','supplier'], true))  $errors[] = 'Tujuan tidak valid.';

    // Kombinasi jenis ↔ asal ↔ tujuan diperiksa DI SERVER, bukan hanya di
    // JavaScript. Tanpa ini, POST yang dimanipulasi bisa mengaku "penerimaan"
    // dengan asal sebuah gudang — stok tercipta di tujuan tanpa pernah
    // dipotong dari asalnya, alias stok berlipat ganda.
    if (!$errors && $pesanRute = periksa_rute($jenis, $asalTipe, $tujuanTipe)) {
        $errors[] = $pesanRute;
    }

    /* Lingkup gudang diperiksa ULANG di server. Menyaring dropdown hanya
       merapikan tampilan; POST yang diubah tangan tetap bisa menyebut rak
       gudang lain, dan itu berarti stok orang lain berpindah atas nama
       petugas yang tidak berwenang. */
    if (!$errors && (terikat_satu_gudang() || is_toko())) {
        /* Toko diperiksa dengan aturan yang sama: rak yang boleh disentuh
           adalah rak gudang penampungnya, bukan rak gudang lembaga. */
        if (is_toko()) {
            $rakBoleh = array_map('intval', rak_gudang_toko($pdo));
            $gdBoleh  = array_map('intval', array_column(gudang_toko($pdo), 'id'));
        } else {
            $rakBoleh = rak_saya($pdo);
            $gdBoleh  = gudang_saya($pdo);
        }
        if ($asalTipe === "rak"    && !in_array((int) $asalId,   $rakBoleh, true)) {
            $errors[] = "Rak asal berada di gudang yang bukan tanggung jawab Anda.";
        }
        if ($asalTipe === "gudang" && !in_array((int) $asalId,   $gdBoleh,  true)) {
            $errors[] = "Gudang asal bukan tanggung jawab Anda.";
        }
        /* TUJUAN sengaja tidak diperiksa terhadap lingkup — lihat alasannya
           di atas, tempat $gudangTujuanList disusun. Yang tetap diperiksa
           bahwa tujuannya SUNGGUH ADA dan masih aktif; tanpa itu, POST yang
           diubah tangan bisa menitipkan barang ke gudang yang sudah dihapus
           dan dokumennya menggantung tanpa penerima. */
        if ($tujuanTipe === "gudang" && $tujuanId) {
            $ada = $pdo->prepare("SELECT COUNT(*) FROM gudang
                                   WHERE id = ? AND deleted_at IS NULL AND is_active = 1");
            $ada->execute([(int) $tujuanId]);
            if (!$ada->fetchColumn()) { $errors[] = "Gudang tujuan tidak ditemukan atau sudah nonaktif."; }
        }
        if ($tujuanTipe === "rak" && $tujuanId) {
            $ada = $pdo->prepare(
                "SELECT COUNT(*) FROM lokasi_rak lr JOIN gudang g ON g.id = lr.gudang_id
                  WHERE lr.id = ? AND lr.deleted_at IS NULL
                    AND g.deleted_at IS NULL AND g.is_active = 1");
            $ada->execute([(int) $tujuanId]);
            if (!$ada->fetchColumn()) { $errors[] = "Rak tujuan tidak ditemukan atau gudangnya nonaktif."; }
        }
    }

    if ($asalTipe === 'eksternal' && $asalTeks === '')   $errors[] = 'Nama asal wajib diisi.';
    if ($asalTipe !== 'eksternal' && !$asalId)           $errors[] = 'Asal wajib dipilih.';
    // Penyaluran boleh menunjuk penerima terdaftar ATAU mengetik nama untuk
    // penerima sekali-pakai. Yang terdaftar lebih baik: riwayatnya terkumpul.
    if ($tujuanTipe === 'penerima' && !$tujuanId && $tujuanTeks === '') {
        $errors[] = 'Pilih penerima dari daftar, atau ketik namanya bila belum terdaftar.';
    }
    if ($tujuanTipe !== 'penerima' && !$tujuanId)        $errors[] = 'Tujuan wajib dipilih.';

    // Asal dan tujuan tidak boleh sama persis.
    if ($asalTipe === $tujuanTipe && $asalId && $tujuanId && $asalId === $tujuanId) {
        $errors[] = 'Asal dan tujuan tidak boleh sama.';
    }
    // Rak asal berada di dalam gudang tujuan yang hanya punya rak itu saja.
    if (!$errors && $asalTipe === 'rak' && $tujuanTipe === 'gudang' && $asalId && $tujuanId) {
        $cekRak = $pdo->prepare(
            "SELECT COUNT(*) FROM lokasi_rak WHERE gudang_id = ? AND deleted_at IS NULL AND id <> ?"
        );
        $cekRak->execute([$tujuanId, $asalId]);
        if ((int) $cekRak->fetchColumn() === 0) {
            $errors[] = 'Gudang tujuan tidak punya rak lain selain rak asal. Pilih rak tujuan secara langsung, '
                      . 'atau tambahkan rak baru di gudang tersebut.';
        }
    }

    // ---- kumpulkan item
    $items = [];
    $rawBarang  = $_POST['barang_id']   ?? [];
    $rawQty     = $_POST['qty']         ?? [];
    $rawBatch   = $_POST['nomor_batch'] ?? [];
    $rawExp     = $_POST['tanggal_expired'] ?? [];
    $rawHarga   = $_POST['harga']       ?? [];
    $rawKet     = $_POST['ket_item']    ?? [];

    foreach ($rawBarang as $i => $bid) {
        $bid = (int) $bid;
        $qty = (int) ($rawQty[$i] ?? 0);
        if (!$bid || $qty <= 0) continue;

        $items[] = [
            'barang_id'       => $bid,
            'qty'             => $qty,
            'nomor_batch'     => trim($rawBatch[$i] ?? ''),
            'tanggal_expired' => trim($rawExp[$i] ?? '') ?: null,
            'harga'           => (float) ($rawHarga[$i] ?? 0),
            'keterangan'      => trim($rawKet[$i] ?? '') ?: null,
            /* Kelompok kardus baris ini (revisi 12 Okt 2026): 0 = tanpa kardus,
               1.. = nomor kardus di formulir. Hanya jenis yang boleh berkardus. */
            'kardus_idx'      => jenis_boleh_kardus($jenis) ? max(0, (int) ($_POST['kardus_idx'][$i] ?? 0)) : 0,
        ];
    }
    /* Kepala tiap kardus (nama, berat, dimensi, catatan — semua opsional), diindeks
       nomor kardus. Kardus yang dirujuk baris tetapi kepalanya tidak terkirim dibuat
       kosong; kardus tanpa satu pun baris ditolak di kardus_buat_untuk_dokumen(). */
    $kardusKepala = [];
    if (jenis_boleh_kardus($jenis)) {
        $idxSemua = array_keys((array) ($_POST['kardus_nama'] ?? []));
        foreach ($items as $it) { if ($it['kardus_idx'] > 0) { $idxSemua[] = $it['kardus_idx']; } }
        /* Kardus siap kirim yang dicentang (dikemas lewat mutasi rak, revisi
           18 Okt 2026): kardus_ada[n] = id kardus. Isinya TIDAK diambil dari
           kiriman peramban, melainkan dibaca ulang dari basis data — baris yang
           dikirim untuk kardus itu dibuang lalu diganti isi aslinya. */
        foreach ((array) ($_POST['kardus_ada'] ?? []) as $ixA => $kidA) {
            $ixA = (int) $ixA; $kidA = (int) $kidA;
            if ($ixA <= 0 || $kidA <= 0) { continue; }
            $idxSemua[] = $ixA;
            $items = array_values(array_filter($items, fn($it) => $it['kardus_idx'] !== $ixA));
            try {
                if (!jenis_ambil_kardus_siap($jenis)) { throw new RuntimeException('Kardus siap kirim hanya untuk transfer gudang, kirim toko, atau penyaluran.'); }
                $kdA = kardus_siap_periksa($pdo, $kidA, $jenis, (string) $asalTipe, $asalId ? (int) $asalId : null);
                foreach (kardus_isi($pdo, $kidA) as $isiA) {
                    $items[] = ['barang_id' => (int) $isiA['barang_id'], 'qty' => (int) $isiA['qty'], 'nomor_batch' => '', 'tanggal_expired' => null,
                                'harga' => 0.0, 'keterangan' => 'Kardus ' . $kdA['kode'], 'kardus_idx' => $ixA];
                }
            } catch (RuntimeException $eA) { $errors[] = $eA->getMessage(); }
        }
        foreach (array_unique(array_map('intval', $idxSemua)) as $ix) {
            if ($ix <= 0) { continue; }
            $kardusKepala[$ix] = [
                'ada'        => (int) ($_POST['kardus_ada'][$ix] ?? 0),
                'nama'       => (string) ($_POST['kardus_nama'][$ix] ?? ''),
                'berat_kg'   => (string) ($_POST['kardus_berat'][$ix] ?? ''),
                'panjang_cm' => (string) ($_POST['kardus_p'][$ix] ?? ''),
                'lebar_cm'   => (string) ($_POST['kardus_l'][$ix] ?? ''),
                'tinggi_cm'  => (string) ($_POST['kardus_t'][$ix] ?? ''),
                'catatan'    => (string) ($_POST['kardus_catatan'][$ix] ?? ''),
            ];
        }
        ksort($kardusKepala);
        foreach ($kardusKepala as $ix => $kk) {
            $ada = false; foreach ($items as $it) { if ($it['kardus_idx'] === $ix) { $ada = true; break; } }
            if (!$ada) { $errors[] = "Kardus $ix masih kosong — tambahkan barangnya atau hapus kardus itu."; }
        }
    }

    if (!$items) $errors[] = 'Minimal satu baris barang harus diisi (barang dan jumlah).';

    // Penerimaan wajib punya nomor batch supaya kedaluwarsa bisa dilacak.
    if ($jenis === 'penerimaan') {
        foreach ($items as $n => $it) {
            if ($it['nomor_batch'] === '') {
                $errors[] = 'Baris ' . ($n + 1) . ': nomor batch wajib diisi untuk penerimaan barang.';
            }
        }
    }

    // Untuk perpindahan yang mengambil stok, cek ketersediaan lebih dulu.
    //
    // Pemeriksaan dilakukan atas TOTAL per barang, bukan per baris. Bila
    // barang yang sama ditulis di dua baris (mis. 2 × 60 unit dari stok 100),
    // pemeriksaan per baris akan meloloskan keduanya dan dokumen baru gagal
    // saat disetujui — menjadi dokumen zombi di antrian persetujuan.
    // Semua jenis KECUALI penerimaan mengambil stok dari lokasi asal.
    $mengambilStok = ($jenis !== '' && $jenis !== 'penerimaan');
    /* Total per barang dari seluruh baris (semua kardus + tanpa kardus),
       dikurangi stok yang sudah dipesan surat jalan lain yang menunggu
       persetujuan atau sedang dikirim (revisi 18 Okt 2026, _stok_pesan.php). */
    if (!$errors && $mengambilStok) {
        $errors = array_merge($errors, periksa_stok_dokumen($pdo, $jenis, (string) $asalTipe, $asalId ? (int) $asalId : null, $items, STATUS_MEMESAN_STOK));
    }

    // ---- titik transit (gudang yang disinggahi) — aturannya di _lacak.php
    $titikTransit = [];
    if (!$errors) {
        try {
            $titikTransit = periksa_titik_transit($pdo, (array) ($_POST['transit_id'] ?? []), $jenis,
                                                  $asalTipe, $asalId, $tujuanTipe, $tujuanId);
        } catch (RuntimeException $e) { $errors[] = $e->getMessage(); }
    }

    // ---- simpan
    if (!$errors) {
        try {
            /* Mutasi rak sah begitu dibuat — lihat langsung_sah() di _lib.php.
               Barangnya tidak berpindah pemilik dan tidak keluar gudang, jadi
               tidak ada pihak kedua yang catatannya perlu dicocokkan.

               Draft tetap dihormati: petugas yang sengaja menyimpan draft
               memang belum ingin memindahkannya. */
            $langsung = langsung_sah($jenis) && !$simpanDraft;
            $status   = $simpanDraft ? 'draft' : 'menunggu_persetujuan';

            $hasil = dengan_nomor_dokumen($pdo, 'perpindahan', 'no_perpindahan', 'PP',
                function (string $no) use ($pdo, $jenis, $asalTipe, $asalId, $asalTeks, $tujuanTipe,
                                            $tujuanId, $tujuanTeks, $noResi, $ekspedisi, $kendaraan,
                                            $noPolisi, $pengirim, $penerima, $program, $catatan,
                                            $status, $items, $u, $tglSimpan, $titikTransit, $kardusKepala) {
                    $pdo->beginTransaction();

                    // Resi wajib terisi, walau barangnya tidak lewat ekspedisi.
                    //
                    // Kiriman yang diantar kendaraan sendiri tidak punya nomor
                    // resi kurir, dan dulu kolomnya dibiarkan NULL. Akibatnya
                    // dokumen itu TIDAK PERNAH bisa ditemukan lewat Scan Resi
                    // — halaman itu mencari `WHERE no_resi = ?` — sehingga
                    // petugas penerima terpaksa mencarinya manual.
                    //
                    // Bila kosong, nomor dokumen dipakai sebagai resi internal.
                    // Cara ini sudah dipakai jalur supplier (memakai nomor surat
                    // jalan), jadi keduanya kini seragam.
                    $resiFinal = $noResi !== '' ? $noResi : $no;

                    $pdo->prepare(
                        "INSERT INTO perpindahan
                           (no_perpindahan, jenis, tanggal, asal_tipe, asal_id, asal_teks,
                            tujuan_tipe, tujuan_id, tujuan_teks, no_resi, ekspedisi, kendaraan,
                            no_polisi, pengirim, penerima, untuk_program, status, user_id, catatan)
                         VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)"
                    )->execute([
                        $no, $jenis, $tglSimpan, $asalTipe, $asalId, $asalTeks ?: null,
                        $tujuanTipe, $tujuanId, $tujuanTeks ?: null,
                        $resiFinal, $ekspedisi ?: null, $kendaraan ?: null,
                        $noPolisi ?: null, $pengirim ?: null, $penerima ?: null,
                        $program ?: null, $status, $u['id'], $catatan ?: null,
                    ]);
                    $ppId = (int) $pdo->lastInsertId();

                    /* Kardus dibuat lebih dulu (kode KRD-… otomatis), lalu tiap baris
                       isinya ditulis dengan kardus_id. Satu transaksi: gagal satu,
                       dokumen & kardusnya tidak tersimpan sebagian. */
                    $kardusDibuat = $kardusKepala
                        ? kardus_buat_untuk_dokumen($pdo, $ppId, ['no_perpindahan' => $no, 'jenis' => $jenis, 'asal_tipe' => $asalTipe, 'asal_id' => $asalId, 'tanggal' => $tglSimpan],
                                                    $kardusKepala, $items, (int) $u['id'])
                        : [];
                    $adaKolKardus = $kardusDibuat && kardus_aktif($pdo);
                    $stD = $pdo->prepare(
                        "INSERT INTO perpindahan_detail
                           (perpindahan_id, barang_id, nomor_batch, tanggal_expired, qty, harga, subtotal, keterangan" . ($adaKolKardus ? ', kardus_id' : '') . ")
                         VALUES (?,?,?,?,?,?,?,?" . ($adaKolKardus ? ',?' : '') . ")"
                    );
                    foreach ($items as $it) {
                        $kid = $kardusDibuat[$it['kardus_idx']]['id'] ?? null;
                        $stD->execute(array_merge([
                            $ppId, $it['barang_id'], $it['nomor_batch'] ?: null, $it['tanggal_expired'],
                            $it['qty'], $it['harga'], $it['qty'] * $it['harga'], $it['keterangan'],
                        ], $adaKolKardus ? [$kid] : []));
                    }
                    simpan_titik_transit($pdo, $ppId, $titikTransit);
                    $GLOBALS['kardusKodeBaru'] = array_column($kardusDibuat, 'kode');

                    $pdo->commit();
                    return ['id' => $ppId, 'no' => $no];
                });

            // Kaitkan ke kiriman supplier bila dokumen ini berasal dari sana.
            if ($dariKiriman > 0) {
                try {
                    $pdo->prepare("UPDATE pengiriman_supplier SET perpindahan_id = ?, status = 'diterima',
                                   diterima_oleh = ?, diterima_at = NOW() WHERE id = ?")
                        ->execute([$hasil['id'], $u['id'], $dariKiriman]);
                } catch (Throwable $e) { error_log('[AMANAH] kait kiriman: ' . $e->getMessage()); }
            }

            /* Tanggal yang BUKAN HARI INI disebut di jejak aktivitas.
               Bukan untuk mencurigai siapa pun — menggeser tanggal
               memang sah dan sering perlu. Tapi sejak batas tanggalnya
               dicabut, jejak inilah SATU-SATUNYA tempat yang menunjukkan
               bahwa sebuah dokumen tidak bertanggal hari pembuatannya.
               Kalau kelak angka laporan dipertanyakan, di sini
               jawabannya. */
            $ketAkt = "Buat perpindahan {$hasil['no']} ($jenis, $status)";
            if (!empty($GLOBALS['kardusKodeBaru'])) { $ketAkt .= ' — kardus ' . implode(', ', $GLOBALS['kardusKodeBaru']); }
            if ($tglDok !== date('Y-m-d')) {
                $ketAkt .= ' — tanggal dokumen disetel ke ' . tgl_id($tglDok)
                          . ' (dibuat ' . tgl_id(date('Y-m-d')) . ')';
            }
            catat_aktivitas($pdo, 'create', 'perpindahan', $hasil['id'], $ketAkt);

            /* Pengesahan mutasi rak dijalankan di transaksinya SENDIRI, setelah
               dokumennya benar-benar tersimpan. Digabung ke dalam transaksi
               pembuatan, satu kegagalan penerapan stok akan ikut membatalkan
               dokumennya — dan petugas kehilangan seluruh isian keranjangnya
               tanpa tahu sebabnya. Dipisah begini, dokumennya tetap ada dan
               bisa disahkan ulang lewat Persetujuan bila penerapannya gagal. */
            $pesanSah = null;
            if ($langsung) {
                try {
                    $pdo->beginTransaction();
                    $sah = sahkan_perpindahan($pdo, (int) $hasil['id'], (int) $u['id']);
                    $pdo->commit();
                    catat_aktivitas($pdo, 'approve', 'perpindahan', $hasil['id'],
                        "Mutasi rak sah langsung → {$sah['no_sj']}");
                    $pesanSah = "Barang sudah dipindahkan. Bukti pindah {$sah['no_sj']} siap dicetak.";
                } catch (Throwable $e) {
                    if ($pdo->inTransaction()) { $pdo->rollBack(); }
                    error_log('[AMANAH] mutasi rak langsung: ' . $e->getMessage());
                    $pesanSah = 'Dokumen tersimpan, tapi stoknya BELUM dipindahkan: '
                              . ($e instanceof RuntimeException ? $e->getMessage() : 'terjadi galat teknis')
                              . ' Selesaikan lewat menu Persetujuan.';
                }
            }

            if ($pesanSah !== null) {
                set_flash('ok', $pesanSah);
            } else {
                set_flash('ok', $simpanDraft
                    ? "Draft {$hasil['no']} tersimpan. Kirim untuk persetujuan bila sudah siap."
                    : "Perpindahan {$hasil['no']} dikirim untuk persetujuan. Stok akan berubah setelah disetujui.");
            }
            header('Location: perpindahan.php'); exit;

        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $errors[] = pesan_error_aman($e, 'simpan perpindahan');
        }
    }
}

// ---------------------------------------------------------------- PREFILL
$prefill = null;
if ($dariKiriman > 0) {
    try {
        $st = $pdo->prepare(
            "SELECT ps.*, s.nama AS nama_supplier
             FROM pengiriman_supplier ps
             JOIN supplier s ON s.id = ps.supplier_id
             WHERE ps.id = ? AND ps.perpindahan_id IS NULL"
        );
        $st->execute([$dariKiriman]);
        $prefill = $st->fetch() ?: null;

        if ($prefill) {
            $std = $pdo->prepare(
                "SELECT d.*, b.nama AS nama_barang
                 FROM pengiriman_supplier_detail d
                 LEFT JOIN barang b ON b.id = d.barang_id
                 WHERE d.pengiriman_supplier_id = ?"
            );
            $std->execute([$dariKiriman]);
            $prefill['items'] = $std->fetchAll();
        }
    } catch (Throwable $e) { error_log('[AMANAH] prefill: ' . $e->getMessage()); }
}

/* Jenis yang sedang disaring di daftar ikut terbawa ke formulir.
 *
 * Menu "Transfer Antar Gudang" dan "Pindah Antar Rak" membuka halaman yang
 * sama dengan ?jenis=, jadi orang yang menekan salah satunya sudah
 * menyatakan maksudnya. Sebelumnya maksud itu dibuang: formulirnya terbuka
 * dengan jenis kosong, dan ia harus memilih ulang dari delapan pilihan yang
 * salah satunya persis menu yang baru saja ditekannya.
 *
 * Kiriman supplier tetap menang — datangnya dari dokumen nyata, bukan dari
 * penyaring daftar. Nilai di luar daftar diabaikan; ?jenis= datang dari URL.
 */
$jenisAwal = '';
if ($prefill) {
    $jenisAwal = 'penerimaan';
} else {
    $minta = (string) ($_GET['jenis'] ?? '');
    $sah   = ['penerimaan', 'kirim_toko', 'retur_toko', 'antar_toko',
              'transfer_gudang', 'mutasi_rak', 'penyaluran', 'retur'];
    if (in_array($minta, $sah, true)) { $jenisAwal = $minta; }
    // Toko hanya boleh dua jenis; penjaga di atas sudah menolak sisanya,
    // tapi nilai awalnya ikut dijaga supaya formulir tidak pernah terbuka
    // dengan pilihan yang akan ditolak saat disimpan.
    if (is_toko() && !in_array($jenisAwal, ['mutasi_rak', 'transfer_gudang'], true)) {
        $jenisAwal = 'mutasi_rak';
    }
}
