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
        components: ''
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
        // Append a component-definition OBJECT, not an HTML string: a bare
        // '<tr>'/'<td>' fragment is dropped by the HTML parser (invalid outside
        // a table context), so the row would silently never be added.
        var cells = [];
        for ( var i = 0; i < cols; i++ ) { cells.push( { tagName: 'td', type: 'woi-cell', content: 'Cell' } ); }
        rows[ rows.length - 1 ].parent().append( { tagName: 'tr', type: 'woi-trow', components: cells } );
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
        woiTableRows( table ).forEach( function ( row ) { row.append( { tagName: 'td', type: 'woi-cell', content: 'Cell' } ); } );
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

    // Load the stored design (or starter) AFTER the custom component types are
    // registered, so loaded <td>/<table> resolve to the editable woi-cell/woi-table
    // types (registering types AFTER init left loaded cells as the built-in,
    // non-editable 'cell' type — the cause of "preloaded fields not editable").
    editor.setComponents( woiVisual.stored || woiVisual.starter || '' );

    // --- Insert-menu catalog + previews + recent (#4) ---
    var WOI_LAYOUT_ITEMS = [
        { label: 'Table (2 columns)', blockId: 'row-2col', description: 'Editable 2-column table' },
        { label: 'Heading',           blockId: 'heading',  description: 'Section heading' },
        { label: 'Divider',           blockId: 'divider',  description: 'Horizontal rule' },
        { label: 'Spacer',            blockId: 'spacer',   description: 'Vertical space' },
        { label: 'Page break',        blockId: 'pagebreak', description: 'Force a new page' }
    ];
    // Tokens whose preview shows a type hint ([image]/[table]) instead of text.
    // billing_address is intentionally NOT here: it's a line-break address block,
    // so it previews better as decoded text via the scalar path below.
    var WOI_BLOCK_TOKENS = { logo: 1, line_items: 1, totals: 1 };

    // Build the unified catalog: tokens (inline) + layout blocks.
    function woiBuildCatalog() {
        var items = TOKEN_META.map( function ( m ) {
            return { id: 'token-' + m[0], label: m[1], kind: 'token', token: m[0], value: '{{' + m[0] + '}}', category: m[2] };
        } );
        WOI_LAYOUT_ITEMS.forEach( function ( l ) {
            items.push( { id: 'block-' + l.blockId, label: l.label, kind: 'block', blockId: l.blockId, description: l.description, category: 'Layout' } );
        } );
        return items;
    }

    // Live value/hint for a token, from the selected order (or sample data).
    function woiTokenPreview( token ) {
        var map = currentOrderTokens || woiVisual.sampleData || {};
        var raw = map[ '{{' + token + '}}' ];
        if ( raw == null || raw === '' ) { return ''; }
        if ( WOI_BLOCK_TOKENS[ token ] ) {
            if ( token === 'logo' ) { return '[image]'; }
            var rows = ( String( raw ).match( /<tr/gi ) || [] ).length;
            return rows ? '[table · ' + rows + ' rows]' : '[table]';
        }
        // The server token value is esc_html'd, so markup like <br/> arrives as
        // entities (&lt;br/&gt;). Decode entities first (via a textarea), then
        // strip any real tags and collapse whitespace, for a clean preview.
        var ta = document.createElement( 'textarea' );
        ta.innerHTML = String( raw );
        var text = ta.value.replace( /<[^>]*>/g, ' ' ).replace( /\s+/g, ' ' ).trim();
        return text.length > 40 ? text.slice( 0, 40 ) + '…' : text;
    }

    function woiGetRecent() {
        try { return JSON.parse( window.localStorage.getItem( 'woiInsertRecent' ) || '[]' ); }
        catch ( e ) { return []; }
    }
    function woiPushRecent( entry ) {
        try {
            var list = woiGetRecent().filter( function ( id ) { return id !== entry.id; } );
            list.unshift( entry.id );
            window.localStorage.setItem( 'woiInsertRecent', JSON.stringify( list.slice( 0, 6 ) ) );
        } catch ( e ) {}
    }

    // --- Insert-menu popup (#4) ---
    var woiMenuEl = null, woiMenuItems = [], woiMenuActive = -1;

    function woiEnsureMenu() {
        if ( woiMenuEl ) { return woiMenuEl; }
        woiMenuEl = document.createElement( 'div' );
        woiMenuEl.id = 'woi-insert-menu';
        woiMenuEl.hidden = true;
        woiMenuEl.innerHTML = '<input type="text" id="woi-insert-search" placeholder="Search variables…" autocomplete="off"><div id="woi-insert-list"></div>';
        document.body.appendChild( woiMenuEl );

        var search = woiMenuEl.querySelector( '#woi-insert-search' );
        search.addEventListener( 'input', function () { woiRenderMenu( search.value ); } );
        search.addEventListener( 'keydown', function ( e ) {
            if ( e.key === 'ArrowDown' ) { e.preventDefault(); woiMoveMenu( 1 ); }
            else if ( e.key === 'ArrowUp' ) { e.preventDefault(); woiMoveMenu( -1 ); }
            else if ( e.key === 'Enter' ) { e.preventDefault(); woiChooseMenu( woiMenuActive ); }
            else if ( e.key === 'Escape' ) { e.preventDefault(); woiCloseInsertMenu(); }
        } );
        document.addEventListener( 'mousedown', function ( e ) {
            if ( woiMenuEl && ! woiMenuEl.hidden && ! woiMenuEl.contains( e.target ) ) { woiCloseInsertMenu(); }
        } );
        return woiMenuEl;
    }

    function woiRenderMenu( filter ) {
        var list = woiMenuEl.querySelector( '#woi-insert-list' );
        var f = ( filter || '' ).toLowerCase();
        var catalog = woiBuildCatalog();
        var byId = {};
        catalog.forEach( function ( it ) { byId[ it.id ] = it; } );

        // Recent group first (only when no active filter).
        var groups = [];
        if ( ! f ) {
            var recent = woiGetRecent().map( function ( id ) { return byId[ id ]; } ).filter( Boolean );
            if ( recent.length ) { groups.push( { name: 'Recent', items: recent } ); }
        }
        var order = [ 'Shop', 'Document', 'Customer', 'Items & Totals', 'Layout' ];
        order.forEach( function ( cat ) {
            var items = catalog.filter( function ( it ) {
                return it.category === cat && ( ! f || it.label.toLowerCase().indexOf( f ) !== -1 || ( it.token || '' ).indexOf( f ) !== -1 );
            } );
            if ( items.length ) { groups.push( { name: cat, items: items } ); }
        } );

        woiMenuItems = [];
        var html = '';
        groups.forEach( function ( g ) {
            html += '<div class="woi-insert-group">' + g.name + '</div>';
            g.items.forEach( function ( it ) {
                var idx = woiMenuItems.length;
                woiMenuItems.push( it );
                var right = it.kind === 'token'
                    ? '<span class="woi-insert-val">' + woiEsc( woiTokenPreview( it.token ) ) + '</span>'
                    : '<span class="woi-insert-desc">' + woiEsc( it.description ) + '</span>';
                html += '<div class="woi-insert-item" data-idx="' + idx + '">' +
                    '<span class="woi-insert-label">' + woiEsc( it.label ) + '</span>' + right + '</div>';
            } );
        } );
        list.innerHTML = html || '<div class="woi-insert-empty">No matches</div>';
        woiMenuActive = woiMenuItems.length ? 0 : -1;
        woiHighlightMenu();
        Array.prototype.forEach.call( list.querySelectorAll( '.woi-insert-item' ), function ( el ) {
            el.addEventListener( 'mousedown', function ( e ) { e.preventDefault(); woiChooseMenu( parseInt( el.getAttribute( 'data-idx' ), 10 ) ); } );
        } );
    }

    function woiEsc( s ) { return String( s == null ? '' : s ).replace( /&/g, '&amp;' ).replace( /</g, '&lt;' ).replace( />/g, '&gt;' ); }

    function woiHighlightMenu() {
        var els = woiMenuEl.querySelectorAll( '.woi-insert-item' );
        Array.prototype.forEach.call( els, function ( el ) {
            el.classList.toggle( 'is-active', parseInt( el.getAttribute( 'data-idx' ), 10 ) === woiMenuActive );
        } );
        var active = woiMenuEl.querySelector( '.woi-insert-item.is-active' );
        if ( active && active.scrollIntoView ) { active.scrollIntoView( { block: 'nearest' } ); }
    }
    function woiMoveMenu( dir ) {
        if ( ! woiMenuItems.length ) { return; }
        woiMenuActive = ( woiMenuActive + dir + woiMenuItems.length ) % woiMenuItems.length;
        woiHighlightMenu();
    }
    function woiChooseMenu( idx ) {
        var entry = woiMenuItems[ idx ];
        if ( entry && typeof woiInsertSelect === 'function' ) { woiInsertSelect( entry ); }
        woiCloseInsertMenu();
    }

    function woiOpenInsertMenu( x, y ) {
        woiEnsureMenu();
        woiMenuEl.hidden = false;
        if ( typeof x === 'number' && typeof y === 'number' ) {
            woiMenuEl.style.left = Math.max( 8, Math.min( x, window.innerWidth - 320 ) ) + 'px';
            woiMenuEl.style.top = ( y + 6 ) + 'px';
        } else {
            woiMenuEl.style.left = ( window.innerWidth / 2 - 150 ) + 'px';
            woiMenuEl.style.top = '120px';
        }
        var search = woiMenuEl.querySelector( '#woi-insert-search' );
        search.value = '';
        woiRenderMenu( '' );
        search.focus();
    }
    function woiCloseInsertMenu() { if ( woiMenuEl ) { woiMenuEl.hidden = true; } }

    // --- Insertion (#4) ---
    function woiInsertTextAtCaret( doc, text ) {
        var sel = doc.getSelection ? doc.getSelection() : null;
        if ( ! sel || ! sel.rangeCount ) { return false; }
        var range = sel.getRangeAt( 0 );
        range.deleteContents();
        var node = doc.createTextNode( text );
        range.insertNode( node );
        range.setStartAfter( node ); range.setEndAfter( node );
        sel.removeAllRanges(); sel.addRange( range );
        return true;
    }

    function woiInsertSelect( entry ) {
        woiClearSlashQuery();
        var doc = editor.Canvas.getDocument();
        var active = doc ? doc.activeElement : null;
        if ( entry.kind === 'token' ) {
            if ( active && active.isContentEditable && woiInsertTextAtCaret( doc, entry.value ) ) {
                // RTE syncs the component's HTML on blur; force a sync trigger.
                active.dispatchEvent( new Event( 'input', { bubbles: true } ) );
            } else {
                var target = editor.getSelected() || editor.getWrapper();
                target.append( { type: 'text', content: entry.value } );
            }
        } else { // layout block
            var block = editor.BlockManager.get( entry.blockId );
            var content = block ? block.get( 'content' ) : '';
            var selc = editor.getSelected();
            if ( selc && selc.parent && selc.parent() ) {
                var parent = selc.parent();
                var idx = parent.components().indexOf( selc );
                parent.append( content, { at: idx + 1 } );
            } else {
                editor.getWrapper().append( content );
            }
        }
        woiPushRecent( entry );
    }

    // --- "/" trigger (#4): open the insert menu while editing a field ---
    var woiSlash = null; // { node, offset } of the "/" character

    function woiCaretPageXY() {
        var doc = editor.Canvas.getDocument();
        var frame = editor.Canvas.getFrameEl();
        var sel = doc.getSelection && doc.getSelection();
        if ( ! sel || ! sel.rangeCount || ! frame ) { return null; }
        var rect = sel.getRangeAt( 0 ).getBoundingClientRect();
        var fr = frame.getBoundingClientRect();
        return { x: fr.left + rect.left, y: fr.top + rect.bottom };
    }

    function woiBindSlash() {
        var doc = editor.Canvas.getDocument();
        if ( ! doc ) { return; }
        doc.addEventListener( 'keyup', function ( e ) {
            var el = doc.activeElement;
            if ( ! el || ! el.isContentEditable ) { return; }
            if ( e.key === '/' ) {
                var sel = doc.getSelection();
                if ( sel && sel.rangeCount ) {
                    var r = sel.getRangeAt( 0 );
                    // The "/" sits just before the caret.
                    woiSlash = { node: r.startContainer, offset: Math.max( 0, r.startOffset - 1 ) };
                    var xy = woiCaretPageXY();
                    if ( xy ) { woiOpenInsertMenu( xy.x, xy.y ); } else { woiOpenInsertMenu(); }
                }
            }
        } );
    }
    // The canvas iframe may not be ready immediately; bind on load and on each component load.
    editor.on( 'load', woiBindSlash );
    editor.on( 'canvas:frame:load', woiBindSlash );

    function woiClearSlashQuery() {
        if ( ! woiSlash ) { return; }
        var doc = editor.Canvas.getDocument();
        var sel = doc.getSelection && doc.getSelection();
        try {
            if ( sel && sel.rangeCount && woiSlash.node ) {
                var range = doc.createRange();
                range.setStart( woiSlash.node, Math.min( woiSlash.offset, ( woiSlash.node.length || 0 ) ) );
                range.setEnd( sel.getRangeAt( 0 ).endContainer, sel.getRangeAt( 0 ).endOffset );
                range.deleteContents();
                sel.removeAllRanges(); sel.addRange( range );
            }
        } catch ( e ) {}
        woiSlash = null;
    }

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

    // --- Real-order preview (control bar rendered by VisualEditorPage) ---
    var selectedOrderId = null;

    function setCurrentOrder( id, label ) {
        selectedOrderId = id;
        woiSelectedOrderId = id;            // keep PDF engine in sync
        var el = document.getElementById( 'woi-order-current' );
        if ( el ) { el.textContent = label ? ( 'Selected: ' + label ) : ''; }
    }

    function orderSearch() {
        var input = document.getElementById( 'woi-order-search' );
        var sel   = document.getElementById( 'woi-order-results' );
        if ( ! input || ! sel ) { return; }
        var term = input.value.trim();
        if ( '' === term ) {
            setCurrentOrder( null, 'last order' );
            sel.style.display = 'none';
            woiFetchOrderTokens( null ).then( function () {
                woiRefreshLiveHtml();
                if ( typeof woiMaybeRefreshPdf === 'function' ) { woiMaybeRefreshPdf(); }
            } );
            return;
        }

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
            woiFetchOrderTokens( sel.value ).then( function () { woiRefreshLiveHtml(); if ( typeof woiMaybeRefreshPdf === 'function' ) { woiMaybeRefreshPdf(); } } );
        } ).catch( function ( e ) { alert( 'Order search failed: ' + ( e && e.message ? e.message : e ) ); } );
    }

    ( function bindOrderBar() {
        var searchBtn  = document.getElementById( 'woi-order-search-btn' );
        var sel        = document.getElementById( 'woi-order-results' );
        if ( searchBtn ) { searchBtn.addEventListener( 'click', orderSearch ); }
        if ( sel ) { sel.addEventListener( 'change', function () {
            woiSelectedOrderId = sel.value;
            var cur = document.getElementById( 'woi-order-current' );
            if ( cur && sel.options[ sel.selectedIndex ] ) { cur.textContent = 'Selected: ' + sel.options[ sel.selectedIndex ].textContent; }
            woiFetchOrderTokens( sel.value ).then( function () { woiRefreshLiveHtml(); if ( typeof woiMaybeRefreshPdf === 'function' ) { woiMaybeRefreshPdf(); } } );
        } ); }
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

    editor.Panels.addButton( 'options', {
        id: 'woi-insert-var',
        className: 'fa fa-plus-circle',
        attributes: { title: 'Insert variable or block' },
        command: function () { woiOpenInsertMenu(); }
    } );

    Array.prototype.forEach.call( document.querySelectorAll( '.woi-preview-tab' ), function ( b ) {
        b.addEventListener( 'click', function () {
            var tab = b.getAttribute( 'data-woi-tab' );
            woiSetTab( tab );
            if ( 'html' === tab && typeof woiRefreshLiveHtml === 'function' ) { woiRefreshLiveHtml(); }
        } );
    } );

    // --- Live HTML preview engine (#5) ---
    var currentOrderTokens = null; // cached token map for the selected order
    var PREVIEW_CSS =
        'body{font-family:dejavusans,sans-serif;font-size:11pt;color:#222;padding:8mm}' +
        'table{border-collapse:collapse;width:100%}' +
        '.order-details th,.order-details td{border:0.5pt solid #000;padding:2px 4px}' +
        '.totals-table td.price{text-align:right}.totals-table th.description{text-align:inherit}' +
        '.woi-lbl-secondary{display:block;direction:rtl}' +
        '.woi-doc-title{text-align:center;margin:4mm 0}.woi-doc-title .title-en,.woi-doc-title .title-ar{font-size:16pt;font-weight:bold}.woi-doc-title .title-ar{margin-left:6mm}' +
        '.woi-pagebreak{border-top:1px dashed #999;margin:4mm 0}.woi-row td{vertical-align:top}' +
        '[dir="rtl"],.woi-bilingual-secondary{direction:rtl}';

    function woiDebounce( fn, ms ) {
        var t; return function () { clearTimeout( t ); t = setTimeout( fn, ms ); };
    }
    function woiMergeTokens( html, tokens ) {
        var out = html;
        if ( tokens ) {
            Object.keys( tokens ).forEach( function ( k ) { out = out.split( k ).join( tokens[ k ] ); } );
        }
        return out.replace( /\{\{\s*[a-z0-9_]+\s*\}\}/gi, '' );
    }
    function woiWrapForPreview( bodyHtml ) {
        return '<!DOCTYPE html><html><head><meta charset="utf-8"><style>' + PREVIEW_CSS + '</style></head><body>' + bodyHtml + '</body></html>';
    }
    function woiRefreshLiveHtml() {
        var frame = document.getElementById( 'woi-preview-html' );
        if ( ! frame || ! woiPaneOpen() ) { return; }
        var tokens = currentOrderTokens || woiVisual.sampleData;
        frame.srcdoc = woiWrapForPreview( woiMergeTokens( getHtml(), tokens ) );
    }

    // Fetch + cache an order's token map; falls back silently to sample data.
    function woiFetchOrderTokens( orderId ) {
        var url = woiVisual.previewDataUrl + '?doc_type=' + encodeURIComponent( woiVisual.docType );
        if ( orderId ) { url += '&order_id=' + encodeURIComponent( orderId ); }
        return fetch( url, { headers: { 'X-WP-Nonce': woiVisual.nonce }, credentials: 'same-origin' } )
            .then( function ( r ) { return r.ok ? r.json() : null; } )
            .then( function ( res ) {
                if ( res && res.tokens ) {
                    currentOrderTokens = res.tokens;
                    var cur = document.getElementById( 'woi-order-current' );
                    if ( cur && res.order_label ) { cur.textContent = 'Order: ' + res.order_label; }
                }
                return res;
            } )
            .catch( function () { return null; } );
    }

    // Re-render live preview on edits (debounced) and once on init for the last order.
    editor.on( 'update', woiDebounce( woiRefreshLiveHtml, 400 ) );
    woiFetchOrderTokens( null ).then( function () { woiRefreshLiveHtml(); } );

    // --- PDF preview tab (#6): save current design, render real mPDF, embed in-place ---
    var woiSelectedOrderId = null;          // set by the order bar / select
    var woiPdfBlobUrl = null;

    function woiPdfTabActive() {
        var pdf = document.getElementById( 'woi-preview-pdf' );
        return pdf && ! pdf.hasAttribute( 'hidden' );
    }
    function woiRenderPdf() {
        var status = document.getElementById( 'woi-render-pdf-status' );
        var frame  = document.getElementById( 'woi-preview-pdf-frame' );
        if ( ! frame ) { return; }
        if ( status ) { status.textContent = 'Rendering…'; }
        save().then( function () {
            var body = 'action=woi_pdf_preview' +
                '&security=' + encodeURIComponent( woiVisual.previewNonce ) +
                '&document_type=' + encodeURIComponent( woiVisual.docType );
            if ( woiSelectedOrderId ) { body += '&order_id=' + encodeURIComponent( woiSelectedOrderId ); }
            return fetch( woiVisual.ajaxUrl, {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                credentials: 'same-origin',
                body: body
            } );
        } ).then( function ( r ) { if ( ! r.ok ) { throw new Error( 'HTTP ' + r.status ); } return r.json(); } )
        .then( function ( res ) {
            if ( ! res.success || ! res.data || ! res.data.preview_data || res.data.output_format !== 'pdf' ) {
                throw new Error( ( res.data && res.data.error ) ? res.data.error : 'Preview failed.' );
            }
            var binary = window.atob( res.data.preview_data );
            var bytes  = new Uint8Array( binary.length );
            for ( var i = 0; i < binary.length; i++ ) { bytes[ i ] = binary.charCodeAt( i ); }
            if ( woiPdfBlobUrl ) { URL.revokeObjectURL( woiPdfBlobUrl ); }
            woiPdfBlobUrl = URL.createObjectURL( new Blob( [ bytes ], { type: 'application/pdf' } ) );
            frame.src = woiPdfBlobUrl;
            if ( status ) { status.textContent = ''; }
        } ).catch( function ( e ) {
            if ( status ) { status.textContent = 'Error: ' + ( e && e.message ? e.message : e ); }
        } );
    }
    // Re-render the PDF only when its tab is active (avoid a save+round-trip on every edit).
    function woiMaybeRefreshPdf() { if ( woiPaneOpen() && woiPdfTabActive() ) { woiRenderPdf(); } }

    ( function bindPdfTab() {
        var btn = document.getElementById( 'woi-render-pdf' );
        if ( btn ) { btn.addEventListener( 'click', woiRenderPdf ); }
    }() );
    window.addEventListener( 'beforeunload', function () { if ( woiPdfBlobUrl ) { URL.revokeObjectURL( woiPdfBlobUrl ); } } );
}() );
