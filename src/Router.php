<?php

namespace App;

use PDO;

/** Routeur des routes /orders — renvoie [statusCode, body] sans toucher aux en-têtes HTTP. */
final class Router
{
    public function __construct(private PDO $pdo) {}

    /**
     * @param array<string, string> $query
     * @return array{0: int, 1: mixed}
     */
    public function dispatch(string $path, array $query = []): array
    {
        if ($path === '/orders') {
            if (isset($query['limit'])) {
                $val = filter_var($query['limit'], FILTER_VALIDATE_INT);
                if ($val === false || $val <= 0) {
                    return [400, ['error' => 'Paramètre limit invalide']];
                }
                return [200, (new Orders($this->pdo))->limited($val)];
            }
            return [200, (new Orders($this->pdo))->all()];
        }

        if (preg_match('#^/orders/(\d+)$#', $path, $m)) {
            $order = (new Orders($this->pdo))->find((int) $m[1]);
            if ($order === null) {
                return [404, ['error' => 'Commande introuvable']];
            }
            return [200, $order];
        }

        if (preg_match('#^/orders/[^/]+$#', $path)) {
            return [400, ['error' => 'Identifiant invalide']];
        }

        return [404, ['error' => 'Route inconnue']];
    }
}
