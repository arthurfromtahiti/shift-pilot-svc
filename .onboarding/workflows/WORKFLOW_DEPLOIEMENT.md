# WORKFLOW_DEPLOIEMENT — Publication de la version servie sur la branche `deployed`

## Classification
- **Type** : `technical_flow`
- **Sous-type** : Pipeline CI/CD GitHub Actions — deux canaux distincts (staging / production)
- **Visibilité** : `technical`
- **Acteur principal** : Développeur ou agent qui pousse sur `main` ou `staging`
- **Acteurs** : Git (push event) ; GitHub Actions (ubuntu-latest) ; `deploy.yml` ; `bin/migrate.php` ; branche `deployed` sur `origin`
- **Criticité** : Haute — raison d'être explicite du dépôt ; confondre les deux canaux est l'échec que ce banc d'essai sert à détecter
- **Confiance** : haute pour le pipeline CI et la publication sur la branche `deployed` (`deploy.yml` entier vérifié) — déploiement effectivement servi sur l'hôte : **INCONNU** (hors dépôt)
- **Justification** : `.github/workflows/deploy.yml` lu intégralement. README (sections « Les deux canaux », « Version servie ») lu. Branche `origin/deployed` inspectée (deux fichiers `production/version.json` et `staging/version.json`, tous deux à `schemaVersion: 1` — `VÉRIFIÉ_CODE` dans la carte des domaines). Commit `da34e1e` référencé (correction du bug d'écrasement inter-environnements). Le déploiement effectif sur l'hôte servi (alimentation de `deployed-version.json`) est hors dépôt et ne peut être prouvé par ce seul dépôt.

## ⚠️ Distinction critique : CI vs Déploiement

**Deux workflows GitHub Actions distincts orchestrent le pipeline — ne pas les confondre** :

| Aspect | `ci.yml` (CI) | `deploy.yml` (Déploiement) |
|--------|---------------|-------------------------|
| **Déclencheur** | Chaque `push` sur `main`/`staging` + chaque PR | Chaque `push` sur `main`/`staging` seulement |
| **Étapes** | 1. Essai à blanc des migrations 2. Tests PHPUnit | 1. Tests PHPUnit 2. Migrations réelles 3. Publier `version.json` |
| **Effet disque** | Aucun (dry-run seulement) | Modifie `data/app.db` (en CI, éphémère) ; publie sur branche `deployed` |
| **Sortie artefact** | Logs console uniquement | Fichier `deployed/<env>/version.json` persisté sur branche `deployed` |
| **Rôle** | Vérification précoce — donne un signal sur la santé des migrations | Publication de la version vérifiée en tant que source de vérité |

**Conséquence** : Un développeur qui pousse sur une PR (déclenche `ci.yml`) voit le résultat du dry-run et des tests mais **aucune publication ne se fait** — le workflow ne produit aucun artefact de déploiement. Seuls les pushes réels sur les branches (déclenche `deploy.yml`) produisent une version publiée. Cela protège contre les « déploiements accidentels » d'une branche de feature testée.

## Objectif
Publier, après chaque push sur `main` ou `staging` et uniquement si la suite de tests passe, un fichier `version.json` sur la branche `deployed`. Ce fichier est la **source de vérité de la version publiée sur la branche `deployed`** : il porte le SHA déployé, l'environnement, la version de schéma appliquée et l'horodatage. Le mécanisme qui dépose ensuite ce fichier sur l'hôte servi est **hors dépôt** — seule la branche `deployed` est prouvée par ce workflow. Si le workflow échoue (tests rouges, migration échoue, push rejeté), le fichier reste celui du déploiement précédent — un merge ne produit donc **pas** automatiquement une nouvelle version sur la branche `deployed`.

## Acteurs
- **Développeur / agent** : pousse sur `main` ou `staging`, déclenchant le workflow
- **GitHub Actions (ubuntu-latest)** : exécuteur hébergeant le job `publier`
- **`deploy.yml`** (`.github/workflows/deploy.yml`) : orchestrateur du pipeline
- **`bin/migrate.php`** : applique les migrations avant la publication
- **Branche `deployed` sur `origin`** : réceptacle pérenne des fichiers `version.json` par environnement

## Points d'entrée
- `push` sur la branche `staging` → publie `deployed/staging/version.json`
- `push` sur la branche `main` → publie `deployed/production/version.json`
- Aucun déclencheur manuel (`workflow_dispatch`) ni sur PR défini dans ce workflow

## Étapes principales

1. **Déclenchement** : tout `push` sur `main` ou `staging` (`deploy.yml:9-11`).

2. **Concurrence** : `group: deploiement-${{ github.ref }}`, `cancel-in-progress: false` (`deploy.yml:13-15`). Deux pushes simultanés sur la même branche sont **mis en file**, non annulés. Deux pushes sur des branches différentes tournent en parallèle sans conflit (groupes distincts).

3. **Checkout & setup** : `actions/checkout@v4` + `shivammathur/setup-php@v2` (PHP 8.1, extensions `pdo`, `pdo_sqlite`) + `composer install --no-interaction --prefer-dist` (`deploy.yml:23-29`).

4. **Tests avant publication** : `composer test` (PHPUnit) (`deploy.yml:31-32`). ⚠️ Même suite de tests que celle exécutée dans `ci.yml`, **mais cette fois-ci sur un push réel** (pas une PR) — les tests ici ont un poids : ils commandent la publication. Si les tests échouent, **le workflow s'arrête** : aucune migration n'est appliquée, aucun `version.json` n'est mis à jour. La version sur la branche `deployed` reste inchangée — comportement intentionnel documenté dans le README et le commentaire du workflow. Que l'hôte servi reste inchangé est possible mais hors dépôt (dépend du mécanisme de dépôt non visible).

5. **Application des migrations** : `php bin/migrate.php` (sans `--dry-run`) (`deploy.yml:34-36`). S'exécute contre `data/app.db` dans le workspace du runner. La sauvegarde est créée dans `data/backups/` (éphémère — non commitée). En cas d'échec, le workflow s'arrête et la version sur la branche `deployed` reste inchangée (le push n'ayant pas eu lieu).

6. **Détermination de l'environnement** : expression GitHub Actions — `github.ref_name == 'main' && 'production' || 'staging'` → `$CIBLE` vaut `production` pour `main`, `staging` pour tout autre déclencheur (i.e. `staging`) (`deploy.yml:38-41`).

7. **Récupération de la branche `deployed` existante** (pour ne pas écraser l'autre environnement) : `git fetch origin deployed --depth 1` ; si réussit et `FETCH_HEAD` est valide → `git checkout -B deployed FETCH_HEAD` ; sinon → `git checkout --orphan deployed` + `git rm -rf --cached .` (`deploy.yml:48-54`). Cette étape résout le bug original d'écrasement inter-environnements (commit `da34e1e`).

8. **Écriture du `version.json`** : `mkdir -p "$CIBLE"` + heredoc JSON dans `$CIBLE/version.json` (`deploy.yml:55-64`) avec les champs :
   - `sha` : `${{ github.sha }}`
   - `ref` : `${{ github.ref_name }}`
   - `environnement` : `$CIBLE` (`production` ou `staging`)
   - `schemaVersion` : lu via `php -r '… App\Db::schemaVersion(App\Db::connect()) …'` sur la base post-migration
   - `deployedAt` : `$(date -u +%Y-%m-%dT%H:%M:%SZ)` UTC

9. **Commit sur `deployed`** : `git config user.name/email` + `git add $CIBLE/version.json` + `git commit -m "deploy($CIBLE): ${{ github.sha }}"` (ou message « rien à publier » si `git commit` sort avec 1) (`deploy.yml:65-68`).

10. **Push** : `git push origin deployed --force-with-lease` (`deploy.yml:69`). Le `--force-with-lease` protège contre un push concurrent sur `deployed` qui aurait eu lieu depuis la lecture en étape 7.

## Règles métier

- **Deux canaux strictement distincts** : `staging` → `deployed/staging/version.json` ; `main` → `deployed/production/version.json`. Ces deux fichiers coexistent sur la branche `deployed` sans jamais s'écraser (`deploy.yml:38-41`, `mkdir -p "$CIBLE"`).

- **Pas de publication sans suite verte** : les tests (`composer test`) sont exécutés **avant** les migrations et la publication. Un test rouge laisse la version publiée sur la branche `deployed` inchangée (`deploy.yml:31-32`, dont le commentaire emploie l'expression « version servie » — preuve limitée à la branche `deployed`, non à l'hôte hors dépôt).

- **La version servie ne se déduit jamais du code** : `schemaVersion` est lue en base après migration, jamais supposée depuis le nom du fichier de migration (`deploy.yml:46`). De même, `public/index.php` lit `deployed-version.json` depuis le disque, jamais depuis une constante de code (`public/index.php:16-19`).

- **Promotion `staging` → `production` : geste humain, hors chaîne d'agents** : un agent ne pousse jamais sur `main`. Ce principe est explicité dans le README (« La Maintenance et la Production préparent, vérifient et passent la main »), dans la carte des domaines et dans `socle-agence` (liste des environnements autonomes : autonomie de merge sur `staging` uniquement pour `T-SVC`).

- **Pushes simultanés mis en file, non annulés** : `cancel-in-progress: false` garantit que deux pushes rapides sur la même branche produisent bien deux publications successives, non pas la première annulée au profit de la seconde (`deploy.yml:14-15`).

- **`--force-with-lease` sur `deployed`** : évite d'écraser un push concurrent sur cette branche entre le fetch (étape 7) et le push (étape 10) (`deploy.yml:69`).

## Données

- **`deployed/staging/version.json`** et **`deployed/production/version.json`** (branche `deployed` sur `origin`) : artefacts publiés. Champs : `sha`, `ref`, `environnement`, `schemaVersion`, `deployedAt`.
- **`data/app.db`** (checkout du runner) : base sur laquelle les migrations sont appliquées ; non commitée après modification.
- **`data/backups/`** (runner, éphémère) : sauvegarde créée par `bin/migrate.php` avant toute mutation — non versionnée, perdue après le run.
- **`deployed-version.json`** (racine projet, sur l'hôte servi) : lu par `public/index.php` au runtime. **Non produit par ce workflow** — mécanisme de dépôt non visible dans le dépôt (voir Questions ouvertes).

## Intégrations

- **GitHub Actions** : exécuteur et déclencheur. Permissions `contents: write` nécessaires pour pousser sur `deployed` (`deploy.yml:20-22`).
- **`bin/migrate.php`** : invoqué à l'étape `Appliquer les migrations` (`deploy.yml:35-36`).
- **`composer test`** (`phpunit`) : invoqué à l'étape `Tests avant publication` (`deploy.yml:32`).
- Aucune intégration à un hébergeur, un CDN ou un service externe visible dans le dépôt.

## Risques

- **`deployed-version.json` absent ou invalide sur l'hôte servi** : Ce workflow publie `deployed/<env>/version.json` sur la branche `deployed`, mais aucune étape ne copie ce fichier à la racine du projet servi (où `public/index.php` le lit en tant que `deployed-version.json`). Le mécanisme de dépôt n'est pas dans ce dépôt. Conséquences :
  - Si absent : `/version` retourne `{"sha": null, "ref": null, "environnement": null, "schemaVersion": <N>, "deployedAt": null}`. Les champs nulls indiquent un problème de dépôt du fichier, pas une absence de déploiement du code.
  - Si présent mais JSON invalide : le comportement de `json_decode` est détérioré, réponse non garantie.
  - Ces deux scénarios ne sont pas décelables dans les logs de `deploy.yml` — aucune vérification post-push du contenu de `deployed-version.json` n'existe. (`public/index.php:16-19` lit silencieusement ; `deploy.yml` ne valide pas le dépôt.)

- **`data/app.db` non commité après migration en CI** : `deploy.yml` applique les migrations contre la base versionnée dans le workspace, mais ne commite pas le résultat. À la deuxième migration, la base versionnée serait en retard d'une version par rapport au schéma appliqué en CI. Dans l'état actuel (une seule migration, base déjà à v1), ce décalage ne se produit pas encore.

- **`force-with-lease` sur `deployed` peut échouer** : si un push concurrent sur `deployed` a eu lieu entre le fetch et le push, `--force-with-lease` rejette le push et le workflow échoue. La version servie reste inchangée. Situation possible si deux branches (`main` et `staging`) déclenchent simultanément et arrivent toutes deux à l'étape 10 au même moment (leurs groupes de concurrence sont distincts).

- **Pas de vérification post-push** : le workflow n'effectue pas de `GET /version` pour confirmer que la version servie est accessible et correcte après publication. Un problème de dépôt de `deployed-version.json` sur l'hôte serait invisible dans les logs CI.

## Questions ouvertes

- **Mécanisme de dépôt de `deployed-version.json` sur l'hôte** : `deploy.yml` publie `deployed/<env>/version.json` sur la branche `deployed`, mais rien dans le dépôt ne copie ce fichier à la racine du projet servi (où `public/index.php` le lit). Ce maillon est hors dépôt — hébergement, webhook, script serveur ? (Question partagée avec `WORKFLOW_SERVICE`.)

- **`data/app.db` versionné et migrations en CI** : est-il prévu que `data/app.db` soit mis à jour et commité après chaque nouvelle migration, ou que les migrations s'appliquent à chaque déploiement contre la base « de départ » versionnée ? La réponse conditionne la gestion des futures migrations.

- **Absence de `workflow_dispatch`** : il n'est pas possible de re-déclencher manuellement le déploiement sans pousser un nouveau commit. Est-ce intentionnel ?

## Preuves
- `.github/workflows/deploy.yml` — lu intégralement
- `.github/workflows/ci.yml` — lu intégralement
- `public/index.php` — lu intégralement (lecture de `deployed-version.json`)
- `README.md` — lu (sections « Les deux canaux », « Version servie »)
- `CARTE_DES_DOMAINES.md` (.onboarding/domaines/) — domaine `deploiement` : branche `origin/deployed` inspectée, `production/version.json` et `staging/version.json` tous deux à `schemaVersion: 1`
- Commit `da34e1e` — cité dans README et carte des domaines (correction du bug d'écrasement inter-environnements)
