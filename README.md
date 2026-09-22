# PRADO HTTP/2 Extension

HTTP/2 ([RFC 9113](https://www.rfc-editor.org/rfc/rfc9113.html)) for the [PRADO PHP Framework](https://github.com/pradosoft/prado) (version 4.4+), implemented over the system **`libnghttp2`** bound through PHP FFI.

A complete, correct HTTP/2 stack is large — frame layer, HPACK header compression, stream state machine, and connection/stream flow control. Rather than reimplement that in PHP, this extension binds nghttp2 (the reference C implementation that powers curl, nginx, and Apache) and lets it own the protocol. PRADO keeps the socket and the bytes; nghttp2 does the framing.

The session I/O is **memory based**: you feed received bytes in and drain produced bytes out, so the same code drives a real socket or an in-process test that pumps bytes between two sessions. This makes HTTP/2 testable without sockets or TLS (cleartext `h2c`).

It is the foundation for HTTP/2 servers and clients, and for [RFC 8441](https://www.rfc-editor.org/rfc/rfc8441.html) WebSocket-over-HTTP/2 multiplexing (used by the [`prado-websocket`](https://github.com/belisoful/prado-websocket) extension).

## Requirements

| Requirement | Scope | Purpose |
|---|---|---|
| PHP 8.1 to 8.5 | required | CI runs 8.1, 8.2, 8.3, 8.4, and 8.5 |
| `ext-ffi` | required | Binds `libnghttp2` at runtime |
| System `libnghttp2` | suggested | The HTTP/2 framing engine, loaded at runtime (`brew install libnghttp2`, `apt-get install libnghttp2-dev`) |
| `ext-openssl` | suggested | HTTP/2 over TLS with ALPN `h2`; cleartext `h2c` needs nothing extra |
| PRADO Framework `^4.4` (master) | dev | `TComponent`, `TException`/`TIOException`, the PHPStan extensions |

> **FFI note.** `ffi.enable` must permit FFI (CLI always allows it; for other SAPIs use `preload` or `true`). HTTP/2 here is intended for a long-running PHP process (a socket server or client), not a per-request web SAPI.

## Installation

```sh
composer require pradosoft/prado-http2
```

The library is resolved in this order: an explicit path set with `TNgHttp2::setLibraryPath()`, the `PRADO_NGHTTP2_LIB` environment variable, then platform defaults (Homebrew/`/usr/local` on macOS, the common `libnghttp2.so.14` sonames on Linux, `nghttp2.dll` on Windows). `TNgHttp2::isAvailable()` reports whether it loads, so an application can fall back to HTTP/1.1 when HTTP/2 is unavailable.

## What it provides

| Class | Role |
|---|---|
| `Prado\IO\Http2\TNgHttp2` | The `libnghttp2` FFI binding: library resolution, the C-declaration surface, and `version()`/`isAvailable()`/`strerror()` helpers and protocol constants |
| `Prado\IO\Http2\TH2Session` | One HTTP/2 connection (server or client): drives nghttp2, manages streams, moves bytes with `receive()`/`send()`, exposes connection control (`resetStream`/`goaway`/`ping`/settings/flow control), and raises stream and diagnostic events |
| `Prado\IO\Http2\TH2Stream` | One HTTP/2 stream as a duplex PSR-7 `StreamInterface`: carries headers, buffers incoming DATA, queues outgoing DATA, and can `cancel()` |
| `Prado\IO\Http2\TH2Options` | Optional session tuning (`PeerMaxConcurrentStreams`, `NoAutoWindowUpdate`), applied at creation |
| `Prado\IO\Http2\TH2Alpn` | TLS/ALPN helper: advertises `h2` in an `ssl` stream context and reads the negotiated protocol back (needs `ext-openssl`) |
| `Prado\IO\Http2\THttp2Exception` | An HTTP/2 failure (library missing, session error); extends `TIOException` |

## Architecture

```
your transport (socket, test pipe, ...)
        │  bytes in                       bytes out  ▲
        ▼                                            │
   TH2Session::receive()  ──► libnghttp2 ──►  TH2Session::send()
        │  (framing, HPACK, flow control via nghttp2)
        ▼  events
   onRequest / onResponse / onInformationalResponse / onTrailers / onData / onClose
        │
        ▼
   TH2Stream  (one per HTTP/2 stream — a duplex StreamInterface)
```

nghttp2 is callback driven. The callbacks (and the data provider) are built **once** and shared by every session — a closure handed to FFI is retained for the FFI instance's life, so per-session closures would leak. Each callback routes to the owning session through nghttp2's `user_data` (a per-session id). Outgoing DATA is pulled by the shared data provider: bytes written to a stream are queued, the stream is resumed, and nghttp2 reads them on the next `send()`.

## Usage

### Server

```php
use Prado\IO\Http2\TH2Session;
use Prado\IO\Http2\TNgHttp2;

$session = new TH2Session(true);                 // server side
$session->submitSettings([
    TNgHttp2::SETTINGS_ENABLE_CONNECT_PROTOCOL => 1,   // RFC 8441 (optional)
]);

$session->attachEventHandler('onRequest', function ($session, $stream) {
    // $stream->getHeaders() includes the pseudo-headers (:method, :path, :authority, ...)
    $session->respond($stream, [':status' => '200']);
    $stream->write('hello');
    $stream->markLocalClosed();                  // finish the body — emits END_STREAM
});
$session->attachEventHandler('onData', function ($session, $stream) {
    $body = $stream->getContents();              // bytes received on this stream
});
$session->attachEventHandler('onClose', function ($session, $stream) {
    // stream finished
});

// Pump a transport: feed what you read, write what it produces.
$transport->write($session->send());             // initial SETTINGS
while (/* connection open */) {
    $session->receive($transport->read(65536));  // drives the events above
    $transport->write($session->send());
}
```

### Client

```php
$session = new TH2Session(false);                // client side
$session->submitSettings([]);

$session->attachEventHandler('onResponse', function ($session, $stream) {
    $status = $stream->getHeader(':status');     // the final (non-1xx) response
});
$session->attachEventHandler('onInformationalResponse', function ($session, $stream) {
    // a 1xx response (100 Continue, 103 Early Hints) before the final response
});
$session->attachEventHandler('onTrailers', function ($session, $stream) {
    // a trailing header block after the body (e.g. grpc-status)
});
$session->attachEventHandler('onData', function ($session, $stream) {
    $body = $stream->getContents();
});

$stream = $session->request([
    ':method'    => 'GET',
    ':scheme'    => 'https',
    ':authority' => 'example.com',
    ':path'      => '/',
]);
$stream->markLocalClosed();                      // a GET has no body — end the request

$transport->write($session->send());             // preface + SETTINGS + request
$session->receive($transport->read(65536));
```

### Streaming and Extended CONNECT

`TH2Stream` is a full PSR-7 `StreamInterface` (`write()` queues outgoing DATA and resumes the stream; `read()`/`getContents()` return buffered incoming DATA; it is **not** seekable). Per PSR-7 it throws when written to after it is local-closed/closed, or read after it is closed/detached. This supports request/response bodies and long-lived tunnels alike. An RFC 8441 Extended CONNECT (`:method` `CONNECT`, `:protocol` `websocket`) opens a bidirectional stream that carries arbitrary bytes both ways — the basis for WebSocket-over-HTTP/2.

A finite body is finished two ways: `markLocalClosed()` flushes the queued bytes and ends the stream (END_STREAM), and `sendTrailers([...])` flushes the body and then ends the stream with a trailing header block (HTTP/2 trailers, e.g. `grpc-status`). `close()` instead discards the buffers and detaches the stream. Once nghttp2 closes a stream (peer reset, completion, or session close) both directions are marked closed: buffered bytes stay readable and a write throws.

Trailers flow both ways: `onTrailers` fires on the server for request trailers and on the client for response trailers. Header names are lowercased on submit (RFC 9113 §8.2.1). A received field that repeats keeps every value: `cookie` crumbs rejoin with `; ` and any other name combines with `, `.

### Connection control and tuning

`TH2Session` exposes the lifecycle primitives a real server/client needs:

- `resetStream($id, $code)` / `TH2Stream::cancel($code)` — cancel one stream (RST_STREAM) without closing the connection.
- `goaway($code)` — graceful shutdown (GOAWAY): no new streams, in-flight ones finish.
- `ping($payload)` — keepalive / round-trip timing.
- `getRemoteSetting($id)` / `getLocalSetting($id)` — read negotiated SETTINGS (e.g. a client checking `getRemoteSetting(SETTINGS_ENABLE_CONNECT_PROTOCOL)` before an Extended CONNECT).
- `wantsIo()` — whether the session still has I/O pending, so an event loop knows when to stop.
- `isRequestAllowed()` — client-side: whether a new `request()` is permitted (not GOAWAY-ed, under the limit).
- `submitWindowUpdate()` / `consume()` — manual flow control, paired with `TH2Options::setNoAutoWindowUpdate()`.
- `close()` — frees the nghttp2 session and marks its open streams closed; any later nghttp2 call on the session throws `http2_session_closed`. Sessions are held weakly, so an unreferenced session is collected and freed on its own.

A client session accepts server push unless it submits `TNgHttp2::SETTINGS_ENABLE_PUSH => 0`; a pushed stream has no `TH2Stream`, so its frames are discarded.

Tuning is passed at creation: `new TH2Session(true, (new TH2Options())->setPeerMaxConcurrentStreams(100))`. Diagnostic events `onFrameSent`/`onFrameNotSent`/`onInvalidFrame`/`onSessionError` surface frame activity and protocol errors.

### `TNgHttp2` directly

`TNgHttp2::ffi()` returns the bound `\FFI` instance for code that needs the raw nghttp2 API. Constants cover the SETTINGS ids (`SETTINGS_*`), frame types (`FRAME_*`), HTTP/2 error codes (`NO_ERROR`, `CANCEL`, `REFUSED_STREAM`, `INTERNAL_ERROR`, `PROTOCOL_ERROR`, `STREAM_CLOSED`), the `FLAG_END_STREAM`/`DATA_FLAG_*` flags, and `ERR_DEFERRED`. `TNgHttp2::version()` returns the loaded library version; `TNgHttp2::strerror($code)` describes an nghttp2 error.

## PRADO integration

The package declares its Prado metadata under `extra.prado` in composer.json (the format `TApplicationConfiguration` reads):

```json
"extra": {
    "prado": {
        "error-messages": "config/errorMessages.txt",
        "class-map": "config/classMap.json"
    }
}
```

`error-messages` registers the `http2_*` codes and `class-map` registers the short class name → FQN map, both system-wide for every installed extension, so they resolve with no further wiring. The extension has no bootstrap module — nothing else to configure.

## TLS with ALPN (`h2`)

Browsers speak HTTP/2 only over TLS, negotiated through the `h2` ALPN protocol (RFC 9113 §3.3). `TH2Alpn` supplies the two HTTP/2-specific pieces; PHP's OpenSSL stream layer terminates the TLS connection, and the session stays transport-agnostic.

```php
use Prado\IO\Http2\TH2Alpn;
use Prado\IO\Http2\TH2Session;

$ctx = stream_context_create(['ssl' => TH2Alpn::sslOptions([
    'local_cert' => '/path/server.pem',
])]);
$listen = stream_socket_server('tls://0.0.0.0:443', $errno, $errstr,
    STREAM_SERVER_BIND | STREAM_SERVER_LISTEN, $ctx);

$conn = stream_socket_accept($listen);   // TLS handshake completes here
TH2Alpn::requireH2($conn);               // confirm the peer negotiated h2

$session = new TH2Session(true);
// ... pump fread($conn) -> $session->receive(); fwrite($conn, $session->send()) ...
```

A client mirrors this with `stream_socket_client('tls://...')` and `TH2Alpn::negotiatedH2($conn)` to fall back to HTTP/1.1 when `h2` is not offered. Certificates and cipher policy remain the caller's concern.

## Limitations

- **HTTP/3 is out of scope.** HTTP/3 runs over QUIC, whose TLS key schedule needs hooks PHP's OpenSSL bindings do not expose; there is no usable pure-PHP or FFI-simple path today.
- **Full TLS termination is the caller's responsibility.** Certificates, cipher policy, and the listen/accept loop belong to the caller (often a reverse proxy). `TH2Alpn` handles the one HTTP/2-specific TLS step, negotiating the `h2` ALPN protocol (see [TLS with ALPN](#tls-with-alpn-h2)); the caller then pumps the TLS stream into a session. Cleartext `h2c` needs no TLS.
- **Not a web-SAPI module.** A request-scoped SAPI (PHP-FPM, mod_php) does not expose the raw socket; use this in a long-running process.

## Development

```sh
composer install
composer unittest                                # unit tests (dual in-process sessions)
composer functest                                # curl --http2-prior-knowledge interop + TLS ALPN
vendor/bin/php-cs-fixer fix --dry-run            # code style (src/ and tests/)
vendor/bin/phpstan analyse --memory-limit=512M   # static analysis, level 3, PHP 8.1 to 8.5
```

Unit tests drive a server and a client `TH2Session` against each other in-process (no sockets, no TLS), so they run anywhere `libnghttp2` is installed and skip cleanly where it is not. The functional tests serve a request to the system `curl` over a real socket and complete a TLS handshake that negotiates `h2`. CI runs the whole check on PHP 8.1 through 8.5 against the PRADO `master` branch, on every push and once a week.

## License

BSD-3-Clause. See [LICENSE](LICENSE).
