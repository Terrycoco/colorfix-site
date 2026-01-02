<?php
declare(strict_types=1);

namespace App\Lib;

/**
 * ColorCompute: derive LAB, LCh (HCL), HSL from hex/RGB.
 * sRGB, D65 reference white. All angles in degrees.
 */
final class ColorCompute
{
    /** @return array{r:int,g:int,b:int,hex6:string} */
    public static function normalizeRgb(?int $r, ?int $g, ?int $b, ?string $hex6): array
    {
        if ($hex6 !== null && $hex6 !== '') {
            $hex = strtoupper(ltrim($hex6, '#'));
            if (!preg_match('/^[0-9A-F]{6}$/', $hex)) {
                throw new \InvalidArgumentException('hex6 must be 6 hex chars');
            }
            $r = hexdec(substr($hex, 0, 2));
            $g = hexdec(substr($hex, 2, 2));
            $b = hexdec(substr($hex, 4, 2));
            return ['r'=>$r, 'g'=>$g, 'b'=>$b, 'hex6'=>$hex];
        }
        if ($r===null || $g===null || $b===null) {
            throw new \InvalidArgumentException('Provide hex6 or all of r,g,b');
        }
        foreach (['r'=>$r,'g'=>$g,'b'=>$b] as $k=>$v) {
            if ($v < 0 || $v > 255) throw new \InvalidArgumentException("$k must be 0..255");
        }
        $hex = sprintf('%02X%02X%02X', $r, $g, $b);
        return ['r'=>$r, 'g'=>$g, 'b'=>$b, 'hex6'=>$hex];
    }

    /** @return array{L:float,a:float,b:float} */
    public static function rgbToLab(int $r, int $g, int $b): array
    {
        // sRGB 0..1
        $R = $r/255; $G = $g/255; $B = $b/255;
        // gamma expand
        $R = ($R <= 0.04045) ? ($R/12.92) : pow(($R+0.055)/1.055, 2.4);
        $G = ($G <= 0.04045) ? ($G/12.92) : pow(($G+0.055)/1.055, 2.4);
        $B = ($B <= 0.04045) ? ($B/12.92) : pow(($B+0.055)/1.055, 2.4);
        // linear RGB -> XYZ (D65)
        $X = $R*0.4124564 + $G*0.3575761 + $B*0.1804375;
        $Y = $R*0.2126729 + $G*0.7151522 + $B*0.0721750;
        $Z = $R*0.0193339 + $G*0.1191920 + $B*0.9503041;
        // normalize by reference white (D65)
        $X /= 0.95047; $Z /= 1.08883; // Y already relative
        // XYZ -> Lab
        $f = function(float $t): float {
            return $t > 216/24389 ? pow($t, 1/3) : (841/108)*$t + 4/29;
        };
        $fx = $f($X); $fy = $f($Y); $fz = $f($Z);
        $L = 116*$fy - 16;
        $a = 500*($fx - $fy);
        $b = 200*($fy - $fz);
        return ['L'=>$L, 'a'=>$a, 'b'=>$b];
    }

    /** @return array{L:float,C:float,h:float} */
    public static function labToLch(float $L, float $a, float $b): array
    {
        $C = hypot($a, $b);
        $h = $C > 0 ? fmod(atan2($b, $a) * 180 / M_PI + 360.0, 360.0) : 0.0;
        return ['L'=>$L, 'C'=>$C, 'h'=>$h];
    }

    /** @return array{h:float,s:float,l:float} */
    public static function rgbToHsl(int $r, int $g, int $b): array
    {
        $R=$r/255; $G=$g/255; $B=$b/255;
        $max = max($R,$G,$B); $min = min($R,$G,$B);
        $l = ($max+$min)/2;
        if ($max === $min) { return ['h'=>0.0,'s'=>0.0,'l'=>$l*100]; }
        $d = $max - $min;
        $s = $l > 0.5 ? $d/(2-$max-$min) : $d/($max+$min);
        if ($max === $R)      { $h = ($G-$B)/$d + ($G<$B ? 6 : 0); }
        elseif ($max === $G)  { $h = ($B-$R)/$d + 2; }
        else                  { $h = ($R-$G)/$d + 4; }
        $h *= 60;
        return ['h'=>$h, 's'=>$s*100, 'l'=>$l*100];
    }

    /**
     * Compute everything from hex/rgb.
     * @return array{
     *   hex6:string,r:int,g:int,b:int,
     *   lab_l:float,lab_a:float,lab_b:float,
     *   hcl_l:float,hcl_c:float,hcl_h:float,
     *   hsl_h:float,hsl_s:float,hsl_l:float
     * }
     */
    public static function computeAll(?int $r, ?int $g, ?int $b, ?string $hex6): array
    {
        $rgb = self::normalizeRgb($r,$g,$b,$hex6);
        $lab = self::rgbToLab($rgb['r'],$rgb['g'],$rgb['b']);
        $lch = self::labToLch($lab['L'],$lab['a'],$lab['b']);
        $hsl = self::rgbToHsl($rgb['r'],$rgb['g'],$rgb['b']);

        return [
            'hex6'   => $rgb['hex6'],
            'r'      => $rgb['r'],
            'g'      => $rgb['g'],
            'b'      => $rgb['b'],
            'lab_l'  => $lab['L'],
            'lab_a'  => $lab['a'],
            'lab_b'  => $lab['b'],
            'hcl_l'  => $lch['L'],
            'hcl_c'  => $lch['C'],
            'hcl_h'  => $lch['h'],
            'hsl_h'  => $hsl['h'],
            'hsl_s'  => $hsl['s'],
            'hsl_l'  => $hsl['l'],
        ];
    }


// Add inside App\Lib\ColorCompute class

/** Convert CIE L*a*b* (D65) to sRGB integers (0–255). */
public static function labToRgb(float $L, float $a, float $b): array
{
    // Lab -> XYZ (D65)
    $fy = ($L + 16.0) / 116.0;
    $fx = $a / 500.0 + $fy;
    $fz = $fy - $b / 200.0;

    $finv = function(float $t): float {
        $e = 216.0/24389.0;            // 0.008856
        $k = 24389.0/27.0;            // 903.3
        return ($t*$t*$t > $e) ? ($t*$t*$t) : (116.0*$t - 16.0) / $k;
    };

    $X = 0.95047 * $finv($fx);
    $Y =           $finv($fy);
    $Z = 1.08883 * $finv($fz);

    // XYZ -> linear sRGB
    $Rl =  3.2404542*$X + (-1.5371385)*$Y + (-0.4985314)*$Z;
    $Gl = (-0.9692660)*$X +  1.8760108*$Y +  0.0415560*$Z;
    $Bl =  0.0556434*$X + (-0.2040259)*$Y +  1.0572252*$Z;

    // Encode to sRGB + clamp
    $enc = function(float $u): int {
        $u = max(0.0, min(1.0, $u));
        $v = ($u <= 0.0031308) ? (12.92*$u) : (1.055 * pow($u, 1.0/2.4) - 0.055);
        $n = (int)round($v * 255.0);
        return max(0, min(255, $n));
    };

    return ['r'=>$enc($Rl), 'g'=>$enc($Gl), 'b'=>$enc($Bl)];
}

/** Lab (D65) -> #RRGGBB (uppercase, no leading #) */
public static function labToHex6(float $L, float $a, float $b): string
{
    $rgb = self::labToRgb($L, $a, $b);
    return sprintf('%02X%02X%02X', $rgb['r'], $rgb['g'], $rgb['b']);
}

/** Convenience: compute L* from LRV% (0–100) using standard piecewise f(t). */
public static function lstarFromLrv(float $lrv): float
{
    $Y = max(0.0, min(1.0, $lrv / 100.0));
    $eps = pow(6.0/29.0, 3.0); // ~0.008856
    return ($Y > $eps) ? (116.0 * pow($Y, 1.0/3.0) - 16.0)
                       : ((841.0/108.0) * $Y + 4.0/29.0);
}







}
