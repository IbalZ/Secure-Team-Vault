<?php

namespace App\Http\Controllers;

use App\Models\CredentialAccess;
use App\Models\User;
use App\Models\VaultCredential;
use App\Services\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class CredentialAccessController extends Controller
{
    public function update(
        Request $request,
        VaultCredential $credential,
        AuditLogger $audit,
    ): RedirectResponse {
        $this->authorize('manageAccess', $credential);

        // Expired dates on unchecked rows must not block revocation. Only a
        // selected grant has an effective expiry or capability set.
        $submittedGrants = $request->input('grants', []);
        if (is_array($submittedGrants)) {
            foreach ($submittedGrants as &$grant) {
                if (is_array($grant) && ! filter_var($grant['selected'] ?? false, FILTER_VALIDATE_BOOL)) {
                    $grant['expires_at'] = null;
                    $grant['can_reveal_secret'] = false;
                    $grant['can_edit'] = false;
                    $grant['can_manage_access'] = false;
                }
            }
            unset($grant);

            $request->merge(['grants' => $submittedGrants]);
        }

        $validated = $request->validate([
            'grants' => ['nullable', 'array'],
            'grants.*.selected' => ['nullable', 'boolean'],
            'grants.*.can_reveal_secret' => ['nullable', 'boolean'],
            'grants.*.can_edit' => ['nullable', 'boolean'],
            'grants.*.can_manage_access' => ['nullable', 'boolean'],
            'grants.*.expires_at' => ['nullable', 'date', 'after:now'],
        ]);

        $submitted = collect($validated['grants'] ?? []);
        $eligibleUsers = User::query()
            ->whereKeyNot($credential->owner_id)
            ->where('is_active', true)
            ->where(function ($query) {
                $query->whereNull('account_expires_at')->orWhere('account_expires_at', '>', now());
            })
            ->get()
            ->keyBy(fn (User $user) => (string) $user->id);

        $selectedIds = $submitted
            ->filter(fn ($grant, $userId) => $eligibleUsers->has((string) $userId) && filter_var($grant['selected'] ?? false, FILTER_VALIDATE_BOOL))
            ->keys()
            ->map(fn ($id) => (int) $id)
            ->values();

        DB::transaction(function () use ($request, $credential, $audit, $submitted, $eligibleUsers, $selectedIds) {
            $revokedIds = CredentialAccess::query()
                ->where('vault_credential_id', $credential->id)
                ->whereNull('revoked_at')
                ->whereNotIn('user_id', $selectedIds)
                ->pluck('user_id');

            CredentialAccess::query()
                ->where('vault_credential_id', $credential->id)
                ->whereNull('revoked_at')
                ->whereNotIn('user_id', $selectedIds)
                ->update([
                    'revoked_at' => now(),
                    'revoked_by' => $request->user()->id,
                    'updated_at' => now(),
                ]);

            $grantedIds = [];

            foreach ($selectedIds as $userId) {
                /** @var User $targetUser */
                $targetUser = $eligibleUsers->get((string) $userId);
                $input = $submitted->get((string) $userId, $submitted->get($userId, []));
                $managerCapabilities = $targetUser->hasRole('manager');

                CredentialAccess::query()->updateOrCreate(
                    [
                        'vault_credential_id' => $credential->id,
                        'user_id' => $targetUser->id,
                    ],
                    [
                        'can_view_metadata' => true,
                        'can_reveal_secret' => filter_var($input['can_reveal_secret'] ?? true, FILTER_VALIDATE_BOOL),
                        'can_edit' => $managerCapabilities && filter_var($input['can_edit'] ?? false, FILTER_VALIDATE_BOOL),
                        'can_manage_access' => $managerCapabilities && filter_var($input['can_manage_access'] ?? false, FILTER_VALIDATE_BOOL),
                        'granted_by' => $request->user()->id,
                        'revoked_by' => null,
                        'expires_at' => $input['expires_at'] ?? null,
                        'revoked_at' => null,
                    ],
                );

                $grantedIds[] = $targetUser->id;
            }

            $audit->record(
                $request->user(),
                'credential.access_updated',
                'vault_credential',
                $credential->uuid,
                ['granted_user_ids' => $grantedIds, 'revoked_user_ids' => $revokedIds->all()],
                $request,
            );
        });

        return redirect()->route('credentials.show', $credential)
            ->with('success', 'Credential access permissions updated.');
    }
}
