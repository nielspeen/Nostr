<?php

namespace Modules\Nostr\Entities;

use Illuminate\Database\Eloquent\Model;

class Announcement extends Model
{
    protected $table = 'nostr_announcements';
    protected $casts = ['event' => 'array', 'relay_results' => 'array'];
}
