# Prompt: Implement File Integrity Monitoring for WL Monitor

## Context

WL Monitor is a PHP web app at `/Users/erikr/Git/wlmonitor/`. The production server is a shared Apache/PHP host. The `web/` subdirectory is the document root; everything else (`include/`, `inc/`, `config/`, `vendor/`, etc.) is above or alongside the web root.

The goal is a lightweight File Integrity Monitor (FIM) that detects unauthorized changes to production code files, and sends a Slack report automatically.

## Architecture decisions already made

- **Git is the source of truth.** `checksums.json` is generated locally, committed to git, and pulled on production. It is never uploaded via HTTP — this avoids the chicken-and-egg problem of a tampered checksum file.
- **SHA-256** per file, stored as a JSON object `{ "relative/path": "hexhash", ... }`.
- **Scope:** All `.php`, `.js`, `.css` files under `web/`, `include/`, `inc/`. Exclude `vendor/` (covered by `composer.lock`), `config/` (environment-specific), `data/` (runtime state), generated or uploaded files.
- **`checksums.json` itself is in the checksum list** — handled by hashing all other files first, then adding its own hash last (or simply accepting that git protects it).
- **Slack notification** via incoming webhook URL stored in `config/db.json` under key `slack_webhook`.

## What to build

### 1. `scripts/generate_checksums.php`

CLI script (run locally, not via web). Usage: `php scripts/generate_checksums.php`

- Walks the file tree for the scoped directories above.
- Computes `hash_file('sha256', $path)` for each file.
- Outputs `checksums.json` at the project root with relative paths as keys (relative to project root, forward slashes, no leading slash).
- Prints a summary: `Generated checksums for N files → checksums.json`
- Must be runnable on both local dev and production.

### 2. `scripts/verify_integrity.php`

CLI script that runs on production (via cron or SSH trigger). Usage: `php scripts/verify_integrity.php`

- Reads `checksums.json` from project root (pulled from git).
- For each entry: hash the file, compare.
- Also scans scoped directories for files NOT in `checksums.json` (added files — possible webshell injection).
- Exit code 0 = all clean. Exit code 1 = mismatch found.
- Outputs structured results as JSON to stdout:
  ```json
  {
    "ok": false,
    "checked": 47,
    "modified": ["web/js/wl-monitor.js"],
    "missing": [],
    "extra": ["web/shell.php"],
    "generated_at": "2026-04-03T08:00:00+02:00"
  }
  ```

### 3. `scripts/notify_slack.php`

CLI script. Usage: `php scripts/notify_slack.php < verify_output.json`

- Reads the JSON from `verify_integrity.php` via stdin.
- Reads `slack_webhook` URL from `config/db.json`.
- Formats a Slack Block Kit message:
  - ✅ green if `ok: true`: `WL Monitor integrity OK — N files verified (timestamp)`
  - 🚨 red if `ok: false`: lists modified/missing/extra files, one per line.
- POSTs to the Slack webhook using `file_get_contents` with a stream context (no Guzzle needed).
- Exits 0 on success, 1 on HTTP error.

### 4. `config/db.json` — add Slack webhook key

Add to the JSON structure under both `local` and `production` sections:
```json
"slack_webhook": "https://hooks.slack.com/services/YOUR/WEBHOOK/URL"
```
Leave the local value empty string `""` so notify_slack.php skips sending when not configured.

### 5. Cron entry (document, don't install)

Document in `docs/architecture.md` (or a comment in `verify_integrity.php`) the recommended cron:
```
0 6 * * * cd /path/to/wlmonitor && git fetch --quiet && git checkout origin/main -- checksums.json 2>/dev/null; php scripts/verify_integrity.php | php scripts/notify_slack.php
```

This pulls only `checksums.json` from git before verifying, so the reference is always current.

### 6. Claude Code hook (document in `CLAUDE.md`)

Add to `CLAUDE.md`:
```
After any editing session that changes .php, .js, or .css files, run:
  php scripts/generate_checksums.php
and commit the updated checksums.json together with the code changes.
```

Optionally, add a `.claude/hooks/post_session` shell hook that runs this automatically.

## Security constraints

- `verify_integrity.php` and `generate_checksums.php` must NOT be web-accessible. The `scripts/` directory already has a `.htaccess` denying access.
- The Slack webhook URL must never be committed in plaintext — it lives in `config/db.json` which is outside the web root and git-ignored on production.
- Do not store any credentials in the scripts themselves.

## Files to create/modify

| File | Action |
|------|--------|
| `scripts/generate_checksums.php` | Create |
| `scripts/verify_integrity.php` | Create |
| `scripts/notify_slack.php` | Create |
| `checksums.json` | Create (generated, committed) |
| `config/db.json` | Add `slack_webhook` key to all sections |
| `CLAUDE.md` | Document checksum regeneration step |

## Testing

After implementing, verify locally:
```bash
php scripts/generate_checksums.php
php scripts/verify_integrity.php
# Should print {"ok":true,...} and exit 0

# Tamper test:
echo "// tampered" >> web/js/wl-monitor.js
php scripts/verify_integrity.php
# Should print {"ok":false,"modified":["web/js/wl-monitor.js"],...} and exit 1

git checkout web/js/wl-monitor.js   # restore
```
