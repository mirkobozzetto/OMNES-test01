<?php

namespace App\Console\Commands;

use App\AnalyticsEvent;
use App\ClickEvent;
use App\Jobs\SendContactStepEmail;
use App\Notifications\SendEmailsCommandLocked;
use App\Notifications\SendEmailsOutput;
use App\QueueHistoryEmail;
use App\SentEmail;
use App\Setting;
use App\Step;
use App\Training;
use App\Traits\SendEmailTrait;
use Carbon\Carbon;
use Exception;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;

class SendEmails extends Command
{
    use SendEmailTrait;

    protected $signature = 'send:emails';
    protected $description = 'Send Emails every day for each Trainings';

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
            $sentEmailsCount = $this->processAllTrainings($dateStart);
        } catch (\Throwable $e) {
            Log::channel('emails_log')->error('CRASH send:emails — ' . $e->getMessage() . ' — ' . $e->getTraceAsString());
            Notification::route('slack', env('SLACK_WEBHOOK'))
                ->notify(new SendEmailsCommandLocked());
            throw $e;
        } finally {
            $canStart->value = '1';
            $canStart->save();
        }

        $execution_time = round((microtime(true) - $GLOBALS['__apex_start_time']), 2);
        $output = 'End send:emails command in ' . $execution_time . ' seconds (start at ' . $dateStart . ').';
        $this->line($output);
        Log::channel('emails_log')->info('End in ' . $execution_time . ' seconds');
        Notification::route('slack', env('SLACK_DEBUG_WEBHOOK'))
            ->notify(new SendEmailsOutput($dateStart, $execution_time, $sentEmailsCount));
    }

    private function processAllTrainings(string $dateStart): int
    {
        $GLOBALS['__apex_start_time'] = microtime(true);
        $this->line('Start Cron at ' . $dateStart);
        Log::channel('emails_log')->info('Start at . ' . $dateStart);

        $sentEmailsCount = 0;

        $trainings = Training::active()
            ->with(['steps' => function ($query) {
                $query->orderBy('trigger_date', 'asc');
            }])
            ->get();

        $dateToday = Carbon::now()->toDateString();
        $timeToday = Carbon::now()->toTimeString();
        $dateTimeToday = Carbon::now();

        foreach ($trainings as $training) {
            $this->line('Training ID : ' . $training->id);

            $training->contacts()
                ->chunk(500, function ($contactsBatch) use (
                    $training, $dateToday, $timeToday, $dateTimeToday, &$sentEmailsCount
                ) {
                    $contacts = $contactsBatch->filter(function ($contact) {
                        return $contact->canSendEmailToday() === true;
                    });

                    if ($contacts->isEmpty()) {
                        return;
                    }

                    $contactIds = $contacts->pluck('id')->toArray();

                    $preloaded = $this->preloadBatchData($contactIds);

                    foreach ($contacts as $contact) {
                        $this->processContact(
                            $contact,
                            $training,
                            $preloaded,
                            $dateToday,
                            $timeToday,
                            $dateTimeToday,
                            $sentEmailsCount
                        );
                    }
                });
        }

        return $sentEmailsCount;
    }

    private function preloadBatchData(array $contactIds): array
    {
        $queueHistories = QueueHistoryEmail::whereIn('contact_id', $contactIds)
            ->select('contact_id', 'step_id')
            ->get()
            ->groupBy(function ($item) {
                return $item->contact_id . '-' . $item->step_id;
            });

        $sentEmails = SentEmail::whereIn('contact_id', $contactIds)
            ->orderBy('created_at', 'desc')
            ->get()
            ->groupBy('contact_id');

        $allSentEmailIds = $sentEmails->flatten()->pluck('id')->toArray();

        $openedEmailIds = [];
        if (! empty($allSentEmailIds)) {
            $openedEmailIds = AnalyticsEvent::whereIn('sent_email_id', $allSentEmailIds)
                ->where('name', 'opened_email')
                ->pluck('sent_email_id')
                ->flip()
                ->toArray();
        }

        $today = Carbon::now()->setTime(0, 0, 0)->toDateTimeString();
        $tomorrow = Carbon::now()->addDay()->setTime(0, 0, 0)->toDateTimeString();

        $lastEmailsToday = QueueHistoryEmail::whereIn('contact_id', $contactIds)
            ->where('date_email', '>=', $today)
            ->where('date_email', '<', $tomorrow)
            ->orderBy('date_email', 'desc')
            ->get()
            ->groupBy('contact_id')
            ->map(function ($emails) {
                return $emails->first();
            });

        return [
            'queueHistories' => $queueHistories,
            'sentEmails' => $sentEmails,
            'openedEmailIds' => $openedEmailIds,
            'lastEmailsToday' => $lastEmailsToday,
        ];
    }

    private function processContact(
        $contact,
        $training,
        array $preloaded,
        string $dateToday,
        string $timeToday,
        Carbon $dateTimeToday,
        int &$sentEmailsCount
    ): void {
        $this->line('Contact ID : ' . $contact->id);

        $contactSentEmails = $preloaded['sentEmails']->get($contact->id, collect());
        $steps = $training->steps;

        foreach ($steps as $stepIndex => $step) {
            $this->line('Step ID : ' . $step->id);
            $rule = 'No rule';

            if ($step->trigger_date->toDateString() > $dateToday) {
                continue;
            }

            if ($contact->unsubscribe) {
                continue;
            }

            if ($step->rule_private_list) {
                if (! in_array($contact->id, json_decode($step->rule_private_list))) {
                    continue;
                }
                $rule = 'Private list';
            }

            $queueKey = $contact->id . '-' . $step->id;
            $queueHistory = $preloaded['queueHistories']->has($queueKey);
            $emailAlreadySent = $contactSentEmails->where('step_id', $step->id)->first();

            if ($step->rule_auto_unsubscribe && $step->isCta()) {
                if ($emailAlreadySent) {
                    if (! $this->isEmailOpenedFromCache($emailAlreadySent, $preloaded['openedEmailIds'])) {
                        if ($dateTimeToday->diffInHours($emailAlreadySent->created_at) >= 72) {
                            $contact->unsubscribe = true;
                            $contact->save();
                            $this->line('UNSUBSCRIBE CONTACT ID : ' . $contact->id . ' - RULE : AUTO UNSUBSCRIBE');
                            Log::channel('emails_log')->info('UNSUBSCRIBE CONTACT ID : ' . $contact->id . ' - RULE : AUTO UNSUBSCRIBE');
                            continue;
                        }
                    }
                }
            }

            if ($step->rule_no_spam && $step->isCta()) {
                $lastEmails = $contactSentEmails->take($step->rule_no_spam);
                if ($lastEmails->count() >= $step->rule_no_spam) {
                    $allUnopened = true;
                    foreach ($lastEmails as $email) {
                        if ($this->isEmailOpenedFromCache($email, $preloaded['openedEmailIds'])) {
                            $allUnopened = false;
                            break;
                        }
                    }
                    if ($allUnopened) {
                        $contact->unsubscribe = true;
                        $contact->save();
                        $this->line('UNSUBSCRIBE CONTACT ID : ' . $contact->id . ' - RULE : NO SPAM');
                        Log::channel('emails_log')->info('UNSUBSCRIBE CONTACT ID : ' . $contact->id . ' - RULE : NO SPAM');
                        continue;
                    }
                }
            }

            if ($step->rule_if_clicked && $step->isCta()) {
                $previousStep = $stepIndex > 0 ? $steps[$stepIndex - 1] : null;
                if ($previousStep) {
                    $previousStepEmails = $contactSentEmails->where('step_id', $previousStep->id);
                    if ($previousStepEmails->isNotEmpty()) {
                        $allUnopened = true;
                        foreach ($previousStepEmails as $email) {
                            if ($this->isEmailOpenedFromCache($email, $preloaded['openedEmailIds'])) {
                                $allUnopened = false;
                                break;
                            }
                        }
                        if ($allUnopened) {
                            $this->line('LAST EMAIL NOT OPENED FOR CONTACT ID : ' . $contact->id . ' - RULE : IF CLICKED');
                            continue;
                        }
                    }
                }
            }

            if ($emailAlreadySent || $queueHistory) {
                continue;
            }

            if ($step->trigger_date->toDateString() < $dateToday) {
                $lastEmailToday = $preloaded['lastEmailsToday']->get($contact->id);
                $when = $lastEmailToday
                    ? Carbon::parse($lastEmailToday->date_email)->addMinutes(5)
                    : Carbon::now();

                $this->sendEmail($contact, $step, $when);
                $this->saveQueueHistory($contact, $step, $when);
                $sentEmailsCount++;

                $this->line('Old Step ID : ' . $step->id . ' -> send to contact ID : ' . $contact->id . ' on : ' . $when . ' - RULE : ' . $rule);
                Log::channel('emails_log')->info('Old Step ID : ' . $step->id . ' -> send to contact ID : ' . $contact->id . ' on : ' . $when . ' - RULE : ' . $rule);
                continue;
            }

            if ($step->trigger_date->isToday() && $step->trigger_time <= $timeToday) {
                $when = Carbon::now();
                if ($training->smart_delivery) {
                    if ($step->trigger_time < '23:00') {
                        $random = random_int(1, 60);
                        $when = Carbon::Parse($step->trigger_time)->addMinutes($random);
                    }
                }

                $this->sendEmail($contact, $step, $when);
                $this->saveQueueHistory($contact, $step, $when);
                $sentEmailsCount++;

                $this->line('Mail today STEP ID : ' . $step->id . ' -> send to contact ID : ' . $contact->id . ' on : ' . $when . ' - RULE : ' . $rule);
                Log::channel('emails_log')->info('Mail today STEP ID : ' . $step->id . ' -> send to contact ID : ' . $contact->id . ' on : ' . $when . ' - RULE : ' . $rule);
            }
        }
    }

    private function isEmailOpenedFromCache($email, array $openedEmailIds): bool
    {
        return isset($openedEmailIds[$email->id]);
    }

    private function sendEmail($contact, $step, $when)
    {
        SendContactStepEmail::dispatch($contact, $step, $when);
    }
}
