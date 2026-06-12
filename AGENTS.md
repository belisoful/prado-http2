# PRADO HTTP/2 Extension Agent Guidelines

`prado-http2` is a PRADO 4 extension that adds HTTP/2 (RFC 9113) to PRADO by binding the system `libnghttp2` through PHP FFI. nghttp2 handles framing, HPACK, stream state, and flow control; the extension is the PHP surface over it, under the PSR-4 namespace `Prado\IO\Http2` (`src/`). See `CLAUDE.md` for the architecture.

## Build, Lint, and Test Commands

- **Unit tests**: `composer unittest` (`vendor/bin/phpunit --testsuite unit`) — two in-process nghttp2 sessions, no sockets/TLS.
- **Functional tests**: `composer functest` (`vendor/bin/phpunit --testsuite functional`) — `curl --http2-prior-knowledge` interop over a real socket.
- **Test filter**: `vendor/bin/phpunit --testsuite unit --filter <function|class>`.
- **PHPStan**: `vendor/bin/phpstan analyse src/ --memory-limit=512M`.
- **PHP CS Fixer**: `vendor/bin/php-cs-fixer fix --dry-run src/` (check) / `vendor/bin/php-cs-fixer fix src/` (apply).
- **Install / update deps**: `composer install` / `composer update`.
- A **full check** is, in order: `php -l` compile → php-cs-fixer → phpstan → phpunit (`unittest`, then `functest` where `libnghttp2` + curl are present). All must pass before a commit.
- NEVER add or change phpunit command options; only run the `unittest`/`functest` scripts. When testing one class or cluster, run only its tests via `--filter`.

## Code Style Guidelines

### PHP Coding Standards
- PHP 8.1 minimum (CI: 8.1, 8.2, 8.3). PSR-12 via php-cs-fixer.
- Indentation: **1 tab**, never spaces. Line endings: Unix (`\n`). All files begin with `<?php`.
- `if` always has a `{}` block (no single-line bodies).
- Use `?` for single nullable types and in doc blocks.
- All class properties declare visibility; properties are `private` with self-encapsulated accessors (see below).

### Naming Conventions
- Classes: `TPascalCase` (`TH2Session`, `TH2Stream`); interfaces `IPascalCase`.
- Methods/variables: `camelCase` (`submitSettings`, `$streamId`).
- Class constants: `SCREAMING_SNAKE_CASE` (`FRAME_HEADERS`, `ERR_DEFERRED`).
- Class properties: `_camelCase` (`$_session`).
- Namespace: `Prado\{Module}` — here `Prado\IO\Http2`.

### Self-Encapsulation (UAP-SE)
- `TComponent` classes use private fields with protected `get<X>Direct()`/`set<X>Direct()` accessors; the public API and all internal code go through them so a subclass can intercept any field.
- Mutable byte buffers and arrays return **by reference** from their `get*Direct()` (as in `TBufferStream`).
- `TH2Session` and `TH2Stream` follow UAP-SE. `TNgHttp2` is a `final` static binding with no instance property system and is exempt.

### Documentation Standards
- Every public method has a PHPDoc block with `@param`/`@return`/`@throws` as applicable, plus at least one descriptive sentence.
- Classes have a clear top docblock (what/why/how, an example where useful) with `@author` and `@since`.
- `@since` uses the current version (`1.0.0`).
- Docblocks are technical, present tense, American English. Banned: antithesis ("not X, it Ys"), em-dash dramatic asides, editorializing/filler, rule-of-three lists. One fact per sentence; prefer subject-verb-object and `condition → result`.

### Error Handling
- Throw `Prado\IO\Http2\THttp2Exception` (extends `TIOException`) for HTTP/2 failures, using error codes from `src/IO/Http2/errorMessages.txt` (`http2_*`). `errorMessages.txt` is for display text only; code throws the code.
- `errorMessages.txt` is registered automatically by `THttp2Module` (it extends `TPluginModule`); the framework `messages.txt` is not used.
- `TNgHttp2::isAvailable()` returns false (rather than throwing) when `libnghttp2` cannot load, so callers can fall back to HTTP/1.1.

### Imports
- PSR-4 autoloading; no manual includes. `use` statements at the top of the file. Extensions do **not** edit the framework `classes.php`.

## HTTP/2 & FFI Specifics

- **libnghttp2 is a system library** resolved at runtime: explicit `TNgHttp2::setLibraryPath()` → `PRADO_NGHTTP2_LIB` env → platform defaults. It is a composer `suggest`/runtime concern, not a hard install requirement of the package.
- **Memory I/O.** A `TH2Session` is transport-agnostic: `receive($bytes)` feeds the session, `send()` drains its output. Pump these over any transport.
- **Callbacks** are PHP closures kept alive on the session (`$_refs`); each session has its own callback set, so two sessions in one process never cross-talk.
- **Data providers** pull outgoing DATA from per-stream queues; deferred until a write resumes the stream. Use `TH2Stream::markLocalClosed()` to finish a finite body (flush then END_STREAM); `close()` tears the stream down.
- **The cdef** in `TNgHttp2` is the only place C declarations live. Extend it there when binding more of the nghttp2 API.
- **FFI gotchas**: call `cast()`/`new()` on the bound instance (`$ffi->cast(...)`); `FFI::addr`/`string`/`memcpy` are static; a `const char*` return arrives as a PHP string.
- **phpstan** cannot prove FFI's dynamic methods or CData fields — `phpstan.neon.dist` ignores `Call to an undefined method FFI::...` and `Access to an undefined property FFI\CData::...`. Keep these.
- **cs-fixer uses tabs** (`@PSR12` + `setIndent("\t")`). If it wants to reformat every file to spaces, the `.php-cs-fixer.dist.php` was replaced by a scaffold (`@auto`); restore the tab config.
- **Out of scope**: HTTP/3 (QUIC needs TLS hooks PHP lacks); TLS/ALPN (the caller's job); web-SAPI hosting (use a long-running process).

### Framework conventions in use
- All classes here extend `TComponent` (or a `Prado\Util`/`Prado\IO` base). Events use the `on` prefix (`onRequest`, `onData`), raised with `raiseEvent('onX', $sender, $param)`. There are no `dy`/`fx` dynamic or global events in this extension.
- `THttp2Module` is the `extra.bootstrap` module (a `TPluginModule`).

## Testing Guidelines
- The testing platform is PHPUnit. All new code must include unit tests asserting typical and edge cases, including error/exception handling.
- Unit tests drive in-process sessions (no network). The functional suite is the curl interop test and self-skips without `libnghttp2`/curl/`proc_open`.
- Tests are isolated (no shared state). When testing one class or cluster, run only its tests.

## Development Environment
- PHP 8.1+; extensions: ffi (required), openssl (h2 over TLS), plus the framework's ctype, dom, intl, json, pcre, spl.
- System library: `libnghttp2` (bound via FFI).
- `pradosoft/prado ^4.4` is a dev dependency; the IO layer it provides is 4.4 (unreleased), so Packagist-resolved CI is red until 4.4 ships.
- Composer for dependency management; presume dependencies are installed.

# PRADO Framework Agent Safeguards — ANTI-PATTERNS

Required without exception:
- NEVER execute these `git` commands without asking the developer first: clone, checkout, mv, restore, rm, branch, add, commit, merge, rebase, reset, pull, push, fetch.
- NEVER execute `rm` on any path without asking the developer first.
- NEVER remove `composer --dev` dependencies; they are required for development.
- NEVER erase or overwrite files for the task of unit testing and fixing; the file changes are what is being tested.
- NEVER delete folders or files until the associated task is absolutely complete.
