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
        deviceManager: { devices: [] },
        components: woiVisual.stored || woiVisual.starter || ''
    } );

    // --- Native editable tables (#2): make td editable + droppable, add row/col commands ---
    editor.DomComponents.addType( 'woi-cell', {
        isComponent: function ( el ) { return el.tagName === 'TD' || el.tagName === 'TH'; },
        model: { defaults: {
            tagName: 'td',
            draggable: 'tr',
            droppable: true,
            editable: true,
            highlightable: true,
            selectable: true
        } }
    } );
    editor.DomComponents.addType( 'woi-trow', {
        isComponent: function ( el ) { return el.tagName === 'TR'; },
        model: { defaults: { tagName: 'tr', draggable: false, droppable: 'td, th' } }
    } );
    editor.DomComponents.addType( 'woi-table', {
        isComponent: function ( el ) { return el.tagName === 'TABLE'; },
        model: { defaults: {
            tagName: 'table',
            droppable: false,
            toolbar: [
                { attributes: { class: 'fa fa-plus', title: 'Add row' },    command: 'woi-add-row' },
                { attributes: { class: 'fa fa-minus', title: 'Delete row' }, command: 'woi-del-row' },
                { attributes: { class: 'fa fa-plus-square-o', title: 'Add column' },  command: 'woi-add-col' },
                { attributes: { class: 'fa fa-minus-square-o', title: 'Delete column' }, command: 'woi-del-col' },
                { attributes: { class: 'fa fa-arrows', title: 'Move' }, command: 'tlb-move' },
                { attributes: { class: 'fa fa-trash-o', title: 'Delete' }, command: 'tlb-delete' }
            ]
        } }
    } );

    // Walk up to the nearest table component from any selection.
    function woiClosestTable( cmp ) {
        while ( cmp ) {
            if ( cmp.get && cmp.get( 'tagName' ) === 'table' ) { return cmp; }
            cmp = cmp.parent && cmp.parent();
        }
        return null;
    }
    function woiTableRows( table ) {
        return table.find( 'tr' );
    }
    editor.Commands.add( 'woi-add-row', { run: function ( ed ) {
        var table = woiClosestTable( ed.getSelected() );
        if ( ! table ) { return; }
        var rows = woiTableRows( table );
        if ( ! rows.length ) { return; }
        var cols = rows[ rows.length - 1 ].components().length || 1;
        var tds = '';
        for ( var i = 0; i < cols; i++ ) { tds += '<td>Cell</td>'; }
        rows[ rows.length - 1 ].parent().append( '<tr>' + tds + '</tr>' );
    } } );
    editor.Commands.add( 'woi-del-row', { run: function ( ed ) {
        var table = woiClosestTable( ed.getSelected() );
        if ( ! table ) { return; }
        var rows = woiTableRows( table );
        if ( rows.length > 1 ) { rows[ rows.length - 1 ].remove(); }
    } } );
    editor.Commands.add( 'woi-add-col', { run: function ( ed ) {
        var table = woiClosestTable( ed.getSelected() );
        if ( ! table ) { return; }
        woiTableRows( table ).forEach( function ( row ) { row.append( '<td>Cell</td>' ); } );
    } } );
    editor.Commands.add( 'woi-del-col', { run: function ( ed ) {
        var table = woiClosestTable( ed.getSelected() );
        if ( ! table ) { return; }
        woiTableRows( table ).forEach( function ( row ) {
            var cells = row.components();
            if ( cells.length > 1 ) { cells.at( cells.length - 1 ).remove(); }
        } );
    } } );

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
        label: 'Table (2 columns)', category: 'Layout',
        attributes: { title: 'Editable table — edit cells, add/remove rows & columns' },
        content: '<table class="woi-row"><tr><td>Cell</td><td>Cell</td></tr></table>'
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

    var REQUIRED_TOKENS = [ 'line_items', 'totals', 'invoice_number', 'invoice_date', 'billing_address' ];

    /** Return the required tokens NOT present in the given design HTML. */
    function missingRequiredTokens( html ) {
        return REQUIRED_TOKENS.filter( function ( t ) {
            return html.indexOf( '{{' + t + '}}' ) === -1;
        } );
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
            var missing = missingRequiredTokens( getHtml() );
            save().then( function () {
                if ( missing.length ) {
                    alert( 'Saved — heads up, these required tokens are missing: ' +
                        missing.map( function ( t ) { return '{{' + t + '}}'; } ).join( ', ' ) );
                } else {
                    alert( 'Saved' );
                }
            } ).catch( function ( e ) {
                alert( 'Save failed: ' + ( e && e.message ? e.message : e ) );
            } );
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
    // --- Real-order preview (control bar rendered by VisualEditorPage) ---
    var selectedOrderId = null;

    function setCurrentOrder( id, label ) {
        selectedOrderId = id;
        var el = document.getElementById( 'woi-order-current' );
        if ( el ) { el.textContent = label ? ( 'Selected: ' + label ) : ''; }
    }

    function orderSearch() {
        var input = document.getElementById( 'woi-order-search' );
        var sel   = document.getElementById( 'woi-order-results' );
        if ( ! input || ! sel ) { return; }
        var term = input.value.trim();
        if ( '' === term ) { setCurrentOrder( null, 'last order' ); sel.style.display = 'none'; return; }

        var body = 'action=' + encodeURIComponent( woiVisual.orderSearchAction ) +
            '&security=' + encodeURIComponent( woiVisual.previewNonce ) +
            '&document_type=' + encodeURIComponent( woiVisual.docType ) +
            '&search=' + encodeURIComponent( term );

        fetch( woiVisual.ajaxUrl, {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            credentials: 'same-origin',
            body: body
        } ).then( function ( r ) { return r.json(); } ).then( function ( res ) {
            sel.innerHTML = '';
            if ( ! res.success || ! res.data ) { alert( 'No orders found.' ); sel.style.display = 'none'; return; }
            Object.keys( res.data ).forEach( function ( id ) {
                var d = res.data[ id ];
                var opt = document.createElement( 'option' );
                opt.value = id;
                opt.textContent = '#' + ( d.order_number || id ) + ' — ' + ( d.billing_first_name || '' ) + ' ' + ( d.billing_last_name || '' );
                sel.appendChild( opt );
            } );
            sel.style.display = 'inline-block';
            sel.selectedIndex = 0;
            setCurrentOrder( sel.value, sel.options[ 0 ].textContent );
        } ).catch( function ( e ) { alert( 'Order search failed: ' + ( e && e.message ? e.message : e ) ); } );
    }

    function previewRealOrder() {
        var url = woiVisual.previewDataUrl + '?doc_type=' + encodeURIComponent( woiVisual.docType );
        if ( selectedOrderId ) { url += '&order_id=' + encodeURIComponent( selectedOrderId ); }

        fetch( url, { headers: { 'X-WP-Nonce': woiVisual.nonce }, credentials: 'same-origin' } )
            .then( function ( r ) {
                if ( ! r.ok ) { throw new Error( r.status === 404 ? 'No order found to preview.' : ( 'HTTP ' + r.status ) ); }
                return r.json();
            } ).then( function ( res ) {
                var html = getHtml();
                Object.keys( res.tokens ).forEach( function ( k ) {
                    html = html.split( k ).join( res.tokens[ k ] );
                } );
                html = html.replace( /\{\{\s*[a-z0-9_]+\s*\}\}/gi, '' );
                var blob = new Blob( [ html ], { type: 'text/html; charset=utf-8' } );
                var blobUrl = URL.createObjectURL( blob );
                window.open( blobUrl, '_blank' );
                setTimeout( function () { URL.revokeObjectURL( blobUrl ); }, 10000 );
                setCurrentOrder( res.order_id, res.order_label );
            } ).catch( function ( e ) { alert( 'Real-order preview failed: ' + ( e && e.message ? e.message : e ) ); } );
    }

    ( function bindOrderBar() {
        var searchBtn  = document.getElementById( 'woi-order-search-btn' );
        var previewBtn = document.getElementById( 'woi-preview-real-order' );
        var sel        = document.getElementById( 'woi-order-results' );
        if ( searchBtn ) { searchBtn.addEventListener( 'click', orderSearch ); }
        if ( previewBtn ) { previewBtn.addEventListener( 'click', previewRealOrder ); }
        if ( sel ) { sel.addEventListener( 'change', function () { setCurrentOrder( sel.value, sel.options[ sel.selectedIndex ].textContent ); } ); }
    }() );

    // --- Preview pane (#4/#5): toggle + tab switching ---
    function woiSetPaneOpen( open ) {
        var pane = document.getElementById( 'woi-preview-pane' );
        if ( ! pane ) { return; }
        if ( open ) { pane.removeAttribute( 'hidden' ); } else { pane.setAttribute( 'hidden', '' ); }
    }
    function woiPaneOpen() {
        var pane = document.getElementById( 'woi-preview-pane' );
        return pane && ! pane.hasAttribute( 'hidden' );
    }
    function woiSetTab( tab ) {
        var html = document.getElementById( 'woi-preview-html' );
        var pdf  = document.getElementById( 'woi-preview-pdf' );
        Array.prototype.forEach.call( document.querySelectorAll( '.woi-preview-tab' ), function ( b ) {
            b.classList.toggle( 'is-active', b.getAttribute( 'data-woi-tab' ) === tab );
        } );
        if ( 'pdf' === tab ) { if ( html ) html.style.display = 'none'; if ( pdf ) pdf.removeAttribute( 'hidden' ); }
        else { if ( html ) html.style.display = ''; if ( pdf ) pdf.setAttribute( 'hidden', '' ); }
    }

    editor.Panels.addButton( 'options', {
        id: 'woi-preview-toggle',
        className: 'fa fa-columns',
        attributes: { title: 'Toggle preview pane' },
        command: function () {
            var open = ! woiPaneOpen();
            woiSetPaneOpen( open );
            if ( open && typeof woiRefreshLiveHtml === 'function' ) { woiRefreshLiveHtml(); }
        }
    } );

    Array.prototype.forEach.call( document.querySelectorAll( '.woi-preview-tab' ), function ( b ) {
        b.addEventListener( 'click', function () {
            var tab = b.getAttribute( 'data-woi-tab' );
            woiSetTab( tab );
            if ( 'html' === tab && typeof woiRefreshLiveHtml === 'function' ) { woiRefreshLiveHtml(); }
        } );
    } );
}() );
