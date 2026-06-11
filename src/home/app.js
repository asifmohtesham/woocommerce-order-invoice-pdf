import { useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import {
	Button,
	Card,
	CardBody,
	CardHeader,
	Flex,
	FlexItem,
} from '@wordpress/components';

function checklistUrl( data, item ) {
	const params = new URLSearchParams( {
		page: 'woi_pdf_options_page',
		tab: item.tab,
	} );

	if ( item.section ) {
		params.set( 'section', item.section );
	}

	return data.adminUrl + 'admin.php?' + params.toString() + ( item.anchor ? '#' + item.anchor : '' );
}

function SetupChecklist( { data } ) {
	const storageKey = 'woiPdfHomeChecklistDismissed';
	const [ dismissed, setDismissed ] = useState( window.localStorage.getItem( storageKey ) === '1' );
	const items = Object.values( data.checklist );
	const done = items.filter( ( item ) => item.done ).length;

	if ( dismissed || done === items.length ) {
		return null;
	}

	return (
		<Card className="woi-home-checklist">
			<CardHeader>
				<strong>{ __( 'Finish setting up PDF Invoices', 'woocommerce-orders-invoice-pdf' ) }</strong>
				<Flex justify="flex-end" gap={ 2 }>
					<FlexItem>{ done }/{ items.length }</FlexItem>
					<Button
						size="small"
						variant="tertiary"
						onClick={ () => {
							window.localStorage.setItem( storageKey, '1' );
							setDismissed( true );
						} }
					>
						{ __( 'Dismiss', 'woocommerce-orders-invoice-pdf' ) }
					</Button>
				</Flex>
			</CardHeader>
			<CardBody>
				<div className="woi-home-progress">
					<div className="woi-home-progress-bar" style={ { width: `${ ( done / items.length ) * 100 }%` } } />
				</div>
				<ul>
					{ items.map( ( item ) => (
						<li key={ item.id } className={ item.done ? 'done' : 'todo' }>
							{ item.done ? '✓ ' : '○ ' }
							{ item.done ? item.label : <a href={ checklistUrl( data, item ) }>{ item.label } ›</a> }
						</li>
					) ) }
				</ul>
			</CardBody>
		</Card>
	);
}

function DocumentCard( { doc, data } ) {
	const [ enabled, setEnabled ] = useState( doc.enabled );
	const [ busy, setBusy ] = useState( false );

	const enable = async () => {
		setBusy( true );

		const body = new FormData();
		body.set( 'action', 'woi_pdf_enable_document' );
		body.set( 'security', data.nonce );
		body.set( 'document_type', doc.type );

		const response = await window.fetch( data.ajaxUrl, { method: 'POST', body } );
		const json = await response.json();

		setBusy( false );

		if ( json.success ) {
			setEnabled( true );
		}
	};

	return (
		<Card className={ 'woi-home-doc-card' + ( enabled ? '' : ' is-off' ) }>
			<CardHeader>
				<strong>{ doc.title }</strong>
				<span className={ 'woi-pill ' + ( enabled ? 'on' : 'off' ) }>
					{ enabled ? __( 'Enabled', 'woocommerce-orders-invoice-pdf' ) : __( 'Off', 'woocommerce-orders-invoice-pdf' ) }
				</span>
			</CardHeader>
			<CardBody>
				{ enabled && doc.next_number !== null && (
					<p>{ __( 'Next number:', 'woocommerce-orders-invoice-pdf' ) } <strong>{ doc.next_number }</strong></p>
				) }
				{ enabled && (
					<p>
						{ doc.email_count > 0
							? __( 'Attached to', 'woocommerce-orders-invoice-pdf' ) + ' ' + doc.email_count + ' ' + __( 'email(s)', 'woocommerce-orders-invoice-pdf' )
							: __( 'Manual download only', 'woocommerce-orders-invoice-pdf' ) }
					</p>
				) }
				<Flex justify="flex-start" gap={ 2 }>
					{ enabled ? (
						<>
							<Button variant="link" href={ doc.settings_url }>{ __( 'Settings ›', 'woocommerce-orders-invoice-pdf' ) }</Button>
							<Button variant="link" href={ doc.customise_url }>{ __( 'Customise ›', 'woocommerce-orders-invoice-pdf' ) }</Button>
						</>
					) : (
						<Button variant="secondary" size="small" isBusy={ busy } onClick={ enable }>
							{ __( 'Enable', 'woocommerce-orders-invoice-pdf' ) }
						</Button>
					) }
				</Flex>
			</CardBody>
		</Card>
	);
}

function QuickActions( { data } ) {
	const [ syncing, setSyncing ] = useState( false );
	const [ syncDone, setSyncDone ] = useState( false );

	const syncAddress = async () => {
		setSyncing( true );

		const fields = [
			'shop_address_line_1',
			'shop_address_line_2',
			'shop_address_country',
			'shop_address_state',
			'shop_address_city',
			'shop_address_postcode',
		];

		await Promise.all( fields.map( ( field ) => {
			const body = new FormData();
			body.set( 'action', 'woi_pdf_sync_address' );
			body.set( 'security', data.nonce );
			body.set( 'address_field', field );
			return window.fetch( data.ajaxUrl, { method: 'POST', body } );
		} ) );

		setSyncing( false );
		setSyncDone( true );
	};

	return (
		<Card className="woi-home-quick-actions">
			<CardBody>
				<Flex justify="flex-start" gap={ 2 } wrap>
					<strong>{ __( 'Quick actions', 'woocommerce-orders-invoice-pdf' ) }</strong>
					<Button variant="secondary" href={ data.urls.previewInvoice }>
						{ __( 'Preview last invoice', 'woocommerce-orders-invoice-pdf' ) }
					</Button>
					<Button variant="secondary" href={ data.urls.setNextNumber }>
						{ __( 'Set next number', 'woocommerce-orders-invoice-pdf' ) }
					</Button>
					<Button variant="secondary" isBusy={ syncing } onClick={ syncAddress }>
						{ syncDone
							? __( 'Address synced ✓', 'woocommerce-orders-invoice-pdf' )
							: __( 'Sync shop address', 'woocommerce-orders-invoice-pdf' ) }
					</Button>
					<Button variant="secondary" href={ data.urls.customiser }>
						{ __( 'Open Customiser', 'woocommerce-orders-invoice-pdf' ) }
					</Button>
				</Flex>
			</CardBody>
		</Card>
	);
}

export default function App( { data } ) {
	return (
		<div className="woi-home">
			<SetupChecklist data={ data } />
			<div className="woi-home-cards">
				{ data.documents.map( ( doc ) => (
					<DocumentCard key={ doc.type } doc={ doc } data={ data } />
				) ) }
			</div>
			<QuickActions data={ data } />
		</div>
	);
}
