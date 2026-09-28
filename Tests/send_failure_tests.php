<?php

// Exercise the real status transition and folder counters in SQLite memory; no relay traffic.
require __DIR__.'/../../CustomApp/Tests/bootstrap.php';

use App\Attachment;
use App\Conversation;
use App\Folder;
use App\SendLog;
use App\Thread;
use Modules\Nostr\Entities\CustomerKey;
use Modules\Nostr\Entities\NostrMailbox;
use Modules\Nostr\Services\InlineAttachments;
use Modules\Nostr\Services\Keys;
use Modules\Nostr\Services\OutgoingMessageSender;

foreach (['conversations', 'threads', 'folders'] as $table) {
    \Schema::dropIfExists($table);
    require_once glob($root.'/database/migrations/*create_'.$table.'_table.php')[0];
    $migration = 'Create'.ucfirst($table).'Table';
    (new $migration())->up();
}
\Schema::table('conversations', function ($table) { $table->integer('channel')->nullable(); });
\Schema::table('threads', function ($table) { $table->text('send_status_data')->nullable(); });
require_once __DIR__.'/../Database/Migrations/2026_09_23_000001_create_nostr_mailboxes_table.php';
(new \CreateNostrMailboxesTable())->up();
\Schema::table('nostr_mailboxes', function ($table) { $table->dateTime('key_created_at')->nullable(); });
config(['app.update_folder_counters_in_background' => false]);

class FailureTestSender extends OutgoingMessageSender
{
    public $delivery = 'rejected';
    public $publishCalls = 0;

    public function sendText(NostrMailbox $cfg, $pubkey, $text, array $options = [])
    {
        $this->publishCalls++;
        if ($this->delivery === 'exception') {
            throw new \RuntimeException('Relay connection failed');
        }

        return ['ok' => $this->delivery === 'accepted', 'results' => [
            'wss://relay.example' => ['ok' => $this->delivery === 'accepted', 'message' => 'Message too large'],
        ]];
    }
}

function failureReply($status = Conversation::STATUS_CLOSED, $assignee = 7)
{
    foreach ([Folder::TYPE_CLOSED, Folder::TYPE_ASSIGNED, Folder::TYPE_UNASSIGNED] as $type) {
        \DB::table('folders')->insert(['mailbox_id' => 1, 'type' => $type]);
    }
    $cfg = new NostrMailbox();
    $cfg->mailbox_id = 1;
    $cfg->setPrivateKey(str_pad('1', 64, '0', STR_PAD_LEFT));
    $cfg->save();
    $customer = customer();
    CustomerKey::link($customer, Keys::pubkeyFromPrivate(str_pad('2', 64, '0', STR_PAD_LEFT)));
    $id = \DB::table('conversations')->insertGetId([
        'number' => 1, 'type' => Conversation::TYPE_CHAT, 'channel' => config('nostr.channel'),
        'folder_id' => 1, 'status' => $status, 'state' => Conversation::STATE_PUBLISHED,
        'subject' => 'Support', 'preview' => 'Reply', 'mailbox_id' => 1, 'user_id' => $assignee,
        'customer_id' => $customer->id, 'source_via' => Conversation::PERSON_CUSTOMER,
        'source_type' => Conversation::SOURCE_TYPE_API,
    ]);
    $conversation = Conversation::find($id);
    $conversation->updateFolder();
    $conversation->save();
    $conversation->mailbox->updateFoldersCounters();
    $threadId = \DB::table('threads')->insertGetId([
        'conversation_id' => $id, 'customer_id' => $customer->id, 'type' => Thread::TYPE_MESSAGE,
        'status' => $status, 'state' => Thread::STATE_PUBLISHED, 'body' => 'Reply',
        'source_via' => Thread::PERSON_USER, 'source_type' => Thread::SOURCE_TYPE_WEB,
    ]);
    $thread = Thread::find($threadId);
    $thread->setRelation('all_attachments', collect());
    $thread->setRelation('created_by_user', null);
    $thread->setRelation('conversation', $conversation);

    return [$conversation, $thread, new FailureTestSender()];
}

function checkReopened($conversation, $thread, $message)
{
    $fresh = $conversation->fresh();
    check($fresh->status == Conversation::STATUS_ACTIVE, 'failed reply left the ticket inactive');
    check($fresh->user_id === $conversation->user_id, 'changed the assignee');
    $folder = Folder::find($fresh->folder_id);
    check($folder->type == ($fresh->user_id ? Folder::TYPE_ASSIGNED : Folder::TYPE_UNASSIGNED), 'ticket stayed in the wrong folder');
    check($folder->active_count == 1, 'active folder counter was not refreshed');
    check(Folder::where('type', Folder::TYPE_CLOSED)->value('total_count') == 0, 'closed folder counter was not refreshed');
    $reply = $thread->fresh();
    check($reply->send_status == SendLog::STATUS_SEND_ERROR && strpos($reply->getSendStatusData()['msg'], $message) !== false, 'lost the send error notice');
    $change = $fresh->threads()->where('action_type', Thread::ACTION_TYPE_STATUS_CHANGED)->orderBy('id', 'desc')->first();
    check($change && $change->status == Conversation::STATUS_ACTIVE && $change->created_by_user_id === null, 'missing system status change');
}

$failures = 0;
runCase('oversized attachments reopen a ticket closed on send before publishing', function () {
    [$conversation, $thread, $sender] = failureReply();
    $attachment = new Attachment();
    $attachment->file_name = 'large.txt';
    $attachment->mime_type = 'text/plain';
    $attachment->size = InlineAttachments::MAX_BYTES + 1;
    $thread->setRelation('all_attachments', collect([$attachment]));
    check($sender->handleSendReply($conversation, collect([$thread])) === false, 'oversized reply succeeded');
    check($sender->publishCalls === 0, 'oversized attachment reached publication');
    checkReopened($conversation, $thread, 'too large');
});

runCase('relay rejection reopens a pending unassigned ticket', function () {
    [$conversation, $thread, $sender] = failureReply(Conversation::STATUS_PENDING, null);
    check($sender->handleSendReply($conversation, collect([$thread])) === false, 'relay rejection succeeded');
    checkReopened($conversation, $thread, 'Could not deliver');
});

runCase('delivery exceptions reopen using current status rather than queued or cached state', function () {
    [$conversation, $thread, $sender] = failureReply(Conversation::STATUS_ACTIVE);
    $conversation->fresh()->changeStatus(Conversation::STATUS_CLOSED);
    $sender->delivery = 'exception';
    check($sender->handleSendReply($conversation, collect([$thread])) === false, 'exception escaped the failure handler');
    checkReopened($conversation, $thread, 'Relay connection failed');
});

runCase('repeated failures on an active ticket do not add status changes', function () {
    [$conversation, $thread, $sender] = failureReply(Conversation::STATUS_ACTIVE);
    $before = $conversation->fresh()->user_updated_at;
    $sender->handleSendReply($conversation, collect([$thread]));
    $sender->handleSendReply($conversation, collect([$thread]));
    check($conversation->fresh()->isActive(), 'active ticket changed status');
    check($conversation->threads()->count() == 1, 'added redundant status history');
    check($conversation->fresh()->user_updated_at == $before, 'changed the status timestamp on a no-op');
});

runCase('successful replies and unrelated channels keep their closed status', function () {
    [$conversation, $thread, $sender] = failureReply();
    $sender->delivery = 'accepted';
    check($sender->handleSendReply($conversation, collect([$thread])) === true, 'accepted reply failed');
    check($thread->fresh()->send_status == SendLog::STATUS_ACCEPTED, 'reply not marked accepted');
    $conversation->channel = 91;
    check($sender->handleSendReply($conversation, collect([$thread])) === null && $sender->publishCalls === 1, 'handled another channel');
    check($conversation->fresh()->isClosed() && $conversation->threads()->count() == 1, 'reopened without a failed reply');
});

exit($failures ? 1 : 0);
