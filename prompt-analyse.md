# Prompt utilisé pour l'analyse

Ce prompt a été conçu avec la skill `/meta-prompt-creator` pour guider un LLM dans l'analyse de performance de l'algorithme `SendEmails`.

---

```xml
<context>
Tu es un expert en performance backend PHP/Laravel spécialisé dans le diagnostic d'incidents de production.

L'algorithme ci-dessous est une commande Laravel Artisan (`send:emails`) qui tourne quotidiennement en CRON.
Contexte de l'incident :
- La commande ralentit progressivement chaque jour depuis plusieurs semaines
- Ce matin, elle a crashé la production (timeout / out of memory probable)
- L'application utilise Laravel avec Eloquent ORM, une base MySQL/PostgreSQL, et un système de queue pour l'envoi d'emails
- Les données grossissent quotidiennement : nouveaux contacts, nouveaux emails envoyés, nouveaux événements analytics
</context>

<algorithm>
{{COLLER LE CODE PHP ICI}}
</algorithm>

<task>
Réalise une analyse complète de performance de cet algorithme en 3 phases :

**Phase 1 — Diagnostic : Pourquoi ça ralentit chaque jour ?**
Identifie chaque problème de performance. Pour chacun :
- Nomme le problème (ex: "N+1 Query sur AnalyticsEvent")
- Cite les lignes exactes du code concernées
- Explique pourquoi ça empire avec le temps (croissance des données)
- Estime la complexité algorithmique (Big-O) en fonction du nombre de trainings (T), contacts (C), steps (S), et emails envoyés (E)

**Phase 2 — Pourquoi ça a crashé ce matin ?**
Identifie le scénario de crash le plus probable :
- Memory exhaustion ? Timeout ? Lock infini ? Autre ?
- Quel volume de données a pu déclencher le crash ?
- Y a-t-il un point de non-retour dans la croissance des données ?

**Phase 3 — Plan d'optimisation priorisé**
Propose des optimisations classées par impact et urgence :

| Priorité | Optimisation | Impact estimé | Effort | Risque |
|----------|-------------|---------------|--------|--------|
| P0 (hotfix) | ... | ... | ... | ... |
| P1 (cette semaine) | ... | ... | ... | ... |
| P2 (sprint suivant) | ... | ... | ... | ... |

Pour chaque optimisation P0 et P1, fournis un snippet de code PHP/Laravel corrigé prêt à être implémenté.
</task>

<constraints>
- Analyse UNIQUEMENT le code fourni, ne suppose pas l'existence de features non visibles
- Les snippets de code doivent être compatibles Laravel 8+ et Eloquent
- Ne propose PAS de changement d'architecture majeur en P0 (pas de refonte complète, pas de migration vers un autre système)
- Les hotfix P0 doivent être déployables en moins de 30 minutes sans migration de BDD
- Chaque optimisation doit préserver le comportement fonctionnel existant (mêmes emails envoyés, mêmes règles appliquées)
</constraints>

<output_format>
Structure ta réponse exactement ainsi :

## 1. Diagnostic — Problèmes identifiés

### Problème 1 : [Nom]
- **Lignes** : L.XX-YY
- **Description** : ...
- **Complexité** : O(...)
- **Dégradation** : pourquoi ça empire chaque jour

(Répéter pour chaque problème)

### Résumé de la complexité totale
- Nombre de requêtes SQL estimé : formule
- Mémoire consommée : formule

## 2. Analyse du crash

- **Cause probable** : ...
- **Scénario de déclenchement** : ...
- **Seuil critique estimé** : ...

## 3. Plan d'optimisation

### P0 — Hotfix immédiat (déployer aujourd'hui)
[Table + snippets de code]

### P1 — Corrections cette semaine
[Table + snippets de code]

### P2 — Améliorations structurelles
[Table + description]

## 4. Checklist de validation post-fix
- [ ] Point 1
- [ ] Point 2
- ...
</output_format>

<success_criteria>
L'analyse est réussie si :
- Tous les N+1 queries sont identifiés avec les lignes exactes
- La complexité Big-O est calculée et expliquée
- Le scénario de crash est plausible et argumenté
- Les hotfix P0 sont des changements minimaux, sans migration, déployables immédiatement
- Les snippets de code sont fonctionnels et préservent le comportement existant
- Le plan couvre à la fois le court terme (survivre aujourd'hui) et le moyen terme (ne plus crasher)
</success_criteria>
```
