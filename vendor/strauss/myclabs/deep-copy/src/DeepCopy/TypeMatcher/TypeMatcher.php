<?php
/**
 * @license MIT
 *
 * Modified by wpo on 18-June-2026 using {@see https://github.com/BrianHenryIE/strauss}.
 */

namespace WOI\PDF\Vendor\DeepCopy\TypeMatcher;

class TypeMatcher
{
    /**
     * @var string
     */
    private $type;

    /**
     * @param string $type
     */
    public function __construct($type)
    {
        $this->type = $type;
    }

    /**
     * @param mixed $element
     *
     * @return boolean
     */
    public function matches($element)
    {
        return is_object($element) ? is_a($element, $this->type) : gettype($element) === $this->type;
    }
}
