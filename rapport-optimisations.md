# Rapport d'optimisations — Commande `send:emails`

**Date** : 2026-03-16
**Contexte** : Suite au crash production, ce rapport détaille les optimisations proposées avec une stratégie de déploiement en 3 phases.

---

## Vue d'ensemble

L'algorithme actuel souffre de 3 catégories de problèmes :

| Catégorie | Problèmes | Impact |
|-----------|-----------|--------|
| **Requêtes SQL** | N+1 queries dans une triple boucle imbriquée | ~3M requêtes/exécution |
| **Mémoire** | Chargement total des contacts sans pagination | ~1-2 GB RAM |
| **Fiabilité** | Lock DB sans mécanisme de récupération | Blocage permanent au crash |

### Projection de la dégradation

```
Requêtes SQL par jour (estimation) :

Jour 1   : ████░░░░░░░░░░░░░░░░  500K
Jour 30  : ████████░░░░░░░░░░░░  1.5M
Jour 90  : ████████████░░░░░░░░  3M    ← crash probable
Jour 180 : ████████████████████  6M+   ← inexécutable
```

La croissance est **linéaire** par rapport au nombre de contacts et **quadratique** par rapport au nombre de steps passés (chaque step passé est retraité chaque jour pour chaque contact).

---

## Stratégie de déploiement

### Phase P0 — Hotfix (aujourd'hui, < 30 min, sans migration)

**Objectif** : Rétablir le service et empêcher un nouveau crash.

| # | Action | Fichier | Impact | Effort |
|---|--------|---------|--------|--------|
| 1 | Débloquer le lock en BDD | Requête SQL directe | Rétablir le service | 1 min |
| 2 | Try/catch avec `finally` pour le lock | `SendEmails.php` | Plus de blocage permanent | 5 min |
| 3 | Chunking des contacts par lots de 500 | `SendEmails.php` | -80% RAM | 15 min |
| 4 | Pré-chargement batch des données dans chaque chunk | `SendEmails.php` | -95% requêtes SQL | 15 min |

**Résultat attendu** : ~3M requêtes → ~10K requêtes, ~1.5 GB RAM → ~150 MB RAM.

Le fichier correctif est fourni dans `correctif-P0-SendEmails.php`.

### Phase P1 — Cette semaine (avec migrations)

**Objectif** : Optimiser la couche SQL pour supporter la croissance future.

| # | Action | Fichier | Impact | Effort |
|---|--------|---------|--------|--------|
| 5 | Index composite sur `queue_history_emails` | Migration Laravel | -50% temps SQL sur QueueHistory | 10 min |
| 6 | Index composite sur `analytics_events` | Migration Laravel | -40% temps SQL sur AnalyticsEvent | 10 min |
| 7 | Index sur `sent_emails` | Migration Laravel | -30% temps SQL sur SentEmail | 10 min |
| 8 | Éliminer `getPreviousCapsule()` (déjà en mémoire) | `SendEmails.php` | Supprimer requêtes inutiles | 10 min |
| 9 | Forcer un `LIMIT` sur `getLastEmailsSent()` | `SendEmails.php` | Réduire RAM sur gros comptes | 5 min |

**Résultat attendu** : ~10K requêtes → ~5K requêtes, temps de chaque requête divisé par 2-5x grâce aux index.

Le fichier de migration est fourni dans `correctif-P1-migration.php`.

### Phase P2 — Sprint suivant (refactoring structurel)

**Objectif** : Rendre l'architecture scalable à long terme.

| # | Action | Impact | Effort | Risque |
|---|--------|--------|--------|--------|
| 10 | Remplacer le lock DB par `withoutOverlapping()` | Lock fiable avec expiration auto | 1h | Faible |
| 11 | Dispatcher un Job par training | Parallélisation, isolation des erreurs | 4h | Moyen |
| 12 | Table `contact_next_step` pré-calculée | -90% itérations (ne traiter que les éligibles) | 1-2j | Moyen |
| 13 | Architecture event-driven | Éliminer le CRON, envoi en temps réel | 3-5j | Élevé |

Le fichier d'architecture est fourni dans `correctif-P2-architecture.php`.

---

## Comparaison avant/après

| Métrique | Actuel | Après P0 | Après P0+P1 | Après P0+P1+P2 |
|----------|--------|----------|-------------|-----------------|
| Requêtes SQL | ~3 000 000 | ~10 000 | ~5 000 | ~500 |
| RAM peak | ~1-2 GB | ~150 MB | ~100 MB | ~50 MB |
| Temps d'exécution | 300s+ (crash) | ~30s | ~15s | ~5s |
| Lock fiable | Non | Oui (finally) | Oui (finally) | Oui (withoutOverlapping) |
| Isolation erreurs | Non | Non | Non | Oui (Job par training) |

---

## Risques et points d'attention

### Risque 1 : Régression fonctionnelle
Les règles métier (AUTO_UNSUBSCRIBE, NO_SPAM, IF_CLICKED) sont complexes et imbriquées. Chaque optimisation doit préserver exactement le même comportement. **Mitigation** : tester sur staging avec un diff des `queue_history_emails` avant/après.

### Risque 2 : Index sur tables volumineuses
Ajouter des index sur `analytics_events` et `sent_emails` (potentiellement millions de rows) peut prendre du temps et locker la table. **Mitigation** : exécuter les migrations en heures creuses, utiliser `ALTER TABLE ... ALGORITHM=INPLACE` si MySQL 5.7+.

### Risque 3 : Le chunking change l'ordre de traitement
Avec `chunk()`, les contacts sont traités par lots au lieu de tous en même temps. L'ordre peut légèrement changer. **Mitigation** : le code actuel ne dépend pas de l'ordre entre contacts, donc risque nul.

---

## Recommandation

Déployer **P0 immédiatement** (fichier `correctif-P0-SendEmails.php`), puis planifier P1 pour cette semaine et P2 pour le sprint suivant. Le P0 seul suffit à résoudre le crash et réduire le temps d'exécution de 300s+ à ~30s.
