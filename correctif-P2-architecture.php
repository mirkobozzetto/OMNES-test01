<?php

/**
 * ============================================================================
 * P2 — AMÉLIORATIONS STRUCTURELLES (SPRINT SUIVANT)
 * ============================================================================
 *
 * Ce fichier contient les drafts des 4 optimisations P2.
 * Chaque section est indépendante et peut être implémentée séparément.
 * ============================================================================
 */

// ============================================================================
// P2-10 : Remplacer le lock DB par withoutOverlapping()
// ============================================================================
// Fichier : app/Console/Kernel.php
//
// AVANT :
//   $schedule->command('send:emails')->daily();
//   + lock manuel via Setting::where('key', 'CAN_RUN_SEND_EMAILS_COMMAND')
//
// APRÈS :
//   $schedule->command('send:emails')
//       ->daily()
//       ->withoutOverlapping(30)        // Lock expire après 30 min max
//       ->onOneServer();                // Un seul serveur en multi-instance
//
// Avantage : plus besoin du Setting, plus de blocage permanent en cas de crash.
// Le lock est géré par Laravel via le cache driver (Redis/DB).
// On peut supprimer tout le code lié à CAN_RUN_SEND_EMAILS_COMMAND.
//
// Impact : supprimer ~15 lignes dans SendEmails.php, 1 ligne dans Kernel.php.

// ============================================================================
// P2-11 : Dispatcher un Job par training
// ============================================================================

namespace App\Console\Commands;

use App\Jobs\ProcessTrainingEmails;
use App\Training;
use Illuminate\Console\Command;

class SendEmailsV2 extends Command
{
    protected $signature = 'send:emails-v2';
    protected $description = 'Dispatch email processing jobs per training';

    public function handle()
    {
        $trainings = Training::active()
            ->with(['steps' => function ($query) {
                $query->orderBy('trigger_date', 'asc');
            }])
            ->get();

        foreach ($trainings as $training) {
            ProcessTrainingEmails::dispatch($training);
            $this->line('Dispatched job for Training ID : ' . $training->id);
        }

        $this->line('All training jobs dispatched. Processing in queue.');
    }
}

// ----------------------------------------------------------------------------
// Le Job correspondant :
// Fichier : app/Jobs/ProcessTrainingEmails.php
// ----------------------------------------------------------------------------

namespace App\Jobs;

use App\Training;
use App\Traits\SendEmailTrait;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class ProcessTrainingEmails implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels, SendEmailTrait;

    public int $tries = 3;
    public int $timeout = 300;

    public function __construct(
        private Training $training
    ) {
        $this->onQueue('emails');
    }

    public function handle(): void
    {
        Log::channel('emails_log')->info('Processing Training ID : ' . $this->training->id);

        // Réutiliser la logique du P0 (chunk + preload) mais pour un seul training
        // Chaque training est isolé : si l'un crash, les autres continuent
        // Le retry automatique (tries=3) relance en cas d'erreur transitoire
    }

    public function failed(\Throwable $exception): void
    {
        Log::channel('emails_log')->error(
            'FAILED Training ID : ' . $this->training->id . ' — ' . $exception->getMessage()
        );
    }
}


// ============================================================================
// P2-12 : Table contact_next_step pré-calculée
// ============================================================================
//
// Concept : au lieu de boucler sur TOUS les contacts × TOUS les steps,
// maintenir une table qui indique pour chaque contact quel est son prochain
// step à recevoir. Seuls les contacts dont le next_step est éligible aujourd'hui
// sont traités.
//
// Migration :
//
//   Schema::create('contact_next_steps', function (Blueprint $table) {
//       $table->id();
//       $table->foreignId('contact_id')->constrained()->onDelete('cascade');
//       $table->foreignId('training_id')->constrained()->onDelete('cascade');
//       $table->foreignId('next_step_id')->nullable()->constrained('steps')->onDelete('set null');
//       $table->timestamp('eligible_at')->nullable();
//       $table->timestamps();
//
//       $table->unique(['contact_id', 'training_id']);
//       $table->index(['next_step_id', 'eligible_at']);
//   });
//
// Requête optimisée :
//
//   $eligibleContacts = ContactNextStep::query()
//       ->where('eligible_at', '<=', now())
//       ->whereNotNull('next_step_id')
//       ->with(['contact', 'nextStep'])
//       ->chunk(500, function ($batch) {
//           foreach ($batch as $item) {
//               $this->processContactForStep($item->contact, $item->nextStep);
//               $item->advanceToNextStep(); // Met à jour next_step_id
//           }
//       });
//
// Avantage : complexité O(contacts_éligibles) au lieu de O(contacts × steps).
// Avec 50K contacts mais seulement 2K éligibles aujourd'hui → 25x plus rapide.
//
// La table est mise à jour après chaque envoi (avancer au step suivant)
// et peut être recalculée en batch si désynchronisée.


// ============================================================================
// P2-13 : Architecture event-driven (long terme)
// ============================================================================
//
// Concept : au lieu d'un CRON qui scanne tout chaque jour, utiliser des events
// Laravel pour déclencher les envois au bon moment.
//
// Events :
//   - StepBecameEligible : déclenché quand trigger_date d'un step = today
//   - ContactSubscribed : déclenché quand un contact rejoint un training
//   - EmailOpened : déclenché quand un tracking pixel est chargé
//
// Scheduler (Kernel.php) :
//
//   $schedule->call(function () {
//       $eligibleSteps = Step::where('trigger_date', today())
//           ->where('trigger_time', '<=', now()->toTimeString())
//           ->get();
//
//       foreach ($eligibleSteps as $step) {
//           event(new StepBecameEligible($step));
//       }
//   })->everyMinute();
//
// Listener :
//
//   class SendStepEmails
//   {
//       public function handle(StepBecameEligible $event)
//       {
//           $step = $event->step;
//           $training = $step->training;
//
//           $training->contacts()
//               ->whereDoesntHave('sentEmails', function ($q) use ($step) {
//                   $q->where('step_id', $step->id);
//               })
//               ->chunk(500, function ($contacts) use ($step) {
//                   foreach ($contacts as $contact) {
//                       SendContactStepEmail::dispatch($contact, $step, now());
//                   }
//               });
//       }
//   }
//
// Avantage : traitement incrémental, pas de scan global, scalable à l'infini.
// Inconvénient : migration complexe, nécessite un re-test complet des règles métier.
