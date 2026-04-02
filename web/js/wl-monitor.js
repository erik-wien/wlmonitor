/**
 * wl-monitor.js -- ES module
 * Vanilla JS, fetch() based. No jQuery.
 */

// --- State -------------------------------------------------------------------
let stationCache = [];
let monitorTimer = null;

// --- Init --------------------------------------------------------------------
document.addEventListener('DOMContentLoaded', () => {
  applyTheme();
  // Render any PHP session alerts passed via wlConfig
  if (window.wlConfig?.alerts?.length) {
    for (const [type, msg] of window.wlConfig.alerts) {
      sendAlert(msg, type);
    }
  }
  loadFavorites();
  loadMonitor();
  startMonitorTimer();
  wireScrollButton();
  wireStationSort();
  wireThemeToggle();
});

// --- API helpers -------------------------------------------------------------
async function apiFetch(action, params = {}) {
  const url = new URL('api.php', location.href);
  url.searchParams.set('action', action);
  for (const [k, v] of Object.entries(params)) url.searchParams.set(k, v);
  const res = await fetch(url);
  if (!res.ok) throw new Error('API ' + action + ' failed: ' + res.status);
  return res.json();
}

async function apiPost(action, body = {}) {
  const fd = new FormData();
  fd.append('action', action);
  const csrfInput = document.querySelector('input[name="csrf_token"]');
  if (csrfInput) fd.append('csrf_token', csrfInput.value);
  for (const [k, v] of Object.entries(body)) fd.append(k, v);
  const res = await fetch('api.php', { method: 'POST', body: fd });
  if (!res.ok) throw new Error('API POST ' + action + ' failed: ' + res.status);
  return res.json();
}

// --- Monitor -----------------------------------------------------------------
async function loadMonitor(diva) {
  const params = diva ? { diva } : {};
  try {
    const data = await apiFetch('monitor', params);
    renderMonitor(data);
  } catch (e) {
    const container = document.getElementById('monitor');
    if (container) container.textContent = 'Keine Abfahrtsdaten verfugbar.';
    console.error(e);
  }
}

function renderMonitor(data) {
  const container = document.getElementById('monitor');
  if (!container) return;
  container.replaceChildren();

  const { trains, update_at, api_ping, ...stations } = data;

  for (const [, s] of Object.entries(stations)) {
    if (typeof s !== 'object') continue;

    const card   = document.createElement('div');
    card.className = 'card mb-2';

    const header = document.createElement('div');
    header.className = 'card-header';
    header.textContent = s.station_name;
    card.appendChild(header);

    const table = document.createElement('table');
    table.className = 'table table-sm departure-table mb-0';

    let i = 0;
    while (('train_' + i) in s) {
      const tr    = table.insertRow();
      const tdLine = tr.insertCell();
      tdLine.textContent = s['train_' + i];
      tdLine.className = 'fw-semibold';
      const tdDep = tr.insertCell();
      tdDep.textContent = s['departure_' + i];
      i++;
    }
    card.appendChild(table);

    const footer = document.createElement('div');
    footer.className = 'card-footer text-muted small';
    footer.textContent = 'Aktualisiert: ' + update_at;
    card.appendChild(footer);

    container.appendChild(card);
  }
}

function startMonitorTimer() {
  if (monitorTimer) clearInterval(monitorTimer);
  monitorTimer = setInterval(() => loadMonitor(), 20000);
}

// --- Favorites ---------------------------------------------------------------
async function loadFavorites() {
  try {
    const favs = await apiFetch('favorites');
    renderFavorites(favs);
  } catch (e) {
    console.error('Could not load favorites:', e);
  }
}

function renderFavorites(favs) {
  const container = document.getElementById('buttons');
  if (!container) return;
  container.replaceChildren();
  for (const fav of favs) {
    const btn = document.createElement('button');
    btn.type = 'button';
    btn.className = 'btn ' + fav.bclass + ' d-block w-100 mb-1';
    btn.id = 'btnFav-' + fav.id;
    btn.dataset.diva = fav.diva;
    btn.dataset.sort = fav.sort;
    btn.textContent = fav.title;
    btn.addEventListener('click', () => {
      loadMonitor(fav.diva);
      startMonitorTimer();
    });
    btn.addEventListener('dblclick', () => {
      location.href = 'editFavorite.php?favID=' + fav.id;
    });
    container.appendChild(btn);
  }
}

// --- Stations ----------------------------------------------------------------
async function loadStationsByDistance(position) {
  const { latitude, longitude } = position.coords;
  const spinner = document.getElementById('stationSortDist');
  if (spinner) spinner.classList.remove('d-none');
  try {
    await apiPost('position_save', { lat: latitude, lon: longitude });
    const stations = await apiFetch('stations', { lat: latitude, lon: longitude });
    stationCache = stations;
    renderStationList(stations, latitude, longitude);
  } catch (e) {
    console.error(e);
    loadStationsAlpha();
  } finally {
    if (spinner) spinner.classList.add('d-none');
  }
}

async function loadStationsAlpha() {
  const spinner = document.getElementById('stationSortAlpha');
  if (spinner) spinner.classList.remove('d-none');
  try {
    const stations = await apiFetch('stations');
    stationCache = stations;
    renderStationList(stations);
  } catch (e) {
    console.error('Could not load stations:', e);
  } finally {
    if (spinner) spinner.classList.add('d-none');
  }
}

function renderStationList(stations, originLat, originLon) {
  const list = document.getElementById('stationList');
  if (!list) return;
  list.replaceChildren();

  for (const s of stations) {
    const li = document.createElement('li');
    const p  = document.createElement('p');
    p.className = 'mb-1';

    if (originLat !== undefined && s.distance !== undefined) {
      const dist = s.distance >= 1000
        ? (s.distance / 1000).toFixed(2) + ' km'
        : s.distance + ' m';

      const mapsUrl = 'https://www.google.com/maps/dir/?api=1'
        + '&origin='      + encodeURIComponent(originLat + ',' + originLon)
        + '&destination=' + encodeURIComponent(s.lat + ',' + s.lon)
        + '&travelmode=walking';

      const a = document.createElement('a');
      a.href   = mapsUrl;
      a.target = 'wlmonitor';
      const icon = document.createElement('i');
      icon.className = 'fas fa-location-arrow me-2';
      a.appendChild(icon);
      p.appendChild(a);

      const span = document.createElement('span');
      span.textContent = s.station + ' (' + dist + ')';
      span.style.cursor = 'pointer';
      span.addEventListener('click', () => { loadMonitor(s.diva); startMonitorTimer(); });
      p.appendChild(span);
    } else {
      p.textContent = s.station;
      p.style.cursor = 'pointer';
      p.addEventListener('click', () => { loadMonitor(s.diva); startMonitorTimer(); });
    }

    li.appendChild(p);
    list.appendChild(li);
  }
}

// --- Station sort radios + search --------------------------------------------
function wireStationSort() {
  const radios      = document.querySelectorAll('input[name="stationSort"]');
  const searchInput = document.getElementById('s');

  radios.forEach(radio => {
    radio.addEventListener('change', () => {
      if (radio.value === 'dist') {
        if (searchInput) searchInput.classList.add('d-none');
        navigator.geolocation.getCurrentPosition(
          loadStationsByDistance,
          positionError,
          { timeout: 8000 }
        );
      } else if (radio.value === 'alpha') {
        if (searchInput) searchInput.classList.add('d-none');
        loadStationsAlpha();
      } else if (radio.value === 'search') {
        if (searchInput) {
          searchInput.classList.remove('d-none');
          searchInput.focus();
        }
        if (stationCache.length === 0) loadStationsAlpha();
      }
    });
  });

  if (searchInput) {
    searchInput.addEventListener('input', () => {
      const q        = searchInput.value.toLowerCase();
      const filtered = stationCache.filter(s => s.station.toLowerCase().includes(q));
      renderStationList(filtered);
    });
  }
}

function positionError(error) {
  console.warn('Geolocation error (' + error.code + '): ' + error.message);
  loadStationsAlpha();
}

// --- Theme -------------------------------------------------------------------
function applyTheme() {
  const saved = getCookie('theme');
  if (saved === 'dark' || saved === 'light') {
    document.documentElement.dataset.theme = saved;
  }
  // 'auto' or empty: CSS media query handles it
}

function wireThemeToggle() {
  document.querySelectorAll('input[name="themePreference"]').forEach(radio => {
    radio.addEventListener('change', () => {
      if (radio.value === 'auto') {
        delete document.documentElement.dataset.theme;
      } else {
        document.documentElement.dataset.theme = radio.value;
      }
      setCookie('theme', radio.value, 365);
    });
  });
}

// --- Scroll to top -----------------------------------------------------------
function wireScrollButton() {
  const btn = document.getElementById('topBtn');
  if (!btn) return;
  window.addEventListener('scroll', () => {
    btn.style.display = document.documentElement.scrollTop > 20 ? 'block' : 'none';
  });
  btn.addEventListener('click', () => { document.documentElement.scrollTop = 0; });
}

// --- Alerts ------------------------------------------------------------------
export function sendAlert(message, type) {
  type = type || 'info';
  const container = document.getElementById('alerts');
  if (!container) return;
  const div = document.createElement('div');
  div.className = 'alert alert-' + type + ' alert-dismissible fade show';
  div.textContent = message;
  const closeBtn = document.createElement('button');
  closeBtn.type = 'button';
  closeBtn.className = 'btn-close';
  closeBtn.dataset.bsDismiss = 'alert';
  div.appendChild(closeBtn);
  container.appendChild(div);
  setTimeout(() => div.remove(), 6000);
}

// --- Cookies (theme + sId only) ----------------------------------------------
function getCookie(name) {
  for (const part of decodeURIComponent(document.cookie).split(';')) {
    const [k, v] = part.trim().split('=');
    if (k === name) return v || '';
  }
  return '';
}

function setCookie(name, value, days) {
  const d = new Date();
  d.setTime(d.getTime() + days * 24 * 60 * 60 * 1000);
  document.cookie = name + '=' + value + ';expires=' + d.toUTCString() + ';path=/;SameSite=Strict';
}
