import { createRoot, useReducer, useState, useEffect, useRef } from '@wordpress/element';
import {
	BlockTools,
	BlockEditorProvider,
	BlockInspector,
	Inserter,
	ListView as StableListView,
	__experimentalListView as ExperimentalListView,
} from '@wordpress/block-editor';
import { parse, serialize, registerBlockCollection } from '@wordpress/blocks';
import {
	Button,
	Popover,
	SlotFillProvider,
	TabPanel,
	SelectControl,
	ToggleControl,
} from '@wordpress/components';
import { useSelect } from '@wordpress/data';
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
import PreviewPanel from './PreviewPanel';

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

function Editor( { initial, activeSource } ) {
	const [ history, dispatch ] = useReducer( historyReducer, initial, initHistory );
	const blocks = history.present;
	const [ status, setStatus ] = useState( '' );
	const [ source, setSource ] = useState( activeSource );
	const [ isSidebarOpen, setIsSidebarOpen ] = useState( true );
	const [ isListViewOpen, setIsListViewOpen ] = useState( false );
	const [ isFullscreen, setIsFullscreen ] = useState( false );
	const previewRef = useRef( null );

	// Order context for the centered toolbar chip. orderLabel is built by the
	// OrderPicker as "#<order_number> — <name>"; split it so the number renders
	// in the navy pill and the (truncated) customer name beside it.
	const { orderId, orderLabel } = useSelect( ( select ) => ( {
		orderId: select( STORE ).getOrderId(),
		orderLabel: select( STORE ).getOrderLabel(),
	} ), [] );
	let chipNo = orderId ? '#' + orderId : '';
	let chipName = orderLabel || '';
	if ( orderLabel ) {
		const dash = orderLabel.indexOf( ' — ' );
		if ( -1 !== dash ) {
			chipNo = orderLabel.slice( 0, dash );
			chipName = orderLabel.slice( dash + 3 );
		}
	}

	// Document appearance options (accent / letterhead / density / bilingual /
	// thumbnails / font). Seeded from the server, persisted via saveDocOptions.
	const DEFAULT_DOC_OPTIONS = {
		accent: 'navy',
		header: 'center',
		density: 'comfortable',
		arabic: 'on',
		thumbs: 'on',
		font: 'grotesque',
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

	// Render the PDF preview (which sits below the full-height editor) and scroll
	// it into view — driven from the header so it's discoverable without scrolling.
	function onRenderPdf() {
		if ( previewRef.current ) {
			previewRef.current.reveal();
			previewRef.current.render();
		}
	}

	// Hide the admin background scroll while the editor is full screen.
	useEffect( () => {
		document.body.classList.toggle( 'woi-block-fullscreen', isFullscreen );
		return () => document.body.classList.remove( 'woi-block-fullscreen' );
	}, [ isFullscreen ] );

	async function onSave() {
		setStatus( __( 'Saving…', 'woocommerce-orders-invoice-pdf' ) );
		try {
			await saveBlocks( serialize( blocks ) );
			setStatus( __( 'Saved.', 'woocommerce-orders-invoice-pdf' ) );
		} catch ( e ) {
			setStatus( __( 'Save failed.', 'woocommerce-orders-invoice-pdf' ) );
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

			<div className="woi-tb-doc">
				{ orderId ? (
					<>
						<span className="woi-tb-docno">{ chipNo }</span>
						<span className="woi-tb-docname">{ chipName }</span>
					</>
				) : null }
			</div>

			<div className="woi-tb-grp woi-tb-grp--right">
				<OrderPicker />
				<span className="woi-tb-status" aria-live="polite">{ status }</span>
				<Button className="woi-tb-btn" variant="secondary" onClick={ onRenderPdf }>
					{ __( 'Render PDF', 'woocommerce-orders-invoice-pdf' ) }
				</Button>
				<Button className="woi-tb-btn" variant="primary" onClick={ onSave }>
					{ __( 'Save', 'woocommerce-orders-invoice-pdf' ) }
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
						</div>

						<p className="insp-note">
							{ __( 'Set the source to "Block editor" to render the PDF from this design. Appearance options apply to the rendered PDF.', 'woocommerce-orders-invoice-pdf' ) }
						</p>
					</div>
				)
			}
		</TabPanel>
	);

	const content = (
		<BlockTools>
			<div style={ { padding: '8px' } }>
				<Inserter rootClientId={ undefined } isAppender />
			</div>
			<Canvas
				previewCss={ ( window.woiBlocks && window.woiBlocks.previewCss ) || '' }
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
				<div className={ 'woi-block-interface-wrap' + ( isFullscreen ? ' is-fullscreen' : '' ) }>
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
				</div>
				<Popover.Slot />
			</BlockEditorProvider>
			<PreviewPanel ref={ previewRef } blocks={ blocks } source={ source } />
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
