---
id: TASK-1
title: 'Responsive favorite buttons: ≥2 per row on narrow screens'
status: Done
assignee: []
created_date: '2026-04-18 09:13'
updated_date: '2026-04-18 21:41'
labels:
  - ui
  - responsive
  - mobile
dependencies: []
references:
  - 'web/js/wl-monitor.js:449'
  - web/css/app/wl-monitor.css
  - 'web/index.php:38'
priority: medium
---

## Description

<!-- SECTION:DESCRIPTION:BEGIN -->
## Context

Favorite buttons are rendered by `renderFavorites()` in `web/js/wl-monitor.js:449` into `#buttons` (inside the `.col-md-4` sidebar in `web/index.php:38`). Each button is created with classes `'btn ' + fav.bclass + ' d-block w-100 mb-1 text-start'` — i.e. always full-width and stacked vertically, regardless of viewport.

On desktop the sidebar is a narrow right-hand column, so a single column of full-width buttons makes sense. On screens below the `md` breakpoint (`<768px`, i.e. iPhones), `col-md-4` expands to the full viewport width and the same `w-100` buttons each occupy the entire row — one big button per row, lots of wasted horizontal space.

## Goal

On narrow viewports, show **at least 2 favorite buttons per row**, growing to more columns as width allows. On ≥`md` (desktop sidebar), keep the current single-column stack — the sidebar is too narrow there for a grid.

## Proposed approach

Switch `#buttons` from a stack of `d-block w-100` buttons to a responsive grid:

- Turn `#buttons` itself into a CSS grid (or flex-wrap) container with a responsive `grid-template-columns` based on `minmax(...)` — e.g. `repeat(auto-fill, minmax(10rem, 1fr))` — so it naturally produces ≥2 columns on iPhone-width, more on wider screens, and falls back to 1 column only when a single tile can't fit.
- On ≥`md`, override to a single-column layout so the desktop sidebar stays as-is.
- Drop `d-block w-100` from the per-button class string in `renderFavorites()` (the grid handles sizing); keep `text-start` and `mb-*` replaced by `gap` on the grid container.
- Keep the button's existing color class (`fav.bclass`) and inner layout (title + optional lines chip) intact.

## Acceptance Criteria
<!-- AC:BEGIN -->
- [ ] #1 #1 On <768px viewports, the favorites sidebar renders at least 2 buttons per row when ≥2 favorites exist
- [ ] #2 #2 On ≥768px the desktop sidebar keeps its current single-column layout
- [ ] #3 #3 Button labels truncate cleanly at small widths (no overflow, no horizontal scroll)
- [ ] #4 #4 Touch target height stays ≥44px on mobile
- [ ] #5 #5 Light and dark themes both render correctly with no color regressions
- [ ] #6 #6 Layout works gracefully with 1 favorite (single tile) and many favorites (multi-row wrap)

## Files likely touched

- `web/js/wl-monitor.js` — `renderFavorites()` at line 449: remove `d-block w-100` from the per-button class list.
- `web/css/app/wl-monitor.css` — add a new `#buttons { display: grid; ... }` block with a mobile-first responsive template and a `@media (min-width: 768px)` override for the desktop single-column layout.

## Out of scope

- Other buttons on the page (modal buttons, `#topBtn`, etc.).
- Changing favorite color/filter UX.
<!-- SECTION:DESCRIPTION:END -->

## Implementation Plan

<!-- SECTION:PLAN:BEGIN -->
## Implementation plan

### 1. `web/css/app/wl-monitor.css` — add grid container rules

```css
/* Favorites grid: ≥2 columns on mobile, single column on desktop sidebar */
#buttons {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(10rem, 1fr));
  gap: 0.375rem;
}
@media (min-width: 768px) {
  #buttons {
    grid-template-columns: 1fr;
  }
}
```

`auto-fill` + `minmax(10rem, 1fr)` naturally produces 2+ columns on iPhone-width (~390px) and falls back to 1 column only when the container is narrower than 10rem. The `@media` override locks to single-column on ≥md where the container is the narrow sidebar.

### 2. `web/js/wl-monitor.js:456` — strip layout classes from per-button class string

Change:
```js
btn.className = 'btn ' + fav.bclass + ' d-block w-100 mb-1 text-start';
```
To:
```js
btn.className = 'btn ' + fav.bclass + ' text-start';
```

`d-block`, `w-100` are replaced by the grid; `mb-1` is replaced by `gap`. `text-start` stays (aligns text in the button).

### Verify

- Open dev site at `http://localhost/wlmonitor.test` in browser
- Resize to <768px (or DevTools mobile emulation) — confirm ≥2 columns when ≥2 favorites exist
- Resize to ≥768px — confirm single-column sidebar restored
- Check both light and dark themes
- Confirm touch target height and label truncation are acceptable
<!-- SECTION:PLAN:END -->

<!-- AC:END -->

<!-- AC:END -->
