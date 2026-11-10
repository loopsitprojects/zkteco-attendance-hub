@extends('layouts.app')

@section('title', 'Logins')
@section('subtitle', 'Who can sign in to this website')

@section('content')
<div class="grid-2">
    <div class="card">
        <div class="card-header"><div><h2>Website logins</h2><p>Administrators can sync, change settings, control the terminal and manage logins. Viewers can only view and export.</p></div></div>
        <div class="table-wrap">
            <table>
                <thead><tr><th>Name</th><th>Role</th><th>New password</th><th></th></tr></thead>
                <tbody>
                @foreach ($users as $u)
                    <tr>
                        <td>
                            <span class="person"><span class="avatar">{{ strtoupper(mb_substr($u->name, 0, 1)) }}</span>
                                <span>{{ $u->name }}<br><small class="text-muted" style="font-weight: 400;">{{ $u->email }}</small></span>
                            </span>
                        </td>
                        <td colspan="2">
                            <form method="POST" action="{{ route('users.update', $u) }}" style="display: flex; gap: 8px;">
                                @csrf
                                @method('PUT')
                                <select name="role" class="select" aria-label="Role for {{ $u->name }}">
                                    @foreach (\App\Models\User::ROLES as $value => $label)
                                        <option value="{{ $value }}" @selected($u->role === $value)>{{ $label }}</option>
                                    @endforeach
                                </select>
                                <input type="password" name="password" class="input" placeholder="Unchanged" autocomplete="new-password" aria-label="New password for {{ $u->name }}" style="width: 170px;">
                                <button class="btn btn-secondary btn-sm" style="height: 38px;">Save</button>
                            </form>
                        </td>
                        <td>
                            @unless ($u->is(auth()->user()))
                                <form method="POST" action="{{ route('users.destroy', $u) }}" onsubmit="return confirm({{ \Illuminate\Support\Js::from('Delete the login for '.$u->name.'?') }});">
                                    @csrf
                                    @method('DELETE')
                                    <button class="btn btn-danger btn-sm">Delete</button>
                                </form>
                            @else
                                <span class="badge badge-neutral">You</span>
                            @endunless
                        </td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
    </div>

    <div class="card">
        <div class="card-header"><h2>Add a login</h2></div>
        <div class="card-body">
            <form method="POST" action="{{ route('users.store') }}">
                @csrf
                <div class="field">
                    <label for="new_name">Name</label>
                    <input class="input" id="new_name" name="name" value="{{ old('name') }}" required>
                </div>
                <div class="field">
                    <label for="new_email">Email</label>
                    <input class="input" type="email" id="new_email" name="email" value="{{ old('email') }}" required>
                </div>
                <div class="field">
                    <label for="new_role">Role</label>
                    <select class="select" id="new_role" name="role">
                        <option value="viewer">Viewer (view and export only)</option>
                        <option value="admin">Administrator (full access)</option>
                    </select>
                </div>
                <div class="field">
                    <label for="new_password">Password</label>
                    <input class="input" type="password" id="new_password" name="password" minlength="8" required autocomplete="new-password">
                    <span class="help">At least 8 characters. Share it with the person privately.</span>
                </div>
                <button class="btn btn-primary">Create login</button>
            </form>
        </div>
    </div>
</div>
@endsection
