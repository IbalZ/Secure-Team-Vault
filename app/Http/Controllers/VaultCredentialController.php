<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Models\VaultCredential;
use App\Services\AuditLogger;
use App\Services\VaultCryptoService;
use Illuminate\Contracts\Support\Renderable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class VaultCredentialController extends Controller
{
    public function index(Request $request): Renderable
    {
        $this->authorize('viewAny', VaultCredential::class);
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:120'],
            'category' => ['nullable', 'string', 'max:60'],
        ]);

        $baseQuery = VaultCredential::query()->visibleTo($request->user());
        $credentials = (clone $baseQuery)
            ->with('owner')
            ->when(filled($filters['search'] ?? null), function ($query) use ($filters) {
                $search = '%'.str_replace(['%', '_'], ['\\%', '\\_'], trim($filters['search'])).'%';

                $query->where(function ($nested) use ($search) {
                    $nested->where('title', 'like', $search)
                        ->orWhere('category', 'like', $search)
                        ->orWhere('website_url', 'like', $search);
                });
            })
            ->when(filled($filters['category'] ?? null), fn ($query) => $query->where('category', $filters['category']))
            ->latest('updated_at')
            ->paginate(12)
            ->withQueryString();

        $categories = (clone $baseQuery)
            ->whereNotNull('category')
            ->where('category', '<>', '')
            ->distinct()
            ->orderBy('category')
            ->pluck('category');

        return view('credentials.index', compact('credentials', 'categories'));
    }

    public function create(): Renderable
    {
        $this->authorize('create', VaultCredential::class);

        return view('credentials.create');
    }

    public function store(Request $request, VaultCryptoService $crypto, AuditLogger $audit): RedirectResponse
    {
        $this->authorize('create', VaultCredential::class);

        $validated = $request->validate($this->rules(includeSecret: true));
        $uuid = (string) Str::uuid();
        $encrypted = $crypto->encrypt([
            'username' => (string) ($validated['vault_username'] ?? ''),
            'password' => $validated['vault_password'],
            'notes' => $validated['vault_notes'] ?? null,
        ], $uuid);

        $credential = DB::transaction(function () use ($request, $validated, $uuid, $encrypted, $audit) {
            $credential = VaultCredential::query()->create([
                'uuid' => $uuid,
                'title' => $validated['title'],
                'category' => $validated['category'] ?? null,
                'website_url' => $validated['website_url'] ?? null,
                ...$encrypted,
                'owner_id' => $request->user()->id,
                'updated_by' => $request->user()->id,
                'lock_version' => 1,
            ]);

            $audit->record(
                $request->user(),
                'credential.created',
                'vault_credential',
                $credential->uuid,
                ['title' => $credential->title, 'category' => $credential->category],
                $request,
            );

            return $credential;
        });

        return redirect()->route('credentials.show', $credential)
            ->with('success', 'Credential encrypted and saved successfully.');
    }

    public function show(Request $request, VaultCredential $credential): Renderable
    {
        $this->authorize('view', $credential);

        $credential->load(['owner', 'updatedBy', 'accesses.user']);
        $eligibleUsers = collect();

        if ($request->user()->can('manageAccess', $credential)) {
            $eligibleUsers = User::query()
                ->whereKeyNot($credential->owner_id)
                ->where('is_active', true)
                ->where(function ($query) {
                    $query->whereNull('account_expires_at')->orWhere('account_expires_at', '>', now());
                })
                ->orderBy('name')
                ->get();
        }

        return view('credentials.show', compact('credential', 'eligibleUsers'));
    }

    public function edit(VaultCredential $credential): Renderable
    {
        $this->authorize('update', $credential);

        return view('credentials.edit', compact('credential'));
    }

    public function update(
        Request $request,
        VaultCredential $credential,
        VaultCryptoService $crypto,
        AuditLogger $audit,
    ): RedirectResponse {
        $this->authorize('update', $credential);

        $replaceSecret = $request->boolean('replace_secret');
        $validated = $request->validate($this->rules(includeSecret: $replaceSecret, updating: true));

        if ((int) $validated['lock_version'] !== (int) $credential->lock_version) {
            return back()->withInput($request->except(['vault_username', 'vault_password', 'vault_notes']))
                ->withErrors(['lock_version' => 'This credential changed in another session. Reload and try again.']);
        }

        DB::transaction(function () use ($request, $credential, $validated, $replaceSecret, $crypto, $audit) {
            $lockedCredential = VaultCredential::query()
                ->lockForUpdate()
                ->findOrFail($credential->getKey());

            if ((int) $validated['lock_version'] !== (int) $lockedCredential->lock_version) {
                throw ValidationException::withMessages([
                    'lock_version' => 'This credential changed in another session. Reload and try again.',
                ]);
            }

            $changes = [
                'title' => $validated['title'],
                'category' => $validated['category'] ?? null,
                'website_url' => $validated['website_url'] ?? null,
                'updated_by' => $request->user()->id,
                'lock_version' => $lockedCredential->lock_version + 1,
            ];

            if ($replaceSecret) {
                $changes = [
                    ...$changes,
                    ...$crypto->encrypt([
                        'username' => (string) ($validated['vault_username'] ?? ''),
                        'password' => $validated['vault_password'],
                        'notes' => $validated['vault_notes'] ?? null,
                    ], $lockedCredential->uuid, (int) $lockedCredential->payload_version),
                ];
            }

            $lockedCredential->update($changes);

            $audit->record(
                $request->user(),
                'credential.updated',
                'vault_credential',
                $lockedCredential->uuid,
                [
                    'title' => $lockedCredential->title,
                    'category' => $lockedCredential->category,
                    'login_details_replaced' => $replaceSecret,
                ],
                $request,
            );
        });

        return redirect()->route('credentials.show', $credential)
            ->with('success', 'Credential updated successfully.');
    }

    public function destroy(Request $request, VaultCredential $credential, AuditLogger $audit): RedirectResponse
    {
        $this->authorize('delete', $credential);

        DB::transaction(function () use ($request, $credential, $audit) {
            $audit->record(
                $request->user(),
                'credential.archived',
                'vault_credential',
                $credential->uuid,
                ['title' => $credential->title],
                $request,
            );
            $credential->delete();
        });

        return redirect()->route('credentials.index')->with('success', 'Credential archived.');
    }

    public function reveal(
        Request $request,
        VaultCredential $credential,
        VaultCryptoService $crypto,
        AuditLogger $audit,
    ): JsonResponse {
        $this->authorize('reveal', $credential);

        if (! $this->passwordRecentlyConfirmed($request)) {
            return $this->confirmationRequired($request, $credential);
        }

        $secret = $crypto->decrypt($credential);
        $audit->record(
            $request->user(),
            'credential.secret_revealed',
            'vault_credential',
            $credential->uuid,
            ['title' => $credential->title],
            $request,
        );

        return $this->secretResponse([
            'username' => $secret['username'],
            'password' => $secret['password'],
            'notes' => $secret['notes'],
            'expires_in' => 20,
        ]);
    }

    public function copySecret(
        Request $request,
        VaultCredential $credential,
        VaultCryptoService $crypto,
        AuditLogger $audit,
    ): JsonResponse {
        $this->authorize('copy', $credential);

        if (! $this->passwordRecentlyConfirmed($request)) {
            return $this->confirmationRequired($request, $credential);
        }

        $secret = $crypto->decrypt($credential);
        $audit->record(
            $request->user(),
            'credential.secret_copied',
            'vault_credential',
            $credential->uuid,
            ['title' => $credential->title],
            $request,
        );

        return $this->secretResponse(['password' => $secret['password']]);
    }

    /** @return array<string, mixed> */
    private function rules(bool $includeSecret, bool $updating = false): array
    {
        $rules = [
            'title' => ['required', 'string', 'max:120'],
            'category' => ['nullable', 'string', 'max:60'],
            'website_url' => ['nullable', 'url:http,https', 'max:2048'],
        ];

        if ($updating) {
            $rules['lock_version'] = ['required', 'integer', 'min:1'];
            $rules['replace_secret'] = ['nullable', 'boolean'];
        }

        if ($includeSecret) {
            $rules['vault_username'] = ['nullable', 'string', 'max:255'];
            $rules['vault_password'] = ['required', 'string', 'max:4096'];
            $rules['vault_notes'] = ['nullable', 'string', 'max:4000'];
        }

        return $rules;
    }

    /** @param array<string, mixed> $payload */
    private function secretResponse(array $payload): JsonResponse
    {
        return response()->json($payload)->withHeaders([
            'Cache-Control' => 'no-store, private, max-age=0',
            'Pragma' => 'no-cache',
            'Expires' => '0',
        ]);
    }

    private function passwordRecentlyConfirmed(Request $request): bool
    {
        $confirmedAt = (int) $request->session()->get('auth.password_confirmed_at', 0);

        return $confirmedAt > 0
            && (time() - $confirmedAt) < (int) config('auth.password_timeout', 900);
    }

    private function confirmationRequired(Request $request, VaultCredential $credential): JsonResponse
    {
        $request->session()->put('url.intended', route('credentials.show', $credential));

        return response()->json([
            'message' => 'Confirm your password before accessing this secret.',
            'confirm_url' => route('password.confirm'),
        ], 428)->withHeaders([
            'Cache-Control' => 'no-store, private, max-age=0',
            'Pragma' => 'no-cache',
        ]);
    }
}
