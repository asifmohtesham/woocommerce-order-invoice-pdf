import { CheckboxControl, SelectControl, TextControl, TextareaControl } from '@wordpress/components';

// Generic renderer mirroring PHP EditorSettings::display_table_field_options().
export default function OptionField( { optionKey, field, value, onChange } ) {
	const desc = field.description || optionKey;
	switch ( field.type ) {
		case 'checkbox':
			return (
				<CheckboxControl
					label={ desc }
					checked={ '1' === String( value ) || true === value }
					onChange={ ( v ) => onChange( v ? '1' : '' ) }
					__nextHasNoMarginBottom
				/>
			);
		case 'select':
			return (
				<SelectControl
					label={ desc }
					value={ value || '' }
					options={ Object.keys( field.options || {} ).map( ( k ) => ( { value: k, label: field.options[ k ] } ) ) }
					onChange={ onChange }
					__nextHasNoMarginBottom
				/>
			);
		case 'number':
			return (
				<TextControl
					label={ desc }
					type="number"
					value={ value || '' }
					placeholder={ field.placeholder || '' }
					min={ field.min }
					max={ field.max }
					step={ field.step }
					onChange={ onChange }
					__nextHasNoMarginBottom
				/>
			);
		case 'textarea':
			return (
				<TextareaControl
					label={ desc }
					value={ value || '' }
					rows={ field.rows || 4 }
					onChange={ onChange }
					__nextHasNoMarginBottom
				/>
			);
		case 'text':
		default:
			return (
				<TextControl
					label={ desc }
					value={ value || '' }
					placeholder={ field.placeholder || '' }
					onChange={ onChange }
					__nextHasNoMarginBottom
				/>
			);
	}
}
