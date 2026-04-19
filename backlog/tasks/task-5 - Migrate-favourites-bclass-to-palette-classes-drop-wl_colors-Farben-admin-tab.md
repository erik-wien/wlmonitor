---
id: TASK-5
title: >-
  Migrate favourites bclass to palette classes; drop wl_colors + Farben admin
  tab
status: Done
assignee: []
created_date: '2026-04-18 11:55'
updated_date: '2026-04-19 05:45'
labels: []
dependencies: []
priority: medium
ordinal: 1000
---

## Description

<!-- SECTION:DESCRIPTION:BEGIN -->
Migrate wlmonitor favourites off the semantic button classes (`btn-outline-primary`, `btn-outline-success`, …) onto the new palette classes (`btn-outline-color-red`, `btn-outline-color-green`, …) introduced by `css_library/TASK-5`. Remove the `wl_colors` table and the Farben admin tab — once the picker is palette-bound, there is nothing admin-editable and the table becomes dead weight.

Users currently pick a "Favoriten-Farbe" from a dropdown whose entries are semantic Bootstrap classes relabelled with German colour names in `wl_colors` (admin-editable). That indirection was always wrong: a user-visible accent should reference concrete colours, not UI semantics. This task makes that right.

## Blocked on

`css_library/TASK-5` — palette tier + `.btn-color-*` family must ship first.

## Data migration

Column: `wl_favorites.bclass` (user's chosen outline-class string). Rewrite every existing value to the palette equivalent that preserves the currently-rendered colour:

| Current `bclass` | New `bclass` | Preserved visual |
|---|---|---|
| `btn-outline-default`   | `btn-outline-color-neutral`    | transparent / body-text |
| `btn-outline-primary`   | `btn-outline-color-red`        | Jardyx red (was --color-primary = --jardyx-red) |
| `btn-outline-success`   | `btn-outline-color-green`      | Jardyx green |
| `btn-outline-info`      | `btn-outline-color-blue`       | Jardyx blue |
| `btn-outline-warning`   | `btn-outline-color-yellow`     | Jardyx yellow (mislabelled "Orange" in seed) |
| `btn-outline-danger`    | `btn-outline-color-red-dark`   | `#9a0014` dark-red |
| `btn-outline-secondary` | `btn-outline-color-grey-dark`  | Jardyx dark-grey |
| `btn-outline-dark`      | `btn-outline-color-grey-dark`  | collapses onto dark-grey |

Migration file `migrations/NNN_wl_favorites_palette_migration.sql`:

```sql
UPDATE wl_favorites SET bclass = CASE bclass
    WHEN 'btn-outline-default'   THEN 'btn-outline-color-neutral'
    WHEN 'btn-outline-primary'   THEN 'btn-outline-color-red'
    WHEN 'btn-outline-success'   THEN 'btn-outline-color-green'
    WHEN 'btn-outline-info'      THEN 'btn-outline-color-blue'
    WHEN 'btn-outline-warning'   THEN 'btn-outline-color-yellow'
    WHEN 'btn-outline-danger'    THEN 'btn-outline-color-red-dark'
    WHEN 'btn-outline-secondary' THEN 'btn-outline-color-grey-dark'
    WHEN 'btn-outline-dark'      THEN 'btn-outline-color-grey-dark'
    ELSE bclass
END
WHERE bclass IN (
    'btn-outline-default','btn-outline-primary','btn-outline-success',
    'btn-outline-info','btn-outline-warning','btn-outline-danger',
    'btn-outline-secondary','btn-outline-dark'
);

DROP TABLE IF EXISTS wl_colors;
```

Idempotent via the `WHERE bclass IN (...)` guard.

## Code changes

- **`inc/colors.php`** — delete `wl_colors_list()`, `wl_colors_bclass_labels()`, `wl_color_edit()`. Replace with a single function returning a hardcoded palette list (this is the **one file a dev edits** to change the picker contents):

  ```php
  /**
   * Palette colours offered to users in the favourite-colour picker.
   * The class must exist in the shared css_library btn-color-* family.
   * Edit this list when adding / removing palette options.
   */
  function wl_palette_list(): array {
      return [
          ['class' => 'btn-outline-color-red',        'label' => 'Rot'],
          ['class' => 'btn-outline-color-blue',       'label' => 'Blau'],
          ['class' => 'btn-outline-color-green',      'label' => 'Grün'],
          ['class' => 'btn-outline-color-yellow',     'label' => 'Gelb'],
          ['class' => 'btn-outline-color-orange',     'label' => 'Orange'],
          ['class' => 'btn-outline-color-purple',     'label' => 'Lila'],
          ['class' => 'btn-outline-color-turquoise',  'label' => 'Türkis'],
          ['class' => 'btn-outline-color-grey-dark',  'label' => 'Grau'],
          ['class' => 'btn-outline-color-grey-light', 'label' => 'Hellgrau'],
          ['class' => 'btn-outline-color-neutral',    'label' => 'Neutral'],
      ];
  }
  ```

- **`web/editFavorite.php:103-106`** — drop `wl_colors_list($con)` loop; read from `wl_palette_list()` instead.
- **`web/admin.php`** — remove the Farben tab (`#tab-colors`, `#panel-colors`, `#colorModal`, the inline JS block at lines ~487+ that wires `btn-edit-color`). Three-tab shell stays per UI rule §15.6 — if tab 1 (App-Parameter) has no other content, show a placeholder card rather than collapsing to two tabs.
- **`web/api.php`** — remove `admin_color_edit` action branch.
- **`web/css/app/wl-monitor.css`** — remove `.color-table` rules at lines 288-295+ (dead after the admin UI drops).

## Infra

- `~/Git/mcp/scripts/grant-db-users.sql` — remove the `wlmonitor` user's privileges on `wl_colors` (per auth-rules §8).
- `docs/architecture.md` — drop the `wl_colors` row from the tables list.

## Deploy sequence

1. `css_library/TASK-5` merged + deployed into wlmonitor's symlinked `web/css/shared/`.
2. Confirm `.btn-outline-color-*` classes resolve correctly by inspecting a test page before running the migration — a bad symlink or stale `composer update` would leave every favourite rendered without colour.
3. Apply `migrations/NNN_wl_favorites_palette_migration.sql` on dev.
4. Verify every pre-existing favourite still renders in its previous colour.
5. Deploy code (admin UI removal + inc/colors.php shrinkage).
6. Apply migration on prod, deploy, re-verify.

## Out of scope

- Adding new palette options to the picker beyond the ones listed.
- Changing how `wl_favorites.bclass` is stored (e.g. moving to a `palette_key` enum) — the class-string form is fine and is consistent with simplechat and suche's preference storage.
- Any user-facing feature for admins to manage the palette (explicitly rejected — palette lives in code).

## Acceptance

See the acceptance-criteria list on the task.
<!-- SECTION:DESCRIPTION:END -->

## Acceptance Criteria
<!-- AC:BEGIN -->
- [x] #1 wl_favorites.bclass values rewritten to btn-outline-color-* equivalents per the mapping table, preserving each favourite's rendered colour
- [x] #2 Migration file is idempotent via WHERE bclass IN (old values) guard; drops wl_colors table in the same file
- [x] #3 inc/colors.php reduces to a single wl_palette_list() function returning a hardcoded array
- [x] #4 wl_colors_list(), wl_colors_bclass_labels(), wl_color_edit() removed
- [x] #5 web/editFavorite.php dropdown renders from wl_palette_list() with the 10 palette colours and correct German labels
- [x] #6 Farben tab + colorModal + inline JS removed from web/admin.php; three-tab shell preserved with placeholder if tab 1 empty
- [x] #7 admin_color_edit action removed from web/api.php
- [x] #8 Dead .color-table CSS removed from web/css/app/wl-monitor.css
- [x] #9 ~/Git/mcp/scripts/grant-db-users.sql drops the wlmonitor wl_colors privileges
- [x] #10 docs/architecture.md no longer references wl_colors
- [x] #11 Manual verification: every pre-existing favourite renders in its original colour after migration
<!-- AC:END -->
