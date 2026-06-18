<?php
/**
 * @license GPL-2.0-only
 *
 * Modified by wpo on 18-June-2026 using {@see https://github.com/BrianHenryIE/strauss}.
 */

namespace WOI\PDF\Vendor\Mpdf;

interface AssetFetcherInterface
{
	/**
	 * Fetch data from a given path, either local or remote.
	 *
	 * @param string $path The path to fetch data from.
	 * @param string|null $originalSrc The original source path, if applicable.
	 * @return string The fetched data.
	 * @throws \WOI\PDF\Vendor\Mpdf\Exception\AssetFetchingException If fetching fails.
	 */
	public function fetchDataFromPath($path, $originalSrc = null);
}
