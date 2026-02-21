const MERGE_API = 'https://proxy.rewcrew.lv/api/marge.php';
const MERGE_SEARCH_API = 'https://proxy.rewcrew.lv/api/search.php';

let searchTimeout;
let searchCache = new Map();

/* =========================
   MERGE API (cached)
========================= */
async function fetchMergeAPI(slug) {
  if (!slug) return null;

  if (searchCache.has(slug)) {
    return searchCache.get(slug);
  }

  try {
    const response = await fetch(`${MERGE_API}?slug=${encodeURIComponent(slug)}`);
    if (!response.ok) {
      console.warn(`Merge API HTTP error ${response.status} for: ${slug}`);
      return null;
    }

    const text = await response.text();

    // Validate JSON-ish response
    const trimmed = text.trim();
    if (!trimmed.startsWith('{') && !trimmed.startsWith('[')) {
      console.warn(`Merge API returned non-JSON for ${slug}:`, trimmed.substring(0, 100));
      return null;
    }

    const data = JSON.parse(trimmed);

    if (data?.error) {
      console.warn(`Merge API error for ${slug}:`, data.error);
      return null;
    }

    searchCache.set(slug, data);
    return data;
  } catch (error) {
    console.warn(`Merge API failed for ${slug}:`, error.message);
    return null;
  }
}

/* =========================
   SEARCH UI SETUP
========================= */
function setupSearch() {
  const input = document.getElementById('searchInput');
  const dropdown = document.getElementById('searchDropdown');
  if (!input || !dropdown) return;

  input.addEventListener('input', (e) => {
    clearTimeout(searchTimeout);

    const query = (e.target.value || '').trim();
    if (query.length < 2) {
      dropdown.classList.remove('show');
      return;
    }

    searchTimeout = setTimeout(() => performSearch(query), 300);
  });

  document.addEventListener('click', (e) => {
    if (!e.target.closest('.search-container')) {
      dropdown.classList.remove('show');
    }
  });
}

/* =========================
   SEARCH REQUEST
========================= */
async function performSearch(query) {
  const dropdown = document.getElementById('searchDropdown');
  if (!dropdown) return;

  dropdown.innerHTML = '<div class="loading"><div class="loading-spinner"></div></div>';
  dropdown.classList.add('show');

  try {

    const response = await fetch(`${MERGE_SEARCH_API}?q=${encodeURIComponent(query)}`, {
      method: 'GET',
      headers: { 'Accept': 'application/json' }
    });


    if (!response.ok) {
      throw new Error(`HTTP ${response.status}`);
    }

    const data = await response.json();

    if (data?.status === 200 && Array.isArray(data?.results) && data.results.length > 0) {
      displaySearchResults(data.results);
    } else {
      console.warn('⚠️ No results - Response:', data);
      dropdown.innerHTML = `
        <p style="padding: 20px; text-align: center; color: var(--text-secondary);">
          Ничего не найдено / No results found
          <br><small style="opacity:0.6">Method: ${data?.search_method || 'unknown'} | Query: "${query}"</small>
        </p>`;
    }
  } catch (error) {
    console.error('❌ Search error:', error);
    dropdown.innerHTML = `
      <p style="padding: 20px; text-align: center; color: var(--text-secondary);">
        Error: ${error.message}
      </p>`;
  }
}

/* =========================
   RENDER SEARCH RESULTS
========================= */
function displaySearchResults(animes) {
  const dropdown = document.getElementById('searchDropdown');
  if (!dropdown) return;

  dropdown.innerHTML = '';

  animes.forEach((anime, idx) => {
    const item = document.createElement('div');
    item.className = 'search-item';

    const animeId = anime?.hianime_id || anime?.id;
    const isClickable = animeId && !String(animeId).startsWith('shiki-');

    if (isClickable) {
      item.onclick = () => (window.location.href = `watch.php?id=${animeId}`);
    } else {
      item.style.opacity = '0.6';
      item.style.cursor = 'not-allowed';
      item.title = 'Not available on HiAnime';
    }

    const displayTitle =
      anime?.title_russian ||
      anime?.name ||
      anime?.title_english ||
      anime?.title_romaji ||
      'Unknown';

    let secondaryTitle = '';
    if (anime?.title_russian && anime?.title_romaji && anime.title_russian !== anime.title_romaji) {
      secondaryTitle = `<div style="font-size: 0.85em; color: var(--text-secondary); margin-top: 2px;">${anime.title_romaji}</div>`;
    } else if (anime?.title_russian && anime?.title_english && anime.title_russian !== anime.title_english) {
      secondaryTitle = `<div style="font-size: 0.85em; color: var(--text-secondary); margin-top: 2px;">${anime.title_english}</div>`;
    } else if (!anime?.title_russian && anime?.title_english && anime.title_english !== displayTitle) {
      secondaryTitle = `<div style="font-size: 0.85em; color: var(--text-secondary); margin-top: 2px;">${anime.title_english}</div>`;
    }

    const epCount = anime?.episodes?.sub || anime?.episodes?.dub || '?';

    item.innerHTML = `
      <img src="${anime?.poster || ''}" alt="${displayTitle}"
           onerror="this.src='https://via.placeholder.com/50x70?text=No+Image'">
      <div class="search-item-info">
        <div class="search-item-title">
          ${displayTitle}
          ${secondaryTitle}
        </div>
        <div class="search-item-meta">
          ${anime?.type || 'TV'} • ${epCount} Ep • ⭐ ${anime?.rating || 'N/A'}
        </div>
      </div>
    `;

    dropdown.appendChild(item);
  });
}

/* =========================
   MANUAL SEARCH TRIGGER
========================= */
function searchAnime() {
  const query = document.getElementById('searchInput')?.value?.trim();
  if (query) performSearch(query);
}

/* =========================
   SLUG -> QUERY (for fallback search)
========================= */
function slugToQuery(slug) {
  return String(slug || '')
    .replace(/-\d+$/, '')
    .replace(/-/g, ' ')
    .trim();
}

/* =========================
   ANIME LOOKUP BY SLUG
   - merge API first
   - fallback to old search endpoint
========================= */
async function fetchAnimeInfoBySlug(animeId) {
  // Try merge API first (with caching + better error handling)
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

  // Fallback to old method (via your proxy.php)
  const query = slugToQuery(animeId);

  try {
    const res = await fetch(
      `/proxy.php?url=${encodeURIComponent(
        `https://thatrew.vercel.app/api/v2/hianime/search?q=${query}&page=1`
      )}`
    );

    const json = await res.json();
    if (!json?.data?.animes?.length) return null;

    return json.data.animes.find((a) => a.id === animeId) || json.data.animes[0];
  } catch (e) {
    console.warn('Anime search failed:', animeId, e);
    return null;
  }
}

/* =========================
   OPTIONAL: Auto-init if the input exists
   (Safe: if your main file already calls setupSearch(), this will just do it once.)
========================= */
document.addEventListener('DOMContentLoaded', () => {
  if (document.getElementById('searchInput') && document.getElementById('searchDropdown')) {
    setupSearch();
  }
});

// ================ I'M FEELING LUCKY ================

// Recommended: your cards should have data-id="<animeId>"
function extractAnimeIdFromCard(card) {
  // 1) data-id (best)
  const did = card.getAttribute('data-id');
  if (did) return did;

  // 2) link href inside the card
  const a = card.querySelector('a[href*="watch.php?id="], a[href*="watch?id="], a[href*="?id="]');
  if (a && a.getAttribute('href')) {
    try {
      const href = a.getAttribute('href');
      const u = new URL(href, window.location.origin);
      const id = u.searchParams.get('id');
      if (id) return id;
    } catch (_) {}
  }

  // 3) onclick string fallback (last resort)
  const raw = String(card.getAttribute('onclick') || '');
  const m = raw.match(/id=([^'"]+)/);
  return m ? m[1] : null;
}

function randomAnime() {
  // Priority 1: sliderData
  if (Array.isArray(sliderData) && sliderData.length > 0) {
    const random = sliderData[Math.floor(Math.random() * sliderData.length)];
    if (random?.id) {
      window.location.href = `watch.php?id=${encodeURIComponent(random.id)}`;
      return;
    }
  }

  // Priority 2: visible cards inside trending carousel
  const trendingContainer = document.getElementById('trendingCarousel');
  if (trendingContainer) {
    const cards = trendingContainer.querySelectorAll('.anime-card');
    const ids = Array.from(cards)
      .map(extractAnimeIdFromCard)
      .filter(Boolean);

    if (ids.length > 0) {
      const id = ids[Math.floor(Math.random() * ids.length)];
      window.location.href = `watch.php?id=${encodeURIComponent(id)}`;
      return;
    }
  }

  alert('Dati vēl ielādējas — mēģini pēc brīža!');
}

// Bind button
document.addEventListener('DOMContentLoaded', () => {
  const btn = document.getElementById('btnLucky');
  if (btn) btn.addEventListener('click', randomAnime);

  // Optional shortcut: press "L"
  document.addEventListener('keydown', (e) => {
    if (e.key.toLowerCase() === 'l' && !e.ctrlKey && !e.metaKey && !e.altKey) {
      const t = e.target;
      if (t && (t.tagName === 'INPUT' || t.tagName === 'TEXTAREA')) return;
      randomAnime();
    }
  });
});


/* =========================
   OPTIONAL: expose globally (useful if you switch to modules later)
========================= */
window.setupSearch = setupSearch;
window.performSearch = performSearch;
window.displaySearchResults = displaySearchResults;
window.searchAnime = searchAnime;
window.slugToQuery = slugToQuery;
window.fetchMergeAPI = fetchMergeAPI;
window.fetchAnimeInfoBySlug = fetchAnimeInfoBySlug;
