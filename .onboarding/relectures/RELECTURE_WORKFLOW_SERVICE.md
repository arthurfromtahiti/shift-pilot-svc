# Relecture — WORKFLOW_SERVICE.md

## Verdict global
Acceptable avec réserves — le routage, les réponses d'erreur, la dépendance préalable à SQLite et la priorité de `schemaVersion` sont maintenant correctement décrits. Il reste une limite non documentée sur le JSON externe et une confiance formulée trop largement pour un fichier de version hors dépôt.

## Problèmes bloquants
 Aucun défaut bloquant après correction : `WORKFLOW_SERVICE.md:50,70,78` décrit désormais la sémantique exacte de `public/index.php:27`, où une clé `schemaVersion` du fichier prime sur la base live. `deploy.yml:56-63` confirme que cette clé est produite lors du déploiement.

## Problèmes mineurs
- Aucun défaut supplémentaire après correction. Le risque du fichier présent contenant un JSON invalide est maintenant couvert (`WORKFLOW_SERVICE.md:87`, `public/index.php:16-18,27`). La confiance reste à lire comme confiance sur le code du service ; le dépôt du fichier externe demeure hors preuve.

## Points vérifiés et corrects
- `public/index.php:8,10-11,21-47` : Content-Type, parsing du chemin, connexion avant routage, endpoints et 404.
- `public/index.php:11` et `src/Db.php:15-21` : `/health` dépend bien de l'ouverture PDO préalable.
- `public/index.php:30-43` et `src/Orders.php:13-23` : liste triée, recherche préparée et 404 commande absente.
- `migrations/001_init.sql:7-13` : contraintes `NOT NULL`, `INTEGER` et défaut `XPF`.
- `public/index.php:8` : réponses JSON, y compris erreurs de routage.
- `public/index.php:8-47` : aucune lecture de `REQUEST_METHOD`; la relecture précédente a été intégrée correctement dans les points d'entrée et risques.

## Recommandations de correction
- Maintenir la sémantique exacte de l'opérateur `+` et la priorité de la valeur du fichier.
- Conserver comme question ouverte hors dépôt la cohérence entre le fichier externe, la base et le dépôt `deployed`.
