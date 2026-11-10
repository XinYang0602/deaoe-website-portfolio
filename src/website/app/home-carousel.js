(() => {
  const configNode = document.getElementById('home-carousel-config');
  if (!configNode) return;

  const config = JSON.parse(configNode.textContent);
  const reduced = matchMedia('(prefers-reduced-motion: reduce)');
  const delay = Math.max(2, Number(config.interval) || 5) * 1000;

  Object.entries(config.groups).forEach(([key, sources]) => {
    const host = key === 'hero' ? document.querySelector('.hero') : document.querySelector('.products #' + key);
    if (!host || !sources.length) return;

    host.classList.add('home-has-carousel');
    host.querySelectorAll(':scope > img').forEach(img => img.remove());

    const region = document.createElement('div');
    region.className = 'home-carousel';
    region.setAttribute('role', 'region');
    region.setAttribute('aria-label', '产品场景轮播');
    const stage = document.createElement('div');
    stage.className = 'home-carousel-stage';
    const track = document.createElement('div');
    track.className = 'home-carousel-track';
    stage.append(track);
    region.append(stage);
    host.prepend(region);

    const slides = sources.map((src, index) => {
      const img = new Image();
      img.alt = `产品场景 ${index + 1}`;
      img.draggable = false;
      img.hidden = index !== 0;
      img.decoding = 'async';
      if (index === 0 && key !== 'hero') img.loading = 'lazy';
      return { img, src, ready: null };
    });

    function prepare(index) {
      const slide = slides[index];
      if (!slide.ready) {
        slide.img.src = slide.src;
        slide.ready = slide.img.decode().then(() => true, () => false);
      }
      return slide.ready;
    }

    let active = 0;
    let busy = false;
    let hover = false;
    let timer;
    let start;
    const wrap = index => (index + slides.length) % slides.length;
    const first = slides[0].img;
    track.append(first);
    first.addEventListener('load', () => {
      stage.style.aspectRatio = `${first.naturalWidth}/${first.naturalHeight}`;
    }, { once: true });

    function preloadNeighbors() {
      prepare(wrap(active + 1));
      prepare(wrap(active - 1));
    }

    function schedule() {
      clearTimeout(timer);
      if (hover || busy || document.hidden) return;
      timer = setTimeout(() => change(1, true), delay);
    }

    async function change(step, automatic = false) {
      if (busy) return;
      busy = true;
      clearTimeout(timer);
      try {
        // Skip a broken image without hiding the current slide.
        for (let offset = 1; offset < slides.length; offset++) {
          const next = wrap(active + step * offset);
          const ready = await prepare(next);
          if (automatic && (hover || document.hidden)) return;
          if (!ready) continue;

          const outgoing = slides[active].img;
          const incoming = slides[next].img;
          // Move both images on one composited track. Set the starting
          // position before painting so Safari never flashes the new image.
          track.style.transition = 'none';
          incoming.hidden = false;
          if (step > 0) track.append(incoming);
          else track.prepend(incoming);
          track.style.transform = step > 0 ? 'translate3d(0,0,0)' : 'translate3d(-100%,0,0)';
          void track.offsetWidth;
          await new Promise(resolve => requestAnimationFrame(() => requestAnimationFrame(resolve)));
          const duration = reduced.matches ? 180 : 520;
          await new Promise(resolve => {
            let fallback;
            const finish = event => {
              if (event && (event.target !== track || event.propertyName !== 'transform')) return;
              clearTimeout(fallback);
              track.removeEventListener('transitionend', finish);
              resolve();
            };
            track.addEventListener('transitionend', finish);
            fallback = setTimeout(() => finish(), duration + 150);
            track.style.transition = `transform ${duration}ms cubic-bezier(.25,.1,.25,1)`;
            track.style.transform = step > 0 ? 'translate3d(-100%,0,0)' : 'translate3d(0,0,0)';
          });
          track.style.transition = 'none';
          outgoing.remove();
          track.style.transform = 'translate3d(0,0,0)';
          outgoing.hidden = true;
          active = next;
          preloadNeighbors();
          return;
        }
      } finally {
        busy = false;
        schedule();
      }
    }

    if (slides.length < 2) {
      first.src = slides[0].src;
      return;
    }

    stage.classList.add('home-carousel-interactive');
    for (const [step, label, glyph] of [[-1, '上一张', '‹'], [1, '下一张', '›']]) {
      const button = document.createElement('button');
      button.type = 'button';
      button.className = 'home-carousel-arrow ' + (step < 0 ? 'is-prev' : 'is-next');
      button.setAttribute('aria-label', label);
      button.textContent = glyph;
      button.addEventListener('click', () => change(step));
      stage.append(button);
    }

    region.addEventListener('pointerenter', event => {
      if (event.pointerType !== 'mouse') return;
      hover = true;
      clearTimeout(timer);
    });
    region.addEventListener('pointerleave', event => {
      if (event.pointerType !== 'mouse') return;
      hover = false;
      schedule();
    });
    stage.addEventListener('touchstart', event => {
      start = event.touches.length === 1 ? event.touches[0] : null;
    }, { passive: true });
    stage.addEventListener('touchend', event => {
      if (!start) return;
      const end = event.changedTouches[0];
      const dx = end.clientX - start.clientX;
      const dy = end.clientY - start.clientY;
      start = null;
      if (Math.abs(dx) > 45 && Math.abs(dx) > Math.abs(dy)) change(dx < 0 ? 1 : -1);
    }, { passive: true });
    stage.addEventListener('touchcancel', () => { start = null; });
    first.addEventListener('load', () => {
      preloadNeighbors();
      document.addEventListener('visibilitychange', schedule);
      schedule();
    }, { once: true });
    first.addEventListener('error', () => change(1), { once: true });
    first.src = slides[0].src;
  });
})();
