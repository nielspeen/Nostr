<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class CreateNostrCustomerKeysTable extends Migration
{
    public function up()
    {
        // A customer can have several Nostr public keys (personal client, one per app install...).
        // The core customer_channel table only holds one id per channel, so keys live here
        // and only the primary key is mirrored into the core table.
        Schema::create('nostr_customer_keys', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('customer_id')->index();
            $table->string('pubkey', 64)->unique();
            $table->string('label', 255)->nullable();
            // auto (first message), manual (agent), api.
            $table->string('source', 16)->default('auto');
            // Cached kind 0 profile (JSON).
            $table->text('profile')->nullable();
            // Cached kind 10050 DM relay list (JSON) and when it was fetched.
            $table->text('dm_relays')->nullable();
            $table->dateTime('dm_relays_fetched_at')->nullable();
            $table->dateTime('first_seen_at')->nullable();
            $table->dateTime('last_seen_at')->nullable();
            $table->timestamps();
        });
    }

    public function down()
    {
        Schema::dropIfExists('nostr_customer_keys');
    }
}
