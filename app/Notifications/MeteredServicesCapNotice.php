<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\Organization;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** AI, browser rendering and vector search passed $percent% of the org's monthly cap (EdgeMeter::alert). */
class MeteredServicesCapNotice extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public Organization $organization,
        public int $percent,
        public int $usedCents,
        public int $capCents,
    ) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $money = static fn (int $cents): string => '$'.number_format($cents / 100, 2);
        $reached = $this->percent >= 100;

        return (new MailMessage)
            ->subject($reached
                ? __(':org reached its AI, browser and vector search limit', ['org' => $this->organization->name])
                : __(':org has used :pct% of its AI, browser and vector search limit', ['org' => $this->organization->name, 'pct' => $this->percent]))
            ->line(__(':org has used :used of its :cap monthly limit for AI, browser rendering and vector search.', [
                'org' => $this->organization->name,
                'used' => $money($this->usedCents),
                'cap' => $money($this->capCents),
            ]))
            ->line($reached
                ? __('Calls to these services are refused until the next billing period or until an owner raises the limit.')
                : __('At the limit, calls to these services are refused until the next billing period. Owners can change the limit on the billing page.'))
            ->action(__('Billing'), route('subscription.show', $this->organization));
    }
}
