<?php

require __DIR__.'/../../CustomApp/Tests/bootstrap.php';

use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Date;
use Modules\Nostr\Entities\CustomerKey;
use Modules\Nostr\Entities\MailboxKey;
use Modules\Nostr\Entities\NostrEvent;
use Modules\Nostr\Entities\NostrMailbox;

$failures = 0;
runCase('relay freshness works after reloading a customer key', function () {
    Date::setTestNow('2026-10-01 12:00:00');
    try {
        config(['nostr.dm_relays_ttl' => 21600]);
        $key = new CustomerKey();
        $key->customer_id = customer()->id;
        $key->pubkey = str_repeat('a', 64);
        check(!$key->dmRelaysAreFresh(), 'missing relay timestamp must be stale');
        $key->setDmRelays(['wss://relay.example']);
        $key->save();
        check($key->fresh()->dmRelaysAreFresh(), 'new relay cache must be fresh after reload');

        $key->dm_relays_fetched_at = '2026-10-01 06:00:00';
        $key->save();
        check(!$key->fresh()->dmRelaysAreFresh(), 'relay cache must expire at the TTL boundary');
    } finally {
        Date::setTestNow();
    }
});

foreach ([
    CustomerKey::class => ['dm_relays_fetched_at', 'first_seen_at', 'last_seen_at'],
    MailboxKey::class => ['key_created_at', 'retired_at'],
    NostrMailbox::class => ['last_announced_at', 'last_event_at', 'key_created_at'],
    NostrEvent::class => ['event_created_at'],
] as $class => $fields) {
    runCase($class.' dates hydrate and store correctly', function () use ($class, $fields) {
        $model = new $class();
        $model->setRawAttributes(array_fill_keys($fields, '2026-10-01 12:00:00'));
        foreach ($fields as $field) {
            check($model->$field instanceof CarbonInterface, $field.' must hydrate as a date');
            $model->$field = Date::parse('2026-10-02 13:14:15');
            check($model->getAttributes()[$field] === '2026-10-02 13:14:15', $field.' must store in database format');
            $model->$field = null;
            check($model->$field === null, $field.' must preserve null');
        }
    });
}

exit($failures ? 1 : 0);
