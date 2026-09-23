<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class AddKeyHistoryToNostrTables extends Migration
{
    public function up()
    {
        // Keys are never destroyed: a replaced key is retired here and keeps working.
        Schema::create('nostr_mailbox_keys', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('mailbox_id')->index();
            $table->string('pubkey', 64)->unique();
            $table->text('private_key');
            $table->dateTime('key_created_at')->nullable();
            $table->dateTime('retired_at')->nullable();
            $table->timestamps();
        });

        Schema::table('nostr_events', function (Blueprint $table) {
            // Which of the mailbox's keys the message was addressed to (or sent from).
            $table->string('mailbox_pubkey', 64)->nullable()->index();
        });

        Schema::table('nostr_mailboxes', function (Blueprint $table) {
            $table->dateTime('key_created_at')->nullable();
            // Full NIP-05 address (name@domain); the domain does not have to be this installation.
            $table->string('nip05', 255)->nullable();
        });

        // Carry over the old local-part-only setting.
        $host = parse_url(config('app.url'), PHP_URL_HOST);
        foreach (\DB::table('nostr_mailboxes')->whereNotNull('nip05_name')->get() as $row) {
            \DB::table('nostr_mailboxes')->where('id', $row->id)->update(['nip05' => $row->nip05_name.'@'.$host]);
        }
        \DB::table('nostr_mailboxes')->whereNotNull('pubkey')->whereNull('key_created_at')->update(['key_created_at' => \DB::raw('created_at')]);

        Schema::table('nostr_mailboxes', function (Blueprint $table) {
            $table->dropColumn('nip05_name');
        });
    }

    public function down()
    {
        Schema::table('nostr_mailboxes', function (Blueprint $table) {
            $table->string('nip05_name', 64)->nullable();
        });
        foreach (\DB::table('nostr_mailboxes')->whereNotNull('nip05')->get() as $row) {
            \DB::table('nostr_mailboxes')->where('id', $row->id)->update(['nip05_name' => explode('@', $row->nip05)[0]]);
        }
        Schema::table('nostr_mailboxes', function (Blueprint $table) {
            $table->dropColumn(['key_created_at', 'nip05']);
        });
        Schema::table('nostr_events', function (Blueprint $table) {
            $table->dropColumn('mailbox_pubkey');
        });
        Schema::dropIfExists('nostr_mailbox_keys');
    }
}
