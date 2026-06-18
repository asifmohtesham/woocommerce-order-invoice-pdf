<?php
/**
 * @license GPL-2.0-only
 *
 * Modified by wpo on 18-June-2026 using {@see https://github.com/BrianHenryIE/strauss}.
 */

namespace WOI\PDF\Vendor\Mpdf\Tag;

class NewColumn extends Tag
{

	public function open($attr, &$ahtml, &$ihtml)
	{
		$this->mpdf->ignorefollowingspaces = true;
		$this->mpdf->NewColumn();
		$this->mpdf->ColumnAdjust = false; // disables all column height adjustment for the page.
	}

	public function close(&$ahtml, &$ihtml)
	{
	}
}
