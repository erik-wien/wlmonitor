<?php
// inc/board_render.php
//
// Rendering-Pipeline aus Spec §7: SVG-String -> PNG-Bytes (rsvg-convert) ->
// gepackte 1bpp-Rohdaten (ext-gd, harter Schwellwert). Das eigentliche
// Board-SVG-Template (Layout) ist NICHT Teil dieser Datei/dieses Plans --
// diese Funktionen sind reine Infrastruktur, unabhaengig vom Inhalt.
declare(strict_types=1);

/**
 * Rendert einen SVG-String zu PNG-Bytes via rsvg-convert (Subprozess).
 * Array-Form von proc_open -- kein Shell-String, keine Escaping-Fragen
 * unabhaengig vom SVG-Inhalt.
 *
 * @throws RuntimeException wenn rsvg-convert nicht startet oder fehlschlaegt
 */
function svg_to_png(string $svg): string
{
    $descriptors = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];

    $process = proc_open(['rsvg-convert', '-f', 'png'], $descriptors, $pipes);
    if (!is_resource($process)) {
        throw new RuntimeException('rsvg-convert konnte nicht gestartet werden');
    }

    fwrite($pipes[0], $svg);
    fclose($pipes[0]);

    $png = stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[2]);

    $exitCode = proc_close($process);

    if ($exitCode !== 0 || $png === false || $png === '') {
        throw new RuntimeException(
            'rsvg-convert fehlgeschlagen (Exit ' . $exitCode . '): ' . trim((string) $stderr)
        );
    }

    return $png;
}
