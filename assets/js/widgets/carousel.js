document.querySelectorAll('[data-pb-carousel]').forEach((root) => {
  const track = root.querySelector('.pb-carousel__track');
  const slides = root.querySelectorAll('.pb-carousel__slide');
  let index = 0;
  const update = () => {
    if (track) track.style.transform = `translateX(-${index * 100}%)`;
  };
  root.querySelector('[data-pb-prev]')?.addEventListener('click', () => {
    index = (index - 1 + slides.length) % Math.max(1, slides.length);
    update();
  });
  root.querySelector('[data-pb-next]')?.addEventListener('click', () => {
    index = (index + 1) % Math.max(1, slides.length);
    update();
  });
});
