<?php
declare(strict_types=1);
namespace App\PUB\Services;
use App\PUB\Repos\PdoPubAssetRepository;
use App\ARTICLES\Services\ArticlePublicationService;
use PDO;
use RuntimeException;
use Throwable;
final class ArticlePublicationManager
{
    public function __construct(private PDO $pdo) {}
    public function release(int $assetId, string $action): array
    {
        if (!in_array($action, ['publish', 'unpublish'], true)) throw new RuntimeException('Invalid action.');
        $owns = !$this->pdo->inTransaction();
        if ($owns) $this->pdo->beginTransaction();
        try {
            $asset = (new PdoPubAssetRepository($this->pdo))->lockArticlePublicationAsset($assetId);
            if ($action === 'publish' && ((int)$asset['approved'] !== 1 || $asset['pipeline_stage'] !== 'created')) {
                throw new RuntimeException('Approve the completed article asset before publishing.');
            }
            $result = (new ArticlePublicationService($this->pdo))->$action((int)$asset['article_id']);
            if ($owns) $this->pdo->commit();
            return $result;
        } catch (Throwable $e) {
            if ($owns && $this->pdo->inTransaction()) $this->pdo->rollBack();
            throw $e;
        }
    }
}
