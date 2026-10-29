<?php

namespace InsightsSDK;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * One product's reporter. Nothing is stored or sent until an administrator
 * opts in. The SDK holds no identity of its own: every request carries the
 * 'site_id' the host product supplies (e.g. the site URL or a licence ID).
 *
 * What the backend receives, and what it lets the product owner answer:
 *
 *  - A weekly snapshot (ingest): every declared feature with its tier, whether
 *    it's enabled, since when, and how often it was used since the last
 *    snapshot. Paid installs with many unused paid features are upsell
 *    candidates ("leaving money on the table"); features switched off soon
 *    after being switched on, or enabled but never used, aren't sticking.
 *  - Events, as they happen: feature_enabled / feature_disabled (found by
 *    diffing snapshots, so the host needs no hooks in its toggle code) and any
 *    the host sends with event().
 *  - A ping on deactivation, so an install going quiet can be told apart from
 *    one that was removed.
 *  - An erase request on opt-out.
 */
class Client
{
    const SDK_VERSION = '1.1.0';

    private $config;
    private $store;
    private $transport;
    private $events = [];
    private $usage  = []; // Uses tracked this request, saved once on shutdown.

    public function __construct( array $config )
    {
        $config['slug'] = sanitize_key( (string) ( $config['slug'] ?? '' ) );
        $this->config   = $config + [
            'name'     => $config['slug'],
            'key'      => '',
            'endpoint' => '',
            'version'  => '',
            'file'     => '',
            'features' => null,
            'plan'     => null,
            'site_id'  => null,
            'notice'   => true,
            'strings'  => [],
        ];
        $this->config['strings'] = (array) $this->config['strings'] + [
            'allow'      => 'Allow',
            'deny'       => 'No thanks',
            'disclosure' => 'Help us improve by sharing which features this site uses: the site address, feature on/off state, usage counts, plan, and WordPress/PHP versions. Nothing about your visitors or users, and you can stop at any time.',
        ];

        $this->store     = new Store( $config['slug'] );
        $this->transport = new Transport( (string) $this->config['endpoint'], (string) $this->config['key'] );
    }

    /**
     * Hook into WordPress. Called once by insights_sdk_boot().
     */
    public function register(): void
    {
        add_action( $this->cron_hook(), [ $this, 'send_snapshot' ] );
        add_action( 'shutdown', [ $this, 'save_usage' ] );
        add_action( 'shutdown', [ $this, 'flush_events' ] );

        if ( $this->config['file'] ) {
            add_action( 'deactivate_' . plugin_basename( $this->config['file'] ), [ $this, 'on_deactivate' ] );
        }

        if ( $this->config['notice'] && is_admin() ) {
            ( new Consent( $this ) )->register();
        }

        // Re-arm the schedule if it was lost (e.g. cron array reset).
        if ( $this->is_opted_in() && ! wp_next_scheduled( $this->cron_hook() ) ) {
            wp_schedule_event( time() + HOUR_IN_SECONDS, 'weekly', $this->cron_hook() );
        }
    }

    // ── Consent ──────────────────────────────────────────────────────────────

    public function slug(): string
    {
        return $this->config['slug'];
    }

    public function name(): string
    {
        return (string) $this->config['name'];
    }

    public function text( string $key ): string
    {
        return (string) ( $this->config['strings'][ $key ] ?? '' );
    }

    /** 'yes', 'no', or '' when the administrator hasn't chosen yet. */
    public function choice(): string
    {
        return (string) $this->store->get( 'optin', '' );
    }

    public function is_opted_in(): bool
    {
        return 'yes' === $this->choice();
    }

    public function opt_in(): void
    {
        if ( $this->is_opted_in() ) {
            return;
        }
        $this->store->set( 'optin', 'yes' );
        wp_clear_scheduled_hook( $this->cron_hook() );
        wp_schedule_event( time() + WEEK_IN_SECONDS, 'weekly', $this->cron_hook() );

        // First snapshot in a minute, so the install shows up without waiting
        // a week or holding up this request.
        wp_schedule_single_event( time() + MINUTE_IN_SECONDS, $this->cron_hook() );
    }

    /**
     * Stop reporting, ask the backend to erase this install, and delete local data.
     */
    public function opt_out(): void
    {
        if ( $this->is_opted_in() ) {
            $this->post( 'erase', [ 'site_id' => $this->call( 'site_id' ) ], false );
        }
        wp_clear_scheduled_hook( $this->cron_hook() );
        $this->events = [];
        $this->usage  = [];
        $this->store->clear();
        $this->store->set( 'optin', 'no' );
    }

    /**
     * Remove every option and cron event for this product. For the host's uninstall routine.
     */
    public function uninstall(): void
    {
        wp_clear_scheduled_hook( $this->cron_hook() );
        $this->store->clear();
    }

    // ── Recording ────────────────────────────────────────────────────────────

    /**
     * Count one use of a feature. $action is 'feature' or 'feature.detail'
     * (e.g. 'exports.run'); the part before the first dot must match a key the
     * 'features' callback returns for it to count towards that feature.
     * Counted in memory and saved once at the end of the request.
     */
    public function track( string $action ): void
    {
        if ( ! $this->is_opted_in() || ! $this->allowed() ) {
            return;
        }
        $action = substr( preg_replace( '/[^a-z0-9_.\-]/', '', strtolower( $action ) ), 0, 100 );
        if ( '' === $action ) {
            return;
        }

        $this->usage[ $action ] = [
            'uses'      => (int) ( $this->usage[ $action ]['uses'] ?? 0 ) + 1,
            'last_used' => time(),
        ];
    }

    /**
     * Add this request's tracked uses to the stored counters. Runs on shutdown.
     */
    public function save_usage(): void
    {
        $pending     = $this->usage;
        $this->usage = [];
        if ( ! $pending || ! $this->is_opted_in() ) {
            return;
        }

        $usage = (array) $this->store->get( 'usage', [] );
        foreach ( $pending as $action => $count ) {
            $usage[ $action ] = [
                'uses'      => (int) ( $usage[ $action ]['uses'] ?? 0 ) + $count['uses'],
                'last_used' => $count['last_used'],
            ];
        }
        $this->store->set( 'usage', $usage );
    }

    /**
     * Queue an event, sent once at the end of the request.
     *
     * @param string $name  e.g. 'upgrade_clicked'.
     * @param string $value Optional detail, e.g. a feature key.
     */
    public function event( string $name, string $value = '' ): void
    {
        if ( ! $this->is_opted_in() || ! $this->allowed() ) {
            return;
        }
        $this->events[] = [
            'name'  => sanitize_key( $name ),
            'value' => sanitize_text_field( $value ),
            'at'    => time(),
        ];
    }

    /**
     * Compare the host's current features with the last snapshot, record when
     * each changed, and queue feature_enabled / feature_disabled events. Hosts
     * can call this right after a toggle for exact timings; the weekly snapshot
     * calls it anyway.
     *
     * @return array [ feature => [ 'enabled' => bool, 'since' => unix ] ]
     */
    public function sync_features(): array
    {
        $current = $this->features();
        $state   = (array) $this->store->get( 'state', [] );
        $now     = time();
        $first   = empty( $state );

        foreach ( $current as $feature => $info ) {
            $enabled = ! empty( $info['enabled'] );
            if ( isset( $state[ $feature ] ) && $state[ $feature ]['enabled'] === $enabled ) {
                continue;
            }
            // On the first snapshot the real start time is unknown; null says so.
            $state[ $feature ] = [ 'enabled' => $enabled, 'since' => $first ? null : $now ];
            if ( ! $first ) {
                $this->event( $enabled ? 'feature_enabled' : 'feature_disabled', $feature );
            }
        }
        // Features the host no longer declares.
        $state = array_intersect_key( $state, $current );

        if ( $this->is_opted_in() ) {
            $this->store->set( 'state', $state );
        }
        return $state;
    }

    // ── Sending ──────────────────────────────────────────────────────────────

    /**
     * Send the snapshot. Usage counters are only reduced by what the backend
     * accepted, so a failed send is retried with everything next week.
     */
    public function send_snapshot(): bool
    {
        if ( ! $this->is_opted_in() || ! $this->allowed() ) {
            return false;
        }

        $this->save_usage();
        $state   = $this->sync_features();
        $usage   = (array) $this->store->get( 'usage', [] );
        $payload = $this->snapshot_payload( $state, $usage );

        if ( ! $this->post( 'ingest', $payload ) ) {
            return false;
        }
        $this->events = [];

        // Subtract what was sent, keeping anything tracked during the request.
        $now = (array) $this->store->get( 'usage', [] );
        foreach ( $usage as $action => $count ) {
            if ( isset( $now[ $action ] ) ) {
                $now[ $action ]['uses'] -= (int) $count['uses'];
                if ( $now[ $action ]['uses'] <= 0 ) {
                    unset( $now[ $action ] );
                }
            }
        }
        $this->store->set( 'usage', $now );
        return true;
    }

    /**
     * The snapshot the next send would contain, without sending it or
     * recording any feature changes. For debugging; null until opted in.
     */
    public function preview(): ?array
    {
        if ( ! $this->is_opted_in() ) {
            return null;
        }
        $this->save_usage();
        return $this->snapshot_payload(
            (array) $this->store->get( 'state', [] ),
            (array) $this->store->get( 'usage', [] )
        );
    }

    private function snapshot_payload( array $state, array $usage ): array
    {
        $features = [];

        foreach ( $this->features() as $feature => $info ) {
            $uses = 0;
            $last = null;
            foreach ( $usage as $action => $count ) {
                if ( $action === $feature || 0 === strpos( $action, $feature . '.' ) ) {
                    $uses += (int) $count['uses'];
                    $last  = max( (int) $last, (int) $count['last_used'] );
                }
            }
            $features[ $feature ] = [
                'tier'          => sanitize_key( (string) ( $info['tier'] ?? 'free' ) ),
                'enabled'       => ! empty( $info['enabled'] ),
                'enabled_since' => $state[ $feature ]['since'] ?? null,
                'uses'          => $uses,
                'last_used'     => $last,
            ];
        }

        $payload = $this->base_payload() + [
            'active'   => true,
            'features' => $features,
            'usage'    => $usage,
            'events'   => $this->events,
        ];

        /**
         * Filters the snapshot before it is sent. Dynamic part: the product slug.
         */
        return (array) apply_filters( $this->slug() . '_insights_payload', $payload );
    }

    /**
     * Send queued events. Runs on shutdown.
     */
    public function flush_events(): void
    {
        if ( empty( $this->events ) || ! $this->is_opted_in() ) {
            return;
        }
        $this->post( 'ingest', $this->base_payload() + [ 'events' => $this->events ], false );
        $this->events = [];
    }

    public function on_deactivate(): void
    {
        if ( $this->is_opted_in() && $this->allowed() ) {
            $this->post( 'ingest', $this->base_payload() + [ 'active' => false ], false );
        }
        wp_clear_scheduled_hook( $this->cron_hook() );
    }

    // ── Internals ────────────────────────────────────────────────────────────

    /**
     * Every request carries this install's secret, so only this install can
     * update or erase its record once the backend has seen it. Derived from
     * the site's salts rather than stored, so it survives opt-out, uninstall
     * and reinstall; it changes only if the salts are rotated.
     */
    private function post( string $path, array $payload, bool $blocking = true ): bool
    {
        $secret = hash_hmac( 'sha256', 'insights-sdk|' . $this->slug(), wp_salt( 'auth' ) );
        return $this->transport->post( $path, $payload, $blocking, [ 'X-Insights-Site-Secret' => $secret ] );
    }

    private function cron_hook(): string
    {
        return $this->slug() . '_insights_snapshot';
    }

    /**
     * Sending can be switched off per site with INSIGHTS_SDK_DISABLE or the
     * insights_sdk_should_send filter (e.g. on staging copies).
     */
    private function allowed(): bool
    {
        if ( defined( 'INSIGHTS_SDK_DISABLE' ) && INSIGHTS_SDK_DISABLE ) {
            return false;
        }
        return (bool) apply_filters( 'insights_sdk_should_send', true, $this->slug() );
    }

    /** The host's features: [ key => [ 'tier' => string, 'enabled' => bool ] ]. */
    private function features(): array
    {
        try {
            $features = is_callable( $this->config['features'] ) ? call_user_func( $this->config['features'] ) : [];
        } catch ( \Throwable $e ) {
            return [];
        }
        return is_array( $features ) ? $features : [];
    }

    /** A config value that may be given as a string or a callback returning one. */
    private function call( string $key ): ?string
    {
        $value = $this->config[ $key ];
        if ( is_callable( $value ) ) {
            try {
                $value = call_user_func( $value );
            } catch ( \Throwable $e ) {
                return null;
            }
        }
        if ( ! is_scalar( $value ) ) {
            return null;
        }
        return null === $value || '' === $value ? null : sanitize_text_field( (string) $value );
    }

    private function base_payload(): array
    {
        return [
            'sdk_version'     => self::SDK_VERSION,
            'site_id'         => $this->call( 'site_id' ),
            'plan'            => $this->call( 'plan' ),
            'product_version' => $this->call( 'version' ),
            'env'             => [
                'wp'        => get_bloginfo( 'version' ),
                'php'       => PHP_VERSION,
                'multisite' => is_multisite(),
                'locale'    => get_locale(),
            ],
            'sent_at'         => time(),
        ];
    }
}
