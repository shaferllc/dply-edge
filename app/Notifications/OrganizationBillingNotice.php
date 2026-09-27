<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\Organization;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Carbon;

/**
 * Trial and pause emails to an org's owners (ruling r-f17p5zgeh120cm5t),
 * sent once each by EnforceOrganizationBillingCommand:
 *
 *   trial_started   the trial began (or an existing Free org got one)
 *   trial_ending_soon  3 days before the trial ends
 *   trial_ending    about a day before the trial ends
 *   paused          the trial ended unpaid; sites are paused
 *   deleting        the paused org's data is deleted on $date
 */
class OrganizationBillingNotice extends Notification implements ShouldQueue
{
    use Queueable;

    public const KINDS = ['trial_started', 'trial_ending_soon', 'trial_ending', 'paused', 'deleting'];

    public function __construct(
        public Organization $organization,
        public string $kind,
        public ?Carbon $date = null,
    ) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $name = $this->organization->name;
        $when = $this->date?->toDayDateTimeString() ?? '';
        $billing = route('subscription.show', $this->organization);
        $days = (int) config('subscription.standard.trial.days', 5);
        $keep = (int) config('subscription.standard.trial.keep_data_days', 7);
        $mail = new MailMessage;

        return match ($this->kind) {
            'trial_started' => $mail
                ->subject(__('Your :days-day dply trial has started', ['days' => $days]))
                ->line(__(':org is on a :days-day trial of Pro, until :when.', ['org' => $name, 'days' => $days, 'when' => $when]))
                ->line($this->organization->subscription('default')?->onTrial()
                    ? __('It becomes Pro ($20/mo) when the trial ends unless you cancel before then.')
                    : __('dply no longer has a free plan. Choose a plan before the trial ends to keep your sites running.'))
                ->action(__('Billing'), $billing),
            'trial_ending_soon' => $mail
                ->subject(__('3 days left on your dply trial'))
                ->line($this->organization->subscription('default')?->onTrial()
                    ? __('The trial for :org ends :when, and Pro starts billing then ($20/mo). Cancel before then if you don’t want to continue.', ['org' => $name, 'when' => $when])
                    : __('The trial for :org ends :when. Choose a plan before then to keep its sites running.', ['org' => $name, 'when' => $when]))
                ->action(__('Billing'), $billing),
            'trial_ending' => $mail
                ->subject(__('Your dply trial ends :when', ['when' => $when]))
                ->line($this->organization->subscription('default')?->onTrial()
                    ? __('The trial for :org ends :when, and Pro starts billing then. Cancel before then if you don’t want to continue.', ['org' => $name, 'when' => $when])
                    : __('The trial for :org ends :when. Choose a plan before then, or its sites will be paused.', ['org' => $name, 'when' => $when]))
                ->action(__('Billing'), $billing),
            'paused' => $mail
                ->subject(__(':org is paused', ['org' => $name]))
                ->line(__('The trial for :org has ended without a plan, so its sites are paused and their visitors see a “paused” page.', ['org' => $name]))
                ->line(__('Your apps, databases and settings are kept for :keep days. Choose a plan to bring everything back.', ['keep' => $keep]))
                ->action(__('Choose a plan'), $billing),
            'deleting' => $mail
                ->subject(__(':org will be deleted :when', ['org' => $name, 'when' => $when]))
                ->line(__(':org has been paused since its trial ended. Its sites, databases and stored data will be deleted :when.', ['org' => $name, 'when' => $when]))
                ->line(__('Choose a plan before then to keep everything.'))
                ->action(__('Choose a plan'), $billing),
        };
    }
}
