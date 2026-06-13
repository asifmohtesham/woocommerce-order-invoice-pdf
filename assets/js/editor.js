jQuery(function($) {
	function debounce( fn, wait ) {
		var timer;
		return function() {
			clearTimeout( timer );
			timer = setTimeout( fn, wait );
		};
	}

	$( "#documents .field-list" ).sortable({
		items: '.field',
		cursor: 'move',
	});

	$( document ).on( "click", ".delete-field", function() { 
		$(this).parent().remove();
		$( document ).trigger( 'woi-pdf-settings-changed' );
	});

	// hide & disable input fields based on type selection
	$( '.custom-blocks' ).on( 'change', 'select.custom-block-type', function () {
		$('.select2-search__field').css( 'width', 'auto' ); // prevents width changes on events

		var option = $( this ).val();
		var $current_block = $( this ).closest('.custom-block');
		$current_block.find('.custom-block-settings tr, .custom-block-advanced > p').each(function( index ) {
			var types = $( this ).data('types');
			if (option.length && typeof types !== 'undefined') {
				if (types.indexOf(option) !== -1) {
					$( this ).find('input, textarea').prop('disabled', false);
					$( this ).show();
				} else {
					$( this ).find('input, textarea').val('').prop('disabled', true);
					$( this ).hide();
				}
			}
		});
		$current_block.accordion({
			header: '.custom-block-advanced-header',
			collapsible: true,
			active: false,
			activate: function( event, ui ) {
				$('.select2-search__field').css( 'width', 'auto' );
			},
		});
	})
	$( 'select.custom-block-type' ).trigger('change'); //ensure visible state matches initially

	// Add Custom block
	$( '.document-content' ).on( "click", ".button.add-custom-block", function() {
		var $button = $( this );

		// Re-entry guard: ignore clicks while a request is already in flight
		if ( $button.hasClass('is-loading') ) {
			return;
		}

		var $current_doc = $button.closest('.document-content');
		var $spinner = $button.nextAll('.woi-add-block-spinner').first();
		var $error = $button.nextAll('.woi-add-block-error').first();
		var document_type = $current_doc.data('document-type');
		var data = {
			security:      woi_pdf_templates.nonce,
			action:        'woi_pdf_templates_add_custom_block',
			document_type: document_type,
		};

		// Loading feedback: grey out button, spin spinner, clear prior error
		$button.addClass('is-loading');
		$spinner.addClass('is-active');
		$error.hide().empty();

		xhr = $.ajax({
			type:		'POST',
			url:		woi_pdf_templates.ajaxurl,
			data:		data,
			success:	function( data ) {
				var $block = $( data );
				$current_doc.find('.custom-blocks').append( $block );
				$(document.body).trigger( 'wc-enhanced-select-init' );
				$block.find('select.custom-block-type').trigger('change');
				$block.accordion({
					header: '.custom-block-advanced-header',
					collapsible: true,
					active: false,
					activate: function( event, ui ) {
						$('.select2-search__field').css( 'width', '450px' );
					},
				});
				setup_requirements($block);
			},
			error:		function() {
				$error.text( woi_pdf_templates.add_block_error ).show();
			},
			complete:	function() {
				$button.removeClass('is-loading');
				$spinner.removeClass('is-active');
			}
		});
	});

	// Custom block of type custom field: placeholder notice
	$( document ).on( "input", ".custom-block-settings .meta_key input", function() { 
		if( ( $(this).val().indexOf("{{") !== -1 ) || $(this).val().indexOf("}}") !== -1 ) {
			$(this).closest('td').addClass('tooltip');
		} else {
			$(this).closest('td').removeClass('tooltip');
		}
	});

	// Add field to totals or columns
	$( document.body ).on( 'change', '.dropdown-add-field', function () {
		let $section      = $( this ).closest( '.field-list' );
		let $current_doc  = $( this ).closest( '.document-content' );
		let document_type = $current_doc.data( 'document-type' );
		let $field_value  = $( this ).val();
		let data          = {
			security:      woi_pdf_templates.nonce,
			action:        'woi_pdf_templates_add_totals_columns_field',
			section:       $section.data( 'section_key' ),
			document_type: document_type,
			field_value:   $field_value,
		};

		xhr = $.ajax( {
			context: $section,
			type:    'POST',
			url:     woi_pdf_templates.ajaxurl,
			data:    data,
			success: function( html ) {
				let $html = $( html ).insertBefore( $( this ).find( '.document.add-field' ) );
				
				$html.accordion( {
					header:      '.field-title',
					collapsible: true,
					active:      0,
				} );
				
				$( '#documents .field-list' ).sortable( {
					items:  '.field',
					cursor: 'move'
				} );
				
				$( '.dropdown-add-field' ).val( 'default' );

				// enable WooCommerce help tips
				$( '.woocommerce-help-tip' ).tipTip( {
					'attribute': 'data-tip',
					'fadeIn':    50,
					'fadeOut':   50,
					'delay':     200
				} );

				$( document ).trigger( 'woi-pdf-settings-changed' );
			}
		} );
	} );
	
	// VAT row: Disable "Include tax base/subtotal" checkbox when the "Combined Tax" dropdown is not selected
	$( document ).on( 'click', '.field.vat', function() {
		$( this ).find( "[data-key='tax_type']" ).change( function() {
			selected_option = $( this ).val();
			$block          = $( this ).closest( '.field' );
			$block.find( "input[data-key='base']" ).prop( 'disabled', true );

			if ( selected_option == 'combined' ) {
				$block.find( "[data-key='base']" ).prop( 'disabled', false );
			}
		} ).trigger( 'change' );
	} );
		
	// Disable VAT percent and VAT base when single_total is checked
	$( ".field.options [data-key='single_total']" ).on( 'change', function () {
		$block = $(this).closest('.field.options');
		if ( $(this).prop( 'checked' ) ) {
			$block.find( "[data-key='percent'], [data-key='base']" ).prop( 'disabled', true );
			$block.find( "[data-key='percent'], [data-key='base']" ).prop( 'checked', false );
		} else {
			$block.find( "[data-key='percent'], [data-key='base']" ).prop( 'disabled', false );
		}
	});

	// trigger change on page load
	$( ".field.options [data-key='single_total']" ).trigger('change');

	// Description column: show the GTIN/UPC/EAN/ISBN label field only when its show checkbox is checked
	$( document.body ).on( 'change', ".field.options [data-key='show_global_unique_id']", function () {
		$( this ).closest( '.field.options' ).find( '.field-option.global_unique_id_label' ).toggle( $( this ).prop( 'checked' ) );
	} );

	// trigger change on page load
	$( ".field.options [data-key='show_global_unique_id']" ).trigger( 'change' );

	$( '.field.options' ).accordion({
		header: '.field-title',
		collapsible: true,
		active: false
	});

	$( '.fields.library' ).accordion({
		header: 'h4'
	});


	$( '#documents' ).tabs().show();
	$(document.body).trigger( 'wc-enhanced-select-init' );
	initTabScroll();


	// Change template path on description in General settings
	let template_selector     = $( '#woi-pdf-settings #template_path' );
	let description_path      = template_selector.next().find( 'code:first' );

	// make replacement on select change
	$( template_selector ).on( 'change', function() {
		template_path_replacements( $(this).find(':selected').text(), $(this).val() );
	} ).trigger('change');

	function template_path_replacements( template_name, template_path ) {
		let premium_templates = [ 'Business', 'Modern', 'Simple Premium' ];

		// replace plugin path
		if( $.inArray( template_name, premium_templates ) > -1 ) {
			description_path.text( get_template_path_by_id( template_path ) );
		// default to 'Simple Premium' for non premium templates
		} else {
			let simple_premium_template_option = template_selector.find('option').filter( function() {
				if( this.text == 'Simple Premium' ) {
					return this;
				}
			} );
			description_path.text( get_template_path_by_id( simple_premium_template_option.val() ) );
		}
	}

	function get_template_path_by_id( template_id ) {
		let template_path = template_id;
		if ( typeof woi_pdf_admin !== 'undefined' && typeof woi_pdf_admin.template_paths === 'object' ) {
			for ( let path in woi_pdf_admin.template_paths ) {
				if ( woi_pdf_admin.template_paths[path] == template_id ) {
					template_path = path.substring(path.indexOf("wp-content"));
				} 
			}
		}
		return template_path;
	}

	function setup_requirements( block ) {

		// Populate requirement dropdown
		block.find('.custom-block-requirements tr.requirement').each(function( index ) {
			let requirementTitle = $(this).find('label').text();
			let requirementId = $(this).data('requirement_id');
			let $requirements_select = $(this).closest('.custom-block-requirements').find('.select-requirements select');
			$requirements_select.append(
	        	$('<option></option>').val(requirementId).html(requirementTitle)
	        );
		});

		// Either disable requirement from dropdown or hide input when empty
		block.find('.custom-block-requirements tr.requirement').each(function( index ) {
			let requirementId = $(this).data('requirement_id');
			let $requirementInput = $(this).find('td > :input');
			let $requirements_select = $(this).closest('.custom-block-requirements').find('.select-requirements select');
			if ( ( $requirementInput.is(':checkbox') && $requirementInput.is(':checked') ) || ( $requirementInput.is(':checkbox') === false && $requirementInput.val().length > 0 ) ) {
				$requirements_select.find('option[value="' + requirementId + '"]').prop('disabled', true);
			} else {
				$(this).hide();
			}
		});

	}
	// Run once on page load
	$('.custom-block').each(function( index ) {
		setup_requirements( $(this) );
	});

	function initTabScroll() {
		$( window ).off( 'resize.tabScroll' );
		var $wrapper = $( '#documents .tab-scroll-wrapper' );
		if ( ! $wrapper.length ) return;

		var $track = $wrapper.find( '.tab-scroll-track' );
		var $ul    = $wrapper.find( '.document-tabs' );
		var $prev  = $wrapper.find( '.tab-scroll-prev' );
		var $next  = $wrapper.find( '.tab-scroll-next' );
		var ul     = $ul[0];

		function update() {
			var atStart = ul.scrollLeft <= 0;
			var atEnd   = ul.scrollLeft + ul.clientWidth >= ul.scrollWidth - 1;
			$prev.toggleClass( 'hidden', atStart );
			$next.toggleClass( 'hidden', atEnd );
			$track.toggleClass( 'at-start', atStart );
			$track.toggleClass( 'at-end',   atEnd );
		}

		$prev.off( 'click.tabScroll' ).on( 'click.tabScroll', function() {
			ul.scrollLeft -= Math.round( ul.clientWidth * 0.5 );
			update();
		} );

		$next.off( 'click.tabScroll' ).on( 'click.tabScroll', function() {
			ul.scrollLeft += Math.round( ul.clientWidth * 0.5 );
			update();
		} );

		$ul.off( 'scroll.tabScroll' ).on( 'scroll.tabScroll', debounce( update, 16 ) );
		$( window ).on( 'resize.tabScroll', debounce( update, 150 ) );

		update();
	}

	// Show custom block requirement field
	$('.custom-blocks').on('change', '.select-requirements', function() { 
		let requirement = $(this).val();
		// Show field
		$(this).closest('table.custom-block-requirements').find('tr[data-requirement_id="' + requirement + '"]').show();
		// Disable selected value from dropdown
		$(this).find('option[value="' + requirement + '"]').prop('disabled', true);
		// Set to default
		$(this).val('');
	});

	// Remove custom block requirement field
	$( '.custom-blocks' ).on( 'click', '.remove-requirement', function( e ) {
		e.preventDefault();
		let $self        = $( this );
		let $requirement = $self.closest( 'tr' );
		// Clear select2
		$requirement.find( 'select' ).val( '' ).trigger( 'change' );
		// Clear checkbox
		$requirement.find( 'input[type="checkbox"]' ).prop( 'checked', false );
		// Hide restriction field
		$requirement.hide();
		// Enable restiction option in dropdown
		$self.closest( '.custom-block-requirements' ).find( '.select-requirements select' ).find( 'option[value="' + $requirement.data( 'requirement_id' ) + '"]' ).removeAttr( 'disabled' );
	} );

	// Refresh preview and show secondary save button on customizer sortable changes
	$( '#documents .field-list' ).on( 'sortstop', function( event, ui ) {
		$( this ).trigger( 'woi-pdf-settings-changed' );
	} );

	// Update Preview document type on editor document change
	$( document ).on( 'click', 'ul.document-tabs > li > a', function( event ) {
		let document_type = $( this ).data( 'document_type' );
		$( '#woi-pdf-preview-wrapper :input[name="document_type"]' ).val( document_type ).trigger( 'change' );
	} );

	// Detect if the editor active tab is different from Invoice, and if yes change the preview document type input
	$( document ).ready( function() {
		if ( $( '#documents ul.document-tabs' ).length ) {
			let $active_tab_link = $( '#documents ul.document-tabs > li.ui-state-active > a' );
			let document_type    = $active_tab_link.data( 'document_type' );
			if ( document_type.length && document_type != 'invoice' ) {
				$( '#woi-pdf-preview-wrapper :input[name="document_type"]' ).val( document_type ).trigger( 'change' );
			}
		}
	} );

	$( '.load-defaults' ).on( 'click', function( event ) {
		event.preventDefault();
		
		if ( ! confirm( woi_pdf_templates.load_defaults_confirm ) ) {
			return false;
		}

		window.location.href = $( this ).attr( 'href' );
	} );
});