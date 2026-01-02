<?php
declare(strict_types=1);

namespace App\Services;

use App\Repos\PdoCtaRepository;

class CtaService
{
    public function __construct(
        private PdoCtaRepository $ctaRepo
    ) {}

    public function getCtasForContext(string $contextKey): array
    {
        $rows = $this->ctaRepo->getByContextKey($contextKey);

        $ctas = [];

        foreach ($rows as $row) {
            $params = [];

            if (!empty($row['params'])) {
                $decoded = json_decode($row['params'], true);

                if (is_array($decoded)) {
                    $params = $decoded;
                }
            }

            $ctas[] = [
                'cta_id'     => (int)$row['cta_id'],
                'label'      => $row['label'],
                'action'     => $row['action_key'],
                'params'     => $params,
                'enabled'    => true,
            ];
        }

        return $ctas;
    }
}
