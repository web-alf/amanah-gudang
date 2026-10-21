<?php
/**
 * AMANAH — Mengubah dokumen perpindahan yang sudah ada (Super Admin)
 *
 * Sampai 6 Okt 2026 surat jalan tidak bisa diubah sama sekali: yang
 * keliru hanya bisa dibatalkan lalu dibuat ulang — dan yang sudah selesai
 * tidak bisa apa-apa. Sekarang Super Admin bisa mengubah jumlah, barang,
 * lokasi asal/tujuan, transit, dan data pengirimannya dari
 * perpindahan_ubah.php, dan SEMUA yang bersumber dari dokumen itu ikut
 * menyesuaikan.
 *
 * APA YANG IKUT BERUBAH, menurut status dokumennya
 *   draft / menunggu_persetujuan / dikirim
 *       Stok belum tersentuh (lihat batalkan_perpindahan). Yang diubah
 *       hanya dokumennya. Untuk 'dikirim', pratinjau batch FEFO dihitung
 *       ulang untuk baris yang batchnya kosong, supaya surat jalan yang
 *       dicetak ulang menyebut batch yang benar.
 *   selesai
 *       Stok SUDAH berpindah. Perubahan jumlah/barang/lokasi dikerjakan
 *       dengan MEMBALIKKAN stok persis seperti saat diterapkan (jejaknya di
 *       tabel perpindahan_mutasi), memperbarui dokumennya, lalu menerapkan
 *       ulang — di dalam satu transaksi. Bila barangnya sudah keluar lagi
 *       dari lokasi tujuan sehingga tidak bisa dibalikkan, seluruh
 *       perubahan ditolak dengan alasannya; tidak ada separuh jalan.
 *       Dokumen yang selesai SEBELUM pencatat mutasi dipasang tidak punya
 *       jejak: untuk itu hanya data pengiriman (resi, penerima, catatan…)
 *       yang bisa diubah.
 *   ditolak / dibatalkan
 *       Tidak diubah — dokumen yang sudah ditutup tetap seperti adanya.
 *
 * Dimuat dari ujung _lib.php.
 */

/** Tabel jejak mutasi dibuat bila belum ada — hanya di luar transaksi (lihat pastikan_tabel_titik). */
function pastikan_tabel_mutasi(PDO $pdo): bool {
    static $ada = null;
    if ($ada !== null) { return $ada; }
    try {
        if ($pdo->inTransaction()) {
            return (bool) $pdo->query(
                "SELECT 1 FROM information_schema.tables
                  WHERE table_schema = DATABASE() AND table_name = 'perpindahan_mutasi'")->fetchColumn();
        }
        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS perpindahan_mutasi (
               id               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
               perpindahan_id   BIGINT UNSIGNED NOT NULL,
               detail_id        BIGINT UNSIGNED NULL,
               barang_id        BIGINT UNSIGNED NOT NULL,
               batch_asal_id    BIGINT UNSIGNED NULL,
               batch_tujuan_id  BIGINT UNSIGNED NULL,
               qty              INT NOT NULL,
               created_at       TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
               PRIMARY KEY (id),
               KEY idx_mutasi_pp (perpindahan_id),
               CONSTRAINT fk_mutasi_pp FOREIGN KEY (perpindahan_id)
                 REFERENCES perpindahan (id) ON DELETE CASCADE
             ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $ada = true;
    } catch (Throwable $e) {
        error_log('[AMANAH] perpindahan_mutasi: ' . $e->getMessage());
        $ada = false;
    }
    return $ada;
}

/** Jejak satu potongan/penambahan batch oleh terapkan_perpindahan(). Gagal mencatat tidak menggagalkan dokumennya. */
function catat_mutasi_perpindahan(PDO $pdo, int $ppId, ?int $detailId, int $barangId,
                                  ?int $batchAsal, ?int $batchTujuan, int $qty): void {
    if ($qty <= 0 || $ppId <= 0) { return; }
    try {
        $pdo->prepare(
            "INSERT INTO perpindahan_mutasi (perpindahan_id, detail_id, barang_id, batch_asal_id, batch_tujuan_id, qty)
             VALUES (?,?,?,?,?,?)")->execute([$ppId, $detailId, $barangId, $batchAsal, $batchTujuan, $qty]);
    } catch (Throwable $e) {
        error_log('[AMANAH] mutasi perpindahan: ' . $e->getMessage());
    }
}

/** Apakah dokumen ini punya jejak mutasi (jadi stoknya bisa dibalikkan). */
function ada_jejak_mutasi(PDO $pdo, int $ppId): bool {
    try {
        $st = $pdo->prepare("SELECT 1 FROM perpindahan_mutasi WHERE perpindahan_id = ? LIMIT 1");
        $st->execute([$ppId]);
        return (bool) $st->fetchColumn();
    } catch (Throwable $e) { return false; }
}

/**
 * Balikkan seluruh mutasi stok sebuah dokumen — kebalikan persis dari
 * terapkan_perpindahan(). Dipanggil DI DALAM transaksi.
 *
 * Batch tujuan dikurangi (qty_awal ikut turun, karena penambahannya memang
 * bukan penerimaan sungguhan), batch asal dikembalikan. Bila batch tujuan
 * sudah tidak punya cukup stok — barangnya sudah dijual, dipindahkan, atau
 * disalurkan — pembalikan DITOLAK: mengurangi stok yang tidak ada hanya
 * memindahkan kesalahannya ke tempat lain.
 */
function balikkan_mutasi_perpindahan(PDO $pdo, int $ppId): int {
    $st = $pdo->prepare("SELECT * FROM perpindahan_mutasi WHERE perpindahan_id = ? ORDER BY id DESC FOR UPDATE");
    $st->execute([$ppId]);
    $rows = $st->fetchAll();
    if (!$rows) { return 0; }
    kunci_stok($pdo, array_column($rows, 'barang_id'));

    $ambil = $pdo->prepare("SELECT id, nomor_batch, qty_tersisa FROM batch_barang WHERE id = ? FOR UPDATE");
    foreach ($rows as $m) {
        $qty = (int) $m['qty'];
        if ($m['batch_tujuan_id']) {
            $ambil->execute([(int) $m['batch_tujuan_id']]);
            $bb = $ambil->fetch();
            if (!$bb) { throw new RuntimeException('Batch tujuan dari dokumen ini sudah tidak ada, jadi stoknya tidak bisa dibalikkan.'); }
            if ((int) $bb['qty_tersisa'] < $qty) {
                throw new RuntimeException(
                    "Batch {$bb['nomor_batch']} di lokasi tujuan tinggal " . angka($bb['qty_tersisa']) . ' dari ' . angka($qty)
                  . ' yang dikirim dokumen ini — sebagian sudah keluar lagi, jadi jumlah/lokasinya tidak bisa diubah. '
                  . 'Catat selisihnya lewat penyesuaian stok atau dokumen baru.');
            }
            $pdo->prepare("UPDATE batch_barang SET qty_tersisa = qty_tersisa - ?, qty_awal = GREATEST(0, qty_awal - ?) WHERE id = ?")
                ->execute([$qty, $qty, (int) $bb['id']]);
        }
        if ($m['batch_asal_id']) {
            $ambil->execute([(int) $m['batch_asal_id']]);
            $bb = $ambil->fetch();
            if (!$bb) { throw new RuntimeException('Batch asal dari dokumen ini sudah tidak ada, jadi stoknya tidak bisa dibalikkan.'); }
            periksa_qty_wajar((int) $bb['qty_tersisa'] + $qty, 'Stok batch asal');
            $pdo->prepare("UPDATE batch_barang SET qty_tersisa = qty_tersisa + ? WHERE id = ?")->execute([$qty, (int) $bb['id']]);
        }
    }
    $pdo->prepare("DELETE FROM perpindahan_mutasi WHERE perpindahan_id = ?")->execute([$ppId]);
    foreach (array_unique(array_map('intval', array_column($rows, 'barang_id'))) as $bid) { recalc_stok($pdo, $bid); }
    return count($rows);
}

/** Lokasi dengan tipe & id itu ada dan masih aktif? */
function lokasi_perpindahan_ada(PDO $pdo, string $tipe, int $id): bool {
    $peta = [
        'gudang'   => "SELECT 1 FROM gudang WHERE id = ? AND deleted_at IS NULL AND is_active = 1",
        'rak'      => "SELECT 1 FROM lokasi_rak lr JOIN gudang g ON g.id = lr.gudang_id WHERE lr.id = ? AND lr.deleted_at IS NULL AND g.deleted_at IS NULL",
        'toko'     => "SELECT 1 FROM toko WHERE id = ? AND deleted_at IS NULL",
        'supplier' => "SELECT 1 FROM supplier WHERE id = ? AND deleted_at IS NULL",
        'penerima' => "SELECT 1 FROM penerima WHERE id = ?",
    ];
    if (!isset($peta[$tipe]) || $id <= 0) { return false; }
    $st = $pdo->prepare($peta[$tipe]);
    $st->execute([$id]);
    return (bool) $st->fetchColumn();
}

/**
 * Ubah dokumen perpindahan. Dipanggil DI DALAM transaksi.
 *
 * @param array $k      kepala: asal_tipe, asal_id, asal_teks, tujuan_tipe, tujuan_id, tujuan_teks,
 *                      tanggal, no_resi, ekspedisi, kendaraan, no_polisi, pengirim, penerima,
 *                      untuk_program, catatan
 * @param array $baris  [['id'=>detail_id|0, 'barang_id', 'qty', 'nomor_batch', 'tanggal_expired', 'keterangan'], …]
 *                      qty 0 = baris dihapus
 * @param array $transit id gudang transit, urut
 * @return array{no:string, ubah:string[], stok:bool}
 */
function ubah_perpindahan(PDO $pdo, int $ppId, array $k, array $baris, array $transit): array {
    if (!is_super()) { throw new RuntimeException('Mengubah dokumen perpindahan adalah wewenang Super Admin.'); }

    $st = $pdo->prepare("SELECT * FROM perpindahan WHERE id = ? FOR UPDATE");
    $st->execute([$ppId]);
    $pp = $st->fetch();
    if (!$pp) { throw new RuntimeException('Dokumen tidak ditemukan.'); }
    $status = (string) $pp['status'];
    if (!in_array($status, ['draft', 'menunggu_persetujuan', 'dikirim', 'selesai'], true)) {
        [$lbl] = label_status($status);
        throw new RuntimeException("Dokumen berstatus \"$lbl\" tidak bisa diubah.");
    }
    $stD = $pdo->prepare("SELECT * FROM perpindahan_detail WHERE perpindahan_id = ? ORDER BY id FOR UPDATE");
    $stD->execute([$ppId]);
    $detailLama = $stD->fetchAll();
    $jenis = (string) $pp['jenis'];

    // ---------------------------------------------------------- kepala
    $asalTipe   = (string) ($k['asal_tipe'] ?? '');
    $asalId     = (int) ($k['asal_id'] ?? 0) ?: null;
    $asalTeks   = mb_substr(trim((string) ($k['asal_teks'] ?? '')), 0, 150);
    $tujuanTipe = (string) ($k['tujuan_tipe'] ?? '');
    $tujuanId   = (int) ($k['tujuan_id'] ?? 0) ?: null;
    $tujuanTeks = mb_substr(trim((string) ($k['tujuan_teks'] ?? '')), 0, 150);

    if ($pesan = periksa_rute($jenis, $asalTipe, $tujuanTipe)) { throw new RuntimeException($pesan); }
    if ($asalTipe === 'eksternal') {
        if ($asalTeks === '') { throw new RuntimeException('Nama asal wajib diisi.'); }
        $asalId = null;
    } elseif (!$asalId || !lokasi_perpindahan_ada($pdo, $asalTipe, $asalId)) {
        throw new RuntimeException('Lokasi asal tidak ditemukan atau sudah nonaktif.');
    }
    if ($tujuanTipe === 'penerima') {
        if ($tujuanId && !lokasi_perpindahan_ada($pdo, 'penerima', $tujuanId)) { throw new RuntimeException('Penerima tidak ditemukan.'); }
        if (!$tujuanId && $tujuanTeks === '') { throw new RuntimeException('Pilih penerima, atau ketik namanya.'); }
    } elseif (!$tujuanId || !lokasi_perpindahan_ada($pdo, $tujuanTipe, $tujuanId)) {
        throw new RuntimeException('Lokasi tujuan tidak ditemukan atau sudah nonaktif.');
    }
    if ($asalTipe === $tujuanTipe && $asalId && $tujuanId && $asalId === $tujuanId) {
        throw new RuntimeException('Asal dan tujuan tidak boleh sama.');
    }
    $tglCek = tanggal_dokumen_sah((string) ($k['tanggal'] ?? ''));
    if ($tglCek['galat']) { throw new RuntimeException($tglCek['galat']); }

    $teks = function (string $kunci, int $maks) use ($k): ?string {
        $v = mb_substr(trim((string) ($k[$kunci] ?? '')), 0, $maks);
        return $v === '' ? null : $v;
    };
    $noResi = $teks('no_resi', 60) ?? (string) $pp['no_perpindahan'];   // resi kosong = nomor dokumen, seperti saat dibuat

    // ----------------------------------------------------------- baris
    $barisBaru = []; $hapusId = [];
    foreach ($baris as $b) {
        $did = (int) ($b['id'] ?? 0);
        $qty = (int) ($b['qty'] ?? 0);
        if ($qty <= 0) { if ($did > 0) { $hapusId[] = $did; } continue; }
        periksa_qty_wajar($qty);
        $bid = (int) ($b['barang_id'] ?? 0);
        $cek = $pdo->prepare("SELECT nama FROM barang WHERE id = ? AND deleted_at IS NULL");
        $cek->execute([$bid]);
        $nama = (string) $cek->fetchColumn();
        if ($nama === '') { throw new RuntimeException('Ada baris yang barangnya tidak ditemukan.'); }
        $exp = trim((string) ($b['tanggal_expired'] ?? ''));
        $barisBaru[] = [
            'id'              => $did,
            'barang_id'       => $bid,
            'nama'            => $nama,
            'qty'             => $qty,
            'nomor_batch'     => mb_substr(trim((string) ($b['nomor_batch'] ?? '')), 0, 50) ?: null,
            'tanggal_expired' => $exp !== '' ? tanggal_expired_sah($exp) : null,
            'keterangan'      => mb_substr(trim((string) ($b['keterangan'] ?? '')), 0, 255) ?: null,
        ];
    }
    if (!$barisBaru) { throw new RuntimeException('Dokumen harus punya minimal satu baris barang. Untuk membatalkan dokumen, pakai tombol Batalkan.'); }
    $transitIds = periksa_titik_transit($pdo, $transit, $jenis, $asalTipe, $asalId, $tujuanTipe, $tujuanId);

    // --------------------------------- apakah perubahan menyentuh stok?
    $lamaPeta = [];
    foreach ($detailLama as $d) { $lamaPeta[(int) $d['id']] = $d; }
    $sentuhStok = $asalTipe !== $pp['asal_tipe'] || (int) $asalId !== (int) $pp['asal_id']
               || $tujuanTipe !== $pp['tujuan_tipe'] || (int) $tujuanId !== (int) $pp['tujuan_id']
               || $hapusId || count($barisBaru) !== count($detailLama);
    foreach ($barisBaru as $b) {
        $d = $lamaPeta[$b['id']] ?? null;
        if (!$d) { $sentuhStok = true; continue; }
        $qtyLama = $status === 'selesai' && $d['qty_diterima'] !== null ? (int) $d['qty_diterima'] : (int) $d['qty'];
        if ((int) $d['barang_id'] !== $b['barang_id'] || $qtyLama !== $b['qty']
            || (string) ($d['nomor_batch'] ?? '') !== (string) ($b['nomor_batch'] ?? '')) { $sentuhStok = true; }
    }

    $ubah = [];
    if ($status === 'selesai' && $sentuhStok) {
        if (!ada_jejak_mutasi($pdo, $ppId)) {
            throw new RuntimeException(
                'Dokumen ini selesai sebelum pencatat mutasi dipasang, jadi stoknya tidak bisa dibalikkan. '
              . 'Yang bisa diubah hanya data pengiriman (resi, penerima, catatan). Untuk membetulkan jumlah '
              . 'atau lokasinya, buat dokumen baru atau penyesuaian stok.');
        }
        $n = balikkan_mutasi_perpindahan($pdo, $ppId);
        $ubah[] = "stok dibalikkan ($n mutasi)";
    }

    // --------------------------------------------------------- tulis
    $pdo->prepare(
        "UPDATE perpindahan
            SET asal_tipe = ?, asal_id = ?, asal_teks = ?, tujuan_tipe = ?, tujuan_id = ?, tujuan_teks = ?,
                tanggal = ?, no_resi = ?, ekspedisi = ?, kendaraan = ?, no_polisi = ?, pengirim = ?,
                penerima = ?, untuk_program = ?, catatan = ?, updated_at = NOW()
          WHERE id = ?")->execute([
        $asalTipe, $asalId, $asalTeks ?: null, $tujuanTipe, $tujuanId, $tujuanTeks ?: null,
        $tglCek['simpan'], $noResi, $teks('ekspedisi', 60), $teks('kendaraan', 60), $teks('no_polisi', 20),
        $teks('pengirim', 100), $teks('penerima', 100), $teks('untuk_program', 150), $teks('catatan', 255), $ppId]);

    if ($hapusId) {
        $pdo->prepare("DELETE FROM perpindahan_detail WHERE perpindahan_id = ? AND id IN (" . implode(',', $hapusId) . ")")->execute([$ppId]);
    }
    $insD = $pdo->prepare(
        "INSERT INTO perpindahan_detail (perpindahan_id, barang_id, nomor_batch, tanggal_expired, qty, qty_diterima, harga, subtotal, keterangan)
         VALUES (?,?,?,?,?,?,0,0,?)");
    $updD = $pdo->prepare(
        "UPDATE perpindahan_detail SET barang_id = ?, nomor_batch = ?, tanggal_expired = ?, qty = ?, qty_diterima = ?,
                keterangan = ?, batch_id = IF(? , batch_id, NULL)
         WHERE id = ? AND perpindahan_id = ?");
    foreach ($barisBaru as $b) {
        $terima = $status === 'selesai' ? $b['qty'] : null;
        if ($b['id'] > 0 && isset($lamaPeta[$b['id']])) {
            $d = $lamaPeta[$b['id']];
            /* Jejak "Batch: …" yang ditulis terapkan_perpindahan(). Bila stoknya akan
               diterapkan ULANG, jejak lama dibuang (yang baru ditulis sesudahnya).
               Bila tidak — hanya data pengiriman yang berubah — jejak lama DIPERTAHANKAN:
               formulir menampilkan keterangan tanpa jejak itu, jadi tanpa langkah ini
               menyimpan catatan saja akan menghapus rekaman batch yang terpakai. */
            $ket = $b['keterangan'];
            if ($ket !== null) { $ket = trim(preg_replace('/(\s*\|\s*)?Batch: .*$/u', '', $ket)) ?: null; }
            if (!($status === 'selesai' && $sentuhStok)
                && preg_match('/Batch: .*$/u', (string) ($d['keterangan'] ?? ''), $jejakBatch)) {
                $ket = mb_substr(($ket !== null ? $ket . ' | ' : '') . $jejakBatch[0], 0, 255);
            }
            /* Dokumen SELESAI punya dua angka: dikirim (qty) dan diterima
               (qty_diterima). Jumlah di formulir adalah yang DITERIMA — yang
               benar-benar berpindah. Angka dikirim tidak boleh ikut ditimpa,
               kalau tidak selisih penerimaan ("dikirim 2, diterima 1") hilang
               tanpa jejak. Dikirim hanya ikut naik bila yang diterima kini
               melebihinya, dan ikut sama bila sebelumnya memang sama. */
            $qtyKirim = $b['qty'];
            $qtyLamaEfektif = (int) $d['qty'];
            if ($status === 'selesai' && $d['qty_diterima'] !== null) {
                $qtyLamaEfektif = (int) $d['qty_diterima'];
                $qtyKirim = (int) $d['qty'] === (int) $d['qty_diterima'] ? $b['qty'] : max((int) $d['qty'], $b['qty']);
            }
            $sama = (int) $d['barang_id'] === $b['barang_id'] && $qtyLamaEfektif === $b['qty'];
            $updD->execute([$b['barang_id'], $b['nomor_batch'], $b['tanggal_expired'], $qtyKirim, $terima, $ket,
                            $sama ? 1 : 0, $b['id'], $ppId]);
            if (!$sama) { $ubah[] = "{$b['nama']}: " . angka($qtyLamaEfektif) . ' → ' . angka($b['qty']); }
        } else {
            $insD->execute([$ppId, $b['barang_id'], $b['nomor_batch'], $b['tanggal_expired'], $b['qty'], $terima, $b['keterangan']]);
            $ubah[] = "+ {$b['nama']} " . angka($b['qty']);
        }
    }
    foreach ($hapusId as $hid) {
        if (isset($lamaPeta[$hid])) {
            $nb = $pdo->prepare("SELECT nama FROM barang WHERE id = ?"); $nb->execute([(int) $lamaPeta[$hid]['barang_id']]);
            $ubah[] = '− ' . $nb->fetchColumn() . ' ' . angka($lamaPeta[$hid]['qty']);
        }
    }
    if ($asalTipe !== $pp['asal_tipe'] || (int) $asalId !== (int) $pp['asal_id'] || $asalTeks !== (string) $pp['asal_teks']) { $ubah[] = 'asal diganti'; }
    if ($tujuanTipe !== $pp['tujuan_tipe'] || (int) $tujuanId !== (int) $pp['tujuan_id'] || $tujuanTeks !== (string) $pp['tujuan_teks']) { $ubah[] = 'tujuan diganti'; }
    if ($tglCek['simpan'] !== $pp['tanggal'] && substr((string) $tglCek['simpan'], 0, 10) !== substr((string) $pp['tanggal'], 0, 10)) { $ubah[] = 'tanggal diganti'; }
    foreach (['no_resi' => 60, 'ekspedisi' => 60, 'kendaraan' => 60, 'no_polisi' => 20, 'pengirim' => 100,
              'penerima' => 100, 'untuk_program' => 150, 'catatan' => 255] as $kol => $maks) {
        $baruV = $kol === 'no_resi' ? $noResi : $teks($kol, $maks);
        if ((string) $baruV !== (string) ($pp[$kol] ?? '')) { $ubah[] = 'data pengiriman diubah'; break; }
    }

    // ------------------------------------------------------- transit
    $titikLama = titik_transit($pdo, $ppId);
    $lamaIds = array_map('intval', array_column($titikLama, 'lokasi_id'));
    if ($lamaIds !== $transitIds) {
        foreach ($titikLama as $t) {
            if (($t['tiba_at'] || $t['berangkat_at']) && !in_array((int) $t['lokasi_id'], $transitIds, true)) {
                throw new RuntimeException("Titik transit {$t['lokasi_nama']} sudah mencatat tiba/berangkat, jadi tidak bisa dihapus dari dokumen.");
            }
        }
        $pdo->prepare("DELETE FROM perpindahan_titik WHERE perpindahan_id = ? AND lokasi_id NOT IN (" . (implode(',', $transitIds) ?: '0') . ")")->execute([$ppId]);
        foreach ($transitIds as $i => $gid) {
            $ada = false;
            foreach ($titikLama as $t) { if ((int) $t['lokasi_id'] === $gid) { $ada = true; break; } }
            if ($ada) { $pdo->prepare("UPDATE perpindahan_titik SET urutan = ? WHERE perpindahan_id = ? AND lokasi_id = ?")->execute([$i + 1, $ppId, $gid]); }
            else      { $pdo->prepare("INSERT INTO perpindahan_titik (perpindahan_id, urutan, lokasi_tipe, lokasi_id) VALUES (?,?,'gudang',?)")->execute([$ppId, $i + 1, $gid]); }
        }
        $ubah[] = 'transit diubah';
    }

    // ------------------------------------------- terapkan ulang stok
    $st->execute([$ppId]); $ppBaru = $st->fetch();
    $stD->execute([$ppId]); $detailBaru = $stD->fetchAll();
    if ($status === 'selesai' && $sentuhStok) {
        terapkan_perpindahan($pdo, $ppBaru, $detailBaru);
        $ubah[] = 'stok diterapkan ulang';
    } elseif ($status === 'dikirim') {
        catat_batch_pratinjau($pdo, $ppBaru, $detailBaru);
    }

    return ['no' => (string) $pp['no_perpindahan'], 'ubah' => $ubah, 'stok' => $status === 'selesai' && $sentuhStok];
}

/**
 * GABUNGKAN beberapa DRAFT menjadi satu surat jalan (revisi 18 Okt 2026).
 *
 * Tim lapangan sering menyimpan beberapa draft untuk kiriman yang sama
 * (asal & tujuan sama) lalu kebanyakan surat jalan. Draft dengan jenis, asal,
 * dan tujuan yang SAMA bisa disatukan: seluruh barisnya — termasuk kardusnya —
 * pindah ke draft tertua, baris kembar tanpa kardus (barang + batch +
 * kedaluwarsa sama) dijumlahkan, catatan digabung, draft lain dihapus.
 * Draft belum menyentuh stok, jadi tidak ada stok yang perlu dibalik.
 *
 * Harus dipanggil di dalam transaksi. @return array{no:string, id:int, gabung:int, baris:int}
 */
function gabung_draft_perpindahan(PDO $pdo, array $ids, int $userId): array {
    $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
    sort($ids);
    if (count($ids) < 2) { throw new RuntimeException('Pilih minimal dua draft untuk digabungkan.'); }
    $in = implode(',', $ids);
    $docs = $pdo->query("SELECT * FROM perpindahan WHERE id IN ($in) ORDER BY id FOR UPDATE")->fetchAll();
    if (count($docs) !== count($ids)) { throw new RuntimeException('Sebagian draft sudah tidak ada. Muat ulang halaman.'); }
    $kunci = fn(array $p) => implode('|', [$p['jenis'], $p['asal_tipe'], (int) $p['asal_id'], trim((string) $p['asal_teks']), $p['tujuan_tipe'], (int) $p['tujuan_id'], trim((string) $p['tujuan_teks'])]);
    $acuan = $kunci($docs[0]);
    foreach ($docs as $p) {
        if ($p['status'] !== 'draft') { throw new RuntimeException("{$p['no_perpindahan']} bukan draft lagi (status: {$p['status']}) — hanya draft yang bisa digabung."); }
        if (!boleh_lihat_perpindahan($pdo, (int) $p['id'])) { throw new RuntimeException("{$p['no_perpindahan']} di luar lingkup akun Anda."); }
        if ($kunci($p) !== $acuan) {
            throw new RuntimeException("{$p['no_perpindahan']} berbeda jenis/asal/tujuan dengan {$docs[0]['no_perpindahan']} — hanya draft dengan jenis, asal, dan tujuan yang sama yang bisa digabung.");
        }
    }
    $target = $docs[0];
    $lain = array_slice($ids, 1);
    $inL = implode(',', $lain);
    $pdo->exec("UPDATE perpindahan_detail SET perpindahan_id = " . (int) $target['id'] . " WHERE perpindahan_id IN ($inL)");
    if (kardus_aktif($pdo)) {
        foreach ($pdo->query("SELECT id FROM kardus WHERE perpindahan_id IN ($inL)")->fetchAll(PDO::FETCH_COLUMN) as $kid) {
            kardus_catat($pdo, (int) $kid, 'kirim', 'Draft digabung ke ' . $target['no_perpindahan'], $userId);
        }
        $pdo->exec("UPDATE kardus SET perpindahan_id = " . (int) $target['id'] . " WHERE perpindahan_id IN ($inL)");
    }
    // Baris kembar tanpa kardus dijumlahkan (barang + batch + kedaluwarsa + kondisi sama).
    $kolKardus = kolom_ada($pdo, 'perpindahan_detail', 'kardus_id') ? 'kardus_id' : 'NULL';
    $rows = $pdo->query("SELECT id, barang_id, nomor_batch, tanggal_expired, kondisi, qty, harga, $kolKardus AS kardus_id FROM perpindahan_detail WHERE perpindahan_id = " . (int) $target['id'] . " ORDER BY id")->fetchAll();
    $pertama = []; $hapus = [];
    $upd = $pdo->prepare("UPDATE perpindahan_detail SET qty = ?, subtotal = ? * harga WHERE id = ?");
    foreach ($rows as $r) {
        if ($r['kardus_id']) { continue; }
        $k = $r['barang_id'] . '|' . $r['nomor_batch'] . '|' . $r['tanggal_expired'] . '|' . $r['kondisi'];
        if (!isset($pertama[$k])) { $pertama[$k] = ['id' => (int) $r['id'], 'qty' => (int) $r['qty']]; continue; }
        $pertama[$k]['qty'] += (int) $r['qty'];
        $upd->execute([$pertama[$k]['qty'], $pertama[$k]['qty'], $pertama[$k]['id']]);
        $hapus[] = (int) $r['id'];
    }
    if ($hapus) { $pdo->exec('DELETE FROM perpindahan_detail WHERE id IN (' . implode(',', $hapus) . ')'); }
    // Catatan & data pengiriman: catatan digabung; kolom kosong diisi dari draft lain.
    $catatan = []; $isi = [];
    foreach ($docs as $p) {
        if (trim((string) $p['catatan']) !== '') { $catatan[] = trim((string) $p['catatan']); }
        foreach (['penerima', 'untuk_program', 'kendaraan', 'no_polisi', 'pengirim', 'ekspedisi'] as $kol) {
            if (empty($isi[$kol]) && trim((string) $p[$kol]) !== '') { $isi[$kol] = trim((string) $p[$kol]); }
        }
    }
    $nos = array_column(array_slice($docs, 1), 'no_perpindahan');
    $catatanBaru = mb_substr(implode(' | ', array_unique($catatan)) . ' [gabungan: ' . implode(', ', $nos) . ']', 0, 500);
    $set = ['catatan = ?']; $par = [$catatanBaru];
    foreach ($isi as $kol => $v) { $set[] = "$kol = COALESCE(NULLIF($kol, ''), ?)"; $par[] = $v; }
    $par[] = (int) $target['id'];
    $pdo->prepare('UPDATE perpindahan SET ' . implode(', ', $set) . ' WHERE id = ?')->execute($par);
    try { $pdo->exec("DELETE FROM perpindahan_titik WHERE perpindahan_id IN ($inL)"); } catch (Throwable $e) {}
    $pdo->exec("DELETE FROM perpindahan WHERE id IN ($inL)");
    $jml = (int) $pdo->query("SELECT COUNT(*) FROM perpindahan_detail WHERE perpindahan_id = " . (int) $target['id'])->fetchColumn();
    return ['no' => (string) $target['no_perpindahan'], 'id' => (int) $target['id'], 'gabung' => count($lain), 'baris' => $jml, 'nos' => $nos];
}
