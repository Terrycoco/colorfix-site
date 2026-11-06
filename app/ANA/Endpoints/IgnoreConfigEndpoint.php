<?php
declare(strict_types=1);

namespace App\ANA\Endpoints;

use App\ANA\Services\ANAIgnoreService;
use App\Repos\PdoAppConfigRepository;
use App\SYSTEM\Http\AdminHttp;
use InvalidArgumentException;
use PDO;
use Throwable;

final class IgnoreConfigEndpoint
{
    public static function handle(PDO $pdo): never
    {
        try {
            $config = new ANAIgnoreService(new PdoAppConfigRepository($pdo));
            $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
            if ($method === 'GET') AdminHttp::respond(['ok' => true, 'item' => ['patterns' => $config->patterns()]]);
            if ($method !== 'POST') {
                header('Allow: GET, POST');
                AdminHttp::respond(['ok' => false, 'error' => 'GET or POST required.'], 405);
            }
            AdminHttp::requireSameOrigin();
            $input = AdminHttp::input();
            if (!isset($input['patterns']) || !is_array($input['patterns'])) {
                throw new InvalidArgumentException('patterns must be a list of crawler user-agent tokens.');
            }
            AdminHttp::respond(['ok' => true, 'item' => ['patterns' => $config->save($input['patterns'])]]);
        } catch (InvalidArgumentException $error) {
            AdminHttp::respond(['ok' => false, 'error' => $error->getMessage()], 400);
        } catch (Throwable $error) {
            error_log('ANA ignore config: ' . get_class($error));
            AdminHttp::respond(['ok' => false, 'error' => 'Unable to load or save ANA crawler settings.'], 500);
        }
    }
}
