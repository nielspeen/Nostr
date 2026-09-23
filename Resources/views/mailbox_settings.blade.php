@extends('layouts.app')

@section('title_full', __('Nostr').' - '.$mailbox->name)

@section('sidebar')
    @include('partials/sidebar_menu_toggle')
    @include('mailboxes/sidebar_menu')
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
                                <dd><code id="nostr-npub">{{ $cfg->getNpub() }}</code> <a href="#" class="btn btn-default btn-xs" onclick="navigator.clipboard && navigator.clipboard.writeText(document.getElementById('nostr-npub').textContent); return false;">{{ __('Copy') }}</a></dd>
                                <dt>{{ __('Hex') }}</dt>
                                <dd><small class="text-help">{{ $cfg->pubkey }}</small></dd>
                                @if ($cfg->getNip05())
                                    <dt>{{ __('Address') }}</dt>
                                    <dd>{{ $cfg->getNip05() }}</dd>
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

                    <h3 class="subheader">{{ __('Keys') }}</h3>

                    <div class="form-group">
                        <label class="col-sm-2 control-label">{{ __('Keypair') }}</label>
                        <div class="col-sm-8">
                            @if ($cfg->pubkey)
                                <p class="form-control-static">{{ __('This mailbox has a keypair.') }} <small class="text-help">{{ __('Generating a new one changes the public key customers write to.') }}</small></p>
                                <button type="submit" name="action" value="generate" class="btn btn-default btn-sm" onclick="return confirm('{{ __('Replace the current keypair? Customers who saved the old public key will no longer reach this mailbox.') }}');">{{ __('Generate new keypair') }}</button>
                            @else
                                <p class="form-control-static text-warning">{{ __('No keypair yet. Generate one or import an existing private key.') }}</p>
                                <button type="submit" name="action" value="generate" class="btn btn-primary btn-sm">{{ __('Generate keypair') }}</button>
                            @endif
                        </div>
                    </div>

                    <div class="form-group">
                        <label for="nostr_nsec" class="col-sm-2 control-label">{{ __('Import private key') }}</label>
                        <div class="col-sm-8">
                            <div class="input-group">
                                <input type="password" id="nostr_nsec" name="nsec" class="form-control" placeholder="nsec1…" autocomplete="off">
                                <span class="input-group-btn"><button type="submit" name="action" value="import" class="btn btn-default" onclick="return document.getElementById('nostr_nsec').value !== '' && confirm('{{ __('Replace the current keypair with the imported key?') }}');">{{ __('Import') }}</button></span>
                            </div>
                            <div class="form-help">{{ __('nsec or hex. The private key is stored encrypted with the application key and never shown again.') }}</div>
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

                    <div class="form-group{{ $errors->has('nip05_name') ? ' has-error' : '' }}">
                        <label for="nostr_nip05_name" class="col-sm-2 control-label">{{ __('Verified address') }}</label>
                        <div class="col-sm-6">
                            <div class="input-group">
                                <input type="text" id="nostr_nip05_name" name="nip05_name" class="form-control" value="{{ old('nip05_name', $cfg->nip05_name ?? '') }}" placeholder="support" maxlength="64">
                                <span class="input-group-addon">{{ '@'.$nip05_host }}</span>
                            </div>
                            <div class="form-help">
                                {{ __('Optional NIP-05 address served by FreeScout at /.well-known/nostr.json. Leave empty to disable.') }}
                                @if (!$nip05_root)
                                    <span class="text-warning">{{ __('FreeScout is installed in a subdirectory, so the address only works if your web server maps /.well-known/nostr.json to it.') }}</span>
                                @endif
                            </div>
                            @include('partials/field_error', ['field'=>'nip05_name'])
                        </div>
                    </div>

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
