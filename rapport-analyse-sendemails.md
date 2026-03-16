# Rapport d'analyse de performance — Commande `send:emails`

**Date** : 2026-03-16
**Sévérité** : CRITIQUE — Crash production
**Statut** : Analyse complète, hotfix proposé

---

## Résumé exécutif

La commande Laravel Artisan `send:emails`, exécutée quotidiennement en CRON, a crashé la production ce matin. L'analyse révèle une complexité algorithmique O(T × C × S × E) avec des millions de requêtes SQL unitaires dans des boucles imbriquées (problème N+1 massif), combinée à un chargement total des contacts en mémoire sans pagination. La dégradation est progressive et proportionnelle à la croissance des données.

---

## 1. Diagnostic — Problèmes identifiés

### Problème 1 : N+1 Query massif sur `QueueHistoryEmail`

- **Lignes** : L.96
- **Code** : `QueueHistoryEmail::where('contact_id', $contact->id)->where('step_id', $step->id)->count()`
- **Description** : Pour CHAQUE combinaison contact × step, une requête `SELECT COUNT(*)` est exécutée sur `queue_history_emails`. Aucun eager loading, aucun batch.
- **Complexité** : O(C × S) requêtes SQL pour cette seule ligne
- **Dégradation** : La table `queue_history_emails` grossit chaque jour (chaque email envoyé = 1 row). Le `COUNT(*)` sans index composite `(contact_id, step_id)` fait un full scan de plus en plus lent.

### Problème 2 : N+1 Query sur `AnalyticsEvent` via `ifEmailOpened()`

- **Lignes** : L.207-210
- **Code** : `AnalyticsEvent::where('sent_email_id', $email->id)->where('name', 'opened_email')->first()`
- **Description** : Appelée unitairement pour chaque email, dans chaque règle (AUTO_UNSUBSCRIBE, NO_SPAM, IF_CLICKED), pour chaque contact × step.
- **Complexité** : O(C × S × E) dans le pire cas (règle NO_SPAM itère sur les X derniers emails)
- **Dégradation** : La table `analytics_events` grossit avec chaque tracking pixel. Sans index sur `(sent_email_id, name)`, chaque lookup ralentit progressivement.

### Problème 3 : N+1 Query sur `SentEmail` via `getLastEmailsSent()`

- **Lignes** : L.176-185
- **Code** : `SentEmail::where('contact_id', $contact->id)->orderBy('created_at', 'desc')->get()`
- **Description** : Requête individuelle appelée dans les boucles NO_SPAM et IF_CLICKED. Quand `$step` est null, récupère TOUS les emails du contact sans limite.
- **Complexité** : O(C × S) requêtes
- **Dégradation** : Le nombre de `sent_emails` par contact augmente chaque jour, et la requête sans `$step` n'a pas de `LIMIT` — elle charge potentiellement des milliers de rows en mémoire.

### Problème 4 : N+1 Query sur `QueueHistoryEmail` via `getLastEmailSentToday()`

- **Lignes** : L.188-199
- **Code** : `QueueHistoryEmail::where('contact_id', '=', $contact->id)->where('date_email', '>=', $today)...`
- **Description** : Requête unitaire par contact pour les emails passés (`trigger_date < today`). Exécutée dans la boucle interne.
- **Complexité** : O(C × S_passés) requêtes

### Problème 5 : N+1 Query sur `Step` via `getPreviousCapsule()`

- **Lignes** : L.201-206
- **Code** : `Step::where('training_id', $step->training_id)->where('trigger_date', '<', $previousDate)->first()`
- **Description** : Requête `Step::where(...)` pour chaque step avec `rule_if_clicked`. Totalement inutile puisque les steps sont déjà eager-loaded et triés par `trigger_date asc`.
- **Complexité** : O(S_clicked) requêtes par contact

### Problème 6 : Chargement total en mémoire de TOUS les contacts

- **Lignes** : L.80-83
- **Code** : `Training::active()->with('contacts')->with(['steps' => ...])->get()`
- **Description** : `with('contacts')` charge la totalité des contacts de chaque training en mémoire via Eloquent collections. Aucune pagination, aucun chunking. Si un training a 100 000 contacts, ils sont tous instanciés en objets PHP.
- **Complexité** : O(T × C) objets en mémoire
- **Dégradation** : Chaque nouveau contact inscrit augmente la consommation RAM. La relation `sent_emails` sur chaque contact est aussi potentiellement chargée (via `$contact->sent_emails` L.97).

### Problème 7 : `$contact->sent_emails` sans eager loading explicite

- **Lignes** : L.97
- **Code** : `$contact->sent_emails->where('step_id', $step->id)->first()`
- **Description** : Accède à la relation `sent_emails` du contact. Si elle n'est pas eager-loaded, ça déclenche une requête N+1 supplémentaire par contact. Même si elle est lazy-loaded une fois, le `->where()` sur la collection en mémoire itère sur tous les `sent_emails` du contact pour chaque step.
- **Complexité** : O(C × S × E_par_contact) opérations en mémoire

### Problème 8 : Mécanisme de lock fragile

- **Lignes** : L.62-64 et L.157
- **Code** : `$canStart->value = '0'` / `$canStart->value = '1'`
- **Description** : Si la commande crash (OOM, timeout), le flag reste à `'0'` et la commande ne peut plus jamais redémarrer sans intervention manuelle en BDD.
- **Dégradation** : Pas de dégradation progressive, mais un crash unique = blocage permanent.

### Résumé de la complexité totale

**Nombre de requêtes SQL estimé par exécution :**

```
Requêtes = T × C × S × (1 [QueueHistory count]
                       + 1 [ifEmailOpened pour AUTO_UNSUB]
                       + N [ifEmailOpened pour NO_SPAM, N = rule_no_spam]
                       + 1 [getLastEmailsSent]
                       + 1 [getPreviousCapsule]
                       + 1 [getLastEmailSentToday])
```

**Estimation avec T=10 trainings, C=5000 contacts, S=20 steps :**

| Scénario                                 | Requêtes SQL estimées | RAM estimée |
| ---------------------------------------- | --------------------- | ----------- |
| Estimation basse (peu de règles actives) | ~3 000 000            | ~500 MB     |
| Estimation haute (toutes règles actives) | ~5 000 000+           | ~1-2 GB     |

---

## 2. Analyse du crash

### Cause probable

**Memory exhaustion (OOM)** combinée à un **timeout**.

L'accumulation de contacts + `sent_emails` en mémoire sans chunking dépasse le `memory_limit` PHP. En parallèle, les millions de requêtes SQL unitaires font exploser le temps d'exécution au-delà du `max_execution_time` ou du timeout du superviseur CRON.

### Scénario de déclenchement

Un seuil critique a été franchi récemment — probablement un training avec un grand nombre de contacts a dépassé la capacité. Chaque jour, de nouveaux contacts s'ajoutent et de nouveaux `sent_emails` / `analytics_events` s'accumulent, ce qui augmente à la fois la RAM et le nombre de requêtes. Le matin du crash, la combinaison `nombre de contacts × nombre de steps passés` a dépassé le point de rupture.

### Seuil critique estimé

Environ **20 000-50 000 contacts actifs** avec **30+ steps** par training et **50+ sent_emails** par contact. Au-delà, PHP dépasse typiquement 512MB-1GB de RAM et 300s de timeout.

### Facteur aggravant

Le crash a laissé le lock `CAN_RUN_SEND_EMAILS_COMMAND = 0`, donc la commande est actuellement **bloquée** et ne peut pas redémarrer.

---

## 3. Plan d'optimisation

### P0 — Hotfix immédiat (déployer aujourd'hui)

| #   | Optimisation                                    | Impact estimé                  | Effort | Risque |
| --- | ----------------------------------------------- | ------------------------------ | ------ | ------ |
| 1   | Débloquer le lock en BDD                        | Critique — rétablir le service | 1 min  | Nul    |
| 2   | Remplacer la boucle globale par `chunk()`       | -80% RAM                       | 15 min | Faible |
| 3   | Eager-load `sent_emails` avec les contacts      | -60% requêtes                  | 10 min | Faible |
| 4   | Pré-charger `QueueHistoryEmail` en batch        | -30% requêtes                  | 15 min | Faible |
| 5   | Ajouter un try/catch avec unlock dans `finally` | Fiabilité du lock              | 5 min  | Nul    |

#### Snippet P0-1 : Débloquer le lock

```sql
UPDATE settings SET value = '1' WHERE key = 'CAN_RUN_SEND_EMAILS_COMMAND';
```

#### Snippet P0-2 : Chunking des contacts

```php
// AVANT (charge tout en mémoire)
$contacts = $training->contacts->filter(function ($contact) {
    return $contact->canSendEmailToday() === true;
});

// APRÈS (traitement par lots de 500)
$training->contacts()->chunk(500, function ($contacts) use ($training, $dateToday, $timeToday, $dateTimeToday, &$sentEmailsCount) {
    $contacts = $contacts->filter(function ($contact) {
        return $contact->canSendEmailToday() === true;
    });

    $contactIds = $contacts->pluck('id');

    // Pré-charger QueueHistoryEmail en batch pour ce lot
    $queueHistories = QueueHistoryEmail::whereIn('contact_id', $contactIds)
        ->select('contact_id', 'step_id')
        ->get()
        ->groupBy(function ($item) {
            return $item->contact_id . '-' . $item->step_id;
        });

    // Pré-charger SentEmail en batch pour ce lot
    $sentEmails = SentEmail::whereIn('contact_id', $contactIds)
        ->get()
        ->groupBy('contact_id');

    // Pré-charger AnalyticsEvent (opened) en batch pour ce lot
    $sentEmailIds = $sentEmails->flatten()->pluck('id');
    $openedEmails = AnalyticsEvent::whereIn('sent_email_id', $sentEmailIds)
        ->where('name', 'opened_email')
        ->pluck('sent_email_id')
        ->flip();

    foreach ($contacts as $contact) {
        $contactSentEmails = $sentEmails->get($contact->id, collect());

        foreach ($training->steps as $step) {
            if ($step->trigger_date->toDateString() > $dateToday) {
                continue;
            }
            if ($contact->unsubscribe) {
                continue;
            }

            // Vérifier via les données pré-chargées (0 requête SQL)
            $key = $contact->id . '-' . $step->id;
            $queueHistory = $queueHistories->has($key);
            $emailAlreadySent = $contactSentEmails->where('step_id', $step->id)->first();

            // Pour ifEmailOpened, utiliser le Set pré-chargé :
            // $openedEmails->has($email->id) au lieu de $this->ifEmailOpened($email)

            // ... reste de la logique identique
        }
    }
});
```

#### Snippet P0-3 : Eager-load sent_emails

```php
// AVANT
$trainings = Training::active()->with('contacts')
    ->with(['steps' => function ($query) {
        $query->orderBy('trigger_date', 'asc');
    }])->get();

// APRÈS (si on garde le chargement global au lieu du chunk)
$trainings = Training::active()
    ->with(['contacts.sent_emails', 'steps' => function ($query) {
        $query->orderBy('trigger_date', 'asc');
    }])->get();
```

#### Snippet P0-5 : Try/catch avec unlock garanti

```php
public function handle()
{
    $canStart = Setting::where('key', 'CAN_RUN_SEND_EMAILS_COMMAND')->first();

    $dateStart = Carbon::now()->toDateTimeString();

    if (! boolval($canStart->value)) {
        $this->error('Can not start');
        Notification::route('slack', env('SLACK_WEBHOOK'))
            ->notify(new SendEmailsCommandLocked());
        return;
    }

    $canStart->value = '0';
    $canStart->save();

    try {
        $this->processEmails($dateStart);
    } catch (\Throwable $e) {
        Log::channel('emails_log')->error('CRASH send:emails — ' . $e->getMessage());
        Notification::route('slack', env('SLACK_WEBHOOK'))
            ->notify(new SendEmailsCommandLocked());
        throw $e;
    } finally {
        $canStart->value = '1';
        $canStart->save();
    }
}

private function processEmails(string $dateStart)
{
    // ... tout le contenu actuel de handle() après le lock
}
```

---

### P1 — Corrections cette semaine

| #   | Optimisation                                                       | Impact estimé                      | Effort | Risque |
| --- | ------------------------------------------------------------------ | ---------------------------------- | ------ | ------ |
| 6   | Index composite `(contact_id, step_id)` sur `queue_history_emails` | -50% temps requêtes QueueHistory   | 10 min | Faible |
| 7   | Index composite `(sent_email_id, name)` sur `analytics_events`     | -40% temps requêtes AnalyticsEvent | 10 min | Faible |
| 8   | Index `(contact_id, created_at)` sur `sent_emails`                 | -30% temps requêtes SentEmail      | 10 min | Faible |
| 9   | Supprimer `getPreviousCapsule()` — utiliser les steps déjà chargés | Éliminer requêtes inutiles         | 15 min | Faible |
| 10  | Limiter `getLastEmailsSent()` quand `$step` est null               | Réduire RAM                        | 5 min  | Faible |

#### Snippet P1-6/7/8 : Migrations d'index

```php
// Migration : add_performance_indexes
public function up()
{
    Schema::table('queue_history_emails', function (Blueprint $table) {
        $table->index(['contact_id', 'step_id'], 'qhe_contact_step_idx');
    });

    Schema::table('analytics_events', function (Blueprint $table) {
        $table->index(['sent_email_id', 'name'], 'ae_sent_email_name_idx');
    });

    Schema::table('sent_emails', function (Blueprint $table) {
        $table->index(['contact_id', 'created_at'], 'se_contact_created_idx');
    });
}
```

#### Snippet P1-9 : Éliminer `getPreviousCapsule()`

```php
// AVANT — requête SQL inutile
if ($previousStep = $this->getPreviousCapsule($step)) { ... }

// APRÈS — les steps sont déjà triés par trigger_date asc dans la collection
$stepIndex = $training->steps->search(function ($s) use ($step) {
    return $s->id === $step->id;
});
$previousStep = $stepIndex > 0 ? $training->steps[$stepIndex - 1] : null;
```

#### Snippet P1-10 : Limiter `getLastEmailsSent()`

```php
// AVANT — charge TOUS les emails quand $step est null
return SentEmail::where('contact_id', $contact->id)
    ->orderBy('created_at', 'desc')
    ->limit($number)
    ->get();

// APRÈS — garantir un LIMIT même si $number est null
private function getLastEmailsSent($contact, $number = 10, $step = null)
{
    $query = SentEmail::where('contact_id', $contact->id);

    if ($step) {
        $query->where('step_id', $step->id);
    }

    return $query->orderBy('created_at', 'desc')
        ->limit($number)
        ->get();
}
```

---

### P2 — Améliorations structurelles (sprint suivant)

| #   | Optimisation                                                        | Impact estimé                                            | Effort    | Risque |
| --- | ------------------------------------------------------------------- | -------------------------------------------------------- | --------- | ------ |
| 11  | Remplacer le lock DB par `withoutOverlapping()` de Laravel          | Fiabilité — lock automatique avec expiration             | 1h        | Faible |
| 12  | Traiter chaque training dans un Job séparé (parallélisation)        | Scalabilité horizontale — répartir la charge             | 4h        | Moyen  |
| 13  | Pré-calculer les contacts éligibles (table pivot `next_step_id`)    | -90% itérations — ne traiter que les contacts pertinents | 1-2 jours | Moyen  |
| 14  | Architecture event-driven : un event "step ready" déclenche l'envoi | Éliminer le CRON — traitement en temps réel              | 3-5 jours | Élevé  |

#### Détail P2-11 : `withoutOverlapping()`

```php
protected $signature = 'send:emails';

public function handle()
{
    // Laravel gère le lock avec expiration automatique (10 min par défaut)
    // Plus besoin du Setting CAN_RUN_SEND_EMAILS_COMMAND
}

// Dans le Kernel :
$schedule->command('send:emails')->daily()->withoutOverlapping(30);
// Lock expire après 30 min max, même en cas de crash
```

#### Détail P2-12 : Job par training

```php
// Au lieu de tout traiter dans la commande :
$trainings = Training::active()->get();

foreach ($trainings as $training) {
    ProcessTrainingEmails::dispatch($training);
}

// Chaque training est traité en parallèle dans la queue
// Si un training crash, les autres continuent
```

---

## 4. Checklist de validation post-fix

- [ ] Le lock est débloqué en BDD (`value = '1'`)
- [ ] La commande `php artisan send:emails` démarre correctement
- [ ] Le bloc `finally` remet le lock à `'1'` même en cas de crash
- [ ] La mémoire PHP reste sous 256MB pendant l'exécution (monitorer avec `memory_get_peak_usage()`)
- [ ] Le nombre de requêtes SQL a été réduit (activer le query log Laravel et compter)
- [ ] Le temps d'exécution total est inférieur à 60 secondes (vérifier la notification Slack)
- [ ] Les mêmes emails sont envoyés qu'avant (comparer les `queue_history_emails` sur staging)
- [ ] Les règles AUTO_UNSUBSCRIBE, NO_SPAM, IF_CLICKED fonctionnent toujours correctement
- [ ] Les index ont été déployés avec `php artisan migrate`
- [ ] Aucune régression sur les emails envoyés pendant 48h de monitoring

---

## 5. Impact estimé des optimisations cumulées

| Métrique                 | Avant (estimé) | Après P0    | Après P0+P1 |
| ------------------------ | -------------- | ----------- | ----------- |
| Requêtes SQL             | ~3 000 000     | ~10 000     | ~5 000      |
| RAM peak                 | ~1-2 GB        | ~100-200 MB | ~100 MB     |
| Temps d'exécution        | 300s+ (crash)  | ~30-60s     | ~10-20s     |
| Risque de lock permanent | Élevé          | Nul         | Nul         |

---

_Rapport généré dans le cadre de l'incident production du 2026-03-16._
