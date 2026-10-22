@php($editing = isset($credential))

<div class="row g-4">
    <div class="col-lg-7">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-body p-4">
                <h2 class="h5 mb-3">Account details</h2>

                <div class="mb-3">
                    <label for="title" class="form-label">Account name</label>
                    <input id="title" name="title" class="form-control @error('title') is-invalid @enderror" value="{{ old('title', $credential->title ?? '') }}" maxlength="120" required autofocus>
                    @error('title')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>

                <div class="mb-3">
                    <label for="category" class="form-label">Category</label>
                    <input id="category" name="category" list="category-options" class="form-control @error('category') is-invalid @enderror" value="{{ old('category', $credential->category ?? '') }}" maxlength="60" placeholder="For example: Social Media">
                    <datalist id="category-options">
                        <option value="Social Media">
                        <option value="Advertising">
                        <option value="Design">
                        <option value="Analytics">
                        <option value="Productivity">
                    </datalist>
                    @error('category')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>

                <div class="mb-0">
                    <label for="website_url" class="form-label">Website URL</label>
                    <input id="website_url" type="url" name="website_url" class="form-control @error('website_url') is-invalid @enderror" value="{{ old('website_url', $credential->website_url ?? '') }}" maxlength="2048" placeholder="https://example.com">
                    @error('website_url')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
            </div>
        </div>
    </div>

    <div class="col-lg-5">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-body p-4">
                <div class="d-flex justify-content-between gap-3 mb-3">
                    <div>
                        <h2 class="h5 mb-1">Encrypted login</h2>
                        <p class="small text-secondary mb-0">Encrypted with AES-256-GCM before storage.</p>
                    </div>
                    <span class="security-pill">GCM</span>
                </div>

                @if ($editing)
                    <input type="hidden" name="lock_version" value="{{ $credential->lock_version }}">
                    @error('lock_version')<div class="alert alert-danger">{{ $message }}</div>@enderror
                    <div class="form-check form-switch mb-3">
                        <input class="form-check-input" type="checkbox" role="switch" id="replace_secret" name="replace_secret" value="1" @checked(old('replace_secret')) data-secret-replacement-toggle>
                        <label class="form-check-label" for="replace_secret">Replace the encrypted login details</label>
                    </div>
                @endif

                <div id="secret-fields" data-secret-fields @if($editing && !old('replace_secret')) hidden @endif>
                    <div class="mb-3">
                        <label for="vault_username" class="form-label">Username or email</label>
                        <input id="vault_username" name="vault_username" class="form-control @error('vault_username') is-invalid @enderror" value="" maxlength="255" autocomplete="off">
                        @error('vault_username')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>

                    <div class="mb-3">
                        <label for="vault_password" class="form-label">Password or secret</label>
                        <input id="vault_password" type="password" name="vault_password" class="form-control @error('vault_password') is-invalid @enderror" maxlength="4096" autocomplete="new-password" @required(!$editing)>
                        @error('vault_password')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>

                    <div class="mb-0">
                        <label for="vault_notes" class="form-label">Secure notes</label>
                        <textarea id="vault_notes" name="vault_notes" class="form-control @error('vault_notes') is-invalid @enderror" rows="4" maxlength="4000" autocomplete="off"></textarea>
                        @error('vault_notes')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                </div>

                @if ($editing)
                    <p id="secret-retained-message" data-secret-retained-message class="small text-secondary mb-0" @if(old('replace_secret')) hidden @endif>
                        Existing encrypted login details will remain unchanged.
                    </p>
                @endif
            </div>
        </div>
    </div>
</div>

<div class="d-flex justify-content-end gap-2 mt-4">
    <a class="btn btn-outline-secondary" href="{{ $editing ? route('credentials.show', $credential) : route('credentials.index') }}">Cancel</a>
    <button class="btn btn-primary px-4" type="submit">{{ $editing ? 'Save changes' : 'Encrypt and save' }}</button>
</div>
