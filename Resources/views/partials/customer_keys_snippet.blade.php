@if (count($keys))
    <div class="customer-section">
        <ul class="customer-contacts">
            @foreach ($keys as $key)
                <li title="{{ $key->getNpub() }}">
                    <i class="glyphicon glyphicon-flash"></i>
                    <a class="nostr-device-label" data-nostr-pubkey="{{ $key->pubkey }}" href="{{ route('customers.nostr', ['id' => $key->customer_id]) }}">{{ $key->label ?: $key->getShortNpub() }}</a>
                    @if ($key->label)<small class="text-help">{{ $key->getShortNpub() }}</small>@endif
                </li>
            @endforeach
        </ul>
    </div>
@endif
