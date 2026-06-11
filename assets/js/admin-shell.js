jQuery( function( $ ) {
	const $shell = $( '.woi-pdf-shell' );

	if ( ! $shell.length ) {
		return;
	}

	const $form = $( '#woi-pdf-settings' );

	//----------> Sticky save + dirty tracking <----------//
	if ( $form.length ) {
		// JS booted: reveal header buttons, hide the in-form fallback submit
		$( '.woi-shell-save' ).prop( 'hidden', false );
		$form.find( 'p.submit' ).addClass( 'woi-js-hidden' );

		$form.on( 'change input', ':input', function() {
			$( '.woi-shell-dirty' ).prop( 'hidden', false );
			window.onbeforeunload = function() { return true; };
		} );

		$form.on( 'submit', function() {
			window.onbeforeunload = null;
		} );

		$( '.woi-shell-save' ).on( 'click', function() {
			if ( $form[0].requestSubmit ) {
				$form[0].requestSubmit();
			} else {
				$form.trigger( 'submit' );
			}
		} );
	}

	//----------> Conditional fields (show_if) <----------//
	function showIfRules() {
		const rules = [];

		$form.find( 'tr.woi-show-if' ).each( function() {
			const $row     = $( this );
			const classes  = ( $row.attr( 'class' ) || '' ).split( /\s+/ );
			const ruleData = classes.find( ( c ) => c.indexOf( 'woi-show-if--' ) === 0 );

			if ( ! ruleData ) {
				return;
			}

			const parts = ruleData.replace( 'woi-show-if--', '' ).split( '--' );

			if ( parts.length !== 2 ) {
				return;
			}

			rules.push( { $row: $row, field: parts[0], value: parts[1] } );
		} );

		return rules;
	}

	function controllerFor( field ) {
		// Field names look like option_name[field_id]
		return $form.find( ':input[name$="[' + field + ']"]' ).first();
	}

	function applyShowIf( rules ) {
		rules.forEach( function( rule ) {
			const $controller = controllerFor( rule.field );

			if ( ! $controller.length ) {
				return;
			}

			let current;

			if ( $controller.is( ':checkbox' ) ) {
				current = $controller.is( ':checked' ) ? '1' : '0';
			} else {
				current = String( $controller.val() );
			}

			rule.$row.toggleClass( 'woi-hidden', current !== rule.value );
		} );
	}

	const rules = showIfRules();

	if ( rules.length ) {
		applyShowIf( rules );

		const fields = [ ...new Set( rules.map( ( r ) => r.field ) ) ];

		fields.forEach( function( field ) {
			controllerFor( field ).on( 'change', function() {
				applyShowIf( rules );
			} );
		} );
	}

	//----------> Accordion count badges <----------//
	$shell.find( '.settings_category' ).each( function() {
		const count = $( this ).find( 'tbody tr' ).not( '.woi-show-if.woi-hidden' ).length;

		if ( count > 0 ) {
			$( this ).children( 'h2' ).append( ' <span class="woi-count">' + count + '</span>' );
		}
	} );

	//----------> Hash deep links: #field_id opens its group and scrolls to the row <----------//
	function openHashTarget() {
		const id = window.location.hash.replace( '#', '' );

		if ( ! id || ! $form.length ) {
			return;
		}

		const $row = $form.find( ':input[name$="[' + id + ']"]' ).first().closest( 'tr' );

		if ( ! $row.length ) {
			return;
		}

		const $category = $row.closest( '.settings_category' );
		const $header   = $category.children( 'h2' );

		// settingsAccordion() (admin.js) collapses the table; clicking the header opens it
		if ( $header.length && $header.attr( 'aria-expanded' ) === 'false' ) {
			$header.trigger( 'click' );
		}

		$row[0].scrollIntoView( { behavior: 'smooth', block: 'center' } );
	}

	openHashTarget();
	$( window ).on( 'hashchange', openHashTarget );

	//----------> Small-screen preview overlay <----------//
	if ( $( '#woi-pdf-preview-wrapper' ).length ) {
		$( '.woi-shell-preview-toggle' ).prop( 'hidden', false ).on( 'click', function() {
			$shell.toggleClass( 'woi-preview-overlay-open' );
		} );
	}
} );
