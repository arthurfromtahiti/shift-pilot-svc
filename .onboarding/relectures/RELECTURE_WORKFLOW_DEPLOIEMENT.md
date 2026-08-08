# Relecture — WORKFLOW_DEPLOIEMENT.md

## Verdict global
Acceptable avec réserves — la chaîne GitHub Actions et la séparation `main`/`staging` sont correctement sourcées. La preuve s'arrête toutefois à la branche `deployed` : le dépôt ne montre pas que l'hôte HTTP reçoit effectivement la version publiée, et quelques formulations continuent de qualifier à tort cet artefact de « version servie ».

## Problèmes bloquants
Aucun défaut factuel bloquant dans le déroulement CI vérifié. `.github/workflows/deploy.yml:9-69` confirme les déclencheurs, l'ordre tests → migrations → écriture → push, le mapping des environnements et `--force-with-lease`. `public/index.php:16-19` lit néanmoins `deployed-version.json` à la racine, fichier que `deploy.yml` ne produit pas.

## Problèmes mineurs
- La confiance `haute` (`WORKFLOW_DEPLOIEMENT.md:10`) doit être explicitement bornée au pipeline et à la branche `deployed`, pas au déploiement effectivement servi. L'analyse le signale déjà en `INCONNU`, mais l'objectif et les données emploient encore « version servie » pour l'artefact de branche (`WORKFLOW_DEPLOIEMENT.md:13-14,69-74`).
- `WORKFLOW_DEPLOIEMENT.md:36,59` dit encore que la « version servie » reste inchangée après échec. C'est prouvé pour le fichier de la branche `deployed` qui n'est pas poussé, mais pas pour l'hôte hors dépôt. `deploy.yml:35-36,69` et l'absence de copie vers `deployed-version.json` imposent de dire « version publiée sur `deployed` ».

## Points vérifiés et corrects
- `.github/workflows/deploy.yml:9-15` : push sur `main`/`staging`, concurrence non annulée par branche.
- `.github/workflows/deploy.yml:23-36` : installation, tests avant migration réelle.
- `.github/workflows/deploy.yml:38-69` : `main` → `production`, `staging` → `staging`, conservation de l'autre sous-chemin et push protégé.
- `README.md:16-35` : distinction des canaux et caractère extérieur de la promotion vers `main`.
- `public/index.php:16-19` et `deploy.yml:42-69` : les deux chemins de version sont distincts ; le maillon hôte n'est pas visible.

## Recommandations de correction
- Reformuler les titres/phrases « version servie » en « version publiée sur la branche `deployed` » tant que l'hôte n'est pas prouvé.
- Conserver une question ouverte explicite sur l'alimentation de `deployed-version.json`; ne pas présenter la branche comme preuve de disponibilité HTTP.
