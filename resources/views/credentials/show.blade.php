@extends('layouts.app')
@section('page-title', 'Credential details')

@section('content')
<div class="container-fluid px-0">
    <div class="d-flex flex-wrap align-items-start justify-content-between gap-3 mb-4">
        <div>
            <a class="small text-decoration-none" href="{{ route('credentials.index') }}">← Back to vault</a>
            <div class="d-flex align-items-center gap-3 mt-2">
                <div class="vault-icon vault-icon-lg">{{ strtoupper(substr($credential->title, 0, 1)) }}</div>
                <div>
                    <h1 class="h3 mb-1">{{ $credential->title }}</h1>
                    <span class="badge text-bg-light border">{{ $credential->category ?: 'Uncategorised' }}</span>
                </div>
            </div>
        </div>
        <div class="d-flex gap-2">
            @can('update', $credential)
                <a class="btn btn-outline-primary" href="{{ route('credentials.edit', $credential) }}">Edit</a>
            @endcan
            @can('delete', $credential)
                <form method="POST" action="{{ route('credentials.destroy', $credential) }}" data-confirm="Archive this credential?">
                    @csrf
                    @method('DELETE')
                    <button class="btn btn-outline-danger" type="submit">Archive</button>
                </form>
            @endcan
        </div>
    </div>

    <div class="row g-4">
        <div class="col-xl-7">
            <div class="card border-0 shadow-sm mb-4">
                <div class="card-body p-4">
                    <div class="row g-4">
                        <div class="col-md-6">
                            <span class="detail-label">Website</span>
                            @if ($credential->website_url)
                                <a href="{{ $credential->website_url }}" target="_blank" rel="noopener noreferrer">{{ $credential->website_url }}</a>
                            @else
                                <span class="text-secondary">Not provided</span>
                            @endif
                        </div>
                        <div class="col-md-6">
                            <span class="detail-label">Owner</span>
                            <span>{{ $credential->owner->name }}</span>
                        </div>
                        <div class="col-md-6">
                            <span class="detail-label">Last updated</span>
                            <span>{{ $credential->updated_at->format('d M Y, g:i A') }}</span>
                        </div>
                        <div class="col-md-6">
                            <span class="detail-label">Encryption</span>
                            <span>AES-256-GCM · key v{{ $credential->key_version }}</span>
                        </div>
                    </div>
                </div>
            </div>

            <div class="card border-0 shadow-sm secure-panel">
                <div class="card-body p-4">
                    <div class="d-flex flex-wrap align-items-center justify-content-between gap-3 mb-3">
                        <div>
                            <h2 class="h5 mb-1">Encrypted login details</h2>
                            <p class="text-secondary small mb-0">Hidden by default and automatically cleared after 20 seconds.</p>
                        </div>
                        <span class="security-pill">Protected</span>
                    </div>

                    <div id="secret-alert" class="alert alert-danger d-none" role="alert"></div>
                    <div id="secret-panel" class="secret-display" aria-live="polite" data-countdown-target="secret-countdown">
                        <div class="mb-3">
                            <span class="detail-label">Username or email</span>
                            <code id="secret-username" data-secret-field="username">••••••••••••</code>
                        </div>
                        <div class="mb-3">
                            <span class="detail-label">Password or secret</span>
                            <code id="secret-password" data-secret-field="password">••••••••••••</code>
                        </div>
                        <div>
                            <span class="detail-label">Secure notes</span>
                            <span id="secret-notes" class="text-secondary" data-secret-field="notes" data-masked-value="Hidden">Hidden</span>
                        </div>
                    </div>

                    <div class="d-flex flex-wrap align-items-center gap-2 mt-4">
                        @can('reveal', $credential)
                            <button type="button" class="btn btn-primary js-reveal-secret" data-reveal-url="{{ route('credentials.reveal', $credential) }}" data-reveal-group="secret-panel">Reveal for 20 seconds</button>
                        @endcan
                        @can('copy', $credential)
                            <button type="button" class="btn btn-outline-primary js-copy-secret" data-copy-url="{{ route('credentials.copy', $credential) }}">Copy password</button>
                        @endcan
                        <span id="secret-countdown" class="small text-secondary ms-auto"></span>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-xl-5">
            @can('manageAccess', $credential)
                <div class="card border-0 shadow-sm">
                    <div class="card-body p-4">
                        <h2 class="h5 mb-1">Team access</h2>
                        <p class="text-secondary small mb-3">Grant only the minimum access each team member needs.</p>

                        <form method="POST" action="{{ route('credentials.access.update', $credential) }}">
                            @csrf
                            @method('PUT')

                            @forelse ($eligibleUsers as $user)
                                @php
                                    $access = $credential->accesses->firstWhere('user_id', $user->id);
                                    $isActive = $access && !$access->revoked_at && (!$access->expires_at || $access->expires_at->isFuture());
                                @endphp
                                <div class="access-row border rounded-3 p-3 mb-3">
                                    <div class="form-check mb-2">
                                        <input class="form-check-input" type="checkbox" name="grants[{{ $user->id }}][selected]" value="1" id="grant-{{ $user->id }}" @checked($isActive)>
                                        <label class="form-check-label fw-semibold" for="grant-{{ $user->id }}">
                                            {{ $user->name }}
                                            <span class="badge text-bg-light border ms-1">{{ $user->role->label() }}</span>
                                        </label>
                                    </div>
                                    <div class="ms-4 small">
                                        <div class="form-check">
                                            <input class="form-check-input" type="checkbox" name="grants[{{ $user->id }}][can_reveal_secret]" value="1" id="reveal-{{ $user->id }}" @checked(!$access || $access->can_reveal_secret)>
                                            <label class="form-check-label" for="reveal-{{ $user->id }}">May reveal/copy secret</label>
                                        </div>
                                        @if ($user->hasRole('manager'))
                                            <div class="form-check">
                                                <input class="form-check-input" type="checkbox" name="grants[{{ $user->id }}][can_edit]" value="1" id="edit-{{ $user->id }}" @checked($access?->can_edit)>
                                                <label class="form-check-label" for="edit-{{ $user->id }}">May edit credential</label>
                                            </div>
                                            <div class="form-check">
                                                <input class="form-check-input" type="checkbox" name="grants[{{ $user->id }}][can_manage_access]" value="1" id="manage-{{ $user->id }}" @checked($access?->can_manage_access)>
                                                <label class="form-check-label" for="manage-{{ $user->id }}">May manage access</label>
                                            </div>
                                        @endif
                                        <label class="form-label mt-2 mb-1" for="expiry-{{ $user->id }}">Optional access expiry</label>
                                        <input class="form-control form-control-sm" type="datetime-local" name="grants[{{ $user->id }}][expires_at]" id="expiry-{{ $user->id }}" value="{{ $access?->expires_at?->format('Y-m-d\TH:i') }}">
                                    </div>
                                </div>
                            @empty
                                <p class="text-secondary">No other active users are available.</p>
                            @endforelse

                            @if ($eligibleUsers->isNotEmpty())
                                <button class="btn btn-primary w-100" type="submit">Save access permissions</button>
                            @endif
                        </form>
                    </div>
                </div>
            @else
                <div class="card border-0 shadow-sm">
                    <div class="card-body p-4">
                        <h2 class="h6">Access scope</h2>
                        <p class="text-secondary mb-0">This credential was assigned to your account according to your role and current access grant.</p>
                    </div>
                </div>
            @endcan
        </div>
    </div>
</div>
@endsection
