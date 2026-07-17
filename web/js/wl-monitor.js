/**
 * wl-monitor.js -- ES module
 * Vanilla JS, fetch() based. No jQuery.
 */

import { confirmDialog } from '../css/shared/js/dialog.js';
import { apiCall, apiForm } from '../css/shared/js/api-call.js';

// --- State -------------------------------------------------------------------
let stationCache       = [];       // full list for current sort mode
let currentSort        = 'alpha';  // 'alpha' | 'dist'
let monitorTimer       = null;
let currentMonitor     = { diva: null, favId: null, fav: null }; // active monitor context
let addModalDiva       = null;     // DIVA override for add-favourite modal (single-steig "+")
let currentMonitorLines = [];      // [{diva, line, platform, direction}] — collected on last render
let sortableInstance   = null;

// --- Init --------------------------------------------------------------------
document.addEventListener('DOMContentLoaded', async () => {
  applyTheme();
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
// Thin wrappers around the shared apiCall()/apiForm() hull (see
// css/shared/js/api-call.js) — kept so the many call sites below don't need
// to change. Errors are ApiError instances (status/detail/kind) with a
// German-language e.message that already includes the server's concrete
// error text where available.
async function apiFetch(action, params = {}) {
  const url = new URL('api.php', location.href);
  url.searchParams.set('action', action);
  for (const [k, v] of Object.entries(params)) url.searchParams.set(k, v);
  return apiCall(url, { method: 'GET' });
}

async function apiPost(action, body = {}) {
  const fd = new FormData();
  fd.append('action', action);
  const csrfInput = document.querySelector('input[name="csrf_token"]');
  if (csrfInput) fd.append('csrf_token', csrfInput.value);
  for (const [k, v] of Object.entries(body)) fd.append(k, v);
  return apiForm('api.php', fd);
}

// --- Monitor -----------------------------------------------------------------
let monitorHasData = false; // true once a departure board has been rendered

async function loadMonitor(diva, fav = null) {
  // Local candidate — NOT committed to currentMonitor until the fetch+render
  // succeeds. Committing eagerly would desync the visible board/toolbar
  // (still the old target on failure) from currentMonitor (already the new
  // target), which e.g. makes deleteFavoriteFromMonitor() delete the wrong
  // favourite. See Review TASK-11.
  const candidate = { diva: diva || null, favId: fav ? fav.id : null, fav };
  const params = diva ? { diva } : {};
  const stationChanged = diva !== currentMonitor.diva;
  try {
    const data = await apiFetch('monitor', params);
    currentMonitor = candidate;
    renderMonitor(data);
    monitorHasData = true;
    updateMonitorToolbar();
    if (stationChanged) {
      const el = document.getElementById('monitor');
      el.classList.remove('board-enter');
      void el.offsetWidth;             // Reflow: Animation neu starten
      el.classList.add('board-enter');
    }
    syncActiveFavChip(fav?.id);
  } catch (e) {
    const container = document.getElementById('monitor');
    if (container) {
      if (monitorHasData) {
        // Keep the last successfully rendered board AND currentMonitor on
        // refresh/switch errors (§21) — show a dismissible-free inline notice
        // instead of wiping it, naming the target that failed so the user
        // knows the switch didn't happen.
        let notice = document.getElementById('monitorRefreshNotice');
        if (!notice) {
          notice = document.createElement('div');
          notice.id = 'monitorRefreshNotice';
          notice.className = 'app-alert app-alert-warning mb-2';
          notice.setAttribute('role', 'alert');
          container.prepend(notice);
        }
        const targetLabel = candidate.fav?.title ?? candidate.diva ?? 'Standardanzeige';
        notice.textContent = 'Aktualisierung von "' + targetLabel + '" fehlgeschlagen (' + e.message + ') — zeige letzten Stand.';
      } else {
        container.textContent = 'Keine Abfahrtsdaten verfügbar (' + e.message + ').';
      }
    }
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
    editBtn.className = 'btn btn-sm';
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
    addBtn.className = 'btn btn-sm btn-outline-color-red';
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
  const bclass = document.getElementById('addFavColor')?.value || 'btn-outline-color-neutral';
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
  if (!await confirmDialog('Favorit "' + (currentMonitor.fav?.title ?? '') + '" wirklich löschen?', {
    titel: 'Favorit löschen',
    okLabel: 'Löschen',
    gefahr: 'commit',
  })) return;
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
    card.className = 'app-card mb-2';

    const header = document.createElement('div');
    header.className = 'app-card-header py-1 d-flex align-items-center';

    const nameSpan = document.createElement('span');
    nameSpan.className = 'flex-grow-1 text-truncate';
    nameSpan.textContent = s.station_name;
    header.appendChild(nameSpan);

    if (update_at) {
      // Nur der Dot kennzeichnet „live" — der Zeitstempel steht einmal unten
      // („Aktualisiert: …") und hier als Tooltip/aria-label (TASK-18).
      const live = document.createElement('span');
      live.className = 'board-live ms-2';
      live.title = 'Live · aktualisiert ' + update_at;
      live.setAttribute('aria-label', 'Live, aktualisiert ' + update_at);
      header.appendChild(live);
    }

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

  if (Array.isArray(data.alerts) && data.alerts.length) {
    const wrap = document.createElement('div');
    wrap.id = 'monitorAlerts';
    for (const info of data.alerts) {
      const box = document.createElement('div');
      box.className = 'app-alert app-alert-warning';
      box.setAttribute('role', 'alert');
      // .app-alert ist flex (Library-Layout für Icon+Text) — ohne Block-Wrapper
      // würden die <p>-Absätze aus descriptionHTML zu Flex-Spalten nebeneinander
      // (TASK-17). Inhalt daher in die vorgesehene .app-alert-body packen.
      const body = document.createElement('div');
      body.className = 'app-alert-body';
      if (info.title) {
        const strong = document.createElement('strong');
        strong.textContent = info.title;
        body.appendChild(strong);
      }
      if (info.descriptionHTML) {
        if (info.title) body.appendChild(document.createElement('br'));
        const frag = parseTrustedHtml(info.descriptionHTML);
        // WL-Feed streut Leer-Absätze (<p><br></p>) — raus damit (TASK-18).
        for (const p of frag.querySelectorAll('p, div')) {
          if (!p.textContent.trim() && !p.querySelector('img, table')) p.remove();
        }
        // Zweites WL-Format: Textzeilen mit <br>-Trennern; Leerzeilen kommen als
        // <br>·" "·<br> bzw. <br><br>. Whitespace-Textknoten entfernen, dann
        // br-Folgen auf eines reduzieren (TASK-18 Nachtrag).
        for (const n of [...frag.childNodes]) {
          if (n.nodeType === 3 && !n.textContent.trim()) n.remove();
        }
        let prevBr = false;
        for (const n of [...frag.childNodes]) {
          if (n.nodeType === 1 && n.tagName === 'BR') {
            if (prevBr) { n.remove(); continue; }
            prevBr = true;
          } else {
            prevBr = false;
          }
        }
        body.appendChild(frag);
      } else if (info.description) {
        if (info.title) body.appendChild(document.createElement('br'));
        body.appendChild(document.createTextNode(info.description));
      }
      box.appendChild(body);
      wrap.appendChild(box);
    }
    container.appendChild(wrap);
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
  tdTowards.appendChild(document.createTextNode(line.towards));

  const tdTimes = tr.insertCell();
  tdTimes.className = 'times-cell';
  if (line.realtime_supported === false) {
    tdTimes.classList.add('times-scheduled');
    tdTimes.title = 'Fahrplanzeit (keine Echtzeit)';
  }
  const deps = Array.isArray(line.departures) ? line.departures : [];
  const jammedTimes = [];
  const deviations  = []; // {t, label}
  deps.forEach((d, i) => {
    if (i > 0) {
      const sep = document.createElement('span');
      sep.className = 'dep-sep';
      sep.textContent = ' · ';
      tdTimes.appendChild(sep);
    }
    const span = document.createElement('span');
    span.className = 'dep ' + (i === 0 ? 'dep-next' : 'dep-follow') + (d.bf ? ' dep-barrierfree' : '');
    const mins = parseInt(d.t, 10);
    if (i === 0 && !Number.isNaN(mins) && mins <= 1) span.classList.add('dep-immi');
    if (d.bf) span.title = 'Barrierefreies Fahrzeug';
    span.textContent = d.t;
    tdTimes.appendChild(span);
    if (d.jam) {
      tdTimes.appendChild(createAlertMarker());
      jammedTimes.push(d.t);
    }
    if (d.name_override || d.towards_override) {
      const parts = [];
      if (d.name_override) parts.push(d.name_override);
      if (d.towards_override) parts.push('→ ' + d.towards_override);
      deviations.push({ t: d.t, label: parts.join(' ') });
    }
  });

  if (jammedTimes.length) {
    const note = document.createElement('div');
    note.className = 'departure-note';
    note.textContent = 'Verzögerung bei: ' + jammedTimes.join(', ');
    tdTowards.appendChild(note);
  }
  for (const dev of deviations) {
    const note = document.createElement('div');
    note.className = 'departure-note';
    note.textContent = dev.t + ': ' + dev.label;
    tdTowards.appendChild(note);
  }
}

// Parses an HTML fragment from the Wiener Linien API into DOM nodes.
// We trust WL as the source; descriptionHTML is operator-curated disruption text.
function parseTrustedHtml(html) {
  const doc = new DOMParser().parseFromString('<div>' + html + '</div>', 'text/html');
  const frag = document.createDocumentFragment();
  const root = doc.body.firstChild;
  if (root) while (root.firstChild) frag.appendChild(root.firstChild);
  return frag;
}

function createAlertMarker() {
  const span = document.createElement('span');
  span.className = 'line-alert';
  span.setAttribute('role', 'img');
  span.setAttribute('aria-label', 'Störung auf dieser Linie');
  span.title = 'Störung auf dieser Linie';
  span.textContent = '⚠️';
  return span;
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
// Markiert den Chip des aktiven Favoriten (favId) und entfernt die Markierung
// von allen anderen. favId == null/undefined → kein Chip aktiv.
function syncActiveFavChip(favId) {
  document.querySelectorAll('#buttons .fav-chip').forEach(b =>
    b.classList.toggle('fav-active', Number(b.dataset.favId) === (favId ?? -1)));
}

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
    btn.className = 'btn fav-chip ' + fav.bclass + ' text-start';
    btn.id = 'btnFav-' + fav.id;
    btn.dataset.diva = fav.diva;
    btn.dataset.favId = fav.id;

    const titleSpan = document.createElement('span');
    titleSpan.className = 'd-block';
    titleSpan.textContent = fav.title;
    btn.appendChild(titleSpan);

    if (window.wlConfig?.loggedIn) {
      const handle = document.createElement('span');
      handle.className = 'drag-handle';
      handle.setAttribute('aria-hidden', 'true');
      handle.textContent = '≡';
      btn.insertBefore(handle, btn.firstChild);
    }

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
  // Ruhe-Farben der Library-Klasse einfrieren (TASK-19): Safari lässt :hover
  // nach einem Tap „kleben" — background:currentColor griffe dann das
  // Hover-Weiß ab (weißer Chip, Text weiß-auf-weiß). borderColor ist der
  // bright-Ton der Klasse und entspricht dem Library-Hover-Füllton.
  for (const btn of container.querySelectorAll('.fav-chip')) {
    const cs = getComputedStyle(btn);
    btn.style.setProperty('--chip-fill', cs.borderColor);
    btn.style.setProperty('--chip-color', cs.color);
  }
  syncActiveFavChip(currentMonitor.fav?.id);
  if (window.wlConfig?.loggedIn) initSortable();
}

async function persistFavSort() {
  const order = [...document.querySelectorAll('#buttons .btn[data-fav-id]')]
    .map((btn, i) => ({ id: parseInt(btn.dataset.favId, 10), sort: i }));
  if (order.length < 2) return;
  const csrfToken = document.querySelector('input[name="csrf_token"]')?.value ?? '';
  try {
    const res = await fetch('api.php?action=favorites_sort', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrfToken },
      body: JSON.stringify(order),
    });
    if (!res.ok) throw new Error('favorites_sort HTTP ' + res.status);
  } catch (e) {
    sendAlert('Reihenfolge konnte nicht gespeichert werden.', 'danger');
    console.error('favorites_sort failed', e);
  }
}

function initSortable() {
  if (sortableInstance) { sortableInstance.destroy(); sortableInstance = null; }
  const container = document.getElementById('buttons');
  if (!container || !window.wlConfig?.loggedIn) return;
  if (container.querySelectorAll('.btn[data-fav-id]').length < 2) return;
  const mobile = window.matchMedia('(max-width: 767px)').matches;
  const reducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
  const opts = { animation: reducedMotion ? 0 : 150, ghostClass: 'sortable-ghost', onUpdate: persistFavSort };
  if (mobile) {
    opts.delay = 300;
    opts.delayOnTouchOnly = true;
  } else {
    opts.handle = '.drag-handle';
  }
  sortableInstance = new Sortable(container, opts);
}

// Re-init Sortable when viewport crosses the mobile/desktop breakpoint so the
// handle vs. delay config stays correct after window resize.
window.matchMedia('(max-width: 767px)').addEventListener('change', () => {
  if (window.wlConfig?.loggedIn) initSortable();
});

// --- Stations ----------------------------------------------------------------
async function loadStationsByDistance(position) {
  const { latitude, longitude } = position.coords;
  try {
    if (window.wlConfig?.loggedIn) {
      await apiPost('position_save', { lat: latitude, lon: longitude });
    }
    let stations = await apiFetch('stations', { lat: latitude, lon: longitude });
    // Nähe-Liste kompakt halten (TASK-20): nur Stationen ≤ 500 m — kürzere
    // Liste rendert schneller. Fallback: mindestens die 8 nächsten, damit die
    // Liste in dünn erschlossenen Gegenden nicht leer ist.
    const near = stations.filter(s => s.distance !== undefined && s.distance <= 500);
    stations = near.length >= 8 ? near : stations.slice(0, 8);
    stationCache = stations;
    renderStationList(stations);
  } catch (e) {
    console.error(e);
    currentSort = 'alpha';
    document.getElementById('sortAlpha')?.click();
  }
}

async function loadStationsAlpha() {
  try {
    const stations = await apiFetch('stations');
    stationCache = stations;
    renderStationList(stations);
  } catch (e) {
    console.error('Could not load stations:', e);
  }
}

/* Liniensignal-Klasse aus dem Liniennamen (Suche liefert nur Namen, keinen Typ).
   Heuristik nach Wiener Konvention: U* Metro, N* Nightline, WLB Lokalbahn,
   Ziffern+Buchstabe Bus (59A), nur Ziffern Tram (62). */
function lineSignalClass(name) {
  const n = name.trim().toUpperCase();
  if (/^U\d$/.test(n)) return 'pt-metro ' + n;
  if (/^N\d+[A-Z]?$/.test(n)) return 'pt-bus-night';
  if (n === 'WLB' || n === 'BB' || n.startsWith('BADNER')) return 'pt-tram-wlb';
  if (/^[A-Z]$/.test(n)) return 'pt-tram';   // Buchstaben-Trams (O, D)
  if (/^\d+[A-Z]$/.test(n)) return 'pt-bus-city';
  if (/^\d+$/.test(n)) return 'pt-tram';
  return 'pt-default';
}

function appendLinePreview(p, s) {
  if (!s.lines) return;
  const wrap = document.createElement('span');
  wrap.className = 'sig-preview';
  s.lines.split(',').slice(0, 6).forEach(raw => {
    const name = raw.trim();
    if (!name) return;
    const upper = name.toUpperCase();
    if (upper === 'WLB' || upper === 'BB') {
      const b = document.createElement('span');
      b.className = 'line-badge sig-mini pt-tram-wlb';
      const img = document.createElement('img');
      img.src = 'img/Logo_Wiener_Lokalbahn.svg';
      img.alt = 'WLB';
      img.className = 'wlb-logo';
      b.appendChild(img);
      wrap.appendChild(b);
      return;
    }
    const b = document.createElement('span');
    b.className = 'line-badge sig-mini ' + lineSignalClass(name);
    b.textContent = name;
    wrap.appendChild(b);
  });
  p.appendChild(wrap);
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

    if (currentSort === 'dist' && s.distance !== undefined) {
      const dist = s.distance >= 1000
        ? (s.distance / 1000).toFixed(2) + ' km'
        : s.distance + ' m';

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

    appendLinePreview(p, s);
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
          // maximumAge: gecachte Position (≤ 2 min) sofort verwenden statt
          // jedes Mal einen frischen GPS-Fix zu erzwingen (Sekunden am Gerät);
          // Stationsdistanzen brauchen keine High-Accuracy (TASK-20).
          { timeout: 8000, maximumAge: 120000, enableHighAccuracy: false }
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

  // pointerdown (capture) statt click: click feuert beim mouseup, das nach einem
  // mousedown innerhalb (z.B. Scrollbar-Drag der Dropdown-Liste) außerhalb enden
  // kann und den Wrap fälschlich als "außerhalb" behandeln würde. pointerdown
  // greift am Gestenstart und bewertet dieselbe Ziel-Prüfung korrekt.
  document.addEventListener('pointerdown', e => {
    const wrap = document.getElementById('stationSearchWrap');
    if (wrap && !wrap.contains(e.target)) {
      closeStationDropdown();
      wrap.classList.remove('open');
    }
  }, true);

  document.getElementById('searchToggle')?.addEventListener('click', () => {
    const hs = document.querySelector('.header-search');
    hs.classList.toggle('open');
    if (hs.classList.contains('open')) document.getElementById('s')?.focus();
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
  div.className = 'app-alert app-alert-' + type + ' alert-dismissible';
  div.setAttribute('role', 'alert');
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

// --- Modals ------------------------------------------------------------------
// Dual mechanism: catalog .app-modal-backdrop shows/hides via the [hidden]
// attribute; legacy .modal (avatar-cropper widget) via the .show class.
function setModal(modal, open) {
  if (!modal) return;
  if (modal.classList.contains('app-modal-backdrop')) modal.hidden = !open;
  else modal.classList.toggle('show', open);
  modal.setAttribute('aria-hidden', open ? 'false' : 'true');
}

window.openModal  = function(id) { setModal(document.getElementById(id), true); };
window.closeModal = function(id) { setModal(document.getElementById(id), false); };

const MODAL_SEL = '.modal, .app-modal-backdrop';

function initModals() {
  document.querySelectorAll('[data-modal-open]').forEach(btn => {
    btn.addEventListener('click', () => openModal(btn.dataset.modalOpen));
  });
  document.querySelectorAll('[data-modal-close]').forEach(btn => {
    btn.addEventListener('click', () => {
      const modal = btn.closest(MODAL_SEL);
      if (modal) setModal(modal, false);
    });
  });
  // Backdrop close binds to pointerdown (gesture start) so a selection drag
  // ending outside the dialog doesn't wrongly close it (UI rule §8).
  document.querySelectorAll(MODAL_SEL).forEach(modal => {
    modal.addEventListener('pointerdown', e => {
      if (e.target === modal) setModal(modal, false);
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
