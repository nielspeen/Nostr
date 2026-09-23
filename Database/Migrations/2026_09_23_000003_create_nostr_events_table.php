<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class CreateNostrEventsTable extends Migration
{
    public function up()
    {
        // Every gift wrap received or sent. Used for de-duplication (relays deliver the
        // same wrap more than once), for finding which pubkey to reply to, and for
        // threading replies with "e" tags.
        Schema::create('nostr_events', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('mailbox_id')->index();
            // 1 = incoming, 2 = outgoing.
            $table->unsignedTinyInteger('direction');
            // Kind 1059 event id.
            $table->string('wrap_id', 64)->nullable()->unique();
            // Kind 14/15 rumor id.
            $table->string('rumor_id', 64)->nullable()->index();
            // The other party's public key.
            $table->string('pubkey', 64)->index();
            $table->unsignedSmallInteger('kind')->default(14);
            $table->unsignedInteger('conversation_id')->nullable()->index();
            $table->unsignedInteger('thread_id')->nullable()->index();
            // Relay the wrap arrived on, or JSON publish results for outgoing wraps.
            $table->string('relay', 255)->nullable();
            $table->text('relays')->nullable();
            // 1 = ok, 2 = failed.
            $table->unsignedTinyInteger('status')->default(1);
            $table->text('error')->nullable();
            // created_at of the rumor (real time of the message).
            $table->dateTime('event_created_at')->nullable();
            $table->timestamps();
        });
    }

    public function down()
    {
        Schema::dropIfExists('nostr_events');
    }
}
