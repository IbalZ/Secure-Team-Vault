<?php

namespace App\Http\Controllers\Admin;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\AuditLogger;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;

class UserController extends Controller
{
    public function index(): View
    {
        $users = User::query()
            ->with('createdBy')
            ->orderBy('name')
            ->paginate(15);

        return view('admin.users.index', compact('users'));
    }

    public function create(): View
    {
        return view('admin.users.create', [
            'roles' => UserRole::cases(),
        ]);
    }

    public function store(Request $request, AuditLogger $audit): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', Rule::unique('users', 'email')],
            'role' => ['required', Rule::enum(UserRole::class)],
            'is_active' => ['required', 'boolean'],
            'account_expires_at' => ['nullable', 'date', 'after:now'],
            'password' => ['required', 'confirmed', $this->strongPasswordRule()],
        ]);

        DB::transaction(function () use ($request, $validated, $audit): void {
            $user = User::create([
                'name' => trim($validated['name']),
                'email' => Str::lower(trim($validated['email'])),
                'password' => $validated['password'],
                'role' => UserRole::from($validated['role']),
                'is_active' => $request->boolean('is_active'),
                'account_expires_at' => $validated['account_expires_at'] ?? null,
                'created_by' => $request->user()->getKey(),
            ]);

            $audit->record(
                $request->user(),
                'user.created',
                'user',
                $user->getKey(),
                [
                    'has_expiry' => $user->account_expires_at !== null,
                    'is_active' => $user->is_active,
                    'role' => $user->role,
                ],
                $request,
            );
        });

        return redirect()
            ->route('admin.users.index')
            ->with('success', 'User account created successfully.');
    }

    public function edit(User $user): View
    {
        return view('admin.users.edit', [
            'user' => $user,
            'roles' => UserRole::cases(),
        ]);
    }

    /**
     * @throws ValidationException
     */
    public function update(Request $request, User $user, AuditLogger $audit): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => [
                'required',
                'string',
                'email',
                'max:255',
                Rule::unique('users', 'email')->ignore($user->getKey()),
            ],
            'role' => ['required', Rule::enum(UserRole::class)],
            'is_active' => ['required', 'boolean'],
            'account_expires_at' => ['nullable', 'date'],
            'password' => ['nullable', 'confirmed', $this->strongPasswordRule()],
        ]);

        $newRole = UserRole::from($validated['role']);
        $newIsActive = $request->boolean('is_active');
        $newExpiresAt = isset($validated['account_expires_at'])
            ? CarbonImmutable::parse($validated['account_expires_at'])
            : null;
        $actorId = (int) $request->user()->getKey();
        $revokeCurrentSession = false;

        DB::transaction(function () use (
            $user,
            $validated,
            $newRole,
            $newIsActive,
            $newExpiresAt,
            $actorId,
            $audit,
            $request,
            &$revokeCurrentSession,
        ): void {
            /** @var User $target */
            $target = User::query()->lockForUpdate()->findOrFail($user->getKey());
            $isSelf = (int) $target->getKey() === $actorId;

            if ($isSelf && $newRole !== UserRole::Admin) {
                throw ValidationException::withMessages([
                    'role' => 'You cannot demote your own administrator account.',
                ]);
            }

            if ($isSelf && ! $newIsActive) {
                throw ValidationException::withMessages([
                    'is_active' => 'You cannot deactivate your own administrator account.',
                ]);
            }

            $newIsUsableAdmin = $newRole === UserRole::Admin
                && $newIsActive
                && ($newExpiresAt === null || $newExpiresAt->isFuture());

            if ($target->isUsableAdmin() && ! $newIsUsableAdmin) {
                $anotherUsableAdmin = User::query()
                    ->whereKeyNot($target->getKey())
                    ->where('role', UserRole::Admin->value)
                    ->where('is_active', true)
                    ->where(function ($query): void {
                        $query->whereNull('account_expires_at')
                            ->orWhere('account_expires_at', '>', now());
                    })
                    ->orderBy('id')
                    ->lockForUpdate()
                    ->first(['id']);

                if ($anotherUsableAdmin === null) {
                    throw ValidationException::withMessages([
                        'role' => 'Create or activate another administrator before removing access from the final active administrator.',
                    ]);
                }
            }

            $newEmail = Str::lower(trim($validated['email']));
            $passwordChanged = filled($validated['password'] ?? null);
            $changedFields = [];

            if ($target->name !== trim($validated['name'])) {
                $changedFields[] = 'name';
            }

            if ($target->email !== $newEmail) {
                $changedFields[] = 'email';
            }

            if ($target->role !== $newRole) {
                $changedFields[] = 'role';
            }

            if ($target->is_active !== $newIsActive) {
                $changedFields[] = 'activation';
            }

            if ($target->account_expires_at?->getTimestamp() !== $newExpiresAt?->getTimestamp()) {
                $changedFields[] = 'expiry';
            }

            if ($passwordChanged) {
                $changedFields[] = 'authentication_material';
            }

            $securityChanged = $target->role !== $newRole
                || $target->is_active !== $newIsActive
                || $target->email !== $newEmail
                || $target->account_expires_at?->getTimestamp() !== $newExpiresAt?->getTimestamp()
                || $passwordChanged;

            $target->name = trim($validated['name']);
            $target->email = $newEmail;
            $target->role = $newRole;
            $target->is_active = $newIsActive;
            $target->account_expires_at = $newExpiresAt;

            if ($passwordChanged) {
                $target->password = $validated['password'];
            }

            $target->save();

            if ($securityChanged) {
                $target->forceFill(['remember_token' => Str::random(60)])->saveQuietly();
                DB::table('sessions')->where('user_id', $target->getKey())->delete();
                $revokeCurrentSession = $isSelf;
            }

            $audit->record(
                $request->user(),
                'user.updated',
                'user',
                $target->getKey(),
                [
                    'changed_fields' => $changedFields,
                    'has_expiry' => $target->account_expires_at !== null,
                    'is_active' => $target->is_active,
                    'role' => $target->role,
                    'sessions_revoked' => $securityChanged,
                ],
                $request,
            );
        });

        if ($revokeCurrentSession) {
            Auth::guard()->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect()
                ->route('login')
                ->with('status', 'Your security details were updated. Please sign in again.');
        }

        return redirect()
            ->route('admin.users.index')
            ->with('success', 'User account updated successfully.');
    }

    private function strongPasswordRule(): Password
    {
        return Password::min(12)
            ->letters()
            ->mixedCase()
            ->numbers()
            ->symbols();
    }
}
