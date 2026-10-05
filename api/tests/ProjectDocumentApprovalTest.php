<?php
declare(strict_types=1);

require_once __DIR__ . '/../autoload.php';

use App\PROJECTS\Endpoints\ProjectDocumentApproveEndpoint;

// SQLite exercises the actual endpoint and repositories, but not MySQL row locks.
final class ApprovalFixturePdo extends PDO
{
    public function __construct(string $path)
    {
        parent::__construct('sqlite:' . $path);
        $this->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->sqliteCreateFunction('CONCAT_WS', static function ($separator, ...$values) {
            return implode($separator, array_filter($values, static fn($value) => $value !== null));
        });
    }

    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        return parent::prepare(str_replace('FOR UPDATE', '', $query), $options);
    }
}

if (PHP_SAPI === 'cli-server' && getenv('APPROVAL_FIXTURE_DB')) {
    ProjectDocumentApproveEndpoint::handle(new ApprovalFixturePdo(getenv('APPROVAL_FIXTURE_DB')));
    return;
}

function with_approval_endpoint_fixture(callable $check): void
{
    $path = tempnam(sys_get_temp_dir(), 'approval-fixture-');
    $pdo = new ApprovalFixturePdo($path);
    $schemas = [
        'projects' => 'id INTEGER PRIMARY KEY, project_name TEXT, client_id INTEGER,
            property_id INTEGER, playlist_id INTEGER, rooms TEXT',
        'clients' => 'id INTEGER PRIMARY KEY, name TEXT',
        'properties' => 'id INTEGER PRIMARY KEY, name TEXT, address_id INTEGER',
        'addresses' => 'id INTEGER PRIMARY KEY, street_1 TEXT, street_2 TEXT, city TEXT,
            state TEXT, postal_code TEXT',
        'playlists' => 'playlist_id INTEGER PRIMARY KEY, title TEXT',
        'project_documents' => 'id INTEGER PRIMARY KEY, project_id INTEGER, scope_id INTEGER,
            document_type TEXT, template_key TEXT, title TEXT, content_html TEXT, status TEXT,
            approval_required INTEGER, sent_at TEXT, accepted_at TEXT, locked_at TEXT,
            created_at TEXT, updated_at TEXT',
        'project_activity' => 'id INTEGER PRIMARY KEY AUTOINCREMENT, project_id INTEGER,
            activity_date TEXT, description TEXT, hours REAL, miles REAL, amount REAL,
            entry_type TEXT, event_type TEXT, resource_type TEXT, resource_id INTEGER,
            created_at TEXT DEFAULT CURRENT_TIMESTAMP, updated_at TEXT DEFAULT CURRENT_TIMESTAMP',
        'rex_reservations' => 'id INTEGER PRIMARY KEY, token TEXT, label TEXT, admin_note TEXT,
            resolver_key TEXT, resource_type TEXT, resource_id INTEGER, context_json TEXT,
            status TEXT, revoked_at TEXT, created_at TEXT, updated_at TEXT, fallback_rex_id INTEGER',
    ];
    foreach ($schemas as $table => $columns) {
        $pdo->exec("CREATE TABLE {$table} ({$columns})");
    }
    $pdo->exec("INSERT INTO clients VALUES (1, 'Fixture Client')");
    $pdo->exec("INSERT INTO projects VALUES (1, 'Fixture Project', 1, NULL, NULL, '[]')");
    $pdo->exec("INSERT INTO project_documents
        (id, project_id, document_type, title, content_html, status, approval_required, locked_at)
        VALUES (4, 1, 'document', 'Final Colors', '<p>Frozen colors</p>', 'draft', 1, '2026-10-05 09:00:00'),
               (5, 1, 'document', 'Other Document', '<p>Other colors</p>', 'draft', 1, NULL)");
    $pdo->exec("INSERT INTO rex_reservations
        (id, token, label, resolver_key, resource_type, resource_id, status, fallback_rex_id)
        VALUES (1, 'valid-doc', 'Document', 'document', 'doc', 4, 'active', NULL),
               (2, 'wrong-type', 'Route', 'route', 'route', 1, 'active', NULL),
               (3, 'revoked-doc', 'Revoked', 'document', 'doc', 4, 'revoked', 1),
               (4, 'missing-doc', 'Missing', 'document', 'doc', 99, 'active', NULL),
               (5, 'unfrozen-doc', 'Unfrozen', 'document', 'doc', 5, 'active', NULL),
               (6, 'revoked-at', 'Revoked timestamp', 'document', 'doc', 4, 'active', 1)");
    $pdo->exec("UPDATE rex_reservations SET revoked_at = '2026-10-05 10:00:00' WHERE id = 6");

    $socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
    if (!$socket) {
        throw new RuntimeException($error);
    }
    $address = stream_socket_get_name($socket, false);
    fclose($socket);
    $process = null;
    try {
        $process = proc_open(
            [PHP_BINARY, '-S', $address, __FILE__],
            [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']],
            $pipes,
            null,
            array_merge(getenv(), ['APPROVAL_FIXTURE_DB' => $path])
        );
        if (!is_resource($process)) {
            throw new RuntimeException('Unable to start isolated approval endpoint.');
        }
        $ready = false;
        for ($attempt = 0; $attempt < 100; $attempt++) {
            $connection = @stream_socket_client('tcp://' . $address, $errno, $error, 0.1);
            if ($connection) {
                fclose($connection);
                $ready = true;
                break;
            }
            usleep(20000);
        }
        assert_true($ready, 'Isolated approval endpoint did not start.');
        $post = static function (array $payload) use ($address): array {
            $context = stream_context_create(['http' => [
                'method' => 'POST',
                'header' => 'Content-Type: application/json',
                'content' => json_encode($payload),
                'ignore_errors' => true,
                'timeout' => 5,
            ]]);
            $body = file_get_contents('http://' . $address . '/approve', false, $context);
            return [
                'status' => (int)explode(' ', $http_response_header[0])[1],
                'payload' => json_decode($body, true, 512, JSON_THROW_ON_ERROR),
            ];
        };
        $check($pdo, $post);
    } finally {
        if (is_resource($process)) {
            proc_terminate($process);
            proc_close($process);
        }
        unlink($path);
    }
}

test('approval endpoint accepts the matching REX and preserves frozen content', function () {
    with_approval_endpoint_fixture(function (PDO $pdo, callable $post) {
        $response = $post(['document_id' => 4, 'rex_token' => 'valid-doc']);
        assert_equals(200, $response['status']);
        assert_true($response['payload']['ok']);
        $document = $response['payload']['data']['document'];
        assert_equals(4, $document['id']);
        assert_true(!empty($document['accepted_at']));
        assert_equals('<p>Frozen colors</p>', $document['content_html']);
        assert_equals('2026-10-05 09:00:00', $document['locked_at']);
        assert_equals('draft', $document['status']);
        $event = $pdo->query('SELECT * FROM project_activity')->fetch(PDO::FETCH_ASSOC);
        assert_equals('document_approved', $event['event_type']);
        assert_equals('project_document', $event['resource_type']);
        assert_equals(4, $event['resource_id']);
    });
});

test('approval endpoint rejects a valid REX paired with another document', function () {
    with_approval_endpoint_fixture(function (PDO $pdo, callable $post) {
        $response = $post(['document_id' => 5, 'rex_token' => 'valid-doc']);
        assert_equals(400, $response['status']);
        assert_equals(false, $response['payload']['ok']);
        assert_equals(0, (int)$pdo->query('SELECT COUNT(*) FROM project_documents WHERE accepted_at IS NOT NULL')->fetchColumn());
        assert_equals(0, (int)$pdo->query('SELECT COUNT(*) FROM project_activity')->fetchColumn());
    });
});

test('approval endpoint rejects invalid, missing, revoked, non-document and unresolvable REX', function () {
    with_approval_endpoint_fixture(function (PDO $pdo, callable $post) {
        foreach ([null, '', [], 'invalid', 'wrong-type', 'revoked-doc', 'revoked-at', 'missing-doc'] as $token) {
            $payload = ['document_id' => 4];
            if ($token !== null) {
                $payload['rex_token'] = $token;
            }
            $response = $post($payload);
            assert_equals(400, $response['status']);
            assert_equals(false, $response['payload']['ok']);
        }
        assert_equals(0, (int)$pdo->query('SELECT COUNT(*) FROM project_documents WHERE accepted_at IS NOT NULL')->fetchColumn());
        assert_equals(0, (int)$pdo->query('SELECT COUNT(*) FROM project_activity')->fetchColumn());
    });
});

test('repeated authorized approval retains timestamp and creates only one activity event', function () {
    with_approval_endpoint_fixture(function (PDO $pdo, callable $post) {
        $first = $post(['document_id' => 4, 'rex_token' => 'valid-doc']);
        assert_true($first['payload']['ok']);
        $pdo->exec("UPDATE project_documents SET accepted_at = '2026-10-05 09:01:02' WHERE id = 4");
        $second = $post(['document_id' => 4, 'rex_token' => 'valid-doc']);
        assert_equals(200, $second['status']);
        assert_true($second['payload']['ok']);
        assert_equals('2026-10-05 09:01:02', $second['payload']['data']['document']['accepted_at']);
        assert_equals(1, (int)$pdo->query('SELECT COUNT(*) FROM project_activity')->fetchColumn());
    });
});

test('authorized approval does not require a frozen document', function () {
    with_approval_endpoint_fixture(function (PDO $pdo, callable $post) {
        $response = $post(['document_id' => 5, 'rex_token' => 'unfrozen-doc']);
        assert_equals(200, $response['status']);
        assert_true($response['payload']['ok']);
        assert_equals(null, $response['payload']['data']['document']['locked_at']);
    });
});
