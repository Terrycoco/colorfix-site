<?php
declare(strict_types=1);

require_once __DIR__ . '/../autoload.php';

use App\ANA\Contracts\ANAEventRepositoryInterface;
use App\ANA\DTO\ANAEvent;
use App\ANA\Services\ANAService;

$repository = new class implements ANAEventRepositoryInterface {
    public array $inserted = [];
    public function record(ANAEvent $event): int
    {
        $this->inserted[] = $event;
        return count($this->inserted);
    }
};
$pdo = new PDO('sqlite::memory:');
$pdo->exec('CREATE TABLE app_configs (config_key TEXT UNIQUE, config_json TEXT)');
$service = new ANAService($repository, new \App\ANA\Services\ANAIgnoreService(new \App\Repos\PdoAppConfigRepository($pdo)));
$event = new ANAEvent(eventKey: 'visit', resourceType: 'page', path: '/picker', src: 'google');
$checks = 0;
foreach ([
    'Mozilla/5.0 (compatible; ElevenlabsCrawler/1.0; +https://elevenlabs.io/docs/bots)',
    'elevenlabscrawler/1.0',
    'ELEVENLABSCRAWLER/1.0',
    'Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)',
    'googlebot-image/1.0',
    'GOOGLEBOT',
    'Mozilla/5.0 (compatible; Google-InspectionTool/1.0)',
    'google-inspectiontool',
    'GOOGLE-INSPECTIONTOOL',
] as $userAgent) {
    $_SERVER['HTTP_USER_AGENT'] = $userAgent;
    if ($service->record($event) !== 0 || count($repository->inserted) !== 0) {
        throw new RuntimeException('Crawler reached the insertion repository.');
    }
    $checks++;
}
foreach ([
    'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 Chrome/156.0.0.0 Safari/537.36',
    'Mozilla/5.0 (iPhone; CPU iPhone OS 18_0 like Mac OS X) AppleWebKit/605.1.15 Version/18.0 Mobile Safari/604.1',
    '',
] as $userAgent) {
    $before = count($repository->inserted);
    $_SERVER['HTTP_USER_AGENT'] = $userAgent;
    if ($service->record($event) !== $before + 1 || $repository->inserted[$before]->eventKey !== $event->eventKey || $repository->inserted[$before]->src !== $event->src) {
        throw new RuntimeException('Ordinary event was filtered or changed.');
    }
    $checks++;
}
// Crawler names inside client-controlled event data do not drive this filter.
$ordinary = new ANAEvent(eventKey: 'visit', payload: ['user_agent' => 'Googlebot']);
$before = count($repository->inserted);
if ($service->record($ordinary) !== $before + 1 || $repository->inserted[$before]->payload !== $ordinary->payload) {
    throw new RuntimeException('Payload data was incorrectly used as the request user agent.');
}
$checks++;
echo "PASS: $checks ANA crawler exclusion and ordinary event preservation checks\n";
