@extends('layouts.app')
@section('page-title', 'User management')

@section('content')
<div class="container">
    <div class="d-flex flex-wrap align-items-center justify-content-between gap-3 mb-4">
        <div>
            <h1 class="h3 mb-1">User management</h1>
            <p class="text-muted mb-0">Manage roles, activation, expiry, and account credentials.</p>
        </div>
        <a href="{{ route('admin.users.create') }}" class="btn btn-primary">Create user</a>
    </div>

    <div class="card shadow-sm">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th scope="col">User</th>
                        <th scope="col">Role</th>
                        <th scope="col">Status</th>
                        <th scope="col">Expiry</th>
                        <th scope="col">Created by</th>
                        <th scope="col" class="text-end">Action</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($users as $user)
                        <tr>
                            <td>
                                <div class="fw-semibold">{{ $user->name }}</div>
                                <div class="small text-muted">{{ $user->email }}</div>
                            </td>
                            <td>{{ $user->role->label() }}</td>
                            <td>
                                @if (! $user->is_active)
                                    <span class="badge text-bg-secondary">Inactive</span>
                                @elseif ($user->isExpired())
                                    <span class="badge text-bg-warning">Expired</span>
                                @else
                                    <span class="badge text-bg-success">Active</span>
                                @endif
                            </td>
                            <td>
                                {{ $user->account_expires_at?->format('d M Y, H:i') ?? 'Never' }}
                            </td>
                            <td>{{ $user->createdBy?->name ?? 'Initial setup' }}</td>
                            <td class="text-end">
                                <a href="{{ route('admin.users.edit', $user) }}" class="btn btn-sm btn-outline-primary">
                                    Edit
                                </a>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" class="text-center text-muted py-5">No users found.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    @if ($users->hasPages())
        <div class="mt-4">{{ $users->links() }}</div>
    @endif
</div>
@endsection
