<?php

return [
    'name' => 'Nostr',

    // Channel code stored in conversations.channel / customer_channel.channel (tinyint).
    // Kept high to avoid clashing with the commercial channel modules.
    'channel' => 90,

    // Prefilled when a mailbox is set up. Can be changed in Settings » Nostr.
    // Inbox relays must accept kind 1059 from anyone and serve "#p" queries for it.
    // (relay.damus.io requires AUTH for that and its AUTH was broken when tested.)
    'default_inbox_relays' => [
        'wss://nos.lol',
        'wss://relay.primal.net',
        'wss://nostr.mom',
    ],
    'default_announce_relays' => [
        'wss://nos.lol',
        'wss://relay.primal.net',
        'wss://relay.damus.io',
        'wss://purplepag.es',
    ],

    // Days of silence after which a new message opens a new conversation.
    'reopen_days' => 30,

    // How long one nostr:listen process lives before the scheduler replaces it (seconds).
    'listener_lifetime' => 1200,

    // Gift wraps carry timestamps randomized up to two days into the past,
    // so subscriptions look this far back (seconds). Duplicates are ignored.
    'lookback' => 3 * 86400,

    // Largest encrypted file (kind 15) that is downloaded and attached.
    'max_attachment_size' => 25 * 1024 * 1024,

    // Timeouts in seconds for synchronous relay operations.
    'publish_timeout' => 10,
    'fetch_timeout' => 6,

    // Cache of a customer's kind 10050 DM relay list.
    'dm_relays_ttl' => 6 * 3600,
];
