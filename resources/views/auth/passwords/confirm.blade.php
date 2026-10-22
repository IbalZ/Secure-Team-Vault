@extends('layouts.app')

@section('title', 'Confirm password | SecureTeam Vault')
@section('page-title', 'Confirm password')

@section('content')
<div class="container-fluid px-0">
    <div class="row justify-content-center">
        <div class="col-lg-7 col-xl-5">
            <div class="card border-0 shadow-sm secure-panel">
                <div class="card-body p-4 p-md-5">
                    <span class="security-pill mb-3">Protected action</span>
                    <h1 class="h3 mb-2">Confirm it is you</h1>
                    <p class="text-secondary mb-4">
                        Enter your current SecureTeam Vault password before revealing or copying encrypted login details.
                    </p>

                    <form method="POST" action="{{ route('password.confirm') }}">
                        @csrf

                        <div class="mb-4">
                            <label for="password" class="form-label">Current password</label>
                            <input
                                id="password"
                                type="password"
                                class="form-control form-control-lg @error('password') is-invalid @enderror"
                                name="password"
                                required
                                autocomplete="current-password"
                                autofocus
                            >
                            @error('password')
                                <div class="invalid-feedback">{{ $message }}</div>
                            @enderror
                        </div>

                        <div class="d-flex flex-wrap gap-2">
                            <button type="submit" class="btn btn-primary px-4">Confirm and continue</button>
                            <a class="btn btn-outline-secondary" href="{{ route('credentials.index') }}">Cancel</a>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>
@endsection
