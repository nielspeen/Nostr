<?php

namespace Modules\Nostr\Services;

use App\Conversation;
use App\Customer;
use App\Thread;
use Illuminate\Support\Carbon;
use Modules\Nostr\Entities\CustomerKey;
use Modules\Nostr\Entities\NostrEvent;
use Modules\Nostr\Entities\NostrMailbox;

/**
 * Turns a received gift wrap into a customer message in a conversation.
 */
class IncomingMessageHandler
{
    // Rumors dated further in the future than this are clamped to now.
    const MAX_FUTURE_SKEW = 900;

    /** @var callable|null */
    protected $logger;

    public function __construct(?callable $logger = null)
    {
        $this->logger = $logger;
    }

    /**
     * @param NostrMailbox $cfg      mailbox the wrap is addressed to
     * @param array        $wrap     kind 1059 event as received from the relay
     * @param string|null  $relayUrl relay the wrap arrived on
     *
     * @return Thread|null the created thread, or null when nothing was created
     */
    public function handleGiftWrap(NostrMailbox $cfg, array $wrap, $relayUrl = null)
    {
        $wrapId = $wrap['id'] ?? '';
        if (!EventBuilder::isHex($wrapId, 64) || NostrEvent::seenWrap($wrapId)) {
            return null;
        }

        // Which of the mailbox's keys (current or retired) is this for?
        $targets = array_values(array_intersect(array_map('strtolower', EventBuilder::tagValues($wrap, 'p')), $cfg->getAllPubkeys()));
        if (!$targets) {
            $this->record($cfg, $wrapId, null, (string) ($wrap['pubkey'] ?? ''), GiftWrap::KIND_WRAP, null, null, $relayUrl, NostrEvent::STATUS_FAILED, 'not addressed to mailbox');

            return null;
        }
        $mailboxPubkey = $targets[0];
        $priv = $cfg->getPrivateKeyFor($mailboxPubkey);
        if (!$priv) {
            $this->record($cfg, $wrapId, null, (string) ($wrap['pubkey'] ?? ''), GiftWrap::KIND_WRAP, null, null, $relayUrl, NostrEvent::STATUS_FAILED, 'no key for recipient', null, $mailboxPubkey);

            return null;
        }

        try {
            $unwrapped = GiftWrap::unwrap($wrap, $priv, $mailboxPubkey);
        } catch (\Throwable $e) {
            $this->log('wrap '.substr($wrapId, 0, 8).' rejected: '.$e->getMessage());
            $this->record($cfg, $wrapId, null, (string) ($wrap['pubkey'] ?? ''), GiftWrap::KIND_WRAP, null, null, $relayUrl, NostrEvent::STATUS_FAILED, $e->getMessage(), null, $mailboxPubkey);

            return null;
        }

        $rumor = $unwrapped['rumor'];
        $pubkey = $rumor['pubkey'];
        $kind = $rumor['kind'];

        if (NostrEvent::seenRumor($rumor['id'])) {
            $this->record($cfg, $wrapId, $rumor['id'], $pubkey, $kind, null, null, $relayUrl, NostrEvent::STATUS_OK, 'duplicate', null, $mailboxPubkey);

            return null;
        }
        if ($cfg->hasPubkey($pubkey)) {
            $this->record($cfg, $wrapId, $rumor['id'], $pubkey, $kind, null, null, $relayUrl, NostrEvent::STATUS_OK, 'own message', null, $mailboxPubkey);

            return null;
        }
        if (!in_array($kind, [GiftWrap::KIND_DM, GiftWrap::KIND_FILE])) {
            $this->log('unsupported kind '.$kind.' from '.Keys::shortNpub($pubkey));
            $this->record($cfg, $wrapId, $rumor['id'], $pubkey, $kind, null, null, $relayUrl, NostrEvent::STATUS_FAILED, 'unsupported kind', null, $mailboxPubkey);

            return null;
        }
        if (!in_array($mailboxPubkey, array_map('strtolower', EventBuilder::tagValues($rumor, 'p')))) {
            $this->record($cfg, $wrapId, $rumor['id'], $pubkey, $kind, null, null, $relayUrl, NostrEvent::STATUS_FAILED, 'not addressed to mailbox', null, $mailboxPubkey);

            return null;
        }

        $createdAt = min((int) $rumor['created_at'] ?: time(), time() + self::MAX_FUTURE_SKEW);

        list($customer, $customerIsNew) = $this->findOrCreateCustomer($pubkey);

        $attachments = [];
        if ($kind === GiftWrap::KIND_FILE) {
            $attachments = $this->downloadFile($rumor);
            $text = $this->fileMessageText($rumor, $attachments);
        } else {
            $text = trim($rumor['content']);
        }
        $body = $this->textToHtml($text);
        if ($body === '') {
            $body = '<i>'.__('(empty message)').'</i>';
        }

        $subject = trim((string) EventBuilder::firstTag($rumor, 'subject'));
        if ($subject === '') {
            $subject = Conversation::subjectFromText($text);
        }
        if ($subject === '') {
            $subject = __('Nostr message');
        }

        $threadData = [
            'type' => Thread::TYPE_CUSTOMER,
            'body' => $body,
            'customer_id' => $customer->id,
            'attachments' => $attachments,
        ];

        $conversation = $this->findOpenConversation($cfg, $customer);
        $new = !$conversation;

        if ($conversation) {
            $thread = Thread::createExtended($threadData, $conversation, $customer);
        } else {
            $result = Conversation::create([
                'type' => Conversation::TYPE_CHAT,
                'subject' => $subject,
                'mailbox_id' => $cfg->mailbox_id,
                'source_type' => Conversation::SOURCE_TYPE_API,
                'channel' => config('nostr.channel'),
                'status' => Conversation::STATUS_ACTIVE,
            ], [$threadData], $customer);
            $conversation = $result['conversation'] ?? null;
            $thread = $result['thread'] ?? null;
        }

        if (!$thread || !$conversation) {
            $this->log('could not create a thread for message from '.Keys::shortNpub($pubkey));
            $this->record($cfg, $wrapId, $rumor['id'], $pubkey, $kind, null, null, $relayUrl, NostrEvent::STATUS_FAILED, 'could not create thread', null, $mailboxPubkey);

            return null;
        }

        $this->record($cfg, $wrapId, $rumor['id'], $pubkey, $kind, $conversation->id, $thread->id, $relayUrl, NostrEvent::STATUS_OK, null, $createdAt, $mailboxPubkey);

        $key = CustomerKey::byPubkey($pubkey);
        if ($key) {
            $key->last_seen_at = now();
            $key->save();
        }
        $cfg->last_event_at = now();
        $cfg->save();

        if ($new && $cfg->auto_reply_enabled && trim((string) $cfg->auto_reply_text) !== '') {
            \Helper::backgroundAction('nostr.auto_reply', [$conversation->id, $cfg->id, $pubkey]);
        }
        if ($customerIsNew) {
            \Helper::backgroundAction('nostr.fetch_profile', [$customer->id, $pubkey, $cfg->id]);
        }

        $this->log(sprintf('%s from %s -> conversation #%s (%s)', $kind === GiftWrap::KIND_FILE ? 'file' : 'message',
            Keys::shortNpub($pubkey), $conversation->number, $new ? 'new' : 'reopened'));

        return $thread;
    }

    /**
     * @return array [Customer, bool created]
     */
    public function findOrCreateCustomer($pubkey)
    {
        $key = CustomerKey::byPubkey($pubkey);
        if ($key && $key->customer) {
            return [$key->customer, false];
        }

        $customer = Customer::getCustomerByChannel(config('nostr.channel'), $pubkey);
        $created = false;
        if (!$customer) {
            $customer = Customer::createWithoutEmail(['first_name' => Keys::shortNpub($pubkey)]);
            $created = true;
        }
        CustomerKey::link($customer, $pubkey, CustomerKey::SOURCE_AUTO);

        return [$customer, $created];
    }

    /**
     * Latest Nostr conversation of this customer in this mailbox, if it is recent enough to reopen.
     */
    public function findOpenConversation(NostrMailbox $cfg, Customer $customer)
    {
        $days = (int) $cfg->reopen_days ?: (int) config('nostr.reopen_days', 30);

        $conversation = Conversation::where('mailbox_id', $cfg->mailbox_id)
            ->where('customer_id', $customer->id)
            ->where('channel', config('nostr.channel'))
            ->where('state', '!=', Conversation::STATE_DELETED)
            ->orderBy('id', 'desc')
            ->first();

        if (!$conversation) {
            return null;
        }

        $last = $conversation->last_reply_at ?: $conversation->created_at;
        if ($last && !($last instanceof \DateTimeInterface)) {
            $last = Carbon::parse($last);
        }
        if ($last && $last->lt(now()->subDays($days))) {
            return null;
        }

        return $conversation;
    }

    /**
     * Plain text from a Nostr client to the HTML FreeScout stores.
     */
    public function textToHtml($text)
    {
        $text = trim((string) $text);
        if ($text === '') {
            return '';
        }
        $html = htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        try {
            $html = \Helper::linkify($html);
        } catch (\Throwable $e) {
            // Keep the escaped text.
        }

        return nl2br($html);
    }

    /**
     * Download and decrypt a kind 15 file message.
     *
     * @return array attachments in the format Thread::createExtended() accepts
     */
    public function downloadFile(array $rumor)
    {
        $url = trim($rumor['content']);
        if (!preg_match('#^https?://#i', $url)) {
            return [];
        }
        $algorithm = strtolower((string) EventBuilder::firstTag($rumor, 'encryption-algorithm'));
        $mime = (string) EventBuilder::firstTag($rumor, 'file-type') ?: 'application/octet-stream';
        $hash = strtolower((string) EventBuilder::firstTag($rumor, 'x'));
        $size = (int) EventBuilder::firstTag($rumor, 'size');
        $max = (int) config('nostr.max_attachment_size', 25 * 1024 * 1024);

        if ($size > $max) {
            $this->log('file too large: '.$size.' bytes');

            return [];
        }

        $data = $this->download($url, $max);
        if ($data === null) {
            $this->log('could not download '.$url);

            return [];
        }

        if ($algorithm && $algorithm !== 'none') {
            if ($algorithm !== 'aes-gcm') {
                $this->log('unsupported file encryption: '.$algorithm);

                return [];
            }
            $key = @hex2bin((string) EventBuilder::firstTag($rumor, 'decryption-key'));
            $nonce = @hex2bin((string) EventBuilder::firstTag($rumor, 'decryption-nonce'));
            if (!$key || !$nonce || strlen($data) <= 16) {
                $this->log('file message has no usable decryption key');

                return [];
            }
            if ($hash && hash('sha256', $data) !== $hash) {
                $this->log('encrypted file hash does not match the x tag, continuing');
            }
            $cipher = strlen($key) === 16 ? 'aes-128-gcm' : 'aes-256-gcm';
            $plain = openssl_decrypt(substr($data, 0, -16), $cipher, $key, OPENSSL_RAW_DATA, $nonce, substr($data, -16));
            if ($plain === false) {
                $this->log('could not decrypt file from '.$url);

                return [];
            }
            $data = $plain;
        } elseif ($hash && hash('sha256', $data) !== $hash) {
            $this->log('file hash does not match the x tag, continuing');
        }

        return [[
            'file_name' => $this->fileName($url, $mime, $rumor['id'] ?? ''),
            'mime_type' => $mime,
            'data' => base64_encode($data),
        ]];
    }

    protected function fileMessageText(array $rumor, array $attachments)
    {
        if ($attachments) {
            return __('Sent a file').': '.$attachments[0]['file_name'];
        }

        return __('Sent an encrypted file that could not be retrieved').': '.trim($rumor['content']);
    }

    /**
     * @return string|null file contents
     */
    protected function download($url, $max)
    {
        try {
            $client = new \GuzzleHttp\Client([
                'timeout' => 60,
                'connect_timeout' => 10,
                'http_errors' => false,
                'headers' => ['User-Agent' => 'FreeScout Nostr'],
            ]);
            $response = $client->get($url, ['stream' => true]);
            if ($response->getStatusCode() !== 200) {
                return null;
            }
            $length = (int) $response->getHeaderLine('Content-Length');
            if ($length > $max) {
                return null;
            }
            $body = $response->getBody();
            $data = '';
            while (!$body->eof()) {
                $data .= $body->read(65536);
                if (strlen($data) > $max) {
                    return null;
                }
            }

            return $data;
        } catch (\Throwable $e) {
            $this->log('download failed: '.$e->getMessage());

            return null;
        }
    }

    protected function fileName($url, $mime, $id = '')
    {
        $name = basename((string) parse_url($url, PHP_URL_PATH));
        $name = preg_replace('/[^A-Za-z0-9._-]/', '_', $name);
        if ($name !== '' && $name !== '.' && strpos($name, '.') > 0) {
            return $name;
        }
        $ext = 'bin';
        if (class_exists('\Symfony\Component\HttpFoundation\File\MimeType\ExtensionGuesser')) {
            $ext = \Symfony\Component\HttpFoundation\File\MimeType\ExtensionGuesser::getInstance()->guess($mime) ?: 'bin';
        }

        return 'nostr-file-'.substr($id ?: md5($url), 0, 8).'.'.$ext;
    }

    protected function record(NostrMailbox $cfg, $wrapId, $rumorId, $pubkey, $kind, $conversationId, $threadId, $relayUrl, $status, $error = null, $createdAt = null, $mailboxPubkey = null)
    {
        try {
            $event = new NostrEvent();
            $event->mailbox_id = $cfg->mailbox_id;
            $event->mailbox_pubkey = $mailboxPubkey ?: $cfg->pubkey;
            $event->direction = NostrEvent::DIRECTION_IN;
            $event->wrap_id = $wrapId;
            $event->rumor_id = $rumorId;
            $event->pubkey = (string) $pubkey;
            $event->kind = (int) $kind;
            $event->conversation_id = $conversationId;
            $event->thread_id = $threadId;
            $event->relay = $relayUrl ? mb_substr($relayUrl, 0, 255) : null;
            $event->status = $status;
            $event->error = $error ? mb_substr($error, 0, 1000) : null;
            $event->event_created_at = $createdAt ? Carbon::createFromTimestamp($createdAt) : null;
            $event->save();
        } catch (\Throwable $e) {
            // Duplicate wrap id (race between relays) or similar: not fatal.
            $this->log('could not record event: '.$e->getMessage());
        }
    }

    protected function log($message)
    {
        if ($this->logger) {
            call_user_func($this->logger, $message);
        }
    }
}
