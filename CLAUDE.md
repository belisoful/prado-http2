# CLAUDE.md

This file provides guidance to Agents when working with code in this repository.

## What This Is

**prado-http2** is a PRADO 4 extension that adds **HTTP/2** ([RFC 9113](https://www.rfc-editor.org/rfc/rfc9113.html)) to the [PRADO framework](https://github.com/pradosoft/prado) by binding the system **`libnghttp2`** through PHP **FFI**. nghttp2 owns the wire framing, HPACK header compression, stream state machine, and flow control; this extension is the PHP surface over it. All source lives under `src/` (PSR-4 namespace `Prado\IO\Http2`). Tests mirror that under `tests/unit/` and `tests/functional/`. Current version: **1.1.0** (see `CHANGELOG.md`).

It is **general** HTTP/2 — the basis for HTTP/2 servers, clients, and [RFC 8441](https://www.rfc-editor.org/rfc/rfc8441.html) WebSocket-over-HTTP/2 multiplexing. The `prado-websockets` extension consumes it as an **optional** dependency.

## Commands

```bash
# Code style (tabs, PSR-12) over src/ and tests/ — check, then fix
vendor/bin/php-cs-fixer fix --dry-run
vendor/bin/php-cs-fixer fix

# Static analysis (level 3, PHP 8.1 to 8.5) over src/ and tests/
vendor/bin/phpstan analyse --memory-limit=512M

# Unit tests — two in-process nghttp2 sessions (h2c, no sockets/TLS)
composer unittest        # vendor/bin/phpunit --testsuite unit

# Functional tests — real `curl --http2-prior-knowledge` interop over a socket
composer functest        # vendor/bin/phpunit --testsuite functional
```

### Full Check (required before git commit)

Run these in order — all must pass:

1. `php -l` on changed files
2. `vendor/bin/php-cs-fixer fix --dry-run`
3. `vendor/bin/phpstan analyse --memory-limit=512M`
4. `composer unittest` (and `composer functest` where `libnghttp2` + a HTTP/2-capable `curl` are present)

> **Never add or change phpunit command options** — run only the `unittest`/`functest` scripts. When testing one class/cluster, use `--filter <name>`.

## Architecture

Six classes under `Prado\IO\Http2` (`src/IO/Http2/`):

| Class | Role |
|---|---|
| `TNgHttp2` | `final` FFI binding: library resolution, the C-declaration (`cdef`) surface, `version()`/`isAvailable()`/`strerror()`, and protocol constants |
| `TH2Session` | one HTTP/2 connection (server or client): owns the nghttp2 session, manages streams, moves bytes with `receive()`/`send()`, raises `onRequest`/`onResponse`/`onInformationalResponse`/`onTrailers`/`onData`/`onClose` |
| `TH2Stream` | one stream as a duplex PSR-7 `StreamInterface`: headers, incoming/outgoing buffers |
| `TH2Options` | optional session tuning (`PeerMaxConcurrentStreams`, `NoAutoWindowUpdate`), applied at creation |
| `TH2Alpn` | `final` static helper: advertises the `h2` ALPN protocol in an `ssl` context and reads back the negotiated protocol (TLS termination stays the caller's) |
| `THttp2Exception` | an HTTP/2 failure; extends `TIOException` |

The extension has no bootstrap module. `config/errorMessages.txt` (the `http2_*` codes) and `config/classMap.json` (short class name → FQN) load system-wide through the `extra.prado.error-messages` and `extra.prado.class-map` entries in composer.json.

### How nghttp2 is driven

- **Memory I/O.** A session never touches a socket. Feed received bytes with `receive()`; drain produced bytes with `send()`. The same code runs over a real socket or an in-process test pipe — which is why the unit tests need neither sockets nor TLS.
- **Callbacks.** The nghttp2 callback set (and data provider) is built **once** and shared by every session, with the closures held alive process-wide in the static `$_sharedRefs` — a closure handed to FFI is retained for the FFI instance's life, so per-session closures would leak. Each session captures the shared provider/closures (`$_dataProvider`, `$_refs`) so a `setLibraryPath()` re-bind cannot strand it. Routing to the owning session is through nghttp2's `user_data` (a per-session id in `$_registry`), so two sessions in one process never cross-talk.
- **Data providers.** Outgoing DATA is pulled by the shared nghttp2 data-provider callback from each stream's queued bytes; an empty but open stream returns `ERR_DEFERRED` until resumed. `TH2Stream::markLocalClosed()` finishes a finite body (flush the queue, then END_STREAM) and **resumes the stream** so a deferred provider re-arms; `sendTrailers()` flushes the body then ends the stream with a trailing header block; `close()` tears the buffers down.
- **Headers.** `buildHeaders()` lowercases every name (RFC 9113 §8.2.1; nghttp2 rejects uppercase). `handleHeader()` keeps a repeated received field by joining: `cookie` with `; `, any other name with `, `. A second HEADERS on a stream is trailers on both sides (`onTrailers`); the server never raises `onRequest` twice.
- **Lifecycle.** `$_registry` holds `WeakReference`s, so an unreferenced session is collected and its `__destruct` closes it. `close()` frees the nghttp2 session, marks the remaining streams closed in both directions (`TH2Stream::markClosed()`: buffered bytes stay readable, a write throws), and makes every later nghttp2 call throw `http2_session_closed` (`assertOpen()`); `resumeStream()` and `wantsIo()` degrade to a no-op/false instead. nghttp2 also closes a stream on peer reset or completion; `handleStreamClose()` marks it closed the same way before `onClose`.
- **FFI quirks.** Call `cast()`/`new()` on the bound instance (`$ffi->cast(...)`), since phpstan treats them as instance methods; `FFI::addr`/`string`/`memcpy` are static. FFI converts a `const char*` to a PHP string both as a **return value** (handled in `TNgHttp2::strerror()`) and as a **callback parameter** (the `error_callback2` `msg`, handled in the `onError` closure); a `uint8_t*` stays CData and needs `FFI::string($ptr, $len)`.

### Library resolution

`libnghttp2` is resolved from (in order) an explicit `TNgHttp2::setLibraryPath()`, the `PRADO_NGHTTP2_LIB` environment variable, then platform defaults (Homebrew/`/usr/local` on macOS, `libnghttp2.so.14` sonames on Linux, `nghttp2.dll` on Windows). `TNgHttp2::isAvailable()` reports whether it loads, so a consumer can fall back to HTTP/1.1.

### Out of scope

- **HTTP/3 (RFC 9220).** Runs over QUIC, whose TLS key schedule needs hooks PHP's OpenSSL bindings do not expose. No viable pure-PHP or FFI-simple path.
- **TLS termination.** Certificates, cipher policy, and the listen/accept loop are the caller's responsibility (often a reverse proxy). `TH2Alpn` covers the one HTTP/2-specific TLS step — negotiating the `h2` ALPN protocol via PHP's OpenSSL streams — and the caller pumps the TLS stream's bytes into `receive()`/`send()`. Cleartext `h2c` needs no TLS.
- **Web SAPIs.** A request-scoped SAPI (PHP-FPM, mod_php) cannot expose the raw socket; use this in a long-running process.

## Naming Conventions

| Thing | Convention | Example |
|---|---|---|
| Classes | `TPascalCase` | `TH2Session` |
| Methods | `camelCase` | `submitSettings` |
| Variables | `camelCase` | `$streamId` |
| Class Constants | `SCREAMING_SNAKE_CASE` | `FRAME_HEADERS` |
| Enumerated Constants | `PascalCase` | `DeepSkyBlue` |
| Class properties | `_camelCase` | `_session`, `_streams` |
| Namespaces | `Prado\{Module}` | `Prado\IO\Http2` |

## Important Rules

- **Namespace** `Prado\IO\Http2`, PSR-4 → `src/`. Extensions do **not** maintain the framework's `classes.php` — composer PSR-4 autoloading covers the classes.
- **Error codes** are `http2_*` in `config/errorMessages.txt`, registered system-wide through the `extra.prado.error-messages` entry in composer.json (read by `TApplicationConfiguration`). The framework `messages.txt` is not used. New codes describe the failure; `{0}`, `{1}` are positional parameters. The `config/classMap.json` short-name → FQN map loads the same way via `extra.prado.class-map`.
- **Self-Encapsulation (UAP-SE)** is required for `TComponent` classes: private fields, protected `get*Direct()`/`set*Direct()` accessors (return **by reference** for mutable buffers/arrays, like `TBufferStream`), and **all** access — public accessors and internal code — routed through them. `TH2Session` and `TH2Stream` follow this. `TNgHttp2` is a `final` static binding with no instance property system and is exempt.
- **phpstan + FFI.** FFI binds nghttp2 methods and CData struct fields dynamically, so they are unprovable statically. `phpstan.neon.dist` carries `ignoreErrors` for `Call to an undefined method FFI::...` and `Access to an undefined property FFI\CData::...` — keep them; do not silence individual lines with casts or `@var`. The config runs level 3 with `phpVersion` 8.1 to 8.5, matching the framework.
- **The cdef mirrors `nghttp2.h` exactly.** `nghttp2_ssize` is `ptrdiff_t` (a `long` is 32-bit on Windows) and struct fields keep the header's order (`nghttp2_info` is `age, version_num, version_str, proto_str`). `TNgHttp2Test::testVersionInfoLayoutMatchesLibrary` cross-checks the layout at runtime.
- **cs-fixer = tabs** (`@PSR12` + `setIndent("\t")`). If cs-fixer suddenly wants to convert tabs → spaces across *every* file, `.php-cs-fixer.dist.php` has been clobbered by a php-cs-fixer scaffold (the `@auto` default); restore the tab-based config (it matches the sibling extensions).
- **`if` statements** always use a block (`{}`), never a single-line body.
- **`@since`** uses the current version (`1.1.0`) for new classes/methods; omit the method tag when it matches the class.
- **Backward compatibility** — all changes must be backward compatible within a point release.
- Method docblocks are **tight** and carry at least one descriptive sentence (not only `@param`/`@return`).

## Testing

- **Unit** (`tests/unit`, `composer unittest`) — drive a server and a client `TH2Session` against each other in-process (h2c) by pumping `send()` → `receive()`; assert headers, DATA, and stream lifecycle. They skip cleanly when `libnghttp2` is unavailable.
- **Functional** (`tests/functional`, `composer functest`) — serve one request with a `TH2Session` over a real socket and hit it with the system `curl --http2-prior-knowledge`, proving interoperability with an independent HTTP/2 implementation. Skips when `libnghttp2`, an HTTP/2-capable `curl`, or `proc_open` is unavailable.
- New code includes tests for typical **and** edge cases, and for error/exception paths. Tests are isolated (no shared state).

## Code Style

- Indentation: **tabs** (not spaces). Line endings: Unix (`\n`). PHP minimum: 8.1 (CI tests 8.1, 8.2, 8.3, 8.4, 8.5). PSR-12 enforced via php-cs-fixer. Use `?` for single nullable types and in doc blocks.

### Documentation Style (enforced)

Docblocks are technical documentation: direct statements, present tense, American English, clear and brief.

_Banned constructions_:
- **Antithesis** ("not just X, it Ys", "is not a Y, it's a Z", "rather than X, it Ys"). State what it does, once.
- **Em-dash dramatic asides** for emphasis. Use a period or a plain clause.
- **Editorializing / filler.**
- **Rule-of-three rhetorical lists** and build-up sentences. One fact per sentence.

Prefer subject-verb-object declaratives and `condition → result` lists.

## Development Environment

- PHP 8.1+ with `ext-ffi`; the system `libnghttp2` library (`brew install libnghttp2`, `apt-get install libnghttp2-dev`). `ext-openssl` only for `h2` over TLS.
- `pradosoft/prado ^4.4` is a dev dependency, providing `TComponent`, `TException`/`TIOException`, and the PHPStan extensions. 4.4 is unreleased, so composer.json resolves it from the sibling `../prado` path repository (symlinked into `vendor/`), and the workflow checks out `pradosoft/prado@master` at that path; a weekly scheduled run catches framework changes no commit here triggered.
- A previous real checkout of prado (commit 086b864, with local edits) was set aside at `vendor/pradosoft/prado.086b864.bak` on 2026-09-22 when `vendor/pradosoft/prado` became the symlink.
- Presume project dependencies are installed.

## Anti-Patterns (Required Safeguards)

- **Never** run `git clone/checkout/mv/restore/rm/branch/add/commit/merge/rebase/reset/pull/push/fetch` without developer approval first.
- **Never** run `rm` on any path without developer approval first.
- **Never** remove `composer --dev` dependencies.
- **Never** erase or overwrite files during unit testing — the file changes being tested must be preserved.
