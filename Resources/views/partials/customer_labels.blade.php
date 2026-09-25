<template id="nostr-customer-labels">
    @foreach ($keys as $key)
        <span data-nostr-pubkey="{{ $key->pubkey }}">{{ $key->label ?: $key->getShortNpub() }}</span>
    @endforeach
</template>
