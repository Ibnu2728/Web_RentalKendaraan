/* ============================================================
   PREVIEW GAMBAR SEBELUM UPLOAD
   ============================================================ */
function previewImg(input, boxId) {
  const box = document.getElementById(boxId);
  if (!input.files || !input.files[0]) return;

  const file = input.files[0];

  // Validasi ukuran (maks 5MB)
  if (file.size > 5 * 1024 * 1024) {
    alert('❌ Ukuran file maksimal 5MB');
    input.value = '';
    return;
  }

  const reader = new FileReader();
  reader.onload = (e) => {
    box.querySelector('.preview').src = e.target.result;
    box.classList.add('has-file');
  };
  reader.readAsDataURL(file);
}

/* ============================================================
   SUBMIT FORM TAMBAH MOBIL
   ============================================================ */
document.addEventListener('DOMContentLoaded', () => {
  const form = document.getElementById('formMobil');
  if (!form) return;

  form.addEventListener('submit', async (e) => {
    e.preventDefault();
    const btn = document.getElementById('btnSubmit');
    const alertBox = document.getElementById('alertBox');

    btn.disabled = true;
    btn.textContent = '⏳ Menyimpan...';
    alertBox.classList.remove('show');

    try {
      const fd = new FormData(form);
      const res = await fetch('../api/mobil.php', {
        method: 'POST',
        body: fd
      });
      const data = await res.json();

      if (data.success) {
        showAlert('success', '✅ ' + data.message);
        setTimeout(() => window.location.href = 'mobil-saya.html', 1200);
      } else {
        showAlert('error', '❌ ' + data.message);
        btn.disabled = false;
        btn.textContent = '💾 Simpan & Publikasikan';
      }
    } catch (err) {
      showAlert('error', '❌ Kesalahan jaringan: ' + err.message);
      btn.disabled = false;
      btn.textContent = '💾 Simpan & Publikasikan';
    }
  });
});

function showAlert(type, msg) {
  const box = document.getElementById('alertBox');
  box.className = 'alert show alert-' + type;
  box.textContent = msg;
  box.scrollIntoView({ behavior: 'smooth', block: 'center' });
}