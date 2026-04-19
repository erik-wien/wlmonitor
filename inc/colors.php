<?php
/**
 * inc/colors.php
 *
 * Palette colours offered to users in the favourite-colour picker.
 * Each entry must correspond to a .btn-outline-color-* class in the
 * shared css_library. Edit this list to add or remove picker options.
 */

function wl_palette_list(): array {
    return [
        ['class' => 'btn-outline-color-red',        'label' => 'Rot'],
        ['class' => 'btn-outline-color-blue',        'label' => 'Blau'],
        ['class' => 'btn-outline-color-green',       'label' => 'Grün'],
        ['class' => 'btn-outline-color-yellow',      'label' => 'Gelb'],
        ['class' => 'btn-outline-color-orange',      'label' => 'Orange'],
        ['class' => 'btn-outline-color-purple',      'label' => 'Lila'],
        ['class' => 'btn-outline-color-turquoise',   'label' => 'Türkis'],
        ['class' => 'btn-outline-color-grey-dark',   'label' => 'Grau'],
        ['class' => 'btn-outline-color-grey-light',  'label' => 'Hellgrau'],
        ['class' => 'btn-outline-color-neutral',     'label' => 'Neutral'],
    ];
}
