<?php

// Exercise FreeScout's queue and HTTP hooks without sending messages or touching live data.
require __DIR__.'/../../CustomApp/Tests/bootstrap.php';

use App\Conversation;
use App\Events\UserReplied;
use App\Http\Middleware\CustomHandle;
use App\Jobs\TriggerAction;
use App\Listeners\SendReplyToCustomer;
use App\MailboxUser;
use App\Thread;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;

class ReplyTestConversation extends Conversation
{
    public $testReplies;

    public function getThreads($skip = null, $take = null, $types = [], $states = [Thread::STATE_PUBLISHED])
    {
        return $this->testReplies;
    }

    public function url($folder_id = null, $thread_id = null, $params = [])
    {
        return 'https://help.example.test/conversation/123';
    }
}

function replyConversation($type, $channel)
{
    $conversation = new ReplyTestConversation();
    $conversation->type = $type;
    $conversation->channel = $channel;
    $conversation->setRelation('customer', customer('Customer', 'customer@example.com'));
    $thread = new Thread();
    $thread->id = 123;
    $thread->type = Thread::TYPE_MESSAGE;
    $conversation->testReplies = collect([$thread]);

    return $conversation;
}

$failures = 0;
runCase('Nostr replies are queued immediately while email and other chats keep the undo delay', function () {
    foreach ([[Conversation::TYPE_CHAT, config('nostr.channel'), true],
        [Conversation::TYPE_CHAT, 91, false], [Conversation::TYPE_EMAIL, null, false]] as [$type, $channel, $immediate]) {
        \Bus::fake();
        $bus = \Bus::getFacadeRoot();
        $conversation = replyConversation($type, $channel);
        (new SendReplyToCustomer())->handle(new UserReplied($conversation, $conversation->testReplies->first()));
        $jobClass = $conversation->isChat() ? TriggerAction::class : \App\Jobs\SendReplyToCustomer::class;
        $jobs = $bus->dispatched($jobClass);
        check($jobs->count() === 1, 'reply was skipped or queued more than once');
        $job = $jobs->first();
        if ($immediate) {
            check($job->delay === null, 'Nostr reply still has an intentional delay');
            check($job->action === 'chat_conversation.send_reply' && $job->queue === 'default', 'bypassed the normal chat queue');
        } else {
            check($job->delay && $job->delay->getTimestamp() >= time() + Conversation::UNDO_TIMOUT - 1, 'another channel lost its undo delay');
        }
    }
});

runCase('unrelated background actions retain their delay even for Nostr conversations', function () {
    \Bus::fake();
    $bus = \Bus::getFacadeRoot();
    $delay = now()->addSeconds(Conversation::UNDO_TIMOUT);
    \Helper::backgroundAction('conversation.user_replied', [replyConversation(Conversation::TYPE_CHAT, config('nostr.channel'))], $delay);
    check($bus->dispatched(TriggerAction::class)->first()->delay === $delay, 'changed an unrelated background action');
});

runCase('Nostr success notifications omit Undo and retain View after navigating away', function () {
    foreach ([MailboxUser::AFTER_SEND_STAY, null] as $afterSend) {
        \Session::flush();
        $request = Request::create('/conversation/ajax', 'POST', ['action' => 'send_reply', 'after_send' => $afterSend]);
        app()->instance('request', $request);
        $response = (new CustomHandle())->handle($request, function () {
            \Eventy::action('conversation.user_replied_can_undo', replyConversation(Conversation::TYPE_CHAT, config('nostr.channel')), new Thread());
            \Session::flash('flash_success_floating', 'Email Sent <a href="/conversation/undo-reply/123/token">Undo</a>');

            return new JsonResponse(['status' => 'success']);
        });
        $flash = \Session::get('flash_success_floating');
        check(strpos($flash, 'undo-reply') === false && strpos($flash, 'Undo') === false, 'notification still offers Undo');
        check(strpos($flash, 'Message sent') !== false, 'missing chat confirmation');
        check((strpos($flash, 'https://help.example.test/conversation/123') !== false) === ($afterSend !== MailboxUser::AFTER_SEND_STAY), 'View link no longer matches navigation');
        check($response->getData(true) === ['status' => 'success'], 'changed the reply response');
    }
});

runCase('email Undo and unrelated notifications remain unchanged', function () {
    foreach ([Conversation::TYPE_EMAIL, Conversation::TYPE_CHAT, null] as $type) {
        \Session::flush();
        $request = Request::create('/conversation/ajax', 'POST');
        app()->instance('request', $request);
        $original = 'Email Sent <a href="/conversation/undo-reply/123/token">Undo</a>';
        (new CustomHandle())->handle($request, function () use ($type, $original) {
            if ($type !== null) {
                \Eventy::action('conversation.user_replied_can_undo', replyConversation($type, $type === Conversation::TYPE_CHAT ? 91 : null), new Thread());
            }
            \Session::flash('flash_success_floating', $original);

            return new JsonResponse(['status' => 'success']);
        });
        check(\Session::get('flash_success_floating') === $original, 'changed another notification');
    }
});

runCase('a stale Undo URL cannot turn a Nostr reply into a draft', function () {
    \Schema::table('conversations', function ($table) { $table->integer('type')->default(Conversation::TYPE_CHAT); $table->integer('channel')->nullable(); });
    \Schema::table('threads', function ($table) { $table->integer('conversation_id'); $table->integer('type'); $table->integer('state'); });
    foreach ([config('nostr.channel'), 91, null] as $channel) {
        $conversation = conversation(customer());
        \DB::table('conversations')->where('id', $conversation->id)->update(['type' => $channel ? Conversation::TYPE_CHAT : Conversation::TYPE_EMAIL, 'channel' => $channel]);
        $id = \DB::table('threads')->insertGetId(['conversation_id' => $conversation->id, 'type' => Thread::TYPE_MESSAGE,
            'state' => Thread::STATE_PUBLISHED, 'customer_id' => $conversation->customer_id, 'created_by_customer_id' => $conversation->customer_id]);
        $request = Request::create('/conversation/undo-reply/'.$id.'/token');
        $route = (new Route('GET', 'conversation/undo-reply/{thread_id}/{token}', []))->name('conversations.undo');
        $route->bind($request);
        $request->setRouteResolver(function () use ($route) { return $route; });
        $called = false;
        try {
            (new CustomHandle())->handle($request, function () use (&$called) { $called = true; return new JsonResponse([]); });
            check($channel !== config('nostr.channel'), 'Nostr Undo reached the controller');
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $error) {
            check($channel === config('nostr.channel') && $error->getStatusCode() === 403, 'blocked another channel or returned the wrong error');
        }
        check($called === ($channel !== config('nostr.channel')), 'wrong Undo route intercepted');
        check(Thread::find($id)->state === Thread::STATE_PUBLISHED, 'reply state changed');
    }
});

exit($failures ? 1 : 0);
