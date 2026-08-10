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

    public function testClientRefPresentSurChaqueCommandeDeLaListe(): void
    {
        foreach ((new Orders($this->pdo))->all() as $o) {
            self::assertArrayHasKey('clientRef', $o, 'Le champ clientRef doit être exposé dans all()');
            self::assertStringStartsWith('CLI-', $o['clientRef']);
        }
    }

    public function testClientRefPresentSurUneCommandeUnique(): void
    {
        $o = (new Orders($this->pdo))->find(3);
        self::assertNotNull($o);
        self::assertArrayHasKey('clientRef', $o, 'Le champ clientRef doit être exposé dans find()');
        self::assertSame('CLI-MANOA', $o['clientRef']);
    }

    public function testChampClientResteInchange(): void
    {
        $o = (new Orders($this->pdo))->find(3);
        self::assertNotNull($o);
        self::assertArrayHasKey('client', $o);
        self::assertSame('Manoa', $o['client']);
    }

    public function testVersionDeSchemaLueEnBase(): void
    {
        // Aucune ligne dans schema_migrations tant que bin/migrate.php n'a pas tourné.
        self::assertSame(0, Db::schemaVersion($this->pdo));
    }
}
