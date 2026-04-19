---
id: TASK-4
title: Drop wl_colors admin tab; hardcode button-colour labels in inc/colors.php
status: To Do
assignee: []
created_date: '2026-04-18 11:15'
labels: []
dependencies: []
priority: medium
---

## Description

<!-- SECTION:DESCRIPTION:BEGIN -->
The wlmonitor admin has a "Farben" tab (web/admin.php:34-160) that lets an admin rename the display labels stored in `wl_colors`. The class-to-colour mapping is driven by CSS (`~/Git/css_library/theme.css`) — the DB only stores the *user-facing text label* of each Bootstrap button class. Since the theme is hardcoded per project, the admin UI is a config knob that fires once and then never again. Two of the seeded labels are also wrong for the current Jardyx theme (`primary` labelled "Blau" when the CSS renders it as Jardyx red; `default` labelled "Standard" which is ambiguous for a transparent/neutral button).

Drop the table and the admin UI; keep the label data in code, owned by developers.

## What the feature actually does (for context)

- `wl_colors` rows map each Bootstrap class (`primary`, `success`, `info`, …) to a German display label (`farbe`) and its outline/solid CSS class variants.
- `inc/colors.php`:`wl_colors_list()` / `wl_colors_bclass_labels()` read the table.
- `web/editFavorite.php:103-106` builds the favourite-colour dropdown from `wl_colors_list()`.
- `web/admin.php` has the "Farben" tab + `colorModal` + JS wiring to call `api.php?action=admin_color_edit`, which calls `wl_color_edit()`.
- `inc/colors.php`:`wl_color_edit()` updates only the `farbe` column — class columns are fixed.

## Scope of the drop

### Remove

- `web/admin.php` — "Farben" tab (`#tab-colors` button + `#panel-colors` section + `#colorModal` + the inline JS that wires the modal at lines ~487+).
- `web/api.php` — `admin_color_edit` action branch.
- `inc/colors.php` — `wl_color_edit()` function.
- `migrations/003_wl_colors_repopulate.sql` — leave on disk as history; no back-migration needed.
- Any `.color-table` / `.color-*` CSS in `web/css/app/wl-monitor.css:288-295+` that's now dead.

### Add

- `migrations/NNN_drop_wl_colors.sql` — `DROP TABLE IF EXISTS wl_colors;`
- Update `~/Git/mcp/scripts/grant-db-users.sql` — remove the wlmonitor user's privileges on `wl_colors`.

### Keep (but change body)

- `inc/colors.php` — `wl_colors_list()` and `wl_colors_bclass_labels()` stay as functions with the same signatures so call sites don't change. Internals become a single hardcoded array. The `mysqli $con` parameter stays (even if unused) so call sites don't need touching; mark it `@phpstan-ignore` / ignore-unused in the docblock.

## Proposed hardcoded labels (matches current Jardyx theme)

```php
// Class key → [farbe, outline class, full class]
const WL_COLORS = [
    'default'   => ['Neutral',    'btn-outline-default',   'btn-default'],
    'primary'   => ['Rot',        'btn-outline-primary',   'btn-primary'],
    'success'   => ['Grün',       'btn-outline-success',   'btn-success'],
    'info'      => ['Blau',       'btn-outline-info',      'btn-info'],
    'warning'   => ['Gelb',       'btn-outline-warning',   'btn-warning'],
    'danger'    => ['Dunkelrot',  'btn-outline-danger',    'btn-danger'],
    'secondary' => ['Grau',       'btn-outline-secondary', 'btn-secondary'],
    'dark'      => ['Dunkel',     'btn-outline-dark',      'btn-dark'],
];
```

Changes from the current DB seed: `primary` Blau → **Rot**, `info` Türkis → **Blau**, `warning` Orange → **Gelb**, `danger` Rot → **Dunkelrot**, `secondary` Hellgrau → **Grau**, `default` Standard → **Neutral**.

Rationale: these match what CSS actually renders on screen (see `css_library/theme.css:25-37`). Developers edit `WL_COLORS` when the theme changes; no DB round-trip.

## Data safety

- `wl_colors` holds only display labels. No user data. Dropping the table loses nothing that isn't re-created by the hardcoded array.
- `wl_favorites.bclass` (user-selected favourite colour) is unaffected — it stores the `btn-outline-*` class string directly, not an FK into `wl_colors`.

## Audit step before dropping

Before the migration runs, grep for any `wl_colors` reference I may have missed. Known: `web/admin.php`, `web/api.php`, `web/editFavorite.php`, `inc/colors.php`, `docs/architecture.md`, `migrations/003_*`. Update `docs/architecture.md` to remove the wl_colors line.

## Tab shell continuity

After the Farben tab is removed, the admin screen is still a three-tab shell per UI rule §15: tab 1 stays as App-Parameter (remaining wlmonitor-specific settings), tab 2 Benutzerverwaltung (Chrome UsersTab), tab 3 Log. If Farben was the only app-parameter and tab 1 becomes empty, show a placeholder card ("Keine App-Parameter konfigurierbar") rather than collapsing to two tabs — §15.6 requires the three-tab shell.

## Out of scope

- Adding a new mechanism for theme switching at runtime.
- Changing the CSS theme itself.
- Migrating other apps that might have similar dead-config admin panels.

## Files expected to change

- `web/admin.php` — remove Farben tab + modal + JS
- `web/api.php` — remove `admin_color_edit` action
- `inc/colors.php` — replace DB reads with hardcoded WL_COLORS array; drop `wl_color_edit()`
- `web/css/app/wl-monitor.css` — remove `.color-table` / `.color-*` dead rules
- `migrations/NNN_drop_wl_colors.sql` — new file
- `~/Git/mcp/scripts/grant-db-users.sql` — drop wl_colors grants for `wlmonitor` user
- `docs/architecture.md` — remove wl_colors reference
<!-- SECTION:DESCRIPTION:END -->

## Acceptance Criteria
<!-- AC:BEGIN -->
- [ ] #1 Farben tab and colorModal removed from web/admin.php
- [ ] #2 admin_color_edit action removed from web/api.php
- [ ] #3 wl_color_edit() removed from inc/colors.php
- [ ] #4 wl_colors_list() and wl_colors_bclass_labels() keep their signatures but return the hardcoded WL_COLORS array
- [ ] #5 Labels updated to match the current Jardyx theme (primary=Rot, info=Blau, warning=Gelb, danger=Dunkelrot, secondary=Grau, default=Neutral)
- [ ] #6 Migration DROP TABLE wl_colors added under migrations/
- [ ] #7 grant-db-users.sql entry for wl_colors removed from ~/Git/mcp/scripts/
- [ ] #8 Admin screen remains a three-tab shell per UI rule §15; if tab 1 has no other content, placeholder card is shown
- [ ] #9 editFavorite.php dropdown still renders with new labels without code changes
- [ ] #10 Dead .color-table CSS removed from web/css/app/wl-monitor.css
- [ ] #11 docs/architecture.md updated to drop wl_colors reference
<!-- AC:END -->
