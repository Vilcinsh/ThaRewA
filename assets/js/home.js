/* =========================================================
   REW HOME JS (FULL) — FAST CACHING + SWR + DEDUPE
   - RAM + sessionStorage/localStorage cache with TTL
   - Stale-While-Revalidate (instant paint + background refresh)
   - In-flight request dedupe
   - Concurrency-limited enrich via MERGE_API
========================================================= */

/* ===================== CONFIG ===================== */
const API_BASE   = 'https://api.rewcrew.lv/api/v2/hianime';
const PROXY_URL  = 'https://corsproxy.rewcrew.lv/proxy?url=';

// IMPORTANT: set this to your real working merge endpoint:
//const MERGE_API  = '/api/merge.php'; // or 'https://rew.vissnavslikti.lv/api/merge.php'

const FETCH_TIMEOUT_MS = 15000;

/* TTL presets (tune freely) */
const TTL = {
  HOME_FRESH: 60_000,                 // 1 min
  HOME_SWR:   120_000,                // allow stale 2 min (background refresh)
  COMPLETED_FRESH: 10 * 60_000,        // 10 min
  COMPLETED_SWR:   60 * 60_000,        // 60 min stale ok
  SEARCH_FRESH: 2 * 60_000,
  SEARCH_SWR:   10 * 60_000,
  MERGE_FRESH:  7 * 86400 * 1000,      // 7 days
  MERGE_SWR:    14 * 86400 * 1000,     // 14 days stale ok
  ENRICH_FRESH: 5 * 60_000,
  ENRICH_SWR:   10 * 60_000,
};

let currentSlide = 0;
let sliderData = [];
let sliderTimer = null;

/* ===================== CACHE LAYER ===================== */
const CACHE_NS = 'rew_home_v3';
const memCache = new Map();   // RAM: key -> {exp, ts, val}
const inflight = new Map();   // key -> Promise

function _now() { return Date.now(); }
function _nsKey(k) { return `${CACHE_NS}:${k}`; }

function cacheRead(k, { storage = 'session' } = {}) {
  const kk = _nsKey(k);

  const m = memCache.get(kk);
  if (m) {
    return { hit: true, val: m.val, stale: m.exp <= _now(), exp: m.exp, ts: m.ts };
  }

  try {
    const s = storage === 'local' ? localStorage : sessionStorage;
    const raw = s.getItem(kk);
    if (!raw) return { hit: false, val: null, stale: false, exp: 0, ts: 0 };
    const obj = JSON.parse(raw);
    if (!obj) return { hit: false, val: null, stale: false, exp: 0, ts: 0 };

    memCache.set(kk, obj);
    return { hit: true, val: obj.val, stale: obj.exp <= _now(), exp: obj.exp, ts: obj.ts };
  } catch {
    return { hit: false, val: null, stale: false, exp: 0, ts: 0 };
  }
}

function cacheWrite(k, val, { ttlMs = 60_000, storage = 'session' } = {}) {
  const kk = _nsKey(k);
  const obj = { exp: _now() + ttlMs, ts: _now(), val };

  memCache.set(kk, obj);

  try {
    const s = storage === 'local' ? localStorage : sessionStorage;
    s.setItem(kk, JSON.stringify(obj));
    if (Math.random() < 0.02) cachePrune({ storage, maxItems: 300 });
  } catch {
    // ignore quota errors
  }
}

function cachePrune({ storage = 'session', maxItems = 300 } = {}) {
  try {
    const s = storage === 'local' ? localStorage : sessionStorage;

    const keys = [];
    for (let i = 0; i < s.length; i++) {
      const k = s.key(i);
      if (k && k.startsWith(`${CACHE_NS}:`)) keys.push(k);
    }
    if (keys.length <= maxItems) return;

    // Remove expired first
    const expired = [];
    for (const k of keys) {
      try {
        const obj = JSON.parse(s.getItem(k));
        if (obj?.exp && obj.exp <= _now()) expired.push(k);
      } catch {}
    }
    expired.forEach(k => s.removeItem(k));

    // Re-check count
    const left = [];
    for (let i = 0; i < s.length; i++) {
      const k = s.key(i);
      if (k && k.startsWith(`${CACHE_NS}:`)) left.push(k);
    }
    if (left.length <= maxItems) return;

    // Remove oldest by ts
    const items = left.map(k => {
      try {
        const obj = JSON.parse(s.getItem(k));
        return { k, ts: obj?.ts || 0 };
      } catch {
        return { k, ts: 0 };
      }
    }).sort((a, b) => a.ts - b.ts);

    const toRemove = items.slice(0, items.length - maxItems);
    toRemove.forEach(x => s.removeItem(x.k));
  } catch {}
}

/**
 * cachedFetchJSON
 * - returns { data, cache: HIT|MISS|WAIT|STALE }
 * - swrMs: if cached is stale, still return it immediately and refresh in background
 */
async function cachedFetchJSON(cacheKey, fetcher, {
  ttlMs = 60_000,
  swrMs = 0,
  storage = 'session'
} = {}) {
  const r = cacheRead(cacheKey, { storage });

  // Fresh hit
  if (r.hit && !r.stale) return { data: r.val, cache: 'HIT' };

  // SWR: allow stale return and background refresh
  if (r.hit && r.stale && swrMs > 0) {
    // If stale is allowed, return it right away
    // (Optional: you can restrict stale-age by comparing r.exp / r.ts, but not required)
    if (!inflight.has(cacheKey)) {
      const p = (async () => {
        try {
          const fresh = await fetcher();
          if (fresh != null) cacheWrite(cacheKey, fresh, { ttlMs, storage });
          return fresh;
        } finally {
          inflight.delete(cacheKey);
        }
      })();
      inflight.set(cacheKey, p);
    }
    return { data: r.val, cache: 'STALE' };
  }

  // Dedupe inflight
  if (inflight.has(cacheKey)) {
    const data = await inflight.get(cacheKey);
    return { data, cache: 'WAIT' };
  }

  const p = (async () => {
    try {
      const fresh = await fetcher();
      if (fresh != null) cacheWrite(cacheKey, fresh, { ttlMs, storage });
      return fresh;
    } finally {
      inflight.delete(cacheKey);
    }
  })();

  inflight.set(cacheKey, p);
  const data = await p;
  return { data, cache: 'MISS' };
}

/* ===================== FETCH HELPERS ===================== */
async function fetchJson(url, { timeoutMs = FETCH_TIMEOUT_MS } = {}) {
  const controller = new AbortController();
  const timer = setTimeout(() => controller.abort(), timeoutMs);
  try {
    const response = await fetch(url, { signal: controller.signal });
    if (!response.ok) throw new Error(`HTTP ${response.status}`);
    return await response.json();
  } finally {
    clearTimeout(timer);
  }
}

/**
 * Proxy fetch with caching (sessionStorage default)
 * Use opts.ttlMs/opts.swrMs per endpoint.
 */
async function fetchWithProxy(url, opts = {}) {
  const proxiedUrl = PROXY_URL + encodeURIComponent(url);

  const key = `proxy:${url}`;
  const ttlMs = opts.ttlMs ?? TTL.HOME_FRESH;
  const swrMs = opts.swrMs ?? TTL.HOME_SWR;
  const timeoutMs = opts.timeoutMs ?? FETCH_TIMEOUT_MS;

  const { data } = await cachedFetchJSON(
    key,
    async () => {
      try {
        return await fetchJson(proxiedUrl, { timeoutMs });
      } catch (e) {
        console.error('Proxy fetch error:', e);
        return null;
      }
    },
    { ttlMs, swrMs, storage: 'session' }
  );

  return data;
}

/**
 * Merge API with long-lived localStorage cache
 */
async function fetchMergeAPI(slug) {
  if (!slug) return null;

  const key = `merge:${slug}`;
  const url = `${MERGE_API}?slug=${encodeURIComponent(slug)}`;

  const { data } = await cachedFetchJSON(
    key,
    async () => {
      try {
        const res = await fetch(url, { cache: 'no-store' });
        if (!res.ok) return null;
        const text = await res.text();
        if (!text.trim().startsWith('{') && !text.trim().startsWith('[')) return null;
        const json = JSON.parse(text);
        if (json?.error) return null;
        return json;
      } catch (e) {
        console.warn('Merge API failed:', slug, e?.message || e);
        return null;
      }
    },
    { ttlMs: TTL.MERGE_FRESH, swrMs: TTL.MERGE_SWR, storage: 'local' }
  );

  return data;
}

/* ===================== CONCURRENCY MAP ===================== */
async function pMap(items, mapper, { concurrency = 6 } = {}) {
  const results = new Array(items.length);
  let nextIndex = 0;

  async function worker() {
    while (true) {
      const i = nextIndex++;
      if (i >= items.length) return;
      results[i] = await mapper(items[i], i);
    }
  }

  const workers = Array.from({ length: Math.min(concurrency, items.length) }, () => worker());
  await Promise.all(workers);
  return results;
}

/* ===================== SKELETONS ===================== */
function showSkeletons(containerId, count = 8) {
  const container = document.getElementById(containerId);
  if (!container) return;
  container.innerHTML = '';
  const frag = document.createDocumentFragment();
  for (let i = 0; i < count; i++) {
    const skeleton = document.createElement('div');
    skeleton.className = 'anime-card skeleton-card';
    skeleton.innerHTML = `
      <div class="skeleton-poster"></div>
      <div class="anime-info skeleton-info">
        <div class="skeleton-line"></div>
        <div class="skeleton-line" style="width:70%"></div>
      </div>
    `;
    frag.appendChild(skeleton);
  }
  container.appendChild(frag);
}

/* ===================== CARD UI ===================== */
function createAnimeCard(anime) {
  const card = document.createElement('div');
  card.className = 'anime-card';

  const animeId = anime.hianime_id || anime.id;
  if (animeId && !String(animeId).startsWith('shiki-')) {
    card.onclick = () => window.location.href = `watch?id=${animeId}`;
  } else {
    card.style.opacity = '0.6';
    card.style.cursor = 'not-allowed';
    card.title = 'Not available on HiAnime';
  }

  const epCount = anime.episodes ? (anime.episodes.sub || anime.episodes.dub || '?') : '';
  const hasSub = (anime.episodes?.sub || 0) > 0;
  const hasDub = (anime.episodes?.dub || 0) > 0;

  let langBadge = '';
  if (hasSub && hasDub) {
    langBadge = '<span class="lang-badge lang-sub">SUB</span><span class="lang-badge lang-dub">DUB</span>';
  } else if (hasSub) {
    langBadge = '<span class="lang-badge lang-sub">SUB</span>';
  } else if (hasDub) {
    langBadge = '<span class="lang-badge lang-dub">DUB</span>';
  }

  card.innerHTML = `
    ${langBadge}
    ${epCount ? `<span class="anime-badge">EP ${epCount}</span>` : ''}
    <img src="${anime.poster}" alt="${anime.name || anime.jname || 'Anime'}" class="anime-poster" loading="lazy" decoding="async"
         onerror="this.src='https://via.placeholder.com/200x280?text=No+Image'">
    <div class="anime-info">
      <h3 class="anime-title">${anime.name || anime.jname || 'Unknown'}</h3>
      <div class="anime-meta">
        <span>${anime.type || 'TV'}</span>
        <span>⭐ ${anime.rating || 'N/A'}</span>
      </div>
    </div>
  `;
  return card;
}

function displayCarousel(containerId, animes) {
  const container = document.getElementById(containerId);
  if (!container || !Array.isArray(animes) || animes.length === 0) {
    if (container) container.innerHTML = '<p style="color: var(--text-secondary);">No anime available</p>';
    return;
  }

  container.innerHTML = '';
  const frag = document.createDocumentFragment();
  const displayAnimes = containerId === 'trendingCarousel' ? animes.slice(0, 10) : animes;

  displayAnimes.forEach((anime) => frag.appendChild(createAnimeCard(anime)));
  container.appendChild(frag);
}

/* ===================== HERO SLIDER ===================== */
function setupHeroSlider(animes) {
  sliderData = Array.isArray(animes) ? animes : [];
  const container = document.getElementById('sliderContainer');
  const dotsContainer = document.getElementById('sliderDots');
  if (!container || !dotsContainer || sliderData.length === 0) return;

  container.innerHTML = '';
  dotsContainer.innerHTML = '';

  const slidesFrag = document.createDocumentFragment();
  const dotsFrag = document.createDocumentFragment();

  sliderData.forEach((anime, index) => {
    const slide = document.createElement('div');
    slide.className = 'slide';
    slide.innerHTML = `
      <div class="slide-background">
        <img src="${anime.poster}" alt="${anime.name || 'Anime'}" class="slide-bg-image">
        <div class="slide-gradient"></div>
      </div>
      <div class="slide-content">
        <div class="slide-poster">
          <img src="${anime.poster}" alt="${anime.name || 'Anime'}">
        </div>
        <div class="slide-details">
          <span class="slide-badge">TRENDING #${index + 1}</span>
          <h1 class="slide-title">${anime.name || anime.jname || 'Unknown'}</h1>
          <div class="slide-info">
            <span><i class="fas fa-tv"></i> ${anime.type || 'TV'}</span>
            <span><i class="fas fa-play"></i> ${anime.episodes?.sub || anime.episodes?.dub || '?'} Episodes</span>
            <span><i class="fas fa-star"></i> ${anime.rating || 'N/A'}</span>
          </div>
          <p class="slide-description">${anime.description || 'Watch the latest episodes now!'}</p>
          <div class="slide-buttons">
            <a href="watch?id=${anime.id}" class="btn btn-primary">
              <i class="fas fa-play"></i> Watch Now
            </a>
          </div>
        </div>
      </div>
    `;
    slidesFrag.appendChild(slide);

    const dot = document.createElement('span');
    dot.className = index === 0 ? 'dot active' : 'dot';
    dot.dataset.index = String(index);
    dotsFrag.appendChild(dot);
  });

  container.appendChild(slidesFrag);
  dotsContainer.appendChild(dotsFrag);

  dotsContainer.onclick = (e) => {
    const dot = e.target?.closest?.('.dot');
    if (!dot) return;
    const idx = parseInt(dot.dataset.index, 10);
    if (Number.isFinite(idx)) goToSlide(idx);
  };

  currentSlide = 0;
  updateSlider();
}

function updateSlider() {
  const container = document.getElementById('sliderContainer');
  if (!container) return;
  container.style.transform = `translateX(-${currentSlide * 100}%)`;
  document.querySelectorAll('.dot').forEach((dot, i) => dot.classList.toggle('active', i === currentSlide));
}

function nextSlide() {
  if (!sliderData.length) return;
  currentSlide = (currentSlide + 1) % sliderData.length;
  updateSlider();
}

function prevSlide() {
  if (!sliderData.length) return;
  currentSlide = (currentSlide - 1 + sliderData.length) % sliderData.length;
  updateSlider();
}

function goToSlide(index) {
  currentSlide = Math.max(0, Math.min(index, sliderData.length - 1));
  updateSlider();
}

function startSliderAutoPlay() {
  if (!Array.isArray(sliderData) || sliderData.length < 2) return;
  if (sliderTimer) clearInterval(sliderTimer);
  sliderTimer = setInterval(nextSlide, 6000);
}

/* ===================== CAROUSEL NAV ===================== */
function scrollCarousel(containerId, direction = 1) {
  const container = document.getElementById(containerId);
  if (!container) return;

  const card = container.querySelector('.anime-card') || container.querySelector('.skeleton-card');
  if (!card) return;

  const cardWidth = card.offsetWidth + parseFloat(getComputedStyle(card).marginRight || 20);
  container.scrollBy({ left: direction * cardWidth * 3, behavior: 'smooth' });
}

function initCarousels() {
  document.querySelectorAll('.carousel-nav').forEach(btn => {
    const targetId = btn.dataset.target;
    if (!targetId) return;
    const dir = btn.classList.contains('prev') ? -1 : 1;
    btn.addEventListener('click', () => scrollCarousel(targetId, dir));
  });
}

/* ===================== ENRICH (MERGE) ===================== */
async function enrichWithShikimori(animes) {
  if (!Array.isArray(animes) || animes.length === 0) return [];

  const ids = animes.map(a => a?.id).filter(Boolean).join(',');
  const key = `enrich:${ids}`;

  const { data } = await cachedFetchJSON(
    key,
    async () => {
      return await pMap(
        animes,
        async (anime) => {
          try {
            const mergeData = await fetchMergeAPI(anime.id);
            if (mergeData && !mergeData.error && mergeData.shikimori_id) {
              return {
                ...anime,
                shikimori_id: mergeData.shikimori_id,
                title_russian: mergeData.title_russian,
                has_russian: true,
                // optional overrides if you want:
                // name: mergeData.title_best || anime.name,
                // poster: mergeData.poster || anime.poster,
              };
            }
            return anime;
          } catch (e) {
            console.warn('enrich failed:', anime?.id, e?.message || e);
            return anime;
          }
        },
        { concurrency: 6 }
      );
    },
    { ttlMs: TTL.ENRICH_FRESH, swrMs: TTL.ENRICH_SWR, storage: 'session' }
  );

  return data || [];
}

/* ===================== COMPLETED ===================== */
async function loadCompletedAnime(page = 1) {
  try {
    const url = `${API_BASE}/category/completed?page=${page}`;
    const data = await fetchWithProxy(url, { ttlMs: TTL.COMPLETED_FRESH, swrMs: TTL.COMPLETED_SWR });
    if (data?.status !== 200 || !data.data?.animes) return;

    const completed = data.data.animes.slice(0, 30);
    displayCarousel('completedContainer', completed);

    const gridEl = document.getElementById('completedGrid');
    if (gridEl) {
      gridEl.dataset.animes = JSON.stringify(data.data.animes);
      gridEl.dataset.page = data.data.currentPage ?? page;
      gridEl.dataset.hasNext = data.data.hasNextPage ?? false;
    }
  } catch (err) {
    console.error('loadCompletedAnime error:', err);
  }
}

/* ===================== CONTINUE WATCHING ===================== */
function slugToQuery(slug) {
  return String(slug || '')
    .replace(/-\d+$/, '')
    .replace(/-/g, ' ')
    .trim();
}

async function fetchAnimeInfoBySlug(animeId) {
  const mergeData = await fetchMergeAPI(animeId);
  if (mergeData && !mergeData.error) {
    return {
      id: mergeData.hianime_id || animeId,
      name: mergeData.title_best || mergeData.title_russian || mergeData.title_romaji,
      jname: mergeData.title_japanese,
      poster: mergeData.poster,
      type: mergeData.type,
      rating: mergeData.score,
      episodes: mergeData.episodes
    };
  }

  // Fallback search (cached via proxy.php on your server; keep as-is)
  const query = slugToQuery(animeId);
  try {
    const res = await fetch(
      `/proxy.php?url=${encodeURIComponent(`${API_BASE}/search?q=${query}&page=1`)}`
    );
    const json = await res.json();
    if (!json?.data?.animes?.length) return null;
    return json.data.animes.find(a => a.id === animeId) || json.data.animes[0];
  } catch (e) {
    console.warn('Anime search failed:', animeId, e);
    return null;
  }
}

function renderContinueCard(item) {
  const anime = item.anime;
  if (!anime) return '';

  const percent = item.duration_seconds
    ? Math.min(100, Math.floor((item.progress_seconds / item.duration_seconds) * 100))
    : 0;

  return `
    <a class="anime-card" href="watch?id=${item.anime_id}&ep=${item.episode}">
      <div class="anime-poster">
        <img src="${anime.poster}" alt="${anime.name}" loading="lazy" decoding="async">
        <div class="progress-bar">
          <div class="progress-fill" style="width:${percent}%"></div>
        </div>
      </div>
      <div class="anime-info">
        <div class="anime-title">${anime.name}</div>
        <div class="anime-meta">
          EP ${item.episode} · ${percent}% · ${anime.type || 'TV'}
        </div>
      </div>
    </a>
  `;
}

async function loadContinueWatching() {
  const section = document.getElementById('continue-watch');
  const container = document.getElementById('continueCarousel');

  if (!container) {
    if (section) section.style.display = 'none';
    return;
  }

  const normalizeTitleKey = (t) => String(t || '')
    .toLowerCase()
    .replace(/\s+/g, ' ')
    .trim();

  const toMs = (v) => {
    if (v == null) return 0;
    if (typeof v === 'number') return v < 1e12 ? v * 1000 : v;
    const s = String(v).trim();
    if (/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}/.test(s)) {
      const iso = s.replace(' ', 'T') + 'Z';
      const dIso = Date.parse(iso);
      if (Number.isFinite(dIso)) return dIso;
    }
    const d = Date.parse(s);
    return Number.isFinite(d) ? d : 0;
  };

  const getRecency = (item) => Math.max(
    toMs(item?.updated_at),
    toMs(item?.updatedAt),
    toMs(item?.last_watched_at),
    toMs(item?.watched_at),
    toMs(item?.watchedAt),
    toMs(item?.created_at),
    toMs(item?.timestamp)
  );

  const pickLatest = (a, b) => {
    const ra = getRecency(a);
    const rb = getRecency(b);
    if (rb !== ra) return rb > ra ? b : a;

    const ea = Number(a?.episode) || 0;
    const eb = Number(b?.episode) || 0;
    if (eb !== ea) return eb > ea ? b : a;

    const pa = Number(a?.progress_seconds) || 0;
    const pb = Number(b?.progress_seconds) || 0;
    return pb > pa ? b : a;
  };

  try {
    const res = await fetch('/api/watch/continue.php', { cache: 'no-store' });
    const progressList = await res.json();

    if (!Array.isArray(progressList) || progressList.length === 0) {
      if (section) section.style.display = 'none';
      return;
    }

    // Dedupe by anime_id (reduce calls)
    const bestByAnimeId = new Map();
    for (const item of progressList) {
      const key = String(item?.anime_id || '');
      if (!key) continue;
      const prev = bestByAnimeId.get(key);
      bestByAnimeId.set(key, prev ? pickLatest(prev, item) : item);
    }

    const unique = Array.from(bestByAnimeId.values());

    // Enrich with concurrency limit
    const enriched = await pMap(
      unique,
      async (item) => {
        const anime = await fetchAnimeInfoBySlug(item.anime_id);
        return { ...item, anime };
      },
      { concurrency: 6 }
    );

    // Dedupe by title key (keep latest)
    const bestByTitle = new Map();
    for (const item of enriched) {
      if (!item?.anime) continue;
      const titleKey = normalizeTitleKey(item.anime.name || item.anime.jname || item.anime.id);
      if (!titleKey) continue;
      const prev = bestByTitle.get(titleKey);
      bestByTitle.set(titleKey, prev ? pickLatest(prev, item) : item);
    }

    const finalList = Array.from(bestByTitle.values())
      .sort((a, b) => getRecency(b) - getRecency(a));

    if (finalList.length === 0) {
      if (section) section.style.display = 'none';
      return;
    }

    container.innerHTML = finalList.map(renderContinueCard).join('');
  } catch (err) {
    console.error('Continue Watching failed', err);
    container.innerHTML = '<p style="color:#888">Failed to load</p>';
  }
}

/* ===================== HOME INIT ===================== */
document.addEventListener('DOMContentLoaded', () => {
  // skeletons first (fast paint)
  ['recentCarousel', 'trendingCarousel', 'topRatedCarousel', 'completedContainer'].forEach(id => showSkeletons(id, 10));

  initCarousels();
  if (typeof setupSearch === 'function') setupSearch();

  // non-blocking
  loadContinueWatching();
  loadCompletedAnime();

  (async () => {
    // cached proxy home
    const data = await fetchWithProxy(`${API_BASE}/home`, { ttlMs: TTL.HOME_FRESH, swrMs: TTL.HOME_SWR });
    if (data?.status !== 200) return;

    const trending = data.data.trendingAnimes || [];
    const recentAnimes = data.data.latestEpisodeAnimes || [];
    const topRated = data.data.topAiringAnimes || [];

    // enrich in parallel
    const [enrichedTrending, enrichedRecent, enrichedTopRated] = await Promise.all([
      enrichWithShikimori(trending.slice(0, 10)),
      enrichWithShikimori(recentAnimes.slice(0, 10)),
      enrichWithShikimori(topRated.slice(0, 10))
    ]);

    setupHeroSlider(enrichedTrending.slice(0, 5));
    displayCarousel('trendingCarousel', enrichedTrending);
    displayCarousel('recentCarousel', enrichedRecent);
    displayCarousel('topRatedCarousel', enrichedTopRated);

    startSliderAutoPlay();
  })().catch(err => console.error('Home init failed', err));
});
