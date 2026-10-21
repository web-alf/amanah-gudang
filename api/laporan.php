<?php
/**
 * GET /api/laporan?tipe=stok|masuk|keluar|expired
 * Laporan ringkasan untuk mobile.
 */
require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/_lingkup.php';
only('GET');
$payload = auth_required();

$tipe    = $_GET['tipe'] ?? 'stok';
[$limit, $offset] = paginate(50);

/* Angka rupiah hanya untuk tingkat manager — cerminan boleh_lihat_nilai()
   di _lib.php. Ditegakkan DI SINI, bukan diserahkan ke aplikasi untuk
   menyembunyikan kolomnya: siapa pun yang memegang token bisa memanggil
   endpoint ini langsung, dan nilai persediaan lembaga bukan angka yang
   boleh terbaca hanya karena tombolnya disembunyikan. */
$lihatNilai = api_lihat_nilai($pdo, $payload);

/* -----------------------------------------------------------------------
   LINGKUP — siapa melihat persediaan siapa
   -----------------------------------------------------------------------
   Sebelumnya berkas ini hanya memanggil auth_required(), tanpa penyaring
   lingkup sama sekali. Akibatnya token supplier atau toko bisa menarik
   SELURUH stok lembaga lewat tipe=stok, dan tipe=expired memberi nomor
   batch beserta nama rak dan gudang setiap barang di seluruh yayasan.

   Aplikasinya memang tidak menggambar itu di layar mereka, tapi angkanya
   tetap terkirim — dan yang memegang token bisa memanggil endpoint ini
   langsung. Menyembunyikan kolom bukan penyaringan.

   Yang benar adalah persis yang sudah dilakukan halaman laporan.php di
   web: view v_stok_rantai dan v_expired_rantai disaring syarat_lingkup().
   Di sini dipakai padanannya untuk konteks token, sehingga web dan API
   memakai satu aturan — bukan dua yang perlahan berbeda.
   ----------------------------------------------------------------------- */
$syaratStok = api_syarat_lingkup($pdo, $payload, 'v');

/* ARUS MASUK/KELUAR — hanya lingkup lembaga dan akun terikat satu gudang.

   Cerminan $lihatArus di laporan.php web: `$L['semua'] || terikat_satu_gudang()`.
   Supplier dan toko tidak mendapatkannya di web, dan tidak boleh
   mendapatkannya di sini.

   Angka masuk/keluar adalah arus barang LEMBAGA — transaksi_keluar bahkan
   memuat nama penerima dan divisi cabang. Bagi mitra itu bukan sekadar
   angka yang tak relevan, melainkan gambaran operasional pihak lain.

   Ditolak, bukan dikosongkan: daftar kosong terbaca sebagai "belum ada
   transaksi", dan itu keterangan yang salah tentang keadaan lembaga. */
$L = api_lingkup_stok($pdo, $payload);
$lihatArus = $L['semua'] || api_terikat_gudang($payload);

/** Buang kolom rupiah bila pembacanya tidak berhak. */
function saring_nilai(array $baris, bool $boleh, array $kolom): array {
    if ($boleh) { return $baris; }
    foreach ($baris as &$b) {
        foreach ($kolom as $k) { unset($b[$k]); }
    }
    return $baris;
}

switch ($tipe) {

    // --- Stok per barang ---
    case 'stok':
        $katId = (int) ($_GET['kategori_id'] ?? 0);
        $cari  = trim($_GET['q'] ?? '');
        $where = ['b.deleted_at IS NULL'];
        $params = [];
        if ($cari) {
            $where[]  = '(b.nama LIKE ? OR b.kode LIKE ?)';
            $like     = '%' . $cari . '%';
            $params[] = $like;
            $params[] = $like;
        }
        if ($katId) {
            $where[]  = 'b.kategori_id = ?';
            $params[] = $katId;
        }
        $wSql = implode(' AND ', $where);
        try {
            /* Stok dihitung dari v_stok_rantai, BUKAN dari cache `stok`.

               Dua alasan, dan keduanya menentukan:

                 1. `stok.qty_total` adalah angka SELURUH lembaga. Tidak ada
                    kolom di sana yang bisa disaring per rak, per toko, atau
                    per mitra — jadi selama angkanya diambil dari situ,
                    lingkup apa pun tidak mungkin ditegakkan.

                 2. v_stok_rantai membawa lokasi_rak_id, toko_id, dan
                    supplier_id sekaligus, sehingga satu klausa yang sama
                    melayani gudang, toko, maupun mitra.

               Barang yang tidak punya stok dalam lingkup ini tetap muncul
               dengan angka nol — LEFT JOIN, bukan INNER. Laporan yang
               membuang barang berstok nol menyembunyikan justru barang yang
               paling perlu dilihat: yang habis. */
            $sub = "(SELECT v.barang_id, SUM(v.qty) AS qty
                     FROM v_stok_rantai v
                     WHERE v.qty > 0 AND $syaratStok
                     GROUP BY v.barang_id)";

            $stCount = $pdo->prepare("SELECT COUNT(*) FROM barang b WHERE $wSql");
            $stCount->execute($params);
            $total = (int) $stCount->fetchColumn();

            $st = $pdo->prepare(
                "SELECT b.id, b.kode, b.nama, b.satuan, b.stok_minimum,
                        k.nama AS kategori,
                        COALESCE(vs.qty,0) AS stok,
                        COALESCE(vs.qty,0)*b.harga AS nilai
                 FROM barang b
                 LEFT JOIN kategori k ON k.id = b.kategori_id
                 LEFT JOIN $sub AS vs ON vs.barang_id = b.id
                 WHERE $wSql
                 ORDER BY b.nama ASC
                 LIMIT $limit OFFSET $offset"
            );
            $st->execute($params);
            ok([
                'data'        => saring_nilai($st->fetchAll(), $lihatNilai, ['nilai']),
                'total'       => $total,
                'lihat_nilai' => $lihatNilai,
            ]);
        } catch (Throwable $e) {
            error_log('[AMANAH API] laporan stok: ' . $e->getMessage());
            err('Gagal menyusun laporan stok.', 500);
        }
        break;

    // --- Transaksi masuk ---
    case 'masuk':
        if (!$lihatArus) {
            err('Laporan arus barang khusus staf gudang lembaga.', 403);
        }
        $dari = $_GET['dari'] ?? date('Y-m-01');
        $ke   = $_GET['ke']   ?? date('Y-m-d');
        try {
            $st = $pdo->prepare(
                /* COUNT(DISTINCT h.id), bukan COUNT(*): sekali JOIN ke
                   detail, satu transaksi berisi lima jenis barang terhitung
                   lima transaksi. Angka "jumlah transaksi hari ini" jadi
                   membesar seiring banyaknya item, bukan banyaknya kejadian.

                   d.subtotal, bukan d.qty*d.harga_satuan: kolom
                   harga_satuan tidak ada (namanya `harga`), dan subtotal
                   memang sudah dihitung saat penyimpanan. */
                "SELECT DATE(h.tanggal) AS tgl, COUNT(DISTINCT h.id) AS jml_transaksi,
                        COALESCE(SUM(d.qty),0) AS total_qty,
                        COALESCE(SUM(d.subtotal),0) AS total_nilai
                 FROM transaksi_masuk h
                 JOIN transaksi_masuk_detail d ON d.transaksi_masuk_id=h.id
                 WHERE DATE(h.tanggal) BETWEEN ? AND ?
                 GROUP BY DATE(h.tanggal) ORDER BY tgl DESC
                 LIMIT $limit OFFSET $offset"
            );
            $st->execute([$dari, $ke]);
            ok([
                'data'        => saring_nilai($st->fetchAll(), $lihatNilai, ['total_nilai']),
                'periode'     => ['dari' => $dari, 'ke' => $ke],
                'lihat_nilai' => $lihatNilai,
            ]);
        } catch (Throwable $e) {
            error_log('[AMANAH API] laporan masuk: ' . $e->getMessage());
            err('Gagal menyusun laporan barang masuk.', 500);
        }
        break;

    // --- Transaksi keluar ---
    case 'keluar':
        if (!$lihatArus) {
            err('Laporan arus barang khusus staf gudang lembaga.', 403);
        }
        $dari = $_GET['dari'] ?? date('Y-m-01');
        $ke   = $_GET['ke']   ?? date('Y-m-d');
        try {
            $st = $pdo->prepare(
                // COUNT(DISTINCT h.id) — alasan sama dengan laporan masuk.
                "SELECT DATE(h.tanggal) AS tgl, COUNT(DISTINCT h.id) AS jml_transaksi,
                        COALESCE(SUM(d.qty),0) AS total_qty
                 FROM transaksi_keluar h
                 JOIN transaksi_keluar_detail d ON d.transaksi_keluar_id=h.id
                 WHERE DATE(h.tanggal) BETWEEN ? AND ?
                 GROUP BY DATE(h.tanggal) ORDER BY tgl DESC
                 LIMIT $limit OFFSET $offset"
            );
            $st->execute([$dari, $ke]);
            ok([
                'data'        => $st->fetchAll(),
                'periode'     => ['dari' => $dari, 'ke' => $ke],
                'lihat_nilai' => $lihatNilai,
            ]);
        } catch (Throwable $e) {
            error_log('[AMANAH API] laporan keluar: ' . $e->getMessage());
            err('Gagal menyusun laporan barang keluar.', 500);
        }
        break;

    // --- Barang mendekati/sudah expired ---
    case 'expired':
        try {
            /* v_expired_rantai, BUKAN batch_barang langsung.

               Kueri lamanya membaca seluruh batch_barang tanpa penyaring
               apa pun, dan ikut mengirim nama rak serta nama gudang. Bagi
               token mitra atau toko itu berarti peta isi gudang yayasan —
               nomor batch, tanggal kedaluwarsa, dan letaknya.

               View ini sudah membawa lokasi_rak_id / toko_id / supplier_id
               sehingga bisa disaring, dan lokasi_nama-nya sudah dirangkum
               dari sisi mana pun stoknya berada. */
            $syaratExp = api_syarat_lingkup($pdo, $payload, 'v');
            $st = $pdo->query(
                /* `rak` dan `gudang` tetap dikirim meski lokasi_nama sudah
                   memuat keduanya: layar Laporan di aplikasi yang sudah
                   terpasang membacanya, dan menghilangkannya membuat
                   kolomnya mendadak kosong pada versi lama tanpa satu pun
                   pesan yang menjelaskan. Keduanya kosong untuk stok yang
                   ada di toko atau di mitra — di sana memang tidak ada rak. */
                "SELECT v.nama_barang AS nama, v.nomor_batch, v.tanggal_expired,
                        v.qty_tersisa, v.sisa_hari, v.status_expired,
                        v.lokasi_tipe, v.lokasi_nama,
                        COALESCE(lr.kode,'') AS rak,
                        COALESCE(g.nama,'')  AS gudang,
                        b.kode, b.satuan
                 FROM v_expired_rantai v
                 JOIN barang b ON b.id = v.barang_id
                 LEFT JOIN lokasi_rak lr ON lr.id = v.lokasi_rak_id
                 LEFT JOIN gudang g      ON g.id = lr.gudang_id
                 WHERE v.qty_tersisa > 0
                   AND v.tanggal_expired IS NOT NULL
                   AND v.tanggal_expired <= DATE_ADD(CURDATE(), INTERVAL 90 DAY)
                   AND $syaratExp
                 ORDER BY v.tanggal_expired ASC
                 LIMIT $limit OFFSET $offset"
            );
            ok(['data' => $st->fetchAll()]);
        } catch (Throwable $e) {
            error_log('[AMANAH API] laporan expired: ' . $e->getMessage());
            err('Gagal menyusun laporan kedaluwarsa.', 500);
        }
        break;

    default:
        err("Tipe laporan '$tipe' tidak dikenal. Pilihan: stok, masuk, keluar, expired.");
}
