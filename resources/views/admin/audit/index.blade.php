@extends('layouts.app')
@section('page-title', 'Audit logs')

@section('content')
<div class="container-fluid px-0">
    <div class="d-flex flex-wrap align-items-start justify-content-between gap-3 mb-4">
        <div>
            <p class="text-uppercase text-teal fw-semibold small mb-1">Security oversight</p>
            <h1 class="h3 mb-1">Audit trail</h1>
            <p class="text-secondary mb-0">Append-only application events protected by an HMAC hash chain.</p>
            <p class="text-secondary small mb-0 mt-1">Times and date filters use {{ $displayTimezone }} (UTC{{ $displayUtcOffset }}). Original event timestamps remain in UTC.</p>
        </div>
        @if ($verification['valid'])
            <span class="badge rounded-pill text-bg-success px-3 py-2">Chain verified · {{ $verification['checked_events'] }} events</span>
        @else
            <span class="badge rounded-pill text-bg-danger px-3 py-2">Integrity warning</span>
        @endif
    </div>

    @unless ($verification['valid'])
        <div class="alert alert-danger">
            Audit verification failed. Investigate before relying on these records.
            <ul class="mb-0 mt-2">
                @foreach ($verification['errors'] as $error)<li>{{ $error['message'] }}</li>@endforeach
            </ul>
        </div>
    @endunless

    <div class="card border-0 shadow-sm mb-4">
        <div class="card-body">
            <form method="GET" class="row g-3 align-items-end">
                <div class="col-lg-4">
                    <label class="form-label" for="action">Action</label>
                    <select class="form-select" id="action" name="action">
                        <option value="">All actions</option>
                        @foreach ($actions as $action)
                            <option value="{{ $action }}" @selected(request('action') === $action)>{{ $action }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-lg-3">
                    <label class="form-label" for="actor">User</label>
                    <select class="form-select" id="actor" name="actor">
                        <option value="">All users</option>
                        @foreach ($users as $user)
                            <option value="{{ $user->id }}" @selected((string) request('actor') === (string) $user->id)>{{ $user->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-lg-3">
                    <label class="form-label" for="date">Date</label>
                    <input class="form-control" id="date" type="date" name="date" value="{{ request('date') }}">
                </div>
                <div class="col-lg-2 d-flex gap-2">
                    <button class="btn btn-outline-primary flex-grow-1">Filter</button>
                    <a class="btn btn-outline-secondary" href="{{ route('admin.audit.index') }}">Reset</a>
                </div>
            </form>
        </div>
    </div>

    <div class="card border-0 shadow-sm overflow-hidden">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead>
                    <tr>
                        <th scope="col">Time</th>
                        <th scope="col">User</th>
                        <th scope="col">Action</th>
                        <th scope="col">Target</th>
                        <th scope="col">Integrity</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($logs as $log)
                        <tr>
                            <td class="text-nowrap"><time datetime="{{ $log->occurred_at->toIso8601String() }}">{{ $log->occurred_at->setTimezone($displayTimezone)->format('d M Y, H:i:s') }}</time></td>
                            <td>{{ $log->actor?->name ?? 'System' }}<br><span class="small text-secondary">{{ $log->actor_role ?? 'system' }}</span></td>
                            <td><code>{{ $log->action }}</code></td>
                            <td>{{ $log->target_type ?: '—' }}<br><span class="small text-secondary">{{ $log->target_id ?: '—' }}</span></td>
                            <td><span class="hash-preview" title="{{ $log->entry_hash }}">{{ substr($log->entry_hash, 0, 12) }}…</span></td>
                        </tr>
                    @empty
                        <tr><td colspan="5" class="text-center text-secondary py-5">No audit events match these filters.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        @if ($logs->hasPages())<div class="card-footer bg-white">{{ $logs->links() }}</div>@endif
    </div>
</div>
@endsection
