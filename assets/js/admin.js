/* ============================================================
   Helper bersama halaman admin (api/admin.php).
   Memakai helper dari auth.js (esc, fmtRp, badgeStatus).
   ============================================================ */

async function apiGet(action, params = {}) {
  const qs = new URLSearchParams({ action, ...params });
  const res = await fetch(`../api/admin.php?${qs}`);
  const data = await res.json();
  if (!data.success) throw new Error(data.message || 'Permintaan gagal.');
  return data;
}

async function apiPost(action, fields = {}) {
  const fd = new FormData();
  for (const [k, v] of Object.entries(fields)) fd.append(k, v);
  const res = await fetch(`../api/admin.php?action=${encodeURIComponent(action)}`, { method: 'POST', body: fd });
  const data = await res.json();
  if (!data.success) throw new Error(data.message || 'Permintaan gagal.');
  return data;
}

/** Jalankan aksi POST dengan konfirmasi, lalu muat ulang halaman. */
async function aksiKonfirmasi(pesan, action, fields) {
  if (!confirm(pesan)) return;
  try {
    const data = await apiPost(action, fields);
    alert(data.message || 'Berhasil.');
    window.location.reload();
  } catch (err) {
    alert('❌ ' + err.message);
  }
}

function badgeBayar(status) {
  const map = {
    settlement: ['badge-success', 'Lunas'],
    pending:    ['badge-warning', 'Pending'],
    expire:     ['badge-danger',  'Kedaluwarsa'],
    cancel:     ['badge-danger',  'Dibatalkan'],
  };
  const [kelas, label] = map[status] || ['badge-warning', String(status || '-')];
  return `<span class="badge ${kelas}">${esc(label)}</span>`;
}

function badgeVerif(status) {
  const map = {
    approved: ['badge-success', 'Disetujui'],
    pending:  ['badge-warning', 'Menunggu'],
    rejected: ['badge-danger',  'Ditolak'],
  };
  const [kelas, label] = map[status] || ['badge-warning', String(status || '-')];
  return `<span class="badge ${kelas}">${esc(label)}</span>`;
}

function badgeAkun(status) {
  return status === 'aktif'
    ? '<span class="badge badge-success">Aktif</span>'
    : '<span class="badge badge-danger">Nonaktif</span>';
}

function badgeMobil(status) {
  const map = {
    tersedia: ['badge-success', 'Tersedia'],
    disewa:   ['badge-warning', 'Disewa'],
    nonaktif: ['badge-danger',  'Nonaktif'],
  };
  const [kelas, label] = map[status] || ['badge-warning', String(status || '-')];
  return `<span class="badge ${kelas}">${esc(label)}</span>`;
}
