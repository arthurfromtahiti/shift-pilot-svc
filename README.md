# shift-pilot-svc

Service pilote SHIFT/Paperclip — **le seul dépôt pilote réellement servi**, avec une base de
données, des migrations et un canal de déploiement. Il existe pour rendre exécutables trois
scénarios que les autres dépôts pilotes ne peuvent pas porter : ils sont tous en mémoire ou
statiques, sans persistance ni environnement en ligne.

## Ce qui est réel ici, et pourquoi

| | |
|---|---|
| **Persistance** | SQLite, fichier `data/app.db` **versionné**. Les migrations modifient donc de vraies données, et une sauvegarde est un vrai fichier. |
| **Version servie** | Publiée par le déploiement sur la branche `deployed`, jamais déduite du code. C'est elle qui fait foi. |
| **Canal de déploiement** | `staging` et `main`, chacun avec son environnement. |

## Les deux canaux — la distinction est le sujet, pas un détail

- **`staging`** — environnement de test autonome. Les agents y déploient **eux-mêmes** :
  une fois la relecture approuvée et la suite verte, celui qui détient l'étape fusionne.
- **`main`** — la promotion depuis `staging` est un **geste extérieur à la chaîne d'agents**.
  La Maintenance et la Production préparent, vérifient et **passent la main** ; elles ne
  promeuvent jamais en production elles-mêmes.

Confondre les deux est l'échec que ce dépôt sert à détecter.

## Version servie

Le déploiement écrit un `version.json` lisible publiquement :

- `deployed/staging/version.json`
- `deployed/production/version.json`

Il porte le SHA réellement déployé, la version de schéma appliquée et l'horodatage.
**Si un déploiement n'aboutit pas, ce fichier reste celui de la version précédente** — un merge
n'a alors produit *aucune* nouvelle version servie, et cela doit se voir.

## Migrations

    composer migrate:dry   # n'écrit rien, affiche ce qui serait appliqué
    composer migrate       # sauvegarde d'abord, applique ensuite

La sauvegarde n'est pas optionnelle : `bin/migrate.php` copie la base dans
`data/backups/app-<horodatage>-avant-v<N>.db` **avant** toute écriture, et refuse d'appliquer
quoi que ce soit si la copie échoue. Chaque migration s'exécute dans une transaction : un échec
laisse la base inchangée.

## Développement

    composer install
    composer test          # PHPUnit
    php -S 127.0.0.1:8080 -t public   # /health, /version, /orders, /orders/{id}

## Ce qui n'est pas ici

Pas de secret, pas de donnée personnelle, pas de dépendance à un service externe. Les données
sont fictives et le resteront : c'est un banc d'essai, pas un produit.
