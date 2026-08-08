# Relecture — ARCHITECTURE_AUDIT.md

## Verdict global
Acceptable avec réserves — les constats statiques sont correctement sourcés et les effets runtime ainsi que l'hébergement sont désormais explicitement bornés par `HYPOTHÈSE`/`INCONNU`.

## Problèmes bloquants

 Aucun défaut bloquant dans la version examinée. Les limites runtime, la configuration `display_errors` et le chaînon d'hébergement sont explicitement qualifiés ; `ARCHITECTURE_AUDIT.md:23` cite correctement `deploy.yml:61`.

## Problèmes mineurs

- La confiance `medium` est cohérente avec les questions ouvertes sur l'hébergement, l'intention de `/health` et la base live.
- « Réduction de la surface d'attaque » est une appréciation de risque, pas un fait déduit de `composer.json:4-5`; préciser qu'elle vaut pour les dépendances déclarées, sans conclure à l'absence de vulnérabilités transitives.

## Points vérifiés et corrects

- `public/index.php:11` appelle bien `Db::connect()` avant le `switch` (`public/index.php:21`).
- `public/index.php:27` conserve bien une clé `schemaVersion` déjà présente dans le tableau gauche; `deploy.yml:56-63` l'écrit dans le JSON généré.

## Recommandations de correction

1. Ajouter à chaque risque la distinction « comportement déduit du code » / « effet observé », et retirer les résultats HTTP non exécutés.
2. Marquer l'absence de preuve de l'hôte servi comme question ouverte `INCONNU`, avec une vérification post-déploiement comme action nécessaire.
3. Maintenir la distinction entre code lu et effets observés lors des évolutions.
