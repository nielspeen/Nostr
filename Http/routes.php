<?php

Route::group(['middleware' => ['web', 'auth'], 'prefix' => \Helper::getSubdirectory(), 'namespace' => 'Modules\Nostr\Http\Controllers'], function () {
    Route::get('/mailbox/settings/{id}/nostr', 'NostrController@mailboxSettings')->name('mailboxes.nostr');
    Route::post('/mailbox/settings/{id}/nostr', 'NostrController@mailboxSettingsSave')->name('mailboxes.nostr.save');

    Route::get('/customers/{id}/nostr', 'NostrController@customerKeys')->name('customers.nostr');
    Route::post('/customers/{id}/nostr', 'NostrController@customerKeysSave')->name('customers.nostr.save');
});

// NIP-05: public, no session, no browser check (Nostr clients fetch it).
Route::get(\Helper::getSubdirectory().'/.well-known/nostr.json', 'Modules\Nostr\Http\Controllers\NostrController@nip05')->name('nostr.nip05');
