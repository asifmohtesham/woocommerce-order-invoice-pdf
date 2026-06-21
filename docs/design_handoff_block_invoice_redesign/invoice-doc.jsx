/* invoice-doc.jsx — Redesigned A4 bilingual UAE Tax Invoice for Milano Leather.
   Consumes props: { t } (tweaks) and reads INVOICE_DATA. Emits class-based markup;
   all styling lives in the host <style>. Arabic is toggled via the root [data-arabic]. */

// ---- deterministic "QR-like" mark (squares only — placeholder for a real QR) ----
function qrMatrix(n, seed) {
  const m = Array.from({ length: n }, () => Array(n).fill(false));
  let s = seed;
  const rnd = () => (s = (s * 1103515245 + 12345) & 0x7fffffff) / 0x7fffffff;
  for (let y = 0; y < n; y++) for (let x = 0; x < n; x++) m[y][x] = rnd() > 0.52;
  const finder = (ox, oy) => {
    for (let y = 0; y < 7; y++) for (let x = 0; x < 7; x++) {
      const border = x === 0 || x === 6 || y === 0 || y === 6;
      const core = x >= 2 && x <= 4 && y >= 2 && y <= 4;
      if (oy + y < n && ox + x < n) m[oy + y][ox + x] = border || core;
    }
    for (let i = 0; i < 8; i++) {
      if (oy + i < n && ox + 7 < n) m[oy + i][ox + 7] = false;
      if (ox + i < n && oy + 7 < n) m[oy + 7][ox + i] = false;
      if (oy + i < n && ox - 1 >= 0) m[oy + i][ox] = m[oy + i][ox]; // noop guard
    }
  };
  finder(0, 0); finder(n - 7, 0); finder(0, n - 7);
  return m;
}

function QRish({ size = 78, n = 25 }) {
  const m = React.useMemo(() => qrMatrix(n, 1337), [n]);
  const cell = size / n;
  const rects = [];
  for (let y = 0; y < n; y++) for (let x = 0; x < n; x++) {
    if (m[y][x]) rects.push(<rect key={y + '-' + x} x={x * cell} y={y * cell} width={cell} height={cell} />);
  }
  return (
    <svg width={size} height={size} viewBox={`0 0 ${size} ${size}`} style={{ display: 'block' }}>
      <g fill="var(--ink)">{rects}</g>
    </svg>
  );
}

// bilingual helper — Arabic span hidden via CSS when [data-arabic="off"]
function Bi({ en, ar, cls = '' }) {
  return (
    <span className={'bi ' + cls}>
      <span className="bi__en">{en}</span>
      {ar ? <span className="bi__ar" dir="rtl"> {ar}</span> : null}
    </span>
  );
}

function Thumb({ tone = 0 }) {
  // tasteful neutral product-photo placeholder (no loud striping)
  return (
    <div className="inv-thumb" data-tone={tone}>
      <svg viewBox="0 0 40 40" width="100%" height="100%" preserveAspectRatio="none">
        <rect x="0.5" y="0.5" width="39" height="39" fill="none" />
        <path d="M7 27 L16 17 L23 24 L29 18 L34 24 L34 33 L7 33 Z" fill="var(--thumb-fg)" opacity="0.5" />
        <circle cx="13" cy="13" r="3" fill="var(--thumb-fg)" opacity="0.5" />
      </svg>
    </div>
  );
}

function InvoiceDoc({ t, selected, onSelect }) {
  const d = window.INVOICE_DATA;
  const sel = (id) => (e) => { e.stopPropagation(); onSelect && onSelect(id); };
  const blk = (id) => 'inv-block' + (selected === id ? ' is-selected' : '');

  return (
    <div className="inv" id="invoice-page">

      {/* ---------------- HEADER ---------------- */}
      <header className={blk('header')} data-block="header" onClick={sel('header')}>
        <div className="inv__header">
          <div className="inv__co inv__co--en">
            <div className="inv__co-name">Milano Leather Trading LLC</div>
            <div className="inv__co-lines">
              <div>Al Buteen, Office 12</div>
              <div>Deira, Dubai — 112247</div>
              <div>United Arab Emirates</div>
            </div>
          </div>

          <div className="inv__brand">
            <MilanoMark width={66} />
            <MilanoWordmark size={12.5} />
          </div>

          <div className="inv__co inv__co--ar ar-col" dir="rtl">
            <div className="inv__co-name">شركة ميلانو لتجارة الجلود ذ.م.م</div>
            <div className="inv__co-lines">
              <div>البطين، مكتب ١٢</div>
              <div>ديرة، دبي — ١١٢٢٤٧</div>
              <div>الإمارات العربية المتحدة</div>
            </div>
          </div>
        </div>

        <div className="inv__contact">
          <div className="inv__contact-item">
            <span className="inv__contact-k"><Bi en="TRN" ar="الرقم الضريبي" /></span>
            <span className="inv__contact-v">100579920800003</span>
          </div>
          <div className="inv__contact-item">
            <span className="inv__contact-k"><Bi en="Tel" ar="هاتف" /></span>
            <span className="inv__contact-v">+971 4 252 6744</span>
          </div>
          <div className="inv__contact-item">
            <span className="inv__contact-k"><Bi en="Email" ar="بريد" /></span>
            <span className="inv__contact-v">info@milanoleather.ae</span>
          </div>
        </div>
      </header>

      {/* ---------------- TITLE + META ---------------- */}
      <section className={blk('title') + ' inv__titlebar'} data-block="title" onClick={sel('title')}>
        <div className="inv__title">
          <h1>TAX INVOICE</h1>
          <span className="inv__title-ar ar" dir="rtl">فاتورة ضريبية</span>
        </div>
        <table className="inv__meta">
          <tbody>
            <tr>
              <th><Bi en="Invoice No." ar="رقم الفاتورة" /></th>
              <td>INV-2026-0237</td>
            </tr>
            <tr>
              <th><Bi en="Order No." ar="رقم الطلب" /></th>
              <td>#237</td>
            </tr>
            <tr>
              <th><Bi en="Issue Date" ar="تاريخ الإصدار" /></th>
              <td>21 Jun 2026</td>
            </tr>
            <tr>
              <th><Bi en="Due Date" ar="تاريخ الاستحقاق" /></th>
              <td>21 Jul 2026</td>
            </tr>
          </tbody>
        </table>
      </section>

      {/* ---------------- PARTIES ---------------- */}
      <section className={blk('parties') + ' inv__parties'} data-block="parties" onClick={sel('parties')}>
        <div className="inv__party">
          <div className="inv__party-label"><Bi en="Bill To" ar="فاتورة إلى" /></div>
          <div className="inv__party-name">Nesto Hypermarket LLC</div>
          <div className="inv__party-lines">
            <div>Branch — Burjnahar, Deira</div>
            <div>Dubai, United Arab Emirates</div>
            <div className="inv__party-trn"><Bi en="TRN" ar="الرقم الضريبي" />&nbsp; 100123456700003</div>
          </div>
        </div>
        <div className="inv__party">
          <div className="inv__party-label"><Bi en="Ship To" ar="الشحن إلى" /></div>
          <div className="inv__party-name">Nesto Hypermarket LLC</div>
          <div className="inv__party-lines">
            <div>Burjnahar, Deira</div>
            <div>Dubai, United Arab Emirates</div>
            <div>+971 4 000 0000</div>
          </div>
        </div>
      </section>

      {/* ---------------- LINE ITEMS ---------------- */}
      <section className={blk('items')} data-block="items" onClick={sel('items')}>
        <table className="inv__items">
          <thead>
            <tr>
              <th className="c-sr"><Bi en="Sr." ar="م" /></th>
              <th className="c-thumb thumb-col"></th>
              <th className="c-sku"><Bi en="Barcode" ar="الباركود" /></th>
              <th className="c-desc"><Bi en="Description" ar="البيان" /></th>
              <th className="c-qty num"><Bi en="Qty" ar="الكمية" /></th>
              <th className="c-rate num"><Bi en="Rate" ar="السعر" /></th>
              <th className="c-tax num"><Bi en="Tax %" ar="الضريبة" /></th>
              <th className="c-total num"><Bi en="Amount" ar="المبلغ" /></th>
            </tr>
          </thead>
          <tbody>
            {d.items.map((it, i) => (
              <tr key={it.sku}>
                <td className="c-sr">{i + 1}</td>
                <td className="c-thumb thumb-col"><Thumb tone={i % 3} /></td>
                <td className="c-sku mono">{it.sku}</td>
                <td className="c-desc">
                  <div className="inv__item-name">{it.name}</div>
                  {it.meta ? <div className="inv__item-meta">{it.meta}</div> : null}
                </td>
                <td className="c-qty num">{it.qty}</td>
                <td className="c-rate num">{it.rate.toFixed(2)}</td>
                <td className="c-tax num">{it.tax}%</td>
                <td className="c-total num">{(it.qty * it.rate).toFixed(2)}</td>
              </tr>
            ))}
          </tbody>
        </table>
      </section>

      {/* ---------------- LOWER: bank/terms + totals ---------------- */}
      <section className="inv__lower">
        <div className={blk('bank') + ' inv__bank'} data-block="bank" onClick={sel('bank')}>
          <div className="inv__sub-label"><Bi en="Bank Details" ar="تفاصيل البنك" /></div>
          <table className="inv__bank-table">
            <tbody>
              <tr><th>Bank</th><td>Emirates NBD</td></tr>
              <tr><th>Account Name</th><td>Milano Leather Trading LLC</td></tr>
              <tr><th>IBAN</th><td className="mono">AE07 0331 2345 6789 0123 456</td></tr>
              <tr><th>Account No.</th><td className="mono">1023456789001</td></tr>
              <tr><th>SWIFT</th><td className="mono">EBILAEAD</td></tr>
            </tbody>
          </table>
          <div className="inv__terms">
            <div className="inv__sub-label"><Bi en="Payment Terms" ar="شروط الدفع" /></div>
            <p>Payment due within 30 days of invoice date. Goods remain the property of Milano Leather Trading LLC until paid in full.</p>
            <p className="ar" dir="rtl">يُستحق الدفع خلال ٣٠ يوماً من تاريخ الفاتورة. تبقى البضاعة ملكاً للشركة حتى سداد كامل المبلغ.</p>
          </div>
        </div>

        <div className={blk('totals') + ' inv__totals'} data-block="totals" onClick={sel('totals')}>
          <table className="inv__totals-table">
            <tbody>
              <tr>
                <th><Bi en="Subtotal" ar="المجموع الفرعي" /></th>
                <td className="num">{d.subtotal.toFixed(2)}</td>
              </tr>
              <tr>
                <th><Bi en="VAT (5%)" ar="ضريبة القيمة المضافة" /></th>
                <td className="num">{d.vat.toFixed(2)}</td>
              </tr>
              <tr>
                <th><Bi en="Shipping" ar="الشحن" /></th>
                <td className="num">{d.shipping.toFixed(2)}</td>
              </tr>
              <tr className="inv__grand">
                <th><Bi en="Total (AED)" ar="المجموع" /></th>
                <td className="num">{d.total.toFixed(2)}</td>
              </tr>
            </tbody>
          </table>
          <div className="inv__amount-words">
            <span className="inv__sub-label"><Bi en="Amount in words" ar="المبلغ كتابةً" /></span>
            <span>UAE Dirham One Thousand Seven Hundred Ten only.</span>
          </div>
        </div>
      </section>

      {/* ---------------- SIGNATURE + QR ---------------- */}
      <section className={blk('sign') + ' inv__sign'} data-block="sign" onClick={sel('sign')}>
        <div className="inv__qr">
          <QRish size={74} />
          <span className="inv__qr-cap"><Bi en="Scan to verify" ar="امسح للتحقق" /></span>
        </div>
        <div className="inv__stamp">
          <span className="inv__stamp-ring">Company<br />Stamp</span>
        </div>
        <div className="inv__signature">
          <div className="inv__sig-line"></div>
          <div className="inv__sig-cap"><Bi en="Authorised Signatory" ar="المُوقّع المُفوّض" /></div>
          <div className="inv__sig-co">For Milano Leather Trading LLC</div>
        </div>
      </section>

      {/* ---------------- FOOTER ---------------- */}
      <footer className={blk('footer') + ' inv__footer'} data-block="footer" onClick={sel('footer')}>
        <span>Milano Leather Trading LLC</span>
        <span className="inv__dot">•</span>
        <span>TRN 100579920800003</span>
        <span className="inv__dot">•</span>
        <span>www.milanoleather.ae</span>
        <span className="inv__dot">•</span>
        <span><Bi en="Page 1 of 1" ar="صفحة ١ من ١" /></span>
      </footer>
    </div>
  );
}

Object.assign(window, { InvoiceDoc });
