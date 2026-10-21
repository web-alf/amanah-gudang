<?php
/**
 * AMANAH — Lapisan bersama marketplace
 *
 * Shopee dan Tokopedia bicara dengan cara yang sangat berbeda: Shopee
 * memakai tanda tangan HMAC dan otorisasi per lapak, Tokopedia memakai
 * OAuth2 client_credentials tanpa otorisasi per lapak. Perbedaan itu
 * tinggal di berkas kliennya masing-masing.
 *
 * Yang tinggal DI SINI adalah bagian yang tidak boleh berbeda:
 *   • cara sebuah pesanan menjadi baris penjualan;
 *   • aturan "satu toko satu kredensial";
 *   • pemilihan klien menurut platform.
 *
 * Kalau penyimpanan pesanan dibiarkan di tiap klien, dua platform akan
 * perlahan menyimpan hal yang sama dengan cara berbeda — dan selisih
 * angkanya baru terlihat berbulan-bulan kemudian, saat tidak ada lagi yang
 * ingat mengapa.
 */

require_once __DIR__ . '/_shopee.php';
require_once __DIR__ . '/_tokopedia.php';

/** Platform yang punya API dan bisa disambungkan. */
const MP_PLATFORM = ['shopee' => 'Shopee', 'tokopedia' => 'Tokopedia'];

/**
 * Nama field kredensial per platform.
 *
 * Isinya sejenis, tetapi NAMANYA berbeda di konsol masing-masing. Operator
 * membaca label di konsol Shopee/Tokopedia, bukan nama kolom kami — jadi
 * label yang ditampilkan harus mengikuti yang di sana, kalau tidak ia akan
 * menempelkan nilai ke kolom yang salah.
 */
function mp_label_kredensial(string $platform): array
{
    if ($platform === 'tokopedia') {
        return [
            'partner_id'  => ['Client ID', 'dari Tokopedia Seller API'],
            'partner_key' => ['Client Secret', 'setara password'],
            'shop_id'     => ['Shop ID', 'id lapak Tokopedia'],
            'fs_id'       => ['FS ID', 'Fulfillment Service ID'],
        ];
    }
    return [
        'partner_id'  => ['Partner ID', 'dari Shopee Open Platform'],
        'partner_key' => ['Partner Key', 'setara password'],
        'shop_id'     => ['Shop ID', 'terisi otomatis saat otorisasi'],
    ];
}

/** Platform sebuah toko, dibaca dari datanya sendiri. */
function mp_platform_toko(PDO $pdo, int $tokoId): ?string
{
    $st = $pdo->prepare("SELECT marketplace FROM toko WHERE id = ? AND deleted_at IS NULL");
    $st->execute([$tokoId]);
    $mp = (string) ($st->fetchColumn() ?: '');
    return isset(MP_PLATFORM[$mp]) ? $mp : null;
}

/**
 * Butuh otorisasi per lapak?
 *
 * Hanya Shopee. Menampilkan tombol Otorisasi untuk Tokopedia akan menuntun
 * operator ke halaman yang tidak ada — dan menyimpulkan sambungannya rusak.
 */
function mp_butuh_otorisasi(string $platform): bool
{
    return $platform === 'shopee';
}

/**
 * Tarik pesanan, apa pun platformnya.
 *
 * Bentuk kembaliannya seragam: ['baru','diperbarui','gagal','pesan'].
 * Karena itu cron dan halaman pengaturan tidak perlu tahu sedang bicara
 * dengan platform mana — dan menambah platform ketiga nanti tidak menyentuh
 * keduanya.
 */
function mp_tarik(PDO $pdo, array $akun, int $hari = 7): array
{
    switch ($akun['platform']) {
        case 'shopee':    return shopee_tarik_pesanan($pdo, $akun, $hari);
        case 'tokopedia': return toped_tarik_pesanan($pdo, $akun, $hari);
    }
    return ['baru' => 0, 'diperbarui' => 0, 'gagal' => 1,
            'pesan' => ['Platform ' . $akun['platform'] . ' belum didukung.']];
}

/** Semua sambungan aktif dari seluruh platform. */
function mp_akun_aktif(PDO $pdo): array
{
    return array_merge(shopee_akun_aktif($pdo), toped_akun_aktif($pdo));
}

function mp_akun(PDO $pdo, int $id): ?array
{
    $st = $pdo->prepare("SELECT * FROM marketplace_akun WHERE id = ?");
    $st->execute([$id]);
    return $st->fetch() ?: null;
}

// =====================================================================
// PENYIMPANAN PESANAN — satu jalur untuk semua platform
// =====================================================================
/**
 * Simpan atau perbarui satu pesanan marketplace.
 *
 * INSERT dan UPDATE dipisah tegas, TIDAK memakai "INSERT ... ON DUPLICATE
 * KEY UPDATE". Dua alasan, keduanya terbukti saat diuji:
 *
 *   1. MySQL memvalidasi baris INSERT-nya LEBIH DULU, sebelum tahu ada
 *      duplikat. Jadi tidak mungkin mengirim NULL untuk no_penjualan pada
 *      jalur pembaruan — kolomnya NOT NULL, dan gagal sebelum cabang
 *      duplikatnya tercapai.
 *   2. Kalau nomor dokumen dibuat setiap kali agar tidak NULL, deretnya
 *      akan naik pada SETIAP putaran cron walau tak ada pesanan baru.
 *      Dengan cron 15 menit, nomornya melompat ribuan dalam sebulan.
 *
 * Mengembalikan 'baru' atau 'diperbarui'.
 */
function marketplace_simpan_penjualan(PDO $pdo, array $b): string
{
    $platform = $b['platform'] ?? 'shopee';

    $st = $pdo->prepare("SELECT id FROM penjualan
                         WHERE toko_id = ? AND marketplace = ? AND no_pesanan = ?");
    $st->execute([$b['toko_id'], $platform, $b['no_pesanan']]);
    $adaId = (int) ($st->fetchColumn() ?: 0);

    if ($adaId > 0) {
        /* no_penjualan sengaja TIDAK ikut diperbarui: nomor dokumen yang
           sudah terbit tidak boleh berubah.
           no_resi & kurir memakai COALESCE — penarikan berikutnya yang belum
           membawa resi tidak boleh menghapus resi yang sudah ada. */
        $pdo->prepare(
            "UPDATE penjualan SET
                tanggal=?, no_resi=COALESCE(?, no_resi), kurir=COALESCE(?, kurir),
                nama_pembeli=?, total=?, ongkir=?, status=?, status_marketplace=?,
                status_kirim=?, escrow_amount=?, komisi=?, biaya_layanan=?,
                biaya_transaksi=?, voucher=?, status_bayar=?, tgl_settlement=?,
                sumber='api', ditarik_at=NOW()
              WHERE id=?"
        )->execute([
            $b['tanggal'], $b['no_resi'] ?? null, $b['kurir'], $b['nama_pembeli'],
            $b['total'], $b['ongkir'], $b['status'], $b['status_marketplace'],
            $b['status_kirim'], $b['escrow_amount'], $b['komisi'],
            $b['biaya_layanan'], $b['biaya_transaksi'], $b['voucher'],
            $b['status_bayar'], $b['tgl_settlement'], $adaId,
        ]);
        return 'diperbarui';
    }

    /* Baris baru. Kunci unik (toko_id, marketplace, no_pesanan) menjaga dari
       dua cron yang berjalan bertabrakan: yang kalah menerima galat duplikat,
       lalu diperlakukan sebagai pembaruan — bukan digagalkan. */
    try {
        $pdo->prepare(
            "INSERT INTO penjualan
               (no_penjualan, toko_id, tanggal, marketplace, no_pesanan, no_resi, kurir,
                nama_pembeli, total, ongkir, status, status_marketplace, status_kirim,
                escrow_amount, komisi, biaya_layanan, biaya_transaksi, voucher,
                status_bayar, tgl_settlement, sumber, ditarik_at)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,'api',NOW())"
        )->execute([
            nomor_dokumen($pdo, 'penjualan', 'no_penjualan', 'JL'),
            $b['toko_id'], $b['tanggal'], $platform, $b['no_pesanan'],
            $b['no_resi'] ?? null, $b['kurir'], $b['nama_pembeli'],
            $b['total'], $b['ongkir'], $b['status'], $b['status_marketplace'],
            $b['status_kirim'], $b['escrow_amount'], $b['komisi'],
            $b['biaya_layanan'], $b['biaya_transaksi'], $b['voucher'],
            $b['status_bayar'], $b['tgl_settlement'],
        ]);
        return 'baru';
    } catch (PDOException $e) {
        if ($e->getCode() !== '23000') { throw $e; }
        // Kalah lomba dengan proses lain; barisnya sudah ada.
        return marketplace_simpan_penjualan($pdo, $b);
    }
}
