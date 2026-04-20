<?php
declare(strict_types=1);

namespace App\Services;

use App\Repos\PdoPhotoAltTextJobRepository;
use PDO;
use Throwable;

class PhotoAltTextQueueService
{
    public function __construct(
        private PdoPhotoAltTextJobRepository $jobs,
        private GeminiImageAltTextService $gemini,
        private OpenAiImageAltTextService $openAi,
        private string $projectRoot
    ) {}

    public static function fromPdo(PDO $pdo, ?string $projectRoot = null): self
    {
        return new self(
            new PdoPhotoAltTextJobRepository($pdo),
            new GeminiImageAltTextService(),
            new OpenAiImageAltTextService(),
            $projectRoot ?: dirname(__DIR__, 2)
        );
    }

    public function enqueue(int $photoLibraryId, bool $force = false): void
    {
        $this->jobs->enqueue($photoLibraryId, $force);
    }

    public function enqueueMissing(int $limit = 200): int
    {
        return $this->jobs->enqueueMissing($limit);
    }

    public function processReady(int $limit = 3): array
    {
        $processed = [];
        $jobs = $this->jobs->claimReadyJobs($limit);
        foreach ($jobs as $job) {
            $jobId = (int)$job['job_id'];
            $photoLibraryId = (int)$job['photo_library_id'];
            try {
                if (trim((string)($job['alt_text'] ?? '')) !== '') {
                    $this->jobs->complete($jobId, $photoLibraryId, (string)$job['alt_text'], [
                        'skipped_reason' => 'alt_text already exists',
                    ], $this->gemini->getModel());
                    $processed[] = ['job_id' => $jobId, 'photo_library_id' => $photoLibraryId, 'status' => 'complete_existing'];
                    continue;
                }

                $provider = $this->selectProvider($job);
                $service = $provider === 'openai' ? $this->openAi : $this->gemini;
                $absolutePath = $this->resolvePhotoPath((string)($job['rel_path'] ?? ''));
                $result = $service->generateForFile($absolutePath, [
                    'title' => $job['title'] ?? '',
                    'tags' => $job['tags'] ?? '',
                ]);
                $this->jobs->complete(
                    $jobId,
                    $photoLibraryId,
                    (string)$result['alt_text'],
                    [
                        'provider' => $provider,
                        'model' => $service->getModel(),
                    ] + (array)($result['metadata'] ?? []),
                    $provider . ':' . $service->getModel(),
                    (string)($result['filename_slug'] ?? '')
                );
                $processed[] = [
                    'job_id' => $jobId,
                    'photo_library_id' => $photoLibraryId,
                    'status' => 'complete',
                    'provider' => $provider,
                    'alt_text' => $result['alt_text'],
                    'filename_slug' => $result['filename_slug'] ?? '',
                ];
            } catch (Throwable $e) {
                $provider = $this->selectProvider($job);
                if ($provider === 'gemini' && $this->shouldFallbackToOpenAi($job, $e->getMessage(), true)) {
                    $this->jobs->failAndFallbackToOpenAi($jobId, $e->getMessage());
                    $status = 'fallback_openai';
                } else {
                    $this->jobs->fail($jobId, $e->getMessage(), $provider);
                    $status = 'retry';
                }
                $processed[] = [
                    'job_id' => $jobId,
                    'photo_library_id' => $photoLibraryId,
                    'status' => $status,
                    'provider' => $provider,
                    'error' => $e->getMessage(),
                ];
            }
        }

        return [
            'processed' => $processed,
            'stats' => $this->jobs->stats(),
        ];
    }

    private function resolvePhotoPath(string $relPath): string
    {
        $relPath = trim($relPath);
        if ($relPath === '') {
            throw new \RuntimeException('Photo has no rel_path');
        }
        if (!str_starts_with($relPath, '/')) {
            $relPath = '/' . $relPath;
        }
        $path = rtrim($this->projectRoot, '/') . $relPath;
        if (is_file($path)) {
            return $path;
        }

        $docRoot = rtrim((string)($_SERVER['DOCUMENT_ROOT'] ?? ''), '/');
        if ($docRoot !== '') {
            $docPath = $docRoot . $relPath;
            if (is_file($docPath)) {
                return $docPath;
            }
        }

        throw new \RuntimeException('Photo file not found for ' . $relPath);
    }

    private function selectProvider(array $job): string
    {
        $provider = strtolower((string)($job['provider'] ?? 'gemini'));
        if ($provider === 'openai') {
            return 'openai';
        }
        if ($this->shouldFallbackToOpenAi($job, '')) {
            return 'openai';
        }
        return 'gemini';
    }

    private function shouldFallbackToOpenAi(array $job, string $error, bool $countCurrentFailure = false): bool
    {
        $provider = strtolower((string)($job['provider'] ?? 'gemini'));
        if ($provider === 'openai') {
            return true;
        }

        $providerAttempts = (int)($job['provider_attempts'] ?? 0) + ($countCurrentFailure ? 1 : 0);
        if ($providerAttempts >= 2) {
            return true;
        }

        $firstAttemptAt = (string)($job['first_attempt_at'] ?? '');
        $createdAt = (string)($job['created_at'] ?? '');
        $anchor = $firstAttemptAt !== '' ? $firstAttemptAt : $createdAt;
        if ($anchor !== '') {
            $started = strtotime($anchor);
            if ($started && time() - $started >= 4 * 60 * 60) {
                return true;
            }
        }

        $error = strtolower($error);
        return $error !== ''
            && (str_contains($error, 'quota')
                || str_contains($error, '429')
                || str_contains($error, '503')
                || str_contains($error, 'high demand')
                || str_contains($error, 'timeout'));
    }
}
