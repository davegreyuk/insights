<?php

namespace InsightsReceiver;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Plugin
{
    public static function init(): void
    {
        Schema::maybe_upgrade();

        Ingest::register();
        Api::register();
        Abilities::register();
        Retention::register();

        if ( is_admin() ) {
            Admin::register();
        }
    }

    /**
     * Capability needed to see the data (admin screens, query API, abilities).
     * Filter it to give e.g. a custom role read access.
     */
    public static function capability(): string
    {
        return (string) apply_filters( 'insights_receiver_capability', 'manage_options' );
    }

    public static function can_view(): bool
    {
        return current_user_can( self::capability() );
    }
}
