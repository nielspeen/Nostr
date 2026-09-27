<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateNostrAnnouncementsTable extends Migration
{
    public function up()
    {
        Schema::create('nostr_announcements', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('mailbox_id')->index();
            $table->string('identifier', 64)->unique();
            $table->text('event');
            $table->text('relay_results')->nullable();
            $table->timestamps();
        });
    }

    public function down()
    {
        Schema::dropIfExists('nostr_announcements');
    }
}
