@extends('layouts.app')

@section('title', 'Sign in | SecureTeam Vault')

@section('content')
<section class="stv-auth-section">
    <div class="container">
        <div class="row justify-content-center">
            <div class="col-md-8 col-lg-5">
                <div class="stv-auth-card">
                    <div class="text-center mb-4">
                        <span class="stv-company-mark stv-company-mark-large">
                            <img src="{{ asset('images/sa-famous-cooling.jpg') }}" alt="SA Famous Cooling">
                        </span>
                        <p class="stv-kicker mt-3 mb-2">Authorized access</p>
                        <h1 class="h2 mb-2">Welcome back</h1>
                        <p class="text-secondary mb-0">Sign in to your SecureTeam Vault workspace.</p>
                    </div>

                    @if ($errors->any())
                        <div class="alert alert-danger" role="alert">
                            We could not sign you in. Check your details and try again.
                        </div>
                    @endif

                    @if (session('status'))
                        <div class="alert alert-success" role="status">
                            {{ session('status') }}
                        </div>
                    @endif

                    <form method="POST" action="{{ route('login') }}" novalidate>
                        @csrf

                        <div class="mb-3">
                            <label for="email" class="form-label">Email address</label>
                            <input
                                id="email"
                                type="email"
                                class="form-control form-control-lg @error('email') is-invalid @enderror"
                                name="email"
                                value="{{ old('email') }}"
                                required
                                autocomplete="email"
                                inputmode="email"
                                autofocus
                                @error('email') aria-describedby="email-error" aria-invalid="true" @enderror
                            >
                            @error('email')
                                <div class="invalid-feedback" id="email-error">{{ $message }}</div>
                            @enderror
                        </div>

                        <div class="mb-3">
                            <div class="d-flex justify-content-between align-items-center">
                                <label for="password" class="form-label">Password</label>
                                @if (Illuminate\Support\Facades\Route::has('password.request'))
                                    <a class="stv-inline-link small" href="{{ route('password.request') }}">Forgot password?</a>
                                @endif
                            </div>
                            <input
                                id="password"
                                type="password"
                                class="form-control form-control-lg @error('password') is-invalid @enderror"
                                name="password"
                                required
                                autocomplete="current-password"
                                @error('password') aria-describedby="password-error" aria-invalid="true" @enderror
                            >
                            @error('password')
                                <div class="invalid-feedback" id="password-error">{{ $message }}</div>
                            @enderror
                        </div>

                        <div class="form-check mb-4">
                            <input
                                class="form-check-input"
                                type="checkbox"
                                name="remember"
                                id="remember"
                                {{ old('remember') ? 'checked' : '' }}
                            >
                            <label class="form-check-label" for="remember">Keep me signed in on this device</label>
                        </div>

                        <button type="submit" class="btn btn-primary btn-lg w-100">Sign in securely</button>
                    </form>

                    <p class="stv-auth-note mb-0 mt-4">
                        Access is restricted to accounts created by a system administrator.
                    </p>
                </div>
            </div>
        </div>
    </div>
</section>
@endsection
