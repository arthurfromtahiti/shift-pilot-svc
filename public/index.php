<?php

require_once dirname(__DIR__) . '/vendor/autoload.php';

use App\Db;
use App\Router;

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

    default:
        [$status, $body] = (new Router($pdo))->dispatch($path, $_GET);
        http_response_code($status);
        echo json_encode($body);
}
