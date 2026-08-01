# Bootstrap Removal & Custom CSS — Design Spec
_2026-04-07_

## Goal

Remove Bootstrap, Font Awesome, and Google Fonts from wlmonitor. Replace with a hand-written CSS system split into a portable base layer and an app-specific layer. Consolidate `include/` into `inc/`. All 12 pages covered in one pass.

---

## What Gets Removed

| Removed | Replaced by |
|---|---|
| `bootstrap.min.css` (CDN) | `css/base/reset.css` + `layout.css` + `components.css` |
| Bootstrap JS bundle (CDN, per-page `<script>` in 7 pages) | Custom JS: dropdown toggle in `wl-monitor.js`, modal in `admin.php` |
| Font Awesome 5 (CDN) | `css/icons.svg` SVG sprite |
| Google Fonts (CDN) | System font stack |
| `css/theme.css` | Moved to `css/base/theme.css` |
| `style/wl-monitor.css` | Merged into `css/app/wl-monitor.css` |
| `include/` directory | Merged into `inc/` |

---

## File Structure

```
web/
  css/
    base/
      theme.css        ← CSS variables only (light/dark/auto). Portable.
      reset.css        ← Box-sizing, margin reset, body base. ~30 lines.
      layout.css       ← Grid (.container, .row, .col-*), navbar, footer, breakpoints.
      components.css   ← Buttons, cards, forms, alerts, badges, dropdowns, utilities.
    app/
      wl-monitor.css   ← Departure tables, line badges, station dropdown, monitor UI.
    icons.svg          ← SVG sprite for all icons used in the app.
inc/
  initialize.php       ← (moved from include/)
  html_header.php      ← (moved from include/; updated <link> tags)
  html_footer.php      ← (moved from include/)
  csrf.php             ← (moved from include/, if not already in auth lib)
  auth.php
  admin.php
  favorites.php
  monitor.php
  stations.php
  ogd.php
  mailer.php
```

The `include/` directory is deleted after migration.

---

## CSS Architecture

### `css/base/theme.css`
CSS custom properties only. No rules, no selectors beyond `:root`, `[data-theme="dark"]`, and `@media (prefers-color-scheme: dark)`. No `--bs-*` variables. Adds `--font-mono` for departure times.

Variables defined:
- `--color-bg`, `--color-surface`, `--color-text`, `--color-muted`, `--color-border`
- `--color-primary`, `--color-primary-hover`
- `--color-nav-bg`, `--color-nav-text`
- `--font-sans`: `system-ui, -apple-system, "Segoe UI", sans-serif`
- `--font-mono`: `ui-monospace, "SF Mono", "Cascadia Code", monospace`
- `--radius`: `0.375rem`
- `--shadow-sm`: subtle box-shadow value

### `css/base/reset.css`
- `*, *::before, *::after { box-sizing: border-box }`
- Zero margins/padding on `html, body`
- `color-scheme: light dark`
- `body`: font, background, color from theme vars
- `img`: `max-width: 100%`, `display: block`
- `a`: inherits color, no underline by default

### `css/base/layout.css`
- `.container`: `max-width: 1200px; margin: 0 auto; padding: 0 1rem`
- `.container-fluid`: `width: 100%; padding: 0 1rem`
- CSS Grid-based `.row` / `.col-*` (replaces Bootstrap's float/flex grid)
  - `.col-md-8`, `.col-md-4` — used on index.php monitor/sidebar split
  - Responsive: stacks at <768px
- `.navbar`: flex, height, border-bottom, theme-adaptive colors
- `.wl-footer`: fixed-bottom, flex, border-top, theme-adaptive
- Spacing utilities (only those used): `.mt-2`, `.mt-3`, `.mt-4`, `.mt-5`, `.mb-2`, `.mb-3`, `.mb-4`, `.ms-1`, `.ms-2`, `.me-1`, `.me-2`, `.gap-2`, `.gap-3`
- Display utilities: `.d-flex`, `.d-none`, `.d-sm-inline`, `.align-items-center`, `.justify-content-between`, `.flex-grow-1`, `.w-100`, `.ms-auto`
- Text utilities: `.text-muted`, `.text-center`, `.small`, `.fw-semibold`, `.text-body`
- Breakpoints: `576px`, `768px`, `992px`

### `css/base/components.css`

**Buttons**
- `.btn`: base padding, border-radius, cursor, transition
- `.btn-primary`: uses `--color-primary`
- `.btn-secondary`: muted surface style
- `.btn-sm`: smaller padding/font
- `.btn-group`: flex container, border-radius merging on children
- `.btn-check` + label pattern (radio button groups for theme picker, sort toggle)
- `.btn-nav`: navbar-adaptive button (from current `theme.css`)
- `.btn-footer-toggle`: footer theme toggle button
- `.btn-close`: ✕ close button for alerts/modals

**Cards**
- `.card`, `.card-header`, `.card-body`, `.card-footer`
- `.shadow-sm`

**Forms**
- `.form-control`: input, textarea, select — border, padding, focus ring
- `.form-label`
- `.form-check`, `.form-check-input`, `.form-check-label`
- `.form-range`: styled range slider
- `.input-group`: flex wrapper with child border merging
- `.is-invalid`, `.invalid-feedback`

**Alerts**
- `.alert`: base
- `.alert-danger`, `.alert-success`, `.alert-info`, `.alert-warning`
- `.alert-dismissible` + `.btn-close` positioning

**Badges**
- `.badge`: inline-flex, small, border-radius

**Dropdowns**
- `.dropdown`: `position: relative`
- `.dropdown-menu`: absolute, surface color, border, shadow, `display: none` / `.show`
- `.dropdown-item`, `.dropdown-item-text`, `.dropdown-divider`
- JS: `wl-monitor.js` gets a small `initDropdowns()` function — click to toggle `.show`, click-outside to close. Replaces `data-bs-toggle="dropdown"`.

**Modal** (admin.php only)
- `.modal`, `.modal-dialog`, `.modal-content`, `.modal-header`, `.modal-body`, `.modal-footer`
- JS: `openModal(id)` / `closeModal(id)` — toggle `.show` + backdrop. Replaces `data-bs-toggle="modal"` in `admin.php`.

**Misc**
- `.list-unstyled`
- `.rounded-circle`
- `.visually-hidden`
- `.spinner-border`, `.spinner-border-sm`
- `.fade`, `.show` (for alert animation)

### `css/app/wl-monitor.css`
App-specific only. Contains:
- `.departure-table` and cell classes (already in `theme.css`, moves here)
- `.line-badge` and all transit type variants + U-Bahn line colours (already in `theme.css`, moves here)
- `.station-dropdown` and `.station-dropdown-header` (already in `theme.css`, moves here)
- `.nav-avatar`
- `#topBtn`
- `#monitor` loading state
- Any remaining legacy rules from `style/wl-monitor.css` that are still relevant

---

## SVG Icon Sprite

**File:** `css/icons.svg`

Hidden sprite block inlined at the top of `<body>` in `html_header.php`:
```html
<svg xmlns="http://www.w3.org/2000/svg" style="display:none">
  <symbol id="icon-subway" viewBox="0 0 24 24">…</symbol>
  …
</svg>
```

**PHP helper** defined in `inc/initialize.php` so it is available on every page:
```php
function icon(string $id, string $class = ''): string {
    $c = $class ? ' class="' . htmlspecialchars($class, ENT_QUOTES) . '"' : '';
    return '<svg' . $c . ' aria-hidden="true" focusable="false"><use href="css/icons.svg#icon-' 
         . htmlspecialchars($id, ENT_QUOTES) . '"></use></svg>';
}
```

All `<i class="fas fa-*">` replaced with `<?= icon('subway') ?>` calls.

**Icons needed** (mapped from current FA5 usage):
| FA5 class | Sprite ID |
|---|---|
| `fa-subway` | `icon-subway` |
| `fa-sign-in-alt` | `icon-sign-in` |
| `fa-sign-out-alt` | `icon-sign-out` |
| `fa-user-cog` | `icon-user-cog` |
| `fa-users-cog` | `icon-users-cog` |
| `fa-sun` | `icon-sun` |
| `fa-moon` | `icon-moon` |
| `fa-adjust` | `icon-adjust` |
| `fa-chevron-down` | `icon-chevron-down` |
| `fa-map-marker-alt` | `icon-map-marker` |
| `fa-sort-alpha-down` | `icon-sort-alpha` |
| `fa-arrow-up` | `icon-arrow-up` |
| `fa-arrow-left` | `icon-arrow-left` |
| `fa-expand-arrows-alt` | `icon-expand` |
| `fa-camera` | `icon-camera` |
| `fa-upload` | `icon-upload` |
| `fa-save` | `icon-save` |
| `fa-key` | `icon-key` |
| `fa-envelope` | `icon-envelope` |
| `fa-palette` | `icon-palette` |
| `fa-list-ol` | `icon-list-ol` |
| `fa-paper-plane` | `icon-paper-plane` |
| `fa-user` | `icon-user` |
| `fa-user-circle` | `icon-user-circle` |

SVG paths sourced from [Heroicons](https://heroicons.com) or similar MIT-licensed set, chosen for visual consistency.

---

## Directory Consolidation

1. Move `include/initialize.php` → `inc/initialize.php`
2. Move `include/html_header.php` → `inc/html_header.php`
3. Move `include/html_footer.php` → `inc/html_footer.php`
4. Move `include/csrf.php` → `inc/csrf.php` (if not already in auth lib)
5. Update every `require_once` path in all PHP files
6. Delete `include/`

---

## Pages Covered

All 12 PHP entry points in `web/`:
`index.php`, `login.php`, `preferences.php`, `admin.php`, `registration.php`, `activate.php`, `authentication.php`, `changePassword.php`, `forgotPassword.php`, `executeReset.php`, `confirm_email.php`, `editFavorite.php`

Plus shared includes: `inc/html_header.php`, `inc/html_footer.php`

---

## Out of Scope

- No SCSS/build step. Plain CSS only.
- No CSS modules, PostCSS, or autoprefixer.
- No animation library.
- `css/base/` is designed to be portable but is not published as a separate package in this task.
