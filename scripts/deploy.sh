#!/usr/bin/env bash
# scripts/deploy.sh
#
# Deploys WL Monitor to a local test vhost or to the production server.
# Run from anywhere:  bash scripts/deploy.sh

set -euo pipefail

# ── Configuration ────────────────────────────────────────────────────────────

LOCAL_DEST="/Library/WebServer/Documents/wlmonitor"

# Fill these in before using production deploy:
PROD_SSH_USER=""          # e.g.  user12345
PROD_SSH_HOST=""          # e.g.  ssh.jardyx.com  or  jardyx.com
PROD_REMOTE_PATH="/home/.sites/765/site679/web/jardyx.com/wlmonitor/"

# ── Helpers ──────────────────────────────────────────────────────────────────

REPO_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"

info()  { printf '\033[0;36m%s\033[0m\n' "$*"; }
ok()    { printf '\033[0;32m✓ %s\033[0m\n' "$*"; }
err()   { printf '\033[0;31mERROR: %s\033[0m\n' "$*" >&2; exit 1; }
ask()   { printf '\033[0;33m%s\033[0m ' "$*"; }

# ── Prompt ───────────────────────────────────────────────────────────────────

echo
echo "  WL Monitor — Deploy"
echo "  ─────────────────────────────────────────"
echo "  l) Local test    →  $LOCAL_DEST"
if [[ -n "$PROD_SSH_USER" && -n "$PROD_SSH_HOST" ]]; then
    echo "  p) Production    →  ${PROD_SSH_USER}@${PROD_SSH_HOST}:${PROD_REMOTE_PATH}"
else
    echo "  p) Production    →  (configure PROD_SSH_USER / PROD_SSH_HOST first)"
fi
echo

ask "Choice [l/p]:"
read -r CHOICE

case "$CHOICE" in
    l|L) MODE="local"      ;;
    p|P) MODE="production" ;;
    *)   err "Unknown choice '${CHOICE}'. Aborted." ;;
esac

# ── Validate production config ────────────────────────────────────────────────

if [[ "$MODE" == "production" ]]; then
    [[ -z "$PROD_SSH_USER" ]] && err "PROD_SSH_USER is not set. Edit this script first."
    [[ -z "$PROD_SSH_HOST" ]] && err "PROD_SSH_HOST is not set. Edit this script first."
fi

# ── rsync options shared by both targets ─────────────────────────────────────
#
# Excluded from the destination:
#   config/    — credentials live on the server; never overwrite
#   data/      — runtime files (ratelimit.json, ogd_update.lock)
#   tests/     — not needed at runtime
#   deprecated/— archived old code
#   docs/      — planning docs
#   img/       — top-level img/ (assets are under web/img/)
#   *.md, CLAUDE.md — documentation
#   phpunit.xml, composer.{json,lock} — build tooling

RSYNC_OPTS=(
    --archive
    --verbose
    --delete
    --delete-excluded
    --copy-links
    --exclude=".git/"
    --exclude=".gitignore"
    --exclude=".DS_Store"
    --exclude=".claude/"
    --exclude=".phpunit.result.cache"
    --exclude="CLAUDE.md"
    --exclude="*.md"
    --exclude="phpunit.xml"
    --exclude="composer.json"
    --exclude="composer.lock"
    --exclude="tests/"
    --exclude="deprecated/"
    --exclude="docs/"
    --exclude="/img/"
    --exclude="config/"
    --exclude="data/"
)

# ── Local deploy ─────────────────────────────────────────────────────────────

if [[ "$MODE" == "local" ]]; then
    info "Syncing to $LOCAL_DEST ..."
    mkdir -p "$LOCAL_DEST"
    rsync "${RSYNC_OPTS[@]}" "$REPO_DIR/" "$LOCAL_DEST/"

    # Ensure runtime directories exist (rsync won't create excluded dirs)
    mkdir -p "$LOCAL_DEST/data"

    # Write the local-production db.json (wlmonitor DB, local credentials).
    # This is distinct from the repo's db.json which points to wlmonitor_dev.
    mkdir -p "$LOCAL_DEST/config"
    cat > "$LOCAL_DEST/config/db.json" << 'DBJSON'
{
  "local": {
    "host": "localhost",
    "user": "wlmonitor",
    "pass": "sopdi9-nyKnyb-zyqpyh",
    "name": "wlmonitor",
    "auth_name": "jardyx_auth",
    "base_url": "http://localhost/wlmonitor"
  },
  "production": {
    "host": "mysqlsvr78.world4you.com",
    "user": "sql6675098",
    "pass": "dr@3ysr",
    "name": "5279249db19",
    "auth_name": "jardyx_auth",
    "base_url": "https://www.jardyx.com/wl-monitor"
  },
  "smtp_local": {
    "host": "smtp.world4you.com",
    "port": 587,
    "user": "catchall@jardyx.com",
    "pass": "rtuk4cy5gu",
    "from": "wlmonitor@jardyx.com",
    "from_name": "WL Monitor"
  },
  "smtp_production": {
    "host": "smtp.world4you.com",
    "port": 587,
    "user": "catchall@jardyx.com",
    "pass": "rtuk4cy5gu",
    "from": "wlmonitor@jardyx.com",
    "from_name": "WL Monitor"
  }
}
DBJSON
    ok "  config/db.json written (wlmonitor DB)"

    # For local, APP_ENV defaults to 'local' in initialize.php (no .htaccess needed).
    # Remove a stale production .htaccess if present.
    if [[ -f "$LOCAL_DEST/web/.htaccess" ]]; then
        rm "$LOCAL_DEST/web/.htaccess"
    fi

    echo
    ok "Local deploy complete."
    info "  Target:   $LOCAL_DEST"
    info "  APP_ENV:  local (default — no .htaccess written)"

# ── Production deploy ─────────────────────────────────────────────────────────

elif [[ "$MODE" == "production" ]]; then
    echo
    ask "Deploy to PRODUCTION at ${PROD_SSH_USER}@${PROD_SSH_HOST}? This is the live site. [yes/no]:"
    read -r CONFIRM
    [[ "$CONFIRM" != "yes" ]] && { info "Aborted."; exit 0; }

    info "Syncing to ${PROD_SSH_USER}@${PROD_SSH_HOST}:${PROD_REMOTE_PATH} ..."
    rsync "${RSYNC_OPTS[@]}" \
        -e "ssh" \
        "$REPO_DIR/" \
        "${PROD_SSH_USER}@${PROD_SSH_HOST}:${PROD_REMOTE_PATH}"

    # Write .htaccess on the remote to set APP_ENV=production.
    # Requires AllowOverride All (or at least FileInfo) in the vhost config.
    ssh "${PROD_SSH_USER}@${PROD_SSH_HOST}" \
        "printf 'SetEnv APP_ENV production\n' > '${PROD_REMOTE_PATH}web/.htaccess'"

    # Ensure runtime directories exist on remote
    ssh "${PROD_SSH_USER}@${PROD_SSH_HOST}" \
        "mkdir -p '${PROD_REMOTE_PATH}data'"

    echo
    ok "Production deploy complete."
    info "  Target:   ${PROD_SSH_USER}@${PROD_SSH_HOST}:${PROD_REMOTE_PATH}"
    info "  APP_ENV:  production (written to web/.htaccess)"
    info
    info "  Reminder: run the DB migration if this is a first deploy:"
    info "    php ${PROD_REMOTE_PATH}scripts/migrate_diva.php"
fi
