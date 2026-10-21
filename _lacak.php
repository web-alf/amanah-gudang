<?php
/**
 * AMANAH — Lacak kiriman: titik transit dan posisi surat jalan
 *
 * Sampai 6 Okt 2026 satu surat jalan hanya tahu asal dan tujuan. Kiriman
 * yang singgah di gudang lain — misalnya Gudang Depo lalu diteruskan ke
 * toko — tidak punya tempat mencatat singgahnya, jadi pertanyaan "barangnya
 * sudah sampai mana?" hanya bisa dijawab lewat telepon.
 *
 * Sekarang surat jalan boleh menyebut TITIK TRANSIT (tabel
 * perpindahan_titik). Di tiap titik, petugas gudang itu menandai "tiba" dan
 * "berangkat" — cukup dari halaman Lacak Kiriman, setelah memindai QR surat
 * jalannya. Posisi kiriman dihitung dari catatan itu.
 *
 * STOK TIDAK IKUT SINGGAH. Barang di gudang transit tetap milik pengirim:
 * stoknya baru berpindah saat diterima di tujuan akhir (penerimaan.php),
 * persis seperti surat jalan tanpa transit. Gudang transit hanya mencatat
 * lewatnya barang, bukan menghitungnya sebagai stok sendiri. Itu keputusan
 * pemilik sistem (6 Okt 2026): gudang transit tidak perlu menerima dan
 * mengirim ulang, cukup menandai.
 *
 * Dimuat dari ujung _lib.php, jadi tersedia di semua halaman.
 */

/** Batas titik singgah per surat jalan — cukup untuk rute mana pun di lembaga ini. */
const MAKS_TITIK_TRANSIT = 3;

/**
 * Tabel dibuat bila belum ada. Pola yang sama dengan galat_sistem dan
 * stok_harian: hosting diunggah lewat FTP, dan satu langkah migrasi yang
 * terlewat tidak boleh membuat daftar surat jalan ikut tumbang.
 * db/migrasi_titik_transit.sql berisi skema yang sama untuk pemasangan baru.
 */
function pastikan_tabel_titik(PDO $pdo): bool {
    static $ada = null;
    if ($ada !== null) { return $ada; }
    try {
        /* CREATE TABLE di dalam transaksi membuat MySQL melakukan COMMIT
           diam-diam — commit() sesudahnya gagal dengan "no active transaction"
           dan separuh dokumen sudah telanjur tersimpan. Di dalam transaksi
           hanya DIPERIKSA; pembuatannya dilakukan pemanggil di luar transaksi
           (periksa_titik_transit, lacak.php, syarat_transit_saya). */
        if ($pdo->inTransaction()) {
            return (bool) $pdo->query(
                "SELECT 1 FROM information_schema.tables
                  WHERE table_schema = DATABASE() AND table_name = 'perpindahan_titik'")->fetchColumn();
        }
        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS perpindahan_titik (
               id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
               perpindahan_id BIGINT UNSIGNED NOT NULL,
               urutan         TINYINT UNSIGNED NOT NULL DEFAULT 1,
               lokasi_tipe    ENUM('gudang','toko') NOT NULL DEFAULT 'gudang',
               lokasi_id      BIGINT UNSIGNED NOT NULL,
               tiba_at        DATETIME NULL,
               tiba_oleh      BIGINT UNSIGNED NULL,
               berangkat_at   DATETIME NULL,
               berangkat_oleh BIGINT UNSIGNED NULL,
               catatan        VARCHAR(255) NULL,
               -- Kendaraan & pengemudi RUAS BERIKUTNYA, diisi saat berangkat dari titik ini
               kendaraan      VARCHAR(60) NULL,
               no_polisi      VARCHAR(20) NULL,
               pengemudi      VARCHAR(100) NULL,
               created_at     TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
               PRIMARY KEY (id),
               KEY idx_titik_pp (perpindahan_id, urutan),
               KEY idx_titik_lokasi (lokasi_tipe, lokasi_id),
               CONSTRAINT fk_titik_pp FOREIGN KEY (perpindahan_id)
                 REFERENCES perpindahan (id) ON DELETE CASCADE
             ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        /* Kolom kendaraan/pengemudi ditambahkan 6 Okt 2026 (malam) — tabel
           yang sudah terlanjur dibuat sore harinya belum memilikinya. */
        $adaKolom = (bool) $pdo->query(
            "SELECT 1 FROM information_schema.columns
              WHERE table_schema = DATABASE() AND table_name = 'perpindahan_titik' AND column_name = 'pengemudi'")->fetchColumn();
        if (!$adaKolom) {
            $pdo->exec("ALTER TABLE perpindahan_titik
                           ADD COLUMN kendaraan VARCHAR(60) NULL AFTER catatan,
                           ADD COLUMN no_polisi VARCHAR(20) NULL AFTER kendaraan,
                           ADD COLUMN pengemudi VARCHAR(100) NULL AFTER no_polisi");
        }
        $ada = true;
    } catch (Throwable $e) {
        error_log('[AMANAH] perpindahan_titik: ' . $e->getMessage());
        $ada = false;
    }
    return $ada;
}

/** Jenis dokumen yang masuk akal punya titik singgah: yang barangnya benar-benar menempuh perjalanan. */
function jenis_boleh_transit(string $jenis): bool {
    return in_array($jenis, ['transfer_gudang', 'kirim_toko', 'antar_toko', 'retur_toko', 'penyaluran'], true);
}

/** Titik transit satu dokumen, urut, lengkap dengan nama lokasi dan nama pencatatnya. */
function titik_transit(PDO $pdo, int $ppId): array {
    if (!pastikan_tabel_titik($pdo)) { return []; }
    $st = $pdo->prepare(
        "SELECT t.*, ut.nama AS tiba_nama, ub.nama AS berangkat_nama,
                COALESCE(g.nama, tk.nama) AS lokasi_nama
         FROM perpindahan_titik t
         LEFT JOIN users ut ON ut.id = t.tiba_oleh
         LEFT JOIN users ub ON ub.id = t.berangkat_oleh
         LEFT JOIN gudang g ON g.id = t.lokasi_id AND t.lokasi_tipe = 'gudang'
         LEFT JOIN toko tk  ON tk.id = t.lokasi_id AND t.lokasi_tipe = 'toko'
         WHERE t.perpindahan_id = ? ORDER BY t.urutan, t.id");
    $st->execute([$ppId]);
    return $st->fetchAll();
}

/**
 * Simpan daftar titik transit untuk dokumen baru. Dipanggil di dalam
 * transaksi pembuatan surat jalan, SETELAH barisnya ada.
 *
 * @param int[] $gudangIds  urutan singgah, id gudang
 */
function simpan_titik_transit(PDO $pdo, int $ppId, array $gudangIds): void {
    if (!$gudangIds) { return; }
    $st = $pdo->prepare(
        "INSERT INTO perpindahan_titik (perpindahan_id, urutan, lokasi_tipe, lokasi_id) VALUES (?,?,'gudang',?)");
    foreach (array_values($gudangIds) as $i => $gid) {
        $st->execute([$ppId, $i + 1, (int) $gid]);
    }
}

/** Gudang pemilik sebuah rak (0 bila tidak ada). */
function gudang_dari_rak(PDO $pdo, int $rakId): int {
    if ($rakId <= 0) { return 0; }
    $st = $pdo->prepare("SELECT gudang_id FROM lokasi_rak WHERE id = ?");
    $st->execute([$rakId]);
    return (int) $st->fetchColumn();
}

/**
 * Periksa isian titik transit dari formulir. Mengembalikan daftar id yang
 * sah (urut, tanpa kembar), atau melempar RuntimeException berisi alasannya.
 */
function periksa_titik_transit(PDO $pdo, array $isian, string $jenis, string $asalTipe, ?int $asalId,
                               string $tujuanTipe, ?int $tujuanId): array {
    pastikan_tabel_titik($pdo);   // di luar transaksi — lihat catatan di fungsinya
    $ids = [];
    foreach ($isian as $v) {
        $v = (int) $v;
        if ($v > 0 && !in_array($v, $ids, true)) { $ids[] = $v; }
    }
    if (!$ids) { return []; }
    if (!jenis_boleh_transit($jenis)) {
        throw new RuntimeException('Titik transit hanya untuk dokumen yang barangnya menempuh perjalanan (transfer, kirim toko, penyaluran).');
    }
    if (count($ids) > MAKS_TITIK_TRANSIT) {
        throw new RuntimeException('Titik transit paling banyak ' . MAKS_TITIK_TRANSIT . '.');
    }
    // Gudang asal/tujuan bukan transit — ia sudah ada di dokumennya.
    $gdAsal   = $asalTipe   === 'gudang' ? (int) $asalId   : ($asalTipe   === 'rak' ? gudang_dari_rak($pdo, (int) $asalId)   : 0);
    $gdTujuan = $tujuanTipe === 'gudang' ? (int) $tujuanId : ($tujuanTipe === 'rak' ? gudang_dari_rak($pdo, (int) $tujuanId) : 0);
    foreach ($ids as $gid) {
        if ($gid === $gdAsal)   { throw new RuntimeException('Gudang asal tidak bisa menjadi titik transit.'); }
        if ($gid === $gdTujuan) { throw new RuntimeException('Gudang tujuan tidak bisa menjadi titik transit.'); }
    }
    $in = implode(',', $ids);
    $n = (int) $pdo->query("SELECT COUNT(*) FROM gudang WHERE id IN ($in) AND deleted_at IS NULL AND is_active = 1")->fetchColumn();
    if ($n !== count($ids)) { throw new RuntimeException('Salah satu gudang transit tidak ditemukan atau sudah nonaktif.'); }
    return $ids;
}

/**
 * Posisi kiriman saat ini — jawaban untuk "sudah sampai mana?".
 *
 * @return array{teks:string, kelas:string, transit:bool}
 */
function posisi_kiriman(PDO $pdo, array $pp, ?array $titik = null): array {
    $titik  = $titik ?? titik_transit($pdo, (int) $pp['id']);
    $tujuan = nama_lokasi($pdo, $pp['tujuan_tipe'], $pp['tujuan_id'] ? (int) $pp['tujuan_id'] : null, $pp['tujuan_teks']);
    $status = (string) $pp['status'];

    if (in_array($status, ['draft', 'menunggu_persetujuan', 'disetujui'], true)) {
        return ['teks' => 'Belum berangkat', 'kelas' => 't-gray', 'transit' => false];
    }
    if ($status === 'ditolak')    { return ['teks' => 'Ditolak',    'kelas' => 't-red',  'transit' => false]; }
    if ($status === 'dibatalkan') { return ['teks' => 'Dibatalkan', 'kelas' => 't-gray', 'transit' => false]; }
    if (in_array($status, ['diterima', 'selesai'], true)) {
        return ['teks' => butuh_penerimaan($pp['jenis']) ? "Diterima di $tujuan" : 'Selesai',
                'kelas' => 't-green', 'transit' => false];
    }

    // Status 'dikirim': sedang singgah di suatu titik?
    foreach ($titik as $t) {
        if ($t['tiba_at'] && !$t['berangkat_at']) {
            return ['teks' => 'Di ' . $t['lokasi_nama'] . ' (transit)', 'kelas' => 't-blue', 'transit' => true];
        }
    }
    // Tidak sedang singgah: yang dituju adalah titik pertama yang belum dilewati.
    $berikut = $tujuan;
    foreach ($titik as $t) {
        if (!$t['berangkat_at']) { $berikut = $t['lokasi_nama'] . ' (transit)'; break; }
    }
    return ['teks' => "Menuju $berikut", 'kelas' => 't-amber', 'transit' => (bool) $titik];
}

/**
 * Linimasa perjalanan satu dokumen, dari dibuat sampai diterima.
 *
 * $pp boleh membawa kolom tambahan pembuat, penyetuju, penerima_nama
 * (nama pengguna) — dipakai bila ada.
 *
 * @return array<int, array{judul:string, waktu:?string, oleh:?string, keadaan:string, titik_id:?int, aksi:?string}>
 *   keadaan: 'selesai' | 'kini' (langkah berikutnya) | 'nanti' | 'lewat' (dilewati tanpa dicatat)
 *   aksi:    'tiba' | 'berangkat' — tombol untuk langkah itu, bila akun berwenang
 */
function linimasa_kiriman(PDO $pdo, array $pp, array $titik): array {
    $asal   = nama_lokasi($pdo, $pp['asal_tipe'],   $pp['asal_id']   ? (int) $pp['asal_id']   : null, $pp['asal_teks']);
    $tujuan = nama_lokasi($pdo, $pp['tujuan_tipe'], $pp['tujuan_id'] ? (int) $pp['tujuan_id'] : null, $pp['tujuan_teks']);
    $status = (string) $pp['status'];
    $jalan  = in_array($status, ['dikirim', 'diterima', 'selesai'], true);
    $sampai = in_array($status, ['diterima', 'selesai'], true);
    $batal  = in_array($status, ['ditolak', 'dibatalkan'], true);

    $L = [];
    $L[] = ['judul' => 'Dokumen dibuat', 'waktu' => $pp['created_at'] ?? $pp['tanggal'], 'oleh' => $pp['pembuat'] ?? null,
            'keadaan' => 'selesai', 'titik_id' => null, 'aksi' => null];
    $L[] = ['judul' => "Disetujui — berangkat dari $asal", 'waktu' => $jalan ? $pp['approved_at'] : null,
            'oleh' => $jalan ? ($pp['penyetuju'] ?? null) : null,
            'keadaan' => $jalan ? 'selesai' : ($batal ? 'lewat' : 'kini'),
            'titik_id' => null, 'aksi' => null];

    $adaKini = !$jalan;   // langkah "kini" sudah ditetapkan?
    foreach ($titik as $t) {
        $tiba = (bool) $t['tiba_at']; $brkt = (bool) $t['berangkat_at'];
        $L[] = ['judul' => 'Tiba di ' . $t['lokasi_nama'] . ' (transit)', 'waktu' => $t['tiba_at'], 'oleh' => $t['tiba_nama'],
                'keadaan' => $tiba ? 'selesai' : (($sampai || $brkt || $batal) ? 'lewat' : ($adaKini ? 'nanti' : 'kini')),
                'titik_id' => (int) $t['id'], 'aksi' => ($tiba || $batal) ? null : 'tiba'];
        if (!$tiba && !$sampai && !$brkt) { $adaKini = true; }
        $L[] = ['judul' => 'Berangkat dari ' . $t['lokasi_nama'], 'waktu' => $t['berangkat_at'], 'oleh' => $t['berangkat_nama'],
                'keadaan' => $brkt ? 'selesai' : (($sampai || $batal) ? 'lewat' : ($adaKini ? 'nanti' : 'kini')),
                'titik_id' => (int) $t['id'], 'aksi' => ($brkt || $batal) ? null : 'berangkat',
                // Kendaraan & pengemudi ruas berikutnya — bisa berbeda dari yang berangkat dari asal.
                'kirim' => teks_angkutan($t['kendaraan'] ?? null, $t['no_polisi'] ?? null, $t['pengemudi'] ?? null)];
        if (!$brkt && !$sampai) { $adaKini = true; }
    }
    $L[] = ['judul' => (butuh_penerimaan($pp['jenis']) ? 'Diterima di ' : 'Selesai di ') . $tujuan,
            'waktu' => $sampai ? ($pp['diterima_at'] ?: ($pp['updated_at'] ?? null)) : null,
            'oleh' => $sampai ? ($pp['penerima_nama'] ?? null) : null,
            'keadaan' => $sampai ? 'selesai' : ($batal ? 'lewat' : ($adaKini ? 'nanti' : 'kini')),
            'titik_id' => null, 'aksi' => null];
    return $L;
}

/** Boleh/tidaknya akun ini menandai tiba/berangkat di satu titik transit. */
function boleh_catat_transit(PDO $pdo, array $t): bool {
    if (is_super()) { return true; }
    if ($t['lokasi_tipe'] === 'gudang' && terikat_satu_gudang()) {
        return in_array((int) $t['lokasi_id'], gudang_saya($pdo), true);
    }
    if ($t['lokasi_tipe'] === 'toko' && is_toko()) {
        return in_array((int) $t['lokasi_id'], toko_saya($pdo), true);
    }
    return false;
}

/**
 * Tandai tiba / berangkat di satu titik. Dipanggil DI DALAM transaksi.
 *
 * Yang dijaga: dokumennya sedang dalam perjalanan, titiknya belum ditandai,
 * dan "berangkat" hanya setelah "tiba". Yang SENGAJA tidak dijaga: urutan
 * antar titik. Bila gudang transit pertama lupa menandai, gudang kedua tetap
 * boleh mencatat tibanya — catatan yang terlewat tampil sebagai "tidak
 * dicatat" di linimasa, bukan menghalangi yang berikutnya.
 */
/** "Mobil box (B 1234 XYZ) · Pak Budi" — kosong bila tidak ada yang diisi. */
function teks_angkutan(?string $kendaraan, ?string $noPolisi, ?string $pengemudi): string {
    $k = trim((string) $kendaraan); $p = trim((string) $noPolisi); $s = trim((string) $pengemudi);
    $bagian = [];
    if ($k !== '' || $p !== '') { $bagian[] = trim($k . ($p !== '' ? " ($p)" : '')); }
    if ($s !== '') { $bagian[] = $s; }
    return implode(' · ', $bagian);
}

/**
 * @param array $angkut  untuk 'berangkat': kendaraan, no_polisi, pengemudi ruas berikutnya (opsional)
 */
function catat_transit(PDO $pdo, int $titikId, string $aksi, int $userId, string $catatan = '', array $angkut = []): array {
    if (!in_array($aksi, ['tiba', 'berangkat'], true)) { throw new RuntimeException('Tindakan tidak dikenali.'); }
    $st = $pdo->prepare("SELECT * FROM perpindahan_titik WHERE id = ? FOR UPDATE");
    $st->execute([$titikId]);
    $t = $st->fetch();
    if (!$t) { throw new RuntimeException('Titik transit itu sudah tidak ada.'); }
    if (!boleh_catat_transit($pdo, $t)) { throw new RuntimeException('Titik transit ini bukan lokasi yang Anda pegang.'); }

    $stP = $pdo->prepare("SELECT * FROM perpindahan WHERE id = ? FOR UPDATE");
    $stP->execute([(int) $t['perpindahan_id']]);
    $pp = $stP->fetch();
    if (!$pp) { throw new RuntimeException('Dokumennya sudah tidak ada.'); }
    if ($pp['status'] !== 'dikirim') {
        [$lbl] = label_status((string) $pp['status']);
        throw new RuntimeException("Dokumen ini berstatus \"$lbl\", bukan dalam perjalanan — tidak ada yang perlu ditandai.");
    }
    if ($aksi === 'tiba' && $t['tiba_at']) {
        throw new RuntimeException('Tiba di titik ini sudah dicatat pada ' . tgl_jam_id($t['tiba_at']) . '.');
    }
    if ($aksi === 'berangkat') {
        if (!$t['tiba_at'])     { throw new RuntimeException('Tandai "tiba" dulu sebelum "berangkat".'); }
        if ($t['berangkat_at']) { throw new RuntimeException('Berangkat dari titik ini sudah dicatat pada ' . tgl_jam_id($t['berangkat_at']) . '.'); }
    }
    $catatan = mb_substr(trim($catatan), 0, 255);
    $kol = $aksi === 'tiba' ? 'tiba' : 'berangkat';
    $pdo->prepare(
        "UPDATE perpindahan_titik
            SET {$kol}_at = NOW(), {$kol}_oleh = ?,
                catatan = CASE WHEN ? = '' THEN catatan ELSE ? END
          WHERE id = ?")->execute([$userId, $catatan, $catatan, $titikId]);
    if ($aksi === 'berangkat') {
        // Kendaraan & pengemudi ruas berikutnya. Kosong = sama dengan sebelumnya (tidak ditulis).
        $k = mb_substr(trim((string) ($angkut['kendaraan'] ?? '')), 0, 60);
        $p = mb_substr(trim((string) ($angkut['no_polisi'] ?? '')), 0, 20);
        $s = mb_substr(trim((string) ($angkut['pengemudi'] ?? '')), 0, 100);
        if ($k !== '' || $p !== '' || $s !== '') {
            $pdo->prepare("UPDATE perpindahan_titik SET kendaraan = ?, no_polisi = ?, pengemudi = ? WHERE id = ?")
                ->execute([$k ?: null, $p ?: null, $s ?: null, $titikId]);
        }
    }
    return ['pp' => $pp, 'titik' => $t];
}

/**
 * Potongan WHERE: dokumen yang salah satu titik transitnya adalah lokasi
 * akun ini. Dipakai syarat_perpindahan() supaya gudang transit melihat
 * (dan bisa menandai) surat jalan yang singgah padanya. '' bila tidak ada.
 */
function syarat_transit_saya(PDO $pdo, string $alias = 'p'): string {
    if (!pastikan_tabel_titik($pdo)) { return ''; }
    $a = $alias !== '' ? $alias . '.' : '';
    $bagian = '';
    if (terikat_satu_gudang() && ($gd = gudang_saya($pdo))) {
        $bagian = "lokasi_tipe = 'gudang' AND lokasi_id IN (" . implode(',', array_map('intval', $gd)) . ')';
    } elseif (is_toko() && ($tk = toko_saya($pdo))) {
        $bagian = "lokasi_tipe = 'toko' AND lokasi_id IN (" . implode(',', array_map('intval', $tk)) . ')';
    }
    if ($bagian === '') { return ''; }
    return "{$a}id IN (SELECT perpindahan_id FROM perpindahan_titik WHERE $bagian)";
}

/** Daftar gudang aktif untuk pilihan titik transit di formulir. */
function gudang_pilihan_transit(PDO $pdo): array {
    try {
        return $pdo->query("SELECT id, kode, nama FROM gudang WHERE deleted_at IS NULL AND is_active = 1 ORDER BY nama")->fetchAll();
    } catch (Throwable $e) { return []; }
}
