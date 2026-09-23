<?php

namespace Modules\Nostr\Providers;

use App\Customer;
use App\CustomerChannel;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;
use Modules\Nostr\Console\AnnounceCommand;
use Modules\Nostr\Console\ListenCommand;
use Modules\Nostr\Entities\CustomerKey;
use Modules\Nostr\Entities\NostrEvent;
use Modules\Nostr\Entities\NostrMailbox;
use Modules\Nostr\Services\Announcer;
use Modules\Nostr\Services\Keys;
use Modules\Nostr\Services\OutgoingMessageSender;
use Modules\Nostr\Services\RelayClient;
use Modules\Nostr\Services\RelayDiscovery;

// Register the module's vendored packages *after* the application's autoloader.
// Composer's own autoload.php would prepend itself and shadow core packages
// (for example psr/log) with incompatible versions.
if (!class_exists('Modules\\Nostr\\Providers\\NostrVendorLoader', false)) {
    class NostrVendorLoader
    {
        public static function register()
        {
            $vendor = realpath(__DIR__.'/../vendor');
            if (!$vendor || !is_file($vendor.'/composer/autoload_psr4.php')) {
                return;
            }
            $loader = new \Composer\Autoload\ClassLoader($vendor);
            foreach (require $vendor.'/composer/autoload_psr4.php' as $namespace => $paths) {
                $loader->setPsr4($namespace, $paths);
            }
            $classMap = require $vendor.'/composer/autoload_classmap.php';
            if ($classMap) {
                $loader->addClassMap($classMap);
            }
            $loader->register(false);

            if (is_file($vendor.'/composer/autoload_files.php')) {
                foreach (require $vendor.'/composer/autoload_files.php' as $identifier => $file) {
                    if (empty($GLOBALS['__composer_autoload_files'][$identifier])) {
                        require $file;
                        $GLOBALS['__composer_autoload_files'][$identifier] = true;
                    }
                }
            }
        }
    }
    NostrVendorLoader::register();
}

class NostrServiceProvider extends ServiceProvider
{
    const MODULE = 'nostr';

    /**
     * Indicates if loading of the provider is deferred.
     *
     * @var bool
     */
    protected $defer = false;

    public function boot()
    {
        $this->registerConfig();
        $this->registerViews();
        $this->registerCommands();
        $this->loadMigrationsFrom(__DIR__.'/../Database/Migrations');
        $this->hooks();
    }

    /**
     * Module hooks.
     */
    public function hooks()
    {
        $channel = (int) config('nostr.channel');

        // Register the channel with the core.
        \Eventy::addFilter('channels.list', function ($channels) use ($channel) {
            $channels[$channel] = __('Nostr');

            return $channels;
        });
        \Eventy::addFilter('channel.name', function ($name, $code) use ($channel) {
            return (int) $code === $channel ? __('Nostr') : $name;
        }, 20, 2);

        // Mailbox Settings » Nostr.
        \Eventy::addAction('mailboxes.settings.menu', function ($mailbox) {
            $user = auth()->user();
            if ($user && $user->can('update', $mailbox)) {
                echo View::make('nostr::partials/settings_menu', ['mailbox' => $mailbox])->render();
            }
        }, 36);

        // Agent replied to a chat conversation: deliver it over Nostr.
        \Eventy::addAction('chat_conversation.send_reply', function ($conversation, $replies, $customer) {
            try {
                (new OutgoingMessageSender(self::logger()))->handleSendReply($conversation, $replies);
            } catch (\Throwable $e) {
                \Log::error('[Nostr] Could not send reply: '.$e->getMessage(), ['exception' => $e]);
            }
        }, 20, 3);

        // Background actions dispatched by the listener and the settings page.
        \Eventy::addAction('nostr.auto_reply', function ($conversation_id, $cfg_id, $pubkey) {
            try {
                (new OutgoingMessageSender(self::logger()))->sendAutoReply($conversation_id, $cfg_id, $pubkey);
            } catch (\Throwable $e) {
                \Log::error('[Nostr] Could not send auto reply: '.$e->getMessage(), ['exception' => $e]);
            }
        }, 20, 3);
        \Eventy::addAction('nostr.fetch_profile', function ($customer_id, $pubkey, $cfg_id) {
            try {
                self::fetchProfile($customer_id, $pubkey, $cfg_id);
            } catch (\Throwable $e) {
                \Log::error('[Nostr] Could not fetch profile: '.$e->getMessage(), ['exception' => $e]);
            }
        }, 20, 3);
        \Eventy::addAction('nostr.announce', function ($cfg_id) {
            $cfg = NostrMailbox::find($cfg_id);
            if ($cfg) {
                (new Announcer(self::logger()))->announce($cfg);
            }
        }, 20, 1);

        // Customer profile: Nostr tab and key list in the sidebar.
        \Eventy::addAction('customers.profile_tabs.append', function () {
            $route = \Route::current();
            $id = $route ? $route->parameter('id') : null;
            if ($id) {
                echo View::make('nostr::partials/profile_tab', [
                    'customer_id' => $id,
                    'count' => CustomerKey::where('customer_id', $id)->count(),
                ])->render();
            }
        });
        \Eventy::addAction('customer.profile.extra', function ($customer, $conversation = null) {
            $keys = CustomerKey::forCustomer($customer->id);
            if (count($keys)) {
                echo View::make('nostr::partials/customer_keys_snippet', ['keys' => $keys])->render();
            }
        }, 20, 2);

        // Keep keys when customers are merged.
        \Eventy::addAction('customer.merged', function ($customer, $customer2, $user = null) use ($channel) {
            CustomerKey::where('customer_id', $customer2->id)->update(['customer_id' => $customer->id]);
            CustomerChannel::where('customer_id', $customer2->id)->where('channel', $channel)->delete();
            CustomerKey::syncPrimary($customer);
        }, 20, 3);

        // Line item shown when the auto reply was sent.
        \Eventy::addFilter('thread.action_types', function ($types) {
            $types[OutgoingMessageSender::ACTION_TYPE_AUTO_REPLY] = 'nostr_auto_reply';

            return $types;
        });
        \Eventy::addFilter('thread.action_text', function ($text, $thread) {
            if ((int) $thread->action_type === OutgoingMessageSender::ACTION_TYPE_AUTO_REPLY) {
                return __('sent the Nostr auto reply').': "'.e(\Helper::textPreview($thread->body, 200)).'"';
            }

            return $text;
        }, 20, 2);
        \Eventy::addFilter('thread.action_person', function ($person, $thread) {
            if ((int) $thread->action_type === OutgoingMessageSender::ACTION_TYPE_AUTO_REPLY) {
                return __('System');
            }

            return $person;
        }, 20, 2);

        // Hand the customer's keys to the CustomApp callback so the backend can link them.
        \Eventy::addFilter('customapp.payload', function ($payload, $conversation, $customer, $mailbox) {
            $pubkeys = CustomerKey::forCustomer($customer->id)->pluck('pubkey')->values()->all();
            $payload['customer']['nostr_pubkeys'] = $pubkeys;
            $payload['customer']['nostr_npubs'] = array_map([Keys::class, 'npub'], $pubkeys);
            $last = NostrEvent::lastIncoming($conversation->id);
            $payload['ticket']['nostr_pubkey'] = $last->pubkey ?? null;
            $payload['ticket']['nostr_npub'] = $last ? Keys::npub($last->pubkey) : null;

            return $payload;
        }, 20, 4);

        // Scheduler: relay listener (long running) and daily announcements.
        \Eventy::addFilter('schedule', function ($schedule) {
            $lifetime = (int) config('nostr.listener_lifetime', 1200);
            $expires = (int) ceil($lifetime / 60) + 5;

            $listen = $schedule->command('nostr:listen')
                ->everyMinute()
                ->withoutOverlapping($expires)
                ->runInBackground()
                ->when(function () {
                    try {
                        return NostrMailbox::active()->count() > 0;
                    } catch (\Throwable $e) {
                        return false;
                    }
                })
                ->sendOutputTo(storage_path('logs/nostr-listen.log'));

            // The listener died without releasing its mutex: release it so it can be restarted.
            if (function_exists('shell_exec')) {
                try {
                    $mutex = $listen->mutexName();
                    if (\Cache::has($mutex) && !count(\Helper::getRunningProcesses('nostr:listen'))) {
                        \Cache::forget($mutex);
                    }
                } catch (\Throwable $e) {
                    // Ignore.
                }
            }

            $schedule->command('nostr:announce')->dailyAt('04:30')->withoutOverlapping();

            return $schedule;
        });

        // Settings » Nostr: default relays for new mailboxes.
        \Eventy::addFilter('settings.sections', function ($sections) {
            $sections[self::MODULE] = ['title' => __('Nostr'), 'icon' => 'flash', 'order' => 650];

            return $sections;
        }, 40);
        \Eventy::addFilter('settings.section_settings', function ($settings, $section) {
            if ($section != self::MODULE) {
                return $settings;
            }
            $settings['nostr.default_inbox_relays'] = \Option::get('nostr.default_inbox_relays') ?: config('nostr.default_inbox_relays', []);
            $settings['nostr.default_announce_relays'] = \Option::get('nostr.default_announce_relays') ?: config('nostr.default_announce_relays', []);

            return $settings;
        }, 20, 2);
        \Eventy::addFilter('settings.section_params', function ($params, $section) {
            if ($section != self::MODULE) {
                return $params;
            }
            $params['settings'] = [
                'nostr.default_inbox_relays' => [],
                'nostr.default_announce_relays' => [],
            ];

            return $params;
        }, 20, 2);
        \Eventy::addFilter('settings.view', function ($view, $section) {
            return $section == self::MODULE ? 'nostr::settings' : $view;
        }, 20, 2);
        \Eventy::addFilter('settings.before_save', function ($request, $section, $settings) {
            if ($section != self::MODULE || empty($request->settings)) {
                return $request;
            }
            $values = $request->settings;
            foreach (['nostr.default_inbox_relays', 'nostr.default_announce_relays'] as $name) {
                if (array_key_exists($name, $values)) {
                    $values[$name] = NostrMailbox::normalizeRelays($values[$name]);
                }
            }
            $request->merge(['settings' => $values]);

            return $request;
        }, 20, 3);
        \Eventy::addFilter('settings.after_save', function ($response, $request, $section, $settings) {
            if ($section == self::MODULE) {
                \Session::flash('flash_success_floating', __('Settings updated'));
            }

            return $response;
        }, 20, 4);
    }

    /**
     * Fill in the name and picture of an auto-created customer from their kind 0 profile,
     * and cache their DM relays for later replies.
     */
    public static function fetchProfile($customer_id, $pubkey, $cfg_id)
    {
        $customer = Customer::find($customer_id);
        $cfg = NostrMailbox::find($cfg_id);
        if (!$customer || !$cfg || !$cfg->getPrivateKey()) {
            return;
        }

        $client = new RelayClient(RelayClient::authSignerForKey($cfg->getPrivateKey()), self::logger());
        $discovery = new RelayDiscovery($client);
        $relays = $cfg->getAllRelays();

        $profile = $discovery->profile($pubkey, $relays);
        $dmRelays = $discovery->dmRelays($pubkey, $relays);

        $key = CustomerKey::byPubkey($pubkey);
        if ($key) {
            if ($profile) {
                $key->setProfile($profile);
            }
            $key->setDmRelays($dmRelays);
            $key->save();
        }

        if (!$profile) {
            return;
        }

        $name = trim((string) ($profile['display_name'] ?? '')) ?: trim((string) ($profile['name'] ?? ''));
        if ($name !== '' && $customer->first_name === Keys::shortNpub($pubkey)) {
            $parts = preg_split('/\s+/', $name, 2);
            $customer->first_name = mb_substr($parts[0], 0, 255);
            $customer->last_name = isset($parts[1]) ? mb_substr($parts[1], 0, 255) : null;
            $customer->save();
        }

        if (!empty($profile['picture']) && !$customer->photo_url && preg_match('#^https?://#i', $profile['picture'])) {
            try {
                $customer->setPhotoFromRemoteFile($profile['picture']);
            } catch (\Throwable $e) {
                // A missing avatar is not a problem.
            }
        }
    }

    /**
     * Logger used outside the console: writes to the Laravel log.
     */
    public static function logger()
    {
        return function ($message) {
            \Log::info('[Nostr] '.$message);
        };
    }

    protected function registerConfig()
    {
        $this->publishes([
            __DIR__.'/../Config/config.php' => config_path(self::MODULE.'.php'),
        ], 'config');
        $this->mergeConfigFrom(
            __DIR__.'/../Config/config.php',
            self::MODULE
        );
    }

    public function registerViews()
    {
        $viewPath = resource_path('views/modules/'.self::MODULE);
        $sourcePath = __DIR__.'/../Resources/views';

        $this->publishes([
            $sourcePath => $viewPath,
        ], 'views');

        $this->loadViewsFrom(array_merge(array_map(function ($path) {
            return $path.'/modules/'.self::MODULE;
        }, \Config::get('view.paths')), [$sourcePath]), self::MODULE);
    }

    public function registerCommands()
    {
        $this->commands(ListenCommand::class);
        $this->commands(AnnounceCommand::class);
    }

    public function provides()
    {
        return [];
    }
}
