import { useState, useEffect, useRef, useCallback } from '@wordpress/element';
import { TextareaControl, Spinner } from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import { getEditorConfig, saveEditorConfig } from './store';

export default function CustomCssPanel( { onSaved } ) {
	const [ css, setCss ] = useState( null );
	const debounceRef = useRef( null );

	useEffect( () => { getEditorConfig().then( ( r ) => setCss( r.custom_styles || '' ) ).catch( () => setCss( '' ) ); }, [] );

	const onChange = useCallback( ( v ) => {
		setCss( v );
		if ( debounceRef.current ) { clearTimeout( debounceRef.current ); }
		debounceRef.current = setTimeout( () => {
			saveEditorConfig( { custom_styles: v } ).then( () => { if ( onSaved ) { onSaved(); } } ).catch( () => {} );
		}, 400 );
	}, [ onSaved ] );

	if ( null === css ) { return <Spinner />; }
	return (
		<TextareaControl
			label={ __( 'Custom CSS', 'woocommerce-orders-invoice-pdf' ) }
			help={ __( 'Global CSS added to the document (applies to the rendered PDF).', 'woocommerce-orders-invoice-pdf' ) }
			value={ css }
			rows={ 8 }
			onChange={ onChange }
			__nextHasNoMarginBottom
		/>
	);
}
