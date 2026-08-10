<?php

require_once dirname(__DIR__) . '/vendor/autoload.php';

use App\Db;
use App\Orders;

header('Content-Type: application/json; charset=utf-8');

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$pdo = Db::connect();

// La version SERVIE : lue dans le fichier déposé par le déploiement, jamais
// devinée depuis le code. Si le déploiement n'a pas abouti, ce fichier reste
// celui de la version précédente — c'est exactement ce que L3-5 doit observer.
$versionFile = dirname(__DIR__) . '/deployed-version.json';
$version = is_file($versionFile)
    ? json_decode((string) file_get_contents($versionFile), true)
    : ['sha' => null, 'ref' => null, 'deployedAt' => null];

switch ($path) {
    case '/health':
        echo json_encode(['status' => 'ok']);
        break;

    case '/version':
        echo json_encode($version + ['schemaVersion' => Db::schemaVersion($pdo)]);
        break;

    case '/orders':
        $qLimit = $_GET['limit'] ?? null;
        $qAfter = $_GET['after'] ?? null;
        if ($qLimit !== null || $qAfter !== null) {
            $limitInt = filter_var($qLimit ?? 20, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 100]]);
            if ($limitInt === false) {
                http_response_code(400);
                echo json_encode(['error' => 'Paramètre limit invalide : entier entre 1 et 100 attendu']);
                break;
            }
            $afterInt = null;
            if ($qAfter !== null) {
                $afterInt = filter_var($qAfter, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
                if ($afterInt === false) {
                    http_response_code(400);
                    echo json_encode(['error' => 'Paramètre after invalide : entier >= 0 attendu']);
                    break;
                }
            }
            echo json_encode((new Orders($pdo))->paginate($limitInt, $afterInt));
        } else {
            echo json_encode((new Orders($pdo))->all());
        }
        break;

    default:
        if (preg_match('#^/orders/(\d+)$#', $path, $m)) {
            $order = (new Orders($pdo))->find((int) $m[1]);
            if ($order === null) {
                http_response_code(404);
                echo json_encode(['error' => 'Commande introuvable']);
                break;
            }
            echo json_encode($order);
            break;
        }
        http_response_code(404);
        echo json_encode(['error' => 'Route inconnue']);
}
