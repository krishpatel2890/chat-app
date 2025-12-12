<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class UserKeyController extends Controller
{
    public function store(Request $request)
    {
        \Log::info('UserKeyController@store called', [
            'user_id' => auth()->id(),
            'payload_length' => strlen($request->public_key ?? '')
        ]);

        $request->validate([
            'public_key' => 'required|string',
            'public_key_alg' => 'nullable|string'
        ]);

        $user = Auth::user();
        if (!$user) {
            \Log::warning('UserKeyController@store NO_AUTH');
            return response()->json(['error' => 'Unauthenticated'], 401);
        }

        $user->public_key = $request->public_key;
        $user->public_key_alg = $request->public_key_alg ?? 'rsa-oaep-sha256';
        $user->save();

        \Log::info('UserKeyController@store KEY_SAVED', [
            'user_id' => $user->id
        ]);

        return response()->json(['ok' => true]);
    }

    public function show(Request $request)
    {
        \Log::info('UserKeyController@show', [
            'user_id' => auth()->id()
        ]);

        $user = auth()->user();
        if (!$user)
            return response()->json(['public_key' => null], 200);

        return response()->json([
            'public_key' => $user->public_key,
            'public_key_alg' => $user->public_key_alg
        ], 200);
    }
}
