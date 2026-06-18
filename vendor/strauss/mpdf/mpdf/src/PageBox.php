<?php
/**
 * @license GPL-2.0-only
 *
 * Modified by wpo on 18-June-2026 using {@see https://github.com/BrianHenryIE/strauss}.
 */

namespace WOI\PDF\Vendor\Mpdf;

class PageBox implements \ArrayAccess
{

	private $container = [];

	public function __construct()
	{
		$this->container = [
			'current' => null,
			'outer_width_LR' => null,
			'outer_width_TB' => null,
			'using' => null,
		];
	}

	/**
	 * @return void
	 */
	#[\ReturnTypeWillChange]
	public function offsetSet($offset, $value)
	{
		if (!$this->offsetExists($offset)) {
			throw new \WOI\PDF\Vendor\Mpdf\MpdfException('Invalid key to set for PageBox');
		}

		$this->container[$offset] = $value;
	}

	/**
	 * @return bool
	 */
	#[\ReturnTypeWillChange]
	public function offsetExists($offset)
	{
		return array_key_exists($offset, $this->container);
	}

	/**
	 * @return void
	 */
	#[\ReturnTypeWillChange]
	public function offsetUnset($offset)
	{
		if (!$this->offsetExists($offset)) {
			throw new \WOI\PDF\Vendor\Mpdf\MpdfException('Invalid key to set for PageBox');
		}

		$this->container[$offset] = null;
	}

	/**
	 * @return mixed
	 */
	#[\ReturnTypeWillChange]
	public function offsetGet($offset)
	{
		if (!$this->offsetExists($offset)) {
			throw new \WOI\PDF\Vendor\Mpdf\MpdfException('Invalid key to set for PageBox');
		}

		return $this->container[$offset];
	}

}
