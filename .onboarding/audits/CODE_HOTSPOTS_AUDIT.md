# Points chauds du code — Audit

> Confiance : medium — les constats sont fondés sur la lecture statique du code ; les effets runtime (comportement sur erreur, conséquences CI) sont qualifiés comme hypothèses ou scénarios conditionnels.

## Compréhension globale

Le codebase est minuscule (< 300 lignes de code source hors tests et migrations). Il n'y a pas de fichier « tentaculaire » au sens classique. Les points chauds ne sont pas des fichiers gros ou couplés, mais des fichiers courts qui concentrent plusieurs comportements fragiles ou des logiques avec des coins non évidents. Trois fichiers méritent une attention particulière de la part d'un senior : `bin/migrate.php`, `public/index.php`, et `deploy.yml`. Les fichiers `src/Db.php` et `src/Orders.php` sont lisibles et sans logique cachée dans les limites du code lu.

## Résumé exécutif

Le fichier le plus risqué est `bin/migrate.php` : il concentre la seule logique d'écriture en base du projet, avec plusieurs subtilités non immédiatement visibles (nommage trompeur de la sauvegarde, message d'erreur inexact sur rollback partiel, dry-run qui ne valide pas la syntaxe SQL, et — point le plus concret non signalé précédemment — collision silencieuse de préfixe numérique qui fait disparaître un fichier de migration sans alerte). `public/index.php` est le second point chaud : son routeur court cache deux fragilités couplées (connexion base avant routage, lecture `deployed-version.json` sans filet). `deploy.yml` présente un masquage d'erreur sur `git commit` et une race condition documentée sur `--force-with-lease`. Les fichiers de domaine (`src/Db.php`, `src/Orders.php`) sont compacts et lisibles sur les routes actuelles.

## Constats détaillés

### `bin/migrate.php` (77 lignes) — point chaud critique

**VÉRIFIÉ_CODE — Collision silencieuse de préfixes numériques.** `bin/migrate.php:24-30` construit `$aFaire` en utilisant `(int) $m[1]` comme clé entière. Si deux fichiers portent le même préfixe numérique (ex. `002_init.sql` et `002_alter.sql`), la boucle sur les fichiers triés assignera d'abord `$aFaire[2] = '002_init.sql'` puis l'écrasera avec `$aFaire[2] = '002_alter.sql'`. La première migration est perdue silencieusement, sans erreur ni avertissement. Ce défaut est distinct du seul problème d'ordre lexicographique et s'applique dès qu'un doublon de préfixe est introduit. Actuellement un seul fichier de migration — le risque se matérialisera à la première collision.

**VÉRIFIÉ_CODE — Nommage de la sauvegarde trompeur.** La ligne `bin/migrate.php:55` calcule le nom de la sauvegarde comme `app-<horodatage>-avant-v<max(array_keys($aFaire))>.db`. `max(array_keys($aFaire))` est la version **cible la plus haute à appliquer**, pas la version de départ réelle si une migration échoue à mi-parcours. Si `$aFaire` contient les versions 2 et 3 et que la migration 2 échoue, la sauvegarde s'appelle `avant-v3.db` — mais aucune migration n'a abouti. Le nom suggère qu'on revient « avant la v3 » alors qu'on n'a jamais atteint la v2.

**VÉRIFIÉ_CODE (code) — Message d'erreur trompeur sur rollback partiel.** `bin/migrate.php:73` affiche `"la base est inchangée ; sauvegarde disponible : ..."` après un rollback. Par lecture du code : chaque migration est dans sa propre transaction (`bin/migrate.php:63-68`) — un rollback ne défait que la migration courante. Si des migrations précédentes de la même exécution ont déjà été committées, le message est inexact. L'WORKFLOW_MIGRATION documente ce point, mais le message dans le code induit l'opérateur en erreur. L'effet observé (opérateur trompé) est un scénario conditionnel à plusieurs migrations dans la même exécution.

**VÉRIFIÉ_CODE — Dry-run ne valide pas la syntaxe SQL.** `bin/migrate.php:42-47` affiche le contenu des fichiers de migration via `file_get_contents` sans exécuter le SQL. Une erreur de syntaxe SQL dans un fichier de migration passerait le dry-run de CI (`.github/workflows/ci.yml:17-18`) et échouerait seulement lors de l'application réelle en déploiement. C'est un faux sentiment de sécurité si on s'appuie sur la CI pour valider les migrations.

**VÉRIFIÉ_CODE — Tri lexicographique + pas de validation de préfixe.** `bin/migrate.php:20-21` trie les fichiers par `sort()` (ordre lexicographique des chemins). Avec des noms bien formés (`001_`, `002_`, `003_`), cela équivaut à un ordre numérique. Avec `10_` ou `002b_`, l'ordre diverge silencieusement. Aucune validation de la convention de nommage. Actuellement un seul fichier (`001_init.sql`) — le risque ne se matérialisera qu'à l'ajout d'une deuxième migration.

**VÉRIFIÉ_CODE — Aucun délai de rétention ou nettoyage des sauvegardes.** Les sauvegardes horodatées s'accumulent dans `data/backups/` (gitignoré). Le script ne supprime jamais les anciennes sauvegardes. Sur un hôte persistant avec des migrations fréquentes, `data/backups/` grossirait indéfiniment — conditionnel à l'existence d'un tel hôte, non vérifiable dans ce dépôt.

### `public/index.php` (47 lignes) — point chaud de robustesse

**VÉRIFIÉ_CODE — Connexion base avant routage : voir ARCHITECTURE_AUDIT.** `public/index.php:11` — point détaillé dans l'audit Architecture. Répété ici car c'est le premier point chaud que lirait un senior cherchant à debugger un `/health` qui ne répond pas.

**VÉRIFIÉ_CODE (chemin) + HYPOTHÈSE (effet) — Lecture `deployed-version.json` sans filet.** `public/index.php:16-19` + `public/index.php:27` — deux étapes couplées sans protection : si le fichier existe et contient un JSON invalide, `json_decode` peut retourner `null`, et l'opérateur `+` sur `null` lève une `TypeError` en PHP 8. Le chemin de code est vérifiable statiquement. La TypeError elle-même est une hypothèse conditionnelle. Détaillé dans SECURITY_ROBUSTNESS_AUDIT.

**VÉRIFIÉ_CODE — `Content-Type: application/json` posé avant tout traitement.** `public/index.php:8` pose l'en-tête pour toute réponse. Bonne pratique de cohérence, mais si une exception échappe avant `echo`, le corps ne sera pas du JSON — l'en-tête mentira.

### `deploy.yml` (69 lignes) — point chaud CI/CD

**VÉRIFIÉ_CODE (masquage d'erreur) + HYPOTHÈSE (scénario de push défaillant).** `deploy.yml:68` exécute `git commit -m "…" || echo "rien à publier"`. En cas de `git commit` qui retourne un code d'erreur non-1 (ex. verrou Git, index corrompu, problème de permission), la branche `||` masque l'erreur et le workflow continue vers le `git push`. Hypothèse conditionnelle : si ce scénario se produit, le push pousserait un état non mis à jour, laissant la branche `deployed` à jour en apparence mais le commit absent. L'intention est de gérer le cas « rien à committer » (exit 1 normal) — la substitution `||` est trop large pour cela.

**VÉRIFIÉ_CODE — Race condition sur `--force-with-lease` documentée.** `deploy.yml:69` utilise `git push origin deployed --force-with-lease`. Entre le `git fetch origin deployed` (étape 7 du workflow, `deploy.yml:49`) et ce push, un push concurrent d'un autre runner sur la même branche `deployed` ferait échouer le `--force-with-lease`. Ce cas est documenté dans WORKFLOW_DEPLOIEMENT (Risques). En pratique, les deux environnements ont des groupes de concurrence distincts (`group: deploiement-${{ github.ref }}`), limitant mais non éliminant le risque.

**VÉRIFIÉ_CODE — `schemaVersion` lue via one-liner PHP.** `deploy.yml:46` exécute `php -r 'require "vendor/autoload.php"; echo App\Db::schemaVersion(App\Db::connect());'`. Un échec de cette commande (ex. autoloader absent, `data/app.db` inaccessible) bloque la génération du JSON sans message clair dans les logs. Le one-liner bénéficie du `set -euo pipefail` global du step (`deploy.yml:44`).

### Fichiers lisibles sur le code lu

**VÉRIFIÉ_CODE — `src/Db.php` (34 lignes) : compact et lisible.** Classe `final`, méthodes statiques au rôle unique (chemin, connexion, version), comportements lisibles. Pas de logique cachée dans les lignes lues. Maintenir cette compacité si le service évolue.

**VÉRIFIÉ_CODE — `src/Orders.php` (30 lignes) : compact et lisible.** Injection de PDO par constructeur, deux requêtes préparées, une requête sans paramètre. `count()` correctement typé. Aucune couche d'abstraction superflue.

**VÉRIFIÉ_CODE — `migrations/001_init.sql` : clair.** Commentaire de contexte, structure lisible, types cohérents. La table `schema_migrations` en premier garantit que le registre existe avant les données.

## Forces

- `src/Db.php` et `src/Orders.php` : une responsabilité par fichier, aucune logique cachée dans le code lu, accès direct depuis les tests.
- `bin/migrate.php` explicite ses garanties (sauvegarde obligatoire, transactions) dans les commentaires de tête.
- `deploy.yml` documente ses choix (`cancel-in-progress: false`, `--force-with-lease`) dans des commentaires ciblés.
- Pas de dépendance croisée entre les domaines : `Orders` ne connaît pas `Db`, ne connaît pas le routeur.

## Dettes techniques

- **`bin/migrate.php:24-30` (collision silencieuse de préfixes)** : deux fichiers de migration avec le même numéro entier font que le second écrase silencieusement le premier dans `$aFaire` — défaut concret dans le code actuel.
- **`bin/migrate.php:55` (nommage sauvegarde)** : nom encode la version cible, pas la version de départ réelle — trompeur en cas d'échec à mi-parcours.
- **`bin/migrate.php:73` (message d'erreur)** : « la base est inchangée » est inexact si des migrations précédentes ont été committées dans la même exécution.
- **`bin/migrate.php:42-47` (dry-run)** : ne valide pas la syntaxe SQL — faux sentiment de sécurité en CI.
- **`deploy.yml:68` (`|| echo`)** : masquage trop large des erreurs `git commit`.
- **`public/index.php:16-19,27`** : chemin de code menant à TypeError si `deployed-version.json` contient un JSON invalide.

## Zones critiques

- **`bin/migrate.php`** (entier, 77 lignes) : seule logique d'écriture en base, plusieurs subtilités non immédiatement visibles, dont la collision silencieuse de préfixe — critique en cas d'incident de migration.
- **`public/index.php:11,16-27`** : deux fragilités couplées dans 20 lignes — connexion avant routage et lecture JSON sans filet.
- **`deploy.yml:68`** : masquage d'erreurs CI qui pourrait passer inaperçu lors d'un déploiement raté.

## Risques

- **Collision de préfixes numériques** : deux fichiers `002_*.sql` font que le premier est silencieusement ignoré — migration perdue sans erreur ni avertissement. (`bin/migrate.php:24-30`)
- **Migration partielle + message d'erreur trompeur** : un opérateur qui lit « la base est inchangée » après un rollback partiel peut ne pas réaliser que des migrations antérieures de la même exécution ont été committées. (`bin/migrate.php:73`)
- **Sauvegarde mal nommée** : en cas de restauration d'urgence après un échec à mi-parcours, le nom `avant-v3.db` peut induire l'opérateur en erreur sur l'état réel de la sauvegarde. (`bin/migrate.php:55`)
- **Push silencieux d'un état non mis à jour (scénario conditionnel)** : si `git commit` échoue pour une raison autre qu'« rien à committer », le `|| echo` masque l'erreur et le push continue — hypothèse : la branche `deployed` serait à jour mais le commit absent. (`deploy.yml:68`)
- **Fausse validation CI** : une migration SQL syntaxiquement invalide passe le dry-run de CI et échoue seulement en déploiement. (`bin/migrate.php:42-47`, `.github/workflows/ci.yml:17-18`)

## Recommandations priorisées

1. **Détecter et refuser les doublons de préfixe numérique dans `bin/migrate.php`** — après la boucle, vérifier que `count($aFaire)` égale le nombre de fichiers dont le numéro est supérieur à `$courante` ; sinon sortir en erreur avec la liste des doublons — `bin/migrate.php:24-30`.
2. **Corriger le message d'erreur dans `bin/migrate.php:73`** — distinguer « la migration courante est rollbackée » de « la base est inchangée » — `bin/migrate.php`.
3. **Protéger la lecture `deployed-version.json` dans `public/index.php:17-19`** — valider le retour de `json_decode` avant usage — `public/index.php`.
4. **Ajouter une validation de syntaxe SQL en dry-run** — exécuter les migrations dans une transaction annulée (`BEGIN; <SQL>; ROLLBACK;`) pour détecter les erreurs de syntaxe — `bin/migrate.php`.
5. **Resserrer le `|| echo` dans `deploy.yml:68`** — tester explicitement le code de sortie 1 de `git commit` (rien à committer) et propager les autres erreurs — `deploy.yml`.

## Questions ouvertes

- Le dry-run est-il supposé valider la syntaxe SQL ou seulement afficher ce qui serait appliqué ? La distinction conditionne si une validation via transaction annulée est hors périmètre.
- La race condition `--force-with-lease` sur `deployed` s'est-elle déjà manifestée ? (Deux environnements déclenchés en parallèle sur des branches différentes.)
