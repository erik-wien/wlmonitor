#!/usr/bin/env python3
"""scripts/akadbrain/mqtt_subscriber.py

Persistenter MQTT-Subscriber fuer die Board-Seite (TASK-26). LAEUFT AUF
AKADBRAIN, als Dauerlaufprozess (launchd KeepAlive), NICHT als periodischer
Cron/Agent -- MQTT ist push/pub-sub, ein periodischer Poll wuerde Nachrichten
zwischen zwei Laeufen verpassen, anders als die WL-API oder der
Kalender-Read (calsync.swift), die beide Pull sind.

Abonniert ein festes Topic-Prefix und schreibt jede empfangene Nachricht
atomar (tmp+rename) in einen Ringpuffer-Cache -- gleiches Grundmuster wie
scripts/weather_fetch_cron.php und scripts/akadbrain/calsync.swift: eine
externe Quelle wird in einen Cache gelegt, den web/board.php nur noch liest.

Konfiguration ausschliesslich aus ~/.config/wlmonitor/mqtt.env (launchd-
Agents erben keine Shell-Umgebung, dasselbe Muster wie calsync.swift).
"""
from __future__ import annotations

import json
import os
import sys
import tempfile
import time
from datetime import datetime, timezone
from pathlib import Path

import paho.mqtt.client as mqtt

CONFIG_PATH = Path.home() / ".config" / "wlmonitor" / "mqtt.env"
SCHEMA = 1
DEFAULT_MAX_MESSAGES = 20


def load_config(path: Path) -> dict[str, str]:
    config: dict[str, str] = {}
    if not path.exists():
        return config
    for line in path.read_text(encoding="utf-8").splitlines():
        line = line.strip()
        if not line or line.startswith("#") or "=" not in line:
            continue
        key, _, value = line.partition("=")
        value = value.strip()
        if len(value) >= 2 and value[0] == value[-1] == '"':
            value = value[1:-1]
        config[key.strip()] = value
    return config


def read_messages(path: Path) -> list[dict]:
    """Aktuellen Cache-Inhalt lesen.

    WICHTIG: Der Subscriber haelt die Liste NICHT im Speicher, sondern liest
    sie vor jedem Schreiben neu. Sonst wuerde eine serverseitige Loeschung
    (Lösch-X auf einer Karte, web/board.php) beim naechsten eintreffenden
    Telegramm aus dem Speicherstand wieder auferstehen -- die Nachricht waere
    scheinbar geloescht und kaeme dann von selbst zurueck.
    """
    try:
        data = json.loads(path.read_text(encoding="utf-8"))
    except (OSError, ValueError):
        return []
    messages = data.get("messages")
    return messages if isinstance(messages, list) else []


def atomic_write(path: Path, payload: dict) -> None:
    path.parent.mkdir(parents=True, exist_ok=True)
    fd, tmp_name = tempfile.mkstemp(dir=str(path.parent), prefix=path.name + ".")
    try:
        with os.fdopen(fd, "w", encoding="utf-8") as fh:
            json.dump(payload, fh, ensure_ascii=False, sort_keys=True)
        os.replace(tmp_name, path)
    except BaseException:
        try:
            os.unlink(tmp_name)
        except OSError:
            pass
        raise


def main() -> int:
    config = load_config(CONFIG_PATH)

    host = config.get("MQTT_HOST", "127.0.0.1")
    port = int(config.get("MQTT_PORT", "1883"))
    user = config.get("MQTT_USER")
    password = config.get("MQTT_PASSWORD")
    topic = config.get("MQTT_TOPIC", "wlmonitor/board/#")
    out_path_raw = config.get("MQTT_OUT")
    max_messages = int(config.get("MQTT_MAX_MESSAGES", str(DEFAULT_MAX_MESSAGES)))

    if not out_path_raw:
        print(f"mqtt_subscriber: MQTT_OUT fehlt in {CONFIG_PATH}", file=sys.stderr)
        return 64  # EX_USAGE

    out_path = Path(out_path_raw)
    # Neueste Nachricht zuerst (board_mqtt.php liest die Liste unveraendert
    # in dieser Reihenfolge) -- ein Ringpuffer nach ANZAHL, keine Zeit-TTL
    # (Nutzerentscheidung: einfache Rotation reicht fuer manuell/aus
    # Skripten publizierte Nachrichten ohne festen Takt).

    def on_connect(client, userdata, flags, reason_code, properties=None):
        if reason_code == 0:
            print(f"mqtt_subscriber: verbunden mit {host}:{port}, abonniere {topic}")
            client.subscribe(topic)
        else:
            print(f"mqtt_subscriber: Verbindung fehlgeschlagen: {reason_code}", file=sys.stderr)

    def on_message(client, userdata, msg):
        entry = {
            "topic": msg.topic,
            "payload": msg.payload.decode("utf-8", errors="replace"),
            "received_at": datetime.now(timezone.utc).astimezone().isoformat(),
        }
        # Read-modify-write statt Speicherstand -- s. read_messages().
        messages = read_messages(out_path)
        messages.insert(0, entry)
        del messages[max_messages:]
        try:
            atomic_write(out_path, {"schema": SCHEMA, "messages": messages})
        except OSError as exc:
            print(f"mqtt_subscriber: Cache nicht schreibbar ({out_path}): {exc}", file=sys.stderr)

    def on_disconnect(client, userdata, flags, reason_code, properties=None):
        print(f"mqtt_subscriber: Verbindung getrennt (reason={reason_code}), reconnect via loop_forever()")

    client = mqtt.Client(mqtt.CallbackAPIVersion.VERSION2)
    if user:
        client.username_pw_set(user, password)
    client.on_connect = on_connect
    client.on_message = on_message
    client.on_disconnect = on_disconnect
    # Reconnect-Backoff: 1s Start, bis zu 30s -- KeepAlive im LaunchAgent faengt
    # nur einen Prozessabsturz ab, nicht einen abgerissenen TCP-Connect.
    client.reconnect_delay_set(min_delay=1, max_delay=30)

    while True:
        try:
            client.connect(host, port, keepalive=60)
            client.loop_forever()
        except (OSError, ConnectionRefusedError) as exc:
            print(f"mqtt_subscriber: Verbindungsfehler ({exc}), erneuter Versuch in 5s", file=sys.stderr)
            time.sleep(5)


if __name__ == "__main__":
    raise SystemExit(main())
