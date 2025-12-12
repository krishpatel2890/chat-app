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
        \Log::info('ChatController@show', [
            'viewer_id' => auth()->id(),
            'chat_with' => $user->id
        ]);

        $auth = auth()->user();

        $friends = $auth->acceptedFriends();
        $incoming = $auth->receivedRequests()->with('sender')->get();
        $sent = $auth->sentRequests()->with('receiver')->get();
        $users = User::where('id', '!=', $auth->id)->get();

        return view('chat.show', compact('user', 'friends', 'incoming', 'users', 'sent'));
    }


    public function messages(User $user)
    {
        $me = Auth::user();

        \Log::info('ChatController@messages fetch', [
            'me' => $me->id,
            'with' => $user->id
        ]);

        $messages = Message::where(function ($q) use ($me, $user) {
            $q->where('sender_id', $me->id)->where('receiver_id', $user->id);
        })->orWhere(function ($q) use ($me, $user) {
            $q->where('sender_id', $user->id)->where('receiver_id', $me->id);
        })->orderBy('created_at')->get();

        \Log::info('ChatController@messages count', [
            'count' => $messages->count()
        ]);

        return response()->json($messages->load('sender'));
    }

    public function send(Request $request)
    {
        \Log::info('ChatController@send called', [
            'sender_id' => auth()->id(),
            'raw_payload' => $request->all()
        ]);

        $request->validate([
            'receiver_id' => 'required|exists:users,id',
            'body' => 'nullable|string',
            'enc_algo' => 'nullable|string',
            'iv' => 'nullable|string',
            'tag' => 'nullable|string',
            'encrypted_keys' => 'required|json',
            'attachment' => 'nullable|file|max:20480',
            'mime' => 'nullable|string',
            'meta' => 'nullable|json'
        ]);

        \Log::info('ChatController@send validated');

        $me = Auth::user();
        $attachmentPath = null;

        if ($request->hasFile('attachment')) {
            $file = $request->file('attachment');
            $attachmentPath = $file->store('messages', 'public');

            \Log::info('ChatController@send attachment_saved', [
                'path' => $attachmentPath,
                'mime' => $request->mime
            ]);
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

        \Log::info('ChatController@send message_created', [
            'message_id' => $msg->id
        ]);

        return response()->json($msg->load('sender'));
    }
}
