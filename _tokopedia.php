<?php
/**
 * AMANAH — Klien Tokopedia (Fulfillment Service)
 *
 * ====================================================================
 * PERINGATAN YANG SAMA SEPERTI PADA KLIEN SHOPEE
 * ====================================================================
 * Jalur endpoint dan nama field di sini disusun mengikuti pola Tokopedia
 * Fulfillment Service, TETAPI belum pernah diuji terhadap API sungguhan —
 * kredensialnya masih dalam proses. Sebelum dipakai ke lapak sungguhan,
 * wajib dicocokkan ke dokumentasi yang berlaku, dijalankan sekali ke lapak
 * uji, lalu pemetaan field-nya disesuaikan dari balasan mentah yang
 * tersimpan di marketplace_tarikan.
 * ====================================================================
 *
 * PERBEDAAN MENDASAR DARI SHOPEE — ini yang paling sering menyesatkan
 * kalau dianggap sama:
 *
 *   Shopee     : setiap lapak MENYETUJUI aplikasi lewat halaman otorisasi
 *                (pemilik toko diarahkan ke Shopee, lalu kembali membawa
 *                kode). Hasilnya access_token + refresh_token per lapak.
 *
 *   Tokopedia  : TIDAK ada halaman otorisasi per lapak. Yang ada adalah
 *                satu kredensial Fulfillment Service (client_id +
 *                client_secret + fs_id), dan token diminta langsung dengan
 *                kredensial itu. Tidak ada refresh_token — kalau token
 *                kedaluwarsa, diminta ulang dari awal.
 *
 * Akibatnya di tampilan: tombol "Otorisasi" hanya masuk akal untuk Shopee.
 * Untuk Tokopedia, mengisi kredensial saja sudah cukup — dan itulah kenapa
 * marketplace.php menampilkan tombol yang berbeda per platform, bukan satu
 * tombol yang sama untuk semuanya.
 */

if (!defined('TOPED_HOST')) {
    define('TOPED_HOST', 'https://fs.tokopedia.net');
}

const TOPED_JALUR = [
    'token'          => '/token?grant_type=client_credentials',
    'shop_info'      => '/v1/shop/fs/%s/shop-info',
    'pesanan_list'   => '/v2/order/list',
    'pesanan_detail' => '/v1/order/%s/fs/%s',
];

const TOPED_MAKS_PESANAN = 50;

// =====================================================================
// TOKEN
// =====================================================================
/**
 * Minta access_token baru.
 *
 * Tokopedia memakai OAuth2 client_credentials dengan Basic auth. Tidak ada
 * refresh_token: setiap kali kedaluwarsa, token diminta ulang memakai
 * kredensial yang sama. Karena itu kolom refresh_token dibiarkan kosong
 * untuk platform ini — mengisinya dengan nilai palsu hanya akan membuat
 * kode lain menyangka ada jalur penyegaran yang sebenarnya tidak ada.
 */
function toped_minta_token(PDO $pdo, array $akun): array
{
    $url = TOPED_HOST . TOPED_JALUR['token'];
    $h   = curl_init($url);
    curl_setopt_array($h, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => '',
        CURLOPT_TIMEOUT        => 25,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        // Basic auth: client_id dijadikan pengguna, client_secret jadi
        // sandinya. CURLOPT_USERPWD menyusun headernya sendiri, jadi
        // kredensialnya tidak pernah muncul di URL — dan karena itu tidak
        // ikut tercatat di log server mana pun.
        CURLOPT_USERPWD        => $akun['partner_id'] . ':' . $akun['partner_key'],
        CURLOPT_HTTPAUTH       => CURLAUTH_BASIC,
    ]);
    $mentah = curl_exec($h);
    $kode   = (int) curl_getinfo($h, CURLINFO_HTTP_CODE);
    $galat  = curl_error($h);
    curl_close($h);

    $hasil = ['ok' => false, 'kode' => $kode, 'data' => [], 'pesan' => '',
              'mentah' => (string) $mentah];

    if ($mentah === false) {
        $hasil['pesan'] = 'Tidak dapat menghubungi Tokopedia: ' . $galat;
    } else {
        $d = json_decode((string) $mentah, true);
        if (!is_array($d)) {
            $hasil['pesan'] = 'Balasan token bukan JSON yang bisa dibaca.';
        } elseif (empty($d['access_token'])) {
            $hasil['pesan'] = trim(($d['error'] ?? 'Token tidak diterima') . ' — '
                                 . ($d['error_description'] ?? ''));
        } else {
            $hasil['ok']   = true;
            $hasil['data'] = $d;
        }
    }

    toped_catat($pdo, (int) $akun['toko_id'], '/token', $hasil);

    if ($hasil['ok']) {
        $detik = (int) ($hasil['data']['expires_in'] ?? 3600);
        $pdo->prepare(
            "UPDATE marketplace_akun
                SET access_token = ?, refresh_token = '',
                    token_expired_at = DATE_ADD(NOW(), INTERVAL ? SECOND),
                    status = 'tersambung', pesan_terakhir = NULL,
                    terakhir_segar_at = NOW()
              WHERE id = ?"
        )->execute([$hasil['data']['access_token'], max(60, $detik - 300), $akun['id']]);
    } else {
        $pdo->prepare("UPDATE marketplace_akun SET status='error', pesan_terakhir=?
                       WHERE id=?")->execute([mb_substr($hasil['pesan'], 0, 500), $akun['id']]);
    }
    return $hasil;
}

/** Token masih layak dipakai? Ambang 30 menit, sama seperti Shopee. */
function toped_perlu_token(array $akun): bool
{
    if (empty($akun['access_token']) || empty($akun['token_expired_at'])) { return true; }
    return strtotime($akun['token_expired_at']) - time() < 1800;
}

// =====================================================================
// PEMANGGILAN
// =====================================================================
/**
 * Panggil satu endpoint Tokopedia dengan Bearer token.
 *
 * Sama seperti klien Shopee, kegagalan TIDAK melempar exception: cron
 * memproses banyak lapak, dan satu lapak yang kredensialnya bermasalah
 * tidak boleh menghentikan lapak lainnya.
 */
function toped_panggil(string $jalur, array $akun, array $param = []): array
{
    $url = TOPED_HOST . $jalur . ($param ? '?' . http_build_query($param) : '');
    $h   = curl_init($url);
    curl_setopt_array($h, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 25,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_HTTPHEADER     => [
            'Authorization: Bearer ' . ($akun['access_token'] ?? ''),
            'Content-Type: application/json',
        ],
    ]);
    $mentah = curl_exec($h);
    $kode   = (int) curl_getinfo($h, CURLINFO_HTTP_CODE);
    $galat  = curl_error($h);
    curl_close($h);

    if ($mentah === false) {
        return ['ok' => false, 'kode' => 0, 'data' => [],
                'pesan' => 'Tidak dapat menghubungi Tokopedia: ' . $galat, 'mentah' => ''];
    }
    $d = json_decode((string) $mentah, true);
    if (!is_array($d)) {
        return ['ok' => false, 'kode' => $kode, 'data' => [],
                'pesan' => 'Balasan bukan JSON yang bisa dibaca.', 'mentah' => (string) $mentah];
    }

    /* Tokopedia menandai kegagalan lewat header_status / errors, dan kode
       HTTP-nya tidak selalu ikut berubah. Memeriksa HTTP saja akan
       menganggap "token kedaluwarsa" sebagai keberhasilan. */
    $adaGalat = !empty($d['errors'])
             || (isset($d['header']['process_time']) && !empty($d['header']['messages']))
             || $kode >= 400;

    $pesan = '';
    if ($adaGalat) {
        if (!empty($d['errors'][0]['detail'])) { $pesan = (string) $d['errors'][0]['detail']; }
        elseif (!empty($d['errors'][0]['message'])) { $pesan = (string) $d['errors'][0]['message']; }
        elseif (!empty($d['header']['messages'])) { $pesan = (string) $d['header']['messages']; }
        else { $pesan = 'Permintaan ditolak (HTTP ' . $kode . ').'; }
    }

    return ['ok' => !$adaGalat, 'kode' => $kode,
            'data' => $d['data'] ?? $d, 'pesan' => $pesan, 'mentah' => (string) $mentah];
}

/** Jejak panggilan, memakai tabel yang sama dengan Shopee. */
function toped_catat(PDO $pdo, int $tokoId, string $jalur, array $hasil,
                     ?string $noPesanan = null): void
{
    try {
        $pdo->prepare(
            "INSERT INTO marketplace_tarikan
               (toko_id, platform, no_pesanan, endpoint, kode_http, sukses, pesan, data_mentah)
             VALUES (?,'tokopedia',?,?,?,?,?,?)"
        )->execute([
            $tokoId, $noPesanan, $jalur, $hasil['kode'], $hasil['ok'] ? 1 : 0,
            mb_substr($hasil['pesan'], 0, 500),
            mb_substr($hasil['mentah'], 0, 60000),
        ]);
    } catch (Throwable $e) {
        error_log('[AMANAH] toped_catat: ' . $e->getMessage());
    }
}

// =====================================================================
// PEMETAAN STATUS
// =====================================================================
/**
 * Status Tokopedia → status internal.
 *
 * Tokopedia memakai KODE ANGKA, bukan teks seperti Shopee. Angkanya
 * dikelompokkan menurut rentang karena Tokopedia menambah kode baru dari
 * waktu ke waktu, dan pengelompokan per rentang jauh lebih tahan terhadap
 * penambahan itu daripada daftar angka satu per satu.
 *
 * Kode yang tidak dikenali jatuh ke 'baru' — sama seperti pada Shopee,
 * supaya pesanan yang belum terpetakan tetap terlihat operator.
 */
function toped_status_pesanan(int $kode): string
{
    if ($kode < 0)                     { return 'batal'; }   // ditolak / dibatalkan
    if ($kode === 0 || $kode === 100)  { return 'baru'; }    // menunggu pembayaran
    if ($kode >= 100 && $kode < 400)   { return 'baru'; }    // sudah bayar, belum diproses
    if ($kode >= 400 && $kode < 500)   { return 'dikemas'; } // diproses penjual
    if ($kode >= 500 && $kode < 600)   { return 'dikirim'; } // diserahkan ke kurir
    if ($kode >= 600 && $kode < 700)   { return 'selesai'; } // sampai / selesai
    if ($kode >= 700)                  { return 'batal'; }   // batal / retur
    return 'baru';
}

function toped_status_kirim(int $kode): string
{
    if ($kode < 0)                   { return 'belum'; }
    if ($kode >= 700)                { return 'kembali'; }
    if ($kode >= 600 && $kode < 700) { return 'sampai'; }
    if ($kode >= 500 && $kode < 600) { return 'dikirim'; }
    if ($kode >= 400 && $kode < 500) { return 'diproses'; }
    return 'belum';
}

// =====================================================================
// PENARIKAN
// =====================================================================
/**
 * Tarik pesanan satu lapak Tokopedia.
 *
 * Bentuk kembaliannya SAMA dengan shopee_tarik_pesanan() supaya cron dan
 * halaman pengaturan tidak perlu tahu sedang bicara dengan platform mana.
 */
function toped_tarik_pesanan(PDO $pdo, array $akun, int $hariKeBelakang = 7): array
{
    $ringkas = ['baru' => 0, 'diperbarui' => 0, 'gagal' => 0, 'pesan' => []];
    $tokoId  = (int) $akun['toko_id'];

    if (empty($akun['fs_id'])) {
        $ringkas['pesan'][] = 'FS ID belum diisi. Tokopedia tidak bisa ditarik tanpa itu.';
        return $ringkas;
    }

    if (toped_perlu_token($akun)) {
        $tok = toped_minta_token($pdo, $akun);
        if (!$tok['ok']) {
            $ringkas['pesan'][] = 'Token gagal diambil: ' . $tok['pesan'];
            return $ringkas;
        }
        $akun = toped_akun($pdo, (int) $akun['id']) ?: $akun;
    }

    $sampai = time();
    $dari   = $sampai - ($hariKeBelakang * 86400);

    $daftar = toped_panggil(TOPED_JALUR['pesanan_list'], $akun, [
        'fs_id'     => $akun['fs_id'],
        'shop_id'   => $akun['shop_id'],
        'from_date' => $dari * 1000,   // Tokopedia memakai milidetik
        'to_date'   => $sampai * 1000,
        'page'      => 1,
        'per_page'  => TOPED_MAKS_PESANAN,
    ]);
    toped_catat($pdo, $tokoId, TOPED_JALUR['pesanan_list'], $daftar);

    if (!$daftar['ok']) {
        $ringkas['pesan'][] = $daftar['pesan'];
        return $ringkas;
    }

    $pesananList = is_array($daftar['data']) ? $daftar['data'] : [];
    if (!$pesananList) {
        $ringkas['pesan'][] = 'Tidak ada pesanan dalam ' . $hariKeBelakang . ' hari terakhir.';
        return $ringkas;
    }

    foreach ($pesananList as $p) {
        if (!is_array($p)) { continue; }
        try {
            $ringkas[toped_serap_satu($pdo, $akun, $p)]++;
        } catch (Throwable $e) {
            $ringkas['gagal']++;
            $ringkas['pesan'][] = ($p['invoice_ref_num'] ?? '?') . ': ' . $e->getMessage();
            error_log('[AMANAH] toped_serap: ' . $e->getMessage());
        }
    }

    $pdo->prepare("UPDATE marketplace_akun SET terakhir_tarik_at = NOW() WHERE id = ?")
        ->execute([$akun['id']]);

    return $ringkas;
}

/**
 * Serap satu pesanan Tokopedia.
 *
 * Berbeda dari Shopee, daftar pesanan Tokopedia sudah memuat cukup banyak
 * field, jadi tidak selalu perlu panggilan detail terpisah. Yang belum ada
 * di daftar barulah diambil menyusul — menghemat kuota panggilan, yang di
 * sini bukan penghematan teoretis: batas panggilan adalah alasan paling
 * sering penarikan gagal separuh jalan.
 */
function toped_serap_satu(PDO $pdo, array $akun, array $p): string
{
    $sn = (string) ($p['invoice_ref_num'] ?? $p['order_id'] ?? '');
    if ($sn === '') { throw new RuntimeException('Pesanan tanpa nomor.'); }

    $kodeStatus = (int) ($p['order_status'] ?? 0);

    /* Nilai uang Tokopedia tersebar di beberapa field, dan namanya berbeda
       antar versi. Diambil dengan urutan cadangan, dan yang tidak ditemukan
       dibiarkan NULL — bukan diisi nol. Nol berarti "tidak ada potongan",
       sedangkan NULL berarti "belum diketahui", dan keduanya tidak boleh
       tertukar di laporan uang. */
    $ambil = function (array $sumber, array $kunci) {
        foreach ($kunci as $k) {
            if (isset($sumber[$k]) && $sumber[$k] !== '') { return (float) $sumber[$k]; }
        }
        return null;
    };

    $total  = $ambil($p, ['total_amount', 'order_amt', 'total_price']) ?? 0.0;
    $ongkir = $ambil($p, ['shipping_cost', 'logistic_price', 'insurance_price']) ?? 0.0;
    $escrow = $ambil($p, ['seller_net_income', 'net_income', 'total_net_income']);

    $baris = [
        'toko_id'            => (int) $akun['toko_id'],
        'platform'           => 'tokopedia',
        'no_pesanan'         => $sn,
        'nama_pembeli'       => mb_substr((string) ($p['buyer_name'] ?? ''), 0, 150) ?: null,
        'tanggal'            => !empty($p['create_time'])
                                  ? date('Y-m-d H:i:s', (int) ($p['create_time'] / 1000))
                                  : date('Y-m-d H:i:s'),
        'total'              => $total,
        'ongkir'             => $ongkir,
        'status'             => toped_status_pesanan($kodeStatus),
        'status_marketplace' => (string) $kodeStatus,
        'status_kirim'       => toped_status_kirim($kodeStatus),
        'kurir'              => mb_substr((string) ($p['shipping_agency'] ?? ''), 0, 80) ?: null,
        'no_resi'            => mb_substr((string) ($p['awb'] ?? $p['awb_num'] ?? ''), 0, 80) ?: null,
        'escrow_amount'      => $escrow,
        'komisi'             => $ambil($p, ['commission', 'commission_fee']) ?? 0.0,
        'biaya_layanan'      => $ambil($p, ['service_fee']) ?? 0.0,
        'biaya_transaksi'    => $ambil($p, ['transaction_fee']) ?? 0.0,
        'voucher'            => $ambil($p, ['voucher_amount', 'seller_voucher']) ?? 0.0,
        'status_bayar'       => toped_status_bayar($kodeStatus, $escrow),
        'tgl_settlement'     => null,
    ];

    return marketplace_simpan_penjualan($pdo, $baris);
}

/**
 * Status pembayaran Tokopedia.
 *
 * Tokopedia tidak memakai istilah escrow seterang Shopee, tetapi dananya
 * sama-sama baru bisa dipakai setelah pesanan selesai. Jadi 'ditahan'
 * dipakai untuk pesanan yang sudah dibayar tapi belum tuntas — bukan
 * langsung 'cair'. Menyatakannya cair lebih awal membuat toko menghitung
 * uang yang belum ada di tangannya.
 */
function toped_status_bayar(int $kodeStatus, ?float $escrow): string
{
    if ($kodeStatus < 0 || $kodeStatus >= 700) { return 'batal'; }
    if ($kodeStatus < 100)                     { return 'belum'; }
    if ($kodeStatus >= 600)                    { return 'cair'; }
    return $escrow !== null && $escrow > 0 ? 'ditahan' : 'belum';
}

// =====================================================================
// PEMBANTU
// =====================================================================
function toped_akun(PDO $pdo, int $id): ?array
{
    $st = $pdo->prepare("SELECT * FROM marketplace_akun WHERE id = ?");
    $st->execute([$id]);
    return $st->fetch() ?: null;
}

function toped_akun_aktif(PDO $pdo): array
{
    try {
        return $pdo->query(
            "SELECT * FROM marketplace_akun
             WHERE platform = 'tokopedia' AND aktif = 1
               AND partner_id <> '' AND partner_key <> ''
               AND fs_id IS NOT NULL AND fs_id <> ''
             ORDER BY COALESCE(terakhir_tarik_at, '2000-01-01') ASC"
        )->fetchAll();
    } catch (Throwable $e) {
        error_log('[AMANAH] toped_akun_aktif: ' . $e->getMessage());
        return [];
    }
}
