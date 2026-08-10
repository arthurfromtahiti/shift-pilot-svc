<?php

namespace Tests;

use App\Db;
use PHPUnit\Framework\TestCase;

/**
 * Tests fonctionnels HTTP pour GET /orders/{id}.
 * Démarre le serveur intégré PHP sur un port local éphémère.
 */
final class HttpOrdersTest extends TestCase
{
    private static string $base = 'http://127.0.0.1:18622';
    private static string $dbPath = '';
    /** @var resource|false */
    private static mixed $serverProc = false;

    public static function setUpBeforeClass(): void
    {
        // Base temporaire initialisée depuis les migrations
        $tmp = tempnam(sys_get_temp_dir(), 'http_svc') . '.db';
        self::$dbPath = $tmp;
        putenv('SVC_DB_PATH=' . $tmp);
        $pdo = Db::connect();
        foreach (glob(dirname(__DIR__) . '/migrations/*.sql') ?: [] as $f) {
            $pdo->exec((string) file_get_contents($f));
        }

        // Serveur intégré PHP — même binaire que celui qui exécute les tests
        $docRoot = dirname(__DIR__) . '/public';
        $router  = $docRoot . '/index.php';
        $cmd = [PHP_BINARY, '-S', '127.0.0.1:18622', '-t', $docRoot, $router];
        $env = array_merge($_ENV, getenv() ?: [], ['SVC_DB_PATH' => $tmp]);
        $spec = [['pipe', 'r'], ['file', '/dev/null', 'w'], ['file', '/dev/null', 'w']];
        $pipes = [];
        self::$serverProc = proc_open($cmd, $spec, $pipes, null, $env);

        // Attente que le serveur soit prêt (max 5 s)
        $ctx = stream_context_create(['http' => ['ignore_errors' => true, 'timeout' => 0.5]]);
        $deadline = microtime(true) + 5.0;
        while (microtime(true) < $deadline) {
            usleep(100_000);
            if (@file_get_contents(self::$base . '/health', false, $ctx) !== false) {
                break;
            }
        }
    }

    public static function tearDownAfterClass(): void
    {
        if (is_resource(self::$serverProc)) {
            proc_terminate(self::$serverProc);
            proc_close(self::$serverProc);
        }
        if (self::$dbPath !== '' && is_file(self::$dbPath)) {
            unlink(self::$dbPath);
        }
    }

    /** @return array{status: int, body: mixed} */
    private function request(string $path): array
    {
        $ctx  = stream_context_create(['http' => ['ignore_errors' => true]]);
        $body = (string) file_get_contents(self::$base . $path, false, $ctx);
        // $http_response_header est injecté dans la portée locale par file_get_contents
        preg_match('#HTTP/\S+\s+(\d+)#', $http_response_header[0] ?? '', $m);
        return ['status' => (int) ($m[1] ?? 0), 'body' => json_decode($body, true)];
    }

    public function testCommandeExistanteRenvoie200(): void
    {
        $r = $this->request('/orders/1');
        self::assertSame(200, $r['status']);
        self::assertIsArray($r['body']);
        self::assertArrayHasKey('id', $r['body']);
    }

    public function testCommandeInexistanteRenvoie404(): void
    {
        $r = $this->request('/orders/9999');
        self::assertSame(404, $r['status']);
        self::assertIsArray($r['body']);
        self::assertArrayHasKey('error', $r['body']);
    }

    public function testIdentifiantNonNumeriqueRenvoie400(): void
    {
        $r = $this->request('/orders/abc');
        self::assertSame(400, $r['status']);
        self::assertIsArray($r['body']);
        self::assertArrayHasKey('error', $r['body']);
    }
}
