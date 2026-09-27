<?php

namespace App\Livewire\Profile;

use App\Actions\Organizations\DeleteOrganizationAction;
use App\Models\Organization;
use App\Models\User;
use App\Modules\Billing\Jobs\SyncOrganizationBillingJob;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Session;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.settings')]
class DeleteAccount extends Component
{
    public string $delete_password = '';

    public function deleteAccount(): void
    {
        $this->validate([
            'delete_password' => ['required', 'current_password'],
        ], [], ['delete_password' => __('password')]);

        $user = auth()->user();
        $org = $user->currentOrganization();
        $snapshot = ['user_id' => (string) $user->id, 'email' => $user->email, 'name' => $user->name];

        $soloOrgs = $this->soleOwnedOrganizations($user);
        $otherOrgIds = $user->organizations()->whereNotIn('organizations.id', $soloOrgs->pluck('id'))->pluck('organizations.id')->all();

        // Organizations only this user is in go with the account, on the same
        // terms as deleting one from General settings (no apps, no paid plan).
        // Guard every one before deleting any.
        $deleteOrg = app(DeleteOrganizationAction::class);
        foreach ($soloOrgs as $solo) {
            try {
                $deleteOrg->guard($solo, $user, requireAnotherOrganization: false);
            } catch (ValidationException $e) {
                throw ValidationException::withMessages([
                    'delete_password' => __(':org is deleted with your account, since you are its only member, but it can\'t be deleted yet. :reason', ['org' => $solo->name, 'reason' => collect($e->errors())->flatten()->first()]),
                ]);
            }
        }
        if ($org && ! $soloOrgs->contains($org)) {
            audit_log($org, $user, 'user.account_deleted', null, $snapshot, null);
        }
        foreach ($soloOrgs as $solo) {
            $deleteOrg->handle($solo, $user, requireAnotherOrganization: false);
        }

        Auth::logout();
        $user->delete();
        // Their seat, API tokens and app roles cascade with the user row.
        foreach ($otherOrgIds as $orgId) {
            SyncOrganizationBillingJob::dispatch($orgId, 'member_removed');
        }
        Session::invalidate();
        Session::regenerateToken();

        $this->redirect('/', navigate: true);
    }

    /**
     * Organizations the user is the only member of. Refuses (validation error)
     * when the user is the only owner of an organization that has other members.
     *
     * @return Collection<int, Organization>
     */
    private function soleOwnedOrganizations(User $user): Collection
    {
        $solo = collect();
        foreach ($user->organizations()->wherePivot('role', 'owner')->withCount('users')->get() as $org) {
            if ($org->users_count <= 1) {
                $solo->push($org);

                continue;
            }
            if ($org->users()->wherePivot('role', 'owner')->count() <= 1) {
                throw ValidationException::withMessages([
                    'delete_password' => __('You are the only owner of :org, which has other members. Make one of them owner (Members → Make owner) or remove them before deleting your account.', ['org' => $org->name]),
                ]);
            }
        }

        return $solo;
    }

    public function render(): View
    {
        return view('livewire.profile.delete-account');
    }
}
