<?php
// inc/board_guest_wifi.php
//
// Gaeste-WLAN fuer den Schlafschirm (Nutzerwunsch 2026-08-23): SSID und
// Passwort als QR-Code, damit Besuch sich ohne Abtippen verbinden kann.
//
// TASK-27 (2026-09-03): Zugangsdaten kommen jetzt aus wl_board_settings
// (board_settings_load()) statt aus data/guest_wifi.json -- ueber die neue
// Admin-Seite board_settings.php pflegbar statt von Hand auf dem Server.
declare(strict_types=1);

/**
 * Baut die Zugangsdaten aus dem Ergebnis von board_settings_load(). Liefert
 * null bei leerer SSID -- der Schlafschirm laesst den QR-Block dann einfach
 * weg, statt einen kaputten Code zu zeigen (gleiches Verhalten wie zuvor bei
 * fehlender data/guest_wifi.json).
 *
 * Ein leeres Passwort ist zulaessig (offenes Netz, encryption "nopass").
 *
 * @param array{wifi_ssid: string, wifi_password: string, wifi_encryption: string, wifi_hidden: bool} $settings
 * @return array{ssid: string, password: string, encryption: string, hidden: bool}|null
 */
function board_guest_wifi_load(array $settings): ?array
{
    if ($settings['wifi_ssid'] === '') {
        return null;
    }

    $encryption = strtoupper($settings['wifi_encryption']);
    if (!in_array($encryption, ['WPA', 'WEP', 'NOPASS'], true)) {
        $encryption = 'WPA';
    }

    return [
        'ssid'       => $settings['wifi_ssid'],
        'password'   => $settings['wifi_password'],
        'encryption' => $encryption,
        'hidden'     => $settings['wifi_hidden'],
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
