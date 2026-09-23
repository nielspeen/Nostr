# Nostr channel for FreeScout

Customer support over [Nostr](https://nostr.com). Customers write to your mailbox from any
Nostr client that supports private direct messages (NIP-17); their messages show up as chat
conversations in FreeScout and your replies are delivered back, end-to-end encrypted.

## What it does

- One Nostr keypair per mailbox (generated in the mailbox settings, or import an existing `nsec`).
  The private key is stored encrypted with the application key. Keys are never lost by accident:
  replacing a key needs the admin's password and a typed confirmation, and the old key is retired
  rather than deleted (see below).
- Receives NIP-17 private direct messages (kind 14 text, kind 15 encrypted files) wrapped per
  NIP-59 and encrypted with NIP-44. Legacy NIP-04 messages are ignored.
- A new message reopens the customer's latest Nostr conversation in that mailbox if it had activity
  within the reopen window (default 30 days); otherwise a new conversation is started.
- Unknown senders become new customers. Their name and picture are filled in from their
  kind 0 profile when available. A customer can have several public keys (personal client, one per
  app install...); replies go to the key that wrote last.
- Agent replies are sent as plain text. Attachments are appended as download links.
- Optional one-time auto reply for new conversations.
- Good citizen: publishes the mailbox's kind 0 profile, kind 10050 DM relay list and kind 10002
  relay list, answers NIP-42 AUTH challenges, and supports a NIP-05 address on any domain.

## Requirements

- FreeScout 1.8.191 or newer, PHP 8.1 or newer with the `openssl` extension.
- The `gmp` PHP extension is recommended (`apt install php8.x-gmp`); without it the module uses
  `bcmath`, which is slower but works.
- The FreeScout cron job (`php artisan schedule:run` every minute). The relay listener is started
  from it, just like the core queue worker.

## Installation

1. Copy this directory to `Modules/Nostr` (it ships with its `vendor` directory; if you cloned it,
   run `composer install --no-dev` inside `Modules/Nostr`).
2. Activate the module in Manage » Modules.
3. Open Mailbox Settings » Nostr for the mailbox that should receive messages:
   generate a keypair, check the relays, fill in the public profile, enable the channel and save.
   The profile and relay lists are published automatically; use "Publish profile now" to force it.
4. Share the mailbox's `npub` (or its NIP-05 address) with your customers.

Defaults for the relay lists live in Settings » Nostr.

### Keys

The keypair is the identity customers write to, so the module treats it carefully:

- **Backup**: "Show private key" displays the `nsec` once after you enter your password. Keep a copy
  outside the server so the identity survives a lost database.
- **Replace**: "Replace the key" needs your password and the word `REPLACE`. The current key is
  *retired*, not deleted: the listener keeps receiving messages sent to it, and conversations that
  came in on it are still answered from it, so customers who saved the old `npub` are not cut off.
  Only the new key is announced (profile, relay lists, address).
- **Delete a retired key**: possible from the retired keys table, again with password and the word
  `DELETE`. Messages sent to a deleted key are unreadable forever.

### NIP-05 address

The address (for example `support@yourdomain.com`) can use any domain. Whoever serves that domain
must answer `https://yourdomain.com/.well-known/nostr.json` with the mailbox's name and key; the
settings page shows the exact JSON to host. If the domain is this FreeScout installation itself
(and FreeScout is not in a subdirectory), the file is served automatically.

### Relays

- **Inbox relays**: where customers deliver their messages and where FreeScout listens. Keep this
  list short. It is published as the mailbox's kind 10050 DM relay list, so clients know where to
  send. You can point it at your own relay later.
- **Announce relays**: popular relays where the mailbox's profile and relay lists are published, and
  where customer profiles and DM relay lists are looked up.

Replies are delivered to the customer's own kind 10050 relays (looked up and cached), plus the relay
their last message arrived on. If none are known, the mailbox's inbox relays are used.

### The listener

`php artisan nostr:listen` keeps a websocket open to every inbox relay of every enabled mailbox and
handles messages as they arrive. The scheduler starts it every minute with `withoutOverlapping`, and
it exits after `listener_lifetime` seconds (default 20 minutes) so a fresh process takes over; relays
keep the gift wraps, so nothing is lost in between. Its output goes to `storage/logs/nostr-listen.log`.

The mailbox settings page shows whether the listener is running (process, restart time, one row per
inbox relay with its connection state and last error, FreeScout's cron status) and the tail of its log.

- `php artisan nostr:listen --once` fetches pending messages once and exits (handy for testing).
- `php artisan nostr:announce` republishes the profile and relay lists (also runs daily).

## Customers and keys

Each customer profile has a **Nostr** tab listing the public keys linked to them. Agents can add keys
(`npub`, `nprofile` or hex) with a label such as "iPhone app", and remove them. If a key already
belongs to another customer, merge the two customers with FreeScout's customer merge; the keys follow.

Only the first key is mirrored into FreeScout's core channel table (that is what shows the "Nostr"
tag on the customer). All keys are used for matching incoming messages.

### CustomApp integration

If the [CustomApp](https://github.com/nielspeen/CustomApp) module is installed, the callback payload
includes `customer.nostr_pubkeys`, `customer.nostr_npubs` and `ticket.nostr_pubkey` (the key that
wrote the latest message). If the callback response contains `"customer": {"email": "...", "fname":
"...", "lname": "..."}`, CustomApp fills in the missing email and placeholder name of the customer.

## Data

| Table                 | Purpose                                                                 |
|-----------------------|-------------------------------------------------------------------------|
| `nostr_mailboxes`     | Per-mailbox key (encrypted), relays, profile, auto reply, reopen window |
| `nostr_mailbox_keys`  | Retired keys (encrypted), still receiving and answering                  |
| `nostr_customer_keys` | Public keys linked to customers, cached profile and DM relays           |
| `nostr_events`        | Every gift wrap received or sent (de-duplication, reply threading)      |

Conversations created by the module have `type = chat` and `channel = 90`.

## Upgrading

Updating through Manage » Modules (or `php artisan freescout:module-update`) runs the module's
migrations automatically. Only when you replace the files by hand (for example `git pull` in
`Modules/Nostr`) run `php artisan migrate` afterwards.

## Development

- `php Tests/crypto_tests.php` runs the NIP-19, NIP-44 (official vectors) and gift wrap checks.
- `php Tests/schnorr_vectors.php` runs the BIP-340 test vectors against the Schnorr implementation.
- `php Modules/Nostr/Tests/integration_offline.php` (from the FreeScout root) exercises the settings
  pages, incoming messages, customer keys, merge, NIP-05 and the outgoing failure path in a rolled
  back transaction; no network needed.
- `php Modules/Nostr/Tests/integration_network.php` talks to public relays with throwaway keys.
- `php Modules/Nostr/Tests/integration_daemon.php` starts a real `nostr:listen` process against the
  development database (it cleans up after itself; never run it on production).

Everything protocol related is implemented in the module so that it shares no library with other
FreeScout modules (several ship their own, mutually incompatible copies of ReactPHP and friends):

- `Crypto/Schnorr.php`: BIP-340 signatures on `simplito/elliptic-php`, the only vendored package
  (works with `gmp` or `bcmath`).
- `Crypto/Nip44.php` and `Crypto/ChaCha20.php`: NIP-44 v2 payload encryption (OpenSSL, pure PHP fallback).
- `Crypto/Bech32.php`: NIP-19 npub / nsec / nprofile.
- `Services/Websocket/Client.php`: a small RFC 6455 client on plain PHP streams with non-blocking
  connect, used by the synchronous relay client and multiplexed by the listener with `stream_select()`.

## License

AGPL-3.0
