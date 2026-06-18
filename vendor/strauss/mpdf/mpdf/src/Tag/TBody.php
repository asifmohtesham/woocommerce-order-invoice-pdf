<?php
/**
 * @license GPL-2.0-only
 *
 * Modified by wpo on 18-June-2026 using {@see https://github.com/BrianHenryIE/strauss}.
 */

namespace WOI\PDF\Vendor\Mpdf\Tag;

class TBody extends Tag
{

	public function open($attr, &$ahtml, &$ihtml)
	{
		$this->mpdf->tablethead = 0;
		$this->mpdf->tabletfoot = 0;
		$this->mpdf->lastoptionaltag = 'TBODY'; // Save current HTML specified optional endtag
		$this->cssManager->tbCSSlvl++;
		$this->cssManager->MergeCSS('TABLE', 'TBODY', $attr);
	}

	public function close(&$ahtml, &$ihtml)
	{
		$this->mpdf->lastoptionaltag = '';
		unset($this->cssManager->tablecascadeCSS[$this->cssManager->tbCSSlvl]);
		$this->cssManager->tbCSSlvl--;
	}
}
