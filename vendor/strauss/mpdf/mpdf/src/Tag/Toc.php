<?php
/**
 * @license GPL-2.0-only
 *
 * Modified by wpo on 18-June-2026 using {@see https://github.com/BrianHenryIE/strauss}.
 */

namespace WOI\PDF\Vendor\Mpdf\Tag;

class Toc extends Tag
{

	public function open($attr, &$ahtml, &$ihtml)
	{
		//added custom-tag - set Marker for insertion later of ToC
		$this->tableOfContents->openTagTOC($attr);
	}

	public function close(&$ahtml, &$ihtml)
	{
	}
}
