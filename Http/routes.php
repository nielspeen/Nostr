<?php

Route::group(['middleware' => ['web', 'auth'], 'prefix' => \Helper::getSubdirectory(), 'namespace' => 'Modules\Nostr\Http\Controllers'], function () {
    Route::get('/mailbox/settings/{id}/nostr/announcements', 'AnnouncementsController@index')->name('mailboxes.nostr.announcements');
    Route::post('/mailbox/settings/{id}/nostr/announcements', 'AnnouncementsController@publish')->name('mailboxes.nostr.announcements.publish');
});
