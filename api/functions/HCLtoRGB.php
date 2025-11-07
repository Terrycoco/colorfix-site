<?php

function HCLtoRGB($h, $c, $l) {
    // Convert HCL to LAB
    $h_rad = deg2rad($h);
    $a = cos($h_rad) * $c;
    $b = sin($h_rad) * $c;

    // Convert LAB to XYZ
    $y = ($l + 16) / 116;
    $x = $a / 500 + $y;
    $z = $y - $b / 200;

    // D65 reference white
    $x = 95.047 * pow($x, 3);
    $y = 100.000 * pow($y, 3);
    $z = 108.883 * pow($z, 3);

    // Convert XYZ to linear RGB
    $r = $x *  0.032406 + $y * -0.015372 + $z * -0.004986;
    $g = $x * -0.009689 + $y *  0.018758 + $z *  0.000415;
    $b = $x *  0.000557 + $y * -0.002040 + $z *  0.010570;

    // Gamma correction
    $r = $r > 0.0031308 ? 1.055 * pow($r, 1/2.4) - 0.055 : 12.92 * $r;
    $g = $g > 0.0031308 ? 1.055 * pow($g, 1/2.4) - 0.055 : 12.92 * $g;
    $b = $b > 0.0031308 ? 1.055 * pow($b, 1/2.4) - 0.055 : 12.92 * $b;

    // Clamp and convert to 0–255
    return [
        'r' => round(max(0, min(1, $r)) * 255),
        'g' => round(max(0, min(1, $g)) * 255),
        'b' => round(max(0, min(1, $b)) * 255)
    ];
}


?>