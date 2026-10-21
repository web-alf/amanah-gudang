<?php
/**
 * AMANAH — Tombol dan dialog "Ubah" untuk Riwayat Penyesuaian
 *
 * Riwayat penyesuaian tampil di dua halaman (Stock Opname dan Sesuaikan
 * Stok per produk). Tombol dan dialognya ditulis sekali di sini supaya
 * keduanya tidak bisa berbeda; yang menerima kirimannya penyesuaian_ubah.php.
 *
 * Pemakaian:
 *   - kueri riwayat menyertakan ps.id, ps.lokasi_tipe, ps.lokasi_rak_id,
 *     dan bb.qty_tersisa AS stok_batch
 *   - di tiap baris:      <?= tombol_ubah_penyesuaian($pdo, $r) ?>
 *   - sekali per halaman: <?php dialog_ubah_penyesuaian('opname.php'); ?>
 */

/** Tombol Ubah untuk satu baris; string kosong bila akun ini tidak berwenang. */
function tombol_ubah_penyesuaian(PDO $pdo, array $r): string {
    if (!boleh_ubah_penyesuaian($pdo, $r)) { return ''; }
    $naik = strpos((string) $r['jenis'], 'tambah') !== false;
    $data = [
        'id'     => (int) $r['id'],
        'no'     => (string) $r['no_penyesuaian'],
        'barang' => (string) ($r['barang'] ?? ''),
        'batch'  => (string) ($r['nomor_batch'] ?? ''),
        'jenis'  => str_replace('_', ' ', (string) $r['jenis']),
        'naik'   => $naik,
        'qty'    => (int) $r['qty'],
        'alasan' => (string) ($r['alasan'] ?? ''),
        // null = batchnya sudah tidak ada → jumlah tidak bisa diubah
        'stok'   => isset($r['stok_batch']) ? (int) $r['stok_batch'] : null,
        'aneh'   => (int) $r['qty'] > MAKS_QTY_BARIS,
    ];
    return '<button type="button" class="btn btn-ghost btn-sm" onclick="bukaUbahPs(this)" data-ps="'
         . e(json_encode($data, JSON_UNESCAPED_UNICODE)) . '">Ubah</button>';
}

/** Dialognya — dipanggil sekali, sesudah tabel. [$kembali] = halaman asal. */
function dialog_ubah_penyesuaian(string $kembali): void {
    ?>
    <dialog class="modal" id="dlgUbahPs" style="--mc:var(--primary)">
      <form method="post" action="penyesuaian_ubah.php">
        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="kembali" value="<?= e($kembali) ?>">
        <input type="hidden" name="id" id="psId">
        <div class="modal-h">
          <span class="modal-ic"><svg aria-hidden="true"><use href="#i-alert"></use></svg></span>
          <div><b>Ubah Penyesuaian</b><small id="psNo"></small></div>
        </div>
        <div class="modal-b">
          <div class="modal-obj"><b id="psBarang"></b><small id="psRinci"></small></div>
          <div class="field" style="margin-top:14px">
            <label for="psQty">Jumlah <span id="psJenis" class="mut2"></span></label>
            <input type="number" name="qty_baru" id="psQty" min="0" max="<?= MAKS_QTY_BARIS ?>" step="1"
                   inputmode="numeric" required>
          </div>
          <div class="field">
            <label for="psAlasan">Alasan</label>
            <input type="text" name="alasan" id="psAlasan" maxlength="255" required>
          </div>
          <p id="psDampak" style="margin:0;font-size:12.5px"></p>
        </div>
        <div class="modal-f">
          <button type="button" class="btn btn-ghost btn-sm" onclick="document.getElementById('dlgUbahPs').close()">Batal</button>
          <button type="submit" class="btn btn-primary btn-sm" id="psSimpan">Simpan</button>
        </div>
      </form>
    </dialog>
    <script>
    /* Dampak ke stok dihitung di sini HANYA untuk ditampilkan; yang berlaku
       tetap hitungan server (ubah_penyesuaian di _lib.php). */
    var psData = null;
    function fmtPs(n){ return Number(n).toLocaleString('id-ID'); }
    function bukaUbahPs(btn){
      psData = JSON.parse(btn.dataset.ps);
      var d = document.getElementById('dlgUbahPs'), q = document.getElementById('psQty');
      document.getElementById('psId').value = psData.id;
      document.getElementById('psNo').textContent = psData.no;
      document.getElementById('psBarang').textContent = psData.barang || 'Penyesuaian stok';
      document.getElementById('psRinci').textContent =
        (psData.batch ? 'Batch ' + psData.batch + ' · ' : '') + 'tercatat ' +
        (psData.naik ? '+' : '−') + fmtPs(psData.qty);
      document.getElementById('psJenis').textContent = '(' + psData.jenis + ')';
      document.getElementById('psAlasan').value = psData.alasan;
      // Angka raksasa tidak diisikan lagi ke kotaknya — petugas mengetik yang benar.
      q.value = psData.aneh ? '' : psData.qty;
      // Tanpa batch, jumlah tidak bisa diubah — hanya alasannya.
      q.readOnly = (psData.stok === null && !psData.aneh);
      dampakPs();
      d.showModal();
      setTimeout(function(){ (q.readOnly ? document.getElementById('psAlasan') : q).focus(); q.select && !q.readOnly && q.select(); }, 30);
    }
    function dampakPs(){
      var el = document.getElementById('psDampak'), btn = document.getElementById('psSimpan');
      var isi = document.getElementById('psQty').value, baru = parseInt(isi, 10);
      el.style.color = 'var(--muted)'; btn.disabled = false; btn.textContent = 'Simpan';
      if (!psData) return;
      if (psData.aneh) {
        el.textContent = 'Angka ' + fmtPs(psData.qty) + ' tidak wajar (barcode masuk ke kotak Jumlah). '
          + 'Hanya catatannya yang diubah — stok tidak ikut berubah. Isi 0 untuk menghapus catatan ini.';
        if (baru === 0) btn.textContent = 'Hapus catatan';
        return;
      }
      if (psData.stok === null) {
        el.textContent = 'Batch catatan ini sudah tidak ada — hanya alasannya yang bisa diubah.';
        return;
      }
      if (isi === '' || isNaN(baru) || baru === psData.qty) {
        el.textContent = 'Stok batch sekarang ' + fmtPs(psData.stok) + '. Mengubah jumlah akan ikut mengubah stok batch ini.';
        return;
      }
      var geser = psData.naik ? (baru - psData.qty) : (psData.qty - baru), jadi = psData.stok + geser;
      if (jadi < 0) {
        el.style.color = 'var(--red)'; btn.disabled = true;
        el.textContent = 'Stok batch tinggal ' + fmtPs(psData.stok) + ' — tidak cukup untuk perubahan ini.';
        return;
      }
      el.textContent = (baru === 0 ? 'Penyesuaian dibatalkan dan catatannya dihapus. ' : '')
        + 'Stok batch ' + fmtPs(psData.stok) + ' → ' + fmtPs(jadi) + '.';
      if (baru === 0) btn.textContent = 'Batalkan penyesuaian';
    }
    document.getElementById('psQty').addEventListener('input', dampakPs);
    </script>
    <?php
}
