<?php
declare(strict_types=1);

namespace App\services;

use App\repos\PdoColorRepository;
use App\repos\PdoSwatchRepository;
use App\Lib\ColorCompute; // <- ensure your ColorCompute is namespaced App\Lib (capital L)
use PDO;

final class ColorSaveService
{






    /**
     * Save to `colors` and return hydrated swatch_view row.
     *
     * INSERT requires: name, brand, and EITHER hex/hex6 OR r,g,b.
     * If only hex/hex6 is provided → derive r,g,b.
     * If only r,g,b is provided → derive hex6.
     *
     * All color math (Lab, LCh/HCL, HSL) is computed via App\Lib\ColorCompute.
     * Incoming Lab values (lab_l/a/b) are ignored for consistency.
     *
     * On UPDATE, recompute the pipeline only if hex/rgb changed.
     * On the first overwrite, back up original lightness into orig_lab_l / orig_hcl_l.
     */
    public function save(array $data, PDO $pdo): array
    {
        $id      = isset($data['id']) ? (int)$data['id'] : 0;
        $name    = $data['name']  ?? null;
        $brand   = $data['brand'] ?? null;
        $code    = $data['code']  ?? null;
        $chipNum = $data['chip_num'] ?? null;

        // Inputs that represent the actual color
        $hex6In = $data['hex6'] ?? null; // "EFEFEF"
        $hexIn  = $data['hex']  ?? null; // "#EFEFEF" or "EFEFEF"
        $rIn    = $data['r']    ?? null;
        $gIn    = $data['g']    ?? null;
        $bIn    = $data['b']    ?? null;

        // ---------- validations ----------
        if ($id <= 0) {
            if (($name ?? '') === '' || ($brand ?? '') === '') {
                throw new \InvalidArgumentException('For inserts, name and brand are required');
            }
        }

        // normalize hex6 if provided
        $hex6 = null;
        if (is_string($hex6In) && $hex6In !== '') $hex6 = self::normHex6($hex6In);
        if ($hex6 === null && is_string($hexIn) && $hexIn !== '') $hex6 = self::normHex6($hexIn);

        $hasRGB = self::isNum($rIn) && self::isNum($gIn) && self::isNum($bIn);
        $r = $hasRGB ? self::clamp8((int)$rIn) : null;
        $g = $hasRGB ? self::clamp8((int)$gIn) : null;
        $b = $hasRGB ? self::clamp8((int)$bIn) : null;

        // Derive missing side of hex/rgb pair
        if ($id <= 0) {
            if ($hex6 === null && !$hasRGB) {
                throw new \InvalidArgumentException('Insert requires hex/hex6 OR r,g,b');
            }
        }
        if ($hex6 === null && $hasRGB) {
            $hex6 = sprintf('%02X%02X%02X', $r, $g, $b);
        }
        if ($hex6 !== null && !$hasRGB) {
            [$r, $g, $b] = self::hexToRgb($hex6);
        }

        $repo = new PdoColorRepository($pdo);

        if ($id > 0) {
            // ------- UPDATE path -------
            $existing = $repo->getById($id);
            if (!$existing) {
                throw new \RuntimeException("Color id {$id} not found");
            }

            $update = [];
            if ($name    !== null) $update['name']     = (string)$name;
            if ($brand   !== null) $update['brand']    = (string)$brand;
            if ($code    !== null) $update['code']     = (string)$code;
            if ($chipNum !== null) $update['chip_num'] = (string)$chipNum;

            // Track whether any color-defining inputs were provided (hex/rgb)
            $colorInputsProvided = false;

            if ($hex6 !== null && strtoupper((string)$existing['hex6']) !== strtoupper($hex6)) {
                $update['hex6'] = strtoupper($hex6);
                $colorInputsProvided = true;
            }
            if ($r !== null && (int)$existing['r'] !== $r) { $update['r'] = $r; $colorInputsProvided = true; }
            if ($g !== null && (int)$existing['g'] !== $g) { $update['g'] = $g; $colorInputsProvided = true; }
            if ($b !== null && (int)$existing['b'] !== $b) { $update['b'] = $b; $colorInputsProvided = true; }

            // If color inputs were provided, recompute full pipeline from the resulting RGB
            if ($colorInputsProvided) {
                $finalHex = $update['hex6'] ?? (is_string($existing['hex6']) ? strtoupper($existing['hex6']) : null);
                $finalR   = $update['r']    ?? (int)$existing['r'];
                $finalG   = $update['g']    ?? (int)$existing['g'];
                $finalB   = $update['b']    ?? (int)$existing['b'];

                if ($finalHex && (!isset($finalR,$finalG,$finalB))) {
                    [$finalR, $finalG, $finalB] = self::hexToRgb($finalHex);
                    $update['r'] = $finalR; $update['g'] = $finalG; $update['b'] = $finalB;
                }

                // Compute via ColorCompute only
                $lab = ColorCompute::rgbToLab($finalR, $finalG, $finalB);
                $lch = ColorCompute::labToLch($lab['L'], $lab['a'], $lab['b']);
                $hsl = ColorCompute::rgbToHsl($finalR, $finalG, $finalB);

                $update['lab_l'] = $lab['L'];
                $update['lab_a'] = $lab['a'];
                $update['lab_b'] = $lab['b'];
                $update['hcl_l'] = $lch['L'];  // equals Lab L*
                $update['hcl_c'] = $lch['C'];
                $update['hcl_h'] = $lch['h'];
                $update['hsl_h'] = $hsl['h'];
                $update['hsl_s'] = $hsl['s'];
                $update['hsl_l'] = $hsl['l'];

                // Back up originals ONCE if not yet backed up
                if (array_key_exists('orig_lab_l', $existing) && $existing['orig_lab_l'] === null && isset($existing['lab_l'])) {
                    $update['orig_lab_l'] = (float)$existing['lab_l'];
                }
                if (array_key_exists('orig_hcl_l', $existing) && $existing['orig_hcl_l'] === null && isset($existing['hcl_l'])) {
                    $update['orig_hcl_l'] = (float)$existing['hcl_l'];
                }
            }

            if (!$update) {
                throw new \InvalidArgumentException('No fields provided to update');
            }

            $repo->updateColor($id, $update);
            $savedId = $id;

        } else {
            // ------- INSERT path -------
            // Compute everything from RGB via ColorCompute
            $lab = ColorCompute::rgbToLab($r, $g, $b);
            $lch = ColorCompute::labToLch($lab['L'], $lab['a'], $lab['b']);
            $hsl = ColorCompute::rgbToHsl($r, $g, $b);

            $payload = [
                'name'        => (string)$name,
                'brand'       => (string)$brand,
                'code'        => $code !== null ? (string)$code : null,
                'chip_num'    => $chipNum !== null ? (string)$chipNum : null,
                'hex6'        => strtoupper((string)$hex6),
                'r'           => $r, 'g' => $g, 'b' => $b,
                'lab_l'       => $lab['L'], 'lab_a' => $lab['a'], 'lab_b' => $lab['b'],
                'hcl_l'       => $lch['L'], 'hcl_c' => $lch['C'], 'hcl_h' => $lch['h'],
                'hsl_h'       => $hsl['h'], 'hsl_s' => $hsl['s'], 'hsl_l' => $hsl['l'],
                // Safety backups on insert = initial values
                'orig_lab_l'  => $lab['L'],
                'orig_hcl_l'  => $lch['L'],
            ];

            $savedId = $repo->insertColor($payload);
        }

        // Hydrate swatch_view for UI
        $swRepo = new PdoSwatchRepository($pdo);
        $sw     = $swRepo->getByIds([$savedId])[$savedId] ?? null;

        return [
            'ok'     => true,
            'id'     => $savedId,
            'swatch' => $sw ?: null,
        ];
    }

    private static function normHex6(string $s): string
    {
        $hx = strtoupper(ltrim(trim($s), '#'));
        if (!preg_match('/^[0-9A-F]{6}$/', $hx)) {
            throw new \InvalidArgumentException('hex/hex6 must be 6 hex chars');
        }
        return $hx;
    }

    private static function isNum($v): bool
    {
        return $v !== null && $v !== '' && is_numeric($v);
    }

    private static function clamp8(int $x): int
    {
        return $x < 0 ? 0 : ($x > 255 ? 255 : $x);
    }

    private static function hexToRgb(string $hex6): array
    {
        $hex6 = strtoupper(ltrim($hex6, '#'));
        $r = hexdec(substr($hex6, 0, 2));
        $g = hexdec(substr($hex6, 2, 2));
        $b = hexdec(substr($hex6, 4, 2));
        return [$r, $g, $b];
    }
}
