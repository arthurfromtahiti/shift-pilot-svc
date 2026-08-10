<?php

namespace App;

use PDO;

/** Routeur des routes /orders — renvoie [statusCode, body] sans toucher aux en-têtes HTTP. */
final class Router
{
    public function __construct(private PDO $pdo) {}

    /** @return array{0: int, 1: mixed} */
    public function dispatch(string $path): array
    {
        if ($path === '/orders') {
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
