import { useRef, useCallback, forwardRef, useImperativeHandle } from '@wordpress/element';
import { useSelect } from '@wordpress/data';
import { __ } from '@wordpress/i18n';
import { STORE } from './previewStore';
import { renderPdfPreview } from './pdfPreview';

// PDF-only preview panel. The A4 block canvas is now the live HTML view, so the
// former Live HTML tab and its own order search are gone; the order is chosen by
// the toolbar OrderPicker and read from the woi/preview store.
//
// forwardRef so the editor header's "Render PDF" button can trigger a render and
// scroll the panel into view (the panel sits below the full-height editor, so a
// header action is the discoverable way to reach it).
const PreviewPanel = forwardRef( function PreviewPanel( { blocks, source }, ref ) {
	const stageRef = useRef( null );
	const rootRef = useRef( null );
	const orderId = useSelect( ( select ) => select( STORE ).getOrderId(), [] );

	const renderPdf = useCallback( () => {
		renderPdfPreview( {
			stageEl: stageRef.current,
			blocks,
			orderId,
			onStatus: () => {},
		} );
	}, [ blocks, orderId ] );

	useImperativeHandle( ref, () => ( {
		render: renderPdf,
		reveal: () => {
			if ( rootRef.current ) {
				rootRef.current.scrollIntoView( { behavior: 'smooth', block: 'start' } );
			}
		},
	} ), [ renderPdf ] );

	return (
		<div className="woi-block-preview" ref={ rootRef }>
			<div
				className="woi-block-preview-bar"
				style={ {
					display: 'flex',
					gap: '8px',
					alignItems: 'center',
					padding: '8px',
					flexWrap: 'wrap',
				} }
			>
				<strong>
					{ __( 'PDF preview', 'woocommerce-orders-invoice-pdf' ) }
				</strong>
				<button
					type="button"
					className="button button-primary"
					onClick={ renderPdf }
				>
					{ __( 'Render PDF', 'woocommerce-orders-invoice-pdf' ) }
				</button>
				{ 'blocks' !== source ? (
					<span style={ { color: '#b32d2e' } }>
						{ __(
							'PDF reflects the active source. Set "PDF source" to "Block editor" above to preview the block design.',
							'woocommerce-orders-invoice-pdf'
						) }
					</span>
				) : null }
			</div>
			<div
				className="woi-a4-scroll"
				style={ {
					flex: '1',
					overflow: 'auto',
					background: '#525659',
					padding: '16px',
					display: 'flex',
					flexDirection: 'column',
					alignItems: 'center',
					gap: '16px',
				} }
			>
				<div
					className="woi-a4-stage"
					ref={ stageRef }
					style={ {
						width: 'min(100%, 820px)',
						display: 'flex',
						flexDirection: 'column',
						alignItems: 'stretch',
						gap: '16px',
					} }
				/>
			</div>
		</div>
	);
} );

export default PreviewPanel;
