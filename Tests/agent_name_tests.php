<?php

// Reuse the isolated in-memory FreeScout bootstrap; no relays are contacted.
require __DIR__.'/../../CustomApp/Tests/bootstrap.php';

use App\Thread;
use App\User;
use Modules\Nostr\Entities\CustomerKey;
use Modules\Nostr\Entities\NostrMailbox;
use Modules\Nostr\Services\EventBuilder;
use Modules\Nostr\Services\GiftWrap;
use Modules\Nostr\Services\Keys;
use Modules\Nostr\Services\OutgoingMessageSender;

require_once __DIR__.'/../Database/Migrations/2026_09_23_000001_create_nostr_mailboxes_table.php';
(new \CreateNostrMailboxesTable())->up();
\Schema::table('nostr_mailboxes', function ($table) { $table->dateTime('key_created_at')->nullable(); });
\Schema::table('nostr_events', function ($table) { $table->string('mailbox_pubkey')->nullable(); });

class OfflineAgentSender extends OutgoingMessageSender
{
    public $result;

    public function targetRelays(NostrMailbox $cfg, $pubkey, array $extra = [])
    {
        return [];
    }

    public function sendText(NostrMailbox $cfg, $pubkey, $text, array $options = [])
    {
        return $this->result = parent::sendText($cfg, $pubkey, $text, $options);
    }

    protected function fail(Thread $thread, $message)
    {
        // This fixture deliberately has no relay accepting its message.
    }
}

$failures = 0;
$fixturePath = $argv[1] ?? null;
runCase('replies carry only their author first name inside the encrypted message', function () use ($fixturePath) {
    // Public test-only private scalars, shared with the Rust interoperability test.
    $senderKey = str_pad('1', 64, '0', STR_PAD_LEFT);
    $recipientKey = str_pad('2', 64, '0', STR_PAD_LEFT);
    $recipient = Keys::pubkeyFromPrivate($recipientKey);
    $cfg = new NostrMailbox();
    $cfg->mailbox_id = 1;
    $cfg->setPrivateKey($senderKey);
    $cfg->save();
    $customer = customer();
    CustomerKey::link($customer, $recipient);
    $conversation = conversation($customer);
    $conversation->subject = 'Support';
    $thread = new Thread();
    $thread->id = 123;
    $thread->body = 'Your reply is here';
    $thread->setRelation('attachments', collect());
    $thread->setRelation('user', new User(['first_name' => 'Assigned']));
    $sender = new OfflineAgentSender();

    foreach (['Alice', 'Mary Jane', '   ', null] as $name) {
        $thread->setRelation('created_by_user', $name === null ? null : new User([
            'first_name' => $name, 'last_name' => 'PrivateSurname', 'email' => 'private@example.com',
        ]));
        $sender->sendThread($conversation, $thread);
        $result = $sender->result;
        $opened = GiftWrap::unwrap($result['wrap'], $recipientKey, $recipient);
        $expected = trim((string) $name) ?: null;
        check(EventBuilder::firstTag($opened['rumor'], 'support_agent') === $expected, 'wrong agent name');
        check($opened['rumor']['content'] === 'Your reply is here', 'message text was changed');
        check($result['wrap']['tags'] === [['p', $recipient]] && $opened['seal']['tags'] === [], 'name leaked outside encrypted rumor');
        $encoded = json_encode($opened);
        check(strpos($encoded, 'PrivateSurname') === false && strpos($encoded, 'private@example.com') === false
            && strpos($encoded, 'Assigned') === false, 'sent another identity field');
        if ($name === 'Alice' && $fixturePath) {
            file_put_contents($fixturePath, json_encode([
                'sender_private_test_key' => $senderKey, 'recipient_private_test_key' => $recipientKey,
                'rumor' => $opened['rumor'], 'wrap' => $result['wrap'],
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n");
        }
    }

    $automated = $sender->sendText($cfg, $recipient, 'Automatic greeting');
    $opened = GiftWrap::unwrap($automated['wrap'], $recipientKey, $recipient);
    check(EventBuilder::firstTag($opened['rumor'], 'support_agent') === null, 'automated reply borrowed an agent');
});

exit($failures ? 1 : 0);
