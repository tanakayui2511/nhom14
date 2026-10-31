(() => {
  const progress = document.querySelector('.progress i');
  const menu = document.querySelector('.menu');
  const nav = document.querySelector('#primary-navigation');
  const navLinks = [...nav.querySelectorAll('a[href^="#"]')];
  const filters = [...document.querySelectorAll('[data-filter]')];
  const projects = [...document.querySelectorAll('.project[data-category]')];
  const form = document.querySelector('#contact-form');
  const status = document.querySelector('#form-status');
  const reduceMotion = matchMedia('(prefers-reduced-motion: reduce)').matches;

  const updateProgress = () => {
    const scrollable = document.documentElement.scrollHeight - innerHeight;
    progress.style.width = (scrollable > 0 ? scrollY / scrollable * 100 : 0) + '%';
  };
  addEventListener('scroll', updateProgress, { passive: true });
  addEventListener('resize', updateProgress);
  updateProgress();

  const closeMenu = () => {
    menu.setAttribute('aria-expanded', 'false');
    menu.setAttribute('aria-label', 'Mở điều hướng');
    nav.classList.remove('open');
    document.body.classList.remove('menu-open');
  };
  menu.addEventListener('click', () => {
    const open = menu.getAttribute('aria-expanded') !== 'true';
    menu.setAttribute('aria-expanded', String(open));
    menu.setAttribute('aria-label', open ? 'Đóng điều hướng' : 'Mở điều hướng');
    nav.classList.toggle('open', open);
    document.body.classList.toggle('menu-open', open);
  });
  navLinks.forEach(link => link.addEventListener('click', closeMenu));
  addEventListener('keydown', event => { if (event.key === 'Escape') closeMenu(); });

  filters.forEach(button => button.addEventListener('click', () => {
    const filter = button.dataset.filter;
    filters.forEach(item => {
      const active = item === button;
      item.classList.toggle('active', active);
      item.setAttribute('aria-pressed', String(active));
    });
    projects.forEach(project => project.classList.toggle('hidden', filter !== 'all' && project.dataset.category !== filter));
  }));

  const revealItems = document.querySelectorAll('.reveal');
  const sections = navLinks.map(link => document.querySelector(link.getAttribute('href'))).filter(Boolean);
  if ('IntersectionObserver' in window && !reduceMotion) {
    const revealObserver = new IntersectionObserver((entries, observer) => entries.forEach(entry => {
      if (entry.isIntersecting) { entry.target.classList.add('visible'); observer.unobserve(entry.target); }
    }), { threshold: .12 });
    revealItems.forEach(item => revealObserver.observe(item));
    const sectionObserver = new IntersectionObserver(entries => entries.forEach(entry => {
      if (!entry.isIntersecting) return;
      navLinks.forEach(link => {
        if (link.hash === '#' + entry.target.id) link.setAttribute('aria-current', 'location');
        else link.removeAttribute('aria-current');
      });
    }), { rootMargin: '-30% 0px -55% 0px' });
    sections.forEach(section => sectionObserver.observe(section));
  } else revealItems.forEach(item => item.classList.add('visible'));

  const dashboard = document.querySelector('.dashboard');
  if (dashboard && !matchMedia('(pointer: coarse)').matches && !reduceMotion) {
    dashboard.addEventListener('pointermove', event => {
      const rect = dashboard.getBoundingClientRect();
      const x = (event.clientX - rect.left) / rect.width - .5;
      const y = (event.clientY - rect.top) / rect.height - .5;
      dashboard.style.transform = 'rotateX(' + (-y * 5) + 'deg) rotateY(' + (x * 8) + 'deg)';
    });
    dashboard.addEventListener('pointerleave', () => { dashboard.style.removeProperty('transform'); });
  }
  if (!matchMedia('(pointer: coarse)').matches) {
    document.querySelectorAll('.project-art').forEach(art => art.addEventListener('pointermove', event => {
      const rect = art.getBoundingClientRect();
      art.style.setProperty('--spot-x', (event.clientX - rect.left) + 'px');
      art.style.setProperty('--spot-y', (event.clientY - rect.top) + 'px');
      art.classList.add('spot-active');
    }));
  }

  form.addEventListener('submit', event => {
    event.preventDefault();
    if (!form.reportValidity()) return;
    const data = new FormData(form);
    const subject = encodeURIComponent('Trao đổi dự án từ ' + data.get('name'));
    const body = encodeURIComponent('Tên: ' + data.get('name') + '\nEmail: ' + data.get('email') + '\n\n' + data.get('message'));
    status.textContent = 'Đang mở ứng dụng email để gửi nội dung đã điền.';
    location.href = 'mailto:hello@example.com?subject=' + subject + '&body=' + body;
  });
})();