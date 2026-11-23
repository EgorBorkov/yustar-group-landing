'use strict';
(() => {
  const button = document.querySelector('.scroll-top');
  if (!button) return;
  let frame = 0;
  function update() {
    frame = 0;
    button.hidden = window.scrollY < 700 || Boolean(document.querySelector('dialog[open]'));
  }
  function schedule() {
    if (!frame) frame = requestAnimationFrame(update);
  }
  window.addEventListener('scroll', schedule, { passive:true });
  document.addEventListener('scroll', schedule, { passive:true, capture:true });
  window.addEventListener('resize', schedule);
  window.addEventListener('pageshow', schedule);
  for (const dialog of document.querySelectorAll('dialog')) {
    new MutationObserver(schedule).observe(dialog, { attributes:true, attributeFilter:['open'] });
  }
  button.addEventListener('click', () => {
    document.querySelector('.brand')?.focus({ preventScroll:true });
    window.scrollTo({ top:0, behavior:matchMedia('(prefers-reduced-motion: reduce)').matches ? 'instant' : 'smooth' });
  });
  update();
})();
