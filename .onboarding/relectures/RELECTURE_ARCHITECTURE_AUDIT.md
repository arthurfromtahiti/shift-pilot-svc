# Relecture — ARCHITECTURE_AUDIT.md

## Verdict global
À corriger — les constats statiques principaux sont justes, mais plusieurs comportements runtime et le fonctionnement de l'hébergement sont présentés avec une certitude excessive. Le producteur doit séparer `VÉRIFIÉ_CODE` des effets réellement observés et rendre la conclusion sur la version servie explicitement conditionnelle.

## Problèmes bloquants

- **Statut de preuve incorrect.** `public/index.php:11` prouve que la connexion précède le routage et permet d'inférer le chemin d'exception, mais pas que `/health` répond effectivement HTTP 500 ni que le corps est vide : aucun runtime PHP n'est exécuté dans l'audit. Qualifier l'effet comme `HYPOTHÈSE`/`INCONNU`, ou fournir une reproduction.
- **Configuration non sourcée.** L'affirmation sur `display_errors = Off/On` n'est pas établie par le dépôt : aucun fichier de configuration PHP ou environnement servi n'est cité. Le risque de fuite doit être présenté comme conditionnel et `INCONNU` côté production.
- **Chaînon hors dépôt surqualifié.** La lecture complète de `deploy.yml` prouve seulement qu'il écrit `deployed/<env>/version.json`, pas que le mécanisme d'hébergement est absent. Le constat doit rester `INCONNU`/`HYPOTHÈSE` et ne pas conclure à une fonctionnalité incomplète sans accès à cet hôte.

## Problèmes mineurs

- `Confiance : high` est trop large alors que les questions ouvertes portent sur l'hébergement, l'intention de `/health` et la base live. Abaisser la confiance ou isoler les zones non vérifiables.
- « Réduction de la surface d'attaque » est une appréciation de risque, pas un fait déduit de `composer.json:4-5`; préciser qu'elle vaut pour les dépendances déclarées, sans conclure à l'absence de vulnérabilités transitives.

## Points vérifiés et corrects

- `public/index.php:11` appelle bien `Db::connect()` avant le `switch` (`public/index.php:21`).
- `public/index.php:27` conserve bien une clé `schemaVersion` déjà présente dans le tableau gauche; `deploy.yml:56-63` l'écrit dans le JSON généré.

## Recommandations de correction

1. Ajouter à chaque risque la distinction « comportement déduit du code » / « effet observé », et retirer les résultats HTTP non exécutés.
2. Marquer l'absence de preuve de l'hôte servi comme question ouverte `INCONNU`, avec une vérification post-déploiement comme action nécessaire.
3. Recalibrer la confiance globale et les impacts selon ces limites.
