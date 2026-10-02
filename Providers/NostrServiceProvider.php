<?php

namespace Modules\Nostr\Providers;

use App\Nostr\CustomerKey;
use App\Nostr\Keys;
use App\Nostr\Nostr;
use App\Nostr\NostrEvent;
use App\Thread;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;
use Modules\Nostr\Services\CustomerLabels;
use Modules\Nostr\Services\InlineAttachments;
use Modules\Nostr\Services\LogAttachment;
use Modules\Nostr\Services\MessageSource;

/**
 * 12VPX app support, on Tallport's Nostr channel: files and logs inside the
 * encrypted messages (vpx_attachment, vpx_log), the app's device names and
 * versions (vpx_client, vpx_daemon, CustomApp), the agent's first name on
 * replies (support_agent) and public announcements (kind 30023).
 */
class NostrServiceProvider extends ServiceProvider
{
    const MODULE = 'nostr';

    public function boot()
    {
        // Waits for a Tallport with the Nostr channel (1.25.0): it can be
        // installed before the update.
        if (!class_exists(\App\Nostr\Nostr::class)) {
            return;
        }
        $this->loadViewsFrom(__DIR__.'/../Resources/views', self::MODULE);
        $this->loadMigrationsFrom(__DIR__.'/../Database/Migrations');
        $this->hooks();
    }

    public function register()
    {
    }

    public function hooks()
    {
        (new CustomerLabels())->registerHooks();

        // Logs the app sends: a .txt attachment, kept out of the message.
        \Eventy::addFilter('nostr.incoming_message', function ($message, $rumor) {
            try {
                $logs = LogAttachment::extract($rumor, (int) config('nostr.max_attachment_size', LogAttachment::MAX_BYTES));
                $message['attachments'] = array_merge($message['attachments'], $logs);
            } catch (\InvalidArgumentException $e) {
                $message['text'] = __('Sent diagnostic logs that could not be attached.');
                \Log::info('[Nostr] '.$e->getMessage());
            }

            return $message;
        }, 20, 2);

        // Files in replies travel inside the encrypted message.
        \Eventy::addFilter('nostr.reply_attachment_tags', function ($tags, $thread) {
            return InlineAttachments::forThread($thread);
        }, 20, 2);

        // Files and logs are left out of "Show original".
        \Eventy::addFilter('nostr.header_tags', function ($tags) {
            return array_values(array_filter($tags, function ($tag) {
                return !in_array($tag[0] ?? '', [InlineAttachments::TAG, LogAttachment::TAG]);
            }));
        });

        // Who replied: the author's first name (not for automated messages).
        \Eventy::addFilter('nostr.rumor_tags', function ($tags, $options) {
            $thread = !empty($options['thread_id']) ? Thread::find($options['thread_id']) : null;
            $name = $thread && $thread->created_by_user ? trim((string) $thread->created_by_user->first_name) : '';
            if ($name !== '') {
                $tags[] = ['support_agent', $name];
            }

            return $tags;
        }, 20, 2);

        // The app and its version a message was sent from.
        \Eventy::addFilter('nostr.message_source', function ($source, $thread) {
            return MessageSource::describe($thread);
        }, 20, 2);

        \Eventy::addFilter('customapp.payload', function ($payload, $conversation, $customer, $mailbox) {
            $pubkeys = CustomerKey::forCustomer($customer->id)->pluck('pubkey')->values()->all();
            $payload['customer']['nostr_pubkeys'] = $pubkeys;
            $payload['customer']['nostr_npubs'] = array_map([Keys::class, 'npub'], $pubkeys);
            $last = NostrEvent::lastIncoming($conversation->id);
            $payload['ticket']['nostr_pubkey'] = $last->pubkey ?? null;
            $payload['ticket']['nostr_npub'] = $last ? Keys::npub($last->pubkey) : null;

            return $payload;
        }, 20, 4);

        \Eventy::addAction('mailboxes.settings.menu', function ($mailbox) {
            if (auth()->user() && auth()->user()->can('update', $mailbox)) {
                echo '<li '.(\Route::currentRouteName() == 'mailboxes.nostr.announcements' ? 'class="active"' : '').'><a href="'.route('mailboxes.nostr.announcements', ['id' => $mailbox->id]).'"><i class="glyphicon glyphicon-bullhorn"></i> '.e(__('Announcements')).'</a></li>';
            }
        }, 37);
    }
}
