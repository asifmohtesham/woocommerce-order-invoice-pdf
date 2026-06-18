<?php
/**
 * @license GPL-2.0-only
 *
 * Modified by wpo on 18-June-2026 using {@see https://github.com/BrianHenryIE/strauss}.
 */

namespace WOI\PDF\Vendor\Mpdf\Barcode;

interface BarcodeInterface
{

	/**
	 * @return string
	 */
	public function getType();

	/**
	 * @return mixed[]
	 */
	public function getData();

	/**
	 * @param string $key
	 *
	 * @return mixed
	 */
	public function getKey($key);

	/**
	 * @return string
	 */
	public function getChecksum();

}
