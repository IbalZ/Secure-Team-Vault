@extends('layouts.app')

@section('title', 'SecureTeam Vault')

@section('content')
@php
    $continueRoute = Illuminate\Support\Facades\Route::has('dashboard')
        ? 'dashboard'
        : (Illuminate\Support\Facades\Route::has('home') ? 'home' : null);
@endphp
<section class="stv-landing">
    <div class="container">
        <div class="row align-items-center g-5">
            <div class="col-lg-7">
                <span class="stv-kicker">SA Famous Cooling | Protected team access</span>
                <h1 class="display-4 fw-bold mt-3 mb-4">Keep shared credentials secure and accountable.</h1>
                <p class="lead text-secondary mb-4">
                    SecureTeam Vault gives authorized team members one controlled place to store,
                    access, and audit business credentials.
                </p>

                <div class="d-flex flex-wrap gap-3">
                    @auth
                        <a class="btn btn-primary btn-lg px-4" href="{{ $continueRoute ? route($continueRoute) : url('/') }}">
                            Continue to dashboard
                        </a>
                    @else
                        @if (Illuminate\Support\Facades\Route::has('login'))
                            <a class="btn btn-primary btn-lg px-4" href="{{ route('login') }}">Sign in securely</a>
                        @endif
                    @endauth
                    <a class="btn btn-outline-secondary btn-lg px-4" href="#security-features">How it works</a>
                </div>
            </div>

            <div class="col-lg-5">
                <div class="stv-hero-panel" aria-label="Security overview">
                    <div class="stv-hero-shield" aria-hidden="true">
                        <svg viewBox="0 0 24 24" focusable="false">
                            <path d="M12 2.5 20 6v5.4c0 4.9-3.2 8.7-8 10.1-4.8-1.4-8-5.2-8-10.1V6l8-3.5Z" fill="none" stroke="currentColor" stroke-width="1.6"/>
                            <path d="m8.8 12 2.1 2.1 4.5-4.6" fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8"/>
                        </svg>
                    </div>
                    <p class="text-uppercase small fw-semibold mb-2">Secure by design</p>
                    <h2 class="h3 mb-4">Access only what your role allows.</h2>
                    <ul class="stv-security-list">
                        <li>Encrypted credential storage</li>
                        <li>Role-based permissions</li>
                        <li>Accountable access history</li>
                    </ul>
                </div>
            </div>
        </div>
    </div>
</section>

<section class="stv-feature-strip" id="security-features" aria-labelledby="security-features-heading">
    <div class="container">
        <div class="text-center mb-5">
            <span class="stv-kicker">Core protection</span>
            <h2 class="h2 mt-2" id="security-features-heading">Built for controlled collaboration</h2>
        </div>
        <div class="row g-4">
            <div class="col-md-4">
                <article class="stv-feature-card h-100">
                    <span class="stv-feature-number" aria-hidden="true">01</span>
                    <h3 class="h5">Encrypted secrets</h3>
                    <p class="mb-0 text-secondary">Credential values remain protected until an authorized user requests them.</p>
                </article>
            </div>
            <div class="col-md-4">
                <article class="stv-feature-card h-100">
                    <span class="stv-feature-number" aria-hidden="true">02</span>
                    <h3 class="h5">Role-based access</h3>
                    <p class="mb-0 text-secondary">Administrators, managers, staff, interns, and freelance users receive only the actions they need.</p>
                </article>
            </div>
            <div class="col-md-4">
                <article class="stv-feature-card h-100">
                    <span class="stv-feature-number" aria-hidden="true">03</span>
                    <h3 class="h5">Auditable activity</h3>
                    <p class="mb-0 text-secondary">Important credential actions are recorded for review and accountability.</p>
                </article>
            </div>
        </div>
    </div>
</section>
@endsection
