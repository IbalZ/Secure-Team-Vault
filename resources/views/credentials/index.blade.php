@extends('layouts.app')
@section('page-title', 'Credential vault')

@section('content')
<div class="container-fluid px-0">
    <div class="d-flex flex-wrap align-items-center justify-content-between gap-3 mb-4">
        <div>
            <p class="text-uppercase text-teal fw-semibold small mb-1">Encrypted workspace</p>
            <h1 class="h3 mb-1">Credential vault</h1>
            <p class="text-secondary mb-0">Only credentials permitted for your account are shown.</p>
        </div>
        @can('create', App\Models\VaultCredential::class)
            <a class="btn btn-primary" href="{{ route('credentials.create') }}">Add credential</a>
        @endcan
    </div>

    <div class="card border-0 shadow-sm mb-4">
        <div class="card-body">
            <form method="GET" action="{{ route('credentials.index') }}" class="row g-3 align-items-end">
                <div class="col-lg-6">
                    <label for="search" class="form-label">Search</label>
                    <input id="search" name="search" class="form-control" value="{{ request('search') }}" placeholder="Account name, category, or URL">
                </div>
                <div class="col-lg-3">
                    <label for="category" class="form-label">Category</label>
                    <select id="category" name="category" class="form-select">
                        <option value="">All categories</option>
                        @foreach ($categories as $category)
                            <option value="{{ $category }}" @selected(request('category') === $category)>{{ $category }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-lg-3 d-flex gap-2">
                    <button class="btn btn-outline-primary flex-grow-1" type="submit">Filter</button>
                    <a class="btn btn-outline-secondary" href="{{ route('credentials.index') }}">Reset</a>
                </div>
            </form>
        </div>
    </div>

    @if ($credentials->isEmpty())
        <div class="empty-state card border-0 shadow-sm text-center">
            <div class="card-body py-5">
                <div class="empty-state-icon mx-auto mb-3">🔐</div>
                <h2 class="h5">No credentials available</h2>
                <p class="text-secondary mb-3">Your vault is empty or no credentials have been assigned to you.</p>
                @can('create', App\Models\VaultCredential::class)
                    <a class="btn btn-primary" href="{{ route('credentials.create') }}">Create the first credential</a>
                @endcan
            </div>
        </div>
    @else
        <div class="row g-4">
            @foreach ($credentials as $credential)
                <div class="col-md-6 col-xl-4">
                    <article class="card vault-card border-0 shadow-sm h-100">
                        <div class="card-body d-flex flex-column">
                            <div class="d-flex justify-content-between align-items-start gap-3 mb-3">
                                <div class="vault-icon">{{ strtoupper(substr($credential->title, 0, 1)) }}</div>
                                <span class="badge text-bg-light border">{{ $credential->category ?: 'Uncategorised' }}</span>
                            </div>
                            <h2 class="h5 mb-1">{{ $credential->title }}</h2>
                            <p class="text-secondary small mb-3 text-truncate">
                                {{ $credential->website_url ?: 'No website URL supplied' }}
                            </p>
                            <div class="small text-secondary mt-auto mb-3">
                                Owner: {{ $credential->owner->name }}<br>
                                Updated {{ $credential->updated_at->diffForHumans() }}
                            </div>
                            <a class="btn btn-outline-primary stretched-link" href="{{ route('credentials.show', $credential) }}">Open securely</a>
                        </div>
                    </article>
                </div>
            @endforeach
        </div>

        <div class="mt-4">{{ $credentials->links() }}</div>
    @endif
</div>
@endsection
