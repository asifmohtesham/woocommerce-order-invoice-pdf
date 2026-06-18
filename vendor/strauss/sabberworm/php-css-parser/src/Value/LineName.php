<?php
/**
 * @license MIT
 *
 * Modified by wpo on 18-June-2026 using {@see https://github.com/BrianHenryIE/strauss}.
 */

namespace WOI\PDF\Vendor\Sabberworm\CSS\Value;

use WOI\PDF\Vendor\Sabberworm\CSS\OutputFormat;
use WOI\PDF\Vendor\Sabberworm\CSS\Parsing\ParserState;
use WOI\PDF\Vendor\Sabberworm\CSS\Parsing\UnexpectedEOFException;
use WOI\PDF\Vendor\Sabberworm\CSS\Parsing\UnexpectedTokenException;

class LineName extends ValueList
{
    /**
     * @param array<int, RuleValueList|CSSFunction|CSSString|LineName|Size|URL|string> $aComponents
     * @param int $iLineNo
     */
    public function __construct(array $aComponents = [], $iLineNo = 0)
    {
        parent::__construct($aComponents, ' ', $iLineNo);
    }

    /**
     * @return LineName
     *
     * @throws UnexpectedTokenException
     * @throws UnexpectedEOFException
     *
     * @internal since V8.8.0
     */
    public static function parse(ParserState $oParserState)
    {
        $oParserState->consume('[');
        $oParserState->consumeWhiteSpace();
        $aNames = [];
        do {
            if ($oParserState->getSettings()->bLenientParsing) {
                try {
                    $aNames[] = $oParserState->parseIdentifier();
                } catch (UnexpectedTokenException $e) {
                    if (!$oParserState->comes(']')) {
                        throw $e;
                    }
                }
            } else {
                $aNames[] = $oParserState->parseIdentifier();
            }
            $oParserState->consumeWhiteSpace();
        } while (!$oParserState->comes(']'));
        $oParserState->consume(']');
        return new LineName($aNames, $oParserState->currentLine());
    }

    /**
     * @return string
     *
     * @deprecated in V8.8.0, will be removed in V9.0.0. Use `render` instead.
     */
    public function __toString()
    {
        return $this->render(new OutputFormat());
    }

    /**
     * @param OutputFormat|null $oOutputFormat
     *
     * @return string
     */
    public function render($oOutputFormat)
    {
        return '[' . parent::render(OutputFormat::createCompact()) . ']';
    }
}
