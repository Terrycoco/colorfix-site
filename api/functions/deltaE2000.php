<?php

/** CIEDE2000 ΔE between (L1,a1,b1) and (L2,a2,b2). Returns float. */
function deltaE2000(float $L1, float $a1, float $b1, float $L2, float $a2, float $b2): float {
  $deg2rad = M_PI / 180.0;
  $rad2deg = 180.0 / M_PI;

  $kL = 1.0; $kC = 1.0; $kH = 1.0;

  $C1 = sqrt($a1*$a1 + $b1*$b1);
  $C2 = sqrt($a2*$a2 + $b2*$b2);
  $Cm = 0.5 * ($C1 + $C2);

  $Cm7 = pow($Cm, 7.0);
  $G = 0.5 * (1.0 - sqrt($Cm7 / ($Cm7 + pow(25.0, 7.0))));

  $a1p = (1.0 + $G) * $a1;
  $a2p = (1.0 + $G) * $a2;

  $C1p = sqrt($a1p*$a1p + $b1*$b1);
  $C2p = sqrt($a2p*$a2p + $b2*$b2);

  $h1p = ($b1 == 0.0 && $a1p == 0.0) ? 0.0 : fmod(atan2($b1, $a1p) * $rad2deg + 360.0, 360.0);
  $h2p = ($b2 == 0.0 && $a2p == 0.0) ? 0.0 : fmod(atan2($b2, $a2p) * $rad2deg + 360.0, 360.0);

  $dLp = $L2 - $L1;
  $dCp = $C2p - $C1p;

  $dhp = 0.0;
  if ($C1p * $C2p == 0.0) {
    $dhp = 0.0;
  } else {
    $dh = $h2p - $h1p;
    if ($dh > 180.0)      $dh -= 360.0;
    else if ($dh < -180.0) $dh += 360.0;
    $dhp = $dh;
  }
  $dHp = 2.0 * sqrt($C1p * $C2p) * sin(($dhp * 0.5) * $deg2rad);

  $Lm = 0.5 * ($L1 + $L2);
  $Cm_p = 0.5 * ($C1p + $C2p);

  $hm_p = 0.0;
  if ($C1p * $C2p == 0.0) {
    $hm_p = $h1p + $h2p;
  } else {
    $h_sum = $h1p + $h2p;
    if (abs($h1p - $h2p) > 180.0) $hm_p = ($h_sum < 360.0) ? ($h_sum + 360.0)*0.5 : ($h_sum - 360.0)*0.5;
    else                          $hm_p = 0.5 * $h_sum;
  }

  $T = 1.0
       - 0.17 * cos(($hm_p - 30.0) * $deg2rad)
       + 0.24 * cos((2.0 * $hm_p) * $deg2rad)
       + 0.32 * cos((3.0 * $hm_p + 6.0) * $deg2rad)
       - 0.20 * cos((4.0 * $hm_p - 63.0) * $deg2rad);

  $Sl = 1.0 + (0.015 * pow($Lm - 50.0, 2.0)) / sqrt(20.0 + pow($Lm - 50.0, 2.0));
  $Sc = 1.0 + 0.045 * $Cm_p;
  $Sh = 1.0 + 0.015 * $Cm_p * $T;

  $Δθ = 30.0 * exp(- pow(($hm_p - 275.0) / 25.0, 2.0));
  $Rc = 2.0 * sqrt(pow($Cm_p, 7.0) / (pow($Cm_p, 7.0) + pow(25.0, 7.0)));
  $Rt = -$Rc * sin(2.0 * $Δθ * $deg2rad);

  $ΔE = sqrt(
    pow($dLp / ($kL * $Sl), 2.0) +
    pow($dCp / ($kC * $Sc), 2.0) +
    pow($dHp / ($kH * $Sh), 2.0) +
    $Rt * ($dCp / ($kC * $Sc)) * ($dHp / ($kH * $Sh))
  );

  return $ΔE;
}
