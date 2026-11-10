<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sign in · {{ config('app.name') }}</title>
    <link rel="stylesheet" href="{{ asset('css/app.css') }}?v={{ filemtime(public_path('css/app.css')) }}">
</head>
<body>
<div class="auth">
    <div class="card auth-card">
        <div class="brand">
            <span class="brand-mark"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 2a10 10 0 1 0 0 20 10 10 0 0 0 0-20zm0 4a3 3 0 1 1 0 6 3 3 0 0 1 0-6zm0 14.2a7.2 7.2 0 0 1-6-3.22c.03-1.99 4-3.08 6-3.08s5.97 1.09 6 3.08a7.2 7.2 0 0 1-6 3.22z"/></svg></span>
            <div>
                <strong>{{ config('app.name') }}</strong>
                <small>ZKTeco attendance</small>
            </div>
        </div>
        <h1>Sign in</h1>
        <p>Use the login your administrator gave you.</p>

        @if ($errors->any())
            <div class="alert alert-danger" role="alert" style="margin-bottom: 16px;">{{ $errors->first() }}</div>
        @endif

        <form method="POST" action="{{ url('/login') }}">
            @csrf
            <div class="field">
                <label for="email">Email</label>
                <input class="input" type="email" id="email" name="email" value="{{ old('email') }}" required autofocus autocomplete="username">
            </div>
            <div class="field">
                <label for="password">Password</label>
                <input class="input" type="password" id="password" name="password" required autocomplete="current-password">
            </div>
            <label class="checkbox" style="margin-bottom: 20px;">
                <input type="checkbox" name="remember" value="1"> Keep me signed in on this computer
            </label>
            <button type="submit" class="btn btn-primary btn-block">Sign in</button>
        </form>
    </div>
</div>
<script src="{{ asset('js/password-toggle.js') }}?v={{ filemtime(public_path('js/password-toggle.js')) }}"></script>
</body>
</html>
