/* editor-app.jsx — Milano Leather "Block Invoice Template" editor.
   A clean, original admin shell (toolbar + blocks outline + inspector) wrapping the
   redesigned A4 invoice, plus a Render-PDF preview and the live Tweaks panel. */

// ----------------------------- invoice data -----------------------------
window.INVOICE_DATA = {
  items: [
    { sku: '20022532', name: 'Classic Milano Cotton Belt', meta: 'Personalisation: JC', qty: 24, rate: 40.0, tax: 0 },
    { sku: '10000014', name: 'Force Automatic Wallet — Black', meta: 'Colour: Black', qty: 12, rate: 25.0, tax: 0 },
    { sku: '10017944', name: 'Classic Milano Auto-Lock Belt', meta: '', qty: 12, rate: 14.0, tax: 0 },
    { sku: '10017913', name: 'Classic Milano PU Pin Buckle Belt', meta: '', qty: 6, rate: 9.0, tax: 0 },
    { sku: '10017876', name: 'Force Casual Pin Buckle Belt', meta: '', qty: 12, rate: 19.0, tax: 0 },
  ],
  subtotal: 1710.0, vat: 0.0, shipping: 0.0, total: 1710.0,
};

const BLOCKS = [
  { id: 'header', label: 'Letterhead', sub: 'Logo · bilingual address · TRN' },
  { id: 'title', label: 'Title & Invoice meta', sub: 'Tax Invoice · numbers · dates' },
  { id: 'parties', label: 'Bill to / Ship to', sub: 'Customer addresses' },
  { id: 'items', label: 'Line items table', sub: '5 rows · 8 columns' },
  { id: 'totals', label: 'Totals', sub: 'Subtotal · VAT · Total' },
  { id: 'bank', label: 'Bank & payment terms', sub: 'IBAN · SWIFT · terms' },
  { id: 'sign', label: 'Signature, stamp & QR', sub: 'Authorised signatory' },
  { id: 'footer', label: 'Footer', sub: 'TRN · web · page number' },
];

const TWEAK_DEFAULTS = /*EDITMODE-BEGIN*/{
  "showThumbs": true,
  "showArabic": true,
  "accent": "#140858",
  "headerLayout": "center",
  "density": "comfortable",
  "font": "grotesque"
}/*EDITMODE-END*/;

// ----------------------------- small icons -----------------------------
const Ico = {
  plus: <svg viewBox="0 0 16 16" width="15" height="15"><path d="M8 3v10M3 8h10" stroke="currentColor" strokeWidth="1.4" fill="none" strokeLinecap="round" /></svg>,
  undo: <svg viewBox="0 0 16 16" width="15" height="15"><path d="M6 4 3 7l3 3M3 7h6.5A3.5 3.5 0 0 1 13 10.5v0" stroke="currentColor" strokeWidth="1.4" fill="none" strokeLinecap="round" strokeLinejoin="round" /></svg>,
  redo: <svg viewBox="0 0 16 16" width="15" height="15"><path d="M10 4l3 3-3 3M13 7H6.5A3.5 3.5 0 0 0 3 10.5v0" stroke="currentColor" strokeWidth="1.4" fill="none" strokeLinecap="round" strokeLinejoin="round" /></svg>,
  list: <svg viewBox="0 0 16 16" width="15" height="15"><path d="M2 4h12M2 8h12M2 12h12" stroke="currentColor" strokeWidth="1.4" strokeLinecap="round" /></svg>,
  gear: <svg viewBox="0 0 16 16" width="15" height="15"><circle cx="8" cy="8" r="2.2" stroke="currentColor" strokeWidth="1.3" fill="none" /><path d="M8 1.5v2M8 12.5v2M1.5 8h2M12.5 8h2M3.4 3.4l1.4 1.4M11.2 11.2l1.4 1.4M12.6 3.4l-1.4 1.4M4.8 11.2l-1.4 1.4" stroke="currentColor" strokeWidth="1.2" strokeLinecap="round" /></svg>,
  expand: <svg viewBox="0 0 16 16" width="15" height="15"><path d="M2 6V2h4M14 6V2h-4M2 10v4h4M14 10v4h-4" stroke="currentColor" strokeWidth="1.3" fill="none" strokeLinecap="round" /></svg>,
  block: <svg viewBox="0 0 16 16" width="13" height="13"><rect x="2.5" y="2.5" width="11" height="11" rx="1.5" stroke="currentColor" strokeWidth="1.2" fill="none" /></svg>,
  chevron: <svg viewBox="0 0 16 16" width="12" height="12"><path d="M6 4l4 4-4 4" stroke="currentColor" strokeWidth="1.4" fill="none" strokeLinecap="round" strokeLinejoin="round" /></svg>,
};

// ----------------------------- fit-to-width hook -----------------------------
function useFit(designW) {
  const ref = React.useRef(null);
  const [scale, setScale] = React.useState(1);
  React.useEffect(() => {
    const el = ref.current; if (!el) return;
    const ro = new ResizeObserver(() => {
      const avail = el.clientWidth - 64;
      setScale(Math.min(1.04, Math.max(0.3, avail / designW)));
    });
    ro.observe(el);
    return () => ro.disconnect();
  }, [designW]);
  return [ref, scale];
}

// ----------------------------- inspector -----------------------------
function InspField({ label, value, type = 'text' }) {
  return (
    <label className="insp-field">
      <span>{label}</span>
      {type === 'check'
        ? <span className={'insp-switch ' + (value ? 'on' : '')}><i /></span>
        : <input readOnly value={value} />}
    </label>
  );
}

function Inspector({ selected, tab, setTab }) {
  const meta = BLOCKS.find((b) => b.id === selected);
  return (
    <aside className="ed-panel ed-panel--right">
      <div className="insp-tabs">
        <button className={tab === 'doc' ? 'on' : ''} onClick={() => setTab('doc')}>Document</button>
        <button className={tab === 'block' ? 'on' : ''} onClick={() => setTab('block')}>Block</button>
      </div>
      <div className="insp-body">
        {tab === 'doc' ? (
          <>
            <div className="insp-sec">Template</div>
            <InspField label="Source" value="Standard UAE Tax Invoice" />
            <InspField label="Page size" value="A4 · Portrait" />
            <InspField label="Document type" value="Tax Invoice" />
            <div className="insp-sec">Localisation</div>
            <InspField label="Second language" value="Arabic (RTL)" />
            <InspField label="Currency" value="AED — د.إ" />
            <InspField label="Visual template" value type="check" />
            <p className="insp-note">Set as the active template source to render the PDF from this design.</p>
          </>
        ) : meta ? (
          <>
            <div className="insp-sec">{meta.label}</div>
            <p className="insp-note">{meta.sub}</p>
            {selected === 'header' && (<>
              <InspField label="Show logo" value type="check" />
              <InspField label="Logo width" value="66 px" />
              <InspField label="Bilingual address" value type="check" />
            </>)}
            {selected === 'items' && (<>
              <InspField label="Thumbnails" value type="check" />
              <InspField label="Barcode column" value type="check" />
              <InspField label="Tax column" value type="check" />
              <InspField label="Row density" value="Comfortable" />
            </>)}
            {selected === 'totals' && (<>
              <InspField label="Show VAT line" value type="check" />
              <InspField label="Amount in words" value type="check" />
            </>)}
            {selected === 'title' && (<>
              <InspField label="English title" value="TAX INVOICE" />
              <InspField label="Arabic title" value="فاتورة ضريبية" />
            </>)}
            {(selected === 'bank' || selected === 'sign' || selected === 'parties' || selected === 'footer') && (<>
              <InspField label="Visible" value type="check" />
              <InspField label="Bilingual labels" value type="check" />
            </>)}
          </>
        ) : (
          <div className="insp-empty">Select a block to edit its settings.</div>
        )}
      </div>
    </aside>
  );
}

// ----------------------------- PDF preview modal -----------------------------
function PdfModal({ open, onClose, children }) {
  if (!open) return null;
  return (
    <div className="pdf-modal" onClick={onClose}>
      <div className="pdf-modal__bar">
        <span>invoice-237.pdf — preview</span>
        <button onClick={onClose} aria-label="Close">✕</button>
      </div>
      <div className="pdf-modal__scroll" onClick={(e) => e.stopPropagation()}>
        <div className="pdf-paper">
          <div className="pdf-watermark">SAMPLE</div>
          {children}
        </div>
      </div>
    </div>
  );
}

// ----------------------------- app -----------------------------
function App() {
  const [t, setTweak] = useTweaks(TWEAK_DEFAULTS);
  const [selected, setSelected] = React.useState('items');
  const [tab, setTab] = React.useState('block');
  const [pdf, setPdf] = React.useState(false);
  const [canvasRef, scale] = useFit(794);

  const fontLabel = { grotesque: 'Grotesque', serif: 'Serif', mono: 'Mono' };

  const invWrap = (forPdf) => (
    <div
      className="inv-scope"
      data-arabic={t.showArabic ? 'on' : 'off'}
      data-thumbs={t.showThumbs ? 'on' : 'off'}
      data-header={t.headerLayout}
      data-density={t.density}
      data-font={t.font}
      style={{ '--accent': t.accent }}
    >
      <InvoiceDoc t={t} selected={forPdf ? null : selected} onSelect={forPdf ? null : (id) => { setSelected(id); setTab('block'); }} />
    </div>
  );

  return (
    <div className="ed">
      {/* global admin strip */}
      <div className="ed-admin">
        <div className="ed-admin__brand"><MilanoMark width={20} /> <b>Milano Leather B2B</b> <span className="ed-live">Live</span></div>
        <div className="ed-admin__right">Hi, Asif Mohtesham</div>
      </div>

      {/* page heading */}
      <div className="ed-head">
        <h2>Block Invoice Template</h2>
        <p>Design the invoice with content blocks. Set it as the active template source to render the PDF from this design.</p>
      </div>

      {/* toolbar */}
      <div className="ed-toolbar">
        <div className="ed-toolbar__grp">
          <button className="ed-tool" title="Add block">{Ico.plus}</button>
          <button className="ed-tool" title="Undo">{Ico.undo}</button>
          <button className="ed-tool" title="Redo">{Ico.redo}</button>
          <button className="ed-tool" title="List view">{Ico.list}</button>
        </div>
        <div className="ed-toolbar__doc">
          <span className="ed-doc-no">#237</span>
          <span className="ed-doc-name">Nesto Hypermarket LLC — Branch Burjnahar, Deira</span>
        </div>
        <div className="ed-toolbar__grp">
          <button className="ed-btn ed-btn--ghost" onClick={() => setPdf(true)}>Render PDF</button>
          <button className="ed-btn ed-btn--solid">Save</button>
          <button className="ed-tool" title="Fullscreen">{Ico.expand}</button>
          <button className="ed-tool" title="Settings">{Ico.gear}</button>
        </div>
      </div>

      {/* body */}
      <div className="ed-body">
        {/* left: blocks outline */}
        <aside className="ed-panel ed-panel--left">
          <div className="ed-panel__title">Blocks</div>
          <div className="ed-tree">
            {BLOCKS.map((b) => (
              <button
                key={b.id}
                className={'ed-node' + (selected === b.id ? ' is-active' : '')}
                onClick={() => { setSelected(b.id); setTab('block'); }}
              >
                <span className="ed-node__ico">{Ico.block}</span>
                <span className="ed-node__txt">
                  <span className="ed-node__label">{b.label}</span>
                  <span className="ed-node__sub">{b.sub}</span>
                </span>
              </button>
            ))}
          </div>
        </aside>

        {/* center: canvas */}
        <div className="ed-canvas" ref={canvasRef} onClick={() => setSelected(null)}>
          <div className="ed-canvas__scaler" style={{ width: 794 * scale, height: 1123 * scale }}>
            <div className="ed-canvas__page" style={{ transform: `scale(${scale})` }}>
              {invWrap(false)}
            </div>
          </div>
        </div>

        {/* right: inspector */}
        <Inspector selected={selected} tab={tab} setTab={setTab} />
      </div>

      {/* render-pdf preview */}
      <PdfModal open={pdf} onClose={() => setPdf(false)}>
        {invWrap(true)}
      </PdfModal>

      {/* tweaks */}
      <TweaksPanel>
        <TweakSection label="Content" />
        <TweakToggle label="Product thumbnails" value={t.showThumbs} onChange={(v) => setTweak('showThumbs', v)} />
        <TweakToggle label="Arabic (bilingual)" value={t.showArabic} onChange={(v) => setTweak('showArabic', v)} />
        <TweakSection label="Layout" />
        <TweakRadio label="Letterhead" value={t.headerLayout} options={[{ value: 'center', label: 'Center' }, { value: 'left', label: 'Left' }]} onChange={(v) => setTweak('headerLayout', v)} />
        <TweakRadio label="Table density" value={t.density} options={[{ value: 'compact', label: 'Compact' }, { value: 'comfortable', label: 'Comfortable' }]} onChange={(v) => setTweak('density', v)} />
        <TweakSection label="Type & colour" />
        <TweakRadio label="Font" value={t.font} options={[{ value: 'grotesque', label: 'Grotesque' }, { value: 'serif', label: 'Serif' }, { value: 'mono', label: 'Mono' }]} onChange={(v) => setTweak('font', v)} />
        <TweakColor label="Accent" value={t.accent} options={['#140858', '#9E0A0E', '#3A3A3A']} onChange={(v) => setTweak('accent', v)} />
      </TweaksPanel>
    </div>
  );
}

ReactDOM.createRoot(document.getElementById('root')).render(<App />);
