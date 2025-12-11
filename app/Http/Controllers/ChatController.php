<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\User;
use App\Models\Message;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;

class ChatController extends Controller
{
    public function show(User $user)
    {
        // supply friends/incoming/users as you had in your existing friends index
        $friends = auth()->user()->friends ?? collect();
        $incoming = collect(); // adapt if you have incoming queries
        $users = User::where('id', '!=', auth()->id())->get();

        return view('chat.show', compact('user','friends','incoming','users'));
    }

    public function messages(User $user)
    {
        $me = Auth::user();

        $messages = Message::where(function($q) use ($me,$user){
            $q->where('sender_id',$me->id)->where('receiver_id',$user->id);
        })->orWhere(function($q) use ($me,$user){
            $q->where('sender_id',$user->id)->where('receiver_id',$me->id);
        })->orderBy('created_at')->get();

        return response()->json($messages->load('sender'));
    }

    public function send(Request $request)
    {
        $request->validate([
            'receiver_id' => 'required|exists:users,id',
            // body is ciphertext base64 (client-side encryption)
            'body' => 'nullable|string',
            'enc_algo' => 'nullable|string',
            'iv' => 'nullable|string',
            'tag' => 'nullable|string',
            'encrypted_keys' => 'required|json',
            'attachment' => 'nullable|file|max:20480', // encrypted file blob
            'mime' => 'nullable|string',
            'meta' => 'nullable|json'
        ]);

        $me = Auth::user();
        $attachmentPath = null;

        if ($request->hasFile('attachment')) {
            $file = $request->file('attachment');
            $attachmentPath = $file->store('messages', 'public'); // store encrypted blob
        }

        $msg = Message::create([
            'sender_id' => $me->id,
            'receiver_id' => $request->receiver_id,
            'body' => $request->body,
            'attachment' => $attachmentPath,
            'mime' => $request->mime,
            'enc_algo' => $request->enc_algo ?? 'aes-256-gcm',
            'iv' => $request->iv,
            'tag' => $request->tag,
            'encrypted_keys' => json_decode($request->encrypted_keys, true),
            'meta' => $request->meta ? json_decode($request->meta, true) : null,
        ]);

        // optionally broadcast event here (ciphertext only)

        return response()->json($msg->load('sender'));
    }
}
