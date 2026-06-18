<?php
/**
 * @license MIT
 *
 * Modified by wpo on 18-June-2026 using {@see https://github.com/BrianHenryIE/strauss}.
 */

namespace WOI\PDF\Vendor\Mpdf\PsrLogAwareTrait;

use WOI\PDF\Vendor\Psr\Log\LoggerInterface;

trait PsrLogAwareTrait 
{

	/**
	 * @var \WOI\PDF\Vendor\Psr\Log\LoggerInterface
	 */
	protected $logger;

	public function setLogger(LoggerInterface $logger): void
	{
		$this->logger = $logger;
	}
	
}
