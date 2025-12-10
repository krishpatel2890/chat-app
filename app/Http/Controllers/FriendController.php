<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\User;
use App\Models\FriendRequest;
use App\Models\Friendship;
use Illuminate\Support\Facades\Auth;

class FriendController extends Controller
{
    /**
     * Show friends page: users list, incoming, sent, friends
     */
    public function index()
    {
        $me = auth()->user();

        // All other users (for Add Friend)
        $users = User::where('id', '!=', $me->id)->orderBy('name')->get();

        // Incoming pending requests to me
        $incoming = FriendRequest::where('receiver_id', $me->id)
            ->where('status', 'pending')
            ->with('sender')
            ->orderBy('created_at', 'desc')
            ->get();

        // Sent requests by me (any status)
        $sent = FriendRequest::where('sender_id', $me->id)
            ->with('receiver')
            ->orderBy('created_at', 'desc')
            ->get();

        // Friends list (User collection)
        $friends = $me->friends();

        return view('friends.index', compact('users','incoming','sent','friends'));
    }

    /**
     * Send friend request
     */
    public function send(Request $request)
    {
        $request->validate([
            'receiver_id' => 'required|integer|exists:users,id'
        ]);

        $receiver = (int)$request->receiver_id;
        $sender = auth()->id();

        if ($sender === $receiver) {
            return response()->json(['error' => 'Cannot send request to yourself'], 422);
        }

        // if already friends
        if (auth()->user()->isFriendsWith($receiver)) {
            return response()->json(['error' => 'Already friends'], 422);
        }

        // if a request exists in either direction
        $exists = FriendRequest::where(function($q) use ($sender, $receiver){
            $q->where('sender_id', $sender)->where('receiver_id', $receiver);
        })->orWhere(function($q) use ($sender, $receiver){
            $q->where('sender_id', $receiver)->where('receiver_id', $sender);
        })->first();

        if ($exists) {
            return response()->json(['error' => 'Request already exists or pending'], 422);
        }

        $req = FriendRequest::create([
            'sender_id' => $sender,
            'receiver_id' => $receiver,
            'status' => 'pending'
        ]);

        return response()->json($req);
    }

    /**
     * Cancel a sent request (sender cancels)
     */
    public function cancel(Request $request)
    {
        $request->validate([
            'receiver_id' => 'required|integer|exists:users,id'
        ]);

        $receiver = (int)$request->receiver_id;
        $sender = auth()->id();

        $req = FriendRequest::where('sender_id', $sender)->where('receiver_id', $receiver)->first();
        if (!$req) {
            return response()->json(['error' => 'No request found to cancel'], 404);
        }

        $req->delete();
        return response()->json(['success' => true]);
    }

    /**
     * Accept an incoming request (receiver accepts)
     */
    public function accept(Request $request)
    {
        $request->validate([
            'request_id' => 'required|integer|exists:friend_requests,id'
        ]);

        $reqId = (int)$request->request_id;
        $req = FriendRequest::find($reqId);
        if (!$req || $req->receiver_id !== auth()->id()) {
            return response()->json(['error' => 'Invalid request'], 403);
        }

        // Create friendship pair (store min/max to keep unique index consistent)
        $a = min($req->sender_id, $req->receiver_id);
        $b = max($req->sender_id, $req->receiver_id);

        if (!Friendship::where('user_one', $a)->where('user_two', $b)->exists()) {
            Friendship::create(['user_one' => $a, 'user_two' => $b]);
        }

        // Delete the friend request (we no longer need it)
        $req->delete();

        return response()->json(['success' => true]);
    }

    /**
     * Reject an incoming request (receiver rejects)
     */
    public function reject(Request $request)
    {
        $request->validate([
            'request_id' => 'required|integer|exists:friend_requests,id'
        ]);

        $reqId = (int)$request->request_id;
        $req = FriendRequest::find($reqId);
        if (!$req || $req->receiver_id !== auth()->id()) {
            return response()->json(['error' => 'Invalid request'], 403);
        }

        $req->delete();
        return response()->json(['success' => true]);
    }

    /**
     * Return friends list as JSON (optional helper)
     */
    public function friendsList()
    {
        $friends = auth()->user()->friends();
        return response()->json($friends);
    }
}
