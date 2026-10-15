/* Image fallback only; no form or business state is read or changed. */
'use strict';
document.querySelectorAll('img').forEach(image => {
  function fallback() {
    if (image.dataset.fallbackApplied) return;
    image.dataset.fallbackApplied = 'true';
    image.src = '/assets/images/fallback.svg';
    image.alt = 'Minh họa thay thế: ảnh ban đầu chưa tải được';
    const caption = image.closest('figure')?.querySelector('figcaption');
    if (caption) caption.textContent = 'Ảnh chưa tải được · đang dùng minh họa thay thế.';
  }
  image.addEventListener('error', fallback);
  if (image.complete && image.naturalWidth === 0) fallback();
});
