(() => {
  const root = document.documentElement;
  const reducedMotion = matchMedia('(prefers-reduced-motion: reduce)').matches;
  const normalize = value => value
    .normalize('NFD')
    .replace(/[\u0300-\u036f]/g, '')
    .toLowerCase()
    .replace(/\u0111/g, 'd')
    .replace(/[^a-z0-9]+/g, '_')
    .replace(/^_|_$/g, '');

  const themeToggles = document.querySelectorAll('[data-theme-toggle]');
  const applyTheme = mode => {
    const dark = mode === 'dark';
    root.dataset.theme = mode;
    root.style.colorScheme = mode;
    themeToggles.forEach(toggle => {
      toggle.setAttribute('aria-pressed', String(dark));
      toggle.setAttribute('aria-label', dark ? 'Chuyển sang giao diện sáng' : 'Chuyển sang giao diện tối');
      toggle.title = dark ? 'Giao diện sáng' : 'Giao diện tối';
      const label = toggle.querySelector('[data-theme-label]');
      if (label) label.textContent = dark ? 'Sáng' : 'Tối';
    });
  };

  applyTheme(root.dataset.theme === 'dark' ? 'dark' : 'light');
  themeToggles.forEach(toggle => toggle.addEventListener('click', () => {
    const next = root.dataset.theme === 'dark' ? 'light' : 'dark';
    try { localStorage.setItem('rt-theme', next); } catch (error) {}
    applyTheme(next);
  }));

  const body = document.body;
  const menuToggle = document.querySelector('[data-nav-open]');
  const closeMenu = () => {
    body.classList.remove('nav-open');
    menuToggle?.setAttribute('aria-expanded', 'false');
  };
  menuToggle?.addEventListener('click', () => {
    const open = !body.classList.contains('nav-open');
    body.classList.toggle('nav-open', open);
    menuToggle.setAttribute('aria-expanded', String(open));
  });
  document.querySelectorAll('[data-nav-close], .main-nav a').forEach(control => control.addEventListener('click', closeMenu));
  addEventListener('keydown', event => {
    if (event.key === 'Escape') closeMenu();
  });

  document.querySelectorAll('.main-nav a').forEach(link => {
    const currentPath = location.pathname === '/index.php' ? '/' : location.pathname;
    if (new URL(link.href, location.href).pathname === currentPath) link.setAttribute('aria-current', 'page');
  });

  document.querySelectorAll('.pill').forEach(pill => {
    const token = normalize(pill.textContent.trim());
    if (token) pill.dataset.token = token;
  });

  document.querySelectorAll('[data-avatar-name]').forEach(avatar => {
    const name = avatar.dataset.avatarName || '';
    let hash = 0;
    for (const character of name) hash = ((hash << 5) - hash + character.codePointAt(0)) | 0;
    avatar.style.setProperty('--avatar-hue', String(Math.abs(hash) % 360));
  });

  const flow = document.querySelector('.release-flow');
  if (flow) {
    const status = document.querySelector('.page-releases .panel h2 .pill');
    const current = status ? normalize(status.textContent.trim()) : '';
    const order = ['planned', 'ready', 'released'];
    const currentIndex = order.indexOf(current);
    flow.dataset.current = currentIndex >= 0 ? current : 'none';
    if (currentIndex >= 0) {
      flow.querySelectorAll('li').forEach((item, index) => {
        item.dataset.state = index < currentIndex ? 'done' : index === currentIndex ? 'current' : 'upcoming';
        if (index === currentIndex) item.setAttribute('aria-current', 'step');
      });
    }
  }

  if (!reducedMotion) {
    document.querySelectorAll('[data-count]').forEach(counter => {
      const target = Number(counter.dataset.count || 0);
      const duration = 650;
      const start = performance.now();
      const tick = now => {
        const progress = Math.min(1, (now - start) / duration);
        counter.textContent = String(Math.round(target * (1 - Math.pow(1 - progress, 3))));
        if (progress < 1) requestAnimationFrame(tick);
      };
      counter.textContent = '0';
      requestAnimationFrame(tick);
    });
  }

  const animated = document.querySelectorAll('.page-heading, .kpi-card, .app-main > .panel, .app-main > .grid, .dashboard-visuals');
  animated.forEach((element, index) => {
    element.classList.add('fade-in');
    if (!reducedMotion) element.style.animationDelay = `${Math.min(index * 45, 240)}ms`;
  });

  document.querySelectorAll('.btn, button:not(.theme-toggle):not(.menu-toggle):not(.sidebar-close):not(.sidebar-scrim)').forEach(control => {
    control.addEventListener('pointerdown', event => {
      if (reducedMotion || control.disabled) return;
      const rect = control.getBoundingClientRect();
      const size = Math.max(rect.width, rect.height);
      const ripple = document.createElement('span');
      ripple.className = 'ripple';
      ripple.style.width = ripple.style.height = `${size}px`;
      ripple.style.left = `${event.clientX - rect.left - size / 2}px`;
      ripple.style.top = `${event.clientY - rect.top - size / 2}px`;
      control.append(ripple);
      ripple.addEventListener('animationend', () => ripple.remove());
    });
  });

  document.querySelectorAll('.error, .ok').forEach(message => {
    message.setAttribute('role', message.classList.contains('error') ? 'alert' : 'status');
    message.classList.add('toast-message');
  });

  const issueTable = document.querySelector('.page-issues table');
  if (issueTable) {
    const rows = [...issueTable.querySelectorAll('tbody tr[data-priority]')];
    const tableWrap = issueTable.closest('.table-wrap');
    if (rows.length && tableWrap) {
      const filters = [
        ['all', 'Tất cả'],
        ['blocker', 'Blocker'],
        ['critical', 'Critical'],
        ['high', 'High'],
        ['medium', 'Medium'],
        ['low', 'Low']
      ];
      const group = document.createElement('div');
      group.className = 'filter-chips';
      group.setAttribute('aria-label', 'Lọc theo độ ưu tiên');
      filters.forEach(([value, label], index) => {
        const button = document.createElement('button');
        button.type = 'button';
        button.className = 'filter-chip';
        button.dataset.priority = value;
        button.dataset.active = String(index === 0);
        button.setAttribute('aria-pressed', String(index === 0));
        button.textContent = label;
        button.addEventListener('click', () => {
          group.querySelectorAll('button').forEach(item => {
            const active = item === button;
            item.dataset.active = String(active);
            item.setAttribute('aria-pressed', String(active));
          });
          rows.forEach(row => { row.hidden = value !== 'all' && row.dataset.priority !== value; });
        });
        group.append(button);
      });
      tableWrap.before(group);
    }
  }
})();
