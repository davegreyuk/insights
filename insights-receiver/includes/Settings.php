<?php

namespace InsightsReceiver;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Settings
{
    const OPTION = 'insr_settings';

    const DEFAULTS = [
        'retention_days'      => 365, // Events, usage and activity older than this are deleted; so are sites not seen for this long.
        'quiet_days'          => 14,  // A site still marked active but silent for longer than this is "quiet".
        'rate_limit'          => 300, // Requests per hour per IP address and product key.
        'delete_on_uninstall' => 0,
    ];

    public static function all(): array
    {
        return array_merge( self::DEFAULTS, (array) get_option( self::OPTION, [] ) );
    }

    public static function get( string $key )
    {
        return self::all()[ $key ] ?? null;
    }

    public static function sanitize( $input ): array
    {
        $input = (array) $input;
        return [
            'retention_days'      => max( 30, min( 3650, absint( $input['retention_days'] ?? 365 ) ) ),
            'quiet_days'          => max( 8, min( 365, absint( $input['quiet_days'] ?? 14 ) ) ),
            'rate_limit'          => max( 10, min( 100000, absint( $input['rate_limit'] ?? 300 ) ) ),
            'delete_on_uninstall' => empty( $input['delete_on_uninstall'] ) ? 0 : 1,
        ];
    }
}
