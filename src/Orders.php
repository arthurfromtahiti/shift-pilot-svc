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
        return $this->pdo->query('SELECT id, client, montant_cents, devise, statut, client_ref AS clientRef FROM orders ORDER BY id')->fetchAll();
    }

    public function find(int $id): ?array
    {
        $st = $this->pdo->prepare('SELECT id, client, montant_cents, devise, statut, client_ref AS clientRef FROM orders WHERE id = :id');
        $st->execute([':id' => $id]);
        $row = $st->fetch();
        return $row === false ? null : $row;
    }

    public function count(): int
    {
        return (int) $this->pdo->query('SELECT COUNT(*) AS n FROM orders')->fetch()['n'];
    }

    /**
     * Retourne une page de commandes (curseur par id).
     * Récupère limit+1 lignes pour détecter s'il en reste davantage.
     *
     * @return array{data: list<array<string,mixed>>, pagination: array{limit: int, nextCursor: int|null, hasMore: bool}}
     */
    public function paginate(int $limit, ?int $afterId): array
    {
        $fetch = $limit + 1;
        if ($afterId !== null) {
            $st = $this->pdo->prepare(
                'SELECT id, client, montant_cents, devise, statut FROM orders WHERE id > :after ORDER BY id LIMIT :limit'
            );
            $st->bindValue(':after', $afterId, PDO::PARAM_INT);
        } else {
            $st = $this->pdo->prepare(
                'SELECT id, client, montant_cents, devise, statut FROM orders ORDER BY id LIMIT :limit'
            );
        }
        $st->bindValue(':limit', $fetch, PDO::PARAM_INT);
        $st->execute();
        $rows = $st->fetchAll();

        $hasMore = count($rows) > $limit;
        if ($hasMore) {
            array_pop($rows);
        }
        $nextCursor = $hasMore ? (int) end($rows)['id'] : null;

        return [
            'data'       => array_values($rows),
            'pagination' => ['limit' => $limit, 'nextCursor' => $nextCursor, 'hasMore' => $hasMore],
        ];
    }
}
