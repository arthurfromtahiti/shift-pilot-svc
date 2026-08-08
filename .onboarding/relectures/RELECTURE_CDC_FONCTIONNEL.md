# Relecture — CDC_FONCTIONNEL.md

## Verdict global
À corriger — les corrections précédentes traitent bien le JSON invalide et la clé `environnement` absente du fallback, mais le CDC conserve des affirmations contradictoires avec les workflows et les audits validés. Il ne peut pas être approuvé tant que la frontière entre artefact Git `deployed` et hôte réellement servi, ainsi que la priorité de `schemaVersion`, n'est pas rétablie.

## Problèmes bloquants

- **Priorité de `schemaVersion` décrite à l'envers.** Le CDC affirme en règle 4.1 que `schemaVersion` est lue « en base après migration » et décrit le cas 2 comme une fusion avec une valeur `<N>` issue de la base. Or `public/index.php:27` fait `$version + ['schemaVersion' => Db::schemaVersion($pdo)]` : la clé du tableau de gauche prime. Si le JSON valide contient `schemaVersion` — ce que `.github/workflows/deploy.yml:61` écrit — la réponse reprend la valeur du fichier, pas la base live. Le constat est explicitement établi dans `WORKFLOW_SERVICE.md` (« la valeur du fichier prime ») et `ARCHITECTURE_AUDIT.md`.

- **Confusion entre publication Git et version servie.** Les règles 2.1, 2.2 et 4.2 parlent de la « version servie » comme si elle était garantie par le workflow. La preuve amont limite cette garantie à `deployed/<env>/version.json` sur la branche `deployed` : `WORKFLOW_DEPLOIEMENT.md:10-11,35,57,95` classe le dépôt sur l'hôte comme inconnu et indique que le mécanisme de copie vers `deployed-version.json` est hors dépôt. Le CDC doit donc qualifier ces affirmations de preuve de branche `deployed`, et marquer l'effet sur l'hôte comme `INCONNU`/hypothèse conditionnelle.

- **Acteurs et capacités non entièrement traçables.** « Administrateur production » qui anime la promotion, « serveur web ... enregistre les appels API » et l'autorisation donnée au développeur de « déclencher le déploiement » ne sont pas démontrés par le code/workflows disponibles. Les matériaux amont prouvent un geste humain mentionné dans README/carte pour la promotion, mais pas un rôle d'administrateur ni des logs d'appels. Ces formulations doivent être sourcées précisément ou rétrogradées en hypothèses/limites.

## Problèmes mineurs

- La preuve de la règle 1.3 cite des tests qui vérifient surtout la couche `Orders`, pas le routeur HTTP ni les codes/réponses JSON : `TESTING_AUDIT.md` indique explicitement l'absence de couverture de `public/index.php`. Remplacer « tous les appels retournent » par un comportement de code vérifié, en séparant les effets runtime non observés.
- Le cycle « rollback possible » (`CDC_FONCTIONNEL.md:179`) décrit une restauration manuelle, mais aucun workflow validé ne démontre une procédure de restauration complète ; le matériau amont ne prouve que la création de la sauvegarde et le rollback transactionnel de la migration courante (`WORKFLOW_MIGRATION.md`).
- La liste des preuves finales cite `src/Server.php`, fichier absent de ce checkout ; la classe effectivement utilisée est `src/Orders.php`. Cette référence orpheline doit être supprimée/corrigée.

## Points vérifiés et corrects

- Les cinq commandes et leurs valeurs correspondent à `migrations/001_init.sql` et aux assertions de `tests/OrdersTest.php`.
- Le fallback sans fichier omet bien `environnement`, conformément à `public/index.php:17-19`.
- Le JSON invalide est bien identifié comme un chemin `json_decode() -> null` puis `null + array`, sans gestion structurée, conformément à `public/index.php:18,27` et `WORKFLOW_SERVICE.md`.
- Les sauvegardes avant application et les transactions par migration sont correctement rattachées à `bin/migrate.php:50-75` et au workflow migration.

## Recommandations de correction

- Réécrire le contrat `/version` en trois cas : fichier absent → base pour `schemaVersion`; fichier valide avec `schemaVersion` → valeur du fichier; fichier valide sans cette clé → base; JSON invalide → exception non structurée. Indiquer explicitement la priorité du tableau de gauche.
- Remplacer toute garantie sur l'hôte servi par une formulation limitée à la branche `deployed`, et déplacer le résultat runtime dans « Questions ouvertes / INCONNU ».
- Sourcer ou marquer comme hypothèses les acteurs, l'enregistrement des appels et les capacités de promotion.
- Corriger la référence `src/Server.php` et distinguer couverture de code, comportement statique et résultat HTTP observé.
