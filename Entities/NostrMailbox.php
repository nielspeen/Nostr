<?php

namespace Modules\Nostr\Entities;

use Illuminate\Database\Eloquent\Model;
use Modules\Nostr\Services\Keys;

/**
 * Per-mailbox Nostr identity and settings.
 */
class NostrMailbox extends Model
{
    protected $table = 'nostr_mailboxes';

    protected $fillable = [
        'mailbox_id', 'enabled', 'inbox_relays', 'announce_relays',
        'profile_name', 'profile_about', 'profile_picture', 'nip05_name',
        'auto_reply_enabled', 'auto_reply_text', 'reopen_days',
    ];

    protected $dates = ['last_announced_at', 'last_event_at'];

    public function mailbox()
    {
        return $this->belongsTo('App\Mailbox');
    }

    /**
     * Settings row for a mailbox (creates an unsaved one with defaults if missing).
     */
    public static function forMailbox($mailbox_id, $create = true)
    {
        $row = self::where('mailbox_id', $mailbox_id)->first();
        if (!$row && $create) {
            $row = new self();
            $row->mailbox_id = $mailbox_id;
            $row->setInboxRelays(\Option::get('nostr.default_inbox_relays') ?: config('nostr.default_inbox_relays', []));
            $row->setAnnounceRelays(\Option::get('nostr.default_announce_relays') ?: config('nostr.default_announce_relays', []));
            $row->reopen_days = config('nostr.reopen_days', 30);
        }

        return $row;
    }

    public static function byPubkey($pubkey)
    {
        return self::where('pubkey', $pubkey)->first();
    }

    /**
     * Mailboxes the listener should subscribe for.
     */
    public static function active()
    {
        return self::where('enabled', true)
            ->whereNotNull('pubkey')
            ->whereNotNull('private_key')
            ->get();
    }

    public function isReady()
    {
        return $this->enabled && $this->pubkey && $this->private_key && count($this->getInboxRelays());
    }

    public function getPrivateKey()
    {
        if (!$this->private_key) {
            return '';
        }

        return (string) \Helper::decrypt($this->private_key);
    }

    public function setPrivateKey($hex)
    {
        $hex = strtolower(trim($hex));
        $this->private_key = $hex ? \Helper::encrypt($hex) : null;
        $this->pubkey = $hex ? Keys::pubkeyFromPrivate($hex) : null;
    }

    public function getNpub()
    {
        return $this->pubkey ? Keys::npub($this->pubkey) : '';
    }

    public function getNsec()
    {
        $priv = $this->getPrivateKey();

        return $priv ? Keys::nsec($priv) : '';
    }

    public function getInboxRelays()
    {
        return self::decodeRelays($this->inbox_relays);
    }

    public function setInboxRelays($relays)
    {
        $this->inbox_relays = json_encode(self::normalizeRelays($relays));
    }

    public function getAnnounceRelays()
    {
        return self::decodeRelays($this->announce_relays);
    }

    public function setAnnounceRelays($relays)
    {
        $this->announce_relays = json_encode(self::normalizeRelays($relays));
    }

    /**
     * Relays used to look things up (profiles, DM relay lists) and to announce.
     */
    public function getAllRelays()
    {
        return array_values(array_unique(array_merge($this->getInboxRelays(), $this->getAnnounceRelays())));
    }

    public function getNip05()
    {
        if (!$this->nip05_name) {
            return '';
        }
        $host = parse_url(config('app.url'), PHP_URL_HOST);

        return $this->nip05_name.'@'.$host;
    }

    /**
     * Fields that, when changed, make the listener resubscribe.
     */
    public function getListenerFingerprint()
    {
        return md5(implode('|', [$this->id, $this->enabled, $this->pubkey, $this->inbox_relays]));
    }

    public static function decodeRelays($json)
    {
        if (!$json) {
            return [];
        }
        $relays = json_decode($json, true);

        return is_array($relays) ? array_values($relays) : [];
    }

    /**
     * Accepts an array or newline/comma separated string of relay URLs.
     */
    public static function normalizeRelays($relays)
    {
        if (is_string($relays)) {
            $relays = preg_split('/[\s,]+/', $relays);
        }
        $result = [];
        foreach ((array) $relays as $relay) {
            $relay = trim((string) $relay);
            if ($relay === '') {
                continue;
            }
            if (!preg_match('#^wss?://#i', $relay)) {
                $relay = 'wss://'.$relay;
            }
            $relay = rtrim($relay, '/');
            if (!in_array($relay, $result)) {
                $result[] = $relay;
            }
        }

        return $result;
    }
}
