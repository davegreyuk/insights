<?php

namespace InsightsSDK;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * The built-in consent notice: shown to administrators on the Dashboard and
 * Plugins screens until they choose. Hosts with their own opt-in UI pass
 * 'notice' => false and call Client::opt_in() / opt_out() themselves.
 */
class Consent
{
    private $client;
    private $action;

    public function __construct( Client $client )
    {
        $this->client = $client;
        $this->action = 'insights_sdk_' . $client->slug();
    }

    public function register(): void
    {
        add_action( 'admin_notices', [ $this, 'render' ] );
        add_action( 'admin_post_' . $this->action, [ $this, 'handle' ] );
    }

    public function render(): void
    {
        if ( '' !== $this->client->choice() || ! current_user_can( 'manage_options' ) ) {
            return;
        }
        $screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
        if ( ! $screen || ! in_array( $screen->id, [ 'dashboard', 'plugins' ], true ) ) {
            return;
        }

        $url = function ( string $choice ): string {
            return wp_nonce_url(
                admin_url( 'admin-post.php?action=' . $this->action . '&choice=' . $choice ),
                $this->action
            );
        };

        printf(
            '<div class="notice notice-info"><p><strong>%1$s</strong> %2$s</p><p><a class="button button-primary" href="%3$s">%4$s</a> <a class="button" href="%5$s">%6$s</a></p></div>',
            esc_html( $this->client->name() ),
            esc_html( $this->client->text( 'disclosure' ) ),
            esc_url( $url( 'yes' ) ),
            esc_html( $this->client->text( 'allow' ) ),
            esc_url( $url( 'no' ) ),
            esc_html( $this->client->text( 'deny' ) )
        );
    }

    public function handle(): void
    {
        check_admin_referer( $this->action );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( '', '', 403 );
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Verified by check_admin_referer() above.
        if ( 'yes' === sanitize_key( wp_unslash( $_GET['choice'] ?? '' ) ) ) {
            $this->client->opt_in();
        } else {
            $this->client->opt_out();
        }

        wp_safe_redirect( wp_get_referer() ?: admin_url() );
        exit;
    }
}
