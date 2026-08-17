<?php
// inc/board_state.php
//
// Pro-Geraet-Zustand fuer das Board-Protokoll (Spec §4): welcher Favorit ist
// aktiv, welche Seite, welcher Frame + ETag wurde zuletzt geschickt, wann
// war der letzte Vollbild-Refresh. Zwei Dateien pro Geraet unter
// data/board_state/, Schluessel = SHA-256(Token) -- kein $_SESSION.
//
// Pattern A (flock read-modify-write), analog zu RATE_LIMIT_FILE
// (vendor/erikr/auth/src/auth.php). Meta und Frame sind bewusst getrennte
// Dateien statt eines JSON-Feldes: der Frame ist ~330 KB roher Bytes, Base64
// im JSON waere 33% groesser und wuerde jede Meta-Lese/Schreib-Operation
// unnoetig verlangsamen.
declare(strict_types=1);

function board_state_dir(): string
{
    return __DIR__ . '/../data/board_state';
}

function board_state_hash(string $token): string
{
    return hash('sha256', $token);
}

function board_state_meta_path(string $hash): string
{
    return board_state_dir() . '/' . $hash . '.json';
}

function board_state_frame_path(string $hash): string
{
    return board_state_dir() . '/' . $hash . '.frame';
}

/** @return array{activeFavoriteIndex:int, activePage:int, etag:?string, fullRefreshAt:?int} */
function board_state_default_meta(): array
{
    return ['activeFavoriteIndex' => 0, 'activePage' => 1, 'etag' => null, 'fullRefreshAt' => null];
}

/** @return array{activeFavoriteIndex:int, activePage:int, etag:?string, fullRefreshAt:?int} */
function board_state_load_meta(string $path): array
{
    if (!file_exists($path)) {
        return board_state_default_meta();
    }
    $fp = fopen($path, 'r');
    if ($fp === false) {
        return board_state_default_meta();
    }
    flock($fp, LOCK_SH);
    $raw = stream_get_contents($fp);
    flock($fp, LOCK_UN);
    fclose($fp);

    $data = json_decode((string) $raw, true);
    return is_array($data) ? array_merge(board_state_default_meta(), $data) : board_state_default_meta();
}

function board_state_save_meta(string $path, array $meta): void
{
    $dir = dirname($path);
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }
    $fp = fopen($path, 'c+');
    if ($fp === false) {
        throw new RuntimeException('board_state: Meta-Datei nicht beschreibbar: ' . $path);
    }
    flock($fp, LOCK_EX);
    ftruncate($fp, 0);
    rewind($fp);
    fwrite($fp, json_encode($meta, JSON_UNESCAPED_SLASHES));
    flock($fp, LOCK_UN);
    fclose($fp);
}

function board_state_load_frame(string $path): ?string
{
    if (!file_exists($path)) {
        return null;
    }
    $fp = fopen($path, 'r');
    if ($fp === false) {
        return null;
    }
    flock($fp, LOCK_SH);
    $raw = stream_get_contents($fp);
    flock($fp, LOCK_UN);
    fclose($fp);

    return ($raw === false || $raw === '') ? null : $raw;
}

function board_state_save_frame(string $path, string $packed): void
{
    $dir = dirname($path);
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }
    $fp = fopen($path, 'c+');
    if ($fp === false) {
        throw new RuntimeException('board_state: Frame-Datei nicht beschreibbar: ' . $path);
    }
    flock($fp, LOCK_EX);
    ftruncate($fp, 0);
    rewind($fp);
    fwrite($fp, $packed);
    flock($fp, LOCK_UN);
    fclose($fp);
}

/**
 * Loest ein X-Device-Touch-Ereignis gegen den gespeicherten Zustand auf
 * (Spec §4 Schritt 3). Reine Funktion -- kennt die tatsaechliche Seitenzahl
 * NICHT (die haengt von den gerade geladenen Abfahrtsdaten ab, die dieser
 * Funktion nicht vorliegen). page_next erhoeht daher unbegrenzt; der
 * Aufrufer (web/board.php) klemmt das Ergebnis gegen die reale Seitenzahl,
 * bevor er board_render_svg() aufruft und den Zustand speichert.
 *
 * @param array{activeFavoriteIndex?:int, activePage?:int} $meta
 * @return array{activeFavoriteIndex:int, activePage:int}
 */
function board_resolve_touch(array $meta, ?string $touch, int $favoriteCount): array
{
    $index = $favoriteCount > 0
        ? max(0, min($favoriteCount - 1, (int) ($meta['activeFavoriteIndex'] ?? 0)))
        : 0;
    $page = max(1, (int) ($meta['activePage'] ?? 1));

    if ($touch !== null && str_starts_with($touch, 'fav') && ctype_digit(substr($touch, 3))) {
        $requested = (int) substr($touch, 3);
        if ($requested < $favoriteCount) {
            $index = $requested;
            $page = 1;
        }
    } elseif ($touch === 'page_prev') {
        $page = max(1, $page - 1);
    } elseif ($touch === 'page_next') {
        $page++;
    }

    return ['activeFavoriteIndex' => $index, 'activePage' => $page];
}
