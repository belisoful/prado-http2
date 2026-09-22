<?php

/**
 * TNgHttp2 class file.
 *
 * @author Brad Anderson <belisoful@icloud.com>
 * @link https://github.com/pradosoft/prado-http2
 * @license https://github.com/pradosoft/prado/blob/master/LICENSE
 */

namespace Prado\IO\Http2;

/**
 * TNgHttp2 class.
 *
 * Binds the system `libnghttp2` shared library through PHP FFI and exposes the subset of its
 * API that an HTTP/2 server or client needs.  nghttp2 owns the HTTP/2 framing, HPACK header
 * compression, stream state, and flow control; this class is the loader and the C-declaration
 * surface, and {@see ffi()} returns the bound {@see \FFI} instance the session drives.
 *
 * The library is resolved from (in order) an explicit {@see setLibraryPath() path}, the
 * `PRADO_NGHTTP2_LIB` environment variable, and platform defaults.  {@see isAvailable()}
 * reports whether it loads, so callers can fall back to HTTP/1.1 when HTTP/2 is unavailable.
 *
 * The session I/O is memory based: feed received bytes with `nghttp2_session_mem_recv2` and
 * drain produced bytes with `nghttp2_session_mem_send2`, so the same code drives a socket or a
 * test that pumps bytes between two in-process sessions.
 *
 * @author Brad Anderson <belisoful@icloud.com>
 * @since 1.0.0
 * @see https://nghttp2.org/documentation/
 * @see https://www.rfc-editor.org/rfc/rfc9113.html
 */
final class TNgHttp2
{
	/** @var int The SETTINGS id for the HPACK header table size. */
	public const SETTINGS_HEADER_TABLE_SIZE = 0x01;

	/** @var int The SETTINGS id that enables server push. */
	public const SETTINGS_ENABLE_PUSH = 0x02;

	/** @var int The SETTINGS id for the maximum concurrent streams. */
	public const SETTINGS_MAX_CONCURRENT_STREAMS = 0x03;

	/** @var int The SETTINGS id for the initial flow-control window size. */
	public const SETTINGS_INITIAL_WINDOW_SIZE = 0x04;

	/** @var int The SETTINGS id for the maximum frame size. */
	public const SETTINGS_MAX_FRAME_SIZE = 0x05;

	/** @var int The SETTINGS id for the maximum header list size. */
	public const SETTINGS_MAX_HEADER_LIST_SIZE = 0x06;

	/** @var int The SETTINGS id that advertises Extended CONNECT support (RFC 8441). */
	public const SETTINGS_ENABLE_CONNECT_PROTOCOL = 0x08;

	/** @var int The DATA frame type. */
	public const FRAME_DATA = 0x00;

	/** @var int The HEADERS frame type. */
	public const FRAME_HEADERS = 0x01;

	/** @var int The RST_STREAM frame type. */
	public const FRAME_RST_STREAM = 0x03;

	/** @var int The SETTINGS frame type. */
	public const FRAME_SETTINGS = 0x04;

	/** @var int The PING frame type. */
	public const FRAME_PING = 0x06;

	/** @var int The GOAWAY frame type. */
	public const FRAME_GOAWAY = 0x07;

	/** @var int The WINDOW_UPDATE frame type. */
	public const FRAME_WINDOW_UPDATE = 0x08;

	/** @var int The END_STREAM frame flag. */
	public const FLAG_END_STREAM = 0x01;

	/** @var int The no-op name/value flag. */
	public const NV_FLAG_NONE = 0x00;

	/** @var int The data-source flag marking the final DATA. */
	public const DATA_FLAG_EOF = 0x01;

	/** @var int The data-source flag deferring DATA without ending the stream. */
	public const DATA_FLAG_NO_END_STREAM = 0x02;

	/** @var int The nghttp2 return code that defers a stream's DATA until resumed. */
	public const ERR_DEFERRED = -508;

	/** @var int The HTTP/2 error code for a clean close (RST_STREAM / GOAWAY). */
	public const NO_ERROR = 0x00;

	/** @var int The HTTP/2 PROTOCOL_ERROR code. */
	public const PROTOCOL_ERROR = 0x01;

	/** @var int The HTTP/2 INTERNAL_ERROR code. */
	public const INTERNAL_ERROR = 0x02;

	/** @var int The HTTP/2 STREAM_CLOSED error code. */
	public const STREAM_CLOSED = 0x05;

	/** @var int The HTTP/2 REFUSED_STREAM error code. */
	public const REFUSED_STREAM = 0x07;

	/** @var int The HTTP/2 CANCEL error code (the default for a reset stream). */
	public const CANCEL = 0x08;

	/** @var string The C declarations bound from libnghttp2. */
	private const CDEF = <<<'C'
		typedef ptrdiff_t nghttp2_ssize;
		typedef struct nghttp2_session nghttp2_session;
		typedef struct nghttp2_session_callbacks nghttp2_session_callbacks;
		typedef struct { int age; int version_num; const char *version_str; const char *proto_str; } nghttp2_info;
		typedef struct { size_t length; int32_t stream_id; uint8_t type; uint8_t flags; uint8_t reserved; } nghttp2_frame_hd;
		typedef struct { int32_t settings_id; uint32_t value; } nghttp2_settings_entry;
		typedef struct { uint8_t *name; uint8_t *value; size_t namelen; size_t valuelen; uint8_t flags; } nghttp2_nv;
		typedef union { void *ptr; int fd; } nghttp2_data_source;
		typedef nghttp2_ssize (*nghttp2_data_source_read_callback2)(nghttp2_session *session, int32_t stream_id, uint8_t *buf, size_t length, uint32_t *data_flags, nghttp2_data_source *source, void *user_data);
		typedef struct { nghttp2_data_source source; nghttp2_data_source_read_callback2 read_callback; } nghttp2_data_provider2;
		nghttp2_info *nghttp2_version(int least_version);
		const char *nghttp2_strerror(int lib_error_code);
		int nghttp2_session_callbacks_new(nghttp2_session_callbacks **callbacks_ptr);
		void nghttp2_session_callbacks_del(nghttp2_session_callbacks *callbacks);
		void nghttp2_session_callbacks_set_on_frame_recv_callback(nghttp2_session_callbacks *cbs, int (*cb)(nghttp2_session *session, const nghttp2_frame_hd *frame, void *user_data));
		void nghttp2_session_callbacks_set_on_data_chunk_recv_callback(nghttp2_session_callbacks *cbs, int (*cb)(nghttp2_session *session, uint8_t flags, int32_t stream_id, const uint8_t *data, size_t len, void *user_data));
		void nghttp2_session_callbacks_set_on_stream_close_callback(nghttp2_session_callbacks *cbs, int (*cb)(nghttp2_session *session, int32_t stream_id, uint32_t error_code, void *user_data));
		void nghttp2_session_callbacks_set_on_begin_headers_callback(nghttp2_session_callbacks *cbs, int (*cb)(nghttp2_session *session, const nghttp2_frame_hd *frame, void *user_data));
		void nghttp2_session_callbacks_set_on_header_callback(nghttp2_session_callbacks *cbs, int (*cb)(nghttp2_session *session, const nghttp2_frame_hd *frame, const uint8_t *name, size_t namelen, const uint8_t *value, size_t valuelen, uint8_t flags, void *user_data));
		int nghttp2_session_server_new(nghttp2_session **session_ptr, const nghttp2_session_callbacks *callbacks, void *user_data);
		int nghttp2_session_client_new(nghttp2_session **session_ptr, const nghttp2_session_callbacks *callbacks, void *user_data);
		void nghttp2_session_del(nghttp2_session *session);
		int nghttp2_submit_settings(nghttp2_session *session, uint8_t flags, const nghttp2_settings_entry *iv, size_t niv);
		int nghttp2_submit_response2(nghttp2_session *session, int32_t stream_id, const nghttp2_nv *nva, size_t nvlen, const nghttp2_data_provider2 *data_prd);
		int32_t nghttp2_submit_request2(nghttp2_session *session, const void *pri_spec, const nghttp2_nv *nva, size_t nvlen, const nghttp2_data_provider2 *data_prd, void *stream_user_data);
		int nghttp2_submit_trailer(nghttp2_session *session, int32_t stream_id, const nghttp2_nv *nva, size_t nvlen);
		nghttp2_ssize nghttp2_session_mem_send2(nghttp2_session *session, const uint8_t **data_ptr);
		nghttp2_ssize nghttp2_session_mem_recv2(nghttp2_session *session, const uint8_t *in, size_t inlen);
		int nghttp2_session_want_read(nghttp2_session *session);
		int nghttp2_session_want_write(nghttp2_session *session);
		int nghttp2_session_resume_data(nghttp2_session *session, int32_t stream_id);
		int nghttp2_submit_rst_stream(nghttp2_session *session, uint8_t flags, int32_t stream_id, uint32_t error_code);
		int nghttp2_session_terminate_session(nghttp2_session *session, uint32_t error_code);
		int nghttp2_submit_ping(nghttp2_session *session, uint8_t flags, const uint8_t *opaque_data);
		int nghttp2_submit_window_update(nghttp2_session *session, uint8_t flags, int32_t stream_id, int32_t window_size_increment);
		int nghttp2_session_consume(nghttp2_session *session, int32_t stream_id, size_t size);
		uint32_t nghttp2_session_get_remote_settings(nghttp2_session *session, int id);
		uint32_t nghttp2_session_get_local_settings(nghttp2_session *session, int id);
		int nghttp2_session_check_request_allowed(nghttp2_session *session);
		typedef struct nghttp2_option nghttp2_option;
		int nghttp2_option_new(nghttp2_option **option_ptr);
		void nghttp2_option_del(nghttp2_option *option);
		void nghttp2_option_set_no_auto_window_update(nghttp2_option *option, int val);
		void nghttp2_option_set_peer_max_concurrent_streams(nghttp2_option *option, uint32_t val);
		int nghttp2_session_server_new2(nghttp2_session **session_ptr, const nghttp2_session_callbacks *callbacks, void *user_data, const nghttp2_option *option);
		int nghttp2_session_client_new2(nghttp2_session **session_ptr, const nghttp2_session_callbacks *callbacks, void *user_data, const nghttp2_option *option);
		void nghttp2_session_callbacks_set_on_frame_send_callback(nghttp2_session_callbacks *cbs, int (*cb)(nghttp2_session *session, const nghttp2_frame_hd *frame, void *user_data));
		void nghttp2_session_callbacks_set_on_frame_not_send_callback(nghttp2_session_callbacks *cbs, int (*cb)(nghttp2_session *session, const nghttp2_frame_hd *frame, int lib_error_code, void *user_data));
		void nghttp2_session_callbacks_set_on_invalid_frame_recv_callback(nghttp2_session_callbacks *cbs, int (*cb)(nghttp2_session *session, const nghttp2_frame_hd *frame, int lib_error_code, void *user_data));
		void nghttp2_session_callbacks_set_error_callback2(nghttp2_session_callbacks *cbs, int (*cb)(nghttp2_session *session, int lib_error_code, const char *msg, size_t len, void *user_data));
		C;

	/** @var ?\FFI The bound FFI instance, created on first use. */
	private static ?\FFI $_ffi = null;

	/** @var ?string An explicit library path overriding the platform defaults. */
	private static ?string $_libraryPath = null;

	// =========================================================================
	// Library API
	// =========================================================================

	/**
	 * Sets an explicit libnghttp2 path, overriding the environment and platform defaults.
	 * @param ?string $value The library path, or null to restore default resolution.
	 */
	public static function setLibraryPath(?string $value): void
	{
		self::$_libraryPath = $value;
		self::$_ffi = null;
	}

	/**
	 * Returns the bound FFI instance, loading libnghttp2 on first use.
	 * @throws THttp2Exception When the library cannot be loaded.
	 * @return \FFI The bound nghttp2 FFI instance.
	 */
	public static function ffi(): \FFI
	{
		if (self::$_ffi !== null) {
			return self::$_ffi;
		}
		$errors = [];
		foreach (self::candidateLibraries() as $candidate) {
			try {
				self::$_ffi = \FFI::cdef(self::CDEF, $candidate);
				return self::$_ffi;
			} catch (\FFI\Exception $e) {
				$errors[] = $candidate . ' (' . $e->getMessage() . ')';
			}
		}
		throw new THttp2Exception('http2_library_missing', implode('; ', $errors));
	}

	/**
	 * Indicates whether libnghttp2 is loadable, so a caller can fall back to HTTP/1.1.
	 * @return bool Whether the library loads.
	 */
	public static function isAvailable(): bool
	{
		try {
			self::ffi();
			return true;
		} catch (\Throwable $e) {
			return false;
		}
	}

	/**
	 * Returns the loaded libnghttp2 version string (e.g. '1.68.1').
	 * @return string The runtime library version.
	 */
	public static function version(): string
	{
		$info = self::ffi()->nghttp2_version(0);
		return \FFI::string($info->version_str);
	}

	/**
	 * Returns the human-readable message for an nghttp2 library error code.
	 * @param int $code The nghttp2 error code (negative).
	 * @return string The error description.
	 */
	public static function strerror(int $code): string
	{
		// FFI converts a const char* return to a PHP string; some builds yield CData instead.
		$message = self::ffi()->nghttp2_strerror($code);
		return is_string($message) ? $message : \FFI::string($message);
	}

	/**
	 * Returns the ordered candidate library paths for the current platform.
	 * @return string[] The candidate paths or sonames to try in order.
	 */
	private static function candidateLibraries(): array
	{
		// An explicit path is authoritative: it is the only candidate, so a bad path fails clearly.
		if (self::$_libraryPath !== null) {
			return [self::$_libraryPath];
		}
		$candidates = [];
		$env = getenv('PRADO_NGHTTP2_LIB');
		if ($env !== false && $env !== '') {
			$candidates[] = $env;
		}
		$candidates = array_merge($candidates, match (PHP_OS_FAMILY) {
			'Darwin' => [
				'/opt/homebrew/opt/libnghttp2/lib/libnghttp2.dylib',
				'/usr/local/opt/libnghttp2/lib/libnghttp2.dylib',
				'libnghttp2.dylib',
			],
			'Windows' => ['nghttp2.dll', 'libnghttp2.dll'],
			default => [
				'libnghttp2.so.14',
				'libnghttp2.so',
				'/usr/lib/x86_64-linux-gnu/libnghttp2.so.14',
			],
		});
		return $candidates;
	}
}
