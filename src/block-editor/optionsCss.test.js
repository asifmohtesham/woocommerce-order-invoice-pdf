import { optionsCss } from './optionsCss';

describe( 'optionsCss row_color', () => {
	it( 'emits a body-cell colour rule that overrides the muted columns when set', () => {
		const css = optionsCss( { accent: 'navy', row_color: '#222222' } );
		expect( css ).toContain( '.order-details tbody td,' );
		expect( css ).toContain( '.order-details tbody td.position' );
		expect( css ).toContain( '.order-details tbody td.tax_rate' );
		expect( css ).toContain( 'color:#222222' );
	} );

	it( 'emits no body-cell colour rule when row_color is empty', () => {
		const css = optionsCss( { accent: 'navy' } );
		expect( css ).not.toContain( '.order-details tbody td.position' );
	} );

	it( 'ignores a non-hex row_color (cannot inject markup)', () => {
		const css = optionsCss( { accent: 'navy', row_color: 'red; }body{x' } );
		expect( css ).not.toContain( '.order-details tbody td.position' );
	} );
} );

describe( 'optionsCss contact divider', () => {
	// The contact-strip block renders a flex <div class="woi-contact-strip-row">,
	// not a table.woi-contact, so it needs its own accent rule to colour the
	// divider like the PDF (which keys on table.woi-contact). Without it the editor
	// divider stays the hardcoded navy and ignores the accent.
	it( 'colours the editor divider with the red accent', () => {
		const css = optionsCss( { accent: 'red' } );
		expect( css ).toContain( '.woi-contact-strip-row{border-top-color:#9E0A0E}' );
	} );

	it( 'colours the editor divider with the navy accent by default', () => {
		const css = optionsCss( {} );
		expect( css ).toContain( '.woi-contact-strip-row{border-top-color:#140858}' );
	} );
} );
