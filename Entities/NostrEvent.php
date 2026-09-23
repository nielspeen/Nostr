<?php

namespace Modules\Nostr\Entities;

use Illuminate\Database\Eloquent\Model;

/**
 * Log of incoming and outgoing gift wraps.
 */
class NostrEvent extends Model
{
    const DIRECTION_IN = 1;
    const DIRECTION_OUT = 2;

    const STATUS_OK = 1;
    const STATUS_FAILED = 2;

    protected $table = 'nostr_events';

    protected $dates = ['event_created_at'];

    public static function seenWrap($wrap_id)
    {
        return self::where('wrap_id', $wrap_id)->exists();
    }

    public static function seenRumor($rumor_id)
    {
        return self::where('rumor_id', $rumor_id)->exists();
    }

    /**
     * Latest incoming message of a conversation: tells which key to reply to.
     */
    public static function lastIncoming($conversation_id)
    {
        return self::where('conversation_id', $conversation_id)
            ->where('direction', self::DIRECTION_IN)
            ->orderBy('id', 'desc')
            ->first();
    }

    public static function sentForThread($thread_id)
    {
        return self::where('thread_id', $thread_id)
            ->where('direction', self::DIRECTION_OUT)
            ->where('status', self::STATUS_OK)
            ->exists();
    }

    public function getRelays()
    {
        $relays = $this->relays ? json_decode($this->relays, true) : null;

        return is_array($relays) ? $relays : [];
    }
}
