/* No provider is contacted until the visitor explicitly activates the player. */
(() => {
  const enhanced = new WeakSet();
  const initialize = root => {
    const widgets = [...root.querySelectorAll('[data-pb-embed-video]')];
    if (root.matches?.('[data-pb-embed-video]')) widgets.unshift(root);
    for (const widget of widgets) {
      if (enhanced.has(widget)) continue;
      const button = widget.querySelector('[data-pb-video-load]');
      if (!button) continue;
      const { pbProvider: provider, pbVideoId: id } = widget.dataset;
      let src = '';
      if (provider === 'youtube' && /^[A-Za-z0-9_-]{11}$/.test(id || '')) {
        src = `https://www.youtube-nocookie.com/embed/${id}?autoplay=1`;
      } else if (provider === 'vimeo' && /^[0-9]{4,15}$/.test(id || '')) {
        src = `https://player.vimeo.com/video/${id}?dnt=1&autoplay=1`;
      }
      if (!src) continue;
      enhanced.add(widget);
      button.hidden = false;
      button.addEventListener('click', () => {
        const frame = document.createElement('iframe');
        frame.title = button.dataset.pbVideoTitle || (provider === 'youtube' ? 'YouTube' : 'Vimeo');
        frame.src = src;
        frame.allow = 'autoplay; fullscreen; picture-in-picture';
        frame.setAttribute('allowfullscreen', '');
        frame.referrerPolicy = 'strict-origin-when-cross-origin';
        button.replaceWith(frame);
        frame.focus();
      }, { once: true });
    }
  };
  initialize(document);
  document.addEventListener('prime-builder:fragment', event => {
    if (typeof event.target?.querySelectorAll === 'function') initialize(event.target);
  });
})();
