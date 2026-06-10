jQuery( function( $ ) {

	$( '#doaction, #doaction2' ).on( 'click', function( e ) {
		let actionselected = $( this ).attr( "id" ).substr( 2 );
		let action         = $( 'select[name="' + actionselected + '"]' ).val();

		if ( $.inArray( action, woi_pdf_ajax.bulk_actions ) !== -1 ) {
			e.preventDefault();

			let document_type = action;
			let checked       = [];
			let xml_output    = false;

			// is XML action
			if ( action.indexOf( 'xml' ) != -1 ) {
				document_type = document_type.replace( '_xml', '' );
				xml_output    = true;
			}

			$( 'tbody th.check-column input[type="checkbox"]:checked' ).each(
				function() {
					checked.push( $( this ).val() );
				}
			);

			if ( ! checked.length ) {
				alert( woi_pdf_ajax.select_orders );
				return;
			}

			let partial_url = '';
			let full_url    = '';

			if ( woi_pdf_ajax.ajaxurl.indexOf ("?" ) != -1 ) {
				partial_url = woi_pdf_ajax.ajaxurl+'&action=generate_woi_pdf&document_type='+document_type+'&bulk&_wpnonce='+woi_pdf_ajax.nonce;
			} else {
				partial_url = woi_pdf_ajax.ajaxurl+'?action=generate_woi_pdf&document_type='+document_type+'&bulk&_wpnonce='+woi_pdf_ajax.nonce;
			}

			// xml
			if ( xml_output ) {

				// Credit Note: get refund IDs first
				if ( 'credit-note' === document_type ) {
					$.ajax( {
						url:       woi_pdf_ajax.ajaxurl,
						type:     'POST',
						dataType: 'json',
						data: {
							action:    'woi_pdf_get_refund_order_ids',
							order_ids: checked,
							security:  woi_pdf_ajax.nonce
						},
						success: function( response ) {
							if ( response && response.success && response.data && response.data.refund_ids && response.data.refund_ids.length ) {
								$.each( response.data.refund_ids, function( i, refund_id ) {
									full_url = partial_url + '&order_ids='+refund_id+'&output=xml';
									window.open( full_url, '_blank' );
								} );
							} else {
								let msg = ( response && response.data && response.data.message ) ? response.data.message : woi_pdf_ajax.error_no_refunds_found;
								alert( msg );
							}
						},
						error: function() {
							alert( woi_pdf_ajax.error_fetching_refund_ids );
						}
					} );

					// stop normal XML processing
					return;
				}

				// default xml
				$.each( checked, function( i, order_id ) {
					full_url = partial_url + '&order_ids='+order_id+'&output=xml';
					window.open( full_url, '_blank' );
				} );

			// pdf
			} else {
				let order_ids = checked.join( 'x' );
				full_url      = partial_url + '&order_ids='+order_ids;
				window.open( full_url, '_blank' );
			}

		}
	} );

	if ( woi_pdf_ajax.sticky_document_data_metabox ) {
		$( '#woi_pdf-data-input-box' ).insertAfter('#woocommerce-order-data');
	}

	// enable invoice number edit if user initiated
	$( '#woi_pdf-data-input-box' ).on( 'click', '.woi-pdf-set-date-number, .woi-pdf-edit-date-number, .woi-pdf-edit-document-notes', function() {
		let $form = $(this).closest('.woi-pdf-data-fields');
		let edit = $(this).data( 'edit' );

		// check visibility
		toggle_edit_mode( $form, edit );
	} );

	// cancel edit
	$( '#woi_pdf-data-input-box' ).on( 'click', '.woi-pdf-cancel', function() {
		let $form = $(this).closest('.woi-pdf-data-fields');
		toggle_edit_mode( $form );
	} );

	// save, regenerate and delete document
	$( '#woi_pdf-data-input-box' ).on( 'click', '.woi-pdf-save-document, .woi-pdf-regenerate-document, .woi-pdf-delete-document', function( e ) {
		e.preventDefault();

		let $form      = $( this ).closest( '.woi-pdf-data-fields' );
		let action     = $( this ).data( 'action' );
		let nonce      = $( this ).data( 'nonce' );
		let data       = $form.data();
		let serialized = $form.find( ":input:visible:not(:disabled)" ).serialize();

		// regenerate specific
		if ( 'regenerate' === action ) {
			if ( window.confirm( woi_pdf_ajax.confirm_regenerate ) === false ) {
				return; // having second thoughts
			}

			$form.find( '.woi-pdf-regenerate-document' ).addClass( 'woi-pdf-regenerate-spin' );

		// delete specific
		} else if ( 'delete' === action ) {
			if ( window.confirm( woi_pdf_ajax.confirm_delete ) === false ) {
				return; // having second thoughts
			}

			// hide regenerate button
			$form.find('.woi-pdf-regenerate-document').hide();
		}

		// Remove previous notice if exists.
		const $previous_notice = $( this ).closest( '#woi_pdf-data-input-box' ).find( '.notice' );
		if ( $previous_notice.length ) {
			$previous_notice.remove();
		}

		// block ui
		$form.block( {
			message: null,
			overlayCSS: {
				background: '#fff',
				opacity: 0.6
			}
		} );

		// request
		$.ajax( {
			url:                            woi_pdf_ajax.ajaxurl,
			data: {
				action:                     'woi_pdf_'+action+'_document',
				security:                   nonce,
				form_data:                  serialized,
				order_id:                   data.order_id,
				document_type:              data.document,
				action_type:                action,
				wpcdf_document_data_notice: action+'d',
			},
			type:               'POST',
			context:            $form,
			success: function( response ) {
				// update document DOM data
				$form.closest('#woi_pdf-data-input-box').load(
					document.URL + ' #woi_pdf-data-input-box .postbox-header, #woi_pdf-data-input-box .inside',
					function() {
						toggle_edit_mode( $form );

						const notice_type   = response.success ? 'success' : 'error';
						const $target_field = $( this ).find( '.woi-pdf-data-fields[data-document="' + data.document + '"][data-order_id="' + data.order_id + '"]' );

						if ( $target_field.length ) {
							$target_field.before(
								'<div class="notice notice-' + notice_type + ' inline" style="margin:0 10px 10px 10px;">' +
								'<p>' + response.data.message + '</p>' +
								'</div>'
							);
						}

						if( action === 'regenerate' ) {
							$form.find('.woi-pdf-regenerate-document').removeClass('woi-pdf-regenerate-spin');
							toggle_edit_mode( $form );
						}

						// unblock ui
						$form.unblock();
				} );
			}
		} );

	} );

	function toggle_edit_mode( $form, mode = null ) {
		// check visibility
		if ( $form.find( '.read-only' ).is( ':visible' ) ) {
			if ( mode === 'notes' ) {
				$form.find( '.editable-notes :input' ).attr( 'disabled', false );
			} else {
				$form.find( '.editable' ).show();
				$form.find( ':input' ).attr( 'disabled', false );
			}

			$form.find( '.read-only' ).hide();
			$form.find( '.editable-notes' ).show();
			$form.closest( '.woi-pdf-data-fields' ).find( '.woi-pdf-document-buttons' ).show();

			// re-initialize WooCommerce tooltips
			$( '.woi-pdf-data-fields .woocommerce-help-tip' ).tipTip( {
					attribute: 'data-tip',
					fadeIn: 50,
					fadeOut: 50,
					delay: 200,
					keepAlive: true,
				} )
				.css( 'cursor', 'help' );

			// re-initialize datepicker
			$( '.woi-pdf-data-fields .date-picker-field, .date-picker' ).datepicker( {
				dateFormat: 'yy-mm-dd',
				numberOfMonths: 1,
				showButtonPanel: true,
			} );
		} else {
			$form.find( '.read-only' ).show();
			$form.find( '.editable' ).hide();
			$form.find( '.editable-notes' ).hide();
			$form.find( ':input' ).attr( 'disabled', true );
			$form.closest( '.woi-pdf-data-fields' ).find( '.woi-pdf-document-buttons' ).hide();
		}
	}

	$( '#woi_pdf-data-input-box' ).on( 'click', '.view-more, .hide-details', function( e ) {
		e.preventDefault();

		$( this ).hide();
		$( '.pdf-more-details' ).slideToggle( 'slow' );

		if ( $( this ).hasClass( 'view-more' ) ) {
			$( '.hide-details' ).show();
		} else {
			$( '.view-more' ).show();
		}
	} );

	function updatePreviewNumber( $table ) {
		let prefix   = $table.find( 'input[name$="_number_prefix"]' ).val();
		let suffix   = $table.find( 'input[name$="_number_suffix"]' ).val();
		let padding  = $table.find( 'input[name$="_number_padding"]' ).val();
		let plain    = $table.find( 'input[name$="_number_plain"]' ).val();
		let document = $table.data( 'document' );
		let orderId  = $table.data( 'order_id' );

		$.ajax( {
			url:    woi_pdf_ajax.ajaxurl,
			method: 'POST',
			data: {
				action:   'woi_pdf_preview_formatted_number',
				security: woi_pdf_ajax.nonce,
				prefix:   prefix,
				suffix:   suffix,
				padding:  padding,
				plain:    plain,
				document: document,
				order_id: orderId,
			},
			success: function( response ) {
				if ( response.success && response.data.formatted ) {
					let $preview = $table.find( '.formatted-number' );
					let current  = $preview.data( 'current' );
					let updated  = response.data.formatted;

					$preview.val( updated );

					if ( current !== updated ) {
						$preview.addClass( 'changed' );
					} else {
						$preview.removeClass( 'changed' );
					}
				}
			},
			error: function( xhr, status, error ) {
				console.error( 'AJAX error:', status, error );
				$table.find( '.formatted-number' ).val( woi_pdf_ajax.error_loading_number_preview );
			}
		} );
	}

	let previewTimer;
	$( document ).on( 'input', '.woi-pdf-data-fields input', function () {
		const $table = $( this ).closest( '.woi-pdf-data-fields' );

		clearTimeout( previewTimer );
		previewTimer = setTimeout( () => {
			updatePreviewNumber( $table );
		}, 300 );
	} );

	// Edi identifiers
	const root = '#wpo_ips-edi-box .edi-customer-identifiers';

	$( document.body ).on( 'click', root + ' td.collapse > a', function( e ) {
		e.preventDefault();
		let $this  = $( this );
		let $tbody = $this.closest( 'table' ).find( 'tbody' );

		if ( $tbody.is( ':visible' ) ) {
			$tbody.slideUp( 'fast' );
			$this.text( woi_pdf_ajax.edi_metabox.show );
		} else {
			$tbody.slideDown( 'fast' );
			$this.text( woi_pdf_ajax.edi_metabox.hide );
		}
	} );

	// Peppol identifiers
	const peppolRoot = `${root}.peppol`;

	// Edit
	$( document.body ).on( 'click', peppolRoot + ' thead .editable a', function ( e ) {
		e.preventDefault();
		$( this ).closest( 'table' ).addClass( 'is-editing' );
	} );

	// Cancel
	$( document.body ).on( 'click', peppolRoot + ' tfoot .button.cancel', function ( e ) {
		e.preventDefault();
		$( this ).closest( 'table' ).removeClass( 'is-editing' );
	} );

	// Save (AJAX)
	$( document.body ).on( 'click', peppolRoot + ' tfoot .button-primary', function ( e ) {
		e.preventDefault();

		const $btn    = $( this );
		const orderId = $btn.data( 'order_id' );
		const $table  = $btn.closest( 'table' );
		const $box    = $table.closest( peppolRoot );

		const pairs   = $box.find( 'tbody input[type="text"]' ).serializeArray();
		const values  = {};

		$.each( pairs, function ( _, p ) { values[p.name] = p.value; } );

		const data = {
			action:   'woi_pdf_edi_save_order_customer_peppol_identifiers',
			security: woi_pdf_ajax.nonce,
			order_id: orderId,
			values:   values
		};

		$btn.prop( 'disabled', true );

		$.post( woi_pdf_ajax.ajaxurl, data )
			.done( function ( response ) {
				$box.find( 'tbody tr' ).each( function () {
					const row = $( this );
					const val = row.find( 'td.edit input[type="text"]' ).val();
					row.find( 'td.display' ).text( val || '—' );
				} );

				$table.removeClass( 'is-editing' );

				const msg = ( response && response.data && response.data.message ) || woi_pdf_ajax.edi_metabox.saved;
				if ( window.wp && wp.a11y && wp.a11y.speak ) {
					wp.a11y.speak( msg );
				} else {
					alert( msg );
				}
			} )
			.fail( function ( jqXHR ) {
				const msg = ( jqXHR.responseJSON && jqXHR.responseJSON.data && jqXHR.responseJSON.data.message ) || woi_pdf_ajax.edi_metabox.fail;
				alert( msg );
			} )
			.always( function () {
				$btn.prop( 'disabled', false );
			} );
	} );

} );
