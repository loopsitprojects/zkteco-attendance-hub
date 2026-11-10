<?php

namespace App\Http\Controllers;

use Illuminate\Support\Facades\DB;

class EmployeeController extends Controller
{
    public function index()
    {
        $employees = DB::table('employees')->where('on_device', true)->get()->map(function ($row) {
            $role = (int) $row->role;

            return [
                'uid' => (int) $row->device_uid,
                'user_id' => (string) $row->user_id,
                'name' => (string) $row->name !== '' && $row->name !== null ? (string) $row->name : 'No name on device',
                'role' => $role === 14 ? 'Admin' : ($role === 0 ? 'User' : "Role {$role}"),
                'role_val' => $role,
                'group' => (string) $row->group_name,
                'card' => (string) $row->card,
            ];
        })->sort(fn ($a, $b) => [$b['role_val'], strtolower($a['name'])] <=> [$a['role_val'], strtolower($b['name'])])->values();

        return view('employees.index', [
            'employees' => $employees,
            'adminCount' => $employees->where('role', 'Admin')->count(),
        ]);
    }
}
