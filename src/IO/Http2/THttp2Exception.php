<?php

/**
 * THttp2Exception class file.
 *
 * @author Brad Anderson <belisoful@icloud.com>
 * @link https://github.com/pradosoft/prado-http2
 * @license https://github.com/pradosoft/prado/blob/master/LICENSE
 */

namespace Prado\IO\Http2;

use Prado\Exceptions\TIOException;

/**
 * THttp2Exception class.
 *
 * Reports an HTTP/2 failure: the libnghttp2 library cannot be loaded, or an nghttp2 session
 * call returns an error.  It extends {@see TIOException}, so existing IO error handling catches
 * it.
 *
 * @author Brad Anderson <belisoful@icloud.com>
 * @since 1.0.0
 */
class THttp2Exception extends TIOException
{
}
