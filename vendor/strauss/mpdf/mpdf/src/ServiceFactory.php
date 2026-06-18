<?php
/**
 * @license GPL-2.0-only
 *
 * Modified by wpo on 18-June-2026 using {@see https://github.com/BrianHenryIE/strauss}.
 */

namespace WOI\PDF\Vendor\Mpdf;

use WOI\PDF\Vendor\Mpdf\Color\ColorConverter;
use WOI\PDF\Vendor\Mpdf\Color\ColorModeConverter;
use WOI\PDF\Vendor\Mpdf\Color\ColorSpaceRestrictor;
use WOI\PDF\Vendor\Mpdf\Css\BorderMerger;
use WOI\PDF\Vendor\Mpdf\Css\CssMerger;
use WOI\PDF\Vendor\Mpdf\Css\CssParser;
use WOI\PDF\Vendor\Mpdf\Css\InlinePropertyConverter;
use WOI\PDF\Vendor\Mpdf\Css\InlineStyleParser;
use WOI\PDF\Vendor\Mpdf\Css\NormalizeProperties;
use WOI\PDF\Vendor\Mpdf\Css\SelectorParser;
use WOI\PDF\Vendor\Mpdf\Css\ShadowParser;
use WOI\PDF\Vendor\Mpdf\File\LocalContentLoader;
use WOI\PDF\Vendor\Mpdf\Fonts\FontCache;
use WOI\PDF\Vendor\Mpdf\Fonts\FontFileFinder;
use WOI\PDF\Vendor\Mpdf\Http\CurlHttpClient;
use WOI\PDF\Vendor\Mpdf\Http\SocketHttpClient;
use WOI\PDF\Vendor\Mpdf\Image\ImageProcessor;
use WOI\PDF\Vendor\Mpdf\Pdf\Protection;
use WOI\PDF\Vendor\Mpdf\Pdf\Protection\UniqidGenerator;
use WOI\PDF\Vendor\Mpdf\Writer\BaseWriter;
use WOI\PDF\Vendor\Mpdf\Writer\BackgroundWriter;
use WOI\PDF\Vendor\Mpdf\Writer\ColorWriter;
use WOI\PDF\Vendor\Mpdf\Writer\BookmarkWriter;
use WOI\PDF\Vendor\Mpdf\Writer\FontWriter;
use WOI\PDF\Vendor\Mpdf\Writer\FormWriter;
use WOI\PDF\Vendor\Mpdf\Writer\ImageWriter;
use WOI\PDF\Vendor\Mpdf\Writer\JavaScriptWriter;
use WOI\PDF\Vendor\Mpdf\Writer\MetadataWriter;
use WOI\PDF\Vendor\Mpdf\Writer\OptionalContentWriter;
use WOI\PDF\Vendor\Mpdf\Writer\PageWriter;
use WOI\PDF\Vendor\Mpdf\Writer\ResourceWriter;
use WOI\PDF\Vendor\Psr\Log\LoggerInterface;

class ServiceFactory
{

	/**
	 * @var \WOI\PDF\Vendor\Mpdf\Container\ContainerInterface|null
	 */
	private $container;

	public function __construct($container = null)
	{
		$this->container = $container;
	}

	public function getServices(
		Mpdf $mpdf,
		LoggerInterface $logger,
		$config,
		$languageToFont,
		$scriptToLanguage,
		$fontDescriptor,
		$bmp,
		$directWrite,
		$wmf
	) {
		$sizeConverter = new SizeConverter($mpdf->dpi, $mpdf->default_font_size, $mpdf, $logger);

		$colorModeConverter = new ColorModeConverter();
		$colorSpaceRestrictor = new ColorSpaceRestrictor(
			$mpdf,
			$colorModeConverter
		);
		$colorConverter = new ColorConverter($mpdf, $colorModeConverter, $colorSpaceRestrictor);

		$tableOfContents = new TableOfContents($mpdf, $sizeConverter);

		$cacheBasePath = $config['tempDir'] . '/mpdf';

		$cache = new Cache($cacheBasePath, $config['cacheCleanupInterval']);
		$fontCache = new FontCache(new Cache($cacheBasePath . '/ttfontdata', $config['cacheCleanupInterval']));

		$fontFileFinder = new FontFileFinder($config['fontDir']);

		if ($this->container && $this->container->has('httpClient')) {
			$httpClient = $this->container->get('httpClient');
		} elseif (\function_exists('curl_init')) {
			$httpClient = new CurlHttpClient($mpdf, $logger);
		} else {
			$httpClient = new SocketHttpClient($logger);
		}

		$localContentLoader = $this->container && $this->container->has('localContentLoader')
			? $this->container->get('localContentLoader')
			: new LocalContentLoader();

		$assetFetcher = $this->container && $this->container->has('assetFetcher')
			? $this->container->get('assetFetcher')
			: new AssetFetcher($mpdf, $localContentLoader, $httpClient, $logger);

		$normalizeProperties = new NormalizeProperties($mpdf, $sizeConverter, $colorConverter);
		$selectorParser = new SelectorParser($mpdf);
		$inlineStyleParser = new InlineStyleParser($normalizeProperties);
		$inlinePropertyConverter = new InlinePropertyConverter($colorConverter);
		$borderMerger = new BorderMerger();

		$cssParser = new CssParser($mpdf, $cache, $sizeConverter, $colorConverter, $assetFetcher);

		$cssMerger = new CssMerger(
			$mpdf,
			$normalizeProperties,
			$inlineStyleParser,
			$selectorParser,
			$inlinePropertyConverter,
			$colorConverter,
			$borderMerger
		);

		$cssManager = new CssManager($cssParser, $cssMerger);

		$otl = new Otl($mpdf, $fontCache);

		$protection = new Protection(new UniqidGenerator());

		$writer = new BaseWriter($mpdf, $protection);

		$gradient = new Gradient($mpdf, $sizeConverter, $colorConverter, $writer);

		$formWriter = new FormWriter($mpdf, $writer);

		$form = new Form($mpdf, $otl, $colorConverter, $writer, $formWriter);

		$hyphenator = new Hyphenator($mpdf);

		$imageProcessor = new ImageProcessor(
			$mpdf,
			$otl,
			$cssManager,
			$sizeConverter,
			$colorConverter,
			$colorModeConverter,
			$cache,
			$languageToFont,
			$scriptToLanguage,
			$assetFetcher,
			$logger
		);

		$tag = new Tag(
			$mpdf,
			$cache,
			$cssManager,
			$form,
			$otl,
			$tableOfContents,
			$sizeConverter,
			$colorConverter,
			$imageProcessor,
			$languageToFont
		);

		$fontWriter = new FontWriter($mpdf, $writer, $fontCache, $fontDescriptor);
		$metadataWriter = new MetadataWriter($mpdf, $writer, $form, $protection, $logger);
		$imageWriter = new ImageWriter($mpdf, $writer);
		$pageWriter = new PageWriter($mpdf, $form, $writer, $metadataWriter);
		$bookmarkWriter = new BookmarkWriter($mpdf, $writer);
		$optionalContentWriter = new OptionalContentWriter($mpdf, $writer);
		$colorWriter = new ColorWriter($mpdf, $writer);
		$backgroundWriter = new BackgroundWriter($mpdf, $writer);
		$javaScriptWriter = new JavaScriptWriter($mpdf, $writer);

		$resourceWriter = new ResourceWriter(
			$mpdf,
			$writer,
			$colorWriter,
			$fontWriter,
			$imageWriter,
			$formWriter,
			$optionalContentWriter,
			$backgroundWriter,
			$bookmarkWriter,
			$metadataWriter,
			$javaScriptWriter,
			$logger
		);

		return [
			'otl' => $otl,
			'bmp' => $bmp,
			'cache' => $cache,
			'cssManager' => $cssManager,
			'directWrite' => $directWrite,
			'fontCache' => $fontCache,
			'fontFileFinder' => $fontFileFinder,
			'form' => $form,
			'gradient' => $gradient,
			'tableOfContents' => $tableOfContents,
			'tag' => $tag,
			'wmf' => $wmf,
			'sizeConverter' => $sizeConverter,
			'colorConverter' => $colorConverter,
			'hyphenator' => $hyphenator,
			'localContentLoader' => $localContentLoader,
			'httpClient' => $httpClient,
			'assetFetcher' => $assetFetcher,
			'imageProcessor' => $imageProcessor,
			'protection' => $protection,

			'languageToFont' => $languageToFont,
			'scriptToLanguage' => $scriptToLanguage,

			'writer' => $writer,
			'fontWriter' => $fontWriter,
			'metadataWriter' => $metadataWriter,
			'imageWriter' => $imageWriter,
			'formWriter' => $formWriter,
			'pageWriter' => $pageWriter,
			'bookmarkWriter' => $bookmarkWriter,
			'optionalContentWriter' => $optionalContentWriter,
			'colorWriter' => $colorWriter,
			'backgroundWriter' => $backgroundWriter,
			'javaScriptWriter' => $javaScriptWriter,
			'resourceWriter' => $resourceWriter
		];
	}

	public function getServiceIds()
	{
		return [
			'otl',
			'bmp',
			'cache',
			'cssManager',
			'directWrite',
			'fontCache',
			'fontFileFinder',
			'form',
			'gradient',
			'tableOfContents',
			'tag',
			'wmf',
			'sizeConverter',
			'colorConverter',
			'hyphenator',
			'localContentLoader',
			'httpClient',
			'assetFetcher',
			'imageProcessor',
			'protection',
			'languageToFont',
			'scriptToLanguage',
			'writer',
			'fontWriter',
			'metadataWriter',
			'imageWriter',
			'formWriter',
			'pageWriter',
			'bookmarkWriter',
			'optionalContentWriter',
			'colorWriter',
			'backgroundWriter',
			'javaScriptWriter',
			'resourceWriter',
		];
	}

}
