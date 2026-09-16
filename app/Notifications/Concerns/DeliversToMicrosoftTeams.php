<?php

declare(strict_types=1);

namespace App\Notifications\Concerns;

use App\Models\NotificationChannel;
use App\Modules\Notifications\Channels\MicrosoftTeams\MicrosoftTeamsMessage;

/**
 * Gives any Notification a Microsoft Teams leg, derived from its toMail().
 *
 * Blanket-applied like DeliversToIntercom rather than opt-in like
 * DeliversToPagerDuty: Teams is a chat room, so an extra message is noise at
 * worst. A pager is not, which is why that trait defaults to silence.
 */
trait DeliversToMicrosoftTeams
{
    public function microsoftTeamsChannelFor(object $notifiable): ?NotificationChannel
    {
        $owners = [$notifiable];

        if (method_exists($notifiable, 'currentOrganization')) {
            $owners[] = $notifiable->currentOrganization();
        }

        foreach ($owners as $owner) {
            if (! is_object($owner) || ! method_exists($owner, 'notificationChannels')) {
                continue;
            }

            $channel = $owner->notificationChannels()
                ->where('type', NotificationChannel::TYPE_MICROSOFT_TEAMS)
                ->first();

            if ($channel instanceof NotificationChannel) {
                return $channel;
            }
        }

        return null;
    }

    /**
     * @return list<string>
     */
    public function viaMicrosoftTeams(object $notifiable): array
    {
        return $this->microsoftTeamsChannelFor($notifiable) !== null ? ['microsoftTeams'] : [];
    }

    public function toMicrosoftTeams(object $notifiable): MicrosoftTeamsMessage
    {
        $channel = $this->microsoftTeamsChannelFor($notifiable);
        $mail = $this->toMail($notifiable);

        $title = $mail->subject !== ''
            ? $mail->subject
            : (string) config('app.name');

        $actionUrl = null;
        $actionLabel = null;

        $lines = array_values(array_filter(
            array_map(static fn ($l): string => trim((string) $l), array_merge($mail->introLines, $mail->outroLines)),
            static fn (string $l): bool => $l !== ''
        ));
        $body = implode("\n\n", $lines);

        if ($mail->actionText !== '' && $mail->actionUrl !== '') {
            $actionUrl = $mail->actionUrl;
            $actionLabel = $mail->actionText;
        }

        if ($channel instanceof NotificationChannel) {
            // Reuse the model's builder so a notification looks identical to an
            // operational alert on the same channel.
            return $channel->microsoftTeamsMessageFor($title, $body, $actionUrl, $actionLabel);
        }

        $message = MicrosoftTeamsMessage::create()->title($title)->summary($title);

        foreach (preg_split('/\R{2,}/', $body) ?: [] as $paragraph) {
            $message->content(trim($paragraph));
        }

        if ($actionUrl !== null) {
            $message->button($actionLabel ?: __('Open in Dply'), $actionUrl);
        }

        return $message;
    }
}
