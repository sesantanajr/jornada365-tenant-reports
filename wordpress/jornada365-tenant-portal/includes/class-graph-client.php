<?php
namespace J365TP;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Microsoft Graph client for tenant and delegated reports.
 * - Automatic paging via @odata.nextLink
 * - Retry with Retry-After on 429/503/504, exponential backoff otherwise
 * - JSON $batch helper (20 requests per call)
 * - Time budget awareness for chunked jobs
 */
final class Graph_Client {

	private string $tenant_id;
	private int $max_retries = 5;
	/** Human-readable notes about automatic adaptations (e.g. a property unavailable in v1.0). */
	private array $notes = [];
	/** End of the current job step (microtime). Waits that would run past it are handed back to the job. */
	private float $deadline = 0.0;

	/** Delegated access token (actions run as the signed-in admin); null = app-only token. */
	private ?string $delegated;

	public function __construct( string $tenant_id, ?string $delegated_token = null ) {
		$this->tenant_id = strtolower( $tenant_id );
		$this->delegated = $delegated_token;
	}

	public function tenant_id(): string {
		return $this->tenant_id;
	}

	/**
	 * Bounds retries/backoff to the job step: when Graph throttles (429/503/504) and the wait would
	 * overrun the step, a "throttled" Graph_Exception is thrown instead of sleeping, so the job keeps
	 * its progress and resumes on the next poll (no request runs into proxy/CDN timeouts).
	 */
	public function set_deadline( float $deadline ): void {
		$this->deadline = $deadline;
	}

	private function can_wait( int $seconds ): bool {
		return $this->deadline <= 0 || microtime( true ) + $seconds <= $this->deadline + 20;
	}

	private function http_timeout( bool $read = true, int $default = 90 ): int {
		if ( $this->deadline <= 0 ) {
			return $default;
		}
		if ( ! $read ) {
			return (int) max( 20, min( $default, ceil( $this->deadline + 40 - microtime( true ) ) ) );
		}
		$seconds = (int) floor( $this->deadline - microtime( true ) );
		if ( $seconds < 1 ) {
			throw new Graph_Throttled( 'A leitura continua no próximo passo do relatório.', 1, 0, 'budget' );
		}
		return min( $default, $seconds );
	}

	private static function retry_delay( string $header, int $fallback ): int {
		$header = trim( $header );
		if ( ctype_digit( $header ) ) {
			return max( 1, (int) $header );
		}
		$timestamp = '' !== $header ? strtotime( $header ) : false;
		return false === $timestamp ? $fallback : max( 1, $timestamp - time() );
	}

	private static function retry_interval( array $headers ): int {
		$headers = array_change_key_case( $headers, CASE_LOWER );
		return self::retry_delay( (string) ( $headers['retry-after'] ?? '' ), 2 );
	}

	public function take_notes(): array {
		$n           = array_values( array_unique( $this->notes ) );
		$this->notes = [];
		return $n;
	}

	/** Batch sub-request URLs are relative to the API version. */
	private static function versioned( string $url ): string {
		return ( str_starts_with( $url, '/v1.0/' ) || str_starts_with( $url, '/beta/' ) || str_starts_with( $url, 'https://' ) ) ? $url : '/v1.0/' . ltrim( $url, '/' );
	}

	private function url( string $path_or_url ): string {
		if ( str_starts_with( $path_or_url, 'https://' ) ) {
			$parts = wp_parse_url( $path_or_url );
			$graph = wp_parse_url( Token_Service::GRAPH, PHP_URL_HOST );
			if ( strtolower( $parts['host'] ?? '' ) !== strtolower( $graph )
				|| 443 !== ( $parts['port'] ?? 443 )
				|| isset( $parts['user'] ) || isset( $parts['pass'] ) || isset( $parts['fragment'] ) ) {
				throw new Graph_Exception( 'A origem da próxima página do Microsoft Graph é inválida.', 502, 'bad_nextlink' );
			}
			return $path_or_url;
		}
		return Token_Service::GRAPH . '/' . ltrim( $path_or_url, '/' );
	}

	/**
	 * Single GET returning decoded JSON.
	 */
	public function get( string $path, array $headers = [] ): array {
		return $this->request( 'GET', $path, null, $headers );
	}

	public function post( string $path, array $body, array $headers = [] ): array {
		return $this->request( 'POST', $path, $body, $headers );
	}

	/** Microsoft Graph exposes this read-only lookup as a POST. */
	public function get_directory_objects( array $ids, array $types ): array {
		return $this->request( 'POST', '/v1.0/directoryObjects/getByIds', [ 'ids' => $ids, 'types' => $types ], [], true );
	}

	/** PATCH (204 No Content returns an empty array). Used only with delegated tokens (actions). */
	public function patch( string $path, array $body, array $headers = [] ): array {
		return $this->request( 'PATCH', $path, $body, $headers );
	}

	/**
	 * Follows @odata.nextLink until exhausted or until $max_pages reached.
	 * Returns [ 'value' => [...], 'nextLink' => string|null ].
	 */
	public function get_pages( string $path, array $headers = [], int $max_pages = 0, ?callable $on_page = null ): array {
		$all   = [];
		$next  = $path;
		$pages = 0;
		while ( $next ) {
			$data  = $this->get( $next, $headers );
			$items = $data['value'] ?? [];
			if ( $on_page ) {
				$on_page( $items );
			} else {
				foreach ( $items as $item ) {
					$all[] = $item;
				}
			}
			$pages++;
			$next = $data['@odata.nextLink'] ?? null;
			if ( $max_pages > 0 && $pages >= $max_pages ) {
				break;
			}
		}
		return [ 'value' => $all, 'nextLink' => $next ];
	}

	/**
	 * Convenience: everything from a collection.
	 */
	public function get_all( string $path, array $headers = [] ): array {
		return $this->get_pages( $path, $headers )['value'];
	}

	/**
	 * JSON batch. $requests = [ ['id'=>'1','method'=>'GET','url'=>'/groups/x/members/$count','headers'=>[...]] ... ]
	 * Returns map id => ['status'=>int,'body'=>mixed].
	 */
	/**
	 * @param bool $retry_throttled Retry 429/503/504 sub-requests one by one (reads). Write actions pass
	 *                              false: they get the status back and queue those items themselves.
	 */
	public function batch( array $requests, bool $retry_throttled = true ): array {
		$results = [];
		foreach ( array_chunk( $requests, 20 ) as $chunk ) {
			$payload = [ 'requests' => array_values( $chunk ) ];
			// A JSON batch submitted by POST is retryable as a read when it contains only GETs.
			$read    = ! array_filter( $chunk, static fn( $req ) => 'GET' !== strtoupper( $req['method'] ?? 'GET' ) );
			$data    = $this->request( 'POST', '/v1.0/$batch', $payload, [], $read );
			foreach ( $data['responses'] ?? [] as $resp ) {
				$id             = (string) ( $resp['id'] ?? '' );
				$results[ $id ] = [
					'status' => (int) ( $resp['status'] ?? 0 ),
					'body'   => $resp['body'] ?? null,
				];
				if ( $read && 401 === $results[ $id ]['status'] && Settings::per_tenant() ) {
					throw new Graph_Exception( 'A autorização da Microsoft expirou ou foi revogada. A página vai renová-la.', 401, 'j365_token' );
				}
				if ( in_array( $results[ $id ]['status'], [ 429, 503, 504 ], true ) ) {
					$results[ $id ]['retryAfter'] = self::retry_interval( (array) ( $resp['headers'] ?? [] ) );
				}
			}
			// Job reports save completed responses and retry the throttled subset in a later poll.
			foreach ( $retry_throttled && $read && $this->deadline <= 0 ? $chunk : [] as $req ) {
				$id = (string) $req['id'];
				if ( isset( $results[ $id ] ) && in_array( $results[ $id ]['status'], [ 429, 503, 504 ], true ) ) {
					usleep( 1500000 );
					try {
						$single         = $this->request( $req['method'] ?? 'GET', self::versioned( (string) $req['url'] ), $req['body'] ?? null, $req['headers'] ?? [] );
						$results[ $id ] = [ 'status' => 200, 'body' => $single ];
					} catch ( Graph_Exception $e ) {
						$results[ $id ] = [ 'status' => $e->getCode(), 'body' => [ 'error' => [ 'message' => $e->getMessage() ] ] ];
					}
				}
			}
		}
		return $results;
	}

	private function request( string $method, string $path, ?array $body, array $headers, bool $read_batch = false ): array {
		$attempt = 0;
		$read    = 'GET' === $method || $read_batch;
		$token   = $this->delegated ?? Token_Service::acquire( $this->tenant_id );

		while ( true ) {
			$attempt++;
			$args = [
				'method'      => $method,
				'timeout'     => $this->http_timeout( $read ),
				'redirection' => 0,
				'headers' => array_merge(
					[
						'Authorization' => 'Bearer ' . $token,
						'Accept'        => 'application/json',
						'User-Agent'    => 'Jornada365-TenantPortal/' . J365TP_VERSION,
					],
					$headers
				),
			];
			if ( null !== $body ) {
				$args['headers']['Content-Type'] = 'application/json';
				$args['body']                    = wp_json_encode( $body );
			}

			$response = wp_remote_request( $this->url( $path ), $args );

			if ( is_wp_error( $response ) ) {
				if ( ! $read ) {
					throw new Graph_Exception( 'A Microsoft não confirmou o resultado da ação. Verifique o estado antes de repeti-la.', 0, 'action_unconfirmed' );
				}
				if ( $read && $this->deadline > 0 ) {
					throw new Graph_Throttled( 'A conexão com o Microsoft Graph falhou. O relatório tentará novamente.', 2, 0, 'network' );
				}
				// Brief blips are retried inside the step; persistent DNS/TLS/firewall failures fail the job.
				$wait = min( 30, 2 ** $attempt );
				if ( $attempt <= $this->max_retries && $this->can_wait( $wait ) ) {
					sleep( $wait );
					continue;
				}
				throw new Graph_Exception( 'Falha de rede ao chamar o Graph: ' . $response->get_error_message(), 0, 'network' );
			}

			$status = (int) wp_remote_retrieve_response_code( $response );
			$raw    = (string) wp_remote_retrieve_body( $response );

			if ( $status >= 200 && $status < 300 ) {
				if ( '' === trim( $raw ) ) {
					return [];
				}
				$decoded = json_decode( $raw, true );
				if ( ! is_array( $decoded ) ) {
					// $count endpoints return a bare integer.
					return [ 'value' => is_numeric( trim( $raw ) ) ? (int) trim( $raw ) : $raw ];
				}
				return $decoded;
			}

			if ( 401 === $status && null !== $this->delegated ) {
				throw new Graph_Exception( 'Sua autorização para executar ações expirou. Autorize novamente.', 401, 'action_token_expired' );
			}
			if ( 401 === $status && Settings::per_tenant() ) {
				// Delegated token expired or revoked (CAE): the browser renews it and repeats the call.
				throw new Graph_Exception( 'A autorização da Microsoft expirou ou foi revogada. A página vai renová-la.', 401, 'j365_token' );
			}
			if ( 401 === $status && $attempt <= 2 ) {
				$token = Token_Service::acquire( $this->tenant_id, true );
				continue;
			}

			if ( ! $read && $status >= 500 ) {
				throw new Graph_Exception( 'A Microsoft não confirmou o resultado da ação. Verifique o estado antes de repeti-la.', $status, 'action_unconfirmed' );
			}
			if ( in_array( $status, [ 429, 503, 504 ], true ) && $attempt <= $this->max_retries ) {
				$retry_after = self::retry_delay( (string) wp_remote_retrieve_header( $response, 'retry-after' ), 2 ** $attempt );
				$wait        = min( 60, $retry_after );
				if ( $this->deadline > 0 ) {
					throw new Graph_Throttled( 'O Microsoft Graph pediu uma pausa. O relatório continua automaticamente.', $retry_after, $status );
				}
				if ( ! $this->can_wait( $wait ) ) {
					throw new Graph_Throttled( 'O Microsoft Graph pediu uma pausa (limite de requisições). O relatório continua automaticamente.', max( $wait, $retry_after ), $status );
				}
				sleep( $wait );
				continue;
			}

			$error   = json_decode( $raw, true )['error'] ?? [];
			$code    = (string) ( $error['code'] ?? 'HTTP_' . $status );
			$message = (string) ( $error['message'] ?? $raw );

			// Self-healing $select: a property that does not exist in this API version (schema drift,
			// beta-only fields) is removed and the call retried, instead of failing the whole report.
			if ( 400 === $status && $attempt <= 4 && preg_match( "/Could not find a property named '([^']+)'/", $message, $m ) ) {
				$fixed = self::strip_select( $path, $m[1] );
				if ( null !== $fixed ) {
					$this->notes[] = sprintf( 'Propriedade "%s" indisponível nesta versão do Microsoft Graph — coluna omitida.', $m[1] );
					$path          = $fixed;
					continue;
				}
			}
			throw new Graph_Exception( $this->humanize( $status, $code, $message, $path ), $status, $code );
		}
	}

	/**
	 * Microsoft 365 usage reports on v1.0 answer "302 Found" with a pre-authenticated CSV download URL
	 * ($format=application/json is beta-only). The redirect is followed manually so the Graph token is
	 * never sent to the download host. Returns the rows keyed by camelCase column names, which match the
	 * JSON property names of the same report (e.g. "User Principal Name" → userPrincipalName).
	 */
	public function get_report_csv( string $path, array $columns = [] ): array {
		$attempt = 0;
		$token   = Token_Service::acquire( $this->tenant_id );
		$ua      = 'Jornada365-TenantPortal/' . J365TP_VERSION;
		while ( true ) {
			$attempt++;
			$res = wp_remote_get( $this->url( $path ), [ 'timeout' => $this->http_timeout(), 'redirection' => 0, 'headers' => [ 'Authorization' => 'Bearer ' . $token, 'User-Agent' => $ua ] ] );
			if ( is_wp_error( $res ) ) {
				if ( $this->deadline > 0 ) {
					throw new Graph_Throttled( 'A conexão com o Microsoft Graph falhou. O relatório tentará novamente.', 2, 0, 'network' );
				}
				if ( $attempt <= 3 && $this->can_wait( min( 20, 2 ** $attempt ) ) ) {
					sleep( min( 20, 2 ** $attempt ) );
					continue;
				}
				throw new Graph_Exception( 'Falha de rede ao chamar o Graph: ' . $res->get_error_message(), 0, 'network' );
			}
			$status = (int) wp_remote_retrieve_response_code( $res );
			if ( in_array( $status, [ 301, 302, 303, 307, 308 ], true ) ) {
				return self::parse_csv( $this->download_report( (string) wp_remote_retrieve_header( $res, 'location' ), $ua ), $columns );
			}
			$raw = (string) wp_remote_retrieve_body( $res );
			if ( 200 === $status ) {
				$json = json_decode( $raw, true );
				if ( is_array( $json ) && isset( $json['value'] ) && is_array( $json['value'] ) ) {
					if ( ! $columns ) {
						return $json['value'];
					}
					$keys = array_flip( $columns );
					return array_map( static fn( $row ) => array_intersect_key( (array) $row, $keys ), $json['value'] );
				}
				return self::parse_csv( $raw, $columns );
			}
			if ( 401 === $status && Settings::per_tenant() ) {
				throw new Graph_Exception( 'A autorização da Microsoft expirou ou foi revogada. A página vai renová-la.', 401, 'j365_token' );
			}
			if ( 401 === $status && $attempt <= 2 ) {
				$token = Token_Service::acquire( $this->tenant_id, true );
				continue;
			}
			if ( in_array( $status, [ 429, 503, 504 ], true ) && $attempt <= $this->max_retries ) {
				$retry_after = self::retry_delay( (string) wp_remote_retrieve_header( $res, 'retry-after' ), 2 ** $attempt );
				$wait        = min( 60, $retry_after );
				if ( $this->deadline > 0 ) {
					throw new Graph_Throttled( 'O Microsoft Graph pediu uma pausa. O relatório continua automaticamente.', $retry_after, $status );
				}
				if ( ! $this->can_wait( $wait ) ) {
					throw new Graph_Throttled( 'O Microsoft Graph pediu uma pausa (limite de requisições). O relatório continua automaticamente.', max( $wait, $retry_after ), $status );
				}
				sleep( $wait );
				continue;
			}
			$error = json_decode( $raw, true )['error'] ?? [];
			$code  = (string) ( $error['code'] ?? 'HTTP_' . $status );
			throw new Graph_Exception( $this->humanize( $status, $code, (string) ( $error['message'] ?? mb_substr( $raw, 0, 300 ) ), $path ), $status, $code );
		}
	}

	private function download_report( string $location, string $user_agent ): string {
		// Validate each pre-authorized hop. Graph authorization stays on the initial request.
		for ( $hops = 0; $hops <= 3; $hops++ ) {
			if ( ! preg_match( '#^https://[a-z0-9.-]+\.[a-z]{2,}(?::443)?/#i', $location ) ) {
				break;
			}
			$response = wp_safe_remote_get( $location, [ 'timeout' => $this->http_timeout( true, 120 ), 'redirection' => 0, 'headers' => [ 'User-Agent' => $user_agent ] ] );
			if ( is_wp_error( $response ) ) {
				if ( $this->deadline > 0 ) {
					throw new Graph_Throttled( 'A conexão foi interrompida ao baixar o relatório. Tentando novamente.', 2, 0, 'network' );
				}
				throw new Graph_Exception( 'Falha ao baixar o relatório de uso: ' . $response->get_error_message(), 0, 'network' );
			}
			$status = (int) wp_remote_retrieve_response_code( $response );
			if ( 200 === $status ) {
				return (string) wp_remote_retrieve_body( $response );
			}
			if ( in_array( $status, [ 301, 302, 303, 307, 308 ], true ) ) {
				$location = \WP_Http::make_absolute_url( (string) wp_remote_retrieve_header( $response, 'location' ), $location );
				continue;
			}
			if ( $this->deadline > 0 && in_array( $status, [ 429, 503, 504 ], true ) ) {
				$pause = self::retry_delay( (string) wp_remote_retrieve_header( $response, 'retry-after' ), 2 );
				throw new Graph_Throttled( 'A Microsoft pediu uma pausa no download. O relatório continua automaticamente.', $pause, $status );
			}
			throw new Graph_Exception( 'Download do relatório de uso falhou (HTTP ' . $status . ').', $status, 'download_failed' );
		}
		throw new Graph_Exception( 'Redirecionamento inesperado ao baixar o relatório de uso.', 502, 'bad_redirect' );
	}

	/** RFC 4180 CSV (UTF-8, optional BOM, quoted fields) → list of rows keyed by camelCase headers. */
	public static function parse_csv( string $csv, array $columns = [] ): array {
		$csv = (string) preg_replace( '/^\xEF\xBB\xBF/', '', $csv );
		if ( '' === trim( $csv ) ) {
			return [];
		}
		$fh = fopen( 'php://temp', 'r+' );
		fwrite( $fh, $csv );
		rewind( $fh );
		$head = fgetcsv( $fh, 0, ',', '"', '' );
		if ( ! is_array( $head ) ) {
			fclose( $fh );
			return [];
		}
		$keys = array_map( [ self::class, 'camel' ], $head );
		if ( $columns ) {
			$keys = array_intersect( $keys, $columns );
		}
		$rows = [];
		while ( false !== ( $line = fgetcsv( $fh, 0, ',', '"', '' ) ) ) {
			if ( [ null ] === $line ) {
				continue; // blank line
			}
			$row = [];
			foreach ( $keys as $i => $k ) {
				$row[ $k ] = $line[ $i ] ?? '';
			}
			$rows[] = $row;
		}
		fclose( $fh );
		return $rows;
	}

	/** "User Principal Name" → userPrincipalName, "OneDrive Last Activity Date" → oneDriveLastActivityDate. */
	public static function camel( string $header ): string {
		$words = preg_split( '/[^A-Za-z0-9]+/', trim( $header ), -1, PREG_SPLIT_NO_EMPTY ) ?: [];
		$out   = '';
		foreach ( $words as $i => $w ) {
			$out .= 0 === $i ? lcfirst( $w ) : ucfirst( $w );
		}
		return $out;
	}

	/**
	 * Integer body of a $count sub-response inside $batch: a JSON number, a numeric string or a
	 * base64-encoded text/plain body, depending on the gateway. Null when it isn't a count.
	 */
	public static function batch_int( $body ): ?int {
		if ( is_int( $body ) ) {
			return $body;
		}
		if ( is_float( $body ) ) {
			return (int) $body;
		}
		if ( is_array( $body ) && isset( $body['value'] ) && ! is_array( $body['value'] ) ) {
			return self::batch_int( $body['value'] );
		}
		if ( is_string( $body ) ) {
			$t = trim( $body );
			if ( '' !== $t && ctype_digit( $t ) ) {
				return (int) $t;
			}
			$dec = base64_decode( $t, true );
			if ( false !== $dec && '' !== trim( $dec ) && ctype_digit( trim( $dec ) ) ) {
				return (int) trim( $dec );
			}
		}
		return null;
	}

	/** Removes $prop from the $select (and $expand selects) of $path; null when not present. */
	public static function strip_select( string $path, string $prop ): ?string {
		$parts = explode( '?', $path, 2 );
		if ( 2 !== count( $parts ) ) {
			return null;
		}
		$changed = false;
		$query   = preg_replace_callback(
			'/(\$select=)([^&]*)/',
			function ( $m ) use ( $prop, &$changed ) {
				$decoded = rawurldecode( $m[2] );
				$fields  = array_map( 'trim', explode( ',', $decoded ) );
				$kept    = array_values( array_filter( $fields, fn( $f ) => strcasecmp( $f, $prop ) !== 0 ) );
				if ( count( $kept ) === count( $fields ) || ! $kept ) {
					return $m[0];
				}
				$changed = true;
				return $m[1] . implode( ',', $kept );
			},
			$parts[1]
		);
		return $changed ? $parts[0] . '?' . $query : null;
	}

	private function humanize( int $status, string $code, string $message, string $path ): string {
		if ( 403 === $status ) {
			return Settings::per_tenant()
				? sprintf( 'O Microsoft Graph negou o acesso a "%s" (%s): a sua conta não tem uma função com acesso a este dado, o aplicativo do seu tenant não tem a permissão ou o recurso não está licenciado.', $this->short_path( $path ), $code )
				: sprintf( 'O Microsoft Graph negou o acesso a "%s" (%s): falta a permissão do aplicativo neste tenant (aprovação do administrador) ou o recurso não está licenciado.', $this->short_path( $path ), $code );
		}
		if ( 404 === $status && str_contains( $path, 'deviceManagement' ) ) {
			return 'Intune não está provisionado ou licenciado neste tenant.';
		}
		return sprintf( 'Graph %d %s: %s', $status, $code, mb_substr( $message, 0, 300 ) );
	}

	private function short_path( string $path ): string {
		$p = preg_replace( '#^https://graph\.microsoft\.com/(v1\.0|beta)/#', '', $path );
		return mb_substr( (string) preg_replace( '/\?.*$/', '', $p ), 0, 80 );
	}
}
