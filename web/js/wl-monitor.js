/**
 * wl-monitor.js -- ES module
 * Vanilla JS, fetch() based. No jQuery.
 */

// --- State -------------------------------------------------------------------
let stationCache  = [];       // full list for current sort mode
let currentSort   = 'alpha';  // 'alpha' | 'dist'
let stationOrigin = null;     // { lat, lon } when sort=dist
let monitorTimer  = null;

// --- Init --------------------------------------------------------------------
document.addEventListener('DOMContentLoaded', () => {
  applyTheme();
  initDropdowns();
  initModals();
  initAlerts();
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
  wireStationDropdown();
  loadStationsAlpha();
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
    if (typeof s !== 'object' || !Array.isArray(s.lines)) continue;

    const card = document.createElement('div');
    card.className = 'card mb-2';

    const header = document.createElement('div');
    header.className = 'card-header py-1';
    header.textContent = s.station_name;
    card.appendChild(header);

    const table = document.createElement('table');
    table.className = 'table table-sm departure-table mb-0';

    // Group lines by name, preserving order of first appearance.
    // Within each group: H (outgoing) first, R (incoming) second.
    const groups = new Map();
    for (const line of s.lines) {
      if (!groups.has(line.name)) groups.set(line.name, { H: null, R: null });
      const g = groups.get(line.name);
      if (line.direction === 'R') { g.R = line; } else { g.H = line; }
    }

    for (const [, g] of groups) {
      const outgoing = g.H;
      const incoming = g.R;
      const tbody = document.createElement('tbody');

      if (outgoing) {
        const tr = tbody.insertRow();
        const tdBadge = tr.insertCell();
        tdBadge.className = 'badge-cell';
        tdBadge.rowSpan = incoming ? 2 : 1;
        tdBadge.appendChild(createLineBadge(outgoing));
        appendDepartureColumns(tr, outgoing);
        tbody.appendChild(tr);
      }

      if (incoming) {
        const tr = tbody.insertRow();
        if (!outgoing) {
          const tdBadge = tr.insertCell();
          tdBadge.className = 'badge-cell';
          tdBadge.appendChild(createLineBadge(incoming));
        }
        appendDepartureColumns(tr, incoming);
        tbody.appendChild(tr);
      }

      table.appendChild(tbody);
    }

    card.appendChild(table);

    const footer = document.createElement('div');
    footer.className = 'card-footer text-muted small py-1';
    footer.textContent = 'Aktualisiert: ' + update_at;
    card.appendChild(footer);

    container.appendChild(card);
  }
}

function appendDepartureColumns(tr, line) {
  const tdPlatform = tr.insertCell();
  tdPlatform.className = 'platform-cell';
  tdPlatform.textContent = line.platform;

  const tdTowards = tr.insertCell();
  tdTowards.className = 'towards-cell';
  tdTowards.textContent = line.towards;

  const tdTimes = tr.insertCell();
  tdTimes.className = 'times-cell';
  tdTimes.textContent = line.departures;
}

function createLineBadge(line) {
  const badge = document.createElement('span');
  badge.className = 'line-badge';

  if (line.type === 'ptTramWLB') {
    badge.classList.add('pt-tram-wlb');
    const img = document.createElement('img');
    img.src = 'img/Logo_Wiener_Lokalbahn.svg';
    img.alt = 'WLB';
    img.className = 'wlb-logo';
    badge.appendChild(img);
    return badge;
  }

  badge.textContent = line.name;

  switch (line.type) {
    case 'ptTram':
      badge.classList.add('pt-tram');
      break;
    case 'ptBusRegion':
      badge.classList.add('pt-bus-region');
      break;
    case 'ptMetro':
      badge.classList.add('pt-metro', line.name);
      break;
    case 'ptTrain':
      badge.classList.add('pt-train');
      break;
    case 'ptTrainS':
      badge.classList.add('pt-train-s');
      break;
    case 'ptBusCity':
      badge.classList.add('pt-bus-city');
      break;
    case 'ptBusNight':
      badge.classList.add('pt-bus-night');
      break;
    default:
      badge.classList.add('pt-default');
  }

  return badge;
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

    const editBtn = document.createElement('a');
    editBtn.href = 'editFavorite.php?favID=' + fav.id;
    editBtn.className = 'btn btn-sm btn-outline-secondary ms-1';
    editBtn.title = 'Bearbeiten';
    editBtn.appendChild(makeSvgIcon('save'));

    const wrap = document.createElement('div');
    wrap.className = 'd-flex mb-1';
    btn.className = 'btn ' + fav.bclass + ' flex-grow-1';
    wrap.appendChild(btn);
    wrap.appendChild(editBtn);
    container.appendChild(wrap);
  }
}

// --- Stations ----------------------------------------------------------------
async function loadStationsByDistance(position) {
  const { latitude, longitude } = position.coords;
  stationOrigin = { lat: latitude, lon: longitude };
  try {
    if (window.wlConfig?.loggedIn) {
      await apiPost('position_save', { lat: latitude, lon: longitude });
    }
    const stations = await apiFetch('stations', { lat: latitude, lon: longitude });
    stationCache = stations;
    renderStationList(stations);
  } catch (e) {
    console.error(e);
    currentSort = 'alpha';
    document.getElementById('sortAlpha')?.click();
  }
}

async function loadStationsAlpha() {
  stationOrigin = null;
  try {
    const stations = await apiFetch('stations');
    stationCache = stations;
    renderStationList(stations);
  } catch (e) {
    console.error('Could not load stations:', e);
  }
}

function renderStationList(stations) {
  const list = document.getElementById('stationList');
  if (!list) return;

  const q = (document.getElementById('s')?.value ?? '').toLowerCase();
  const visible = q ? stations.filter(s => s.station.toLowerCase().includes(q)) : stations;

  list.replaceChildren();

  for (const s of visible) {
    const li = document.createElement('li');
    const p  = document.createElement('p');
    p.className = 'mb-1';

    if (currentSort === 'dist' && stationOrigin && s.distance !== undefined) {
      const dist = s.distance >= 1000
        ? (s.distance / 1000).toFixed(2) + ' km'
        : s.distance + ' m';

      const mapsUrl = 'https://www.google.com/maps/dir/?api=1'
        + '&origin='      + encodeURIComponent(stationOrigin.lat + ',' + stationOrigin.lon)
        + '&destination=' + encodeURIComponent(s.lat + ',' + s.lon)
        + '&travelmode=walking';

      const a = document.createElement('a');
      a.href   = mapsUrl;
      a.target = 'wlmonitor';
      a.appendChild(makeSvgIcon('map-marker', 'me-2'));
      p.appendChild(a);

      const span = document.createElement('span');
      span.textContent = s.station + ' (' + dist + ')';
      span.style.cursor = 'pointer';
      span.addEventListener('click', () => { loadMonitor(s.diva); startMonitorTimer(); closeStationDropdown(); });
      p.appendChild(span);
    } else {
      p.textContent = s.station;
      p.style.cursor = 'pointer';
      p.addEventListener('click', () => { loadMonitor(s.diva); startMonitorTimer(); closeStationDropdown(); });
    }

    li.appendChild(p);
    list.appendChild(li);
  }
}

// --- Station sort radios + search --------------------------------------------
function wireStationSort() {
  document.querySelectorAll('input[name="stationSort"]').forEach(radio => {
    radio.addEventListener('change', () => {
      currentSort = radio.value;
      if (radio.value === 'dist') {
        navigator.geolocation.getCurrentPosition(
          loadStationsByDistance,
          positionError,
          { timeout: 8000 }
        );
      } else {
        loadStationsAlpha();
      }
    });
  });

  document.getElementById('s')?.addEventListener('input', () => {
    openStationDropdown();
    renderStationList(stationCache);
  });
}

// --- Station dropdown show/hide ----------------------------------------------
function openStationDropdown() {
  const dd = document.getElementById('stationDropdown');
  if (dd) dd.style.display = '';
}

function closeStationDropdown() {
  const dd = document.getElementById('stationDropdown');
  if (dd) dd.style.display = 'none';
}

function wireStationDropdown() {
  document.getElementById('stationListToggle')?.addEventListener('click', () => {
    const dd = document.getElementById('stationDropdown');
    if (!dd) return;
    if (dd.style.display === 'none') {
      openStationDropdown();
    } else {
      closeStationDropdown();
    }
  });

  document.addEventListener('click', e => {
    const wrap = document.getElementById('stationSearchWrap');
    if (wrap && !wrap.contains(e.target)) closeStationDropdown();
  });
}

function positionError(error) {
  console.warn('Geolocation error (' + error.code + '): ' + error.message);
  currentSort = 'alpha';
  const alphaRadio = document.getElementById('sortAlpha');
  if (alphaRadio) alphaRadio.checked = true;
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

async function setTheme(t) {
  if (t === 'dark' || t === 'light') {
    document.documentElement.dataset.theme = t;
  } else {
    delete document.documentElement.dataset.theme;
  }
  setCookie('theme', t, 365);
  document.querySelectorAll('[data-theme-btn]').forEach(btn => {
    btn.classList.toggle('active', btn.dataset.themeBtn === t);
  });
  if (window.wlConfig?.loggedIn) {
    try { await apiPost('theme_save', { theme: t }); } catch (e) { console.error(e); }
  }
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
  closeBtn.dataset.dismissAlert = '';
  div.appendChild(closeBtn);
  container.appendChild(div);
  setTimeout(() => div.remove(), 6000);
}

// --- Cookies (theme + sId only) ----------------------------------------------
function getCookie(name) {
  for (const part of decodeURIComponent(document.cookie).split(';')) {
    const trimmed = part.trim();
    const eqIdx = trimmed.indexOf('=');
    if (eqIdx === -1) continue;
    const k = trimmed.slice(0, eqIdx);
    const v = trimmed.slice(eqIdx + 1);
    if (k === name) return v || '';
  }
  return '';
}

function setCookie(name, value, days) {
  const d = new Date();
  d.setTime(d.getTime() + days * 24 * 60 * 60 * 1000);
  document.cookie = name + '=' + value + ';expires=' + d.toUTCString() + ';path=/;SameSite=Strict';
}

// --- SVG icon helper (mirrors PHP icon()) ------------------------------------
function makeSvgIcon(id, cls) {
  const ns = 'http://www.w3.org/2000/svg';
  const svg = document.createElementNS(ns, 'svg');
  svg.setAttribute('class', 'icon' + (cls ? ' ' + cls : ''));
  svg.setAttribute('aria-hidden', 'true');
  svg.setAttribute('focusable', 'false');
  const use = document.createElementNS(ns, 'use');
  use.setAttribute('href', 'css/icons.svg#icon-' + id);
  svg.appendChild(use);
  return svg;
}

// --- Dropdowns ---------------------------------------------------------------
function initDropdowns() {
  document.querySelectorAll('[data-dropdown-toggle]').forEach(toggle => {
    const menu = toggle.closest('.dropdown')?.querySelector('.dropdown-menu');
    if (!menu) return;
    toggle.addEventListener('click', e => {
      e.stopPropagation();
      const open = menu.classList.contains('show');
      closeAllDropdowns();
      if (!open) menu.classList.add('show');
    });
  });
  document.addEventListener('click', closeAllDropdowns);
}

function closeAllDropdowns() {
  document.querySelectorAll('.dropdown-menu.show').forEach(m => m.classList.remove('show'));
}

// --- Modals ------------------------------------------------------------------
window.openModal = function(id) {
  const modal = document.getElementById(id);
  if (modal) modal.classList.add('show');
};

window.closeModal = function(id) {
  const modal = document.getElementById(id);
  if (modal) modal.classList.remove('show');
};

function initModals() {
  document.querySelectorAll('[data-modal-open]').forEach(btn => {
    btn.addEventListener('click', () => openModal(btn.dataset.modalOpen));
  });
  document.querySelectorAll('[data-modal-close]').forEach(btn => {
    btn.addEventListener('click', () => {
      const modal = btn.closest('.modal');
      if (modal) modal.classList.remove('show');
    });
  });
  document.querySelectorAll('.modal').forEach(modal => {
    modal.addEventListener('click', e => {
      if (e.target === modal) modal.classList.remove('show');
    });
  });
}

// --- Alert dismiss -----------------------------------------------------------
function initAlerts() {
  document.addEventListener('click', e => {
    if (e.target.matches('[data-dismiss-alert]')) {
      const alert = e.target.closest('.alert');
      if (alert) alert.remove();
    }
  });
}
