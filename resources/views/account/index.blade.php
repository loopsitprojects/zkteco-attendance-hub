@extends('layouts.app')

@section('title', 'My account')
@section('subtitle', auth()->user()->email)

@section('content')
<div class="grid-2">
    <div class="card">
        <div class="card-header"><h2>Change password</h2></div>
        <div class="card-body">
            <form method="POST" action="{{ route('account.password') }}">
                @csrf
                @method('PUT')
                <div class="field">
                    <label for="current_password">Current password</label>
                    <input class="input" type="password" id="current_password" name="current_password" required autocomplete="current-password">
                </div>
                <div class="field">
                    <label for="password">New password</label>
                    <input class="input" type="password" id="password" name="password" minlength="8" required autocomplete="new-password">
                </div>
                <div class="field">
                    <label for="password_confirmation">Repeat new password</label>
                    <input class="input" type="password" id="password_confirmation" name="password_confirmation" minlength="8" required autocomplete="new-password">
                </div>
                <button class="btn btn-primary">Change password</button>
            </form>
        </div>
    </div>
</div>
@endsection
