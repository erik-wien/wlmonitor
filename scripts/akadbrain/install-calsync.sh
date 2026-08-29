#!/bin/bash
# scripts/akadbrain/install-calsync.sh
#
# Baut calsync.swift zu einem stabilen, ad-hoc signierten Kommandozeilen-
# Binary und installiert es als LaunchAgent (900s-Intervall). LAEUFT AUF
# AKADBRAIN, als der Nutzer "erik" -- EventKit/TCC braucht die eingeloggte
# GUI-Session, ein LaunchDaemon ohne Session bekaeme nie eine Freigabe.
#
# Fester Installationspfad (~/bin/wlmonitor-calsync), damit TCC die einmal
# erteilte Kalenderfreigabe an genau dieses Binary bindet -- ein Neubau an
# anderer Stelle wuerde die Freigabe verlieren.
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
BIN_DIR="$HOME/bin"
BIN_PATH="$BIN_DIR/wlmonitor-calsync"
CONFIG_DIR="$HOME/.config/wlmonitor"
CONFIG_PATH="$CONFIG_DIR/calsync.env"
LOG_DIR="$HOME/Library/Logs/wlmonitor"
AGENT_LABEL="cloud.eriks.wlmonitor.calsync"
AGENT_PLIST="$HOME/Library/LaunchAgents/$AGENT_LABEL.plist"

mkdir -p "$BIN_DIR" "$CONFIG_DIR" "$LOG_DIR"
chmod 700 "$CONFIG_DIR"

echo "==> Baue $BIN_PATH"
swiftc "$SCRIPT_DIR/calsync.swift" -o "$BIN_PATH" \
    -Xlinker -sectcreate -Xlinker __TEXT -Xlinker __info_plist -Xlinker "$SCRIPT_DIR/Info.plist"

# Ad-hoc-Signatur mit fester Kennung: TCC bindet die Kalenderfreigabe an
# Pfad+Identifier, nicht an ein Zertifikat -- ohne das wuerde jeder Neubau
# als "neues" Programm gelten und erneut nachfragen.
codesign -s - --force --identifier "$AGENT_LABEL" "$BIN_PATH"
echo "==> Signiert: $(codesign -dv "$BIN_PATH" 2>&1 | grep Identifier)"

if [ ! -f "$CONFIG_PATH" ]; then
    echo "==> Schreibe Konfigurationsvorlage $CONFIG_PATH (bitte CALSYNC_OUT pruefen!)"
    cat > "$CONFIG_PATH" <<'EOF'
# ~/.config/wlmonitor/calsync.env
# launchd-Agents erben keine Shell-Umgebung -- calsync liest diese Datei
# selbst. chmod 600, falls hier je ein Token noetig wird.

# Zielpfad fuer den Kalender-Cache: data/calendar/<userId>.json im
# ausgelieferten wlmonitor-Repo. <userId> ist die auth_accounts.id des
# Kontos, als das sich das E-Paper-Board per API-Token anmeldet -- NICHT
# Eriks persoenliche Account-ID. Nachsehen: SELECT id FROM auth_accounts
# WHERE username = 'Display'; (oder analog).
CALSYNC_OUT="/PFAD/ZUM/DEPLOY/data/calendar/USERID.json"

# Optional, Pipe-getrennt, ueberschreibt die eingebaute Standardliste:
# CALSYNC_CALENDARS="🔜 Eriks Termine|🅰️ Armins Termine|👨‍❤️‍👨 Gemeinsame Termine|Birthdays|Österreichische Feiertage"
EOF
    chmod 600 "$CONFIG_PATH"
else
    echo "==> Konfiguration existiert bereits, unveraendert: $CONFIG_PATH"
fi

echo "==> Installiere LaunchAgent $AGENT_PLIST"
sed \
    -e "s|__CALSYNC_BIN__|$BIN_PATH|" \
    -e "s|__LOG_DIR__|$LOG_DIR|" \
    "$SCRIPT_DIR/$AGENT_LABEL.plist" > "$AGENT_PLIST"

launchctl bootout "gui/$(id -u)/$AGENT_LABEL" 2>/dev/null || true
launchctl bootstrap "gui/$(id -u)" "$AGENT_PLIST"
echo "==> LaunchAgent geladen (alle 900s + sofort beim Laden)."

echo ""
echo "==> Naechster Schritt: einmal MANUELL im Terminal ausfuehren, damit macOS"
echo "    den Freigabe-Dialog anzeigt (ein Hintergrund-Start durch launchd allein"
echo "    zeigt oft keinen Dialog, sondern schlaegt nur mit EXIT_NOPERM (77) fehl):"
echo ""
echo "        $BIN_PATH --json"
echo ""
echo "    Danach in Systemeinstellungen > Datenschutz & Sicherheit > Kalender"
echo "    pruefen, dass 'wlmonitor-calsync' aktiviert ist. Log: $LOG_DIR/"
