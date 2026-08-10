<?php

namespace Tests;

use App\Db;
use PHPUnit\Framework\TestCase;

/**
 * Tests fonctionnels HTTP pour GET /orders et GET /orders/{id}.
 * Démarre le serveur intégré PHP sur un port local éphémère.
 */
final class HttpOrdersTest extends TestCase
{
    private static string $base = 'http://127.0.0.1:18635';
    private static string $dbPath = '';
    /** @var resource|false */
    private static mixed $serverProc = false;

    public static function setUpBeforeClass(): void
    {
        $tmp = tempnam(sys_get_temp_dir(), 'http_svc') . '.db';
        self::$dbPath = $tmp;
        putenv('SVC_DB_PATH=' . $tmp);
        $pdo = Db::connect();
        foreach (glob(dirname(__DIR__) . '/migrations/*.sql') ?: [] as $f) {
            $pdo->exec((string) file_get_contents($f));
        }

        $docRoot = dirname(__DIR__) . '/public';
        $router  = $docRoot . '/index.php';
        $cmd = [PHP_BINARY, '-S', '127.0.0.1:18635', '-t', $docRoot, $router];
        $env = array_merge($_ENV, getenv() ?: [], ['SVC_DB_PATH' => $tmp]);
        $spec = [['pipe', 'r'], ['file', '/dev/null', 'w'], ['file', '/dev/null', 'w']];
        $pipes = [];
        self::$serverProc = proc_open($cmd, $spec, $pipes, null, $env);

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
        preg_match('#HTTP/\S+\s+(\d+)#', $http_response_header[0] ?? '', $m);
        return ['status' => (int) ($m[1] ?? 0), 'body' => json_decode($body, true)];
    }

    public function testSansParamRenvoieTableauBrut(): void
    {
        $r = $this->request('/orders');
        self::assertSame(200, $r['status']);
        self::assertIsArray($r['body']);
        // comportement inchangé : tableau plat, pas d'enveloppe de pagination
        self::assertArrayNotHasKey('data', $r['body']);
        self::assertCount(5, $r['body']);
    }

    public function testCommandeParIdRenvoie200(): void
    {
        $r = $this->request('/orders/1');
        self::assertSame(200, $r['status']);
        self::assertSame(1, (int) $r['body']['id']);
        self::assertSame('Heiata', $r['body']['client']);
    }

    public function testCommandeExistanteExposeClientRef(): void
    {
        $r = $this->request('/orders/1');
        self::assertSame(200, $r['status']);
        self::assertIsArray($r['body']);
        self::assertArrayHasKey('clientRef', $r['body']);
        self::assertSame('CLI-HEIATA', $r['body']['clientRef']);
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

    public function testLimitRenvoieFormatPagine(): void
    {
        $r = $this->request('/orders?limit=2');
        self::assertSame(200, $r['status']);
        self::assertArrayHasKey('data', $r['body']);
        self::assertArrayHasKey('pagination', $r['body']);
        self::assertCount(2, $r['body']['data']);
        self::assertSame(2, $r['body']['pagination']['limit']);
        self::assertTrue($r['body']['pagination']['hasMore']);
        self::assertSame(2, $r['body']['pagination']['nextCursor']);
    }

    public function testAfterEtLimitPagineCurseur(): void
    {
        $r = $this->request('/orders?after=2&limit=2');
        self::assertSame(200, $r['status']);
        self::assertCount(2, $r['body']['data']);
        self::assertSame(3, (int) $r['body']['data'][0]['id']);
        self::assertSame(2, $r['body']['pagination']['limit']);
        self::assertTrue($r['body']['pagination']['hasMore']);
    }

    public function testDernierePageHasMoreFaux(): void
    {
        $r = $this->request('/orders?after=4&limit=5');
        self::assertSame(200, $r['status']);
        self::assertCount(1, $r['body']['data']);
        self::assertSame(5, $r['body']['pagination']['limit']);
        self::assertFalse($r['body']['pagination']['hasMore']);
        self::assertNull($r['body']['pagination']['nextCursor']);
    }

    public function testAfterZeroRetourneToutDepuisDebut(): void
    {
        $r = $this->request('/orders?after=0&limit=10');
        self::assertSame(200, $r['status']);
        self::assertArrayHasKey('data', $r['body']);
        self::assertCount(5, $r['body']['data']);
        self::assertSame(1, (int) $r['body']['data'][0]['id']);
        self::assertFalse($r['body']['pagination']['hasMore']);
    }

    public function testLimitInvalideRenvoie400(): void
    {
        $r = $this->request('/orders?limit=abc');
        self::assertSame(400, $r['status']);
        self::assertArrayHasKey('error', $r['body']);
    }

    public function testLimitZeroRenvoie400(): void
    {
        $r = $this->request('/orders?limit=0');
        self::assertSame(400, $r['status']);
        self::assertArrayHasKey('error', $r['body']);
    }

    public function testLimitTropGrandRenvoie400(): void
    {
        $r = $this->request('/orders?limit=101');
        self::assertSame(400, $r['status']);
        self::assertArrayHasKey('error', $r['body']);
    }

    public function testAfterInvalideRenvoie400(): void
    {
        $r = $this->request('/orders?after=abc&limit=2');
        self::assertSame(400, $r['status']);
        self::assertArrayHasKey('error', $r['body']);
    }

    public function testAfterSeulSansLimitRenvoie400(): void
    {
        $r = $this->request('/orders?after=1');
        self::assertSame(400, $r['status']);
        self::assertArrayHasKey('error', $r['body']);
    }
}
