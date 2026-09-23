<?php

namespace Modules\Nostr\Console;

use Illuminate\Console\Command;
use Modules\Nostr\Services\IncomingMessageHandler;
use Modules\Nostr\Services\Listener;

class ListenCommand extends Command
{
    protected $signature = 'nostr:listen
        {--once : Fetch pending messages once and exit instead of staying connected}
        {--lifetime= : Seconds to stay running before exiting (the scheduler restarts it)}';

    protected $description = 'Listen on the configured relays for Nostr messages addressed to the mailboxes';

    public function handle()
    {
        $logger = function ($message) {
            $this->line('['.date('Y-m-d H:i:s').'] '.$message);
        };

        $handler = new IncomingMessageHandler($logger);
        $lifetime = (int) ($this->option('lifetime') ?: config('nostr.listener_lifetime', 1200));
        $listener = new Listener($handler, $lifetime, $logger);

        if ($this->option('once')) {
            $count = $listener->runOnce();
            $this->line('Processed '.$count.' new message(s)');

            return 0;
        }

        $listener->run();

        return 0;
    }
}
