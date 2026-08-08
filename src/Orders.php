<?php

namespace App;

use PDO;

/** Domaine « commandes » — lecture seule côté HTTP, la mutation passe par les migrations. */
final class Orders
{
    public function __construct(private PDO $pdo) {}

    /** @return array<int, array<string, mixed>> */
    public function all(): array
    {
        return $this->pdo->query('SELECT id, client, montant_cents, devise, statut FROM orders ORDER BY id')->fetchAll();
    }

    public function find(int $id): ?array
    {
        $st = $this->pdo->prepare('SELECT id, client, montant_cents, devise, statut FROM orders WHERE id = :id');
        $st->execute([':id' => $id]);
        $row = $st->fetch();
        return $row === false ? null : $row;
    }

    public function count(): int
    {
        return (int) $this->pdo->query('SELECT COUNT(*) AS n FROM orders')->fetch()['n'];
    }
}
