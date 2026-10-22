@extends('layouts.app')
@section('page-title', 'Edit credential')

@section('content')
<div class="container-fluid px-0">
    <div class="d-flex flex-wrap align-items-start justify-content-between gap-3 mb-4">
        <div>
            <p class="text-uppercase text-teal fw-semibold small mb-1">Vault management</p>
            <h1 class="h3 mb-1">Edit {{ $credential->title }}</h1>
            <p class="text-secondary mb-0">Metadata can be changed without decrypting the saved login details.</p>
        </div>
        @can('delete', $credential)
            <form method="POST" action="{{ route('credentials.destroy', $credential) }}" data-confirm="Delete this credential? It will be archived and removed from the active vault. Its audit history will be retained.">
                @csrf
                @method('DELETE')
                <button class="btn btn-outline-danger" type="submit">Delete credential</button>
                <p class="small text-secondary mt-2 mb-0">Archives the credential; audit history is retained.</p>
            </form>
        @endcan
    </div>

    <form method="POST" action="{{ route('credentials.update', $credential) }}" autocomplete="off">
        @csrf
        @method('PUT')
        @include('credentials._form')
    </form>
</div>
@endsection
