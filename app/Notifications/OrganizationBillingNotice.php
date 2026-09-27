<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\Organization;
use Carbon\CarbonInterface;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Trial and pause emails to an org's owners (ruling r-f17p5zgeh120cm5t),
 * sent once each by EnforceOrganizationBillingCommand:
 *
 *   trial_started   the trial began (or an existing Free org got one)
 *   trial_ending_soon  3 days before the trial ends
 *   trial_ending    about a day before the trial ends
 *   capped          the trial hit its spending cap; sites are paused
 *   paused          the trial ended unpaid; sites are paused
 *   deleting_soon   the paused org's data is deleted on $date (7 days out)
 *   deleting        the paused org's data is deleted on $date (1 day out)
 */
class OrganizationBillingNotice extends Notification implements ShouldQueue
{
    use Queueable;

    public const KINDS = ['trial_started', 'trial_ending_soon', 'trial_ending', 'capped', 'paused', 'deleting_soon', 'deleting'];

    public function __construct(
        public Organization $organization,
        public string $kind,
        public ?CarbonInterface $date = null,
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
        $keep = (int) config('subscription.standard.trial.keep_data_days', 30);
        // The trial is of the plan chosen at checkout (a card-less trial: trial.tier).
        $plan = $this->organization->planTierLabel();
        $price = '$'.number_format(((int) ($this->organization->tierAllowances()['price_cents'] ?? 0)) / 100, 0).'/mo';
        $mail = new MailMessage;

        return match ($this->kind) {
            'trial_started' => $mail
                ->subject(__('Your :days-day dply trial has started', ['days' => $days]))
                ->line(__(':org is on a :days-day trial of :plan, until :when.', ['org' => $name, 'days' => $days, 'plan' => $plan, 'when' => $when]))
                ->line($this->organization->subscription('default')?->onTrial()
                    ? __('It becomes :plan (:price) when the trial ends unless you cancel before then.', ['plan' => $plan, 'price' => $price])
                    : __('dply no longer has a free plan. Choose a plan before the trial ends to keep your sites running.'))
                ->action(__('Billing'), $billing),
            'trial_ending_soon' => $mail
                ->subject(__('3 days left on your dply trial'))
                ->line($this->organization->subscription('default')?->onTrial()
                    ? __('The trial for :org ends :when, and :plan starts billing then (:price). Cancel before then if you don’t want to continue.', ['org' => $name, 'when' => $when, 'plan' => $plan, 'price' => $price])
                    : __('The trial for :org ends :when. Choose a plan before then to keep its sites running.', ['org' => $name, 'when' => $when]))
                ->action(__('Billing'), $billing),
            'trial_ending' => $mail
                ->subject(__('Your dply trial ends :when', ['when' => $when]))
                ->line($this->organization->subscription('default')?->onTrial()
                    ? __('The trial for :org ends :when, and :plan starts billing then. Cancel before then if you don’t want to continue.', ['org' => $name, 'when' => $when, 'plan' => $plan])
                    : __('The trial for :org ends :when. Choose a plan before then, or its sites will be paused.', ['org' => $name, 'when' => $when]))
                ->action(__('Billing'), $billing),
            'capped' => $mail
                ->subject(__(':org is paused: trial usage cap reached', ['org' => $name]))
                ->line(__('The trial for :org has used its $:limit usage cap, so its sites are paused and their visitors see a “paused” page.', ['org' => $name, 'limit' => number_format(((int) config('subscription.standard.trial.spending_limit_cents', 500)) / 100, 0)]))
                ->line($this->organization->subscription('default')?->onTrial()
                    ? __('Choose End trial now on the billing page to start paying today and resume, or wait for the plan to start on :when.', ['when' => $when])
                    : __('To resume today, add a card for your plan on the billing page, then choose End trial now. Otherwise its sites stay paused until the trial ends on :when.', ['when' => $when]))
                ->action(__('Billing'), $billing),
            'paused' => $mail
                ->subject(__(':org is paused', ['org' => $name]))
                ->line(__('The trial for :org has ended without a plan, so its sites are paused and their visitors see a “paused” page.', ['org' => $name]))
                ->line(__('Your apps, databases and settings are kept for :keep days. Choose a plan to bring everything back.', ['keep' => $keep]))
                ->action(__('Choose a plan'), $billing),
            'deleting_soon', 'deleting' => $mail
                ->subject(__(':org will be deleted :when', ['org' => $name, 'when' => $when]))
                ->line(__(':org has been paused without a plan. Its sites, databases and stored data will be deleted :when.', ['org' => $name, 'when' => $when]))
                ->line(__('Choose a plan before then to keep everything.'))
                ->action(__('Choose a plan'), $billing),
        };
    }
}
