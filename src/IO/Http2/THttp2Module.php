<?php

/**
 * THttp2Module class file.
 *
 * @author Brad Anderson <belisoful@icloud.com>
 * @link https://github.com/pradosoft/prado-http2
 * @license https://github.com/pradosoft/prado/blob/master/LICENSE
 */

namespace Prado\IO\Http2;

use Prado\Util\TPluginModule;

/**
 * THttp2Module class.
 *
 * The bootstrap module of the PRADO HTTP/2 extension, named in `extra.prado.bootstrap` of the
 * package's composer.json.  Extending {@see TPluginModule} registers the adjacent
 * `errorMessages.txt` automatically (its {@see TPluginModule::getErrorFile() error file} is
 * resolved from the module's own directory), so the `http2_*` codes resolve in a PRADO
 * application without any further wiring.
 *
 * @author Brad Anderson <belisoful@icloud.com>
 * @since 1.0.0
 */
class THttp2Module extends TPluginModule
{
}
