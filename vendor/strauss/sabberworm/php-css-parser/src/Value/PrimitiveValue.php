<?php
/**
 * @license MIT
 *
 * Modified by wpo on 18-June-2026 using {@see https://github.com/BrianHenryIE/strauss}.
 */

namespace WOI\PDF\Vendor\Sabberworm\CSS\Value;

abstract class PrimitiveValue extends Value
{
    /**
     * @param int $iLineNo
     */
    public function __construct($iLineNo = 0)
    {
        parent::__construct($iLineNo);
    }
}
