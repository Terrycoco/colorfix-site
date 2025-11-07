<?php

function checkBlacks(array $color): array {
    $categories = [];

    $l = isset($color['hcl_l']) ? floatval($color['hcl_l']) : null;
    $c = isset($color['hcl_c']) ? floatval($color['hcl_c']) : null;
    $lrv = isset($color['lrv']) ? floatval($color['lrv']) : null;

    if ($l !== null && $c !== null) {
        if ($l < 26 && $c < 6) {
            $categories[] = 'Blacks';
        }
    }

    // ✅ Only tag as black if it's very low chroma too
    if ($lrv !== null && $lrv < 5 && $c !== null && $c < 6) {
        $categories[] = 'Blacks';
    }

    return array_unique($categories);
}


?>