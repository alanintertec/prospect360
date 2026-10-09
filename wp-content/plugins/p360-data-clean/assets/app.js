(function () {
  'use strict';
  var C = window.P360, root = document.getElementById('p360-app');
  if (!C || !root) { return; }
  var csrf = '', timer = null;

  function el(tag, attrs, kids) {
    var n = document.createElement(tag);
    Object.keys(attrs || {}).forEach(function (k) {
      if (k === 'text') { n.textContent = attrs[k]; } else if (k.slice(0, 2) === 'on') { n.addEventListener(k.slice(2), attrs[k]); } else { n.setAttribute(k, attrs[k]); }
    });
    (kids || []).forEach(function (c) { if (c) { n.appendChild(typeof c === 'string' ? document.createTextNode(c) : c); } });
    return n;
  }
  function again(fn, ms) { clearTimeout(timer); timer = setTimeout(fn, ms); }
  function pence(p) { return '£' + (p / 100).toFixed(2); }
  function micro(m) { var v = m / 1000000, t = v.toFixed(v < 1 ? 3 : 2); return '£' + (v < 1 ? t.replace(/(\.\d\d)0$/, '$1') : t); }
  function perRow(price) { return '£' + price.toFixed(4).replace(/0+$/, '').replace(/\.$/, ''); }
  function n(x) { return Number(x).toLocaleString(); }
  function fmtDate(t) { return new Date(t * 1000).toLocaleDateString('en-GB', { day: 'numeric', month: 'short', year: 'numeric' }); }
  function api(path, opts) {
    opts = opts || {}; opts.headers = opts.headers || {}; opts.credentials = 'same-origin';
    if (csrf) { opts.headers['X-P360-CSRF'] = csrf; }
    return fetch(C.rest + path, opts).then(function (r) {
      return r.json().then(function (j) { if (!r.ok) { throw new Error(j.error || j.message || 'Request failed'); } return j; });
    });
  }
  function post(path, body) { return api(path, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(body || {}) }); }
  function stripUrl() {
    var u = new URL(location.href);
    ['p360_login', 'p360_topup', 'p360_key'].forEach(function (k) { u.searchParams.delete(k); });
    history.replaceState(null, '', u.pathname + u.search + u.hash);
  }
  function show(kids) {
    clearTimeout(timer);
    root.textContent = '';
    if (C.testMode) { root.appendChild(testBanner()); }
    kids.forEach(function (k) { if (k) { root.appendChild(k); } });
  }
  function card(kids) { return el('div', { 'class': 'p360-card' }, kids); }
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


  /* ---------- buy credit ---------- */
  function buyCredit(emailField) {
    var net = C.packs.length ? C.packs[Math.min(1, C.packs.length - 1)] : C.topupMin;
    var err = el('div', { 'class': 'p360-err', role: 'alert' }), sum = el('table', { 'class': 'p360-sum' });
    var custom = el('input', { type: 'number', min: C.topupMin / 100, max: C.topupMax / 100, step: '1', placeholder: 'Other amount (£)', id: 'p360-custom' });
    var email = emailField ? el('input', { type: 'email', id: 'p360-email', autocomplete: 'email', placeholder: 'you@company.com' }) : null;
    var btn = el('button', { type: 'submit', 'class': 'p360-btn', text: 'Pay securely with Stripe' });
    var packs = el('div', { 'class': 'p360-packs' });
    var form = el('form', { novalidate: 'novalidate' });

    function update() {
      sum.textContent = '';
      if (net >= C.topupMin && net <= C.topupMax) {
        var v = Math.round(net * C.vat);
        sum.appendChild(el('tr', {}, [el('td', { text: 'Credit added to your wallet' }), el('td', { text: pence(net) })]));
        if (v > 0) { sum.appendChild(el('tr', {}, [el('td', { text: 'VAT' }), el('td', { text: pence(v) })])); }
        sum.appendChild(el('tr', { 'class': 't' }, [el('td', { text: 'You pay' }), el('td', { text: pence(net + v) })]));
      }
      Array.prototype.forEach.call(packs.children, function (b) { b.className = 'p360-pack' + (Number(b.getAttribute('data-p')) === net ? ' on' : ''); });
    }
    C.packs.forEach(function (p) {
      packs.appendChild(el('button', { type: 'button', 'class': 'p360-pack', 'data-p': p, text: pence(p), onclick: function () { net = p; custom.value = ''; update(); } }));
    });
    custom.addEventListener('input', function () { net = Math.round(parseFloat(custom.value || '0') * 100); update(); });

    form.appendChild(el('label', { 'class': 'p360-f', text: 'Choose an amount of credit' }));
    form.appendChild(packs); form.appendChild(custom);
    if (email) { form.appendChild(el('label', { 'class': 'p360-f', 'for': 'p360-email', text: 'Your email (this is how you sign in later - no password)' })); form.appendChild(email); }
    form.appendChild(sum); form.appendChild(err); form.appendChild(btn);
    form.appendChild(el('p', { 'class': 'p360-note', text: 'Credit can be used on any service and is valid for ' + C.expiryDays + ' days from your latest top-up.' }));
    update();

    form.addEventListener('submit', function (ev) {
      ev.preventDefault(); err.textContent = '';
      btn.disabled = true; btn.textContent = 'Redirecting to Stripe...';
      post('topup', { amount_pence: net, email: email ? email.value.trim() : '', page_url: location.origin + location.pathname })
        .then(function (j) { location.href = j.url; })
        .catch(function (e) { err.textContent = e.message; btn.disabled = false; btn.textContent = 'Pay securely with Stripe'; });
    });
    return form;
  }

  /* ---------- landing (not signed in) ---------- */
  function renderLanding(notice) {
    var prices = el('table', { 'class': 'p360-prices' }, Object.keys(C.services).map(function (k) {
      var s = C.services[k];
      return el('tr', {}, [el('td', {}, [el('strong', { text: s.label }), el('br'), el('small', { text: s.desc })]), el('td', { text: perRow(s.price) + ' / row' }),
        el('td', {}, [el('a', { href: C.rest + 'sample?service=' + k, text: 'Sample CSV' })])]);
    }));
    var err = el('div', { 'class': 'p360-err', role: 'alert' }), ok = el('div', { 'class': 'p360-ok' });
    var email = el('input', { type: 'email', id: 'p360-login-email', autocomplete: 'email', placeholder: 'you@company.com' });
    var btn = el('button', { type: 'submit', 'class': 'p360-btn alt', text: 'Email me a sign-in link' });
    var login = el('form', { novalidate: 'novalidate' }, [email, err, ok, btn]);
    login.addEventListener('submit', function (ev) {
      ev.preventDefault(); err.textContent = ''; ok.textContent = ''; btn.disabled = true;
      post('login/request', { email: email.value.trim(), page_url: location.origin + location.pathname })
        .then(function (j) { ok.textContent = j.message; btn.disabled = false; })
        .catch(function (e) { err.textContent = e.message; btn.disabled = false; });
    });
    show([
      notice ? el('div', { 'class': 'p360-err', role: 'alert', text: notice }) : null,
      card([el('h3', { text: 'Prepaid credit for every data check' }), el('p', { text: 'Top up once, then clean as many files as your credit allows. One wallet works on all services.' }), prices,
        el('p', { 'class': 'p360-note', text: 'Prices are per row with a value, excluding VAT. Blank rows are free.' })]),
      card([el('h3', { text: 'Buy credit' }), buyCredit(true)]),
      card([el('h3', { text: 'Already have credit?' }), el('p', { text: 'Enter the email you paid with and we will send a one-time sign-in link. There are no passwords.' }), login])
    ]);
  }

  /* ---------- wallet (signed in) ---------- */
  function showWallet(w) { csrf = w.csrf; renderWallet(w); }

  function renderWallet(w) {
    var active = w.jobs.filter(function (j) { return j.status === 'processing'; })[0];
    var out = el('button', { type: 'button', 'class': 'p360-link', text: 'Sign out', onclick: function () { post('logout').then(function () { csrf = ''; renderLanding(); }); } });
    var head = card([el('div', { 'class': 'p360-head' }, [el('span', { text: w.email }), out]),
      el('div', { 'class': 'p360-balance' }, [el('small', { text: 'Your credit' }), el('strong', { text: micro(w.balance) })]),
      w.balance > 0 && w.expires ? el('p', { 'class': 'p360-note', text: 'Valid until ' + fmtDate(w.expires) + ' (extended by each top-up).' }) : null,
      el('details', {}, [el('summary', { text: 'Add credit' }), buyCredit(false)])]);

    var kids = [head];
    var live = null;
    if (active) {
      var bar = el('div', {}); bar.style.width = '0%';
      live = { bar: bar, count: el('p', {}), msg: el('p', { 'class': 'p360-note' }) };
      kids.push(card([el('h3', { text: 'Cleaning your file...' }), el('div', { 'class': 'p360-bar' }, [bar]), live.count, live.msg]));
      paint(live, active);
    } else {
      kids.push(card([uploadForm(w)]));
    }
    if (w.jobs.length) { kids.push(card([filesList(w.jobs)])); }
    if (w.ledger.length) { kids.push(card([activity(w.ledger)])); }
    show(kids);
    if (active) { runJob(active.job, live); }
  }

  function uploadForm(w) {
    var svc = Object.keys(C.services)[0];
    var err = el('div', { 'class': 'p360-err', role: 'alert' }), helpBox = el('div', {}), sample = el('a', { 'class': 'p360-btn alt', text: 'Download sample CSV' });
    var file = el('input', { type: 'file', accept: '.csv,text/csv', id: 'p360-file' });
    var btn = el('button', { type: 'submit', 'class': 'p360-btn', text: 'Upload and clean' });
    var label = el('label', { 'class': 'p360-f', 'for': 'p360-file' });
    var form = el('form', {}, [el('h3', { text: 'Clean a file' })]);
    Object.keys(C.services).forEach(function (k, i) {
      var s = C.services[k];
      var r = el('input', { type: 'radio', name: 'svc', value: k, onchange: function () { svc = k; update(); } });
      if (i === 0) { r.checked = true; }
      form.appendChild(el('label', { 'class': 'p360-svc' }, [r, el('span', {}, [el('strong', { text: s.label }), el('small', { text: s.desc })]), el('span', { 'class': 'p360-price', text: perRow(s.price) + ' / row' })]));
    });
    function update() {
      helpBox.textContent = ''; helpBox.appendChild(helpFor(svc));
      label.textContent = 'Upload a CSV with ' + C.services[svc].columnLabel + ' (max ' + C.maxMb + 'MB)';
      sample.setAttribute('href', C.rest + 'sample?service=' + svc);
    }
    [helpBox, el('p', {}, [sample]), label, file, el('p', { 'class': 'p360-note', text: 'The cost (rows with a value x the price above) is taken from your credit when the file is accepted. Rows that fail because of a lookup fault are refunded automatically.' }), err, btn]
      .forEach(function (x) { form.appendChild(x); });
    update();
    form.addEventListener('submit', function (ev) {
      ev.preventDefault(); err.textContent = '';
      if (!file.files[0]) { err.textContent = 'Please choose a CSV file.'; return; }
      btn.disabled = true; btn.textContent = 'Uploading...';
      var fd = new FormData(); fd.append('service', svc); fd.append('file', file.files[0]);
      api('upload', { method: 'POST', body: fd }).then(refresh)
        .catch(function (e) { err.textContent = e.message; btn.disabled = false; btn.textContent = 'Upload and clean'; });
    });
    return form;
  }

  function refresh() { return api('me').then(showWallet).catch(function () { renderLanding('Please sign in again.'); }); }

  function filesList(jobs) {
    var blocks = jobs.map(function (j) {
      var s = C.services[j.service] || { label: j.service };
      var kids = [el('div', {}, [el('strong', { text: s.label }), ' - ' + fmtDate(j.created) + ', ' + n(j.total) + ' rows, ' + micro(j.cost - j.refunded) + (j.refunded ? ' (after ' + micro(j.refunded) + ' refunded)' : '')])];
      if (j.status === 'processing') { kids.push(el('div', { 'class': 'p360-note', text: 'Processing ' + n(j.done) + ' of ' + n(j.total) + '...' })); }
      else if (j.status === 'expired') { kids.push(el('div', { 'class': 'p360-note', text: 'Expired - files are deleted after a few days.' })); }
      else {
        var st = j.stats, addr = j.service === 'address';
        if (st) {
          kids.push(el('div', {}, [el('span', { 'class': 'p360-pill ok', text: n(st.ok) + (addr ? ' verified' : ' checked') }),
            el('span', { 'class': 'p360-pill bad', text: n(st.invalid) + (addr ? ' not verified' : ' invalid / rejected') }), el('span', { 'class': 'p360-pill', text: n(st.blank) + ' blank' })]));
          var reasons = Object.keys(st.reasons || {});
          if (reasons.length) { kids.push(el('ul', { 'class': 'p360-reasons' }, reasons.map(function (r) { return el('li', { text: n(st.reasons[r]) + ' x ' + r }); }))); }
        }
        var links = [el('a', { 'class': 'p360-btn', href: C.rest + 'download?job=' + j.job, text: 'Download cleaned CSV' })];
        if (st && st.invalid > 0) { links.push(' ', el('a', { 'class': 'p360-btn alt', href: C.rest + 'download?job=' + j.job + '&invalid=1', text: (addr ? 'Rows needing attention (' : 'Invalid rows only (') + n(st.invalid) + ')' })); }
        kids.push(el('p', {}, links));
      }
      return el('div', { 'class': 'p360-job' }, kids);
    });
    return el('div', {}, [el('h3', { text: 'Your files' })].concat(blocks, [el('p', { 'class': 'p360-note', text: 'Files are deleted automatically after a few days, so download them promptly. Fixed rows can be re-uploaded in a new file (they cost credit again).' })]));
  }

  function activity(ledger) {
    var rows = ledger.map(function (l) {
      return el('tr', {}, [el('td', { text: fmtDate(l.time) }), el('td', { text: l.note || l.type }), el('td', { 'class': l.amount < 0 ? 'neg' : 'pos', text: (l.amount < 0 ? '-' : '+') + micro(Math.abs(l.amount)) }), el('td', { text: micro(l.balance) })]);
    });
    return el('details', {}, [el('summary', { text: 'Recent activity' }), el('table', { 'class': 'p360-files' }, [el('tr', {}, [el('th', { text: 'Date' }), el('th', { text: 'Details' }), el('th', { text: 'Amount' }), el('th', { text: 'Balance' })])].concat(rows))]);
  }

  function paint(live, j) {
    var pct = j.total ? Math.round(j.done * 100 / j.total) : 0;
    live.bar.style.width = pct + '%';
    live.count.textContent = n(j.done) + ' of ' + n(j.total) + ' rows (' + pct + '%)';
    live.msg.textContent = j.message || 'Please keep this page open. You can safely come back and sign in again later.';
  }

  function runJob(jobId, live) {
    post('process', { job: jobId }).then(function (j) {
      if (j.status === 'complete') { refresh(); return; }
      paint(live, j);
      again(function () { runJob(jobId, live); }, j.paused ? 15000 : 0);
    }).catch(function (e) {
      if (/sign in|Session check/i.test(e.message)) { renderLanding('Your session ended. Please sign in again to continue.'); return; }
      again(function () { runJob(jobId, live); }, 5000);
    });
  }

  /* ---------- start ---------- */
  var qs = new URLSearchParams(location.search);
  var loginToken = qs.get('p360_login'), topupId = qs.get('p360_topup'), topupKey = qs.get('p360_key');
  if (loginToken || topupId) { stripUrl(); }

  function claim() {
    post('topup/claim', { id: topupId, key: topupKey }).then(function (r) {
      if (r.state === 'pending') { show([card([el('h3', { text: 'Confirming your payment...' }), el('p', { text: 'This usually takes a few seconds.' })])]); again(claim, 3000); }
      else if (r.state === 'ok') { showWallet(r); }
      else { renderLanding('Your payment was received and the credit is in your wallet. Sign in with your email below to use it.'); }
    }).catch(function (e) { renderLanding(e.message); });
  }

  if (loginToken) {
    show([card([el('h3', { text: 'Signing you in...' })])]);
    post('login/redeem', { token: loginToken }).then(showWallet).catch(function (e) { renderLanding(e.message); });
  } else if (topupId && topupKey) {
    claim();
  } else {
    api('me').then(showWallet).catch(function () { renderLanding(); });
  }
})();
