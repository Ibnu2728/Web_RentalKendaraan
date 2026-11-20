/* ============================================================
   Helper bersama: guard halaman, logout, escape HTML, format.
   Dipakai dari halaman di subfolder (user/, mitra/, admin/) -> path '../api/...'
   ============================================================ */

/** Escape teks sebelum dimasukkan ke innerHTML (cegah XSS). */
function esc(v) {
  return String(v ?? '').replace(/[&<>"']/g, c =>
    ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
}

/** 350000 -> "350.000" */
function fmtRp(n) {
  return Number(n || 0).toLocaleString('id-ID');
}

/** Gambar pengganti kalau mobil belum punya foto / file hilang. */
const NOIMG = 'data:image/svg+xml;utf8,' + encodeURIComponent(
  '<svg xmlns="http://www.w3.org/2000/svg" width="300" height="180"><rect fill="#f3f4f6" width="300" height="180"/>' +
  '<text x="150" y="105" text-anchor="middle" font-size="48">🚗</text></svg>');

function fotoSrc(nama) {
  return nama ? '../uploads/mobil/' + encodeURIComponent(nama) : NOIMG;
}

/**
 * Pastikan user login (dan role cocok). Halaman disembunyikan sampai terverifikasi,
 * jadi tidak ada "kedipan" isi halaman untuk orang yang tidak berhak.
 *   checkAuth('mitra')            -> hanya mitra
 *   checkAuth(['user','admin'])   -> salah satu
 *   checkAuth()                   -> asal sudah login
 * Mengembalikan Promise berisi data sesi (atau null kalau ditolak).
 */
function checkAuth(roles) {
  const allowed = roles ? [].concat(roles) : null;
  document.documentElement.style.visibility = 'hidden';
  return fetch('../api/session.php', { cache: 'no-store' })
    .then(r => r.json())
    .then(s => {
      if (!s.logged_in || (allowed && !allowed.includes(s.role))) {
        window.location.href = '../login.html';
        return null;
      }
      document.documentElement.style.visibility = '';
      return s;
    })
    .catch(() => { window.location.href = '../login.html'; return null; });
}

/** Lencana status booking (kelas .badge-* tersedia di admin.css & user.css). */
function badgeStatus(status) {
  const map = {
    pending:      ['badge-warning', 'Pending'],
    dikonfirmasi: ['badge-success', 'Dikonfirmasi'],
    aktif:        ['badge-success', 'Aktif'],
    selesai:      ['badge-success', 'Selesai'],
    batal:        ['badge-danger',  'Batal'],
  };
  const [kelas, label] = map[status] || ['badge-warning', String(status || '-')];
  return `<span class="badge ${kelas}">${esc(label)}</span>`;
}

/** Hancurkan sesi di server lalu ke halaman login. */
async function logout() {
  try { await fetch('../api/logout.php', { method: 'POST' }); } catch (e) {}
  window.location.href = '../login.html';
}
