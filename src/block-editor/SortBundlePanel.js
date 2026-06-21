import { useState, useEffect } from '@wordpress/element';
import { SelectControl, Spinner } from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import { getEditorConfig, saveEditorConfig } from './store';

export default function SortBundlePanel( { onSaved } ) {
	const [ cfg, setCfg ] = useState( null );

	useEffect( () => { getEditorConfig().then( setCfg ).catch( () => setCfg( {} ) ); }, [] );
	if ( null === cfg ) { return <Spinner />; }

	const toOptions = ( map ) => Object.keys( map || {} ).map( ( k ) => ( { value: k, label: map[ k ] } ) );
	const save = ( payload ) => saveEditorConfig( payload ).then( () => { if ( onSaved ) { onSaved(); } } ).catch( () => {} );

	return (
		<div className="woi-col-editor">
			<SelectControl
				label={ __( 'Sort items by', 'woocommerce-orders-invoice-pdf' ) }
				value={ cfg.sort?.value || 'default' }
				options={ toOptions( cfg.sort?.options ) }
				onChange={ ( v ) => { setCfg( { ...cfg, sort: { ...cfg.sort, value: v } } ); save( { sort: v } ); } }
				__nextHasNoMarginBottom
			/>
			{ cfg.bundle && (
				<SelectControl
					label={ __( 'Product bundle display', 'woocommerce-orders-invoice-pdf' ) }
					value={ cfg.bundle.value || 'all' }
					options={ toOptions( cfg.bundle.options ) }
					onChange={ ( v ) => { setCfg( { ...cfg, bundle: { ...cfg.bundle, value: v } } ); save( { bundle: v } ); } }
					__nextHasNoMarginBottom
				/>
			) }
		</div>
	);
}
