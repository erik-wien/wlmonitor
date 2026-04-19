/**
 * wl-monitor.js -- ES module
 * Vanilla JS, fetch() based. No jQuery.
 */

// --- State -------------------------------------------------------------------
let stationCache       = [];       // full list for current sort mode
let currentSort        = 'alpha';  // 'alpha' | 'dist'
let stationOrigin      = null;     // { lat, lon } when sort=dist
let monitorTimer       = null;
let currentMonitor     = { diva: null, favId: null, fav: null }; // active monitor context
let addModalDiva       = null;     // DIVA override for add-favourite modal (single-steig "+")
let currentMonitorLines = [];      // [{diva, line, platform, direction}] — collected on last render

// --- Init --------------------------------------------------------------------
document.addEventListener('DOMContentLoaded', async () => {
  applyTheme();
  initDropdowns();
  initModals();
  initAlerts();
  if (window.wlConfig?.alerts?.length) {
    for (const [type, msg] of window.wlConfig.alerts) sendAlert(msg, type);
  }

  // Load favourites first so we can resolve loadFavId if set
  const favs = await loadFavorites();

  const loadFavId   = window.wlConfig?.loadFavId;
  const targetFav   = loadFavId ? favs.find(f => f.id === loadFavId) : null;
  const initialDiva = window.wlConfig?.initialDiva
    ?? (!window.wlConfig?.loggedIn ? (localStorage.getItem('wl_last_diva') || null) : null);
  if (targetFav) {
    loadMonitor(targetFav.diva, targetFav);
  } else if (initialDiva) {
    loadMonitor(initialDiva);
  } else {
    loadMonitor();
  }

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
async function loadMonitor(diva, fav = null) {
  currentMonitor = { diva: diva || null, favId: fav ? fav.id : null, fav };
  const params = diva ? { diva } : {};
  try {
    const data = await apiFetch('monitor', params);
    renderMonitor(data);
    updateMonitorToolbar();
  } catch (e) {
    const container = document.getElementById('monitor');
    if (container) container.textContent = 'Keine Abfahrtsdaten verfügbar.';
    console.error(e);
  }
}

function saveState(diva, favId = null) {
  if (window.wlConfig?.loggedIn) {
    const body = {};
    if (diva   != null) body.diva  = diva;
    if (favId  !== null) body.favId = favId;
    apiPost('state_save', body).catch(e => console.error('state_save failed', e));
  } else if (diva) {
    localStorage.setItem('wl_last_diva', diva);
  }
}

function updateMonitorToolbar() {
  const bar = document.getElementById('monitorToolbar');
  if (!bar) return;

  bar.replaceChildren();

  if (!currentMonitor.diva) return;

  if (currentMonitor.favId) {
    const editBtn = document.createElement('a');
    editBtn.href = 'editFavorite.php?favID=' + currentMonitor.favId;
    editBtn.className = 'btn btn-sm btn-outline-secondary';
    editBtn.appendChild(makeSvgIcon('pencil', 'me-1'));
    editBtn.appendChild(document.createTextNode('Bearbeiten'));
    bar.appendChild(editBtn);

    const delBtn = document.createElement('button');
    delBtn.type = 'button';
    delBtn.className = 'btn btn-sm btn-outline-danger';
    delBtn.appendChild(makeSvgIcon('trash', 'me-1'));
    delBtn.appendChild(document.createTextNode('Löschen'));
    delBtn.addEventListener('click', deleteFavoriteFromMonitor);
    bar.appendChild(delBtn);
  } else {
    const addBtn = document.createElement('button');
    addBtn.type = 'button';
    addBtn.className = 'btn btn-sm btn-outline-primary';
    addBtn.appendChild(makeSvgIcon('star', 'me-1'));
    addBtn.appendChild(document.createTextNode('Als Favorit speichern'));
    addBtn.addEventListener('click', () => {
      addModalDiva = null;
      populateAddFavLines(null);
      openModal('addFavModal');
    });
    bar.appendChild(addBtn);
  }

  // Wire add-fav submit button
  const addSubmit = document.getElementById('addFavSubmit');
  if (addSubmit) addSubmit.onclick = () => addFavoriteFromMonitor();
}

async function addFavoriteFromMonitor() {
  const diva   = addModalDiva ?? currentMonitor.diva;
  const title  = document.getElementById('addFavTitle')?.value.trim();
  const bclass = document.getElementById('addFavColor')?.value || 'btn-outline-default';
  if (!title || !diva) return;

  // Collect checked lines, grouped by DIVA into new per-station format
  const checked = [...document.querySelectorAll('#addFavLines input[type="checkbox"]:checked')];
  let filterJson = null;
  if (checked.length) {
    const byDiva = {};
    for (const cb of checked) {
      const val = JSON.parse(cb.value);
      if (!byDiva[val.diva]) byDiva[val.diva] = [];
      byDiva[val.diva].push({ line: val.line, platform: val.platform });
    }
    filterJson = JSON.stringify(byDiva);
  }

  try {
    const body = { title, diva, bclass, sort: 0 };
    if (filterJson) body.filter_json = filterJson;
    const res = await apiPost('favorites_add', body);
    closeModal('addFavModal');
    document.getElementById('addFavTitle').value = '';
    addModalDiva = null;
    // Only update toolbar state if the saved DIVA matches the current monitor
    if (diva === currentMonitor.diva) {
      currentMonitor.favId = res.id;
      currentMonitor.fav   = { id: res.id, title, diva, bclass, sort: 0, filter: filterJson ? JSON.parse(filterJson) : null };
      updateMonitorToolbar();
      saveState(diva, res.id);
    }
    await loadFavorites();
    sendAlert('Favorit gespeichert.', 'success');
  } catch (e) {
    sendAlert('Favorit konnte nicht gespeichert werden.', 'danger');
    console.error(e);
  }
}

/**
 * Populate the line-filter checkboxes in the add-favourite modal.
 * Renders a vertical list; disables the save button until at least one is checked.
 *
 * @param {string|null} filterByDiva  If set, show only lines for this DIVA.
 *                                    If null, show all lines from the current monitor.
 */
function populateAddFavLines(filterByDiva) {
  const section   = document.getElementById('addFavLinesSection');
  const container = document.getElementById('addFavLines');
  const saveBtn   = document.getElementById('addFavSubmit');
  if (!section || !container) return;
  container.replaceChildren();

  const lines = filterByDiva
    ? currentMonitorLines.filter(l => l.diva === filterByDiva)
    : currentMonitorLines;

  // Deduplicate by line + platform
  const seen   = new Set();
  const unique = [];
  for (const l of lines) {
    const key = l.line + '|' + l.platform;
    if (!seen.has(key)) { seen.add(key); unique.push(l); }
  }

  if (!unique.length) {
    section.style.display = 'none';
    if (saveBtn) saveBtn.disabled = false;
    return;
  }
  section.style.display = '';
  if (saveBtn) saveBtn.disabled = true; // require at least one selection

  function syncSaveBtn() {
    if (!saveBtn) return;
    saveBtn.disabled = !container.querySelector('input[type="checkbox"]:checked');
  }

  for (const l of unique) {
    const label = document.createElement('label');
    label.className = 'd-flex align-items-center gap-2 px-2 py-1 rounded';
    label.style.cssText = 'cursor:pointer;font-size:.85rem;border:1px solid var(--color-border)';

    const cb = document.createElement('input');
    cb.type = 'checkbox';
    cb.className = 'form-check-input mt-0 flex-shrink-0';
    cb.value = JSON.stringify({ diva: l.diva, line: l.line, platform: l.platform });
    cb.addEventListener('change', syncSaveBtn);

    // Line name — bold, fixed width
    const lineName = document.createElement('span');
    lineName.style.cssText = 'font-weight:700;min-width:2.5em;flex-shrink:0';
    lineName.textContent = l.line;

    // Platform + direction arrow
    const plat = document.createElement('span');
    plat.style.cssText = 'color:var(--color-muted);flex-shrink:0;min-width:1.5em';
    const dirStr = l.direction === 'H' ? '→' : l.direction === 'R' ? '←' : '';
    plat.textContent = l.platform + (dirStr ? '\u00a0' + dirStr : '');

    // Destination — truncated
    const dest = document.createElement('span');
    dest.style.cssText = 'overflow:hidden;text-overflow:ellipsis;white-space:nowrap;color:var(--color-muted)';
    dest.textContent = l.towards ?? '';

    label.append(cb, lineName, plat, dest);
    container.appendChild(label);
  }
}

async function deleteFavoriteFromMonitor() {
  if (!currentMonitor.favId) return;
  if (!confirm('Favorit "' + (currentMonitor.fav?.title ?? '') + '" wirklich löschen?')) return;
  try {
    await apiPost('favorites_delete', { id: currentMonitor.favId });
    currentMonitor.favId = null;
    currentMonitor.fav = null;
    updateMonitorToolbar();
    await loadFavorites();
    sendAlert('Favorit gelöscht.', 'success');
  } catch (e) {
    sendAlert('Favorit konnte nicht gelöscht werden.', 'danger');
    console.error(e);
  }
}

function renderMonitor(data) {
  const container = document.getElementById('monitor');
  if (!container) return;
  container.replaceChildren();
  currentMonitorLines = []; // reset for add-modal checkbox population

  const activeFilter = currentMonitor.fav?.filter ?? null; // {diva: [{line,platform}]} or null
  const { trains, update_at, api_ping, ...stations } = data;

  for (const [, s] of Object.entries(stations)) {
    if (typeof s !== 'object' || !Array.isArray(s.lines)) continue;

    // Collect all lines before filtering (for add-modal checkboxes)
    for (const line of s.lines) {
      currentMonitorLines.push({ diva: s.diva, line: line.name, platform: line.platform, direction: line.direction, towards: line.towards });
    }

    // Apply per-station line filter when active
    const stationFilter = activeFilter?.[s.diva] ?? null;
    const visibleLines = stationFilter
      ? s.lines.filter(l => stationFilter.some(f => f.line === l.name && String(f.platform) === String(l.platform)))
      : s.lines;

    // Skip unfiltered cards with no lines; keep filtered cards even if empty (line may be out of service)
    if (!visibleLines.length && stationFilter === null) continue;

    const card = document.createElement('div');
    card.className = 'card mb-2';

    const header = document.createElement('div');
    header.className = 'card-header py-1 d-flex align-items-center';

    const nameSpan = document.createElement('span');
    nameSpan.className = 'flex-grow-1 text-truncate';
    nameSpan.textContent = s.station_name;
    header.appendChild(nameSpan);

    if (window.wlConfig?.loggedIn && s.diva) {
      const plusBtn = document.createElement('button');
      plusBtn.type = 'button';
      plusBtn.className = 'btn-add-steig btn btn-sm py-0 px-1 ms-1';
      plusBtn.title = 'Als Favorit speichern';
      plusBtn.appendChild(makeSvgIcon('plus'));
      const steigDiva = s.diva;
      plusBtn.addEventListener('click', () => {
        addModalDiva = steigDiva;
        document.getElementById('addFavTitle').value = s.station_name;
        populateAddFavLines(steigDiva);
        openModal('addFavModal');
      });
      header.appendChild(plusBtn);
    }

    card.appendChild(header);

    const table = document.createElement('table');
    table.className = 'table table-sm departure-table mb-0';

    // Group visible lines by name, preserving order of first appearance.
    // Within each group: H (outgoing) first, R (incoming) second.
    const groups = new Map();
    for (const line of visibleLines) {
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

    if (visibleLines.length === 0 && stationFilter !== null) {
      const tbody = document.createElement('tbody');
      const tr = tbody.insertRow();
      const td = tr.insertCell();
      td.colSpan = 4;
      td.className = 'text-center text-muted py-2 small';
      td.textContent = 'Keine aktuellen Abfahrten';
      table.appendChild(tbody);
    }

    card.appendChild(table);
    container.appendChild(card);
  }

  if (update_at) {
    const t = document.createElement('p');
    t.id = 'monitorUpdateTime';
    t.textContent = 'Aktualisiert: ' + update_at;
    container.appendChild(t);
  }

  if (window.wlConfig?.loggedIn) {
    const bar = document.createElement('div');
    bar.id = 'monitorToolbar';
    bar.className = 'd-flex gap-2 mt-2 justify-content-end';
    container.appendChild(bar);
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
  monitorTimer = setInterval(() => loadMonitor(currentMonitor.diva, currentMonitor.fav), 20000);
}

// --- Favorites ---------------------------------------------------------------
async function loadFavorites() {
  try {
    const favs = await apiFetch('favorites');
    renderFavorites(favs);
    return favs;
  } catch (e) {
    console.error('Could not load favorites:', e);
    return [];
  }
}

function renderFavorites(favs) {
  const container = document.getElementById('buttons');
  if (!container) return;
  container.replaceChildren();
  for (const fav of favs) {
    const btn = document.createElement('button');
    btn.type = 'button';
    btn.className = 'btn ' + fav.bclass + ' text-start';
    btn.id = 'btnFav-' + fav.id;
    btn.dataset.diva = fav.diva;

    const titleSpan = document.createElement('span');
    titleSpan.className = 'd-block';
    titleSpan.textContent = fav.title;
    btn.appendChild(titleSpan);

    if (fav.filter && typeof fav.filter === 'object') {
      const allEntries = Object.values(fav.filter).flat();
      if (allEntries.length) {
        const sub = document.createElement('span');
        sub.className = 'd-block fav-filter-sub';
        sub.style.cssText = 'font-size:.7em;opacity:.75;font-weight:400';
        sub.textContent = allEntries.map(f => f.line + '\u00a0' + f.platform).join(' · ');
        btn.appendChild(sub);
      }
    }

    btn.addEventListener('click', () => {
      loadMonitor(fav.diva, fav);
      startMonitorTimer();
      saveState(fav.diva, fav.id);
    });
    container.appendChild(btn);
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
      span.addEventListener('click', () => { loadMonitor(s.diva); startMonitorTimer(); closeStationDropdown(); saveState(s.diva); });
      p.appendChild(span);
    } else {
      p.textContent = s.station;
      p.style.cursor = 'pointer';
      p.addEventListener('click', () => { loadMonitor(s.diva); startMonitorTimer(); closeStationDropdown(); saveState(s.diva); });
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
    try {
      const fd = new FormData();
      fd.append('action', 'change_theme');
      fd.append('theme', t);
      const csrfInput = document.querySelector('input[name="csrf_token"]');
      if (csrfInput) fd.append('csrf_token', csrfInput.value);
      await fetch('preferences.php', { method: 'POST', body: fd });
    } catch (e) { console.error(e); }
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
