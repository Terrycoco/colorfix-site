<?php
declare(strict_types=1);

namespace App\Controllers;

use PDO;
use App\Repos\PdoColorRepository;
use App\Repos\PdoSwatchRepository;
use App\Repos\PdoColorDetailRepository;
use App\Repos\PdoClusterRepository;
use App\Services\Rules;
use App\Services\ScoreCandidates;
use App\Services\FindBestPerBrand;
use App\Services\MatchingService;
use App\Services\FriendsService;

final class FriendsController
{
    private FriendsService $svc;

    public function __construct(private PDO $pdo)
    {
        $colorRepo   = new PdoColorRepository($pdo);
        $swatchRepo  = new PdoSwatchRepository($pdo);
        $detailRepo  = new PdoColorDetailRepository($pdo);
        $clusterRepo = new PdoClusterRepository($pdo);

        $rules    = new Rules();
        $scorer   = new ScoreCandidates($colorRepo);
        $perBrand = new FindBestPerBrand($colorRepo);

        $matching = new MatchingService(
            $colorRepo, $swatchRepo, $detailRepo,
            $rules, $scorer, $perBrand
        );

        $this->svc = new FriendsService($pdo, $clusterRepo, $matching);
    }

public function handle(array $query, array $json): array
{
    $ids    = $this->readIds($query, $json);
    $brands = $this->readBrands($query, $json);
    if (!$ids) return ['items' => []];

    // v2: mode controls neutral filtering only
    $mode = strtolower((string)($query['mode'] ?? $json['mode'] ?? 'colors'));
    if (!in_array($mode, ['colors','neutrals','all'], true)) $mode = 'colors';

    // v2: include close = expand anchors to neighbors before computing friend intersection
    $includeClose = (
        (isset($query['include_neighbors']) && (string)$query['include_neighbors'] === '1') ||
        (isset($json['include_neighbors'])  && (int)$json['include_neighbors'] === 1)
    );

    // v2: near tuning passthrough (defaults match your current svc defaults)
    $nearCap   = $this->intOpt($query, $json, ['near_cap', 'closeLimit'], 60);
    $nearMaxDe = $this->floatOpt($query, $json, ['near_max_de'], 12.0);
    $nearMode  = strtolower($this->strOpt($query, $json, ['near_mode'], 'white')); // 'white' | 'de'

    $onlyNeutrals    = ($mode === 'neutrals');
    $excludeNeutrals = ($mode === 'colors');

    $opts = [
        // existing FriendsService options
        'onlyNeutrals'        => $onlyNeutrals,
        'excludeNeutrals'     => $excludeNeutrals,
        'includeCloseMatches' => $includeClose,

        // keep old param for backward compat, but also send the canonical v2 key
        'closeLimit'          => $nearCap,

        // v2 canonical near tuning (FriendsService should forward these to MatchingService)
        'near_cap'            => $nearCap,
        'near_max_de'         => $nearMaxDe,
        'near_mode'           => in_array($nearMode, ['white','de'], true) ? $nearMode : 'white',

    ];

    $opts['near_step']     = $this->floatOpt($query, $json, ['near_step'], 0.10);
    $opts['near_hard_max'] = $this->floatOpt($query, $json, ['near_hard_max'], 1.60);
    $opts['target_count']  = $this->intOpt($query, $json, ['target_count'], 18);

    return $this->svc->getFriendSwatches($ids, $brands, $opts);
}



    private function readIds(array $q, array $j): array {
        $ids = [];
        foreach (['ids'] as $k) {
            if (isset($q[$k])) $ids = array_merge($ids, is_array($q[$k]) ? $q[$k] : preg_split('/\s*,\s*/', (string)$q[$k]));
            if (isset($j[$k])) $ids = array_merge($ids, is_array($j[$k]) ? $j[$k] : preg_split('/\s*,\s*/', (string)$j[$k]));
        }
        $ids = array_values(array_unique(array_map('intval', $ids)));
        return array_values(array_filter($ids, fn($v)=>$v>0));
    }

private function readBrands(array $q, array $j): array {
    $brands = [];

    // Collect values from both GET and JSON, normalize to flat list
    foreach (['brand','brands','brand[]','brands[]'] as $k) {
        $valsQ = $q[$k] ?? [];
        $valsJ = $j[$k] ?? [];
        foreach ( (array)$valsQ as $v ) {
            if (is_array($v)) { $brands = array_merge($brands, $v); }
            else { $brands = array_merge($brands, preg_split('/\s*,\s*/', (string)$v, -1, PREG_SPLIT_NO_EMPTY)); }
        }
        foreach ( (array)$valsJ as $v ) {
            if (is_array($v)) { $brands = array_merge($brands, $v); }
            else { $brands = array_merge($brands, preg_split('/\s*,\s*/', (string)$v, -1, PREG_SPLIT_NO_EMPTY)); }
        }
    }

    // v2 convention: brand codes lowercase
    $brands = array_map(fn($b)=> strtolower(trim((string)$b)), $brands);
    return array_values(array_unique(array_filter($brands, fn($b)=>$b!=='')));
}


    private function intOpt(array $q, array $j, array $keys, int $def): int {
        foreach ($keys as $k) {
            if (isset($q[$k]) && is_numeric($q[$k])) return max(1, (int)$q[$k]);
            if (isset($j[$k]) && is_numeric($j[$k])) return max(1, (int)$j[$k]);
        }
        return $def;
    }

    private function boolOpt(array $q, array $j, string $key, bool $def): bool {
        $v = $q[$key] ?? $j[$key] ?? null;
        if (is_bool($v)) return $v;
        if (is_numeric($v)) return ((int)$v) === 1;
        if (is_string($v)) {
            $t = strtolower(trim($v));
            return in_array($t, ['1','true','yes','on'], true);
        }
        return $def;
    }






    private function floatOpt(array $q, array $j, array $keys, float $def): float {
    foreach ($keys as $k) {
        $v = $q[$k] ?? $j[$k] ?? null;
        if (is_numeric($v)) return (float)$v;
        if (is_string($v) && preg_match('/^-?\d+(\.\d+)?$/', trim($v))) return (float)$v;
    }
    return $def;
}

private function strOpt(array $q, array $j, array $keys, string $def): string {
    foreach ($keys as $k) {
        $v = $q[$k] ?? $j[$k] ?? null;
        if (is_string($v) && $v !== '') return trim($v);
    }
    return $def;
}

}
