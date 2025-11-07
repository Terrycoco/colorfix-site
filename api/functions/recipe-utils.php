<?php
require_once dirname(__DIR__) . '/config.php';
logDebug("🔥 ENTERED recipe-utils.php!");

function logRecipeEvent($msg) {
    logDebug($msg);
}

function logRecipeError($msg) {
    logError("🚨 $msg");
}


function findCandidateRecipes(PDO $pdo, int $colorId): array {
    $stmt = $pdo->prepare("SELECT hcl_c, hcl_l FROM colors WHERE id = ?");
    $stmt->execute([$colorId]);
    $color = $stmt->fetch();

    if (!$color) {
        logError("Color not found for ID $colorId");
        return [];
    }

    $anchorC = $color['hcl_c'];
    $anchorL = $color['hcl_l'];

    logDebug("🎯 Finding recipes for anchor: C={$anchorC}, L={$anchorL}");

    // Try in this order:
    $toleranceRounds = [
        ['cTol' => 0.5, 'lTol' => 0.5],
        ['cTol' => 0.5, 'lTol' => 1.0],
        ['cTol' => 0.5, 'lTol' => 1.5],
        ['cTol' => 0.5, 'lTol' => 2.0],

        ['cTol' => 1.0, 'lTol' => 0.5],
        ['cTol' => 1.5, 'lTol' => 0.5],
        ['cTol' => 2.0, 'lTol' => 0.5],

        ['cTol' => 1.0, 'lTol' => 1.0],
        ['cTol' => 1.5, 'lTol' => 1.5],
        ['cTol' => 2.0, 'lTol' => 2.0],
    ];

    foreach ($toleranceRounds as $round) {
        $cTol = $round['cTol'];
        $lTol = $round['lTol'];

        logDebug("🔍 Trying tolerance: C ±$cTol, L ±$lTol");

        $stmt = $pdo->prepare("
            SELECT DISTINCT rd.recipe_id
            FROM recipe_deltas rd
            JOIN recipes r ON rd.recipe_id = r.id
            JOIN colors c ON r.anchor_color_id = c.id
            WHERE ABS(c.hcl_c - ?) <= ?
              AND ABS(c.hcl_l - ?) <= ?
        ");
        $stmt->execute([$anchorC, $cTol, $anchorL, $lTol]);
        $matches = $stmt->fetchAll(PDO::FETCH_COLUMN);

        if (!empty($matches)) {
            logDebug("✅ Found " . count($matches) . " recipe(s) at C ±$cTol, L ±$lTol");
            return $matches;
        }
    }

    logDebug("❌ No matching recipes found in any tolerance round.");
    return [];
}


function getRecipeDeltas(PDO $pdo, int $recipeId): array {
    $stmt = $pdo->prepare("
        SELECT dH, dC, dL, color_id
        FROM recipe_deltas
        WHERE recipe_id = ? 
    ");
    $stmt->execute([$recipeId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}


function deltaHue($a, $b) {
    return (($b - $a + 540) % 360) - 180;
}

function directionalHueDelta($h1, $h2) {
    $delta = $h1 - $h2;
    if ($delta > 180) $delta -= 360;
    if ($delta < -180) $delta += 360;
    return $delta;
}

function findMatchingColor($pdo, $target, $maxTolerance = 3.0, $step = 0.5, $epsilon = 0.001) {
    for ($tol = 0; $tol <= $maxTolerance; $tol += $step) {
        $searchTol = $tol + $epsilon;

   
        logDebug( "\n=== Trying tolerance: $tol (effective $searchTol) ===\n");
        logDebug("Target H={$target['h']} C={$target['c']} L={$target['l']}\n");


        $stmt = $pdo->prepare("
            SELECT *
            FROM swatch_view
            WHERE ABS(hcl_c - :c) <= :tol_c
            AND ABS(hcl_l - :l) <= :tol_l
        ");

        logDebug("🧾 SQL Params: C={$target['c']} L={$target['l']} Tol=$searchTol\n");

       try {
            $stmt->execute([
                ':c' => $target['c'],
                ':l' => $target['l'],
                ':tol_c' => $searchTol,
                ':tol_l' => $searchTol
            ]);
        } catch (PDOException $e) {
            logError("❌ SQL ERROR: " . $e->getMessage() . "\n");
        }
           logDebug("🧾 SQL Row Count: " . $stmt->rowCount() . "\n");


        $candidates = $stmt->fetchAll(PDO::FETCH_ASSOC);
        logDebug("🧪 Candidates:\n");
        foreach ($candidates as $c) {
           logDebug(print_r($c, true));
        }
        logDebug("🟢 Found " . count($candidates) . " chroma/lightness matches\n");

        // Now filter those by HUE directionally
        $filtered = [];
        foreach ($candidates as $row) {
            $dH = directionalHueDelta($row['hcl_h'], $target['h']);  // wrapped, directional
            if (abs($dH) <= $searchTol) {
                $filtered[] = $row;
            }
        }

        logDebug("🎯 Final matches after hue filtering: " . count($filtered) . "\n");
        foreach ($filtered as $match) {
            logDebug(print_r($match, true));
        }

        if (!empty($filtered)) {
            $filtered[0]['role'] = $target['role'];  // preserve role
            return $filtered[0];
        }
    }

    logDebug("❌ No match found within tolerance range.\n");
    return null;
}

function calcDeltas(array $anchor, array $target): array {
    $dH = fmod(($target['hcl_h'] - $anchor['hcl_h'] + 540), 360) - 180;
    $dC = $target['hcl_c'] - $anchor['hcl_c'];
    $dL = $target['hcl_l'] - $anchor['hcl_l'];
    return ['dH' => $dH, 'dC' => $dC, 'dL' => $dL];
}


function createReverseRecipesFromExisting($paletteId) {
    global $pdo;

    logDebug("↻ Starting reverse recipe creation for palette_id: $paletteId");

    try {
        // Step 1: Get all original recipes for this palette
        $stmt = $pdo->prepare("SELECT id AS recipe_id, anchor_color_id FROM recipes WHERE palette_id = ?");
        $stmt->execute([$paletteId]);
        $recipes = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($recipes as $original) {
            $originalRecipeId = $original['recipe_id'];
            $anchorId = $original['anchor_color_id'];

            // Step 2: Create a new recipe row for reverse
            $stmt = $pdo->prepare("INSERT INTO recipes (palette_id, anchor_color_id, is_reverse) VALUES (?, ?, 1)");
            $stmt->execute([$paletteId, $anchorId]);
            $newRecipeId = $pdo->lastInsertId();
            logDebug("🔄 Created reverse recipe_id: $newRecipeId for anchor $anchorId");

            // Step 3: Fetch deltas from original recipe
            $stmt = $pdo->prepare("SELECT color_id, dH, dC, dL FROM recipe_deltas WHERE recipe_id = ?");
            $stmt->execute([$originalRecipeId]);
            $deltas = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // Step 4: Insert reversed deltas
            $stmt = $pdo->prepare("INSERT INTO recipe_deltas (recipe_id, color_id, dH, dC, dL) VALUES (?, ?, ?, ?, ?)");

            foreach ($deltas as $d) {
                $stmt->execute([
                    $newRecipeId,
                    $d['color_id'],
                    -1 * $d['dH'],
                    $d['dC'],
                    $d['dL']
                ]);
            }

            logDebug("✔️ Stored reversed deltas for recipe_id: $newRecipeId");
        }

        logDebug("🎉 DONE creating reverse recipes for palette_id: $paletteId");

    } catch (Throwable $e) {
        logError("❌ ERROR in createReverseRecipesFromExisting($paletteId): " . $e->getMessage());
        throw $e;
    }
}


function rebuildRecipesForPalette($paletteId) {
    global $pdo;

    logDebug("🔥 START rebuilding recipes for palette_id: $paletteId");

    try {
        // Step 1: Delete existing recipes
        $stmt = $pdo->prepare("DELETE FROM recipes WHERE palette_id = ?");
        $stmt->execute([$paletteId]);
        logDebug("🧹 Deleted existing recipes for palette_id: $paletteId");


        // Step 2: Get all colors in palette
        $stmt = $pdo->prepare("
            SELECT pc.color_id, c.hcl_h, c.hcl_c, c.hcl_l, pc.role
            FROM palette_colors pc
            JOIN swatch_view c ON pc.color_id = c.id
            WHERE pc.palette_id = ?
        ");
        $stmt->execute([$paletteId]);
        $colors = $stmt->fetchAll(PDO::FETCH_ASSOC);
        logDebug("🎨 Found " . count($colors) . " colors for palette_id: $paletteId");

        foreach ($colors as $anchor) {
            $anchorId = $anchor['color_id'];

            // Insert recipe row
            $stmt = $pdo->prepare("
                INSERT INTO recipes (palette_id, anchor_color_id)
                VALUES (?, ?)
            ");
            $stmt->execute([$paletteId, $anchorId]);
            $recipeId = $pdo->lastInsertId();
            logDebug("📘 Created recipe_id: $recipeId with anchor_color_id: $anchorId");

            foreach ($colors as $target) {
                if ($target['color_id'] == $anchorId) continue;

                $deltas = calcDeltas($anchor, $target);

                $stmt = $pdo->prepare("
                    INSERT INTO recipe_deltas 
                        (recipe_id, color_id, dH, dC, dL)
                    VALUES (?, ?, ?, ?, ?)
                ");
                $stmt->execute([
                    $recipeId,
                    $target['color_id'],
                    $deltas['dH'],
                    $deltas['dC'],
                    $deltas['dL']
                ]);
            }

            logDebug("✅ Stored deltas for recipe_id: $recipeId");
        }

        logDebug("🎉 DONE rebuilding recipes for palette_id: $paletteId");

    } catch (Throwable $e) {
        logError("❌ ERROR in rebuildRecipesForPalette($paletteId): " . $e->getMessage());
        throw $e; // Optional: rethrow to surface
    }
}

function generatePaletteFromRecipe($pdo, $recipeId, $anchorId, $hTol = 5.0, $cTol = 1.0, $lTol = 1.0) {
    // Load anchor swatch
    $stmt = $pdo->prepare("SELECT * FROM swatch_view WHERE id = ?");
    $stmt->execute([$anchorId]);
    $anchor = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$anchor) {
        throw new Exception("Anchor color not found for ID: $anchorId");
    }

    $anchorHCL = [
        'h' => $anchor['hcl_h'],
        'c' => $anchor['hcl_c'],
        'l' => $anchor['hcl_l']
    ];

    // Load deltas for this recipe
    $stmt = $pdo->prepare("
        SELECT d.color_id, d.dH, d.dC, d.dL, pc.role
        FROM recipe_deltas d
        LEFT JOIN palette_colors pc ON d.color_id = pc.color_id
        WHERE d.recipe_id = ?
    ");
    $stmt->execute([$recipeId]);
    $deltas = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Calculate target HCLs from deltas
    $targets = array_map(function ($d) use ($anchorHCL) {
        return [
            'original_color_id' => $d['color_id'],
            'role' => $d['role'] ?? '',
            'target_hcl' => [
                'h' => fmod($anchorHCL['h'] + $d['dH'] + 360, 360),
                'c' => $anchorHCL['c'] + $d['dC'],
                'l' => $anchorHCL['l'] + $d['dL'],
            ]
        ];
    }, $deltas);

    $matched = [];
$usedIds = [$anchorId]; // Start with anchor to avoid accidental re-matching

foreach ($targets as $t) {
    try {
        $match = findMatchingColor($pdo, $t['target_hcl'], $hTol, $cTol, $lTol);

        if (!$match || !isset($match['id'])) {
            throw new Exception("No valid match found for HCL: " . json_encode($t['target_hcl']));
        }

        // if (in_array($match['id'], $usedIds)) {
        //     throw new Exception("Duplicate match detected: ID {$match['id']} already used");
        // }

        $usedIds[] = $match['id'];

        $matched[] = array_merge($match, [
            'role' => $t['role'],
            'original_color_id' => $t['original_color_id'],
            'target_hcl' => $t['target_hcl']
        ]);
    } catch (Throwable $e) {
        throw new Exception("Palette rejected due to failed match for role '{$t['role']}': " . $e->getMessage());
    }
}


    // Add the anchor color last
    $matched[] = array_merge($anchor, [
        'role' => 'anchor',
        'target_hcl' => $anchorHCL
    ]);

    // Sort by target hue
    usort($matched, function ($a, $b) {
        return ($a['target_hcl']['h'] ?? 999) <=> ($b['target_hcl']['h'] ?? 999);
    });

    return $matched;
}


function paletteAlreadyExists($pdo, $colorIdsInOrder) {
    $colorIds = $colorIdsInOrder;
    sort($colorIds); // Ignore order

    $count = count($colorIds);
    if ($count === 0) return false;

    // Get all candidate palettes with same number of colors
    $stmt = $pdo->prepare("
        SELECT generated_palette_id
        FROM generated_palette_colors
        GROUP BY generated_palette_id
        HAVING COUNT(*) = ?
    ");
    $stmt->execute([$count]);
    $candidates = $stmt->fetchAll(PDO::FETCH_COLUMN);

    foreach ($candidates as $paletteId) {
        $stmt2 = $pdo->prepare("
            SELECT color_id
            FROM generated_palette_colors
            WHERE generated_palette_id = ?
        ");
        $stmt2->execute([$paletteId]);
        $existingIds = $stmt2->fetchAll(PDO::FETCH_COLUMN);
        sort($existingIds);

        if ($existingIds === $colorIds) {
            return true; // found a match
        }
    }

    return false; // it's unique
}


function logGeneratedPalette($pdo, $recipeId, $anchorColorId, $direction, $colorIdsInOrder) {
    // Insert into generated_palettes
    $stmt = $pdo->prepare("
        INSERT INTO generated_palettes (recipe_id, anchor_color_id, direction)
        VALUES (?, ?, ?)
    ");
    $stmt->execute([$recipeId, $anchorColorId, $direction]);

    $generatedPaletteId = $pdo->lastInsertId();

    // Insert each color with its position (skip duplicates)
    $insertColor = $pdo->prepare("
        INSERT IGNORE INTO generated_palette_colors (generated_palette_id, color_id, position)
        VALUES (?, ?, ?)
    ");

    $seen = [];

    foreach ($colorIdsInOrder as $index => $colorId) {
        if (in_array($colorId, $seen)) {
            continue; // skip duplicate
        }
        $seen[] = $colorId;
        $insertColor->execute([$generatedPaletteId, $colorId, $index]);
    }

    return $generatedPaletteId;
}
