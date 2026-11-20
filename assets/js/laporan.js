let chartInstance = null;

function isoLokal(d) {
  return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
}

async function loadLaporan() {
  const dari   = document.getElementById('tglDari').value;
  const sampai = document.getElementById('tglSampai').value;

  if (!dari || !sampai) {
    alert('Pilih rentang tanggal terlebih dahulu!');
    return;
  }
  if (sampai < dari) {
    alert('Tanggal "Sampai" tidak boleh sebelum tanggal "Dari".');
    return;
  }

  try {
    const res  = await fetch(`../api/laporan.php?dari=${encodeURIComponent(dari)}&sampai=${encodeURIComponent(sampai)}`);
    const data = await res.json();
    if (!data.success) throw new Error(data.message);

    renderChart(data.labels, data.pendapatan);
    renderRingkasan(data.total, data.labels.length);
  } catch (err) {
    alert('❌ Gagal memuat laporan: ' + err.message);
  }
}

function renderChart(labels, values) {
  const ctx = document.getElementById('chartKeuangan').getContext('2d');
  if (chartInstance) chartInstance.destroy();

  chartInstance = new Chart(ctx, {
    type: 'bar',
    data: {
      labels,
      datasets: [{
        label: 'Pendapatan (Rp)',
        data: values,
        backgroundColor: '#2563eb',
        borderRadius: 6
      }]
    },
    options: {
      responsive: true,
      plugins: { legend: { display: false } },
      scales: { y: { beginAtZero: true } }
    }
  });
}

function renderRingkasan(total, jumlahHari) {
  const el = document.getElementById('ringkasan');
  if (!el) return;
  el.textContent = jumlahHari
    ? `Total pendapatan: Rp ${Number(total).toLocaleString('id-ID')} (${jumlahHari} hari ada transaksi)`
    : 'Tidak ada pembayaran pada rentang tanggal ini.';
}

function cetakLaporan() { window.print(); }

// Default: awal bulan s/d hari ini, langsung ditampilkan
document.addEventListener('DOMContentLoaded', () => {
  const now = new Date();
  const dari = document.getElementById('tglDari');
  const sampai = document.getElementById('tglSampai');
  if (!dari || !sampai) return;
  dari.value   = isoLokal(new Date(now.getFullYear(), now.getMonth(), 1));
  sampai.value = isoLokal(now);
  loadLaporan();
});
