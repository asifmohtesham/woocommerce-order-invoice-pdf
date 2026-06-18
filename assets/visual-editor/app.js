( function () {
    // Bail if GrapesJS or the localised config is not available.
    if ( typeof grapesjs === 'undefined' || ! window.woiVisual ) { return; }

    var tokens = [
        'logo', 'shop_name', 'shop_address', 'shop_name_ar', 'shop_address_ar',
        'document_title', 'document_title_ar', 'trn', 'shop_phone', 'shop_email',
        'billing_address', 'invoice_number', 'invoice_date', 'order_number',
        'payment_method', 'line_items', 'totals'
    ];

    var editor = grapesjs.init( {
        container: '#woi-visual-editor',
        height: '80vh',
        fromElement: false,
        storageManager: false,
        components: woiVisual.stored || woiVisual.starter || ''
    } );

    // Register one draggable block per invoice token.
    tokens.forEach( function ( t ) {
        editor.BlockManager.add( 'token-' + t, {
            label: '{{' + t + '}}',
            category: 'Invoice tokens',
            content: '<span data-woi-token="' + t + '">{{' + t + '}}</span>'
        } );
    } );

    /** Return full editor HTML + embedded CSS. */
    function getHtml() {
        return editor.getHtml() + '<style>' + editor.getCss() + '</style>';
    }

    /** POST current design to REST endpoint; returns the fetch Promise. */
    function save() {
        return fetch( woiVisual.restUrl, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-WP-Nonce': woiVisual.nonce
            },
            body: JSON.stringify( { doc_type: woiVisual.docType, html: getHtml() } )
        } ).then( function ( r ) {
            if ( ! r.ok ) { return Promise.reject( new Error( 'HTTP ' + r.status ) ); }
            return r.json();
        } );
    }

    /**
     * Merge woiVisual.sampleData keys into html and strip any remaining {{…}}
     * for the in-browser sample-data preview.
     */
    function mergeSample( html ) {
        var out = html;
        Object.keys( woiVisual.sampleData ).forEach( function ( k ) {
            // Split/join to replace all occurrences without a regex.
            out = out.split( k ).join( woiVisual.sampleData[ k ] );
        } );
        // Strip any leftover unresolved tokens.
        return out.replace( /\{\{\s*[a-z0-9_]+\s*\}\}/gi, '' );
    }

    // --- Toolbar buttons ---

    /** Save the current design to the database. */
    editor.Panels.addButton( 'options', {
        id: 'woi-save',
        className: 'fa fa-floppy-o',
        attributes: { title: 'Save' },
        command: function () {
            save().then( function () { alert( 'Saved' ); } ).catch( function ( e ) { alert( 'Save failed: ' + ( e && e.message ? e.message : e ) ); } );
        }
    } );

    /**
     * Preview real PDF: save first, then open the preview URL.
     * Appends woiVisual.previewNonce as ?security=… so the admin-ajax handler
     * (which validates a 'woi_pdf_preview' nonce, not the wp_rest nonce) accepts
     * the request.
     * Note: the PDF renders the visual template only when "Visual template (invoice)"
     * is enabled in Invoice Settings — it reflects the last saved design.
     */
    editor.Panels.addButton( 'options', {
        id: 'woi-preview-pdf',
        className: 'fa fa-file-pdf-o',
        attributes: { title: 'Preview real PDF' },
        command: function () {
            save().then( function () {
                var url = woiVisual.previewUrl +
                    '&security=' + encodeURIComponent( woiVisual.previewNonce );
                window.open( url, '_blank' );
            } ).catch( function ( e ) { alert( 'Save failed, preview not opened: ' + ( e && e.message ? e.message : e ) ); } );
        }
    } );

    /**
     * In-browser sample-data preview: merge tokens then open via a Blob URL.
     * Using a Blob avoids document.write() (XSS risk) and srcdoc attribute-encoding
     * issues. The blob URL is revoked after the tab loads to free memory.
     */
    editor.Panels.addButton( 'options', {
        id: 'woi-preview-sample',
        className: 'fa fa-eye',
        attributes: { title: 'Preview sample data' },
        command: function () {
            var merged = mergeSample( getHtml() );
            var blob = new Blob( [ merged ], { type: 'text/html; charset=utf-8' } );
            var url  = URL.createObjectURL( blob );
            var tab  = window.open( url, '_blank' );
            // Revoke after 10 s — enough time for the tab to load the blob.
            // (The 'load' event does not reliably fire for blob-navigated tabs.)
            if ( tab ) {
                setTimeout( function () { URL.revokeObjectURL( url ); }, 10000 );
            } else {
                // Popup blocked; still revoke to avoid leak.
                URL.revokeObjectURL( url );
            }
        }
    } );
}() );
