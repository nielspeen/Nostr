<?php

namespace Modules\Nostr\Http\Controllers;

use App\Customer;
use App\Http\Controllers\Controller;
use App\Mailbox;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Modules\Nostr\Entities\CustomerKey;
use Modules\Nostr\Entities\MailboxKey;
use Modules\Nostr\Entities\NostrEvent;
use Modules\Nostr\Entities\NostrMailbox;
use Modules\Nostr\Services\Announcer;
use Modules\Nostr\Services\Diagnostics;
use Modules\Nostr\Services\Keys;
use Modules\Nostr\Services\ListenerStatus;

class NostrController extends Controller
{
    /**
     * Mailbox Settings » Nostr.
     */
    public function mailboxSettings($id)
    {
        $mailbox = Mailbox::findOrFail($id);
        $this->authorize('update', $mailbox);

        $cfg = NostrMailbox::forMailbox($mailbox->id);

        $stats = [
            'incoming' => NostrEvent::where('mailbox_id', $mailbox->id)->where('direction', NostrEvent::DIRECTION_IN)->whereNotNull('thread_id')->count(),
            'outgoing' => NostrEvent::where('mailbox_id', $mailbox->id)->where('direction', NostrEvent::DIRECTION_OUT)->where('status', NostrEvent::STATUS_OK)->count(),
            'failed' => NostrEvent::where('mailbox_id', $mailbox->id)->where('direction', NostrEvent::DIRECTION_OUT)->where('status', NostrEvent::STATUS_FAILED)->count(),
        ];

        $nip05Json = '';
        if ($cfg->pubkey && $cfg->getNip05Name()) {
            $nip05Json = json_encode([
                'names' => [$cfg->getNip05Name() => $cfg->pubkey],
                'relays' => [$cfg->pubkey => $cfg->getInboxRelays()],
            ], JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
        }

        return view('nostr::mailbox_settings', [
            'mailbox' => $mailbox,
            'cfg' => $cfg,
            'stats' => $stats,
            'retired_keys' => $cfg->exists ? $cfg->getRetiredKeys() : collect(),
            'listener' => ListenerStatus::forMailbox($cfg),
            'legacy' => $cfg->exists ? NostrEvent::where('mailbox_id', $mailbox->id)->where('kind', \Modules\Nostr\Services\IncomingMessageHandler::KIND_LEGACY_DM)->orderBy('id', 'desc')->first() : null,
            'legacy_count' => $cfg->exists ? NostrEvent::where('mailbox_id', $mailbox->id)->where('kind', \Modules\Nostr\Services\IncomingMessageHandler::KIND_LEGACY_DM)->count() : 0,
            'diagnose' => session('nostr_diagnose'),
            'nip05_json' => $nip05Json,
            'nip05_url' => $cfg->getNip05Domain() ? 'https://'.$cfg->getNip05Domain().'/.well-known/nostr.json' : '',
        ]);
    }

    public function mailboxSettingsSave($id, Request $request)
    {
        $mailbox = Mailbox::findOrFail($id);
        $this->authorize('update', $mailbox);

        $cfg = NostrMailbox::forMailbox($mailbox->id);
        $action = $request->input('action', 'save');

        switch ($action) {
            // First key only. Once a key exists it can only be replaced through the gated "replace" action.
            case 'generate':
            case 'import':
                if ($cfg->pubkey) {
                    \Session::flash('flash_error_floating', __('This mailbox already has a key. Use "Replace the key" below, which asks for your password.'));
                    break;
                }
                if ($action === 'import') {
                    $hex = Keys::toHex($request->input('nsec'), 'priv');
                    if (!$hex) {
                        \Session::flash('flash_error_floating', __('This is not a valid private key (nsec or hex).'));
                        break;
                    }
                    if (MailboxKey::where('pubkey', Keys::pubkeyFromPrivate($hex))->exists() || NostrMailbox::byPubkey(Keys::pubkeyFromPrivate($hex))) {
                        \Session::flash('flash_error_floating', __('This key is already used by a mailbox.'));
                        break;
                    }
                } else {
                    $hex = Keys::generatePrivateKey();
                }
                $cfg->setPrivateKey($hex);
                $cfg->save();
                \Session::flash('flash_success_floating', __('The mailbox now has a keypair. Its public key is :npub. Back it up with "Show private key" before sharing it.', ['npub' => $cfg->getNpub()]));
                break;

            case 'replace':
                if (!$cfg->pubkey) {
                    \Session::flash('flash_error_floating', __('There is no key to replace yet.'));
                    break;
                }
                if (!$this->confirmDangerousAction($request, 'REPLACE')) {
                    break;
                }
                if ($request->input('replace_mode') === 'import') {
                    $hex = Keys::toHex($request->input('nsec'), 'priv');
                    if (!$hex) {
                        \Session::flash('flash_error_floating', __('This is not a valid private key (nsec or hex).'));
                        break;
                    }
                    $newPubkey = Keys::pubkeyFromPrivate($hex);
                    if ($newPubkey === $cfg->pubkey || MailboxKey::where('pubkey', $newPubkey)->exists() || NostrMailbox::byPubkey($newPubkey)) {
                        \Session::flash('flash_error_floating', __('This key is already used by a mailbox.'));
                        break;
                    }
                } else {
                    $hex = Keys::generatePrivateKey();
                }
                $old = $cfg->getNpub();
                $cfg->replaceKey($hex);
                $cfg->save();
                if ($cfg->enabled && $cfg->getAllRelays()) {
                    \Helper::backgroundAction('nostr.announce', [$cfg->id]);
                }
                \Session::flash('flash_success_floating', __('The key was replaced. The new public key is :npub. The previous key (:old) was retired: it still receives messages and its conversations are still answered from it, but it is no longer announced.', ['npub' => $cfg->getNpub(), 'old' => Keys::shortNpub(Keys::toHex($old))]));
                break;

            case 'reveal':
                if (!$cfg->pubkey) {
                    break;
                }
                if (!$this->confirmDangerousAction($request, null)) {
                    break;
                }
                // Shown once on the next page load, never stored in the session longer than that.
                \Session::flash('nostr_reveal_nsec', $cfg->getNsec());
                break;

            case 'diagnose':
                if (!$cfg->pubkey || !$cfg->getPrivateKey()) {
                    \Session::flash('flash_error_floating', __('Generate a key first.'));
                    break;
                }
                set_time_limit(120);
                $result = (new Diagnostics())->run($cfg, 5);
                \Session::flash('nostr_diagnose', $result);
                break;

            case 'delete_key':
                if (!$this->confirmDangerousAction($request, 'DELETE')) {
                    break;
                }
                $key = MailboxKey::where('mailbox_id', $mailbox->id)->where('id', (int) $request->input('key_id'))->first();
                if (!$key) {
                    \Session::flash('flash_error_floating', __('Retired key not found.'));
                    break;
                }
                $npub = $key->getShortNpub();
                $key->delete();
                \Session::flash('flash_success_floating', __('The retired key :npub was deleted. Messages sent to it can no longer be read.', ['npub' => $npub]));
                break;

            case 'announce':
                if (!$cfg->pubkey || !$cfg->getAllRelays()) {
                    \Session::flash('flash_error_floating', __('Generate a key and configure relays first.'));
                    break;
                }
                $results = (new Announcer())->announce($cfg);
                $accepted = 0;
                $total = 0;
                foreach ($results as $relays) {
                    foreach ($relays as $result) {
                        $total++;
                        if (!empty($result['ok'])) {
                            $accepted++;
                        }
                    }
                }
                if ($accepted) {
                    \Session::flash('flash_success_floating', __('Published to the relays (:accepted of :total accepted).', ['accepted' => $accepted, 'total' => $total]));
                } else {
                    \Session::flash('flash_error_floating', __('No relay accepted the events.'));
                }
                break;

            default:
                return $this->saveSettings($mailbox, $cfg, $request);
        }

        return redirect()->route('mailboxes.nostr', ['id' => $id]);
    }

    /**
     * Destructive key operations need the admin's password and, when given, a typed phrase.
     */
    protected function confirmDangerousAction(Request $request, $phrase)
    {
        $user = auth()->user();
        $password = (string) $request->input('password', '');

        if (!$user || $password === '' || !\Hash::check($password, $user->password)) {
            \Session::flash('flash_error_floating', __('Wrong password. Nothing was changed.'));

            return false;
        }
        if ($phrase !== null && strtoupper(trim((string) $request->input('confirm', ''))) !== $phrase) {
            \Session::flash('flash_error_floating', __('Type :phrase to confirm. Nothing was changed.', ['phrase' => $phrase]));

            return false;
        }

        return true;
    }

    protected function saveSettings(Mailbox $mailbox, NostrMailbox $cfg, Request $request)
    {
        $input = [
            'enabled' => (bool) $request->input('enabled'),
            'inbox_relays' => NostrMailbox::normalizeRelays($request->input('inbox_relays', '')),
            'announce_relays' => NostrMailbox::normalizeRelays($request->input('announce_relays', '')),
            'profile_name' => trim((string) $request->input('profile_name', '')),
            'profile_about' => trim((string) $request->input('profile_about', '')),
            'profile_picture' => trim((string) $request->input('profile_picture', '')),
            'nip05' => strtolower(trim((string) $request->input('nip05', ''))),
            'auto_reply_enabled' => (bool) $request->input('auto_reply_enabled'),
            'auto_reply_text' => trim((string) $request->input('auto_reply_text', '')),
            'reopen_days' => (int) $request->input('reopen_days', 30),
        ];

        $validator = Validator::make($input, [
            'inbox_relays' => 'array',
            'inbox_relays.*' => ['regex:#^wss?://[^\s/]+#i'],
            'announce_relays' => 'array',
            'announce_relays.*' => ['regex:#^wss?://[^\s/]+#i'],
            'profile_name' => 'nullable|string|max:255',
            'profile_picture' => 'nullable|url|max:1024',
            'nip05' => ['nullable', 'regex:/^[a-z0-9._-]+@([a-z0-9-]+\.)+[a-z]{2,}$/', 'max:255'],
            'reopen_days' => 'required|integer|min:1|max:3650',
        ]);
        $validator->after(function ($validator) use ($input, $cfg) {
            if ($input['enabled'] && !$cfg->pubkey) {
                $validator->errors()->add('enabled', __('Generate or import a key before enabling the channel.'));
            }
            if ($input['enabled'] && !$input['inbox_relays']) {
                $validator->errors()->add('inbox_relays', __('At least one inbox relay is required.'));
            }
            if ($input['auto_reply_enabled'] && $input['auto_reply_text'] === '') {
                $validator->errors()->add('auto_reply_text', __('Enter the auto reply text.'));
            }
        });

        if ($validator->fails()) {
            return redirect()->route('mailboxes.nostr', ['id' => $mailbox->id])
                ->withErrors($validator)
                ->withInput();
        }

        $announceBefore = $cfg->exists ? md5(json_encode([$cfg->inbox_relays, $cfg->profile_name, $cfg->profile_about, $cfg->profile_picture, $cfg->nip05])) : '';

        $cfg->enabled = $input['enabled'];
        $cfg->setInboxRelays($input['inbox_relays']);
        $cfg->setAnnounceRelays($input['announce_relays']);
        $cfg->profile_name = $input['profile_name'] ?: null;
        $cfg->profile_about = $input['profile_about'] ?: null;
        $cfg->profile_picture = $input['profile_picture'] ?: null;
        $cfg->nip05 = $input['nip05'] ?: null;
        $cfg->auto_reply_enabled = $input['auto_reply_enabled'];
        $cfg->auto_reply_text = $input['auto_reply_text'] ?: null;
        $cfg->reopen_days = $input['reopen_days'];
        $cfg->save();

        \Session::flash('flash_success_floating', __('Settings updated'));

        // Public metadata changed: republish in the background.
        $announceAfter = md5(json_encode([$cfg->inbox_relays, $cfg->profile_name, $cfg->profile_about, $cfg->profile_picture, $cfg->nip05]));
        if ($cfg->enabled && $cfg->pubkey && $cfg->getAllRelays() && ($announceBefore !== $announceAfter || !$cfg->last_announced_at)) {
            \Helper::backgroundAction('nostr.announce', [$cfg->id]);
        }

        return redirect()->route('mailboxes.nostr', ['id' => $mailbox->id]);
    }

    /**
     * Customer profile » Nostr tab: the customer's public keys.
     */
    public function customerKeys($id)
    {
        $customer = Customer::findOrFail($id);

        return view('nostr::customer_keys', [
            'customer' => $customer,
            'keys' => CustomerKey::forCustomer($customer->id),
        ]);
    }

    public function customerKeysSave($id, Request $request)
    {
        $customer = Customer::findOrFail($id);
        $action = $request->input('action');

        if ($action === 'add') {
            $hex = Keys::toHex($request->input('pubkey'));
            $label = mb_substr(trim((string) $request->input('label', '')), 0, 255);

            if (!$hex) {
                \Session::flash('flash_error_floating', __('This is not a valid public key. Use an npub, nprofile or hex key.'));
            } else {
                $existing = CustomerKey::byPubkey($hex);
                if ($existing && $existing->customer_id != $customer->id) {
                    $other = $existing->customer;
                    $name = $other ? $other->getFullName(true) : '#'.$existing->customer_id;
                    $url = $other ? $other->url() : '';
                    \Session::flash('flash_error_floating', __('This key already belongs to :customer. Merge the two customers if they are the same person.', [
                        'customer' => $url ? '<a href="'.$url.'">'.e($name).'</a>' : e($name),
                    ]));
                } else {
                    CustomerKey::link($customer, $hex, CustomerKey::SOURCE_MANUAL, $label);
                    \Session::flash('flash_success_floating', __('Public key added'));
                }
            }
        } elseif ($action === 'remove') {
            $key = CustomerKey::where('customer_id', $customer->id)->where('id', (int) $request->input('key_id'))->first();
            if ($key) {
                $key->delete();
                CustomerKey::syncPrimary($customer);
                \Session::flash('flash_success_floating', __('Public key removed'));
            }
        } elseif ($action === 'label') {
            $key = CustomerKey::where('customer_id', $customer->id)->where('id', (int) $request->input('key_id'))->first();
            if ($key) {
                $key->label = mb_substr(trim((string) $request->input('label', '')), 0, 255) ?: null;
                $key->save();
                \Session::flash('flash_success_floating', __('Label updated'));
            }
        }

        return redirect()->route('customers.nostr', ['id' => $customer->id]);
    }

    /**
     * NIP-05: /.well-known/nostr.json?name=support
     */
    public function nip05(Request $request)
    {
        $name = strtolower(trim((string) $request->input('name', '')));
        $names = [];
        $relays = [];

        $rows = NostrMailbox::where('enabled', true)
            ->whereNotNull('pubkey')
            ->whereNotNull('nip05')
            ->get();

        foreach ($rows as $cfg) {
            $localPart = strtolower($cfg->getNip05Name());
            if ($localPart === '' || ($name !== '' && $localPart !== $name)) {
                continue;
            }
            $names[$localPart] = $cfg->pubkey;
            if ($cfg->getInboxRelays()) {
                $relays[$cfg->pubkey] = $cfg->getInboxRelays();
            }
        }

        return response()->json(['names' => (object) $names, 'relays' => (object) $relays], 200, [
            'Access-Control-Allow-Origin' => '*',
            'Cache-Control' => 'public, max-age=300',
        ], JSON_UNESCAPED_SLASHES);
    }
}
