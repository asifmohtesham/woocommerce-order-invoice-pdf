( function () {
    // Bail if GrapesJS or the localised config is not available.
    if ( typeof grapesjs === 'undefined' || ! window.woiVisual ) { return; }

    // token, friendly label, palette category, tooltip hint
    var TOKEN_META = [
        [ 'logo',             'Logo image',            'Shop',           'Shop logo image' ],
        [ 'shop_name',        'Shop name',             'Shop',           'Company name' ],
        [ 'shop_address',     'Shop address',          'Shop',           'Company address' ],
        [ 'shop_name_ar',     'Shop name (AR)',        'Shop',           'Company name, second language' ],
        [ 'shop_address_ar',  'Shop address (AR)',     'Shop',           'Company address, second language' ],
        [ 'trn',              'TRN',                   'Shop',           'Tax registration number' ],
        [ 'shop_phone',       'Shop phone',            'Shop',           'Company phone' ],
        [ 'shop_email',       'Shop email',            'Shop',           'Company email' ],
        [ 'document_title',   'Document title',        'Document',       'e.g. Tax Invoice' ],
        [ 'document_title_ar','Document title (AR)',   'Document',       'Title, second language' ],
        [ 'invoice_number',   'Invoice number',        'Document',       'Document number' ],
        [ 'invoice_date',     'Invoice date',          'Document',       'Document date' ],
        [ 'order_number',     'Order number',          'Document',       'WooCommerce order number' ],
        [ 'payment_method',   'Payment method',        'Document',       'Order payment method' ],
        [ 'billing_address',  'Billing address',       'Customer',       'Customer billing block' ],
        [ 'line_items',       'Line items table',      'Items & Totals', 'Order line-items table' ],
        [ 'totals',           'Totals table',          'Items & Totals', 'Subtotal / VAT / total table' ]
    ];

    var editor = grapesjs.init( {
        container: '#woi-visual-editor',
        height: '80vh',
        fromElement: false,
        storageManager: false,
        components: woiVisual.stored || woiVisual.starter || ''
    } );

    // Register one draggable block per token, grouped by category.
    TOKEN_META.forEach( function ( m ) {
        var token = m[ 0 ];
        editor.BlockManager.add( 'token-' + token, {
            label: m[ 1 ],
            category: m[ 2 ],
            attributes: { title: m[ 3 ] },
            content: '<span data-woi-token="' + token + '">{{' + token + '}}</span>'
        } );
    } );

    // Layout building blocks (non-token). Tables (not flex/grid) for mPDF safety.
    editor.BlockManager.add( 'row-2col', {
        label: '2-column row', category: 'Layout',
        attributes: { title: 'Two side-by-side columns' },
        content: '<table class="woi-row"><tr><td>Column one</td><td>Column two</td></tr></table>'
    } );
    editor.BlockManager.add( 'spacer', {
        label: 'Spacer', category: 'Layout',
        attributes: { title: 'Vertical empty space' },
        content: '<div class="woi-spacer"></div>'
    } );
    editor.BlockManager.add( 'divider', {
        label: 'Divider', category: 'Layout',
        attributes: { title: 'Horizontal rule' },
        content: '<hr>'
    } );
    editor.BlockManager.add( 'heading', {
        label: 'Heading', category: 'Layout',
        attributes: { title: 'Section heading' },
        content: '<h2>Section heading</h2>'
    } );
    editor.BlockManager.add( 'pagebreak', {
        label: 'Page break', category: 'Layout',
        attributes: { title: 'Force a new page at this point' },
        content: '<div class="woi-pagebreak"></div>'
    } );

    // "Print" style sector: keep a block together across page breaks (mPDF).
    editor.StyleManager.addSector( 'print', {
        name: 'Print',
        open: false,
        properties: [ {
            name: 'Keep together',
            property: 'page-break-inside',
            type: 'select',
            defaults: 'auto',
            list: [
                { value: 'auto',  name: 'Allow break' },
                { value: 'avoid', name: 'Keep together' }
            ]
        } ]
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
     * Preview real PDF: save first, then POST to admin-ajax (same fields as
     * admin.js ajaxLoadPreview), decode the base64 PDF from the JSON response,
     * and open it as a Blob URL — matching the proven POST+Blob mechanism used
     * by the settings-page preview (assets/js/admin.js lines 653-695).
     *
     * Fields replicated from admin.js:
     *   action        = 'woi_pdf_preview'
     *   security      = woiVisual.previewNonce  (woi_pdf_preview nonce)
     *   document_type = woiVisual.docType        (e.g. 'invoice')
     *   order_id      omitted → handler defaults to the last order (Settings.php ~271)
     *   output_format omitted → handler defaults to 'pdf'
     *   data          omitted → no live-settings override needed in the visual editor
     *
     * Note: the PDF renders the visual template only when "Visual template (invoice)"
     * is enabled in Invoice Settings — it reflects the last SAVED design.
     */
    editor.Panels.addButton( 'options', {
        id: 'woi-preview-pdf',
        className: 'fa fa-file-pdf-o',
        attributes: { title: 'Preview real PDF' },
        command: function () {
            save().then( function () {
                // Build form-encoded body mirroring admin.js ajaxLoadPreview.
                var body = 'action=woi_pdf_preview' +
                    '&security=' + encodeURIComponent( woiVisual.previewNonce ) +
                    '&document_type=' + encodeURIComponent( woiVisual.docType );

                return fetch( woiVisual.ajaxUrl, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    credentials: 'same-origin',
                    body: body
                } );
            } ).then( function ( r ) {
                if ( ! r.ok ) { throw new Error( 'HTTP ' + r.status ); }
                return r.json();
            } ).then( function ( response ) {
                if ( ! response.success || ! response.data || ! response.data.preview_data ) {
                    var msg = ( response.data && response.data.error ) ? response.data.error : 'Preview failed.';
                    alert( 'PDF preview error: ' + msg );
                    return;
                }
                if ( response.data.output_format !== 'pdf' ) {
                    alert( 'PDF preview returned unexpected format: ' + response.data.output_format );
                    return;
                }
                // Decode base64 → Uint8Array → Blob → object URL (same as admin.js).
                var b64    = response.data.preview_data;
                var binary = window.atob( b64 );
                var bytes  = new Uint8Array( binary.length );
                for ( var i = 0; i < binary.length; i++ ) {
                    bytes[ i ] = binary.charCodeAt( i );
                }
                var blob    = new Blob( [ bytes ], { type: 'application/pdf' } );
                var blobUrl = URL.createObjectURL( blob );
                window.open( blobUrl, '_blank' );
                // Revoke after 10 s — enough time for the tab to load the blob.
                setTimeout( function () { URL.revokeObjectURL( blobUrl ); }, 10000 );
            } ).catch( function ( e ) {
                alert( 'Preview failed: ' + ( e && e.message ? e.message : e ) );
            } );
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
