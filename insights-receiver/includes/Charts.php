<?php

namespace InsightsReceiver;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Charts rendered on the server as SVG/HTML: no JavaScript or third-party
 * library. Every mark has a hover tooltip (an SVG <title> or title
 * attribute), multi-series charts have a legend, and each chart has a table
 * view, so no value is carried by colour alone.
 *
 * Colours are the validated categorical order (blue, orange, aqua, yellow,
 * magenta) on the wp-admin white surface; "off" and "other" use neutral grey.
 */
class Charts
{
    const SERIES = [ '#2a78d6', '#eb6834', '#1baf7a', '#eda100', '#e87ba4' ];
    const NEUTRAL = '#c3c2b7';

    const W  = 720;
    const ML = 44;
    const MR = 12;
    const MT = 12;
    const MB = 26;

    /**
     * Line chart over days.
     *
     * @param array $days   'Y-m-d' labels.
     * @param array $series [ [ 'name' => string, 'values' => int[], 'color' => hex ], … ]
     */
    public static function line( array $days, array $series, int $height = 200 ): string
    {
        $n   = count( $days );
        $max = self::nice( max( array_merge( [ 1 ], array_map( static function ( $s ) {
            return $s['values'] ? max( $s['values'] ) : 0;
        }, $series ) ) ) );
        $pw  = self::W - self::ML - self::MR;
        $ph  = $height - self::MT - self::MB;
        $x   = static function ( $i ) use ( $n, $pw ) {
            return self::ML + ( $n > 1 ? $i * $pw / ( $n - 1 ) : $pw / 2 );
        };
        $y   = static function ( $v ) use ( $max, $ph ) {
            return self::MT + $ph - ( $v / $max ) * $ph;
        };

        $svg = self::open( $height ) . self::y_axis( $max, $height ) . self::x_labels( $days, $x, $height );

        foreach ( $series as $s ) {
            $pts = [];
            foreach ( $s['values'] as $i => $v ) {
                $pts[] = round( $x( $i ), 1 ) . ',' . round( $y( $v ), 1 );
            }
            $svg .= '<polyline fill="none" stroke="' . esc_attr( $s['color'] ) . '" stroke-width="2" stroke-linejoin="round" stroke-linecap="round" points="' . esc_attr( implode( ' ', $pts ) ) . '"/>';
        }

        // Hover columns: one per day, wider than the line, listing every series.
        $col = $n > 1 ? $pw / ( $n - 1 ) : $pw;
        foreach ( $days as $i => $day ) {
            $tip = self::day_label( $day );
            foreach ( $series as $s ) {
                $tip .= "\n" . $s['name'] . ': ' . number_format_i18n( $s['values'][ $i ] ?? 0 );
            }
            $svg .= '<g class="insr-hover"><rect x="' . round( $x( $i ) - $col / 2, 1 ) . '" y="' . self::MT . '" width="' . round( $col, 1 ) . '" height="' . $ph . '" fill="transparent"/>'
                . '<line x1="' . round( $x( $i ), 1 ) . '" x2="' . round( $x( $i ), 1 ) . '" y1="' . self::MT . '" y2="' . ( self::MT + $ph ) . '" class="insr-crosshair"/>';
            foreach ( $series as $s ) {
                $svg .= '<circle cx="' . round( $x( $i ), 1 ) . '" cy="' . round( $y( $s['values'][ $i ] ?? 0 ), 1 ) . '" r="4" fill="' . esc_attr( $s['color'] ) . '" stroke="#fff" stroke-width="2" class="insr-dot"/>';
            }
            $svg .= '<title>' . esc_html( $tip ) . '</title></g>';
        }

        return self::legend( $series ) . $svg . '</svg>' . self::table( $days, $series );
    }

    /**
     * Vertical bars over days (one series).
     */
    public static function columns( array $days, array $values, string $name, int $height = 200 ): string
    {
        $n   = max( 1, count( $days ) );
        $max = self::nice( max( 1, $values ? max( $values ) : 0 ) );
        $pw  = self::W - self::ML - self::MR;
        $ph  = $height - self::MT - self::MB;
        $bw  = $pw / $n;
        $x   = static function ( $i ) use ( $bw ) {
            return self::ML + $i * $bw + $bw / 2;
        };

        $svg = self::open( $height ) . self::y_axis( $max, $height ) . self::x_labels( $days, $x, $height );
        foreach ( $days as $i => $day ) {
            $v  = (int) ( $values[ $i ] ?? 0 );
            $h  = $v / $max * $ph;
            $w  = max( 1, $bw - 2 ); // 2px gap between bars.
            $bx = self::ML + $i * $bw + 1;
            $svg .= '<g class="insr-hover"><rect x="' . round( $bx, 1 ) . '" y="' . self::MT . '" width="' . round( $w, 1 ) . '" height="' . $ph . '" fill="transparent"/>';
            if ( $v ) {
                $svg .= '<rect x="' . round( $bx, 1 ) . '" y="' . round( self::MT + $ph - $h, 1 ) . '" width="' . round( $w, 1 ) . '" height="' . round( max( 1, $h ), 1 ) . '" rx="' . ( $w >= 8 ? 2 : 0 ) . '" fill="' . self::SERIES[0] . '"/>';
            }
            $svg .= '<title>' . esc_html( self::day_label( $day ) . "\n" . $name . ': ' . number_format_i18n( $v ) ) . '</title></g>';
        }

        return $svg . '</svg>' . self::table( $days, [ [ 'name' => $name, 'values' => $values ] ] );
    }

    /**
     * Horizontal bars, one row per label: "on" (blue) and "off" (grey)
     * stacked. Pass off = null for single-value bars.
     *
     * @param array $rows [ [ 'label' => string, 'on' => int, 'off' => ?int, 'url' => ?string, 'note' => ?string ], … ]
     */
    public static function bars( array $rows, string $on_label = 'Enabled', string $off_label = 'Disabled' ): string
    {
        if ( ! $rows ) {
            return self::empty_state();
        }
        $stacked = array_key_exists( 'off', $rows[0] ) && null !== $rows[0]['off'];
        $max     = max( array_merge( [ 1 ], array_map( static function ( $r ) use ( $stacked ) {
            return $r['on'] + ( $stacked ? (int) $r['off'] : 0 );
        }, $rows ) ) );

        $html = '';
        if ( $stacked ) {
            $html .= self::legend( [ [ 'name' => $on_label, 'color' => self::SERIES[0] ], [ 'name' => $off_label, 'color' => self::NEUTRAL ] ] );
        }
        $html .= '<div class="insr-bars">';
        foreach ( $rows as $r ) {
            $on    = (int) $r['on'];
            $off   = $stacked ? (int) $r['off'] : 0;
            $tip   = $stacked ? sprintf( '%s: %s %s, %s %s', $r['label'], number_format_i18n( $on ), strtolower( $on_label ), number_format_i18n( $off ), strtolower( $off_label ) ) : $r['label'] . ': ' . number_format_i18n( $on );
            $label = esc_html( $r['label'] );
            if ( ! empty( $r['url'] ) ) {
                $label = '<a href="' . esc_url( $r['url'] ) . '">' . $label . '</a>';
            }
            $html .= '<div class="insr-bar-row" title="' . esc_attr( $tip ) . '">'
                . '<div class="insr-bar-label">' . $label . ( ! empty( $r['note'] ) ? ' <span class="insr-chip">' . esc_html( $r['note'] ) . '</span>' : '' ) . '</div>'
                . '<div class="insr-bar-track">'
                . ( $on ? '<span class="insr-bar-on" style="width:' . round( 100 * $on / $max, 2 ) . '%"></span>' : '' )
                . ( $off ? '<span class="insr-bar-off" style="width:' . round( 100 * $off / $max, 2 ) . '%"></span>' : '' )
                . '</div>'
                . '<div class="insr-bar-value">' . number_format_i18n( $on ) . ( $stacked ? ' <span class="insr-muted">/ ' . number_format_i18n( $on + $off ) . '</span>' : '' ) . '</div>'
                . '</div>';
        }
        return $html . '</div>';
    }

    /**
     * Donut of shares. Slices past the fifth fold into "Other".
     *
     * @param array $slices [ [ 'label' => string, 'value' => int ], … ] largest first.
     */
    public static function donut( array $slices ): string
    {
        $slices = array_values( array_filter( $slices, static function ( $s ) {
            return $s['value'] > 0;
        } ) );
        if ( ! $slices ) {
            return self::empty_state();
        }
        if ( count( $slices ) > 5 ) {
            $rest   = array_slice( $slices, 4 );
            $slices = array_slice( $slices, 0, 4 );
            $slices[] = [ 'label' => 'Other', 'value' => array_sum( array_column( $rest, 'value' ) ), 'other' => true ];
        }
        $total = array_sum( array_column( $slices, 'value' ) );
        $r     = 60;
        $c     = 2 * M_PI * $r;
        $off   = 0;

        $svg = '<div class="insr-donut"><svg viewBox="0 0 160 160" width="160" height="160" role="img" aria-label="Share by category">';
        foreach ( $slices as $i => $s ) {
            $len   = $c * $s['value'] / $total;
            $color = empty( $s['other'] ) ? self::SERIES[ $i ] : self::NEUTRAL;
            // A 2px gap between slices, unless it's the only one.
            $dash  = count( $slices ) > 1 ? max( 0.5, $len - 2 ) : $len;
            $svg  .= '<circle cx="80" cy="80" r="' . $r . '" fill="none" stroke="' . $color . '" stroke-width="22" stroke-dasharray="' . round( $dash, 2 ) . ' ' . round( $c - $dash, 2 ) . '" stroke-dashoffset="' . round( -$off, 2 ) . '" transform="rotate(-90 80 80)" class="insr-slice">'
                . '<title>' . esc_html( sprintf( '%s: %s (%s%%)', $s['label'], number_format_i18n( $s['value'] ), round( 100 * $s['value'] / $total ) ) ) . '</title></circle>';
            $off  += $len;
        }
        $svg .= '<text x="80" y="78" text-anchor="middle" class="insr-donut-total">' . esc_html( number_format_i18n( $total ) ) . '</text><text x="80" y="96" text-anchor="middle" class="insr-axis">sites</text></svg>';

        $svg .= '<ul class="insr-donut-legend">';
        foreach ( $slices as $i => $s ) {
            $color = empty( $s['other'] ) ? self::SERIES[ $i ] : self::NEUTRAL;
            $svg  .= '<li><span class="insr-swatch" style="background:' . $color . '"></span>' . esc_html( $s['label'] )
                . ' <strong>' . esc_html( number_format_i18n( $s['value'] ) ) . '</strong> <span class="insr-muted">' . round( 100 * $s['value'] / $total ) . '%</span></li>';
        }
        return $svg . '</ul></div>';
    }

    // ── Pieces ───────────────────────────────────────────────────────────────

    private static function open( int $height ): string
    {
        return '<svg class="insr-chart" viewBox="0 0 ' . self::W . ' ' . $height . '" role="img">';
    }

    private static function y_axis( int $max, int $height ): string
    {
        $ph  = $height - self::MT - self::MB;
        $out = '';
        // Midline only when it lands on a whole number.
        foreach ( $max >= 2 ? [ 0, 0.5, 1 ] : [ 0, 1 ] as $f ) {
            $yy   = round( self::MT + $ph - $f * $ph, 1 );
            $out .= '<line x1="' . self::ML . '" x2="' . ( self::W - self::MR ) . '" y1="' . $yy . '" y2="' . $yy . '" class="' . ( $f ? 'insr-grid' : 'insr-baseline' ) . '"/>'
                . '<text x="' . ( self::ML - 6 ) . '" y="' . ( $yy + 4 ) . '" text-anchor="end" class="insr-axis">' . esc_html( self::short( $max * $f ) ) . '</text>';
        }
        return $out;
    }

    private static function x_labels( array $days, callable $x, int $height ): string
    {
        $n = count( $days );
        if ( ! $n ) {
            return '';
        }
        $out = '';
        foreach ( array_unique( [ 0, intdiv( $n - 1, 2 ), $n - 1 ] ) as $i ) {
            $anchor = 0 === $i ? 'start' : ( $n - 1 === $i ? 'end' : 'middle' );
            $out   .= '<text x="' . round( $x( $i ), 1 ) . '" y="' . ( $height - 6 ) . '" text-anchor="' . $anchor . '" class="insr-axis">' . esc_html( self::day_label( $days[ $i ] ) ) . '</text>';
        }
        return $out;
    }

    private static function legend( array $series ): string
    {
        if ( count( $series ) < 2 ) {
            return '';
        }
        $out = '<div class="insr-legend">';
        foreach ( $series as $s ) {
            $out .= '<span><span class="insr-swatch" style="background:' . esc_attr( $s['color'] ) . '"></span>' . esc_html( $s['name'] ) . '</span>';
        }
        return $out . '</div>';
    }

    private static function table( array $days, array $series ): string
    {
        $out = '<details class="insr-table-view"><summary>Table</summary><table class="widefat striped"><thead><tr><th>Day</th>';
        foreach ( $series as $s ) {
            $out .= '<th>' . esc_html( $s['name'] ) . '</th>';
        }
        $out .= '</tr></thead><tbody>';
        foreach ( array_reverse( $days, true ) as $i => $day ) {
            $out .= '<tr><td>' . esc_html( $day ) . '</td>';
            foreach ( $series as $s ) {
                $out .= '<td>' . esc_html( number_format_i18n( $s['values'][ $i ] ?? 0 ) ) . '</td>';
            }
            $out .= '</tr>';
        }
        return $out . '</tbody></table></details>';
    }

    public static function empty_state(): string
    {
        return '<p class="insr-empty">No data yet for these filters.</p>';
    }

    private static function day_label( string $day ): string
    {
        return wp_date( 'j M', strtotime( $day . ' 12:00:00 UTC' ) );
    }

    /** Round up to 1, 2 or 5 × 10^n. */
    private static function nice( int $v ): int
    {
        $p = 10 ** max( 0, (int) floor( log10( max( 1, $v ) ) ) );
        foreach ( [ 1, 2, 5, 10 ] as $m ) {
            if ( $v <= $m * $p ) {
                return $m * $p;
            }
        }
        return 10 * $p;
    }

    private static function short( $v ): string
    {
        $v = (float) $v;
        if ( $v >= 1000000 ) {
            return round( $v / 1000000, 1 ) . 'M';
        }
        if ( $v >= 1000 ) {
            return round( $v / 1000, 1 ) . 'k';
        }
        return (string) round( $v );
    }
}
