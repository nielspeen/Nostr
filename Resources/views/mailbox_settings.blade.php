@extends('layouts.app')

@section('title_full', __('Nostr').' - '.$mailbox->name)

@section('sidebar')
    @include('partials/sidebar_menu_toggle')
    @include('mailboxes/sidebar_menu')
@endsection

@section('javascript')
    @parent
    // Copy buttons. Inline onclick handlers are blocked by the content security policy,
    // so the handler lives here, in the page script block.
    $('.nostr-copy').on('click', function (e) {
        e.preventDefault();
        var button = $(this);
        var text = String(button.data('copy'));
        var done = function () {
            var label = button.text();
            button.text('{{ __('Copied') }}');
            setTimeout(function () { button.text(label); }, 1500);
        };
        var fallback = function () {
            var area = $('<textarea readonly>').val(text).css({position: 'fixed', top: 0, left: 0, opacity: 0}).appendTo('body');
            area[0].select();
            try { if (document.execCommand('copy')) { done(); } } catch (err) {}
            area.remove();
        };
        if (navigator.clipboard && window.isSecureContext) {
            navigator.clipboard.writeText(text).then(done, fallback);
        } else {
            fallback();
        }
    });
@endsection

@section('content')

    <div class="section-heading">
        {{ __('Nostr') }}
    </div>

    @include('partials/flash_messages')

    <div class="row-container">
        <div class="row">
            <div class="col-xs-12">

                <p class="block-help">
                    {{ __('Customers can write to this mailbox with any Nostr client that supports private direct messages (NIP-17). Their messages become chat conversations and your replies are delivered back encrypted.') }}
                </p>

                @if ($cfg->pubkey)
                    <div class="panel panel-default">
                        <div class="panel-heading"><h4 class="panel-title">{{ __('Identity of this mailbox') }}</h4></div>
                        <div class="panel-body">
                            <dl class="dl-horizontal margin-bottom-0">
                                <dt>{{ __('Public key') }}</dt>
                                <dd><code>{{ $cfg->getNpub() }}</code> <a href="#" class="btn btn-default btn-xs nostr-copy" data-copy="{{ $cfg->getNpub() }}">{{ __('Copy') }}</a></dd>
                                <dt>{{ __('Hex') }}</dt>
                                <dd><small class="text-help">{{ $cfg->pubkey }}</small> <a href="#" class="btn btn-default btn-xs nostr-copy" data-copy="{{ $cfg->pubkey }}">{{ __('Copy') }}</a></dd>
                                <dt>{{ __('Key since') }}</dt>
                                <dd>{{ $cfg->key_created_at ? App\User::dateFormat($cfg->key_created_at) : '' }}</dd>
                                @if ($cfg->getNip05())
                                    <dt>{{ __('Address') }}</dt>
                                    <dd>{{ $cfg->getNip05() }} @if ($cfg->nip05ServedHere())<small class="text-success">{{ __('served by this FreeScout') }}</small>@else<small class="text-warning">{{ __('needs the file below on :domain', ['domain' => $cfg->getNip05Domain()]) }}</small>@endif</dd>
                                @endif
                                <dt>{{ __('Messages') }}</dt>
                                <dd>{{ __(':in received, :out sent', ['in' => $stats['incoming'], 'out' => $stats['outgoing']]) }}@if ($stats['failed']), <span class="text-danger">{{ __(':failed failed', ['failed' => $stats['failed']]) }}</span>@endif</dd>
                                <dt>{{ __('Last message') }}</dt>
                                <dd>{{ $cfg->last_event_at ? App\User::dateFormat($cfg->last_event_at) : __('never') }}</dd>
                                <dt>{{ __('Last announced') }}</dt>
                                <dd>{{ $cfg->last_announced_at ? App\User::dateFormat($cfg->last_announced_at) : __('never') }}</dd>
                            </dl>
                        </div>
                    </div>
                @endif

                @php
                    $ls = $listener['status'] ?? [];
                    $lstate = $listener['state'] ?? 'never';
                    $ago = function ($ts) { return $ts ? \Illuminate\Support\Carbon::createFromTimestamp($ts)->diffForHumans() : ''; };
                    $labels = [
                        'running'    => ['success', __('Running')],
                        'restarting' => ['info',    __('Restarting')],
                        'stale'      => ['danger',  __('Not responding')],
                        'stopped'    => ['danger',  __('Stopped')],
                        'never'      => ['warning', __('Not started yet')],
                        'disabled'   => ['default', __('Off')],
                    ];
                @endphp
                <div class="panel panel-default">
                    <div class="panel-heading">
                        <h4 class="panel-title">{{ __('Listener') }} <span class="label label-{{ $labels[$lstate][0] }}">{{ $labels[$lstate][1] }}</span></h4>
                    </div>
                    <div class="panel-body">
                        @if ($lstate == 'disabled')
                            <p class="text-help">{{ __('The listener starts automatically once this channel is enabled and has a key and inbox relays.') }}</p>
                        @elseif ($lstate == 'running')
                            <p>{{ __('Process :pid on :host, started :started, restarts :ends.', ['pid' => $ls['pid'] ?? '?', 'host' => $ls['host'] ?? '?', 'started' => $ago($ls['started_at'] ?? null), 'ends' => $ago($ls['ends_at'] ?? null)]) }} <small class="text-help">{{ __('Last heartbeat :ago.', ['ago' => $ago($ls['heartbeat_at'] ?? null)]) }}</small></p>
                        @elseif ($lstate == 'restarting')
                            <p>{{ __('The previous process finished its scheduled run :ago; the cron job starts a new one within a minute.', ['ago' => $ago($ls['stopped_at'] ?? null)]) }}</p>
                        @elseif ($lstate == 'stopped')
                            <p class="text-danger">{{ __('The listener stopped :ago (:reason) and has not been started again.', ['ago' => $ago($ls['stopped_at'] ?? null), 'reason' => $ls['stop_reason'] ?? '?']) }}</p>
                        @elseif ($lstate == 'stale')
                            <p class="text-danger">{{ __('No heartbeat since :ago. The process was probably killed or the server rebooted; the cron job should start a new one within a minute.', ['ago' => $ago($ls['heartbeat_at'] ?? null)]) }}</p>
                        @else
                            <p class="text-warning">{{ __('The listener has never reported in. It is started by FreeScout\'s cron job (php artisan schedule:run every minute) within a minute of enabling the channel.') }}</p>
                        @endif

                        @if ($listener['cron_ok'] === false)
                            <p class="text-danger">{{ __('FreeScout\'s cron job last ran :ago. Without it neither emails nor the listener run.', ['ago' => $ago($listener['cron_last_run'])]) }}</p>
                        @elseif ($listener['cron_ok'] === true)
                            <p class="text-help">{{ __('FreeScout\'s cron job last ran :ago.', ['ago' => $ago($listener['cron_last_run'])]) }}</p>
                        @endif

                        @if ($lstate != 'disabled' && count($listener['relays']))
                            <table class="table table-condensed margin-top">
                                <thead>
                                    <tr>
                                        <th>{{ __('Inbox relay') }}</th>
                                        <th>{{ __('Connection') }}</th>
                                        <th>{{ __('Messages') }}</th>
                                        <th>{{ __('Details') }}</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach ($listener['relays'] as $url => $relay)
                                        @php
                                            $rstate = $lstate == 'running' ? ($relay['state'] ?? 'none') : 'none';
                                            $rlabel = ['connected' => ['success', __('connected')], 'connecting' => ['warning', __('connecting')], 'reconnecting' => ['warning', __('reconnecting')], 'none' => ['default', __('not connected')]][$rstate] ?? ['default', $rstate];
                                        @endphp
                                        <tr>
                                            <td><code>{{ $url }}</code></td>
                                            <td><span class="label label-{{ $rlabel[0] }}">{{ $rlabel[1] }}</span> @if ($rstate == 'connected' && !empty($relay['since']))<small class="text-help">{{ __('since') }} {{ $ago($relay['since']) }}</small>@endif</td>
                                            <td>{{ (int) ($relay['events'] ?? 0) }}@if (!empty($relay['last_event_at'])) <small class="text-help">({{ __('last') }} {{ $ago($relay['last_event_at']) }})</small>@endif</td>
                                            <td>
                                                @if ($rstate == 'connected')
                                                    {{ !empty($relay['caught_up']) ? __('subscribed') : __('waiting for the relay') }}{{ !empty($relay['authed']) ? ', '.__('authenticated') : '' }}
                                                @elseif ($rstate == 'reconnecting')
                                                    {{ __('retry in :s s', ['s' => $relay['retry_in'] ?? '?']) }}@if (!empty($relay['error'])): <small class="text-danger">{{ $relay['error'] }}</small>@endif
                                                @elseif (!empty($relay['error']))
                                                    <small class="text-danger">{{ $relay['error'] }}</small>
                                                @endif
                                            </td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        @endif

                        @if (!empty($legacy_count))
                            <p class="text-warning">
                                {{ __(':count message(s) arrived as legacy NIP-04 direct messages, which this channel does not support; the last one :ago from :npub.', ['count' => $legacy_count, 'ago' => $legacy && $legacy->created_at ? $legacy->created_at->diffForHumans() : '', 'npub' => $legacy ? \Modules\Nostr\Services\Keys::shortNpub($legacy->pubkey) : '']) }}
                                {{ __('Ask the sender to use a client that speaks NIP-17 (Damus 1.18 or newer with legacy DMs off, Amethyst, 0xchat, Primal).') }}
                            </p>
                        @endif

                        @if ($lstate != 'disabled')
                            <form method="POST" action="{{ route('mailboxes.nostr.save', ['id' => $mailbox->id]) }}" class="form-inline">
                                {{ csrf_field() }}
                                <input type="hidden" name="action" value="diagnose">
                                <button type="submit" class="btn btn-default btn-sm">{{ __('Check relays') }}</button>
                                <small class="text-help">{{ __('Asks each relay what it holds for this mailbox (takes up to a minute).') }}</small>
                            </form>
                        @endif

                        @if (!empty($diagnose['relays']))
                            <table class="table table-condensed margin-top">
                                <thead>
                                    <tr>
                                        <th>{{ __('Relay') }}</th>
                                        <th>{{ __('Connection') }}</th>
                                        <th>{{ __('Gift wraps (3 days)') }}</th>
                                        <th>{{ __('Legacy NIP-04') }}</th>
                                        <th>{{ __('Profile') }}</th>
                                        <th>{{ __('DM relay list') }}</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach ($diagnose['relays'] as $url => $row)
                                        <tr>
                                            <td><code>{{ $url }}</code>@if (!empty($row['inbox'])) <small class="text-help">{{ __('inbox') }}</small>@endif</td>
                                            <td>@if ($row['ok'])<span class="label label-success">{{ __('ok') }}</span>@else<span class="label label-danger">{{ __('failed') }}</span> <small class="text-danger">{{ $row['error'] }}</small>@endif</td>
                                            <td>{{ $row['wraps'] }}@if ($row['unseen_wraps']) <span class="label label-warning">{{ __(':n not yet received', ['n' => $row['unseen_wraps']]) }}</span>@endif</td>
                                            <td>{{ $row['legacy'] }}</td>
                                            <td>{{ $row['profile'] ? __('found') : __('missing') }}</td>
                                            <td>@if ($row['dm_relays'] === null){{ __('missing') }}@else<small>{{ implode(' ', $row['dm_relays']) }}</small>@endif</td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                            <p class="text-help">{{ __('"Not yet received" gift wraps sit on a relay the listener does not watch or arrived while it was down; add that relay to the inbox relays or wait for the next run. A missing DM relay list on the relays a sender uses means their client cannot find where to deliver: publish the profile again.') }}</p>
                        @endif

                        @if ($listener['log'] !== '')
                            <p><a data-toggle="collapse" href="#nostr-listener-log" class="btn btn-default btn-xs">{{ __('Show listener log') }}</a> <small class="text-help">storage/logs/nostr-listen.log</small></p>
                            <div id="nostr-listener-log" class="collapse"><pre style="max-height: 300px; overflow: auto;">{{ $listener['log'] }}</pre></div>
                        @endif
                    </div>
                </div>

                @if (session('nostr_reveal_nsec'))
                    <div class="alert alert-warning">
                        <strong>{{ __('Private key of this mailbox') }}</strong> <small>{{ __('(shown once; store it somewhere safe, anyone who has it can read and send messages as this mailbox)') }}</small>
                        <pre class="margin-top">{{ session('nostr_reveal_nsec') }}</pre>
                    </div>
                @endif

                <h3 class="subheader">{{ __('Keys') }}</h3>

                @if (!$cfg->pubkey)
                    <div class="form-horizontal">
                        <div class="form-group">
                            <label class="col-sm-2 control-label">{{ __('Keypair') }}</label>
                            <div class="col-sm-8">
                                <p class="form-control-static text-warning">{{ __('No keypair yet. Generate one, or import the private key of an existing Nostr identity.') }}</p>
                                <form method="POST" action="{{ route('mailboxes.nostr.save', ['id' => $mailbox->id]) }}" class="form-inline">
                                    {{ csrf_field() }}
                                    <input type="hidden" name="action" value="generate">
                                    <button type="submit" class="btn btn-primary btn-sm">{{ __('Generate keypair') }}</button>
                                </form>
                            </div>
                        </div>
                        <div class="form-group">
                            <label for="nostr_nsec" class="col-sm-2 control-label">{{ __('Import private key') }}</label>
                            <div class="col-sm-8">
                                <form method="POST" action="{{ route('mailboxes.nostr.save', ['id' => $mailbox->id]) }}">
                                    {{ csrf_field() }}
                                    <input type="hidden" name="action" value="import">
                                    <div class="input-group">
                                        <input type="password" id="nostr_nsec" name="nsec" class="form-control" placeholder="nsec1…" autocomplete="off">
                                        <span class="input-group-btn"><button type="submit" class="btn btn-default">{{ __('Import') }}</button></span>
                                    </div>
                                    <div class="form-help">{{ __('nsec or hex. The private key is stored encrypted with the application key.') }}</div>
                                </form>
                            </div>
                        </div>
                    </div>
                @else
                    <p class="block-help">
                        {{ __('The keypair is the identity customers write to. It is never deleted by accident: replacing it retires the old key, which keeps receiving messages and keeps answering its conversations, and every change below asks for your password.') }}
                    </p>

                    @if (count($retired_keys))
                        <table class="table table-condensed">
                            <thead>
                                <tr>
                                    <th>{{ __('Retired key') }}</th>
                                    <th>{{ __('Used') }}</th>
                                    <th>{{ __('Retired') }}</th>
                                    <th>{{ __('Messages') }}</th>
                                    <th></th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($retired_keys as $key)
                                    <tr>
                                        <td><code title="{{ $key->pubkey }}">{{ $key->getNpub() }}</code></td>
                                        <td>{{ $key->key_created_at ? App\User::dateFormat($key->key_created_at, 'M j, Y') : '' }}</td>
                                        <td>{{ $key->retired_at ? App\User::dateFormat($key->retired_at, 'M j, Y') : '' }}</td>
                                        <td>{{ $key->getMessageCount() }}@if ($key->getLastMessageAt()) <small class="text-help">({{ __('last') }} {{ App\User::dateFormat(\Illuminate\Support\Carbon::parse($key->getLastMessageAt()), 'M j, Y') }})</small>@endif</td>
                                        <td class="text-right"><a data-toggle="collapse" href="#nostr-delete-key-{{ $key->id }}" class="btn btn-default btn-xs">{{ __('Delete…') }}</a></td>
                                    </tr>
                                    <tr id="nostr-delete-key-{{ $key->id }}" class="collapse">
                                        <td colspan="5">
                                            <form method="POST" action="{{ route('mailboxes.nostr.save', ['id' => $mailbox->id]) }}" class="form-inline">
                                                {{ csrf_field() }}
                                                <input type="hidden" name="action" value="delete_key">
                                                <input type="hidden" name="key_id" value="{{ $key->id }}">
                                                <span class="text-danger">{{ __('Messages still sent to this key will be unreadable forever.') }}</span>
                                                <input type="password" name="password" class="form-control input-sm" placeholder="{{ __('Your password') }}" autocomplete="current-password" required>
                                                <input type="text" name="confirm" class="form-control input-sm" placeholder="{{ __('Type DELETE') }}" autocomplete="off" required>
                                                <button type="submit" class="btn btn-danger btn-sm">{{ __('Delete retired key') }}</button>
                                            </form>
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    @endif

                    <p>
                        <a data-toggle="collapse" href="#nostr-key-reveal" class="btn btn-default btn-sm">{{ __('Show private key (backup)…') }}</a>
                        <a data-toggle="collapse" href="#nostr-key-replace" class="btn btn-default btn-sm">{{ __('Replace the key…') }}</a>
                    </p>

                    <div id="nostr-key-reveal" class="collapse">
                        <div class="panel panel-default">
                            <div class="panel-body">
                                <form method="POST" action="{{ route('mailboxes.nostr.save', ['id' => $mailbox->id]) }}" class="form-inline">
                                    {{ csrf_field() }}
                                    <input type="hidden" name="action" value="reveal">
                                    <p class="text-help">{{ __('Keep a copy of the private key outside this server so the identity survives a lost database or a migration.') }}</p>
                                    <input type="password" name="password" class="form-control" placeholder="{{ __('Your password') }}" autocomplete="current-password" required>
                                    <button type="submit" class="btn btn-default">{{ __('Show private key') }}</button>
                                </form>
                            </div>
                        </div>
                    </div>

                    <div id="nostr-key-replace" class="collapse">
                        <div class="panel panel-danger">
                            <div class="panel-heading"><h4 class="panel-title">{{ __('Replace the key') }}</h4></div>
                            <div class="panel-body">
                                <form method="POST" action="{{ route('mailboxes.nostr.save', ['id' => $mailbox->id]) }}" class="form-horizontal">
                                    {{ csrf_field() }}
                                    <input type="hidden" name="action" value="replace">
                                    <p class="text-help">{{ __('Customers who saved the current public key can still reach this mailbox afterwards: the current key is retired, not deleted. New customers are pointed to the new key through the profile, the relay lists and the address.') }}</p>
                                    <div class="form-group">
                                        <label class="col-sm-3 control-label">{{ __('New key') }}</label>
                                        <div class="col-sm-9">
                                            <label class="radio-inline"><input type="radio" name="replace_mode" value="generate" checked> {{ __('Generate') }}</label>
                                            <label class="radio-inline"><input type="radio" name="replace_mode" value="import"> {{ __('Import') }}</label>
                                            <input type="password" name="nsec" class="form-control margin-top-10" placeholder="{{ __('nsec1… (only when importing)') }}" autocomplete="off">
                                        </div>
                                    </div>
                                    <div class="form-group">
                                        <label class="col-sm-3 control-label">{{ __('Your password') }}</label>
                                        <div class="col-sm-9"><input type="password" name="password" class="form-control" autocomplete="current-password" required></div>
                                    </div>
                                    <div class="form-group">
                                        <label class="col-sm-3 control-label">{{ __('Type REPLACE') }}</label>
                                        <div class="col-sm-9"><input type="text" name="confirm" class="form-control" autocomplete="off" required></div>
                                    </div>
                                    <div class="form-group">
                                        <div class="col-sm-9 col-sm-offset-3"><button type="submit" class="btn btn-danger">{{ __('Replace the key') }}</button></div>
                                    </div>
                                </form>
                            </div>
                        </div>
                    </div>
                @endif

                <form class="form-horizontal margin-top" method="POST" action="{{ route('mailboxes.nostr.save', ['id' => $mailbox->id]) }}">
                    {{ csrf_field() }}

                    <div class="form-group{{ $errors->has('enabled') ? ' has-error' : '' }}">
                        <label for="nostr_enabled" class="col-sm-2 control-label">{{ __('Enable Nostr') }}</label>
                        <div class="col-sm-6">
                            <div class="controls">
                                <div class="onoffswitch-wrap">
                                    <div class="onoffswitch">
                                        <input type="checkbox" name="enabled" value="1" id="nostr_enabled" class="onoffswitch-checkbox" @if (old('enabled', $cfg->enabled))checked="checked"@endif>
                                        <label class="onoffswitch-label" for="nostr_enabled"></label>
                                    </div>
                                </div>
                            </div>
                            @include('partials/field_error', ['field'=>'enabled'])
                        </div>
                    </div>

                    <h3 class="subheader">{{ __('Relays') }}</h3>

                    <div class="form-group{{ $errors->has('inbox_relays') || $errors->has('inbox_relays.*') ? ' has-error' : '' }}">
                        <label for="nostr_inbox_relays" class="col-sm-2 control-label">{{ __('Inbox relays') }}</label>
                        <div class="col-sm-8">
                            <textarea id="nostr_inbox_relays" name="inbox_relays" class="form-control" rows="4">{{ old('inbox_relays', implode("\n", $cfg->getInboxRelays())) }}</textarea>
                            <div class="form-help">{{ __('One per line. Where customers deliver their messages and where FreeScout listens. Keep this list short (1-3 relays); it is published as your DM relay list (kind 10050).') }}</div>
                            @include('partials/field_error', ['field'=>'inbox_relays'])
                        </div>
                    </div>

                    <div class="form-group{{ $errors->has('announce_relays') || $errors->has('announce_relays.*') ? ' has-error' : '' }}">
                        <label for="nostr_announce_relays" class="col-sm-2 control-label">{{ __('Announce relays') }}</label>
                        <div class="col-sm-8">
                            <textarea id="nostr_announce_relays" name="announce_relays" class="form-control" rows="4">{{ old('announce_relays', implode("\n", $cfg->getAnnounceRelays())) }}</textarea>
                            <div class="form-help">{{ __('One per line. Popular relays where the profile and relay lists of this mailbox are published, and where customer profiles are looked up.') }}</div>
                            @include('partials/field_error', ['field'=>'announce_relays'])
                        </div>
                    </div>

                    <h3 class="subheader">{{ __('Public profile') }}</h3>

                    <div class="form-group{{ $errors->has('profile_name') ? ' has-error' : '' }}">
                        <label for="nostr_profile_name" class="col-sm-2 control-label">{{ __('Name') }}</label>
                        <div class="col-sm-6">
                            <input type="text" id="nostr_profile_name" name="profile_name" class="form-control" value="{{ old('profile_name', $cfg->profile_name ?? '') }}" placeholder="{{ $mailbox->name }}" maxlength="255">
                            @include('partials/field_error', ['field'=>'profile_name'])
                        </div>
                    </div>

                    <div class="form-group">
                        <label for="nostr_profile_about" class="col-sm-2 control-label">{{ __('About') }}</label>
                        <div class="col-sm-6">
                            <textarea id="nostr_profile_about" name="profile_about" class="form-control" rows="3">{{ old('profile_about', $cfg->profile_about ?? '') }}</textarea>
                        </div>
                    </div>

                    <div class="form-group{{ $errors->has('profile_picture') ? ' has-error' : '' }}">
                        <label for="nostr_profile_picture" class="col-sm-2 control-label">{{ __('Picture URL') }}</label>
                        <div class="col-sm-6">
                            <input type="url" id="nostr_profile_picture" name="profile_picture" class="form-control" value="{{ old('profile_picture', $cfg->profile_picture ?? '') }}" placeholder="https://">
                            @include('partials/field_error', ['field'=>'profile_picture'])
                        </div>
                    </div>

                    <div class="form-group{{ $errors->has('nip05') ? ' has-error' : '' }}">
                        <label for="nostr_nip05" class="col-sm-2 control-label">{{ __('Address') }}</label>
                        <div class="col-sm-6">
                            <input type="text" id="nostr_nip05" name="nip05" class="form-control" value="{{ old('nip05', $cfg->nip05 ?? '') }}" placeholder="support@yourdomain.com" maxlength="255">
                            <div class="form-help">
                                {{ __('Optional NIP-05 address customers can look up instead of the npub. Any domain works: the domain must serve /.well-known/nostr.json.') }}
                                @if ($cfg->getNip05Domain() && $cfg->nip05ServedHere())
                                    <span class="text-success">{{ __('This FreeScout answers on :domain, so the file is served automatically.', ['domain' => $cfg->getNip05Domain()]) }}</span>
                                @endif
                            </div>
                            @include('partials/field_error', ['field'=>'nip05'])
                        </div>
                    </div>

                    @if ($nip05_json && !$cfg->nip05ServedHere())
                        <div class="form-group">
                            <div class="col-sm-8 col-sm-offset-2">
                                <div class="panel panel-default">
                                    <div class="panel-heading"><h4 class="panel-title">{{ __('Host this file at :url', ['url' => $nip05_url]) }}</h4></div>
                                    <div class="panel-body">
                                        <p class="text-help">{{ __('Serve it as application/json with the header Access-Control-Allow-Origin: *. Update it when you change the inbox relays or the key.') }}</p>
                                        <pre>{{ $nip05_json }}</pre>
                                    </div>
                                </div>
                            </div>
                        </div>
                    @endif

                    <h3 class="subheader">{{ __('Conversations') }}</h3>

                    <div class="form-group{{ $errors->has('reopen_days') ? ' has-error' : '' }}">
                        <label for="nostr_reopen_days" class="col-sm-2 control-label">{{ __('Reopen window') }}</label>
                        <div class="col-sm-6">
                            <div class="input-group input-sized">
                                <input type="number" id="nostr_reopen_days" name="reopen_days" class="form-control" value="{{ old('reopen_days', $cfg->reopen_days ?: 30) }}" min="1" max="3650">
                                <span class="input-group-addon">{{ __('days') }}</span>
                            </div>
                            <div class="form-help">{{ __('A new message reopens the customer\'s latest Nostr conversation if it had activity within this many days; otherwise a new conversation is started.') }}</div>
                            @include('partials/field_error', ['field'=>'reopen_days'])
                        </div>
                    </div>

                    <div class="form-group">
                        <label for="nostr_auto_reply_enabled" class="col-sm-2 control-label">{{ __('Auto reply') }}</label>
                        <div class="col-sm-6">
                            <div class="controls">
                                <div class="onoffswitch-wrap">
                                    <div class="onoffswitch">
                                        <input type="checkbox" name="auto_reply_enabled" value="1" id="nostr_auto_reply_enabled" class="onoffswitch-checkbox" @if (old('auto_reply_enabled', $cfg->auto_reply_enabled))checked="checked"@endif>
                                        <label class="onoffswitch-label" for="nostr_auto_reply_enabled"></label>
                                    </div>
                                </div>
                            </div>
                            <div class="form-help">{{ __('Sent once when a new conversation is started, not when an existing one is reopened.') }}</div>
                        </div>
                    </div>

                    <div class="form-group{{ $errors->has('auto_reply_text') ? ' has-error' : '' }}">
                        <label for="nostr_auto_reply_text" class="col-sm-2 control-label">{{ __('Auto reply text') }}</label>
                        <div class="col-sm-8">
                            <textarea id="nostr_auto_reply_text" name="auto_reply_text" class="form-control" rows="4">{{ old('auto_reply_text', $cfg->auto_reply_text ?? '') }}</textarea>
                            <div class="form-help">{{ __('Plain text. Nostr messages have no formatting.') }}</div>
                            @include('partials/field_error', ['field'=>'auto_reply_text'])
                        </div>
                    </div>

                    <div class="form-group margin-top">
                        <div class="col-sm-8 col-sm-offset-2">
                            <button type="submit" name="action" value="save" class="btn btn-primary">{{ __('Save') }}</button>
                            @if ($cfg->pubkey)
                                <button type="submit" name="action" value="announce" class="btn btn-default" title="{{ __('Publish the profile and relay lists to the relays now') }}">{{ __('Publish profile now') }}</button>
                            @endif
                        </div>
                    </div>
                </form>

                <h3 class="subheader">{{ __('How it works') }}</h3>
                <ul class="text-help">
                    <li>{{ __('The relay listener runs from the FreeScout cron (nostr:listen). Check storage/logs/nostr-listen.log if messages do not arrive.') }}</li>
                    <li>{{ __('Unknown senders become new customers; link additional public keys on the Nostr tab of a customer profile.') }}</li>
                    <li>{{ __('Replies are plain text. Attachments are sent as download links.') }}</li>
                </ul>

            </div>
        </div>
    </div>
@endsection
