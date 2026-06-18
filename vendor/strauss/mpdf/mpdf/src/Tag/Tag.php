<?php
/**
 * @license GPL-2.0-only
 *
 * Modified by wpo on 18-June-2026 using {@see https://github.com/BrianHenryIE/strauss}.
 */

namespace WOI\PDF\Vendor\Mpdf\Tag;

use WOI\PDF\Vendor\Mpdf\Strict;

use WOI\PDF\Vendor\Mpdf\Cache;
use WOI\PDF\Vendor\Mpdf\Color\ColorConverter;
use WOI\PDF\Vendor\Mpdf\CssManager;
use WOI\PDF\Vendor\Mpdf\Form;
use WOI\PDF\Vendor\Mpdf\Image\ImageProcessor;
use WOI\PDF\Vendor\Mpdf\Language\LanguageToFontInterface;
use WOI\PDF\Vendor\Mpdf\Mpdf;
use WOI\PDF\Vendor\Mpdf\Otl;
use WOI\PDF\Vendor\Mpdf\SizeConverter;
use WOI\PDF\Vendor\Mpdf\TableOfContents;

abstract class Tag
{

	use Strict;

	/**
	 * @var \WOI\PDF\Vendor\Mpdf\Mpdf
	 */
	protected $mpdf;

	/**
	 * @var \WOI\PDF\Vendor\Mpdf\Cache
	 */
	protected $cache;

	/**
	 * @var \WOI\PDF\Vendor\Mpdf\CssManager
	 */
	protected $cssManager;

	/**
	 * @var \WOI\PDF\Vendor\Mpdf\Form
	 */
	protected $form;

	/**
	 * @var \WOI\PDF\Vendor\Mpdf\Otl
	 */
	protected $otl;

	/**
	 * @var \WOI\PDF\Vendor\Mpdf\TableOfContents
	 */
	protected $tableOfContents;

	/**
	 * @var \WOI\PDF\Vendor\Mpdf\SizeConverter
	 */
	protected $sizeConverter;

	/**
	 * @var \WOI\PDF\Vendor\Mpdf\Color\ColorConverter
	 */
	protected $colorConverter;

	/**
	 * @var \WOI\PDF\Vendor\Mpdf\Image\ImageProcessor
	 */
	protected $imageProcessor;

	/**
	 * @var \WOI\PDF\Vendor\Mpdf\Language\LanguageToFontInterface
	 */
	protected $languageToFont;

	const ALIGN = [
		'left' => 'L',
		'center' => 'C',
		'right' => 'R',
		'top' => 'T',
		'text-top' => 'TT',
		'middle' => 'M',
		'baseline' => 'BS',
		'bottom' => 'B',
		'text-bottom' => 'TB',
		'justify' => 'J'
	];

	public function __construct(
		Mpdf $mpdf,
		Cache $cache,
		CssManager $cssManager,
		Form $form,
		Otl $otl,
		TableOfContents $tableOfContents,
		SizeConverter $sizeConverter,
		ColorConverter $colorConverter,
		ImageProcessor $imageProcessor,
		LanguageToFontInterface $languageToFont
	) {

		$this->mpdf = $mpdf;
		$this->cache = $cache;
		$this->cssManager = $cssManager;
		$this->form = $form;
		$this->otl = $otl;
		$this->tableOfContents = $tableOfContents;
		$this->sizeConverter = $sizeConverter;
		$this->colorConverter = $colorConverter;
		$this->imageProcessor = $imageProcessor;
		$this->languageToFont = $languageToFont;
	}

	public function getTagName()
	{
		$tag = get_class($this);
		return strtoupper(str_replace('WOI\PDF\Vendor\Mpdf\Tag\\', '', $tag));
	}

	protected function getAlign($property)
	{
		$property = strtolower($property);
		return array_key_exists($property, self::ALIGN) ? self::ALIGN[$property] : '';
	}

	abstract public function open($attr, &$ahtml, &$ihtml);

	abstract public function close(&$ahtml, &$ihtml);

}
