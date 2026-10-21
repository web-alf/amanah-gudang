<?php
/**
 * AMANAH — Kardus: pembantu bersama (revisi 12 Okt 2026)
 *
 * Kardus DIBUAT DI DALAM formulir Perpindahan: saat menyusun surat jalan,
 * petugas memilih "masuk kardus" atau "tanpa kardus" untuk tiap kelompok
 * barang. Satu perpindahan boleh berisi beberapa kardus sekaligus barang
 * tanpa kardus. Kardus = satu baris tabel `kardus` (kode KRD-…, milik satu
 * perpindahan) dan isinya = baris `perpindahan_detail` bertanda kardus_id.
 *
 * STOK: tidak ada mekanisme stok tersendiri. Isi kardus adalah baris
 * perpindahan biasa, dipotong FEFO saat dokumen disahkan / diterima —
 * persis seperti barang tanpa kardus. Jadi stok tidak pernah dipotong dua
 * kali, dan mencetak label / surat jalan tidak mengubah stok.
 *
 * STATUS kardus mengikuti dokumennya: dikemas (draft / menunggu
 * persetujuan) → dalam_perjalanan (disahkan, ada penerima) → diterima
 * (penerimaan dikonfirmasi, atau penyaluran langsung disahkan);
 * dibatalkan bila dokumennya ditolak / dibatalkan.
 *
 * Menu Kardus ("Folder Kardus") hanya untuk melihat, mencari, memindai,
 * dan mencetak ulang label — bukan membuat kardus.
 *
 * Dimuat dari ujung _lib.php.
 */

const KARDUS_STATUS = [
    'draft'            => ['Draf',             't-gray'],
    'dikemas'          => ['Dikemas',          't-amber'],
    'siap_dikirim'     => ['Siap dikirim',     't-blue'],
    'dalam_perjalanan' => ['Dalam perjalanan', 't-amber'],
    'diterima'         => ['Diterima',         't-green'],
    'dibatalkan'       => ['Dibatalkan',       't-gray'],
];
const KARDUS_KONDISI = [
    'baik'              => ['Baik',              't-green'],
    'rusak'             => ['Rusak',             't-red'],
    'hilang'            => ['Hilang',            't-red'],
    'perlu_pemeriksaan' => ['Perlu pemeriksaan', 't-amber'],
];

function label_status_kardus(?string $s): array  { return KARDUS_STATUS[$s]  ?? [ucfirst((string) $s), 't-gray']; }
function label_kondisi_kardus(?string $k): array { return KARDUS_KONDISI[$k] ?? [ucfirst((string) $k), 't-gray']; }

/** Fitur kardus aktif = migrasi 03 sudah dijalankan (tabel kardus & kolom kardus_id ada). */
function kardus_aktif(PDO $pdo): bool {
    return kolom_ada($pdo, 'perpindahan_detail', 'kardus_id');
}

/* Potongan " AND bb.kardus_id IS NULL" untuk kueri stok. Sejak revisi 12 Okt
   2026 tidak ada lagi batch bertanda kardus (isi kardus = baris perpindahan
   biasa), jadi potongan ini hanya menjaga sisa data lama bila ada. */
function sql_tanpa_kardus(PDO $pdo, string $alias = 'bb'): string {
    return kolom_ada($pdo, 'batch_barang', 'kardus_id') ? " AND $alias.kardus_id IS NULL" : '';
}

/** Jenis perpindahan yang boleh membawa kardus: barang benar-benar keluar gudang. */
function jenis_boleh_kardus(string $jenis): bool {
    /* mutasi_rak (revisi 18 Okt 2026): kardus dikemas sambil memindahkan
       barang dari rak gudang ke rak siap kirim; kardusnya lalu menunggu di
       rak itu (status Siap dikirim) dan tinggal dicentang di surat jalan. */
    return in_array($jenis, ['transfer_gudang', 'kirim_toko', 'penyaluran', 'mutasi_rak'], true);
}
/** Jenis yang boleh MENGAMBIL kardus siap kirim (bukan mengemas baru di rak). */
function jenis_ambil_kardus_siap(string $jenis): bool {
    return in_array($jenis, ['transfer_gudang', 'kirim_toko', 'penyaluran'], true);
}

/* ------------------------------------------------------------------ hak */
/** Boleh melihat kardus: lingkup gudang asalnya, atau dokumennya terlihat (penerima/transit). */
function kardus_boleh_lihat(PDO $pdo, int $gudangId, ?int $perpindahanId = null): bool {
    if (is_super() || is_pimpinan()) { return true; }
    if (is_supplier()) { return false; }
    if ($perpindahanId && boleh_lihat_perpindahan($pdo, $perpindahanId)) { return true; }
    if (is_toko()) { return false; }
    return !terikat_satu_gudang() || in_array($gudangId, gudang_saya($pdo), true);
}

/* ---------------------------------------------------------------- baca */
function kardus_ambil(PDO $pdo, int $id, bool $kunci = false): ?array {
    $st = $pdo->prepare(
        "SELECT k.*, g.nama AS gudang_nama, g.kode AS gudang_kode, lr.kode AS rak_kode, lr.nama AS rak_nama,
                up.nama AS petugas_nama, ud.nama AS penerima_nama,
                p.no_perpindahan, p.no_surat_jalan, p.status AS pp_status, p.no_resi AS pp_resi, p.ekspedisi,
                p.kendaraan, p.no_polisi, p.pengirim, p.jenis AS pp_jenis, p.tanggal AS pp_tanggal, p.diterima_at AS pp_diterima_at,
                p.asal_tipe, p.asal_id, p.asal_teks, p.tujuan_tipe, p.tujuan_id, p.tujuan_teks, p.penerima
         FROM kardus k
         LEFT JOIN gudang g      ON g.id  = k.gudang_id
         LEFT JOIN lokasi_rak lr ON lr.id = k.lokasi_rak_id
         LEFT JOIN users up      ON up.id = k.petugas_id
         LEFT JOIN users ud      ON ud.id = k.diterima_oleh
         LEFT JOIN perpindahan p ON p.id  = k.perpindahan_id
         WHERE k.id = ?" . ($kunci ? ' FOR UPDATE' : ''));
    $st->execute([$id]);
    return $st->fetch() ?: null;
}

/** Isi kardus = baris perpindahan_detail bertanda kardus_id (urut baris). */
function kardus_isi(PDO $pdo, int $kardusId): array {
    $st = $pdo->prepare(
        "SELECT d.id, d.barang_id, d.qty, d.qty_diterima, d.nomor_batch, d.tanggal_expired, d.keterangan AS catatan,
                d.selisih_catatan, d.kondisi, b.nama AS nama_barang, b.kode AS kode_barang, b.satuan, b.merek, b.ukuran
         FROM perpindahan_detail d JOIN barang b ON b.id = d.barang_id
         WHERE d.kardus_id = ? AND d.perpindahan_id = (SELECT k.perpindahan_id FROM kardus k WHERE k.id = ?)
         ORDER BY d.id");
    /* Kardus siap kirim muncul di DUA dokumen: mutasi rak yang mengemasnya dan
       surat jalan yang membawanya. Isinya selalu dibaca dari dokumen yang
       sedang dipegang kardus (kardus.perpindahan_id) supaya tidak terhitung dua kali. */
    $st->execute([$kardusId, $kardusId]);
    $isi = $st->fetchAll();
    // Keterangan baris memuat jejak "Batch: …" dari penerapan stok — bukan catatan isi kardus.
    foreach ($isi as &$r) { $r['catatan'] = trim((string) preg_replace('/(\s*\|\s*)?Batch: .*$/u', '', (string) $r['catatan'])) ?: null; }
    unset($r);
    return $isi;
}

function kardus_riwayat(PDO $pdo, int $kardusId): array {
    $st = $pdo->prepare(
        "SELECT r.*, u.nama AS oleh FROM kardus_riwayat r LEFT JOIN users u ON u.id = r.user_id
         WHERE r.kardus_id = ? ORDER BY r.id");
    $st->execute([$kardusId]);
    return $st->fetchAll();
}

function kardus_catat(PDO $pdo, int $kardusId, string $aksi, string $ket = '', ?int $userId = null): void {
    $pdo->prepare("INSERT INTO kardus_riwayat (kardus_id, aksi, keterangan, user_id) VALUES (?,?,?,?)")
        ->execute([$kardusId, $aksi, mb_substr($ket, 0, 500) ?: null, $userId ?? ($_SESSION['user_id'] ?? null)]);
}

/** Ringkasan isi: jumlah jenis & total unit per satuan (satuan berbeda tidak dijumlahkan). */
function kardus_ringkas(array $isi): array {
    $perSatuan = [];
    foreach ($isi as $r) {
        $s = (string) ($r['satuan'] ?: 'unit');
        $perSatuan[$s] = ($perSatuan[$s] ?? 0) + (int) $r['qty'];
    }
    return ['jenis' => count($isi), 'per_satuan' => $perSatuan];
}
function kardus_teks_satuan(array $perSatuan): string {
    $b = [];
    foreach ($perSatuan as $s => $n) { $b[] = angka($n) . ' ' . $s; }
    return implode(' + ', $b) ?: '0';
}
function kardus_teks_isi(array $isi, int $maks = 4): string {
    $b = [];
    foreach (array_slice($isi, 0, $maks) as $r) { $b[] = $r['nama_barang'] . ' ' . angka($r['qty']) . ' ' . ($r['satuan'] ?: ''); }
    if (count($isi) > $maks) { $b[] = '+' . (count($isi) - $maks) . ' lainnya'; }
    return implode(', ', $b);
}

/** Cari kardus lewat kode (hasil pindai label). */
function kardus_cari_kode(PDO $pdo, string $kode): ?int {
    $st = $pdo->prepare("SELECT id FROM kardus WHERE kode = ? LIMIT 1");
    $st->execute([trim($kode)]);
    return ((int) $st->fetchColumn()) ?: null;
}

/** Id kardus yang dibawa sebuah dokumen, urut pembuatan. */
function kardus_di_dokumen(PDO $pdo, int $ppId): array {
    if (!kardus_aktif($pdo)) { return []; }
    $st = $pdo->prepare("SELECT id FROM kardus WHERE perpindahan_id = ? AND status <> 'dibatalkan' ORDER BY id");
    $st->execute([$ppId]);
    return array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
}

/** Kardus sebuah dokumen beserta isinya, diindeks id, dengan nomor urut 1..n. */
function kardus_dokumen_lengkap(PDO $pdo, int $ppId): array {
    $h = []; $n = 0;
    foreach (kardus_di_dokumen($pdo, $ppId) as $kid) {
        $k = kardus_ambil($pdo, $kid); if (!$k) { continue; }
        $k['isi']  = kardus_isi($pdo, $kid);
        $k['urut'] = ++$n;
        $h[$kid] = $k;
    }
    foreach ($h as &$k) { $k['jumlah_kardus'] = $n; }
    unset($k);
    return $h;
}

/* ------------------------------------------------- buat dari dokumen */
/**
 * Buat (atau ambil) kardus-kardus sebuah perpindahan. Dipanggil di dalam
 * transaksi pembuatan dokumen, SEBELUM baris detailnya ditulis (baris lalu
 * diberi kardus_id dari hasil fungsi ini).
 *
 * Kepala berisi 'ada' => id kardus siap kirim (dikemas lewat mutasi rak):
 * kardus itu tidak dibuat baru, melainkan dipindah ke dokumen ini — kodenya
 * tetap, label yang sudah ditempel tetap berlaku.
 *
 * @param array $kardusKepala [idx => ['ada','nama','berat_kg','panjang_cm','lebar_cm','tinggi_cm','catatan']]
 * @param array $items        baris perpindahan; tiap baris punya 'kardus_idx' (0 = tanpa kardus)
 * @return array idx => ['id' => int, 'kode' => string, 'ada' => bool]
 */
function kardus_buat_untuk_dokumen(PDO $pdo, int $ppId, array $pp, array $kardusKepala, array $items, int $userId): array {
    if (!kardus_aktif($pdo)) { throw new RuntimeException('Fitur kardus belum aktif: migrasi basis data (db/migrasi_tim_gudang_03_kardus.sql) belum dijalankan.'); }
    $gudangId = kardus_gudang_asal($pdo, (string) $pp['asal_tipe'], $pp['asal_id'] ? (int) $pp['asal_id'] : null);
    $rakId    = $pp['asal_tipe'] === 'rak' ? (int) $pp['asal_id'] : null;
    $angka = function ($v, int $maks): ?float {
        $v = trim((string) $v); if ($v === '') { return null; }
        $v = (float) str_replace(',', '.', $v);
        if ($v < 0 || $v > $maks) { throw new RuntimeException('Angka berat/dimensi kardus tidak wajar.'); }
        return $v;
    };
    $ins = $pdo->prepare(
        "INSERT INTO kardus (kode, nama, gudang_id, lokasi_rak_id, tanggal_kemas, petugas_id, berat_kg, panjang_cm, lebar_cm, tinggi_cm, catatan, status, perpindahan_id, ditutup_at)
         VALUES (?,?,?,?,?,?,?,?,?,?,?,'dikemas',?,NOW())");
    $hasil = [];
    foreach ($kardusKepala as $idx => $k) {
        $idx = (int) $idx; if ($idx <= 0) { continue; }
        $adaIsi = false;
        foreach ($items as $it) { if ((int) ($it['kardus_idx'] ?? 0) === $idx) { $adaIsi = true; break; } }
        if (!$adaIsi) { throw new RuntimeException("Kardus $idx kosong — isi barangnya atau hapus kardusnya."); }
        if (!empty($k['ada'])) {
            $kd = kardus_siap_periksa($pdo, (int) $k['ada'], (string) $pp['jenis'], (string) $pp['asal_tipe'], $pp['asal_id'] ? (int) $pp['asal_id'] : null, true);
            $pdo->prepare("UPDATE kardus SET perpindahan_id = ?, status = 'dikemas' WHERE id = ?")->execute([$ppId, (int) $kd['id']]);
            kardus_catat($pdo, (int) $kd['id'], 'kirim', "Masuk surat jalan {$pp['no_perpindahan']} (dikemas di {$kd['no_perpindahan']})", $userId);
            $hasil[$idx] = ['id' => (int) $kd['id'], 'kode' => $kd['kode'], 'ada' => true];
            continue;
        }
        $noKardus = nomor_dokumen($pdo, 'kardus', 'kode', 'KRD');
        $ins->execute([$noKardus, mb_substr(trim((string) ($k['nama'] ?? '')), 0, 150) ?: null, $gudangId ?: 0, $rakId,
                       substr((string) $pp['tanggal'], 0, 10) ?: date('Y-m-d'), $userId,
                       $angka($k['berat_kg'] ?? '', 5000), $angka($k['panjang_cm'] ?? '', 1000), $angka($k['lebar_cm'] ?? '', 1000), $angka($k['tinggi_cm'] ?? '', 1000),
                       mb_substr(trim((string) ($k['catatan'] ?? '')), 0, 500) ?: null, $ppId]);
        $id = (int) $pdo->lastInsertId();
        $hasil[$idx] = ['id' => $id, 'kode' => $noKardus, 'ada' => false];
        kardus_catat($pdo, $id, 'buat', "Dibuat di perpindahan {$pp['no_perpindahan']}", $userId);
    }
    return $hasil;
}

/** Gudang asal sebuah rute (asal gudang, atau gudang pemilik rak asal). */
function kardus_gudang_asal(PDO $pdo, string $asalTipe, ?int $asalId): ?int {
    if (!$asalId) { return null; }
    if ($asalTipe === 'gudang') { return $asalId; }
    if ($asalTipe === 'rak') {
        $st = $pdo->prepare("SELECT gudang_id FROM lokasi_rak WHERE id = ?"); $st->execute([$asalId]);
        return ((int) $st->fetchColumn()) ?: null;
    }
    return null;
}

/* ------------------------------------------- kardus siap kirim (mutasi rak) */
/**
 * Kardus siap kirim boleh dipakai surat jalan ini? Mengembalikan baris kardus
 * (dikunci bila $kunci). Syarat: status Siap dikirim, raknya berada di lokasi
 * asal surat jalan, jenisnya boleh mengambil kardus siap kirim.
 */
function kardus_siap_periksa(PDO $pdo, int $kid, string $jenis, string $asalTipe, ?int $asalId, bool $kunci = false): array {
    $k = kardus_ambil($pdo, $kid, $kunci);
    if (!$k) { throw new RuntimeException("Kardus #$kid tidak ditemukan."); }
    if (!jenis_ambil_kardus_siap($jenis)) { throw new RuntimeException('Kardus siap kirim hanya bisa dibawa surat jalan transfer gudang, kirim toko, atau penyaluran.'); }
    if ($k['status'] !== 'siap_dikirim') {
        throw new RuntimeException('Kardus ' . $k['kode'] . ' berstatus ' . label_status_kardus($k['status'])[0] . ' — hanya kardus Siap dikirim yang bisa dicentang.');
    }
    $gudangAsal = kardus_gudang_asal($pdo, $asalTipe, $asalId);
    $rakOk = $asalTipe === 'rak' ? ((int) $k['lokasi_rak_id'] === (int) $asalId) : ((int) $k['gudang_id'] === (int) $gudangAsal);
    if (!$rakOk) { throw new RuntimeException('Kardus ' . $k['kode'] . ' berada di ' . ($k['rak_nama'] ?: $k['gudang_nama']) . ', bukan di lokasi asal surat jalan ini.'); }
    return $k;
}

/** Kardus Siap dikirim dalam lingkup akun, beserta isinya — untuk dicentang di formulir surat jalan. */
function kardus_siap_daftar(PDO $pdo): array {
    if (!kardus_aktif($pdo)) { return []; }
    $where = "k.status = 'siap_dikirim'";
    if (terikat_satu_gudang() && !is_super()) {
        $ids = array_map('intval', gudang_saya($pdo));
        $where .= $ids ? ' AND k.gudang_id IN (' . implode(',', $ids) . ')' : ' AND 0';
    }
    $rows = $pdo->query(
        "SELECT k.id, k.kode, k.nama, k.gudang_id, k.lokasi_rak_id, k.tanggal_kemas, lr.nama AS rak_nama, lr.kode AS rak_kode
         FROM kardus k LEFT JOIN lokasi_rak lr ON lr.id = k.lokasi_rak_id WHERE $where ORDER BY k.id LIMIT 400")->fetchAll();
    $h = [];
    foreach ($rows as $r) {
        $isi = kardus_isi($pdo, (int) $r['id']);
        if (!$isi) { continue; }
        $h[] = ['id' => (int) $r['id'], 'kode' => $r['kode'], 'nama' => (string) $r['nama'], 'g' => (int) $r['gudang_id'], 'r' => (int) $r['lokasi_rak_id'],
                'rak' => (string) ($r['rak_nama'] ?: $r['rak_kode']),
                'isi' => array_map(fn($i) => ['b' => (int) $i['barang_id'], 'n' => $i['nama_barang'], 'k' => $i['kode_barang'], 's' => $i['satuan'], 'q' => (int) $i['qty']], $isi)];
    }
    return $h;
}

/** Rak tempat isi kardus diambil saat stok dipotong: rak kardus bila berada di lokasi asal dokumen. */
function kardus_rak_ambil(PDO $pdo, int $kid, ?int $gudangAsal, ?int $rakAsal): ?int {
    static $cache = [];
    if (!array_key_exists($kid, $cache)) {
        $st = $pdo->prepare("SELECT k.lokasi_rak_id, lr.gudang_id FROM kardus k LEFT JOIN lokasi_rak lr ON lr.id = k.lokasi_rak_id WHERE k.id = ?");
        $st->execute([$kid]);
        $cache[$kid] = $st->fetch() ?: null;
    }
    $r = $cache[$kid];
    if (!$r || !$r['lokasi_rak_id']) { return null; }
    if ($rakAsal) { return (int) $r['lokasi_rak_id'] === $rakAsal ? $rakAsal : null; }
    return ($gudangAsal && (int) $r['gudang_id'] === $gudangAsal) ? (int) $r['lokasi_rak_id'] : null;
}

/** Mutasi rak tempat kardus dikemas (bila kardus berasal dari sana), selain dokumen $kecuali. */
function kardus_dok_kemas(PDO $pdo, int $kid, int $kecuali = 0): ?int {
    $st = $pdo->prepare("SELECT p.id FROM perpindahan_detail d JOIN perpindahan p ON p.id = d.perpindahan_id
                         WHERE d.kardus_id = ? AND p.jenis = 'mutasi_rak' AND p.status = 'selesai' AND p.id <> ? ORDER BY p.id DESC LIMIT 1");
    $st->execute([$kid, $kecuali]);
    return ((int) $st->fetchColumn()) ?: null;
}

/* --------------------------------------------- ikut status dokumen */
/** Sesudah dokumen disahkan: berangkat (ada penerima), langsung diterima (penyaluran), atau siap kirim (mutasi rak). */
function kardus_sesudah_sah(PDO $pdo, int $ppId, bool $adaPenerima, ?int $userId): void {
    $ids = kardus_di_dokumen($pdo, $ppId);
    if (!$ids) { return; }
    $st = $pdo->prepare("SELECT p.jenis, p.tujuan_tipe, p.tujuan_id, lr.gudang_id AS tujuan_gudang, lr.nama AS rak_nama
                         FROM perpindahan p LEFT JOIN lokasi_rak lr ON p.tujuan_tipe = 'rak' AND lr.id = p.tujuan_id WHERE p.id = ?");
    $st->execute([$ppId]); $pp = $st->fetch() ?: [];
    foreach ($ids as $kid) {
        if (($pp['jenis'] ?? '') === 'mutasi_rak') {
            /* Dikemas sambil dipindah ke rak siap kirim: kardus menunggu di rak tujuan. */
            $pdo->prepare("UPDATE kardus SET status = 'siap_dikirim', lokasi_rak_id = ?, gudang_id = COALESCE(?, gudang_id) WHERE id = ?")
                ->execute([(int) $pp['tujuan_id'], $pp['tujuan_gudang'] ? (int) $pp['tujuan_gudang'] : null, $kid]);
            kardus_catat($pdo, $kid, 'siap', 'Dikemas & dipindah ke rak ' . ($pp['rak_nama'] ?? '') . ' — siap dikirim', $userId);
        } elseif ($adaPenerima) {
            $pdo->prepare("UPDATE kardus SET status = 'dalam_perjalanan' WHERE id = ?")->execute([$kid]);
            kardus_catat($pdo, $kid, 'berangkat', 'Surat jalan disahkan — kardus berangkat', $userId);
        } else {
            $pdo->prepare("UPDATE kardus SET status = 'diterima', diterima_at = NOW(), diterima_oleh = ? WHERE id = ?")->execute([$userId, $kid]);
            kardus_catat($pdo, $kid, 'terima', 'Disalurkan langsung (dokumen disahkan)', $userId);
        }
    }
}

/**
 * Sesudah penerimaan dokumen dikonfirmasi: kondisi fisik, catatan, foto, dan
 * status tiap kardus. Jumlah diterima per isi sudah ada di perpindahan_detail.
 * $kondisi/$catatan/$foto diindeks id kardus. $detail = baris detail berisi qty_diterima.
 */
function kardus_sesudah_terima(PDO $pdo, int $ppId, array $detail, array $kondisi, array $catatan, array $foto, ?int $userId): array {
    $ringkas = [];
    foreach (kardus_di_dokumen($pdo, $ppId) as $kid) {
        $k = kardus_ambil($pdo, $kid, true); if (!$k) { continue; }
        $selisih = false;
        foreach ($detail as $d) {
            if ((int) ($d['kardus_id'] ?? 0) !== $kid) { continue; }
            $terima = $d['qty_diterima'] !== null ? (int) $d['qty_diterima'] : (int) $d['qty'];
            if ($terima !== (int) $d['qty']) { $selisih = true; }
        }
        $kd = (string) ($kondisi[$kid] ?? 'baik');
        if (!isset(KARDUS_KONDISI[$kd])) { $kd = 'baik'; }
        if ($selisih && $kd === 'baik') { $kd = 'perlu_pemeriksaan'; }
        $pdo->prepare("UPDATE kardus SET status = 'diterima', kondisi = ?, diterima_at = NOW(), diterima_oleh = ?, catatan_terima = ?, foto_terima = COALESCE(?, foto_terima) WHERE id = ?")
            ->execute([$kd, $userId, mb_substr(trim((string) ($catatan[$kid] ?? '')), 0, 500) ?: null, $foto[$kid] ?? null, $kid]);
        kardus_catat($pdo, $kid, 'terima', 'Diterima — kondisi ' . label_kondisi_kardus($kd)[0] . ($selisih ? ', ADA SELISIH jumlah' : '')
                     . (trim((string) ($catatan[$kid] ?? '')) !== '' ? ': ' . trim((string) $catatan[$kid]) : ''), $userId);
        $ringkas[] = $k['kode'] . ' (' . label_kondisi_kardus($kd)[0] . ')';
    }
    return $ringkas;
}

/**
 * Dokumen ditolak / dibatalkan. Kardus yang dibuat di dokumen itu ikut
 * dibatalkan; kardus siap kirim yang hanya "dipinjam" surat jalan ini
 * kembali menunggu di raknya (Siap dikirim) supaya bisa dicentang lagi.
 */
function kardus_batal_dokumen(PDO $pdo, int $ppId, ?int $userId, string $alasan = ''): int {
    $n = 0;
    foreach (kardus_di_dokumen($pdo, $ppId) as $kid) {
        if ($kemas = kardus_dok_kemas($pdo, $kid, $ppId)) {
            $pdo->prepare("UPDATE kardus SET perpindahan_id = ?, status = 'siap_dikirim' WHERE id = ?")->execute([$kemas, $kid]);
            kardus_catat($pdo, $kid, 'lepas_sj', 'Surat jalan dibatalkan — kardus kembali siap dikirim' . ($alasan !== '' ? ': ' . $alasan : ''), $userId);
        } else {
            $pdo->prepare("UPDATE kardus SET status = 'dibatalkan', alasan_batal = ? WHERE id = ?")->execute([mb_substr($alasan, 0, 255) ?: null, $kid]);
            kardus_catat($pdo, $kid, 'batal', 'Dokumen dibatalkan' . ($alasan !== '' ? ': ' . $alasan : ''), $userId);
        }
        $n++;
    }
    return $n;
}

/** Draft dihapus: kardusnya ikut terhapus — kecuali kardus siap kirim pinjaman, yang kembali ke raknya. */
function kardus_hapus_dokumen(PDO $pdo, int $ppId): void {
    if (!kardus_aktif($pdo)) { return; }
    $st = $pdo->prepare("SELECT id FROM kardus WHERE perpindahan_id = ?"); $st->execute([$ppId]);
    foreach (array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN)) as $kid) {
        if ($kemas = kardus_dok_kemas($pdo, $kid, $ppId)) {
            $pdo->prepare("UPDATE kardus SET perpindahan_id = ?, status = 'siap_dikirim' WHERE id = ?")->execute([$kemas, $kid]);
            kardus_catat($pdo, $kid, 'lepas_sj', 'Draft surat jalan dihapus — kardus kembali siap dikirim');
        } else {
            $pdo->prepare("DELETE FROM kardus WHERE id = ?")->execute([$kid]);   // riwayat ikut (FK cascade)
        }
    }
}

/** Posisi terakhir kardus — mengikuti dokumennya (lihat posisi_kiriman di _lacak.php). */
function kardus_posisi(PDO $pdo, array $k): string {
    if ($k['status'] === 'dibatalkan') { return 'Dibatalkan'; }
    if ($k['status'] === 'siap_dikirim') { return 'Siap kirim di ' . ($k['rak_nama'] ?? $k['rak_kode'] ?? '') . (!empty($k['gudang_nama']) ? ' — ' . $k['gudang_nama'] : ''); }
    if (empty($k['perpindahan_id'])) { return $k['gudang_nama'] ?: '—'; }
    $pp = ['id' => (int) $k['perpindahan_id'], 'status' => $k['pp_status'], 'jenis' => $k['pp_jenis'],
           'asal_tipe' => $k['asal_tipe'], 'asal_id' => $k['asal_id'], 'asal_teks' => $k['asal_teks'],
           'tujuan_tipe' => $k['tujuan_tipe'], 'tujuan_id' => $k['tujuan_id'], 'tujuan_teks' => $k['tujuan_teks'],
           'diterima_at' => $k['pp_diterima_at'] ?? null];
    try {
        $pos = posisi_kiriman($pdo, $pp, titik_transit($pdo, (int) $k['perpindahan_id']));
        return (string) ($pos['teks'] ?? '—');
    } catch (Throwable $e) { return '—'; }
}
