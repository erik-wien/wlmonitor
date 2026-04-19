---
id: TASK-2
title: 'Apps menu in header: Jardyx prod links + "Test" dropdown for local .test hosts'
status: Done
assignee: []
created_date: '2026-04-18 10:04'
updated_date: '2026-04-19 05:46'
labels:
  - ui
  - navigation
  - cross-repo
  - chrome-library
dependencies: []
references:
  - 'inc/html_header.php:78'
  - '/Users/erikr/Git/chrome/src/Header.php:39'
  - '/Users/erikr/Git/chrome/src/Header.php:98'
  - /Users/erikr/Git/CLAUDE.md
  - ~/.claude/rules/ui-design-rules.md#12
priority: medium
---

## Description

<!-- SECTION:DESCRIPTION:BEGIN -->
## Context

wlmonitor's top bar is rendered by the shared `\Erikr\Chrome\Header::render()` (see `inc/html_header.php:78`). The Chrome library accepts an `appMenu` option — an array of `['href', 'label', 'type']` items rendered as flat `<a>` links inside `<nav class="header-nav">` (see `/Users/erikr/Git/chrome/src/Header.php:98-111`). **wlmonitor currently passes no `appMenu`**, so no sibling-app navigation exists today.

The request: add an "apps menu" to wlmonitor's header with:

1. **Top-level links** — sibling Jardyx-ecosystem apps on their production hosts:
   - WL Monitor → `https://wlmonitor.jardyx.com`
   - Energie   → `https://energie.jardyx.com`
   - Chat      → `https://chat.jardyx.com`
   - Zeit      → `https://zeit.jardyx.com`  *(Zeiterfassung, labeled "Zeit")*
2. **"Test" dropdown** — local-dev variants:
   - WL Monitor → `http://wlmonitor.test`
   - Energie   → `http://energie.test`
   - Chat      → `http://chat.test`
   - Zeit      → `http://zeit.test`

## Decisions (locked in from user Q&A 2026-04-18)

- **Production URLs** confirmed: `wlmonitor.jardyx.com`, `energie.jardyx.com`, `chat.jardyx.com`, `zeit.jardyx.com`.
- **Test dropdown visibility:** local dev only — gate on `APP_ENV === 'local'` (defined in `inc/initialize.php`, line 26). Hidden on `akadbrain` (TEST) and `world4you` (PROD).
- **No active-item highlighting.** Do not set `.active` on the current app; do not compare hostnames.
- **Rollout scope: wlmonitor only.** The Chrome library change is done here, but no other app's `html_header.php` is modified in this task. Rolling out to energie/chat/zeit/suche is a separate future task.
- **Mobile hamburger is out of scope** — handled by a separate `mcp`-layer task.
- **App set:** the four named apps only (no `suche`).
- **Label for Zeiterfassung:** shown as **"Zeit"** in both menus (shorter label, matches the `zeit.*` hostname).

## Complication: Chrome library doesn't support nested dropdowns today

`Header.php` renders `appMenu` items as flat `<a>` — no child-items array, no dropdown CSS, no toggle JS. The shared Chrome library must be extended.

### Implementation approach — extend Chrome (locked in)

1. **Extend `appMenu` item schema** with an optional `children` array:
   ```php
   [
       'label'    => 'Test',
       'children' => [
           ['href' => 'http://wlmonitor.test', 'label' => 'WL Monitor'],
           ['href' => 'http://energie.test',   'label' => 'Energie'],
           ['href' => 'http://chat.test',      'label' => 'Chat'],
           ['href' => 'http://zeit.test',      'label' => 'Zeit'],
       ],
   ]
   ```
2. **Render branch:** when an item has `children`, emit a `<div class="nav-menu">` containing `<button class="nav-btn">Label ▾</button>` + `<div class="nav-dropdown">…</div>`, analogous to the existing `.user-menu` / `.user-dropdown` at `Header.php:116-161`. Reuse the toggle JS already in the behaviour script at the end of `Header.php` — extend the existing selector so the same click-outside/Escape logic covers both dropdowns.
3. **Add shared CSS** (`~/Git/css_library/components.css`) for `.nav-menu` + `.nav-btn` + `.nav-dropdown`, mirroring `.user-dropdown` visuals with `--color-surface`, `--color-border`, `--shadow` tokens. Both light and dark themes.
4. **wlmonitor wiring** in `inc/html_header.php` — pass `appMenu` to `Header::render(...)` and conditionally include the Test dropdown:
   ```php
   $appMenu = [
       ['href' => 'https://wlmonitor.jardyx.com', 'label' => 'WL Monitor'],
       ['href' => 'https://energie.jardyx.com',   'label' => 'Energie'],
       ['href' => 'https://chat.jardyx.com',      'label' => 'Chat'],
       ['href' => 'https://zeit.jardyx.com',      'label' => 'Zeit'],
   ];
   if (defined('APP_ENV') && APP_ENV === 'local') {
       $appMenu[] = ['label' => 'Test', 'children' => [
           ['href' => 'http://wlmonitor.test', 'label' => 'WL Monitor'],
           ['href' => 'http://energie.test',   'label' => 'Energie'],
           ['href' => 'http://chat.test',      'label' => 'Chat'],
           ['href' => 'http://zeit.test',      'label' => 'Zeit'],
       ]];
   }
   ```

## Files likely touched

- `/Users/erikr/Git/chrome/src/Header.php` — schema extension + render branch for `children`; extend dropdown-toggle behaviour script.
- `/Users/erikr/Git/css_library/components.css` — `.nav-menu` / `.nav-btn` / `.nav-dropdown` styles.
- `/Users/erikr/Git/wlmonitor/inc/html_header.php` — pass `appMenu` to `Header::render()`; gate Test dropdown on `APP_ENV === 'local'`.

## Out of scope

- Other apps' headers (energie, zeiterfassung, chat, suche) — not modified in this task.
- Hamburger / mobile nav collapse — tracked separately in `mcp` tasks.
- Active-app highlighting — explicitly declined by user.
- User-dropdown changes, brand-cluster changes, footer changes.
<!-- SECTION:DESCRIPTION:END -->

- [ ] #8 Mobile (≤767px) layout does not overlap the user area; behaviour matches the decision from open question 5
- [ ] #9 Active-app highlighting behaves per the decision from open question 3
<!-- AC:END -->

## Acceptance Criteria
<!-- AC:BEGIN -->
- [x] #1 Chrome library Header::render() appMenu schema accepts a 'children' array for nested dropdowns; usage-comment docs updated
- [x] #2 Dropdown renders as a button + panel mirroring the .user-dropdown pattern, with click-outside and Escape to close, keyboard-accessible
- [x] #3 wlmonitor header shows four top-level links: WL Monitor → https://wlmonitor.jardyx.com, Energie → https://energie.jardyx.com, Chat → https://chat.jardyx.com, Zeit → https://zeit.jardyx.com
- [x] #4 wlmonitor header includes a 'Test' dropdown with WL Monitor → http://wlmonitor.test, Energie → http://energie.test, Chat → http://chat.test, Zeit → http://zeit.test; dropdown is rendered only when APP_ENV === 'local' (hidden on akadbrain and world4you)
- [x] #5 No active-item highlighting on any nav entry (no .active class, no current-app detection)
- [x] #6 No regressions for other apps consuming Header::render() without an appMenu (energie, zeiterfassung, chat, suche)
- [x] #7 Light and dark themes both render the dropdown correctly with shared CSS tokens; focus rings visible
- [x] #8 Changes are wired into wlmonitor only; other ecosystem apps are not modified in this task
- [x] #9 Mobile behaviour is explicitly out of scope (tracked separately in mcp hamburger tasks); nav is allowed to wrap or overflow on ≤767px without causing horizontal scroll
<!-- AC:END -->
