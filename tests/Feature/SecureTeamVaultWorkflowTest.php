<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\CredentialAccess;
use App\Models\User;
use App\Models\VaultCredential;
use App\Services\AuditChainVerifier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class SecureTeamVaultWorkflowTest extends TestCase
{
    use RefreshDatabase;

    private const LOGIN_PASSWORD = 'Strong-Test-Password!42';

    public function test_public_registration_is_disabled_and_guests_are_sent_to_login(): void
    {
        $this->get('/register')->assertNotFound();
        $this->post('/register')->assertNotFound();
        $this->get('/password/reset')->assertNotFound();
        $this->get(route('dashboard'))->assertRedirect(route('login'));
        $this->get(route('login'))
            ->assertOk()
            ->assertHeader('X-Frame-Options', 'DENY')
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('Referrer-Policy', 'no-referrer');
    }

    public function test_active_user_login_and_logout_are_audited_while_expired_accounts_are_rejected(): void
    {
        $active = $this->user(UserRole::Staff, [
            'email' => 'active@example.test',
            'password' => Hash::make(self::LOGIN_PASSWORD),
        ]);

        $this->post(route('login'), [
            'email' => $active->email,
            'password' => self::LOGIN_PASSWORD,
        ])->assertRedirect('/home');

        $this->assertAuthenticatedAs($active);
        $this->assertDatabaseHas('audit_logs', [
            'actor_user_id' => $active->id,
            'action' => 'auth.login_succeeded',
        ]);

        $this->post(route('logout'))->assertRedirect('/');
        $this->assertGuest();
        $this->assertDatabaseHas('audit_logs', [
            'actor_user_id' => $active->id,
            'action' => 'auth.logout',
        ]);

        $expired = $this->user(UserRole::Staff, [
            'email' => 'expired@example.test',
            'password' => Hash::make(self::LOGIN_PASSWORD),
            'account_expires_at' => now()->subMinute(),
        ]);

        $this->post(route('login'), [
            'email' => $expired->email,
            'password' => self::LOGIN_PASSWORD,
        ])->assertSessionHasErrors('email');

        $this->assertGuest();
        $this->assertDatabaseHas('audit_logs', [
            'actor_user_id' => $expired->id,
            'action' => 'auth.login_rejected',
        ]);

        $this->post(route('login'), [
            'email' => 'unknown@example.test',
            'password' => self::LOGIN_PASSWORD,
        ])->assertSessionHasErrors('email');
        $this->assertDatabaseHas('audit_logs', [
            'actor_user_id' => null,
            'action' => 'auth.login_failed',
        ]);
    }

    public function test_role_boundaries_protect_administration_and_credential_creation(): void
    {
        $admin = $this->user(UserRole::Admin);
        $manager = $this->user(UserRole::Manager);
        $staff = $this->user(UserRole::Staff);

        $this->actingAs($admin)->get(route('admin.users.index'))->assertOk();
        $this->actingAs($manager)->get(route('admin.users.index'))->assertForbidden();
        $this->actingAs($staff)->get(route('credentials.create'))->assertForbidden();
        $this->actingAs($manager)->get(route('credentials.create'))->assertOk();
    }

    public function test_credential_is_encrypted_shared_by_grant_and_revealed_only_after_confirmation(): void
    {
        $manager = $this->user(UserRole::Manager);
        $staff = $this->user(UserRole::Staff);
        $unassignedStaff = $this->user(UserRole::Staff);

        $plainPassword = 'Fictional-Social-Password!789';
        $this->actingAs($manager)->post(route('credentials.store'), [
            'title' => 'Demonstration social account',
            'category' => 'Social Media',
            'website_url' => 'https://example.com',
            'vault_username' => 'demo-team@example.test',
            'vault_password' => $plainPassword,
            'vault_notes' => 'Fictional FYP demonstration record.',
        ])->assertRedirect();

        $credential = VaultCredential::query()->sole();
        $stored = $credential->encrypted_payload.$credential->nonce.$credential->auth_tag;

        $this->assertStringNotContainsString($plainPassword, $stored);
        $this->assertStringNotContainsString('demo-team@example.test', $stored);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'credential.created',
            'target_id' => $credential->uuid,
        ]);

        $this->from(route('credentials.edit', $credential))
            ->put(route('credentials.update', $credential), [
                'title' => 'Stale overwrite attempt',
                'category' => $credential->category,
                'website_url' => $credential->website_url,
                'lock_version' => $credential->lock_version + 1,
                'replace_secret' => '0',
            ])
            ->assertRedirect(route('credentials.edit', $credential))
            ->assertSessionHasErrors('lock_version');
        $this->assertSame('Demonstration social account', $credential->fresh()->title);

        $this->actingAs($manager)->put(route('credentials.access.update', $credential), [
            'grants' => [
                $staff->id => [
                    'selected' => '1',
                    'can_reveal_secret' => '1',
                ],
            ],
        ])->assertRedirect(route('credentials.show', $credential));

        $this->assertTrue(CredentialAccess::query()->where('user_id', $staff->id)->sole()->can_reveal_secret);
        $this->actingAs($staff)->get(route('credentials.show', $credential))->assertOk();

        $this->actingAs($staff)
            ->withSession(['auth.password_confirmed_at' => null])
            ->postJson(route('credentials.reveal', $credential))
            ->assertStatus(428)
            ->assertJsonPath('confirm_url', route('password.confirm'));

        $this->actingAs($staff)
            ->withSession(['auth.password_confirmed_at' => time()])
            ->postJson(route('credentials.reveal', $credential))
            ->assertOk()
            ->assertHeader('Cache-Control', 'max-age=0, no-store, private')
            ->assertJson([
                'username' => 'demo-team@example.test',
                'password' => $plainPassword,
                'notes' => 'Fictional FYP demonstration record.',
                'expires_in' => 20,
            ]);

        $this->assertDatabaseHas('audit_logs', [
            'actor_user_id' => $staff->id,
            'action' => 'credential.secret_revealed',
            'target_id' => $credential->uuid,
        ]);

        $this->actingAs($unassignedStaff)
            ->postJson(route('credentials.reveal', $credential))
            ->assertForbidden();

        $verification = app(AuditChainVerifier::class)->verify();
        $this->assertTrue($verification['valid'], json_encode($verification['errors']));
    }

    public function test_administrator_can_create_users_but_cannot_demote_their_own_final_admin_account(): void
    {
        $admin = $this->user(UserRole::Admin, [
            'name' => 'System Administrator',
            'email' => 'admin@secureteamvault.test',
        ]);

        $this->actingAs($admin)->post(route('admin.users.store'), [
            'name' => 'Project Manager',
            'email' => 'manager@example.test',
            'role' => UserRole::Manager->value,
            'is_active' => '1',
            'account_expires_at' => null,
            'password' => self::LOGIN_PASSWORD,
            'password_confirmation' => self::LOGIN_PASSWORD,
        ])->assertRedirect(route('admin.users.index'));

        $this->assertDatabaseHas('users', [
            'email' => 'manager@example.test',
            'role' => UserRole::Manager->value,
            'created_by' => $admin->id,
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'actor_user_id' => $admin->id,
            'action' => 'user.created',
        ]);

        $this->from(route('admin.users.edit', $admin))
            ->put(route('admin.users.update', $admin), [
                'name' => $admin->name,
                'email' => $admin->email,
                'role' => UserRole::Manager->value,
                'is_active' => '1',
                'account_expires_at' => null,
                'password' => null,
                'password_confirmation' => null,
            ])
            ->assertRedirect(route('admin.users.edit', $admin))
            ->assertSessionHasErrors('role');

        $this->assertTrue($admin->fresh()->isAdmin());
    }

    /** @param array<string, mixed> $attributes */
    private function user(UserRole $role, array $attributes = []): User
    {
        return User::factory()->create([
            'role' => $role,
            'is_active' => true,
            'account_expires_at' => null,
            ...$attributes,
        ]);
    }
}
