<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    /** @use HasFactory<\Database\Factories\UserFactory> */
    use HasFactory, Notifiable;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'public_key',
        'public_key_alg',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    //
    // -----------------------------
    // Friend / FriendRequest helpers
    // -----------------------------
    //

    /**
     * Friend requests sent by this user.
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function sentRequests()
    {
        return $this->hasMany(FriendRequest::class, 'sender_id');
    }

    /**
     * Friend requests received by this user.
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function receivedRequests()
    {
        return $this->hasMany(FriendRequest::class, 'receiver_id');
    }

    /**
     * Friendships where this user is stored in user_one column.
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function friendshipsOne()
    {
        return $this->hasMany(Friendship::class, 'user_one');
    }

    /**
     * Friendships where this user is stored in user_two column.
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function friendshipsTwo()
    {
        return $this->hasMany(Friendship::class, 'user_two');
    }

    /**
     * Return a Collection of User models that are friends with this user.
     *
     * Note: This method loads friendships (both directions) and returns the corresponding User models.
     *
     * @return \Illuminate\Support\Collection|\Illuminate\Database\Eloquent\Collection
     */
    public function friends()
    {
        // get ids where this user is user_one
        $one = Friendship::where('user_one', $this->id)->pluck('user_two')->toArray();

        // get ids where this user is user_two
        $two = Friendship::where('user_two', $this->id)->pluck('user_one')->toArray();

        $ids = array_merge($one, $two);

        if (empty($ids)) {
            return collect([]);
        }

        return User::whereIn('id', $ids)->get();
    }

    /**
     * Check if this user is friends with given user id.
     * Ensures consistent ordering for the unique friendship constraint.
     *
     * @param  int  $userId
     * @return bool
     */
    public function isFriendsWith($userId)
    {
        $min = min($this->id, (int) $userId);
        $max = max($this->id, (int) $userId);

        return Friendship::where('user_one', $min)->where('user_two', $max)->exists();
    }

    /**
     * Convenience: Check if there is a pending friend request between this user and another user.
     * It checks both directions.
     *
     * @param int $otherUserId
     * @return bool
     */
    public function hasPendingRequestWith($otherUserId)
    {
        return FriendRequest::where(function ($q) use ($otherUserId) {
            $q->where('sender_id', $this->id)->where('receiver_id', $otherUserId);
        })->orWhere(function ($q) use ($otherUserId) {
            $q->where('sender_id', $otherUserId)->where('receiver_id', $this->id);
        })->where('status', 'pending')->exists();
    }

    /**
     * Convenience: Get the pending friend request (if any) between this user and another user.
     * Returns null if none exists.
     *
     * @param int $otherUserId
     * @return \App\Models\FriendRequest|null
     */
    public function pendingRequestWith($otherUserId)
    {
        return FriendRequest::where(function ($q) use ($otherUserId) {
            $q->where('sender_id', $this->id)->where('receiver_id', $otherUserId);
        })->orWhere(function ($q) use ($otherUserId) {
            $q->where('sender_id', $otherUserId)->where('receiver_id', $this->id);
        })->where('status', 'pending')->first();
    }

    public function sentMessages()
    {
        return $this->hasMany(\App\Models\Message::class, 'sender_id');
    }
    public function receivedMessages()
    {
        return $this->hasMany(\App\Models\Message::class, 'receiver_id');
    }

}
