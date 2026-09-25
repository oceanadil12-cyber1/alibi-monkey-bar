document.addEventListener('DOMContentLoaded', () => {

  // Header scroll effect — transparent at top, solid on scroll
  const header = document.querySelector('.header');
  window.addEventListener('scroll', () => {
    header.classList.toggle('scrolled', window.scrollY > 80);
  });

  // Hero image slider
  const heroSlides = document.querySelectorAll('.hero-slide');
  const heroDots = document.querySelectorAll('.hero-dot');
  const heroPrev = document.querySelector('.hero-nav.prev');
  const heroNext = document.querySelector('.hero-nav.next');
  let heroIndex = 0;
  let heroTimer;

  // Slides after the first carry data-src so they don't compete with the first
  // image; they load once the page has finished loading, or on demand.
  const loadHeroImage = (slide) => {
    const img = slide && slide.querySelector('img[data-src]');
    if (img) { img.src = img.dataset.src; img.removeAttribute('data-src'); }
  };
  const loadAllHeroImages = () => heroSlides.forEach(loadHeroImage);
  if (document.readyState === 'complete') loadAllHeroImages();
  else window.addEventListener('load', loadAllHeroImages);

  function showHeroSlide(index) {
    heroSlides.forEach(s => s.classList.remove('active'));
    heroDots.forEach(d => d.classList.remove('active'));
    heroIndex = (index + heroSlides.length) % heroSlides.length;
    loadHeroImage(heroSlides[heroIndex]);
    if (heroSlides[heroIndex]) heroSlides[heroIndex].classList.add('active');
    if (heroDots[heroIndex]) heroDots[heroIndex].classList.add('active');
  }

  function startHeroTimer() {
    clearInterval(heroTimer);
    heroTimer = setInterval(() => showHeroSlide(heroIndex + 1), 7000);
  }

  if (heroSlides.length > 1) {
    if (heroPrev) heroPrev.addEventListener('click', () => { showHeroSlide(heroIndex - 1); startHeroTimer(); });
    if (heroNext) heroNext.addEventListener('click', () => { showHeroSlide(heroIndex + 1); startHeroTimer(); });
    heroDots.forEach((dot, i) => dot.addEventListener('click', () => { showHeroSlide(i); startHeroTimer(); }));
    startHeroTimer();
  }

  // Lazy loading for things browsers can't lazy-load themselves: background
  // photos (data-bg) and the Elfsight widgets (Instagram feed, Google reviews),
  // which only load once they're close to being scrolled into view.
  // Runs fn for each element once it's within `margin` px below the screen or
  // anywhere above it, so a fast flick or an anchor jump never skips one.
  const whenNear = (els, margin, fn) => {
    if (!els.length) return;
    let queued = false;
    const check = () => {
      queued = false;
      for (let i = els.length - 1; i >= 0; i--) {
        if (els[i].getBoundingClientRect().top < window.innerHeight + margin) fn(els.splice(i, 1)[0]);
      }
      if (!els.length) {
        window.removeEventListener('scroll', onScroll);
        window.removeEventListener('resize', onScroll);
      }
    };
    const onScroll = () => { if (!queued) { queued = true; requestAnimationFrame(check); } };
    window.addEventListener('scroll', onScroll, { passive: true });
    window.addEventListener('resize', onScroll, { passive: true });
    check();
  };

  whenNear([...document.querySelectorAll('[data-bg]')], 600, (el) => {
    el.style.backgroundImage = `url("${el.dataset.bg}")`;
    el.removeAttribute('data-bg');
  });

  let elfsightLoaded = false;
  whenNear([...document.querySelectorAll('[class*="elfsight-app-"]')], 800, () => {
    if (elfsightLoaded) return;
    elfsightLoaded = true;
    const script = document.createElement('script');
    script.src = 'https://static.elfsight.com/platform/platform.js';
    script.async = true;
    document.body.appendChild(script);
  });

  // Happy Hour (daily 4:00–6:45 PM, restaurant time): "happening now" status
  // for the home-page ticker and the menu-page Happy Hour section.
  const hhEls = document.querySelectorAll('[data-hh-status]');
  const updateHappyHour = () => {
    const parts = new Intl.DateTimeFormat('en-US', { timeZone: 'America/New_York', hour: '2-digit', minute: '2-digit', hourCycle: 'h23' }).formatToParts(new Date());
    const mins = Number(parts.find(p => p.type === 'hour').value) * 60 + Number(parts.find(p => p.type === 'minute').value);
    const live = mins >= 16 * 60 && mins < 18 * 60 + 45;
    hhEls.forEach(el => {
      el.classList.toggle('is-live', live);
      const bar = el.closest('.hh-ticker');
      if (bar) bar.classList.toggle('is-live', live);
      if (live) {
        el.textContent = 'Happy Hour is on now · until 6:45 PM';
        el.hidden = false;
      } else if (el.dataset.hhIdle) {
        el.innerHTML = el.dataset.hhIdle;
      } else {
        el.hidden = true;
      }
    });
  };
  if (hhEls.length) {
    updateHappyHour();
    setInterval(updateHappyHour, 60000);
  }

  // Duplicate the ticker items so the scroll loops seamlessly.
  document.querySelectorAll('.hh-ticker-items').forEach(list => {
    list.parentNode.appendChild(list.cloneNode(true));
  });

  // Mobile menu toggle
  const menuToggle = document.querySelector('.menu-toggle');
  const mobileNav = document.querySelector('.mobile-nav');
  const mobileNavClose = document.querySelector('.mobile-nav-close');

  if (menuToggle) {
    menuToggle.addEventListener('click', () => mobileNav.classList.add('open'));
  }
  if (mobileNavClose) {
    mobileNavClose.addEventListener('click', () => mobileNav.classList.remove('open'));
  }
  if (mobileNav) {
    mobileNav.querySelectorAll('a').forEach(link => {
      link.addEventListener('click', () => mobileNav.classList.remove('open'));
    });
  }

  // Scroll animations
  const fadeEls = document.querySelectorAll('.fade-in');
  const observer = new IntersectionObserver((entries) => {
    entries.forEach(entry => {
      if (entry.isIntersecting) {
        entry.target.classList.add('visible');
      }
    });
  }, { threshold: 0.1 });

  fadeEls.forEach(el => observer.observe(el));

  // Instagram feed infinite scroll — presses the Elfsight widget's "See More Posts"
  // button whenever the visitor nears the end of the feed (newest posts first).
  const infiniteFeed = document.querySelector('[data-infinite-scroll]');
  if (infiniteFeed) {
    const findLoadMore = (root) => {
      for (const el of root.querySelectorAll('*')) {
        if (el.shadowRoot) {
          const found = findLoadMore(el.shadowRoot);
          if (found) return found;
        }
        const isLoadMore = el.classList.contains('es-load-more-button') ||
          ((el.tagName === 'BUTTON' || el.getAttribute('role') === 'button') &&
            /load more|see more posts|show more/i.test(el.textContent));
        if (isLoadMore && el.offsetParent !== null) {
          return el;
        }
      }
      return null;
    };

    let loading = false;

    // Load the next batch once the end of the feed is within 600px of the fold
    // (also when the visitor has already scrolled past it, e.g. a fast flick).
    const checkFeedEnd = () => {
      if (loading || infiniteFeed.getBoundingClientRect().bottom > window.innerHeight + 600) return;
      const button = findLoadMore(infiniteFeed);
      if (!button) return;
      loading = true;
      button.click();
      // Give the widget time to render, then check again in case the end of
      // the feed is still on screen.
      setTimeout(() => { loading = false; checkFeedEnd(); }, 1500);
    };

    window.addEventListener('scroll', checkFeedEnd, { passive: true });
  }

  // Smooth scroll for anchor links
  document.querySelectorAll('a[href^="#"]').forEach(anchor => {
    anchor.addEventListener('click', (e) => {
      const target = document.querySelector(anchor.getAttribute('href'));
      if (target) {
        e.preventDefault();
        target.scrollIntoView({ behavior: 'smooth', block: 'start' });
      }
    });
  });

  // Counter animation for stats
  const counters = document.querySelectorAll('[data-count]');
  const counterObserver = new IntersectionObserver((entries) => {
    entries.forEach(entry => {
      if (entry.isIntersecting) {
        const target = parseInt(entry.target.dataset.count);
        let current = 0;
        const step = target / 60;
        const timer = setInterval(() => {
          current += step;
          if (current >= target) {
            entry.target.textContent = target + (entry.target.dataset.suffix || '');
            clearInterval(timer);
          } else {
            entry.target.textContent = Math.floor(current) + (entry.target.dataset.suffix || '');
          }
        }, 20);
        counterObserver.unobserve(entry.target);
      }
    });
  }, { threshold: 0.5 });

  counters.forEach(c => counterObserver.observe(c));

  // Forms (contact, reservations, newsletter) post JSON to api/*.php.
  // Nothing is ever put in the page URL; results show inline under the form.
  document.querySelectorAll('form[data-endpoint]').forEach((form) => {
    const startedAt = Date.now();
    const status = form.parentElement.querySelector('.form-status') || form.querySelector('.form-status');
    const fallback = "Sorry, that didn't go through. Please call us at (954) 565-2526.";
    const show = (msg, kind) => {
      if (!status) return;
      status.textContent = msg;
      status.hidden = !msg;
      status.className = 'form-status' + (kind ? ' is-' + kind : '');
    };

    const date = form.querySelector('input[type="date"]');
    if (date) date.min = new Date().toISOString().slice(0, 10);

    form.addEventListener('submit', async (e) => {
      e.preventDefault();
      show('', '');
      if (!form.reportValidity()) return;
      const button = form.querySelector('[type="submit"]');
      const label = button.textContent;
      const data = Object.fromEntries(new FormData(form));
      data.startedAt = startedAt;
      data.page = location.pathname;
      if (form.dataset.type) data.type = form.dataset.type;
      button.disabled = true;
      button.textContent = 'Sending…';
      try {
        const res = await fetch(form.dataset.endpoint, {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify(data),
        });
        let json;
        try { json = await res.json(); } catch (err) { throw new Error(fallback); } // no PHP on this host
        if (!json.ok) throw new Error(json.error || fallback);
        form.reset();
        const tmp = document.createElement('textarea');
        tmp.innerHTML = form.dataset.success || 'Thank you!';
        show(tmp.value, 'ok');
      } catch (err) {
        show(err.message && !/fetch|network/i.test(err.message) ? err.message : fallback, 'error');
      } finally {
        button.disabled = false;
        button.textContent = label;
      }
    });
  });
});
