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
    // Claimed by a listener, not finished yet.
    const STATUS_PROCESSING = 3;

    protected $table = 'nostr_events';

    protected $dates = ['event_created_at'];

    /**
     * Reserve a wrap id before processing it. The unique index makes this atomic across
     * processes: the second claim of the same wrap fails and returns null.
     */
    public static function claim($mailbox_id, $wrap_id, $pubkey, $kind, $relay = null, $mailbox_pubkey = null)
    {
        $event = new self();
        $event->mailbox_id = $mailbox_id;
        $event->mailbox_pubkey = $mailbox_pubkey;
        $event->direction = self::DIRECTION_IN;
        $event->wrap_id = $wrap_id;
        $event->pubkey = (string) $pubkey;
        $event->kind = (int) $kind;
        $event->relay = $relay ? mb_substr($relay, 0, 255) : null;
        $event->status = self::STATUS_PROCESSING;
        try {
            $event->save();
        } catch (\Illuminate\Database\QueryException $e) {
            return null;
        }

        return $event;
    }

    /**
     * Attach the rumor id to a claimed wrap. Fails when another wrap of the same rumor
     * already did, which means the message is a duplicate.
     */
    public function claimRumor($rumor_id)
    {
        $this->rumor_id = $rumor_id;
        try {
            $this->save();
        } catch (\Illuminate\Database\QueryException $e) {
            $this->rumor_id = null;
            $this->syncOriginalAttribute('rumor_id');

            return false;
        }

        return true;
    }

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
