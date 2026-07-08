/* ============================================================
   Live order progress timer.
   Drives every .order-timer element on the page:
     data-start = unix seconds when the order was placed
     data-ready = unix seconds when it should be ready
   Updates a countdown label (.ot-remaining) and a progress
   bar (.ot-fill), and toggles .ot-soon / .ot-done states.
   ============================================================ */
(function () {
  function update(el) {
    const start = parseInt(el.dataset.start, 10) * 1000;
    const ready = parseInt(el.dataset.ready, 10) * 1000;
    if (!start || !ready || ready <= start) return;

    const fill = el.querySelector('.ot-fill');
    const out  = el.querySelector('.ot-remaining');
    const now  = Date.now();
    const left = ready - now;
    const pct  = Math.max(0, Math.min(100, ((now - start) / (ready - start)) * 100));

    if (fill) fill.style.width = pct + '%';
    el.classList.toggle('ot-done', left <= 0);
    el.classList.toggle('ot-soon', left > 0 && left <= 5 * 60 * 1000);

    if (!out) return;
    if (left <= 0) {
      const over = Math.floor(-left / 1000);
      out.textContent = over < 60 ? 'Ready' : ('Overdue ' + Math.floor(over / 60) + 'm');
    } else {
      const s = Math.floor(left / 1000);
      out.textContent = Math.floor(s / 60) + ':' + String(s % 60).padStart(2, '0');
    }
  }

  function tick() {
    document.querySelectorAll('.order-timer').forEach(update);
  }

  tick();
  setInterval(tick, 1000);
})();
