# Relecture — TESTING_AUDIT.md

## Verdict global
À corriger — l'inventaire des tests et des zones non couvertes est utile, mais le rapport confond configuration de CI avec exécution observée et affirme des résultats de tests sans preuve runtime disponible.

## Problèmes bloquants

- « La CI exécute la suite à chaque push et pull request » est vérifiable comme configuration (`.github/workflows/ci.yml:3-6,19-20`), pas comme exécution réussie. Le statut doit être `VÉRIFIÉ_CODE` pour le déclencheur configuré et `INCONNU` pour le résultat réel; le rapport doit éviter « suite verte » sans run identifié.
- « Isolation exemplaire » et « chaque test repart » ne sont pas entièrement prouvés par `setUp`: aucun `tearDown()` ne ferme/supprime la base, et `SVC_DB_PATH` reste défini entre tests. Le rapport relève le fichier orphelin mais ne discute pas ce résidu d'environnement.
- Les tests sont annoncés comme couvrant `Db::schemaVersion`, mais ils recréent seulement le schéma SQL et vérifient `0` (`tests/OrdersTest.php:19-22,51-54`); ils ne testent pas le registre après `bin/migrate.php`. Reformuler la portée pour ne pas suggérer une couverture du migrateur.

## Problèmes mineurs

- Les affirmations « retourne », « valide » et « échouerait » doivent distinguer lecture du test et exécution de PHPUnit, particulièrement puisque `php` est indisponible dans cet environnement de relecture.
- « Couverture nulle » signifie absence de tests identifiés, pas nécessairement un taux de couverture mesuré; employer « aucune couverture de tests visible ».

## Points vérifiés et corrects

- `tests/OrdersTest.php` contient bien cinq méthodes de test et aucun test du routeur/migrateur (`tests/OrdersTest.php:9-55`).
- `.github/workflows/ci.yml:17-20` ordonne le dry-run puis `composer test`.
- `phpunit.xml` ne configure pas de rapport de couverture visible dans les fichiers lus.

## Recommandations de correction

1. Séparer couverture déclarée par lecture statique et résultats d'exécution, avec SHA/run si disponible.
2. Corriger l'analyse du cycle de vie `SVC_DB_PATH`/fichiers temporaires et l'absence de `tearDown()`.
3. Ajouter le cas de collision des numéros de migration aux tests prioritaires avec `bin/migrate.php`.
