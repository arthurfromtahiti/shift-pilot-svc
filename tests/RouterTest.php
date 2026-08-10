<?php

namespace Tests;

use App\Db;
use App\Router;
use PHPUnit\Framework\TestCase;

final class RouterTest extends TestCase
{
    private \PDO $pdo;
    private Router $router;

    protected function setUp(): void
    {
        $tmp = tempnam(sys_get_temp_dir(), 'svc') . '.db';
        putenv('SVC_DB_PATH=' . $tmp);
        $this->pdo = Db::connect();
        foreach (glob(dirname(__DIR__) . '/migrations/*.sql') ?: [] as $f) {
            $this->pdo->exec((string) file_get_contents($f));
        }
        $this->router = new Router($this->pdo);
    }

    public function testCommandeExistanteRenvoie200(): void
    {
        [$status, $body] = $this->router->dispatch('/orders/1');
        self::assertSame(200, $status);
        self::assertIsArray($body);
        self::assertArrayHasKey('id', $body);
        self::assertSame(1, (int) $body['id']);
    }

    public function testCommandeInconnueRenvoie404AvecCorpsJson(): void
    {
        [$status, $body] = $this->router->dispatch('/orders/9999');
        self::assertSame(404, $status);
        self::assertIsArray($body);
        self::assertArrayHasKey('error', $body);
    }

    public function testIdentifiantNonNumeriqueRenvoie400(): void
    {
        [$status, $body] = $this->router->dispatch('/orders/abc');
        self::assertSame(400, $status);
        self::assertIsArray($body);
        self::assertArrayHasKey('error', $body);
    }

    public function testListeDesCommandesRenvoie200(): void
    {
        [$status, $body] = $this->router->dispatch('/orders');
        self::assertSame(200, $status);
        self::assertIsArray($body);
        self::assertCount(5, $body);
    }

    public function testRouteInconnueRenvoie404(): void
    {
        [$status, $body] = $this->router->dispatch('/unknown');
        self::assertSame(404, $status);
        self::assertArrayHasKey('error', $body);
    }
}
