<?php

namespace Modules\Nostr\Services;

use App\Conversation;
use App\MailboxUser;
use App\Thread;
use Illuminate\Http\JsonResponse;

class ReplyDelivery
{
    public function registerHooks(): void
    {
        \Eventy::addFilter('backgound_action.dispatch_delay', function ($delay, $action, $params) {
            if ($action === 'chat_conversation.send_reply' && $this->isNostr($params[0] ?? null)) {
                return 0;
            }

            return $delay;
        }, 20, 3);

        \Eventy::addAction('conversation.user_replied_can_undo', function ($conversation) {
            if ($this->isNostr($conversation)) {
                request()->attributes->set('nostr.sent_reply', $conversation);
            }
        });

        // The core adds its Undo flash after the reply hook, and may redirect to another page.
        \Eventy::addFilter('middleware.web.custom_handle.response', function ($response, $request) {
            $conversation = $request->attributes->get('nostr.sent_reply');
            $request->attributes->remove('nostr.sent_reply');
            if ($conversation && $response instanceof JsonResponse
                && ($response->getData(true)['status'] ?? null) === 'success'
                && \Session::has('flash_success_floating')) {
                $message = '<strong>'.__('Message sent').'</strong>';
                if ((int) $request->after_send !== MailboxUser::AFTER_SEND_STAY) {
                    $message .= ' &nbsp;<a href="'.e($conversation->url()).'">'.__('View').'</a>';
                }
                \Session::flash('flash_success_floating', $message);
            }

            return $response;
        }, 20, 2);

        // Hiding Undo alone would still let an old link turn a delivered reply into a draft.
        \Eventy::addAction('middleware.web.custom_handle', function ($request) {
            if (!$request->route() || $request->route()->getName() !== 'conversations.undo') {
                return;
            }
            $thread = Thread::find($request->route('thread_id'));
            if ($thread && $thread->type == Thread::TYPE_MESSAGE && $this->isNostr($thread->conversation)) {
                abort(403, __('Sending can not be undone'));
            }
        });
    }

    private function isNostr($conversation): bool
    {
        return $conversation instanceof Conversation && $conversation->isChat()
            && (int) $conversation->channel === (int) config('nostr.channel');
    }
}
