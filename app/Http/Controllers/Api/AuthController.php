<?php

namespace App\Http\Controllers\Api;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class AuthController
{
    public function register(Request $request)
    {
        $data = $request->validate([
            'name' => ['required', 'string'],
            'email' => ['required', 'email'],
            'password' => ['required', 'string', 'min:6'],
            'businessName' => ['nullable', 'string'],
            'phone' => ['nullable', 'string'],
        ]);

        if (User::where('email', $data['email'])->exists()) {
            return response()->json(['error' => 'Email already registered'], 409);
        }

        $user = User::create([
            'name' => $data['name'],
            'email' => $data['email'],
            'password' => Hash::make($data['password']),
            'businessName' => $data['businessName'] ?? null,
            'phone' => $data['phone'] ?? null,
            'role' => 'vendor',
        ]);

        return response()->json([
            'user' => $user->only(['_id', 'name', 'email', 'businessName', 'role']),
        ], 201);
    }
}
