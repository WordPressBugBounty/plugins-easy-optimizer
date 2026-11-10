<?php
/**
 * Requests this site makes to itself (2.8.0): queue runner dispatches and
 * preload warms.
 *
 * They used to go wherever DNS pointed. Behind a CDN (Hostinger's hcdn,
 * Cloudflare, …) that is the CDN edge: each request left the server and came
 * back through the edge, its bot protection and its rate limits. The
 * fire-and-forget ones hang up after a second (WordPress' cURL minimum), and
 * WordPress reports no error for them, so a dispatch or warm the edge
 * challenged or dropped simply never happened, and nothing said so.
 *
 * Once a probe proves that this server's own address answers as this site,
 * requests to our own host are pinned to it (CURLOPT_RESOLVE): no DNS, no CDN,
 * no edge firewall. The probe repeats twice a day, and a pinned request that
 * cannot connect drops the pin and goes the normal way.
 *
 * @package EasyOptimizer
 * @since   2.8.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class EasyOpt_Loopback {

    /** array{ip:string,host:string,port:int,at:int} — ip '' means "use DNS". */
    const OPTION = 'easyopt_loopback_route';

    /** Seconds before the route is probed again. */
    const RECHECK = 43200;

    /** Per-address probe timeout, in seconds (filter easyopt_loopback_probe_timeout). */
    const PROBE_TIMEOUT = 5;

    /** CURLOPT_RESOLVE entry for the request in flight, '' when none. */
    private static $pin = '';

    public static function get( $url, array $args = array() ) {
        $args['method'] = 'GET';
        return self::request( $url, $args );
    }

    public static function post( $url, array $args = array() ) {
        $args['method'] = 'POST';
        return self::request( $url, $args );
    }

    /**
     * wp_remote_request() to this site, pinned to its own address when proven.
     *
     * @param string $url
     * @param array  $args wp_remote_request() arguments.
     * @return array|WP_Error
     */
    public static function request( $url, array $args ) {
        $blocking = ! isset( $args['blocking'] ) || false !== $args['blocking'];
        $pin = self::pin_for( $url );
        if ( '' === $pin ) {
            return wp_remote_request( $url, $args );
        }

        $response = self::pinned( $url, $args, $pin );

        // The address stopped answering (server moved, listener changed). Go
        // back to DNS until a probe proves it again, and send this one that way.
        if ( $blocking && is_wp_error( $response ) && self::is_connect_error( $response->get_error_message() ) ) {
            self::save( '', '', 0 );
            $response = wp_remote_request( $url, $args );
        }
        return $response;
    }

    /**
     * Pin a raw curl handle (the preload's curl_multi batch).
     *
     * @param resource|\CurlHandle $handle
     * @param string               $url
     */
    public static function pin_handle( $handle, $url ) {
        $pin = self::pin_for( $url );
        if ( '' !== $pin && defined( 'CURLOPT_RESOLVE' ) ) {
            curl_setopt( $handle, CURLOPT_RESOLVE, array( $pin ) );
        }
    }

    /** http_api_curl callback: applies the pin of the request in flight. */
    public static function apply_pin( $handle ) {
        if ( '' !== self::$pin && defined( 'CURLOPT_RESOLVE' ) ) {
            curl_setopt( $handle, CURLOPT_RESOLVE, array( self::$pin ) );
        }
    }

    /**
     * CURLOPT_RESOLVE entry for a URL, or '' when it is not our proven host.
     *
     * @param string $url
     * @return string
     */
    public static function pin_for( $url ) {
        $route = self::route();
        if ( '' === $route['ip'] ) {
            return '';
        }
        $target = self::host_port( $url );
        if ( null === $target || $target[0] !== $route['host'] || $target[1] !== $route['port'] ) {
            return '';
        }
        return self::entry( $target[0], $target[1], $route['ip'] );
    }

    /** "host:port:address", IPv6 in brackets as curl expects. */
    public static function entry( $host, $port, $ip ) {
        $ip = trim( (string) $ip, '[]' );
        if ( false !== strpos( $ip, ':' ) ) {
            $ip = '[' . $ip . ']';
        }
        return $host . ':' . (int) $port . ':' . $ip;
    }

    /** Probe again when the last result is older than RECHECK. */
    public static function refresh() {
        $route = self::route();
        if ( $route['at'] > 0 && ( time() - $route['at'] ) < self::RECHECK ) {
            return;
        }
        self::probe();
    }

    /**
     * Find an address of this server that answers as this site.
     *
     * The test request is the queue runner with a wrong token: a 403 carrying
     * our own error code can only come from this plugin, behind this host name.
     *
     * @return string The address now used, or '' (requests go through DNS).
     */
    public static function probe() {
        $url    = rest_url( 'easyopt/v1/queue/run' );
        $target = self::host_port( $url );
        if ( null === $target ) {
            return '';
        }

        $found = '';
        foreach ( self::candidates() as $ip ) {
            if ( self::answers_as_us( $url, self::entry( $target[0], $target[1], $ip ) ) ) {
                $found = $ip;
                break;
            }
        }

        $before = self::route();
        self::save( $found, $target[0], $target[1] );

        if ( $found !== $before['ip'] && class_exists( 'EasyOpt_Debug_Log' ) ) {
            EasyOpt_Debug_Log::info( 'preload', '' !== $found
                ? sprintf( 'Requests to this site now go straight to %s, skipping DNS and any CDN in front of it.', $found )
                : 'Requests to this site go through DNS: no address of this server answered as this site.' );
        }
        return $found;
    }

    /* ─────────────────────────── internals ─────────────────────────── */

    private static function pinned( $url, array $args, $pin ) {
        self::$pin = $pin;
        add_action( 'http_api_curl', array( __CLASS__, 'apply_pin' ), 10, 1 );
        try {
            $response = wp_remote_request( $url, $args );
        } finally {
            remove_action( 'http_api_curl', array( __CLASS__, 'apply_pin' ), 10 );
            self::$pin = '';
        }
        return $response;
    }

    private static function answers_as_us( $url, $pin ) {
        $response = self::pinned( $url, array(
            'method'      => 'POST',
            'timeout'     => max( 1, (int) apply_filters( 'easyopt_loopback_probe_timeout', self::PROBE_TIMEOUT ) ),
            'blocking'    => true,
            'sslverify'   => false,
            'redirection' => 0,
            'body'        => array( 'group' => 'probe', 'callback' => 'probe', 'token' => 'probe' ),
        ), $pin );
        if ( is_wp_error( $response ) || 403 !== (int) wp_remote_retrieve_response_code( $response ) ) {
            return false;
        }
        $body = json_decode( (string) wp_remote_retrieve_body( $response ), true );
        return is_array( $body ) && isset( $body['code'] ) && 'easyopt-queue/invalid-runner' === $body['code'];
    }

    /** The address the current request came in on, then the loopback. */
    private static function candidates() {
        $ips = array();
        if ( ! empty( $_SERVER['SERVER_ADDR'] ) ) {
            $ips[] = trim( (string) wp_unslash( $_SERVER['SERVER_ADDR'] ), '[]' );
        }
        $ips[] = '127.0.0.1';
        /**
         * Addresses tried, in order, when looking for a direct route to this site.
         *
         * @param string[] $ips
         */
        $ips = (array) apply_filters( 'easyopt_loopback_ips', $ips );
        $out = array();
        foreach ( $ips as $ip ) {
            $ip = trim( (string) $ip, '[] ' );
            if ( false !== filter_var( $ip, FILTER_VALIDATE_IP ) && ! in_array( $ip, $out, true ) ) {
                $out[] = $ip;
            }
        }
        return $out;
    }

    /** array( host, port ) of a URL, or null. */
    private static function host_port( $url ) {
        $parts = wp_parse_url( (string) $url );
        if ( ! is_array( $parts ) || empty( $parts['host'] ) ) {
            return null;
        }
        $scheme = isset( $parts['scheme'] ) ? strtolower( $parts['scheme'] ) : 'http';
        $port   = isset( $parts['port'] ) ? (int) $parts['port'] : ( 'https' === $scheme ? 443 : 80 );
        return array( strtolower( $parts['host'] ), $port );
    }

    private static function is_connect_error( $message ) {
        return 1 === preg_match( '/cURL error (?:7|35):|cURL error 28: (?:Connection|Resolving) timed out/i', (string) $message );
    }

    /** @return array{ip:string,host:string,port:int,at:int} */
    private static function route() {
        $r = get_option( self::OPTION, array() );
        $r = is_array( $r ) ? $r : array();
        return array(
            'ip'   => isset( $r['ip'] ) ? (string) $r['ip'] : '',
            'host' => isset( $r['host'] ) ? (string) $r['host'] : '',
            'port' => isset( $r['port'] ) ? (int) $r['port'] : 0,
            'at'   => isset( $r['at'] ) ? (int) $r['at'] : 0,
        );
    }

    private static function save( $ip, $host, $port ) {
        update_option( self::OPTION, array(
            'ip'   => (string) $ip,
            'host' => (string) $host,
            'port' => (int) $port,
            'at'   => time(),
        ), false );
    }
}
