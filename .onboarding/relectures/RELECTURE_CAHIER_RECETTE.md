# Relecture — CAHIER_RECETTE.md

## Verdict global
À corriger — le cahier a gagné en précision sur `/version`, mais plusieurs étapes ne sont pas exécutables de façon fiable à partir des préconditions annoncées et certains attendus sont inventés ou trop forts par rapport aux preuves amont.

## Problèmes bloquants

- **Phase 2 suppose un état initial non garanti.** Elle attend `Version de schéma courante : 0`, l'application de `001_init.sql` et une sauvegarde `avant-v1`. Or `WORKFLOW_MIGRATION.md` précise que `data/app.db` est versionnée et peut déjà être à v1 ; le cahier ne réinitialise ni ne remplace `data/app.db` dans le clone avant ces commandes. Dans ce cas, `bin/migrate.php` affiche « aucune migration à appliquer », ne crée pas de sauvegarde et les sorties attendues sont fausses. Ajouter une précondition d'état (ou construire explicitement une base de test vierge) et des attendus conditionnels.

- **Phase 5 vérifie un fichier créé hors périmètre documentaire.** L'étape 5.1.3 écrit `RECETTE.md`, fichier qui n'existe pas dans la liste du dépôt, puis le commit/push déclenche une PR. C'est une modification de recette non tracée et l'étape ne prévoit pas de supprimer le commit/PR selon un chemin sûr avant de conclure. Utiliser un fichier de test explicitement temporaire avec nettoyage, ou décrire la procédure comme test manuel conditionnel et préciser les permissions/risques de push.

- **Le test d'isolation production ne prouve pas l'absence de modification.** Comparer les timestamps des deux artefacts (`CAHIER_RECETTE.md:328-332`) ne démontre pas que `production` n'a pas été modifiée par le push staging : les timestamps peuvent déjà différer, ou être identiques à la seconde. La preuve attendue doit comparer le contenu/SHA de `production/version.json` avant et après, avec une capture préalable.

## Problèmes mineurs

- `composer test 2>&1 | grep -E "testGetAll|testGetById|testResponse"` (`CAHIER_RECETTE.md:203-207`) cherche des noms qui n'existent pas dans `tests/OrdersTest.php` (`testListeToutesLesCommandes`, `testTrouveUneCommandeParIdentifiant`, etc.). La commande peut ne rien afficher tout en laissant croire que la vérification est faite. Utiliser les noms réels ou vérifier uniquement le code de sortie PHPUnit.
- L'attendu « 3+ tests verts » est imprécis alors que l'amont montre exactement cinq méthodes dans `tests/OrdersTest.php` et ne permet pas d'affirmer le résultat d'exécution dans ce checkout (`TESTING_AUDIT.md`).
- La conclusion « application atomique, sauvegarde, rollback possible » mélange atomicité transactionnelle démontrée et restauration manuelle non démontrée. La recette doit distinguer rollback de la migration courante et restauration d'une sauvegarde, et ne pas appeler une simple copie vers `app.db.restored` une restauration effective.
- La recette décrit `/version` avec un statut HTTP précis dans le cas nominal sans exécution observée ; les audits classent les effets HTTP runtime comme inconnus. Garder l'attendu code/JSON, ou marquer le code HTTP comme résultat à observer.

## Points vérifiés et corrects

- Les commandes de `/orders` et les cinq lignes attendues concordent avec `migrations/001_init.sql`.
- Le cas fichier absent de `/version` omet correctement `environnement` (`public/index.php:17-19`).
- Le cas JSON invalide et l'absence de JSON d'erreur structuré sont correctement explicités (`public/index.php:18,27`; `WORKFLOW_SERVICE.md`).
- La distinction entre artefact Git `deployed/<env>/version.json` et fichier runtime `deployed-version.json` est utile et conforme à `WORKFLOW_DEPLOIEMENT.md:95,119`.

## Recommandations de correction

- Rendre la phase migration déterministe : copier une base vierge explicitement, ou remplacer les sorties attendues par des branches « base à v0 » / « base déjà à v1 ».
- Corriger les noms de tests et remplacer le seuil « 3+ » par le résultat attendu de la suite réellement présente.
- Refaire le contrôle staging/production avec un snapshot avant/après du contenu de `origin/deployed:production/version.json`.
- Limiter les conclusions aux comportements prouvés et identifier les observations HTTP comme résultats de recette, pas comme faits déjà établis.
