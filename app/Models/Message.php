<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Message extends Model
{
    protected $fillable = [
        'sender_id','receiver_id','body','attachment','mime',
        'enc_algo','iv','tag','encrypted_keys','meta','is_read'
    ];

    protected $casts = [
        'encrypted_keys' => 'array',
        'meta' => 'array',
        'is_read' => 'boolean',
    ];

    public function sender() { return $this->belongsTo(User::class, 'sender_id'); }
    public function receiver() { return $this->belongsTo(User::class, 'receiver_id'); }
}
