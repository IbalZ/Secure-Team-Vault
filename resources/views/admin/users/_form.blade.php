@php
    $isEditing = isset($user);
    $selectedRole = old('role', $isEditing ? $user->role->value : \App\Enums\UserRole::Staff->value);
    $isActive = (bool) old('is_active', $isEditing ? $user->is_active : true);
    $expiresAt = old(
        'account_expires_at',
        $isEditing && $user->account_expires_at
            ? $user->account_expires_at->format('Y-m-d\TH:i')
            : ''
    );
@endphp

@if ($errors->any())
    <div class="alert alert-danger" role="alert">
        <strong>Please correct the highlighted fields.</strong>
        <ul class="mb-0 mt-2">
            @foreach ($errors->all() as $error)
                <li>{{ $error }}</li>
            @endforeach
        </ul>
    </div>
@endif

<div class="row g-3">
    <div class="col-md-6">
        <label for="name" class="form-label">Full name</label>
        <input
            id="name"
            name="name"
            type="text"
            class="form-control @error('name') is-invalid @enderror"
            value="{{ old('name', $isEditing ? $user->name : '') }}"
            maxlength="255"
            autocomplete="name"
            required
        >
        @error('name')
            <div class="invalid-feedback">{{ $message }}</div>
        @enderror
    </div>

    <div class="col-md-6">
        <label for="email" class="form-label">Email address</label>
        <input
            id="email"
            name="email"
            type="email"
            class="form-control @error('email') is-invalid @enderror"
            value="{{ old('email', $isEditing ? $user->email : '') }}"
            maxlength="255"
            autocomplete="email"
            required
        >
        @error('email')
            <div class="invalid-feedback">{{ $message }}</div>
        @enderror
    </div>

    <div class="col-md-6">
        <label for="role" class="form-label">Role</label>
        <select id="role" name="role" class="form-select @error('role') is-invalid @enderror" required>
            @foreach ($roles as $role)
                <option value="{{ $role->value }}" @selected($selectedRole === $role->value)>
                    {{ $role->label() }}
                </option>
            @endforeach
        </select>
        <div class="form-text">Staff, Intern, and Freelance can only access credentials explicitly shared with them. They cannot edit credentials or manage users or access grants.</div>
        @error('role')
            <div class="invalid-feedback">{{ $message }}</div>
        @enderror
    </div>

    <div class="col-md-6">
        <label for="account_expires_at" class="form-label">Account expiry</label>
        <input
            id="account_expires_at"
            name="account_expires_at"
            type="datetime-local"
            class="form-control @error('account_expires_at') is-invalid @enderror"
            value="{{ $expiresAt }}"
        >
        <div class="form-text">Leave blank if the account should not expire. For an Intern or Freelance account, consider setting the placement or contract end date.</div>
        @error('account_expires_at')
            <div class="invalid-feedback">{{ $message }}</div>
        @enderror
    </div>

    <div class="col-md-6">
        <label for="password" class="form-label">
            {{ $isEditing ? 'New password (optional)' : 'Temporary password' }}
        </label>
        <input
            id="password"
            name="password"
            type="password"
            class="form-control @error('password') is-invalid @enderror"
            autocomplete="new-password"
            @required(! $isEditing)
        >
        <div class="form-text">Use at least 12 characters with uppercase, lowercase, a number, and a symbol.</div>
        @error('password')
            <div class="invalid-feedback">{{ $message }}</div>
        @enderror
    </div>

    <div class="col-md-6">
        <label for="password_confirmation" class="form-label">Confirm password</label>
        <input
            id="password_confirmation"
            name="password_confirmation"
            type="password"
            class="form-control"
            autocomplete="new-password"
            @required(! $isEditing)
        >
    </div>

    <div class="col-12">
        <input type="hidden" name="is_active" value="0">
        <div class="form-check form-switch">
            <input
                id="is_active"
                name="is_active"
                type="checkbox"
                class="form-check-input @error('is_active') is-invalid @enderror"
                value="1"
                @checked($isActive)
            >
            <label for="is_active" class="form-check-label">Account is active</label>
        </div>
        @error('is_active')
            <div class="text-danger small mt-1">{{ $message }}</div>
        @enderror
    </div>
</div>

<div class="d-flex gap-2 mt-4">
    <button type="submit" class="btn btn-primary">
        {{ $isEditing ? 'Save changes' : 'Create user' }}
    </button>
    <a href="{{ route('admin.users.index') }}" class="btn btn-outline-secondary">Cancel</a>
</div>
