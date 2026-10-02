<?php

namespace Modules\Nostr\Services;

use App\Customer;
use App\Nostr\CustomerKey;

/**
 * Device names of customers' keys, from the CustomApp callback: the
 * response's customer.nostr_keys ([{"pubkey", "label"}]) relabel the
 * customer's keys, and the page shows the new names right away.
 */
class CustomerLabels
{
    public function registerHooks()
    {
        \Eventy::addAction('customapp.response', function ($json, $conversation, $customer) {
            $this->sync($customer, $json['customer']['nostr_keys'] ?? []);
        }, 20, 3);

        \Eventy::addFilter('customapp.content', function ($html, $conversation, $customer) {
            if (!\App\Nostr\Nostr::isNostr($conversation)) {
                return $html;
            }

            return $html.view('nostr::partials.customer_labels', [
                'keys' => CustomerKey::forCustomer($customer->id),
            ])->render();
        }, 20, 3);

        \Eventy::addFilter('javascripts', function ($scripts) {
            $scripts[] = \Module::getPublicPath('nostr').'/js/customer-labels.js';

            return $scripts;
        });
    }

    public function sync(Customer $customer, $data)
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
    }
}
