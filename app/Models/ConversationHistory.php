<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ConversationHistory extends Model
{
    protected $fillable = [
        'conversation_id',
        'sender',
        'message',
        'last_message_sent',
    ];


}
