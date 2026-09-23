<?php

namespace Modules\Nostr\Services;

use Modules\Nostr\Entities\NostrMailbox;

/**
 * Heartbeat written by the listener daemon and read by the settings page.
 */
class ListenerStatus
{
    const OPTION = 'nostr.listener';

    // No heartbeat for this long means the process is gone (it beats every 30s).
    const STALE_AFTER = 150;
    // After a scheduled exit the cron restarts it within about a minute.
    const RESTART_GRACE = 180;
    // FreeScout's own cron indicator (fetch-emails runs every minute).
    const CRON_STALE_AFTER = 300;

    public static function write(array $status)
    {
        $status['heartbeat_at'] = time();
        \Option::set(self::OPTION, $status);
    }

    public static function read()
    {
        $status = \Option::get(self::OPTION, null, true, false);

        return is_array($status) ? $status : null;
    }

    /**
     * What the settings page shows for one mailbox.
     *
     * state: disabled | never | running | restarting | stale | stopped
     */
    public static function forMailbox(NostrMailbox $cfg)
    {
        $status = self::read();
        $now = time();

        $summary = [
            'state' => 'never',
            'status' => $status,
            'relays' => [],
            'cron_last_run' => null,
            'cron_ok' => null,
            'log' => self::logTail(),
        ];

        if (!$cfg->enabled || !$cfg->pubkey || !$cfg->getInboxRelays()) {
            $summary['state'] = 'disabled';
        } elseif (!$status) {
            $summary['state'] = 'never';
        } else {
            $heartbeat = (int) ($status['heartbeat_at'] ?? 0);
            $stopped = (int) ($status['stopped_at'] ?? 0);
            if ($stopped && $stopped >= $heartbeat) {
                $scheduled = ($status['stop_reason'] ?? '') === 'lifetime';
                $summary['state'] = ($scheduled && $now - $stopped < self::RESTART_GRACE) ? 'restarting' : 'stopped';
            } elseif ($now - $heartbeat > self::STALE_AFTER) {
                $summary['state'] = 'stale';
            } else {
                $summary['state'] = 'running';
            }
        }

        foreach ($cfg->getInboxRelays() as $url) {
            $found = null;
            foreach ($status['connections'] ?? [] as $connection) {
                if ((int) ($connection['mailbox_id'] ?? 0) === (int) $cfg->mailbox_id && ($connection['url'] ?? '') === $url) {
                    $found = $connection;
                    break;
                }
            }
            $summary['relays'][$url] = $found ?: ['state' => 'none'];
        }

        $cron = \Option::get('fetch_emails_last_run');
        if ($cron) {
            $summary['cron_last_run'] = (int) $cron;
            $summary['cron_ok'] = ($now - (int) $cron) < self::CRON_STALE_AFTER;
        }

        return $summary;
    }

    /**
     * Last lines of the listener log written by the scheduler.
     */
    public static function logTail($lines = 25)
    {
        $path = storage_path('logs/nostr-listen.log');
        try {
            if (!is_file($path) || !is_readable($path)) {
                return '';
            }
            $size = filesize($path);
            $handle = fopen($path, 'r');
            if (!$handle) {
                return '';
            }
            $chunk = 8192;
            fseek($handle, max(0, $size - $chunk));
            $data = (string) stream_get_contents($handle);
            fclose($handle);
            $rows = explode("\n", trim($data));
            if ($size > $chunk && count($rows) > 1) {
                array_shift($rows);
            }

            return implode("\n", array_slice($rows, -$lines));
        } catch (\Throwable $e) {
            return '';
        }
    }
}
