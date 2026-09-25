<?php

namespace Modules\Nostr\Services;

use App\Customer;
use Modules\Nostr\Entities\CustomerKey;
use Modules\Nostr\Entities\NostrEvent;

class CustomerLabels
{
    private $senders = [];

    public function registerHooks(): void
    {
        \Eventy::addAction('customapp.response', function ($json, $conversation, $customer) {
            $this->sync($customer, $json['customer']['nostr_keys'] ?? []);
        }, 20, 3);

        \Eventy::addFilter('customapp.content', function ($html, $conversation, $customer) {
            if ((int) $conversation->channel !== (int) config('nostr.channel')) {
                return $html;
            }

            return $html.view('nostr::partials.customer_labels', [
                'keys' => CustomerKey::forCustomer($customer->id),
            ])->render();
        }, 20, 3);

        \Eventy::addAction('thread.before_recipients', function ($thread, $loop, $threads, $conversation) {
            if (!$thread->isCustomerMessage() || (int) $conversation->channel !== (int) config('nostr.channel')) {
                return;
            }
            // Load the displayed messages together; each message keeps its own
            // sender even when several devices share a merged conversation.
            if (!isset($this->senders[$conversation->id])) {
                $this->senders[$conversation->id] = NostrEvent::query()
                    ->leftJoin('nostr_customer_keys', 'nostr_customer_keys.pubkey', '=', 'nostr_events.pubkey')
                    ->where('nostr_events.conversation_id', $conversation->id)
                    ->whereIn('nostr_events.thread_id', collect($threads)->pluck('id')->all())
                    ->where('nostr_events.direction', NostrEvent::DIRECTION_IN)
                    ->where('nostr_events.status', NostrEvent::STATUS_OK)
                    ->get(['nostr_events.thread_id', 'nostr_events.pubkey', 'nostr_customer_keys.label'])
                    ->keyBy('thread_id');
            }
            if ($sender = $this->senders[$conversation->id]->get($thread->id)) {
                echo view('nostr::partials.thread_sender', ['sender' => $sender])->render();
            }
        }, 20, 4);

        \Eventy::addFilter('javascripts', function ($scripts) {
            $scripts[] = \Module::getPublicPath('nostr').'/js/customer-labels.js';

            return $scripts;
        });
    }

    public function sync(Customer $customer, $data): void
    {
        if (!is_array($data) || !$data || count($data) > 100) {
            return;
        }
        $labels = [];
        foreach ($data as $item) {
            if (!is_array($item) || !is_string($item['pubkey'] ?? null) || !is_string($item['label'] ?? null)
                || !preg_match('/^[0-9a-f]{64}$/D', $item['pubkey']) || trim($item['label']) === '') {
                continue;
            }
            $labels[$item['pubkey']] = mb_substr(trim($item['label']), 0, 255);
        }
        foreach (CustomerKey::where('customer_id', $customer->id)->whereIn('pubkey', array_keys($labels))->get() as $key) {
            if ($key->label !== $labels[$key->pubkey]) {
                $key->label = $labels[$key->pubkey];
                $key->save();
            }
        }
        $this->senders = [];
    }
}
