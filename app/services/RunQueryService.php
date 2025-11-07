<?php
declare(strict_types=1);

namespace App\Services;

use PDO;
use App\Repos\PdoSqlQueryRepository;
use App\Lib\Logger;

final class RunQueryService
{
    public function __construct(
        private PDO $pdo,
        private PdoSqlQueryRepository $repo
    ) {}

    /**
     * Legacy v1 payload shape expected by the UI:
     * { results: [...], inserts: [...], meta: { query, result_count, insert_count } }
     *
     * - If the stored SQL returns rows, we use them EXACTLY in that order.
     * - If the stored SQL returns 0 rows, we fall back to items(query_id = $id).
     * - Global inserts are items(query_id = 17).
     */
    public function run(int $queryId, array $params = [], array $searchFilters = [], bool $debug = false): array
    {
        try {
            $query = $this->repo->getQueryRow($queryId);
            if ($query === null) {
                return ['http_code' => 404, 'payload' => ['error' => 'Query not found', 'id' => $queryId]];
            }

            // 1) Run the stored SQL first — its ORDER BY controls the order.
            $sqlRows = [];
            try {
                $sqlRows = $this->repo->runStoredQuery($queryId, $params, $searchFilters);
            } catch (\Throwable $e) {
                Logger::error('runStoredQuery failed', ['id' => $queryId, 'err' => $e->getMessage()]);
                $sqlRows = [];
            }

            // 2) Load page items (for inserts + fallback)
            $allItems = $this->repo->getItemsFor($queryId);
            $pageItems = [];
            $globalInserts = [];
            foreach ($allItems as $it) {
                $qid = (int)($it['query_id'] ?? 0);
                if ($qid === 17) {
                    $globalInserts[] = $it;
                } elseif ($qid === $queryId) {
                    $pageItems[] = $it;
                }
            }

            // 3) Final results: SQL rows if present; otherwise items (keep their DB order)
            $results = !empty($sqlRows) ? $sqlRows : $pageItems;

            $meta = [
                'query'         => $query,
                'result_count'  => count($results),
                'insert_count'  => count($globalInserts),
            ];

            return [
                'http_code' => 200,
                'payload'   => [
                    'results' => $results,
                    'inserts' => $globalInserts,
                    'meta'    => $meta,
                ],
            ];
        } catch (\Throwable $e) {
            Logger::error('RunQueryService.run failed', [
                'id'   => $queryId,
                'err'  => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);

            $payload = ['error' => 'Query failed'];
            if ($debug) {
                $payload['detail'] = $e->getMessage();
                $payload['at']     = $e->getFile() . ':' . $e->getLine();
            }
            return ['http_code' => 500, 'payload' => $payload];
        }
    }
}
