<?php

namespace Modules\Nostr\Entities;

use Illuminate\Database\Eloquent\Model;
use Modules\Nostr\Services\Keys;

/**
 * A retired mailbox key. It is no longer announced, but messages sent to it are
 * still received and its conversations are still answered from it.
 */
class MailboxKey extends Model
{
    protected $table = 'nostr_mailbox_keys';

    protected $dates = ['key_created_at', 'retired_at'];

    public function getPrivateKey()
    {
        return $this->private_key ? (string) \Helper::decrypt($this->private_key) : '';
    }

    public function getNpub()
    {
        return Keys::npub($this->pubkey);
    }

    public function getShortNpub()
    {
        return Keys::shortNpub($this->pubkey);
    }

    /**
     * Messages received on this key.
     */
    public function getMessageCount()
    {
        return NostrEvent::where('mailbox_pubkey', $this->pubkey)
            ->where('direction', NostrEvent::DIRECTION_IN)
            ->whereNotNull('thread_id')
            ->count();
    }

    public function getLastMessageAt()
    {
        return NostrEvent::where('mailbox_pubkey', $this->pubkey)
            ->where('direction', NostrEvent::DIRECTION_IN)
            ->whereNotNull('thread_id')
            ->max('created_at');
    }
}
