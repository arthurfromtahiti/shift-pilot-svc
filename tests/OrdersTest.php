<?php

namespace Tests;

use App\Db;
use App\Orders;
use PHPUnit\Framework\TestCase;

final class OrdersTest extends TestCase
{
    private \PDO $pdo;

    protected function setUp(): void
    {
        // Base temporaire reconstruite depuis les migrations : les tests ne
        // dépendent jamais de l'état du fichier versionné.
        $tmp = tempnam(sys_get_temp_dir(), 'svc') . '.db';
        putenv('SVC_DB_PATH=' . $tmp);
        $this->pdo = Db::connect();
        foreach (glob(dirname(__DIR__) . '/migrations/*.sql') ?: [] as $f) {
            $this->pdo->exec((string) file_get_contents($f));
        }
    }

    public function testListeToutesLesCommandes(): void
    {
        self::assertCount(5, (new Orders($this->pdo))->all());
    }

    public function testTrouveUneCommandeParIdentifiant(): void
    {
        $o = (new Orders($this->pdo))->find(3);
        self::assertNotNull($o);
        self::assertSame('Manoa', $o['client']);
        self::assertSame(960000, (int) $o['montant_cents']);
    }

    public function testIdentifiantInconnuRenvoieNull(): void
    {
        self::assertNull((new Orders($this->pdo))->find(999));
    }

    public function testMontantsStockesEnCentimesEntiers(): void
    {
        foreach ((new Orders($this->pdo))->all() as $o) {
            self::assertIsInt((int) $o['montant_cents']);
            self::assertGreaterThan(0, (int) $o['montant_cents']);
        }
    }

    public function testVersionDeSchemaLueEnBase(): void
    {
        // Aucune ligne dans schema_migrations tant que bin/migrate.php n'a pas tourné.
        self::assertSame(0, Db::schemaVersion($this->pdo));
    }

    public function testPaginatePremierePage(): void
    {
        $r = (new Orders($this->pdo))->paginate(2, null);
        self::assertArrayHasKey('data', $r);
        self::assertArrayHasKey('pagination', $r);
        self::assertCount(2, $r['data']);
        self::assertSame(1, (int) $r['data'][0]['id']);
        self::assertSame(2, (int) $r['data'][1]['id']);
        self::assertSame(2, $r['pagination']['limit']);
        self::assertTrue($r['pagination']['hasMore']);
        self::assertSame(2, $r['pagination']['nextCursor']);
    }

    public function testPaginateAvecCurseur(): void
    {
        $r = (new Orders($this->pdo))->paginate(2, 2);
        self::assertCount(2, $r['data']);
        self::assertSame(3, (int) $r['data'][0]['id']);
        self::assertSame(4, (int) $r['data'][1]['id']);
        self::assertSame(2, $r['pagination']['limit']);
        self::assertTrue($r['pagination']['hasMore']);
        self::assertSame(4, $r['pagination']['nextCursor']);
    }

    public function testPaginateDernierePage(): void
    {
        $r = (new Orders($this->pdo))->paginate(2, 4);
        self::assertCount(1, $r['data']);
        self::assertSame(2, $r['pagination']['limit']);
        self::assertFalse($r['pagination']['hasMore']);
        self::assertNull($r['pagination']['nextCursor']);
    }

    public function testPaginateSansCurseurHasMoreFalsiAvecTout(): void
    {
        $r = (new Orders($this->pdo))->paginate(10, null);
        self::assertCount(5, $r['data']);
        self::assertSame(10, $r['pagination']['limit']);
        self::assertFalse($r['pagination']['hasMore']);
        self::assertNull($r['pagination']['nextCursor']);
    }

    public function testPaginateCurseurApresLaDerniere(): void
    {
        $r = (new Orders($this->pdo))->paginate(5, 5);
        self::assertCount(0, $r['data']);
        self::assertSame(5, $r['pagination']['limit']);
        self::assertFalse($r['pagination']['hasMore']);
        self::assertNull($r['pagination']['nextCursor']);
    }

    public function testPaginateAfterZeroRetourneTout(): void
    {
        $r = (new Orders($this->pdo))->paginate(10, 0);
        self::assertCount(5, $r['data']);
        self::assertSame(1, (int) $r['data'][0]['id']);
        self::assertFalse($r['pagination']['hasMore']);
    }
}
