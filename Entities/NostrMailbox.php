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
        'profile_name', 'profile_about', 'profile_picture', 'nip05',
        'auto_reply_enabled', 'auto_reply_text', 'reopen_days',
    ];

    protected $dates = ['last_announced_at', 'last_event_at', 'key_created_at'];

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
        $this->key_created_at = $hex ? now() : null;
    }

    /**
     * Replace the current key. The old key is retired, never deleted: it keeps
     * receiving messages and its conversations are still answered from it.
     */
    public function replaceKey($newPrivHex)
    {
        if ($this->pubkey && $this->private_key) {
            $retired = new MailboxKey();
            $retired->mailbox_id = $this->mailbox_id;
            $retired->pubkey = $this->pubkey;
            $retired->private_key = $this->private_key;
            $retired->key_created_at = $this->key_created_at;
            $retired->retired_at = now();
            $retired->save();
        }
        $this->setPrivateKey($newPrivHex);
    }

    /**
     * Retired keys, newest first.
     */
    public function getRetiredKeys()
    {
        return MailboxKey::where('mailbox_id', $this->mailbox_id)->orderBy('retired_at', 'desc')->get();
    }

    /**
     * Current and retired public keys: everything the listener subscribes for.
     */
    public function getAllPubkeys()
    {
        $pubkeys = $this->pubkey ? [$this->pubkey] : [];
        foreach (MailboxKey::where('mailbox_id', $this->mailbox_id)->pluck('pubkey') as $pubkey) {
            $pubkeys[] = $pubkey;
        }

        return array_values(array_unique($pubkeys));
    }

    public function hasPubkey($pubkey)
    {
        return in_array(strtolower((string) $pubkey), $this->getAllPubkeys());
    }

    /**
     * Private key for one of the mailbox's public keys (current or retired), or null.
     */
    public function getPrivateKeyFor($pubkey)
    {
        $pubkey = strtolower((string) $pubkey);
        if ($pubkey !== '' && $pubkey === $this->pubkey) {
            return $this->getPrivateKey() ?: null;
        }
        $retired = MailboxKey::where('mailbox_id', $this->mailbox_id)->where('pubkey', $pubkey)->first();

        return $retired ? ($retired->getPrivateKey() ?: null) : null;
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
        return (string) $this->nip05;
    }

    public function getNip05Name()
    {
        return $this->nip05 ? explode('@', $this->nip05)[0] : '';
    }

    public function getNip05Domain()
    {
        return $this->nip05 && strpos($this->nip05, '@') !== false ? explode('@', $this->nip05, 2)[1] : '';
    }

    /**
     * True when this FreeScout installation itself answers on the address's domain,
     * so /.well-known/nostr.json is served without further setup.
     */
    public function nip05ServedHere()
    {
        $domain = strtolower($this->getNip05Domain());
        $host = strtolower((string) parse_url(config('app.url'), PHP_URL_HOST));
        $path = trim((string) parse_url(config('app.url'), PHP_URL_PATH), '/');

        return $domain !== '' && $domain === $host && $path === '';
    }

    /**
     * Fields that, when changed, make the listener resubscribe.
     */
    public function getListenerFingerprint()
    {
        return md5(implode('|', [$this->id, $this->enabled, implode(',', $this->getAllPubkeys()), $this->inbox_relays]));
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
