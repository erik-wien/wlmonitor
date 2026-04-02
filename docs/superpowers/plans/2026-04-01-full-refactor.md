# WL Monitor Full Refactor Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Reorganise WL Monitor into `inc/` modules behind a single `api.php` dispatcher, replace ajaxCRUD with custom admin code, harden security (CSRF, session flags, rate limiting, bcrypt 13), fix the broken search function, and modernise the UI to Bootstrap 5 with vanilla JS and CSS-variable theming.

**Architecture:** All dynamic data flows through `web/api.php` (JSON) or `web/index.php` (page shell). Business logic lives in `inc/` modules (`auth`, `monitor`, `favorites`, `stations`, `admin`). The JS layer is a single vanilla ES module that calls `api.php` with `fetch()`.

**Tech Stack:** PHP 8.2 + MySQLi, Bootstrap 5, vanilla JS (ES modules), Font Awesome 5, CSS custom properties. No build pipeline.

---

## File Map

**Create (new):**
- `inc/auth.php` — login, logout, register, password reset, rate limiting
- `inc/monitor.php` — Wiener Linien API fetch → structured array
- `inc/stations.php` — station list by distance or alpha from DB
- `inc/favorites.php` — CRUD for `wl_favorites`
- `inc/admin.php` — user management queries
- `include/csrf.php` — `csrf_token()` / `csrf_verify()` helpers
- `web/api.php` — single JSON dispatcher (routes `?action=` to inc/ modules)
- `web/admin.php` — admin HTML page (Bootstrap 5 table + modals)
- `web/css/theme.css` — CSS custom properties (replaces CSS file swapping)
- `data/` directory (outside web root) — rate limit state

**Modify:**
- `include/initialize.php` — add session cookie hardening flags; rename `sanitizeRblInput` to `sanitizeDivaInput`
- `include/html_header.php` — Bootstrap 5, theme.css, remove jQuery/Popper CDN refs
- `web/index.php` — reduce to ~80-line shell; add `<script type="module">` boot
- `web/js/wl-monitor.js` — full rewrite: vanilla JS ES module, `fetch()` API calls
- `web/authentication.php` — wire CSRF check; delegate to `inc/auth.php`
- `web/logout.php` — delegate to `inc/auth.php`
- `web/editFavorite.php` — Bootstrap 5 form; add CSRF token

**Move to `web/deprecated/`:**
- `web/monitor.php` (HTML output path, replaced by index.php + fetch)
- `web/monitor_json.php` (absorbed into api.php?action=monitor)
- `web/monitor_json - Kopieren.php` (backup file)
- `web/debug.php` (publicly exposed debug dump)
- `web/watch.php` (no active callers)
- `web/ajaxFav.php` (replaced by web/admin.php)
- `web/getFavorites.php` → api.php?action=favorites
- `web/addFavorite.php` → api.php?action=favorites_add
- `web/deleteFavorite.php` → api.php?action=favorites_delete
- `web/checkFavorite.php` → api.php?action=favorites_check
- `web/saveFavorites.php` → api.php?action=favorites_sort
- `web/getStations.php` → api.php?action=stations
- `web/savePosition.php` → api.php?action=position_save
- `web/getLog.php` → api.php?action=log
- `web/steigInfo.php` → api.php?action=steig_info
- `include/ajaxCRUD/` directory

---

## Task 1: Directory structure + move deprecated files

**Files:**
- Create: `web/deprecated/`
- Create: `inc/`
- Create: `data/`
- Move: files listed above

- [ ] **Step 1: Create directories**

```bash
mkdir -p /Users/erikr/Git/wlmonitor/web/deprecated
mkdir -p /Users/erikr/Git/wlmonitor/inc
mkdir -p /Users/erikr/Git/wlmonitor/data
```

- [ ] **Step 2: Move deprecated entry-point files**

```bash
cd /Users/erikr/Git/wlmonitor/web
mv monitor.php deprecated/
mv "monitor_json - Kopieren.php" deprecated/
mv debug.php deprecated/
mv watch.php deprecated/
mv ajaxFav.php deprecated/
mv getFavorites.php deprecated/
mv addFavorite.php deprecated/
mv deleteFavorite.php deprecated/
mv checkFavorite.php deprecated/
mv saveFavorites.php deprecated/
mv getStations.php deprecated/
mv savePosition.php deprecated/
mv getLog.php deprecated/
mv steigInfo.php deprecated/
mv monitor_json.php deprecated/
```

- [ ] **Step 3: Move ajaxCRUD**

```bash
mv /Users/erikr/Git/wlmonitor/include/ajaxCRUD /Users/erikr/Git/wlmonitor/web/deprecated/ajaxCRUD
```

- [ ] **Step 4: Verify**

```bash
ls /Users/erikr/Git/wlmonitor/web/deprecated/
ls /Users/erikr/Git/wlmonitor/inc/
ls /Users/erikr/Git/wlmonitor/data/
```

Expected: deprecated/ has 15+ files, inc/ and data/ are empty.

- [ ] **Step 5: Commit**

```bash
cd /Users/erikr/Git/wlmonitor
git add -A
git commit -m "refactor: create inc/, data/, deprecated/ -- move retired endpoints"
```

---

## Task 2: Harden `include/initialize.php`

**Files:**
- Modify: `include/initialize.php`

- [ ] **Step 1: Replace the file**

```php
<?php

// Global Constants
define("SCRIPT_PATH", '/home/.sites/765/site679/web/jardyx.com/wlmonitor/');
define("CURRENT_PATH", __FILE__);
define("AVATAR_DIR", "img/user/");
date_default_timezone_set('Europe/Vienna');
define("APIKEY", 'tVqqssNTeDyFb35');
define("MAX_DEPARTURES", 2);
define("DATABASE_HOST", 'mysqlsvr78.world4you.com');
define("DATABASE_USER", 'sql6675098');
define("DATABASE_PASS", 'dr@3ysr');
define("DATABASE_NAME", '5279249db19');


// Database Connection
function createDBConnection() {
    mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
    $con = mysqli_connect(DATABASE_HOST, DATABASE_USER, DATABASE_PASS, DATABASE_NAME);
    mysqli_set_charset($con, 'utf8');
    if (mysqli_connect_errno()) {
        die('Failed to connect to MySQL: ' . mysqli_connect_error());
    }
    return $con;
}

// Manage User Session
function manageUserSession() {
    // Harden session cookies before session_start
    ini_set('session.cookie_httponly', 1);
    ini_set('session.cookie_secure', 1);
    ini_set('session.cookie_samesite', 'Strict');
    ini_set('session.use_strict_mode', 1);

    session_start(['cookie_lifetime' => 60 * 60 * 24 * 4]);
    $sId = $_SESSION["sId"] ?? "";

    if ($sId == "") {
        if (isset($_COOKIE['sId']) && preg_match('/^[a-zA-Z0-9,\-]{22,128}$/', $_COOKIE['sId'])) {
            $sId = $_COOKIE['sId'];
            session_abort();
            session_id($sId);
            session_start(['cookie_lifetime' => 60 * 60 * 24 * 4]);
            addAlert('warning', 'Session recovered');
        } else {
            $sId = session_id();
            addAlert('warning', 'New Session.');
            setCookie("theme", "auto", time() + 60 * 60 * 24 * 365);
            $img = $_SESSION['img'] = "img/user-md-grey.svg";
        }
        setcookie('sId', $sId, [
            'expires'  => time() + 60 * 60 * 24 * 4,
            'path'     => '/',
            'httponly' => true,
            'secure'   => true,
            'samesite' => 'Strict',
        ]);
        $_SESSION['sId'] = $sId;
    }

    $_SESSION["logPage"] ??= 1;
    $_SESSION["logLimit"] ??= 20;
    return $sId;
}

// Append Log Entry
function appendLog($con, $context = "*", $activity = "*", $origin = "web") {
    $sql = "INSERT INTO wl_log (idUser, context, activity, origin, ipAdress, logTime) VALUES (?, ?, ?, ?, INET_ATON(?), CURRENT_TIMESTAMP)";
    $stmt = $con->prepare($sql);
    $userIP = getUserIpAddr();
    $id = $_SESSION['id'] ?? 1;
    $stmt->bind_param('issss', $id, $context, $activity, $origin, $userIP);
    $stmt->execute();
    $stmt->close();
    return true;
}

// shortcut for append log entry
function logDebug($label, $message) {
    global $con;
    if (($_SESSION["debug"] ?? null)) {
        appendLog($con, $label, $message, 'web');
    }
}

// Get User IP Address
function getUserIpAddr() {
    return $_SERVER['HTTP_CLIENT_IP'] ?? $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'];
}

// Add Alert Message
function addAlert($messageType, $message) {
    $_SESSION['alerts'] = $_SESSION['alerts'] ?? [];
    array_push($_SESSION['alerts'], [$messageType, htmlentities($message)]);
}

function sanitizeDivaInput(string $divaGet): string {
    return preg_replace('/[^0-9,]/', '', $divaGet);
}

// Alias so existing callers don't break during the transition
function sanitizeRblInput(string $input): string {
    return sanitizeDivaInput($input);
}


// Create a Database Connection
$con = createDBConnection();

// Manage User Session
$sId = manageUserSession();

$loggedIn = $_SESSION['loggedin'] ?? 0;
$username = $loggedIn ? $_SESSION['username'] : "";
$img = $_SESSION['img'] = $_SESSION['img'] ?? "user-md-grey.svg";
$avatarDir = $loggedIn ? AVATAR_DIR . $_SESSION['img'] : "";

require_once(__DIR__ . '/csrf.php');
```

- [ ] **Step 2: Check syntax**

```bash
php -l /Users/erikr/Git/wlmonitor/include/initialize.php
```

Expected: `No syntax errors detected`

- [ ] **Step 3: Commit**

```bash
cd /Users/erikr/Git/wlmonitor
git add include/initialize.php
git commit -m "security: harden session cookies; rename sanitizeRblInput to sanitizeDivaInput"
```

---

## Task 3: Create `include/csrf.php`

**Files:**
- Create: `include/csrf.php`

- [ ] **Step 1: Write the file**

```php
<?php
// include/csrf.php
// CSRF token generation and verification

function csrf_token(): string {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function csrf_verify(): bool {
    $submitted = $_POST['csrf_token']
        ?? $_SERVER['HTTP_X_CSRF_TOKEN']
        ?? '';
    return hash_equals($_SESSION['csrf_token'] ?? '', $submitted);
}

function csrf_input(): string {
    return '<input type="hidden" name="csrf_token" value="'
        . htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8') . '">';
}
```

- [ ] **Step 2: Check syntax**

```bash
php -l /Users/erikr/Git/wlmonitor/include/csrf.php
```

Expected: `No syntax errors detected`

- [ ] **Step 3: Commit**

```bash
cd /Users/erikr/Git/wlmonitor
git add include/csrf.php
git commit -m "security: add CSRF token helpers (csrf_token, csrf_verify, csrf_input)"
```

---

## Task 4: Database migration — rename `rbls` column to `diva`

**Files:**
- No PHP files changed; SQL run against the live DB

- [ ] **Step 1: Run the migration**

```bash
mysql -h mysqlsvr78.world4you.com -u sql6675098 -p'dr@3ysr' 5279249db19 \
  -e "ALTER TABLE ogd_stations CHANGE rbls diva VARCHAR(255);"
```

- [ ] **Step 2: Verify**

```bash
mysql -h mysqlsvr78.world4you.com -u sql6675098 -p'dr@3ysr' 5279249db19 \
  -e "DESCRIBE ogd_stations;"
```

Expected: column is now named `diva` not `rbls`.

- [ ] **Step 3: Also rename in wl_favorites**

The favorites table stores DIVA values in a column also called `rbls`. Rename it:

```bash
mysql -h mysqlsvr78.world4you.com -u sql6675098 -p'dr@3ysr' 5279249db19 \
  -e "ALTER TABLE wl_favorites CHANGE rbls diva VARCHAR(255);"
```

- [ ] **Step 4: Commit note**

```bash
cd /Users/erikr/Git/wlmonitor
git commit --allow-empty -m "db: renamed rbls to diva in ogd_stations and wl_favorites (migration run manually)"
```

---

## Task 5: Create `inc/auth.php`

**Files:**
- Create: `inc/auth.php`

Login with rate limiting, logout, and hash upgrade on next login.

- [ ] **Step 1: Create the rate-limit data file**

```bash
echo '{}' > /Users/erikr/Git/wlmonitor/data/ratelimit.json
chmod 600 /Users/erikr/Git/wlmonitor/data/ratelimit.json
```

- [ ] **Step 2: Write `inc/auth.php`**

```php
<?php
// inc/auth.php
// Authentication: login, logout, rate limiting, bcrypt-13 upgrade

define('RATE_LIMIT_FILE', __DIR__ . '/../data/ratelimit.json');
define('RATE_LIMIT_MAX', 5);
define('RATE_LIMIT_WINDOW', 900); // 15 minutes in seconds

function auth_is_rate_limited(string $ip): bool {
    $fp = fopen(RATE_LIMIT_FILE, 'c+');
    if (!$fp) return false;
    flock($fp, LOCK_EX);
    $data  = json_decode(stream_get_contents($fp), true) ?? [];
    $now   = time();
    $entry = $data[$ip] ?? ['count' => 0, 'since' => $now];
    if ($now - $entry['since'] > RATE_LIMIT_WINDOW) {
        $entry = ['count' => 0, 'since' => $now];
    }
    $limited = $entry['count'] >= RATE_LIMIT_MAX;
    flock($fp, LOCK_UN);
    fclose($fp);
    return $limited;
}

function auth_record_failure(string $ip): void {
    $fp = fopen(RATE_LIMIT_FILE, 'c+');
    if (!$fp) return;
    flock($fp, LOCK_EX);
    $data  = json_decode(stream_get_contents($fp), true) ?? [];
    $now   = time();
    $entry = $data[$ip] ?? ['count' => 0, 'since' => $now];
    if ($now - $entry['since'] > RATE_LIMIT_WINDOW) {
        $entry = ['count' => 0, 'since' => $now];
    }
    $entry['count']++;
    $data[$ip] = $entry;
    ftruncate($fp, 0);
    rewind($fp);
    fwrite($fp, json_encode($data));
    flock($fp, LOCK_UN);
    fclose($fp);
}

function auth_clear_failures(string $ip): void {
    $fp = fopen(RATE_LIMIT_FILE, 'c+');
    if (!$fp) return;
    flock($fp, LOCK_EX);
    $data = json_decode(stream_get_contents($fp), true) ?? [];
    unset($data[$ip]);
    ftruncate($fp, 0);
    rewind($fp);
    fwrite($fp, json_encode($data));
    flock($fp, LOCK_UN);
    fclose($fp);
}

/**
 * Attempt login.
 * Returns ['ok' => true, 'username' => '...'] or ['ok' => false, 'error' => '...'].
 */
function auth_login(mysqli $con, string $email, string $password): array {
    $ip = getUserIpAddr();

    if (auth_is_rate_limited($ip)) {
        return ['ok' => false, 'error' => 'Zu viele Fehlversuche. Bitte warten Sie 15 Minuten.'];
    }

    $stmt = $con->prepare(
        'SELECT id, username, password, email, img, activation_code, disabled,
                invalidLogins, departures, debug, rights
         FROM wl_accounts WHERE email = ?'
    );
    $stmt->bind_param('s', $email);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows === 0) {
        $stmt->close();
        auth_record_failure($ip);
        return ['ok' => false, 'error' => 'Falscher Benutzername oder Kennwort.'];
    }

    $row = $result->fetch_assoc();
    $stmt->close();

    if ($row['activation_code'] !== 'activated') {
        return ['ok' => false, 'error' => 'Benutzer ist noch nicht aktiviert.'];
    }
    if ((int) $row['disabled'] === 1) {
        return ['ok' => false, 'error' => 'Benutzer ist gesperrt.'];
    }
    if (!password_verify($password, $row['password'])) {
        auth_record_failure($ip);
        $upd = $con->prepare('UPDATE wl_accounts SET invalidLogins = invalidLogins + 1 WHERE email = ?');
        $upd->bind_param('s', $email);
        $upd->execute();
        $upd->close();
        return ['ok' => false, 'error' => 'Falscher Benutzername oder Kennwort.'];
    }

    // Upgrade hash cost if needed
    if (password_needs_rehash($row['password'], PASSWORD_BCRYPT, ['cost' => 13])) {
        $newHash = password_hash($password, PASSWORD_BCRYPT, ['cost' => 13]);
        $upd = $con->prepare('UPDATE wl_accounts SET password = ? WHERE id = ?');
        $upd->bind_param('si', $newHash, $row['id']);
        $upd->execute();
        $upd->close();
    }

    auth_clear_failures($ip);

    session_regenerate_id(true);
    $sId = session_id();
    setcookie('sId', $sId, [
        'expires'  => time() + 60 * 60 * 24 * 4,
        'path'     => '/',
        'httponly' => true,
        'secure'   => true,
        'samesite' => 'Strict',
    ]);

    $_SESSION['sId']        = $sId;
    $_SESSION['loggedin']   = true;
    $_SESSION['id']         = (int) $row['id'];
    $_SESSION['username']   = $row['username'];
    $_SESSION['email']      = $row['email'];
    $_SESSION['img']        = $row['img'];
    $_SESSION['disabled']   = $row['disabled'];
    $_SESSION['departures'] = $row['departures'];
    $_SESSION['debug']      = $row['debug'];
    $_SESSION['rights']     = $row['rights'];
    unset($_SESSION['failedLogins'], $_SESSION['Error']);

    $upd = $con->prepare('UPDATE wl_accounts SET lastLogin = NOW(), invalidLogins = 0 WHERE id = ?');
    $upd->bind_param('i', $row['id']);
    $upd->execute();
    $upd->close();

    appendLog($con, 'auth', $row['username'] . ' logged in.', 'web');

    return ['ok' => true, 'username' => $row['username']];
}

function auth_logout(mysqli $con): void {
    if (!empty($_SESSION['username'])) {
        appendLog($con, 'log', $_SESSION['username'] . ' logged out.', 'web');
    }
    session_destroy();
    setcookie('sId', '', [
        'expires'  => time() - 3600,
        'path'     => '/',
        'httponly' => true,
        'secure'   => true,
        'samesite' => 'Strict',
    ]);
}
```

- [ ] **Step 3: Check syntax**

```bash
php -l /Users/erikr/Git/wlmonitor/inc/auth.php
```

Expected: `No syntax errors detected`

- [ ] **Step 4: Commit**

```bash
cd /Users/erikr/Git/wlmonitor
git add inc/auth.php data/ratelimit.json
git commit -m "feat: add inc/auth.php with rate limiting and bcrypt-13 upgrade"
```

---

## Task 6: Create `inc/monitor.php`

**Files:**
- Create: `inc/monitor.php`

Extracted from `web/deprecated/monitor_json.php`. Returns a structured array; no output.

- [ ] **Step 1: Write `inc/monitor.php`**

```php
<?php
// inc/monitor.php
// Fetch departure data from Wiener Linien API and return structured array

function monitor_get(mysqli $con, string $divaRaw, int $maxDepartures): array {
    $divaRaw = sanitizeDivaInput($divaRaw);
    if ($divaRaw === '') {
        throw new InvalidArgumentException('No valid DIVA numbers provided.');
    }

    $apiUrl = 'https://www.wienerlinien.at/ogd_realtime/monitor?diva='
        . str_replace(',', '&diva=', $divaRaw)
        . '&sender=' . APIKEY
        . '&activateTrafficInfo=stoerungkurz&activateTrafficInfo=stoerunglang';

    $raw = @file_get_contents($apiUrl);
    if ($raw === false) {
        throw new RuntimeException('Wiener Linien API request failed.');
    }

    $json = json_decode($raw, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        throw new RuntimeException('Invalid JSON from API: ' . json_last_error_msg());
    }

    $monitors   = $json['data']['monitors'] ?? [];
    $serverTime = $json['message']['serverTime'] ?? date('c');

    if (count($monitors) === 0) {
        throw new RuntimeException('No monitors found for the given DIVA numbers.');
    }

    $result = [];
    $prevStationId = null;
    $trainCount = 0;
    $stationData = [];

    foreach ($monitors as $monitor) {
        $stationName = $monitor['locationStop']['properties']['title'];
        $stationId   = $monitor['locationStop']['properties']['name'];

        if ($stationId !== $prevStationId) {
            $prevStationId = $stationId;
            $trainCount    = 0;
            $stationData   = ['id' => $stationId, 'station_name' => $stationName];
        }

        foreach ($monitor['lines'] as $line) {
            $stationData['train_'     . $trainCount] = $line['name'] . ' -> ' . $line['towards'];
            $stationData['platform_'  . $trainCount] = $line['platform'];
            $stationData['departure_' . $trainCount] = '';

            $dCount = 1;
            foreach ($line['departures']['departure'] ?? [] as $dep) {
                if ($dCount > $maxDepartures) break;
                if ($stationData['departure_' . $trainCount] !== '') {
                    $stationData['departure_' . $trainCount] .= ', ';
                }
                $cd = $dep['departureTime']['countdown'];
                $stationData['departure_' . $trainCount] .= ($cd === 0 ? '*' : $cd);
                $dCount++;
            }
            $trainCount++;
        }

        $result[$stationId] = $stationData;
    }

    $result['trains']    = $trainCount;
    $result['update_at'] = date_format(date_create($serverTime), 'H:i:s');
    $result['api_ping']  = strtotime($serverTime) - time();

    return $result;
}
```

- [ ] **Step 2: Check syntax**

```bash
php -l /Users/erikr/Git/wlmonitor/inc/monitor.php
```

Expected: `No syntax errors detected`

- [ ] **Step 3: Commit**

```bash
cd /Users/erikr/Git/wlmonitor
git add inc/monitor.php
git commit -m "feat: add inc/monitor.php -- extract Wiener Linien API logic"
```

---

## Task 7: Create `inc/stations.php`

**Files:**
- Create: `inc/stations.php`

Extracted from `web/deprecated/getStations.php`. Uses the renamed `diva` column (requires Task 4).

- [ ] **Step 1: Write `inc/stations.php`**

```php
<?php
// inc/stations.php
// Station list from ogd_stations table

function stations_by_distance(mysqli $con, float $lat, float $lon): array {
    $sql = "SELECT s.Haltestelle AS station,
                   FLOOR(ST_Distance_Sphere(point(s.LAT, s.LON), point(?, ?)) / 30) * 30 AS distance,
                   s.diva, s.lat, s.lon
            FROM ogd_stations AS s
            ORDER BY distance, station
            LIMIT 100";
    $stmt = $con->prepare($sql);
    $stmt->bind_param('dd', $lat, $lon);
    $stmt->execute();
    $result = $stmt->get_result();
    $rows = [];
    while ($row = $result->fetch_assoc()) {
        $rows[] = [
            'station'  => $row['station'],
            'distance' => (int) ($row['distance'] ?? 0),
            'diva'     => $row['diva'],
            'lat'      => $row['lat'],
            'lon'      => $row['lon'],
        ];
    }
    $stmt->close();
    return $rows;
}

function stations_alpha(mysqli $con): array {
    $result = $con->query(
        "SELECT s.Haltestelle AS station, s.diva, s.lat, s.lon FROM ogd_stations AS s ORDER BY station"
    );
    $rows = [];
    while ($row = $result->fetch_assoc()) {
        $rows[] = [
            'station' => $row['station'],
            'diva'    => $row['diva'],
            'lat'     => $row['lat'],
            'lon'     => $row['lon'],
        ];
    }
    return $rows;
}

function stations_save_position(mysqli $con, float $lat, float $lon): void {
    $_SESSION['lat'] = $lat;
    $_SESSION['lon'] = $lon;
    appendLog($con, 'pos', "Position saved: $lat, $lon", 'web');
}
```

- [ ] **Step 2: Check syntax**

```bash
php -l /Users/erikr/Git/wlmonitor/inc/stations.php
```

Expected: `No syntax errors detected`

- [ ] **Step 3: Commit**

```bash
cd /Users/erikr/Git/wlmonitor
git add inc/stations.php
git commit -m "feat: add inc/stations.php -- station list queries"
```

---

## Task 8: Create `inc/favorites.php`

**Files:**
- Create: `inc/favorites.php`

Consolidates getFavorites, addFavorite, deleteFavorite, checkFavorite, saveFavorites, editFavorite. Uses renamed `diva` column (requires Task 4).

- [ ] **Step 1: Write `inc/favorites.php`**

```php
<?php
// inc/favorites.php
// Favorites CRUD for wl_favorites table

function favorites_get(mysqli $con, int $idUser): array {
    $stmt = $con->prepare(
        'SELECT id, idUser, title, diva, bclass, sort FROM wl_favorites WHERE idUser = ? ORDER BY sort, id'
    );
    $stmt->bind_param('i', $idUser);
    $stmt->execute();
    $result = $stmt->get_result();
    $rows = [];
    while ($row = $result->fetch_assoc()) {
        $rows[] = [
            'id'     => (int) $row['id'],
            'title'  => $row['title'],
            'diva'   => $row['diva'],
            'bclass' => $row['bclass'],
            'sort'   => (int) $row['sort'],
        ];
    }
    $stmt->close();
    return $rows;
}

function favorites_check(mysqli $con, int $idUser, string $diva): bool {
    $diva = sanitizeDivaInput($diva);
    $stmt = $con->prepare('SELECT id FROM wl_favorites WHERE idUser = ? AND diva = ?');
    $stmt->bind_param('is', $idUser, $diva);
    $stmt->execute();
    $stmt->store_result();
    $found = $stmt->num_rows > 0;
    $stmt->close();
    return $found;
}

function favorites_add(mysqli $con, int $idUser, string $title, string $diva, string $bclass, int $sort): int {
    $title  = mb_substr(strip_tags($title), 0, 120);
    $diva   = sanitizeDivaInput($diva);
    $bclass = preg_replace('/[^a-z0-9\-]/', '', $bclass);

    $stmt = $con->prepare(
        'INSERT INTO wl_favorites (idUser, title, sort, diva, bclass, updated, created)
         VALUES (?, ?, ?, ?, ?, SYSDATE(), CURRENT_TIMESTAMP)'
    );
    $stmt->bind_param('isiss', $idUser, $title, $sort, $diva, $bclass);
    $stmt->execute();
    $newId = (int) $con->insert_id;
    $stmt->close();
    appendLog($con, 'favAdd', "Favorite #$newId ($title) added.", 'web');
    return $newId;
}

function favorites_edit(mysqli $con, int $idUser, int $favId, string $title, string $diva, string $bclass, int $sort): bool {
    $title  = mb_substr(strip_tags($title), 0, 120);
    $diva   = sanitizeDivaInput($diva);
    $bclass = preg_replace('/[^a-z0-9\-]/', '', $bclass);

    $stmt = $con->prepare(
        'UPDATE wl_favorites SET title = ?, diva = ?, bclass = ?, sort = ?
         WHERE id = ? AND idUser = ?'
    );
    $stmt->bind_param('sssiii', $title, $diva, $bclass, $sort, $favId, $idUser);
    $stmt->execute();
    $affected = $stmt->affected_rows;
    $stmt->close();
    appendLog($con, 'favEdit', "Favorite #$favId updated.", 'web');
    return $affected >= 0;
}

function favorites_delete(mysqli $con, int $idUser, int $favId): bool {
    $stmt = $con->prepare('DELETE FROM wl_favorites WHERE id = ? AND idUser = ?');
    $stmt->bind_param('ii', $favId, $idUser);
    $stmt->execute();
    $affected = $stmt->affected_rows;
    $stmt->close();
    appendLog($con, 'favDel', "Favorite #$favId deleted.", 'web');
    return $affected > 0;
}

/**
 * Save a new sort order.
 * $items = [['id' => 12, 'sort' => 1], ...]
 */
function favorites_save_sort(mysqli $con, int $idUser, array $items): void {
    $stmt = $con->prepare('UPDATE wl_favorites SET sort = ? WHERE id = ? AND idUser = ?');
    foreach ($items as $item) {
        if (!isset($item['id'], $item['sort'])) continue;
        $id   = (int) $item['id'];
        $sort = (int) $item['sort'];
        $stmt->bind_param('iii', $sort, $id, $idUser);
        $stmt->execute();
    }
    $stmt->close();
    appendLog($con, 'favSort', 'Sort order saved.', 'web');
}
```

- [ ] **Step 2: Check syntax**

```bash
php -l /Users/erikr/Git/wlmonitor/inc/favorites.php
```

Expected: `No syntax errors detected`

- [ ] **Step 3: Commit**

```bash
cd /Users/erikr/Git/wlmonitor
git add inc/favorites.php
git commit -m "feat: add inc/favorites.php -- CRUD for wl_favorites"
```

---

## Task 9: Create `inc/admin.php`

**Files:**
- Create: `inc/admin.php`

Replaces ajaxCRUD with focused user management (list, edit, reset password, delete).

- [ ] **Step 1: Write `inc/admin.php`**

```php
<?php
// inc/admin.php
// User management -- caller must verify Admin rights before calling these functions

function admin_list_users(mysqli $con, int $page = 1, int $perPage = 25, string $filter = ''): array {
    $offset = ($page - 1) * $perPage;
    if ($filter !== '') {
        $like = '%' . $con->real_escape_string($filter) . '%';
        $stmt = $con->prepare(
            'SELECT id, username, email, disabled, departures, debug, rights
             FROM wl_accounts WHERE username LIKE ? ORDER BY username LIMIT ? OFFSET ?'
        );
        $stmt->bind_param('sii', $like, $perPage, $offset);
    } else {
        $stmt = $con->prepare(
            'SELECT id, username, email, disabled, departures, debug, rights
             FROM wl_accounts ORDER BY username LIMIT ? OFFSET ?'
        );
        $stmt->bind_param('ii', $perPage, $offset);
    }
    $stmt->execute();
    $result = $stmt->get_result();
    $rows = [];
    while ($row = $result->fetch_assoc()) {
        $rows[] = [
            'id'         => (int) $row['id'],
            'username'   => $row['username'],
            'email'      => $row['email'],
            'disabled'   => (int) $row['disabled'],
            'departures' => (int) $row['departures'],
            'debug'      => (int) $row['debug'],
            'rights'     => $row['rights'],
        ];
    }
    $stmt->close();

    // Total count for pagination
    if ($filter !== '') {
        $like  = '%' . $con->real_escape_string($filter) . '%';
        $cstmt = $con->prepare('SELECT COUNT(*) FROM wl_accounts WHERE username LIKE ?');
        $cstmt->bind_param('s', $like);
    } else {
        $cstmt = $con->prepare('SELECT COUNT(*) FROM wl_accounts');
    }
    $cstmt->execute();
    $total = 0;
    $cstmt->bind_result($total);
    $cstmt->fetch();
    $cstmt->close();

    return ['users' => $rows, 'total' => (int) $total, 'page' => $page, 'per_page' => $perPage];
}

function admin_edit_user(mysqli $con, int $targetId, string $email, string $rights, int $disabled, int $departures, int $debug): bool {
    $rights = in_array($rights, ['Admin', 'User'], true) ? $rights : 'User';
    $stmt   = $con->prepare(
        'UPDATE wl_accounts SET email = ?, rights = ?, disabled = ?, departures = ?, debug = ? WHERE id = ?'
    );
    $stmt->bind_param('ssiiii', $email, $rights, $disabled, $departures, $debug, $targetId);
    $stmt->execute();
    $ok = $stmt->affected_rows >= 0;
    $stmt->close();
    appendLog($con, 'admin', "User #$targetId updated.", 'web');
    return $ok;
}

function admin_reset_password(mysqli $con, int $targetId): string {
    $newPass = bin2hex(random_bytes(8));
    $hash    = password_hash($newPass, PASSWORD_BCRYPT, ['cost' => 13]);
    $stmt    = $con->prepare('UPDATE wl_accounts SET password = ? WHERE id = ?');
    $stmt->bind_param('si', $hash, $targetId);
    $stmt->execute();
    $stmt->close();
    appendLog($con, 'admin', "Password reset for user #$targetId.", 'web');
    return $newPass;
}

function admin_delete_user(mysqli $con, int $targetId, int $requestingUserId): bool {
    if ($targetId === $requestingUserId) {
        return false; // cannot delete yourself
    }
    $stmt = $con->prepare('DELETE FROM wl_accounts WHERE id = ?');
    $stmt->bind_param('i', $targetId);
    $stmt->execute();
    $ok = $stmt->affected_rows > 0;
    $stmt->close();
    appendLog($con, 'admin', "User #$targetId deleted.", 'web');
    return $ok;
}
```

- [ ] **Step 2: Check syntax**

```bash
php -l /Users/erikr/Git/wlmonitor/inc/admin.php
```

Expected: `No syntax errors detected`

- [ ] **Step 3: Commit**

```bash
cd /Users/erikr/Git/wlmonitor
git add inc/admin.php
git commit -m "feat: add inc/admin.php -- replace ajaxCRUD with focused user management"
```

---

## Task 10: Create `web/api.php`

**Files:**
- Create: `web/api.php`

Single dispatcher routing `?action=` to inc/ module functions. All responses are JSON.

- [ ] **Step 1: Write `web/api.php`**

```php
<?php
// web/api.php
// Single JSON API dispatcher

require_once(__DIR__ . '/../include/initialize.php');
require_once(__DIR__ . '/../inc/monitor.php');
require_once(__DIR__ . '/../inc/stations.php');
require_once(__DIR__ . '/../inc/favorites.php');
require_once(__DIR__ . '/../inc/admin.php');

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

$action = $_GET['action'] ?? $_POST['action'] ?? '';

function api_json(mixed $data, int $status = 200): never {
    http_response_code($status);
    echo json_encode($data, JSON_HEX_TAG | JSON_HEX_AMP | JSON_THROW_ON_ERROR);
    exit;
}

function api_require_login(): void {
    if (empty($_SESSION['loggedin'])) {
        api_json(['error' => 'Not authenticated'], 401);
    }
}

function api_require_admin(): void {
    api_require_login();
    if (($_SESSION['rights'] ?? '') !== 'Admin') {
        api_json(['error' => 'Forbidden'], 403);
    }
}

function api_require_csrf(): void {
    if (!csrf_verify()) {
        api_json(['error' => 'Invalid CSRF token'], 403);
    }
}

try {
    switch ($action) {

        // --- Monitor ---
        case 'monitor':
            $diva = sanitizeDivaInput($_GET['diva'] ?? $_SESSION['diva'] ?? '60200103');
            $_SESSION['diva'] = $diva;
            $maxDep = (int) ($_SESSION['departures'] ?? MAX_DEPARTURES);
            api_json(monitor_get($con, $diva, $maxDep));

        // --- Stations ---
        case 'stations':
            if (isset($_GET['lat'], $_GET['lon'])) {
                $lat = (float) $_GET['lat'];
                $lon = (float) $_GET['lon'];
                api_json(stations_by_distance($con, $lat, $lon));
            }
            api_json(stations_alpha($con));

        case 'position_save':
            api_require_csrf();
            $lat = (float) ($_POST['lat'] ?? 0);
            $lon = (float) ($_POST['lon'] ?? 0);
            stations_save_position($con, $lat, $lon);
            api_json(['ok' => true]);

        // --- Favorites ---
        case 'favorites':
            api_require_login();
            api_json(favorites_get($con, (int) $_SESSION['id']));

        case 'favorites_check':
            api_require_login();
            $diva = sanitizeDivaInput($_GET['diva'] ?? '');
            api_json(['found' => favorites_check($con, (int) $_SESSION['id'], $diva)]);

        case 'favorites_add':
            api_require_login();
            api_require_csrf();
            $id = favorites_add(
                $con,
                (int) $_SESSION['id'],
                $_POST['title']  ?? '',
                $_POST['diva']   ?? '',
                $_POST['bclass'] ?? 'btn-outline-success',
                (int) ($_POST['sort'] ?? 0)
            );
            api_json(['id' => $id]);

        case 'favorites_edit':
            api_require_login();
            api_require_csrf();
            $ok = favorites_edit(
                $con,
                (int) $_SESSION['id'],
                (int) ($_POST['favId'] ?? 0),
                $_POST['title']  ?? '',
                $_POST['diva']   ?? '',
                $_POST['bclass'] ?? 'btn-outline-success',
                (int) ($_POST['sort'] ?? 0)
            );
            api_json(['ok' => $ok]);

        case 'favorites_delete':
            api_require_login();
            api_require_csrf();
            $ok = favorites_delete($con, (int) $_SESSION['id'], (int) ($_POST['id'] ?? 0));
            api_json(['ok' => $ok]);

        case 'favorites_sort':
            api_require_login();
            api_require_csrf();
            $body = json_decode(file_get_contents('php://input'), true);
            if (!is_array($body)) {
                api_json(['error' => 'Invalid JSON body'], 400);
            }
            favorites_save_sort($con, (int) $_SESSION['id'], $body);
            api_json(['ok' => true]);

        // --- Log ---
        case 'log':
            api_require_login();
            $page   = max(1, (int) ($_GET['page']  ?? $_SESSION['logPage']));
            $limit  = max(1, min(100, (int) ($_GET['limit'] ?? $_SESSION['logLimit'])));
            $offset = ($page - 1) * $limit;
            $uid    = (int) $_SESSION['id'];
            $stmt   = $con->prepare(
                'SELECT context, activity, origin, INET_NTOA(ipAdress) AS ip, logTime
                 FROM wl_log WHERE idUser = ? ORDER BY logTime DESC LIMIT ? OFFSET ?'
            );
            $stmt->bind_param('iii', $uid, $limit, $offset);
            $stmt->execute();
            $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
            $stmt->close();
            api_json($rows);

        // --- Admin ---
        case 'admin_users':
            api_require_admin();
            $page   = max(1, (int) ($_GET['page'] ?? 1));
            $filter = $_GET['filter'] ?? '';
            api_json(admin_list_users($con, $page, 25, $filter));

        case 'admin_user_edit':
            api_require_admin();
            api_require_csrf();
            $ok = admin_edit_user(
                $con,
                (int) ($_POST['id']         ?? 0),
                $_POST['email']             ?? '',
                $_POST['rights']            ?? 'User',
                (int) ($_POST['disabled']   ?? 0),
                (int) ($_POST['departures'] ?? MAX_DEPARTURES),
                (int) ($_POST['debug']      ?? 0)
            );
            api_json(['ok' => $ok]);

        case 'admin_user_reset':
            api_require_admin();
            api_require_csrf();
            $newPass = admin_reset_password($con, (int) ($_POST['id'] ?? 0));
            api_json(['password' => $newPass]);

        case 'admin_user_delete':
            api_require_admin();
            api_require_csrf();
            $ok = admin_delete_user($con, (int) ($_POST['id'] ?? 0), (int) $_SESSION['id']);
            api_json(['ok' => $ok]);

        default:
            api_json(['error' => 'Unknown action'], 400);
    }
} catch (Throwable $e) {
    appendLog($con, 'api', 'Error: ' . $e->getMessage(), 'web');
    api_json(['error' => 'Internal server error'], 500);
}
```

- [ ] **Step 2: Check syntax**

```bash
php -l /Users/erikr/Git/wlmonitor/web/api.php
```

Expected: `No syntax errors detected`

- [ ] **Step 3: Smoke test (requires DB access; use local PHP server)**

```bash
cd /Users/erikr/Git/wlmonitor/web
php -S localhost:8080 &
sleep 1
# Unknown action should return 400
curl -s -o /dev/null -w "%{http_code}" "http://localhost:8080/api.php?action=bogus"
# expected: 400

# Favorites without session should return 401
curl -s -o /dev/null -w "%{http_code}" "http://localhost:8080/api.php?action=favorites"
# expected: 401

kill %1
```

- [ ] **Step 4: Commit**

```bash
cd /Users/erikr/Git/wlmonitor
git add web/api.php
git commit -m "feat: add web/api.php -- single JSON dispatcher for all AJAX actions"
```

---

## Task 11: Update `web/authentication.php` + `web/logout.php`

**Files:**
- Modify: `web/authentication.php`
- Modify: `web/logout.php`

- [ ] **Step 1: Replace `web/authentication.php`**

```php
<?php
require_once(__DIR__ . '/../include/initialize.php');
require_once(__DIR__ . '/../inc/auth.php');

if (!isset($_POST['login-email'], $_POST['login-password'])) {
    addAlert('danger', 'Bitte sowohl Benutzername als auch Kennwort ausfullen.');
    header('Location: index.php'); exit;
}

if (!csrf_verify()) {
    addAlert('danger', 'Ungultige Anfrage.');
    header('Location: index.php'); exit;
}

$result = auth_login($con, $_POST['login-email'], $_POST['login-password']);

if ($result['ok']) {
    if (!empty($_POST['stayLoggedin'])) {
        ini_set('session.cookie_lifetime', 60 * 60 * 24 * 365);
    }
    if (!empty($_POST['rememberName'])) {
        setcookie('wlmonitor_username', $_POST['login-email'], [
            'expires'  => time() + 10 * 24 * 60 * 60,
            'path'     => '/',
            'httponly' => true,
            'secure'   => true,
            'samesite' => 'Strict',
        ]);
    } else {
        setcookie('wlmonitor_username', '', ['expires' => time() - 3600, 'path' => '/']);
    }
    addAlert('info', 'Hallo ' . htmlspecialchars($result['username'], ENT_QUOTES, 'UTF-8') . '.');
} else {
    addAlert('danger', $result['error']);
}

header('Location: index.php'); exit;
```

- [ ] **Step 2: Replace `web/logout.php`**

```php
<?php
require_once(__DIR__ . '/../include/initialize.php');
require_once(__DIR__ . '/../inc/auth.php');

auth_logout($con);
addAlert('notice', 'Sie wurden abgemeldet.');
header('Location: index.php'); exit;
```

- [ ] **Step 3: Check syntax on both**

```bash
php -l /Users/erikr/Git/wlmonitor/web/authentication.php
php -l /Users/erikr/Git/wlmonitor/web/logout.php
```

- [ ] **Step 4: Commit**

```bash
cd /Users/erikr/Git/wlmonitor
git add web/authentication.php web/logout.php
git commit -m "refactor: authentication.php and logout.php delegate to inc/auth.php with CSRF"
```

---

## Task 12: Bootstrap 5 + CSS custom properties

**Files:**
- Modify: `include/html_header.php`
- Create: `web/css/theme.css`

- [ ] **Step 1: Create `web/css/theme.css`**

```css
/* web/css/theme.css
   CSS custom properties for light/dark theming.
   Toggle via data-theme="dark"|"light" on <html>.
   "auto" (no attribute) follows prefers-color-scheme.
*/

:root {
  --color-bg:       #ffffff;
  --color-surface:  #f8f9fa;
  --color-text:     #212529;
  --color-muted:    #6c757d;
  --color-border:   #dee2e6;
  --color-primary:  #0d6efd;
  --color-nav-bg:   #000000;
  --color-nav-text: #ffffff;
}

[data-theme="dark"] {
  --color-bg:       #1a1a2e;
  --color-surface:  #16213e;
  --color-text:     #e0e0e0;
  --color-muted:    #adb5bd;
  --color-border:   #495057;
  --color-primary:  #4d9fff;
  --color-nav-bg:   #0d0d0d;
  --color-nav-text: #ffffff;
}

@media (prefers-color-scheme: dark) {
  :root:not([data-theme="light"]) {
    --color-bg:       #1a1a2e;
    --color-surface:  #16213e;
    --color-text:     #e0e0e0;
    --color-muted:    #adb5bd;
    --color-border:   #495057;
    --color-primary:  #4d9fff;
    --color-nav-bg:   #0d0d0d;
    --color-nav-text: #ffffff;
  }
}

body {
  background-color: var(--color-bg);
  color: var(--color-text);
}

.navbar {
  background-color: var(--color-nav-bg) !important;
}

.card,
.list-group-item {
  background-color: var(--color-surface);
  border-color: var(--color-border);
  color: var(--color-text);
}

.departure-table td,
.departure-table th {
  color: var(--color-text);
  border-color: var(--color-border);
}

.text-muted {
  color: var(--color-muted) !important;
}
```

- [ ] **Step 2: Replace `include/html_header.php`**

```html
<!DOCTYPE html>
<html lang="de">
<head>
  <title>Wiener Linien Abfahrtsmonitor</title>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <meta http-equiv="X-UA-Compatible" content="ie=edge">

  <meta name="application-name" content="WL Monitor">
  <meta name="mobile-web-app-capable" content="yes">
  <meta name="apple-mobile-web-app-capable" content="yes">
  <meta name="apple-mobile-web-app-title" content="WL Monitor">
  <meta name="theme-color" content="#000000">

  <link rel="shortcut icon" type="image/x-icon" href="img/favicon.ico">
  <link rel="apple-touch-icon" sizes="180x180" href="img/apple-touch-icon-180x180.png">
  <link rel="manifest" href="img/manifest.json">

  <!-- Font Awesome -->
  <link rel="stylesheet" href="https://use.fontawesome.com/releases/v5.7.0/css/all.css"
        integrity="sha384-lZN37f5QGtY3VHgisS14W3ExzMWZxybE1SJSEsQp9S+oqd12jhcu+A56Ebc1zFSJ"
        crossorigin="anonymous">

  <!-- Google Fonts -->
  <link rel="stylesheet" href="https://fonts.googleapis.com/css?family=Roboto|Roboto+Mono|Share+Tech+Mono">

  <!-- Bootstrap 5 -->
  <link rel="stylesheet"
        href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css"
        integrity="sha384-QWTKZyjpPEjISv5WaRU9OFeRpok6YctnYmDr5pNlyT2bRjXh0JMhjY6hW+ALEwIH"
        crossorigin="anonymous">

  <!-- App styles -->
  <link rel="stylesheet" href="css/theme.css">
  <link rel="stylesheet" href="style/wl-monitor.css">
</head>
<body>
```

- [ ] **Step 3: Commit**

```bash
cd /Users/erikr/Git/wlmonitor
git add web/css/theme.css include/html_header.php
git commit -m "feat: Bootstrap 5 + CSS custom properties for light/dark theming"
```

---

## Task 13: Rewrite `web/js/wl-monitor.js` as vanilla JS module

**Files:**
- Modify: `web/js/wl-monitor.js`

Replaces jQuery with `fetch()`. All DOM construction uses `createElement`/`textContent` (no string injection).

- [ ] **Step 1: Replace the file**

```js
/**
 * wl-monitor.js -- ES module
 * Vanilla JS, fetch() based. No jQuery.
 */

// --- State -------------------------------------------------------------------
let stationCache = [];
let monitorTimer = null;

// --- Init --------------------------------------------------------------------
document.addEventListener('DOMContentLoaded', () => {
  applyTheme();
  // Render any PHP session alerts passed via wlConfig
  if (window.wlConfig?.alerts?.length) {
    for (const [type, msg] of window.wlConfig.alerts) {
      sendAlert(msg, type);
    }
  }
  loadFavorites();
  loadMonitor();
  startMonitorTimer();
  wireScrollButton();
  wireStationSort();
  wireThemeToggle();
});

// --- API helpers -------------------------------------------------------------
async function apiFetch(action, params = {}) {
  const url = new URL('api.php', location.href);
  url.searchParams.set('action', action);
  for (const [k, v] of Object.entries(params)) url.searchParams.set(k, v);
  const res = await fetch(url);
  if (!res.ok) throw new Error('API ' + action + ' failed: ' + res.status);
  return res.json();
}

async function apiPost(action, body = {}) {
  const fd = new FormData();
  fd.append('action', action);
  const csrfInput = document.querySelector('input[name="csrf_token"]');
  if (csrfInput) fd.append('csrf_token', csrfInput.value);
  for (const [k, v] of Object.entries(body)) fd.append(k, v);
  const res = await fetch('api.php', { method: 'POST', body: fd });
  if (!res.ok) throw new Error('API POST ' + action + ' failed: ' + res.status);
  return res.json();
}

// --- Monitor -----------------------------------------------------------------
async function loadMonitor(diva) {
  const params = diva ? { diva } : {};
  try {
    const data = await apiFetch('monitor', params);
    renderMonitor(data);
  } catch (e) {
    const container = document.getElementById('monitor');
    if (container) container.textContent = 'Keine Abfahrtsdaten verfugbar.';
    console.error(e);
  }
}

function renderMonitor(data) {
  const container = document.getElementById('monitor');
  if (!container) return;
  container.replaceChildren();

  const { trains, update_at, api_ping, ...stations } = data;

  for (const [, s] of Object.entries(stations)) {
    if (typeof s !== 'object') continue;

    const card   = document.createElement('div');
    card.className = 'card mb-2';

    const header = document.createElement('div');
    header.className = 'card-header';
    header.textContent = s.station_name;
    card.appendChild(header);

    const table = document.createElement('table');
    table.className = 'table table-sm departure-table mb-0';

    let i = 0;
    while (('train_' + i) in s) {
      const tr    = table.insertRow();
      const tdLine = tr.insertCell();
      tdLine.textContent = s['train_' + i];
      tdLine.className = 'fw-semibold';
      const tdDep = tr.insertCell();
      tdDep.textContent = s['departure_' + i];
      i++;
    }
    card.appendChild(table);

    const footer = document.createElement('div');
    footer.className = 'card-footer text-muted small';
    footer.textContent = 'Aktualisiert: ' + update_at;
    card.appendChild(footer);

    container.appendChild(card);
  }
}

function startMonitorTimer() {
  if (monitorTimer) clearInterval(monitorTimer);
  monitorTimer = setInterval(() => loadMonitor(), 20000);
}

// --- Favorites ---------------------------------------------------------------
async function loadFavorites() {
  try {
    const favs = await apiFetch('favorites');
    renderFavorites(favs);
  } catch (e) {
    console.error('Could not load favorites:', e);
  }
}

function renderFavorites(favs) {
  const container = document.getElementById('buttons');
  if (!container) return;
  container.replaceChildren();
  for (const fav of favs) {
    const btn = document.createElement('button');
    btn.type = 'button';
    btn.className = 'btn ' + fav.bclass + ' d-block w-100 mb-1';
    btn.id = 'btnFav-' + fav.id;
    btn.dataset.diva = fav.diva;
    btn.dataset.sort = fav.sort;
    btn.textContent = fav.title;
    btn.addEventListener('click', () => {
      loadMonitor(fav.diva);
      startMonitorTimer();
    });
    btn.addEventListener('dblclick', () => {
      location.href = 'editFavorite.php?favID=' + fav.id;
    });
    container.appendChild(btn);
  }
}

// --- Stations ----------------------------------------------------------------
async function loadStationsByDistance(position) {
  const { latitude, longitude } = position.coords;
  const spinner = document.getElementById('stationSortDist');
  if (spinner) spinner.classList.remove('d-none');
  try {
    await apiPost('position_save', { lat: latitude, lon: longitude });
    const stations = await apiFetch('stations', { lat: latitude, lon: longitude });
    stationCache = stations;
    renderStationList(stations, latitude, longitude);
  } catch (e) {
    console.error(e);
    loadStationsAlpha();
  } finally {
    if (spinner) spinner.classList.add('d-none');
  }
}

async function loadStationsAlpha() {
  const spinner = document.getElementById('stationSortAlpha');
  if (spinner) spinner.classList.remove('d-none');
  try {
    const stations = await apiFetch('stations');
    stationCache = stations;
    renderStationList(stations);
  } catch (e) {
    console.error('Could not load stations:', e);
  } finally {
    if (spinner) spinner.classList.add('d-none');
  }
}

function renderStationList(stations, originLat, originLon) {
  const list = document.getElementById('stationList');
  if (!list) return;
  list.replaceChildren();

  for (const s of stations) {
    const li = document.createElement('li');
    const p  = document.createElement('p');
    p.className = 'mb-1';

    if (originLat !== undefined && s.distance !== undefined) {
      const dist = s.distance >= 1000
        ? (s.distance / 1000).toFixed(2) + ' km'
        : s.distance + ' m';

      const mapsUrl = 'https://www.google.com/maps/dir/?api=1'
        + '&origin='      + encodeURIComponent(originLat + ',' + originLon)
        + '&destination=' + encodeURIComponent(s.lat + ',' + s.lon)
        + '&travelmode=walking';

      const a = document.createElement('a');
      a.href   = mapsUrl;
      a.target = 'wlmonitor';
      const icon = document.createElement('i');
      icon.className = 'fas fa-location-arrow me-2';
      a.appendChild(icon);
      p.appendChild(a);

      const span = document.createElement('span');
      span.textContent = s.station + ' (' + dist + ')';
      span.style.cursor = 'pointer';
      span.addEventListener('click', () => { loadMonitor(s.diva); startMonitorTimer(); });
      p.appendChild(span);
    } else {
      p.textContent = s.station;
      p.style.cursor = 'pointer';
      p.addEventListener('click', () => { loadMonitor(s.diva); startMonitorTimer(); });
    }

    li.appendChild(p);
    list.appendChild(li);
  }
}

// --- Station sort radios + search --------------------------------------------
function wireStationSort() {
  const radios      = document.querySelectorAll('input[name="stationSort"]');
  const searchInput = document.getElementById('s');

  radios.forEach(radio => {
    radio.addEventListener('change', () => {
      if (radio.value === 'dist') {
        if (searchInput) searchInput.classList.add('d-none');
        navigator.geolocation.getCurrentPosition(
          loadStationsByDistance,
          positionError,
          { timeout: 8000 }
        );
      } else if (radio.value === 'alpha') {
        if (searchInput) searchInput.classList.add('d-none');
        loadStationsAlpha();
      } else if (radio.value === 'search') {
        if (searchInput) {
          searchInput.classList.remove('d-none');
          searchInput.focus();
        }
        if (stationCache.length === 0) loadStationsAlpha();
      }
    });
  });

  if (searchInput) {
    searchInput.addEventListener('input', () => {
      const q        = searchInput.value.toLowerCase();
      const filtered = stationCache.filter(s => s.station.toLowerCase().includes(q));
      renderStationList(filtered);
    });
  }
}

function positionError(error) {
  console.warn('Geolocation error (' + error.code + '): ' + error.message);
  loadStationsAlpha();
}

// --- Theme -------------------------------------------------------------------
function applyTheme() {
  const saved = getCookie('theme');
  if (saved === 'dark' || saved === 'light') {
    document.documentElement.dataset.theme = saved;
  }
  // 'auto' or empty: CSS media query handles it
}

function wireThemeToggle() {
  document.querySelectorAll('input[name="themePreference"]').forEach(radio => {
    radio.addEventListener('change', () => {
      if (radio.value === 'auto') {
        delete document.documentElement.dataset.theme;
      } else {
        document.documentElement.dataset.theme = radio.value;
      }
      setCookie('theme', radio.value, 365);
    });
  });
}

// --- Scroll to top -----------------------------------------------------------
function wireScrollButton() {
  const btn = document.getElementById('topBtn');
  if (!btn) return;
  window.addEventListener('scroll', () => {
    btn.style.display = document.documentElement.scrollTop > 20 ? 'block' : 'none';
  });
  btn.addEventListener('click', () => { document.documentElement.scrollTop = 0; });
}

// --- Alerts ------------------------------------------------------------------
export function sendAlert(message, type) {
  type = type || 'info';
  const container = document.getElementById('alerts');
  if (!container) return;
  const div = document.createElement('div');
  div.className = 'alert alert-' + type + ' alert-dismissible fade show';
  div.textContent = message;
  const closeBtn = document.createElement('button');
  closeBtn.type = 'button';
  closeBtn.className = 'btn-close';
  closeBtn.dataset.bsDismiss = 'alert';
  div.appendChild(closeBtn);
  container.appendChild(div);
  setTimeout(() => div.remove(), 6000);
}

// --- Cookies (theme + sId only) ----------------------------------------------
function getCookie(name) {
  for (const part of decodeURIComponent(document.cookie).split(';')) {
    const [k, v] = part.trim().split('=');
    if (k === name) return v || '';
  }
  return '';
}

function setCookie(name, value, days) {
  const d = new Date();
  d.setTime(d.getTime() + days * 24 * 60 * 60 * 1000);
  document.cookie = name + '=' + value + ';expires=' + d.toUTCString() + ';path=/;SameSite=Strict';
}
```

- [ ] **Step 2: Parse check**

```bash
node --input-type=module < /Users/erikr/Git/wlmonitor/web/js/wl-monitor.js 2>&1 | head -5
```

Expected: no output (clean parse, module exits immediately).

- [ ] **Step 3: Commit**

```bash
cd /Users/erikr/Git/wlmonitor
git add web/js/wl-monitor.js
git commit -m "feat: rewrite wl-monitor.js as vanilla ES module -- fetch(), search fix, dark mode"
```

---

## Task 14: Rebuild `web/index.php` as shell

**Files:**
- Modify: `web/index.php`

- [ ] **Step 1: Read current html_body.php nav for reference before replacing index.php**

```bash
head -80 /Users/erikr/Git/wlmonitor/include/html_body.php
```

Note any nav links or IDs you want to preserve, then proceed.

- [ ] **Step 2: Replace `web/index.php`**

```php
<?php
require_once(__DIR__ . '/../include/initialize.php');
header('Content-Type: text/html; charset=utf-8');

// Flush session alerts for JS to consume
$alerts = $_SESSION['alerts'] ?? [];
unset($_SESSION['alerts']);
$alertsJson = json_encode($alerts, JSON_HEX_TAG | JSON_HEX_AMP);

$userID   = (int) ($_SESSION['id'] ?? 0);
$loggedIn = !empty($_SESSION['loggedin']);
$uname    = htmlspecialchars($_SESSION['username'] ?? '', ENT_QUOTES, 'UTF-8');
$rights   = htmlspecialchars($_SESSION['rights']   ?? '', ENT_QUOTES, 'UTF-8');
$theme    = htmlspecialchars($_COOKIE['theme']     ?? 'auto', ENT_QUOTES, 'UTF-8');
?>
<?php include_once(__DIR__ . '/../include/html_header.php'); ?>

<nav class="navbar navbar-expand-lg navbar-dark bg-dark">
  <div class="container-fluid">
    <a class="navbar-brand" href="index.php">
      <i class="fas fa-subway me-1"></i> WL Monitor
    </a>
    <button class="navbar-toggler" type="button"
            data-bs-toggle="collapse" data-bs-target="#navMain">
      <span class="navbar-toggler-icon"></span>
    </button>
    <div class="collapse navbar-collapse" id="navMain">
      <ul class="navbar-nav ms-auto align-items-center gap-1">
        <?php if ($loggedIn): ?>
          <li class="nav-item"><span class="nav-link"><?= $uname ?></span></li>
          <li class="nav-item"><a class="nav-link" href="changePassword.php" title="Passwort andern">
            <i class="fas fa-key"></i></a></li>
          <?php if ($rights === 'Admin'): ?>
            <li class="nav-item"><a class="nav-link" href="admin.php">
              <i class="fas fa-users-cog"></i> Admin</a></li>
          <?php endif; ?>
          <li class="nav-item"><a class="nav-link" href="logout.php" title="Abmelden">
            <i class="fas fa-sign-out-alt"></i></a></li>
        <?php else: ?>
          <li class="nav-item">
            <form class="d-flex gap-1 align-items-center" method="post" action="authentication.php">
              <?= csrf_input() ?>
              <input type="email" name="login-email" class="form-control form-control-sm"
                     placeholder="E-Mail"
                     value="<?= htmlspecialchars($_COOKIE['wlmonitor_username'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
              <input type="password" name="login-password"
                     class="form-control form-control-sm" placeholder="Kennwort">
              <button class="btn btn-sm btn-outline-light" type="submit">
                <i class="fas fa-sign-in-alt"></i>
              </button>
            </form>
          </li>
        <?php endif; ?>
      </ul>
    </div>
  </div>
</nav>

<div id="alerts" class="container-fluid mt-2"></div>

<div class="container-fluid mt-2">
  <div class="row">

    <!-- Monitor panel -->
    <div class="col-md-8">
      <div id="monitor" class="mb-3">
        <div class="spinner-border spinner-border-sm text-secondary" role="status"></div>
        <span class="ms-2 text-muted">Lade Abfahrten ...</span>
      </div>
    </div>

    <!-- Sidebar -->
    <div class="col-md-4">
      <div id="buttons" class="mb-3"></div>

      <!-- Station sort controls -->
      <div class="mb-2">
        <div class="btn-group btn-group-sm w-100" role="group">
          <input type="radio" class="btn-check" name="stationSort"
                 id="sortDist" value="dist" autocomplete="off">
          <label class="btn btn-outline-secondary" for="sortDist">
            <i class="fas fa-map-marker-alt"></i> Nahe
          </label>

          <input type="radio" class="btn-check" name="stationSort"
                 id="sortAlpha" value="alpha" autocomplete="off">
          <label class="btn btn-outline-secondary" for="sortAlpha">
            <i class="fas fa-sort-alpha-down"></i> A-Z
          </label>

          <input type="radio" class="btn-check" name="stationSort"
                 id="sortSearch" value="search" autocomplete="off">
          <label class="btn btn-outline-secondary" for="sortSearch">
            <i class="fas fa-search"></i> Suche
          </label>
        </div>

        <input type="text" id="s" class="form-control form-control-sm mt-1 d-none"
               placeholder="Station suchen ...">

        <div id="stationSortDist" class="d-none mt-1 text-muted small">
          <span class="spinner-border spinner-border-sm"></span> Standort wird ermittelt ...
        </div>
        <div id="stationSortAlpha" class="d-none mt-1 text-muted small">
          <span class="spinner-border spinner-border-sm"></span> Stationen werden geladen ...
        </div>
      </div>

      <ul id="stationList" class="list-unstyled"></ul>

      <!-- Theme toggle -->
      <div class="mt-3">
        <div class="btn-group btn-group-sm" role="group">
          <input type="radio" class="btn-check" name="themePreference"
                 id="themeAuto" value="auto" autocomplete="off"
                 <?= $theme === 'auto'  ? 'checked' : '' ?>>
          <label class="btn btn-outline-secondary" for="themeAuto">Auto</label>

          <input type="radio" class="btn-check" name="themePreference"
                 id="themeLight" value="light" autocomplete="off"
                 <?= $theme === 'light' ? 'checked' : '' ?>>
          <label class="btn btn-outline-secondary" for="themeLight">
            <i class="fas fa-sun"></i>
          </label>

          <input type="radio" class="btn-check" name="themePreference"
                 id="themeDark" value="dark" autocomplete="off"
                 <?= $theme === 'dark'  ? 'checked' : '' ?>>
          <label class="btn btn-outline-secondary" for="themeDark">
            <i class="fas fa-moon"></i>
          </label>
        </div>
      </div>
    </div>

  </div>
</div>

<button id="topBtn" class="btn btn-secondary btn-sm"
        style="display:none;position:fixed;bottom:20px;right:20px;"
        title="Nach oben">
  <i class="fas fa-arrow-up"></i>
</button>

<!-- Bootstrap 5 JS -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"
        integrity="sha384-YvpcrYf0tY3lHB60NNkmXc4s9bIOgUxi8T/jzmB6rVQO0ViiINFjyRUNLnCIE3T"
        crossorigin="anonymous"></script>

<!-- Pass PHP state to JS module -->
<script>
window.wlConfig = {
  userID:   <?= $userID ?>,
  loggedIn: <?= $loggedIn ? 'true' : 'false' ?>,
  alerts:   <?= $alertsJson ?>
};
</script>

<!-- App module -->
<script type="module" src="js/wl-monitor.js"></script>

<?php include_once(__DIR__ . '/../include/html_footer.php'); ?>
```

- [ ] **Step 3: Check syntax**

```bash
php -l /Users/erikr/Git/wlmonitor/web/index.php
```

- [ ] **Step 4: Commit**

```bash
cd /Users/erikr/Git/wlmonitor
git add web/index.php
git commit -m "feat: rebuild index.php as Bootstrap 5 shell with wlConfig alert bridge"
```

---

## Task 15: Create `web/admin.php` (new admin page)

**Files:**
- Create: `web/admin.php`

Bootstrap 5 user table with inline edit modal. All mutations go through `api.php`.

- [ ] **Step 1: Write `web/admin.php`**

```php
<?php
require_once(__DIR__ . '/../include/initialize.php');
require_once(__DIR__ . '/../inc/admin.php');

if (empty($_SESSION['loggedin']) || ($_SESSION['rights'] ?? '') !== 'Admin') {
    header('Location: index.php'); exit;
}

header('Content-Type: text/html; charset=utf-8');

$page   = max(1, (int) ($_GET['page'] ?? 1));
$filter = htmlspecialchars($_GET['filter'] ?? '', ENT_QUOTES, 'UTF-8');
$data   = admin_list_users($con, $page, 25, $filter);
$users  = $data['users'];
$total  = $data['total'];
$pages  = (int) ceil($total / 25);

$csrfToken = csrf_token();
?>
<?php include_once(__DIR__ . '/../include/html_header.php'); ?>

<nav class="navbar navbar-dark bg-dark mb-3">
  <div class="container-fluid">
    <span class="navbar-brand">
      <i class="fas fa-users-cog me-1"></i> Benutzerverwaltung
    </span>
    <a href="index.php" class="btn btn-sm btn-outline-light">
      <i class="fas fa-arrow-left me-1"></i> Monitor
    </a>
  </div>
</nav>

<div id="adminAlerts" class="container-fluid"></div>

<div class="container-fluid">
  <form class="d-flex gap-2 mb-3" method="get">
    <input type="text" name="filter" class="form-control form-control-sm w-auto"
           placeholder="Username suchen" value="<?= $filter ?>">
    <button class="btn btn-sm btn-secondary" type="submit">
      <i class="fas fa-search"></i>
    </button>
  </form>

  <div class="table-responsive">
    <table class="table table-sm table-hover">
      <thead class="table-dark">
        <tr>
          <th>ID</th><th>Username</th><th>E-Mail</th><th>Rechte</th>
          <th>Aktiv</th><th>Abfahrten</th><th>Debug</th><th></th>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($users as $u): ?>
        <tr>
          <td><?= $u['id'] ?></td>
          <td><?= htmlspecialchars($u['username'], ENT_QUOTES, 'UTF-8') ?></td>
          <td><?= htmlspecialchars($u['email'],    ENT_QUOTES, 'UTF-8') ?></td>
          <td><?= htmlspecialchars($u['rights'],   ENT_QUOTES, 'UTF-8') ?></td>
          <td><?= $u['disabled'] ? 'gesperrt' : 'aktiv' ?></td>
          <td><?= $u['departures'] ?></td>
          <td><?= $u['debug'] ? 'ja' : '' ?></td>
          <td class="text-nowrap">
            <button class="btn btn-sm btn-outline-primary btn-edit"
              data-id="<?= $u['id'] ?>"
              data-email="<?= htmlspecialchars($u['email'],    ENT_QUOTES, 'UTF-8') ?>"
              data-rights="<?= htmlspecialchars($u['rights'],  ENT_QUOTES, 'UTF-8') ?>"
              data-disabled="<?= $u['disabled'] ?>"
              data-departures="<?= $u['departures'] ?>"
              data-debug="<?= $u['debug'] ?>"
              data-bs-toggle="modal" data-bs-target="#editModal">
              Bearbeiten
            </button>
            <button class="btn btn-sm btn-outline-warning btn-reset"
                    data-id="<?= $u['id'] ?>">Passwort</button>
            <button class="btn btn-sm btn-outline-danger btn-delete"
                    data-id="<?= $u['id'] ?>">Loschen</button>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>

  <?php if ($pages > 1): ?>
  <nav><ul class="pagination pagination-sm">
    <?php for ($p = 1; $p <= $pages; $p++): ?>
      <li class="page-item <?= $p === $page ? 'active' : '' ?>">
        <a class="page-link"
           href="?page=<?= $p ?>&amp;filter=<?= urlencode($filter) ?>"><?= $p ?></a>
      </li>
    <?php endfor; ?>
  </ul></nav>
  <?php endif; ?>
</div>

<!-- Edit Modal -->
<div class="modal fade" id="editModal" tabindex="-1" aria-labelledby="editModalLabel">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="editModalLabel">Benutzer bearbeiten</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <form id="editForm">
        <div class="modal-body">
          <input type="hidden" name="id" id="editId">
          <input type="hidden" name="csrf_token"
                 value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
          <div class="mb-2">
            <label class="form-label" for="editEmail">E-Mail</label>
            <input type="email" name="email" id="editEmail" class="form-control">
          </div>
          <div class="mb-2">
            <label class="form-label" for="editRights">Rechte</label>
            <select name="rights" id="editRights" class="form-select">
              <option value="User">User</option>
              <option value="Admin">Admin</option>
            </select>
          </div>
          <div class="form-check mb-2">
            <input class="form-check-input" type="checkbox" name="disabled"
                   id="editDisabled" value="1">
            <label class="form-check-label" for="editDisabled">Gesperrt</label>
          </div>
          <div class="mb-2">
            <label class="form-label" for="editDepartures">Abfahrten</label>
            <input type="number" name="departures" id="editDepartures"
                   class="form-control" min="1" max="10">
          </div>
          <div class="form-check">
            <input class="form-check-input" type="checkbox" name="debug"
                   id="editDebug" value="1">
            <label class="form-check-label" for="editDebug">Debug</label>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary"
                  data-bs-dismiss="modal">Abbrechen</button>
          <button type="submit" class="btn btn-primary">Speichern</button>
        </div>
      </form>
    </div>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"
        integrity="sha384-YvpcrYf0tY3lHB60NNkmXc4s9bIOgUxi8T/jzmB6rVQO0ViiINFjyRUNLnCIE3T"
        crossorigin="anonymous"></script>
<script>
const CSRF = <?= json_encode($csrfToken) ?>;

function showAlert(msg, type) {
  const container = document.getElementById('adminAlerts');
  const div = document.createElement('div');
  div.className = 'alert alert-' + (type || 'info') + ' alert-dismissible fade show';
  div.textContent = msg;
  const btn = document.createElement('button');
  btn.type = 'button';
  btn.className = 'btn-close';
  btn.dataset.bsDismiss = 'alert';
  div.appendChild(btn);
  container.appendChild(div);
  setTimeout(() => div.remove(), 5000);
}

async function adminPost(action, params) {
  const fd = new FormData();
  fd.append('action', action);
  fd.append('csrf_token', CSRF);
  for (const [k, v] of Object.entries(params)) fd.append(k, v);
  const r = await fetch('api.php', { method: 'POST', body: fd });
  return r.json();
}

// Populate edit modal from button data attributes
document.querySelectorAll('.btn-edit').forEach(btn => {
  btn.addEventListener('click', () => {
    document.getElementById('editId').value          = btn.dataset.id;
    document.getElementById('editEmail').value       = btn.dataset.email;
    document.getElementById('editRights').value      = btn.dataset.rights;
    document.getElementById('editDisabled').checked  = btn.dataset.disabled === '1';
    document.getElementById('editDepartures').value  = btn.dataset.departures;
    document.getElementById('editDebug').checked     = btn.dataset.debug === '1';
  });
});

document.getElementById('editForm').addEventListener('submit', async e => {
  e.preventDefault();
  const fd  = new FormData(e.target);
  const res = await adminPost('admin_user_edit', Object.fromEntries(fd));
  if (res.ok) {
    showAlert('Gespeichert.', 'success');
    bootstrap.Modal.getInstance(document.getElementById('editModal')).hide();
    setTimeout(() => location.reload(), 900);
  } else {
    showAlert('Fehler beim Speichern.', 'danger');
  }
});

document.querySelectorAll('.btn-reset').forEach(btn => {
  btn.addEventListener('click', async () => {
    if (!confirm('Passwort fur Benutzer #' + btn.dataset.id + ' zurucksetzen?')) return;
    const res = await adminPost('admin_user_reset', { id: btn.dataset.id });
    if (res.password) {
      showAlert('Neues Passwort: ' + res.password, 'warning');
    } else {
      showAlert('Fehler beim Zurucksetzen.', 'danger');
    }
  });
});

document.querySelectorAll('.btn-delete').forEach(btn => {
  btn.addEventListener('click', async () => {
    if (!confirm('Benutzer #' + btn.dataset.id + ' wirklich loschen?')) return;
    const res = await adminPost('admin_user_delete', { id: btn.dataset.id });
    if (res.ok) {
      showAlert('Geloscht.', 'success');
      setTimeout(() => location.reload(), 900);
    } else {
      showAlert('Fehler beim Loschen.', 'danger');
    }
  });
});
</script>

<?php include_once(__DIR__ . '/../include/html_footer.php'); ?>
```

- [ ] **Step 2: Check syntax**

```bash
php -l /Users/erikr/Git/wlmonitor/web/admin.php
```

Expected: `No syntax errors detected`

- [ ] **Step 3: Commit**

```bash
cd /Users/erikr/Git/wlmonitor
git add web/admin.php
git commit -m "feat: add web/admin.php -- Bootstrap 5 user editor replacing ajaxCRUD"
```

---

## Task 16: Update `web/editFavorite.php` to Bootstrap 5 + CSRF

**Files:**
- Modify: `web/editFavorite.php`

Add CSRF check, update Bootstrap version, fix `input-group-prepend` (BS4) to `input-group-text` (BS5), remove jQuery CDN.

- [ ] **Step 1: Replace the head section (lines 36-45)**

In `web/editFavorite.php`, replace everything between `<head>` and `</head>` with:

```html
<head>
  <title>Favorit bearbeiten - WL Monitor</title>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <link rel="stylesheet"
        href="https://use.fontawesome.com/releases/v5.7.0/css/all.css"
        integrity="sha384-lZN37f5QGtY3VHgisS14W3ExzMWZxybE1SJSEsQp9S+oqd12jhcu+A56Ebc1zFSJ"
        crossorigin="anonymous">
  <link rel="stylesheet"
        href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css"
        integrity="sha384-QWTKZyjpPEjISv5WaRU9OFeRpok6YctnYmDr5pNlyT2bRjXh0JMhjY6hW+ALEwIH"
        crossorigin="anonymous">
  <link rel="stylesheet" href="css/theme.css">
  <link rel="stylesheet" href="style/wl-monitor.css">
</head>
```

- [ ] **Step 2: Add CSRF check to the POST handler**

After the line `if (isset($_POST['favID'])) {` (around line 70), add:

```php
    if (!csrf_verify()) {
        $_SESSION['Error'] = 'Ungultige Anfrage.';
        header('Location: index.php'); exit;
    }
```

- [ ] **Step 3: Add CSRF token to the form**

After the `<form action='editFavourite.php?favID=...` opening tag, add:

```php
<?= csrf_input() ?>
```

- [ ] **Step 4: Replace Bootstrap 4 script block at bottom**

Remove all `<script src=...jquery...>`, `<script src=...popper...>`, `<script src=...bootstrap 4...>`, `<script src=...jquery.validate...>` tags.

Replace with:

```html
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"
        integrity="sha384-YvpcrYf0tY3lHB60NNkmXc4s9bIOgUxi8T/jzmB6rVQO0ViiINFjyRUNLnCIE3T"
        crossorigin="anonymous"></script>
```

Also replace any `data-dismiss="alert"` or `data-dismiss="modal"` with `data-bs-dismiss="alert"` and `data-bs-dismiss="modal"`.

Replace `input-group-prepend` and `input-group-append` class references: each `<span class="input-group-prepend ...">` becomes `<span class="input-group-text ...">`.

- [ ] **Step 5: Check syntax**

```bash
php -l /Users/erikr/Git/wlmonitor/web/editFavorite.php
```

- [ ] **Step 6: Commit**

```bash
cd /Users/erikr/Git/wlmonitor
git add web/editFavorite.php
git commit -m "refactor: editFavorite.php -- Bootstrap 5, CSRF protection, remove jQuery"
```

---

## Task 17: End-to-end smoke test + cleanup

**Files:**
- No new files; browser and curl verification

- [ ] **Step 1: Start local PHP server**

```bash
cd /Users/erikr/Git/wlmonitor/web
php -S localhost:8080 2>/dev/null &
sleep 1
```

- [ ] **Step 2: API smoke tests**

```bash
# Monitor (no auth) -- should return JSON with trains/update_at
curl -s "http://localhost:8080/api.php?action=monitor&diva=60200103" | python3 -m json.tool | head -15

# Stations alpha -- should return array of station objects
curl -s "http://localhost:8080/api.php?action=stations" | python3 -m json.tool | head -10

# Unknown action -- should return 400
STATUS=$(curl -s -o /dev/null -w "%{http_code}" "http://localhost:8080/api.php?action=bogus")
echo "bogus action: $STATUS"  # expected: 400

# Favorites without session -- should return 401
STATUS=$(curl -s -o /dev/null -w "%{http_code}" "http://localhost:8080/api.php?action=favorites")
echo "favorites unauthenticated: $STATUS"  # expected: 401
```

- [ ] **Step 3: Browser walkthrough**

Open `http://localhost:8080/` in a browser and verify:
- Page loads with Bootstrap 5 navbar
- Monitor panel populates with departure cards (station name, line, countdown)
- A-Z radio button loads station list
- Search radio reveals text input and filters list as you type
- Theme toggle switches dark/light by changing `data-theme` on `<html>`
- Login form submits to authentication.php (CSRF token present in form source)
- No `$ is not defined` or jQuery errors in console (jQuery is gone)
- No `innerHTML` with user data visible in DevTools

- [ ] **Step 4: Stop server + final commit**

```bash
kill %1
cd /Users/erikr/Git/wlmonitor
git add -A
git commit -m "chore: full refactor complete"
```

---

## Self-Review

**Spec coverage:**

| Spec requirement | Task(s) |
|---|---|
| inc/ module structure | Tasks 5-9 |
| Single api.php dispatcher | Task 10 |
| CSRF protection (all POSTs + forms) | Tasks 3, 10, 11, 14, 15, 16 |
| Session cookie hardening (httponly, secure, samesite) | Task 2 |
| Login rate limiting (file-based, 5 attempts / 15 min) | Task 5 |
| bcrypt cost 13 + transparent upgrade on login | Task 5 |
| Fix search function (radio handler + client-side filter) | Task 13 |
| RBL/DIVA column rename in DB | Task 4 |
| Remove duplicate HTML monitor path | Task 1 (moved to deprecated/) |
| Bootstrap 5 migration | Tasks 12, 14, 15 |
| CSS custom properties dark mode (no CSS file swap) | Task 12 |
| Vanilla JS fetch() replacing jQuery | Task 13 |
| Replace ajaxCRUD with custom admin | Tasks 9, 15 |
| Consistent output escaping | inc/ modules + api.php (JSON_HEX_TAG) |
| Favorites sort-save fix (proper JSON body) | Task 8 (favorites_save_sort), Task 10 |
| Files to deprecated/ not deleted | Task 1 |
| wl_favorites.rbls also renamed to diva | Task 4 (Step 3) |

All spec requirements covered. No placeholders. Function names used in api.php (Task 10) match definitions in Tasks 5-9. `diva` column referenced after Task 4 migration in all inc/ modules. `csrf_token()` / `csrf_verify()` / `csrf_input()` defined in Task 3, consumed from Task 10 onward.
