---
id: TASK-3
title: 'Deprecate the "Debug" per-user flag: remove from admin UI and auth DB'
status: Done
assignee: []
created_date: '2026-04-18 10:43'
updated_date: '2026-04-19 06:27'
labels:
  - cleanup
  - admin
  - cross-repo
  - auth-library
  - db-migration
dependencies: []
references:
  - 'web/admin.php:140'
  - 'web/admin.php:201'
  - 'inc/admin.php:46'
  - 'inc/admin.php:79'
  - 'web/api.php:273'
  - '/Users/erikr/Git/auth/src/admin.php:140'
  - '/Users/erikr/Git/auth/src/auth.php:376'
  - '/Users/erikr/Git/auth/src/log.php:67'
  - /Users/erikr/Git/auth/db/
priority: medium
---

## Description

<!-- SECTION:DESCRIPTION:BEGIN -->
## Goal

Remove the per-user "Debug" feature end-to-end. The column exists on `auth.auth_accounts.debug`, is shown in the wlmonitor admin user table (`Debug` column with a "ja" badge), and is editable via a checkbox in the create/edit-user modal. The feature is **unused** — no code actually calls `logDebug()` to emit debug-gated log entries anywhere in `~/Git/` (only the helper itself and its read-site in `~/Git/auth/src/log.php:69` reference `$_SESSION['debug']`). Time to drop it.

## Interpretation

User said "deprecate in UI and in DB". I'm reading this as a **hard removal** in both places (UI entries removed, DB column dropped), not a two-phase deprecate-then-remove — there's nothing using the flag, so there's nothing to deprecate-and-migrate. If the user actually wants a soft-deprecation first (hide in UI but keep the column for now), please say so before starting — otherwise the task proceeds as a full removal.

## Where "debug" lives

### Consumer — wlmonitor

- `web/admin.php:140-150` — `extraColumns` array adds the `Debug` column to the users table (renders "ja" badge or muted dash).
- `web/admin.php:201-207` — `extraFields` array adds the "Debug-Modus" checkbox to the create/edit modal.
- `web/admin.php:245` — inline JS pre-fills the modal checkbox from `btn.dataset.debug`.
- `inc/admin.php:7,16,19,46-61` — `listUsersWithExtras()` does a second SELECT on `auth_accounts.debug` and merges it onto each user row.
- `inc/admin.php:79-82` — `editUser()` wrapper forwards `$debug` to `admin_edit_user()`.
- `web/api.php:273-285` — `admin_user_edit` action reads `$_POST['debug']`.
- `docs/technical-specification.md`, `docs/architecture.md` — mention the flag; need updating.
- `CLAUDE.md` (project) — lists `debug` as a column of `auth.auth_accounts`; needs updating.
- `tests/Integration/AdminTest.php`, `tests/Integration/IntegrationTestCase.php` — fixtures and assertions referencing `debug`.

### Library — ~/Git/auth

- `src/admin.php:30,45,51,67` — `admin_listUsers()` SELECTs and returns `debug`.
- `src/admin.php:140,147,150-151` — `admin_edit_user($con, $id, $email, $rights, $disabled, $debug, $totp_reset)` writes `debug` in the UPDATE; signature takes `int $debug`.
- `src/auth.php:282,376` — login SELECT fetches `debug`, then sets `$_SESSION['debug']`.
- `src/log.php:64-72` — `logDebug()` helper, no-op when `$_SESSION['debug']` is falsy.
- `docs/conventions.md` — probably mentions the flag; check.
- `tests/Unit/AdminTest.php`, `tests/Unit/TotpAuthTest.php`, `tests/Unit/LogTest.php` — reference `debug`.
- No existing auth migration creates or alters the column (it predates the migration set; migrations 01-10 don't mention it). The DROP is a new forward-only migration.

### External consumers of the flag

- `grep logDebug` across `~/Git/` finds **zero** real call sites — only the definition in `auth/src/log.php`. Nobody actually writes debug-gated log entries.
- Other apps (`energie`, `zeiterfassung`, `chat`) don't read `$_SESSION['debug']` in their code (zeiterfassung's `debug` matches are unrelated local variables in `easy.php` / `buchen.php` / `config.example.php`).
- The Chrome library admin components (`\Erikr\Chrome\Admin\UsersTab`, `UserModals`) are generic over `extraColumns` / `extraFields` — they have no hard-coded knowledge of debug. Removing the wlmonitor entries is enough; no Chrome-library change required.

## Cross-repo scope

This spans two repos: **`~/Git/wlmonitor`** (UI + API + docs + tests) and **`~/Git/auth`** (library + migration + tests + docs). Per `~/Git/CLAUDE.md`, cross-repo work usually belongs at the ecosystem level; filed here because wlmonitor is the visible surface and the only consumer of the admin UI. Confirm before starting whether to keep this task in wlmonitor's backlog or promote it to a global task.

## Proposed changes

### 1. Drop the auth library support (do first)

- `~/Git/auth/src/admin.php`:
  - Remove `debug` from the SELECT in `admin_listUsers()` and from the returned row.
  - Remove `int $debug` parameter from `admin_edit_user()` signature; drop from the UPDATE and `bind_param` types/args.
  - Update the PHPDoc `@return` shape in the doc comment at line 30.
- `~/Git/auth/src/auth.php`: remove `debug` from the login SELECT (line 282) and the `$_SESSION['debug']` assignment (line 376).
- `~/Git/auth/src/log.php`: delete the `logDebug()` function (lines 64-72).
- `~/Git/auth/tests/Unit/*`: update assertions/fixtures that reference `debug`.
- `~/Git/auth/docs/conventions.md`: remove any mention of the flag.

### 2. Update wlmonitor consumers to match the new signature

- `web/admin.php`: remove the `debug` entry from `extraColumns` (line 142-149) and from `extraFields` (line 201-207); drop the inline JS prefill line at 245.
- `inc/admin.php`: delete the secondary SELECT that loads `debug` from auth_accounts (lines 46-55) and the merge (line 61); drop the `int $debug` parameter from `editUser()` and the corresponding arg in the `admin_edit_user` call (lines 79-82).
- `web/api.php`: stop reading `$_POST['debug']` (line 285) and stop passing it to `editUser()`.
- `docs/technical-specification.md`, `docs/architecture.md`, `CLAUDE.md`: scrub `debug` from the `auth.auth_accounts` column list.
- `tests/Integration/AdminTest.php`, `tests/Integration/IntegrationTestCase.php`: remove debug-related fixtures/assertions.

### 3. DB migration (do last, after the code is deployed OR shipped atomically)

- Add `~/Git/auth/db/11_drop_debug_column.sql`:
  ```sql
  USE auth;
  ALTER TABLE auth_accounts DROP COLUMN debug;
  ```
- Add header comment noting this is idempotent-by-hand: document how to check `information_schema.COLUMNS` before re-running.
- Run manually in each environment (local, akadbrain, world4you) after the app code has been deployed so no running request tries to SELECT/UPDATE a dropped column.

### 4. Grants

- `~/Git/mcp/scripts/grant-db-users.sql` — no explicit grant on the `debug` column; the wlmonitor DB user holds table-level SELECT/UPDATE. **No grant change needed.** Verify by reading the grants file.

## Acceptance Criteria
<!-- AC:BEGIN -->
- [x] #1 auth.auth_accounts.debug column dropped via a new sequential migration in ~/Git/auth/db/ with USE auth; header and idempotency note
- [x] #2 ~/Git/auth/src/admin.php: admin_listUsers() stops selecting/returning debug; admin_edit_user() signature drops the $debug parameter; UPDATE no longer writes debug
- [x] #3 ~/Git/auth/src/auth.php login SELECT and $_SESSION['debug'] assignment removed
- [x] #4 ~/Git/auth/src/log.php: logDebug() function removed
- [x] #5 wlmonitor admin users table no longer shows a Debug column
- [x] #6 wlmonitor create/edit-user modal no longer shows a Debug-Modus checkbox; related JS prefill removed
- [x] #7 wlmonitor inc/admin.php: editUser() signature drops $debug; listUsersWithExtras() no longer does the secondary SELECT on debug
- [x] #8 wlmonitor web/api.php admin_user_edit no longer reads $_POST['debug']

## Out of scope

- Any alternative debug/diagnostic mechanism. If a replacement is wanted, file a separate task — this one is deletion-only.
- Changes to Chrome library admin components (they're generic; nothing to change).
<!-- SECTION:DESCRIPTION:END -->

- [x] #9 docs updated: wlmonitor CLAUDE.md, docs/technical-specification.md, docs/architecture.md, ~/Git/auth/docs/conventions.md drop all mentions of the debug flag
- [x] #10 Tests pass: wlmonitor integration tests and ~/Git/auth unit tests (AdminTest, TotpAuthTest, LogTest)
- [x] #11 Deployment order documented: app code first, then the DROP COLUMN migration, to avoid running queries against a missing column
- [x] #12 grep -r 'auth_accounts.*debug|\$_SESSION\[.debug.\]|logDebug' across ~/Git returns no matches in consumer code after the change
<!-- AC:END -->
