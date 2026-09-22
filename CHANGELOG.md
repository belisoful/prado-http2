# Changelog

All notable changes to `belisoful/prado-http2` are recorded here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/); versions follow [Semantic Versioning](https://semver.org/).

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

[1.1.0]: https://github.com/belisoful/prado-http2/compare/v1.0.0...v1.1.0
[1.0.0]: https://github.com/belisoful/prado-http2/compare/v0.9.0...v1.0.0
[0.9.0]: https://github.com/belisoful/prado-http2/releases/tag/v0.9.0
