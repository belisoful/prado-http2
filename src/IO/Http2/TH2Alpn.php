<?php

/**
 * TH2Alpn class file.
 *
 * @author Brad Anderson <belisoful@icloud.com>
 * @link https://github.com/pradosoft/prado-http2
 * @license https://github.com/pradosoft/prado/blob/master/LICENSE
 */

namespace Prado\IO\Http2;

/**
 * TH2Alpn class.
 *
 * Negotiates the `h2` ALPN protocol for HTTP/2 over TLS (RFC 9113 §3.3).  PHP's OpenSSL stream
 * layer terminates the TLS connection and selects the protocol; this helper supplies the two
 * HTTP/2-specific pieces: it advertises `h2` in the stream's `ssl` context options, and it reads
 * back the negotiated protocol after the handshake.  The session itself stays transport-agnostic:
 * the caller pumps the TLS stream's bytes into {@see TH2Session::receive()}/{@see TH2Session::send()}
 * exactly as for a cleartext socket.
 *
 * Full TLS termination (certificates, cipher policy, the listen and accept loop) is the caller's
 * concern.  Browsers speak HTTP/2 only over TLS with `h2`, so a browser-facing server (including
 * an RFC 8441 WebSocket-over-HTTP/2 server) negotiates `h2` through this helper; a cleartext
 * `h2c` session needs none of it.
 *
 * Server example:
 * ```php
 * $ctx = stream_context_create(['ssl' => TH2Alpn::sslOptions([
 *     'local_cert' => '/path/server.pem',
 * ])]);
 * $listen = stream_socket_server('tls://0.0.0.0:443', $errno, $errstr,
 *     STREAM_SERVER_BIND | STREAM_SERVER_LISTEN, $ctx);
 * $conn = stream_socket_accept($listen);              // TLS handshake completes here
 * TH2Alpn::requireH2($conn);                          // confirm the peer negotiated h2
 * $session = new TH2Session(true);
 * // ... pump fread($conn) -> $session->receive(); fwrite($conn, $session->send()) ...
 * ```
 *
 * Client example:
 * ```php
 * $ctx = stream_context_create(['ssl' => TH2Alpn::sslOptions(['peer_name' => 'example.com'])]);
 * $conn = stream_socket_client('tls://example.com:443', $errno, $errstr, 30,
 *     STREAM_CLIENT_CONNECT, $ctx);
 * if (!TH2Alpn::negotiatedH2($conn)) {                // fall back to HTTP/1.1 when h2 is absent
 *     // ...
 * }
 * ```
 *
 * @author Brad Anderson <belisoful@icloud.com>
 * @since 1.0.0
 * @see https://www.rfc-editor.org/rfc/rfc9113.html#name-starting-http-2-for-https-u
 * @see https://www.rfc-editor.org/rfc/rfc8441.html
 */
final class TH2Alpn
{
	/** @var string The ALPN protocol identifier for HTTP/2 over TLS. */
	public const PROTOCOL_H2 = 'h2';

	/** @var string The ALPN protocol identifier for HTTP/1.1 over TLS. */
	public const PROTOCOL_HTTP11 = 'http/1.1';

	/**
	 * Indicates whether TLS with ALPN is usable, so a caller can fall back to cleartext or
	 * HTTP/1.1.  ALPN needs OpenSSL 1.0.2 or newer, which every current PHP `ext-openssl` build
	 * provides.
	 * @return bool Whether `ext-openssl` is loaded.
	 */
	public static function isAvailable(): bool
	{
		return extension_loaded('openssl');
	}

	/**
	 * Returns an `ssl` context-options array advertising the ALPN protocols, `h2` first.  Merge it
	 * with any TLS options (certificate, peer name) and pass it to `stream_context_create()`.
	 * @param array<string, mixed> $ssl Existing `ssl` context options to extend.
	 * @param string[] $protocols The ALPN identifiers in preference order. Default `['h2']`.
	 * @return array<string, mixed> The `ssl` options with `alpn_protocols` set.
	 */
	public static function sslOptions(array $ssl = [], array $protocols = [self::PROTOCOL_H2]): array
	{
		$ssl['alpn_protocols'] = implode(',', $protocols);
		return $ssl;
	}

	/**
	 * Returns the ALPN protocol negotiated on a TLS stream, read after the handshake completes.
	 * @param resource $stream The TLS stream resource.
	 * @return ?string The negotiated protocol (e.g. 'h2'), or null when none was negotiated or the
	 *   stream is not encrypted.
	 */
	public static function negotiatedProtocol($stream): ?string
	{
		if (!is_resource($stream)) {
			return null;
		}
		$meta = stream_get_meta_data($stream);
		$alpn = $meta['crypto']['alpn_protocol'] ?? null;
		return is_string($alpn) && $alpn !== '' ? $alpn : null;
	}

	/**
	 * Indicates whether the TLS stream negotiated HTTP/2 (`h2`).
	 * @param resource $stream The TLS stream resource.
	 * @return bool Whether `h2` was negotiated.
	 */
	public static function negotiatedH2($stream): bool
	{
		return self::negotiatedProtocol($stream) === self::PROTOCOL_H2;
	}

	/**
	 * Asserts the TLS stream negotiated `h2`, throwing otherwise.  A server uses it to reject a
	 * connection that did not negotiate HTTP/2.
	 * @param resource $stream The TLS stream resource.
	 * @throws THttp2Exception When the negotiated protocol is not `h2`.
	 */
	public static function requireH2($stream): void
	{
		if (!self::negotiatedH2($stream)) {
			throw new THttp2Exception('http2_alpn_not_negotiated', self::negotiatedProtocol($stream) ?? '(none)');
		}
	}
}
