<?php

namespace App\Console\Commands;

use App\AnalyticsEvent;
use App\ClickEvent;
use App\Jobs\SendContactStepEmail;
use App\Mail\ContactStepEmail;
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
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;

class SendEmails extends Command
{
    use SendEmailTrait;
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'send:emails';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Send Emails every day for each Trainings';

    /**
     * Create a new command instance.
     *
     * @return void
     */
    public function __construct()
    {
        parent::__construct();
    }

    /**
     * Execute the console command.
     *
     * @return mixed
     * @throws Exception
     */
    public function handle()
    {
        /*
         * To start this command : php artisan send:email
         * To start the Mail Queue : php artisan queue:work
         */

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

        $this->line('Start Cron at '.$dateStart);
        $start_time = microtime(true);
        Log::channel('emails_log')->info('Start at . '.$dateStart);

        $sentEmailsCount = 0;

//        --------------------------------------------------------------------------------------------------

        $trainings = Training::active()->with('contacts')
            ->with(['steps' => function ($query) {
                $query->orderBy('trigger_date', 'asc');
            }])->get();

        $dateToday = Carbon::now()->toDateString();
        $timeToday = Carbon::now()->toTimeString();
        $dateTimeToday = Carbon::now();

        foreach ($trainings as $training) {
            $this->line('Training ID : '.$training->id);

            //Get all the contacts allowed to receive email
            $contacts = $training->contacts->filter(function ($contact) {
                return $contact->canSendEmailToday() === true;
            });

            foreach ($contacts as $contact) {
                $this->line('Contact ID : '.$contact->id);
                foreach ($training->steps as $step) {
                    $this->line('Step ID : '.$step->id);
                    $rule = 'No rule';

                    //If the step date is later
                    if ($step->trigger_date->toDateString() > $dateToday) {
                        continue;
                    }

                    //If contact subscribe has been changed during the task, we check it first
                    if ($contact->unsubscribe) {
                        continue;
                    }

                    //RULE PRIVATE LIST
                    //If contact is not in private list we don't send email for the step
                    if ($step->rule_private_list) {
                        if (! in_array($contact->id, json_decode($step->rule_private_list))) {
                            continue;
                        }
                        $rule = 'Private list';
                    }

                    //Queue History is used to prevent sending email twice
                    $queueHistory = QueueHistoryEmail::where('contact_id', $contact->id)->where('step_id', $step->id)->count();
                    $emailAlreadySent = $contact->sent_emails->where('step_id', $step->id)->first();

                    //RULE AUTO_UNSUBSCRIBE
                    //We check if user didn't open this email during the last 72h and unsubscribe him
                    if ($step->rule_auto_unsubscribe && $step->isCta()) {
                        if ($emailAlreadySent) {
                            //If no email was opened we check how long now
                            if (! $emailOpened = $this->ifEmailOpened($emailAlreadySent)) {
                                if ($dateTimeToday->diffInHours($emailAlreadySent->created_at) >= 72) {
                                    $contact->unsubscribe = true;
                                    $contact->save();

                                    $this->line('UNSUBSCRIBE CONTACT ID : '.$contact->id.' - RULE : AUTO UNSUBSCRIBE');
                                    Log::channel('UNSUBSCRIBE CONTACT ID : '.$contact->id.' - RULE : AUTO UNSUBSCRIBE');

                                    continue;
                                }
                            }
                        }
                    }

                    //RULE NO_SPAM
                    //If user didn't open the lasts X mails, then we unsubscribe him
                    if ($step->rule_no_spam && $step->isCta()) {
                        if ($lastEmailsSent = $this->getLastEmailsSent($contact, $step->rule_no_spam)) {
                            //Example if lasts 5 emails are not opened, we unsubscribe
                            if (count($lastEmailsSent) >= $step->rule_no_spam) {
                                $check = true;
                                foreach ($lastEmailsSent as $email) {
                                    if ($emailOpened = $this->ifEmailOpened($email)) {
                                        $check = false;
                                    }
                                }

                                if ($check) {
                                    $contact->unsubscribe = true;
                                    $contact->save();

                                    $this->line('UNSUBSCRIBE CONTACT ID : '.$contact->id.' - RULE : NO SPAM');
                                    Log::channel('UNSUBSCRIBE CONTACT ID : '.$contact->id.' - RULE : NO SPAM');
                                    continue;
                                }
                            }
                        }
                    }

                    //RULE IF_CLICKED
                    //We send an email only if user saw the last capsule
                    if ($step->rule_if_clicked && $step->isCta()) {
                        if ($previousStep = $this->getPreviousCapsule($step)) {
                            if ($lastEmailsSent = $this->getLastEmailsSent($contact, null, $previousStep)) {
                                $check = true;
                                foreach ($lastEmailsSent as $email) {
                                    if ($emailOpened = $this->ifEmailOpened($email)) {
                                        $check = false;
                                    }
                                }

                                if ($check) {
                                    //Email not opened, then we stop
                                    $this->line('LAST EMAIL NOT OPENED FOR CONTACT ID : '.$contact->id.' - RULE : IF CLICKED');
                                    continue;
                                }
                            }
                        }
                    }

                    //If this step is already sent to the contact we jump to the next loop
                    if ($emailAlreadySent or $queueHistory > 0) {
                        continue;
                    }

                    //If Step trigger time is before today we send email that User has missed
                    if ($step->trigger_date->toDateString() < $dateToday) {
                        $lastEmailToday = $this->getLastEmailSentToday($contact);
                        //We had 5 minutes interval between each email for today
                        $when = $lastEmailToday ? Carbon::parse($lastEmailToday->date_email)->addMinutes(5) : Carbon::now();

                        $this->sendEmail($contact, $step, $when);
                        $this->saveQueueHistory($contact, $step, $when);
                        $sentEmailsCount++;

                        $this->line('Old Step ID : '.$step->id.' -> send to contact ID : '.$contact->id.' on : '.$when.' - RULE : '.$rule);
                        Log::channel('emails_log')->info('Old Step ID : '.$step->id.' -> send to contact ID : '.$contact->id.' on : '.$when.' - RULE : '.$rule);

                        continue;
                    }

                    //if Step trigger time is Today we put email in Queue to send now or later
                    if ($step->trigger_date->isToday() && $step->trigger_time <= $timeToday) {
                        $when = Carbon::now();
                        //If training has Smart Delivery
                        if ($training->smart_delivery) {
                            if ($step->trigger_time < '23:00') {
                                //We fix a random value to send email around the trigger fixed
                                $random = random_int(1, 60);
                                $when = Carbon::Parse($step->trigger_time)->addMinutes($random);
                            }
                        }

                        $this->sendEmail($contact, $step, $when);
                        $this->saveQueueHistory($contact, $step, $when);
                        $sentEmailsCount++;

                        $this->line('Mail today STEP ID : '.$step->id.' -> send to contact ID : '.$contact->id.' on : '.$when.' - RULE : '.$rule);
                        Log::channel('emails_log')->info('Mail today STEP ID : '.$step->id.' -> send to contact ID : '.$contact->id.' on : '.$when.' - RULE : '.$rule);
                    }
                }
            }
        }

        $canStart->value = '1';
        $canStart->save();

        $end_time = microtime(true);
        $execution_time = round(($end_time - $start_time), 2);

        $output = 'End send:emails command in '.$execution_time.' seconds (start at '.$dateStart.').';

        $this->line($output);
        Log::channel('emails_log')->info('End in '.$execution_time.' seconds');
        Notification::route('slack', env('SLACK_DEBUG_WEBHOOK'))
            ->notify(new SendEmailsOutput($dateStart, $execution_time, $sentEmailsCount));
    }

    private function getLastEmailSent($contact, $step = null)
    {
        if ($step) {
            return SentEmail::where('contact_id', $contact->id)->where('step_id', $step->id)->orderBy('created_at', 'desc')->first();
        }

        return SentEmail::where('contact_id', $contact->id)->orderBy('created_at', 'desc')->first();
    }

    private function getLastEmailsSent($contact, $number, $step = null)
    {
        if ($step) {
            return SentEmail::where('contact_id', $contact->id)->where('step_id', $step->id)->orderBy('created_at', 'desc')->get();
        }

        return SentEmail::where('contact_id', $contact->id)->orderBy('created_at', 'desc')->limit($number)->get();
    }

    private function getLastEmailSentToday($contact)
    {
        $today = Carbon::now()->setTime(0, 0, 0)->toDateTimeString();
        $tomorrow = Carbon::now()->addDay()->setTime(0, 0, 0)->toDateTimeString();

        $lastEmailSentToday = QueueHistoryEmail::where('contact_id', '=', $contact->id)
            ->where('date_email', '>=', $today)
            ->where('date_email', '<', $tomorrow)
            ->orderBy('date_email', 'desc')
            ->first();

        return $lastEmailSentToday;
    }

    private function getLastEmailOpened($contact)
    {
        $lastEmailOpened = ClickEvent::whereIntegerInRaw('sent_email_id', function ($query) use ($contact) {
            $query->select('id')->from('sent_emails')
                ->where('contact_id', $contact->id);
        })->orderBy('date', 'desc')->first();

        return $lastEmailOpened;
    }

    private function getNextCapsule($step)
    {
        $nextDate = $step->trigger_date->toDateString();

        return Step::where('training_id', $step->training_id)->where('id', '!=', $step->id)->where('trigger_date', '>', $nextDate)->orderBy('trigger_date', 'asc')->first();
    }

    private function getPreviousCapsule($step)
    {
        $previousDate = $step->trigger_date->toDateString();

        return Step::where('training_id', $step->training_id)->where('id', '!=', $step->id)->where('trigger_date', '<', $previousDate)->orderBy('trigger_date', 'desc')->first();
    }

    private function ifEmailOpened($email)
    {
        return AnalyticsEvent::where('sent_email_id', $email->id)->where('name', 'opened_email')->first();
    }

    private function sendEmail($contact, $step, $when)
    {
        SendContactStepEmail::dispatch($contact, $step, $when);
    }
}
