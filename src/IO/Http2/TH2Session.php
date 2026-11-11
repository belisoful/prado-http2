<?php

/**
 * TH2Session class file.
 *
 * @author Brad Anderson <belisoful@icloud.com>
 * @link https://github.com/belisoful/prado-http2
 * @license https://github.com/pradosoft/prado/blob/master/LICENSE
 */

namespace Prado\IO\Http2;

use Prado\Exceptions\TCancelledException;
use Prado\IO\Async\TAsync;
use Prado\IO\Async\TAsyncStream;
use Prado\IO\Async\TCancellationToken;
use Prado\IO\IResource;
use Prado\Prado;
use Prado\TComponent;
use Psr\Http\Message\StreamInterface;
use Revolt\EventLoop\Suspension;

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
 * request stream.  {@see respond()} accepts it with response headers (e.g. `:status` 200 for an
 * RFC 8441 Extended CONNECT) and the stream then carries DATA both ways.  Client flow:
 * {@see request()} opens a stream and {@see onResponse} delivers the response headers.
 *
 * Outgoing bytes written to a {@see TH2Stream} are pulled into DATA frames by a shared data
 * provider; an empty open stream defers until {@see resumeStream()} (triggered by a write).
 *
 * Header names are lowercased on submit (RFC 9113 §8.2.1), pseudo-headers are sent first (§8.3),
 * and a name given twice in any letter case throws `http2_header_duplicate`.  A name that is not a
 * token, and a value with a CR, LF, or NUL character, throw `http2_header_invalid`.  A received
 * field that repeats keeps every value: `cookie` rejoins with `; `, any other name combines with
 * `, `.  A received header block larger than the {@see getMaxHeaderListSize() header list limit}
 * (64 KiB unless {@see TH2Options::setMaxHeaderListSize()} sets another) is dropped and its stream
 * reset: nghttp2 does not bound the decoded size of a header block.  A client session declines
 * server push (`SETTINGS_ENABLE_PUSH => 0`) when it {@see submitSettings() submits settings},
 * unless the caller sets that id; a pushed stream has no {@see TH2Stream}, so its frames are
 * discarded.  A server session advertises `SETTINGS_MAX_CONCURRENT_STREAMS` of
 * {@see DEFAULT_MAX_CONCURRENT_STREAMS} the same way.  A stream counts against that limit until
 * both sides have ended it; {@see TH2Stream::setResetAfterEnd()} ends one whose response is
 * complete while the client still sends.  {@see close()} frees the nghttp2 session and raises {@see onClose} for the streams
 * still open; every later nghttp2 call throws `http2_session_closed`.
 *
 * Flow control is nghttp2's by default: it reopens the peer's window as DATA arrives, so a stream
 * buffers whatever the peer sends until the application reads it.
 * {@see TH2Options::setConsumeOnRead()} reopens the window as the application reads instead, which
 * bounds each stream's buffer at the window.  {@see TH2Options::setNoAutoWindowUpdate()} leaves the
 * accounting to the application ({@see consume()}).
 *
 * Event handlers run inside nghttp2 callbacks, where PHP cannot throw.  A Throwable raised there
 * (by a handler or by this class) is held while nghttp2 finishes the {@see receive()} or
 * {@see send()} call, and the first one is then rethrown from that call; the session stays usable.
 * A failure inside the data provider resets only its stream.  A {@see close()} from inside a
 * handler takes effect at once for the PHP side and frees the nghttp2 session once the call returns.
 *
 * The session is busy while nghttp2 executes a call on it.  {@see receive()} throws
 * `http2_session_busy` while any nghttp2 call on the session is in progress, and {@see send()}
 * throws it while another send() is in progress: a nested call would re-enter nghttp2's state
 * machine.  PHP permits a fiber switch inside an FFI callback.  A handler that waits on the PRADO
 * event loop (`TAsync::sleep()`, `TFuture::await()`, a `TAsyncStream` read) therefore leaves
 * nghttp2 mid-call while the loop runs, and the loop's next receive() on the session is such a
 * re-entry.  A handler that waits hands the work to {@see \Prado\IO\Async\TAsync::run()} and
 * returns; the fiber resumes from the loop outside any nghttp2 call and may then respond, write,
 * and end the stream.
 *
 * {@see pump()} drives the session over a `TAsyncStream` transport on the loop: it drains
 * {@see send()}, writes what the transport accepts, waits until the transport is readable, accepts
 * more output, or a submit queued output, and feeds what it reads to {@see receive()}.  It returns
 * once the session no longer wants I/O or a cancellation token ends it, and closes the session
 * when the transport ends or a read or write on it fails.  Every submit ({@see respond()},
 * {@see resumeStream()} behind a stream write, {@see goaway()}, ...) wakes a waiting pump, so a
 * worker fiber's response reaches the wire without the caller flushing.
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
 *  - onResponse: a client request's final (non-1xx) response headers arrived.
 *  - onInformationalResponse: a client received a 1xx informational response (100, 103, ...).
 *  - onTrailers: a trailing header block arrived (a second HEADERS on the stream): request trailers
 *    on the server, response trailers (no `:status`) on the client.
 *  - onData: DATA arrived on a stream (read it via the stream).
 *  - onClose: a stream closed: nghttp2 closed it (completion, a reset), or the session closed with
 *    the stream still open.
 *
 * Diagnostic events ('on' prefix), raised with the session as sender:
 *  - onFrameSent / onFrameNotSent / onInvalidFrame: an array of `type`, `streamId`, `flags` (and an
 *    nghttp2 `error` code for the latter two).  onInvalidFrame also reports a header block over the
 *    header list limit, with `error` {@see TNgHttp2::ERR_TEMPORAL_CALLBACK_FAILURE}.
 *  - onSessionError: nghttp2's message string for a session-level error.
 *
 * @author Brad Anderson <belisoful@icloud.com>
 * @since 0.9.0
 * @see https://www.rfc-editor.org/rfc/rfc9113.html
 * @see https://www.rfc-editor.org/rfc/rfc8441.html
 */
class TH2Session extends TComponent
{
	/**
	 * @var int The largest header list a session accepts in one header block when
	 *   {@see TH2Options::setMaxHeaderListSize()} sets none: 64 KiB.
	 * @since 1.4.0
	 */
	public const DEFAULT_MAX_HEADER_LIST_SIZE = 65536;

	/**
	 * @var int The `SETTINGS_MAX_CONCURRENT_STREAMS` value a server advertises when the caller of
	 *   {@see submitSettings()} gives none: the streams a client may have open at once.
	 * @since 1.5.0
	 */
	public const DEFAULT_MAX_CONCURRENT_STREAMS = 100;

	/** @var ?\FFI\CData The nghttp2 callbacks, built once and shared by all sessions. */
	private static ?\FFI\CData $_callbacks = null;

	/** @var ?\FFI\CData The data provider, built once and shared by all sessions. */
	private static ?\FFI\CData $_provider = null;

	/** @var ?\FFI The FFI instance the shared callbacks were built with. */
	private static ?\FFI $_callbacksFfi = null;

	/** @var array<int, mixed> The shared callback closures, kept alive for the process. */
	private static array $_sharedRefs = [];

	/** @var array<int, \WeakReference<TH2Session>> Live sessions keyed by routing id (nghttp2 user_data), held weakly so an unreferenced session is collected and freed. */
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

	/** @var array<int, int> The header list size accumulated per stream during reception (RFC 9113 §6.5.2). */
	private array $_pendingHeaderSizes = [];

	/** @var bool Whether the session has been closed. */
	private bool $_closed = false;

	/** @var ?TH2Options The tuning options applied at creation. */
	private ?TH2Options $_options = null;

	/** @var ?\FFI\CData The data provider this session submits with (the shared one at creation). */
	private ?\FFI\CData $_dataProvider = null;

	/** @var array<int, mixed> The callback closures this session uses, held alive for its life. */
	private array $_refs = [];

	/** @var int The nesting depth of nghttp2 calls in progress that may run callbacks. */
	private int $_nativeDepth = 0;

	/** @var bool Whether a send() is draining nghttp2's output. */
	private bool $_sending = false;

	/** @var bool Whether close() ran inside a callback and the nghttp2 session waits to be freed. */
	private bool $_closePending = false;

	/** @var ?\Throwable The first Throwable a callback raised during the current nghttp2 call. */
	private ?\Throwable $_pendingThrowable = null;

	/** @var string Bytes a send() produced before it rethrew a callback's Throwable; the next send() returns them first. */
	private string $_unsentOutput = '';

	/** @var bool Whether a fiber is inside {@see pump()}. */
	private bool $_pumping = false;

	/** @var ?Suspension The pump's fiber while it waits for the transport or for output to send. */
	private ?Suspension $_pumpWaiter = null;

	/**
	 * @var string The pump wake reason: the transport is readable.
	 * @since 1.3.0
	 */
	private const PUMP_READABLE = 'readable';

	/**
	 * @var string The pump wake reason: the transport accepts more of the held output.
	 * @since 1.3.0
	 */
	private const PUMP_WRITABLE = 'writable';

	/**
	 * @var string The pump wake reason: a submit queued output, or the session closed.
	 * @since 1.3.0
	 */
	private const PUMP_OUTPUT = 'output';

	/**
	 * @var int The bytes the pump reads from the transport per round.
	 * @since 1.3.0
	 */
	private const PUMP_READ_LENGTH = 65536;

	/**
	 * Creates a server or client session over libnghttp2.
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

	/**
	 * Returns the raw bound nghttp2 FFI instance.
	 * @return \FFI The bound nghttp2 FFI instance.
	 */
	protected function getFfiDirect(): \FFI
	{
		return $this->_ffi;
	}

	/**
	 * Sets the raw bound nghttp2 FFI instance.
	 * @param \FFI $value The bound nghttp2 FFI instance.
	 */
	protected function setFfiDirect(\FFI $value): void
	{
		$this->_ffi = $value;
	}

	/**
	 * Returns the raw server-side flag.
	 * @return bool Whether this is the server side.
	 */
	protected function getIsServerDirect(): bool
	{
		return $this->_isServer;
	}

	/**
	 * Sets the raw server-side flag.
	 * @param bool $value Whether this is the server side.
	 */
	protected function setIsServerDirect(bool $value): void
	{
		$this->_isServer = $value;
	}

	/**
	 * Returns the raw nghttp2_session pointer.
	 * @return \FFI\CData The nghttp2_session pointer.
	 */
	protected function getSessionDirect(): \FFI\CData
	{
		return $this->_session;
	}

	/**
	 * Sets the raw nghttp2_session pointer.
	 * @param \FFI\CData $value The nghttp2_session pointer.
	 */
	protected function setSessionDirect(\FFI\CData $value): void
	{
		$this->_session = $value;
	}

	/**
	 * Returns the raw routing id.
	 * @return int The routing id.
	 */
	protected function getIdDirect(): int
	{
		return $this->_id;
	}

	/**
	 * Sets the raw routing id.
	 * @param int $value The routing id.
	 */
	protected function setIdDirect(int $value): void
	{
		$this->_id = $value;
	}

	/**
	 * Returns the raw nghttp2 user_data cell.
	 * @return ?\FFI\CData The nghttp2 user_data cell.
	 */
	protected function getUserDataDirect(): ?\FFI\CData
	{
		return $this->_userData;
	}

	/**
	 * Sets the raw nghttp2 user_data cell.
	 * @param ?\FFI\CData $value The nghttp2 user_data cell.
	 */
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

	/**
	 * Sets the raw open stream map.
	 * @param array<int, TH2Stream> $value The open streams.
	 */
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

	/**
	 * Sets the raw pending header map.
	 * @param array<int, array<string, string>> $value The pending headers.
	 */
	protected function setPendingHeadersDirect(array $value): void
	{
		$this->_pendingHeaders = $value;
	}

	/**
	 * Returns the raw pending header list sizes by reference, for in-place mutation.
	 * @return array<int, int> The accumulated size per stream, by reference.
	 * @since 1.4.0
	 */
	protected function &getPendingHeaderSizesDirect(): array
	{
		return $this->_pendingHeaderSizes;
	}

	/**
	 * Sets the raw pending header list sizes.
	 * @param array<int, int> $value The accumulated size per stream.
	 * @since 1.4.0
	 */
	protected function setPendingHeaderSizesDirect(array $value): void
	{
		$this->_pendingHeaderSizes = $value;
	}

	/**
	 * Returns the raw closed flag.
	 * @return bool Whether the session is closed.
	 */
	protected function getClosedDirect(): bool
	{
		return $this->_closed;
	}

	/**
	 * Sets the raw closed flag.
	 * @param bool $value Whether the session is closed.
	 */
	protected function setClosedDirect(bool $value): void
	{
		$this->_closed = $value;
	}

	/**
	 * Returns the raw tuning options.
	 * @return ?TH2Options The tuning options.
	 */
	protected function getOptionsDirect(): ?TH2Options
	{
		return $this->_options;
	}

	/**
	 * Sets the raw tuning options.
	 * @param ?TH2Options $value The tuning options.
	 */
	protected function setOptionsDirect(?TH2Options $value): void
	{
		$this->_options = $value;
	}

	/**
	 * Returns the raw data provider.
	 * @return ?\FFI\CData The data provider this session submits with.
	 * @since 1.0.0
	 */
	protected function getDataProviderDirect(): ?\FFI\CData
	{
		return $this->_dataProvider;
	}

	/**
	 * Sets the raw data provider.
	 * @param ?\FFI\CData $value The data provider this session submits with.
	 * @since 1.0.0
	 */
	protected function setDataProviderDirect(?\FFI\CData $value): void
	{
		$this->_dataProvider = $value;
	}

	/**
	 * Returns the session's callback closures by reference (held alive for the session's life).
	 * @return array<int, mixed> The callback closures, by reference.
	 * @since 1.0.0
	 */
	protected function &getRefsDirect(): array
	{
		return $this->_refs;
	}

	/**
	 * Sets the raw callback closures.
	 * @param array<int, mixed> $value The callback closures.
	 * @since 1.0.0
	 */
	protected function setRefsDirect(array $value): void
	{
		$this->_refs = $value;
	}

	/**
	 * Returns the raw nghttp2 call depth.
	 * @return int The nesting depth of nghttp2 calls in progress.
	 * @since 1.2.0
	 */
	protected function getNativeDepthDirect(): int
	{
		return $this->_nativeDepth;
	}

	/**
	 * Sets the raw nghttp2 call depth.
	 * @param int $value The nesting depth of nghttp2 calls in progress.
	 * @since 1.2.0
	 */
	protected function setNativeDepthDirect(int $value): void
	{
		$this->_nativeDepth = $value;
	}

	/**
	 * Returns the raw sending flag.
	 * @return bool Whether a send() is draining nghttp2's output.
	 * @since 1.3.0
	 */
	protected function getSendingDirect(): bool
	{
		return $this->_sending;
	}

	/**
	 * Sets the raw sending flag.
	 * @param bool $value Whether a send() is draining nghttp2's output.
	 * @since 1.3.0
	 */
	protected function setSendingDirect(bool $value): void
	{
		$this->_sending = $value;
	}

	/**
	 * Returns the raw close-pending flag.
	 * @return bool Whether the nghttp2 session waits to be freed after the current call.
	 * @since 1.2.0
	 */
	protected function getClosePendingDirect(): bool
	{
		return $this->_closePending;
	}

	/**
	 * Sets the raw close-pending flag.
	 * @param bool $value Whether the nghttp2 session waits to be freed after the current call.
	 * @since 1.2.0
	 */
	protected function setClosePendingDirect(bool $value): void
	{
		$this->_closePending = $value;
	}

	/**
	 * Returns the raw held Throwable.
	 * @return ?\Throwable The first Throwable a callback raised during the current nghttp2 call.
	 * @since 1.2.0
	 */
	protected function getPendingThrowableDirect(): ?\Throwable
	{
		return $this->_pendingThrowable;
	}

	/**
	 * Sets the raw held Throwable.
	 * @param ?\Throwable $value The Throwable to rethrow once the nghttp2 call returns.
	 * @since 1.2.0
	 */
	protected function setPendingThrowableDirect(?\Throwable $value): void
	{
		$this->_pendingThrowable = $value;
	}

	/**
	 * Returns the raw unsent output.
	 * @return string The bytes produced by a send() that rethrew, still to be returned.
	 * @since 1.2.0
	 */
	protected function getUnsentOutputDirect(): string
	{
		return $this->_unsentOutput;
	}

	/**
	 * Sets the raw unsent output.
	 * @param string $value The bytes produced by a send() that rethrew, still to be returned.
	 * @since 1.2.0
	 */
	protected function setUnsentOutputDirect(string $value): void
	{
		$this->_unsentOutput = $value;
	}

	/**
	 * Returns the raw pumping flag.
	 * @return bool Whether a fiber is inside {@see pump()}.
	 * @since 1.3.0
	 */
	protected function getPumpingDirect(): bool
	{
		return $this->_pumping;
	}

	/**
	 * Sets the raw pumping flag.
	 * @param bool $value Whether a fiber is inside {@see pump()}.
	 * @since 1.3.0
	 */
	protected function setPumpingDirect(bool $value): void
	{
		$this->_pumping = $value;
	}

	/**
	 * Returns the raw pump's suspension.
	 * @return ?Suspension The pump's suspension while it waits, or null.
	 * @since 1.3.0
	 */
	protected function getPumpWaiterDirect(): ?Suspension
	{
		return $this->_pumpWaiter;
	}

	/**
	 * Sets the raw pump's suspension.
	 * @param ?Suspension $value The pump's suspension while it waits, or null.
	 * @since 1.3.0
	 */
	protected function setPumpWaiterDirect(?Suspension $value): void
	{
		$this->_pumpWaiter = $value;
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

		// Each closure resolves its session through the FFI instance it was built with, so a later
		// TNgHttp2::setLibraryPath() re-bind (or a failed one) cannot reach into a callback.  Every
		// body runs through guarded(): PHP cannot throw out of an FFI callback (it is a fatal error),
		// so a Throwable is held and rethrown by receive()/send() once nghttp2 returns.
		$onBeginHeaders = static function ($session, $frame, $userData) use ($ffi) {
			$self = self::fromUserData($ffi, $userData);
			if ($self === null || $frame->type === TNgHttp2::FRAME_PUSH_PROMISE) {
				return 0;   // a pushed request's header block belongs to no TH2Stream
			}
			$streamId = $frame->stream_id;
			return $self->guarded(fn () => $self->handleBeginHeaders($streamId));
		};
		$onHeader = static function ($session, $frame, $name, $namelen, $value, $valuelen, $flags, $userData) use ($ffi) {
			$self = self::fromUserData($ffi, $userData);
			if ($self === null || $frame->type === TNgHttp2::FRAME_PUSH_PROMISE) {
				return 0;
			}
			$streamId = $frame->stream_id;
			$name = \FFI::string($name, $namelen);
			$value = \FFI::string($value, $valuelen);
			// A header list over the limit, or a failure here, resets this stream and keeps the session.
			return $self->guarded(fn () => $self->handleHeader($streamId, $name, $value), TNgHttp2::ERR_TEMPORAL_CALLBACK_FAILURE);
		};
		$onFrameRecv = static function ($session, $frame, $userData) use ($ffi) {
			$self = self::fromUserData($ffi, $userData);
			return $self === null ? 0 : $self->guarded(fn () => $self->handleFrameRecv($frame));
		};
		$onDataChunk = static function ($session, $flags, $streamId, $data, $len, $userData) use ($ffi) {
			$self = self::fromUserData($ffi, $userData);
			if ($self === null) {
				return 0;
			}
			$bytes = \FFI::string($data, $len);
			return $self->guarded(fn () => $self->handleDataChunk($streamId, $bytes));
		};
		$onStreamClose = static function ($session, $streamId, $errorCode, $userData) use ($ffi) {
			$self = self::fromUserData($ffi, $userData);
			return $self === null ? 0 : $self->guarded(fn () => $self->handleStreamClose($streamId));
		};

		$ffi->nghttp2_session_callbacks_set_on_begin_headers_callback($callbacks, $onBeginHeaders);
		$ffi->nghttp2_session_callbacks_set_on_header_callback($callbacks, $onHeader);
		$ffi->nghttp2_session_callbacks_set_on_frame_recv_callback($callbacks, $onFrameRecv);
		$ffi->nghttp2_session_callbacks_set_on_data_chunk_recv_callback($callbacks, $onDataChunk);
		$ffi->nghttp2_session_callbacks_set_on_stream_close_callback($callbacks, $onStreamClose);

		$provider = $ffi->new('nghttp2_data_provider2');
		$provideData = static function ($session, $streamId, $buf, $length, $dataFlags, $source, $userData) use ($ffi) {
			$self = self::fromUserData($ffi, $userData);
			if ($self === null) {
				$dataFlags[0] = TNgHttp2::DATA_FLAG_EOF;
				return 0;
			}
			// A failure here resets this stream (RST_STREAM INTERNAL_ERROR) and keeps the session.
			return $self->guarded(fn () => $self->provideData($streamId, $buf, $length, $dataFlags), TNgHttp2::ERR_TEMPORAL_CALLBACK_FAILURE);
		};
		$provider->read_callback = $provideData;

		$onFrameSend = static function ($session, $frame, $userData) use ($ffi) {
			$self = self::fromUserData($ffi, $userData);
			$info = self::frameInfo($frame);
			return $self === null ? 0 : $self->guarded(fn () => $self->handleFrameSent($info));
		};
		$onFrameNotSend = static function ($session, $frame, $libError, $userData) use ($ffi) {
			$self = self::fromUserData($ffi, $userData);
			$info = self::frameInfo($frame) + ['error' => $libError];
			return $self === null ? 0 : $self->guarded(fn () => $self->onFrameNotSent($info));
		};
		$onInvalidFrame = static function ($session, $frame, $libError, $userData) use ($ffi) {
			$self = self::fromUserData($ffi, $userData);
			$info = self::frameInfo($frame) + ['error' => $libError];
			return $self === null ? 0 : $self->guarded(fn () => $self->handleInvalidFrame($info));
		};
		$onError = static function ($session, $libError, $msg, $len, $userData) use ($ffi) {
			$self = self::fromUserData($ffi, $userData);
			// FFI converts the const char* `msg` to a PHP string; some builds yield CData instead.
			$message = is_string($msg) ? $msg : \FFI::string($msg, $len);
			return $self === null ? 0 : $self->guarded(fn () => $self->onSessionError($message));
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
	 * Resolves the session from a callback's nghttp2 `user_data` (an int64 routing id).  The
	 * registry holds sessions weakly, so a collected session resolves to null.
	 * @param \FFI $ffi The FFI instance the callbacks were built with.
	 * @param ?\FFI\CData $userData The user_data pointer passed by nghttp2.
	 * @return ?TH2Session The owning session, or null.
	 */
	private static function fromUserData(\FFI $ffi, ?\FFI\CData $userData): ?TH2Session
	{
		if ($userData === null) {
			return null;
		}
		$id = $ffi->cast('int64_t*', $userData)[0];
		return (self::$_registry[$id] ?? null)?->get();
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
		// Capture the provider and closures this session was built with, so a later
		// TNgHttp2::setLibraryPath() rebuild (new FFI, new shared statics) cannot strand it on a
		// foreign provider: request()/respond() submit with this captured provider, not the static.
		$this->setDataProviderDirect(self::$_provider);
		$this->setRefsDirect(self::$_sharedRefs);

		$this->setIdDirect(self::$_nextId++);
		self::$_registry[$this->getIdDirect()] = \WeakReference::create($this);
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
		if ($options->getNoAutoWindowUpdate() || $options->getConsumeOnRead()) {
			$ffi->nghttp2_option_set_no_auto_window_update($option, 1);
		}
		return $option;
	}

	// =========================================================================
	// Settings API
	// =========================================================================

	/**
	 * Submits connection SETTINGS (e.g. {@see TNgHttp2::SETTINGS_ENABLE_CONNECT_PROTOCOL}).  A client
	 * adds `SETTINGS_ENABLE_PUSH => 0` unless the caller gives that id.  A server adds
	 * `SETTINGS_MAX_CONCURRENT_STREAMS => ` {@see DEFAULT_MAX_CONCURRENT_STREAMS} unless the caller
	 * gives that id: nghttp2's own default is no limit, so a client could hold any number of streams
	 * open.  nghttp2 enforces the limit: a request over it raises {@see onInvalidFrame} and no
	 * {@see onRequest}, is refused (RST_STREAM REFUSED_STREAM) until the client has acknowledged the
	 * SETTINGS, and ends the connection (GOAWAY PROTOCOL_ERROR) after that.  The session's
	 * {@see getMaxHeaderListSize() header list limit} is advertised as `SETTINGS_MAX_HEADER_LIST_SIZE`
	 * unless the caller gives that id or the limit is off.  The advertised value is advisory to the
	 * peer; the limit this session enforces comes from {@see TH2Options::setMaxHeaderListSize()}.
	 * @param array<int, int> $settings The settings as id => value.
	 * @throws THttp2Exception When the submission fails.
	 */
	public function submitSettings(array $settings): void
	{
		$this->assertOpen();
		if (!$this->getIsServerDirect() && !array_key_exists(TNgHttp2::SETTINGS_ENABLE_PUSH, $settings)) {
			// A pushed stream has no TH2Stream and is discarded, so a client declines push (RFC 9113 §8.4).
			$settings[TNgHttp2::SETTINGS_ENABLE_PUSH] = 0;
		}
		if ($this->getIsServerDirect() && !array_key_exists(TNgHttp2::SETTINGS_MAX_CONCURRENT_STREAMS, $settings)) {
			$settings[TNgHttp2::SETTINGS_MAX_CONCURRENT_STREAMS] = self::DEFAULT_MAX_CONCURRENT_STREAMS;
		}
		$limit = $this->getMaxHeaderListSize();
		if ($limit > 0 && !array_key_exists(TNgHttp2::SETTINGS_MAX_HEADER_LIST_SIZE, $settings)) {
			$settings[TNgHttp2::SETTINGS_MAX_HEADER_LIST_SIZE] = min($limit, 0xFFFFFFFF);
		}
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
		$this->outputChanged();
	}

	/**
	 * Returns the largest header list this session accepts in one received header block: request
	 * headers, response headers, or trailers.  The size is the sum over the fields of name length,
	 * value length, and 32 (RFC 9113 §6.5.2).  A block over the limit is dropped, its stream is reset
	 * (RST_STREAM INTERNAL_ERROR), and {@see onInvalidFrame} is raised; the session stays usable.
	 * nghttp2 does not enforce `SETTINGS_MAX_HEADER_LIST_SIZE`, and HPACK lets a few kilobytes on the
	 * wire decode to hundreds of megabytes, so the session enforces the limit itself.
	 * @return int The limit in bytes ({@see TH2Options::setMaxHeaderListSize()}, else
	 *   {@see DEFAULT_MAX_HEADER_LIST_SIZE}), or 0 for no limit.
	 * @since 1.4.0
	 */
	public function getMaxHeaderListSize(): int
	{
		return $this->getOptionsDirect()?->getMaxHeaderListSize() ?? self::DEFAULT_MAX_HEADER_LIST_SIZE;
	}

	/**
	 * Returns a setting value the peer advertised (e.g. {@see TNgHttp2::SETTINGS_ENABLE_CONNECT_PROTOCOL}).
	 * @param int $id A {@see TNgHttp2} `SETTINGS_*` id.
	 * @throws THttp2Exception When the session is closed.
	 * @return int The negotiated value.
	 */
	public function getRemoteSetting(int $id): int
	{
		$this->assertOpen();
		return $this->getFfiDirect()->nghttp2_session_get_remote_settings($this->getSessionDirect(), $id);
	}

	/**
	 * Returns a setting value this side advertised.
	 * @param int $id A {@see TNgHttp2} `SETTINGS_*` id.
	 * @throws THttp2Exception When the session is closed.
	 * @return int The local value.
	 */
	public function getLocalSetting(int $id): int
	{
		$this->assertOpen();
		return $this->getFfiDirect()->nghttp2_session_get_local_settings($this->getSessionDirect(), $id);
	}

	/**
	 * Returns the peer's remaining flow-control window for a stream: the DATA bytes nghttp2 may
	 * still send on it before the peer's next WINDOW_UPDATE.  The connection window
	 * ({@see getRemoteWindowSize()}) caps it as well.  A stream nghttp2 does not have yields -1: a
	 * {@see request()} whose HEADERS is not sent yet, or a stream that has closed.
	 * {@see TH2Stream::writeAsync()} uses it as the default high-water mark.
	 * @param int $streamId The stream identifier.
	 * @throws THttp2Exception When the session is closed.
	 * @return int The window in bytes, or -1 for an unknown stream.
	 * @since 1.3.0
	 */
	public function getStreamRemoteWindowSize(int $streamId): int
	{
		$this->assertOpen();
		return $this->getFfiDirect()->nghttp2_session_get_stream_remote_window_size($this->getSessionDirect(), $streamId);
	}

	/**
	 * Returns the peer's remaining connection-level flow-control window: the DATA bytes nghttp2
	 * may still send across all streams before the peer's next WINDOW_UPDATE on stream 0.
	 * @throws THttp2Exception When the session is closed.
	 * @return int The window in bytes.
	 * @since 1.3.0
	 */
	public function getRemoteWindowSize(): int
	{
		$this->assertOpen();
		return $this->getFfiDirect()->nghttp2_session_get_remote_window_size($this->getSessionDirect());
	}

	// =========================================================================
	// Stream API
	// =========================================================================

	/**
	 * Opens a client request stream with the given headers (including pseudo-headers).  Names are
	 * lowercased and pseudo-headers go first; the stream keeps the headers in that form.
	 * @param array<string, string> $headers The request headers (e.g. ':method' => 'CONNECT').
	 * @throws THttp2Exception When a name repeats or is not valid, a value is not valid, or the
	 *   request cannot be submitted.
	 * @return TH2Stream The new stream.
	 */
	public function request(array $headers): TH2Stream
	{
		$this->assertOpen();
		$headers = $this->normalizeHeaders($headers);
		$keep = [];
		$nva = $this->buildHeaders($headers, $keep);
		$streamId = $this->getFfiDirect()->nghttp2_submit_request2($this->getSessionDirect(), null, $nva, count($headers), \FFI::addr($this->getDataProviderDirect()), null);
		if ($streamId < 0) {
			throw new THttp2Exception('http2_session_failed', TNgHttp2::strerror($streamId));
		}
		$stream = Prado::createComponent(TH2Stream::class, $this, $streamId, $headers);
		$stream->markHeadersSubmitted();
		$streams = &$this->getStreamsDirect();
		$streams[$streamId] = $stream;
		$this->outputChanged();
		return $stream;
	}

	/**
	 * Sends response headers on a server stream, accepting it (e.g. ':status' => '200').
	 * @param TH2Stream $stream The request stream to respond on.
	 * @param array<string, string> $headers The response headers.
	 * @throws THttp2Exception When a name repeats or is not valid, a value is not valid, or the
	 *   response cannot be submitted.
	 */
	public function respond(TH2Stream $stream, array $headers): void
	{
		$this->assertOpen();
		$headers = $this->normalizeHeaders($headers);
		$keep = [];
		$nva = $this->buildHeaders($headers, $keep);
		$result = $this->getFfiDirect()->nghttp2_submit_response2($this->getSessionDirect(), $stream->getStreamId(), $nva, count($headers), \FFI::addr($this->getDataProviderDirect()));
		if ($result !== 0) {
			throw new THttp2Exception('http2_session_failed', TNgHttp2::strerror($result));
		}
		$stream->markHeadersSubmitted();
		$this->outputChanged();
	}

	/**
	 * Submits a trailing header block on a stream, sent after the body as a HEADERS frame with
	 * END_STREAM.  Called by {@see TH2Stream::sendTrailers()}; the stream's data provider then ends
	 * the body without END_STREAM so the trailers carry it.
	 * @param int $streamId The stream identifier.
	 * @param array<string, string> $headers The trailing header name => value pairs.
	 * @throws THttp2Exception When a name repeats or is not valid, a value is not valid, or the
	 *   trailers cannot be submitted.
	 * @since 1.0.0
	 */
	public function submitTrailers(int $streamId, array $headers): void
	{
		$this->assertOpen();
		$headers = $this->normalizeHeaders($headers);
		$keep = [];
		$nva = $this->buildHeaders($headers, $keep);
		$result = $this->getFfiDirect()->nghttp2_submit_trailer($this->getSessionDirect(), $streamId, $nva, count($headers));
		if ($result !== 0) {
			throw new THttp2Exception('http2_session_failed', TNgHttp2::strerror($result));
		}
		$this->outputChanged();
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
		$this->assertOpen();
		$result = $this->getFfiDirect()->nghttp2_submit_rst_stream($this->getSessionDirect(), 0, $streamId, $errorCode);
		if ($result !== 0) {
			throw new THttp2Exception('http2_session_failed', TNgHttp2::strerror($result));
		}
		$this->outputChanged();
	}

	/**
	 * Resumes a deferred stream so nghttp2 pulls its newly queued outgoing DATA.  The return code is
	 * intentionally ignored: nghttp2 returns `NGHTTP2_ERR_INVALID_ARGUMENT` when the stream is not
	 * currently deferred, which is the normal case for a write to an already-active stream, not a
	 * failure.  Resuming is best-effort and idempotent, and a no-op on a closed session.
	 * @param int $streamId The stream identifier.
	 */
	public function resumeStream(int $streamId): void
	{
		if ($this->getClosedDirect()) {
			return;
		}
		$this->getFfiDirect()->nghttp2_session_resume_data($this->getSessionDirect(), $streamId);
		$this->outputChanged();
	}

	// =========================================================================
	// Transport I/O API
	// =========================================================================

	/**
	 * Feeds received transport bytes into the session, driving its callbacks and event handlers.
	 * The session must be idle: a call from inside a handler, or from the event loop or another
	 * fiber while a handler is suspended inside a callback, throws `http2_session_busy`.
	 * @param string $bytes The bytes read from the transport.
	 * @throws THttp2Exception When nghttp2 rejects the input, or `http2_session_busy` when an nghttp2
	 *   call on this session is in progress.
	 * @throws \Throwable The first Throwable an event handler raised while the bytes were processed;
	 *   the remaining frames were still processed and the session stays usable.
	 */
	public function receive(string $bytes): void
	{
		$this->assertOpen();
		if ($this->getNativeDepthDirect() > 0) {
			// nghttp2 is executing this session (a handler runs, or a fiber is suspended inside one):
			// a nested mem_recv would re-enter the inbound state machine with a frame half processed.
			throw new THttp2Exception('http2_session_busy', 'receive');
		}
		$length = strlen($bytes);
		if ($length === 0) {
			return;
		}
		$ffi = $this->getFfiDirect();
		// Owned (auto-freed): nghttp2_session_mem_recv2 consumes the input synchronously.
		$buffer = $ffi->new("uint8_t[$length]");
		\FFI::memcpy($buffer, $bytes, $length);
		$session = $this->getSessionDirect();
		$result = $this->nativeCall(fn () => $ffi->nghttp2_session_mem_recv2($session, $ffi->cast('uint8_t*', $buffer), $length));
		$this->rethrowPending();
		if ($result < 0) {
			throw new THttp2Exception('http2_session_recv_failed', TNgHttp2::strerror($result));
		}
	}

	/**
	 * Drains and returns all bytes the session has to send to the transport.  Stops early when a
	 * handler closed the session while the bytes were produced.  When a handler or the data provider
	 * threw, the bytes produced in this call are kept and returned first by the next send(), so the
	 * peer misses nothing.  A send() from inside a {@see receive()} handler is allowed; one from
	 * inside a send() handler (`onFrameSent`), or from the event loop or another fiber while such a
	 * handler is suspended, throws `http2_session_busy`.
	 * @throws THttp2Exception When nghttp2 fails to produce output (the bytes drained before the
	 *   failure are kept for the next send()), or `http2_session_busy` when another send() on this
	 *   session is in progress.
	 * @throws \Throwable The first Throwable an event handler or data provider raised while the
	 *   bytes were produced; the session stays usable.
	 * @return string The bytes to write to the transport ('' when none).
	 * @phpstan-impure
	 */
	public function send(): string
	{
		$this->assertOpen();
		if ($this->getSendingDirect()) {
			// nghttp2 is producing this session's output (an onFrameSent handler runs, or a fiber is
			// suspended inside one): a nested mem_send would report the frame in progress twice.
			throw new THttp2Exception('http2_session_busy', 'send');
		}
		$ffi = $this->getFfiDirect();
		$session = $this->getSessionDirect();
		$output = $this->getUnsentOutputDirect();
		$this->setUnsentOutputDirect('');
		$this->setSendingDirect(true);
		try {
			while (!$this->getClosedDirect()) {
				$pointer = $ffi->new('uint8_t*');
				$count = $this->nativeCall(fn () => $ffi->nghttp2_session_mem_send2($session, \FFI::addr($pointer)));
				if ($count < 0) {
					$this->setUnsentOutputDirect($output);   // what was drained before the failure is not lost
					throw new THttp2Exception('http2_session_send_failed', TNgHttp2::strerror($count));
				}
				if ($count === 0) {
					break;
				}
				$output .= \FFI::string($pointer, $count);
			}
		} finally {
			$this->setSendingDirect(false);
		}
		if ($this->getPendingThrowableDirect() !== null && $this->getNativeDepthDirect() === 0) {
			$this->setUnsentOutputDirect($output);
			$this->rethrowPending();
		}
		return $output;
	}

	/**
	 * Runs an nghttp2 call that may run callbacks.  Tracks the nesting so that a {@see close()}
	 * requested inside a callback frees the nghttp2 session only after the outermost call returns.
	 * @param \Closure $call The nghttp2 call.
	 * @return mixed The call's result.
	 * @since 1.2.0
	 */
	private function nativeCall(\Closure $call): mixed
	{
		$this->setNativeDepthDirect($this->getNativeDepthDirect() + 1);
		try {
			$result = $call();
		} finally {
			$this->setNativeDepthDirect($this->getNativeDepthDirect() - 1);
		}
		if ($this->getNativeDepthDirect() === 0 && $this->getClosePendingDirect()) {
			$this->freeSession();
		}
		return $result;
	}

	/**
	 * Rethrows the first Throwable a callback held back, once no nghttp2 call is in progress.
	 * @throws \Throwable The held Throwable.
	 * @since 1.2.0
	 */
	private function rethrowPending(): void
	{
		$pending = $this->getPendingThrowableDirect();
		if ($pending !== null && $this->getNativeDepthDirect() === 0) {
			$this->setPendingThrowableDirect(null);
			throw $pending;
		}
	}

	/**
	 * Runs a callback body where PHP cannot throw (inside an nghttp2 callback: a Throwable escaping
	 * one is a fatal error).  A Throwable is held, the first one per nghttp2 call, for
	 * {@see rethrowPending()} to rethrow, and $failure is returned to nghttp2 instead.
	 * @param \Closure $body The callback body; an int result is returned to nghttp2 (void → 0).
	 * @param int $failure The nghttp2 return code on a Throwable. Default 0 (continue).
	 * @return int The code returned to nghttp2.
	 * @since 1.2.0
	 */
	private function guarded(\Closure $body, int $failure = 0): int
	{
		try {
			return $body() ?? 0;
		} catch (\Throwable $e) {
			if ($this->getPendingThrowableDirect() === null) {
				$this->setPendingThrowableDirect($e);
			}
			return $failure;
		}
	}

	/**
	 * Indicates whether the session still wants to read from or write to the transport.  An event
	 * loop ends and closes the connection once this is false.  A closed session wants none.
	 * @return bool Whether the session has pending I/O.
	 */
	public function wantsIo(): bool
	{
		if ($this->getClosedDirect()) {
			return false;
		}
		$ffi = $this->getFfiDirect();
		return $ffi->nghttp2_session_want_read($this->getSessionDirect()) !== 0
			|| $ffi->nghttp2_session_want_write($this->getSessionDirect()) !== 0;
	}

	/**
	 * Drives the session over a transport on the PRADO event loop.  Each round drains {@see send()}
	 * and writes what the transport accepts at once (its stream is non-blocking); the rest is held
	 * for the next round, so the pump keeps reading while the peer's socket buffer is full.  The
	 * pump returns once nothing is held and the session no longer {@see wantsIo() wants I/O} (a
	 * GOAWAY exchange has completed).  Otherwise it suspends the current fiber until the transport is
	 * readable, the transport accepts more of the held output, or a submit queued output
	 * ({@see respond()}, a {@see TH2Stream::write()} from another fiber, {@see goaway()}, ...), and
	 * feeds what it reads to {@see receive()}.  End of stream on the transport, or a read or write
	 * that fails (a peer that resets the connection), closes the session; the caller closes the
	 * transport.  Event handlers run inside receive(); one that waits hands the work to
	 * `TAsync::run()`, and that fiber's later respond or write wakes the pump.  Call it from a worker
	 * fiber (`TAsync::run(fn () => $session->pump($transport))`), one per session, or from the main
	 * fiber, where it runs the loop until it returns.  The transport's inner stream must own an open
	 * PHP stream resource, such as a `TSocketStream`.  A Throwable an event handler raised, or a
	 * cancelled token, leaves the pump with the session still usable and the held output kept for
	 * the next {@see send()}, so the pump can be called again.  An nghttp2 failure in receive() (a
	 * peer that does not speak HTTP/2) leaves the pump as a `THttp2Exception` with the session still
	 * open; the caller closes it.  When the pump closes the session, {@see onClose} is raised for the
	 * streams still open.
	 * @param TAsyncStream $transport The transport over the connection.
	 * @param ?TCancellationToken $token A token whose cancellation ends the pump; the session stays open.
	 * @throws THttp2Exception `http2_pump_in_progress` when a fiber already pumps the session,
	 *   `http2_pump_transport` when the transport owns no open resource, `http2_session_closed`
	 *   when the session is closed, or an nghttp2 failure.
	 * @throws TCancelledException When the token is cancelled.
	 * @throws \Throwable What an event handler raised.
	 * @since 1.3.0
	 */
	public function pump(TAsyncStream $transport, ?TCancellationToken $token = null): void
	{
		$this->assertOpen();
		if ($this->getPumpingDirect()) {
			throw new THttp2Exception('http2_pump_in_progress');
		}
		$inner = $transport->getStream();
		if (!($inner instanceof IResource) || !is_resource($inner->getResource())) {
			throw new THttp2Exception('http2_pump_transport');
		}
		$this->setPumpingDirect(true);
		$held = '';   // output the transport has not accepted yet
		try {
			while (!$this->getClosedDirect()) {
				// Drain nghttp2 in full: a submit made during a handler is picked up in this round.
				while (!$this->getClosedDirect() && ($output = $this->send()) !== '') {
					$held .= $output;
				}
				if ($held !== '') {
					$held = $this->writeTransport($inner, $held);
					if ($this->getClosedDirect()) {
						return;   // the write failed: the connection is gone
					}
				}
				if ($held === '' && !$this->wantsIo()) {
					return;   // a GOAWAY exchange has completed
				}
				if ($this->awaitTransport($inner, $held !== '', $token) !== self::PUMP_READABLE) {
					continue;   // the transport accepts more output, or a submit queued some: write it
				}
				$bytes = $this->readTransport($inner);
				if ($bytes !== '') {
					$this->receive($bytes);
				}
			}
		} finally {
			if ($held !== '' && !$this->getClosedDirect()) {
				// Cancelled, or a handler threw: the next send() returns the held bytes first.
				$this->setUnsentOutputDirect($held . $this->getUnsentOutputDirect());
			}
			$this->setPumpingDirect(false);
		}
	}

	/**
	 * Writes what the transport accepts now and returns the rest.  The transport's stream is
	 * non-blocking (`TAsyncStream` sets it so), so a full socket buffer returns a short count.  A
	 * write that throws closes the session: the peer reset the connection.  `fwrite()` reports that
	 * as an E_NOTICE, which the PRADO error handler turns into a `TPhpErrorException` and the
	 * stream otherwise turns into a `RuntimeException`.
	 * @param IResource&StreamInterface $inner The transport's inner stream.
	 * @param string $output The bytes to write.
	 * @return string The bytes the transport did not accept ('' when all were written).
	 * @phpstan-impure
	 * @since 1.3.0
	 */
	private function writeTransport(IResource&StreamInterface $inner, string $output): string
	{
		try {
			$written = $inner->write($output);
		} catch (\Exception $e) {
			$this->close();
			return '';
		}
		return $written >= strlen($output) ? '' : substr($output, $written);
	}

	/**
	 * Reads what the transport has now.  The loop reported the transport readable, so a
	 * non-blocking read returns at once: bytes, or '' with {@see StreamInterface::eof()} at end of
	 * stream, or '' without it (TLS bookkeeping).  End of stream, or a read that throws (a peer
	 * reset, which `fread()` reports as a failure without an E_NOTICE), closes the session.
	 * @param IResource&StreamInterface $inner The transport's inner stream.
	 * @return string The bytes read ('' when none, or when the session was closed).
	 * @phpstan-impure
	 * @since 1.3.0
	 */
	private function readTransport(IResource&StreamInterface $inner): string
	{
		try {
			$bytes = $inner->read(self::PUMP_READ_LENGTH);
		} catch (\Exception $e) {
			$this->close();   // a failed read (a peer reset): the connection is gone
			return '';
		}
		if ($bytes === '' && $inner->eof()) {
			$this->close();   // end of stream: the peer is gone
		}
		return $bytes;
	}

	/**
	 * Suspends the pump's fiber until the transport is readable, the transport is writable (when
	 * output is held), a submit queues output, the session closes, or the token is cancelled.
	 * @param IResource $resource The transport's inner stream, owning the resource to watch.
	 * @param bool $writable Whether to wake when the transport accepts output.
	 * @param ?TCancellationToken $token A token whose cancellation ends the wait.
	 * @throws TCancelledException When the token is cancelled.
	 * @return string The wake reason: {@see PUMP_READABLE}, {@see PUMP_WRITABLE}, or {@see PUMP_OUTPUT}.
	 * @since 1.3.0
	 */
	private function awaitTransport(IResource $resource, bool $writable, ?TCancellationToken $token): string
	{
		$token?->throwIfCancelled();
		$loop = TAsync::getLoop();
		$suspension = $loop->suspension();
		$this->setPumpWaiterDirect($suspension);
		$ready = fn (string $reason): \Closure => function (string $id) use ($loop, $reason): void {
			$loop->cancel($id);
			$this->wakePump($reason);
		};
		$watchers = [$loop->onReadable($resource, $ready(self::PUMP_READABLE))];
		if ($writable) {
			$watchers[] = $loop->onWritable($resource, $ready(self::PUMP_WRITABLE));
		}
		$subscription = $token?->subscribe(function (TCancelledException $reason) use ($suspension): void {
			if ($this->getPumpWaiterDirect() === $suspension) {
				$this->setPumpWaiterDirect(null);
				$suspension->throw($reason);
			}
		});
		try {
			return $suspension->suspend();
		} finally {
			foreach ($watchers as $watcher) {
				$loop->cancel($watcher);
			}
			if ($this->getPumpWaiterDirect() === $suspension) {
				$this->setPumpWaiterDirect(null);
			}
			if ($subscription !== null) {
				$token->unsubscribe($subscription);
			}
		}
	}

	/**
	 * Resumes a waiting {@see pump()} with a reason.  The loop resumes it on its next iteration, so
	 * a wake from inside an nghttp2 callback switches no fiber there.
	 * @param string $reason {@see PUMP_READABLE}, {@see PUMP_WRITABLE}, or {@see PUMP_OUTPUT}.
	 * @since 1.3.0
	 */
	private function wakePump(string $reason): void
	{
		$waiter = $this->getPumpWaiterDirect();
		if ($waiter !== null) {
			$this->setPumpWaiterDirect(null);
			$waiter->resume($reason);
		}
	}

	/**
	 * Notes that a submit may have queued output: a waiting {@see pump()} flushes it.  Called at the
	 * end of every submit.
	 * @since 1.3.0
	 */
	private function outputChanged(): void
	{
		$this->wakePump(self::PUMP_OUTPUT);
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
		$this->assertOpen();
		$result = $this->getFfiDirect()->nghttp2_session_terminate_session($this->getSessionDirect(), $errorCode);
		if ($result !== 0) {
			throw new THttp2Exception('http2_session_failed', TNgHttp2::strerror($result));
		}
		$this->outputChanged();
	}

	/**
	 * Sends a PING for keepalive or round-trip timing; the peer answers with a PING ACK.
	 * @param string $payload Up to 8 bytes of opaque data (padded/truncated), or '' for a zero ping.
	 * @throws THttp2Exception When the ping cannot be submitted.
	 */
	public function ping(string $payload = ''): void
	{
		$this->assertOpen();
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
		$this->outputChanged();
	}

	/**
	 * Indicates whether a client may open a new {@see request()} (not GOAWAY-ed, under the limit).
	 * @throws THttp2Exception When the session is closed.
	 * @return bool Whether a new request is allowed.
	 */
	public function isRequestAllowed(): bool
	{
		$this->assertOpen();
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
		$this->assertOpen();
		$result = $this->getFfiDirect()->nghttp2_submit_window_update($this->getSessionDirect(), 0, $streamId, $increment);
		if ($result !== 0) {
			throw new THttp2Exception('http2_session_failed', TNgHttp2::strerror($result));
		}
		$this->outputChanged();
	}

	/**
	 * Tells nghttp2 that the application consumed bytes, advancing flow control (manual mode only,
	 * see {@see TH2Options::setNoAutoWindowUpdate()}).  Under
	 * {@see TH2Options::setConsumeOnRead() ConsumeOnRead} the streams report their reads themselves
	 * and the application does not call this.
	 * @param int $streamId The stream identifier.
	 * @param int $size The number of bytes consumed.
	 * @throws THttp2Exception When the consume call fails.
	 */
	public function consume(int $streamId, int $size): void
	{
		$this->assertOpen();
		$result = $this->getFfiDirect()->nghttp2_session_consume($this->getSessionDirect(), $streamId, $size);
		if ($result !== 0) {
			throw new THttp2Exception('http2_session_failed', TNgHttp2::strerror($result));
		}
		$this->outputChanged();
	}

	/**
	 * Reports received DATA bytes the application has read, under
	 * {@see TH2Options::setConsumeOnRead() ConsumeOnRead}: nghttp2 counts them as consumed and
	 * reopens the peer's window with a WINDOW_UPDATE once enough are.  Called by {@see TH2Stream}
	 * as it returns bytes from a read, and with $streamId 0 for bytes left unread when a stream
	 * closes (only the connection window gets them back).  Without ConsumeOnRead, on a closed session,
	 * or for no bytes it does nothing.  Best-effort, as {@see resumeStream()} is.
	 * @param int $streamId The stream identifier, or 0 for the connection window only.
	 * @param int $size The number of bytes read.
	 * @since 1.4.0
	 */
	public function consumeReceived(int $streamId, int $size): void
	{
		if ($size <= 0 || $this->getClosedDirect() || !$this->getOptionsDirect()?->getConsumeOnRead()) {
			return;
		}
		$ffi = $this->getFfiDirect();
		if ($streamId > 0) {
			$ffi->nghttp2_session_consume($this->getSessionDirect(), $streamId, $size);
		} else {
			$ffi->nghttp2_session_consume_connection($this->getSessionDirect(), $size);
		}
		$this->outputChanged();
	}

	// =========================================================================
	// Lifecycle
	// =========================================================================

	/**
	 * Closes the session, freeing the nghttp2 session and unregistering it.  Streams still open are
	 * marked closed in both directions ({@see TH2Stream::markClosed()}): their buffered bytes stay
	 * readable and a write throws.  {@see onClose} is then raised for each of them, as it is when
	 * nghttp2 closes a stream, so a handler learns of a connection that ended with streams open (the
	 * {@see pump()} closes the session when the transport ends).  The session is already closed when
	 * the handlers run; the first Throwable one raises is rethrown after every stream was reported.
	 * Every later nghttp2 call on the session throws `http2_session_closed`.  Called from inside an
	 * event handler (so while nghttp2 is executing), the PHP side closes at once and the nghttp2
	 * session is freed when the {@see receive()} or {@see send()} in progress returns.  The shared
	 * callbacks and data provider are process-wide and are not freed here.
	 * @throws \Throwable The first Throwable an `onClose` handler raised.
	 */
	public function close(): void
	{
		$this->closeSession(true);
	}

	/**
	 * Closes the session ({@see close()}), raising {@see onClose} for the streams still open only
	 * when asked: the destructor closes without events.
	 * @param bool $raise Whether to raise onClose for each stream still open.
	 * @throws \Throwable The first Throwable an `onClose` handler raised.
	 * @since 1.4.0
	 */
	private function closeSession(bool $raise): void
	{
		if ($this->getClosedDirect()) {
			return;
		}
		$this->setClosedDirect(true);
		unset(self::$_registry[$this->getIdDirect()]);
		$streams = $this->getStreamsDirect();
		foreach ($streams as $stream) {
			$stream->markClosed();
		}
		$this->setStreamsDirect([]);
		$this->setPendingHeadersDirect([]);
		$this->setPendingHeaderSizesDirect([]);
		$this->setUnsentOutputDirect('');
		$this->wakePump(self::PUMP_OUTPUT);   // a waiting pump() returns
		if ($this->getNativeDepthDirect() > 0) {
			// nghttp2 is executing this session: freeing it now would be a use-after-free.
			$this->setClosePendingDirect(true);
		} else {
			$this->freeSession();
		}
		if (!$raise) {
			return;
		}
		$first = null;
		foreach ($streams as $stream) {
			try {
				$this->onClose($stream);
			} catch (\Throwable $e) {
				$first ??= $e;   // the other streams are still reported
			}
		}
		if ($first !== null) {
			throw $first;
		}
	}

	/**
	 * Frees the nghttp2 session and drops the per-session callback plumbing.  Runs from
	 * {@see close()}, or from {@see nativeCall()} when close() was requested inside a callback.
	 * @since 1.2.0
	 */
	private function freeSession(): void
	{
		$this->setClosePendingDirect(false);
		$this->getFfiDirect()->nghttp2_session_del($this->getSessionDirect());
		$this->setUserDataDirect(null);
		$this->setDataProviderDirect(null);
		$this->setRefsDirect([]);
	}

	/**
	 * Frees the nghttp2 session if it was not closed explicitly.  The close is idempotent and
	 * best-effort here (it is skipped if the FFI binding is already gone, e.g. during shutdown), and
	 * raises no {@see onClose}: no handler runs while the session is collected.
	 */
	public function __destruct()
	{
		try {
			$this->closeSession(false);
		} catch (\Throwable $e) {
			// best-effort cleanup
		}
		parent::__destruct();
	}

	/**
	 * Throws when the session is closed.  {@see close()} frees the nghttp2 session, so a later
	 * nghttp2 call would touch freed memory.
	 * @throws THttp2Exception When the session is closed.
	 * @since 1.1.0
	 */
	private function assertOpen(): void
	{
		if ($this->getClosedDirect()) {
			throw new THttp2Exception('http2_session_closed');
		}
	}

	// =========================================================================
	// nghttp2 Callbacks
	// =========================================================================

	/**
	 * Begins accumulating headers for a stream, discarding anything an unfinished block left.
	 * @param int $streamId The stream identifier.
	 */
	private function handleBeginHeaders(int $streamId): void
	{
		$pending = &$this->getPendingHeadersDirect();
		$pending[$streamId] = [];
		$sizes = &$this->getPendingHeaderSizesDirect();
		$sizes[$streamId] = 0;
	}

	/**
	 * Forgets the header block a stream has accumulated, with its size.
	 * @param int $streamId The stream identifier.
	 * @since 1.4.0
	 */
	private function dropPendingHeaders(int $streamId): void
	{
		$pending = &$this->getPendingHeadersDirect();
		unset($pending[$streamId]);
		$sizes = &$this->getPendingHeaderSizesDirect();
		unset($sizes[$streamId]);
	}

	/**
	 * Records one received header for a stream.  A repeated name keeps every value: `cookie`
	 * crumbs rejoin with `; ` (RFC 9113 §8.2.3) and any other field combines with `, `
	 * (RFC 9110 §5.3).  The block's header list size grows by name length, value length, and 32
	 * (RFC 9113 §6.5.2).  Once it passes {@see getMaxHeaderListSize()} the block is dropped,
	 * {@see onInvalidFrame} is raised with `error` {@see TNgHttp2::ERR_TEMPORAL_CALLBACK_FAILURE}, and
	 * that code is returned: nghttp2 resets the stream and reports none of the block's later fields.
	 * @param int $streamId The stream identifier.
	 * @param string $name The header name.
	 * @param string $value The header value.
	 * @return int 0 to continue, or {@see TNgHttp2::ERR_TEMPORAL_CALLBACK_FAILURE} to reset the stream.
	 */
	private function handleHeader(int $streamId, string $name, string $value): int
	{
		$sizes = &$this->getPendingHeaderSizesDirect();
		$size = ($sizes[$streamId] ?? 0) + strlen($name) + strlen($value) + 32;
		$limit = $this->getMaxHeaderListSize();
		if ($limit > 0 && $size > $limit) {
			$this->dropPendingHeaders($streamId);
			$this->onInvalidFrame(['type' => TNgHttp2::FRAME_HEADERS, 'streamId' => $streamId, 'flags' => 0, 'error' => TNgHttp2::ERR_TEMPORAL_CALLBACK_FAILURE]);
			return TNgHttp2::ERR_TEMPORAL_CALLBACK_FAILURE;
		}
		$sizes[$streamId] = $size;
		$pending = &$this->getPendingHeadersDirect();
		if (isset($pending[$streamId][$name])) {
			$pending[$streamId][$name] .= ($name === 'cookie' ? '; ' : ', ') . $value;
		} else {
			$pending[$streamId][$name] = $value;
		}
		return 0;
	}

	/**
	 * Handles a completed frame: opens streams on a first HEADERS, routes a later HEADERS as
	 * trailers (or, on the client, as an informational or final response), and tracks END_STREAM.
	 * @param \FFI\CData $frame The nghttp2_frame_hd of the received frame.
	 * @return int Always 0 (continue).
	 */
	private function handleFrameRecv(\FFI\CData $frame): int
	{
		$streamId = $frame->stream_id;
		$endStream = ($frame->flags & TNgHttp2::FLAG_END_STREAM) !== 0;
		if ($frame->type === TNgHttp2::FRAME_HEADERS) {
			$headers = $this->getPendingHeadersDirect()[$streamId] ?? [];
			$this->dropPendingHeaders($streamId);
			$streams = &$this->getStreamsDirect();
			if ($this->getIsServerDirect()) {
				$stream = $streams[$streamId] ?? null;
				if ($stream === null) {
					$stream = Prado::createComponent(TH2Stream::class, $this, $streamId, $headers);
					$streams[$streamId] = $stream;
					if ($endStream) {
						$stream->markRemoteClosed();
					}
					$this->onRequest($stream);
				} else {
					// A second HEADERS on a request stream is the client's trailing header block.
					$stream->mergeHeaders($headers);
					if ($endStream) {
						$stream->markRemoteClosed();
					}
					$this->onTrailers($stream);
				}
			} else {
				$stream = $streams[$streamId] ?? null;
				if ($stream !== null) {
					$frameStatus = $headers[':status'] ?? null;
					$priorStatus = $stream->getHeader(':status');
					$stream->mergeHeaders($headers);
					if ($endStream) {
						$stream->markRemoteClosed();
					}
					if ($frameStatus !== null && $frameStatus !== '' && $frameStatus[0] === '1') {
						$this->onInformationalResponse($stream);
					} elseif ($frameStatus === null && $priorStatus !== null) {
						$this->onTrailers($stream);
					} else {
						$this->onResponse($stream);
					}
				}
			}
		} elseif ($frame->type === TNgHttp2::FRAME_DATA && $endStream) {
			($this->getStreamsDirect()[$streamId] ?? null)?->markRemoteClosed();
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
	 * Handles a sent frame.  A DATA or HEADERS frame with END_STREAM ends this side of its stream:
	 * the stream is told ({@see TH2Stream::markEndStreamSent()}), which submits the RST_STREAM of a
	 * stream set to {@see TH2Stream::setResetAfterEnd() reset after its end}.  {@see onFrameSent} is
	 * raised after it.
	 * @param array{type: int, streamId: int, flags: int} $info The frame summary.
	 * @since 1.5.0
	 */
	private function handleFrameSent(array $info): void
	{
		if (($info['flags'] & TNgHttp2::FLAG_END_STREAM) !== 0
			&& ($info['type'] === TNgHttp2::FRAME_DATA || $info['type'] === TNgHttp2::FRAME_HEADERS)) {
			($this->getStreamsDirect()[$info['streamId']] ?? null)?->markEndStreamSent();
		}
		$this->onFrameSent($info);
	}

	/**
	 * Handles a frame nghttp2 rejected: drops the header block the frame left unfinished (a HEADERS
	 * rejected mid-block never reaches on_frame_recv), then raises {@see onInvalidFrame}.  nghttp2 1.70
	 * resets such a stream and reports it here and in on_stream_close; older versions end the
	 * connection with GOAWAY instead, and {@see close()} drops the block with the session.
	 * @param array{type: int, streamId: int, flags: int, error: int} $info The frame summary.
	 * @since 1.2.0
	 */
	private function handleInvalidFrame(array $info): void
	{
		$this->dropPendingHeaders($info['streamId']);
		$this->onInvalidFrame($info);
	}

	/**
	 * Handles a closed stream: marks both directions closed, raises {@see onClose}, and forgets it
	 * along with any header block it left unfinished (a stream reset mid-HEADERS).  The stream is
	 * forgotten when a handler throws as well.
	 * @param int $streamId The stream identifier.
	 * @return int Always 0 (continue).
	 */
	private function handleStreamClose(int $streamId): int
	{
		$this->dropPendingHeaders($streamId);
		$stream = $this->getStreamsDirect()[$streamId] ?? null;
		if ($stream !== null) {
			$stream->markClosed();
			try {
				$this->onClose($stream);
			} finally {
				$streams = &$this->getStreamsDirect();
				unset($streams[$streamId]);
			}
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
				if ($stream->hasTrailersPending()) {
					// EOF ends the body; NO_END_STREAM holds END_STREAM back so the trailers carry
					// it.  nghttp2 requires the trailers be submitted from inside this callback,
					// after the body has drained.
					$dataFlags[0] = TNgHttp2::DATA_FLAG_EOF | TNgHttp2::DATA_FLAG_NO_END_STREAM;
					$this->submitTrailers($streamId, $stream->consumeTrailers());
					return 0;
				}
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
	 * Lowercases header names, rejects a name given twice, and orders pseudo-headers first.  HTTP/2
	 * field names are lowercase (RFC 9113 §8.2.1; nghttp2 rejects an uppercase name) and a
	 * pseudo-header after a regular field is malformed (§8.3): nghttp2 submits it and the peer resets
	 * the stream.
	 * A name that is not a token (with one leading colon for a pseudo-header) and a value with a CR,
	 * LF, or NUL character are rejected (§8.2.1): nghttp2 would send them, and a peer either drops
	 * the field without notice or treats the message as malformed.
	 * @param array<string, string> $headers The header name => value pairs as given.
	 * @throws THttp2Exception `http2_header_duplicate` when two names differ only by letter case;
	 *   `http2_header_invalid` for a name or a value HTTP/2 forbids.
	 * @return array<string, string> The normalized headers, pseudo-headers first.
	 * @since 1.2.0
	 */
	private function normalizeHeaders(array $headers): array
	{
		$pseudo = [];
		$regular = [];
		foreach ($headers as $name => $value) {
			$name = strtolower((string) $name);
			$value = (string) $value;
			if (isset($pseudo[$name]) || isset($regular[$name])) {
				throw new THttp2Exception('http2_header_duplicate', $name);
			}
			if (preg_match('/^:?[!#$%&\'*+\-.^_`|~0-9a-z]+$/D', $name) !== 1) {
				throw new THttp2Exception('http2_header_invalid', addcslashes($name, "\0..\37\177..\377"), 'the name is not a token');
			}
			if (strpbrk($value, "\r\n\0") !== false) {
				throw new THttp2Exception('http2_header_invalid', $name, 'the value holds a CR, LF, or NUL character');
			}
			if ($name[0] === ':') {
				$pseudo[$name] = $value;
			} else {
				$regular[$name] = $value;
			}
		}
		return $pseudo + $regular;
	}

	/**
	 * Builds an nghttp2_nv[] from {@see normalizeHeaders() normalized} header pairs; $keep holds the
	 * byte buffers alive during submit.
	 * @param array<string, string> $headers The normalized header name => value pairs.
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
			$name = (string) $name;
			$value = (string) $value;
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
	 * Raised when a client request's final response headers arrive (a non-1xx `:status`).  A 1xx
	 * informational response raises {@see onInformationalResponse} instead, and a trailing header
	 * block raises {@see onTrailers}.
	 * @param TH2Stream $stream The stream carrying the response.
	 */
	public function onResponse(TH2Stream $stream): void
	{
		$this->raiseEvent('onResponse', $this, $stream);
	}

	/**
	 * Raised when a client receives a 1xx informational response (e.g. 100 Continue, 103 Early
	 * Hints) before the final response.  The stream stays open; {@see onResponse} follows.
	 * @param TH2Stream $stream The stream carrying the informational response.
	 * @since 1.0.0
	 */
	public function onInformationalResponse(TH2Stream $stream): void
	{
		$this->raiseEvent('onInformationalResponse', $this, $stream);
	}

	/**
	 * Raised when a trailing header block arrives: a second HEADERS frame on the stream, after the
	 * body.  A server receives request trailers; a client receives response trailers (no `:status`).
	 * The trailers are merged into the stream's headers.
	 * @param TH2Stream $stream The stream carrying the trailers.
	 * @since 1.0.0
	 */
	public function onTrailers(TH2Stream $stream): void
	{
		$this->raiseEvent('onTrailers', $this, $stream);
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
	 * Raised when a stream closes: nghttp2 closed it (both sides finished, or a reset), or
	 * {@see close()} closed the session while the stream was open.  In the second case the session
	 * is already closed when the handler runs.
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
	 * Raised when an invalid frame is received (a protocol violation by the peer), and when a header
	 * block exceeds the {@see getMaxHeaderListSize() header list limit} (`type`
	 * {@see TNgHttp2::FRAME_HEADERS}, `error` {@see TNgHttp2::ERR_TEMPORAL_CALLBACK_FAILURE}).
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
