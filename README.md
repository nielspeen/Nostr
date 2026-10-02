# Nostr for 12VPX (Tallport)

What the 12VPX apps need from Tallport's Nostr channel, on top of the
generic channel that is part of Tallport:

- **Logs**: the app's "Send logs" arrives as a `.txt` attachment
  (`["vpx_log", "1", "vpx-logs-YYYYMMDD-HHMMSS.txt", "UTF-8 text"]` inside the
  encrypted message), kept out of the message text and Show original.
- **Files in replies**: attachments travel inside the encrypted reply as
  `["vpx_attachment", "1", "filename", "mime/type", "base64"]` tags (4 MiB in
  total, images up to 16 million pixels). Without this module Tallport refuses
  Nostr replies with files.
- **Who replied**: each agent reply carries the author's first name in a
  `support_agent` tag. Automated messages don't.
- **Where a message came from**: `vpx_client` and `vpx_daemon` tags are shown
  next to the sender ("Windows 11 · 12VPX Neo 2.3.0").
- **CustomApp**: the callback payload gets `customer.nostr_pubkeys`,
  `customer.nostr_npubs` and `ticket.nostr_pubkey`; a response's
  `customer.nostr_keys` (`[{"pubkey", "label"}]`) renames the customer's keys,
  shown right away.
- **Announcements**: Mailbox settings » Announcements publishes signed kind-30023
  notices (`t=vpx-announcement`, `vpx-incident=true` for incidents) to the
  mailbox's relays. Kept in `nostr_announcements`.

Requires Tallport 1.25.0 or newer (the Nostr channel). Install in `Modules/Nostr`
and activate it under Manage » Modules; it can be installed before updating
Tallport: it waits until Tallport has the Nostr channel.

Check: `php Modules/Nostr/Tests/integration_offline.php` from the Tallport root
(offline, rolled back).

AGPL-3.0
