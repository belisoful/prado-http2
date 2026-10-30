# PRADO HTTP/2 Extension

HTTP/2 ([RFC 9113](https://www.rfc-editor.org/rfc/rfc9113.html)) for the [PRADO PHP Framework](https://github.com/pradosoft/prado) (version 4.4+), implemented over the system **`libnghttp2`** bound through PHP FFI.

A complete, correct HTTP/2 stack is large — frame layer, HPACK header compression, stream state machine, and connection/stream flow control. Rather than reimplement that in PHP, this extension binds nghttp2 (the reference C implementation that powers curl, nginx, and Apache) and lets it own the protocol. PRADO keeps the socket and the bytes; nghttp2 does the framing.

The session I/O is **memory based**: you feed received bytes in and drain produced bytes out, so the same code drives a real socket or an in-process test that pumps bytes between two sessions. This makes HTTP/2 testable without sockets or TLS (cleartext `h2c`).

It is the foundation for HTTP/2 servers and clients, and for [RFC 8441](https://www.rfc-editor.org/rfc/rfc8441.html) WebSocket-over-HTTP/2 multiplexing (used by the [`prado-websocket`](https://github.com/belisoful/prado-websocket) extension).

## Requirements

| Requirement | Scope | Purpose |
|---|---|---|
| PHP 8.2 to 8.5 | required | CI runs 8.2, 8.3, 8.4, and 8.5 |
| `ext-ffi` | required | Binds `libnghttp2` at runtime |
| System `libnghttp2` 1.60.0 or newer | suggested | The HTTP/2 framing engine, loaded at runtime (`brew install libnghttp2`, `apt-get install libnghttp2-dev`); 1.60.0 introduced the `nghttp2_ssize` API this binding declares. Ubuntu 24.04 ships 1.59.0, Ubuntu 26.04 ships 1.68.0 |
| `ext-openssl` | suggested | HTTP/2 over TLS with ALPN `h2`; cleartext `h2c` needs nothing extra |
| PRADO Framework `^4.4` (master) | dev | `TComponent`, `TException`/`TIOException`, the PHPStan extensions |

> **FFI note.** `ffi.enable` must permit FFI (CLI always allows it; for other SAPIs use `preload` or `true`). HTTP/2 here is intended for a long-running PHP process (a socket server or client), not a per-request web SAPI.

## Installation

```sh
composer require belisoful/prado-http2
```

The library is resolved in this order: an explicit path set with `TNgHttp2::setLibraryPath()`, the `PRADO_NGHTTP2_LIB` environment variable, then platform defaults (Homebrew/`/usr/local` on macOS, the common `libnghttp2.so.14` sonames on Linux, `nghttp2.dll` on Windows). `TNgHttp2::isAvailable()` reports whether it loads, so an application can fall back to HTTP/1.1 when HTTP/2 is unavailable; `TNgHttp2::ffi()` throws `http2_library_too_old` (naming the version found and `TNgHttp2::MIN_VERSION`) when the only library found predates 1.60.0.

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

A finite body is finished two ways: `markLocalClosed()` flushes the queued bytes and ends the stream (END_STREAM), and `sendTrailers([...])` flushes the body and then ends the stream with a trailing header block (HTTP/2 trailers, e.g. `grpc-status`). `close()` discards the buffers, detaches the stream, and ends it on the wire: a peer still sending is cancelled (RST_STREAM CANCEL), a peer that has finished sees END_STREAM. Once nghttp2 closes a stream (peer reset, completion, or session close) both directions are marked closed: buffered bytes stay readable and a write throws.

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

A client session declines server push: `submitSettings()` adds `TNgHttp2::SETTINGS_ENABLE_PUSH => 0` unless the caller gives that id. A pushed stream has no `TH2Stream`, so its frames are discarded even when push is enabled.

Event handlers run inside nghttp2 callbacks, where PHP cannot throw. A Throwable raised by a handler is held until nghttp2 finishes the `receive()` or `send()` call that triggered it, then rethrown from that call; every frame in that call was still processed and the session stays usable (bytes a throwing `send()` produced come out of the next `send()`). A failure inside the data provider resets only its stream. Header names are lowercased and pseudo-headers ordered first on submit; a name given twice in different letter case throws `http2_header_duplicate`. Calling `close()` on the session from inside a handler is safe: the PHP side closes at once and nghttp2 is freed when the call returns.

Tuning is passed at creation: `new TH2Session(true, (new TH2Options())->setPeerMaxConcurrentStreams(100))`. Diagnostic events `onFrameSent`/`onFrameNotSent`/`onInvalidFrame`/`onSessionError` surface frame activity and protocol errors.

### Event loops and fibers

A handler runs inside an nghttp2 callback, so the session is busy until the `receive()` or `send()` that raised it returns. `receive()` throws `http2_session_busy` while any nghttp2 call on the session is in progress, and `send()` throws it while another `send()` is in progress (a `send()` from inside a `receive()` handler is allowed): a nested call would re-enter nghttp2's state machine. This covers PRADO 4.4's fibers (`Prado\IO\Async`). PHP permits a fiber switch inside an FFI callback, so a handler that waits on the event loop (`TAsync::sleep()`, `TFuture::await()`, a `TAsyncStream` read) leaves nghttp2 mid-call while the loop runs, from the main fiber or from a suspended worker fiber, and the loop's next `receive()` on the session is such a re-entry. A handler that needs to wait hands the work to `TAsync::run()` and returns; the fiber resumes from the loop outside any nghttp2 call and may then respond, write, and end the stream. The next `send()` carries what it produced.

`TH2Stream::readAsync($length, $token = null)` is the fiber-aware read, with the `TAsyncStream::read()` contract: it returns buffered bytes when any are present, suspends the current fiber on `TAsync::getLoop()` while the buffer is empty and the peer is still sending, and returns `''` only at end of stream. The session's `receive()` wakes the fiber through the loop when DATA arrives or the remote side closes, so a worker fiber consumes a request body as it arrives without polling `onData`; the synchronous `read()` and `getContents()` are unchanged. A `TCancellationToken` ends the wait with its `TCancelledException`. One fiber waits per stream; a second concurrent wait throws. `waitReadable($token = null)` is the bare wait. Call `readAsync()` from the fiber `TAsync::run()` started, never from inside a handler (a wait there is the `http2_session_busy` case above).

```php
use Prado\IO\Async\TAsync;

$session->attachEventHandler('onRequest', function ($session, $stream) use ($backend) {
    TAsync::run(function () use ($session, $stream, $backend) {
        $body = $backend->fetch($stream->getHeader(':path'));   // waits on the loop
        $session->respond($stream, [':status' => '200']);
        $stream->write($body);
        $stream->markLocalClosed();
    });
});

$session->attachEventHandler('onRequest', function ($session, $stream) use ($store) {
    TAsync::run(function () use ($session, $stream, $store) {
        while (($chunk = $stream->readAsync(65536)) !== '') {   // suspends until DATA arrives; '' at end of stream
            $store->append($chunk);
        }
        $session->respond($stream, [':status' => '201']);
        $stream->markLocalClosed();
    });
});
```

`TH2Stream::writeAsync($bytes, $token = null)` is the fiber-aware write with backpressure. It queues the bytes as `write()` does, then suspends the fiber while more than the stream's high-water mark is queued; each time the data provider drains the queue into DATA frames the fiber is resumed to re-check, and the write returns once the queue is at or below the mark. The mark defaults to the peer's remaining flow-control window for the stream (`TH2Session::getStreamRemoteWindowSize()`; `getRemoteWindowSize()` is the connection window), so the queue holds what the peer can accept; `setWriteHighWaterMark($bytes)` fixes it instead. The bytes are queued in full before the wait, so the queue holds at most the mark plus one write and never stalls on a closed window with nothing queued. A close while the fiber waits throws; a cancelled token throws and leaves the bytes queued. `waitWritable($token = null)` is the bare wait.

`TH2Stream::awaitResponse($token = null)` suspends a client fiber until the final response headers arrive (a `:status` other than 1xx; `hasFinalResponse()` is the check) and returns the headers; a 1xx keeps the wait going, and a stream that ends first (reset, session close) throws. The body then follows through `readAsync()`.

`TH2Session::pump($transport, $token = null)` drives a session over a `TAsyncStream` transport on the loop, so a server or client needs no I/O loop of its own. Each round flushes `send()` to the transport, returns once the session wants no more I/O (a GOAWAY exchange has completed), otherwise waits until the transport is readable or a submit queued output, and feeds what it reads to `receive()`. Every submit wakes a waiting pump (`respond()`, a stream write from another fiber, `goaway()`, ...), so a worker fiber's response reaches the wire without the caller flushing. End of stream on the transport, or a failed read (a peer that resets the connection), closes the session; the caller closes the transport. A cancelled token ends the pump and leaves the session open; a Throwable an event handler raised leaves it through `receive()` with the session still usable. One fiber pumps a session at a time (`http2_pump_in_progress`), and the transport's inner stream must own an open PHP stream resource (`http2_pump_transport`).

```php
use Prado\IO\Async\TAsync;
use Prado\IO\Async\TAsyncStream;
use Prado\IO\Socket\TSocketStream;

// Server: one accepted connection, served on the loop
$session = new TH2Session(true);
$session->submitSettings([]);
$session->attachEventHandler('onRequest', function ($session, $stream) {
    TAsync::run(function () use ($session, $stream) {
        $body = '';
        while (($chunk = $stream->readAsync(65536)) !== '') {
            $body .= $chunk;
        }
        $session->respond($stream, [':status' => '200']);
        $stream->writeAsync(strrev($body));                  // waits while the peer's window is full
        $stream->markLocalClosed();
    });
});
TAsync::run(fn () => $session->pump(new TAsyncStream(new TSocketStream($accepted))));

// Client: request and response in one fiber
$client = new TH2Session(false);
$client->submitSettings([]);
TAsync::run(fn () => $client->pump(new TAsyncStream(TSocketStream::connect('tcp://example.com:80'))));
$result = TAsync::run(function () use ($client) {
    $stream = $client->request([':method' => 'GET', ':scheme' => 'http', ':authority' => 'example.com', ':path' => '/']);
    $stream->markLocalClosed();
    $headers = $stream->awaitResponse();                      // suspends until the final response headers
    $body = '';
    while (($chunk = $stream->readAsync(65536)) !== '') {
        $body .= $chunk;
    }
    $client->goaway();                                        // the pump returns once the GOAWAY is out
    return [$headers[':status'], $body];
})->await();
```

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
vendor/bin/phpstan analyse --memory-limit=512M   # static analysis, level 4, PHP 8.2 to 8.5
```

Unit tests drive a server and a client `TH2Session` against each other in-process (no sockets, no TLS), so they run anywhere `libnghttp2` is installed and skip cleanly where it is not. The functional tests serve a request to the system `curl` over a real socket and complete a TLS handshake that negotiates `h2`. CI runs the whole check on PHP 8.2 through 8.5 against the PRADO `master` branch, on every push and once a week, on Ubuntu 26.04 (its libnghttp2 is new enough); a step fails the run when the library or an HTTP/2 `curl` is missing, so the skips cannot pass silently.

## License

BSD-3-Clause. See [LICENSE](LICENSE).
