<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\AuditLog;

/**
 * Plain-English rendering of an audit row for the org Activity timeline:
 * a sentence read after the actor's name, and `field old → new` chips.
 *
 * Lives outside the Livewire component on purpose: public methods on a
 * component are callable from the browser, and these take an AuditLog.
 */
final class AuditSentence
{
    /**
     * Plain-English verb phrase per action code, read after the actor's name
     * ("TJ Shafer created the app blog"). `:subject` is swapped for the
     * subject's name, or dropped when there is none. Unmapped codes fall back
     * to the AuditActionMeta label in {@see self::sentence}, so a row is never blank.
     *
     * @var array<string, string>
     */
    private const SENTENCES = [
        'site.edge.created' => 'created the app :subject',
        'site.edge.deleted' => 'deleted the app :subject',
        'site.deleted' => 'deleted the app :subject',
        'site.edge.deletion_scheduled' => 'scheduled the app :subject for deletion',
        'site.edge.paused' => 'paused :subject',
        'site.edge.resumed' => 'resumed :subject',
        'site.edge.deployment.cancelled' => 'cancelled a deploy of :subject',
        'site.edge.preview.promoted' => 'promoted a preview of :subject to production',
        'site.edge.origin.updated' => 'changed the origin of :subject',
        'site.edge.origin.secret_rotated' => 'rotated the origin secret for :subject',
        'site.edge.images.saved' => 'updated image settings for :subject',
        'site.edge.images.disabled' => 'turned off image optimization for :subject',
        'site.edge.images.secret_rotated' => 'rotated the images secret for :subject',
        'site.edge.preview_protection.updated' => 'changed preview protection on :subject',
        'site.edge.comment_widget.enabled' => 'turned on preview comments for :subject',
        'site.edge.comment_widget.disabled' => 'turned off preview comments for :subject',
        'site.edge.cache.purged_by_tag' => 'purged the cache for :subject',
        'site.edge.cache.purge_tag' => 'purged the cache for :subject',
        'site.edge.cache.purge_paths' => 'purged cached paths for :subject',
        'site.edge.deploy_hook.created' => 'created a deploy hook for :subject',
        'site.edge.deploy_hook.revoked' => 'revoked a deploy hook for :subject',
        'site.edge.converted_to_hybrid' => 'converted :subject to hybrid',
        'site.edge.member.added' => 'gave someone access to :subject',
        'site.edge.member.removed' => 'removed someone’s access to :subject',
        'site.edge.member.role_updated' => 'changed someone’s role on :subject',
        'site.deploy_contract.run' => 'ran the deploy contract for :subject',
        'site.deploy_contract.waived' => 'waived the deploy contract for :subject',
        'database.created' => 'created the database :subject',
        'database.deleted' => 'deleted the database :subject',
        'queue.created' => 'created the queue :subject',
        'queue.deleted' => 'deleted the queue :subject',
        'team.created' => 'created the team :subject',
        'team.updated' => 'updated the team :subject',
        'team.deleted' => 'deleted the team :subject',
        'team.member_added' => 'added someone to the team :subject',
        'team.member_removed' => 'removed someone from the team :subject',
        'api_token.created' => 'created the API token :subject',
        'api_token.revoked' => 'revoked the API token :subject',
        'api_token.device_authorized' => 'signed in the CLI on a device',
        'invitation.sent' => 'invited :subject',
        'invitation.cancelled' => 'cancelled the invitation for :subject',
        'member.role_changed' => 'changed the role of :subject',
        'member.removed' => 'removed :subject from the organization',
        'member.left' => 'left the organization',
        'organization.created' => 'created the organization',
        'organization.updated' => 'updated the organization settings',
        'organization.icon.updated' => 'changed the organization icon',
        'organization.edge_data_region_updated' => 'changed the edge data region',
        'organization.deploy_email_notifications_updated' => 'changed deploy-finish emails',
        'organization.ownership_transferred' => 'transferred ownership to :subject',
        'notification_channel.created' => 'added the notification channel :subject',
        'notification_channel.test_sent' => 'sent a test notification',
        'notification_channel.slack_connected' => 'connected Slack',
        'notification_channel.slack_disconnected' => 'disconnected Slack',
        'notification_channel.discord_connected' => 'connected Discord',
        'notification_channel.discord_disconnected' => 'disconnected Discord',
        'notification_channel.telegram_disconnected' => 'disconnected Telegram',
        'credential.created' => 'added the provider credential :subject',
        'credential.verified' => 'verified the provider credential :subject',
        'credential.verify_failed' => 'could not verify the provider credential :subject',
        'credential.deleted' => 'removed the provider credential :subject',
        'billing.checkout_started' => 'started checkout',
        'billing.portal_accessed' => 'opened the billing portal',
        'billing.plan_changed' => 'changed the plan',
        'billing.subscription_canceled' => 'cancelled the subscription',
        'billing.subscription_resumed' => 'resumed the subscription',
        'billing.trial_ended_early' => 'ended the trial early',
        'user.password_changed' => 'changed their password',
        'user.two_factor_enabled' => 'turned on two-factor authentication',
        'user.two_factor_disabled' => 'turned off two-factor authentication',
        'user.passkey_removed' => 'removed a passkey',
        'user.oauth_unlinked' => 'unlinked a sign-in account',
        'user.account_deleted' => 'deleted their account',
    ];

    /**
     * The row's plain-English sentence, minus the actor (the view bolds that).
     * Unmapped codes return the AuditActionMeta label plus the subject summary.
     *
     * @return array{text: string, mapped: bool}
     */
    public static function sentence(AuditLog $log): array
    {
        $template = self::SENTENCES[(string) $log->action] ?? null;
        if ($template === null) {
            $label = AuditActionMeta::meta((string) $log->action)['label'];
            $summary = $log->subject_summary;

            return ['text' => $summary ? $label.' — '.$summary : $label, 'mapped' => false];
        }

        $name = self::subjectName($log);
        $text = $name !== null
            ? str_replace(':subject', $name, $template)
            : trim(str_replace(' :subject', '', $template));

        return ['text' => $text, 'mapped' => true];
    }

    /**
     * Changed fields worth an inline `field old → new` chip: keys recorded on
     * both sides with different scalar values, plus billing's from/to pair.
     *
     * @return list<array{field: string, from: string, to: string}>
     */
    public static function changes(AuditLog $log): array
    {
        $old = (array) ($log->old_values ?? []);
        $new = (array) ($log->new_values ?? []);

        if (! $old && isset($new['from'], $new['to']) && is_scalar($new['from']) && is_scalar($new['to'])) {
            return [['field' => 'plan', 'from' => (string) $new['from'], 'to' => (string) $new['to']]];
        }

        $show = fn (mixed $v): string => match (true) {
            $v === null || $v === '' => '—',
            is_bool($v) => $v ? 'on' : 'off',
            default => (string) $v,
        };

        $out = [];
        foreach ($new as $field => $to) {
            if (! array_key_exists($field, $old)) {
                continue;
            }
            $from = $old[$field];
            if ((! is_scalar($from) && $from !== null) || (! is_scalar($to) && $to !== null) || $from === $to) {
                continue;
            }
            $out[] = ['field' => str_replace('_', ' ', (string) $field), 'from' => $show($from), 'to' => $show($to)];
        }

        return array_slice($out, 0, 3);
    }

    private static function subjectName(AuditLog $log): ?string
    {
        $subject = $log->subject_type && $log->subject_id ? $log->subject : null;

        foreach ([$subject?->getAttribute('name'), $subject?->getAttribute('email')] as $candidate) {
            if (is_string($candidate) && $candidate !== '') {
                return $candidate;
            }
        }

        foreach (['new_values', 'old_values'] as $side) {
            foreach (['name', 'email', 'token_name'] as $key) {
                $candidate = $log->{$side}[$key] ?? null;
                if (is_string($candidate) && $candidate !== '') {
                    return $candidate;
                }
            }
        }

        return null;
    }

}
