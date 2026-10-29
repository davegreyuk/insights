<?php

namespace InsightsReceiver;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * The query API as WordPress abilities (core since 6.9), so agents can use
 * it: through /wp-abilities/v1/*, and as MCP tools via the WordPress MCP
 * adapter. All read-only and gated by the same capability as the screens.
 */
class Abilities
{
    const CATEGORY = 'insights';

    public static function register(): void
    {
        if ( ! function_exists( 'wp_register_ability' ) ) {
            return;
        }
        add_action( 'wp_abilities_api_categories_init', [ self::class, 'category' ] );
        add_action( 'wp_abilities_api_init', [ self::class, 'abilities' ] );
    }

    public static function category(): void
    {
        wp_register_ability_category( self::CATEGORY, [
            'label'       => 'Insights',
            'description' => 'Feature usage reported by your plugins and themes through the Insights SDK.',
        ] );
    }

    /** JSON schema for the shared filters. */
    private static function filter_props(): array
    {
        return [
            'product'  => [ 'type' => 'integer', 'description' => 'Product ID (see insights/list-products). Defaults to the first product.' ],
            'days'     => [ 'type' => 'integer', 'minimum' => 1, 'maximum' => 730, 'description' => 'Reporting window in days. Default 30.' ],
            'status'   => [ 'type' => 'string', 'enum' => Query::STATUSES, 'description' => 'live (default): active and recently seen; quiet: active but silent; deactivated; all.' ],
            'plan'     => [ 'type' => 'string', 'description' => '"paid", "free", "trial" or an exact plan name. Empty for any.' ],
            'search'   => [ 'type' => 'string', 'description' => 'Part of the site ID.' ],
            'feature'  => [ 'type' => 'string', 'description' => 'Only sites that have this feature key.' ],
            'state'    => [ 'type' => 'string', 'enum' => [ 'enabled', 'disabled' ], 'description' => 'With feature: only sites where it is on / off.' ],
            'page'     => [ 'type' => 'integer', 'minimum' => 1 ],
            'per_page' => [ 'type' => 'integer', 'minimum' => 1, 'maximum' => 200 ],
        ];
    }

    private static function add( string $name, string $label, string $description, array $props, array $required, callable $run ): void
    {
        $args = [
            'label'               => $label,
            'description'         => $description,
            'category'            => self::CATEGORY,
            'output_schema'       => [ 'type' => [ 'object', 'array' ] ],
            'execute_callback'    => static function ( $input = [] ) use ( $run ) {
                return $run( (array) $input );
            },
            'permission_callback' => [ Plugin::class, 'can_view' ],
            'meta'                => [
                'annotations'  => [ 'readonly' => true, 'destructive' => false, 'idempotent' => true ],
                'show_in_rest' => true,
                'mcp'          => [ 'public' => true ],
            ],
        ];
        // No schema for argument-free abilities; otherwise default to {} so a call with no input is valid.
        if ( $props ) {
            $args['input_schema'] = [
                'type'                 => 'object',
                'properties'           => $props,
                'required'             => $required,
                'additionalProperties' => false,
                'default'              => [],
            ];
        }
        wp_register_ability( self::CATEGORY . '/' . $name, $args );
    }

    public static function abilities(): void
    {
        $filters = self::filter_props();

        self::add( 'list-products', 'List products', 'Products (plugins/themes) reporting usage, with their IDs.', [], [], static function () {
            return array_map( [ Api::class, 'product' ], Products::all() );
        } );

        self::add( 'get-overview', 'Get overview', 'Headline numbers: sites live/quiet/deactivated/new, feature switches currently on and off, uses and events in the window.', $filters, [], static function ( $in ) {
            return Query::overview( Query::filters( $in ) );
        } );

        self::add( 'get-feature-stats', 'Get feature stats', 'Per feature: sites with it on and off now, ever enabled, retention %, enabled but unused for 30 days, uses, enables/disables in the window, median days to disable.', $filters, [], static function ( $in ) {
            return Query::feature_stats( Query::filters( $in ) );
        } );

        self::add( 'list-feature-sites', 'List sites for a feature', 'Sites that have a feature, with whether it is on, since when, total uses and last use. Use state to list only sites where it is on or off.', $filters, [ 'feature' ], static function ( $in ) {
            return Query::feature_sites( Query::filters( $in ), (string) $in['feature'] );
        } );

        self::add( 'list-sites', 'List sites', 'Sites with status, plan, versions, features on/total and uses in the window. Filter by status, plan, search or feature state; order by last_seen, first_seen, site_id, plan, features_on or uses.', $filters + [
            'orderby' => [ 'type' => 'string', 'enum' => [ 'last_seen', 'first_seen', 'site_id', 'plan', 'features_on', 'uses' ] ],
            'order'   => [ 'type' => 'string', 'enum' => [ 'asc', 'desc' ] ],
        ], [], static function ( $in ) {
            return Query::sites( Query::filters( $in ) );
        } );

        self::add( 'get-site', 'Get site', 'One site in full: details, every feature, usage by day and action, and a timeline of what it sent. Identify it by id, or by product + site_id.', [
            'id'      => [ 'type' => 'integer', 'description' => 'Internal site ID from list-sites.' ],
            'product' => [ 'type' => 'integer' ],
            'site_id' => [ 'type' => 'string', 'description' => 'The site ID the product sends, e.g. its URL.' ],
            'days'    => [ 'type' => 'integer', 'minimum' => 1, 'maximum' => 730 ],
        ], [], static function ( $in ) {
            $f  = Query::filters( $in );
            $id = absint( $in['id'] ?? 0 ) ?: Data::find_site( $f['product'], Data::text( $in['site_id'] ?? '', 191 ) );
            $site = $id ? Query::site( $id, $f['days'] ) : null;
            return $site ?: new \WP_Error( 'insr_no_site', 'Site not found.' );
        } );

        self::add( 'list-events', 'List events', 'Events newest first (feature_enabled, feature_disabled and custom events). Filter by site (internal ID), name, or feature (the event value).', $filters + [
            'site' => [ 'type' => 'integer' ],
            'name' => [ 'type' => 'string' ],
        ], [], static function ( $in ) {
            return Query::events( Query::filters( $in ), absint( $in['site'] ?? 0 ), (string) ( $in['name'] ?? '' ) );
        } );

        self::add( 'get-timeseries', 'Get daily activity', 'Per day in the window: sites reporting, uses, feature enables and disables, new sites.', $filters, [], static function ( $in ) {
            return Query::timeseries( Query::filters( $in ) );
        } );

        self::add( 'get-breakdown', 'Get breakdown', 'Site counts by plan, product_version, wp_version, php_version, locale or sdk_version.', $filters + [
            'dimension' => [ 'type' => 'string', 'enum' => array_keys( Query::BREAKDOWNS ) ],
        ], [ 'dimension' ], static function ( $in ) {
            return Query::breakdown( Query::filters( $in ), (string) $in['dimension'] );
        } );

        self::add( 'get-opportunities', 'Get upsell opportunities', 'Sites on a paid plan with less than the threshold share of their paid features switched on, fewest first, with the paid features they have off.', $filters + [
            'threshold' => [ 'type' => 'number', 'minimum' => 0, 'maximum' => 1, 'description' => 'Default 0.5.' ],
        ], [], static function ( $in ) {
            return Query::opportunities( Query::filters( $in ), isset( $in['threshold'] ) ? (float) $in['threshold'] : 0.5 );
        } );
    }
}
