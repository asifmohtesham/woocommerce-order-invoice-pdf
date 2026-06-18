<?php

/**
 * This file is part of FPDI
 *
 * @package   setasign\Fpdi
 * @copyright Copyright (c) 2026 Setasign GmbH & Co. KG (https://www.setasign.com)
 * @license   http://opensource.org/licenses/mit-license The MIT License
 *
 * Modified by wpo on 18-June-2026 using {@see https://github.com/BrianHenryIE/strauss}.
 */

namespace WOI\PDF\Vendor\setasign\Fpdi;

use WOI\PDF\Vendor\setasign\Fpdi\PdfParser\CrossReference\CrossReferenceException;
use WOI\PDF\Vendor\setasign\Fpdi\PdfParser\PdfParserException;
use WOI\PDF\Vendor\setasign\Fpdi\PdfParser\Type\PdfIndirectObject;
use WOI\PDF\Vendor\setasign\Fpdi\PdfParser\Type\PdfNull;

/**
 * Class Fpdi
 *
 * This class let you import pages of existing PDF documents into a reusable structure for FPDF.
 */
class Fpdi extends FpdfTpl
{
    use FpdiTrait;
    use FpdfTrait;

    /**
     * FPDI version
     *
     * @string
     */
    const VERSION = '2.6.8';
}
