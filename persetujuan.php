<?php
/**
 * AMANAH — Persetujuan Perpindahan
 *
 * Tanpa halaman ini tidak ada stok yang berubah. Setiap perpindahan
 * harus ditinjau dan disetujui oleh Super Admin / Pimpinan; saat itulah
 * stok dipindahkan dan nomor surat jalan diterbitkan.
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/_lib.php';
require_login();

$u          = current_user();
$canApprove = boleh_setujui($pdo);

/* Mitra dan toko diblokir dari halaman ini — KECUALI yang memang diberi
   izin 'setujui' secara sengaja.

   Tanpa pengecualian ini, izin itu tersimpan rapi di basis data dan
   terlihat menyala di Kelola Pengguna, tetapi tidak berarti apa-apa:
   halamannya sudah menolak lebih dulu, sebelum izinnya sempat diperiksa.
   Izin yang tidak bisa dipakai lebih buruk daripada izin yang tidak ada,
   karena yang memberikannya mengira sudah memberi.

   Yang DIBUKA hanyalah pintu halaman ini. Apa yang terlihat di dalamnya
   tetap dibatasi syarat_persetujuan_saya() menurut lingkup perannya —
   akun toko yang diberi izin ini melihat dokumen tokonya, bukan dokumen
   seluruh yayasan. */
if (is_eksternal() && !$canApprove) {
    tolak_supplier();
}
$lihatId    = (int) ($_GET['id'] ?? 0);

// ---------------------------------------------------------------- AKSI
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    wajib_csrf($_POST['csrf'] ?? null);
    $aksi = $_POST['aksi'] ?? '';
    $ppId = (int) ($_POST['id'] ?? 0);

    // Kirim draft untuk persetujuan — boleh dilakukan admin gudang.
    if ($aksi === 'kirim') {
        if (!boleh_edit()) { http_response_code(403); die('403'); }
        /* Draft tidak memesan stok, jadi diperiksa ulang saat dikirim untuk
           persetujuan — terhadap stok dikurangi pesanan dokumen lain. */
        $stPp = $pdo->prepare("SELECT * FROM perpindahan WHERE id = ? AND status = 'draft'"); $stPp->execute([$ppId]);
        if ($ppK = $stPp->fetch()) {
            $kurang = periksa_stok_dokumen($pdo, (string) $ppK['jenis'], (string) $ppK['asal_tipe'], $ppK['asal_id'] ? (int) $ppK['asal_id'] : null,
                                           baris_stok_dokumen($pdo, $ppId), STATUS_MEMESAN_STOK, $ppId);
            if ($kurang) {
                set_flash('err', 'Draft belum bisa dikirim, stok tidak cukup: ' . implode(' • ', $kurang));
                header('Location: persetujuan.php?id=' . $ppId); exit;
            }
        }
        try {
            /* Lingkup ikut disyaratkan di WHERE, bukan hanya di tampilan.
               Tanpa ini, menebak satu angka di ?id= sudah cukup untuk
               mendorong draft milik gudang lain masuk ke antrian
               persetujuan — perbuatan yang tidak bisa dibatalkan sendiri
               oleh pemilik dokumennya. */
            $st = $pdo->prepare("UPDATE perpindahan SET status = 'menunggu_persetujuan'
                                 WHERE id = ? AND status = 'draft'
                                   AND " . syarat_perpindahan($pdo, 'perpindahan'));
            $st->execute([$ppId]);
            if ($st->rowCount()) {
                catat_aktivitas($pdo, 'submit', 'perpindahan', $ppId, 'Kirim untuk persetujuan');
                set_flash('ok', 'Dokumen dikirim untuk persetujuan.');
            } else {
                set_flash('err', 'Dokumen tidak dalam status draft.');
            }
        } catch (Throwable $e) {
            set_flash('err', pesan_error_aman($e, 'kirim persetujuan'));
        }
        header('Location: persetujuan.php?id=' . $ppId); exit;
    }

    /* BATALKAN dokumen (draft, menunggu, atau dikirim) dan HAPUS draft.
       Sampai 13 Sep 2026 tidak ada keduanya — lihat batalkan_perpindahan()
       di _lib.php. Lingkupnya ikut disyaratkan: dokumen di luar lingkup
       akun tidak ditemukan, bukan ditolak dengan pesan yang membenarkan
       keberadaannya. */
    if ($aksi === 'batal' || $aksi === 'hapus_draft') {
        $stD = $pdo->prepare("SELECT * FROM perpindahan WHERE id = ? AND ("
                             . syarat_perpindahan($pdo, 'perpindahan') . ' OR '
                             . syarat_persetujuan_saya($pdo, 'perpindahan') . ')');
        $stD->execute([$ppId]);
        $dokAksi = $stD->fetch();
        if (!$dokAksi) {
            set_flash('err', 'Dokumen tidak ditemukan atau di luar lingkup Anda.');
            header('Location: persetujuan.php'); exit;
        }
        $bolehPutus = boleh_edit() || boleh_setujui_dokumen($pdo, $dokAksi);
        if (!$bolehPutus) { http_response_code(403); die('403'); }
        try {
            if ($aksi === 'batal') {
                $alasan = trim((string) ($_POST['alasan'] ?? ''));
                if ($alasan === '') { throw new RuntimeException('Alasan pembatalan wajib diisi.'); }
                batalkan_perpindahan($pdo, $ppId, (int) $_SESSION['user_id'], $alasan);
                set_flash('ok', 'Dokumen ' . $dokAksi['no_perpindahan'] . ' dibatalkan.');
                header('Location: persetujuan.php?id=' . $ppId); exit;
            }
            if (!boleh_edit()) { http_response_code(403); die('403'); }
            hapus_draft_perpindahan($pdo, $ppId);
            set_flash('ok', 'Draft ' . $dokAksi['no_perpindahan'] . ' dihapus.');
            header('Location: perpindahan.php'); exit;
        } catch (RuntimeException $e) {
            set_flash('err', pesan_runtime($e, 'persetujuan'));
        } catch (Throwable $e) {
            set_flash('err', pesan_error_aman($e, 'batalkan perpindahan'));
        }
        header('Location: persetujuan.php?id=' . $ppId); exit;
    }

    /* Wewenang diperiksa terhadap DOKUMENNYA, bukan sekadar peran.
       Penyaluran langsung mitra → penerima amanah boleh disetujui manager
       gudang; jenis lain tetap milik Super Admin dan Pimpinan. Lihat
       boleh_setujui_dokumen() di _lib.php.

       Dokumennya dibaca sekali di sini hanya untuk menimbang izin. Penguncian
       dan pembacaan ulang yang sebenarnya terjadi di dalam
       sahkan_perpindahan(), di dalam transaksinya sendiri. */
    /* Aksi borongan dikecualikan dari penjaga ini — ia memang tidak punya
       SATU id untuk ditimbang. Wewenangnya tidak dilonggarkan, hanya
       dipindah: diperiksa per dokumen di dalam gelangnya, memakai fungsi
       yang sama persis. Dokumen yang tidak berwenang dilewati, bukan
       menggagalkan seluruh kelompoknya. */
    $cekDok = $pdo->prepare("SELECT jenis, asal_tipe FROM perpindahan WHERE id = ?");
    $cekDok->execute([$ppId]);
    $dokIzin = $cekDok->fetch() ?: [];

    if ($aksi !== 'setujui_kelompok' && (!$dokIzin || !boleh_setujui_dokumen($pdo, $dokIzin))) {
        http_response_code(403);
        die('403 — Anda tidak berwenang memproses dokumen ini. '
          . 'Penyaluran langsung dari mitra dapat disetujui Manager gudang; '
          . 'jenis lainnya oleh Super Admin atau Pimpinan.');
    }

    // ------------------------------------------------------------ TOLAK
    if ($aksi === 'tolak') {
        $alasan = trim($_POST['alasan'] ?? '');
        if ($alasan === '') {
            set_flash('err', 'Alasan penolakan wajib diisi agar pembuat dokumen tahu apa yang harus diperbaiki.');
            header('Location: persetujuan.php?id=' . $ppId); exit;
        }
        try {
            $st = $pdo->prepare(
                "UPDATE perpindahan
                 SET status = 'ditolak', alasan_tolak = ?, approved_by = ?, approved_at = NOW()
                 WHERE id = ? AND status = 'menunggu_persetujuan'"
            );
            $st->execute([mb_substr($alasan, 0, 255), $u['id'], $ppId]);
            if ($st->rowCount()) {
                try { kardus_batal_dokumen($pdo, $ppId, (int) $u['id'], 'ditolak: ' . $alasan); }
                catch (Throwable $eK) { error_log('[AMANAH] batal kardus tolak: ' . $eK->getMessage()); }
                catat_aktivitas($pdo, 'reject', 'perpindahan', $ppId, "Tolak perpindahan: $alasan");
                set_flash('ok', 'Dokumen ditolak. Stok tidak berubah.');
            } else {
                set_flash('err', 'Dokumen sudah diproses sebelumnya.');
            }
        } catch (Throwable $e) {
            set_flash('err', pesan_error_aman($e, 'tolak perpindahan'));
        }
        header('Location: persetujuan.php?id=' . $ppId); exit;
    }

    // ---------------------------------------------------------- SETUJUI
    /* ----------------------------------------------------------------------
       SETUJUI SEKELOMPOK — semua dokumen ke tujuan yang sama

       Penjualan daring menerbitkan SATU dokumen kirim per pesanan. Satu toko
       yang ramai menghasilkan puluhan dokumen menunggu persetujuan dalam
       sehari, seluruhnya dari rak yang sama ke toko yang sama, masing-masing
       berisi satu jenis barang. Menyetujuinya satu per satu berarti empat
       puluh kali membuka, membaca, menekan, kembali.

       Yang digabung PERSETUJUANNYA, bukan dokumennya. Tiap dokumen tetap
       berdiri sendiri karena tiap dokumen mewakili satu pesanan pembeli yang
       sungguhan, dengan surat jalannya sendiri — menggabungkannya jadi satu
       dokumen akan memutus jejak antara pesanan dan kirimannya, dan itu
       justru yang dicari saat ada pembeli mengaku barangnya belum sampai.

       Id-nya TIDAK diambil dari formulir. Yang dikirim hanya kunci
       kelompoknya (jenis + tipe + id tujuan); daftar dokumennya disusun
       ulang di sini dengan syarat lingkup yang sama seperti antrian. Kalau
       id dikirim dari formulir, satu angka yang diubah tangan sudah cukup
       untuk mengesahkan dokumen milik gudang lain.
       ---------------------------------------------------------------------- */
    if ($aksi === 'setujui_kelompok') {
        $gJenis  = (string) ($_POST['g_jenis'] ?? '');
        $gTipe   = (string) ($_POST['g_tipe'] ?? '');
        $gTujuan = (int) ($_POST['g_tujuan'] ?? 0);

        $daftar = [];
        try {
            $st = $pdo->prepare(
                "SELECT p.id, p.jenis, p.asal_tipe FROM perpindahan p
                  WHERE p.status = 'menunggu_persetujuan'
                    AND p.jenis = ? AND p.tujuan_tipe = ?
                    AND p.tujuan_id <=> ?
                    AND (" . syarat_perpindahan($pdo, 'p')
                      . " OR " . syarat_persetujuan_saya($pdo, 'p') . ")
                  ORDER BY p.tanggal ASC");
            $st->execute([$gJenis, $gTipe, $gTujuan ?: null]);
            /* Wewenang ditimbang per dokumen dengan boleh_setujui_dokumen() —
               fungsi yang sama dengan jalur satuan. Kueri di atas hanya
               menjawab "boleh dilihat"; ini menjawab "boleh disahkan", dan
               keduanya memang pertanyaan yang berbeda. */
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $d) {
                if (boleh_setujui_dokumen($pdo, $d)) { $daftar[] = (int) $d['id']; }
            }
        } catch (Throwable $e) {
            error_log('[AMANAH] daftar kelompok: ' . $e->getMessage());
        }

        if (!$daftar) {
            set_flash('err', 'Tidak ada dokumen yang bisa disetujui di kelompok itu.');
            header('Location: persetujuan.php'); exit;
        }

        /* SATU TRANSAKSI PER DOKUMEN, bukan satu untuk semuanya.

           Pengesahan bisa gagal per dokumen — stok kurang, batch keburu
           habis diambil dokumen lain. Dibungkus satu transaksi besar, satu
           kegagalan membatalkan tiga puluh sembilan pengesahan yang sudah
           benar, dan petugas mengulang seluruhnya tanpa tahu yang mana yang
           bermasalah. Per dokumen, yang gagal tinggal sisanya. */
        $sukses = 0; $gagal = []; $noSJ = [];
        foreach ($daftar as $pid) {
            try {
                $pdo->beginTransaction();
                $h = sahkan_perpindahan($pdo, $pid, (int) $u['id']);
                $pdo->commit();
                $sukses++;
                $noSJ[] = $h['no_sj'];
                catat_aktivitas($pdo, 'approve', 'perpindahan', $pid,
                    "Setujui {$h['no_perpindahan']} → surat jalan {$h['no_sj']} (borongan)");
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) { $pdo->rollBack(); }
                $gagal[] = '#' . $pid . ': ' . $e->getMessage();
            }
        }

        /* SATU pesan, bukan dua. set_flash() menimpa isinya, jadi memanggilnya
           dua kali membuat kabar keberhasilan hilang tepat ketika ia paling
           dibutuhkan — yaitu saat sebagian gagal dan orang perlu tahu mana
           yang sudah terlanjur sah. */
        $pesan = '';
        if ($sukses) {
            $pesan = "$sukses dokumen disetujui sekaligus, surat jalan terbit "
                   . 'untuk masing-masing.';
        }
        if ($gagal) {
            $pesan .= ($pesan ? ' ' : '')
                . count($gagal) . ' dokumen GAGAL dan stoknya tidak berubah sama sekali: '
                . implode(' · ', array_slice($gagal, 0, 3))
                . (count($gagal) > 3 ? ' · dan ' . (count($gagal) - 3) . ' lainnya' : '')
                . '. Yang gagal masih menunggu di antrian.';
        }
        set_flash($gagal ? 'err' : 'ok', $pesan);
        header('Location: persetujuan.php'); exit;
    }

    if ($aksi === 'setujui') {
        try {
            $pdo->beginTransaction();

            /* Seluruh isi pengesahan — mengunci dokumen, menerapkan stok,
               menerbitkan nomor surat jalan — ada di sahkan_perpindahan()
               di _lib.php, dipakai bersama jalur mutasi rak yang sah
               langsung tanpa persetujuan. Ditulis dua kali, salah satunya
               cepat atau lambat akan lupa menerapkan stok. */
            $hasil = sahkan_perpindahan($pdo, $ppId, (int) $u['id']);
            $noSJ = $hasil['no_sj'];

            $pdo->commit();

            catat_aktivitas($pdo, 'approve', 'perpindahan', $ppId,
                "Setujui {$hasil['no_perpindahan']} → surat jalan $noSJ (status {$hasil['status']})");
            set_flash('ok', $hasil['ada_penerima']
                ? "Disetujui. Surat jalan $noSJ terbit dan barang berstatus dalam perjalanan. "
                . "Stok baru berpindah setelah pihak tujuan memverifikasi fisiknya di menu Penerimaan."
                : "Disetujui. Stok sudah diperbarui dan surat jalan $noSJ siap dicetak.");

        } catch (PDOException $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();

            // Deadlock / lock-wait hanya berarti dokumen lain sedang diproses
            // pada barang yang sama. Stok tidak berubah sama sekali — petugas
            // cukup menekan Setujui sekali lagi.
            if (str_contains($e->getMessage(), 'Deadlock')
                || str_contains($e->getMessage(), 'Lock wait timeout')) {
                error_log('[AMANAH] deadlock saat setujui perpindahan #' . $ppId . ': ' . $e->getMessage());
                set_flash('err', 'Dokumen lain sedang memproses barang yang sama pada saat bersamaan. '
                               . 'Stok tidak berubah — silakan tekan Setujui sekali lagi.');
            } else {
                set_flash('err', pesan_error_aman($e, 'setujui perpindahan'));
            }

        } catch (RuntimeException $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            // RuntimeException = pesan yang memang ditujukan untuk pengguna
            // (mis. stok tidak cukup), jadi ditampilkan apa adanya.
            set_flash('err', pesan_runtime($e, 'persetujuan'));

        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            set_flash('err', pesan_error_aman($e, 'setujui perpindahan'));
        }
        header('Location: persetujuan.php?id=' . $ppId); exit;
    }
}

// ---------------------------------------------------------------- DATA
$dok = null; $detail = [];
if ($lihatId > 0) {
    /* Dokumen hanya terbaca bila menyentuh lingkup akun ini.
     *
     * Dulu syaratnya cuma `WHERE p.id = ?`. Halaman ini memang hanya bisa
     * DISETUJUI Super Admin dan Pimpinan, tapi MEMBACANYA terbuka untuk
     * semua peran internal — sehingga petugas Gudang A cukup menebak angka
     * di ?id= untuk melihat isi dokumen Gudang B, lengkap sampai daftar
     * barang, harga, dan nomor batch-nya.
     *
     * syarat_perpindahan() dipakai supaya aturannya sama persis dengan
     * halaman Surat Jalan — dokumen dianggap milik lingkup bila ASAL atau
     * TUJUAN-nya menyentuhnya. Untuk Super Admin dan Pimpinan fungsi ini
     * mengembalikan '1=1', jadi pengawasan lintas gudang tidak terganggu. */
    $st = $pdo->prepare(
        "SELECT p.*, u.nama AS pembuat, ua.nama AS penyetuju
         FROM perpindahan p
         LEFT JOIN users u  ON u.id  = p.user_id
         LEFT JOIN users ua ON ua.id = p.approved_by
         WHERE p.id = ?
           AND (" . syarat_perpindahan($pdo, 'p')
             . " OR " . syarat_persetujuan_saya($pdo, 'p') . ")"
    );
    $st->execute([$lihatId]);
    $dok = $st->fetch() ?: null;

    if (!$dok) {
        set_flash('err', 'Dokumen itu tidak ada, atau bukan untuk gudang Anda.');
    }

    if ($dok) {
        $stD = $pdo->prepare(
            "SELECT d.*, b.nama AS nama_barang, b.kode AS kode_barang, b.satuan
             FROM perpindahan_detail d
             JOIN barang b ON b.id = d.barang_id
             WHERE d.perpindahan_id = ? ORDER BY d.id"
        );
        $stD->execute([$lihatId]);
        $detail = $stD->fetchAll();
    }
}

// Antrian menunggu persetujuan — bisa dicari nomor/pihaknya, dibatasi 100.
$qAntrian = trim((string) ($_GET['q'] ?? ''));
$syaratAntrian = syarat_cari($pdo, $qAntrian, ['p.no_perpindahan', 'p.asal_teks', 'p.tujuan_teks', 'p.penerima', 'p.catatan']);
// Antrian menunggu persetujuan.
$antrian = [];
try {
    $antrian = $pdo->query(
        "SELECT p.*, u.nama AS pembuat,
                (SELECT COALESCE(SUM(d.qty),0) FROM perpindahan_detail d WHERE d.perpindahan_id = p.id) AS total_qty,
                (SELECT COUNT(*)               FROM perpindahan_detail d WHERE d.perpindahan_id = p.id) AS jml_item
         FROM perpindahan p
         LEFT JOIN users u ON u.id = p.user_id
         WHERE p.status = 'menunggu_persetujuan'
           AND (" . syarat_perpindahan($pdo, 'p')
             . " OR " . syarat_persetujuan_saya($pdo, 'p') . ")
           AND $syaratAntrian
         ORDER BY p.tanggal ASC LIMIT 100"
    )->fetchAll();

    /* Rincian tiap dokumen di antrian, diambil sekali lalu dikelompokkan.
       Halaman ?id= memang sudah menampilkannya lengkap, tapi menyetujui
       berarti membandingkan beberapa dokumen berturut-turut — dan kalau
       setiap kali harus masuk-keluar halaman, urutan antrian yang sedang
       ditelusuri hilang setiap kali. Di sini rinciannya cukup untuk
       memutuskan; halaman penuhnya tetap ada untuk menyetujui. */
    if ($antrian) {
        $ids = implode(',', array_map(function ($a) { return (int) $a['id']; }, $antrian));
        $rinciAntri = kelompokkan($pdo->query(
            "SELECT d.perpindahan_id, d.qty, d.nomor_batch, d.tanggal_expired, d.kondisi,
                    d.keterangan, b.nama AS nama_barang, b.kode AS kode_barang, b.satuan
             FROM perpindahan_detail d
             JOIN barang b ON b.id = d.barang_id
             WHERE d.perpindahan_id IN ($ids)
             ORDER BY d.id")->fetchAll(), 'perpindahan_id');
    }
} catch (Throwable $e) { error_log('[AMANAH] ' . $e->getMessage()); }
$rinciAntri = $rinciAntri ?? [];

$PAGE_TITLE = 'Persetujuan'; $ACTIVE = 'persetujuan';
require __DIR__ . '/_header.php';
?>
<div class="page-head">
  <div><h1>Persetujuan Perpindahan</h1>
    <p>Stok hanya berubah setelah dokumen disetujui di halaman ini</p></div>
  <a class="btn btn-ghost" href="perpindahan.php">Semua Perpindahan</a>
</div>

<?php /* Manager gudang bukan penyetuju umum, tapi ia berwenang atas satu
         jenis: penyaluran langsung dari mitra. Peringatan di bawah karena
         itu hanya ditujukan kepada yang benar-benar tidak berwenang atas
         apa pun di antrian ini. */
      $adaYangBisaDisetujui = false;
      foreach ($antrian as $a) {
          if (boleh_setujui_dokumen($pdo, $a)) { $adaYangBisaDisetujui = true; break; }
      } ?>
<?php if (!$canApprove && !$adaYangBisaDisetujui): ?>
  <div class="flash flash-err">
    Anda dapat melihat dokumen, tetapi tidak berwenang menyetujui atau menolak.
    <b>Super Admin</b> dan <b>Pimpinan</b> menyetujui semua jenis; <b>Manager gudang</b>
    menyetujui penyaluran langsung dari mitra ke penerima amanah.
  </div>
<?php elseif (!$canApprove): ?>
  <div class="flash flash-ok">
    Sebagai Manager, Anda berwenang menyetujui <b>penyaluran langsung dari mitra ke penerima
    amanah</b>. Jenis dokumen lain tetap menunggu Super Admin atau Pimpinan.
  </div>
<?php endif; ?>

<?php if ($dok): $bolehDok = boleh_setujui_dokumen($pdo, $dok); ?>
  <?php
    [$jLbl, $jIco, $jCls] = label_jenis($dok['jenis']);
    [$sLbl, $sCls]        = label_status($dok['status']);
    $asal   = nama_lokasi($pdo, $dok['asal_tipe'],   $dok['asal_id']   ? (int) $dok['asal_id']   : null, $dok['asal_teks']);
    $tujuan = nama_lokasi($pdo, $dok['tujuan_tipe'], $dok['tujuan_id'] ? (int) $dok['tujuan_id'] : null, $dok['tujuan_teks']);
    $totalQty = array_sum(array_map(fn($d) => (int) $d['qty'], $detail));
  ?>
  <div class="card mb16">
    <div style="display:flex;justify-content:space-between;align-items:flex-start;flex-wrap:wrap;gap:12px;margin-bottom:16px">
      <div>
        <h3 style="font-size:18px"><?= e($dok['no_perpindahan']) ?></h3>
        <div style="margin-top:6px;display:flex;gap:8px;flex-wrap:wrap">
          <span class="tag <?= $jCls ?>"><svg aria-hidden="true"><use href="#<?= e($jIco) ?>"></use></svg> <?= e($jLbl) ?></span>
          <span class="tag <?= $sCls ?>"><?= $sLbl ?></span>
          <?php if ($dok['no_surat_jalan']): ?>
            <span class="tag t-blue">Surat Jalan: <?= e($dok['no_surat_jalan']) ?></span>
          <?php endif; ?>
        </div>
      </div>
      <div style="display:flex;gap:8px;flex-wrap:wrap">
        <?php if (in_array($dok['status'], ['disetujui','dikirim','diterima','selesai'], true)): ?>
          <a class="btn btn-primary" href="surat_jalan.php?id=<?= (int) $dok['id'] ?>" target="_blank">🖨️ Cetak Surat Jalan</a>
        <?php endif; ?>
        <?php /* Label kardus tersedia begitu dokumen tersimpan (sebelum disetujui pun
                 berlabel tanda air) supaya kardusnya bisa ditempeli saat dikemas. */ ?>
        <?php if ($dok['status'] !== 'dibatalkan' && kardus_di_dokumen($pdo, (int) $dok['id'])): ?>
          <a class="btn btn-ghost" href="kardus_label.php?pp=<?= (int) $dok['id'] ?>" target="_blank" rel="noopener">🏷️ Label Kardus</a>
        <?php endif; ?>
        <?php if ($dok['status'] === 'draft' && boleh_edit()): ?>
          <form method="post"><input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="aksi" value="kirim"><input type="hidden" name="id" value="<?= (int) $dok['id'] ?>">
            <button class="btn btn-primary"><svg aria-hidden="true"><use href="#i-kirim"></use></svg> Kirim untuk Persetujuan</button></form>
          <form method="post" data-konfirm="Hapus draft <?= e($dok['no_perpindahan']) ?>?"
                data-akibat="Draft beserta rinciannya dihapus. Stok tidak tersentuh.">
            <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="aksi" value="hapus_draft"><input type="hidden" name="id" value="<?= (int) $dok['id'] ?>">
            <button class="btn btn-red"><svg aria-hidden="true"><use href="#i-hapus"></use></svg> Hapus Draft</button></form>
        <?php endif; ?>
        <?php if (in_array($dok['status'], ['draft', 'menunggu_persetujuan', 'dikirim'], true)
                  && (boleh_edit() || boleh_setujui_dokumen($pdo, $dok))): ?>
          <details class="batal-dok">
            <summary class="btn btn-ghost"><svg aria-hidden="true"><use href="#i-tolak"></use></svg> Batalkan Dokumen</summary>
            <form method="post" class="batal-form">
              <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
              <input type="hidden" name="aksi" value="batal"><input type="hidden" name="id" value="<?= (int) $dok['id'] ?>">
              <label>Alasan pembatalan <span class="wajib">wajib</span>
                <input type="text" name="alasan" required maxlength="240"
                       placeholder="mis. salah tujuan, barang tidak jadi dikirim"></label>
              <small class="mut">Stok tidak tersentuh<?= $dok['status'] === 'dikirim' ? ' — surat jalan ' . e($dok['no_surat_jalan'] ?: '') . ' tidak berlaku lagi' : '' ?>. Jejak dokumen tetap tersimpan.</small>
              <button class="btn btn-red">Ya, batalkan</button>
            </form>
          </details>
        <?php endif; ?>
      </div>
    </div>
    <style>
    .batal-dok summary{ list-style:none; cursor:pointer } .batal-dok summary::-webkit-details-marker{ display:none }
    .batal-form{ margin-top:10px; padding:12px; border:1px solid var(--border); border-radius:10px;
      display:flex; flex-direction:column; gap:8px; min-width:280px; background:var(--surface-2) }
    .wajib{ font-size:11px; color:var(--red); font-weight:600 }
    </style>

    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:14px;
                padding:14px;background:var(--surface-2);border-radius:11px;margin-bottom:16px">
      <div><div class="label-mini">Dari</div>
        <b><?= e($asal) ?></b></div>
      <div><div class="label-mini">Ke</div>
        <b><?= e($tujuan) ?></b></div>
      <div><div class="label-mini">Tanggal</div>
        <b><?= e(tgl_jam_id($dok['tanggal'])) ?></b></div>
      <div><div class="label-mini">Dibuat oleh</div>
        <b><?= e($dok['pembuat'] ?: '—') ?></b></div>
      <?php if ($dok['no_resi']): ?>
        <div><div class="label-mini">No. Resi</div>
          <b><code><?= e($dok['no_resi']) ?></code></b>
          <?= $dok['ekspedisi'] ? '<br><small class="mut">' . e($dok['ekspedisi']) . '</small>' : '' ?></div>
      <?php endif; ?>
      <?php if ($dok['penerima']): ?>
        <div><div class="label-mini">Penerima</div>
          <b><?= e($dok['penerima']) ?></b></div>
      <?php endif; ?>
      <?php if ($dok['untuk_program']): ?>
        <div><div class="label-mini">Untuk Program</div>
          <b><?= e($dok['untuk_program']) ?></b></div>
      <?php endif; ?>
      <?php if ($dok['approved_at']): ?>
        <div><div class="label-mini">Diproses</div>
          <b><?= e($dok['penyetuju'] ?: '—') ?></b><br>
          <small class="mut"><?= e(tgl_jam_id($dok['approved_at'])) ?></small></div>
      <?php endif; ?>
    </div>

    <?php if ($dok['status'] === 'ditolak' && $dok['alasan_tolak']): ?>
      <div class="flash flash-err">🚫 <b>Ditolak:</b> <?= e($dok['alasan_tolak']) ?></div>
    <?php endif; ?>

    <?php if ($dok['catatan']): ?>
      <p style="color:var(--muted);font-size:13px;margin-bottom:14px">📝 <?= e($dok['catatan']) ?></p>
    <?php endif; ?>

    <div class="scrollx"><table class="tbl-kartu">
      <thead><tr><th>#</th><th>Barang</th><th>Batch</th><th>Expired</th><th>Jumlah</th>
        <?php if ($dok['jenis'] === 'penerimaan'): ?><th>Harga</th><th>Subtotal</th><?php endif; ?>
        <th>Keterangan</th></tr></thead>
      <tbody>
      <?php foreach ($detail as $i => $d): ?>
        <tr>
          <td data-label="#"><?= $i + 1 ?></td>
          <td data-label="Barang"><b><?= e($d['nama_barang']) ?></b><br><small class="mut"><?= e($d['kode_barang']) ?></small></td>
          <td data-label="Batch"><?= $d['nomor_batch'] ? '<code>' . e($d['nomor_batch']) . '</code>' : '—' ?></td>
          <td data-label="Expired"><?= e(tgl_id($d['tanggal_expired'])) ?></td>
          <td data-label="Jumlah"><b><?= angka($d['qty']) ?></b> <?= e($d['satuan']) ?></td>
          <?php if ($dok['jenis'] === 'penerimaan'): ?>
            <td data-label="Harga"><?= rupiah($d['harga']) ?></td>
            <td data-label="Subtotal"><?= rupiah($d['subtotal']) ?></td>
          <?php endif; ?>
          <td data-label="Keterangan"><?= e($d['keterangan'] ?: '—') ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
      <tfoot><tr style="background:var(--surface-2);font-weight:700">
        <td colspan="4">Total</td>
        <td><?= angka($totalQty) ?> unit</td>
        <?php if ($dok['jenis'] === 'penerimaan'): ?>
          <td></td><td><?= rupiah(array_sum(array_map(fn($d) => (float) $d['subtotal'], $detail))) ?></td>
        <?php endif; ?>
        <td></td>
      </tr></tfoot>
    </table></div>

    <?php /* $bolehDok, bukan $canApprove: tombol Setujui/Tolak muncul bila
         orang ini berwenang atas DOKUMEN INI. */ ?>
<?php if ($dok['status'] === 'menunggu_persetujuan' && $bolehDok): ?>
      <div style="display:flex;gap:10px;margin-top:18px;flex-wrap:wrap;align-items:center;
                  padding-top:16px;border-top:1px solid var(--border)">
        <form method="post" data-konfirm="Setujui dokumen ini?" data-akibat="Stok akan langsung berubah dan surat jalan diterbitkan." data-tombol="Ya, setujui" data-warna="green">
          <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
          <input type="hidden" name="aksi" value="setujui">
          <input type="hidden" name="id" value="<?= (int) $dok['id'] ?>">
          <button class="btn btn-primary"><svg aria-hidden="true"><use href="#i-cek-bulat"></use></svg> Setujui & Terbitkan Surat Jalan</button>
        </form>
        <button class="btn btn-red" onclick="document.getElementById('formTolak').style.display='block';this.style.display='none'">
          <svg aria-hidden="true"><use href="#i-tolak"></use></svg> Tolak
        </button>
      </div>

      <form method="post" id="formTolak" style="display:none;margin-top:14px">
        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="aksi" value="tolak">
        <input type="hidden" name="id" value="<?= (int) $dok['id'] ?>">
        <div class="field"><label>Alasan Penolakan <span class="req">*</span></label>
          <textarea name="alasan" rows="2" required
                    placeholder="cth: Jumlah tidak sesuai fisik, tunggu konfirmasi supplier"></textarea></div>
        <button class="btn btn-red">Kirim Penolakan</button>
      </form>
    <?php endif; ?>
  </div>
<?php endif; ?>

<div class="card">
  <h3>Antrian Menunggu Persetujuan</h3>
  <div class="sub"><?= count($antrian) ?> dokumen · diurutkan dari yang paling lama menunggu</div>

  <?php /* ====================================================================
     KELOMPOK PER TUJUAN

     Penjualan daring menerbitkan satu dokumen kirim per pesanan. Satu toko
     yang ramai menghasilkan puluhan dokumen dalam sehari — seluruhnya dari
     rak yang sama ke toko yang sama, masing-masing satu jenis barang.
     Menyetujuinya satu per satu berarti puluhan kali membuka, membaca,
     menekan, kembali.

     Yang dikelompokkan PERSETUJUANNYA, bukan dokumennya: tiap dokumen tetap
     berdiri sendiri karena masing-masing mewakili satu pesanan pembeli
     dengan surat jalannya sendiri. Tabel di bawah tetap utuh untuk yang
     memang perlu ditinjau satu per satu.

     Kelompok berisi SATU dokumen tidak ditampilkan — tombol "setujui semua"
     untuk satu dokumen hanya mengulang tombol Tinjau di barisnya.
     ==================================================================== */
  $kelompok = [];
  foreach ($antrian as $a) {
      $kunci = $a['jenis'] . '|' . $a['tujuan_tipe'] . '|' . (int) $a['tujuan_id'];
      if (!isset($kelompok[$kunci])) {
          $kelompok[$kunci] = [
              'jenis'   => $a['jenis'],
              'tipe'    => $a['tujuan_tipe'],
              'tujuan'  => (int) $a['tujuan_id'],
              'nama'    => nama_lokasi($pdo, $a['tujuan_tipe'],
                               $a['tujuan_id'] ? (int) $a['tujuan_id'] : null, $a['tujuan_teks']),
              'n'       => 0, 'qty' => 0,
              'boleh'   => boleh_setujui_dokumen($pdo, $a),
          ];
      }
      $kelompok[$kunci]['n']++;
      $kelompok[$kunci]['qty'] += (int) $a['total_qty'];
  }
  $kelompok = array_filter($kelompok, function ($k) { return $k['n'] > 1 && $k['boleh']; });
  uasort($kelompok, function ($a, $b) { return $b['n'] <=> $a['n']; });
  ?>

  <?php if ($kelompok): ?>
    <div class="kelompok-setuju">
      <div class="kelompok-judul">
        Bisa disetujui sekaligus — <?= count($kelompok) ?> kelompok tujuan yang sama
      </div>
      <?php foreach ($kelompok as $k): [$jLbl, $jIco, $jCls] = label_jenis($k['jenis']); ?>
        <form method="post" class="kelompok-baris"
              data-konfirm="Setujui <?= (int) $k['n'] ?> dokumen sekaligus?"
              data-obj="<?= e($jLbl) ?> → <?= e($k['nama']) ?>"
              data-info="<?= (int) $k['n'] ?> dokumen · <?= angka($k['qty']) ?> unit"
              data-akibat="Tiap dokumen disahkan sendiri-sendiri dan menerbitkan surat jalannya masing-masing. Bila ada yang gagal — misalnya stoknya keburu habis — hanya yang itu yang batal; sisanya tetap sah."
              data-tombol="Ya, setujui semua" data-warna="green">
          <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
          <input type="hidden" name="aksi" value="setujui_kelompok">
          <input type="hidden" name="g_jenis" value="<?= e($k['jenis']) ?>">
          <input type="hidden" name="g_tipe" value="<?= e($k['tipe']) ?>">
          <input type="hidden" name="g_tujuan" value="<?= (int) $k['tujuan'] ?>">
          <div class="kelompok-tx">
            <span class="tag <?= $jCls ?>"><svg aria-hidden="true"><use href="#<?= e($jIco) ?>"></use></svg> <?= e($jLbl) ?></span>
            <b><?= e($k['nama']) ?></b>
            <small class="mut2"><?= (int) $k['n'] ?> dokumen · <?= angka($k['qty']) ?> unit</small>
          </div>
          <button class="btn btn-green btn-sm" type="submit">
            Setujui <?= (int) $k['n'] ?> dokumen
          </button>
        </form>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>

  <?php cari_bar($qAntrian, 'Cari nomor dokumen, pihak, atau catatan…'); ?>
  <div class="scrollx"><table class="tbl-kartu">
    <thead><tr><th>No. Dokumen</th><th>Jenis</th><th>Diajukan</th><th>Dari → Ke</th><th>Item</th><th>Pembuat</th><th></th></tr></thead>
    <tbody>
    <?php if (!$antrian): ?>
      <tr><td colspan="7" class="empty">Tidak ada dokumen yang menunggu persetujuan. 🎉</td></tr>
    <?php else: foreach ($antrian as $a):
        [$jLbl, $jIco, $jCls] = label_jenis($a['jenis']); ?>
      <tr>
        <td data-label="No. Dokumen"><code><?= e($a['no_perpindahan']) ?></code></td>
        <td data-label="Jenis"><span class="tag <?= $jCls ?>"><svg aria-hidden="true"><use href="#<?= e($jIco) ?>"></use></svg> <?= e($jLbl) ?></span></td>
        <td data-label="Diajukan"><?= e(tgl_jam_id($a['tanggal'])) ?></td>
        <td data-label="Dari → Ke" style="font-size:12.5px">
          <?= e(nama_lokasi($pdo, $a['asal_tipe'], $a['asal_id'] ? (int) $a['asal_id'] : null, $a['asal_teks'])) ?>
          → <?= e(nama_lokasi($pdo, $a['tujuan_tipe'], $a['tujuan_id'] ? (int) $a['tujuan_id'] : null, $a['tujuan_teks'])) ?>
        </td>
        <td data-label="Item"><b><?= angka($a['total_qty']) ?></b> unit<br>
          <?= tombol_rincian('rantri' . (int) $a['id'], (int) $a['jml_item']) ?></td>
        <td data-label="Pembuat"><?= e($a['pembuat'] ?: '—') ?></td>
        <?php $bolehBaris = boleh_setujui_dokumen($pdo, $a); ?>
        <td><a class="btn <?= $bolehBaris ? 'btn-green' : 'btn-ghost' ?> btn-sm"
               href="persetujuan.php?id=<?= (int) $a['id'] ?>"><?= $bolehBaris ? 'Tinjau' : 'Lihat' ?></a></td>
      </tr>
      <?php $isi = $rinciAntri[(int) $a['id']] ?? []; ?>
      <tr class="baris-rinci" id="rantri<?= (int) $a['id'] ?>" hidden>
        <td colspan="7">
          <div class="rinci-isi">
            <?php if (!$isi): ?>
              <div class="mut" style="font-size:12.5px">Dokumen ini tidak punya rincian barang.</div>
            <?php else: ?>
              <div class="judul">Isi dokumen <?= e($a['no_perpindahan']) ?></div>
              <table>
                <thead><tr>
                  <th>Barang</th><th>Batch</th><th>Kondisi</th><th>Kedaluwarsa</th>
                  <th class="num">Jumlah</th><th>Keterangan</th>
                </tr></thead>
                <tbody>
                <?php foreach ($isi as $d): [$lblK, $wrnK] = label_kondisi($d['kondisi'] ?? 'baru'); ?>
                  <tr>
                    <td><b><?= e($d['nama_barang']) ?></b><br><small class="mut2"><?= e($d['kode_barang']) ?></small></td>
                    <td><?= $d['nomor_batch'] ? '<code>' . e($d['nomor_batch']) . '</code>' : '—' ?></td>
                    <td><span class="tag <?= $wrnK ?>"><?= e($lblK) ?></span></td>
                    <td><?= e(tgl_id($d['tanggal_expired'])) ?></td>
                    <td class="num"><b><?= angka($d['qty']) ?></b> <?= e($d['satuan']) ?></td>
                    <td><?= e($d['keterangan'] ?: '—') ?></td>
                  </tr>
                <?php endforeach; ?>
                </tbody>
              </table>
            <?php endif; ?>
          </div>
        </td>
      </tr>
    <?php endforeach; endif; ?>
    </tbody>
  </table></div>
</div>
<?php require __DIR__ . '/_footer.php'; ?>
