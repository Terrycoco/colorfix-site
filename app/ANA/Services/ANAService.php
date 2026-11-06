<?php
declare(strict_types=1);

namespace App\ANA\Services;

use App\ANA\Contracts\ANAEventRepositoryInterface;
use App\ANA\DTO\ANAEvent;
use App\ANA\Repos\PdoANAEventRepository;
use App\Repos\PdoAppConfigRepository;
use PDO;
use Closure;
use App\SYSTEM\Services\AdminAuthService;
use App\SYSTEM\Services\AdminSession;

final class ANAService
{
    public function __construct(
        private ANAEventRepositoryInterface $events,
        private ANAIgnoreService $ignore,
        private ?Closure $isVerifiedAdmin = null,
    ) {}

    public static function fromPdo(PDO $pdo): self
    {
        return new self(
            new PdoANAEventRepository($pdo),
            new ANAIgnoreService(new PdoAppConfigRepository($pdo)),
            static fn (): bool => AdminAuthService::fromPdo($pdo)->currentUser(true) !== null,
        );
    }

    public function record(ANAEvent $event): int
    {
        $userAgent = (string)($_SERVER['HTTP_USER_AGENT'] ?? '');
        if ($this->ignore->matches($userAgent)) return 0;
        if (!$event->isTest) {
            // This cookie is only an analytics opt-out, never authentication.
            $marked = in_array(strtolower(trim((string)($_COOKIE['cf_internal_viewer'] ?? ''))), ['1', 'true', 'yes'], true);
            if ($marked) return 0;
            // Cover a first page event before the browser's async session check
            // has marked local storage. Ordinary visitors incur no auth lookup.
            if (!empty($_COOKIE[AdminSession::COOKIE]) && $this->isVerifiedAdmin !== null
                && ($this->isVerifiedAdmin)()) {
                setcookie('cf_internal_viewer', '1', [
                    'expires' => time() + 365 * 24 * 60 * 60,
                    'path' => '/', 'samesite' => 'Lax', 'httponly' => false,
                    'secure' => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
                        || strtolower((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https',
                ]);
                return 0;
            }
        }

        // Only the server-observed header can populate this field. Preserve all
        // other event values, including Direct attribution and test behavior.
        $storedUserAgent = $userAgent !== '' ? mb_substr($userAgent, 0, 1000, 'UTF-8') : null;
        return $this->events->record($event->withUserAgent($storedUserAgent));
    }
}
