#!/bin/bash
# scripts/akadbrain/install-mqtt-subscriber.sh
#
# Installiert den persistenten MQTT-Subscriber (TASK-26) als LaunchAgent mit
# KeepAlive. LAEUFT AUF AKADBRAIN, als der Nutzer "erik". Reines Netzwerk-
# I/O -- anders als scripts/akadbrain/install-calsync.sh keine GUI-Session/
# TCC-Freigabe noetig.
#
# Voraussetzung: ~/.venvs/wlmonitor-mqtt existiert mit paho-mqtt installiert
# (python3.11 -m venv ~/.venvs/wlmonitor-mqtt && pip install paho-mqtt).
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
VENV_PYTHON="$HOME/.venvs/wlmonitor-mqtt/bin/python"
INSTALL_DIR="$HOME/bin"
SCRIPT_DEST="$INSTALL_DIR/wlmonitor-mqtt-subscriber.py"
CONFIG_DIR="$HOME/.config/wlmonitor"
CONFIG_PATH="$CONFIG_DIR/mqtt.env"
LOG_DIR="$HOME/Library/Logs/wlmonitor"
AGENT_LABEL="cloud.eriks.wlmonitor.mqtt-subscriber"
AGENT_PLIST="$HOME/Library/LaunchAgents/$AGENT_LABEL.plist"

if [ ! -x "$VENV_PYTHON" ]; then
    echo "FEHLER: $VENV_PYTHON fehlt. Erst anlegen:" >&2
    echo "  /opt/homebrew/bin/python3.11 -m venv ~/.venvs/wlmonitor-mqtt" >&2
    echo "  ~/.venvs/wlmonitor-mqtt/bin/pip install paho-mqtt" >&2
    exit 1
fi

mkdir -p "$INSTALL_DIR" "$CONFIG_DIR" "$LOG_DIR"
chmod 700 "$CONFIG_DIR"

echo "==> Installiere $SCRIPT_DEST"
cp "$SCRIPT_DIR/mqtt_subscriber.py" "$SCRIPT_DEST"
chmod +x "$SCRIPT_DEST"

if [ ! -f "$CONFIG_PATH" ]; then
    echo "==> Schreibe Konfigurationsvorlage $CONFIG_PATH (bitte MQTT_PASSWORD/MQTT_OUT pruefen!)"
    cat > "$CONFIG_PATH" <<'EOF'
# ~/.config/wlmonitor/mqtt.env
# launchd-Agents erben keine Shell-Umgebung -- der Subscriber liest diese
# Datei selbst. chmod 600, enthaelt das MQTT-Passwort im Klartext.

MQTT_HOST="127.0.0.1"
MQTT_PORT="1883"
MQTT_USER="wlmonitor"
MQTT_PASSWORD="AENDERN"
MQTT_TOPIC="wlmonitor/board/#"

# Zielpfad fuer den Nachrichten-Cache: data/mqtt_cache.json im ausgelieferten
# wlmonitor-Repo (Geschwisterordner von web/, wie bei data/calendar/).
MQTT_OUT="/Library/WebServer/Documents/wlmonitor/data/mqtt_cache.json"

# Ringpuffergroesse (Anzahl Nachrichten, keine Zeit-TTL).
MQTT_MAX_MESSAGES="20"
EOF
    chmod 600 "$CONFIG_PATH"
else
    echo "==> Konfiguration existiert bereits, unveraendert: $CONFIG_PATH"
fi

echo "==> Installiere LaunchAgent $AGENT_PLIST"
sed \
    -e "s|__VENV_PYTHON__|$VENV_PYTHON|" \
    -e "s|__SCRIPT_PATH__|$SCRIPT_DEST|" \
    -e "s|__LOG_DIR__|$LOG_DIR|" \
    "$SCRIPT_DIR/$AGENT_LABEL.plist" > "$AGENT_PLIST"

launchctl bootout "gui/$(id -u)/$AGENT_LABEL" 2>/dev/null || true
launchctl bootstrap "gui/$(id -u)" "$AGENT_PLIST"
echo "==> LaunchAgent geladen (Dauerlauf, KeepAlive)."
echo "    Log: $LOG_DIR/mqtt-subscriber.log / .error.log"
