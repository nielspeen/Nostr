@extends('layouts.app')
@section('title_full', __('Announcements').' - '.$mailbox->name)
@section('sidebar')
    @include('partials/sidebar_menu_toggle')
    @include('mailboxes/sidebar_menu')
@endsection
@section('content')
<div class="section-heading">{{ __('Announcements') }}</div>
@include('partials/flash_messages')
<div class="container-fluid">
    <p class="text-help">{{ __('Public messages signed with this mailbox’s Nostr key. Do not include customer details. Clients display plain text.') }}</p>
    @php
        $event = $editing ? $editing->event : [];
        $tag = function ($name) use ($event) { return \App\Nostr\EventBuilder::firstTag($event, $name); };
    @endphp
    <form method="POST" action="{{ route('mailboxes.nostr.announcements.publish', ['id' => $mailbox->id]) }}">
        {{ csrf_field() }}
        <input type="hidden" name="announcement_id" value="{{ $editing ? $editing->id : '' }}">
        <div class="form-group"><label>{{ __('Title') }}</label><input class="form-control" name="title" required maxlength="300" value="{{ old('title', $tag('title')) }}"></div>
        <div class="form-group"><label>{{ __('Summary') }}</label><input class="form-control" name="summary" maxlength="1000" value="{{ old('summary', $tag('summary')) }}"></div>
        <div class="form-group"><label>{{ __('Message') }}</label><textarea class="form-control" rows="8" name="body" required maxlength="16384">{{ old('body', $event['content'] ?? '') }}</textarea></div>
        <div class="checkbox"><label><input type="checkbox" name="incident" value="1" @if(old('incident', $tag('vpx-incident')) == 'true' || old('incident') == '1') checked @endif> {{ __('Show an active incident banner in chat') }}</label></div>
        <div class="form-group"><label>{{ __('Expiry (UTC, optional)') }}</label><input class="form-control" type="datetime-local" name="expires_at" value="{{ old('expires_at', $tag('expiration') ? gmdate('Y-m-d\TH:i', (int)$tag('expiration')) : '') }}"></div>
        <button class="btn btn-primary" name="action" value="publish">{{ $editing ? __('Publish update') : __('Publish announcement') }}</button>
        @if($editing)<a class="btn btn-link" href="{{ route('mailboxes.nostr.announcements', ['id' => $mailbox->id]) }}">{{ __('Cancel') }}</a>@endif
    </form>
    <hr>
    @foreach($announcements as $item)
        <div class="panel panel-default"><div class="panel-body">
            <strong>{{ \App\Nostr\EventBuilder::firstTag($item->event, 'title') }}</strong>
            <span class="text-muted">{{ $item->updated_at }} UTC</span>
            <a href="{{ route('mailboxes.nostr.announcements', ['id' => $mailbox->id, 'edit' => $item->id]) }}">{{ __('Edit') }}</a>
            @foreach($item->relay_results ?: [] as $relay => $result)
                <div>{{ $relay }}: {{ !empty($result['ok']) ? __('Accepted') : ($result['message'] ?? __('Failed')) }}</div>
            @endforeach
            @if(!$item->relay_results)<p>{{ __('No relay acknowledgements recorded. Retry to check delivery.') }}</p>@endif
            <form method="POST" action="{{ route('mailboxes.nostr.announcements.publish', ['id' => $mailbox->id]) }}">
                {{ csrf_field() }}<input type="hidden" name="announcement_id" value="{{ $item->id }}">
                <button class="btn btn-default btn-xs" name="action" value="retry">{{ __('Retry delivery') }}</button>
            </form>
        </div></div>
    @endforeach
    {{ $announcements->links() }}
</div>
@endsection
