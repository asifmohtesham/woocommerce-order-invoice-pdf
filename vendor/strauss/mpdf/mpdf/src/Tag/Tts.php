<?php
/**
 * @license GPL-2.0-only
 *
 * Modified by wpo on 18-June-2026 using {@see https://github.com/BrianHenryIE/strauss}.
 */

namespace WOI\PDF\Vendor\Mpdf\Tag;

class Tts extends SubstituteTag
{

	public function open($attr, &$ahtml, &$ihtml)
	{
		$this->mpdf->tts = true;
		$this->mpdf->InlineProperties['TTS'] = $this->mpdf->saveInlineProperties();
		$this->mpdf->setCSS(['FONT-FAMILY' => 'csymbol', 'FONT-WEIGHT' => 'normal', 'FONT-STYLE' => 'normal'], 'INLINE');
	}

}
