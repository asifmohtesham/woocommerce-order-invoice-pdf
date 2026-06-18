<?php
/**
 * @license MIT
 *
 * Modified by wpo on 18-June-2026 using {@see https://github.com/BrianHenryIE/strauss}.
 */

namespace WOI\PDF\Vendor\DeepCopy\TypeFilter\Date;

use DateInterval;
use WOI\PDF\Vendor\DeepCopy\TypeFilter\TypeFilter;

/**
 * @final
 *
 * @deprecated Will be removed in 2.0. This filter will no longer be necessary in PHP 7.1+.
 */
class DateIntervalFilter implements TypeFilter
{

    /**
     * {@inheritdoc}
     *
     * @param DateInterval $element
     *
     * @see http://news.php.net/php.bugs/205076
     */
    public function apply($element)
    {
        $copy = new DateInterval('P0D');

        foreach ($element as $propertyName => $propertyValue) {
            $copy->{$propertyName} = $propertyValue;
        }

        return $copy;
    }
}
