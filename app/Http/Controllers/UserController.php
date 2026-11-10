<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

/** Website logins (not the people enrolled on the terminal). */
class UserController extends Controller
{
    public function index()
    {
        return view('users.index', ['users' => User::orderBy('name')->get()]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'email' => ['required', 'email', 'max:190', 'unique:users,email'],
            'role' => ['required', Rule::in(array_keys(User::ROLES))],
            'password' => ['required', Password::min(8)],
        ]);
        User::create($data);

        return back()->with('success', "Login created for {$data['name']}.");
    }

    public function update(Request $request, User $user)
    {
        $data = $request->validate([
            'role' => ['required', Rule::in(array_keys(User::ROLES))],
            'password' => ['nullable', Password::min(8)],
        ]);

        if ($data['role'] !== 'admin' && $user->isAdmin() && $this->isLastAdmin($user)) {
            return back()->with('error', 'At least one administrator is needed, so this role was not changed.');
        }

        $user->role = $data['role'];
        if (! empty($data['password'])) {
            $user->password = $data['password'];
        }
        $user->save();

        return back()->with('success', "Saved changes for {$user->name}.");
    }

    public function destroy(Request $request, User $user)
    {
        if ($user->is($request->user())) {
            return back()->with('error', 'You cannot delete your own login.');
        }
        if ($user->isAdmin() && $this->isLastAdmin($user)) {
            return back()->with('error', 'At least one administrator is needed.');
        }
        $user->delete();

        return back()->with('success', "Deleted the login for {$user->name}.");
    }

    private function isLastAdmin(User $user): bool
    {
        return User::where('role', 'admin')->whereKeyNot($user->id)->doesntExist();
    }
}
