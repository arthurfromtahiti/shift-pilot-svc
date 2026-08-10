# Index de documentation — shift-pilot-svc

Bienvenue. Ce document vous guide vers les ressources pour comprendre, développer, tester et déployer shift-pilot-svc.

---

## 🚀 Premiers pas

### Je découvre shift-pilot-svc pour la première fois

**Lisez dans cet ordre** :

1. **[README.md](README.md)** (5 min)
   - Ce qu'est le projet, pourquoi il existe (banc d'essai opérationnel)
   - Les deux canaux (`staging`/`main`) et ce qui les distingue
   - Architecture minimale : SQL + PHP + migrations + déploiement

2. **[PROJECT_CONTEXT.md](PROJECT_CONTEXT.md)** (10 min)
   - Contexte complet : domaines clés, points d'attention
   - Hypothèses validées par audit, zones non observées
   - Checklist de non-régression avant toute modification

3. **[CDC_FONCTIONNEL.md](CDC_FONCTIONNEL.md)** (15 min)
   - Contrats des 4 endpoints HTTP avec exemples
   - Modèle de données métier : la table `orders` et ses règles
   - Garanties de service et points de vigilance futurs

---

## 👨‍💻 Je dois modifier le code

### Je dois comprendre la structure du code
→ **[CARTOGRAPHIE_CODE.md](CARTOGRAPHIE_CODE.md)** (20 min)
- Où trouver chaque fonctionnalité : fichier par fichier
- Hotspots critiques identifiés dans le code
- Chemins de référence rapide (endpoint → code → base)

### Je dois modifier un endpoint ou une classe
→ **[ARCHITECTURE.md](ARCHITECTURE.md)** (20 min)
- Analyse détaillée de chaque domaine (5 domaines)
- Points d'extension et zones critiques
- Dépendances entre les classes
- Recommandations de sécurité

---

## 🔄 Je dois ajouter une migration

### Je dois créer et appliquer une migration
→ **[GUIDE_MIGRATIONS.md](GUIDE_MIGRATIONS.md)** (15 min)
- Convention de nommage (`NNN_*.sql`)
- Procédure obligatoire : essai à blanc, tests, sauvegarde
- Restauration depuis une sauvegarde en cas d'erreur
- **Points critiques** : tri lexicographique, doublons, transactions

---

## 📦 Je dois déployer / publier une version

### Je dois comprendre le pipeline CI/CD
→ **[GUIDE_DEPLOIEMENT.md](GUIDE_DEPLOIEMENT.md)** (15 min)
- Pipeline automatisé (`deploy.yml`) : tests → migrations → publication
- Distinction staging/production : deux canaux, deux `version.json`
- Comment la version servie est observée via l'API
- Dépannage en cas d'échec de déploiement

### Je dois tester avant de déployer
→ **[CAHIER_RECETTE.md](CAHIER_RECETTE.md)** (45 min, parcours complet)
- 5 parcours de test manuels et end-to-end
- Vérifications pour chaque endpoint
- Test du cycle complet migrations → déploiement → observation
- Robustesse : erreurs et cas limites

---

## ❓ Points d'attention et questions ouvertes

### Il y a des zones d'incertitude ou des décisions à prendre
→ **[QUESTIONS_OUVERTES.md](QUESTIONS_OUVERTES.md)** (10 min)
- Synthèse de tous les points signalés par les audits
- Priorisés par impact (critique → trivial)
- Notamment : déploiement de `deployed-version.json`, `/health` sous panne SQLite

### Il y a des défauts bloquants identifiés
→ Vérifiez **[PLAN_DE_RECETTE.md](PLAN_DE_RECETTE.md)** et **[CHECKLIST_CONTRIBUTION.md](CHECKLIST_CONTRIBUTION.md)**
- Test en local avant chaque PR
- Checklist à respecter avant le merge

---

## 📋 Pour les auditeurs / relecteurs

### Je dois comprendre la conception complète
**Parcours audits complets** :

1. **[PROJECT_CONTEXT.md](PROJECT_CONTEXT.md)** — Résumé exécutif (10 min)
2. **[CDC_FONCTIONNEL.md](CDC_FONCTIONNEL.md)** — Fonctionalités détaillées (15 min)
3. **[CARTOGRAPHIE_CODE.md](CARTOGRAPHIE_CODE.md)** — Structure technique (20 min)
4. **`.onboarding/audits/`** — Analyses sourcées complètes (30 min)
   - `FUNCTIONAL_AUDIT.md` — fonctionnalité
   - `ARCHITECTURE_AUDIT.md` — architecture et couplages
   - `DATA_MODEL_AUDIT.md` — schéma et cycle de vie des données
   - `CODE_HOTSPOTS_AUDIT.md` — zones critiques du code
   - `SECURITY_ROBUSTNESS_AUDIT.md` — sécurité et robustesse
   - `TESTING_AUDIT.md` — couverture et zones non testées
   - `ARCHITECTURE_AUDIT.md` — cohérence, dépendances, risques
   - `DATA_MODEL_AUDIT.md` — schéma, cycle de vie de la base
   - `FUNCTIONAL_AUDIT.md` — endpoints, comportements HTTP
   - `CODE_HOTSPOTS_AUDIT.md` — points sensibles du code
   - `SECURITY_ROBUSTNESS_AUDIT.md` — surface d'attaque, sécurité
   - `TESTING_AUDIT.md` — couverture de tests, zones à couvrir

### Je dois valider une migration
→ **[GUIDE_MIGRATIONS.md](GUIDE_MIGRATIONS.md)** + code de `bin/migrate.php`
- Assurer que le nommage est correct (`NNN_*.sql`)
- Vérifier que la sauvegarde est bien exécutée
- Confirmer que la migration s'applique correctement en essai à blanc

### Je dois valider un déploiement
→ **[GUIDE_DEPLOIEMENT.md](GUIDE_DEPLOIEMENT.md)** + `.github/workflows/deploy.yml`
- Vérifier que les deux canaux ne s'interfèrent jamais
- Confirmer que les tests tournent avant les migrations
- S'assurer que la branche `deployed` reçoit bien la version

---

## Pour les opérationnels/oncall

### Statut général du service
→ **[README.md](README.md)** — section « Ce qui est réel ici »
- Quatre endpoints : `/health`, `/version`, `/orders`, `/orders/{id}`
- Deux environnements : `deployed/staging/version.json` et `deployed/production/version.json`

### Je dois vérifier que tout fonctionne
```bash
# En développement local
composer test          # Tests unitaires
composer migrate:dry   # Essai à blanc des migrations

# En production (via API)
curl http://localhost:8080/health       # Santé du service
curl http://localhost:8080/version      # Version déployée
curl http://localhost:8080/orders       # Liste des commandes
```

### Le pipeline échoue
→ **[GUIDE_DEPLOIEMENT.md](GUIDE_DEPLOIEMENT.md)** — section « Dépannage »
- Tests rouges ? → voir `.github/workflows/ci.yml`
- Migration échouée ? → voir `bin/migrate.php`, `GUIDE_MIGRATIONS.md`
- Version inchangée ? → voir la branche `deployed`

### Restauration d'urgence
→ **[GUIDE_MIGRATIONS.md](GUIDE_MIGRATIONS.md)** — section « Restauration manuelle »
```bash
ls -la data/backups/
cp data/backups/app-YYYYMMDD-HHmmss-avant-v<N>.db data/app.db
```

---

## Pour les questions stratégiques

### Le projet peut-il croître ?
→ **[QUESTIONS_OUVERTES.md](QUESTIONS_OUVERTES.md)**
- Lister les blockers critiques avant l'extension
- Résoudre la question du cycle de vie de `data/app.db`
- Clarifier le mécanisme de `deployed-version.json`

### Y a-t-il des dettes techniques ?
→ **[ARCHITECTURE.md](ARCHITECTURE.md)** — section « Zones critiques »
Et **[QUESTIONS_OUVERTES.md](QUESTIONS_OUVERTES.md)** — section « Par priorité »

---

## Structure de la documentation

```
shift-pilot-svc/
├── README.md                    ← Vue d'ensemble générale
├── ARCHITECTURE.md              ← Structure et hotspots
├── GUIDE_MIGRATIONS.md          ← Procédure complète pour les migrations
├── GUIDE_DEPLOIEMENT.md         ← Pipeline et canaux
├── QUESTIONS_OUVERTES.md        ← Blockers et recommandations
├── INDEX_DOCUMENTATION.md       ← Ce fichier
│
├── .onboarding/
│   ├── audits/                  ← Analyses détaillées sourcées
│   │   ├── ARCHITECTURE_AUDIT.md
│   │   ├── DATA_MODEL_AUDIT.md
│   │   ├── FUNCTIONAL_AUDIT.md
│   │   ├── CODE_HOTSPOTS_AUDIT.md
│   │   ├── SECURITY_ROBUSTNESS_AUDIT.md
│   │   └── TESTING_AUDIT.md
│   ├── workflows/               ← Modèles de flux (source de vérité technique)
│   │   ├── WORKFLOW_SERVICE.md
│   │   ├── WORKFLOW_MIGRATION.md
│   │   └── WORKFLOW_DEPLOIEMENT.md
│   ├── domaines/
│   │   └── CARTE_DES_DOMAINES.md
│   └── relectures/              ← Validations des audits
│
├── bin/
│   └── migrate.php              ← Migrateur SQL (77 lignes)
├── src/
│   ├── Db.php                   ← Couche SQLite (34 lignes)
│   └── Orders.php               ← Domaine métier (30 lignes)
├── public/
│   └── index.php                ← Routeur HTTP (47 lignes)
├── migrations/
│   └── 001_init.sql             ← Schéma initial
├── tests/
│   └── OrdersTest.php           ← PHPUnit
└── data/
    ├── app.db                   ← Base SQLite versionnée
    └── backups/                 ← Sauvegardes horodatées (.gitignore)
```

---

## FAQ rapide

**Q: Par où je commence si je suis nouveau ?**
A: Lisez [README.md](README.md) (5 min), puis [ARCHITECTURE.md](ARCHITECTURE.md) (10 min).
Si vous devez modifier le code, allez à la section correspondante ci-dessus.

**Q: Comment je sais si je vais casser quelque chose ?**
A: Consultez [ARCHITECTURE.md](ARCHITECTURE.md) — « Recette de sécurité ». Elle énumère les
points à ne pas casser.

**Q: Le projet est bloqué avant d'aller plus loin ?**
A: Consultez [QUESTIONS_OUVERTES.md](QUESTIONS_OUVERTES.md) et filtrez par priorité.

**Q: Où sont les analyses détaillées ?**
A: Dans `.onboarding/audits/` — chaque audit est complet et sourcé par le code.

**Q: Je dois faire passer la migration X en production — qu'est-ce que je fais ?**
A: Lisez [GUIDE_MIGRATIONS.md](GUIDE_MIGRATIONS.md) puis [GUIDE_DEPLOIEMENT.md](GUIDE_DEPLOIEMENT.md).

**Q: Il y a un problème en production — où je regarde ?**
A: [GUIDE_DEPLOIEMENT.md](GUIDE_DEPLOIEMENT.md) — section « Dépannage ».

---

## Contacts directs vers les sources

- **Code source (hotspots)** → [ARCHITECTURE.md](ARCHITECTURE.md) — « Zones critiques »
- **Comportement HTTP (endpoints)** → `.onboarding/audits/FUNCTIONAL_AUDIT.md`
- **Schéma de données** → `.onboarding/audits/DATA_MODEL_AUDIT.md`
- **Pipeline CI/CD** → `.onboarding/workflows/WORKFLOW_DEPLOIEMENT.md`
- **Migrations** → `.onboarding/workflows/WORKFLOW_MIGRATION.md`
- **Sécurité/robustesse** → `.onboarding/audits/SECURITY_ROBUSTNESS_AUDIT.md`

---

## Historique de la documentation

| Date | Version | Auteur | Sujet |
|------|---------|--------|-------|
| 2026-08-08 | 1.0 | Rédacteur (Agent) | Documentation initiale — README, guides, architecture |

Dernière mise à jour : 2026-08-08
