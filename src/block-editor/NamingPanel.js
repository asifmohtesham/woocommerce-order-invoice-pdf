import { useState, useEffect, useCallback, useRef } from '@wordpress/element';
import { SelectControl, TextControl, ToggleControl, Spinner } from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import {
	NAMING_TYPES, hasSeries, buildNamingPayload, FILENAME_TOKENS,
	prefixTokens, filenameTokenChips,
} from './namingModel';
import { getDocumentNaming, saveDocumentNaming, getNamingPreview } from './store';
import TokenField from './TokenField';

export default function NamingPanel( { orderId = 0 } ) {
	const [ type, setType ] = useState( 'invoice' );
	const [ values, setValues ] = useState( null ); // null => loading
	const [ preview, setPreview ] = useState( null );
	const debounceRef = useRef( null );
	const previewRef = useRef( null );

	// Load the selected type's settings whenever the type changes.
	useEffect( () => {
		let active = true;
		setValues( null );
		getDocumentNaming( type )
			.then( ( r ) => { if ( active ) { setValues( r ); } } )
			.catch( () => { if ( active ) { setValues( {} ); } } );
		return () => {
			active = false;
			if ( debounceRef.current ) { clearTimeout( debounceRef.current ); }
			if ( previewRef.current ) { clearTimeout( previewRef.current ); }
		};
	}, [ type ] );

	const persist = useCallback( ( next ) => {
		if ( debounceRef.current ) { clearTimeout( debounceRef.current ); }
		debounceRef.current = setTimeout( () => {
			saveDocumentNaming( buildNamingPayload( type, next ) )
				.then( ( r ) => setValues( r ) )
				.catch( () => {} );
		}, 500 );
	}, [ type ] );

	// Debounced server-resolved preview of the number + filename for the loaded
	// order. Uses the unsaved field values so it reflects edits live.
	const refreshPreview = useCallback( ( next ) => {
		if ( previewRef.current ) { clearTimeout( previewRef.current ); }
		previewRef.current = setTimeout( () => {
			getNamingPreview( {
				type,
				order_id: orderId || 0,
				prefix: next.prefix || '',
				suffix: next.suffix || '',
				padding: next.padding ?? '',
				next_number: next.next_number,
				filename_template: next.filename_template || '',
			} ).then( ( r ) => setPreview( r ) ).catch( () => {} );
		}, 250 );
	}, [ type, orderId ] );

	const onField = useCallback( ( key, value ) => {
		setValues( ( prev ) => {
			const next = { ...( prev || {} ), [ key ]: value };
			persist( next );
			refreshPreview( next );
			return next;
		} );
	}, [ persist, refreshPreview ] );

	// Refresh the preview when values first load or the order changes.
	useEffect( () => {
		if ( values ) { refreshPreview( values ); }
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [ values === null, orderId, type ] );

	const series = hasSeries( type );

	return (
		<div className="woi-naming-panel">
			<SelectControl
				label={ __( 'Document type', 'woocommerce-orders-invoice-pdf' ) }
				value={ type }
				options={ NAMING_TYPES.map( ( t ) => ( { value: t.value, label: t.label } ) ) }
				onChange={ ( v ) => setType( v ) }
				__nextHasNoMarginBottom
			/>

			{ null === values ? (
				<Spinner />
			) : (
				<>
					{ series && (
						<>
							<TokenField
								label={ __( 'Number prefix', 'woocommerce-orders-invoice-pdf' ) }
								value={ values.prefix || '' }
								onChange={ ( v ) => onField( 'prefix', v ) }
								tokens={ prefixTokens( type ) }
							/>
							<TokenField
								label={ __( 'Number suffix', 'woocommerce-orders-invoice-pdf' ) }
								value={ values.suffix || '' }
								onChange={ ( v ) => onField( 'suffix', v ) }
								tokens={ prefixTokens( type ) }
							/>
							<TextControl
								type="number"
								label={ __( 'Padding (digits)', 'woocommerce-orders-invoice-pdf' ) }
								value={ values.padding || '' }
								onChange={ ( v ) => onField( 'padding', v ) }
								__nextHasNoMarginBottom
							/>
							<TextControl
								type="number"
								label={ __( 'Next number', 'woocommerce-orders-invoice-pdf' ) }
								help={ __( 'Setting this lower than the current highest number can create duplicates.', 'woocommerce-orders-invoice-pdf' ) }
								value={ undefined === values.next_number || null === values.next_number ? '' : values.next_number }
								onChange={ ( v ) => onField( 'next_number', v ? parseInt( v, 10 ) : '' ) }
								__nextHasNoMarginBottom
							/>
							<ToggleControl
								label={ __( 'Reset number yearly', 'woocommerce-orders-invoice-pdf' ) }
								checked={ !! values.reset_number_yearly }
								onChange={ ( v ) => onField( 'reset_number_yearly', v ) }
								__nextHasNoMarginBottom
							/>
						</>
					) }

					<TokenField
						label={ __( 'PDF filename override', 'woocommerce-orders-invoice-pdf' ) }
						help={ __( 'Leave blank to use the global template. Tokens: ', 'woocommerce-orders-invoice-pdf' ) + FILENAME_TOKENS.join( ' ' ) }
						value={ values.filename_template || '' }
						onChange={ ( v ) => onField( 'filename_template', v ) }
						tokens={ filenameTokenChips() }
					/>

					{ preview && preview.has_order ? (
						<div className="woi-naming-preview">
							{ series ? (
								<p><strong>{ __( 'Number', 'woocommerce-orders-invoice-pdf' ) }:</strong> { preview.number_preview }</p>
							) : null }
							<p><strong>{ __( 'Filename', 'woocommerce-orders-invoice-pdf' ) }:</strong> { preview.filename_preview }</p>
						</div>
					) : (
						<p className="woi-naming-preview woi-naming-preview--empty">
							{ __( 'Select an order to preview the number and filename.', 'woocommerce-orders-invoice-pdf' ) }
						</p>
					) }
				</>
			) }
		</div>
	);
}
