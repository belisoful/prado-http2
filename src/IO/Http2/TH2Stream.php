<?php

/**
 * TH2Stream class file.
 *
 * @author Brad Anderson <belisoful@icloud.com>
 * @link https://github.com/pradosoft/prado-http2
 * @license https://github.com/pradosoft/prado/blob/master/LICENSE
 */

namespace Prado\IO\Http2;

use Prado\TComponent;
use Psr\Http\Message\StreamInterface;

/**
 * TH2Stream class.
 *
 * One HTTP/2 stream as a duplex {@see StreamInterface}.  Bytes received in DATA frames are
 * buffered for {@see read()}/{@see getContents()}; bytes written with {@see write()} are queued
 * and drained into outgoing DATA frames by the owning {@see TH2Session}'s data provider, which
 * resumes the stream so nghttp2 pulls the new bytes.
 *
 * The stream carries the request or response {@see getHeaders() headers}, including the HTTP/2
 * pseudo-headers (`:method`, `:path`, `:protocol`, `:status`, ...).  It is not seekable; a seek
 * throws.  Reads are non-blocking: {@see read()} returns the buffered bytes, or '' when none
 * have arrived yet.  The stream is at {@see eof()} once the peer half-closes and the buffer is
 * drained.  {@see markLocalClosed()} finishes a finite body: the queued bytes flush and the
 * stream then ends (END_STREAM), unlike {@see close()}, which discards the buffers and leaves
 * the stream detached.  A {@see read()}, {@see getContents()}, or {@see write()} on a detached
 * stream throws, per PSR-7.
 *
 * State is self-encapsulated: every field is reached through a protected `get*Direct()`/
 * `set*Direct()` accessor (the byte buffers return by reference), so a subclass can intercept
 * any of it.
 *
 * @author Brad Anderson <belisoful@icloud.com>
 * @since 1.0.0
 * @see https://www.rfc-editor.org/rfc/rfc9113.html
 */
class TH2Stream extends TComponent implements StreamInterface
{
	/** @var TH2Session The owning session. */
	private TH2Session $_session;

	/** @var int The HTTP/2 stream identifier. */
	private int $_streamId;

	/** @var array<string, string> The stream's headers, including pseudo-headers. */
	private array $_headers;

	/** @var string Bytes received from the peer, awaiting a read. */
	private string $_incoming = '';

	/** @var string Bytes written locally, awaiting outgoing DATA frames. */
	private string $_outgoing = '';

	/** @var bool Whether the peer has half-closed (no more incoming DATA). */
	private bool $_remoteClosed = false;

	/** @var bool Whether this side has finished writing (no more outgoing DATA). */
	private bool $_localClosed = false;

	/** @var bool Whether the stream has been closed or detached (unusable per PSR-7). */
	private bool $_detached = false;

	/** @var bool Whether a trailing header block is queued to send after the body. */
	private bool $_trailersPending = false;

	/** @var array<string, string> The trailing headers to send once the body finishes. */
	private array $_trailers = [];

	/** @var int The total number of bytes read, for {@see tell()}. */
	private int $_readPosition = 0;

	/**
	 * @param TH2Session $session The owning session.
	 * @param int $streamId The HTTP/2 stream identifier.
	 * @param array<string, string> $headers The stream's headers, including pseudo-headers.
	 */
	public function __construct(TH2Session $session, int $streamId, array $headers = [])
	{
		$this->_session = $session;
		$this->_streamId = $streamId;
		$this->setHeadersDirect($headers);
		parent::__construct();
	}

	// =========================================================================
	// Self-Encapsulated Accessors
	// =========================================================================

	/**
	 * Returns the owning session.
	 * @return TH2Session The owning session.
	 */
	protected function getSessionDirect(): TH2Session
	{
		return $this->_session;
	}

	/**
	 * Returns the raw stream identifier.
	 * @return int The HTTP/2 stream identifier.
	 */
	protected function getStreamIdDirect(): int
	{
		return $this->_streamId;
	}

	/**
	 * Returns the raw header map.
	 * @return array<string, string> The headers.
	 */
	protected function getHeadersDirect(): array
	{
		return $this->_headers;
	}

	/**
	 * Sets the raw header map.
	 * @param array<string, string> $value The headers.
	 */
	protected function setHeadersDirect(array $value): void
	{
		$this->_headers = $value;
	}

	/**
	 * Returns the raw incoming buffer by reference, for in-place mutation.
	 * @return string The incoming bytes, by reference.
	 */
	protected function &getIncomingDirect(): string
	{
		return $this->_incoming;
	}

	/**
	 * Sets the raw incoming buffer.
	 * @param string $value The incoming bytes.
	 */
	protected function setIncomingDirect(string $value): void
	{
		$this->_incoming = $value;
	}

	/**
	 * Returns the raw outgoing buffer by reference, for in-place mutation.
	 * @return string The outgoing bytes, by reference.
	 */
	protected function &getOutgoingDirect(): string
	{
		return $this->_outgoing;
	}

	/**
	 * Sets the raw outgoing buffer.
	 * @param string $value The outgoing bytes.
	 */
	protected function setOutgoingDirect(string $value): void
	{
		$this->_outgoing = $value;
	}

	/**
	 * Returns the raw remote-closed flag.
	 * @return bool Whether the peer has half-closed.
	 */
	protected function getRemoteClosedDirect(): bool
	{
		return $this->_remoteClosed;
	}

	/**
	 * Sets the raw remote-closed flag.
	 * @param bool $value Whether the peer has half-closed.
	 */
	protected function setRemoteClosedDirect(bool $value): void
	{
		$this->_remoteClosed = $value;
	}

	/**
	 * Returns the raw local-closed flag.
	 * @return bool Whether this side has finished writing.
	 */
	protected function getLocalClosedDirect(): bool
	{
		return $this->_localClosed;
	}

	/**
	 * Sets the raw local-closed flag.
	 * @param bool $value Whether this side has finished writing.
	 */
	protected function setLocalClosedDirect(bool $value): void
	{
		$this->_localClosed = $value;
	}

	/**
	 * Returns the raw detached flag.
	 * @return bool Whether the stream has been closed or detached.
	 */
	protected function getDetachedDirect(): bool
	{
		return $this->_detached;
	}

	/**
	 * Sets the raw detached flag.
	 * @param bool $value Whether the stream has been closed or detached.
	 */
	protected function setDetachedDirect(bool $value): void
	{
		$this->_detached = $value;
	}

	/**
	 * Returns the raw trailers-pending flag.
	 * @return bool Whether a trailing header block is queued.
	 */
	protected function getTrailersPendingDirect(): bool
	{
		return $this->_trailersPending;
	}

	/**
	 * Sets the raw trailers-pending flag.
	 * @param bool $value Whether a trailing header block is queued.
	 */
	protected function setTrailersPendingDirect(bool $value): void
	{
		$this->_trailersPending = $value;
	}

	/**
	 * Returns the raw trailing headers.
	 * @return array<string, string> The queued trailing headers.
	 */
	protected function getTrailersDirect(): array
	{
		return $this->_trailers;
	}

	/**
	 * Sets the raw trailing headers.
	 * @param array<string, string> $value The trailing headers.
	 */
	protected function setTrailersDirect(array $value): void
	{
		$this->_trailers = $value;
	}

	/**
	 * Returns the raw read position.
	 * @return int The total bytes read.
	 */
	protected function getReadPositionDirect(): int
	{
		return $this->_readPosition;
	}

	/**
	 * Sets the raw read position.
	 * @param int $value The total bytes read.
	 */
	protected function setReadPositionDirect(int $value): void
	{
		$this->_readPosition = $value;
	}

	// =========================================================================
	// Properties
	// =========================================================================

	/** @return int The HTTP/2 stream identifier. */
	public function getStreamId(): int
	{
		return $this->getStreamIdDirect();
	}

	/** @return array<string, string> The stream's headers, including pseudo-headers. */
	public function getHeaders(): array
	{
		return $this->getHeadersDirect();
	}

	/**
	 * Returns a single header value (pseudo-headers included), or null when absent.
	 * @param string $name The header name (e.g. ':method', ':protocol').
	 * @return ?string The header value, or null.
	 */
	public function getHeader(string $name): ?string
	{
		return $this->getHeadersDirect()[$name] ?? null;
	}

	// =========================================================================
	// Session Plumbing
	// =========================================================================

	/**
	 * Appends received DATA bytes to the incoming buffer (called by {@see TH2Session}).
	 * @param string $bytes The received bytes.
	 */
	public function pushIncoming(string $bytes): void
	{
		$incoming = &$this->getIncomingDirect();
		$incoming .= $bytes;
	}

	/**
	 * Removes and returns up to $length queued outgoing bytes (called by the data provider).
	 * @param int $length The maximum bytes to take.
	 * @return string The dequeued bytes ('' when none are queued).
	 */
	public function drainOutgoing(int $length): string
	{
		$outgoing = &$this->getOutgoingDirect();
		if ($outgoing === '') {
			return '';
		}
		$take = min($length, strlen($outgoing));
		$bytes = substr($outgoing, 0, $take);
		$outgoing = substr($outgoing, $take);
		return $bytes;
	}

	/** @return bool Whether outgoing bytes are queued for sending. */
	public function hasOutgoing(): bool
	{
		return $this->getOutgoingDirect() !== '';
	}

	/** @return bool Whether this side has finished writing. */
	public function isLocalClosed(): bool
	{
		return $this->getLocalClosedDirect();
	}

	/** Marks the peer as half-closed; no further incoming DATA arrives. */
	public function markRemoteClosed(): void
	{
		$this->setRemoteClosedDirect(true);
	}

	/**
	 * Marks this side as done writing: the data provider sends any queued outgoing bytes and then
	 * ends the stream (END_STREAM).  Unlike {@see close()}, the queued buffer is preserved and
	 * still flushed.  Use this to finish a finite response or request body.
	 *
	 * The stream is resumed so nghttp2 re-arms its data provider: a stream whose provider was
	 * already deferred (an open stream with nothing queued, e.g. a server `respond()` then
	 * `send()` with no body) would otherwise never emit END_STREAM.
	 */
	public function markLocalClosed(): void
	{
		$this->setLocalClosedDirect(true);
		$this->getSessionDirect()->resumeStream($this->getStreamIdDirect());
	}

	/** @return bool Whether a trailing header block is queued to send after the body. */
	public function hasTrailersPending(): bool
	{
		return $this->getTrailersPendingDirect();
	}

	/**
	 * Returns the queued trailing headers and clears the pending flag.  Called by the session's
	 * data provider once the body finishes, to submit the trailers exactly once.
	 * @return array<string, string> The trailing headers.
	 */
	public function consumeTrailers(): array
	{
		$this->setTrailersPendingDirect(false);
		return $this->getTrailersDirect();
	}

	/**
	 * Finishes the body with a trailing header block (HTTP/2 trailers): the queued bytes flush as
	 * DATA, then the trailers are sent as a HEADERS frame with END_STREAM.  Trailers carry no
	 * pseudo-headers (no `:status`).  Call after {@see write()}ing the body, instead of
	 * {@see markLocalClosed()}.  The trailers are submitted by the data provider once the body has
	 * drained, as nghttp2 requires.
	 * @param array<string, string> $headers The trailing header name => value pairs.
	 */
	public function sendTrailers(array $headers): void
	{
		$this->setTrailersDirect($headers);
		$this->setTrailersPendingDirect(true);
		$this->setLocalClosedDirect(true);
		$this->getSessionDirect()->resumeStream($this->getStreamIdDirect());
	}

	/**
	 * Cancels this stream (RST_STREAM) on the session, leaving the connection open for others.
	 * @param int $errorCode A {@see TNgHttp2} error code. Default {@see TNgHttp2::CANCEL}.
	 */
	public function cancel(int $errorCode = TNgHttp2::CANCEL): void
	{
		$this->getSessionDirect()->resetStream($this->getStreamIdDirect(), $errorCode);
	}

	/**
	 * Merges additional headers into the stream (e.g. response headers on the client side).
	 * @param array<string, string> $headers The headers to merge.
	 */
	public function mergeHeaders(array $headers): void
	{
		$this->setHeadersDirect(array_merge($this->getHeadersDirect(), $headers));
	}

	// =========================================================================
	// StreamInterface
	// =========================================================================

	/**
	 * Queues bytes for outgoing DATA frames and resumes the stream so nghttp2 sends them.
	 * @param string $string The bytes to send.
	 * @throws \RuntimeException When the stream is not writable (closed, detached, or local-closed).
	 * @return int The number of bytes queued.
	 */
	public function write(string $string): int
	{
		if ($this->getDetachedDirect()) {
			throw new \RuntimeException('Cannot write to a detached or closed HTTP/2 stream.');
		}
		if ($this->getLocalClosedDirect()) {
			throw new \RuntimeException('Cannot write to a non-writable HTTP/2 stream; the local side is closed.');
		}
		$outgoing = &$this->getOutgoingDirect();
		$outgoing .= $string;
		$this->getSessionDirect()->resumeStream($this->getStreamIdDirect());
		return strlen($string);
	}

	/**
	 * Returns up to $length buffered incoming bytes (non-blocking).  An open stream with nothing
	 * buffered returns '' rather than blocking.
	 * @param int $length The maximum number of bytes to return.
	 * @throws \RuntimeException When the stream is detached/closed, or $length is negative.
	 * @return string The bytes read, or '' when none are buffered.
	 */
	public function read(int $length): string
	{
		if ($this->getDetachedDirect()) {
			throw new \RuntimeException('Cannot read from a detached or closed HTTP/2 stream.');
		}
		if ($length < 0) {
			throw new \RuntimeException('Length parameter cannot be negative.');
		}
		$incoming = &$this->getIncomingDirect();
		if ($length === 0 || $incoming === '') {
			return '';
		}
		$bytes = substr($incoming, 0, $length);
		$incoming = substr($incoming, strlen($bytes));
		$this->setReadPositionDirect($this->getReadPositionDirect() + strlen($bytes));
		return $bytes;
	}

	/**
	 * Returns and clears all buffered incoming bytes.
	 * @throws \RuntimeException When the stream is detached or closed.
	 * @return string The buffered incoming bytes.
	 */
	public function getContents(): string
	{
		if ($this->getDetachedDirect()) {
			throw new \RuntimeException('Cannot read from a detached or closed HTTP/2 stream.');
		}
		$incoming = &$this->getIncomingDirect();
		$bytes = $incoming;
		$incoming = '';
		$this->setReadPositionDirect($this->getReadPositionDirect() + strlen($bytes));
		return $bytes;
	}

	/**
	 * Closes the stream for both directions and clears its buffers.  The stream becomes unusable:
	 * a later {@see read()}, {@see getContents()}, or {@see write()} throws (PSR-7).
	 */
	public function close(): void
	{
		$this->setLocalClosedDirect(true);
		$this->setRemoteClosedDirect(true);
		$this->setDetachedDirect(true);
		$this->setIncomingDirect('');
		$this->setOutgoingDirect('');
	}

	/**
	 * Detaches the stream (no underlying resource to return).
	 * @return null Always null; an HTTP/2 stream has no PHP resource.
	 */
	public function detach()
	{
		$this->close();
		return null;
	}

	/** @return bool Whether the peer has half-closed and the buffer is drained. */
	public function eof(): bool
	{
		return $this->getRemoteClosedDirect() && $this->getIncomingDirect() === '';
	}

	/** @return ?int Always null; an HTTP/2 stream length is unknown. */
	public function getSize(): ?int
	{
		return null;
	}

	/** @return int The total number of bytes read so far. */
	public function tell(): int
	{
		return $this->getReadPositionDirect();
	}

	/** @return bool Whether incoming bytes can be read (true until detached and drained). */
	public function isReadable(): bool
	{
		return !$this->getDetachedDirect()
			&& (!$this->getRemoteClosedDirect() || $this->getIncomingDirect() !== '');
	}

	/** @return bool Whether bytes can be written (true until this side closes or detaches). */
	public function isWritable(): bool
	{
		return !$this->getDetachedDirect() && !$this->getLocalClosedDirect();
	}

	/** @return bool Always false; an HTTP/2 stream is not seekable. */
	public function isSeekable(): bool
	{
		return false;
	}

	/**
	 * Throws: an HTTP/2 stream cannot seek.
	 * @param int $offset The seek offset (unused).
	 * @param int $whence The seek origin (unused).
	 * @throws \RuntimeException Always.
	 */
	public function seek(int $offset, int $whence = SEEK_SET): void
	{
		throw new \RuntimeException('An HTTP/2 stream is not seekable.');
	}

	/**
	 * Throws: an HTTP/2 stream cannot seek.
	 * @throws \RuntimeException Always.
	 */
	public function rewind(): void
	{
		throw new \RuntimeException('An HTTP/2 stream is not seekable.');
	}

	/**
	 * Returns stream metadata (none for an HTTP/2 stream).
	 * @param ?string $key The metadata key, or null for all.
	 * @return null|array<string, mixed> An empty array for all, or null for a key.
	 */
	public function getMetadata(?string $key = null)
	{
		return $key === null ? [] : null;
	}

	/**
	 * Returns and clears the buffered incoming bytes.  Casting consumes the buffer (an HTTP/2
	 * stream is not seekable, so the bytes cannot be re-read).  Per PSR-7 this never throws: a
	 * detached or closed stream casts to ''.
	 * @return string The buffered incoming bytes.
	 */
	public function __toString(): string
	{
		try {
			return $this->getContents();
		} catch (\Throwable $e) {
			return '';
		}
	}
}
