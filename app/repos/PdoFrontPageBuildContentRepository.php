<?php
declare(strict_types=1);

namespace App\Repos;

use PDO;

final class PdoFrontPageBuildContentRepository
{
    public function __construct(
        private PDO $pdo
    ) {}

    /**
     * @return array<string,string>
     */
    public function getActiveMap(string $buildKey): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT content_key, content_value
               FROM front_page_build_content
              WHERE build_key = :build_key
                AND is_active = 1
              ORDER BY content_key'
        );
        $stmt->execute(['build_key' => $buildKey]);

        $map = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $key = trim((string)($row['content_key'] ?? ''));
            if ($key === '') {
                continue;
            }
            $map[$key] = (string)($row['content_value'] ?? '');
        }

        return $map;
    }
}
