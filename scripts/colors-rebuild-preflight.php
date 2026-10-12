<?php
declare(strict_types=1);

// Read-only preflight for the COLORS atomic rebuild; no transaction or data changes.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once dirname(__DIR__) . '/api/autoload.php';
require_once dirname(__DIR__) . '/api/db.php';

try {
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $required = ['colors','category_definitions','color_category','mv_color_categories','clusters'];
    $sql = "SELECT TABLE_NAME, ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN ('colors','category_definitions','color_category','mv_color_categories','clusters')";
    $engines = $pdo->query($sql)->fetchAll(PDO::FETCH_KEY_PAIR);
    $errors = [];
    foreach ($required as $name) {
        if (!isset($engines[$name])) $errors[] = "Missing table: {$name}";
    }
    foreach (['color_category','mv_color_categories','clusters'] as $name) {
        if (strtoupper((string)($engines[$name] ?? '')) !== 'INNODB') $errors[] = "{$name} is not InnoDB";
    }
    if ($errors) throw new RuntimeException(implode('; ', $errors));

    $count = static fn(string $sql): int => (int)$pdo->query($sql)->fetchColumn();
    $counts = [
        'colors' => $count('SELECT COUNT(*) FROM colors'),
        'active_colors' => $count('SELECT COUNT(*) FROM colors WHERE is_inactive = 0'),
        'category_definitions_active' => $count('SELECT COUNT(*) FROM category_definitions WHERE active = 1'),
        'colors_with_assignments' => $count('SELECT COUNT(DISTINCT color_id) FROM color_category'),
        'mv_rows' => $count('SELECT COUNT(*) FROM mv_color_categories'),
        'clusters' => $count('SELECT COUNT(*) FROM clusters'),
    ];
    if ($counts['colors'] === 0 || $counts['category_definitions_active'] === 0) {
        $errors[] = 'No colors or active category definitions';
    }
    $invalidHcl = $count('SELECT COUNT(*) FROM colors WHERE hcl_h IS NULL OR hcl_c IS NULL OR hcl_l IS NULL');
    if ($invalidHcl > 0) $errors[] = "{$invalidHcl} colors have NULL HCL values";
    $orphanClusters = $count('SELECT COUNT(*) FROM colors c LEFT JOIN clusters k ON k.id = c.cluster_id WHERE c.cluster_id IS NOT NULL AND k.id IS NULL');
    if ($orphanClusters > 0) $errors[] = "{$orphanClusters} colors reference missing clusters";

    // Mirror PdoClusterCategoryRepository's aggregation, without issuing updates.
    $sets = [];
    $stmt = $pdo->query('SELECT c.cluster_id, mv.hue_cats, mv.neutral_cats, mv.lightness_cats, mv.chroma_cats FROM colors c LEFT JOIN mv_color_categories mv ON mv.color_id = c.id WHERE c.cluster_id IS NOT NULL ORDER BY c.cluster_id,c.id');
    $fields = ['hue_cats','neutral_cats','lightness_cats','chroma_cats'];
    while ($row = $stmt->fetch()) {
        $id = (int)$row['cluster_id'];
        if (!isset($sets[$id])) $sets[$id] = [[],[],[],[]];
        foreach ($fields as $i => $field) {
            if ($row[$field] === null || $row[$field] === '') continue;
            foreach (explode(',', (string)$row[$field]) as $name) {
                $name = trim($name);
                if ($name !== '') $sets[$id][$i][$name] = true;
            }
        }
    }
    $overflows = [];
    foreach ($sets as $id => $categories) {
        foreach ($categories as $i => $categorySet) {
            $names = array_keys($categorySet);
            sort($names, SORT_STRING);
            $length = strlen(implode(',', $names));
            if ($length > 64) $overflows[] = ['cluster_id'=>$id, 'column'=>$fields[$i], 'bytes'=>$length];
        }
    }
    if ($overflows) $errors[] = count($overflows) . ' cluster categories exceed varchar(64)';
    $result = [
        'ok' => count($errors) === 0,
        'mode' => 'read_only_preflight',
        'database' => $pdo->query('SELECT DATABASE()')->fetchColumn(),
        'engines' => $engines,
        'counts' => $counts,
        'invalid_hcl_nulls' => $invalidHcl,
        'orphan_cluster_references' => $orphanClusters,
        'clusters_examined' => count($sets),
        'cluster_overflow_count' => count($overflows),
        'cluster_overflow_examples' => array_slice($overflows, 0, 10),
        'errors' => $errors,
        'writes_performed' => false,
    ];
    echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
    exit($result['ok'] ? 0 : 1);
} catch (Throwable $e) {
    fwrite(STDERR, "COLORS preflight failed (no data changed): {$e->getMessage()}\n");
    exit(1);
}
