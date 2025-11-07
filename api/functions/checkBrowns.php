<?php
function checkBrowns(array $color): array {
    $categories = [];

    $h = $color['hcl_h'] ?? null;
    $c = $color['hcl_c'] ?? null;
    $l = $color['hcl_l'] ?? null;

    if ($h === null || $c === null || $l === null) {
        return $categories;
    }

    // Browns = mid-dark, low-chroma, warm hues (orangey/yellowy)
    if (
        $h >= 10 && $h <= 60 &&     // red-orange-yellow zone
        $c >= 5 && $c <= 35 &&      // modest saturation (not vivid)
        $l >= 20 && $l <= 55        // mid-to-dark lightness
    ) {
        $categories[] = 'Browns';
    }

    return $categories;
}
?>
