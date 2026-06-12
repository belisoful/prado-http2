<?php

/**
 * TH2Session class file.
 *
 * @author Brad Anderson <belisoful@icloud.com>
 * @link https://github.com/pradosoft/prado-http2
 * @license https://github.com/pradosoft/prado/blob/master/LICENSE
 */

namespace Prado\IO\Http2;

use Prado\Prado;
use Prado\TComponent;

/**
 * TH2Session class.
 *
 * An HTTP/2 connection driven by libnghttp2 (via {@see TNgHttp2}).  It owns one nghttp2 server
 * or client session, manages the {@see TH2Stream} streams that open on it, and moves bytes
 * to and from a transport with {@see receive()} and {@see send()} (memory based, so the same
 * code drives a socket or an in-process test).  nghttp2 handles framing, HPACK, stream state,
 * and flow control; this class bridges its callbacks to PHP.
 *
 * Server flow: advertise settings, feed received bytes, and handle {@see onRequest} for each
 * request stream — {@see respond()} accepts it with response headers (e.g. `:status` 200 for an
 * RFC 8441 Extended CONNECT) and the stream then carries DATA both ways.  Client flow:
 * {@see request()} opens a stream and {@see onResponse} delivers the response headers.
 *
 * Outgoing bytes written to a {@see TH2Stream} are pulled into DATA frames by a shared data
 * provider; an empty open stream defers until {@see resumeStream()} (triggered by a write).
 *
 * The nghttp2 callbacks are built once and shared by every session: PHP closures handed to FFI
 * are retained for the FFI instance's life, so per-session closures would leak.  The shared
 * callbacks route to the owning session through nghttp2's `user_data`, a per-session id held in
 * {@see $_registry}.  Instance state is self-encapsulated: every field is reached through a
 * protected `get*Direct()`/`set*Direct()` accessor (the stream and pending-header maps return by
 * reference), so a subclass can intercept any of it.
 *
 * Stream events ('on' prefix), each raised with the session as sender and the {@see TH2Stream} as param:
 *  - onRequest: a server request stream's headers are complete.
 *  - onResponse: a client request's response headers arrived.
 *  - onData: DATA arrived on a stream (read it via the stream).
 *  - onClose: a stream closed.
 *
 * Diagnostic events ('on' prefix), raised with the session as sender:
 *  - onFrameSent / onFrameNotSent / onInvalidFrame: an array of `type`, `streamId`, `flags` (and an
 *    nghttp2 `error` code for the latter two).
 *  - onSessionError: nghttp2's message string for a session-level error.
 *
 * @author Brad Anderson <belisoful@icloud.com>
 * @since 1.0.0
 * @see https://www.rfc-editor.org/rfc/rfc9113.html
 * @see https://www.rfc-editor.org/rfc/rfc8441.html
 */
class TH2Session extends TComponent
{
	/** @var ?\FFI\CData The nghttp2 callbacks, built once and shared by all sessions. */
	private static ?\FFI\CData $_callbacks = null;

	/** @var ?\FFI\CData The data provider, built once and shared by all sessions. */
	private static ?\FFI\CData $_provider = null;

	/** @var ?\FFI The FFI instance the shared callbacks were built with. */
	private static ?\FFI $_callbacksFfi = null;

	/** @var array<int, mixed> The shared callback closures, kept alive for the process. */
	private static array $_sharedRefs = [];

	/** @var array<int, TH2Session> Live sessions, keyed by routing id (nghttp2 user_data). */
	private static array $_registry = [];

	/** @var int The next routing id to assign. */
	private static int $_nextId = 1;

	/** @var \FFI The bound nghttp2 FFI instance. */
	private \FFI $_ffi;

	/** @var bool Whether this is the server side. */
	private bool $_isServer;

	/** @var \FFI\CData The nghttp2_session pointer. */
	private \FFI\CData $_session;

	/** @var int The routing id, encoded in the session's nghttp2 user_data. */
	private int $_id = 0;

	/** @var ?\FFI\CData The int64 cell whose address is the session's nghttp2 user_data. */
	private ?\FFI\CData $_userData = null;

	/** @var array<int, TH2Stream> The open streams, keyed by stream id. */
	private array $_streams = [];

	/** @var array<int, array<string, string>> Headers accumulating per stream during reception. */
	private array $_pendingHeaders = [];

	/** @var bool Whether the session has been closed. */
	private bool $_closed = false;

	/** @var ?TH2Options The tuning options applied at creation. */
	private ?TH2Options $_options = null;

	/**
	 * @param bool $isServer Whether this is the server side. Default true.
	 * @param ?TH2Options $options Tuning options, or null for nghttp2's defaults.
	 * @throws THttp2Exception When libnghttp2 is unavailable or the session cannot be created.
	 */
	public function __construct(bool $isServer = true, ?TH2Options $options = null)
	{
		$this->setFfiDirect(TNgHttp2::ffi());
		$this->setIsServerDirect($isServer);
		$this->setOptionsDirect($options);
		$this->createSession();
		parent::__construct();
	}

	// =========================================================================
	// Self-Encapsulated Accessors
	// =========================================================================

	/** @return \FFI The bound nghttp2 FFI instance. */
	protected function getFfiDirect(): \FFI
	{
		return $this->_ffi;
	}

	/** @param \FFI $value The bound nghttp2 FFI instance. */
	protected function setFfiDirect(\FFI $value): void
	{
		$this->_ffi = $value;
	}

	/** @return bool Whether this is the server side. */
	protected function getIsServerDirect(): bool
	{
		return $this->_isServer;
	}

	/** @param bool $value Whether this is the server side. */
	protected function setIsServerDirect(bool $value): void
	{
		$this->_isServer = $value;
	}

	/** @return \FFI\CData The nghttp2_session pointer. */
	protected function getSessionDirect(): \FFI\CData
	{
		return $this->_session;
	}

	/** @param \FFI\CData $value The nghttp2_session pointer. */
	protected function setSessionDirect(\FFI\CData $value): void
	{
		$this->_session = $value;
	}

	/** @return int The routing id. */
	protected function getIdDirect(): int
	{
		return $this->_id;
	}

	/** @param int $value The routing id. */
	protected function setIdDirect(int $value): void
	{
		$this->_id = $value;
	}

	/** @return ?\FFI\CData The nghttp2 user_data cell. */
	protected function getUserDataDirect(): ?\FFI\CData
	{
		return $this->_userData;
	}

	/** @param ?\FFI\CData $value The nghttp2 user_data cell. */
	protected function setUserDataDirect(?\FFI\CData $value): void
	{
		$this->_userData = $value;
	}

	/**
	 * Returns the raw stream map by reference, for in-place mutation.
	 * @return array<int, TH2Stream> The open streams, by reference.
	 */
	protected function &getStreamsDirect(): array
	{
		return $this->_streams;
	}

	/** @param array<int, TH2Stream> $value The open streams. */
	protected function setStreamsDirect(array $value): void
	{
		$this->_streams = $value;
	}

	/**
	 * Returns the raw pending-header map by reference, for in-place mutation.
	 * @return array<int, array<string, string>> The pending headers, by reference.
	 */
	protected function &getPendingHeadersDirect(): array
	{
		return $this->_pendingHeaders;
	}

	/** @param array<int, array<string, string>> $value The pending headers. */
	protected function setPendingHeadersDirect(array $value): void
	{
		$this->_pendingHeaders = $value;
	}

	/** @return bool Whether the session is closed. */
	protected function getClosedDirect(): bool
	{
		return $this->_closed;
	}

	/** @param bool $value Whether the session is closed. */
	protected function setClosedDirect(bool $value): void
	{
		$this->_closed = $value;
	}

	/** @return ?TH2Options The tuning options. */
	protected function getOptionsDirect(): ?TH2Options
	{
		return $this->_options;
	}

	/** @param ?TH2Options $value The tuning options. */
	protected function setOptionsDirect(?TH2Options $value): void
	{
		$this->_options = $value;
	}

	// =========================================================================
	// Session Setup
	// =========================================================================

	/**
	 * Builds the shared nghttp2 callbacks (and data provider) once, wired to static closures that
	 * route to the owning session through nghttp2's `user_data`.  Rebuilt only if the FFI instance
	 * changes (e.g. after {@see TNgHttp2::setLibraryPath()}).
	 * @param \FFI $ffi The bound nghttp2 FFI instance.
	 * @return \FFI\CData The shared nghttp2_session_callbacks pointer.
	 */
	private static function sharedCallbacks(\FFI $ffi): \FFI\CData
	{
		if (self::$_callbacks !== null && self::$_callbacksFfi === $ffi) {
			return self::$_callbacks;
		}
		$callbacks = $ffi->new('nghttp2_session_callbacks*');
		$ffi->nghttp2_session_callbacks_new(\FFI::addr($callbacks));

		$onBeginHeaders = static function ($session, $frame, $userData) {
			self::fromUserData($userData)?->handleBeginHeaders($frame->stream_id);
			return 0;
		};
		$onHeader = static function ($session, $frame, $name, $namelen, $value, $valuelen, $flags, $userData) {
			self::fromUserData($userData)?->handleHeader($frame->stream_id, \FFI::string($name, $namelen), \FFI::string($value, $valuelen));
			return 0;
		};
		$onFrameRecv = static fn ($session, $frame, $userData) => self::fromUserData($userData)?->handleFrameRecv($frame) ?? 0;
		$onDataChunk = static fn ($session, $flags, $streamId, $data, $len, $userData) => self::fromUserData($userData)?->handleDataChunk($streamId, \FFI::string($data, $len)) ?? 0;
		$onStreamClose = static fn ($session, $streamId, $errorCode, $userData) => self::fromUserData($userData)?->handleStreamClose($streamId) ?? 0;

		$ffi->nghttp2_session_callbacks_set_on_begin_headers_callback($callbacks, $onBeginHeaders);
		$ffi->nghttp2_session_callbacks_set_on_header_callback($callbacks, $onHeader);
		$ffi->nghttp2_session_callbacks_set_on_frame_recv_callback($callbacks, $onFrameRecv);
		$ffi->nghttp2_session_callbacks_set_on_data_chunk_recv_callback($callbacks, $onDataChunk);
		$ffi->nghttp2_session_callbacks_set_on_stream_close_callback($callbacks, $onStreamClose);

		$provider = $ffi->new('nghttp2_data_provider2');
		$provideData = static function ($session, $streamId, $buf, $length, $dataFlags, $source, $userData) {
			$self = self::fromUserData($userData);
			if ($self === null) {
				$dataFlags[0] = TNgHttp2::DATA_FLAG_EOF;
				return 0;
			}
			return $self->provideData($streamId, $buf, $length, $dataFlags);
		};
		$provider->read_callback = $provideData;

		$onFrameSend = static function ($session, $frame, $userData) {
			self::fromUserData($userData)?->onFrameSent(self::frameInfo($frame));
			return 0;
		};
		$onFrameNotSend = static function ($session, $frame, $libError, $userData) {
			self::fromUserData($userData)?->onFrameNotSent(self::frameInfo($frame) + ['error' => $libError]);
			return 0;
		};
		$onInvalidFrame = static function ($session, $frame, $libError, $userData) {
			self::fromUserData($userData)?->onInvalidFrame(self::frameInfo($frame) + ['error' => $libError]);
			return 0;
		};
		$onError = static function ($session, $libError, $msg, $len, $userData) {
			self::fromUserData($userData)?->onSessionError(\FFI::string($msg, $len));
			return 0;
		};
		$ffi->nghttp2_session_callbacks_set_on_frame_send_callback($callbacks, $onFrameSend);
		$ffi->nghttp2_session_callbacks_set_on_frame_not_send_callback($callbacks, $onFrameNotSend);
		$ffi->nghttp2_session_callbacks_set_on_invalid_frame_recv_callback($callbacks, $onInvalidFrame);
		$ffi->nghttp2_session_callbacks_set_error_callback2($callbacks, $onError);

		// nghttp2 keeps the function pointers; the closures (and provider) live for the process.
		self::$_sharedRefs = [$onBeginHeaders, $onHeader, $onFrameRecv, $onDataChunk, $onStreamClose, $provideData, $onFrameSend, $onFrameNotSend, $onInvalidFrame, $onError];
		self::$_provider = $provider;
		self::$_callbacksFfi = $ffi;
		self::$_callbacks = $callbacks;
		return $callbacks;
	}

	/**
	 * Resolves the session from a callback's nghttp2 `user_data` (an int64 routing id).
	 * @param ?\FFI\CData $userData The user_data pointer passed by nghttp2.
	 * @return ?TH2Session The owning session, or null.
	 */
	private static function fromUserData(?\FFI\CData $userData): ?TH2Session
	{
		if ($userData === null) {
			return null;
		}
		$id = TNgHttp2::ffi()->cast('int64_t*', $userData)[0];
		return self::$_registry[$id] ?? null;
	}

	/**
	 * Summarizes a frame header for a diagnostic event.
	 * @param \FFI\CData $frame The nghttp2_frame_hd.
	 * @return array{type: int, streamId: int, flags: int} The frame type, stream id, and flags.
	 */
	private static function frameInfo(\FFI\CData $frame): array
	{
		return ['type' => $frame->type, 'streamId' => $frame->stream_id, 'flags' => $frame->flags];
	}

	/**
	 * Creates the underlying nghttp2 server or client session, registered for callback routing.
	 * @throws THttp2Exception When the session cannot be created.
	 */
	private function createSession(): void
	{
		$ffi = $this->getFfiDirect();
		$callbacks = self::sharedCallbacks($ffi);

		$this->setIdDirect(self::$_nextId++);
		self::$_registry[$this->getIdDirect()] = $this;
		$userData = $ffi->new('int64_t');
		$userData->cdata = $this->getIdDirect();
		$this->setUserDataDirect($userData);

		$this->setSessionDirect($ffi->new('nghttp2_session*'));
		$option = $this->buildOption($ffi);
		if ($option !== null) {
			$result = $this->getIsServerDirect()
				? $ffi->nghttp2_session_server_new2(\FFI::addr($this->getSessionDirect()), $callbacks, \FFI::addr($userData), $option)
				: $ffi->nghttp2_session_client_new2(\FFI::addr($this->getSessionDirect()), $callbacks, \FFI::addr($userData), $option);
			$ffi->nghttp2_option_del($option);
		} else {
			$result = $this->getIsServerDirect()
				? $ffi->nghttp2_session_server_new(\FFI::addr($this->getSessionDirect()), $callbacks, \FFI::addr($userData))
				: $ffi->nghttp2_session_client_new(\FFI::addr($this->getSessionDirect()), $callbacks, \FFI::addr($userData));
		}
		if ($result !== 0) {
			unset(self::$_registry[$this->getIdDirect()]);
			throw new THttp2Exception('http2_session_failed', TNgHttp2::strerror($result));
		}
	}

	/**
	 * Builds an nghttp2_option from the session's {@see TH2Options}, or null when none are set.
	 * The caller deletes the returned option after the session is created.
	 * @param \FFI $ffi The bound nghttp2 FFI instance.
	 * @return ?\FFI\CData The nghttp2_option pointer, or null when defaults apply.
	 */
	private function buildOption(\FFI $ffi): ?\FFI\CData
	{
		$options = $this->getOptionsDirect();
		if ($options === null || $options->isEmpty()) {
			return null;
		}
		$option = $ffi->new('nghttp2_option*');
		$ffi->nghttp2_option_new(\FFI::addr($option));
		if ($options->getPeerMaxConcurrentStreams() !== null) {
			$ffi->nghttp2_option_set_peer_max_concurrent_streams($option, $options->getPeerMaxConcurrentStreams());
		}
		if ($options->getNoAutoWindowUpdate()) {
			$ffi->nghttp2_option_set_no_auto_window_update($option, 1);
		}
		return $option;
	}

	// =========================================================================
	// Settings API
	// =========================================================================

	/**
	 * Submits connection SETTINGS (e.g. {@see TNgHttp2::SETTINGS_ENABLE_CONNECT_PROTOCOL}).
	 * @param array<int, int> $settings The settings as id => value.
	 * @throws THttp2Exception When the submission fails.
	 */
	public function submitSettings(array $settings): void
	{
		$ffi = $this->getFfiDirect();
		$count = count($settings);
		$entries = $count > 0 ? $ffi->new("nghttp2_settings_entry[$count]") : null;
		$i = 0;
		foreach ($settings as $id => $value) {
			$entries[$i]->settings_id = $id;
			$entries[$i]->value = $value;
			$i++;
		}
		$result = $ffi->nghttp2_submit_settings($this->getSessionDirect(), 0, $entries, $count);
		if ($result !== 0) {
			throw new THttp2Exception('http2_session_failed', TNgHttp2::strerror($result));
		}
	}

	/**
	 * Returns a setting value the peer advertised (e.g. {@see TNgHttp2::SETTINGS_ENABLE_CONNECT_PROTOCOL}).
	 * @param int $id A {@see TNgHttp2} `SETTINGS_*` id.
	 * @return int The negotiated value.
	 */
	public function getRemoteSetting(int $id): int
	{
		return $this->getFfiDirect()->nghttp2_session_get_remote_settings($this->getSessionDirect(), $id);
	}

	/**
	 * Returns a setting value this side advertised.
	 * @param int $id A {@see TNgHttp2} `SETTINGS_*` id.
	 * @return int The local value.
	 */
	public function getLocalSetting(int $id): int
	{
		return $this->getFfiDirect()->nghttp2_session_get_local_settings($this->getSessionDirect(), $id);
	}

	// =========================================================================
	// Stream API
	// =========================================================================

	/**
	 * Opens a client request stream with the given headers (including pseudo-headers).
	 * @param array<string, string> $headers The request headers (e.g. ':method' => 'CONNECT').
	 * @throws THttp2Exception When the request cannot be submitted.
	 * @return TH2Stream The new stream.
	 */
	public function request(array $headers): TH2Stream
	{
		$keep = [];
		$nva = $this->buildHeaders($headers, $keep);
		$streamId = $this->getFfiDirect()->nghttp2_submit_request2($this->getSessionDirect(), null, $nva, count($headers), \FFI::addr(self::$_provider), null);
		if ($streamId < 0) {
			throw new THttp2Exception('http2_session_failed', TNgHttp2::strerror($streamId));
		}
		$stream = Prado::createComponent(TH2Stream::class, $this, $streamId, $headers);
		$streams = &$this->getStreamsDirect();
		$streams[$streamId] = $stream;
		return $stream;
	}

	/**
	 * Sends response headers on a server stream, accepting it (e.g. ':status' => '200').
	 * @param TH2Stream $stream The request stream to respond on.
	 * @param array<string, string> $headers The response headers.
	 * @throws THttp2Exception When the response cannot be submitted.
	 */
	public function respond(TH2Stream $stream, array $headers): void
	{
		$keep = [];
		$nva = $this->buildHeaders($headers, $keep);
		$result = $this->getFfiDirect()->nghttp2_submit_response2($this->getSessionDirect(), $stream->getStreamId(), $nva, count($headers), \FFI::addr(self::$_provider));
		if ($result !== 0) {
			throw new THttp2Exception('http2_session_failed', TNgHttp2::strerror($result));
		}
	}

	/**
	 * Returns an open stream by id, or null.
	 * @param int $streamId The stream identifier.
	 * @return ?TH2Stream The stream, or null.
	 */
	public function getStream(int $streamId): ?TH2Stream
	{
		return $this->getStreamsDirect()[$streamId] ?? null;
	}

	/**
	 * Resets (cancels) one stream with an error code, leaving the connection open.
	 * @param int $streamId The stream identifier.
	 * @param int $errorCode A {@see TNgHttp2} error code. Default {@see TNgHttp2::CANCEL}.
	 * @throws THttp2Exception When the reset cannot be submitted.
	 */
	public function resetStream(int $streamId, int $errorCode = TNgHttp2::CANCEL): void
	{
		$result = $this->getFfiDirect()->nghttp2_submit_rst_stream($this->getSessionDirect(), 0, $streamId, $errorCode);
		if ($result !== 0) {
			throw new THttp2Exception('http2_session_failed', TNgHttp2::strerror($result));
		}
	}

	/**
	 * Resumes a deferred stream so nghttp2 pulls its newly queued outgoing DATA.
	 * @param int $streamId The stream identifier.
	 */
	public function resumeStream(int $streamId): void
	{
		$this->getFfiDirect()->nghttp2_session_resume_data($this->getSessionDirect(), $streamId);
	}

	// =========================================================================
	// Transport I/O API
	// =========================================================================

	/**
	 * Feeds received transport bytes into the session, driving its callbacks.
	 * @param string $bytes The bytes read from the transport.
	 * @throws THttp2Exception When nghttp2 rejects the input.
	 */
	public function receive(string $bytes): void
	{
		$length = strlen($bytes);
		if ($length === 0) {
			return;
		}
		$ffi = $this->getFfiDirect();
		// Owned (auto-freed): nghttp2_session_mem_recv2 consumes the input synchronously.
		$buffer = $ffi->new("uint8_t[$length]");
		\FFI::memcpy($buffer, $bytes, $length);
		$result = $ffi->nghttp2_session_mem_recv2($this->getSessionDirect(), $ffi->cast('uint8_t*', $buffer), $length);
		if ($result < 0) {
			throw new THttp2Exception('http2_session_recv_failed', TNgHttp2::strerror($result));
		}
	}

	/**
	 * Drains and returns all bytes the session has to send to the transport.
	 * @throws THttp2Exception When nghttp2 fails to produce output.
	 * @return string The bytes to write to the transport ('' when none).
	 */
	public function send(): string
	{
		$ffi = $this->getFfiDirect();
		$output = '';
		while (true) {
			$pointer = $ffi->new('uint8_t*');
			$count = $ffi->nghttp2_session_mem_send2($this->getSessionDirect(), \FFI::addr($pointer));
			if ($count < 0) {
				throw new THttp2Exception('http2_session_send_failed', TNgHttp2::strerror($count));
			}
			if ($count === 0) {
				break;
			}
			$output .= \FFI::string($pointer, $count);
		}
		return $output;
	}

	/**
	 * Indicates whether the session still wants to read from or write to the transport.  An event
	 * loop ends and closes the connection once this is false.
	 * @return bool Whether the session has pending I/O.
	 */
	public function wantsIo(): bool
	{
		$ffi = $this->getFfiDirect();
		return $ffi->nghttp2_session_want_read($this->getSessionDirect()) !== 0
			|| $ffi->nghttp2_session_want_write($this->getSessionDirect()) !== 0;
	}

	// =========================================================================
	// Connection Control API
	// =========================================================================

	/**
	 * Shuts the connection down gracefully by sending GOAWAY (no new streams; in-flight finish).
	 * @param int $errorCode A {@see TNgHttp2} error code. Default {@see TNgHttp2::NO_ERROR}.
	 * @throws THttp2Exception When the termination cannot be submitted.
	 */
	public function goaway(int $errorCode = TNgHttp2::NO_ERROR): void
	{
		$result = $this->getFfiDirect()->nghttp2_session_terminate_session($this->getSessionDirect(), $errorCode);
		if ($result !== 0) {
			throw new THttp2Exception('http2_session_failed', TNgHttp2::strerror($result));
		}
	}

	/**
	 * Sends a PING for keepalive or round-trip timing; the peer answers with a PING ACK.
	 * @param string $payload Up to 8 bytes of opaque data (padded/truncated), or '' for a zero ping.
	 * @throws THttp2Exception When the ping cannot be submitted.
	 */
	public function ping(string $payload = ''): void
	{
		$ffi = $this->getFfiDirect();
		$opaque = null;
		if ($payload !== '') {
			$cell = $ffi->new('uint8_t[8]');
			\FFI::memcpy($cell, substr(str_pad($payload, 8, "\0"), 0, 8), 8);
			$opaque = $ffi->cast('uint8_t*', $cell);
		}
		$result = $ffi->nghttp2_submit_ping($this->getSessionDirect(), 0, $opaque);
		if ($result !== 0) {
			throw new THttp2Exception('http2_session_failed', TNgHttp2::strerror($result));
		}
	}

	/**
	 * Indicates whether a client may open a new {@see request()} (not GOAWAY-ed, under the limit).
	 * @return bool Whether a new request is allowed.
	 */
	public function isRequestAllowed(): bool
	{
		return $this->getFfiDirect()->nghttp2_session_check_request_allowed($this->getSessionDirect()) !== 0;
	}

	/**
	 * Submits a flow-control WINDOW_UPDATE for a stream (id 0 for the whole connection).
	 * @param int $streamId The stream identifier, or 0 for the connection.
	 * @param int $increment The window size increment in bytes.
	 * @throws THttp2Exception When the update cannot be submitted.
	 */
	public function submitWindowUpdate(int $streamId, int $increment): void
	{
		$result = $this->getFfiDirect()->nghttp2_submit_window_update($this->getSessionDirect(), 0, $streamId, $increment);
		if ($result !== 0) {
			throw new THttp2Exception('http2_session_failed', TNgHttp2::strerror($result));
		}
	}

	/**
	 * Tells nghttp2 that the application consumed bytes, advancing flow control (manual mode only,
	 * see {@see TH2Options::setNoAutoWindowUpdate()}).
	 * @param int $streamId The stream identifier.
	 * @param int $size The number of bytes consumed.
	 * @throws THttp2Exception When the consume call fails.
	 */
	public function consume(int $streamId, int $size): void
	{
		$result = $this->getFfiDirect()->nghttp2_session_consume($this->getSessionDirect(), $streamId, $size);
		if ($result !== 0) {
			throw new THttp2Exception('http2_session_failed', TNgHttp2::strerror($result));
		}
	}

	// =========================================================================
	// Lifecycle
	// =========================================================================

	/**
	 * Closes the session, freeing the nghttp2 session and unregistering it.  The shared callbacks
	 * and data provider are process-wide and are not freed here.
	 */
	public function close(): void
	{
		if ($this->getClosedDirect()) {
			return;
		}
		$this->setClosedDirect(true);
		$this->getFfiDirect()->nghttp2_session_del($this->getSessionDirect());
		unset(self::$_registry[$this->getIdDirect()]);
		$this->setStreamsDirect([]);
		$this->setUserDataDirect(null);
	}

	/**
	 * Frees the nghttp2 session if it was not closed explicitly.  {@see close()} is idempotent and
	 * best-effort here (it is skipped if the FFI binding is already gone, e.g. during shutdown).
	 */
	public function __destruct()
	{
		try {
			$this->close();
		} catch (\Throwable $e) {
			// best-effort cleanup
		}
		parent::__destruct();
	}

	// =========================================================================
	// nghttp2 Callbacks
	// =========================================================================

	/**
	 * Begins accumulating headers for a stream.
	 * @param int $streamId The stream identifier.
	 */
	private function handleBeginHeaders(int $streamId): void
	{
		$pending = &$this->getPendingHeadersDirect();
		$pending[$streamId] ??= [];
	}

	/**
	 * Records one received header for a stream.
	 * @param int $streamId The stream identifier.
	 * @param string $name The header name.
	 * @param string $value The header value.
	 */
	private function handleHeader(int $streamId, string $name, string $value): void
	{
		$pending = &$this->getPendingHeadersDirect();
		$pending[$streamId][$name] = $value;
	}

	/**
	 * Handles a completed frame: opens/finishes streams on HEADERS and tracks END_STREAM.
	 * @param \FFI\CData $frame The nghttp2_frame_hd of the received frame.
	 * @return int Always 0 (continue).
	 */
	private function handleFrameRecv(\FFI\CData $frame): int
	{
		$streamId = $frame->stream_id;
		$endStream = ($frame->flags & TNgHttp2::FLAG_END_STREAM) !== 0;
		if ($frame->type === TNgHttp2::FRAME_HEADERS) {
			$pending = &$this->getPendingHeadersDirect();
			$headers = $pending[$streamId] ?? [];
			unset($pending[$streamId]);
			$streams = &$this->getStreamsDirect();
			if ($this->getIsServerDirect()) {
				$stream = $streams[$streamId] ?? Prado::createComponent(TH2Stream::class, $this, $streamId, $headers);
				$streams[$streamId] = $stream;
				if ($endStream) {
					$stream->markRemoteClosed();
				}
				$this->onRequest($stream);
			} else {
				$stream = $streams[$streamId] ?? null;
				if ($stream !== null) {
					$stream->mergeHeaders($headers);
					if ($endStream) {
						$stream->markRemoteClosed();
					}
					$this->onResponse($stream);
				}
			}
		} elseif ($frame->type === TNgHttp2::FRAME_DATA && $endStream) {
			$this->getStreamsDirect()[$streamId]?->markRemoteClosed();
		}
		return 0;
	}

	/**
	 * Handles a DATA chunk: buffers it on the stream and raises {@see onData}.
	 * @param int $streamId The stream identifier.
	 * @param string $bytes The received bytes.
	 * @return int Always 0 (continue).
	 */
	private function handleDataChunk(int $streamId, string $bytes): int
	{
		$stream = $this->getStreamsDirect()[$streamId] ?? null;
		if ($stream !== null) {
			$stream->pushIncoming($bytes);
			$this->onData($stream);
		}
		return 0;
	}

	/**
	 * Handles a closed stream: marks it, raises {@see onClose}, and forgets it.
	 * @param int $streamId The stream identifier.
	 * @return int Always 0 (continue).
	 */
	private function handleStreamClose(int $streamId): int
	{
		$streams = &$this->getStreamsDirect();
		$stream = $streams[$streamId] ?? null;
		if ($stream !== null) {
			$stream->markRemoteClosed();
			$this->onClose($stream);
			unset($streams[$streamId]);
		}
		return 0;
	}

	/**
	 * The shared data provider: copies a stream's queued outgoing bytes into nghttp2's buffer.
	 * @param int $streamId The stream identifier.
	 * @param \FFI\CData $buf The destination buffer (uint8_t*).
	 * @param int $length The buffer capacity.
	 * @param \FFI\CData $dataFlags The data-flags out-parameter (uint32_t*).
	 * @return int The bytes written, 0 with EOF set, or {@see TNgHttp2::ERR_DEFERRED}.
	 */
	private function provideData(int $streamId, \FFI\CData $buf, int $length, \FFI\CData $dataFlags): int
	{
		$stream = $this->getStreamsDirect()[$streamId] ?? null;
		if ($stream === null) {
			$dataFlags[0] = TNgHttp2::DATA_FLAG_EOF;
			return 0;
		}
		if (!$stream->hasOutgoing()) {
			if ($stream->isLocalClosed()) {
				$dataFlags[0] = TNgHttp2::DATA_FLAG_EOF;
				return 0;
			}
			return TNgHttp2::ERR_DEFERRED;
		}
		$chunk = $stream->drainOutgoing($length);
		\FFI::memcpy($buf, $chunk, strlen($chunk));
		return strlen($chunk);
	}

	/**
	 * Builds an nghttp2_nv[] from header pairs; $keep holds the byte buffers alive during submit.
	 * @param array<string, string> $headers The header name => value pairs.
	 * @param array<int, mixed> &$keep Receives the name/value buffers (held by the caller).
	 * @return \FFI\CData The nghttp2_nv array.
	 */
	private function buildHeaders(array $headers, array &$keep): \FFI\CData
	{
		$ffi = $this->getFfiDirect();
		$count = max(1, count($headers));
		$nva = $ffi->new("nghttp2_nv[$count]");
		$i = 0;
		foreach ($headers as $name => $value) {
			$nameLen = strlen($name);
			$valueLen = strlen($value);
			// Owned (auto-freed): held in $keep through the submit call, which copies the headers.
			$nameBuf = $ffi->new('uint8_t[' . ($nameLen + 1) . ']');
			$valueBuf = $ffi->new('uint8_t[' . ($valueLen + 1) . ']');
			if ($nameLen > 0) {
				\FFI::memcpy($nameBuf, $name, $nameLen);
			}
			if ($valueLen > 0) {
				\FFI::memcpy($valueBuf, $value, $valueLen);
			}
			$nva[$i]->name = $ffi->cast('uint8_t*', $nameBuf);
			$nva[$i]->value = $ffi->cast('uint8_t*', $valueBuf);
			$nva[$i]->namelen = $nameLen;
			$nva[$i]->valuelen = $valueLen;
			$nva[$i]->flags = TNgHttp2::NV_FLAG_NONE;
			$keep[] = $nameBuf;
			$keep[] = $valueBuf;
			$i++;
		}
		return $nva;
	}

	// =========================================================================
	// Events
	// =========================================================================

	/**
	 * Raised when a server request stream's headers are complete.
	 * @param TH2Stream $stream The request stream.
	 */
	public function onRequest(TH2Stream $stream): void
	{
		$this->raiseEvent('onRequest', $this, $stream);
	}

	/**
	 * Raised when a client request's response headers arrive.
	 * @param TH2Stream $stream The stream carrying the response.
	 */
	public function onResponse(TH2Stream $stream): void
	{
		$this->raiseEvent('onResponse', $this, $stream);
	}

	/**
	 * Raised when DATA arrives on a stream.
	 * @param TH2Stream $stream The stream with new buffered data.
	 */
	public function onData(TH2Stream $stream): void
	{
		$this->raiseEvent('onData', $this, $stream);
	}

	/**
	 * Raised when a stream closes.
	 * @param TH2Stream $stream The closed stream.
	 */
	public function onClose(TH2Stream $stream): void
	{
		$this->raiseEvent('onClose', $this, $stream);
	}

	/**
	 * Raised after a frame is sent.
	 * @param mixed $param An array of `type`, `streamId`, and `flags`.
	 */
	public function onFrameSent(mixed $param): void
	{
		$this->raiseEvent('onFrameSent', $this, $param);
	}

	/**
	 * Raised when a queued frame could not be sent.
	 * @param mixed $param An array of `type`, `streamId`, `flags`, and the nghttp2 `error` code.
	 */
	public function onFrameNotSent(mixed $param): void
	{
		$this->raiseEvent('onFrameNotSent', $this, $param);
	}

	/**
	 * Raised when an invalid frame is received (a protocol violation by the peer).
	 * @param mixed $param An array of `type`, `streamId`, `flags`, and the nghttp2 `error` code.
	 */
	public function onInvalidFrame(mixed $param): void
	{
		$this->raiseEvent('onInvalidFrame', $this, $param);
	}

	/**
	 * Raised with nghttp2's human-readable message for a session-level error.
	 * @param mixed $param The error message string.
	 */
	public function onSessionError(mixed $param): void
	{
		$this->raiseEvent('onSessionError', $this, $param);
	}
}
