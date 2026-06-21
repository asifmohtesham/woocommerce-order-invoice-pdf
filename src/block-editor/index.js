import { createRoot, useState } from '@wordpress/element';
import {
	BlockTools,
	BlockEditorProvider,
	BlockInspector,
	Inserter,
} from '@wordpress/block-editor';
import { parse, serialize, registerBlockCollection } from '@wordpress/blocks';
import { Button, Popover, SlotFillProvider, TabPanel } from '@wordpress/components';
import { InterfaceSkeleton } from '@wordpress/interface';
import { cog } from '@wordpress/icons';
import { __ } from '@wordpress/i18n';
import { registerTokenBlocks } from './blocks/token';
import { registerTextBlock } from './blocks/text';
import { registerLayoutBlocks } from './blocks/layout';
import {
	registerColumnsBlocks,
	registerHeaderRowVariation,
} from './blocks/columns';
import { registerTableBlock } from './blocks/table';
import { saveBlocks, setActiveSource } from './store';
import './previewStore';
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
	const [ blocks, setBlocks ] = useState( initial );
	const [ status, setStatus ] = useState( '' );
	const [ source, setSource ] = useState( activeSource );
	const [ isSidebarOpen, setIsSidebarOpen ] = useState( true );

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
		<div
			className="woi-block-header"
			style={ { display: 'flex', gap: '8px', alignItems: 'center', width: '100%' } }
		>
			<Button variant="primary" onClick={ onSave }>
				{ __( 'Save', 'woocommerce-orders-invoice-pdf' ) }
			</Button>
			<span aria-live="polite">{ status }</span>
			<OrderPicker />
			<div style={ { marginLeft: 'auto' } }>
				<Button
					icon={ cog }
					isPressed={ isSidebarOpen }
					label={ __( 'Settings', 'woocommerce-orders-invoice-pdf' ) }
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
					<div className="woi-block-document-panel" style={ { padding: '16px' } }>
						<label htmlFor="woi-pdf-source" style={ { display: 'block', marginBottom: '4px' } }>
							{ __( 'PDF source:', 'woocommerce-orders-invoice-pdf' ) }
						</label>
						<select
							id="woi-pdf-source"
							value={ source }
							onChange={ ( e ) => onSource( e.target.value ) }
						>
							<option value="grapesjs">{ __( 'GrapesJS', 'woocommerce-orders-invoice-pdf' ) }</option>
							<option value="blocks">{ __( 'Block editor', 'woocommerce-orders-invoice-pdf' ) }</option>
						</select>
						<p style={ { marginTop: '12px', color: '#757575' } }>
							{ __( 'Set the source to "Block editor" to render the PDF from this design.', 'woocommerce-orders-invoice-pdf' ) }
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

	return (
		<SlotFillProvider>
			<BlockEditorProvider value={ blocks } onInput={ setBlocks } onChange={ setBlocks }>
				<div className="woi-block-interface-wrap">
					<InterfaceSkeleton
						className="woi-block-interface"
						header={ header }
						content={ content }
						sidebar={ isSidebarOpen ? sidebar : undefined }
						labels={ {
							header: __( 'Editor top bar', 'woocommerce-orders-invoice-pdf' ),
							body: __( 'Editor content', 'woocommerce-orders-invoice-pdf' ),
							sidebar: __( 'Editor settings', 'woocommerce-orders-invoice-pdf' ),
						} }
					/>
				</div>
				<Popover.Slot />
			</BlockEditorProvider>
			<PreviewPanel blocks={ blocks } source={ source } />
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
