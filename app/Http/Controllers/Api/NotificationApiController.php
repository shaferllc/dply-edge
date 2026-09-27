<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\NotificationChannel;
use App\Models\Organization;
use App\Models\Server;
use App\Models\Site;
use App\Models\User;
use App\Modules\Notifications\Services\AssignableNotificationChannels;
use App\Support\Notifications\NotificationEventScopes;
use App\Support\NotificationSubscriptionMatrix;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;

/**
 * Notification channels and per-subject event routing, for the CLI.
 *
 * Reads and writes go through the same {@see NotificationSubscriptionMatrix}
 * and {@see AssignableNotificationChannels} the workspace tabs use, so a
 * subscription made from a terminal is indistinguishable from one made in the
 * browser. What differs is breadth: the UI manages one feature's keys per tab,
 * while these endpoints hand back every group that applies to the subject.
 */
class NotificationApiController extends Controller
{
    /** Channels this token's user may route events to. */
    public function channels(Request $request): JsonResponse
    {
        $channels = $this->assignableChannels($request);

        return response()->json([
            'data' => $channels->map(fn (NotificationChannel $channel) => [
                'id' => (string) $channel->id,
                'label' => $channel->label,
                'type' => $channel->type,
                'destination' => $channel->describeDestination(),
                'owner' => class_basename((string) $channel->owner_type),
            ])->values(),
        ]);
    }

    /** The event catalog: every group, or the ones that apply to a subject. */
    public function events(Request $request): JsonResponse
    {
        $subject = strtolower(trim((string) $request->query('subject', 'all')));

        $groups = match ($subject) {
            'site' => NotificationEventScopes::forSite(new Site),
            'server' => NotificationEventScopes::forServer(new Server),
            default => NotificationEventScopes::all(),
        };

        return response()->json(['data' => $this->shapeGroups($groups)]);
    }

    /** Send the channel's own test message — the API face of the "Test" button. */
    public function test(Request $request, string $channel): JsonResponse
    {
        $found = $this->assignableChannels($request)->first(fn (NotificationChannel $c) => (string) $c->id === $channel);

        if (! $found instanceof NotificationChannel) {
            return response()->json(['message' => 'No channel with that id is available to this token.'], 404);
        }

        $user = $request->user();
        $result = $found->sendTest($user instanceof User ? $user : null);

        return response()->json([
            'data' => [
                'ok' => (bool) $result['ok'],
                'message' => (string) $result['message'],
            ],
        ], $result['ok'] ? 200 : 422);
    }

    public function siteIndex(Request $request, Site $site): JsonResponse
    {
        $this->assertSiteOwnership($request, $site);

        return $this->subscriptionState($request, $site, NotificationEventScopes::forSite($site));
    }

    public function siteUpdate(Request $request, Site $site): JsonResponse
    {
        $this->assertSiteOwnership($request, $site);

        return $this->applySubscriptionChange($request, $site, NotificationEventScopes::forSite($site));
    }

    public function serverIndex(Request $request, Server $server): JsonResponse
    {
        $this->assertServerOwnership($request, $server);

        return $this->subscriptionState($request, $server, NotificationEventScopes::forServer($server));
    }

    public function serverUpdate(Request $request, Server $server): JsonResponse
    {
        $this->assertServerOwnership($request, $server);

        return $this->applySubscriptionChange($request, $server, NotificationEventScopes::forServer($server));
    }

    /**
     * @param  array<string, array{label: string, events: array<string, string>}>  $groups
     */
    private function subscriptionState(Request $request, Model $subject, array $groups): JsonResponse
    {
        $channels = $this->assignableChannels($request);
        $selections = NotificationSubscriptionMatrix::load(
            $subject::class,
            (string) $subject->getKey(),
            NotificationEventScopes::keys($groups),
            $channels,
        );

        return response()->json([
            'data' => [
                'groups' => $this->shapeGroups($groups),
                'channels' => $channels->map(fn (NotificationChannel $channel) => [
                    'id' => (string) $channel->id,
                    'label' => $channel->label,
                    'type' => $channel->type,
                    'events' => $selections[(string) $channel->id] ?? [],
                ])->values(),
            ],
        ]);
    }

    /**
     * `{"channel": "…", "subscribe": ["site.uptime.down"], "unsubscribe": [...]}`
     *
     * Add/remove rather than replace: two clients editing different events on
     * the same channel must not clobber each other, and a CLI that only knows
     * about the event it was told to route should not silently drop the rest.
     *
     * @param  array<string, array{label: string, events: array<string, string>}>  $groups
     */
    private function applySubscriptionChange(Request $request, Model $subject, array $groups): JsonResponse
    {
        $data = $request->validate([
            'channel' => ['required', 'string', 'max:64'],
            'subscribe' => ['array'],
            'subscribe.*' => ['string', 'max:128'],
            'unsubscribe' => ['array'],
            'unsubscribe.*' => ['string', 'max:128'],
        ]);

        $channels = $this->assignableChannels($request);
        $channel = $channels->first(fn (NotificationChannel $c) => (string) $c->id === (string) $data['channel']);

        if (! $channel instanceof NotificationChannel) {
            return response()->json(['message' => 'No channel with that id is available to this token.'], 404);
        }

        $managed = NotificationEventScopes::keys($groups);
        $add = array_values(array_intersect((array) ($data['subscribe'] ?? []), $managed));
        $remove = array_values(array_intersect((array) ($data['unsubscribe'] ?? []), $managed));
        $unknown = array_values(array_diff(
            array_merge((array) ($data['subscribe'] ?? []), (array) ($data['unsubscribe'] ?? [])),
            $managed,
        ));

        if ($unknown !== []) {
            return response()->json([
                'message' => 'Unknown event for this subject: '.implode(', ', $unknown),
                'events' => $managed,
            ], 422);
        }

        if ($add === [] && $remove === []) {
            return response()->json(['message' => 'Pass at least one event to subscribe or unsubscribe.'], 422);
        }

        $current = NotificationSubscriptionMatrix::load(
            $subject::class,
            (string) $subject->getKey(),
            $managed,
            $channels,
        );

        $next = array_values(array_unique(array_diff(
            array_merge($current[(string) $channel->id] ?? [], $add),
            $remove,
        )));

        $changed = NotificationSubscriptionMatrix::save(
            $subject::class,
            (string) $subject->getKey(),
            $managed,
            // Reconcile only this channel: save() leaves channels it is not
            // given alone, so the other rows in the matrix stay untouched.
            $channels->filter(fn (NotificationChannel $c) => (string) $c->id === (string) $channel->id)->values(),
            [(string) $channel->id => $next],
        );

        return response()->json([
            'data' => [
                'channel' => (string) $channel->id,
                'events' => $next,
                'added' => $changed['added'],
                'removed' => $changed['removed'],
            ],
        ]);
    }

    /**
     * @param  array<string, array{label: string, events: array<string, string>}>  $groups
     * @return list<array{key: string, label: string, events: list<array{key: string, label: string}>}>
     */
    private function shapeGroups(array $groups): array
    {
        $shaped = [];

        foreach ($groups as $key => $group) {
            $events = [];

            foreach ($group['events'] as $eventKey => $label) {
                $events[] = ['key' => (string) $eventKey, 'label' => (string) $label];
            }

            $shaped[] = ['key' => (string) $key, 'label' => $group['label'], 'events' => $events];
        }

        return $shaped;
    }

    /**
     * @return Collection<int, NotificationChannel>
     */
    private function assignableChannels(Request $request): Collection
    {
        $user = $request->user();
        $organization = $request->attributes->get('api_organization');

        if (! $user instanceof User) {
            abort(403);
        }

        return AssignableNotificationChannels::forUser($user, $organization)->values();
    }

    private function assertSiteOwnership(Request $request, Site $site): void
    {
        $organization = $request->attributes->get('api_organization');

        if ($site->server?->organization_id !== $organization?->id) {
            abort(403);
        }
        $this->authorizeTokenUser($request, $site);
    }

    private function assertServerOwnership(Request $request, Server $server): void
    {
        $organization = $request->attributes->get('api_organization');

        if ($server->organization_id !== $organization?->id) {
            abort(403);
        }
        $this->authorizeTokenUser($request, $server);
    }

    /**
     * The token's abilities are not enough: its user must also be allowed on
     * this site/server (reads 'view', writes 'update'), as in the UI.
     */
    private function authorizeTokenUser(Request $request, Model $subject): void
    {
        $user = $request->user();
        $organization = $request->attributes->get('api_organization');
        if (! $user instanceof User || ! $organization instanceof Organization) {
            abort(403);
        }

        $user->rememberCurrentOrganization($organization);
        Gate::forUser($user)->authorize($request->isMethodSafe() ? 'view' : 'update', $subject);
    }
}
