import { createRoot, useState } from '@wordpress/element';
import {
	BlockList,
	BlockTools,
	BlockEditorProvider,
	WritingFlow,
	ObserveTyping,
	Inserter,
} from '@wordpress/block-editor';
import { parse, serialize, registerBlockCollection } from '@wordpress/blocks';
import { Button } from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import { registerTokenBlocks } from './blocks/token';
import { registerTextBlock } from './blocks/text';
import { registerLayoutBlocks } from './blocks/layout';
import { registerColumnsBlocks, registerHeaderRowVariation } from './blocks/columns';
import { saveBlocks, setActiveSource } from './store';

// Register our blocks (core blocks not used in slice 1, but registering the
// collection groups ours under an "Invoice" heading in the inserter).
registerBlockCollection( 'woi', { title: __( 'Invoice', 'woocommerce-orders-invoice-pdf' ) } );
registerTextBlock();
registerTokenBlocks();
registerLayoutBlocks();
registerColumnsBlocks();
registerHeaderRowVariation();

function Editor( { initial, activeSource } ) {
	const [ blocks, setBlocks ] = useState( initial );
	const [ status, setStatus ] = useState( '' );
	const [ source, setSource ] = useState( activeSource );

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

	return (
		<div className="woi-block-shell">
			<div className="woi-block-toolbar" style={ { display: 'flex', gap: '8px', alignItems: 'center', marginBottom: '8px' } }>
				<Button variant="primary" onClick={ onSave }>{ __( 'Save', 'woocommerce-orders-invoice-pdf' ) }</Button>
				<label>{ __( 'PDF source:', 'woocommerce-orders-invoice-pdf' ) }</label>
				<select value={ source } onChange={ ( e ) => onSource( e.target.value ) }>
					<option value="grapesjs">{ __( 'GrapesJS', 'woocommerce-orders-invoice-pdf' ) }</option>
					<option value="blocks">{ __( 'Block editor', 'woocommerce-orders-invoice-pdf' ) }</option>
				</select>
				<span aria-live="polite">{ status }</span>
			</div>
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
			</BlockEditorProvider>
		</div>
	);
}

const mount = document.getElementById( 'woi-block-editor-root' );
if ( mount && window.woiBlocks ) {
	const initial = window.woiBlocks.storedMarkup ? parse( window.woiBlocks.storedMarkup ) : [];
	createRoot( mount ).render( <Editor initial={ initial } activeSource={ window.woiBlocks.activeSource || 'grapesjs' } /> );
}
