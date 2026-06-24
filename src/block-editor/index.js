import { createRoot, useReducer, useState, useEffect, useCallback, useRef } from '@wordpress/element';
import {
	BlockTools,
	BlockEditorProvider,
	BlockInspector,
	Inserter,
	ListView as StableListView,
	__experimentalListView as ExperimentalListView,
} from '@wordpress/block-editor';
import { parse, serialize, createBlock, registerBlockCollection } from '@wordpress/blocks';
import {
	Button,
	Popover,
	SlotFillProvider,
	Spinner,
	TabPanel,
	SelectControl,
	ToggleControl,
	ColorPalette,
} from '@wordpress/components';
import { useSelect, useDispatch, select as dataSelect } from '@wordpress/data';
import { InterfaceSkeleton } from '@wordpress/interface';
import {
	cog,
	plus,
	undo as undoIcon,
	redo as redoIcon,
	listView as listViewIcon,
	fullscreen as fullscreenIcon,
} from '@wordpress/icons';
import { __ } from '@wordpress/i18n';
import ErrorBoundary from './ErrorBoundary';

// WordPress renamed the block list-view export from `__experimentalListView` to
// the stable `ListView` partway through the 6.x line. The externalized
// `wp.blockEditor` global belongs to the LIVE site's WP, not our build — so the
// stable name is `undefined` on older installs and rendering it throws React
// error #130 ("Element type is invalid: got undefined"), which without a boundary
// unmounts the whole editor. Resolve whichever name the live WP actually exposes.
const ListView = StableListView || ExperimentalListView;
import { historyReducer, initHistory, canUndo, canRedo } from './history';
import { registerTokenBlocks } from './blocks/token';
import { registerTextBlock } from './blocks/text';
import { registerLayoutBlocks } from './blocks/layout';
import {
	registerColumnsBlocks,
	registerHeaderRowVariation,
} from './blocks/columns';
import { registerTableBlock } from './blocks/table';
import { saveBlocks, setActiveSource, saveDocOptions } from './store';
import { STORE } from './previewStore';
import OrderPicker from './OrderPicker';
import Canvas from './canvas/Canvas';
import injectCanvasStyles from './canvas/canvasStyles';
import { optionsCss } from './optionsCss';
import { downloadPdf } from './pdfPreview';
import { fetchOrderTokens } from './preview';
import { patchLineItems } from './patchLineItems';
import ColumnEditor from './ColumnEditor';
import TotalsEditor from './TotalsEditor';
import CustomBlocksEditor from './CustomBlocksEditor';
import SortBundlePanel from './SortBundlePanel';
import CustomCssPanel from './CustomCssPanel';
import NamingPanel from './NamingPanel';

// Register our blocks; group them under an "Invoice" heading in the inserter.
registerBlockCollection( 'woi', {
	title: __( 'Invoice', 'woocommerce-orders-invoice-pdf' ),
} );
registerTextBlock();
registerTokenBlocks();
registerLayoutBlocks();
registerColumnsBlocks();
registerHeaderRowVariation();
registerTableBlock();
injectCanvasStyles();

// The redesigned default invoice as a sequence of section blocks. Built with
// createBlock (not parsed markup) so there's no save/markup validation mismatch.
// Each section block emits its canonical visual-document.css section at render.
const DEFAULT_TEMPLATE_SECTIONS = [
	'woi/letterhead',
	'woi/contact-strip',
	'woi/title-meta',
	'woi/parties',
	'woi/line-items',
	'woi/lower',
	'woi/signature',
	// Footer is an mPDF running page-footer (accurate Page X of Y), added by the
	// document wrapper — not a content block.
];
function buildDefaultTemplate() {
	return DEFAULT_TEMPLATE_SECTIONS.map( ( name ) => createBlock( name ) );
}

function Editor( { initial, activeSource } ) {
	const [ history, dispatch ] = useReducer( historyReducer, initial, initHistory );
	const blocks = history.present;
	const [ source, setSource ] = useState( activeSource );
	const [ isSidebarOpen, setIsSidebarOpen ] = useState( true );
	const [ isListViewOpen, setIsListViewOpen ] = useState( false );
	const [ isFullscreen, setIsFullscreen ] = useState( false );
	const [ isSaving, setIsSaving ] = useState( false );
	const [ isDownloading, setIsDownloading ] = useState( false );

	// Fit the editor to the viewport in normal (non-fullscreen) mode. A fixed
	// 80vh height left the page taller than the viewport (admin bar + page
	// heading sit above the editor), so the browser added a PAGE-level scrollbar
	// right next to the settings sidebar's own scrollbar — two scrollbars at the
	// right edge. Sizing the wrap from its real top to the viewport bottom means
	// the page itself doesn't scroll, leaving only the inner panels to scroll.
	//
	// Two-pass, self-correcting: a first estimate (top → viewport bottom) is then
	// corrected by the ACTUAL residual page overflow. We can't compute the chrome
	// below the editor up front — the WP admin footer is position:absolute (so its
	// height isn't reclaimable in flow), #wpbody-content adds padding-bottom, and
	// (verified live) the page can't shrink below the LEFT ADMIN MENU's height
	// (~918px here). Measuring documentElement.scrollHeight after sizing captures
	// all of that in one number; shrink by it (+4px for sub-pixel rounding).
	//
	// Short viewports (the ADAPTIVE branch): when the viewport is shorter than the
	// admin menu, the page MUST scroll no matter how small the editor gets. A
	// bounded editor then shows TWO scrollbars at the right edge — the page's and
	// the settings sidebar's own — which overlap (Firefox hits this because its
	// window chrome leaves a shorter viewport than Chrome at the same monitor).
	// So when residual overflow can't be removed, GROW the editor to its content
	// height instead: the sidebar/canvas stop scrolling internally and ONLY the
	// page scrolls — a single scrollbar. Verified live in Firefox via Playwright.
	// Fullscreen keeps its own 100vh (the is-fullscreen class), so clear inline.
	//
	// The grow target depends on the sidebar/canvas CONTENT height, which arrives
	// ASYNChronously — selecting an order fetches its columns + total rows (REST)
	// inside the Document panel, growing the sidebar to ~3000px AFTER mount. mount
	// + window-resize alone never re-run for that, so the editor would stay sized
	// for the initial (short) content and the sidebar would overflow again. A
	// ResizeObserver on the intrinsic-height content (the sidebar tab panel and the
	// A4 canvas frame) re-runs the fit whenever that content grows/shrinks.
	const wrapRef = useRef( null );
	useEffect( () => {
		const el = wrapRef.current;
		if ( ! el ) { return undefined; }
		let raf = 0;
		let ro;
		const apply = () => {
			if ( isFullscreen ) { el.style.height = ''; return; }
			const de = document.documentElement;
			const top = el.getBoundingClientRect().top;
			const h = Math.max( 600, window.innerHeight - top - 16 );
			el.style.height = h + 'px';
			let overflow = de.scrollHeight - de.clientHeight;
			if ( overflow > 0 ) {
				el.style.height = Math.max( 600, h - overflow - 4 ) + 'px';
				overflow = de.scrollHeight - de.clientHeight;
			}
			if ( overflow > 1 ) {
				// Can't fit — grow so only the page scrolls (one scrollbar).
				const sb = el.querySelector( '.interface-interface-skeleton__sidebar' );
				const cv = el.querySelector( '.woi-canvas-scroll' );
				const hdr = el.querySelector( '.interface-interface-skeleton__header' );
				const need = Math.max( sb ? sb.scrollHeight : 0, cv ? cv.scrollHeight : 0 ) +
					( hdr ? hdr.offsetHeight : 0 );
				el.style.height = Math.max( need + 2, h ) + 'px';
			}
			// (Re)observe the intrinsic-height content that drives `need`, so async
			// data loads / tab switches / panel expansions re-run the fit. These
			// elements size to their content (NOT the editor height), so observing
			// them can't feedback-loop with the height we set above.
			if ( ro ) {
				const tabs = el.querySelector( '.woi-block-sidebar-tabs' );
				const frame = el.querySelector( '.woi-a4-frame' );
				if ( tabs ) { ro.observe( tabs ); }
				if ( frame ) { ro.observe( frame ); }
			}
		};
		const schedule = () => {
			cancelAnimationFrame( raf );
			raf = requestAnimationFrame( apply );
		};
		ro = typeof ResizeObserver !== 'undefined' ? new ResizeObserver( schedule ) : null;
		apply();
		window.addEventListener( 'resize', apply );
		return () => {
			window.removeEventListener( 'resize', apply );
			cancelAnimationFrame( raf );
			if ( ro ) { ro.disconnect(); }
		};
	}, [ isFullscreen ] );

	// Selected order id (drives the Download PDF filename + which order the PDF
	// renders). The order chip itself now lives inside the OrderPicker.
	const { orderId, orderLabel } = useSelect( ( select ) => ( {
		orderId: select( STORE ).getOrderId(),
		orderLabel: select( STORE ).getOrderLabel(),
	} ), [] );
	const { setOrder, patchTokens } = useDispatch( STORE );

	// Merge the partial tokens the column save returns (just {{line_items}}) into
	// the store so the canvas line-items live-update — one light round-trip.
	const applyTokens = useCallback( ( tokens ) => {
		if ( tokens && Object.keys( tokens ).length ) {
			patchTokens( tokens );
		}
	}, [ patchTokens ] );

	// Optimistic, instant canvas update for in-place column edits (title/width/
	// align): patch the current line-items HTML client-side so there's no wait
	// for the slow server save (which still runs in the background to persist +
	// re-confirm). Field-key / add / remove / reorder fall through to the save.
	const onColumnsLiveEdit = useCallback( ( columns ) => {
		const tokens = dataSelect( STORE ).getTokens();
		const html = tokens && tokens[ '{{line_items}}' ];
		if ( ! html ) { return; }
		const patched = patchLineItems( html, columns );
		if ( patched && patched !== html ) {
			patchTokens( { '{{line_items}}': patched } );
		}
	}, [ patchTokens ] );

	// Fallback: re-fetch the current order's tokens (used when the save couldn't
	// render them, e.g. no order selected is a no-op).
	const refreshTokens = useCallback( () => {
		if ( ! orderId ) { return; }
		fetchOrderTokens( orderId ).then( ( res ) => {
			if ( res && res.tokens ) {
				setOrder( { tokens: res.tokens, orderLabel: orderLabel || '', orderId } );
			}
		} ).catch( () => {} );
	}, [ orderId, orderLabel, setOrder ] );

	// Document appearance options (accent / letterhead / density / bilingual /
	// thumbnails / font). Seeded from the server, persisted via saveDocOptions.
	const DEFAULT_DOC_OPTIONS = {
		accent: 'navy',
		header: 'center',
		density: 'comfortable',
		arabic: 'on',
		thumbs: 'on',
		font: 'grotesque',
		borders: 'off',
		stripes: 'off',
		repeat_letterhead: 'off',
		row_color: '',
	};
	const [ docOptions, setDocOptions ] = useState( {
		...DEFAULT_DOC_OPTIONS,
		...( ( window.woiBlocks && window.woiBlocks.docOptions ) || {} ),
	} );
	function onDocOption( key, value ) {
		const next = { ...docOptions, [ key ]: value };
		setDocOptions( next );
		saveDocOptions( next ).catch( () => {} );
	}

	// The A4 block canvas IS the live preview now, so the header action just
	// generates and downloads the PDF (no separate preview panel).
	async function onDownloadPdf() {
		setIsDownloading( true );
		try {
			// The server resolves the filename from the document's configured
			// naming (override -> global -> default); don't hardcode it here.
			await downloadPdf( { blocks, orderId } );
		} catch ( e ) {
			/* errors surface via the browser; keep the button responsive */
		} finally {
			setIsDownloading( false );
		}
	}

	// Hide the admin background scroll while the editor is full screen.
	useEffect( () => {
		document.body.classList.toggle( 'woi-block-fullscreen', isFullscreen );
		return () => document.body.classList.remove( 'woi-block-fullscreen' );
	}, [ isFullscreen ] );

	async function onSave() {
		setIsSaving( true );
		try {
			await saveBlocks( serialize( blocks ) );
		} catch ( e ) {
			/* keep the prior design; the busy state clears in finally */
		} finally {
			setIsSaving( false );
		}
	}

	async function onSource( next ) {
		setSource( next );
		try {
			const r = await setActiveSource( next );
			setSource( r.source );
		} catch ( e ) {
			/* keep prior on failure */
		}
	}

	// Replace the current design with the redesigned default (section blocks).
	async function onResetTemplate() {
		// eslint-disable-next-line no-alert
		if ( ! window.confirm( __( 'Replace the current design with the default redesigned template? This overwrites the current blocks.', 'woocommerce-orders-invoice-pdf' ) ) ) {
			return;
		}
		const next = buildDefaultTemplate();
		dispatch( { type: 'RESET', blocks: next } );
		// Also restore the default appearance (accent navy, sans font, etc.) so a
		// reset is a full clean slate, not just the block structure.
		setDocOptions( DEFAULT_DOC_OPTIONS );
		try {
			await Promise.all( [
				saveBlocks( serialize( next ) ),
				saveDocOptions( DEFAULT_DOC_OPTIONS ),
			] );
		} catch ( e ) {
			/* the editor still shows the reset design; save can be retried */
		}
	}

	const header = (
		<div className="woi-block-header">
			<div className="woi-tb-grp woi-tb-grp--left">
				<Inserter
					rootClientId={ undefined }
					isAppender={ false }
					renderToggle={ ( { onToggle, isOpen } ) => (
						<Button
							icon={ plus }
							label={ __( 'Add block', 'woocommerce-orders-invoice-pdf' ) }
							onClick={ onToggle }
							aria-expanded={ isOpen }
						/>
					) }
				/>
				<Button
					icon={ undoIcon }
					label={ __( 'Undo', 'woocommerce-orders-invoice-pdf' ) }
					onClick={ () => dispatch( { type: 'UNDO' } ) }
					disabled={ ! canUndo( history ) }
				/>
				<Button
					icon={ redoIcon }
					label={ __( 'Redo', 'woocommerce-orders-invoice-pdf' ) }
					onClick={ () => dispatch( { type: 'REDO' } ) }
					disabled={ ! canRedo( history ) }
				/>
				<Button
					icon={ listViewIcon }
					label={ __( 'List view', 'woocommerce-orders-invoice-pdf' ) }
					isPressed={ isListViewOpen }
					onClick={ () => setIsListViewOpen( ( o ) => ! o ) }
				/>
			</div>

			<div className="woi-tb-grp woi-tb-grp--right">
				<OrderPicker />
				<Button
					className="woi-tb-btn"
					variant="secondary"
					onClick={ onDownloadPdf }
					disabled={ isDownloading }
					aria-busy={ isDownloading }
				>
					{ isDownloading ? <Spinner /> : null }
					{ isDownloading ? __( 'Generating…', 'woocommerce-orders-invoice-pdf' ) : __( 'Download PDF', 'woocommerce-orders-invoice-pdf' ) }
				</Button>
				<Button
					className="woi-tb-btn"
					variant="primary"
					onClick={ onSave }
					disabled={ isSaving }
					aria-busy={ isSaving }
				>
					{ isSaving ? <Spinner /> : null }
					{ isSaving ? __( 'Saving…', 'woocommerce-orders-invoice-pdf' ) : __( 'Save', 'woocommerce-orders-invoice-pdf' ) }
				</Button>
				<Button
					icon={ fullscreenIcon }
					label={ __( 'Toggle full screen', 'woocommerce-orders-invoice-pdf' ) }
					isPressed={ isFullscreen }
					onClick={ () => setIsFullscreen( ( f ) => ! f ) }
				/>
				<Button
					icon={ cog }
					label={ __( 'Settings', 'woocommerce-orders-invoice-pdf' ) }
					isPressed={ isSidebarOpen }
					onClick={ () => setIsSidebarOpen( ( o ) => ! o ) }
				/>
			</div>
		</div>
	);

	const sidebar = (
		<TabPanel
			className="woi-block-sidebar-tabs"
			tabs={ [
				{ name: 'document', title: __( 'Document', 'woocommerce-orders-invoice-pdf' ) },
				{ name: 'block', title: __( 'Block', 'woocommerce-orders-invoice-pdf' ) },
			] }
			initialTabName="block"
		>
			{ ( tab ) =>
				'block' === tab.name ? (
					<BlockInspector />
				) : (
					<div className="woi-block-document-panel">
						<div className="insp-sec">{ __( 'Template', 'woocommerce-orders-invoice-pdf' ) }</div>
						<label className="insp-field" htmlFor="woi-pdf-source">
							<span>{ __( 'Source', 'woocommerce-orders-invoice-pdf' ) }</span>
							<select
								id="woi-pdf-source"
								value={ source }
								onChange={ ( e ) => onSource( e.target.value ) }
							>
								<option value="grapesjs">{ __( 'GrapesJS', 'woocommerce-orders-invoice-pdf' ) }</option>
								<option value="blocks">{ __( 'Block editor', 'woocommerce-orders-invoice-pdf' ) }</option>
							</select>
						</label>
						<div className="insp-field">
							<span>{ __( 'Page size', 'woocommerce-orders-invoice-pdf' ) }</span>
							<span className="insp-val">{ __( 'A4 · Portrait', 'woocommerce-orders-invoice-pdf' ) }</span>
						</div>
						<Button
							className="woi-reset-template"
							variant="secondary"
							onClick={ onResetTemplate }
							style={ { marginTop: '8px' } }
						>
							{ __( 'Reset to default template', 'woocommerce-orders-invoice-pdf' ) }
						</Button>
						<p className="insp-note">
							{ __( 'Loads the full redesigned invoice (letterhead, contact, title, bill/ship to, items, bank & totals, signature/QR, footer).', 'woocommerce-orders-invoice-pdf' ) }
						</p>
						<div className="insp-sec">{ __( 'Localisation', 'woocommerce-orders-invoice-pdf' ) }</div>
						<div className="insp-field">
							<span>{ __( 'Second language', 'woocommerce-orders-invoice-pdf' ) }</span>
							<span className="insp-val">{ __( 'Arabic (RTL)', 'woocommerce-orders-invoice-pdf' ) }</span>
						</div>
						<div className="insp-field">
							<span>{ __( 'Currency', 'woocommerce-orders-invoice-pdf' ) }</span>
							<span className="insp-val">{ __( 'AED — د.إ', 'woocommerce-orders-invoice-pdf' ) }</span>
						</div>

						<div className="insp-sec">{ __( 'Appearance', 'woocommerce-orders-invoice-pdf' ) }</div>
						<div className="woi-doc-appearance">
							<SelectControl
								label={ __( 'Accent colour', 'woocommerce-orders-invoice-pdf' ) }
								value={ docOptions.accent }
								options={ [
									{ value: 'navy', label: __( 'Navy', 'woocommerce-orders-invoice-pdf' ) },
									{ value: 'red', label: __( 'Red', 'woocommerce-orders-invoice-pdf' ) },
									{ value: 'mono', label: __( 'Monochrome', 'woocommerce-orders-invoice-pdf' ) },
								] }
								onChange={ ( v ) => onDocOption( 'accent', v ) }
								__nextHasNoMarginBottom
							/>
							<SelectControl
								label={ __( 'Letterhead', 'woocommerce-orders-invoice-pdf' ) }
								value={ docOptions.header }
								options={ [
									{ value: 'center', label: __( 'Centred mark', 'woocommerce-orders-invoice-pdf' ) },
									{ value: 'left', label: __( 'Left-aligned mark', 'woocommerce-orders-invoice-pdf' ) },
									{ label: __( 'Right-aligned mark', 'woocommerce-orders-invoice-pdf' ), value: 'right' },
								] }
								onChange={ ( v ) => onDocOption( 'header', v ) }
								__nextHasNoMarginBottom
							/>
							<SelectControl
								label={ __( 'Table density', 'woocommerce-orders-invoice-pdf' ) }
								value={ docOptions.density }
								options={ [
									{ value: 'comfortable', label: __( 'Comfortable', 'woocommerce-orders-invoice-pdf' ) },
									{ value: 'compact', label: __( 'Compact', 'woocommerce-orders-invoice-pdf' ) },
								] }
								onChange={ ( v ) => onDocOption( 'density', v ) }
								__nextHasNoMarginBottom
							/>
							<SelectControl
								label={ __( 'Font', 'woocommerce-orders-invoice-pdf' ) }
								value={ docOptions.font }
								options={ [
									{ value: 'grotesque', label: __( 'Grotesque (sans)', 'woocommerce-orders-invoice-pdf' ) },
									{ value: 'serif', label: __( 'Serif', 'woocommerce-orders-invoice-pdf' ) },
									{ value: 'mono', label: __( 'Monospace', 'woocommerce-orders-invoice-pdf' ) },
								] }
								onChange={ ( v ) => onDocOption( 'font', v ) }
								__nextHasNoMarginBottom
							/>
							<ToggleControl
								label={ __( 'Arabic (bilingual)', 'woocommerce-orders-invoice-pdf' ) }
								checked={ 'on' === docOptions.arabic }
								onChange={ ( v ) => onDocOption( 'arabic', v ? 'on' : 'off' ) }
								__nextHasNoMarginBottom
							/>
							<ToggleControl
								label={ __( 'Product thumbnails', 'woocommerce-orders-invoice-pdf' ) }
								checked={ 'on' === docOptions.thumbs }
								onChange={ ( v ) => onDocOption( 'thumbs', v ? 'on' : 'off' ) }
								__nextHasNoMarginBottom
							/>
							<ToggleControl
								label={ __( 'Column borders', 'woocommerce-orders-invoice-pdf' ) }
								checked={ 'on' === docOptions.borders }
								onChange={ ( v ) => onDocOption( 'borders', v ? 'on' : 'off' ) }
								__nextHasNoMarginBottom
							/>
							<ToggleControl
								label={ __( 'Striped rows', 'woocommerce-orders-invoice-pdf' ) }
								checked={ 'on' === docOptions.stripes }
								onChange={ ( v ) => onDocOption( 'stripes', v ? 'on' : 'off' ) }
								__nextHasNoMarginBottom
							/>
							<ToggleControl
								label={ __( 'Repeat letterhead on every page', 'woocommerce-orders-invoice-pdf' ) }
								help={ __( 'Shows the letterhead at the top of every PDF page. The preview shows it once; the effect appears in the generated PDF.', 'woocommerce-orders-invoice-pdf' ) }
								checked={ 'on' === docOptions.repeat_letterhead }
								onChange={ ( v ) => onDocOption( 'repeat_letterhead', v ? 'on' : 'off' ) }
								__nextHasNoMarginBottom
							/>
							<p className="woi-doc-colour-label">{ __( 'Line item text colour', 'woocommerce-orders-invoice-pdf' ) }</p>
							<p className="woi-doc-colour-help">{ __( 'Applies one colour to every line-item row (overrides the default greyed Sr # and tax-rate columns). Clear to use the default styling.', 'woocommerce-orders-invoice-pdf' ) }</p>
							<ColorPalette
								value={ docOptions.row_color || '' }
								colors={ [
									{ name: __( 'Black', 'woocommerce-orders-invoice-pdf' ), color: '#1C1A17' },
									{ name: __( 'Navy', 'woocommerce-orders-invoice-pdf' ), color: '#140858' },
									{ name: __( 'Grey', 'woocommerce-orders-invoice-pdf' ), color: '#8A8378' },
									{ name: __( 'Red', 'woocommerce-orders-invoice-pdf' ), color: '#9E0A0E' },
								] }
								onChange={ ( v ) => onDocOption( 'row_color', v || '' ) }
								enableAlpha={ false }
								clearable={ true }
								__nextHasNoMarginBottom
							/>
						</div>

						<p className="insp-note">
							{ __( 'Set the source to "Block editor" to render the PDF from this design. Appearance options apply to the rendered PDF.', 'woocommerce-orders-invoice-pdf' ) }
						</p>

						<div className="insp-sec">{ __( 'Line items columns', 'woocommerce-orders-invoice-pdf' ) }</div>
						<ColumnEditor onTokens={ applyTokens } onSaved={ refreshTokens } onLiveEdit={ onColumnsLiveEdit } orderId={ orderId } />
						<p className="insp-note">
							{ __( 'Reorder, rename, set width and alignment, add or remove columns. Applies to the line-items table.', 'woocommerce-orders-invoice-pdf' ) }
						</p>

						<div className="insp-sec">{ __( 'Total rows', 'woocommerce-orders-invoice-pdf' ) }</div>
						<TotalsEditor onTokens={ applyTokens } onSaved={ refreshTokens } orderId={ orderId } />

						<div className="insp-sec">{ __( 'Custom blocks', 'woocommerce-orders-invoice-pdf' ) }</div>
						<CustomBlocksEditor onSaved={ refreshTokens } />

						<div className="insp-sec">{ __( 'Sorting & bundle', 'woocommerce-orders-invoice-pdf' ) }</div>
						<SortBundlePanel onSaved={ refreshTokens } />

						<div className="insp-sec">{ __( 'Custom CSS', 'woocommerce-orders-invoice-pdf' ) }</div>
						<CustomCssPanel onSaved={ refreshTokens } />

						<div className="insp-sec">{ __( 'Numbering & filename', 'woocommerce-orders-invoice-pdf' ) }</div>
						<NamingPanel />
						<p className="insp-note">
							{ __( 'Sets the numbering series and PDF filename per document type. Shared with the classic settings tabs.', 'woocommerce-orders-invoice-pdf' ) }
						</p>
					</div>
				)
			}
		</TabPanel>
	);

	const content = (
		<BlockTools>
			<Canvas
				previewCss={ ( window.woiBlocks && window.woiBlocks.previewCss ) || '' }
				optionsCss={ optionsCss( docOptions ) }
			/>
		</BlockTools>
	);

	const secondarySidebar = isListViewOpen ? (
		<div className="woi-block-listview">
			<div className="woi-panel-title">{ __( 'Blocks', 'woocommerce-orders-invoice-pdf' ) }</div>
			<ErrorBoundary
				fallback={
					<p style={ { padding: '8px', color: '#757575' } }>
						{ __( 'List view is unavailable.', 'woocommerce-orders-invoice-pdf' ) }
					</p>
				}
			>
				<ListView />
			</ErrorBoundary>
		</div>
	) : undefined;

	return (
		<SlotFillProvider>
			<BlockEditorProvider
				value={ blocks }
				onInput={ ( next ) => dispatch( { type: 'INPUT', blocks: next } ) }
				onChange={ ( next ) => dispatch( { type: 'CHANGE', blocks: next } ) }
			>
				<div ref={ wrapRef } className={ 'woi-block-interface-wrap' + ( isFullscreen ? ' is-fullscreen' : '' ) }>
					<InterfaceSkeleton
						className="woi-block-interface"
						header={ header }
						content={ content }
						sidebar={ isSidebarOpen ? sidebar : undefined }
						secondarySidebar={ secondarySidebar }
						labels={ {
							header: __( 'Editor top bar', 'woocommerce-orders-invoice-pdf' ),
							body: __( 'Editor content', 'woocommerce-orders-invoice-pdf' ),
							sidebar: __( 'Editor settings', 'woocommerce-orders-invoice-pdf' ),
							secondarySidebar: __( 'Block list view', 'woocommerce-orders-invoice-pdf' ),
						} }
					/>
				<Popover.Slot />
				</div>
			</BlockEditorProvider>
		</SlotFillProvider>
	);
}

const mount = document.getElementById( 'woi-block-editor-root' );
if ( mount && window.woiBlocks ) {
	const initial = window.woiBlocks.storedMarkup
		? parse( window.woiBlocks.storedMarkup )
		: [];
	createRoot( mount ).render(
		<Editor
			initial={ initial }
			activeSource={ window.woiBlocks.activeSource || 'grapesjs' }
		/>
	);
}
