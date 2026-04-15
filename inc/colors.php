<?php
/**
 * inc/colors.php
 *
 * Read/write helpers for the wl_colors table, which maps Bootstrap-era
 * button classes (primary/success/…) to German display labels. Stored
 * in DB so an admin can relabel them without touching code — e.g. the
 * theme overrides --color-primary to Jardyx red, so "Blau" is renamed
 * to "Rot" via the admin UI.
 *
 * Row shape: ['color' => 'primary', 'farbe' => 'Blau',
 *             'outline' => 'btn-outline-primary', 'full' => 'btn-primary']
 */

/**
 * Return all color rows keyed by the `color` column.
 *
 * @return array<string, array{color:string, farbe:string, outline:string, full:string}>
 */
function wl_colors_list(mysqli $con): array {
    $out = [];
    $res = $con->query('SELECT color, farbe, outline, full FROM wl_colors ORDER BY
        CASE color
            WHEN "default"   THEN 0
            WHEN "primary"   THEN 1
            WHEN "success"   THEN 2
            WHEN "info"      THEN 3
            WHEN "warning"   THEN 4
            WHEN "danger"    THEN 5
            WHEN "secondary" THEN 6
            WHEN "dark"      THEN 7
            ELSE 99
        END');
    while ($row = $res->fetch_assoc()) {
        $out[$row['color']] = $row;
    }
    $res->free();
    return $out;
}

/**
 * Build the legacy bclass → label map that older code paths expect.
 * Contains both the outline and the solid (full) variant per color.
 *
 * @return array<string, string>  e.g. ['btn-outline-primary' => 'Blau', ...]
 */
function wl_colors_bclass_labels(mysqli $con): array {
    $labels = [];
    foreach (wl_colors_list($con) as $row) {
        $labels[$row['outline']] = $row['farbe'];
        $labels[$row['full']]    = $row['farbe'] . ' (voll)';
    }
    return $labels;
}

/**
 * Rename the display label (`farbe`) of one color row. The class columns
 * are fixed and never edited — they're the app-wide Bootstrap contract.
 *
 * @return bool True if the row existed and was updated.
 */
function wl_color_edit(mysqli $con, string $color, string $farbe): bool {
    $farbe = trim($farbe);
    if ($farbe === '' || mb_strlen($farbe) > 50) return false;

    $stmt = $con->prepare('UPDATE wl_colors SET farbe = ? WHERE color = ?');
    $stmt->bind_param('ss', $farbe, $color);
    $stmt->execute();
    $affected = $stmt->affected_rows;
    $stmt->close();
    return $affected >= 0 && $con->error === '';
}
