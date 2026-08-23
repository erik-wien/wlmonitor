<?php
// inc/board_guest_wifi.php
//
// Gaeste-WLAN fuer den Schlafschirm (Nutzerwunsch 2026-08-23): SSID und
// Passwort als QR-Code, damit Besuch sich ohne Abtippen verbinden kann.
//
// Die Zugangsdaten liegen BEWUSST in data/ und nicht im Repo (Nutzerentscheid
// 2026-08-23): data/ ist von deploy.py ausgenommen und in .gitignore, ein
// WLAN-Passwort landet damit weder in der Versionsgeschichte noch in einem
// Deploy-Artefakt. Preis dafuer: die Datei wird pro Instanz von Hand gepflegt,
// Vorlage ist data/guest_wifi.example.json.
declare(strict_types=1);

/** Voreingestellter Ablageort, relativ zum Repo-/Instanzwurzelverzeichnis. */
function board_guest_wifi_path(): string
{
    return __DIR__ . '/../data/guest_wifi.json';
}

/**
 * Laedt die Zugangsdaten. Liefert null, wenn die Datei fehlt, unlesbar ist,
 * kein gueltiges JSON enthaelt oder keine SSID nennt -- der Schlafschirm
 * laesst den QR-Block dann einfach weg, statt einen kaputten Code zu zeigen.
 *
 * Ein leeres Passwort ist zulaessig (offenes Netz, encryption "nopass").
 *
 * @return array{ssid: string, password: string, encryption: string, hidden: bool}|null
 */
function board_guest_wifi_load(?string $path = null): ?array
{
    $path ??= board_guest_wifi_path();

    if (!is_file($path) || !is_readable($path)) {
        return null;
    }

    $raw = file_get_contents($path);
    if ($raw === false) {
        return null;
    }

    $cfg = json_decode($raw, true);
    if (!is_array($cfg) || !isset($cfg['ssid']) || !is_string($cfg['ssid']) || $cfg['ssid'] === '') {
        return null;
    }

    $encryption = isset($cfg['encryption']) && is_string($cfg['encryption'])
        ? strtoupper($cfg['encryption'])
        : 'WPA';
    if (!in_array($encryption, ['WPA', 'WEP', 'NOPASS'], true)) {
        $encryption = 'WPA';
    }

    return [
        'ssid'       => $cfg['ssid'],
        'password'   => isset($cfg['password']) && is_string($cfg['password']) ? $cfg['password'] : '',
        'encryption' => $encryption,
        'hidden'     => (bool) ($cfg['hidden'] ?? false),
    ];
}

/**
 * Sonderzeichen im WIFI:-Schema maskieren. Backslash, Semikolon, Komma,
 * Doppelpunkt und Anfuehrungszeichen trennen im Schema die Felder -- ein
 * Passwort mit einem davon (z.B. "Sommer;2026") ergaebe ohne Maskierung
 * einen QR-Code, der ein falsches Netz oder Passwort einliest.
 */
function board_guest_wifi_escape(string $value): string
{
    return str_replace(
        ['\\', ';', ',', ':', '"'],
        ['\\\\', '\\;', '\\,', '\\:', '\\"'],
        $value
    );
}

/**
 * Baut die Nutzlast nach dem verbreiteten WIFI:-Schema, das Android und iOS
 * beim Scannen als "mit Netz verbinden?" anbieten.
 *
 * @param array{ssid: string, password: string, encryption: string, hidden: bool} $wifi
 */
function board_guest_wifi_payload(array $wifi): string
{
    $parts = 'WIFI:T:' . ($wifi['encryption'] === 'NOPASS' ? 'nopass' : $wifi['encryption']) . ';'
        . 'S:' . board_guest_wifi_escape($wifi['ssid']) . ';';

    if ($wifi['encryption'] !== 'NOPASS') {
        $parts .= 'P:' . board_guest_wifi_escape($wifi['password']) . ';';
    }
    if ($wifi['hidden']) {
        $parts .= 'H:true;';
    }

    return $parts . ';';
}
