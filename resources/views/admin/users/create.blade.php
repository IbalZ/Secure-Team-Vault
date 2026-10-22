@extends('layouts.app')
@section('page-title', 'Create user')

@section('content')
<div class="container">
    <div class="row justify-content-center">
        <div class="col-xl-9">
            <div class="d-flex align-items-center justify-content-between mb-3">
                <div>
                    <h1 class="h3 mb-1">Create user</h1>
                    <p class="text-muted mb-0">Add a managed SecureTeam Vault account.</p>
                </div>
            </div>

            <div class="card shadow-sm">
                <div class="card-body p-4">
                    <form method="POST" action="{{ route('admin.users.store') }}">
                        @csrf
                        @include('admin.users._form')
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>
@endsection
