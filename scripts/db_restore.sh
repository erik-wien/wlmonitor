#!/usr/bin/env bash
# scripts/db_restore.sh
#
# Restores the wlmonitor database from a backup file created by db_backup.sh.
# Reads credentials from config/db.json (APP_ENV=local by default).
#
# Usage:  bash scripts/db_restore.sh <backup_file> [local|production]
#
# Example:
#   bash scripts/db_restore.sh backups/wlmonitor_local_20260403_120000.sql.gz

set -euo pipefail

REPO_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
BACKUP_FILE="${1:-}"
ENV="${2:-local}"
CONFIG="$REPO_DIR/config/db.json"

# ── Validate input ────────────────────────────────────────────────────────────

if [[ -z "$BACKUP_FILE" ]]; then
    echo "Usage: bash scripts/db_restore.sh <backup_file> [local|production]"
    echo
    echo "Available backups:"
    ls -lh "$REPO_DIR/backups/"*.sql.gz 2>/dev/null || echo "  (none found in backups/)"
    exit 1
fi

[[ -f "$BACKUP_FILE" ]] || { echo "ERROR: File not found: $BACKUP_FILE" >&2; exit 1; }

# ── Parse credentials ─────────────────────────────────────────────────────────

if ! command -v python3 &>/dev/null; then
    echo "ERROR: python3 required to parse db.json" >&2; exit 1
fi

read -r DB_HOST DB_USER DB_PASS DB_NAME <<< "$(python3 -c "
import json, sys
cfg = json.load(open('$CONFIG'))
db  = cfg.get('$ENV', cfg['local'])
print(db['host'], db['user'], db['pass'], db['name'])
")"

# ── Confirm ───────────────────────────────────────────────────────────────────

echo
echo "  Restore: $BACKUP_FILE"
echo "  Target:  $DB_NAME @ $DB_HOST ($ENV)"
echo
printf "  This will OVERWRITE all data in '$DB_NAME'. Continue? [yes/no]: "
read -r CONFIRM
[[ "$CONFIRM" == "yes" ]] || { echo "Aborted."; exit 0; }

# ── Restore ───────────────────────────────────────────────────────────────────

echo "Restoring ..."

if [[ "$BACKUP_FILE" == *.gz ]]; then
    gunzip -c "$BACKUP_FILE" | mysql \
        --host="$DB_HOST" \
        --user="$DB_USER" \
        --password="$DB_PASS" \
        "$DB_NAME"
else
    mysql \
        --host="$DB_HOST" \
        --user="$DB_USER" \
        --password="$DB_PASS" \
        "$DB_NAME" < "$BACKUP_FILE"
fi

echo "✓ Restore complete."
