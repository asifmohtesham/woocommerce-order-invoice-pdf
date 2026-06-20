import { useRef, useCallback } from '@wordpress/element';
import { useSelect } from '@wordpress/data';
import { __ } from '@wordpress/i18n';
import { STORE } from './previewStore';
import { renderPdfPreview } from './pdfPreview';

// PDF-only preview panel. The A4 block canvas is now the live HTML view, so the
// former Live HTML tab and its own order search are gone; the order is chosen by
// the toolbar OrderPicker and read from the woi/preview store.
export default function PreviewPanel( { blocks, source, hidden } ) {
	const stageRef = useRef( null );
	const orderId = useSelect( ( select ) => select( STORE ).getOrderId(), [] );

	const renderPdf = useCallback( () => {
		renderPdfPreview( {
			stageEl: stageRef.current,
			blocks,
			orderId,
			onStatus: () => {},
		} );
	}, [ blocks, orderId ] );

	return (
		<div className="woi-block-preview" hidden={ hidden }>
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
}
