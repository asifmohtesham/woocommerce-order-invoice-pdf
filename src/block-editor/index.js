import { createRoot, useState, useEffect } from '@wordpress/element';
import {
	BlockList,
	BlockTools,
	BlockEditorProvider,
	WritingFlow,
	ObserveTyping,
	Inserter,
} from '@wordpress/block-editor';
import { parse, serialize, registerBlockCollection } from '@wordpress/blocks';
import { Button, Popover, SlotFillProvider } from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import { registerTokenBlocks } from './blocks/token';
import { registerTextBlock } from './blocks/text';
import { registerLayoutBlocks } from './blocks/layout';
import { registerColumnsBlocks, registerHeaderRowVariation } from './blocks/columns';
import { saveBlocks, setActiveSource } from './store';
import PreviewPanel from './PreviewPanel';
import { injectLayoutStyles, LAYOUTS } from './layout';

// Register our blocks; group them under an "Invoice" heading in the inserter.
registerBlockCollection( 'woi', { title: __( 'Invoice', 'woocommerce-orders-invoice-pdf' ) } );
registerTextBlock();
registerTokenBlocks();
registerLayoutBlocks();
registerColumnsBlocks();
registerHeaderRowVariation();
injectLayoutStyles();

function readLayout() {
	try { return window.localStorage.getItem( 'woiBlockEditorLayout' ) || 'full'; } catch ( e ) { return 'full'; }
}

function Editor( { initial, activeSource } ) {
	const [ blocks, setBlocks ] = useState( initial );
	const [ status, setStatus ] = useState( '' );
	const [ source, setSource ] = useState( activeSource );
	const [ layout, setLayout ] = useState( readLayout );
	const [ overlayOpen, setOverlayOpen ] = useState( false );

	// Toggle the body fullscreen class (hides WP chrome scroll) for full mode.
	useEffect( () => {
		document.body.classList.toggle( 'woi-block-fullscreen', 'full' === layout );
		return () => document.body.classList.remove( 'woi-block-fullscreen' );
	}, [ layout ] );

	function applyLayout( mode ) {
		setLayout( mode );
		try { window.localStorage.setItem( 'woiBlockEditorLayout', mode ); } catch ( e ) {}
	}

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
		} catch ( e ) { /* keep prior on failure */ }
	}

	const previewHidden = 'overlay' === layout && ! overlayOpen;

	return (
		<div className="woi-block-shell" data-layout={ layout }>
			<div className="woi-block-main">
				<div className="woi-block-toolbar" style={ { display: 'flex', gap: '8px', alignItems: 'center', marginBottom: '8px', flexWrap: 'wrap' } }>
					<Button variant="primary" onClick={ onSave }>{ __( 'Save', 'woocommerce-orders-invoice-pdf' ) }</Button>
					<label>{ __( 'PDF source:', 'woocommerce-orders-invoice-pdf' ) }</label>
					<select value={ source } onChange={ ( e ) => onSource( e.target.value ) }>
						<option value="grapesjs">{ __( 'GrapesJS', 'woocommerce-orders-invoice-pdf' ) }</option>
						<option value="blocks">{ __( 'Block editor', 'woocommerce-orders-invoice-pdf' ) }</option>
					</select>
					<span aria-live="polite">{ status }</span>
					<span className="woi-block-layout-switch" role="group" aria-label={ __( 'Editor layout', 'woocommerce-orders-invoice-pdf' ) } style={ { marginLeft: 'auto', display: 'inline-flex', gap: '4px' } }>
						{ LAYOUTS.map( ( l ) => (
							<button
								key={ l.id }
								type="button"
								className={ 'button' + ( layout === l.id ? ' button-primary' : '' ) }
								onClick={ () => applyLayout( l.id ) }
							>{ l.label }</button>
						) ) }
						{ 'overlay' === layout ? (
							<button type="button" className="button" onClick={ () => setOverlayOpen( ( o ) => ! o ) }>
								{ overlayOpen ? __( 'Hide preview', 'woocommerce-orders-invoice-pdf' ) : __( 'Show preview', 'woocommerce-orders-invoice-pdf' ) }
							</button>
						) : null }
					</span>
				</div>
				<SlotFillProvider>
					<BlockEditorProvider value={ blocks } onInput={ setBlocks } onChange={ setBlocks }>
						<div className="woi-block-canvas" style={ { border: '1px solid #ddd', background: '#fff', minHeight: '60vh' } }>
							<BlockTools>
								<div style={ { padding: '8px' } }><Inserter rootClientId={ undefined } isAppender /></div>
								<WritingFlow>
									<ObserveTyping>
										<BlockList />
									</ObserveTyping>
								</WritingFlow>
							</BlockTools>
						</div>
						{ /* Default render target for block toolbar / dropdown popovers.
						     Without this Slot, Popover-based UI anchors to the document
						     origin (top-left) instead of the selected block. */ }
						<Popover.Slot />
					</BlockEditorProvider>
				</SlotFillProvider>
			</div>
			<PreviewPanel blocks={ blocks } source={ source } hidden={ previewHidden } />
		</div>
	);
}

const mount = document.getElementById( 'woi-block-editor-root' );
if ( mount && window.woiBlocks ) {
	const initial = window.woiBlocks.storedMarkup ? parse( window.woiBlocks.storedMarkup ) : [];
	createRoot( mount ).render( <Editor initial={ initial } activeSource={ window.woiBlocks.activeSource || 'grapesjs' } /> );
}
