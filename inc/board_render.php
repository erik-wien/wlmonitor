<?php
// inc/board_render.php
//
// Rendering-Pipeline aus Spec §7: SVG-String -> PNG-Bytes (rsvg-convert) ->
// gepackte 1bpp-Rohdaten (ext-gd, harter Schwellwert). Das eigentliche
// Board-SVG-Template (Layout) ist NICHT Teil dieser Datei/dieses Plans --
// diese Funktionen sind reine Infrastruktur, unabhaengig vom Inhalt.
declare(strict_types=1);

/**
 * Erzeugt (bzw. aktualisiert) eine Fontconfig-XML, die ausschliesslich auf
 * assets/fonts/board/ zeigt, und gibt ihren Pfad zurueck. rsvg-convert
 * findet Schriften ueber Fontconfig, nicht ueber @font-face -- ohne dieses
 * Env-Var faellt "Atkinson Hyperlegible" still auf eine Systemschrift
 * zurueck (kein Fehler, nur falsche Optik). Pfad relativ zu __DIR__, damit
 * Dev und Prod ohne Konfigurationsaenderung funktionieren.
 *
 * @throws RuntimeException wenn assets/fonts/board fehlt
 */
function board_fontconfig_path(): string
{
    $fontsDir = realpath(__DIR__ . '/../assets/fonts/board');
    if ($fontsDir === false) {
        throw new RuntimeException('assets/fonts/board nicht gefunden');
    }

    $cacheDir = sys_get_temp_dir() . '/wlmonitor-board-fontcache';
    if (!is_dir($cacheDir)) {
        mkdir($cacheDir, 0755, true);
    }

    $xml = '<?xml version="1.0"?>' . "\n"
        . '<!DOCTYPE fontconfig SYSTEM "fonts.dtd">' . "\n"
        . '<fontconfig>' . "\n"
        . '  <dir>' . $fontsDir . '</dir>' . "\n"
        . '  <cachedir>' . $cacheDir . '</cachedir>' . "\n"
        . '</fontconfig>' . "\n";

    $path = sys_get_temp_dir() . '/wlmonitor-board-fontconfig.xml';
    file_put_contents($path, $xml);

    return $path;
}

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

    $env = getenv();
    $env['FONTCONFIG_FILE'] = board_fontconfig_path();

    $process = proc_open(['rsvg-convert', '-f', 'png', '-b', 'white'], $descriptors, $pipes, null, $env);
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

/**
 * Liest PNG-Bytes und packt sie in gepackte 1bpp-Rohdaten: MSB-first,
 * zeilenweise, Breite auf ein Vielfaches von 8 aufgerundet. Bit-Konvention:
 * 1 = Weiss, 0 = Schwarz (siehe Global Constraints). Harter Schwellwert bei
 * Helligkeit >= 128 (ITU-R BT.601) -- bewusst kein Dithering (Spec §7).
 *
 * @throws RuntimeException wenn das PNG unlesbar ist oder nicht die
 *         erwartete Groesse hat
 */
function png_to_1bpp_packed(string $pngBinary, int $width, int $height): string
{
    $image = @imagecreatefromstring($pngBinary);
    if ($image === false) {
        throw new RuntimeException('PNG konnte nicht gelesen werden');
    }

    if (imagesx($image) !== $width || imagesy($image) !== $height) {
        $actualWidth = imagesx($image);
        $actualHeight = imagesy($image);
        throw new RuntimeException(sprintf(
            'PNG-Groesse (%dx%d) passt nicht zur erwarteten Groesse (%dx%d)',
            $actualWidth, $actualHeight, $width, $height
        ));
    }

    $rowBytes = (int) ceil($width / 8);
    $out = '';

    for ($y = 0; $y < $height; $y++) {
        $row = str_repeat("\x00", $rowBytes);
        for ($byteIndex = 0; $byteIndex < $rowBytes; $byteIndex++) {
            $byte = 0;
            for ($bit = 0; $bit < 8; $bit++) {
                $x = $byteIndex * 8 + $bit;
                $isWhite = true; // Fuell-Pixel jenseits der Bildbreite sind weiss
                if ($x < $width) {
                    $rgb = imagecolorat($image, $x, $y);
                    $r = ($rgb >> 16) & 0xFF;
                    $g = ($rgb >> 8) & 0xFF;
                    $b = $rgb & 0xFF;
                    $luminance = 0.299 * $r + 0.587 * $g + 0.114 * $b;
                    $isWhite = $luminance >= 128.0;
                }
                if ($isWhite) {
                    $byte |= (1 << (7 - $bit));
                }
            }
            $row[$byteIndex] = chr($byte);
        }
        $out .= $row;
    }

    return $out;
}

const BOARD_WIDTH = 1872;
const BOARD_HEIGHT = 1404;

/**
 * Bounding-Box aller unterschiedlichen Bytes zwischen zwei gepackten
 * 1bpp-Frames (Spec §4 Schritt 6). x/w liegen immer auf Byte-Grenzen (8px) --
 * bitgenaues Cropping waere fuer den Firmware-Schreibvorgang unnoetig
 * kompliziert; y/h sind pixelgenau, da jede Zeile eigene Bytes hat.
 *
 * @return array{x:int,y:int,w:int,h:int}|null null = Frames identisch (kein Patch noetig)
 * @throws never -- bei Laengenmismatch wird bewusst der volle Bereich zurueckgegeben statt eines Fehlers, s. Global Constraints
 */
function board_frame_diff(string $oldPacked, string $newPacked, int $width, int $height): ?array
{
    if (strlen($oldPacked) !== strlen($newPacked)) {
        return ['x' => 0, 'y' => 0, 'w' => $width, 'h' => $height];
    }

    $rowBytes = intdiv($width + 7, 8);
    $minByteX = null;
    $maxByteX = null;
    $minY = null;
    $maxY = null;

    for ($y = 0; $y < $height; $y++) {
        $rowStart = $y * $rowBytes;
        for ($b = 0; $b < $rowBytes; $b++) {
            if ($oldPacked[$rowStart + $b] !== $newPacked[$rowStart + $b]) {
                if ($minY === null) {
                    $minY = $y;
                }
                $maxY = $y;
                if ($minByteX === null || $b < $minByteX) {
                    $minByteX = $b;
                }
                if ($maxByteX === null || $b > $maxByteX) {
                    $maxByteX = $b;
                }
            }
        }
    }

    if ($minY === null) {
        return null;
    }

    $x = $minByteX * 8;
    $w = min($width - $x, ($maxByteX - $minByteX + 1) * 8);

    return ['x' => $x, 'y' => $minY, 'w' => $w, 'h' => $maxY - $minY + 1];
}

/**
 * Schneidet ein Rechteck aus dem vollen PNG (vor der 1bpp-Packung) und packt
 * NUR diesen Ausschnitt -- fuer den Patch-Antwortkoerper aus Spec §5. $x muss
 * ein Vielfaches von 8 sein (board_frame_diff() garantiert das).
 *
 * @throws RuntimeException wenn das PNG unlesbar ist
 */
function board_crop_and_pack(string $pngBinary, int $x, int $y, int $w, int $h): string
{
    $src = @imagecreatefromstring($pngBinary);
    if ($src === false) {
        throw new RuntimeException('PNG konnte nicht gelesen werden');
    }

    $cropped = imagecreatetruecolor($w, $h);
    imagecopy($cropped, $src, 0, 0, $x, $y, $w, $h);

    ob_start();
    imagepng($cropped);
    $croppedPng = (string) ob_get_clean();

    return png_to_1bpp_packed($croppedPng, $w, $h);
}
