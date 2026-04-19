---
id: TASK-6
title: Drag-and-drop reorder for favorites sidebar
status: Done
assignee: []
created_date: '2026-04-18 19:26'
updated_date: '2026-04-19 04:50'
labels:
  - ui
  - mobile
  - favorites
dependencies:
  - TASK-1
references:
  - 'web/js/wl-monitor.js:449'
  - 'web/api.php:233'
priority: medium
---

## Description

<!-- SECTION:DESCRIPTION:BEGIN -->
Allow users to reorder their favourite buttons directly in the `#buttons` sidebar on `index.php` by drag and drop. The sort order must be persisted via the existing `api.php?action=favorites_sort` endpoint (POST + CSRF, JSON body `[{id, sort}, …]`).

## Context

`wl_favorites.sort` already stores the order and `favorites_sort` already writes it — there is just no drag UI yet. `renderFavorites()` in `wl-monitor.js:449` builds the button list; that is the natural place to wire sortability.

## Constraints

- No build step — vanilla JS only. Use **SortableJS** (CDN or local copy in `web/js/vendor/`) — it handles both mouse and touch drag natively, which is essential for mobile.
- Must work on iOS Safari (touch events, no `pointer-events` tricks that break on WebKit).
- Sort persists automatically on `end` event (after drop), not via a separate save button.
- On desktop (≥768px), the sidebar is single-column; on mobile (<768px) it is a 2-column grid (TASK-1). SortableJS handles both layouts.
- Visual feedback: ghost element while dragging, placeholder showing drop target. Use SortableJS defaults — no custom animations needed.
- A subtle drag handle (≡ icon, left-aligned, low opacity) signals draggability without cluttering the button. On mobile the whole button is the drag target (handles are too small to tap reliably).

## API call on sort end

```js
apiPost('favorites_sort', { order: [{id, sort}, …] });
```

where `sort` is the 0-based index after the drop. Silent on success; show a toast on error.

## Out of scope

- Sorting on the edit-favorite page.
- Keyboard-accessible reorder (nice to have, separate task).
- Animation beyond SortableJS defaults.
<!-- SECTION:DESCRIPTION:END -->

## Acceptance Criteria
<!-- AC:BEGIN -->
- [ ] #1 Favorites can be dragged to a new position in the sidebar and snap into place on drop
- [ ] #2 Sort order is persisted via favorites_sort API immediately after drop (no save button)
- [ ] #3 Works with mouse on desktop and touch on iOS Safari (iPhone)
- [ ] #4 2-column mobile grid layout (TASK-1) sorts correctly — items move between columns
- [ ] #5 Drag handle (≡) visible on desktop buttons; whole button draggable on mobile
- [ ] #6 Single-favorite edge case: no drag handle shown, nothing breaks
- [ ] #7 Failed API call shows a toast error and does not update the DOM order
<!-- AC:END -->

## Implementation Plan

<!-- SECTION:PLAN:BEGIN -->
## Implementation Plan — TASK-6: Drag-and-drop favorites sidebar

**Key design decisions:**
- SortableJS 1.15.2, downloaded locally to `web/js/vendor/Sortable.min.js` (no CDN dependency)
- Desktop (≥768px): `handle: '.drag-handle'` so button click still fires normally; `≡` drag-handle span visible at low opacity
- Mobile (<768px): no handle, `delay: 300 / delayOnTouchOnly: true` so short taps still fire click events
- Sort persistence: raw JSON POST with `X-CSRF-TOKEN` header (csrf_verify() accepts this; favorites_sort reads php://input)
- Sortable instance stored in module-level `sortableInstance`; destroyed + recreated on each `renderFavorites()` call; not created when 0 or 1 favorites

---

### File map

| File | Action |
|---|---|
| `web/js/vendor/Sortable.min.js` | Create — download SortableJS 1.15.2 |
| `web/index.php` | Modify — load Sortable.min.js before app module |
| `web/js/wl-monitor.js` | Modify — data-fav-id on buttons, drag handle span, initSortable(), persistFavSort() |
| `web/css/app/wl-monitor.css` | Modify — drag handle styles + .sortable-ghost |

---

### Task 1: Download SortableJS + wire into index.php

**Files:**
- Create: `web/js/vendor/Sortable.min.js`
- Modify: `web/index.php`

- [ ] Create `web/js/vendor/` directory and download SortableJS 1.15.2:

```bash
mkdir -p /Users/erikr/Git/wlmonitor/web/js/vendor
curl -L "https://cdn.jsdelivr.net/npm/sortablejs@1.15.2/Sortable.min.js" \
  -o /Users/erikr/Git/wlmonitor/web/js/vendor/Sortable.min.js
```

Verify file is non-empty and contains `Sortable`.

- [ ] In `web/index.php`, add the script tag **before** `<script type="module" src="js/wl-monitor.js">`:

```php
<script src="js/vendor/Sortable.min.js" nonce="<?= $_cspNonce ?>"></script>
```

- [ ] Commit:

```bash
git add web/js/vendor/Sortable.min.js web/index.php
git commit -m "feat(sortable): add SortableJS 1.15.2 vendor file"
```

---

### Task 2: JS changes — drag handle + Sortable init + sort persistence

**Files:**
- Modify: `web/js/wl-monitor.js`

- [ ] Add module-level variable near the top (after other module-level vars like `currentMonitor`):

```js
let sortableInstance   = null;
```

- [ ] Add `persistFavSort` function after `renderFavorites` (before the `// --- Stations` comment):

```js
async function persistFavSort() {
  const order = [...document.querySelectorAll('#buttons .btn[data-fav-id]')]
    .map((btn, i) => ({ id: parseInt(btn.dataset.favId, 10), sort: i }));
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
```

- [ ] Add `initSortable` function after `persistFavSort`:

```js
function initSortable() {
  if (sortableInstance) { sortableInstance.destroy(); sortableInstance = null; }
  const container = document.getElementById('buttons');
  if (!container || !window.wlConfig?.loggedIn) return;
  if (container.querySelectorAll('.btn[data-fav-id]').length < 2) return;
  const mobile = window.matchMedia('(max-width: 767px)').matches;
  const opts = { animation: 150, ghostClass: 'sortable-ghost', onEnd: persistFavSort };
  if (mobile) {
    opts.delay = 300;
    opts.delayOnTouchOnly = true;
  } else {
    opts.handle = '.drag-handle';
  }
  sortableInstance = new Sortable(container, opts);
}
```

- [ ] In `renderFavorites`, make these changes to each button:

**Add `data-fav-id`** — on the line `btn.dataset.diva = fav.diva;`, add immediately after:
```js
    btn.dataset.favId = fav.id;
```

**Add drag handle** — after `btn.appendChild(titleSpan);`, add:
```js
    if (window.wlConfig?.loggedIn) {
      const handle = document.createElement('span');
      handle.className = 'drag-handle';
      handle.setAttribute('aria-hidden', 'true');
      handle.textContent = '≡';
      btn.insertBefore(handle, btn.firstChild);
    }
```

- [ ] At the end of `renderFavorites`, call `initSortable()`:

Find the closing `}` of `renderFavorites` (after the `for` loop and `container.appendChild(btn)`). The current last line before `}` is `container.appendChild(btn);` inside the loop. After the loop closes, add:

```js
  if (window.wlConfig?.loggedIn) initSortable();
```

- [ ] Run tests and confirm no regressions:

```bash
cd /Users/erikr/Git/wlmonitor && ./vendor/bin/phpunit tests/ --no-coverage 2>&1 | tail -5
```

- [ ] Commit:

```bash
git add web/js/wl-monitor.js
git commit -m "feat(js): drag-and-drop favorites reorder via SortableJS"
```

---

### Task 3: CSS — drag handle styles

**Files:**
- Modify: `web/css/app/wl-monitor.css`

- [ ] Add drag handle and ghost styles after the existing `/* ── Favorites grid */` section:

```css
/* ── Drag handle ──────────────────────────────────────────────────────────── */
.drag-handle {
  display: inline-block;
  opacity: 0.35;
  cursor: grab;
  margin-right: 0.4rem;
  user-select: none;
  font-size: 0.9em;
  line-height: 1;
}

.drag-handle:active {
  cursor: grabbing;
  opacity: 0.6;
}

.sortable-ghost {
  opacity: 0.35;
}

@media (max-width: 767px) {
  .drag-handle {
    display: none;
  }
  #buttons .btn {
    cursor: grab;
  }
}
```

- [ ] Commit:

```bash
git add web/css/app/wl-monitor.css
git commit -m "feat(css): drag handle and ghost styles for favorites reorder"
```
<!-- SECTION:PLAN:END -->
