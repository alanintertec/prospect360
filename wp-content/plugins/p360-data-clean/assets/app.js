(function () {
  'use strict';
  var C = window.P360, root = document.getElementById('p360-app');
  if (!C || !root) { return; }
  var qs = new URLSearchParams(location.search);
  var orderId = qs.get('p360_order') || '', orderKey = qs.get('p360_key') || '';

  function el(tag, attrs, kids) {
    var n = document.createElement(tag);
    Object.keys(attrs || {}).forEach(function (k) {
      if (k === 'text') { n.textContent = attrs[k]; } else if (k.slice(0, 2) === 'on') { n.addEventListener(k.slice(2), attrs[k]); } else { n.setAttribute(k, attrs[k]); }
    });
    (kids || []).forEach(function (c) { if (c) { n.appendChild(typeof c === 'string' ? document.createTextNode(c) : c); } });
    return n;
  }
  function money(p) { return '£' + (p / 100).toFixed(2); }
  // mirrors p360_net_pence() in PHP; the server recomputes and is authoritative
  function net(service, n) { return Math.max(C.minCharge, Math.ceil(Math.round(n * C.services[service].price * 100 * 1e6) / 1e6)); }
  function api(path, opts) {
    return fetch(C.rest + path, opts).then(function (r) {
      return r.json().then(function (j) { if (!r.ok) { throw new Error(j.error || j.message || 'Request failed'); } return j; });
    });
  }
  function steps(active) {
    var names = ['1. Choose & pay', '2. Sample CSV', '3. Upload', '4. Download'];
    return el('ul', { 'class': 'p360-steps' }, names.map(function (t, i) { return el('li', { 'class': i === active ? 'on' : '', text: t }); }));
  }

  function testBanner() {
    return el('div', { 'class': 'p360-test' }, [el('strong', { text: 'TEST MODE - no real payment is taken. ' }),
      'Pay with card 4242 4242 4242 4242, any future expiry, any CVC.' + (C.dryRun ? ' DRY RUN: results are fake and nothing is sent to Provero.' : '')]);
  }

  function helpFor(service) {
    var email = service === 'email', tps = service === 'tps';
    if (service === 'address') {
      return el('div', { 'class': 'p360-help' }, [el('strong', { text: 'Accepted format' }), el('ul', {}, [
        'One address per row. Use EITHER a "full_address" column, OR a "postcode" column plus "address_line_1" (and ideally "town_city").',
        'Optional columns: address_line_2, address_line_3, county. Column names such as address1, town, city and post_code also work.',
        'Example: address_line_1 = 20 Canterbury Crescent, town_city = Sheffield, postcode = S10 3RX.',
        'Each address comes back as verified (a complete Royal Mail PAF match), needs review (a possible match) or no match. Non-UK addresses are not supported.'
      ].map(function (t) { return el('li', { text: t }); })),
        el('p', { 'class': 'p360-note', text: 'Please check your data first: each row with any address detail uses one record. Completely blank rows are free.' })]);
    }
    var items = email ? [
      'One email address per row, in a column headed "email".',
      'Format: name@domain.com - no spaces, one @, and a domain with a dot (e.g. jane@example.co.uk).',
      'Anything that does not look like an email is rejected without being checked, but still uses a record.'
    ] : [
      'One phone number per row, in a column headed "phone_number".',
      'Digits only, with optional + ( ) - . and spaces. These are all fine: 07700 900123, +44 7700 900123, +44 (0)7700 900123, +44-7700-900123, 0044 7700 900123.',
      'No letters or other symbols. Numbers without a country code are treated as UK.',
      tps ? 'TPS / CTPS screening works for UK numbers only.' : 'Non-UK numbers are fine if they start with their country code, e.g. +33 1 23 45 67 89.',
      'Values that are not valid phone numbers are rejected without being checked, but still use a record.'
    ];
    return el('div', { 'class': 'p360-help' }, [el('strong', { text: 'Accepted format' }), el('ul', {}, items.map(function (t) { return el('li', { text: t }); })),
      el('p', { 'class': 'p360-note', text: 'Please check your data first: each row with a value uses one record. Blank cells are free.' })]);
  }

  /* ---------- buy ---------- */
  function renderBuy() {
    root.textContent = '';
    var svc = Object.keys(C.services)[0], err = el('div', { 'class': 'p360-err', role: 'alert' });
    var records = el('input', { type: 'number', min: C.min, max: C.max, value: Math.max(C.min, 1000), id: 'p360-records' });
    var email = el('input', { type: 'email', id: 'p360-email', autocomplete: 'email', placeholder: 'you@company.com' });
    var sum = el('table', { 'class': 'p360-sum' }), btn = el('button', { type: 'submit', 'class': 'p360-btn', text: 'Pay securely with Stripe' });
    var form = el('form', { novalidate: 'novalidate' });

    Object.keys(C.services).forEach(function (k, i) {
      var s = C.services[k];
      var r = el('input', { type: 'radio', name: 'svc', value: k, onchange: function () { svc = k; update(); } });
      if (i === 0) { r.checked = true; }
      form.appendChild(el('label', { 'class': 'p360-svc' }, [r, el('span', {}, [el('strong', { text: s.label }), el('small', { text: s.desc })]),
        el('span', { 'class': 'p360-price', text: '£' + s.price.toFixed(4).replace(/0+$/, '').replace(/\.$/, '') + ' / record' })]));
    });
    form.appendChild(el('label', { 'class': 'p360-f', 'for': 'p360-records', text: 'How many records? (' + C.min.toLocaleString() + ' - ' + C.max.toLocaleString() + ')' }));
    form.appendChild(records);
    form.appendChild(el('label', { 'class': 'p360-f', 'for': 'p360-email', text: 'Your email (receipt and order link)' }));
    form.appendChild(email);
    var help = el('div', {}); form.appendChild(help);
    form.appendChild(sum); form.appendChild(err); form.appendChild(btn);
    form.appendChild(el('p', { 'class': 'p360-note', text: 'You buy a balance of records. Each row with a value in your files uses one record (please check your data first), and you can upload several files until the balance is used. Unused records are valid for ' + C.expiryDays + ' days. After payment you can download a sample CSV and upload your files.' }));

    function update() {
      var n = parseInt(records.value, 10) || 0, ok = n >= C.min && n <= C.max;
      sum.textContent = ''; help.textContent = ''; help.appendChild(helpFor(svc));
      if (!ok) { return; }
      var a = net(svc, n), v = Math.round(a * C.vat);
      sum.appendChild(el('tr', {}, [el('td', { text: C.services[svc].label + ' x ' + n.toLocaleString() }), el('td', { text: money(a) })]));
      if (v > 0) { sum.appendChild(el('tr', {}, [el('td', { text: 'VAT' }), el('td', { text: money(v) })])); }
      sum.appendChild(el('tr', { 'class': 't' }, [el('td', { text: 'Total' }), el('td', { text: money(a + v) })]));
    }
    records.addEventListener('input', update); update();

    form.addEventListener('submit', function (ev) {
      ev.preventDefault(); err.textContent = '';
      btn.disabled = true; btn.textContent = 'Redirecting to Stripe...';
      api('checkout', { method: 'POST', headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ service: svc, records: parseInt(records.value, 10), email: email.value.trim(), page_url: location.origin + location.pathname }) })
        .then(function (j) { location.href = j.url; })
        .catch(function (e) { err.textContent = e.message; btn.disabled = false; btn.textContent = 'Pay securely with Stripe'; });
    });
    if (C.testMode) { root.appendChild(testBanner()); }
    root.appendChild(steps(0)); root.appendChild(el('div', { 'class': 'p360-card' }, [form]));
  }

  /* ---------- order ---------- */
  var q = 'id=' + encodeURIComponent(orderId) + '&key=' + encodeURIComponent(orderKey);
  var timer = null;
  function again(fn, ms) { clearTimeout(timer); timer = setTimeout(fn, ms); }
  function card(kids) { root.textContent = ''; if (C.testMode) { root.appendChild(testBanner()); } root.appendChild(kids.shift()); root.appendChild(el('div', { 'class': 'p360-card' }, kids)); }
  function fmtDate(t) { return new Date(t * 1000).toLocaleDateString('en-GB', { day: 'numeric', month: 'short', year: 'numeric' }); }
  function n(x) { return Number(x).toLocaleString(); }

  function loadOrder() {
    clearTimeout(timer);
    api('order?' + q).then(function (o) {
      if (o.status === 'pending') { card([steps(0), el('h3', { text: 'Confirming your payment...' }), el('p', { text: 'This usually takes a few seconds.' })]); again(loadOrder, 3000); }
      else if (o.status === 'paid') { renderOrder(o); }
      else { card([steps(0), el('h3', { text: 'This order has expired' }), el('p', { text: 'Unused records expire ' + C.expiryDays + ' days after payment. Please place a new order.' }), el('a', { 'class': 'p360-btn', href: location.pathname, text: 'New order' })]); }
    }).catch(function (e) { card([steps(0), el('div', { 'class': 'p360-err', text: e.message }), el('a', { 'class': 'p360-btn', href: location.pathname, text: 'Start again' })]); });
  }

  function renderOrder(o) {
    var s = C.services[o.service];
    var active = o.jobs.filter(function (j) { return j.status === 'processing'; })[0];
    var done = o.jobs.some(function (j) { return j.status === 'complete'; });
    var pct = o.records ? Math.round(o.used * 100 / o.records) : 0;
    var usedBar = el('div', {}); usedBar.style.width = pct + '%';
    var kids = [steps(active ? 2 : (done ? 3 : 1)), el('h3', { text: 'Payment received - ' + s.label }),
      el('p', {}, [el('strong', { text: n(o.remaining) }), ' of ' + n(o.records) + ' records remaining. Unused records are valid until ' + fmtDate(o.expires) + '.']),
      el('div', { 'class': 'p360-bar' }, [usedBar]),
      el('p', { 'class': 'p360-note', text: 'Each row with ' + ({ email: 'an email address', address: 'an address' }[o.service] || 'a phone number') + ' uses one record, whether or not the value turns out to be valid. Rows with a blank value are free. You can upload several files until your records are used up.' }),
      el('p', {}, [el('a', { 'class': 'p360-btn alt', href: C.rest + 'sample?' + q, text: 'Download sample CSV' })])];

    var live = null;
    if (active) {
      var bar = el('div', {}); bar.style.width = '0%';
      var count = el('p', {}), msg = el('p', { 'class': 'p360-note' });
      live = { bar: bar, count: count, msg: msg };
      kids.push(el('h3', { text: 'Cleaning your file...' }), el('div', { 'class': 'p360-bar' }, [bar]), count, msg);
      paint(live, active);
    } else if (o.remaining > 0) {
      kids.push(uploadForm(o, s));
    } else {
      kids.push(el('p', {}, ['All records on this order have been used. ', el('a', { href: location.pathname, text: 'Place a new order' }), '.']));
    }
    if (o.jobs.length) { kids.push(filesTable(o.jobs, o.service)); }
    card(kids);
    if (active) { runJob(active.job, live); }
  }

  function uploadForm(o, s) {
    var err = el('div', { 'class': 'p360-err', role: 'alert' });
    var file = el('input', { type: 'file', accept: '.csv,text/csv', id: 'p360-file' });
    var btn = el('button', { type: 'submit', 'class': 'p360-btn', text: 'Upload and clean' });
    var form = el('form', {}, [helpFor(o.service), el('label', { 'class': 'p360-f', 'for': 'p360-file', text: 'Upload a CSV with ' + s.columnLabel + ' (max ' + C.maxMb + 'MB)' }), file, err, btn]);
    form.addEventListener('submit', function (ev) {
      ev.preventDefault(); err.textContent = '';
      if (!file.files[0]) { err.textContent = 'Please choose a CSV file.'; return; }
      btn.disabled = true; btn.textContent = 'Uploading...';
      var fd = new FormData(); fd.append('id', orderId); fd.append('key', orderKey); fd.append('file', file.files[0]);
      api('upload', { method: 'POST', body: fd }).then(loadOrder)
        .catch(function (e) { err.textContent = e.message; btn.disabled = false; btn.textContent = 'Upload and clean'; });
    });
    return form;
  }

  function filesTable(jobs, service) {
    var okLabel = service === 'address' ? ' verified' : ' checked', badLabel = service === 'address' ? ' not verified' : ' invalid / rejected';
    var blocks = jobs.map(function (j) {
      var kids = [el('div', {}, [el('strong', { text: fmtDate(j.created) }), ' - ' + n(j.total) + ' rows, ' + n(j.billed) + ' records used'])];
      if (j.status === 'processing') { kids.push(el('div', { 'class': 'p360-note', text: 'Processing ' + n(j.done) + ' of ' + n(j.total) + '...' })); }
      else if (j.status === 'expired') { kids.push(el('div', { 'class': 'p360-note', text: 'Expired - files are deleted after a few days.' })); }
      else {
        var st = j.stats;
        if (st) {
          kids.push(el('div', {}, [el('span', { 'class': 'p360-pill ok', text: n(st.ok) + okLabel }), el('span', { 'class': 'p360-pill bad', text: n(st.invalid) + badLabel }), el('span', { 'class': 'p360-pill', text: n(st.blank) + ' blank' })]));
          var reasons = Object.keys(st.reasons || {});
          if (reasons.length) { kids.push(el('ul', { 'class': 'p360-reasons' }, reasons.map(function (r) { return el('li', { text: n(st.reasons[r]) + ' x ' + r }); }))); }
        }
        var links = [el('a', { 'class': 'p360-btn', href: C.rest + 'download?' + q + '&job=' + j.job, text: 'Download cleaned CSV' })];
        if (st && st.invalid > 0) { links.push(' ', el('a', { 'class': 'p360-btn alt', href: C.rest + 'download?' + q + '&job=' + j.job + '&invalid=1', text: (service === 'address' ? 'Rows needing attention (' : 'Invalid rows only (') + n(st.invalid) + ')' })); }
        kids.push(el('p', {}, links));
      }
      return el('div', { 'class': 'p360-job' }, kids);
    });
    return el('div', {}, [el('h3', { text: 'Your files' })].concat(blocks, [el('p', { 'class': 'p360-note', text: 'Files are deleted automatically after a few days, so download them promptly. Fixed rows can be re-uploaded in a new file (they use records again).' })]));
  }

  function paint(live, j) {
    var pct = j.total ? Math.round(j.done * 100 / j.total) : 0;
    live.bar.style.width = pct + '%';
    live.count.textContent = n(j.done) + ' of ' + n(j.total) + ' rows (' + pct + '%)';
    live.msg.textContent = j.message || 'Please keep this page open. You can safely come back to the same link later.';
  }

  function runJob(jobId, live) {
    api('process', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ id: orderId, key: orderKey, job: jobId }) })
      .then(function (j) {
        if (j.status === 'complete') { loadOrder(); return; }
        paint(live, j);
        again(function () { runJob(jobId, live); }, j.paused ? 15000 : 0);
      })
      .catch(function () { again(function () { runJob(jobId, live); }, 5000); });
  }

  if (orderId && orderKey) { loadOrder(); } else { renderBuy(); }
})();
