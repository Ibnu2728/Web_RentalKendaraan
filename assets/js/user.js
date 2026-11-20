/* ============================================================
   Logika halaman penyewa: dashboard & riwayat booking.
   Memakai helper dari auth.js (esc, fmtRp, badgeStatus, checkAuth).
   ============================================================ */

function barisBooking(b, denganAksi) {
  const bayar = b.status_bayar === 'settlement' ? 'Lunas'
    : b.status_bayar === 'pending' ? 'Menunggu bayar' : '-';
  let aksi = '';
  if (denganAksi) {
    if (b.status === 'pending' && b.status_bayar !== 'settlement') {
      aksi += `<a class="btn-primary btn-sm" style="text-decoration:none;" href="pembayaran.html?booking_id=${b.id}">Bayar</a> `;
    }
    if (b.status_bayar === 'settlement') {
      aksi += `<a class="btn-outline btn-sm" style="text-decoration:none;" href="struk.html?booking_id=${b.id}">Struk</a> `;
    }
    if (['pending', 'dikonfirmasi'].includes(b.status)) {
      aksi += `<button class="btn-batal btn-sm" onclick="batalBooking(${b.id})">Batalkan</button>`;
    }
  }
  return `<tr>
    <td>${esc(b.kode_booking)}</td>
    <td>${esc(b.nama_mobil)}<br><small>${esc(b.plat_nomor || '')} • ${esc(b.nama_usaha || '')}</small></td>
    <td>${esc(String(b.jenis_sewa).replace(/_/g, ' '))}</td>
    <td>${esc(b.tgl_mulai)} s/d ${esc(b.tgl_selesai)}</td>
    <td>Rp ${fmtRp(b.total_bayar)}</td>
    <td>${badgeStatus(b.status)}<br><small>${esc(bayar)}</small></td>
    <td>${aksi}</td>
  </tr>`;
}

async function muatDashboardUser() {
  const res = await fetch('../api/user.php?action=dashboard');
  const data = await res.json();
  if (!data.success) throw new Error(data.message || 'Gagal memuat dashboard.');
  const r = data.ringkasan || {};
  document.getElementById('statTotal').textContent = r.total ?? 0;
  document.getElementById('statBerjalan').textContent = r.berjalan ?? 0;
  document.getElementById('statSelesai').textContent = r.selesai ?? 0;
  document.getElementById('statPending').textContent = r.pending ?? 0;
  const tbody = document.getElementById('bookingTerbaru');
  tbody.innerHTML = data.terbaru.length
    ? data.terbaru.map(b => barisBooking(b, true)).join('')
    : '<tr><td colspan="7" class="kosong">Belum ada booking. Yuk, lihat katalog dulu.</td></tr>';
}

async function muatRiwayatUser() {
  const res = await fetch('../api/user.php?action=riwayat');
  const data = await res.json();
  if (!data.success) throw new Error(data.message || 'Gagal memuat riwayat.');
  const tbody = document.getElementById('tabelRiwayat');
  tbody.innerHTML = data.data.length
    ? data.data.map(b => barisBooking(b, true)).join('')
    : '<tr><td colspan="7" class="kosong">Belum ada riwayat booking.</td></tr>';
}

async function batalBooking(id) {
  if (!confirm('Batalkan booking ini? Tagihan yang belum dibayar ikut dibatalkan.')) return;
  const fd = new FormData();
  fd.append('booking_id', id);
  const res = await fetch('../api/user.php?action=batal', { method: 'POST', body: fd });
  const data = await res.json();
  alert(data.message || (data.success ? 'Booking dibatalkan.' : 'Gagal membatalkan.'));
  if (data.success) window.location.reload();
}
