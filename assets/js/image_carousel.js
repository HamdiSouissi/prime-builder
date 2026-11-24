/* No automatic rotation: without JS all slides remain visible. */
(() => {
  const enhanced = new WeakSet();
  const initialize = root => {
    const widgets = [...root.querySelectorAll('[data-pb-carousel]')];
    if (root.matches?.('[data-pb-carousel]')) widgets.unshift(root);
    for (const carousel of widgets) {
      if (enhanced.has(carousel)) continue;
      const slides = [...carousel.querySelectorAll('[data-pb-slide]')];
      if (!slides.length) continue;
      enhanced.add(carousel);
      carousel.setAttribute('role', 'region');
      carousel.setAttribute('aria-label', carousel.dataset.pbCarouselLabel || 'Images');
      carousel.setAttribute('aria-roledescription', carousel.dataset.pbCarouselRole || 'carousel');
      const positionText = index => (carousel.dataset.pbCarouselPosition || ':current / :total')
        .replace(':current', String(index + 1)).replace(':total', String(slides.length));
      slides.forEach((slide, index) => {
        slide.setAttribute('role', 'group');
        slide.setAttribute('aria-roledescription', carousel.dataset.pbCarouselSlideRole || 'slide');
        slide.setAttribute('aria-label', positionText(index));
      });
      if (slides.length === 1) continue;
      const controls = document.createElement('div');
      controls.className = 'pb-carousel-controls';
      const makeButton = label => {
        const button = document.createElement('button');
        button.type = 'button'; button.textContent = label; controls.append(button); return button;
      };
      const previous = makeButton(carousel.dataset.pbCarouselPrevious || 'Previous');
      const next = makeButton(carousel.dataset.pbCarouselNext || 'Next');
      const status = document.createElement('span');
      status.setAttribute('role', 'status');
      controls.append(status); carousel.append(controls);
      let index = 0;
      const show = target => {
        index = (target + slides.length) % slides.length;
        slides.forEach((slide, position) => {
          slide.hidden = position !== index;
          if (slide.hidden) {
            for (const media of slide.querySelectorAll('video, audio')) {
              if (!media.paused) media.pause();
            }
          }
        });
        status.textContent = positionText(index);
      };
      previous.addEventListener('click', () => show(index - 1));
      next.addEventListener('click', () => show(index + 1));
      carousel.addEventListener('keydown', event => {
        // Leave native controls, links and editable fields in charge of their own keys.
        if (event.defaultPrevented || event.altKey || event.ctrlKey || event.metaKey || event.shiftKey ||
            event.target?.closest?.('a, video, audio, input, textarea, select, [contenteditable]:not([contenteditable="false"])')) return;
        if (event.key === 'ArrowLeft') { event.preventDefault(); show(index - 1); }
        else if (event.key === 'ArrowRight') { event.preventDefault(); show(index + 1); }
      });
      show(0);
    }
  };
  initialize(document);
  document.addEventListener('prime-builder:fragment', event => {
    if (typeof event.target?.querySelectorAll === 'function') initialize(event.target);
  });
})();
