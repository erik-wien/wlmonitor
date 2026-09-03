<?php
declare(strict_types=1);

/**
 * web/mqtt/index.php
 *
 * Mini-Tool, bewusst NICHT mit dem restlichen wlmonitor verlinkt (kein
 * Menue-Eintrag) -- nutzt aber dieselbe Server-Infrastruktur: publiziert
 * lokal (127.0.0.1:1883) an den bestehenden MQTT-Broker, den auch
 * scripts/akadbrain/mqtt_subscriber.py liest, und landet damit auf der
 * Board-Nachrichtenseite (inc/board_mqtt.php).
 *
 * Absichtlich serverseitig statt Browser-MQTT: der Broker spricht nur
 * unverschluesseltes ws://, eine per HTTPS ausgelieferte Seite duerfte das
 * per Mixed-Content-Regel gar nicht aufrufen. Ein simpler POST an dieses
 * PHP-Skript, das lokal auf demselben Host publiziert, umgeht das Problem
 * komplett und haelt die Broker-Zugangsdaten aus dem Browser heraus.
 *
 * Eigener Broker-User "sender" (write-only ACL auf MQTT_TOPIC, siehe
 * /opt/homebrew/etc/mosquitto/aclfile auf akadbrain) -- getrennt vom
 * "wlmonitor"-User des Readers, damit ein Leck dieser Seite nicht den
 * Lesezugriff mit ausliefert.
 *
 * Nutzerentscheidung 2026-09-01: Login-Pflicht (auth_require()), aber KEINE
 * Rolleneinschraenkung -- jeder eingeloggte User darf senden. Deshalb ganz
 * normal initialize.php (Session/CSRF/DB), trotzdem kein Menue-Eintrag und
 * keine Chrome\Header::render()-Einbindung -- die Seite bleibt optisch
 * eigenstaendig, nur der Zugriff ist jetzt an die Session gebunden.
 *
 * TASK-27 (2026-09-03): User/Passwort kommen jetzt aus wl_board_settings
 * (board_settings_load(), Admin-Seite board_settings.php) statt aus einer
 * hartcodierten Konstante. Uebergang: ist mqtt_sender_password in der DB noch
 * leer (Migration frisch eingespielt, noch nichts gespeichert), faellt es auf
 * das alte hartcodierte Passwort zurueck, bis ein Admin einmal ueber die
 * neue Seite ein echtes Passwort setzt (das rotiert dann gleichzeitig den
 * Broker, s. board_settings_sync_mqtt_broker_password()).
 */

require_once __DIR__ . '/../../inc/initialize.php';
require_once __DIR__ . '/../../inc/board_settings.php';

// Nur dort, wo das Display und sein Broker ueberhaupt existieren (eriks.cloud
// auf akadbrain, s. BOARD_FEATURE_AVAILABLE). Auf jardyx.com laeuft kein
// mosquitto -- die Seite koennte dort ausschliesslich "Senden fehlgeschlagen"
// melden. Das Ausblenden des Menuepunkts allein reicht nicht: die URL ist
// erratbar (Nutzervorgabe 2026-09-03).
if (!BOARD_FEATURE_AVAILABLE) {
    http_response_code(404);
    exit;
}

auth_require();

const MQTT_HOST = '127.0.0.1';
const MQTT_PORT = 1883;
const MQTT_TOPIC = 'wlmonitor/board/message';
const MQTT_PUB_BIN = '/opt/homebrew/bin/mosquitto_pub';
// Uebergangs-Fallback, s. Docblock oben -- wird geloescht, sobald jede
// Instanz einmal ueber board_settings.php ein echtes Passwort gesetzt hat.
const MQTT_PASSWORD_FALLBACK = 'f49d486303c764f6c749b279f0122804';

const MAX_TITEL_CHARS = 200;
const MAX_TEXT_CHARS = 500;

$boardSettings = board_settings_load($con);
$mqttUser = $boardSettings['mqtt_sender_user'];
$mqttPassword = $boardSettings['mqtt_sender_password'] !== ''
    ? $boardSettings['mqtt_sender_password']
    : MQTT_PASSWORD_FALLBACK;

$status = null;
$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $titel = mb_substr(trim((string)($_POST['titel'] ?? '')), 0, MAX_TITEL_CHARS);
    $text = mb_substr(trim((string)($_POST['text'] ?? '')), 0, MAX_TEXT_CHARS);

    if (!csrf_verify()) {
        $error = 'Sicherheits-Token abgelaufen -- Seite neu laden und nochmal versuchen.';
    } elseif ($titel === '' && $text === '') {
        $error = 'Titel oder Text ausfüllen.';
    } else {
        $payload = json_encode(['title' => $titel, 'body' => $text], JSON_UNESCAPED_UNICODE);
        $cmd = sprintf(
            '%s -h %s -p %d -u %s -P %s -t %s -m %s 2>&1',
            escapeshellarg(MQTT_PUB_BIN),
            escapeshellarg(MQTT_HOST),
            MQTT_PORT,
            escapeshellarg($mqttUser),
            escapeshellarg($mqttPassword),
            escapeshellarg(MQTT_TOPIC),
            escapeshellarg($payload)
        );
        exec($cmd, $output, $exitCode);

        if ($exitCode === 0) {
            $status = 'Gesendet.';
            $titel = '';
            $text = '';
        } else {
            $error = 'Senden fehlgeschlagen -- Broker nicht erreichbar.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="de">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="theme-color" content="#0b1120">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
<meta name="apple-mobile-web-app-title" content="Board-Nachricht">
<title>Board-Nachricht senden</title>
<style>
  :root {
    --bg: #0b1120; --card: #1e293b; --text: #f1f5f9; --muted: #94a3b8;
    --accent: #38bdf8; --accent-2: #818cf8; --ok: #22c55e; --err: #ef4444;
    --border: #334155; --radius: 14px;
  }
  * { box-sizing: border-box; -webkit-tap-highlight-color: transparent; }
  html, body { height: 100%; }
  body {
    margin: 0; font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
    background: var(--bg); color: var(--text);
    padding: max(env(safe-area-inset-top), 16px) 16px max(env(safe-area-inset-bottom), 16px);
    display: flex; flex-direction: column; min-height: 100vh;
  }
  h1 { font-size: 18px; margin: 0 0 14px; font-weight: 600; }
  main { flex: 1; display: flex; flex-direction: column; gap: 12px; max-width: 480px; margin: 0 auto; width: 100%; }
  .field label { display: block; font-size: 13px; color: var(--muted); margin: 0 0 6px 2px; }
  input[type=text], textarea {
    width: 100%; padding: 13px 14px; border-radius: var(--radius);
    background: var(--card); color: var(--text); border: 1px solid var(--border);
    font-size: 16px; outline: none;
  }
  input:focus, textarea:focus { border-color: var(--accent); }
  textarea { resize: vertical; min-height: 96px; font-family: inherit; }
  button {
    border: none; cursor: pointer; font-size: 16px; font-weight: 600;
    padding: 15px 18px; border-radius: var(--radius); color: #0b1120;
    background: linear-gradient(135deg, var(--accent), var(--accent-2));
    margin-top: 4px;
  }
  button:active { transform: scale(.985); }
  .msg { padding: 12px 16px; border-radius: var(--radius); font-size: 14px; border: 1px solid var(--border); }
  .msg.ok { border-color: var(--ok); color: var(--ok); }
  .msg.err { border-color: var(--err); color: var(--err); }
</style>
</head>
<body>
<main>
  <h1>Nachricht ans Board</h1>
  <?php if ($status !== null): ?>
    <div class="msg ok"><?= htmlspecialchars($status, ENT_QUOTES) ?></div>
  <?php endif; ?>
  <?php if ($error !== null): ?>
    <div class="msg err"><?= htmlspecialchars($error, ENT_QUOTES) ?></div>
  <?php endif; ?>
  <form method="post">
    <?= csrf_input() ?>
    <div class="field">
      <label for="titel">Titel</label>
      <input type="text" id="titel" name="titel" autocomplete="off" maxlength="<?= MAX_TITEL_CHARS ?>" value="<?= htmlspecialchars($titel ?? '', ENT_QUOTES) ?>">
    </div>
    <div class="field">
      <label for="text">Text</label>
      <textarea id="text" name="text" maxlength="<?= MAX_TEXT_CHARS ?>"><?= htmlspecialchars($text ?? '', ENT_QUOTES) ?></textarea>
    </div>
    <button type="submit">Senden</button>
  </form>
</main>
</body>
</html>
