<?php

function checkGrays(array $color): array {
    $categories = [];

    $l = $color['hcl_l'] ?? null;  // perceptual lightness
    $c = $color['hcl_c'] ?? null;  // perceptual chroma

    if (!is_numeric($l) || !is_numeric($c)) return $categories;

    // Adjusted: only count as gray if NOT too dark
    if (
        $c < 5 &&             // still low chroma
        $l >= 35 && $l <= 85  // was 20, now raised to 35
    ) {
        $categories[] = 'Grays';
    }

    return $categories;
}



?>