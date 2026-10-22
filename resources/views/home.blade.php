@extends('layouts.app')
@section('page-title', 'Dashboard')

@section('content')
<div class="container-fluid px-0">
    <div class="d-flex flex-wrap align-items-start justify-content-between gap-3 mb-4">
        <div>
            <p class="text-uppercase text-teal fw-semibold small mb-1">Security dashboard</p>
            <h1 class="h3 mb-1">Welcome, {{ auth()->user()->name }}</h1>
            <p class="text-secondary mb-0">Your access level is {{ auth()->user()->role->label() }}.</p>
        </div>
        @can('create', App\Models\VaultCredential::class)
            <a class="btn btn-primary" href="{{ route('credentials.create') }}">Add credential</a>
        @endcan
    </div>

    <div class="row g-4 mb-4">
        <div class="col-sm-6 col-xl-3">
            <div class="stat-card card border-0 shadow-sm h-100"><div class="card-body"><span class="stat-label">Accessible credentials</span><strong>{{ $stats['credentials'] }}</strong></div></div>
        </div>
        <div class="col-sm-6 col-xl-3">
                <div class="stat-card card border-0 shadow-sm h-100"><div class="card-body"><span class="stat-label">Owned credentials</span><strong>{{ $stats['owned'] }}</strong></div></div>
        </div>
        @if ($stats['active_users'] !== null)
            <div class="col-sm-6 col-xl-3">
                <div class="stat-card card border-0 shadow-sm h-100"><div class="card-body"><span class="stat-label">Active users</span><strong>{{ $stats['active_users'] }}</strong></div></div>
            </div>
            <div class="col-sm-6 col-xl-3">
                <div class="stat-card card border-0 shadow-sm h-100"><div class="card-body"><span class="stat-label">Audit events</span><strong>{{ $stats['audit_events'] }}</strong></div></div>
            </div>
        @endif
    </div>

    <div class="row g-4">
        <div class="col-xl-7">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-header bg-white border-0 d-flex justify-content-between align-items-center pt-4 px-4">
                    <h2 class="h5 mb-0">Recently updated credentials</h2>
                    <a href="{{ route('credentials.index') }}" class="small text-decoration-none">View vault</a>
                </div>
                <div class="card-body px-4">
                    @forelse ($recentCredentials as $credential)
                        <a class="recent-row d-flex align-items-center justify-content-between gap-3 text-decoration-none" href="{{ route('credentials.show', $credential) }}">
                            <span><strong class="d-block text-body">{{ $credential->title }}</strong><small class="text-secondary">{{ $credential->category ?: 'Uncategorised' }} · {{ $credential->owner->name }}</small></span>
                            <span class="text-secondary small">{{ $credential->updated_at->diffForHumans() }}</span>
                        </a>
                    @empty
                        <p class="text-secondary py-4 mb-0">No credentials are available yet.</p>
                    @endforelse
                </div>
            </div>
        </div>
        <div class="col-xl-5">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-header bg-white border-0 pt-4 px-4"><h2 class="h5 mb-0">Recent activity</h2></div>
                <div class="card-body px-4">
                    @forelse ($recentActivity as $event)
                        <div class="audit-row"><span class="audit-dot"></span><div><strong class="small">{{ str_replace('.', ' ', ucfirst($event->action)) }}</strong><div class="small text-secondary">{{ $event->occurred_at->diffForHumans() }}</div></div></div>
                    @empty
                        <p class="text-secondary py-4 mb-0">Activity will appear here as the vault is used.</p>
                    @endforelse
                </div>
            </div>
        </div>
    </div>
</div>
@endsection
