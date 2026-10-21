<?php
/**
 * AMANAH — Klien Shopee Open Platform
 *
 * ====================================================================
 * PERINGATAN YANG HARUS DIBACA SEBELUM DIPAKAI KE TOKO SUNGGUHAN
 * ====================================================================
 * Jalur endpoint dan nama field di berkas ini disusun mengikuti pola
 * Shopee Open Platform v2 yang umum dipakai, TETAPI belum pernah diuji
 * terhadap API Shopee yang sebenarnya — karena kredensial partner-nya
 * masih dalam proses pendaftaran.
 *
 * Dokumentasi Shopee berubah, dan nama field balasan API kadang berbeda
 * antar wilayah. Jadi sebelum dipakai ke toko sungguhan, WAJIB:
 *   1. cocokkan setiap jalur di KONSTANTA di bawah dengan dokumentasi
 *      Shopee yang berlaku saat itu;
 *   2. jalankan sekali ke satu toko uji, lalu buka Sambungan Marketplace
 *      dan periksa balasan mentahnya di tabel marketplace_tarikan;
 *   3. sesuaikan pemetaan field di petakan_*() bila ada yang berbeda.
 *
 * Balasan mentah SELALU disimpan justru untuk keperluan langkah 2 —
 * memetakan field dari dokumentasi jauh lebih rawan daripada memetakan
 * dari balasan yang sungguh-sungguh diterima.
 * ====================================================================
 *
 * Yang TIDAK dilakukan berkas ini, dengan sengaja:
 *   • tidak menyentuh stok. Penarikan hanya mencatat pesanan; pengurangan
 *     stok tetap lewat jalur penjualan yang sudah ada, supaya satu
 *     kesalahan pemetaan tidak langsung merusak angka stok.
 *   • tidak menghapus apa pun.
 */

if (!defined('SHOPEE_HOST')) {
    /* Host produksi. Untuk pengujian, Shopee menyediakan lingkungan
       sandbox dengan host berbeda — disetel lewat config.local.php
       supaya tidak tertukar dengan produksi karena salah menyunting. */
    define('SHOPEE_HOST', 'https://partner.shopeemobile.com');
}

/** Jalur API. Dikumpulkan di satu tempat supaya mudah dicocokkan ke dokumentasi. */
const SHOPEE_JALUR = [
    'token_baru'   => '/api/v2/auth/token/get',
    'token_segar'  => '/api/v2/auth/access_token/get',
    'shop_info'    => '/api/v2/shop/get_shop_info',
    'pesanan_list' => '/api/v2/order/get_order_list',
    'pesanan_detail' => '/api/v2/order/get_order_detail',
    'escrow'       => '/api/v2/payment/get_escrow_detail',
    'pelacakan'    => '/api/v2/logistics/get_tracking_info',
];

/** Batas aman: berapa pesanan ditarik dalam satu putaran. */
const SHOPEE_MAKS_PESANAN = 50;

// =====================================================================
// TANDA TANGAN & PEMANGGILAN
// =====================================================================
/**
 * Tanda tangan permintaan Shopee.
 *
 * Susunan bahan tanda tangan BERBEDA antara panggilan yang butuh toko
 * (partner+path+timestamp+token+shop) dan yang tidak (partner+path+timestamp).
 * Salah susun menghasilkan galat "wrong sign" yang tidak menjelaskan
 * apa-apa, jadi keduanya dipisah terang-terangan di sini.
 */
function shopee_tanda(string $jalur, int $partnerId, string $partnerKey,
                      int $waktu, ?string $token = null, ?string $shopId = null): string
{
    $bahan = $partnerId . $jalur . $waktu;
    if ($token !== null && $shopId !== null) { $bahan .= $token . $shopId; }
    return hash_hmac('sha256', $bahan, $partnerKey);
}

/**
 * Panggil satu endpoint Shopee.
 *
 * Mengembalikan ['ok'=>bool, 'kode'=>int, 'data'=>array, 'pesan'=>string,
 *                'mentah'=>string].
 *
 * Kegagalan TIDAK melempar exception: pemanggilnya berjalan di dalam cron
 * yang memproses banyak toko, dan satu toko yang tokennya kedaluwarsa
 * tidak boleh menghentikan penarikan toko-toko lain.
 */
function shopee_panggil(string $jalur, array $akun, array $param = [],
                        ?array $body = null): array
{
    $waktu = time();
    $partnerId  = (int) $akun['partner_id'];
    $partnerKey = (string) $akun['partner_key'];

    // Panggilan otorisasi belum punya token; panggilan lain wajib punya.
    $pakaiToko = !empty($akun['access_token']) && !empty($akun['shop_id']);

    $q = [
        'partner_id' => $partnerId,
        'timestamp'  => $waktu,
        'sign'       => shopee_tanda($jalur, $partnerId, $partnerKey, $waktu,
                            $pakaiToko ? $akun['access_token'] : null,
                            $pakaiToko ? $akun['shop_id'] : null),
    ];
    if ($pakaiToko) {
        $q['access_token'] = $akun['access_token'];
        $q['shop_id']      = $akun['shop_id'];
    }
    $url = SHOPEE_HOST . $jalur . '?' . http_build_query(array_merge($q, $param));

    $h = curl_init($url);
    $opsi = [
        CURLOPT_RETURNTRANSFER => true,
        // Batas waktu WAJIB ada. Tanpa itu, satu panggilan yang menggantung
        // akan menahan seluruh proses cron sampai batas waktu PHP.
        CURLOPT_TIMEOUT        => 25,
        CURLOPT_CONNECTTIMEOUT => 10,
        // Verifikasi sertifikat tidak pernah dimatikan. Mematikannya
        // membuat seluruh tanda tangan HMAC di atas kehilangan artinya,
        // karena lawan bicaranya tidak lagi dipastikan Shopee.
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
    ];
    if ($body !== null) {
        $opsi[CURLOPT_POST]       = true;
        $opsi[CURLOPT_POSTFIELDS] = json_encode($body);
    }
    curl_setopt_array($h, $opsi);

    $mentah = curl_exec($h);
    $kode   = (int) curl_getinfo($h, CURLINFO_HTTP_CODE);
    $galat  = curl_error($h);
    curl_close($h);

    if ($mentah === false) {
        return ['ok' => false, 'kode' => 0, 'data' => [],
                'pesan' => 'Tidak dapat menghubungi Shopee: ' . $galat,
                'mentah' => ''];
    }

    $data = json_decode((string) $mentah, true);
    if (!is_array($data)) {
        return ['ok' => false, 'kode' => $kode, 'data' => [],
                'pesan' => 'Balasan Shopee bukan JSON yang bisa dibaca.',
                'mentah' => (string) $mentah];
    }

    /* Shopee membalas HTTP 200 walau permintaannya gagal; yang menentukan
       adalah field "error". Memeriksa kode HTTP saja akan menganggap
       "token kedaluwarsa" sebagai keberhasilan. */
    $adaGalat = !empty($data['error']);
    return [
        'ok'     => !$adaGalat,
        'kode'   => $kode,
        'data'   => $data['response'] ?? $data,
        'pesan'  => $adaGalat
                      ? trim(($data['error'] ?? '') . ' — ' . ($data['message'] ?? ''))
                      : '',
        'mentah' => (string) $mentah,
    ];
}

/** Catat satu panggilan ke jejak, termasuk balasan mentahnya. */
function shopee_catat(PDO $pdo, int $tokoId, string $jalur, array $hasil,
                      ?string $noPesanan = null): void
{
    try {
        $pdo->prepare(
            "INSERT INTO marketplace_tarikan
               (toko_id, platform, no_pesanan, endpoint, kode_http, sukses, pesan, data_mentah)
             VALUES (?,'shopee',?,?,?,?,?,?)"
        )->execute([
            $tokoId, $noPesanan, $jalur, $hasil['kode'], $hasil['ok'] ? 1 : 0,
            mb_substr($hasil['pesan'], 0, 500),
            // Balasan mentah dipotong: satu balasan raksasa tidak boleh
            // membuat baris jejaknya gagal disimpan.
            mb_substr($hasil['mentah'], 0, 60000),
        ]);
    } catch (Throwable $e) {
        error_log('[AMANAH] shopee_catat: ' . $e->getMessage());
    }
}

// =====================================================================
// TOKEN
// =====================================================================
/**
 * Tukar kode otorisasi menjadi access_token + refresh_token.
 *
 * Dipanggil sekali setelah pemilik toko menyetujui aplikasi di halaman
 * Shopee dan dikembalikan ke sistem ini membawa `code`.
 */
function shopee_tukar_kode(PDO $pdo, array $akun, string $kode): array
{
    $hasil = shopee_panggil(SHOPEE_JALUR['token_baru'],
        // Sengaja tanpa access_token: tanda tangannya memang beda susunan.
        ['partner_id' => $akun['partner_id'], 'partner_key' => $akun['partner_key']],
        [], ['code' => $kode, 'shop_id' => (int) $akun['shop_id'],
             'partner_id' => (int) $akun['partner_id']]);

    shopee_catat($pdo, (int) $akun['toko_id'], SHOPEE_JALUR['token_baru'], $hasil);
    if (!$hasil['ok']) { return $hasil; }

    shopee_simpan_token($pdo, (int) $akun['id'], $hasil['data']);
    return $hasil;
}

/**
 * Perbarui access_token memakai refresh_token.
 *
 * Harus dijalankan JAUH sebelum kedaluwarsa, bukan setelah gagal:
 * access_token Shopee berumur pendek (hitungan jam), dan menunggu sampai
 * gagal berarti satu putaran penarikan terlewat setiap kali.
 */
function shopee_segarkan(PDO $pdo, array $akun): array
{
    $hasil = shopee_panggil(SHOPEE_JALUR['token_segar'],
        ['partner_id' => $akun['partner_id'], 'partner_key' => $akun['partner_key']],
        [], ['refresh_token' => $akun['refresh_token'],
             'shop_id' => (int) $akun['shop_id'],
             'partner_id' => (int) $akun['partner_id']]);

    shopee_catat($pdo, (int) $akun['toko_id'], SHOPEE_JALUR['token_segar'], $hasil);
    if (!$hasil['ok']) {
        $pdo->prepare("UPDATE marketplace_akun SET status='kedaluwarsa', pesan_terakhir=?
                       WHERE id=?")->execute([mb_substr($hasil['pesan'], 0, 500), $akun['id']]);
        return $hasil;
    }
    shopee_simpan_token($pdo, (int) $akun['id'], $hasil['data']);
    return $hasil;
}

function shopee_simpan_token(PDO $pdo, int $akunId, array $data): void
{
    // expire_in dalam detik; dikurangi 5 menit sebagai jeda aman supaya
    // token tidak pernah dipakai persis di detik kedaluwarsanya.
    $detik = (int) ($data['expire_in'] ?? 14400);
    $pdo->prepare(
        "UPDATE marketplace_akun
            SET access_token = ?, refresh_token = ?,
                token_expired_at = DATE_ADD(NOW(), INTERVAL ? SECOND),
                status = 'tersambung', pesan_terakhir = NULL,
                terakhir_segar_at = NOW()
          WHERE id = ?"
    )->execute([
        $data['access_token'] ?? '', $data['refresh_token'] ?? '',
        max(60, $detik - 300), $akunId,
    ]);
}

/** Perlu disegarkan? Ambang 30 menit, bukan 0. */
function shopee_perlu_segar(array $akun): bool
{
    if (empty($akun['token_expired_at'])) { return true; }
    return strtotime($akun['token_expired_at']) - time() < 1800;
}

// =====================================================================
// PEMETAAN STATUS
// =====================================================================
/**
 * Status Shopee → status internal.
 *
 * Statusnya disimpan APA ADANYA di penjualan.status_marketplace; peta ini
 * hanya untuk tampilan ringkas. Status yang tidak dikenali sengaja
 * dijatuhkan ke 'baru', BUKAN diabaikan: pesanan yang tak masuk peta harus
 * tetap terlihat operator, sebab status baru dari Shopee justru yang paling
 * perlu diperhatikan.
 */
function shopee_status_pesanan(string $s): string
{
    $peta = [
        'UNPAID'             => 'baru',
        'READY_TO_SHIP'      => 'dikemas',
        'PROCESSED'          => 'dikemas',
        'RETRY_SHIP'         => 'dikemas',
        'SHIPPED'            => 'dikirim',
        'TO_CONFIRM_RECEIVE' => 'dikirim',
        'COMPLETED'          => 'selesai',
        'CANCELLED'          => 'batal',
        'IN_CANCEL'          => 'batal',
        'INVOICE_PENDING'    => 'dikemas',
        'TO_RETURN'          => 'dikirim',
    ];
    return $peta[strtoupper($s)] ?? 'baru';
}

/** Status Shopee → status kiriman. */
function shopee_status_kirim(string $s): string
{
    $peta = [
        'UNPAID'             => 'belum',
        'READY_TO_SHIP'      => 'diproses',
        'PROCESSED'          => 'diproses',
        'RETRY_SHIP'         => 'diproses',
        'INVOICE_PENDING'    => 'diproses',
        'SHIPPED'            => 'dikirim',
        'TO_CONFIRM_RECEIVE' => 'dikirim',
        'COMPLETED'          => 'sampai',
        'CANCELLED'          => 'belum',
        'IN_CANCEL'          => 'belum',
        'TO_RETURN'          => 'kembali',
    ];
    return $peta[strtoupper($s)] ?? 'belum';
}

/**
 * Status pembayaran dari data escrow.
 *
 * 'ditahan' adalah keadaan paling sering disalahpahami: uangnya SUDAH
 * dibayar pembeli tapi BELUM bisa dipakai penjual. Membedakannya dari
 * 'cair' mencegah toko menghitung uang yang belum ada di tangannya.
 */
function shopee_status_bayar(string $statusPesanan, ?float $escrow, ?string $tglLepas): string
{
    $s = strtoupper($statusPesanan);
    if (in_array($s, ['CANCELLED', 'IN_CANCEL'], true)) { return 'batal'; }
    if ($s === 'UNPAID')                                { return 'belum'; }
    if ($tglLepas)                                      { return 'cair'; }
    if ($escrow !== null && $escrow > 0)                { return 'ditahan'; }
    return 'belum';
}

// =====================================================================
// PENARIKAN
// =====================================================================
/**
 * Tarik pesanan satu toko, lalu simpan/perbarui ke tabel penjualan.
 *
 * Mengembalikan ringkasan: ['baru'=>n, 'diperbarui'=>n, 'gagal'=>n, 'pesan'=>[]].
 */
function shopee_tarik_pesanan(PDO $pdo, array $akun, int $hariKeBelakang = 7): array
{
    $ringkas = ['baru' => 0, 'diperbarui' => 0, 'gagal' => 0, 'pesan' => []];
    $tokoId  = (int) $akun['toko_id'];

    if (shopee_perlu_segar($akun)) {
        $seg = shopee_segarkan($pdo, $akun);
        if (!$seg['ok']) {
            $ringkas['pesan'][] = 'Token tidak bisa diperbarui: ' . $seg['pesan'];
            return $ringkas;
        }
        $akun = shopee_akun($pdo, (int) $akun['id']) ?: $akun;
    }

    /* Shopee membatasi rentang waktu per permintaan. Rentangnya dipotong
       per 15 hari supaya permintaan tidak ditolak, dan supaya penarikan
       pertama (yang biasanya panjang) tidak gagal seluruhnya. */
    $sampai = time();
    $dari   = $sampai - ($hariKeBelakang * 86400);

    $daftar = shopee_panggil(SHOPEE_JALUR['pesanan_list'], $akun, [
        'time_range_field' => 'update_time',
        'time_from'        => $dari,
        'time_to'          => $sampai,
        'page_size'        => SHOPEE_MAKS_PESANAN,
        'response_optional_fields' => 'order_status',
    ]);
    shopee_catat($pdo, $tokoId, SHOPEE_JALUR['pesanan_list'], $daftar);

    if (!$daftar['ok']) {
        $ringkas['pesan'][] = $daftar['pesan'];
        return $ringkas;
    }

    $noPesanan = [];
    foreach (($daftar['data']['order_list'] ?? []) as $o) {
        if (!empty($o['order_sn'])) { $noPesanan[] = (string) $o['order_sn']; }
    }
    if (!$noPesanan) {
        $ringkas['pesan'][] = 'Tidak ada pesanan baru dalam ' . $hariKeBelakang . ' hari terakhir.';
        return $ringkas;
    }

    foreach ($noPesanan as $sn) {
        try {
            $hasil = shopee_serap_satu($pdo, $akun, $sn);
            $ringkas[$hasil]++;
        } catch (Throwable $e) {
            $ringkas['gagal']++;
            $ringkas['pesan'][] = "$sn: " . $e->getMessage();
            error_log('[AMANAH] shopee_serap ' . $sn . ': ' . $e->getMessage());
        }
    }

    $pdo->prepare("UPDATE marketplace_akun SET terakhir_tarik_at = NOW() WHERE id = ?")
        ->execute([$akun['id']]);

    return $ringkas;
}

/**
 * Serap satu pesanan: detail + escrow + pelacakan.
 * Mengembalikan 'baru' atau 'diperbarui'.
 */
function shopee_serap_satu(PDO $pdo, array $akun, string $sn): string
{
    $tokoId = (int) $akun['toko_id'];

    $det = shopee_panggil(SHOPEE_JALUR['pesanan_detail'], $akun, [
        'order_sn_list' => $sn,
        'response_optional_fields' =>
            'order_status,total_amount,buyer_username,create_time,item_list,'
          . 'shipping_carrier,actual_shipping_fee',
    ]);
    shopee_catat($pdo, $tokoId, SHOPEE_JALUR['pesanan_detail'], $det, $sn);
    if (!$det['ok']) { throw new RuntimeException($det['pesan']); }

    $p = ($det['data']['order_list'][0] ?? null);
    if (!is_array($p)) { throw new RuntimeException('Rincian pesanan kosong.'); }

    // ---- pembayaran (escrow). Gagal di sini TIDAK menggagalkan pesanannya:
    // status kiriman tetap berguna walau angka uangnya belum bisa ditarik.
    $esc = shopee_panggil(SHOPEE_JALUR['escrow'], $akun, ['order_sn' => $sn]);
    shopee_catat($pdo, $tokoId, SHOPEE_JALUR['escrow'], $esc, $sn);
    $e = $esc['ok'] ? ($esc['data']['order_income'] ?? []) : [];

    $statusMp = (string) ($p['order_status'] ?? '');
    $escrowAmt = isset($e['escrow_amount']) ? (float) $e['escrow_amount'] : null;
    $tglLepas  = !empty($e['payout_time'])
                   ? date('Y-m-d', (int) $e['payout_time']) : null;

    $baris = [
        'toko_id'            => $tokoId,
        'no_pesanan'         => $sn,
        'nama_pembeli'       => mb_substr((string) ($p['buyer_username'] ?? ''), 0, 150) ?: null,
        'tanggal'            => !empty($p['create_time'])
                                  ? date('Y-m-d H:i:s', (int) $p['create_time'])
                                  : date('Y-m-d H:i:s'),
        'total'              => (float) ($p['total_amount'] ?? 0),
        'ongkir'             => (float) ($p['actual_shipping_fee'] ?? 0),
        'status'             => shopee_status_pesanan($statusMp),
        'status_marketplace' => $statusMp ?: null,
        'status_kirim'       => shopee_status_kirim($statusMp),
        'kurir'              => mb_substr((string) ($p['shipping_carrier'] ?? ''), 0, 80) ?: null,
        'escrow_amount'      => $escrowAmt,
        'komisi'             => (float) ($e['commission_fee'] ?? 0),
        'biaya_layanan'      => (float) ($e['service_fee'] ?? 0),
        'biaya_transaksi'    => (float) ($e['seller_transaction_fee'] ?? 0),
        'voucher'            => (float) ($e['voucher_from_seller'] ?? 0),
        'status_bayar'       => shopee_status_bayar($statusMp, $escrowAmt, $tglLepas),
        'tgl_settlement'     => $tglLepas,
    ];

    // ---- pelacakan, hanya bila sudah dikirim.
    if (in_array($baris['status_kirim'], ['dikirim', 'sampai'], true)) {
        $lac = shopee_panggil(SHOPEE_JALUR['pelacakan'], $akun, ['order_sn' => $sn]);
        shopee_catat($pdo, $tokoId, SHOPEE_JALUR['pelacakan'], $lac, $sn);
        if ($lac['ok']) {
            $baris['no_resi'] = mb_substr((string) ($lac['data']['tracking_number'] ?? ''), 0, 80) ?: null;
        }
    }

    return shopee_simpan_penjualan($pdo, $baris);
}


/**
 * Simpan pesanan Shopee.
 *
 * Hanya meneruskan ke marketplace_simpan_penjualan() di _marketplace.php.
 * Logikanya TIDAK diduplikasi di sini: Shopee dan Tokopedia harus menyimpan
 * pesanan dengan cara yang sama persis. Kalau masing-masing menyimpan dengan
 * caranya sendiri, selisih angkanya baru terlihat berbulan-bulan kemudian —
 * saat tidak ada lagi yang ingat mengapa keduanya berbeda.
 *
 * Fungsi ini dipertahankan (bukan dihapus) supaya pemanggil yang sudah ada
 * tetap berjalan tanpa disunting.
 */
function shopee_simpan_penjualan(PDO $pdo, array $b): string
{
    require_once __DIR__ . '/_marketplace.php';
    $b['platform'] = 'shopee';
    return marketplace_simpan_penjualan($pdo, $b);
}

// =====================================================================
// PEMBANTU
// =====================================================================
/** Satu akun marketplace beserta kredensialnya. */
function shopee_akun(PDO $pdo, int $id): ?array
{
    $st = $pdo->prepare("SELECT * FROM marketplace_akun WHERE id = ?");
    $st->execute([$id]);
    return $st->fetch() ?: null;
}

/** Semua sambungan Shopee yang aktif dan siap ditarik. */
function shopee_akun_aktif(PDO $pdo): array
{
    try {
        return $pdo->query(
            "SELECT * FROM marketplace_akun
             WHERE platform = 'shopee' AND aktif = 1
               AND status IN ('tersambung','kedaluwarsa')
               AND partner_id <> '' AND shop_id <> ''
             ORDER BY COALESCE(terakhir_tarik_at, '2000-01-01') ASC"
        )->fetchAll();
    } catch (Throwable $e) {
        error_log('[AMANAH] shopee_akun_aktif: ' . $e->getMessage());
        return [];
    }
}

/**
 * URL halaman otorisasi Shopee.
 *
 * Pemilik toko dibawa ke sini, menyetujui aplikasinya, lalu Shopee
 * mengembalikannya ke $kembaliKe dengan membawa `code`.
 */
function shopee_url_otorisasi(array $akun, string $kembaliKe): string
{
    $waktu = time();
    $jalur = '/api/v2/shop/auth_partner';
    $tanda = shopee_tanda($jalur, (int) $akun['partner_id'],
                          (string) $akun['partner_key'], $waktu);
    return SHOPEE_HOST . $jalur . '?' . http_build_query([
        'partner_id' => (int) $akun['partner_id'],
        'timestamp'  => $waktu,
        'sign'       => $tanda,
        'redirect'   => $kembaliKe,
    ]);
}
