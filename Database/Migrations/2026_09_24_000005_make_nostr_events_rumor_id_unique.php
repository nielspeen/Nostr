<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class MakeNostrEventsRumorIdUnique extends Migration
{
    public function up()
    {
        // A rumor (the actual message) may arrive in several gift wraps; only one may become a thread.
        $duplicates = \DB::table('nostr_events')
            ->select('rumor_id')
            ->whereNotNull('rumor_id')
            ->groupBy('rumor_id')
            ->havingRaw('COUNT(*) > 1')
            ->pluck('rumor_id');
        foreach ($duplicates as $rumorId) {
            $keep = \DB::table('nostr_events')->where('rumor_id', $rumorId)->min('id');
            \DB::table('nostr_events')->where('rumor_id', $rumorId)->where('id', '!=', $keep)
                ->update(['rumor_id' => null, 'error' => 'duplicate']);
        }

        Schema::table('nostr_events', function (Blueprint $table) {
            $table->dropIndex(['rumor_id']);
            $table->unique('rumor_id');
        });
    }

    public function down()
    {
        Schema::table('nostr_events', function (Blueprint $table) {
            $table->dropUnique(['rumor_id']);
            $table->index('rumor_id');
        });
    }
}
