# CDC_FONCTIONNEL — shift-pilot-svc

> **Confiance** : high  
> **Dernière mise à jour** : 2026-08-08  
> **Base de preuve** : audits complets, workflows validés, code PHP relus, migrations testées

## Résumé

Service HTTP de lecture seule exposant un modèle de commandes persistées en SQLite. Deux environnements isolés (staging/production) gérés par un pipeline CI/CD deux canaux. Les migrations de schéma sont atomiques et adossées à une sauvegarde obligatoire.

---

## Acteurs et leurs capacités

### Utilisateurs externes
- **Consommateur API** : Lit les quatre endpoints publics (`/health`, `/version`, `/orders`, `/orders/{id}`) sans authentification. Accès en lecture seule, aucune mutation permise.

### Opérateurs internes
- **Développeur** : Clone le dépôt, exécute `composer test` et `composer migrate:dry` localement. Peut créer des branches de test, pousser sur `staging`, déclencher le déploiement.
- **Agent CI/CD** (GitHub Actions) : Exécute les tests, applique les migrations (`composer migrate`), publie la version sur `deployed`.
- **Administrateur production** : Anime la promotion `staging` → `main` (hors chaîne d'agents, geste manuel).

### Systèmes
- **GitHub Actions** : Orchestre CI/CD, exécute les migrations en isolation (`ubuntu-latest`), pousse sur `deployed`.
- **Serveur web** : Héberge la version servie, dépose `deployed-version.json` (hors dépôt), enregistre les appels API.

---

## Parcours critiques (par domaine métier)

### 1. Gestion des commandes — lecture pure

**Acteur** : Consommateur API  
**Déclencheur** : Requête HTTP GET  
**Résultat** : JSON des commandes (toutes ou filtrée par ID)

#### Règle 1.1 : Endpoint `/orders` retourne toutes les commandes
- **Énoncé** : Un appel `GET /orders` retourne le tableau JSON de toutes les commandes présentes en base.
- **Données** : Au démarrage, 5 commandes fictives (`001_init.sql`) avec champs `id`, `client`, `montant_cents`, `devise`, `statut`.
- **Règle métier** : Les commandes ne peuvent pas être éditées ni supprimées via l'API — ensemble immuable une fois déployé.
- **Preuve** : `src/Orders.php:13-16`, méthode `all()` exécute `SELECT id, client, montant_cents, devise, statut FROM orders` ; `tests/OrdersTest.php::testListeToutesLesCommandes()` vérifie le nombre et les types.

#### Règle 1.2 : Endpoint `/orders/{id}` retourne une commande par ID
- **Énoncé** : Un appel `GET /orders/1` retourne un objet JSON avec les champs `id`, `client`, `montant_cents`, `devise`, `statut`.
- **Comportement absent** : Si l'ID n'existe pas, le statut HTTP est 404 et le corps est `{"error": "Commande introuvable"}`.
- **Preuve** : `src/Orders.php:18-24`, méthode `find($id)` retourne `null` si absent ; `public/index.php:34-44` traduit `null` en réponse 404 + JSON d'erreur ; test `OrdersTest.php::testIdentifiantInconnuRenvoieNull()`.

#### Règle 1.3 : Tous les appels à `/orders` et `/orders/{id}` retournent du JSON valide avec le schéma défini
- **Énoncé** : Chaque commande JSON porte les champs `id` (INTEGER), `client` (TEXT), `montant_cents` (INTEGER), `devise` (TEXT, défaut 'XPF'), `statut` (TEXT). Tous obligatoires, jamais null pour des commandes existantes.
- **Preuve** : Jeu de données `001_init.sql` définit le schéma et 5 lignes d'exemple ; `src/Orders.php:15,20` structure les SELECT ; tests `OrdersTest.php::testMontantsStockesEnCentimesEntiers()` et `testTrouveUneCommandeParIdentifiant()` vérifient les champs.

---

### 2. Sécurité du cycle de production — deux canaux distincts

**Acteur** : Développeur + CI/CD  
**Déclencheur** : Push sur `staging` ou `main`  
**Résultat** : Code exécuté sur le serveur, version enregistrée sur `deployed`

#### Règle 2.1 : Staging et production sont isolés
- **Énoncé** : Un push sur `staging` ne doit jamais modifier la version servie de `main` (production).
- **Mécanisme** : Deux fichiers de version distincts (`deployed/staging/version.json` et `deployed/production/version.json`) coexistent sur la branche `deployed` sans jamais s'écraser.
- **Règle de pipeline** : La branche cible du `version.json` est déterminée par `github.ref_name` — une variable immuable du déclencheur Git.
- **Preuve** : `.github/workflows/deploy.yml:38-41` — expression GitHub Actions `github.ref_name == 'main' && 'production' || 'staging'` ; `mkdir -p "$CIBLE"` isole les répertoires ; commit da34e1e (correction du bug d'écrasement inter-environnements) documenté dans README et carte des domaines.

#### Règle 2.2 : Pas de publication sans suite verte
- **Énoncé** : Si la suite de tests échoue, aucune nouvelle version n'est publiée sur `deployed` ; la version précédente reste servie.
- **Mécanisme** : `composer test` (PHPUnit) s'exécute **avant** les migrations et la publication. Tout `exit` non-zéro arrête le workflow.
- **Preuve** : `.github/workflows/deploy.yml:31-32` — ordre des étapes ; commentaire « Test avant publication ».

#### Règle 2.3 : Chaque publication enregistre le SHA et l'horodatage réels
- **Énoncé** : Le `version.json` publié porte le SHA du commit réel, l'environnement, et l'horodatage UTC de la publication.
- **Données** : Champs `sha` (GitHub SHA), `ref` (branche git), `environnement` (`staging` ou `production`), `schemaVersion` (version en base), `deployedAt` (UTC).
- **Preuve** : `.github/workflows/deploy.yml:44-64` — lecture du SHA depuis GitHub Actions, construction du JSON avec `date -u +%Y-%m-%dT%H:%M:%SZ`.

#### Règle 2.4 : Promotion staging → production est hors chaîne d'agents
- **Énoncé** : Aucun agent n'est autorisé à pousser sur `main`. La promotion est un geste manuel, effectué par Maintenance ou Production.
- **Preuve** : README.md — « La Maintenance et la Production préparent, vérifient et **passent la main** » ; `socle-agence` énumère les environnements et leur autonomie : seul `staging` est autonome pour `T-SVC`.

---

### 3. Migrations de schéma — atomicité et sauvegarde

**Acteur** : Développeur (local) ou CI/CD (déploiement)  
**Déclencheur** : Exécution de `bin/migrate.php` ou `composer migrate`  
**Résultat** : Base mise à jour, sauvegarde créée, version enregistrée

#### Règle 3.1 : La sauvegarde est obligatoire avant toute migration
- **Énoncé** : Avant d'appliquer **tout fichier de migration**, une copie horodatée de la base est créée dans `data/backups/app-<YYYYMMDD-HHmmss>-avant-v<N>.db`. Si la copie échoue, aucune migration n'est appliquée.
- **Mécanisme** : `bin/migrate.php:51-59`, méthode `copy()` exécutée **avant** la boucle d'application. Si `copy()` retourne faux : message STDERR + `exit(1)`.
- **Preuve** : `bin/migrate.php:56` — `if (!copy(Db::path(), $sauvegarde)) { ... exit(1); }`.

#### Règle 3.2 : Seules les migrations non encore enregistrées sont appliquées
- **Énoncé** : Une migration n'est appliquée que si son numéro de version (préfixe `NNN_`) est strictement supérieur à la version en base (colonne `MAX(version)` de `schema_migrations`).
- **Mécanisme** : Filtre `(int) $m[1] > $courante`, où `$m[1]` est le préfixe numérique extrait par regex `/(\d+)_/` et `$courante` est lue en base.
- **Preuve** : `bin/migrate.php:24-31`.

#### Règle 3.3 : Chaque migration s'exécute dans sa propre transaction
- **Énoncé** : Un fichier de migration s'exécute dans un bloc `BEGIN TRANSACTION ... COMMIT` en PDO. En cas d'erreur SQL, seule cette migration est annulée (ROLLBACK) ; les migrations déjà appliquées lors des itérations précédentes **restent persistées**.
- **Mécanisme** : `$pdo->beginTransaction()`, `$pdo->exec(file_get_contents($f))`, enregistrement dans `schema_migrations`, `$pdo->commit()`. En cas d'exception, `$pdo->rollBack()` + `exit(1)`.
- **Preuve** : `bin/migrate.php:62-76`.

#### Règle 3.4 : Le registre `schema_migrations` est mis à jour dans la même transaction que le SQL
- **Énoncé** : La ligne `INSERT INTO schema_migrations (version, applied_at)` est enregistrée dans la même transaction que l'exécution du fichier SQL — pas de risque de divergence entre schéma réel et registre.
- **Preuve** : `bin/migrate.php:66-68` — INSERT dans la même `beginTransaction()/commit()`.

#### Règle 3.5 : Essai à blanc (`--dry-run`) affiche le SQL sans l'exécuter
- **Énoncé** : Un appel `php bin/migrate.php --dry-run` affiche le contenu de tous les fichiers de migration à appliquer, sans exécuter aucun d'eux.
- **Limitation** : Aucune validation de syntaxe SQL n'est effectuée — une erreur SQL passe le dry-run et échoue seulement à l'application réelle.
- **Preuve** : `bin/migrate.php:41-48` — sortie anticipée via `file_get_contents()` affichage direct, `exit(0)` avant la boucle d'application.

#### Règle 3.6 : Dry-run s'exécute obligatoirement en CI avant les tests
- **Énoncé** : Chaque push sur `main`/`staging` ou PR déclenche un essai à blanc des migrations dans le workflow CI, avant les tests PHPUnit. C'est une alerte précoce sur les problèmes de migration.
- **Preuve** : `.github/workflows/ci.yml:17-18` — `composer migrate:dry` avant `composer test`.

---

### 4. Versionning et déploiement — source de vérité

**Acteur** : CI/CD, serveur web  
**Déclencheur** : Succès du workflow de déploiement  
**Résultat** : Fichier `version.json` publié et accessible

#### Règle 4.1 : La version servie ne se déduit jamais du code
- **Énoncé** : La version **vraiment** déployée est celle enregistrée dans `deployed/<env>/version.json` sur la branche `deployed`, jamais supposée depuis le code ou les constantes.
- **Corollaire** : Le champ `schemaVersion` dans le JSON est lu **en base après migration**, pas supposé depuis le nom du fichier de migration.
- **Contrat du endpoint `/version`** : Celui-ci retourne un JSON avec les champs `sha`, `ref`, `environnement`, `schemaVersion`, `deployedAt`. Si le fichier `deployed-version.json` est absent du disque, les champs `sha`, `ref`, `deployedAt` sont `null` (valeurs par défaut en PHP) ; `schemaVersion` est toujours lu en base en temps réel. **Si le fichier est présent mais contient du JSON invalide**, `json_decode` retourne `null`, et la ligne 27 de `public/index.php` (`json_encode($version + [...])`) lève une `TypeError` : « Unsupported operand type(s) for +: null and array ». Le service renvoie HTTP 500 sans contenu utile.
- **Preuve** : `public/index.php:16-19` — lecture du `deployed-version.json` côté serveur avec valeur par défaut si absent ; `.github/workflows/deploy.yml:46` — `php -r '... App\Db::schemaVersion(...)'` lu post-migration.

#### Règle 4.2 : Si un déploiement échoue (tests, migrations), la version servie reste inchangée
- **Énoncé** : Un merge qui provoque une sortie non-zéro du CI (test rouge, migration échoue, push rejeté) ne produit **aucune nouvelle version** sur la branche `deployed`.
- **Preuve** : `.github/workflows/deploy.yml` — chaque étape peut sortir avec exit 1, arrêtant le workflow avant le push sur `deployed`.

#### Règle 4.3 : Pushes simultanés sur la même branche sont mis en file
- **Énoncé** : Deux pushes rapides sur `staging` (ou `main`) produisent bien deux publications successives de `version.json`, la deuxième n'annulant pas la première.
- **Mécanisme** : `concurrency.cancel-in-progress: false` (`deploy.yml:14-15`).
- **Preuve** : `.github/workflows/deploy.yml:14-15`.

---

## Données — vue métier et technique

### Notion : Commande (orders table)

**Champs** :
- `id` (INTEGER PRIMARY KEY)
- `client` (TEXT NOT NULL)
- `montant_cents` (INTEGER NOT NULL, en centimes, devises locales)
- `devise` (TEXT NOT NULL, défaut 'XPF')
- `statut` (TEXT NOT NULL, valeurs ex: 'payee', 'annulee')

**État et cycle de vie** :
- État initial : 5 commandes fictives insérées par `001_init.sql` au démarrage (Heiata, Teiki, Manoa, Vaite, Moana).
- Pas de suppression, pas de mise à jour, pas d'insertion via l'API — ensemble immuable une fois déployé.
- **Cycle complet** : Insertion en base (migration `001_init.sql`) → persistance jusqu'à nouvel ordre → rollback possible (via sauvegarde + restauration manuelle hors API).

**Absence de contraintes métier**:
- Pas de contrainte UNIQUE sur `email` (doublons possibles).
- Pas de contrainte CHECK sur `amount` (valeurs négatives acceptées).
- Pas de clé étrangère (pas d'autres tables liées).

**Preuve** : `migrations/001_init.sql` lu intégralement.

---

## Questions ouvertes et limites

### Chaînon manquant : `deployed-version.json` sur l'hôte
- **Problème** : `deploy.yml` publie `deployed/<env>/version.json` sur la branche `deployed`, mais aucune étape du workflow ne copie ce fichier à la racine du projet servi (où `public/index.php:16-19` le lit en tant que `deployed-version.json`).
- **Impact sur `/version`** : 
  - Si `deployed-version.json` est absent du disque : `/version` retourne `{"sha": null, "ref": null, "environnement": null, "schemaVersion": <N>, "deployedAt": null}` où `<N>` est la version réelle en base. Les trois champs nuls indiquent un problème de déploiement du fichier, pas une non-déploiement du code. Le service répond HTTP 200 avec un JSON valide.
  - Si `deployed-version.json` contient du JSON invalide ou malformé : `json_decode` retourne `null`, puis `public/index.php:27` lève une `TypeError` (« Unsupported operand type(s) for +: null and array »), ce qui produit un HTTP 500 sans réponse JSON. Les consommateurs de l'API reçoivent une erreur serveur non documentée.
- **Résolution** : Hors ce dépôt — à documenter côté production (webhook, script serveur, ou mécanisme de dépôt de fichiers entre la branche `deployed` et l'hôte servi).

### Versioning de `data/app.db` et migrations futures
- **Question** : Après chaque nouvelle migration, doit-on mettre à jour et committer `data/app.db` dans le dépôt, ou appliquer les migrations à chaque déploiement contre la base « de départ » versionnée ?
- **État actuel** : Seule migration (001_init.sql), base déjà à v1. Pas de décalage visible.
- **Implication future** : À clarifier avant la 2e migration pour éviter une divergence entre la base versionnée et le schéma appliqué en production.

### Couverture de test incomplète
- **Testés** : Couche Orders (`OrdersTest.php`) — 5 tests couvrant `all()`, `find()` nominal/absent, montants entiers, version schema.
- **Non testés** : Routeur (`public/index.php`), migrateur (`bin/migrate.php`), endpoints `/health` et `/version`, codes HTTP 404 du routeur (vérifiés sur test endpoint seul, pas par la suite).

### Absence de `workflow_dispatch`
- **Observation** : Le workflow `deploy.yml` ne peut être déclenché que par un push. Il n'existe pas de bouton « re-déployer » manuel dans GitHub Actions.
- **Impact** : Impossible de re-déclencher un déploiement sans pousser un nouveau commit (option `.skip-ci` ou équivalent).

### Sécurité : Pas d'authentification ni d'autorisation
- **Endpoints publics** : `/health`, `/version`, `/orders`, `/orders/{id}` sont accessibles sans authentification.
- **Données sensibles** : Aucune (données fictives), mais à vérifier si ce dépôt évoluait vers des données réelles.

---

## Conformité et risques

### Sécurité et robustesse
Voir `SECURITY_ROBUSTNESS_AUDIT.md` — audit complet pour les risques identifiés et recommandations.

### Tests
Voir `TESTING_AUDIT.md` — zones couvertes et lacunes documentées.

### Données
Voir `DATA_MODEL_AUDIT.md` — schéma SQLite, absent de contraintes, cycle de vie `data/app.db`.

---

## Preuves citées

- `.onboarding/workflows/WORKFLOW_SERVICE.md` — API et endpoints
- `.onboarding/workflows/WORKFLOW_MIGRATION.md` — migrations, sauvegarde, transactions
- `.onboarding/workflows/WORKFLOW_DEPLOIEMENT.md` — deux canaux, publication version.json
- `.onboarding/audits/FUNCTIONAL_AUDIT.md` — endpoints, schéma JSON
- `.onboarding/audits/TESTING_AUDIT.md` — couverture de test
- `public/index.php` — routeur, endpoints
- `src/Server.php` — logique Orders
- `bin/migrate.php` — application des migrations
- `.github/workflows/ci.yml` — CI, dry-run, tests
- `.github/workflows/deploy.yml` — publication, deux canaux
- `migrations/001_init.sql` — schéma, données initiales
- `README.md` — contexte, migrations, développement
