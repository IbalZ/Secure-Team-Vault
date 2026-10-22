@extends('layouts.app')
@section('page-title', 'Add credential')

@section('content')
<div class="container-fluid px-0">
    <div class="mb-4">
        <p class="text-uppercase text-teal fw-semibold small mb-1">Vault management</p>
        <h1 class="h3 mb-1">Add credential</h1>
        <p class="text-secondary mb-0">The login details are encrypted before they are written to MySQL.</p>
    </div>

    <form method="POST" action="{{ route('credentials.store') }}" autocomplete="off">
        @csrf
        @include('credentials._form')
    </form>
</div>
@endsection
