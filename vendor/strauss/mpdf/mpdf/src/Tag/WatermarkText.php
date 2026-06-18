<?php
/**
 * @license GPL-2.0-only
 *
 * Modified by wpo on 18-June-2026 using {@see https://github.com/BrianHenryIE/strauss}.
 */

namespace WOI\PDF\Vendor\Mpdf\Tag;

class WatermarkText extends Tag
{

	public function open($attr, &$ahtml, &$ihtml)
	{
		$txt = '';
		if (!empty($attr['CONTENT'])) {
			$txt = htmlspecialchars_decode($attr['CONTENT'], ENT_QUOTES);
		}

		$alpha = -1;
		if (isset($attr['ALPHA']) && $attr['ALPHA'] > 0) {
			$alpha = $attr['ALPHA'];
		}
		$this->mpdf->SetWatermarkText($txt, $alpha);
	}

	public function close(&$ahtml, &$ihtml)
	{
	}
}
