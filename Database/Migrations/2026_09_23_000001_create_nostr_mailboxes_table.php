<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class CreateNostrMailboxesTable extends Migration
{
    public function up()
    {
        Schema::create('nostr_mailboxes', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('mailbox_id')->unique();
            $table->boolean('enabled')->default(false);
            // Hex public key (x-only, 64 chars).
            $table->string('pubkey', 64)->nullable()->index();
            // Private key encrypted with the application key.
            $table->text('private_key')->nullable();
            // JSON arrays of relay URLs.
            $table->text('inbox_relays')->nullable();
            $table->text('announce_relays')->nullable();
            // Kind 0 profile.
            $table->string('profile_name', 255)->nullable();
            $table->text('profile_about')->nullable();
            $table->string('profile_picture', 1024)->nullable();
            // NIP-05 local part served from /.well-known/nostr.json (empty = off).
            $table->string('nip05_name', 64)->nullable();
            // One-time auto reply for new conversations.
            $table->boolean('auto_reply_enabled')->default(false);
            $table->text('auto_reply_text')->nullable();
            // Days of silence after which a new conversation is started.
            $table->unsignedSmallInteger('reopen_days')->default(30);
            $table->dateTime('last_announced_at')->nullable();
            $table->dateTime('last_event_at')->nullable();
            $table->timestamps();
        });
    }

    public function down()
    {
        Schema::dropIfExists('nostr_mailboxes');
    }
}
