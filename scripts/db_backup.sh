#!/usr/bin/env bash
# scripts/db_backup.sh
#
# Dumps the wlmonitor database to backups/<timestamp>.sql.gz
# Reads credentials from config/db.json (APP_ENV=local by default).
#
# Usage:  bash scripts/db_backup.sh [local|production]

set -euo pipefail

REPO_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
ENV="${1:-local}"
CONFIG="$REPO_DIR/config/db.json"
BACKUP_DIR="$REPO_DIR/backups"

# ── Parse credentials from db.json ───────────────────────────────────────────

if ! command -v python3 &>/dev/null; then
    echo "ERROR: python3 required to parse db.json" >&2; exit 1
fi

read -r DB_HOST DB_USER DB_PASS DB_NAME <<< "$(python3 -c "
import json, sys
cfg = json.load(open('$CONFIG'))
db  = cfg.get('$ENV', cfg['local'])
print(db['host'], db['user'], db['pass'], db['name'])
")"

# ── Dump ──────────────────────────────────────────────────────────────────────

mkdir -p "$BACKUP_DIR"
TIMESTAMP="$(date +%Y%m%d_%H%M%S)"
OUTFILE="$BACKUP_DIR/${DB_NAME}_${ENV}_${TIMESTAMP}.sql.gz"

echo "Backing up '$DB_NAME' ($ENV) → $OUTFILE ..."

mysqldump \
    --host="$DB_HOST" \
    --user="$DB_USER" \
    --password="$DB_PASS" \
    --single-transaction \
    --routines \
    --triggers \
    "$DB_NAME" | gzip > "$OUTFILE"

SIZE="$(du -sh "$OUTFILE" | cut -f1)"
echo "✓ Done. $SIZE written."
