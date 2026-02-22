/* ========== CONFIG (injected from PHP) ========== */
const _cfg      = JSON.parse(document.getElementById('watch-config').textContent);
const API_BASE  = _cfg.API_BASE;
const KODIK_API_BASE = _cfg.KODIK_API_BASE;
const PROXY_BASE     = _cfg.PROXY_BASE;
const ANISKIP_API    = _cfg.ANISKIP_API;
const animeId        = _cfg.animeId;

let currentEpisode = _cfg.currentEpisode;

const preferences = {
  autoplay:          _cfg.autoplay,
  autoSkipIntro:     _cfg.autoSkipIntro,
  autoSkipOutro:     _cfg.autoSkipOutro,
  preferredLanguage: _cfg.preferredLanguage,
};

/* ========== STATE ========== */
let animeData           = null;
let mergedData          = null;
let kodikData           = null;
let videoData           = null;

let isLoadingVideo      = false;
let video               = null;
let isIframeMode        = false;

let nextEpisodeTimer    = null;
let nextEpisodeCountdown = 10;
let nextEpisodeCanceled = false;

let lastSavedSecond     = 0;
let resumeAt            = 0;
let hasAttemptedResume  = false;

let progressTimer       = null;
let activeSubTracks     = [];
let lastServersSnapshot = null;
let userWatchPref       = null;

const SERVER_BLACKLIST = ['hd-3', 'hd-1', 'streamtape', 'streamsb', 'filemoon', 'tendoloads'];
const DISPLAY_NAMES = {
  'hd-1':        'HD-1',
  'hd-2':        'HD-2',
  'vidstreaming':'VidStreaming',
  'vidcloud':    'VidCloud',
  'mycloud':     'MyCloud',
  'animefox':    'AnimeFox',
  'kwik':        'Kwik',
};

/* =========================================================
   WATCH PREFERENCES
========================================================= */
const PREF_KEY = () => `watch_pref:${animeId}`;

function savePrefLocal(pref) {
  try { localStorage.setItem(PREF_KEY(), JSON.stringify(pref)); } catch(e) {}
}
function loadPrefLocal() {
  try { return JSON.parse(localStorage.getItem(PREF_KEY()) || 'null'); } catch(e) { return null; }
}
async function savePrefServer(pref) {
  try {
    await fetch('/api/watch/preferences/save.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ anime_id: animeId, ...pref }),
    });
  } catch(e) {}
}
async function loadPrefServer() {
  try {
    const res  = await fetch(`/api/watch/preferences/load.php?anime_id=${encodeURIComponent(animeId)}`);
    const json = await res.json();
    return json?.ok ? json.data : null;
  } catch(e) { return null; }
}
async function initWatchPref() {
  userWatchPref = loadPrefLocal();
  if (!userWatchPref) {
    userWatchPref = await loadPrefServer();
    if (userWatchPref) savePrefLocal(userWatchPref);
  }
}

/* =========================================================
   NETWORK LAYER
========================================================= */
const inFlightJSON = new Map();

function ssGet(key) {
  try {
    const raw = sessionStorage.getItem(key);
    if (!raw) return null;
    const obj = JSON.parse(raw);
    if (!obj?.exp || Date.now() > obj.exp) { sessionStorage.removeItem(key); return null; }
    return obj.val;
  } catch(e) { return null; }
}
function ssSet(key, val, ttlMs) {
  try { sessionStorage.setItem(key, JSON.stringify({ exp: Date.now() + ttlMs, val })); } catch(e) {}
}

async function fetchJSON(url, { cacheKey = null, ttlMs = 30_000, timeoutMs = 20_000, headers = {} } = {}) {
  const key = cacheKey ? `json:${cacheKey}` : null;
  if (key) {
    const hit = ssGet(key);
    if (hit) return hit;
    if (inFlightJSON.has(key)) return await inFlightJSON.get(key);
  }

  const ctrl = new AbortController();
  const t    = setTimeout(() => ctrl.abort(), timeoutMs);

  const p = (async () => {
    try {
      const res  = await fetch(url, { method: 'GET', headers: { 'Accept': 'application/json', ...headers }, signal: ctrl.signal });
      const text = await res.text();
      if (!res.ok) throw new Error(`HTTP ${res.status}: ${text.substring(0, 200)}`);
      const json = JSON.parse(text);
      if (key) ssSet(key, json, ttlMs);
      return json;
    } finally {
      clearTimeout(t);
      if (key) inFlightJSON.delete(key);
    }
  })();

  if (key) inFlightJSON.set(key, p);
  return await p;
}

function proxify(url) {
  return url.startsWith(PROXY_BASE) ? url : `${PROXY_BASE}?url=${encodeURIComponent(url)}`;
}

/* =========================================================
   UTILS
========================================================= */
function showLoading(show) {
  document.getElementById('videoLoading')?.classList.toggle('show', show);
}
function showError(msg) {
  const toast = document.getElementById('errorToast');
  if (!toast) return;
  toast.textContent = msg;
  toast.classList.add('show');
  setTimeout(() => toast.classList.remove('show'), 6000);
}
function fmtTime(s) {
  s = Math.max(0, Math.floor(s || 0));
  const h   = Math.floor(s / 3600);
  const m   = Math.floor((s % 3600) / 60);
  const sec = s % 60;
  if (h > 0) return `${h}:${String(m).padStart(2,'0')}:${String(sec).padStart(2,'0')}`;
  return `${m}:${String(sec).padStart(2,'0')}`;
}

/* =========================================================
   UI AUTO-HIDE
========================================================= */
let uiHideTimer = null;
const UI_HIDE_MS = 2500;

function getUI()  { return document.getElementById('playerUI'); }
function showUI() {
  getUI()?.classList.remove('is-hidden');
  scheduleHideUI();
}
function hideUI() {
  const ui = getUI(); if (!ui) return;
  if (document.getElementById('resumeOverlay')?.classList.contains('show')) return;
  if (document.getElementById('nextEpisodeOverlay')?.classList.contains('show')) return;
  ui.classList.add('is-hidden');
}
function scheduleHideUI() {
  clearTimeout(uiHideTimer);
  uiHideTimer = setTimeout(() => {
    if (!isIframeMode && video && !video.paused && !video.ended) hideUI();
    if (isIframeMode) hideUI();
  }, UI_HIDE_MS);
}

/* =========================================================
   EPISODES PANEL (fullscreen overlay)
========================================================= */
function buildFsEpisodeOverlay() {
  const grid = document.getElementById('fsEpisodesGrid');
  if (!grid || !animeData?.episodes?.length) return;

  grid.innerHTML = '';
  animeData.episodes.forEach(ep => {
    const btn    = document.createElement('button');
    btn.type     = 'button';
    btn.className = 'ep' + (ep.number === currentEpisode ? ' active' : '');
    btn.textContent = ep.number;
    btn.onclick  = async () => {
      document.getElementById('fsEpisodes')?.classList.remove('show');
      await loadEpisode(ep.number);
    };
    grid.appendChild(btn);
  });
}

function wireEpisodesPanel() {
  const btn   = document.getElementById('btnEpisodes');
  const panel = document.getElementById('fsEpisodes');
  const close = document.getElementById('fsEpisodesClose');

  if (btn) btn.onclick = () => {
    if (!animeData?.episodes?.length) return;
    showUI();
    buildFsEpisodeOverlay();
    panel?.classList.toggle('show');
    document.getElementById('fsSubtitles')?.classList.remove('show');
  };
  if (close) close.onclick = () => panel?.classList.remove('show');

  // Native fullscreen events
  document.addEventListener('fullscreenchange', () => {
    const vc = document.getElementById('videoContainer');
    if (vc) vc.classList.toggle('is-fs', !!document.fullscreenElement);
    if (!document.fullscreenElement) {
      panel?.classList.remove('show');
      document.getElementById('fsSubtitles')?.classList.remove('show');
    }
  });
}

/* =========================================================
   SUBTITLES PANEL (fullscreen overlay)
========================================================= */
function wireSubtitlesPanel() {
  const btn   = document.getElementById('btnSubtitles');
  const panel = document.getElementById('fsSubtitles');
  const close = document.getElementById('fsSubtitlesClose');

  if (btn) btn.onclick = () => {
    showUI();
    buildFsSubtitlesOverlay();
    panel?.classList.toggle('show');
    document.getElementById('fsEpisodes')?.classList.remove('show');
  };
  if (close) close.onclick = () => panel?.classList.remove('show');
}

function buildFsSubtitlesOverlay() {
  const grid = document.getElementById('fsSubtitlesGrid');
  if (!grid) return;
  grid.innerHTML = '';

  const offBtn      = document.createElement('button');
  offBtn.type       = 'button';
  offBtn.className  = 'ep' + (activeSubTracks.every(t => t.mode !== 'showing') ? ' active' : '');
  offBtn.textContent = 'Off';
  offBtn.onclick    = () => { applySubtitleSelection('off'); buildFsSubtitlesOverlay(); };
  grid.appendChild(offBtn);

  if (!isIframeMode && activeSubTracks.length > 0) {
    activeSubTracks.forEach((track, idx) => {
      const btn      = document.createElement('button');
      btn.type       = 'button';
      btn.className  = 'ep' + (track.mode === 'showing' ? ' active' : '');
      btn.textContent = track.label || track.language || `Subtitle ${idx + 1}`;
      btn.onclick    = () => { applySubtitleSelection(String(idx)); buildFsSubtitlesOverlay(); };
      grid.appendChild(btn);
    });
  }
}

/* =========================================================
   VIDEO EVENTS
========================================================= */
function startProgressHeartbeat() {
  stopProgressHeartbeat();
  progressTimer = setInterval(() => {
    if (isIframeMode || !video || video.paused || video.ended) return;
    saveProgress(false);
  }, 15000);
}
function stopProgressHeartbeat() {
  if (progressTimer) clearInterval(progressTimer);
  progressTimer = null;
}

function setupVideoEvents() {
  video = document.getElementById('videoPlayer');
  if (!video) return;

  video.addEventListener('timeupdate', () => {
    if (!videoData || isIframeMode) return;

    // Skip intro
    if (videoData.intro) {
      const inIntro = video.currentTime >= videoData.intro.start && video.currentTime < videoData.intro.end;
      document.getElementById('skipIntro')?.classList.toggle('show', inIntro);
      if (preferences.autoSkipIntro && inIntro && video.currentTime < videoData.intro.start + 1) {
        video.currentTime = videoData.intro.end;
      }
    }

    // Skip outro
    if (videoData.outro) {
      const inOutro = video.currentTime >= videoData.outro.start && video.currentTime < videoData.outro.end;
      document.getElementById('skipOutro')?.classList.toggle('show', inOutro);
      if (preferences.autoSkipOutro && inOutro && video.currentTime < videoData.outro.start + 1) {
        video.currentTime = videoData.outro.end;
      }
    }

    // Auto next episode countdown
    if (videoData.outro && preferences.autoplay
        && currentEpisode < (animeData?.episodes?.length || 0)
        && !nextEpisodeTimer && !nextEpisodeCanceled
        && video.currentTime >= videoData.outro.start) {
      startNextEpisodeCountdown();
    }

    // Progress save throttle
    const current = Math.floor(video.currentTime);
    if (Math.abs(current - lastSavedSecond) >= 1) {
      lastSavedSecond = current;
      saveProgress();
    }
  });

  video.addEventListener('play',   startProgressHeartbeat);
  video.addEventListener('pause',  () => { saveProgress(false); stopProgressHeartbeat(); });
  video.addEventListener('ended',  () => { saveProgress(true);  stopProgressHeartbeat(); });

  ['pause', 'seeking'].forEach(ev => video.addEventListener(ev, () => {
    if (nextEpisodeTimer) cancelNextEpisode();
  }));

  document.removeEventListener('keydown', handleKeyboard);
  document.addEventListener('keydown', handleKeyboard);

  document.addEventListener('visibilitychange', () => { if (document.hidden) saveProgress(false); });
  window.addEventListener('beforeunload', () => saveProgress(false, true));
}

function handleKeyboard(e) {
  if (['INPUT', 'TEXTAREA'].includes(e.target.tagName)) return;

  if (e.key === 'Escape') {
    document.getElementById('fsEpisodes')?.classList.remove('show');
    document.getElementById('fsSubtitles')?.classList.remove('show');
    return;
  }
  if (e.key === 'f' || e.key === 'F') {
    const container = document.getElementById('videoContainer');
    if (!container) return;
    document.fullscreenElement ? document.exitFullscreen() : container.requestFullscreen?.();
    return;
  }
  if (isIframeMode || !video) return;
  switch (e.key) {
    case ' ':          e.preventDefault(); video.paused ? video.play().catch(() => {}) : video.pause(); break;
    case 'ArrowLeft':  video.currentTime -= 10; break;
    case 'ArrowRight': video.currentTime += 10; break;
  }
}

/* =========================================================
   PLYR
========================================================= */
function initPlyrPlayer() {
  try {
    const container = document.getElementById('videoContainer');
    if (!container) return;
    container.classList.add('use-plyr');

    if (window.plyrInstance) {
      try { window.plyrInstance.destroy(); } catch(e) {}
      window.plyrInstance = null;
    }

    const el = document.getElementById('videoPlayer');
    if (!el || typeof Plyr === 'undefined') return;

    window.plyrInstance = new Plyr(el, {
      controls: ['play', 'progress', 'current-time', 'mute', 'volume', 'settings', 'fullscreen'],
      settings: ['quality', 'speed', 'captions'],
    });

    window.plyrInstance.on('enterfullscreen', () => {
      document.getElementById('videoContainer')?.classList.add('is-fs');
      buildFsEpisodeOverlay();

    const skipIntro = document.getElementById('skipIntro');
    const skipOutro = document.getElementById('skipOutro');
    const container = document.getElementById('videoContainer');
    if (skipIntro) container.appendChild(skipIntro);
    if (skipOutro) container.appendChild(skipOutro);
    });
    window.plyrInstance.on('exitfullscreen', () => {
      document.getElementById('videoContainer')?.classList.remove('is-fs');
      document.getElementById('fsEpisodes')?.classList.remove('show');
      document.getElementById('fsSubtitles')?.classList.remove('show');
    });
  } catch(err) {}
}

function clearSubtitles() {
  activeSubTracks.forEach(t => (t.mode = 'disabled'));
  activeSubTracks = [];
}
function applySubtitleSelection(value) {
  if (isIframeMode || !video) return;
  activeSubTracks.forEach(t => (t.mode = 'disabled'));
  if (value === 'off') return;
  const idx = parseInt(value, 10);
  if (Number.isFinite(idx) && activeSubTracks[idx]) activeSubTracks[idx].mode = 'showing';
}

/* =========================================================
   LOAD ANIME INFO
========================================================= */
async function loadAnimeInfo() {
  try {
    showLoading(true);

    mergedData = await fetchJSON(`${_cfg.MERGE_API}?slug=${encodeURIComponent(animeId)}`, {
      cacheKey: `merge:${animeId}`,
      ttlMs:    10 * 60_000,
    });
    if (!mergedData || mergedData.error) throw new Error('Anime not found');

    const episodesData = await fetchJSON(`${API_BASE}/anime/${animeId}/episodes`, {
      cacheKey: `episodes:${animeId}`,
      ttlMs:    5 * 60_000,
    });
    if (episodesData?.status !== 200 || !episodesData.data?.episodes?.length) {
      throw new Error('No episodes found');
    }

    animeData = { info: mergedData, episodes: episodesData.data.episodes };

    renderAnimeInfo();
    renderEpisodes();

    if (mergedData.shikimori_id) await loadKodikData(mergedData.shikimori_id);

    const maxEp = animeData.episodes.length;
    if (currentEpisode < 1 || currentEpisode > maxEp) currentEpisode = 1;

    await loadEpisode(currentEpisode);
  } catch(err) {
    console.error('Load anime failed:', err);
    showError('Failed to load anime: ' + (err?.message || err));
  } finally {
    showLoading(false);
  }
}

/* =========================================================
   KODIK API
========================================================= */
async function loadKodikData(shikimoriId) {
  try {
    const data = await fetchJSON(
      `${KODIK_API_BASE}/kodik/search-by-id?anime_id=${shikimoriId}&id_type=shikimori`,
      { cacheKey: `kodik:${shikimoriId}`, ttlMs: 10 * 60_000 }
    );
    if (data?.success && data.results?.length > 0) {
      kodikData = data.results;
    }
  } catch(err) { console.error('Kodik load failed:', err); }
}

async function getKodikInfo(animeId) {
  try {
    const data = await fetchJSON(
      `${KODIK_API_BASE}/kodik/info?anime_id=${animeId}&id_type=shikimori`,
      { cacheKey: `kodik_info:${animeId}`, ttlMs: 10 * 60_000 }
    );
    return data?.success && data.info ? data.info : null;
  } catch(err) { return null; }
}

async function getKodikStreamUrl(animeId, episode, translationId, quality = 720) {
  const data = await fetchJSON(
    `${KODIK_API_BASE}/kodik/stream-url?anime_id=${animeId}&id_type=shikimori&episode=${episode}&translation_id=${translationId}&quality=${quality}`,
    { cacheKey: `kodik_stream:${animeId}:${episode}:${translationId}:${quality}`, ttlMs: 5 * 60_000 }
  );
  if (data?.success && data.stream_url) return data.stream_url;
  throw new Error('No stream URL found');
}

/* =========================================================
   ANISKIP
========================================================= */
async function fetchSkipTimes(malId, episode) {
  try {
    const url  = `${ANISKIP_API}/${malId}/${episode}?types=op&types=ed&episodeLength=0`;
    const data = await fetchJSON(url, { cacheKey: `aniskip:${malId}:${episode}`, ttlMs: 24 * 60 * 60_000 });
    if (!data.found || !data.results?.length) return { intro: null, outro: null };

    let intro = null, outro = null;
    for (const result of data.results) {
      const st = { start: Math.floor(result.interval.startTime), end: Math.floor(result.interval.endTime) };
      if (result.skipType === 'op') intro = st;
      if (result.skipType === 'ed') outro = st;
    }
    return { intro, outro };
  } catch(err) { return { intro: null, outro: null }; }
}

/* =========================================================
   RENDER
========================================================= */
function displayScreenshots(screenshots) {
  const section = document.getElementById('screenshotsSection');
  const grid    = document.getElementById('screenshotsGrid');
  if (!section || !grid) return;
  section.style.display = 'block';
  grid.innerHTML = '';
  screenshots.forEach(url => {
    const img     = document.createElement('img');
    img.src       = url;
    img.className = 'screenshot-thumb';
    img.onclick   = () => openScreenshotModal(url);
    grid.appendChild(img);
  });
}
function openScreenshotModal(url) {
  document.getElementById('screenshotModalImage').src = url;
  document.getElementById('screenshotModal').style.display = 'flex';
}
function closeScreenshotModal() {
  document.getElementById('screenshotModal').style.display = 'none';
}

function renderAnimeInfo() {
  const info  = animeData.info;
  const title = info.title_romaji || info.title_english || info.title_best || 'Unknown';
  document.getElementById('animeTitle').textContent    = title;
  document.getElementById('animeSynopsis').textContent = info.description || 'No synopsis available.';

  document.getElementById('animeDetails').innerHTML = `
    <div class="detail-item"><span class="detail-label">Status:</span><span class="detail-value">${info.status || 'Unknown'}</span></div>
    <div class="detail-item"><span class="detail-label">Type:</span><span class="detail-value">${info.type || 'TV'}</span></div>
    <div class="detail-item"><span class="detail-label">Rating:</span><span class="detail-value">⭐ ${info.score || 'N/A'}</span></div>
    <div class="detail-item"><span class="detail-label">Episodes:</span><span class="detail-value">${animeData.episodes.length}</span></div>
    <div class="detail-item"><span class="detail-label">Year:</span><span class="detail-value">${info.year || 'N/A'}</span></div>
  `;
  document.getElementById('episodeCount').textContent = `${animeData.episodes.length} Episodes`;
}

function renderEpisodes() {
  const container = document.getElementById('episodesList');
  container.innerHTML = '';
  animeData.episodes.forEach(ep => {
    const a       = document.createElement('a');
    a.href        = `watch?id=${animeId}&ep=${ep.number}`;
    a.className   = 'episode-btn' + (ep.number === currentEpisode ? ' active' : '');
    a.textContent = ep.number;
    a.onclick     = e => { e.preventDefault(); loadEpisode(ep.number); };
    container.appendChild(a);
  });
  updateNavButtons();
}

async function loadEpisode(num) {
  if (isLoadingVideo) return;

  currentEpisode = num;
  document.getElementById('episodeTitle').textContent = `Episode ${num}`;
  history.pushState({}, '', `watch?id=${animeId}&ep=${num}`);

  document.querySelectorAll('.episode-btn').forEach(b =>
    b.classList.toggle('active', Number(b.textContent) === num)
  );

  const ep = animeData.episodes.find(e => e.number === num);
  if (!ep) return showError('Episode not found');

  if (videoData?.isKodik && kodikData?.length) {
    const translationId = userWatchPref?.kodik_translation_id || kodikData[0]?.translation?.id;
    if (translationId) {
      await loadKodikStream(mergedData.shikimori_id, num, translationId);
    }
  } else {
    await loadServers(ep.episodeId);
  }

  document.querySelectorAll('#fsEpisodesGrid .ep').forEach(b => {
    b.classList.toggle('active', Number(b.textContent) === num);
  });

  updateNavButtons();
}

/* =========================================================
   SERVERS
========================================================= */
async function loadServers(episodeId) {
  const container = document.getElementById('serverButtons');
  container.innerHTML = '<div style="grid-column:1/-1;text-align:center;color:var(--text-secondary);padding:20px;">Loading servers...</div>';

  let hasEnglishServers = false;

  try {
    const data = await fetchJSON(
      `${API_BASE}/episode/servers?animeEpisodeId=${encodeURIComponent(episodeId)}`,
      { cacheKey: `servers:${episodeId}`, ttlMs: 2 * 60_000 }
    );
    if (data?.status === 200 && data.data) {
      lastServersSnapshot = data.data;
      renderEnglishServers(data.data, episodeId);
      hasEnglishServers = true;
      await tryAutoSelectServer(data.data, episodeId);
    }
  } catch(err) { console.warn('English servers failed', err); }

  if (kodikData?.length > 0) {
    await renderRussianServers();
    if (userWatchPref?.provider === 'kodik') {
      const translationId = userWatchPref.kodik_translation_id;
      if (translationId) await loadKodikStream(mergedData.shikimori_id, currentEpisode, translationId);
    } else if (!hasEnglishServers && kodikData[0]?.translation?.id) {
      await loadKodikStream(mergedData.shikimori_id, currentEpisode, kodikData[0].translation.id);
    }
  }

  if (!hasEnglishServers && !kodikData?.length) {
    container.innerHTML = '<p style="color:#888;grid-column:1/-1;text-align:center;padding:40px;">No servers available</p>';
  }
}

function createServerButton(displayName, badgeClass = '', onClick) {
  const btn     = document.createElement('button');
  btn.className = `server-btn ${badgeClass}`.trim();
  btn.innerHTML = displayName;
  btn.onclick   = onClick;
  return btn;
}

function renderEnglishServers(servers, episodeId) {
  const c = document.getElementById('serverButtons');
  c.innerHTML = '';

  const header = document.createElement('div');
  header.className = 'server-header';
  header.innerHTML = '<span class="lang-badge lang-sub">EN</span> Servers';
  header.style.cssText = 'grid-column:1/-1;margin:0 0 10px;font-weight:600;display:flex;align-items:center;gap:8px;';
  c.appendChild(header);

  ['sub', 'dub'].forEach(cat => {
    const list = (servers[cat] || []).filter(s =>
      !SERVER_BLACKLIST.some(bad => s.serverName.toLowerCase().includes(bad))
    );
    list.forEach(s => {
      const name = DISPLAY_NAMES[s.serverName.toLowerCase()] || s.serverName;
      c.appendChild(createServerButton(name, cat, async () => {
        const pref = { provider: 'hianime', category: cat, server: s.serverName };
        userWatchPref = pref; savePrefLocal(pref); savePrefServer(pref);
        loadVideo(episodeId, s.serverName, cat);
      }));
    });
  });
}

async function renderRussianServers() {
  const c         = document.getElementById('serverButtons');
  const kodikInfo = await getKodikInfo(mergedData.shikimori_id);

  const header = document.createElement('div');
  header.className = 'server-header';
  header.innerHTML = '<span class="lang-badge">RUS</span> Servers';
  header.style.cssText = 'grid-column:1/-1;margin:20px 0 10px;font-weight:600;display:flex;align-items:center;gap:8px;';
  c.appendChild(header);

  const translations = kodikInfo?.translations || [];
  const source       = translations.length > 0 ? translations : (kodikData || []).map(r => r.translation).filter(Boolean);

  source.forEach(trans => {
    const isVoice   = trans.type === 'voice';
    const badgeClass = isVoice ? 'dub' : 'sub';
    const name      = trans.title || trans.name || 'Unknown';

    c.appendChild(createServerButton(name, badgeClass, async () => {
      const pref = { provider: 'kodik', category: isVoice ? 'voice' : 'rus_sub', server: name, kodik_translation_id: trans.id };
      userWatchPref = pref; savePrefLocal(pref); savePrefServer(pref);
      await loadKodikStream(mergedData.shikimori_id, currentEpisode, trans.id);
    }));
  });
}

async function tryAutoSelectServer(servers, episodeId) {
  if (userWatchPref?.provider === 'kodik') return;

  const PRIORITY  = ['hd-2', 'vidstreaming', 'vidcloud', 'mycloud'];
  const categories = ['sub', 'dub'];
  if (userWatchPref?.category && categories.includes(userWatchPref.category)) {
    categories.sort(a => (a === userWatchPref.category ? -1 : 1));
  }

  for (const cat of categories) {
    const list = (servers[cat] || []).filter(s =>
      !SERVER_BLACKLIST.some(bad => s.serverName.toLowerCase().includes(bad))
    );
    if (!list.length) continue;

    if (userWatchPref?.server) {
      const exact = list.find(s => s.serverName.toLowerCase() === userWatchPref.server.toLowerCase());
      if (exact) { await loadVideo(episodeId, exact.serverName, cat); return; }
    }

    const found = list.find(s => PRIORITY.some(p => s.serverName.toLowerCase().includes(p))) || list[0];
    if (found) { await loadVideo(episodeId, found.serverName, cat); return; }
  }
}

/* =========================================================
   HIANIME PLAYER
========================================================= */
function isForbiddenError(err) {
  const m = String(err?.message || err);
  return m.includes('HTTP 403') || m.toLowerCase().includes('forbidden');
}

async function loadVideo(episodeId, serverName, category) {
  if (isLoadingVideo) return;
  isLoadingVideo = true;
  showLoading(true);

  document.querySelectorAll('.server-btn').forEach(b => b.classList.remove('active'));
  if (!isIframeMode) clearSubtitles();

  try {
    if (isIframeMode) { await cleanupVideo(); }
    else {
      document.getElementById('videoPlayer')?.pause();
      if (window.hls) { window.hls.destroy(); window.hls = null; }
    }

    const data = await fetchJSON(
      `${API_BASE}/episode/sources?animeEpisodeId=${encodeURIComponent(episodeId)}&server=${encodeURIComponent(serverName)}&category=${category}`,
      { cacheKey: `sources:${episodeId}:${serverName}:${category}`, ttlMs: 60_000 }
    );

    if (!data)            throw new Error('Empty response from API');
    if (data.error)       throw new Error(data.error);
    if (data.status === 500 || data.message) throw new Error(data.message || 'Server error');
    if (data.status !== 200 && data.status !== true) throw new Error(`API returned status: ${data.status}`);
    if (!data.data)       throw new Error('No data in response');

    const sources   = data.data.sources || [];
    if (!sources.length) throw new Error('No sources available');

    const hlsSource = sources.find(s => s.isM3U8) || sources[0];
    if (!hlsSource?.url) throw new Error('No valid source URL found');

    videoData    = { ...data.data, isKodik: false };
    isIframeMode = false;
    document.getElementById('videoContainer')?.classList.remove('is-iframe');

    // ── Fetch AniSkip for HiAnime episodes too ──
    if (mergedData?.mal_id) {
      const skipTimes = await fetchSkipTimes(mergedData.mal_id, currentEpisode);
      videoData.intro = skipTimes.intro;
      videoData.outro = skipTimes.outro;
    }

    await ensureVideoElement();
    await playVideo(hlsSource.url);

    if (category === 'sub' && Array.isArray(data.data.tracks)) {
      addSubtitles(data.data.tracks);
    }
    buildFsSubtitlesOverlay();

    const badge = document.getElementById('qualityBadge');
    if (badge) badge.textContent = category.toUpperCase();

    document.querySelectorAll('.server-btn').forEach(b => {
      const name = DISPLAY_NAMES[serverName.toLowerCase()] || serverName;
      if (b.textContent.includes(name) && b.classList.contains(category)) b.classList.add('active');
    });

  } catch(err) {
    console.error('loadVideo failed:', err);

    if (category === 'dub' && isForbiddenError(err) && lastServersSnapshot) {
      showError('DUB blocked — trying fallback…');
      const order   = ['vidcloud', 'vidstreaming', 'mycloud', 'hd-2', 'hd-1'];
      const dubList = (lastServersSnapshot.dub || [])
        .filter(s => !SERVER_BLACKLIST.some(bad => s.serverName.toLowerCase().includes(bad)));

      for (const pref of order) {
        const cand = dubList.find(s => s.serverName.toLowerCase().includes(pref));
        if (cand && cand.serverName.toLowerCase() !== serverName.toLowerCase()) {
          isLoadingVideo = false; showLoading(false);
          return await loadVideo(episodeId, cand.serverName, 'dub');
        }
      }

      isLoadingVideo = false; showLoading(false);
      showError('DUB not available — switching to SUB.');
      const subList = (lastServersSnapshot.sub || [])
        .filter(s => !SERVER_BLACKLIST.some(bad => s.serverName.toLowerCase().includes(bad)));
      if (subList[0]) return await loadVideo(episodeId, subList[0].serverName, 'sub');
    }

    showError(`Failed to load ${serverName}: ${err?.message || err}`);
  } finally {
    isLoadingVideo = false;
    showLoading(false);
  }
}

/* =========================================================
   KODIK PLAYER
========================================================= */
async function loadKodikStream(shikimoriId, episode, translationId, quality = 720) {
  if (isLoadingVideo) return;
  isLoadingVideo = true;
  showLoading(true);

  document.querySelectorAll('.server-btn').forEach(b => b.classList.remove('active'));
  if (!isIframeMode) clearSubtitles();

  try {
    if (isIframeMode) { await cleanupVideo(); }
    else {
      document.getElementById('videoPlayer')?.pause();
      if (window.hls) { window.hls.destroy(); window.hls = null; }
    }

    const [skipTimes, streamUrl] = await Promise.all([
      shikimoriId ? fetchSkipTimes(shikimoriId, episode) : Promise.resolve({ intro: null, outro: null }),
      getKodikStreamUrl(shikimoriId, episode, translationId, quality),
    ]);

    videoData    = { sources: [{ url: streamUrl, quality }], tracks: [], ...skipTimes, isKodik: true, translationId };
    isIframeMode = false;
    document.getElementById('videoContainer')?.classList.remove('is-iframe');

    await ensureVideoElement();
    await playHLS(streamUrl, { forKodik: true, quality });

    document.querySelectorAll('.server-btn').forEach(b => {
      if (b.onclick?.toString().includes(translationId)) b.classList.add('active');
    });

  } catch(err) {
    console.error('Kodik stream failed:', err);
    showError('Failed to load Russian stream: ' + (err?.message || err));
  } finally {
    isLoadingVideo = false;
    showLoading(false);
  }
}

/* =========================================================
   UNIFIED HLS PLAYER
   (replaces separate playVideo + playKodikHLS)
========================================================= */
async function playHLS(masterUrl, { forKodik = false, quality = null } = {}) {
  showLoading(true);
  try {
    const v = await ensureVideoElement();
    isIframeMode = false;
    document.getElementById('videoContainer')?.classList.remove('is-iframe');
    hasAttemptedResume = false;

    const url = forKodik ? masterUrl : proxify(masterUrl);

    if (Hls.isSupported()) {
      if (window.hls) { window.hls.destroy(); window.hls = null; }

      window.hls = new Hls({
        debug:                    false,
        enableWorker:             true,
        backBufferLength:         90,
        maxBufferLength:          30,
        maxMaxBufferLength:       60,
        maxBufferHole:            2,
        manifestLoadingTimeOut:   30000,
        manifestLoadingMaxRetry:  4,
        manifestLoadingRetryDelay:1000,
        levelLoadingTimeOut:      30000,
        levelLoadingMaxRetry:     4,
        fragLoadingTimeOut:       30000,
        fragLoadingMaxRetry:      6,
        xhrSetup: xhr => (xhr.withCredentials = false),
      });

      window.hls.on(Hls.Events.MANIFEST_PARSED, () => { tryResumeProgress(); scheduleHideUI(); });
      window.hls.on(Hls.Events.ERROR, (event, data) => {
        if (!data.fatal) return;
        if (data.type === Hls.ErrorTypes.NETWORK_ERROR) setTimeout(() => window.hls?.startLoad(), 800);
        else if (data.type === Hls.ErrorTypes.MEDIA_ERROR) window.hls.recoverMediaError();
        else { showError('Playback error — try another server'); window.hls.destroy(); }
      });

      window.hls.loadSource(url);
      window.hls.attachMedia(v);

    } else if (v.canPlayType('application/vnd.apple.mpegurl')) {
      v.src = url;
      v.addEventListener('loadedmetadata', () => { tryResumeProgress(); scheduleHideUI(); }, { once: true });
    } else {
      throw new Error('HLS not supported in this browser');
    }

    const badge = document.getElementById('qualityBadge');
    if (badge) badge.textContent = forKodik && quality ? `RUS • ${quality}p` : 'AUTO';

  } catch(err) {
    console.error('[HLS] Error:', err);
    showError('Failed to play stream');
  } finally {
    showLoading(false);
  }
}

// Keep old name as alias so nothing breaks during migration
const playVideo      = url => playHLS(url, { forKodik: false });
const playKodikHLS   = (url, q) => playHLS(url, { forKodik: true, quality: q });

/* =========================================================
   ENSURE VIDEO ELEMENT
========================================================= */
function ensureVideoElement() {
  return new Promise((resolve, reject) => {
    let attempts = 0;
    const check  = () => {
      const v = document.getElementById('videoPlayer');
      if (v) { video = v; return resolve(v); }
      if (++attempts >= 50) return reject(new Error('Video element not found'));
      setTimeout(check, 100);
    };
    check();
  });
}

/* =========================================================
   SUBTITLES
========================================================= */
function addSubtitles(tracks) {
  clearSubtitles();
  if (!tracks?.length || !video) return;

  const primary = tracks.find(t => /english/i.test(t.lang)) || tracks[0];

  tracks.forEach(track => {
    const label = (track.lang || '').split(' - ')[0] || 'Sub';
    const tt    = video.addTextTrack('subtitles', label, track.lang || label);
    tt.mode     = (track === primary) ? 'showing' : 'disabled';
    activeSubTracks.push(tt);

    fetch(proxify(track.url))
      .then(r => r.text())
      .then(vtt => { parseVTT(vtt).forEach(cue => tt.addCue(new VTTCue(cue.start, cue.end, cue.text))); buildFsSubtitlesOverlay(); })
      .catch(() => {});
  });
}

function parseVTT(vtt) {
  const cues  = [];
  const lines = vtt.split(/\r?\n/);
  let cue     = null;

  for (let line of lines) {
    line = line.trim();
    if (!line && cue)         { cues.push(cue); cue = null; continue; }
    if (line.includes('-->')) {
      const [s, e] = line.split('-->').map(t => t.trim());
      cue = { start: timeToSeconds(s), end: timeToSeconds(e), text: '' };
      continue;
    }
    if (cue) cue.text += (cue.text ? '\n' : '') + line;
  }
  if (cue) cues.push(cue);
  return cues;
}

function timeToSeconds(t) {
  const p = t.split(':');
  if (p.length === 3) return parseInt(p[0]) * 3600 + parseInt(p[1]) * 60 + parseFloat(p[2]);
  return parseFloat(t || 0);
}

function skipIntro() { if (!isIframeMode && videoData?.intro?.end) video.currentTime = videoData.intro.end; }
function skipOutro() { if (!isIframeMode && videoData?.outro?.end) video.currentTime = videoData.outro.end; }

/* =========================================================
   CLEANUP + REBUILD
========================================================= */
async function cleanupVideo() {
  if (window.hls) { window.hls.destroy(); window.hls = null; }

  const container = document.getElementById('videoContainer');
  if (!container) return;

  if (isIframeMode || container.classList.contains('is-iframe')) {
    isIframeMode = false;
    container.classList.remove('is-iframe');
    await rebuildVideoPlayer();
    return;
  }

  const v = document.getElementById('videoPlayer');
  if (v) {
    v.pause();
    v.removeAttribute('src');
    v.load();
    for (let i = v.textTracks.length - 1; i >= 0; i--) v.textTracks[i].mode = 'disabled';
    v.querySelectorAll('track').forEach(tr => tr.remove());
  }
}

async function rebuildVideoPlayer() {
  const container = document.getElementById('videoContainer');
  if (!container) return;

  container.innerHTML = `
    <video id="videoPlayer" class="video-player" preload="none" playsinline></video>

    <button class="skip-button" id="skipIntro" onclick="skipIntro()">Skip Intro <i class="fas fa-forward"></i></button>
    <button class="skip-button" id="skipOutro" onclick="skipOutro()">Skip Outro <i class="fas fa-forward"></i></button>

    <button class="player-overlay-btn" id="btnEpisodes" type="button" aria-label="Episodes" title="Episodes"><i class="fas fa-list"></i></button>
    <button class="player-overlay-btn" id="btnSubtitles" type="button" aria-label="Subtitles" title="Subtitles" style="right:60px;"><i class="fas fa-closed-captioning"></i></button>

    <div class="fs-episodes" id="fsEpisodes" aria-hidden="true">
      <div class="fs-episodes-header"><span>Episodes</span><button type="button" class="fs-episodes-close" id="fsEpisodesClose">&times;</button></div>
      <div class="fs-episodes-grid" id="fsEpisodesGrid"></div>
    </div>

    <div class="fs-episodes" id="fsSubtitles" aria-hidden="true" style="max-width:300px;">
      <div class="fs-episodes-header"><span>Subtitles</span><button type="button" class="fs-episodes-close" id="fsSubtitlesClose">&times;</button></div>
      <div class="fs-episodes-grid" id="fsSubtitlesGrid" style="grid-template-columns:1fr;"></div>
    </div>

    <div class="player-ui" id="playerUI">
      <div class="next-episode-overlay" id="nextEpisodeOverlay">
        <div class="next-episode-box">
          <p>Next episode in <span id="nextCountdown">10</span></p>
          <div class="next-actions">
            <button onclick="playNextEpisode()">Play Now</button>
            <button onclick="cancelNextEpisode()">Cancel</button>
          </div>
        </div>
      </div>
      <div class="resume-overlay" id="resumeOverlay">
        <div class="resume-box">
          <p>Resume from <strong id="resumeTime"></strong>?</p>
          <div class="resume-actions">
            <button onclick="resumePlayback()">Resume</button>
            <button onclick="dismissResume()">Start Over</button>
          </div>
        </div>
      </div>
    </div>`;

  video = document.getElementById('videoPlayer');
  setupVideoEvents();
  wireEpisodesPanel();
  wireSubtitlesPanel();
  initPlyrPlayer();
  buildFsSubtitlesOverlay();
}

/* =========================================================
   NEXT EPISODE
========================================================= */
async function changeEpisode(dir) {
  const next = currentEpisode + dir;
  if (next >= 1 && next <= animeData.episodes.length) await loadEpisode(next);
}

function updateNavButtons() {
  const prev = document.getElementById('prevBtn');
  const next = document.getElementById('nextBtn');
  if (prev) prev.disabled = currentEpisode <= 1;
  if (next) next.disabled = currentEpisode >= animeData.episodes.length;
}

function startNextEpisodeCountdown() {
  if (!preferences.autoplay || currentEpisode >= animeData.episodes.length) return;
  nextEpisodeCanceled  = false;
  nextEpisodeCountdown = 10;

  const overlay = document.getElementById('nextEpisodeOverlay');
  const counter = document.getElementById('nextCountdown');
  overlay?.classList.add('show');
  if (counter) counter.textContent = nextEpisodeCountdown;

  nextEpisodeTimer = setInterval(() => {
    nextEpisodeCountdown--;
    if (counter) counter.textContent = nextEpisodeCountdown;
    if (nextEpisodeCountdown <= 0) {
      clearInterval(nextEpisodeTimer);
      overlay?.classList.remove('show');
      playNextEpisode();
    }
  }, 1000);
}

function cancelNextEpisode() {
  nextEpisodeCanceled = true;
  clearInterval(nextEpisodeTimer);
  nextEpisodeTimer = null;
  document.getElementById('nextEpisodeOverlay')?.classList.remove('show');
}

function playNextEpisode() {
  clearInterval(nextEpisodeTimer);
  nextEpisodeTimer = null;
  document.getElementById('nextEpisodeOverlay')?.classList.remove('show');
  changeEpisode(1);
}

/* =========================================================
   PROGRESS + RESUME
========================================================= */
function saveProgress(isCompleted = false, useBeacon = false) {
  if (isIframeMode || !video?.duration || isNaN(video.currentTime)) return;

  const payload = {
    anime_id:  animeId,
    episode:   currentEpisode,
    progress:  Math.floor(video.currentTime),
    duration:  Math.floor(video.duration),
    completed: isCompleted ? 1 : 0,
  };

  if (useBeacon && navigator.sendBeacon) {
    navigator.sendBeacon('/api/watch/progress/save.php', new Blob([JSON.stringify(payload)], { type: 'application/json' }));
    return;
  }

  fetch('/api/watch/progress/save.php', {
    method:    'POST',
    headers:   { 'Content-Type': 'application/json' },
    body:      JSON.stringify(payload),
    keepalive: true,
  }).catch(() => {});
}

async function tryResumeProgress() {
  if (isIframeMode || hasAttemptedResume) return;
  hasAttemptedResume = true;

  try {
    const res  = await fetch(`/api/watch/progress/load.php?anime_id=${animeId}&episode=${currentEpisode}`);
    const data = await res.json();

    if (!data || data.completed || data.progress_seconds < 1) {
      setTimeout(() => video.play().catch(() => {}), 200);
      return;
    }
    showResumeOverlay(data.progress_seconds);
  } catch(err) {
    setTimeout(() => video.play().catch(() => {}), 200);
  }
}

function showResumeOverlay(seconds) {
  resumeAt = seconds;
  document.getElementById('resumeTime').textContent = fmtTime(seconds);
  document.getElementById('resumeOverlay')?.classList.add('show');
  document.getElementById('playerUI')?.classList.add('blocked');
}

function resumePlayback() {
  document.getElementById('resumeOverlay')?.classList.remove('show');
  document.getElementById('playerUI')?.classList.remove('blocked');
  video.currentTime = resumeAt;
  video.play().catch(() => {});
  scheduleHideUI();
}

function dismissResume() {
  document.getElementById('resumeOverlay')?.classList.remove('show');
  document.getElementById('playerUI')?.classList.remove('blocked');
  video.play().catch(() => {});
  scheduleHideUI();
}

/* =========================================================
   INIT
========================================================= */
document.addEventListener('DOMContentLoaded', async () => {
  video = document.getElementById('videoPlayer');
  setupVideoEvents();
  wireEpisodesPanel();
  wireSubtitlesPanel();
  initPlyrPlayer();
  buildFsSubtitlesOverlay();

  await initWatchPref();
  loadAnimeInfo();
});