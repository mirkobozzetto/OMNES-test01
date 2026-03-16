# Checklist de déploiement

## Pré-déploiement

- [ ] Backup de la table `settings` (pour rollback du lock)
- [ ] Backup du fichier `SendEmails.php` actuel
- [ ] Vérifier le `memory_limit` PHP actuel (`php -i | grep memory_limit`)
- [ ] Relever le temps d'exécution actuel (dernière notification Slack)

## P0 — Hotfix immédiat

### Étape 1 : Débloquer le lock (1 min)
```sql
UPDATE settings SET value = '1' WHERE key = 'CAN_RUN_SEND_EMAILS_COMMAND';
```
- [ ] Exécuté en production
- [ ] Vérifié : `SELECT * FROM settings WHERE key = 'CAN_RUN_SEND_EMAILS_COMMAND';` → value = '1'

### Étape 2 : Déployer le correctif (15 min)
- [ ] Remplacer `app/Console/Commands/SendEmails.php` par `correctif-P0-SendEmails.php`
- [ ] Vérifier que le trait `SendEmailTrait` expose bien `saveQueueHistory()`
- [ ] Déployer en production

### Étape 3 : Valider (10 min)
- [ ] Lancer `php artisan send:emails` manuellement
- [ ] Vérifier la notification Slack (temps d'exécution < 60s)
- [ ] Vérifier `memory_get_peak_usage()` dans les logs (< 256 MB)
- [ ] Vérifier que des emails sont bien mis en queue (`php artisan queue:work --once`)
- [ ] Vérifier le lock : si crash simulé (kill -9), le lock revient à '1'

### Étape 4 : Monitoring 24h
- [ ] Comparer le nombre d'emails envoyés avec la veille
- [ ] Vérifier qu'aucun doublon n'a été créé dans `queue_history_emails`
- [ ] Vérifier que les règles de désinscription fonctionnent (AUTO_UNSUBSCRIBE, NO_SPAM)

## P1 — Index (cette semaine)

- [ ] Planifier la migration en heures creuses
- [ ] Exécuter `php artisan migrate` avec `correctif-P1-migration.php`
- [ ] Vérifier les index : `SHOW INDEX FROM queue_history_emails;`
- [ ] Vérifier les index : `SHOW INDEX FROM analytics_events;`
- [ ] Vérifier les index : `SHOW INDEX FROM sent_emails;`
- [ ] Re-lancer `send:emails` et comparer le temps d'exécution

## P2 — Sprint suivant

- [ ] Planifier le refactoring `withoutOverlapping()` (P2-10)
- [ ] Évaluer le passage en Jobs par training (P2-11)
- [ ] Prototyper la table `contact_next_steps` sur staging (P2-12)

## Rollback

Si régression détectée après P0 :
```bash
# Restaurer l'ancien fichier
git checkout HEAD~1 -- app/Console/Commands/SendEmails.php
# Redéployer
```

Si lock bloqué :
```sql
UPDATE settings SET value = '1' WHERE key = 'CAN_RUN_SEND_EMAILS_COMMAND';
```
