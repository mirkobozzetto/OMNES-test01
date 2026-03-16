# Défi 2 — Analyse de performance d'un algorithme en production

## Contexte

Un algorithme Laravel (`send:emails`) tourne quotidiennement en CRON pour envoyer des emails de formation aux contacts. Il a été constaté que :

- La commande ralentissait progressivement chaque jour
- Ce matin, elle a **crashé la production**
- Le service d'envoi d'emails est actuellement **bloqué**

L'objectif est de diagnostiquer les causes de la dégradation, comprendre pourquoi le crash s'est produit, et proposer des correctifs priorisés.

## Structure du dossier

```
test_code2/
├── README.md                          ← Ce fichier
├── original-SendEmails.php            ← Code source original (le code à analyser)
├── prompt-analyse.md                  ← Prompt d'analyse utilisé (meta-prompt-creator)
├── rapport-analyse-sendemails.md      ← Rapport d'analyse détaillé (diagnostic complet)
├── rapport-optimisations.md           ← Stratégie d'optimisation P0/P1/P2
├── correctif-P0-SendEmails.php        ← Hotfix prêt à déployer (sans migration)
├── correctif-P1-migration.php         ← Migration Laravel pour les index
├── correctif-P2-architecture.php      ← Drafts architecturaux (long terme)
└── checklist-deploiement.md           ← Checklist pas-à-pas pour le déploiement
```

## Résumé du diagnostic

### Problèmes identifiés : 8

| # | Problème | Impact |
|---|----------|--------|
| 1 | N+1 Query sur `QueueHistoryEmail` (1 SELECT COUNT par contact x step) | ~1M requêtes |
| 2 | N+1 Query sur `AnalyticsEvent` via `ifEmailOpened()` | ~500K requêtes |
| 3 | N+1 Query sur `SentEmail` via `getLastEmailsSent()` (sans LIMIT) | ~500K requêtes + RAM |
| 4 | N+1 Query sur `QueueHistoryEmail` via `getLastEmailSentToday()` | ~300K requêtes |
| 5 | N+1 Query sur `Step` via `getPreviousCapsule()` (données déjà en mémoire) | Inutile |
| 6 | Chargement total de TOUS les contacts en mémoire (pas de chunking) | OOM |
| 7 | `$contact->sent_emails` sans eager loading explicite | N+1 caché |
| 8 | Lock DB fragile (pas de `finally`, blocage permanent au crash) | Service bloqué |

### Complexité totale

```
Requêtes SQL ≈ T × C × S × 6 ≈ 3 000 000 (avec T=10, C=5000, S=20)
RAM ≈ T × C × sizeof(Contact + SentEmails) ≈ 1-2 GB
```

### Cause du crash

**Memory exhaustion (OOM)** combinée à un timeout. Le lock DB est resté verrouillé, bloquant toute exécution ultérieure.

## Plan d'optimisation

| Phase | Quand | Impact | Migrations |
|-------|-------|--------|------------|
| **P0** | Aujourd'hui (< 30 min) | 3M → 10K requêtes, 1.5 GB → 150 MB RAM | Non |
| **P1** | Cette semaine | 10K → 5K requêtes, temps SQL divisé par 3 | Oui (5 index) |
| **P2** | Sprint suivant | Architecture scalable (Jobs, events) | Oui |

## Comparaison avant/après

| Métrique | Avant | Après P0 | Après P0+P1 |
|----------|-------|----------|-------------|
| Requêtes SQL | ~3 000 000 | ~10 000 | ~5 000 |
| RAM peak | ~1-2 GB | ~150 MB | ~100 MB |
| Temps d'exécution | 300s+ (crash) | ~30s | ~15s |
| Lock fiable | Non | Oui | Oui |

## Comment utiliser les correctifs

### Étape 1 : Débloquer la production (immédiat)

```sql
UPDATE settings SET value = '1' WHERE key = 'CAN_RUN_SEND_EMAILS_COMMAND';
```

### Étape 2 : Déployer le hotfix P0

Remplacer `app/Console/Commands/SendEmails.php` par le contenu de `correctif-P0-SendEmails.php`.

### Étape 3 : Déployer les index P1

Copier `correctif-P1-migration.php` dans `database/migrations/` avec le bon timestamp, puis :

```bash
php artisan migrate
```

### Étape 4 : Valider

Suivre la `checklist-deploiement.md`.

## Méthodologie

1. **Prompt engineering** (`/meta-prompt-creator`) : création d'un prompt structuré pour guider l'analyse
2. **Analyse APEX** (`/workflow-apex`) : diagnostic systématique, plan d'optimisation priorisé, drafts de correctifs
3. **Livrables** : rapport d'analyse, rapport d'optimisations, 3 fichiers de correctifs, checklist de déploiement
