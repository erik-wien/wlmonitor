# Umstellung auf den Board-Endpunkt — Ablauf beim Deploy

**Stand:** 2026-08-02
**Betrifft:** `web/board.php` (neu), `web/monitor_json.php` (jetzt token-pflichtig),
`erikr/auth` (keine Session bei Token-Anfragen)

## Der Punkt, an dem etwas kaputtgeht

`monitor_json.php` war bisher **ohne Authentifizierung** erreichbar. Ab dem
Deploy verlangt er ein Token. Home Assistant hört also **in dem Moment auf zu
funktionieren, in dem deployt wird** — nicht später, nicht schleichend.

Deshalb ist die Reihenfolge nicht beliebig:

| # | Schritt | Wo |
|---|---|---|
| 1 | API-Token anlegen | `profil.php` → Abschnitt „API-Token", auf **derselben Instanz**, die HA abfragt |
| 2 | Token in Home Assistant eintragen | `secrets.yaml` + `headers:` (siehe unten) |
| 3 | Deployen | `mcp/deploy.py wlmonitor <ziel>` |
| 4 | Prüfen | HA-Sensor aktualisiert sich weiterhin |

Schritt 1 und 2 **vor** Schritt 3. Ein Token, das auf akadbrain ausgestellt
wurde, gilt nicht auf world4you — die Instanzen haben getrennte Datenbanken.

## Home Assistant

```yaml
# secrets.yaml
wlmonitor_token: "Bearer <token>"

# configuration.yaml
rest:
  - resource: https://wlmonitor.jardyx.com/monitor_json.php?diva=60201015
    headers:
      Authorization: !secret wlmonitor_token
```

Die **Antwortform ist unverändert** — bestehende Templates bleiben gültig. Nur
die Anfrage braucht den Header. Falls `Authorization` unterwegs verschluckt
wird (manche Proxy-/FPM-Setups tun das), akzeptiert der Endpunkt auch
`X-Auth-Token: <token>` ohne das `Bearer `-Präfix.

Eine Änderung, die die Form nicht bricht, aber auffallen kann: die **Anzahl**
der Abfahrten je Linie kommt jetzt aus den Einstellungen des Token-Benutzers
(`wl_preferences.departures`) statt aus der Sitzung des Aufrufers. Vorher hing
sie davon ab, wer zuletzt im Browser eingeloggt war — sie war also nie
verlässlich.

## Neuer Endpunkt für Geräte

```
GET /board.php?fav=<id>[,<id>…]
Authorization: Bearer <token>
```

Favoriten-IDs stehen in der URL von `editFavorite.php?id=…`. Ohne `fav`:
alle Favoriten des Token-Benutzers. Fremde IDs werden still übergangen.

Antwortform: `docs/superpowers/specs/2026-08-01-epaper-abfahrtsmonitor-design.md` §4.

## Prüfen nach dem Deploy

```bash
# 1) Ohne Token: 401, aber MIT Set-Cookie (der Web-Pfad ist unverändert)
curl -s -o /dev/null -w '%{http_code}\n' https://<host>/board.php

# 2) Mit beliebigem Token-Header: 401 und KEIN Set-Cookie.
#    Das belegt, dass eine Token-Anfrage keine Session mehr anlegt.
curl -sI -H 'Authorization: Bearer ungueltig' https://<host>/board.php | grep -i set-cookie

# 3) Mit echtem Token: 200 und JSON
curl -s -H "Authorization: Bearer $TOKEN" 'https://<host>/board.php?fav=<id>'
```

Prüfung 2 ist die aussagekräftigste, weil sie **kein** gültiges Token braucht:
die Session-Freiheit hängt am Vorhandensein des Headers, nicht an seiner
Gültigkeit. Kommt dort ein `Set-Cookie`, ist die `erikr/auth`-Änderung nicht
mitdeployt.

## Sessions

Vor dieser Umstellung legte **jeder** anonyme Aufruf von `monitor_json.php`
eine PHP-Session mit vier Tagen Lebensdauer an (nachgemessen: 875 → 876
Sessiondateien durch einen einzigen `curl`). Ein Gerät im Zwei-Minuten-Takt
hätte 720 Dateien pro Tag erzeugt.

Ursache war nicht der Endpunkt, sondern `auth_bootstrap()`: es rief
`session_start()` bedingungslos und **bevor** es das Token überhaupt ansah.
Die Korrektur liegt deshalb in `erikr/auth` und wirkt in allen sieben Apps.
**Beide Repos müssen zusammen deployt werden** — `wlmonitor` allein würde
weiterhin Sessions anlegen.

## Token verlieren oder tauschen

Widerrufen in `profil.php` (Abschnitt „API-Token"), dann ein neues ausstellen
und in HA bzw. `firmware/config.h` eintragen. Gespeichert wird nur der
SHA-256-Hash; ein verlorenes Token lässt sich nicht wieder anzeigen, nur
ersetzen.

Ein Token gehört nie in ein Repository, in ein Ticket oder in einen Chat — es
läuft nicht ab und gilt, bis es widerrufen wird.
