# Wiener Linien Realtime API (V1.4)

Distilled from `docs/wienerlinien-echtzeitdaten-dokumentation.pdf` (updated 2026-02-09).

Base URL: `http://www.wienerlinien.at/ogd_realtime/`

All endpoints are GET-only. Headers: `Accept: application/json`, `Content-Type: application/json`.

## monitor — Departure Monitor

```
monitor?stopId=<int>&diva=<int>&activateTrafficInfo=<type>&aArea=<0|1>
```

| Param | Req | Description |
|-------|-----|-------------|
| `stopId` | y | Haltepunkt ID (repeatable: `stopId=123&stopId=124`). Legacy alias: `rbl` |
| `diva` | n | DIVA number — returns departures for all Haltepunkte sharing this DIVA |
| `activateTrafficInfo` | n | Repeatable. Values: `stoerunglang` (default), `stoerungkurz` (default), `aufzugsinfo` (default), `fahrtreppeninfo`, `information` |
| `aArea` | n | `1` = also query all stops sharing the same DIVA as the given stopId |

### Response structure (`data.monitors[]`)

Each monitor element contains:
- `locationStop` — GeoJSON Feature with `geometry.coordinates` (lon, lat WGS84) and `properties` (`name` = DIVA number, `title` = station name, `attributes.rbl` = Haltepunkt ID)
- `lines[]` — each line at this stop:
  - `name` (e.g. "13A"), `towards`, `direction` ("H"/"R"), `richtungsId`, `platform`
  - `type` — vehicle type string used for CSS badge classes: `ptTram`, `ptMetro`, `ptBusCity`, `ptBusNight`, `ptBusRegion`, `ptTrain`, `ptTrainS`, `ptTramWLB`
  - `lineId`, `barrierFree`, `realtimeSupported`, `trafficjam`
  - `departures.departure[]`:
    - `departureTime.timePlanned` (ISO 8601), `departureTime.timeReal` (optional), `departureTime.countdown` (minutes)
    - `vehicle` (optional, only when departure differs from line defaults) — same fields as line plus `foldingRamp`, `linienId`
- `refTrafficInfoNames[]` — links to `trafficInfos[].name`

### Traffic info (when `activateTrafficInfo` is set)

- `trafficInfos[]` — `name`, `title`, `description`, `descriptionHTML`, `priority`, `owner`, `status` ("active"/"resolved"), `time` (`start`, `end`, `resume`, `created`, `lastupdate`), `location`, `pictures[]`, `relatedLines[]`, `relatedLinesDetails[]`, `relatedStops[]`
- `trafficInfos[].attributes` — elevator-specific: `reason`, `station`, `location`, `towards`, `status`, `relatedLines[]`, `relatedStops[]`
- `trafficInfoCategories[]` — `id`, `name` (stoerunglang/stoerungkurz/aufzugsinfo), `title`, `trafficInfoNameList`
- `trafficInfoCategoryGroups[]` — always `{"id": 1, "name": "pt"}`

## trafficInfoList — Disruptions & Elevator Outages

```
trafficInfoList?relatedLine=<name>&relatedStop=<int>&name=<category>
```

All params optional and repeatable. `name` values: `stoerunglang`, `stoerungkurz`, `aufzugsinfo`, `fahrtreppeninfo`. Default: first three. Response structure same as traffic info section above.

## trafficInfo — Single Disruption by Name

```
trafficInfo?name=<trafficInfoName>
```

Required. Repeatable. Response: same as trafficInfoList.

## newsList — News & Elevator Maintenance

```
newsList?relatedLine=<name>&relatedStop=<int>&name=<category>
```

All params optional and repeatable. Categories: `news`, `aufzugsservice`.

Response (`data.pois[]`): `refPoiCategoryId`, `name`, `title`, `subtitle`, `description`, `time` (`start` = display start, `validfrom` = validity start, `end`), `relatedLines`, `relatedStops`, `attributes` (elevator maintenance: `station`, `location`, `status`, `towards`, `ausVon`, `ausBis`, `rbls[]`).

## newsInfo — Single News by Name

```
newsInfo?name=<newsName>
```

Required. Repeatable. Response: same as newsList.

## Error Codes (all endpoints)

| Code | Meaning |
|------|---------|
| 311 | DB unavailable |
| 312 | Haltepunkt does not exist (monitor only) |
| 316 | Query rate limit reached |
| 320 | Invalid GET parameter |
| 321 | Missing GET parameter |
| 322 | No data in DB |

Error responses: `message.value`, `message.messageCode`, `message.serverTime`.

## Key Terminology

- **Haltepunkt** (stopId/rbl) — a single boarding point (one platform, one direction). Numeric ID stored in `ogd_steige`.
- **DIVA** — groups multiple Haltepunkte into one logical station. 8-digit identifier from `ogd_haltestellen.DIVA`. A single physical station may have dozens of Haltepunkte.
- **`type` values** map directly to CSS badge classes in `wl-monitor.css`: `ptTram` → `.pt-tram`, `ptMetro` → `.pt-metro`, `ptBusCity` → `.pt-bus-city`, etc.
- **countdown** — minutes until departure, computed server-side. Use this for display; `timeReal`/`timePlanned` for sorting or absolute times.
- **Important API behaviour:** The WL API returns one monitor entry per line (not per station), and entries for different stations are interleaved. It also silently omits stops with no upcoming departures.
