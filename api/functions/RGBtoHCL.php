<?php



function RGBtoHCL($r, $g, $b) {
    // Normalize RGB
    $r = $r / 255;
    $g = $g / 255;
    $b = $b / 255;

    // sRGB to linear
    $toLinear = function ($c) {
        return ($c <= 0.04045) ? $c / 12.92 : pow(($c + 0.055) / 1.055, 2.4);
    };
    $r = $toLinear($r);
    $g = $toLinear($g);
    $b = $toLinear($b);

    // Linear RGB to XYZ
    $x = $r * 0.4124 + $g * 0.3576 + $b * 0.1805;
    $y = $r * 0.2126 + $g * 0.7152 + $b * 0.0722;
    $z = $r * 0.0193 + $g * 0.1192 + $b * 0.9505;

    // Normalize for D65 white
    $x /= 0.95047;
    $y /= 1.00000;
    $z /= 1.08883;

    // XYZ to LAB
    $f = function ($t) {
        return ($t > 0.008856) ? pow($t, 1/3) : (7.787 * $t + 16/116);
    };
    $fx = $f($x);
    $fy = $f($y);
    $fz = $f($z);

    $lab_l = 116 * $fy - 16;
    $lab_a = 500 * ($fx - $fy);
    $lab_b = 200 * ($fy - $fz);

    // LAB to HCL
    $hcl_l = $lab_l;
    $hcl_c = sqrt($lab_a ** 2 + $lab_b ** 2);

    //echo "lab_a: {$lab_a}, lab_b: {$lab_b}, atan2: " . atan2($lab_b, $lab_a) . "\n";
    $h_rad = atan2($lab_b, $lab_a);  //decimals returned
    $hcl_h = fmod(($h_rad * 180 / M_PI + 360), 360);
 //no decimals returned 

   //echo "  h_rad: " . number_format($h_rad, 6) . ",  hcl_h: " . number_format($hcl_h, 6) . "\n";


    // Add this after LAB and HCL sections

        $max = max($r, $g, $b);
        $min = min($r, $g, $b);
        $delta = $max - $min;

        $h = 0;
        if ($delta != 0) {
            if ($max == $r) {
                $h = 60 * fmod((($g - $b) / $delta), 6);
            } elseif ($max == $g) {
                $h = 60 * ((($b - $r) / $delta) + 2);
            } else {
                $h = 60 * ((($r - $g) / $delta) + 4);
            }
        }
        if ($h < 0) $h += 360;

        $l = ($max + $min) / 2;
        $s = ($delta == 0) ? 0 : $delta / (1 - abs(2 * $l - 1));

        $hsl_h = $h;
        $hsl_s = $s * 100;
        $hsl_l = $l * 100;

    return [
        'lab_l' => $lab_l,
        'lab_a' => $lab_a,
        'lab_b' => $lab_b,
        'hcl_l' => $hcl_l,
        'hcl_c' => $hcl_c,
        'hcl_h' => $hcl_h,
        'hsl_l' => $hsl_l,
        'hsl_s' => $hsl_s,
        'hsl_h' => $hsl_h
    ];
}



?>