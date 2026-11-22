/* No automatic rotation: without JS all images remain visible. */
(() => {
  for (const carousel of document.querySelectorAll('[data-pb-carousel]')) {
    const slides = [...carousel.querySelectorAll('[data-pb-slide]')];
    if (!slides.length) continue;
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
      slides.forEach((slide, position) => { slide.hidden = position !== index; });
      status.textContent = positionText(index);
    };
    previous.addEventListener('click', () => show(index - 1));
    next.addEventListener('click', () => show(index + 1));
    carousel.addEventListener('keydown', event => {
      if (event.key === 'ArrowLeft') { event.preventDefault(); show(index - 1); }
      else if (event.key === 'ArrowRight') { event.preventDefault(); show(index + 1); }
    });
    show(0);
  }
})();
