@extends('layouts.app')

@section('title_full', '12VPX '.__('Announcements').' - '.$mailbox->name)

@section('main_class', 'fruit-ui')

@section('sidebar')
    @include('mailboxes/sidebar_menu')
@endsection

@section('content')
    <div class="page-content">
        @include('partials/flash_messages')

        @php
            $event = $editing ? $editing->event : [];
            $tag = function ($name) use ($event) { return \App\Nostr\EventBuilder::firstTag($event, $name); };
        @endphp

        <div class="settings-form">
            <p class="f-help">{{ __('Public messages signed with this mailbox’s Nostr key. Do not include customer details. Clients display plain text.') }}</p>

            <form class="settings-form" method="POST" action="{{ route('mailboxes.nostr.announcements.publish', ['id' => $mailbox->id]) }}">
                {{ csrf_field() }}
                <input type="hidden" name="announcement_id" value="{{ $editing ? $editing->id : '' }}">

                <x-fruit::form-section :title="$editing ? __('Edit announcement') : __('New announcement')">
                    <x-fruit::field :label="__('Title')" layout="row">
                        <x-fruit::input name="title" required maxlength="300" :value="old('title', $tag('title'))" />
                    </x-fruit::field>

                    <x-fruit::field :label="__('Summary')" layout="row">
                        <x-fruit::input name="summary" maxlength="1000" :value="old('summary', $tag('summary'))" />
                    </x-fruit::field>

                    <x-fruit::field :label="__('Message')">
                        <x-fruit::textarea name="body" rows="8" required maxlength="16384">{{ old('body', $event['content'] ?? '') }}</x-fruit::textarea>
                    </x-fruit::field>

                    <x-fruit::field :label="__('Show an active incident banner in chat')" layout="row">
                        <x-fruit::switch name="incident" value="1" :checked="old('incident', $tag('vpx-incident')) == 'true' || old('incident') == '1'" />
                    </x-fruit::field>

                    <x-fruit::field :label="__('Expiry (UTC, optional)')" control-id="expires_at" layout="row">
                        <input type="datetime-local" class="f-input" id="expires_at" name="expires_at" value="{{ old('expires_at', $tag('expiration') ? gmdate('Y-m-d\TH:i', (int)$tag('expiration')) : '') }}">
                    </x-fruit::field>
                </x-fruit::form-section>

                <footer class="f-form-row settings-form__actions">
                    @if ($editing)
                        <a class="f-button f-button--ghost" href="{{ route('mailboxes.nostr.announcements', ['id' => $mailbox->id]) }}">{{ __('Cancel') }}</a>
                    @endif
                    <x-fruit::button type="submit" variant="primary" name="action" value="publish">{{ $editing ? __('Publish update') : __('Publish announcement') }}</x-fruit::button>
                </footer>
            </form>

            @if (count($announcements))
                <x-fruit::form-section :title="__('Announcements')">
                    @foreach ($announcements as $item)
                        <div class="f-form-row">
                            <div>
                                <strong>{{ \App\Nostr\EventBuilder::firstTag($item->event, 'title') }}</strong>
                                <span class="f-help">{{ $item->updated_at }} UTC</span>
                                <a href="{{ route('mailboxes.nostr.announcements', ['id' => $mailbox->id, 'edit' => $item->id]) }}">{{ __('Edit') }}</a>
                                @foreach ($item->relay_results ?: [] as $relay => $result)
                                    <div class="f-help">{{ $relay }}: {{ !empty($result['ok']) ? __('Accepted') : ($result['message'] ?? __('Failed')) }}</div>
                                @endforeach
                                @if (!$item->relay_results)<p class="f-help">{{ __('No relay acknowledgements recorded. Retry to check delivery.') }}</p>@endif
                            </div>
                            <form method="POST" action="{{ route('mailboxes.nostr.announcements.publish', ['id' => $mailbox->id]) }}">
                                {{ csrf_field() }}<input type="hidden" name="announcement_id" value="{{ $item->id }}">
                                <x-fruit::button type="submit" size="small" name="action" value="retry">{{ __('Retry delivery') }}</x-fruit::button>
                            </form>
                        </div>
                    @endforeach
                </x-fruit::form-section>
            @endif

            {{ $announcements->links() }}
        </div>
    </div>
@endsection
