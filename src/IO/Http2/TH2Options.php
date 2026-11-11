<?php

/**
 * TH2Options class file.
 *
 * @author Brad Anderson <belisoful@icloud.com>
 * @link https://github.com/belisoful/prado-http2
 * @license https://github.com/pradosoft/prado/blob/master/LICENSE
 */

namespace Prado\IO\Http2;

use Prado\TComponent;

/**
 * TH2Options class.
 *
 * Tuning for a {@see TH2Session}, applied at creation through nghttp2's option API.  Each value
 * is optional: a null limit leaves nghttp2's default in place.
 *
 *  - {@see setPeerMaxConcurrentStreams() PeerMaxConcurrentStreams}: the assumed peer limit before
 *    its SETTINGS arrive, bounding how many streams open eagerly.
 *  - {@see setNoAutoWindowUpdate() NoAutoWindowUpdate}: when true, the application drives flow
 *    control itself with {@see TH2Session::consume()} and {@see TH2Session::submitWindowUpdate()}.
 *  - {@see setConsumeOnRead() ConsumeOnRead}: when true, the peer's flow-control window reopens as
 *    the application reads a stream, so an unread body stops the peer at the window (receive
 *    backpressure).  It turns nghttp2's automatic window updates off.
 *  - {@see setMaxHeaderListSize() MaxHeaderListSize}: the largest header list the session accepts
 *    in one header block.  Null means {@see TH2Session::DEFAULT_MAX_HEADER_LIST_SIZE}.
 *
 * A session with no options uses nghttp2's defaults (automatic flow control) and the default
 * header list limit.  Under automatic flow control nghttp2 reopens the window as DATA arrives, so
 * a stream buffers whatever the peer sends until the application reads it.  The local maximum
 * concurrent streams is a SETTING, set with {@see TH2Session::submitSettings()} using
 * {@see TNgHttp2::SETTINGS_MAX_CONCURRENT_STREAMS}; a server advertises
 * {@see TH2Session::DEFAULT_MAX_CONCURRENT_STREAMS} when the caller gives none.
 *
 * @author Brad Anderson <belisoful@icloud.com>
 * @since 0.9.0
 */
class TH2Options extends TComponent
{
	/** @var ?int The assumed peer concurrency limit before its SETTINGS arrive, or null. */
	private ?int $_peerMaxConcurrentStreams = null;

	/** @var bool Whether automatic flow-control window updates are disabled. */
	private bool $_noAutoWindowUpdate = false;

	/** @var bool Whether the peer's window reopens as the application reads a stream. */
	private bool $_consumeOnRead = false;

	/** @var ?int The largest accepted header list in bytes, 0 for no limit, or null for the default. */
	private ?int $_maxHeaderListSize = null;

	// =========================================================================
	// Self-Encapsulated Accessors
	// =========================================================================

	/**
	 * Returns the raw peer concurrency limit.
	 * @return ?int The raw peer concurrency limit, or null.
	 * @since 1.1.0
	 */
	protected function getPeerMaxConcurrentStreamsDirect(): ?int
	{
		return $this->_peerMaxConcurrentStreams;
	}

	/**
	 * Sets the raw peer concurrency limit.
	 * @param ?int $value The raw peer concurrency limit, or null.
	 * @since 1.1.0
	 */
	protected function setPeerMaxConcurrentStreamsDirect(?int $value): void
	{
		$this->_peerMaxConcurrentStreams = $value;
	}

	/**
	 * Returns the raw no-auto-window-update flag.
	 * @return bool The raw no-auto-window-update flag.
	 * @since 1.1.0
	 */
	protected function getNoAutoWindowUpdateDirect(): bool
	{
		return $this->_noAutoWindowUpdate;
	}

	/**
	 * Sets the raw no-auto-window-update flag.
	 * @param bool $value The raw no-auto-window-update flag.
	 * @since 1.1.0
	 */
	protected function setNoAutoWindowUpdateDirect(bool $value): void
	{
		$this->_noAutoWindowUpdate = $value;
	}

	/**
	 * Returns the raw consume-on-read flag.
	 * @return bool The raw consume-on-read flag.
	 * @since 1.4.0
	 */
	protected function getConsumeOnReadDirect(): bool
	{
		return $this->_consumeOnRead;
	}

	/**
	 * Sets the raw consume-on-read flag.
	 * @param bool $value The raw consume-on-read flag.
	 * @since 1.4.0
	 */
	protected function setConsumeOnReadDirect(bool $value): void
	{
		$this->_consumeOnRead = $value;
	}

	/**
	 * Returns the raw header list limit.
	 * @return ?int The raw limit in bytes, 0 for no limit, or null for the default.
	 * @since 1.4.0
	 */
	protected function getMaxHeaderListSizeDirect(): ?int
	{
		return $this->_maxHeaderListSize;
	}

	/**
	 * Sets the raw header list limit.
	 * @param ?int $value The raw limit in bytes, 0 for no limit, or null for the default.
	 * @since 1.4.0
	 */
	protected function setMaxHeaderListSizeDirect(?int $value): void
	{
		$this->_maxHeaderListSize = $value;
	}

	// =========================================================================
	// Properties
	// =========================================================================

	/**
	 * Returns the peer concurrency limit nghttp2 assumes before the peer's SETTINGS arrive.
	 * @return ?int The assumed peer concurrency limit, or null for nghttp2's default.
	 */
	public function getPeerMaxConcurrentStreams(): ?int
	{
		return $this->getPeerMaxConcurrentStreamsDirect();
	}

	/**
	 * Sets the peer concurrency limit nghttp2 assumes before the peer's SETTINGS arrive.
	 * @param ?int $value The assumed peer concurrency limit, or null for nghttp2's default.
	 */
	public function setPeerMaxConcurrentStreams(?int $value): void
	{
		$this->setPeerMaxConcurrentStreamsDirect($value);
	}

	/**
	 * Indicates whether the application drives flow control itself.
	 * @return bool Whether automatic flow-control window updates are disabled.
	 */
	public function getNoAutoWindowUpdate(): bool
	{
		return $this->getNoAutoWindowUpdateDirect();
	}

	/**
	 * Sets whether the application drives flow control itself.  When true, nghttp2 sends no
	 * WINDOW_UPDATE on its own: the application calls {@see TH2Session::consume()} for the bytes it
	 * has processed, or sets {@see setConsumeOnRead() ConsumeOnRead} so the streams do.
	 * @param bool $value Whether to disable automatic flow-control window updates.
	 */
	public function setNoAutoWindowUpdate(bool $value): void
	{
		$this->setNoAutoWindowUpdateDirect($value);
	}

	/**
	 * Indicates whether the peer's flow-control window reopens as the application reads a stream.
	 * @return bool Whether reads drive flow control.
	 * @since 1.4.0
	 */
	public function getConsumeOnRead(): bool
	{
		return $this->getConsumeOnReadDirect();
	}

	/**
	 * Sets whether the peer's flow-control window reopens as the application reads a stream.  When
	 * true, nghttp2's automatic window updates are off and each {@see TH2Stream::read()},
	 * {@see TH2Stream::getContents()}, and {@see TH2Stream::readAsync()} reports the bytes it returns
	 * to nghttp2.  A peer that sends faster than the application reads stops at the window, so a
	 * stream buffers at most the window.  Bytes still unread when a stream closes or is
	 * {@see TH2Stream::close() closed} by the application are returned to the connection window, so
	 * an abandoned stream cannot stall the others.  The application does not call
	 * {@see TH2Session::consume()} in this mode.
	 * @param bool $value Whether reads drive flow control.
	 * @since 1.4.0
	 */
	public function setConsumeOnRead(bool $value): void
	{
		$this->setConsumeOnReadDirect($value);
	}

	/**
	 * Returns the largest header list the session accepts in one header block.
	 * @return ?int The limit in bytes, 0 for no limit, or null for
	 *   {@see TH2Session::DEFAULT_MAX_HEADER_LIST_SIZE}.
	 * @since 1.4.0
	 */
	public function getMaxHeaderListSize(): ?int
	{
		return $this->getMaxHeaderListSizeDirect();
	}

	/**
	 * Sets the largest header list the session accepts in one header block (request headers,
	 * response headers, or trailers).  The size is the sum over the fields of name length, value
	 * length, and 32 (RFC 9113 §6.5.2).  A block over the limit is dropped and its stream reset.
	 * {@see TH2Session::submitSettings()} advertises the limit as `SETTINGS_MAX_HEADER_LIST_SIZE`.
	 * A negative value is treated as zero.
	 * @param ?int $value The limit in bytes, 0 for no limit, or null for the default.
	 * @since 1.4.0
	 */
	public function setMaxHeaderListSize(?int $value): void
	{
		$this->setMaxHeaderListSizeDirect($value === null ? null : max(0, $value));
	}

	/**
	 * Indicates whether every option holds its default.
	 * @return bool Whether no option is set.
	 */
	public function isEmpty(): bool
	{
		return $this->getPeerMaxConcurrentStreamsDirect() === null
			&& !$this->getNoAutoWindowUpdateDirect()
			&& !$this->getConsumeOnReadDirect()
			&& $this->getMaxHeaderListSizeDirect() === null;
	}
}
