<?php
/**
 * This file is part of Swoole.
 *
 * @link     https://www.swoole.com
 * @contact  team@swoole.com
 * @license  https://github.com/swoole/library/blob/master/LICENSE
 */

declare(strict_types=1);

namespace Swoole;

/**
 * FastCGI constants.
 */
class FastCGI
{
    /**
     * Number of bytes in a FCGI_Header.  Future versions of the protocol
     * will not reduce this number.
     */
    const HEADER_LEN = 8;

    /**
     * Format of FCGI_HEADER for unpacking in PHP
     */
    const HEADER_FORMAT = 'Cversion/Ctype/nrequestId/ncontentLength/CpaddingLength/Creserved';

    /**
     * Max content length of a record
     */
    const MAX_CONTENT_LENGTH = 65535;

    /**
     * Value for version component of FCGI_Header
     */
    const VERSION_1 = 1;

    /**
     * Values for type component of FCGI_Header
     */
    const BEGIN_REQUEST = 1;

    const ABORT_REQUEST = 2;

    const END_REQUEST = 3;

    const PARAMS = 4;

    const STDIN = 5;

    const STDOUT = 6;

    const STDERR = 7;

    const DATA = 8;

    const GET_VALUES = 9;

    const GET_VALUES_RESULT = 10;

    const UNKNOWN_TYPE = 11;

    /**
     * Value for requestId component of FCGI_Header
     */
    const DEFAULT_REQUEST_ID = 1;

    /**
     * Mask for flags component of FCGI_BeginRequestBody
     */
    const KEEP_CONN = 1;

    /**
     * Values for role component of FCGI_BeginRequestBody
     */
    const RESPONDER = 1;

    const AUTHORIZER = 2;

    const FILTER = 3;

    /**
     * Values for protocolStatus component of FCGI_EndRequestBody
     */
    const REQUEST_COMPLETE = 0;

    const CANT_MPX_CONN = 1;

    const OVERLOADED = 2;

    const UNKNOWN_ROLE = 3;
}
