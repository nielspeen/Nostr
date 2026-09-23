@extends('layouts.app')

@section('title_full', $customer->getFullName(true).' - '.__('Nostr'))
@section('body_class', 'sidebar-no-height')

@section('body_attrs')@parent data-customer_id="{{ $customer->id }}"@endsection

@section('sidebar')
    <div class="profile-preview">
        @include('customers/profile_menu')
        @include('customers/profile_snippet')
    </div>
@endsection

@section('content')
    @include('customers/profile_tabs')

    <div class="container form-container">
        <div class="row">
            <div class="col-xs-12 col-md-10">

                @include('partials/flash_messages')

                <p class="block-help">
                    {{ __('Messages from any of these public keys are added to this customer\'s Nostr conversations. Replies go to the key that wrote last.') }}
                </p>

                @if (count($keys))
                    <table class="table table-striped">
                        <thead>
                            <tr>
                                <th>{{ __('Label') }}</th>
                                <th>{{ __('Public key') }}</th>
                                <th>{{ __('Source') }}</th>
                                <th>{{ __('Last message') }}</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($keys as $key)
                                <tr>
                                    <td>
                                        <form method="POST" action="{{ route('customers.nostr.save', ['id' => $customer->id]) }}" class="form-inline">
                                            {{ csrf_field() }}
                                            <input type="hidden" name="action" value="label">
                                            <input type="hidden" name="key_id" value="{{ $key->id }}">
                                            <div class="input-group input-group-sm">
                                                <input type="text" name="label" class="form-control" value="{{ $key->label ?? '' }}" placeholder="{{ __('e.g. iPhone app') }}" maxlength="255">
                                                <span class="input-group-btn"><button type="submit" class="btn btn-default" title="{{ __('Save label') }}"><i class="glyphicon glyphicon-ok"></i></button></span>
                                            </div>
                                        </form>
                                    </td>
                                    <td>
                                        <code title="{{ $key->pubkey }}">{{ $key->getNpub() }}</code>
                                        @if ($key->getDisplayName())<br><small class="text-help">{{ $key->getDisplayName() }}</small>@endif
                                    </td>
                                    <td>{{ $key->source }}</td>
                                    <td>{{ $key->last_seen_at ? App\User::dateFormat($key->last_seen_at) : '' }}</td>
                                    <td class="text-right">
                                        <form method="POST" action="{{ route('customers.nostr.save', ['id' => $customer->id]) }}" onsubmit="return confirm('{{ __('Remove this key from the customer?') }}');">
                                            {{ csrf_field() }}
                                            <input type="hidden" name="action" value="remove">
                                            <input type="hidden" name="key_id" value="{{ $key->id }}">
                                            <button type="submit" class="btn btn-default btn-sm" title="{{ __('Remove') }}"><i class="glyphicon glyphicon-remove"></i></button>
                                        </form>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                @else
                    <p class="text-help">{{ __('No Nostr public keys are linked to this customer yet.') }}</p>
                @endif

                <h3 class="subheader">{{ __('Link a public key') }}</h3>

                <form method="POST" action="{{ route('customers.nostr.save', ['id' => $customer->id]) }}" class="form-horizontal">
                    {{ csrf_field() }}
                    <input type="hidden" name="action" value="add">
                    <div class="form-group">
                        <label for="nostr_pubkey" class="col-sm-2 control-label">{{ __('Public key') }}</label>
                        <div class="col-sm-8">
                            <input type="text" id="nostr_pubkey" name="pubkey" class="form-control" placeholder="npub1…" required>
                            <div class="form-help">{{ __('npub, nprofile or 64 character hex key.') }}</div>
                        </div>
                    </div>
                    <div class="form-group">
                        <label for="nostr_label" class="col-sm-2 control-label">{{ __('Label') }}</label>
                        <div class="col-sm-8">
                            <input type="text" id="nostr_label" name="label" class="form-control" placeholder="{{ __('e.g. Personal, Android app') }}" maxlength="255">
                        </div>
                    </div>
                    <div class="form-group">
                        <div class="col-sm-8 col-sm-offset-2">
                            <button type="submit" class="btn btn-primary">{{ __('Add key') }}</button>
                        </div>
                    </div>
                </form>

            </div>
        </div>
    </div>
@endsection
