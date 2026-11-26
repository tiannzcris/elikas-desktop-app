@extends('layouts.app')

@section('title', 'Change password')

@section('content')
    <div class="page-header">
        <div class="min-w-0">
            <h1 class="page-title">{{ $currentUser->must_change_password ? 'Choose your own password' : 'Change password' }}</h1>
            <p class="page-subtitle">{{ $currentUser->name }}{{ $currentUser->username ? ' · '.$currentUser->username : '' }}</p>
        </div>
    </div>

    @if ($currentUser->must_change_password)
        <div class="callout callout-warning flex items-start gap-3 mb-6 max-w-2xl" role="status" data-password-change-required>
            <i class="ti ti-key shrink-0 mt-0.5" style="font-size: 18px;" aria-hidden="true"></i>
            <p>Your account has a temporary password from the administrator. Choose your own before anything else: until then the server accepts nothing from this account. Needs a connection.</p>
        </div>
    @endif

    <form method="POST" action="{{ route('password.update') }}" class="card card-body max-w-2xl flex flex-col gap-4">
        @csrf
        <div>
            <label for="current-password" class="label">{{ $currentUser->must_change_password ? 'Temporary password' : 'Current password' }}</label>
            <input id="current-password" type="password" name="current_password" class="input" required autofocus autocomplete="current-password" @error('current_password') aria-invalid="true" @enderror>
            @error('current_password')<p class="field-error">{{ $message }}</p>@enderror
        </div>
        <div>
            <label for="new-password" class="label">New password</label>
            <input id="new-password" type="password" name="password" class="input" required minlength="{{ \App\Http\Controllers\PasswordController::MIN_LENGTH }}" autocomplete="new-password" @error('password') aria-invalid="true" @enderror>
            <p class="help">At least {{ \App\Http\Controllers\PasswordController::MIN_LENGTH }} characters, different from the current one.</p>
            @error('password')<p class="field-error">{{ $message }}</p>@enderror
        </div>
        <div>
            <label for="new-password-2" class="label">New password again</label>
            <input id="new-password-2" type="password" name="password_confirmation" class="input" required autocomplete="new-password">
        </div>
        <div class="form-actions mt-2"><button type="submit" class="btn btn-primary" data-online-only>Change password</button></div>
    </form>
@endsection
