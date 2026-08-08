# Relecture — SECURITY_ROBUSTNESS_AUDIT.md

## Verdict global
À corriger — les chemins de code fragiles sont correctement repérés, mais les conclusions « pas d'injection possible », « permissions cohérentes » et les effets HTTP/stack trace dépassent les preuves disponibles. Les risques doivent être bornés au code lu et aux conditions non observées.

## Problèmes bloquants

- **Runtime présenté comme fait.** `public/index.php:17-18,27` permet de vérifier statiquement le chemin `json_decode(null)` puis l'addition de tableaux; il ne prouve pas qu'une requête `/version` produit effectivement une `TypeError`. La conséquence doit être `HYPOTHÈSE` tant qu'elle n'est pas reproduite sous PHP 8.1.
- **Configuration d'exécution absente.** « Stack trace exposée avec `display_errors = On` » est conditionnel, et le rapport ne prouve pas la valeur de cette configuration sur l'hôte. « Pour ce banc d'essai, impact nul » est aussi une conclusion non démontrée : le dépôt affirme seulement que les données sont fictives.
- **Observation de permissions non traçable.** `data/app.db` à `644` et `data/backups` à `775` sont annoncés `VÉRIFIÉ_CODE`, alors que ce sont des propriétés du filesystem observé, non du code. Il faut les marquer `OBSERVÉ` avec une preuve de commande reproductible, ou `INCONNU`.

## Problèmes mineurs

- « Pas d'injection possible » doit être borné aux requêtes actuellement présentes (`src/Orders.php:15,20-21`, `src/Db.php:27,31`) : une lecture statique ne valide pas toutes les configurations ou futures entrées.
- `PRAGMA integrity_check` avant `copy()` ne garantirait pas à lui seul une copie SQLite cohérente pendant une écriture; cette recommandation doit être justifiée (ou remplacée par une sauvegarde SQLite dédiée) et son risque marqué comme conception à confirmer.

## Points vérifiés et corrects

- Les requêtes actuelles sont préparées ou sans entrée utilisateur (`src/Orders.php:15,20-21`).
- Le routeur limite l'identifiant par `#^/orders/(\\d+)$#` (`public/index.php:35`) avant le cast.
- `Db::connect()` active effectivement `PDO::ERRMODE_EXCEPTION` et `foreign_keys` (`src/Db.php:18-20`).

## Recommandations de correction

1. Requalifier les effets runtime en `HYPOTHÈSE`/`INCONNU` ou joindre une exécution PHP réelle.
2. Séparer les observations filesystem de la vérification du code et documenter la commande/source.
3. Nuancer les affirmations absolues de sécurité et la recommandation d'intégrité SQLite.
