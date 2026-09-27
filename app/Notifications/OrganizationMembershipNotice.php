<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\Organization;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Tells a member their place in an organization changed (ManageOrganizationMembers):
 *
 *   role_changed  an owner or admin gave them a new organization role ($role)
 *   removed       an owner or admin removed them from the organization
 */
class OrganizationMembershipNotice extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public Organization $organization,
        public string $kind,
        public ?string $role = null,
    ) {
        $this->onQueue((string) config('dply.notification_queue', 'default'));
    }

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $name = $this->organization->name;
        $mail = new MailMessage;

        if ($this->kind === 'removed') {
            return $mail
                ->subject(__('You were removed from :org', ['org' => $name]))
                ->line(__('An owner or admin removed you from :org on :app. You no longer have access to its apps, and your API tokens for it have been revoked.', ['org' => $name, 'app' => config('app.name')]))
                ->line(__('If you think this was a mistake, ask an owner of :org to invite you again.', ['org' => $name]));
        }

        $role = (string) $this->role;

        return $mail
            ->subject(__('Your role in :org is now :role', ['org' => $name, 'role' => $role]))
            ->line(__('An owner or admin changed your role in :org to :role.', ['org' => $name, 'role' => $role]))
            ->action(__('Open :org', ['org' => $name]), route('organizations.show', $this->organization))
            ->line(__('See what each role can do in the Roles & permissions docs.'));
    }
}
