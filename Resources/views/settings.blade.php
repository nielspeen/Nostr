<form class="form-horizontal margin-top" method="POST" action="">
    {{ csrf_field() }}

    <p class="block-help">{{ __('Defaults used when Nostr is set up for a mailbox. Each mailbox can override them in Mailbox Settings » Nostr.') }}</p>

    <div class="form-group">
        <label for="nostr_default_inbox_relays" class="col-sm-2 control-label">{{ __('Default inbox relays') }}</label>
        <div class="col-sm-6">
            <textarea id="nostr_default_inbox_relays" name="settings[nostr.default_inbox_relays]" class="form-control" rows="4">{{ implode("\n", (array) $settings['nostr.default_inbox_relays']) }}</textarea>
            <div class="form-help">{{ __('One per line. Relays where customers deliver messages and FreeScout listens.') }}</div>
        </div>
    </div>

    <div class="form-group">
        <label for="nostr_default_announce_relays" class="col-sm-2 control-label">{{ __('Default announce relays') }}</label>
        <div class="col-sm-6">
            <textarea id="nostr_default_announce_relays" name="settings[nostr.default_announce_relays]" class="form-control" rows="4">{{ implode("\n", (array) $settings['nostr.default_announce_relays']) }}</textarea>
            <div class="form-help">{{ __('One per line. Relays where mailbox profiles are published and customer profiles are looked up.') }}</div>
        </div>
    </div>

    <div class="form-group margin-top">
        <div class="col-sm-6 col-sm-offset-2">
            <button type="submit" class="btn btn-primary">{{ __('Save') }}</button>
        </div>
    </div>
</form>
