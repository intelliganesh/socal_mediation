<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;

class UserController extends Controller
{
    public function index()
    {
        $this->authorizeGlobalAdmin();

        return view('admin.users.index', [
            'users' => User::query()->orderBy('name')->paginate(12),
        ]);
    }

    public function create()
    {
        $this->authorizeGlobalAdmin();

        return view('admin.users.form', [
            'user' => new User,
            'mode' => 'create',
        ]);
    }

    public function store(Request $request)
    {
        $this->authorizeGlobalAdmin();

        $data = $this->validatedData($request);

        User::create([
            'name' => $data['name'],
            'email' => $data['email'],
            'password' => Hash::make($data['password']),
            'role' => 'admin',
            'application' => $data['application'] ?: null,
        ]);

        return redirect()->route('admin.users.index')->with('status', 'Admin user created.');
    }

    public function edit(User $user)
    {
        $this->authorizeGlobalAdmin();

        return view('admin.users.form', [
            'user' => $user,
            'mode' => 'edit',
        ]);
    }

    public function update(Request $request, User $user)
    {
        $this->authorizeGlobalAdmin();

        $data = $this->validatedData($request, $user);
        $updates = [
            'name' => $data['name'],
            'email' => $data['email'],
            'role' => 'admin',
            'application' => $data['application'] ?: null,
        ];

        if ($user->email === 'admin@socal.test') {
            $updates['email'] = 'admin@socal.test';
            $updates['application'] = null;
        }

        if (filled($data['password'] ?? null)) {
            $updates['password'] = Hash::make($data['password']);
        }

        $user->update($updates);

        return redirect()->route('admin.users.index')->with('status', 'Admin user updated.');
    }

    private function validatedData(Request $request, ?User $user = null): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => [
                'required',
                'email',
                'max:255',
                Rule::unique('users', 'email')->ignore($user),
            ],
            'password' => [$user ? 'nullable' : 'required', 'string', 'min:8'],
            'application' => ['nullable', 'string', Rule::in(['socal', 'legal'])],
        ]);
    }

    private function authorizeGlobalAdmin(): void
    {
        abort_unless(auth()->user()?->isGlobalAdmin(), 403);
    }
}
