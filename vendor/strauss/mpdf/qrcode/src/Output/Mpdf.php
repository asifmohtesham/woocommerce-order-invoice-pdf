<?php
/**
 * @license LGPL-2.1-or-later
 *
 * Modified by wpo on 21-June-2026 using {@see https://github.com/BrianHenryIE/strauss}.
 */

namespace WOI\PDF\Vendor\Mpdf\QrCode\Output;

use WOI\PDF\Vendor\Mpdf\Mpdf as MpdfObject;
use WOI\PDF\Vendor\Mpdf\QrCode\QrCode;

class Mpdf
{

	/**
	 * Write the QR code into an Mpdf\Mpdf object
	 *
	 * @param \WOI\PDF\Vendor\Mpdf\QrCode\QrCode $qrCode QR code instance
	 * @param \WOI\PDF\Vendor\Mpdf\Mpdf $mpdf Mpdf instance
	 * @param float $x position X
	 * @param float $y position Y
	 * @param float $w QR code width
	 * @param int[] $background RGB background color
	 * @param int[] $color RGB foreground and border color
	 */
	public function output(QrCode $qrCode, MpdfObject $mpdf, $x, $y, $w, $background = [255, 255, 255], $color = [0, 0, 0])
	{
		$size = $w;
		$qrSize = $qrCode->getQrSize();
		$s = $size / $qrCode->getQrDimensions();

		$mpdf->SetDrawColor($color[0], $color[1], $color[2]);
		$mpdf->SetFillColor($background[0], $background[1], $background[2]);

		if ($qrCode->isBorderDisabled()) {
			$minSize = 4;
			$maxSize = $qrSize - 4;
			$mpdf->Rect($x, $y, $size, $size, 'F');
		} else {
			$minSize = 0;
			$maxSize = $qrSize;
			$mpdf->Rect($x, $y, $size, $size, 'FD');
		}

		$mpdf->SetFillColor($color[0], $color[1], $color[2]);

		$final = $qrCode->getFinal();

		for ($j = $minSize; $j < $maxSize; $j++) {
			for ($i = $minSize; $i < $maxSize; $i++) {
				if ($final[$i + $j * $qrSize + 1]) {
					$mpdf->Rect($x + ($i - $minSize) * $s, $y + ($j - $minSize) * $s, $s, $s, 'F');
				}
			}
		}
	}

}
