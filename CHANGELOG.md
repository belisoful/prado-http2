# Changelog

All notable changes to `belisoful/prado-http2` are recorded here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/); versions follow [Semantic Versioning](https://semver.org/).

## [Unreleased]

### Added
- `TNgHttp2::MIN_VERSION` (`1.60.0`, where nghttp2's `nghttp2_ssize` API arrived). Each candidate library is probed for its version before the full declaration is bound; an older one is reported through the new error code `http2_library_too_old` (version found, path, version required) instead of a symbol-resolution failure.
- Error code `http2_header_duplicate`: `request()`, `respond()` and `submitTrailers()` reject a header name given twice in different letter case. Pseudo-headers are sent first whatever the given order (RFC 9113 §8.3); `request()` keeps the normalized order on the stream.
- `TNgHttp2::FRAME_PUSH_PROMISE`, `TNgHttp2::ERR_TEMPORAL_CALLBACK_FAILURE`, `TNgHttp2::ERR_PUSH_DISABLED`; `nghttp2_submit_push_promise` in the FFI declaration.
- `TH2Stream` tracks a consumed offset per buffer (`IncomingOffset`, `OutgoingOffset` self-encapsulated accessors) and compacts once the consumed prefix is large.

### Fixed
- A Throwable raised by an event handler (or by the extension) inside an nghttp2 callback was a PHP fatal error ("Throwing from FFI callbacks is not allowed") that ended the process. It is now held while nghttp2 finishes the `receive()`/`send()` call and rethrown from that call; the remaining frames are still processed and the session stays usable. Bytes a throwing `send()` produced are returned by the next `send()`. A Throwable inside the data provider resets only its stream (`NGHTTP2_ERR_TEMPORAL_CALLBACK_FAILURE`).
- `TH2Session::close()` from inside an event handler freed the nghttp2 session while it was executing (a segmentation fault). The PHP side now closes at once and nghttp2 is freed when the call returns.
- A server PUSH_PROMISE polluted the client's request stream: its header block was kept as pending headers under the request stream's id and merged into the real response, so the request's `:path` became the pushed path. Push header blocks are ignored, `handleBeginHeaders()` resets the pending block, and `handleStreamClose()` drops the block of a stream reset mid-HEADERS.
- `TH2Stream::close()` and `detach()` never ended the stream on the wire: a headers-only response's deferred provider was never resumed, so the peer waited for END_STREAM forever. `close()` now cancels a peer that is still sending (RST_STREAM CANCEL) or ends the local side (END_STREAM) when the peer has finished, is idempotent, and `pushIncoming()` drops bytes for a detached stream instead of buffering them.
- Draining a large body was quadratic: `drainOutgoing()` rebuilt the remaining buffer for every 16 KiB frame (16 MiB took 0.78 s in-process; now 0.05 s). `read()` had the same shape.
- A regular header placed before the pseudo-headers passed submit and the peer reset the stream (the client saw only `onClose`, the server never raised `onRequest`).
- `mergeHeaders()` renumbered a digit-only header name (`array_merge`), so `getHeader('123')` returned null after a merge.
- `sendTrailers()` after the body had already ended was silently dropped; it now throws `RuntimeException`, as `write()` does.

### Changed
- A client session declines server push: `submitSettings()` adds `SETTINGS_ENABLE_PUSH => 0` unless the caller sets that id (pushed streams have no `TH2Stream` and are discarded in any case).
- CI runs on `ubuntu-26.04`: Ubuntu 24.04's libnghttp2 1.59.0 predates the API this binding declares, so FFI failed to load there and every nghttp2 test was skipped on every run while the workflow stayed green. A step now fails the run when the library or an HTTP/2 `curl` is unusable.
- PHP 8.2 is the minimum (`"php": ">=8.2.0"`), following PRADO 4.4, which dropped PHP 8.1 (pradosoft/prado#1290). CI covers PHP 8.2 to 8.5 and the Composer platform is 8.2.
- PHPStan runs at level 4 with `treatPhpDocTypesAsCertain: false`, matching the framework.
- CI uses `actions/checkout@v7` and `actions/cache@v6`; the dependency cache keys on `composer.json` per PHP version (the lock file is not committed) and the job times out after 30 minutes.
- Development dependencies pin `friendsofphp/php-cs-fixer` 3.95.27 and `phpstan/phpstan` 2.3.0, the versions PRADO pins.

### Fixed
- The package `homepage`, the `@link` tag in every class header, and the README install command named `pradosoft/prado-http2`; the package is `belisoful/prado-http2`.

### Upgrading
- Run on PHP 8.2 or later. PHP 8.1 is no longer supported: PRADO 4.4 does not install on it.
- libnghttp2 1.60.0 or newer is required (it always was; the failure is now named). Ubuntu 24.04 ships 1.59.0.
- An exception thrown by an event handler now surfaces from `receive()` or `send()` instead of ending the process; catch it there. A client that relies on server push must pass `SETTINGS_ENABLE_PUSH => 1` to `submitSettings()`. `TH2Stream::close()` now sends RST_STREAM or END_STREAM; use `markLocalClosed()` to finish a body gracefully, as before.

## [1.1.0] - 2026-09-22

### Added
- `TH2Stream::markClosed()`: marks both directions closed without touching the session. The session calls it when nghttp2 closes a stream (peer reset, completion) and when the session closes; buffered bytes stay readable, a write throws.
- Error code `http2_session_closed`: every nghttp2 call on a closed `TH2Session` throws it instead of touching freed memory. `resumeStream()` becomes a no-op and `wantsIo()` returns false on a closed session.
- Server-side trailers: a second HEADERS frame on a request stream merges into the stream's headers and raises `onTrailers` (it previously raised `onRequest` again and dropped the trailers).
- Repeated received header fields keep every value: `cookie` crumbs rejoin with `; ` (RFC 9113 §8.2.3), any other name combines with `, ` (RFC 9110 §5.3). Previously the last value won.
- Header names are lowercased on submit (RFC 9113 §8.2.1); nghttp2 rejected an uppercase name.
- `TH2Options` follows the self-encapsulation convention (`get*Direct()`/`set*Direct()`).
- CI matrix covers PHP 8.4 and 8.5 (8.1 through 8.5 in total), runs weekly against PRADO `master`, and can be dispatched manually.
- `CHANGELOG.md`.

### Fixed
- Sessions are held weakly in the callback routing registry, so an unreferenced `TH2Session` is collected and its nghttp2 session freed. Previously every session that was not closed explicitly stayed alive for the process.
- The FFI declaration of `nghttp2_ssize` is `ptrdiff_t` (a C `long` is 32-bit on Windows, so DATA-provider return values and `mem_send2` lengths were truncated there) and `nghttp2_info` keeps the header's field order (`version_num` was declared last).
- Callbacks resolve their session through the FFI instance they were built with; a `TNgHttp2::setLibraryPath()` re-bind (or a failed one) can no longer throw inside an FFI callback.
- A stream the peer reset or that completed reports `isWritable()` false and rejects a write, rather than queuing bytes nghttp2 never sends.

### Changed
- Development dependencies track the PRADO `master` branch through the sibling `../prado` path repository; PHPStan runs at level 3 with `phpVersion` 8.1 to 8.5, matching the framework.
- `friendsofphp/php-cs-fixer` constraint raised to `^3.95`.
- README, CLAUDE.md, and AGENTS.md describe the closed-state, trailer, and header rules and the wider CI matrix.

## [1.0.0] - 2026-06-24

### Added
- `TH2Alpn`: advertises the `h2` ALPN protocol in an `ssl` stream context and reads the negotiated protocol back (`negotiatedProtocol()`, `negotiatedH2()`, `requireH2()`), with error code `http2_alpn_not_negotiated`. A functional test completes a real in-process TLS handshake.
- Sending trailers: `TH2Stream::sendTrailers()` and `TH2Session::submitTrailers()` end a body with a trailing header block.
- Client-side HEADERS classification: `onResponse` (final), `onInformationalResponse` (1xx), and `onTrailers`.
- Full PSR-7 `StreamInterface` conformance for `TH2Stream` (detached state, `read()`/`getContents()`/`write()` throw when closed, `__toString()` never throws).
- Composer metadata under `extra.prado` (`error-messages`, `class-map`); the bootstrap module was removed.

### Fixed
- `TH2Stream::markLocalClosed()` resumes a deferred stream so an empty or finite body emits END_STREAM.
- The `error_callback2` closure accepts the `const char*` message FFI already converted to a PHP string.
- Each session captures the shared data provider and closures, so a `TNgHttp2::setLibraryPath()` re-bind cannot strand it.

## [0.9.0] - 2026-06-12

### Added
- Initial release: `TNgHttp2` FFI binding over the system `libnghttp2`, `TH2Session` (server and client, memory I/O, shared callbacks), `TH2Stream` (duplex PSR-7 stream), `TH2Options`, `THttp2Exception`, in-process unit tests, and a `curl --http2-prior-knowledge` interoperability test.

[Unreleased]: https://github.com/belisoful/prado-http2/compare/v1.1.0...HEAD
[1.1.0]: https://github.com/belisoful/prado-http2/compare/v1.0.0...v1.1.0
[1.0.0]: https://github.com/belisoful/prado-http2/compare/v0.9.0...v1.0.0
[0.9.0]: https://github.com/belisoful/prado-http2/releases/tag/v0.9.0
