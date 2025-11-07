<?php
function checkBeiges($color) {
    $categories = [];

    $h = $color['hcl_h'] ?? null;
    $c = $color['hcl_c'] ?? null;
    $l = $color['hcl_l'] ?? null;

    if (!is_numeric($h) || !is_numeric($c) || !is_numeric($l)) {
        return $categories;
    }

        if (
            $h >= 70 && $h <= 100 &&    // HCL hue range for beiges
            $c >= 3 && $c <= 20 &&      // Soft chroma
            $l >= 70 && $l <= 92        // Medium to light
        ) {
            $categories[] = 'Beiges';
        }


    return $categories;
}
?>
