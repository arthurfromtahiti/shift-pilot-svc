# Relecture — documents de référence Phase 4

## Confiance

high — les trois documents livrés, les workflows actifs et les matériaux amont de `.onboarding/` ont été lus. Le verdict porte sur la traçabilité documentaire ; il ne constitue pas une observation d’exécution GitHub Actions ni de l’hôte servi.

## Verdict global

À corriger — la distinction entre publication Git sur `deployed` et fichier runtime `deployed-version.json` est maintenant explicitée, mais le guide de déploiement conserve une contradiction centrale sur l’indépendance de `ci.yml` et `deploy.yml`. Le guide des migrations conserve aussi un intitulé qui réduit à tort le périmètre CI aux exécutions « après merge ».

## Problèmes bloquants

- **Causalité CI/CD contradictoire.** `GUIDE_DEPLOIEMENT.md:5,12-13` affirme que les workflows sont indépendants et parallèles, mais `GUIDE_DEPLOIEMENT.md:15` dit : « Si les tests (`ci.yml`) échouent, le pipeline s'arrête — aucun commit ne quitte le dépôt ». Or `.github/workflows/ci.yml:2-6,17-20` et `.github/workflows/deploy.yml:9-11,30-36` montrent deux workflows déclenchés séparément ; `deploy.yml` exécute ses propres tests et migrations. Un échec du run `ci.yml` ne prouve donc pas l'arrêt du run `deploy.yml`. Reformuler la phrase en distinguant : échec de la validation propre à `deploy.yml` → pas de publication ; échec de `ci.yml` seul → signal rouge, sans effet causal documenté sur `deploy.yml`.

## Problèmes mineurs

- **Périmètre du CI mal nommé.** `GUIDE_MIGRATIONS.md:127` titre la section « En CI (après merge) », alors que `.github/workflows/ci.yml:3-6` déclenche aussi le CI sur `pull_request`, avant merge. Employer par exemple « En CI (push et pull request) ». La ligne `GUIDE_MIGRATIONS.md:129` le dit déjà correctement (« chaque push et PR »), ce qui rend l'intitulé contradictoire.

- **Terminologie encore trop large dans le README.** `README.md:12-14` présente la « Version servie » comme publiée sur `deployed`, alors que `README.md:41-49` reconnaît que l'hôte servi lit un autre fichier, alimenté hors dépôt et non vérifié. Le contenu explique ensuite la distinction, mais le tableau et `README.md:36-37` (« aucune nouvelle version servie ») peuvent faire attribuer à la branche Git une garantie runtime que les preuves amont limitent à la publication sur `deployed` (`.onboarding/workflows/WORKFLOW_DEPLOIEMENT.md:10,34-35,95`). Employer « version publiée sur `deployed` » pour l'artefact Git, et réserver « version servie » au fichier hôte/API sous statut `INCONNU`.

## Points vérifiés et corrects

- `GUIDE_DEPLOIEMENT.md:68-113` sépare correctement l'artefact Git (`deployed/<env>/version.json`) du fichier lu par `/version` (`deployed-version.json`) et identifie le mécanisme de copie comme externe au dépôt.
- `GUIDE_DEPLOIEMENT.md:74-83` décrit correctement le mapping `staging → deployed/staging` et `main → deployed/production`, ainsi que le rôle de `deploy.yml`.
- `GUIDE_MIGRATIONS.md` reprend les éléments amont sur dry-run, sauvegarde avant mutation, transactions et caractère éphémère des sauvegardes CI ; aucune procédure de restauration complète n'est présentée comme une observation runtime.
- La publication est correctement limitée aux pushes sur `main`/`staging` dans `GUIDE_DEPLOIEMENT.md:10` et `README.md:28-39`, sans la confondre avec une exécution sur PR.

## Recommandations de correction

1. Corriger `GUIDE_DEPLOIEMENT.md:15` pour supprimer la dépendance fictive de `deploy.yml` à la réussite de `ci.yml`.
2. Renommer `GUIDE_MIGRATIONS.md:127` et conserver la distinction push/PR déjà présente dans son contenu.
3. Harmoniser le vocabulaire du tableau et du résumé de `README.md` avec la réserve explicite sur l'hôte.
