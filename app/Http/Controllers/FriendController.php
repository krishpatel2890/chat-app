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
        \Log::info('FriendController@index', [
            'user_id' => auth()->id()
        ]);

        $me = auth()->user();

        $users = User::where('id', '!=', $me->id)->orderBy('name')->get();

        $incoming = FriendRequest::where('receiver_id', $me->id)
            ->where('status', 'pending')
            ->with('sender')
            ->orderBy('created_at', 'desc')
            ->get();

        $sent = FriendRequest::where('sender_id', $me->id)
            ->with('receiver')
            ->orderBy('created_at', 'desc')
            ->get();

        \Log::info('FriendController@index data', [
            'incoming_count' => $incoming->count(),
            'sent_count' => $sent->count()
        ]);

        $friends = $me->friends();  // You already fixed this alias

        \Log::info('FriendController@index friends_count', [
            'count' => $friends->count()
        ]);

        return view('friends.index', compact('users','incoming','sent','friends'));
    }

    public function send(Request $request)
    {
        \Log::info('FriendController@send', [
            'sender_id' => auth()->id(),
            'payload' => $request->all()
        ]);

        $request->validate([
            'receiver_id' => 'required|integer|exists:users,id'
        ]);

        $receiver = (int)$request->receiver_id;
        $sender = auth()->id();

        if ($sender === $receiver) {
            \Log::warning('FriendController@send attempted self-request');
            return response()->json(['error' => 'Cannot send request to yourself'], 422);
        }

        if (auth()->user()->isFriendsWith($receiver)) {
            \Log::warning('FriendController@send already friends', ['receiver' => $receiver]);
            return response()->json(['error' => 'Already friends'], 422);
        }

        $exists = FriendRequest::where(function($q) use ($sender, $receiver){
            $q->where('sender_id', $sender)->where('receiver_id', $receiver);
        })->orWhere(function($q) use ($sender, $receiver){
            $q->where('sender_id', $receiver)->where('receiver_id', $sender);
        })->first();

        if ($exists) {
            \Log::warning('FriendController@send request already exists');
            return response()->json(['error' => 'Request already exists or pending'], 422);
        }

        $req = FriendRequest::create([
            'sender_id' => $sender,
            'receiver_id' => $receiver,
            'status' => 'pending'
        ]);

        \Log::info('FriendController@send created', ['id' => $req->id]);

        return response()->json($req);
    }

    public function cancel(Request $request)
    {
        \Log::info('FriendController@cancel', [
            'sender_id' => auth()->id(),
            'payload' => $request->all()
        ]);

        $request->validate([
            'receiver_id' => 'required|integer|exists:users,id'
        ]);

        $receiver = (int)$request->receiver_id;
        $sender = auth()->id();

        $req = FriendRequest::where('sender_id', $sender)->where('receiver_id', $receiver)->first();
        if (!$req) {
            \Log::warning('FriendController@cancel request_not_found');
            return response()->json(['error' => 'No request found to cancel'], 404);
        }

        $req->delete();

        \Log::info('FriendController@cancel deleted');

        return response()->json(['success' => true]);
    }

    public function accept(Request $request)
    {
        \Log::info('FriendController@accept', [
            'receiver_id' => auth()->id(),
            'payload' => $request->all()
        ]);

        $request->validate([
            'request_id' => 'required|integer|exists:friend_requests,id'
        ]);

        $req = FriendRequest::find($request->request_id);

        if (!$req || $req->receiver_id !== auth()->id()) {
            \Log::warning('FriendController@accept invalid_request');
            return response()->json(['error' => 'Invalid request'], 403);
        }

        $a = min($req->sender_id, $req->receiver_id);
        $b = max($req->sender_id, $req->receiver_id);

        if (!Friendship::where('user_one', $a)->where('user_two', $b)->exists()) {
            Friendship::create(['user_one' => $a, 'user_two' => $b]);
            \Log::info('FriendController@accept friendship_created');
        }

        $req->delete();
        \Log::info('FriendController@accept request_deleted');

        return response()->json(['success' => true]);
    }

    public function reject(Request $request)
    {
        \Log::info('FriendController@reject', [
            'receiver_id' => auth()->id(),
            'payload' => $request->all()
        ]);

        $request->validate([
            'request_id' => 'required|integer|exists:friend_requests,id'
        ]);

        $req = FriendRequest::find($request->request_id);
        if (!$req || $req->receiver_id !== auth()->id()) {
            \Log::warning('FriendController@reject invalid_request');
            return response()->json(['error' => 'Invalid request'], 403);
        }

        $req->delete();
        \Log::info('FriendController@reject request_deleted');

        return response()->json(['success' => true]);
    }

    public function friendsList()
    {
        \Log::info('FriendController@friendsList', [
            'user_id' => auth()->id()
        ]);

        $friends = auth()->user()->friends();

        \Log::info('FriendController@friendsList count', [
            'count' => $friends->count()
        ]);

        return response()->json($friends);
    }
}
