<?php

namespace Modules\Nostr\Entities;

use App\Customer;
use App\CustomerChannel;
use Illuminate\Database\Eloquent\Model;
use Modules\Nostr\Services\Keys;

/**
 * A Nostr public key linked to a FreeScout customer.
 */
class CustomerKey extends Model
{
    const SOURCE_AUTO = 'auto';
    const SOURCE_MANUAL = 'manual';
    const SOURCE_API = 'api';

    protected $table = 'nostr_customer_keys';

    protected $fillable = ['customer_id', 'pubkey', 'label', 'source'];

    protected $dates = ['dm_relays_fetched_at', 'first_seen_at', 'last_seen_at'];

    public function customer()
    {
        return $this->belongsTo('App\Customer');
    }

    public static function byPubkey($pubkey)
    {
        return self::where('pubkey', strtolower($pubkey))->first();
    }

    public static function forCustomer($customer_id)
    {
        return self::where('customer_id', $customer_id)->orderBy('id')->get();
    }

    /**
     * Link a public key to a customer. Mirrors the first key into the core
     * customer_channel table so the "Nostr" tag and core lookups work.
     */
    public static function link(Customer $customer, $pubkey, $source = self::SOURCE_AUTO, $label = null)
    {
        $pubkey = strtolower($pubkey);

        $key = self::byPubkey($pubkey);
        if ($key) {
            if ($key->customer_id != $customer->id) {
                return null;
            }
            if ($label !== null && $label !== '') {
                $key->label = $label;
                $key->save();
            }

            return $key;
        }

        $key = new self();
        $key->customer_id = $customer->id;
        $key->pubkey = $pubkey;
        $key->label = $label;
        $key->source = $source;
        $key->first_seen_at = now();
        $key->save();

        self::syncPrimary($customer);

        return $key;
    }

    /**
     * Keep the core customer_channel record pointing at the customer's first key.
     */
    public static function syncPrimary(Customer $customer)
    {
        $channel = config('nostr.channel');
        $first = self::where('customer_id', $customer->id)->orderBy('id')->first();

        if ($first) {
            $customer->addChannel($channel, $first->pubkey);
        } else {
            CustomerChannel::where('customer_id', $customer->id)->where('channel', $channel)->delete();
            if ($customer->channel == $channel) {
                $customer->channel = null;
                $customer->channel_id = null;
                $customer->save();
            }
        }
    }

    public function getNpub()
    {
        return Keys::npub($this->pubkey);
    }

    public function getShortNpub()
    {
        return Keys::shortNpub($this->pubkey);
    }

    public function getProfile()
    {
        $profile = $this->profile ? json_decode($this->profile, true) : null;

        return is_array($profile) ? $profile : [];
    }

    public function setProfile($profile)
    {
        $this->profile = $profile ? json_encode($profile) : null;
    }

    public function getDisplayName()
    {
        $profile = $this->getProfile();

        return $profile['display_name'] ?? $profile['name'] ?? '';
    }

    public function getDmRelays()
    {
        $relays = $this->dm_relays ? json_decode($this->dm_relays, true) : null;

        return is_array($relays) ? $relays : [];
    }

    public function setDmRelays(array $relays)
    {
        $this->dm_relays = json_encode(array_values($relays));
        $this->dm_relays_fetched_at = now();
    }

    public function dmRelaysAreFresh()
    {
        return $this->dm_relays_fetched_at
            && $this->dm_relays_fetched_at->gt(now()->subSeconds(config('nostr.dm_relays_ttl', 21600)));
    }
}
