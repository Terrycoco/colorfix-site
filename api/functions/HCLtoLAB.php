<?php
// Convert HCL (LCh°) to LAB
function HCLtoLAB($h, $c, $l) {
    $hrad = deg2rad($h); // convert hue to radians
    $a = $c * cos($hrad);
    $b = $c * sin($hrad);
    return [$l, $a, $b];
}
?>
