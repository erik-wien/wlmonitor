# Bootstrap Removal & Custom CSS — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Remove Bootstrap, Font Awesome, and Google Fonts; replace with a hand-written CSS system in `css/base/` (portable) and `css/app/` (wlmonitor-specific); consolidate `include/` into `inc/`; replace Bootstrap JS with vanilla dropdown/modal/alert code.

**Architecture:** All Bootstrap class names are preserved — only the CSS implementation changes. SVG sprite (`css/icons.svg`) replaces FA icons, accessed via a PHP `icon()` helper. Bootstrap JS replaced with ~60 lines of vanilla JS added to `wl-monitor.js`. `include/` merged into `inc/` with all `require_once` paths updated.

**Tech Stack:** Plain CSS (no preprocessor, no build step), vanilla JS ES module, PHP 8.2

---

## File Map

| Action | File | Purpose |
|--------|------|---------|
| Create | `web/css/base/theme.css` | CSS variables only — light/dark/auto |
| Create | `web/css/base/reset.css` | Box-sizing, body base, element resets |
| Create | `web/css/base/layout.css` | Grid, containers, navbar, footer, spacing/display utilities |
| Create | `web/css/base/components.css` | Buttons, cards, forms, alerts, tables, pagination, modal, dropdown, badge, spinner |
| Create | `web/css/app/wl-monitor.css` | Departure table, line badges, station dropdown, monitor UI |
| Create | `web/css/icons.svg` | SVG sprite for all 27 icons |
| Modify | `inc/initialize.php` | Add `icon()` helper function |
| Modify | `inc/html_header.php` | Remove CDN links; load new CSS; inline SVG sprite |
| Move | `include/*.php` → `inc/` | Consolidate directories |
| Modify | All PHP files | Update `require_once` paths, replace `<i class="fas fa-*">` with `<?= icon() ?>` |
| Modify | `web/index.php` | Fix navbar classes, wire dropdown |
| Modify | `web/preferences.php` | Fix form/card classes |
| Modify | `web/admin.php` | Fix table/modal/pagination classes, remove Bootstrap JS |
| Modify | `web/js/wl-monitor.js` | Add `initDropdowns()`, `initModals()`, `initAlerts()` |
| Delete | `web/css/theme.css` | Replaced by `css/base/theme.css` |
| Delete | `web/style/wl-monitor.css` | Replaced by `css/app/wl-monitor.css` |
| Delete | `web/style/dark.css` + `web/style/bootstrap-darkly*` | Obsolete |
| Delete | `include/` | Merged into `inc/` |

---

## Task 1: `css/base/theme.css` — CSS variables

**Files:**
- Create: `web/css/base/theme.css`

- [ ] **Create the file with this exact content:**

```css
/* web/css/base/theme.css
   CSS custom properties for light/dark/auto theming.
   No rules — variables only. Toggle via data-theme="dark"|"light" on <html>.
   "auto" (no attribute) follows prefers-color-scheme.
*/

:root {
  --color-bg:          #ffffff;
  --color-surface:     #f8f9fa;
  --color-surface-alt: #e9ecef;
  --color-text:        #212529;
  --color-muted:       #6c757d;
  --color-border:      #dee2e6;
  --color-primary:     #0d6efd;
  --color-primary-hover: #0b5ed7;
  --color-danger:      #dc3545;
  --color-success:     #198754;
  --color-warning:     #ffc107;
  --color-info:        #0dcaf0;
  --color-nav-bg:      #ffffff;
  --color-nav-text:    #212529;
  --font-sans: system-ui, -apple-system, "Segoe UI", Roboto, sans-serif;
  --font-mono: ui-monospace, "SF Mono", "Cascadia Code", "Fira Code", monospace;
  --radius:    0.375rem;
  --radius-sm: 0.25rem;
  --shadow-sm: 0 1px 3px rgba(0,0,0,.08), 0 1px 2px rgba(0,0,0,.05);
  --shadow:    0 4px 6px rgba(0,0,0,.07), 0 2px 4px rgba(0,0,0,.06);
}

[data-theme="dark"] {
  --color-bg:          #1a1a2e;
  --color-surface:     #16213e;
  --color-surface-alt: #0f3460;
  --color-text:        #e0e0e0;
  --color-muted:       #adb5bd;
  --color-border:      #495057;
  --color-primary:     #4d9fff;
  --color-primary-hover: #3d8fe0;
  --color-danger:      #f07070;
  --color-success:     #5bc98a;
  --color-warning:     #ffd060;
  --color-info:        #5dd5f0;
  --color-nav-bg:      #0d0d0d;
  --color-nav-text:    #ffffff;
}

@media (prefers-color-scheme: dark) {
  :root:not([data-theme="light"]) {
    --color-bg:          #1a1a2e;
    --color-surface:     #16213e;
    --color-surface-alt: #0f3460;
    --color-text:        #e0e0e0;
    --color-muted:       #adb5bd;
    --color-border:      #495057;
    --color-primary:     #4d9fff;
    --color-primary-hover: #3d8fe0;
    --color-danger:      #f07070;
    --color-success:     #5bc98a;
    --color-warning:     #ffd060;
    --color-info:        #5dd5f0;
    --color-nav-bg:      #0d0d0d;
    --color-nav-text:    #ffffff;
  }
}
```

- [ ] **Verify file created:**

```bash
ls -la web/css/base/theme.css
```

Expected: file exists.

- [ ] **Commit:**

```bash
git add web/css/base/theme.css
git commit -m "feat: add css/base/theme.css — CSS variables, no BS vars"
```

---

## Task 2: `css/base/reset.css`

**Files:**
- Create: `web/css/base/reset.css`

- [ ] **Create the file:**

```css
/* web/css/base/reset.css */

*, *::before, *::after {
  box-sizing: border-box;
  margin: 0;
  padding: 0;
}

html {
  color-scheme: light dark;
  -webkit-text-size-adjust: 100%;
  scroll-behavior: smooth;
}

body {
  font-family: var(--font-sans);
  font-size: 1rem;
  line-height: 1.5;
  background-color: var(--color-bg);
  color: var(--color-text);
  -webkit-font-smoothing: antialiased;
}

img, svg, video {
  display: block;
  max-width: 100%;
}

input, button, textarea, select {
  font: inherit;
  color: inherit;
}

button { cursor: pointer; }

a {
  color: var(--color-primary);
  text-decoration: none;
}
a:hover { text-decoration: underline; }

p, h1, h2, h3, h4, h5, h6 { overflow-wrap: break-word; }

ul, ol { list-style: none; }

table { border-collapse: collapse; }

pre {
  font-family: var(--font-mono);
  white-space: pre-wrap;
}

small { font-size: 0.875em; }
```

- [ ] **Commit:**

```bash
git add web/css/base/reset.css
git commit -m "feat: add css/base/reset.css"
```

---

## Task 3: `css/base/layout.css`

**Files:**
- Create: `web/css/base/layout.css`

- [ ] **Create the file:**

```css
/* web/css/base/layout.css */

/* ── Containers ─────────────────────────────────────────────────────────────── */

.container {
  width: 100%;
  max-width: 1200px;
  margin-inline: auto;
  padding-inline: 1rem;
}

.container-fluid {
  width: 100%;
  padding-inline: 1rem;
}

/* ── Grid ───────────────────────────────────────────────────────────────────── */

.row {
  display: grid;
  grid-template-columns: repeat(12, 1fr);
  gap: 1rem;
}

.col-12  { grid-column: span 12; }
.col-8   { grid-column: span 8; }
.col-4   { grid-column: span 4; }
.col-6   { grid-column: span 6; }

/* Mobile: everything full-width below 768px */
@media (max-width: 767px) {
  .col-md-8, .col-md-4, .col-md-6 { grid-column: span 12; }
}
@media (min-width: 768px) {
  .col-md-12 { grid-column: span 12; }
  .col-md-8  { grid-column: span 8; }
  .col-md-4  { grid-column: span 4; }
  .col-md-6  { grid-column: span 6; }
}

/* ── Navbar ─────────────────────────────────────────────────────────────────── */

.navbar {
  display: flex;
  align-items: center;
  background-color: var(--color-nav-bg);
  border-bottom: 1px solid var(--color-border);
  padding: 0.5rem 0;
  position: sticky;
  top: 0;
  z-index: 100;
}

.navbar .container-fluid {
  display: flex;
  align-items: center;
  gap: 0.5rem;
}

.navbar-brand {
  font-size: 1.1rem;
  color: var(--color-nav-text);
  white-space: nowrap;
  text-decoration: none;
}
.navbar-brand:hover { text-decoration: none; color: var(--color-nav-text); }

.navbar-nav {
  display: flex;
  align-items: center;
  gap: 0.25rem;
}

.nav-link {
  color: var(--color-nav-text);
  padding: 0.375rem 0.5rem;
  text-decoration: none;
  display: inline-flex;
  align-items: center;
  gap: 0.25rem;
}
.nav-link:hover { text-decoration: none; opacity: 0.8; }

/* ── Footer ─────────────────────────────────────────────────────────────────── */

.wl-footer {
  position: fixed;
  bottom: 0;
  left: 0;
  right: 0;
  background-color: var(--color-surface);
  border-top: 1px solid var(--color-border);
  z-index: 100;
}

.fixed-bottom {
  position: fixed;
  bottom: 0;
  left: 0;
  right: 0;
  z-index: 100;
}

.border-top { border-top: 1px solid var(--color-border); }

/* ── Spacing utilities ──────────────────────────────────────────────────────── */

.mt-1 { margin-top: 0.25rem; }
.mt-2 { margin-top: 0.5rem; }
.mt-3 { margin-top: 1rem; }
.mt-4 { margin-top: 1.5rem; }
.mt-5 { margin-top: 3rem; }

.mb-1 { margin-bottom: 0.25rem; }
.mb-2 { margin-bottom: 0.5rem; }
.mb-3 { margin-bottom: 1rem; }
.mb-4 { margin-bottom: 1.5rem; }

.ms-1 { margin-left: 0.25rem; }
.ms-2 { margin-left: 0.5rem; }
.ms-auto { margin-left: auto; }

.me-1 { margin-right: 0.25rem; }
.me-2 { margin-right: 0.5rem; }

.p-2 { padding: 0.5rem; }
.p-4 { padding: 1.5rem; }
.py-1 { padding-block: 0.25rem; }
.py-2 { padding-block: 0.5rem; }
.px-2 { padding-inline: 0.5rem; }

.mb-0 { margin-bottom: 0; }
.me-0 { margin-right: 0; }

/* ── Display utilities ──────────────────────────────────────────────────────── */

.d-flex    { display: flex; }
.d-grid    { display: grid; }
.d-block   { display: block; }
.d-none    { display: none; }
.d-inline  { display: inline; }

.flex-grow-1    { flex-grow: 1; }
.align-items-center   { align-items: center; }
.justify-content-between { justify-content: space-between; }
.justify-content-center  { justify-content: center; }
.flex-column    { flex-direction: column; }

.gap-1 { gap: 0.25rem; }
.gap-2 { gap: 0.5rem; }
.gap-3 { gap: 1rem; }

.w-100  { width: 100%; }
.w-auto { width: auto; }
.h-100  { height: 100%; }

/* ── Text utilities ─────────────────────────────────────────────────────────── */

.text-muted   { color: var(--color-muted); }
.text-center  { text-align: center; }
.text-body    { color: var(--color-text); }
.text-nowrap  { white-space: nowrap; }

.fw-semibold  { font-weight: 600; }
.fw-bold      { font-weight: 700; }

/* ── Misc ───────────────────────────────────────────────────────────────────── */

.rounded-circle { border-radius: 50%; }
.overflow-scroll { overflow: scroll; }
.small { font-size: 0.875em; }

.visually-hidden {
  position: absolute;
  width: 1px; height: 1px;
  padding: 0; margin: -1px;
  overflow: hidden;
  clip: rect(0,0,0,0);
  white-space: nowrap;
  border: 0;
}

/* ── Responsive display ─────────────────────────────────────────────────────── */

@media (max-width: 575px) { .d-sm-inline { display: none; } }
@media (min-width: 576px) { .d-sm-inline { display: inline; } }
```

- [ ] **Commit:**

```bash
git add web/css/base/layout.css
git commit -m "feat: add css/base/layout.css"
```

---

## Task 4: `css/base/components.css` — Part 1: buttons, cards, forms

**Files:**
- Create: `web/css/base/components.css`

- [ ] **Create the file with buttons, cards, and forms:**

```css
/* web/css/base/components.css */

/* ── Buttons ────────────────────────────────────────────────────────────────── */

.btn {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  gap: 0.25rem;
  padding: 0.375rem 0.75rem;
  font-size: 1rem;
  font-weight: 400;
  line-height: 1.5;
  border: 1px solid transparent;
  border-radius: var(--radius);
  cursor: pointer;
  text-decoration: none;
  transition: background-color .15s, border-color .15s, color .15s;
  white-space: nowrap;
  user-select: none;
}
.btn:hover { text-decoration: none; }
.btn:disabled, .btn[disabled] { opacity: 0.65; pointer-events: none; }
.btn:focus-visible { outline: 2px solid var(--color-primary); outline-offset: 2px; }

.btn-primary {
  color: #fff;
  background-color: var(--color-primary);
  border-color: var(--color-primary);
}
.btn-primary:hover {
  color: #fff;
  background-color: var(--color-primary-hover);
  border-color: var(--color-primary-hover);
}

.btn-secondary {
  color: var(--color-text);
  background-color: var(--color-surface-alt);
  border-color: var(--color-border);
}
.btn-secondary:hover {
  background-color: var(--color-border);
}

.btn-outline-primary {
  color: var(--color-primary);
  background: transparent;
  border-color: var(--color-primary);
}
.btn-outline-primary:hover {
  color: #fff;
  background-color: var(--color-primary);
}

.btn-outline-secondary {
  color: var(--color-text);
  background: transparent;
  border-color: var(--color-border);
}
.btn-outline-secondary:hover {
  background-color: var(--color-surface-alt);
}

.btn-outline-danger {
  color: var(--color-danger);
  background: transparent;
  border-color: var(--color-danger);
}
.btn-outline-danger:hover { color: #fff; background-color: var(--color-danger); }

.btn-outline-warning {
  color: #856404;
  background: transparent;
  border-color: var(--color-warning);
}
.btn-outline-warning:hover { background-color: var(--color-warning); color: #000; }

.btn-sm {
  padding: 0.25rem 0.5rem;
  font-size: 0.875rem;
  border-radius: var(--radius-sm);
}

/* Navbar-adaptive button */
.btn-nav {
  color: var(--color-nav-text);
  background: transparent;
  border: 1px solid color-mix(in srgb, var(--color-nav-text) 35%, transparent);
  border-radius: var(--radius);
}
.btn-nav:hover, .btn-nav:focus {
  color: var(--color-nav-text);
  background: color-mix(in srgb, var(--color-nav-text) 10%, transparent);
  border-color: color-mix(in srgb, var(--color-nav-text) 50%, transparent);
}

/* Footer toggle button */
.btn-footer-toggle {
  color: var(--color-text);
  background: transparent;
  border: 1px solid var(--color-border);
  border-radius: var(--radius-sm);
  padding: 0.2rem 0.5rem;
  font-size: 0.875rem;
  cursor: pointer;
}
.btn-footer-toggle:hover {
  background: color-mix(in srgb, var(--color-text) 10%, transparent);
}
.btn-check:checked + .btn-footer-toggle,
.btn-check:checked + .btn-outline-secondary {
  color: var(--color-bg);
  background: var(--color-text);
  border-color: var(--color-text);
}
.btn-check:checked + .btn-outline-secondary {
  background: var(--color-primary);
  border-color: var(--color-primary);
  color: #fff;
}

/* Button group */
.btn-group {
  display: inline-flex;
}
.btn-group > .btn { border-radius: 0; }
.btn-group > .btn:first-child { border-radius: var(--radius) 0 0 var(--radius); }
.btn-group > .btn:last-child  { border-radius: 0 var(--radius) var(--radius) 0; }
.btn-group > .btn:not(:first-child) { margin-left: -1px; }

.btn-group-sm > .btn {
  padding: 0.2rem 0.45rem;
  font-size: 0.875rem;
}
.btn-group-sm > .btn:first-child { border-radius: var(--radius-sm) 0 0 var(--radius-sm); }
.btn-group-sm > .btn:last-child  { border-radius: 0 var(--radius-sm) var(--radius-sm) 0; }

/* Hidden radio/checkbox for btn-check pattern */
.btn-check {
  position: absolute;
  clip: rect(0,0,0,0);
  pointer-events: none;
}

/* Close button */
.btn-close {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  width: 1.5rem; height: 1.5rem;
  padding: 0;
  background: transparent;
  border: none;
  cursor: pointer;
  opacity: 0.5;
  font-size: 1.2rem;
  line-height: 1;
  color: var(--color-text);
}
.btn-close::before { content: "×"; }
.btn-close:hover { opacity: 1; }

/* ── Cards ──────────────────────────────────────────────────────────────────── */

.card {
  background-color: var(--color-surface);
  border: 1px solid var(--color-border);
  border-radius: var(--radius);
  overflow: hidden;
}

.card-header {
  padding: 0.75rem 1rem;
  background-color: var(--color-surface);
  border-bottom: 1px solid var(--color-border);
  font-weight: 500;
  display: flex;
  align-items: center;
}

.card-body {
  padding: 1rem;
  color: var(--color-text);
}

.card-footer {
  padding: 0.75rem 1rem;
  background-color: var(--color-surface);
  border-top: 1px solid var(--color-border);
}

.card-title {
  font-size: 1.25rem;
  font-weight: 600;
  margin-bottom: 0.75rem;
}

.shadow-sm { box-shadow: var(--shadow-sm); }

/* ── Forms ──────────────────────────────────────────────────────────────────── */

.form-label {
  display: block;
  margin-bottom: 0.375rem;
  font-weight: 500;
  font-size: 0.9375rem;
}

.form-control {
  display: block;
  width: 100%;
  padding: 0.375rem 0.75rem;
  font-size: 1rem;
  line-height: 1.5;
  color: var(--color-text);
  background-color: var(--color-bg);
  border: 1px solid var(--color-border);
  border-radius: var(--radius);
  appearance: none;
  transition: border-color .15s, box-shadow .15s;
}
.form-control:focus {
  outline: none;
  border-color: var(--color-primary);
  box-shadow: 0 0 0 0.2rem color-mix(in srgb, var(--color-primary) 25%, transparent);
}
.form-control::placeholder { color: var(--color-muted); opacity: 1; }

.form-control-sm {
  padding: 0.25rem 0.5rem;
  font-size: 0.875rem;
  border-radius: var(--radius-sm);
}

.form-select {
  display: block;
  width: 100%;
  padding: 0.375rem 2.25rem 0.375rem 0.75rem;
  font-size: 1rem;
  line-height: 1.5;
  color: var(--color-text);
  background-color: var(--color-bg);
  border: 1px solid var(--color-border);
  border-radius: var(--radius);
  appearance: none;
  background-image: url("data:image/svg+xml,%3csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 16 16'%3e%3cpath fill='none' stroke='%236c757d' stroke-linecap='round' stroke-linejoin='round' stroke-width='2' d='m2 5 6 6 6-6'/%3e%3c/svg%3e");
  background-repeat: no-repeat;
  background-position: right 0.75rem center;
  background-size: 16px 12px;
  cursor: pointer;
}
.form-select:focus {
  outline: none;
  border-color: var(--color-primary);
  box-shadow: 0 0 0 0.2rem color-mix(in srgb, var(--color-primary) 25%, transparent);
}

.form-check {
  display: flex;
  align-items: center;
  gap: 0.5rem;
  padding-left: 0;
}

.form-check-input {
  width: 1rem; height: 1rem;
  cursor: pointer;
  flex-shrink: 0;
}

.form-check-label { cursor: pointer; }

.form-range {
  width: 100%;
  height: 1.5rem;
  cursor: pointer;
  appearance: none;
  background: transparent;
}
.form-range::-webkit-slider-runnable-track {
  height: 0.375rem;
  background: var(--color-border);
  border-radius: 1rem;
}
.form-range::-webkit-slider-thumb {
  appearance: none;
  width: 1rem; height: 1rem;
  border-radius: 50%;
  background: var(--color-primary);
  margin-top: -0.3125rem;
}

.form-text {
  font-size: 0.875em;
  color: var(--color-muted);
  margin-top: 0.25rem;
}

.input-group {
  display: flex;
  align-items: stretch;
}
.input-group > .form-control,
.input-group > .btn {
  border-radius: 0;
}
.input-group > *:first-child {
  border-radius: var(--radius) 0 0 var(--radius);
}
.input-group > *:last-child {
  border-radius: 0 var(--radius) var(--radius) 0;
}
.input-group > *:not(:first-child) {
  margin-left: -1px;
}
.input-group-sm > .form-control,
.input-group-sm > .btn {
  padding: 0.25rem 0.5rem;
  font-size: 0.875rem;
}
```

- [ ] **Verify syntax (no build, just check file exists and is non-empty):**

```bash
wc -l web/css/base/components.css
```

Expected: > 150 lines

- [ ] **Commit:**

```bash
git add web/css/base/components.css
git commit -m "feat: add css/base/components.css — buttons, cards, forms"
```

---

## Task 5: `css/base/components.css` — Part 2: alerts, tables, pagination, modal, dropdown, misc

**Files:**
- Modify: `web/css/base/components.css` (append)

- [ ] **Append to `web/css/base/components.css`:**

```css

/* ── Alerts ─────────────────────────────────────────────────────────────────── */

.alert {
  padding: 0.75rem 1rem;
  border: 1px solid transparent;
  border-radius: var(--radius);
  margin-bottom: 1rem;
  position: relative;
}

.alert-danger  { color: #58151c; background-color: #f8d7da; border-color: #f1aeb5; }
.alert-success { color: #0a3622; background-color: #d1e7dd; border-color: #a3cfbb; }
.alert-warning { color: #664d03; background-color: #fff3cd; border-color: #ffe69c; }
.alert-info    { color: #055160; background-color: #cff4fc; border-color: #9eeaf9; }

[data-theme="dark"] .alert-danger,
[data-theme="dark"] .alert-success,
[data-theme="dark"] .alert-warning,
[data-theme="dark"] .alert-info {
  color: var(--color-text);
}
[data-theme="dark"] .alert-danger  { background-color: #3d1515; border-color: #7a2020; }
[data-theme="dark"] .alert-success { background-color: #0f2a1a; border-color: #1e5c35; }
[data-theme="dark"] .alert-warning { background-color: #2a2000; border-color: #5c4700; }
[data-theme="dark"] .alert-info    { background-color: #072030; border-color: #0d4a6e; }
@media (prefers-color-scheme: dark) {
  :root:not([data-theme="light"]) .alert-danger  { color: var(--color-text); background-color: #3d1515; border-color: #7a2020; }
  :root:not([data-theme="light"]) .alert-success { color: var(--color-text); background-color: #0f2a1a; border-color: #1e5c35; }
  :root:not([data-theme="light"]) .alert-warning { color: var(--color-text); background-color: #2a2000; border-color: #5c4700; }
  :root:not([data-theme="light"]) .alert-info    { color: var(--color-text); background-color: #072030; border-color: #0d4a6e; }
}

.alert-dismissible {
  padding-right: 3rem;
}
.alert-dismissible .btn-close {
  position: absolute;
  top: 50%;
  right: 0.75rem;
  transform: translateY(-50%);
}

.fade { transition: opacity .15s linear; }
.fade:not(.show) { opacity: 0; }
.show { opacity: 1; }

/* ── Badge ──────────────────────────────────────────────────────────────────── */

.badge {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  padding: 0.25em 0.5em;
  font-size: 0.75em;
  font-weight: 700;
  border-radius: var(--radius-sm);
  min-width: 2rem;
}

/* ── Tables ─────────────────────────────────────────────────────────────────── */

.table {
  width: 100%;
  color: var(--color-text);
  vertical-align: top;
  border-color: var(--color-border);
}
.table > thead { vertical-align: bottom; }
.table > :not(caption) > * > * {
  padding: 0.5rem;
  border-bottom: 1px solid var(--color-border);
}
.table-sm > :not(caption) > * > * { padding: 0.25rem 0.5rem; }

.table-hover > tbody > tr:hover > * {
  background-color: color-mix(in srgb, var(--color-text) 5%, transparent);
}

.table-dark {
  background-color: var(--color-surface-alt);
  color: var(--color-text);
}
.table-dark > tr > th {
  background-color: var(--color-surface-alt);
  border-color: var(--color-border);
}

.table-responsive {
  overflow-x: auto;
  -webkit-overflow-scrolling: touch;
}

/* ── Pagination ─────────────────────────────────────────────────────────────── */

.pagination {
  display: flex;
  gap: 0.25rem;
  list-style: none;
  flex-wrap: wrap;
}
.pagination-sm .page-link {
  padding: 0.2rem 0.5rem;
  font-size: 0.875rem;
}
.page-item { display: flex; }
.page-link {
  display: flex;
  align-items: center;
  padding: 0.375rem 0.625rem;
  color: var(--color-primary);
  background-color: var(--color-surface);
  border: 1px solid var(--color-border);
  border-radius: var(--radius-sm);
  text-decoration: none;
  transition: background-color .15s;
}
.page-link:hover { background-color: var(--color-surface-alt); }
.page-item.active .page-link {
  color: #fff;
  background-color: var(--color-primary);
  border-color: var(--color-primary);
}

/* ── Dropdown ───────────────────────────────────────────────────────────────── */

.dropdown { position: relative; }

.dropdown-toggle::after {
  display: none; /* we use icon instead */
}

.dropdown-menu {
  display: none;
  position: absolute;
  right: 0;
  top: calc(100% + 4px);
  min-width: 14rem;
  background-color: var(--color-surface);
  border: 1px solid var(--color-border);
  border-radius: var(--radius);
  box-shadow: var(--shadow);
  z-index: 200;
  overflow: hidden;
}
.dropdown-menu.show { display: block; }
.dropdown-menu-end { right: 0; left: auto; }

.dropdown-item {
  display: flex;
  align-items: center;
  gap: 0.25rem;
  padding: 0.5rem 1rem;
  color: var(--color-text);
  text-decoration: none;
  white-space: nowrap;
  background: transparent;
  border: none;
  width: 100%;
  cursor: pointer;
  font-size: 0.9375rem;
}
.dropdown-item:hover {
  background-color: var(--color-surface-alt);
  text-decoration: none;
}

.dropdown-item-text {
  display: block;
  padding: 0.5rem 1rem;
  color: var(--color-muted);
  font-size: 0.875rem;
}

.dropdown-divider {
  height: 0;
  margin: 0.25rem 0;
  overflow: hidden;
  border-top: 1px solid var(--color-border);
}

/* ── Modal ──────────────────────────────────────────────────────────────────── */

.modal {
  display: none;
  position: fixed;
  inset: 0;
  z-index: 1050;
  overflow-y: auto;
  background-color: rgba(0,0,0,.5);
  padding: 1rem;
  align-items: flex-start;
  justify-content: center;
}
.modal.show {
  display: flex;
}

.modal-dialog {
  background-color: var(--color-surface);
  border: 1px solid var(--color-border);
  border-radius: var(--radius);
  box-shadow: var(--shadow);
  width: 100%;
  max-width: 500px;
  margin-top: 3rem;
  overflow: hidden;
}

.modal-header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 1rem;
  border-bottom: 1px solid var(--color-border);
}
.modal-title {
  font-size: 1.1rem;
  font-weight: 600;
  margin: 0;
}
.modal-body   { padding: 1rem; }
.modal-footer {
  display: flex;
  justify-content: flex-end;
  gap: 0.5rem;
  padding: 1rem;
  border-top: 1px solid var(--color-border);
}

/* ── Spinner ────────────────────────────────────────────────────────────────── */

.spinner-border {
  display: inline-block;
  width: 2rem; height: 2rem;
  border: 0.25em solid var(--color-border);
  border-right-color: var(--color-primary);
  border-radius: 50%;
  animation: spinner-border .75s linear infinite;
}
.spinner-border-sm {
  width: 1rem; height: 1rem;
  border-width: 0.2em;
}
@keyframes spinner-border {
  to { transform: rotate(360deg); }
}

/* ── Misc ───────────────────────────────────────────────────────────────────── */

.list-group-item {
  padding: 0.5rem 1rem;
  background-color: var(--color-surface);
  border: 1px solid var(--color-border);
  color: var(--color-text);
}

.list-unstyled { list-style: none; padding-left: 0; }

.nav-avatar {
  width: 28px; height: 28px;
  border-radius: 50%;
  object-fit: cover;
}

/* SVG icons — inherit text color, sized to 1em */
.icon {
  display: inline-block;
  width: 1em; height: 1em;
  vertical-align: -0.125em;
  fill: none;
  stroke: currentColor;
  stroke-width: 1.5;
  stroke-linecap: round;
  stroke-linejoin: round;
  flex-shrink: 0;
}
```

- [ ] **Verify:**

```bash
wc -l web/css/base/components.css
```

Expected: > 350 lines

- [ ] **Commit:**

```bash
git add web/css/base/components.css
git commit -m "feat: add alerts, tables, pagination, modal, dropdown to components.css"
```

---

## Task 6: `css/app/wl-monitor.css`

**Files:**
- Create: `web/css/app/wl-monitor.css`

This consolidates the app-specific parts of `css/theme.css` and `style/wl-monitor.css`.

- [ ] **Create the file:**

```css
/* web/css/app/wl-monitor.css
   wlmonitor-specific styles. Not portable to other apps.
*/

/* ── Departure table ────────────────────────────────────────────────────────── */

.departure-table { table-layout: fixed; width: 100%; }
.departure-table .badge-cell    { width: 2.8em; padding: 0.2em; vertical-align: middle; }
.departure-table .platform-cell { width: 2.2em; font-size: 0.7em; color: var(--color-muted); vertical-align: middle; }
.departure-table .towards-cell  { overflow: hidden; text-overflow: ellipsis; white-space: nowrap; vertical-align: middle; }
.departure-table .times-cell    { width: 5.5em; text-align: right; font-family: var(--font-mono); font-variant-numeric: tabular-nums; font-weight: 600; vertical-align: middle; }
.departure-table td, .departure-table th {
  color: var(--color-text);
  border-color: var(--color-border);
}

/* ── Line badges ────────────────────────────────────────────────────────────── */

.line-badge {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  width: 2.4em; height: 2.4em;
  font-weight: 700;
  font-size: 0.7rem;
  color: #fff;
  line-height: 1;
}

/* ptTram — black circle; inverted in dark mode */
.line-badge.pt-tram { border-radius: 50%; background: #000; color: #fff; }
[data-theme="dark"] .line-badge.pt-tram { background: #fff; color: #000; }
@media (prefers-color-scheme: dark) {
  :root:not([data-theme="light"]) .line-badge.pt-tram { background: #fff; color: #000; }
}

/* ptBusRegion — black circle, yellow text */
.line-badge.pt-bus-region { border-radius: 50%; background: #000; color: #ffd700; }

/* ptMetro — square, per-line colour */
.line-badge.pt-metro     { border-radius: 5px; background: #666; }
.line-badge.pt-metro.U1  { background: #e2001a; }
.line-badge.pt-metro.U2  { background: #a762a3; }
.line-badge.pt-metro.U3  { background: #ec6725; }
.line-badge.pt-metro.U4  { background: #009540; }
.line-badge.pt-metro.U5  { background: #008f95; }
.line-badge.pt-metro.U6  { background: #9d6930; }

/* ptTrain — red square */
.line-badge.pt-train   { border-radius: 5px; background: #e2001a; }

/* ptTrainS — blue square */
.line-badge.pt-train-s { border-radius: 5px; background: #0000ff; }

/* ptBusCity — navy square */
.line-badge.pt-bus-city  { border-radius: 5px; background: #000080; }

/* ptBusNight — navy square, orange text */
.line-badge.pt-bus-night { border-radius: 5px; background: #000080; color: #ff8c00; }

/* ptTramWLB — blue square */
.line-badge.pt-tram-wlb  { border-radius: 5px; background: #0055a5; padding: 0.2em; }
.line-badge.pt-tram-wlb .wlb-logo {
  width: 100%; height: 100%;
  object-fit: contain;
  filter: brightness(0) invert(1);
}

/* unknown type fallback */
.line-badge.pt-default { border-radius: 5px; background: #555; }

/* ── Station search dropdown ────────────────────────────────────────────────── */

.station-dropdown {
  position: absolute;
  top: calc(100% + 4px);
  left: 0;
  min-width: 240px;
  max-height: 60vh;
  overflow-y: auto;
  background-color: var(--color-surface);
  border: 1px solid var(--color-border);
  border-radius: var(--radius);
  box-shadow: 0 6px 16px rgba(0,0,0,.35);
  z-index: 1055;
}
.station-dropdown-header {
  padding: 0.4rem 0.5rem;
  border-bottom: 1px solid var(--color-border);
  position: sticky;
  top: 0;
  background-color: var(--color-surface);
  z-index: 1;
}
.station-dropdown #stationList li p {
  padding: 0.35rem 0.75rem;
  margin: 0;
  color: var(--color-text);
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
  cursor: pointer;
}
.station-dropdown #stationList li p:hover { background-color: var(--color-surface-alt); }

/* ── Search input inside navbar ─────────────────────────────────────────────── */

nav.navbar #s {
  background-color: color-mix(in srgb, var(--color-nav-bg) 70%, var(--color-surface));
  color: var(--color-nav-text);
  border-color: color-mix(in srgb, var(--color-nav-text) 25%, transparent);
}
nav.navbar #s::placeholder { color: var(--color-muted); opacity: 1; }
nav.navbar #s:focus {
  background-color: var(--color-bg);
  color: var(--color-text);
  border-color: var(--color-primary);
  box-shadow: 0 0 0 0.2rem color-mix(in srgb, var(--color-primary) 25%, transparent);
}

/* ── Misc app elements ──────────────────────────────────────────────────────── */

#topBtn {
  position: fixed;
  bottom: 70px;
  right: 20px;
  z-index: 99;
  display: none;
}

body {
  padding-bottom: 48px; /* prevent footer overlap */
}

#stationList { cursor: default; }
```

- [ ] **Commit:**

```bash
git add web/css/app/wl-monitor.css
git commit -m "feat: add css/app/wl-monitor.css — app-specific styles"
```

---

## Task 7: `css/icons.svg` — SVG sprite

**Files:**
- Create: `web/css/icons.svg`

- [ ] **Create the sprite file.** All icons use 24×24 viewBox, stroke-based (Heroicons v2 outline):

```xml
<svg xmlns="http://www.w3.org/2000/svg" style="display:none">

  <symbol id="icon-subway" viewBox="0 0 24 24">
    <rect x="3" y="6" width="18" height="12" rx="2" stroke="currentColor" stroke-width="1.5" fill="none"/>
    <circle cx="8.5" cy="14" r="1.5" fill="currentColor"/>
    <circle cx="15.5" cy="14" r="1.5" fill="currentColor"/>
    <path d="M3 9.5h18M8.5 6V4m7 2V4" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/>
  </symbol>

  <symbol id="icon-sign-in" viewBox="0 0 24 24">
    <path stroke-linecap="round" stroke-linejoin="round" d="M15.75 9V5.25A2.25 2.25 0 0 0 13.5 3h-6a2.25 2.25 0 0 0-2.25 2.25v13.5A2.25 2.25 0 0 0 7.5 21h6a2.25 2.25 0 0 0 2.25-2.25V15m3 0 3-3m0 0-3-3m3 3H9"/>
  </symbol>

  <symbol id="icon-sign-out" viewBox="0 0 24 24">
    <path stroke-linecap="round" stroke-linejoin="round" d="M8.25 9V5.25A2.25 2.25 0 0 1 10.5 3h6a2.25 2.25 0 0 1 2.25 2.25v13.5A2.25 2.25 0 0 1 16.5 21h-6a2.25 2.25 0 0 1-2.25-2.25V15M12 9l3 3m0 0-3 3m3-3H2.25"/>
  </symbol>

  <symbol id="icon-user-cog" viewBox="0 0 24 24">
    <path stroke-linecap="round" stroke-linejoin="round" d="M15.75 6a3.75 3.75 0 1 1-7.5 0 3.75 3.75 0 0 1 7.5 0ZM4.501 20.118a7.5 7.5 0 0 1 14.998 0A17.933 17.933 0 0 1 12 21.75c-2.676 0-5.216-.584-7.499-1.632Z"/>
    <path stroke-linecap="round" stroke-linejoin="round" d="M19.5 14.25v-1.5m0 0a1.5 1.5 0 1 0 0-3 1.5 1.5 0 0 0 0 3Zm0 0v1.5m0 0a1.5 1.5 0 1 0 0 3 1.5 1.5 0 0 0 0-3Z"/>
  </symbol>

  <symbol id="icon-users-cog" viewBox="0 0 24 24">
    <path stroke-linecap="round" stroke-linejoin="round" d="M15 19.128a9.38 9.38 0 0 0 2.625.372 9.337 9.337 0 0 0 4.121-.952 4.125 4.125 0 0 0-7.533-2.493M15 19.128v-.003c0-1.113-.285-2.16-.786-3.07M15 19.128v.106A12.318 12.318 0 0 1 8.624 21c-2.331 0-4.512-.645-6.374-1.766l-.001-.109a6.375 6.375 0 0 1 11.964-3.07M12 6.375a3.375 3.375 0 1 1-6.75 0 3.375 3.375 0 0 1 6.75 0Zm8.25 2.25a2.625 2.625 0 1 1-5.25 0 2.625 2.625 0 0 1 5.25 0Z"/>
  </symbol>

  <symbol id="icon-sun" viewBox="0 0 24 24">
    <path stroke-linecap="round" stroke-linejoin="round" d="M12 3v2.25m6.364.386-1.591 1.591M21 12h-2.25m-.386 6.364-1.591-1.591M12 18.75V21m-4.773-4.227-1.591 1.591M5.25 12H3m4.227-4.773L5.636 5.636M15.75 12a3.75 3.75 0 1 1-7.5 0 3.75 3.75 0 0 1 7.5 0Z"/>
  </symbol>

  <symbol id="icon-moon" viewBox="0 0 24 24">
    <path stroke-linecap="round" stroke-linejoin="round" d="M21.752 15.002A9.72 9.72 0 0 1 18 15.75c-5.385 0-9.75-4.365-9.75-9.75 0-1.33.266-2.597.748-3.752A9.753 9.753 0 0 0 3 11.25C3 16.635 7.365 21 12.75 21a9.753 9.753 0 0 0 9.002-5.998Z"/>
  </symbol>

  <symbol id="icon-adjust" viewBox="0 0 24 24">
    <path stroke-linecap="round" stroke-linejoin="round" d="M10.5 6h9.75M10.5 6a1.5 1.5 0 1 1-3 0m3 0a1.5 1.5 0 1 0-3 0M3.75 6H7.5m3 12h9.75m-9.75 0a1.5 1.5 0 0 1-3 0m3 0a1.5 1.5 0 0 0-3 0m-3.75 0H7.5m9-6h3.75m-3.75 0a1.5 1.5 0 0 1-3 0m3 0a1.5 1.5 0 0 0-3 0m-9.75 0h9.75"/>
  </symbol>

  <symbol id="icon-chevron-down" viewBox="0 0 24 24">
    <path stroke-linecap="round" stroke-linejoin="round" d="m19.5 8.25-7.5 7.5-7.5-7.5"/>
  </symbol>

  <symbol id="icon-map-marker" viewBox="0 0 24 24">
    <path stroke-linecap="round" stroke-linejoin="round" d="M15 10.5a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z"/>
    <path stroke-linecap="round" stroke-linejoin="round" d="M19.5 10.5c0 7.142-7.5 11.25-7.5 11.25S4.5 17.642 4.5 10.5a7.5 7.5 0 1 1 15 0Z"/>
  </symbol>

  <symbol id="icon-sort-alpha" viewBox="0 0 24 24">
    <path stroke-linecap="round" stroke-linejoin="round" d="M3 4.5h14.25M3 9h9.75M3 13.5h9.75m4.5-4.5v12m0 0-3.75-3.75M17.25 21l3.75-3.75"/>
  </symbol>

  <symbol id="icon-arrow-up" viewBox="0 0 24 24">
    <path stroke-linecap="round" stroke-linejoin="round" d="M4.5 10.5 12 3m0 0 7.5 7.5M12 3v18"/>
  </symbol>

  <symbol id="icon-arrow-left" viewBox="0 0 24 24">
    <path stroke-linecap="round" stroke-linejoin="round" d="M10.5 19.5 3 12m0 0 7.5-7.5M3 12h18"/>
  </symbol>

  <symbol id="icon-expand" viewBox="0 0 24 24">
    <path stroke-linecap="round" stroke-linejoin="round" d="M3.75 3.75v4.5m0-4.5h4.5m-4.5 0L9 9M3.75 20.25v-4.5m0 4.5h4.5m-4.5 0L9 15M20.25 3.75h-4.5m4.5 0v4.5m0-4.5L15 9m5.25 11.25h-4.5m4.5 0v-4.5m0 4.5L15 15"/>
  </symbol>

  <symbol id="icon-camera" viewBox="0 0 24 24">
    <path stroke-linecap="round" stroke-linejoin="round" d="M6.827 6.175A2.31 2.31 0 0 1 5.186 7.23c-.38.054-.757.112-1.134.175C2.999 7.58 2.25 8.507 2.25 9.574V18a2.25 2.25 0 0 0 2.25 2.25h15A2.25 2.25 0 0 0 21.75 18V9.574c0-1.067-.75-1.994-1.802-2.169a47.865 47.865 0 0 0-1.134-.175 2.31 2.31 0 0 1-1.64-1.055l-.822-1.316a2.192 2.192 0 0 0-1.736-1.039 48.774 48.774 0 0 0-5.232 0 2.192 2.192 0 0 0-1.736 1.039l-.821 1.316Z"/>
    <path stroke-linecap="round" stroke-linejoin="round" d="M16.5 12.75a4.5 4.5 0 1 1-9 0 4.5 4.5 0 0 1 9 0ZM18.75 10.5h.008v.008h-.008V10.5Z"/>
  </symbol>

  <symbol id="icon-upload" viewBox="0 0 24 24">
    <path stroke-linecap="round" stroke-linejoin="round" d="M3 16.5v2.25A2.25 2.25 0 0 0 5.25 21h13.5A2.25 2.25 0 0 0 21 18.75V16.5m-13.5-9L12 3m0 0 4.5 4.5M12 3v13.5"/>
  </symbol>

  <symbol id="icon-save" viewBox="0 0 24 24">
    <path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75 11.25 15 15 9.75M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z"/>
  </symbol>

  <symbol id="icon-key" viewBox="0 0 24 24">
    <path stroke-linecap="round" stroke-linejoin="round" d="M15.75 5.25a3 3 0 0 1 3 3m3 0a6 6 0 0 1-7.029 5.912c-.563-.097-1.159.026-1.563.43L10.5 17.25H8.25v2.25H6v2.25H2.25v-2.818c0-.597.237-1.17.659-1.591l6.499-6.499c.404-.404.527-1 .43-1.563A6 6 0 0 1 21.75 8.25Z"/>
  </symbol>

  <symbol id="icon-envelope" viewBox="0 0 24 24">
    <path stroke-linecap="round" stroke-linejoin="round" d="M21.75 6.75v10.5a2.25 2.25 0 0 1-2.25 2.25h-15a2.25 2.25 0 0 1-2.25-2.25V6.75m19.5 0A2.25 2.25 0 0 0 19.5 4.5h-15a2.25 2.25 0 0 0-2.25 2.25m19.5 0v.243a2.25 2.25 0 0 1-1.07 1.916l-7.5 4.615a2.25 2.25 0 0 1-2.36 0L3.32 8.91a2.25 2.25 0 0 1-1.07-1.916V6.75"/>
  </symbol>

  <symbol id="icon-palette" viewBox="0 0 24 24">
    <path stroke-linecap="round" stroke-linejoin="round" d="M9.53 16.122a3 3 0 0 0-5.78 1.128 2.25 2.25 0 0 1-2.4 2.245 4.5 4.5 0 0 0 8.4-2.245c0-.399-.078-.78-.22-1.128Zm0 0a15.998 15.998 0 0 0 3.388-1.62m-5.043-.025a15.994 15.994 0 0 1 1.622-3.395m3.42 3.42a15.995 15.995 0 0 0 4.764-4.648l3.876-5.814a1.151 1.151 0 0 0-1.597-1.597L14.146 6.32a15.996 15.996 0 0 0-4.649 4.763m3.42 3.42a6.776 6.776 0 0 0-3.42-3.42"/>
  </symbol>

  <symbol id="icon-list-ol" viewBox="0 0 24 24">
    <path stroke-linecap="round" stroke-linejoin="round" d="M8.25 6.75h12M8.25 12h12m-12 5.25h12M3.75 6.75h.007v.008H3.75V6.75Zm.375 0a.375.375 0 1 1-.75 0 .375.375 0 0 1 .75 0ZM3.75 12h.007v.008H3.75V12Zm.375 0a.375.375 0 1 1-.75 0 .375.375 0 0 1 .75 0Zm-.375 5.25h.007v.008H3.75v-.008Zm.375 0a.375.375 0 1 1-.75 0 .375.375 0 0 1 .75 0Z"/>
  </symbol>

  <symbol id="icon-paper-plane" viewBox="0 0 24 24">
    <path stroke-linecap="round" stroke-linejoin="round" d="M6 12 3.269 3.125A59.769 59.769 0 0 1 21.485 12 59.768 59.768 0 0 1 3.27 20.875L5.999 12Zm0 0h7.5"/>
  </symbol>

  <symbol id="icon-user" viewBox="0 0 24 24">
    <path stroke-linecap="round" stroke-linejoin="round" d="M15.75 6a3.75 3.75 0 1 1-7.5 0 3.75 3.75 0 0 1 7.5 0ZM4.501 20.118a7.5 7.5 0 0 1 14.998 0A17.933 17.933 0 0 1 12 21.75c-2.676 0-5.216-.584-7.499-1.632Z"/>
  </symbol>

  <symbol id="icon-user-circle" viewBox="0 0 24 24">
    <path stroke-linecap="round" stroke-linejoin="round" d="M17.982 18.725A7.488 7.488 0 0 0 12 15.75a7.488 7.488 0 0 0-5.982 2.975m11.963 0a9 9 0 1 0-11.963 0m11.963 0A8.966 8.966 0 0 1 12 21a8.966 8.966 0 0 1-5.982-2.275M15 9.75a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z"/>
  </symbol>

  <symbol id="icon-user-plus" viewBox="0 0 24 24">
    <path stroke-linecap="round" stroke-linejoin="round" d="M18 7.5v3m0 0v3m0-3h3m-3 0h-3m-2.25-4.125a3.375 3.375 0 1 1-6.75 0 3.375 3.375 0 0 1 6.75 0ZM3 19.235v-.11a6.375 6.375 0 0 1 12.75 0v.109A12.318 12.318 0 0 1 9.374 21c-2.331 0-4.512-.645-6.374-1.766Z"/>
  </symbol>

  <symbol id="icon-database" viewBox="0 0 24 24">
    <path stroke-linecap="round" stroke-linejoin="round" d="M20.25 6.375c0 2.278-3.694 4.125-8.25 4.125S3.75 8.653 3.75 6.375m16.5 0c0-2.278-3.694-4.125-8.25-4.125S3.75 4.097 3.75 6.375m16.5 0v11.25c0 2.278-3.694 4.125-8.25 4.125s-8.25-1.847-8.25-4.125V6.375m16.5 0v3.75m-16.5-3.75v3.75m16.5 0v3.75C20.25 16.153 16.556 18 12 18s-8.25-1.847-8.25-4.125v-3.75m16.5 0c0 2.278-3.694 4.125-8.25 4.125s-8.25-1.847-8.25-4.125"/>
  </symbol>

  <symbol id="icon-search" viewBox="0 0 24 24">
    <path stroke-linecap="round" stroke-linejoin="round" d="m21 21-5.197-5.197m0 0A7.5 7.5 0 1 0 5.196 5.196a7.5 7.5 0 0 0 10.607 10.607Z"/>
  </symbol>

  <symbol id="icon-sync" viewBox="0 0 24 24">
    <path stroke-linecap="round" stroke-linejoin="round" d="M16.023 9.348h4.992v-.001M2.985 19.644v-4.992m0 0h4.992m-4.993 0 3.181 3.183a8.25 8.25 0 0 0 13.803-3.7M4.031 9.865a8.25 8.25 0 0 1 13.803-3.7l3.181 3.182m0-4.991v4.99"/>
  </symbol>

</svg>
```

- [ ] **Commit:**

```bash
git add web/css/icons.svg
git commit -m "feat: add css/icons.svg SVG sprite (27 icons, Heroicons v2 outline)"
```

---

## Task 8: `icon()` helper + update `inc/initialize.php`

**Files:**
- Modify: `inc/initialize.php`

- [ ] **Add `icon()` helper function** at the end of `inc/initialize.php`, before the closing line:

```php
/**
 * Render an SVG icon from the sprite.
 *
 * @param string $id    Icon ID (without 'icon-' prefix, e.g. 'subway').
 * @param string $class Extra CSS classes to add alongside 'icon'.
 * @return string       HTML <svg> element (safe, no user input reflected).
 */
function icon(string $id, string $class = ''): string {
    $c = 'icon' . ($class !== '' ? ' ' . htmlspecialchars($class, ENT_QUOTES, 'UTF-8') : '');
    $safeId = htmlspecialchars($id, ENT_QUOTES, 'UTF-8');
    return '<svg class="' . $c . '" aria-hidden="true" focusable="false">'
         . '<use href="css/icons.svg#icon-' . $safeId . '"></use>'
         . '</svg>';
}
```

- [ ] **Check PHP syntax:**

```bash
php -l inc/initialize.php
```

Expected: `No syntax errors detected`

- [ ] **Commit:**

```bash
git add inc/initialize.php
git commit -m "feat: add icon() helper to initialize.php"
```

---

## Task 9: Update `inc/html_header.php` — remove CDN deps, load custom CSS

**Files:**
- Modify: `inc/html_header.php`

- [ ] **Replace the `<head>` content** — remove Bootstrap, FA, Google Fonts; add new CSS links; inline sprite:

```php
<!DOCTYPE html>
<html lang="de">
<head>
  <title>Wiener Linien Abfahrtsmonitor</title>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <meta http-equiv="X-UA-Compatible" content="ie=edge">
  <meta name="application-name" content="WL Monitor">
  <meta name="mobile-web-app-capable" content="yes">
  <meta name="apple-mobile-web-app-capable" content="yes">
  <meta name="apple-mobile-web-app-title" content="WL Monitor">
  <meta name="theme-color" content="#000000">
  <link rel="shortcut icon" type="image/x-icon" href="img/favicon.ico">
  <link rel="apple-touch-icon" sizes="180x180" href="img/apple-touch-icon-180x180.png">
  <link rel="manifest" href="img/manifest.json">
  <link rel="stylesheet" href="css/base/theme.css">
  <link rel="stylesheet" href="css/base/reset.css">
  <link rel="stylesheet" href="css/base/layout.css">
  <link rel="stylesheet" href="css/base/components.css">
  <link rel="stylesheet" href="css/app/wl-monitor.css">
</head>
<body>
<?php
// Inline SVG sprite (one request, cached by browser)
$_spritePath = __DIR__ . '/../web/css/icons.svg';
if (file_exists($_spritePath)) { readfile($_spritePath); }
unset($_spritePath);
?>
```

- [ ] **Check PHP syntax:**

```bash
php -l inc/html_header.php
```

Expected: `No syntax errors detected`

- [ ] **Commit:**

```bash
git add inc/html_header.php
git commit -m "feat: update html_header.php — remove Bootstrap/FA/GF, load custom CSS + SVG sprite"
```

---

## Task 10: Consolidate `include/` → `inc/`

**Files:**
- Move all files from `include/` to `inc/`
- Update `require_once` paths in all PHP files
- Delete `include/`

- [ ] **Move files:**

```bash
# html_header.php and html_footer.php are already in inc/ from previous tasks
# Move the remaining ones
cp include/csrf.php inc/csrf.php 2>/dev/null || true
# initialize.php is already in inc/
```

Check which files still exist in `include/`:
```bash
ls include/
```

Move any remaining files that haven't been moved yet:
```bash
for f in include/*.php; do
  base=$(basename "$f")
  if [ ! -f "inc/$base" ]; then
    cp "$f" "inc/$base"
    echo "Copied $base"
  fi
done
```

- [ ] **Update all `require_once` paths** — replace `include/` with `inc/` across all PHP files:

```bash
find web/ inc/ -name "*.php" | xargs grep -l "include/initialize\|include/html_header\|include/html_footer\|include/csrf" | while read f; do
  sed -i '' \
    -e "s|include/initialize\.php|inc/initialize.php|g" \
    -e "s|include/html_header\.php|inc/html_header.php|g" \
    -e "s|include/html_footer\.php|inc/html_footer.php|g" \
    -e "s|include/csrf\.php|inc/csrf.php|g" \
    -e "s|'/../include/|'/../inc/|g" \
    -e "s|__DIR__ \. '/../include/|__DIR__ . '/../inc/|g" \
    "$f"
  echo "Updated $f"
done
```

- [ ] **Verify no `include/` references remain in PHP:**

```bash
grep -rn "include/" web/ inc/ --include="*.php" | grep -v "//\|#"
```

Expected: no output (or only unrelated `include` PHP statements, not path-based ones).

- [ ] **Check syntax on all modified files:**

```bash
find web/ -name "*.php" | xargs -I{} php -l {} | grep -v "No syntax"
```

Expected: no output (all files pass).

- [ ] **Delete `include/` and old CSS files:**

```bash
rm -rf include/
rm -f web/css/theme.css
rm -f web/style/wl-monitor.css
rm -f web/style/dark.css
rm -f web/style/dark.css.map
rm -f web/style/bootstrap-darkly.css
rm -f web/style/bootstrap-darkly.min.css
rm -f web/style/_variables.scss
```

- [ ] **Commit:**

```bash
git add -A
git commit -m "refactor: consolidate include/ into inc/, remove old CSS files"
```

---

## Task 11: Update `index.php`

Replace Bootstrap-specific classes (`navbar-dark`, `bg-dark`), replace `<i class="fas">` with `<?= icon() ?>`, remove inline `<script src="bootstrap.bundle...">`, fix dropdown.

**Files:**
- Modify: `web/index.php`

- [ ] **Replace `<i class="fas fa-*">` with `icon()` calls:**

```bash
# Run these replacements in web/index.php:
sed -i '' \
  -e 's|<i class="fas fa-subway me-1"></i>|<?= icon("subway", "me-1") ?>|g' \
  -e 's|<i class="fas fa-chevron-down"></i>|<?= icon("chevron-down") ?>|g' \
  -e 's|<i class="fas fa-sort-alpha-down"></i>|<?= icon("sort-alpha") ?>|g' \
  -e 's|<i class="fas fa-map-marker-alt"></i>|<?= icon("map-marker") ?>|g' \
  -e 's|<i class="fas fa-arrow-up"></i>|<?= icon("arrow-up") ?>|g' \
  -e 's|<i class="fas fa-user-cog me-2"></i>|<?= icon("user-cog", "me-2") ?>|g' \
  -e 's|<i class="fas fa-users-cog me-2"></i>|<?= icon("users-cog", "me-2") ?>|g' \
  -e 's|<i class="fas fa-sign-out-alt me-2"></i>|<?= icon("sign-out", "me-2") ?>|g' \
  -e 's|<i class="fas fa-sun"></i>|<?= icon("sun") ?>|g' \
  -e 's|<i class="fas fa-adjust"></i>|<?= icon("adjust") ?>|g' \
  -e 's|<i class="fas fa-moon"></i>|<?= icon("moon") ?>|g' \
  web/index.php
```

- [ ] **Fix navbar dropdown** — replace `data-bs-toggle="dropdown"` with `data-dropdown-toggle`:

Open `web/index.php` and change:
```html
<a class="nav-link dropdown-toggle d-flex align-items-center gap-2 px-2"
   href="#" role="button" data-bs-toggle="dropdown" aria-expanded="false">
```
to:
```html
<a class="nav-link dropdown-toggle d-flex align-items-center gap-2 px-2"
   href="#" role="button" data-dropdown-toggle aria-expanded="false">
```

- [ ] **Remove inline Bootstrap JS `<script>` tag** from `web/index.php`:

```bash
sed -i '' '/cdn.jsdelivr.net.*bootstrap.*bundle/d' web/index.php
```

- [ ] **Check PHP syntax:**

```bash
php -l web/index.php
```

Expected: `No syntax errors detected`

- [ ] **Commit:**

```bash
git add web/index.php
git commit -m "refactor: update index.php — replace FA icons, fix dropdown, remove BS JS"
```

---

## Task 12: Update auth pages: `login.php`, `register.php`, `forgotPassword.php`, `executeReset.php`, `changePassword.php`

**Files:**
- Modify: `web/login.php`, `web/register.php`, `web/forgotPassword.php`, `web/executeReset.php`, `web/changePassword.php`

- [ ] **Replace FA icons and remove Bootstrap JS across all five files:**

```bash
for FILE in web/login.php web/register.php web/forgotPassword.php web/executeReset.php web/changePassword.php; do
  sed -i '' \
    -e 's|<i class="fas fa-subway me-1"></i>|<?= icon("subway", "me-1") ?>|g' \
    -e 's|<i class="fas fa-subway me-2"></i>|<?= icon("subway", "me-2") ?>|g' \
    -e 's|<i class="fas fa-sign-in-alt me-1"></i>|<?= icon("sign-in", "me-1") ?>|g' \
    -e 's|<i class="fas fa-key me-1"></i>|<?= icon("key", "me-1") ?>|g' \
    -e 's|<i class="fas fa-user-plus me-2"></i>|<?= icon("user-plus", "me-2") ?>|g' \
    -e 's|<i class="fas fa-user-plus me-1"></i>|<?= icon("user-plus", "me-1") ?>|g' \
    -e 's|<i class="fas fa-save me-1"></i>|<?= icon("save", "me-1") ?>|g' \
    -e 's|<i class="fas fa-arrow-left"></i>|<?= icon("arrow-left") ?>|g' \
    -e '/cdn.jsdelivr.net.*bootstrap.*bundle/d' \
    "$FILE"
  echo "Updated $FILE"
done
```

- [ ] **Fix `executeReset.php`** — the navbar uses `navbar-dark bg-dark` Bootstrap classes; replace with plain `.navbar`:

In `web/executeReset.php`, change:
```html
<nav class="navbar navbar-dark bg-dark">
```
to:
```html
<nav class="navbar">
```

- [ ] **Fix `editFavorite.php` and `changePassword.php`** — same navbar issue:

```bash
sed -i '' \
  -e 's|class="navbar navbar-expand-lg navbar-dark bg-dark"|class="navbar"|g' \
  -e 's|class="nav-link text-light"|class="nav-link"|g' \
  web/editFavorite.php web/changePassword.php
```

- [ ] **Replace FA icons in `editFavorite.php`:**

```bash
sed -i '' \
  -e 's|<i class="fas fa-subway me-1"></i>|<?= icon("subway", "me-1") ?>|g' \
  -e 's|<i class="fas fa-arrow-left"></i>|<?= icon("arrow-left") ?>|g' \
  -e 's|<i class="fas fa-save me-1"></i>|<?= icon("save", "me-1") ?>|g' \
  -e '/cdn.jsdelivr.net.*bootstrap.*bundle/d' \
  web/editFavorite.php
```

- [ ] **Replace FA icons in `changePassword.php`:**

```bash
sed -i '' \
  -e 's|<i class="fas fa-subway me-1"></i>|<?= icon("subway", "me-1") ?>|g' \
  -e 's|<i class="fas fa-arrow-left"></i>|<?= icon("arrow-left") ?>|g' \
  -e 's|<i class="fas fa-save me-1"></i>|<?= icon("save", "me-1") ?>|g' \
  -e '/cdn.jsdelivr.net.*bootstrap.*bundle/d' \
  web/changePassword.php
```

- [ ] **Fix `data-bs-dismiss="alert"` in alert close buttons** — replace with `data-dismiss-alert`:

```bash
for FILE in web/login.php web/register.php web/preferences.php; do
  sed -i '' 's|data-bs-dismiss="alert"|data-dismiss-alert|g' "$FILE"
done
```

- [ ] **Verify PHP syntax on all modified files:**

```bash
for FILE in web/login.php web/register.php web/forgotPassword.php web/executeReset.php web/changePassword.php web/editFavorite.php; do
  php -l "$FILE"
done
```

Expected: all report `No syntax errors detected`

- [ ] **Commit:**

```bash
git add web/login.php web/register.php web/forgotPassword.php web/executeReset.php web/changePassword.php web/editFavorite.php
git commit -m "refactor: replace FA icons, remove BS JS, fix navbar classes in auth/form pages"
```

---

## Task 13: Update `preferences.php`

**Files:**
- Modify: `web/preferences.php`

- [ ] **Replace all FA icons:**

```bash
sed -i '' \
  -e 's|<i class="fas fa-camera me-1"></i>|<?= icon("camera", "me-1") ?>|g' \
  -e 's|<i class="fas fa-upload me-1"></i>|<?= icon("upload", "me-1") ?>|g' \
  -e 's|<i class="fas fa-list-ol me-1"></i>|<?= icon("list-ol", "me-1") ?>|g' \
  -e 's|<i class="fas fa-palette me-1"></i>|<?= icon("palette", "me-1") ?>|g' \
  -e 's|<i class="fas fa-envelope me-1"></i>|<?= icon("envelope", "me-1") ?>|g' \
  -e 's|<i class="fas fa-paper-plane me-1"></i>|<?= icon("paper-plane", "me-1") ?>|g' \
  -e 's|<i class="fas fa-key me-1"></i>|<?= icon("key", "me-1") ?>|g' \
  -e 's|<i class="fas fa-save me-1"></i>|<?= icon("save", "me-1") ?>|g' \
  -e 's|<i class="fas fa-user-cog me-2"></i>|<?= icon("user-cog", "me-2") ?>|g' \
  -e 's|<i class="fas fa-arrow-left"></i>|<?= icon("arrow-left") ?>|g' \
  -e 's|<i class="fas fa-user"></i>|<?= icon("user") ?>|g' \
  -e 's|data-bs-dismiss="alert"|data-dismiss-alert|g' \
  -e '/cdn.jsdelivr.net.*bootstrap.*bundle/d' \
  web/preferences.php
```

- [ ] **Verify PHP syntax:**

```bash
php -l web/preferences.php
```

Expected: `No syntax errors detected`

- [ ] **Commit:**

```bash
git add web/preferences.php
git commit -m "refactor: update preferences.php — replace FA icons, remove BS JS"
```

---

## Task 14: Update `admin.php` — modal, table, remove Bootstrap JS

**Files:**
- Modify: `web/admin.php`

- [ ] **Replace FA icons:**

```bash
sed -i '' \
  -e 's|<i class="fas fa-users-cog me-1"></i>|<?= icon("users-cog", "me-1") ?>|g' \
  -e 's|<i class="fas fa-arrow-left me-1"></i>|<?= icon("arrow-left", "me-1") ?>|g' \
  -e 's|<i class="fas fa-database me-1"></i>|<?= icon("database", "me-1") ?>|g' \
  -e 's|<i class="fas fa-sync-alt me-1"></i>|<?= icon("sync", "me-1") ?>|g' \
  -e 's|<i class="fas fa-search"></i>|<?= icon("search") ?>|g' \
  web/admin.php
```

- [ ] **Replace Bootstrap modal trigger** — change `data-bs-toggle="modal" data-bs-target="#editModal"` to `data-modal-open="editModal"`:

In `web/admin.php`, find the `.btn-edit` button and change:
```html
data-bs-toggle="modal" data-bs-target="#editModal">
```
to:
```html
data-modal-open="editModal">
```

- [ ] **Replace Bootstrap modal dismiss** — change `data-bs-dismiss="modal"` to `data-modal-close`:

```bash
sed -i '' 's|data-bs-dismiss="modal"|data-modal-close|g' web/admin.php
```

- [ ] **Replace Bootstrap modal JS call** — in `admin.php`'s inline script, change:

```js
bootstrap.Modal.getInstance(document.getElementById('editModal')).hide();
```
to:
```js
closeModal('editModal');
```

- [ ] **Remove Bootstrap JS `<script>` tag:**

```bash
sed -i '' '/cdn.jsdelivr.net.*bootstrap.*bundle/d' web/admin.php
```

- [ ] **Verify PHP syntax:**

```bash
php -l web/admin.php
```

Expected: `No syntax errors detected`

- [ ] **Commit:**

```bash
git add web/admin.php
git commit -m "refactor: update admin.php — custom modal, replace FA icons, remove BS JS"
```

---

## Task 15: Add vanilla JS — dropdowns, modals, alert dismiss

Bootstrap's JS handled three things in this app: dropdown toggles, modal open/close, and alert dismiss. Add all three to `wl-monitor.js` and expose `closeModal()` globally for `admin.php`.

**Files:**
- Modify: `web/js/wl-monitor.js`
- Modify: `inc/html_footer.php`

- [ ] **Add to the bottom of `web/js/wl-monitor.js`** (before the last line or at the end):

```js
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
```

- [ ] **Call the new init functions** — in the `DOMContentLoaded` handler at the top of `wl-monitor.js`, add:

```js
  initDropdowns();
  initModals();
  initAlerts();
```

- [ ] **Update `inc/html_footer.php`** — the footer JS also uses `data-bs-dismiss` attribute name in the `showAlert` function that creates dynamic alerts. Change the footer to also use `data-dismiss-alert`:

In `inc/html_footer.php`, the footer theme toggle JS creates no alerts, so no change needed there. But check the `showAlert` function in any inline scripts uses `data-dismiss-alert` not `data-bs-dismiss`:

```bash
grep -n "data-bs-dismiss\|bsDismiss" inc/html_footer.php web/admin.php web/js/wl-monitor.js
```

Fix any remaining occurrences — change `btn.dataset.bsDismiss = 'alert'` in `wl-monitor.js`'s `sendAlert()` function to use `data-dismiss-alert`:

Find in `wl-monitor.js` the `sendAlert` function and change:
```js
closeBtn.dataset.bsDismiss = 'alert';
```
to:
```js
closeBtn.dataset.dismissAlert = '';
```

And in `admin.php`'s `showAlert` function change:
```js
btn.dataset.bsDismiss = 'alert';
```
to:
```js
btn.dataset.dismissAlert = '';
```

- [ ] **Verify no remaining Bootstrap JS references:**

```bash
grep -rn "bootstrap\." web/ inc/ --include="*.php" --include="*.js" | grep -v "cdn.jsdelivr\|#\|//"
```

Expected: no output.

- [ ] **Check JS syntax (basic):**

```bash
node --check web/js/wl-monitor.js 2>&1
```

Expected: no output (no syntax errors).

- [ ] **Commit:**

```bash
git add web/js/wl-monitor.js inc/html_footer.php web/admin.php
git commit -m "feat: add dropdown/modal/alert-dismiss vanilla JS, remove all BS JS calls"
```

---

## Task 16: Smoke-test in browser + fix visual regressions

There's no automated test for CSS. This task is manual verification.

- [ ] **Start Apache if not running:**

```bash
sudo apachectl start 2>/dev/null || true
```

- [ ] **Sync files to document root:**

```bash
rsync -a --checksum /Users/erikr/Git/wlmonitor/web/ /Library/WebServer/Documents/wlmonitor/web/
rsync -a --checksum /Users/erikr/Git/wlmonitor/inc/ /Library/WebServer/Documents/wlmonitor/inc/
```

- [ ] **Open each page and verify:**

  1. `http://localhost/wlmonitor/web/index.php`
     - Navbar renders, avatar dropdown opens/closes on click, closes on outside click
     - Station search dropdown works
     - Departure table renders (if favorites exist)
     - Theme switcher in dropdown applies theme instantly
     - Footer theme toggle works
  2. `http://localhost/wlmonitor/web/login.php`
     - Card centered, form styled, alert dismissible
  3. `http://localhost/wlmonitor/web/preferences.php` (when logged in)
     - Cards render, form range slider styled, theme radio buttons work
  4. `http://localhost/wlmonitor/web/admin.php` (when logged in as Admin)
     - Table renders, Edit button opens modal, modal close button works, modal closes on backdrop click
  5. `http://localhost/wlmonitor/web/forgotPassword.php`
     - Page renders cleanly

- [ ] **Fix any visual issues** — common regressions to look for:
  - Missing padding in navbar → check `.container-fluid` has padding
  - Icons not rendering → check SVG sprite path in `html_header.php` is relative to web root
  - Form controls missing focus ring → check `.form-control:focus` in components.css
  - Dark mode not applying → check `data-theme` attribute toggle in JS

- [ ] **Commit any fixes:**

```bash
git add -A
git commit -m "fix: CSS visual regressions after Bootstrap removal"
```

---

## Task 17: Run integration tests + final cleanup

**Files:**
- No code changes — verification only

- [ ] **Run integration tests:**

```bash
cd /Users/erikr/Git/wlmonitor
APP_ENV=local vendor/bin/phpunit tests/Integration/ --testdox 2>&1
```

Expected: all tests pass. If failures relate to changed `require_once` paths, fix them now and commit.

- [ ] **Verify no CDN references remain:**

```bash
grep -rn "bootstrap\|fontawesome\|fonts.googleapis\|cdn.jsdelivr" web/ inc/ --include="*.php" --include="*.js" --include="*.css"
```

Expected: no output.

- [ ] **Verify old files are gone:**

```bash
ls web/css/theme.css web/style/wl-monitor.css web/style/bootstrap-darkly.css 2>&1
```

Expected: `No such file or directory` for each.

- [ ] **Sync to document root one final time:**

```bash
rsync -a --checksum /Users/erikr/Git/wlmonitor/ /Library/WebServer/Documents/wlmonitor/ \
  --exclude='.git' --exclude='vendor' --exclude='tests' --exclude='docs' --exclude='backups'
```

- [ ] **Final commit:**

```bash
git add -A
git commit -m "chore: final cleanup after Bootstrap removal — all pages migrated, tests passing"
```
