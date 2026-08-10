<?php

namespace Tests;

use App\Db;
use App\Orders;
use PHPUnit\Framework\TestCase;

final class ClientRefTest extends TestCase
{
    private \PDO $pdo;

    protected function setUp(): void
    {
        $tmp = tempnam(sys_get_temp_dir(), 'svc') . '.db';
        putenv('SVC_DB_PATH=' . $tmp);
        $this->pdo = Db::connect();
        foreach (glob(dirname(__DIR__) . '/migrations/*.sql') ?: [] as $f) {
            $this->pdo->exec((string) file_get_contents($f));
        }
    }

    public function testToutesLesCommandesPortentClientRef(): void
    {
        $orders = (new Orders($this->pdo))->all();
        self::assertCount(5, $orders);
        foreach ($orders as $order) {
            self::assertArrayHasKey('clientRef', $order);
            self::assertMatchesRegularExpression('/^CLI-[A-Z]+$/', $order['clientRef']);
        }
    }

    public function testClientRefEgalCLIPlusUpperClient(): void
    {
        foreach ((new Orders($this->pdo))->all() as $order) {
            self::assertSame('CLI-' . strtoupper($order['client']), $order['clientRef']);
        }
    }

    public function testHeiataRefEstCLIHEIATA(): void
    {
        $order = (new Orders($this->pdo))->find(1);
        self::assertNotNull($order);
        self::assertSame('Heiata', $order['client']);
        self::assertSame('CLI-HEIATA', $order['clientRef']);
    }

    public function testClientNomAffichéRestantInchange(): void
    {
        $order = (new Orders($this->pdo))->find(1);
        self::assertSame('Heiata', $order['client']);
    }

    public function testFindExposeAussiClientRef(): void
    {
        $order = (new Orders($this->pdo))->find(3);
        self::assertNotNull($order);
        self::assertSame('Manoa', $order['client']);
        self::assertSame('CLI-MANOA', $order['clientRef']);
    }
}
