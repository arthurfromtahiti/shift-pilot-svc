# Relecture — SECURITY_ROBUSTNESS_AUDIT.md

## Verdict global
À corriger — les chemins de code fragiles et la correction sur les permissions sont désormais correctement bornés, mais une gravité reste sur-calibrée et l'absence d'accès public n'est pas sourcée.

## Problèmes bloquants

- **Périmètre d'exposition non sourcé.** `SECURITY_ROBUSTNESS_AUDIT.md:7,11` parle de « pas d'accès public », alors que le dépôt établit seulement qu'il s'agit d'un service servi et que `version.json` est publiquement lisible ; aucun fichier ne prouve l'absence d'exposition HTTP. Reformuler en « exposition non vérifiée » ou fournir une preuve d'hôte.
- **Gravité sur-calibrée.** `SECURITY_ROBUSTNESS_AUDIT.md:25` qualifie de « nul » le risque des méthodes HTTP non filtrées, alors que l'absence de contrôle est un fait et que l'impact nul dépend du périmètre futur et d'un éventuel frontal hors dépôt. Reformuler en impact non démontré / conditionnel, borné au service de lecture seule observé.

## Problèmes mineurs

- « Pas d'injection possible » doit être borné aux requêtes actuellement présentes (`src/Orders.php:15,20-21`, `src/Db.php:27,31`) : une lecture statique ne valide pas toutes les configurations ou futures entrées.
- `PRAGMA integrity_check` avant `copy()` ne garantirait pas à lui seul une copie SQLite cohérente pendant une écriture; cette recommandation doit être justifiée (ou remplacée par une sauvegarde SQLite dédiée) et son risque marqué comme conception à confirmer.

## Points vérifiés et corrects

- Les requêtes actuelles sont préparées ou sans entrée utilisateur (`src/Orders.php:15,20-21`).
- Le routeur limite l'identifiant par `#^/orders/(\\d+)$#` (`public/index.php:35`) avant le cast.
- `Db::connect()` active effectivement `PDO::ERRMODE_EXCEPTION` et `foreign_keys` (`src/Db.php:18-20`).

## Recommandations de correction

1. Requalifier les effets runtime en `HYPOTHÈSE`/`INCONNU` ou joindre une exécution PHP réelle.
2. Séparer les observations filesystem de la vérification du code et documenter la commande/source ; le statut `INCONNU` actuel est correct.
3. Nuancer les affirmations absolues de sécurité et la recommandation d'intégrité SQLite.
