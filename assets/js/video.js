/* No provider is contacted until the visitor explicitly activates the player. */
(() => {
  for (const widget of document.querySelectorAll('[data-pb-embed-video]')) {
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
})();
