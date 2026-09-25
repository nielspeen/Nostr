<div class="nostr-thread-from">
    <strong>{{ __('From') }}:</strong>
    <span class="nostr-device-label" data-nostr-pubkey="{{ $sender->pubkey }}" title="{{ \Modules\Nostr\Services\Keys::npub($sender->pubkey) }}">{{ $sender->label ?: \Modules\Nostr\Services\Keys::shortNpub($sender->pubkey) }}</span>
</div>
