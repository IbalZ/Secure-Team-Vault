<?php

namespace Tests\Feature;

use App\Models\CredentialAccess;
use App\Models\User;
use App\Models\VaultCredential;
use App\Services\AuditChainVerifier;
use App\Services\VaultCryptoService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class CredentialDeletionTest extends TestCase
{
    use RefreshDatabase;

    public static function permittedDeleters(): array
    {
        return ['admin deleting another owner' => ['admin'], 'manager deleting own' => ['manager']];
    }

    public static function restrictedRoles(): array
    {
        return ['staff' => ['staff'], 'intern' => ['intern'], 'freelance' => ['freelance']];
    }

    #[DataProvider('permittedDeleters')]
    public function test_edit_page_has_separate_confirmed_delete_form_and_deletion_preserves_audit(string $role): void
    {
        $actor = $this->user($role);
        $owner = $role === 'admin' ? $this->user('manager') : $actor;
        $credential = $this->credential($owner);

        $response = $this->actingAs($actor)->get(route('credentials.edit', $credential))->assertOk();
        $response->assertSee('Delete credential')
            ->assertSee('data-confirm="Delete this credential?', false)
            ->assertSee('Archives the credential; audit history is retained.')
            ->assertSee('name="_method" value="DELETE"', false)
            ->assertSee('name="_token"', false)
            ->assertDontSee('Fictional-Delete-Test!42');

        // Keep DELETE and PUT as sibling forms: the delete action must not save edit fields.
        $this->assertMatchesRegularExpression('/<\/form>\s*<\/div>\s*<form[^>]+credentials/s', $response->getContent());
        $this->delete(route('credentials.destroy', $credential))
            ->assertRedirect(route('credentials.index'))
            ->assertSessionHas('success', 'Credential archived.');
        $this->assertSoftDeleted($credential);
        $this->assertSame(1, VaultCredential::withTrashed()->whereKey($credential->id)->count());
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'credential.archived', 'target_id' => $credential->uuid,
            'actor_user_id' => $actor->id, 'actor_role' => $role,
        ]);
        $this->get(route('credentials.index'))->assertOk()->assertDontSee($credential->title);
        $this->get(route('credentials.edit', $credential))->assertNotFound();
        $this->assertTrue(app(AuditChainVerifier::class)->verify()['valid']);
    }

    public function test_manager_with_edit_grant_has_no_delete_button_and_cannot_delete_another_owners_credential(): void
    {
        $owner = $this->user('manager');
        $editor = $this->user('manager');
        $credential = $this->credential($owner);
        CredentialAccess::create([
            'vault_credential_id' => $credential->id, 'user_id' => $editor->id,
            'granted_by' => $owner->id, 'can_view_metadata' => true, 'can_edit' => true,
        ]);

        $this->actingAs($editor)->get(route('credentials.edit', $credential))
            ->assertOk()->assertDontSee('Delete credential')->assertDontSee('value="DELETE"', false);
        $this->delete(route('credentials.destroy', $credential))->assertForbidden();
        $this->assertNotSoftDeleted($credential);
        $this->assertDatabaseMissing('audit_logs', ['action' => 'credential.archived']);
    }

    public function test_unshared_manager_cannot_edit_or_delete_another_owners_credential(): void
    {
        $credential = $this->credential($this->user('manager'));
        $this->actingAs($this->user('manager'))->get(route('credentials.edit', $credential))->assertForbidden();
        $this->delete(route('credentials.destroy', $credential))->assertForbidden();
        $this->assertNotSoftDeleted($credential);
    }

    #[DataProvider('restrictedRoles')]
    public function test_restricted_roles_cannot_delete_even_with_ownership_or_forged_edit_grants(string $role): void
    {
        $actor = $this->user($role);
        $credential = $this->credential($actor);
        CredentialAccess::create([
            'vault_credential_id' => $credential->id, 'user_id' => $actor->id,
            'granted_by' => $actor->id, 'can_view_metadata' => true, 'can_edit' => true,
        ]);
        $this->actingAs($actor)->get(route('credentials.edit', $credential))->assertForbidden();
        $this->delete(route('credentials.destroy', $credential))->assertForbidden();
        $this->assertNotSoftDeleted($credential);
        $this->assertDatabaseMissing('audit_logs', ['action' => 'credential.archived']);
    }

    public function test_guest_cannot_delete_a_credential(): void
    {
        $credential = $this->credential($this->user('manager'));
        $this->delete(route('credentials.destroy', $credential))->assertRedirect(route('login'));
        $this->assertNotSoftDeleted($credential);
    }

    public function test_inactive_admin_and_expired_manager_cannot_delete(): void
    {
        foreach ([['admin', false, null], ['manager', true, now()->subMinute()]] as [$role, $active, $expiry]) {
            $actor = $this->user($role, ['is_active' => $active, 'account_expires_at' => $expiry]);
            $credential = $this->credential($actor);
            $this->actingAs($actor)->delete(route('credentials.destroy', $credential))
                ->assertRedirect(route('login'))->assertSessionHasErrors('email');
            $this->assertGuest();
            $this->assertNotSoftDeleted($credential);
        }
    }

    private function user(string $role, array $attributes = []): User
    {
        return User::factory()->create([
            'role' => $role, 'is_active' => true, 'account_expires_at' => null, ...$attributes,
        ]);
    }

    private function credential(User $owner): VaultCredential
    {
        $uuid = (string) Str::uuid();
        return VaultCredential::create([
            'uuid' => $uuid, 'title' => 'Fictional delete regression record',
            'owner_id' => $owner->id, 'updated_by' => $owner->id, 'lock_version' => 1,
            ...app(VaultCryptoService::class)->encrypt([
                'username' => 'fictional@example.test', 'password' => 'Fictional-Delete-Test!42',
            ], $uuid),
        ]);
    }
}
