import { useState, useEffect, useCallback, useRef } from '@wordpress/element';
import { SelectControl, TextControl, ToggleControl, Spinner } from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import { NAMING_TYPES, hasSeries, buildNamingPayload, FILENAME_TOKENS } from './namingModel';
import { getDocumentNaming, saveDocumentNaming } from './store';

export default function NamingPanel() {
	const [ type, setType ] = useState( 'invoice' );
	const [ values, setValues ] = useState( null ); // null => loading
	const debounceRef = useRef( null );

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

	const onField = useCallback( ( key, value ) => {
		setValues( ( prev ) => {
			const next = { ...( prev || {} ), [ key ]: value };
			persist( next );
			return next;
		} );
	}, [ persist ] );

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
							<TextControl
								label={ __( 'Number prefix', 'woocommerce-orders-invoice-pdf' ) }
								value={ values.prefix || '' }
								onChange={ ( v ) => onField( 'prefix', v ) }
								__nextHasNoMarginBottom
							/>
							<TextControl
								label={ __( 'Number suffix', 'woocommerce-orders-invoice-pdf' ) }
								value={ values.suffix || '' }
								onChange={ ( v ) => onField( 'suffix', v ) }
								__nextHasNoMarginBottom
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

					<TextControl
						label={ __( 'PDF filename override', 'woocommerce-orders-invoice-pdf' ) }
						help={ __( 'Leave blank to use the global template. Tokens: ', 'woocommerce-orders-invoice-pdf' ) + FILENAME_TOKENS.join( ' ' ) }
						value={ values.filename_template || '' }
						onChange={ ( v ) => onField( 'filename_template', v ) }
						__nextHasNoMarginBottom
					/>
				</>
			) }
		</div>
	);
}
