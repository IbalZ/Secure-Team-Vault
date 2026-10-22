<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\CredentialAccess;
use App\Models\User;
use App\Models\VaultCredential;
use App\Services\AuditChainVerifier;
use App\Services\VaultCryptoService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class UserRoleSeparationTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'Fictional-Role-Test!47';

    public static function newRoles(): array
    {
        return ['intern' => ['intern'], 'freelance' => ['freelance']];
    }

    public static function restrictedRoles(): array
    {
        return ['staff' => ['staff'], ...self::newRoles()];
    }

    public static function unusableAccounts(): array
    {
        $cases = [];
        foreach (self::restrictedRoles() as $name => [$role]) {
            $cases[$name.' inactive'] = [$role, 'inactive'];
            $cases[$name.' expired'] = [$role, 'expired'];
        }

        return $cases;
    }

    public function test_administrator_forms_offer_five_separate_roles(): void
    {
        $admin = $this->user('admin');
        $staff = $this->user('staff');

        foreach ([route('admin.users.create'), route('admin.users.edit', $staff)] as $url) {
            $response = $this->actingAs($admin)->get($url)->assertOk();
            foreach (UserRole::cases() as $role) {
                $response->assertSee('value="'.$role->value.'"', false)
                    ->assertSee($role->label());
            }
            $response->assertDontSee('Staff / Intern');
        }
    }

    #[DataProvider('newRoles')]
    public function test_administrator_can_create_new_roles_and_audit_the_change(string $role): void
    {
        $admin = $this->user('admin');

        $this->actingAs($admin)->post(route('admin.users.store'), [
            'name' => 'Fictional '.ucfirst($role),
            'email' => $role.'@role-test.example',
            'role' => $role,
            'is_active' => '1',
            'account_expires_at' => now()->addMonth()->format('Y-m-d\TH:i'),
            'password' => self::PASSWORD,
            'password_confirmation' => self::PASSWORD,
        ])->assertSessionHasNoErrors()->assertRedirect(route('admin.users.index'));

        $created = User::query()->where('email', $role.'@role-test.example')->sole();
        $this->assertSame(UserRole::from($role), $created->role);
        $this->assertNotNull($created->account_expires_at);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'user.created', 'actor_user_id' => $admin->id,
            'target_id' => (string) $created->id,
        ]);
        $this->assertValidAuditChain();
    }

    #[DataProvider('newRoles')]
    public function test_administrator_can_reclassify_one_staff_account_without_changing_other_staff(string $role): void
    {
        $admin = $this->user('admin');
        $staff = $this->user('staff');
        $otherStaff = $this->user('staff');

        $this->actingAs($admin)->put(route('admin.users.update', $staff), [
            'name' => $staff->name, 'email' => $staff->email,
            'role' => $role, 'is_active' => '1', 'account_expires_at' => null,
        ])->assertSessionHasNoErrors()->assertRedirect(route('admin.users.index'));

        $this->assertSame(UserRole::from($role), $staff->fresh()->role);
        $this->assertSame(UserRole::Staff, $otherStaff->fresh()->role);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'user.updated', 'target_id' => (string) $staff->id,
        ]);
    }

    #[DataProvider('restrictedRoles')]
    public function test_restricted_roles_can_login_with_distinct_dashboard_and_navigation_labels(string $role): void
    {
        $user = $this->user($role);

        $this->post(route('login'), ['email' => $user->email, 'password' => self::PASSWORD])
            ->assertRedirect('/home');
        $this->assertAuthenticatedAs($user);
        $this->get(route('dashboard'))->assertOk()
            ->assertSee('Your access level is '.UserRole::from($role)->label().'.')
            ->assertDontSee('Staff / Intern')
            ->assertDontSee('User management')
            ->assertDontSee('Audit logs')
            ->assertDontSee('Add credential');
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'auth.login_succeeded', 'actor_role' => $role,
        ]);
    }

    #[DataProvider('restrictedRoles')]
    public function test_restricted_roles_cannot_administer_create_or_access_unshared_credentials(string $role): void
    {
        $user = $this->user($role);
        $credential = $this->credential($this->user('manager'));

        $this->actingAs($user)->get(route('admin.users.index'))->assertForbidden();
        $this->get(route('admin.users.create'))->assertForbidden();
        $this->post(route('admin.users.store'), ['role' => 'admin'])->assertForbidden();
        $this->get(route('admin.audit.index'))->assertForbidden();
        $this->get(route('credentials.create'))->assertForbidden();
        $this->post(route('credentials.store'), [])->assertForbidden();
        $this->get(route('credentials.index'))->assertOk()->assertDontSee($credential->title);
        $this->get(route('credentials.show', $credential))->assertForbidden();
        $this->postJson(route('credentials.reveal', $credential))->assertForbidden();
        $this->postJson(route('credentials.copy', $credential))->assertForbidden();
    }

    #[DataProvider('restrictedRoles')]
    public function test_granted_restricted_roles_can_reveal_but_cannot_gain_edit_or_sharing_permissions(string $role): void
    {
        $manager = $this->user('manager');
        $user = $this->user($role);
        $credential = $this->credential($manager);

        // The server must discard forged manager capabilities, not just hide checkboxes.
        $this->actingAs($manager)->put(route('credentials.access.update', $credential), [
            'grants' => [$user->id => [
                'selected' => '1', 'can_reveal_secret' => '1',
                'can_edit' => '1', 'can_manage_access' => '1',
            ]],
        ])->assertRedirect(route('credentials.show', $credential));

        $grant = CredentialAccess::query()->where('user_id', $user->id)->sole();
        $this->assertTrue($grant->can_view_metadata);
        $this->assertTrue($grant->can_reveal_secret);
        $this->assertFalse($grant->can_edit);
        $this->assertFalse($grant->can_manage_access);
        $this->actingAs($user)->get(route('credentials.index'))->assertOk()->assertSee($credential->title);
        $this->get(route('credentials.show', $credential))->assertOk()
            ->assertDontSee('Team access')->assertDontSee('May manage access');

        // Existing or manually set grant flags must not override the restricted role.
        $grant->update(['can_edit' => true, 'can_manage_access' => true]);
        $this->get(route('credentials.edit', $credential))->assertForbidden();
        $this->put(route('credentials.update', $credential), [])->assertForbidden();
        $this->delete(route('credentials.destroy', $credential))->assertForbidden();
        $this->put(route('credentials.access.update', $credential), ['grants' => []])->assertForbidden();

        $this->withSession(['auth.password_confirmed_at' => null])
            ->postJson(route('credentials.reveal', $credential))->assertStatus(428);
        $this->withSession(['auth.password_confirmed_at' => time()])
            ->postJson(route('credentials.reveal', $credential))->assertOk()
            ->assertJsonPath('password', self::PASSWORD)
            ->assertHeader('Cache-Control', 'max-age=0, no-store, private');
        $this->postJson(route('credentials.copy', $credential))->assertOk()
            ->assertJsonPath('password', self::PASSWORD);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'credential.secret_revealed', 'actor_user_id' => $user->id, 'actor_role' => $role,
        ]);

        $grant->update(['can_reveal_secret' => false]);
        $this->get(route('credentials.show', $credential))->assertOk();
        $this->postJson(route('credentials.reveal', $credential))->assertForbidden();
        $this->postJson(route('credentials.copy', $credential))->assertForbidden();
        $this->assertValidAuditChain();
    }

    #[DataProvider('restrictedRoles')]
    public function test_expired_and_revoked_grants_block_restricted_roles(string $role): void
    {
        $manager = $this->user('manager');
        $user = $this->user($role);
        $credential = $this->credential($manager);
        $grant = CredentialAccess::create([
            'vault_credential_id' => $credential->id, 'user_id' => $user->id,
            'granted_by' => $manager->id, 'can_view_metadata' => true,
            'can_reveal_secret' => true, 'expires_at' => now()->subMinute(),
        ]);

        $this->actingAs($user)->get(route('credentials.index'))->assertOk()->assertDontSee($credential->title);
        $this->get(route('credentials.show', $credential))->assertForbidden();
        $this->postJson(route('credentials.reveal', $credential))->assertForbidden();
        $grant->update(['expires_at' => null, 'revoked_at' => now(), 'revoked_by' => $manager->id]);
        $this->get(route('credentials.show', $credential))->assertForbidden();
        $this->postJson(route('credentials.copy', $credential))->assertForbidden();
    }

    #[DataProvider('unusableAccounts')]
    public function test_inactive_or_expired_restricted_accounts_cannot_login(string $role, string $condition): void
    {
        $user = $this->user($role, [
            'is_active' => $condition !== 'inactive',
            'account_expires_at' => $condition === 'expired' ? now()->subMinute() : null,
        ]);

        $this->post(route('login'), ['email' => $user->email, 'password' => self::PASSWORD])
            ->assertSessionHasErrors('email');
        $this->assertGuest();
    }

    private function user(string $role, array $attributes = []): User
    {
        return User::factory()->create([
            'role' => UserRole::from($role), 'password' => self::PASSWORD,
            'is_active' => true, 'account_expires_at' => null, ...$attributes,
        ]);
    }

    private function credential(User $owner): VaultCredential
    {
        $uuid = (string) Str::uuid();

        return VaultCredential::create([
            'uuid' => $uuid, 'title' => 'Fictional restricted role credential',
            'owner_id' => $owner->id, 'updated_by' => $owner->id, 'lock_version' => 1,
            ...app(VaultCryptoService::class)->encrypt([
                'username' => 'fictional@example.test', 'password' => self::PASSWORD,
                'notes' => 'Isolated role regression test only.',
            ], $uuid),
        ]);
    }

    private function assertValidAuditChain(): void
    {
        $result = app(AuditChainVerifier::class)->verify();
        $this->assertTrue($result['valid'], json_encode($result['errors']));
    }
}
