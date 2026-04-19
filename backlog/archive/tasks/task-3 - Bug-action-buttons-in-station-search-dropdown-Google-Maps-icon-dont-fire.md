---
id: TASK-3
title: 'Bug: action buttons in station-search dropdown (Google Maps icon) don''t fire'
status: To Do
assignee: []
created_date: '2026-04-18 10:17'
labels:
  - bug
  - ui
  - search
  - needs-repro
  - needs-clarification
dependencies: []
references:
  - 'web/js/wl-monitor.js:513'
  - 'web/js/wl-monitor.js:532'
  - 'web/js/wl-monitor.js:604'
  - 'web/js/wl-monitor.js:696'
  - 'inc/html_header.php:24'
  - 'web/css/app/wl-monitor.css:74'
priority: high
---

## Description

<!-- SECTION:DESCRIPTION:BEGIN -->
## Reported

User reports that "action buttons in the search area (e.g. Google) don't fire" — clicking the Google Maps action in the station-search dropdown does not open the maps URL.

## What I found

The station-search dropdown lives in `inc/html_header.php:24-48` (`#stationSearchWrap` → `#stationDropdown` → `#stationList`). Rows are rendered by `renderStationList()` in `web/js/wl-monitor.js:513-557`.

In **distance-sort** mode (`currentSort === 'dist'`), each row contains:

1. An `<a>` whose only child is an SVG icon (`makeSvgIcon('map-marker', 'me-2')`), with `href = https://www.google.com/maps/dir/?api=1&origin=…&destination=…&travelmode=walking` and `target="wlmonitor"` (`web/js/wl-monitor.js:532-541`). **No click handler, no preventDefault.**
2. A `<span>` with the station name + distance that calls `loadMonitor(s.diva)` on click (line 543-547).

In **alpha-sort** mode (lines 549-552), there is no Google icon — only the clickable `<p>` that loads the monitor.

So the "Google" action button is literally a map-marker SVG inside an `<a href="google.com/maps/…" target="wlmonitor">`, and clicking it is supposed to open Google Maps directions in a named window.

## Hypotheses — needs reproduction to confirm

Ordered by plausibility:

1. **SVG icon is the click target, and the anchor has no padding/hit area around it.** `makeSvgIcon()` returns an inline `<svg>` with a `<use href="css/icons.svg#icon-map-marker">` (lines 696-706). The anchor contains only the SVG; if shared `components.css` sets `.icon { pointer-events: none }` or similar, clicks pass through the SVG but still hit the `<a>` because the anchor wraps it. Should work — but worth verifying.
2. **Sprite lookup failure.** If `css/icons.svg#icon-map-marker` doesn't resolve (sprite not loaded, id renamed), the SVG renders 0×0, the anchor collapses to 0×0, and there's nothing clickable. The `\Erikr\Chrome\Header::render()` call in `inc/html_header.php:84` already inlines the sprite from `web/css/icons.svg` — so this should be OK, but a deploy-state drift could cause a missing icon id.
3. **Outside-click dropdown-close handler.** `wireStationDropdown()` adds a `document` click listener (line 604-607) that closes the dropdown when click target is outside `#stationSearchWrap`. Clicking the anchor is inside the wrap, so it does **not** close — and it does **not** preventDefault, so navigation should proceed. Probably not the bug, but worth verifying e.target during a click.
4. **CSP or browser popup block.** The anchor opens `google.com` in a named window `target="wlmonitor"`. CSP `connect-src`/`navigate-to` directives on the page (set by `auth_bootstrap()`) could theoretically interfere — check `Content-Security-Policy` header on `/index.php`.
5. **Other "action buttons" in the search area that I didn't find in code.** The user wrote "e.g. Google" (plural), but I can only find the one map-marker anchor. Possible I'm missing a second UI location — ask the user.

## Reproduction checklist for whoever picks this up

- [ ] Open wlmonitor on the dev URL, switch station sort to "Nähe" (distance), grant geolocation, inspect a row in `#stationList`.
- [ ] DevTools → Console: click the Google-icon anchor and verify a `click` event fires on the `<a>`.
- [ ] DevTools → Network: is a navigation attempted? Is it blocked by a CSP report?
- [ ] Inspect computed styles on the SVG icon and the anchor: does the anchor have a non-zero bounding box?
- [ ] Remove `target="wlmonitor"` temporarily and see if the link works (rules out popup-blocker + named-window edge cases).
- [ ] Confirm whether the span next to the icon (station name) still fires `loadMonitor` — isolates whether the whole row is dead or only the anchor.

## Open question for user

- Are there **other** action buttons in the search area besides the Google-Maps icon? The wording "e.g. Google" implies several, but the current code has only one per row in distance-sort mode. A screenshot of the search area showing the broken buttons would help scope the fix.

## Likely fix directions (depending on root cause)

- If the SVG is the click target issue: give the anchor a display: inline-flex + padding via CSS so the whole icon-circle is clickable, and ensure `.icon { pointer-events: none }` in shared CSS so the anchor (not the SVG) receives the click.
- If the `<span>` fires `loadMonitor` and also closes the dropdown first: **it doesn't** in the distance branch — the anchor has no sibling span click handler. But verify nothing blocks the anchor's default action (no parent with `preventDefault`, no touchstart handler).
- If popup-blocker / named-window: either remove `target="wlmonitor"` or add `rel="noopener"` and `target="_blank"` — the `wlmonitor` named target is an old convention that has no value here.

## Files involved

- `web/js/wl-monitor.js:513-557` — `renderStationList()` renders the rows and the Google anchor.
- `web/js/wl-monitor.js:696-706` — `makeSvgIcon()` creates the `<svg><use>` icon.
- `web/css/app/wl-monitor.css:74-104` — `.station-dropdown` + `#stationList` styles.
- `inc/html_header.php:24-48` — dropdown wrapper + input markup.
- Shared `components.css` / `layout.css` — inspect for `.icon { pointer-events: none }` and anchor hit-area rules.
<!-- SECTION:DESCRIPTION:END -->

## Acceptance Criteria
<!-- AC:BEGIN -->
- [ ] #1 Reproduce the reported bug on the dev environment and identify the exact root cause (not just a plausible hypothesis)
- [ ] #2 Google-Maps action anchor in the station-search dropdown opens the directions URL reliably when clicked, on desktop and on touch devices
- [ ] #3 Whatever other action buttons the user refers to with 'e.g. Google' are also identified and fixed in the same change (pending user clarification)
- [ ] #4 Fix does not regress alpha-sort behavior (clicking a station still loads its monitor and closes the dropdown)
- [ ] #5 Anchor has an adequate touch hit area (≥24×24px) so the icon is reliably tappable on mobile
<!-- AC:END -->
