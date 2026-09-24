const appRoot = document.getElementById('bg-organizer-app');
const main = document.getElementById('bg-app-main');
const nav = document.getElementById('bg-app-nav');
const toast = document.getElementById('bg-app-toast');
const onlineState = document.getElementById('bg-online-state');
const installButton = document.getElementById('bg-install-button');
const refreshButton = document.getElementById('bg-refresh-button');

if (!appRoot || !main || !nav || !toast || !onlineState || !installButton || !refreshButton) {
  throw new Error('BosqueGrande organizer app shell is incomplete.');
}

let wording = {};
try {
  const parsed = JSON.parse(appRoot.dataset.wording || '{}');
  if (parsed && typeof parsed === 'object' && !Array.isArray(parsed)) wording = parsed;
} catch (_error) {
  wording = {};
}

function t(key, fallback = '', tokens = {}) {
  let template = typeof wording[key] === 'string' && wording[key] !== '' ? wording[key] : fallback;
  Object.entries(tokens).forEach(([name, value]) => {
    template = template.replaceAll(`{${name}}`, () => String(value ?? ''));
  });
  return template;
}

const config = Object.freeze({
  restRoot: appRoot.dataset.restRoot || '',
  nonce: appRoot.dataset.restNonce || '',
  appUrl: appRoot.dataset.appUrl || window.location.href.split('#')[0],
  serviceWorkerUrl: appRoot.dataset.swUrl || '',
  version: appRoot.dataset.version || '',
  userName: appRoot.dataset.userName || t('user_fallback', 'Organizer'),
  logoutUrl: appRoot.dataset.logoutUrl || '',
  currency: /^[A-Z]{3}$/.test(appRoot.dataset.currency || '') ? appRoot.dataset.currency : 'USD',
  currencySymbol: appRoot.dataset.currencySymbol || '$',
});

const state = {
  bootstrap: null,
  event: null,
  eventId: 0,
  roster: [],
  rosterState: null,
  receipt: null,
  walkInResult: null,
  attendee: null,
  deferredInstall: null,
  routeSerial: 0,
};

class ApiError extends Error {
  constructor(message, code = 'request_failed', status = 0, data = null) {
    super(message || t('request_failed', 'The request could not be completed.'));
    this.name = 'ApiError';
    this.code = code;
    this.status = status;
    this.data = data;
  }
}

// BEGIN DISPLAY TEXT NORMALIZATION
// WordPress and imported event data can contain encoded punctuation. Some
// legacy titles lost the leading ampersand and arrived as `#8217;`. Decode
// only text entities, then run the result through the normal output escape.
const DISPLAY_ENTITIES = Object.freeze({
  amp: '&',
  quot: '"',
  apos: "'",
  lt: '<',
  gt: '>',
  nbsp: '\u00a0',
  hellip: '\u2026',
  ndash: '\u2013',
  mdash: '\u2014',
  lsquo: '\u2018',
  rsquo: '\u2019',
  ldquo: '\u201c',
  rdquo: '\u201d',
});

const MALFORMED_PUNCTUATION_ENTITY = /(^|[^&;])#(0?39|34|160|8211|8212|8216|8217|8220|8221|8230);/g;

function decodeDisplayCodePoint(raw, fallback) {
  const isHex = String(raw).toLowerCase().startsWith('x');
  const digits = isHex ? String(raw).slice(1) : String(raw);
  const codePoint = Number.parseInt(digits, isHex ? 16 : 10);
  if (!Number.isInteger(codePoint)
      || codePoint < 0
      || codePoint > 0x10ffff
      || (codePoint >= 0xd800 && codePoint <= 0xdfff)) {
    return fallback;
  }
  return String.fromCodePoint(codePoint);
}

function displayText(value) {
  let text = String(value ?? '');
  for (let pass = 0; pass < 3; pass += 1) {
    const previous = text;
    text = text
      .replace(/&amp;(?=#(?:x[0-9a-f]+|[0-9]+);)/gi, '&')
      .replace(MALFORMED_PUNCTUATION_ENTITY, '$1&#$2;')
      .replace(/&#(x[0-9a-f]+|[0-9]+);/gi, (match, raw) => decodeDisplayCodePoint(raw, match))
      .replace(/&(amp|quot|apos|lt|gt|nbsp|hellip|ndash|mdash|lsquo|rsquo|ldquo|rdquo);/gi,
        (match, name) => DISPLAY_ENTITIES[String(name).toLowerCase()] ?? match);
    if (text === previous) break;
  }
  return text;
}

const escapeHtml = (value) => displayText(value)
  .replaceAll('&', '&amp;')
  .replaceAll('<', '&lt;')
  .replaceAll('>', '&gt;')
  .replaceAll('"', '&quot;')
  .replaceAll("'", '&#039;');

const attr = escapeHtml;
// END DISPLAY TEXT NORMALIZATION

function uuid() {
  if (window.crypto && typeof window.crypto.randomUUID === 'function') {
    return window.crypto.randomUUID();
  }
  const bytes = new Uint8Array(16);
  window.crypto.getRandomValues(bytes);
  bytes[6] = (bytes[6] & 0x0f) | 0x40;
  bytes[8] = (bytes[8] & 0x3f) | 0x80;
  const hex = [...bytes].map((byte) => byte.toString(16).padStart(2, '0')).join('');
  return `${hex.slice(0, 8)}-${hex.slice(8, 12)}-${hex.slice(12, 16)}-${hex.slice(16, 20)}-${hex.slice(20)}`;
}

function money(cents) {
  return new Intl.NumberFormat(document.documentElement.lang || undefined, {style: 'currency', currency: config.currency}).format((Number(cents) || 0) / 100);
}

function signedMoney(cents) {
  const amount = Number(cents) || 0;
  if (amount === 0) return money(0);
  return `${amount > 0 ? '+' : '−'}${money(Math.abs(amount))}`;
}

function dollarsToCents(value) {
  const raw = String(value ?? '').trim().replaceAll(',', '');
  if (raw === '') return 0;
  if (!/^\$?\d+(?:\.\d{0,2})?$/.test(raw)) return null;
  const normalized = raw.replace(config.currencySymbol, '').replace('$', '');
  const [whole, decimals = ''] = normalized.split('.');
  const cents = (Number.parseInt(whole, 10) * 100) + Number.parseInt(decimals.padEnd(2, '0') || '0', 10);
  return Number.isSafeInteger(cents) ? cents : null;
}

function centsToInput(cents) {
  return ((Number(cents) || 0) / 100).toFixed(2);
}

function clockLabel(value) {
  const raw = String(value || '').trim();
  if (!raw) return '';
  let match = raw.match(/^(\d{1,2}):(\d{2})(?::\d{2})?$/);
  let hours;
  let minutes;
  if (match) {
    hours = Number(match[1]);
    minutes = Number(match[2]);
  } else {
    match = raw.match(/^(\d{1,2})(?::(\d{2}))?\s*(AM|PM)$/i);
    if (!match) return raw;
    hours = Number(match[1]) % 12;
    minutes = Number(match[2] || 0);
    if (String(match[3]).toUpperCase() === 'PM') hours += 12;
  }
  if (!Number.isFinite(hours) || !Number.isFinite(minutes) || hours > 23 || minutes > 59) return raw;
  const t = new Date(2000, 0, 1, hours, minutes);
  return new Intl.DateTimeFormat('en-US', {hour: 'numeric', minute: '2-digit'}).format(t);
}

function dateLabel(date, time = '', endTime = '') {
  const match = String(date || '').match(/^(\d{4})-(\d{2})-(\d{2})$/);
  let label = date || t('date_not_set', 'Date not set');
  if (match) {
    const local = new Date(Number(match[1]), Number(match[2]) - 1, Number(match[3]));
    label = new Intl.DateTimeFormat('en-US', {weekday: 'short', month: 'short', day: 'numeric', year: 'numeric'}).format(local);
  }
  const start = clockLabel(time);
  const end = clockLabel(endTime);
  if (start && end) label += ` · ${start} – ${end}`;
  else if (start) label += ` · ${start}`;
  else if (end) label += ` · ${t('time_ends', 'ends {time}', {time: end})}`;
  return label;
}

function eventRegionLabel(event) {
  return String(event?.location_region_label || '');
}

function attendanceLabel(status) {
  return ({
    registered: t('attendance_registered', 'Registered'),
    checked_in: t('attendance_checked_in', 'Checked in'),
    attended: t('attendance_attended', 'Attended'),
    no_show: t('attendance_no_show', 'No-show'),
  })[status] || t('attendance_registered', 'Registered');
}

function matchLabel(status) {
  return ({
    matched: t('match_existing', 'Matched to an existing constituent'),
    new: t('match_new', 'New constituent created'),
    ambiguous: t('match_ambiguous', 'Separate provisional constituent created for staff review'),
    existing_registration: t('match_existing_registration', 'Existing event registration checked in'),
  })[status] || t('match_recorded', 'Walk-in recorded');
}

function currentRoute() {
  const raw = decodeURIComponent(window.location.hash.replace(/^#/, '')).replace(/^\/+/, '');
  if (!raw) return {name: 'events', parts: []};
  const parts = raw.split('/').filter(Boolean);
  return {name: parts[0] || 'events', parts: parts.slice(1)};
}

function go(route, replace = false) {
  const target = `#${route.replace(/^#/, '')}`;
  if (replace) window.history.replaceState(null, '', target);
  else window.location.hash = target;
  if (replace) void routeApp();
}

function setBusy(element, busy, label = t('working', 'Working…')) {
  if (!(element instanceof HTMLElement)) return;
  if (busy) {
    element.dataset.originalLabel = element.textContent || '';
    element.textContent = label;
    element.setAttribute('aria-busy', 'true');
    if ('disabled' in element) element.disabled = true;
  } else {
    element.textContent = element.dataset.originalLabel || element.textContent || '';
    element.removeAttribute('aria-busy');
    if ('disabled' in element) element.disabled = false;
  }
}

function showToast(message, tone = 'success') {
  toast.textContent = message;
  toast.dataset.tone = tone;
  toast.hidden = false;
  window.clearTimeout(showToast.timer);
  showToast.timer = window.setTimeout(() => {
    toast.hidden = true;
  }, 5000);
}

function renderError(error, heading = t('error_heading', 'The app could not complete that request')) {
  const message = error instanceof Error ? error.message : String(error || t('unknown_error', 'Unknown error.'));
  main.innerHTML = `
    <section class="bg-panel bg-error-panel" aria-labelledby="bg-error-heading">
      <p class="bg-kicker">${escapeHtml(t('error_kicker', 'Event Organizer App'))}</p>
      <h2 id="bg-error-heading">${escapeHtml(heading)}</h2>
      <p>${escapeHtml(message)}</p>
      <div class="bg-button-row">
        <button class="bg-button bg-button-primary" type="button" data-action="refresh-route">${escapeHtml(t('try_again', 'Try again'))}</button>
        <button class="bg-button bg-button-secondary" type="button" data-route="events">${escapeHtml(t('assigned_events_button', 'Assigned events'))}</button>
      </div>
    </section>`;
  nav.hidden = true;
  main.focus({preventScroll: true});
}

function loading(message = t('loading', 'Loading…')) {
  main.innerHTML = `<div class="bg-loading" role="status"><span class="bg-spinner" aria-hidden="true"></span>${escapeHtml(message)}</div>`;
}

async function api(path, options = {}) {
  const cleanPath = String(path).replace(/^\/+/, '');
  const url = `${config.restRoot}${cleanPath}`;
  const headers = new Headers(options.headers || {});
  headers.set('Accept', 'application/json');
  if (config.nonce) headers.set('X-WP-Nonce', config.nonce);
  let body;
  if (Object.prototype.hasOwnProperty.call(options, 'body')) {
    headers.set('Content-Type', 'application/json');
    body = JSON.stringify(options.body);
  }
  let response;
  try {
    response = await fetch(url, {
      method: options.method || 'GET',
      credentials: 'same-origin',
      cache: 'no-store',
      // A REST request must not follow an authentication or canonical redirect
      // and accidentally treat an HTML page as an application response.
      redirect: 'error',
      headers,
      body,
      signal: options.signal,
    });
  } catch (error) {
    throw new ApiError(
      navigator.onLine ? t('server_unreachable', 'The server could not be reached. Check the connection and try again.') : t('offline_reconnect', 'You are offline. Reconnect before continuing.'),
      'network_error',
      0,
      null
    );
  }

  let payload = null;
  try {
    payload = await response.json();
  } catch (_error) {
    payload = null;
  }

  if (!response.ok) {
    const errorData = payload && typeof payload === 'object' ? payload.data : null;
    throw new ApiError(
      payload?.message || t('http_error', 'The server returned HTTP {status}.', {status: response.status}),
      payload?.code || 'request_failed',
      response.status,
      errorData
    );
  }
  if (!payload || payload.ok !== true) {
    throw new ApiError(t('incomplete_response', 'The server response was incomplete.'), 'invalid_response', response.status, payload);
  }
  return payload.data;
}

async function ensureBootstrap(force = false) {
  if (state.bootstrap && !force) return state.bootstrap;
  const data = await api('bootstrap');
  state.bootstrap = data;
  return data;
}

async function loadEvent(eventId, force = false) {
  if (!force && state.event && state.eventId === eventId) return state.event;
  const data = await api(`events/${eventId}`);
  state.event = data;
  state.eventId = eventId;
  return data;
}

async function loadRoster(eventId, force = false) {
  if (!force && state.eventId === eventId && state.roster.length) {
    return {rows: state.roster, state: state.rosterState};
  }
  const data = await api(`events/${eventId}/roster`);
  state.roster = Array.isArray(data.rows) ? data.rows : [];
  state.rosterState = data.state || null;
  state.eventId = eventId;
  if (state.event && state.event.id === eventId && data.state) state.event.state = data.state;
  return data;
}

function setNav(event, active) {
  if (!event) {
    nav.hidden = true;
    nav.innerHTML = '';
    return;
  }
  const items = [
    {key: 'dashboard', label: t('nav_overview', 'Overview'), route: `event/${event.id}`},
    event.permissions?.view_roster ? {key: 'roster', label: t('nav_checkin', 'Check-in'), route: `roster/${event.id}`} : null,
    event.permissions?.walk_in ? {key: 'walkin', label: t('nav_walkin', 'Walk-in'), route: `walkin/${event.id}`} : null,
    event.permissions?.tender ? {key: 'tender', label: t('nav_payments', 'Payments'), route: `tender/${event.id}`} : null,
    event.permissions?.close_out ? {key: 'closeout', label: t('nav_closeout', 'Closeout'), route: `closeout/${event.id}`} : null,
  ].filter(Boolean);
  nav.innerHTML = items.map((item) => `
    <button type="button" class="bg-nav-item${active === item.key ? ' is-active' : ''}" data-route="${attr(item.route)}" ${active === item.key ? 'aria-current="page"' : ''}>
      <span>${escapeHtml(item.label)}</span>
    </button>`).join('');
  nav.hidden = false;
}

function pageHeading(event, eyebrow, title, description = '') {
  return `
    <div class="bg-page-heading">
      <button class="bg-back-link" type="button" data-route="events" aria-label="${attr(t('back_events_aria', 'Return to assigned events'))}">${escapeHtml(t('back_events', '← Events'))}</button>
      <p class="bg-kicker">${escapeHtml(eyebrow)}</p>
      <h2>${escapeHtml(title)}</h2>
      ${description ? `<p>${escapeHtml(description)}</p>` : ''}
      ${event ? `<p class="bg-event-context"><strong>${escapeHtml(event.title)}</strong><br>${escapeHtml(dateLabel(event.start_date, event.start_time, event.end_time))}${eventRegionLabel(event) ? ` · ${escapeHtml(eventRegionLabel(event))}` : ''}</p>` : ''}
    </div>`;
}

function lockedBanner(event) {
  if (!event?.state?.write_locked) return '';
  return `<div class="bg-alert bg-alert-warning" role="status"><strong>${escapeHtml(t('locked_title', 'Event locked.'))}</strong> ${escapeHtml(t('locked_body', 'This event has a submitted or accepted closeout. Staff must reopen it before attendance, walk-ins, private intake, or payments can change.'))}</div>`;
}

async function renderEvents() {
  document.body.classList.remove('bg-attendee-mode');
  const data = await ensureBootstrap();
  state.event = null;
  state.eventId = 0;
  state.roster = [];
  state.rosterState = null;
  setNav(null, '');
  const events = Array.isArray(data.events) ? data.events : [];
  const eventCards = events.map((event) => `
    <article class="bg-event-card">
      <div>
        <p class="bg-event-date">${escapeHtml(dateLabel(event.start_date, event.start_time, event.end_time))}</p>
        <h3>${escapeHtml(event.title)}</h3>
        ${eventRegionLabel(event) ? `<p class="bg-muted">${escapeHtml(eventRegionLabel(event))}</p>` : ''}
        <p class="bg-muted">${escapeHtml(t('assigned_as', 'Assigned as {role}', {role: String(event.organizer_role || t('role_fallback', 'organizer'))}))}</p>
      </div>
      <button class="bg-button bg-button-primary" type="button" data-route="event/${event.id}">${escapeHtml(t('open_event', 'Open event'))}</button>
    </article>`).join('');
  main.innerHTML = `
    <section class="bg-page">
      <div class="bg-page-heading bg-page-heading-wide">
        <p class="bg-kicker">${escapeHtml(t('signed_in_as', 'Signed in as {name}', {name: data.user?.name || config.userName}))}</p>
        <h2>${escapeHtml(t('assigned_heading', 'Assigned events'))}</h2>
        <p>${escapeHtml(t('assigned_intro', 'Open an event to check people in, register walk-ins, record cash or checks, and complete closeout.'))}</p>
      </div>
      ${events.length ? `<div class="bg-event-list">${eventCards}</div>` : `
        <div class="bg-empty-state"><h3>${escapeHtml(t('no_events_heading', 'No assigned events'))}</h3><p>${escapeHtml(t('no_events_body', 'This account does not currently have an active assignment to a published event.'))}</p></div>`}
      <div class="bg-secondary-actions">
        <button class="bg-button bg-button-secondary" type="button" data-action="install" ${state.deferredInstall ? '' : 'hidden'}>${escapeHtml(t('install_this', 'Install this app'))}</button>
        ${data.user?.my_profile_url ? `<a class="bg-button bg-button-secondary" href="${attr(data.user.my_profile_url)}">${escapeHtml(t('my_profile', 'My Profile'))}</a>` : ''}
        ${config.logoutUrl ? `<a class="bg-text-link" href="${attr(config.logoutUrl)}">${escapeHtml(t('sign_out', 'Sign out'))}</a>` : ''}
      </div>
    </section>`;
  main.focus({preventScroll: true});
}

async function renderDashboard(eventId) {
  const event = await loadEvent(eventId, true);
  const stats = event.state || {};
  setNav(event, 'dashboard');
  main.innerHTML = `
    <section class="bg-page">
      ${pageHeading(null, t('dashboard_kicker', 'Event dashboard'), event.title, dateLabel(event.start_date, event.start_time, event.end_time))}
      ${lockedBanner(event)}
      <div class="bg-stat-grid" aria-label="${attr(t('event_status_aria', 'Event status'))}">
        ${statCard(t('stat_total', 'Total'), stats.total || 0)}
        ${statCard(t('stat_checked_in', 'Checked in'), stats.checked_in || 0)}
        ${statCard(t('stat_attended', 'Attended'), stats.attended || 0)}
        ${statCard(t('stat_walkins', 'Walk-ins'), stats.walk_ins || 0)}
        ${statCard(t('stat_scholarships', 'Scholarships'), stats.scholarship || 0)}
        ${statCard(t('stat_needs_review', 'Needs staff review'), stats.unresolved_records || 0)}
        ${(event.permissions?.tender || event.permissions?.close_out) ? statCard(t('stat_cash', 'Cash'), money(stats.cash_cents || 0)) : ''}
        ${(event.permissions?.tender || event.permissions?.close_out) ? statCard(t('stat_checks', 'Checks'), money(stats.check_cents || 0)) : ''}
      </div>
      <div class="bg-action-grid">
        ${event.permissions?.view_roster ? actionCard(t('action_roster_title', 'Check-in roster'), t('action_roster_body', 'Find registered attendees and update attendance.'), `roster/${event.id}`, t('action_roster_button', 'Open roster')) : ''}
        ${event.permissions?.walk_in ? actionCard(t('action_walkin_title', 'Register a walk-in'), t('action_walkin_body', 'Create or safely match an attendee and add a normal event registration.'), `walkin/${event.id}`, t('action_walkin_button', 'Add walk-in')) : ''}
        ${event.permissions?.tender ? actionCard(t('action_payment_title', 'Cash and checks'), t('action_payment_body', 'Record registration payments and additional donations separately.'), `tender/${event.id}`, t('action_payment_button', 'Record payment')) : ''}
        ${event.permissions?.close_out ? actionCard(t('action_closeout_title', 'Event closeout'), t('action_closeout_body', 'Reconcile expected and counted money, then lock the event for review.'), `closeout/${event.id}`, t('action_closeout_button', 'Open closeout')) : ''}
      </div>
      <div class="bg-panel bg-panel-compact">
        <h3>${escapeHtml(t('status_heading', 'Event status'))}</h3>
        <dl class="bg-detail-list">
          <div><dt>${escapeHtml(t('status_region', 'Region from event title'))}</dt><dd>${escapeHtml(eventRegionLabel(event) || t('status_not_classified', 'Not classified'))}</dd></div>
          <div><dt>${escapeHtml(t('status_closeout', 'Closeout'))}</dt><dd>${escapeHtml(closeoutStatusLabel(stats.closeout_status))}</dd></div>
          ${(event.permissions?.tender || event.permissions?.close_out) ? `<div><dt>${escapeHtml(t('manual_payment_records', 'Manual payment records'))}</dt><dd>${Number(stats.tender_count || 0)}</dd></div>` : ''}
        </dl>
      </div>
    </section>`;
  main.focus({preventScroll: true});
}

function closeoutStatusLabel(status) {
  const code = String(status || 'open');
  return ({
    open: t('status_open', 'open'),
    submitted: t('status_submitted', 'submitted'),
    accepted: t('status_accepted', 'accepted'),
    reopened: t('status_reopened', 'reopened'),
  })[code] || code.replaceAll('_', ' ');
}

function paymentMethodLabel(method) {
  const code = String(method || '');
  return ({cash: t('cash', 'Cash'), check: t('check', 'Check')})[code] || code;
}

function tenderStatusLabel(status) {
  const code = String(status || '');
  return ({
    recorded: t('tender_status_recorded', 'Recorded'),
    reconciled: t('tender_status_reconciled', 'Reconciled'),
    voided: t('tender_status_voided', 'Voided'),
  })[code] || code.replaceAll('_', ' ');
}

function statCard(label, value) {
  return `<div class="bg-stat-card"><span>${escapeHtml(label)}</span><strong>${escapeHtml(value)}</strong></div>`;
}

function actionCard(title, description, route, label) {
  return `<article class="bg-action-card"><h3>${escapeHtml(title)}</h3><p>${escapeHtml(description)}</p><button class="bg-button bg-button-primary" type="button" data-route="${attr(route)}">${escapeHtml(label)}</button></article>`;
}

async function renderRoster(eventId, preserveControls = false) {
  const [event] = await Promise.all([loadEvent(eventId), loadRoster(eventId, !preserveControls)]);
  setNav(event, 'roster');
  const search = preserveControls ? (document.getElementById('bg-roster-search')?.value || '') : '';
  const filter = preserveControls ? (document.getElementById('bg-roster-filter')?.value || 'all') : 'all';
  main.innerHTML = `
    <section class="bg-page">
      ${pageHeading(event, t('roster_kicker', 'Check-in'), t('roster_heading', 'Event roster'), t('roster_intro', 'Search by the contact information BosqueGrande is permitted to show.'))}
      ${lockedBanner(event)}
      <div class="bg-roster-toolbar">
        <label class="bg-field bg-field-grow"><span>${escapeHtml(t('roster_find', 'Find attendee'))}</span><input id="bg-roster-search" type="search" value="${attr(search)}" autocomplete="off" placeholder="${attr(t('roster_placeholder', 'Name, email, phone, or town'))}"></label>
        <label class="bg-field"><span>${escapeHtml(t('roster_status', 'Status'))}</span><select id="bg-roster-filter">
          ${['all', 'registered', 'checked_in', 'attended', 'no_show'].map((value) => `<option value="${value}" ${filter === value ? 'selected' : ''}>${escapeHtml(value === 'all' ? t('roster_all', 'All attendees') : attendanceLabel(value))}</option>`).join('')}
        </select></label>
      </div>
      <p id="bg-roster-result-count" class="bg-muted" aria-live="polite"></p>
      <div id="bg-roster-list" class="bg-roster-list"></div>
    </section>`;
  filterRoster();
  main.focus({preventScroll: true});
}

function filterRoster() {
  const list = document.getElementById('bg-roster-list');
  const count = document.getElementById('bg-roster-result-count');
  if (!list || !count) return;
  const query = String(document.getElementById('bg-roster-search')?.value || '').trim().toLowerCase();
  const status = document.getElementById('bg-roster-filter')?.value || 'all';
  const rows = state.roster.filter((row) => {
    if (status !== 'all' && row.attendance_status !== status) return false;
    if (!query) return true;
    return [row.name, row.email, row.phone, row.city, row.state, row.tier]
      .some((value) => String(value || '').toLowerCase().includes(query));
  });
  count.textContent = t('roster_count', '{shown} of {total} attendees shown', {shown: rows.length, total: state.roster.length});
  list.innerHTML = rows.length ? rows.map(rosterCard).join('') : `<div class="bg-empty-state"><h3>${escapeHtml(t('roster_empty_heading', 'No matching attendees'))}</h3><p>${escapeHtml(t('roster_empty_body', 'Change the search or status filter.'))}</p></div>`;
}

function rosterCard(row) {
  const event = state.event;
  const status = row.attendance_status || 'registered';
  const primary = status === 'registered' || status === 'no_show'
    ? {label: t('check_in', 'Check in'), next: 'checked_in'}
    : status === 'checked_in'
      ? {label: t('mark_attended', 'Mark attended'), next: 'attended'}
      : {label: t('undo_checked_in', 'Undo to checked in'), next: 'checked_in'};
  const contact = row.contact_withheld
    ? `<p class="bg-private-label">${escapeHtml(t('contact_withheld', 'Contact information withheld by attendee choice.'))}</p>`
    : `<p class="bg-contact-line">${[row.email, row.phone, [row.city, row.state].filter(Boolean).join(', ')].filter(Boolean).map(escapeHtml).join(' · ') || escapeHtml(t('no_contact', 'No contact details available'))}</p>`;
  const locked = Boolean(event?.state?.write_locked);
  return `
    <article class="bg-roster-card" data-registration-id="${Number(row.registration_id)}">
      <div class="bg-roster-person">
        <div class="bg-name-line"><h3>${escapeHtml(row.name)}</h3><span class="bg-attendance-badge" data-status="${attr(status)}">${escapeHtml(attendanceLabel(status))}</span></div>
        ${contact}
        <p class="bg-muted">${escapeHtml(t('registration_number', 'Registration #{id}', {id: Number(row.registration_id)}))} · ${escapeHtml(row.tier || t('standard_tier', 'standard'))}</p>
      </div>
      <div class="bg-roster-actions">
        ${event?.permissions?.check_in ? `<button class="bg-button bg-button-primary" type="button" data-action="attendance" data-registration-id="${Number(row.registration_id)}" data-status="${attr(primary.next)}" ${locked ? 'disabled' : ''}>${escapeHtml(primary.label)}</button>` : ''}
        ${event?.permissions?.private_answers && !locked ? `<button class="bg-button bg-button-secondary" type="button" data-action="privacy" data-registration-id="${Number(row.registration_id)}">${escapeHtml(t('attendee_questions', 'Attendee questions'))}</button>` : ''}
        ${event?.permissions?.tender && !locked ? `<button class="bg-button bg-button-secondary" type="button" data-route="tender/${event.id}/${Number(row.registration_id)}">${escapeHtml(t('payment', 'Payment'))}</button>` : ''}
      </div>
      ${event?.permissions?.check_in ? `<details class="bg-correction"><summary>${escapeHtml(t('attendance_correction', 'Attendance correction'))}</summary><div class="bg-inline-form"><label><span>${escapeHtml(t('set_status', 'Set status'))}</span><select data-role="attendance-status">${['registered', 'checked_in', 'attended', 'no_show'].map((value) => `<option value="${value}" ${status === value ? 'selected' : ''}>${escapeHtml(attendanceLabel(value))}</option>`).join('')}</select></label><button class="bg-button bg-button-secondary" type="button" data-action="attendance-selected" data-registration-id="${Number(row.registration_id)}" ${locked ? 'disabled' : ''}>${escapeHtml(t('save_status', 'Save status'))}</button></div></details>` : ''}
    </article>`;
}

async function saveAttendance(button, registrationId, status) {
  const row = state.roster.find((item) => Number(item.registration_id) === registrationId);
  if (!row) return;
  setBusy(button, true, t('saving', 'Saving…'));
  try {
    const result = await api(`events/${state.eventId}/attendance`, {
      method: 'POST',
      body: {
        registration_id: registrationId,
        status,
        revision: row.attendance_revision || '',
      },
    });
    row.attendance_status = result.attendance.status;
    row.attendance_revision = result.attendance.revision;
    state.rosterState = result.state;
    if (state.event) state.event.state = result.state;
    renderRosterCardsOnly();
    showToast(t('attendance_saved', '{name} is now {status}.', {name: row.name, status: attendanceLabel(status).toLowerCase()}));
  } catch (error) {
    if (error instanceof ApiError && error.code === 'attendance_conflict' && error.data?.current) {
      row.attendance_status = error.data.current.status || row.attendance_status;
      row.attendance_revision = error.data.current.revision || '';
      renderRosterCardsOnly();
      showToast(t('attendance_conflict', 'Another organizer changed this attendance record. The current status is now shown.'), 'warning');
    } else {
      showToast(error.message || t('attendance_failed', 'Attendance could not be saved.'), 'error');
    }
  } finally {
    setBusy(button, false);
  }
}

function renderRosterCardsOnly() {
  filterRoster();
}

async function renderWalkIn(eventId) {
  const event = await loadEvent(eventId);
  const boot = await ensureBootstrap();
  setNav(event, 'walkin');
  const tiers = Array.isArray(event.tiers) && event.tiers.length ? event.tiers : (Array.isArray(boot.tiers) ? boot.tiers : []);
  main.innerHTML = `
    <section class="bg-page">
      ${pageHeading(event, t('walkin_kicker', 'Attendee sign-in'), t('walkin_heading', 'Sign in or register an attendee'), t('walkin_intro', 'A returning attendee can verify an exact saved identity and prefill the same contact fields used by online registration. First-time attendees create a normal encrypted contact record.'))}
      ${lockedBanner(event)}
      ${state.walkInResult ? walkInSuccess(event, state.walkInResult) : `
      <form id="bg-attendee-lookup-form" class="bg-panel bg-form" novalidate>
        <div><p class="bg-kicker">${escapeHtml(t('returning_kicker', 'Returning attendee'))}</p><h3>${escapeHtml(t('returning_heading', 'Prefill a saved constituent'))}</h3><p class="bg-form-note">${escapeHtml(t('returning_intro', 'Enter the first and last name plus the email or cell phone already on file. The app never shows a list of candidates and only prefills someone with at least one earlier event.'))}</p></div>
        <div class="bg-form-grid">
          ${field(t('field_first_name', 'First name'), 'first_name', 'text', true, 'given-name')}
          ${field(t('field_last_name', 'Last name'), 'last_name', 'text', true, 'family-name')}
          ${field(t('field_email', 'Email'), 'email', 'email', false, 'email')}
          ${field(t('field_phone', 'Phone'), 'phone', 'tel', false, 'tel')}
        </div>
        <button class="bg-button bg-button-secondary" type="submit" ${event.state?.write_locked ? 'disabled' : ''}>${escapeHtml(t('find_returning', 'Find returning attendee'))}</button>
        <div id="bg-attendee-lookup-status" class="bg-alert bg-alert-neutral" hidden role="status" aria-live="polite"></div>
      </form>
      <form id="bg-walkin-form" class="bg-panel bg-form" data-idempotency-key="${attr(uuid())}" novalidate>
        <div><p class="bg-kicker">${escapeHtml(t('registration_kicker', 'Registration information'))}</p><h3>${escapeHtml(t('contact_heading', 'Attendee contact record'))}</h3><p class="bg-form-note">${escapeHtml(t('contact_intro', 'The attendee may enter this directly, or an organizer may enter it with the attendee. Labels mirror the public registration form.'))}</p></div>
        <div class="bg-form-grid">
          ${field(t('field_first_name', 'First name'), 'first_name', 'text', true, 'given-name')}
          ${field(t('field_last_name', 'Last name'), 'last_name', 'text', true, 'family-name')}
          ${field(t('field_email', 'Email'), 'email', 'email', true, 'email')}
          ${field(t('field_phone', 'Phone'), 'phone', 'tel', false, 'tel')}
          ${field(t('field_street', 'Street address'), 'street', 'text', false, 'street-address')}
          ${field(t('field_city', 'City'), 'city', 'text', false, 'address-level2')}
          ${field(t('field_state', 'State'), 'state', 'text', false, 'address-level1')}
          ${field(t('field_zip', 'ZIP'), 'zip', 'text', false, 'postal-code')}
        </div>
        <label class="bg-field"><span>${escapeHtml(t('registration_tier', 'Registration tier'))}</span><select name="tier" required>
          ${tiers.map((tier, index) => `<option value="${attr(tier.value)}" ${index === 0 ? 'selected' : ''}>${escapeHtml(tier.label)}</option>`).join('')}
        </select></label>
        <div class="bg-alert bg-alert-neutral"><strong>${escapeHtml(t('private_questions_title', 'Private registration questions follow check-in.'))}</strong> ${escapeHtml(t('private_questions_body', 'Personal questions, subscriptions, and consent are collected in Attendee Questions so saved answers are not displayed to the organizer.'))}</div>
        <div class="bg-alert bg-alert-neutral"><strong>${escapeHtml(t('payment_separate_title', 'Payment is a separate step.'))}</strong> ${escapeHtml(t('payment_separate_body', 'Choosing a paid tier records the registration price, but it does not claim that cash or a check was received.'))}</div>
        <button class="bg-button bg-button-primary bg-button-large" type="submit" ${event.state?.write_locked ? 'disabled' : ''}>${escapeHtml(t('sign_in_check_in', 'Sign in and check in'))}</button>
      </form>`}
    </section>`;
  main.focus({preventScroll: true});
}

function field(label, name, type = 'text', required = false, autocomplete = '', placeholder = '') {
  return `<label class="bg-field"><span>${escapeHtml(label)}${required ? ' <b aria-hidden="true">*</b>' : ''}</span><input name="${attr(name)}" type="${attr(type)}" ${required ? 'required' : ''} ${autocomplete ? `autocomplete="${attr(autocomplete)}"` : ''} ${placeholder ? `placeholder="${attr(placeholder)}"` : ''}></label>`;
}


async function submitAttendeeLookup(form, button) {
  const values = Object.fromEntries(new FormData(form).entries());
  const status = document.getElementById('bg-attendee-lookup-status');
  if (!String(values.email || '').trim() && !String(values.phone || '').trim()) {
    if (status) {
      status.textContent = t('lookup_contact_required', 'Enter either the saved email or saved phone number.');
      status.hidden = false;
    }
    return;
  }
  setBusy(button, true, t('checking', 'Checking…'));
  try {
    const result = await api(`events/${state.eventId}/attendee-lookup`, {method: 'POST', body: values});
    if (status) {
      status.textContent = result.message || (result.matched ? t('returning_found', 'Returning attendee found.') : t('returning_not_found', 'No returning attendee matched.'));
      status.className = `bg-alert ${result.matched ? 'bg-alert-success' : result.match_status === 'ambiguous' ? 'bg-alert-warning' : 'bg-alert-neutral'}`;
      status.hidden = false;
    }
    if (!result.matched || !result.prefill) return;
    const intake = document.getElementById('bg-walkin-form');
    if (!(intake instanceof HTMLFormElement)) return;
    Object.entries(result.prefill).forEach(([name, value]) => {
      const input = intake.elements.namedItem(name);
      if (input instanceof HTMLInputElement || input instanceof HTMLSelectElement) input.value = String(value || '');
    });
    intake.dataset.returningConstituentId = String(result.constituent_id || '');
    intake.scrollIntoView({behavior: 'smooth', block: 'start'});
    intake.querySelector('select[name="tier"]')?.focus({preventScroll: true});
  } catch (error) {
    if (status) {
      status.textContent = error.message || t('lookup_failed', 'The returning-attendee lookup could not be completed.');
      status.className = 'bg-alert bg-alert-warning';
      status.hidden = false;
    }
  } finally {
    setBusy(button, false);
  }
}

function walkInSuccess(event, result) {
  const review = result.review_required
    ? `<div class="bg-alert bg-alert-warning"><strong>${escapeHtml(t('review_required_title', 'Staff review required.'))}</strong> ${escapeHtml(t('review_required_body', 'BosqueGrande created a separate provisional constituent because the possible matches were ambiguous.'))}</div>`
    : '';
  return `
    <div class="bg-panel bg-success-panel">
      <p class="bg-kicker">${escapeHtml(t('walkin_success_kicker', 'Walk-in checked in'))}</p>
      <h3>${escapeHtml(result.display_name || `Registration #${result.registration_id}`)}</h3>
      <p>${escapeHtml(matchLabel(result.match_status))}.</p>
      ${review}
      <dl class="bg-detail-list">
        <div><dt>${escapeHtml(t('registration_label', 'Registration'))}</dt><dd>#${Number(result.registration_id)}</dd></div>
        <div><dt>${escapeHtml(t('constituent_label', 'Constituent'))}</dt><dd>#${Number(result.constituent_id)}</dd></div>
      </dl>
      <div class="bg-button-stack">
        ${event.permissions?.private_answers ? `<button class="bg-button bg-button-primary" type="button" data-action="privacy" data-registration-id="${Number(result.registration_id)}">${escapeHtml(t('collect_private', 'Collect private attendee answers'))}</button>` : ''}
        ${event.permissions?.tender ? `<button class="bg-button bg-button-secondary" type="button" data-route="tender/${event.id}/${Number(result.registration_id)}/${Number(result.tier_amount_cents || 0)}">${escapeHtml(t('record_cash_check', 'Record cash or check'))}</button>` : ''}
        <button class="bg-button bg-button-secondary" type="button" data-action="another-walkin">${escapeHtml(t('another_walkin', 'Register another walk-in'))}</button>
        <button class="bg-text-link" type="button" data-route="roster/${event.id}">${escapeHtml(t('return_roster', 'Return to roster'))}</button>
      </div>
    </div>`;
}

async function submitWalkIn(form, button) {
  const values = Object.fromEntries(new FormData(form).entries());
  const eventTiers = Array.isArray(state.event?.tiers) && state.event.tiers.length ? state.event.tiers : (state.bootstrap?.tiers || []);
  const selectedTier = eventTiers.find((tier) => tier.value === values.tier);
  setBusy(button, true, t('registering', 'Registering…'));
  try {
    const result = await api(`events/${state.eventId}/walk-ins`, {
      method: 'POST',
      body: {...values, idempotency_key: form.dataset.idempotencyKey},
    });
    result.display_name = result.display_name || `${values.first_name || ''} ${values.last_name || ''}`.trim();
    result.tier_amount_cents = Number(result.tier_amount_cents ?? selectedTier?.amount_cents ?? 0);
    state.walkInResult = result;
    state.roster = [];
    if (state.event && result.state) state.event.state = result.state;
    await renderWalkIn(state.eventId);
    showToast(t('walkin_saved', 'Walk-in registered and checked in.'));
  } catch (error) {
    showToast(error.message || t('walkin_failed', 'The walk-in could not be registered.'), 'error');
  } finally {
    setBusy(button, false);
  }
}

async function startPrivacy(registrationId, button = null) {
  if (button) setBusy(button, true, t('opening', 'Opening…'));
  try {
    const result = await api(`events/${state.eventId}/attendee-session`, {
      method: 'POST',
      body: {registration_id: registrationId},
    });
    state.attendee = null;
    go(`attendee/${result.token}/${state.eventId}`);
  } catch (error) {
    showToast(error.message || t('privacy_open_failed', 'Attendee privacy mode could not be opened.'), 'error');
  } finally {
    if (button) setBusy(button, false);
  }
}

async function renderTender(eventId, registrationId = 0, suggestedCents = 0) {
  const [event] = await Promise.all([loadEvent(eventId, true), loadRoster(eventId)]);
  setNav(event, 'tender');
  const options = state.roster.map((row) => `<option value="${Number(row.registration_id)}" ${Number(row.registration_id) === registrationId ? 'selected' : ''}>${escapeHtml(row.name)} · #${Number(row.registration_id)}</option>`).join('');
  const recent = Array.isArray(event.tenders) ? event.tenders.slice(0, 20) : [];
  main.innerHTML = `
    <section class="bg-page">
      ${pageHeading(event, t('tender_kicker', 'Cash and checks'), t('tender_heading', 'Record a payment'), t('tender_intro', 'Registration payments and additional donations are separate ledger entries. No bank account or routing information is collected.'))}
      ${lockedBanner(event)}
      ${state.receipt ? receiptPanel(state.receipt) : ''}
      <form id="bg-tender-form" class="bg-panel bg-form" data-idempotency-key="${attr(uuid())}" novalidate>
        <label class="bg-field"><span>${escapeHtml(t('attendee_registration', 'Attendee registration'))}</span><select name="registration_id" required><option value="">${escapeHtml(t('choose_attendee', 'Choose an attendee'))}</option>${options}</select></label>
        <fieldset class="bg-fieldset"><legend>${escapeHtml(t('payment_method', 'Payment method'))}</legend><div class="bg-choice-row">
          <label class="bg-choice"><input type="radio" name="method" value="cash" checked> ${escapeHtml(t('cash', 'Cash'))}</label>
          <label class="bg-choice"><input type="radio" name="method" value="check"> ${escapeHtml(t('check', 'Check'))}</label>
        </div></fieldset>
        <div class="bg-form-grid">
          <label class="bg-field"><span>${escapeHtml(t('registration_payment', 'Registration payment'))}</span><span class="bg-money-input"><span aria-hidden="true">${escapeHtml(config.currencySymbol)}</span><input name="event_fee" type="text" inputmode="decimal" value="${attr(suggestedCents > 0 ? centsToInput(suggestedCents) : '')}" placeholder="0.00"></span></label>
          <label class="bg-field"><span>${escapeHtml(t('additional_donation', 'Additional donation'))}</span><span class="bg-money-input"><span aria-hidden="true">${escapeHtml(config.currencySymbol)}</span><input name="donation" type="text" inputmode="decimal" placeholder="0.00"></span></label>
        </div>
        <label id="bg-check-reference-field" class="bg-field" hidden><span>${escapeHtml(t('check_reference', 'Check number or reference'))} <small>${escapeHtml(t('optional', '(optional)'))}</small></span><input name="check_reference" type="text" maxlength="80" autocomplete="off"></label>
        <div class="bg-alert bg-alert-neutral">${escapeHtml(t('monthly_proof_note', 'A checked monthly-giving amount on the old paper sheet is not proof of an active subscription. Use Attendee Questions to record interest, then complete enrollment through the existing secure monthly-donation flow.'))}</div>
        <button class="bg-button bg-button-primary bg-button-large" type="submit" ${event.state?.write_locked ? 'disabled' : ''}>${escapeHtml(t('action_payment_button', 'Record payment'))}</button>
      </form>
      <section class="bg-panel">
        <h3>${escapeHtml(t('recent_payments', 'Recent event payments'))}</h3>
        ${recent.length ? `<div class="bg-table-scroll"><table class="bg-table"><thead><tr><th>${escapeHtml(t('column_recorded', 'Recorded'))}</th><th>${escapeHtml(t('column_registration', 'Registration'))}</th><th>${escapeHtml(t('column_purpose', 'Purpose'))}</th><th>${escapeHtml(t('column_method', 'Method'))}</th><th>${escapeHtml(t('column_amount', 'Amount'))}</th><th>${escapeHtml(t('column_status', 'Status'))}</th></tr></thead><tbody>${recent.map((row) => `<tr><td>${escapeHtml(row.created_at)}</td><td>#${Number(row.registration_id)}</td><td>${escapeHtml(row.purpose === 'event_fee' ? t('registration_payment', 'Registration payment') : t('donation', 'Donation'))}</td><td>${escapeHtml(paymentMethodLabel(row.method))}</td><td>${escapeHtml(money(row.amount_cents))}</td><td>${escapeHtml(tenderStatusLabel(row.status))}</td></tr>`).join('')}</tbody></table></div>` : `<p class="bg-muted">${escapeHtml(t('no_payments', 'No cash or check payments have been recorded for this event.'))}</p>`}
      </section>
    </section>`;
  toggleCheckReference();
  main.focus({preventScroll: true});
}

function receiptPanel(receipt) {
  const lines = Array.isArray(receipt.lines) ? receipt.lines : [];
  return `
    <section id="bg-payment-receipt" class="bg-panel bg-receipt" aria-labelledby="bg-receipt-heading">
      <p class="bg-kicker">${escapeHtml(t('payment_recorded_kicker', 'Payment recorded'))}</p>
      <h3 id="bg-receipt-heading">${escapeHtml(t('receipt_heading', 'Receipt summary'))}</h3>
      <p>${escapeHtml(t('receipt_registration', 'Registration #{id} · {method}', {id: Number(receipt.registration_id), method: paymentMethodLabel(receipt.method)}))}</p>
      <ul>${lines.map((line) => `<li><span>${escapeHtml(line.purpose === 'event_fee' ? t('registration_payment', 'Registration payment') : t('donation', 'Donation'))}</span><strong>${escapeHtml(money(line.amount_cents))}</strong></li>`).join('')}</ul>
      <p class="bg-receipt-total"><span>${escapeHtml(t('total', 'Total'))}</span><strong>${escapeHtml(money(receipt.total_cents))}</strong></p>
      <div class="bg-button-row"><button class="bg-button bg-button-secondary" type="button" data-action="print-receipt">${escapeHtml(t('print', 'Print'))}</button><button class="bg-text-link" type="button" data-action="clear-receipt">${escapeHtml(t('dismiss', 'Dismiss'))}</button></div>
    </section>`;
}

function toggleCheckReference() {
  const method = document.querySelector('input[name="method"]:checked')?.value;
  const field = document.getElementById('bg-check-reference-field');
  if (field) field.hidden = method !== 'check';
}

async function submitTender(form, button) {
  const data = new FormData(form);
  const eventFee = dollarsToCents(data.get('event_fee'));
  const donation = dollarsToCents(data.get('donation'));
  if (eventFee === null || donation === null) {
    showToast(t('money_format_error', 'Enter money as dollars and cents, such as 10.00.'), 'error');
    return;
  }
  if (eventFee > 10000000 || donation > 10000000) {
    showToast(t('payment_limit_error', 'Each payment line must be {limit} or less.', {limit: money(10000000)}), 'error');
    return;
  }
  if (eventFee <= 0 && donation <= 0) {
    showToast(t('payment_amount_required', 'Enter a registration payment, a donation, or both.'), 'error');
    return;
  }
  setBusy(button, true, t('recording', 'Recording…'));
  try {
    const result = await api(`events/${state.eventId}/tenders`, {
      method: 'POST',
      body: {
        registration_id: Number(data.get('registration_id') || 0),
        method: data.get('method'),
        event_fee_cents: eventFee,
        donation_cents: donation,
        check_reference: data.get('check_reference') || '',
        idempotency_key: form.dataset.idempotencyKey,
      },
    });
    state.receipt = result.receipt;
    if (state.event && result.state) state.event.state = result.state;
    await renderTender(state.eventId, Number(data.get('registration_id') || 0), 0);
    showToast(result.idempotent ? t('payment_existing', 'The existing payment record was returned safely.') : t('payment_saved', 'Payment recorded.'));
  } catch (error) {
    showToast(error.message || t('payment_failed', 'The payment could not be recorded.'), 'error');
  } finally {
    setBusy(button, false);
  }
}

async function renderCloseout(eventId) {
  const [event, closeout] = await Promise.all([loadEvent(eventId, true), api(`events/${eventId}/closeout`)]);
  if (event.state) event.state.write_locked = Boolean(closeout.write_locked);
  setNav(event, 'closeout');
  const locked = Boolean(closeout.write_locked);
  const status = closeout.status || 'open';
  main.innerHTML = `
    <section class="bg-page">
      ${pageHeading(event, t('closeout_kicker', 'Event closeout'), t('closeout_heading', 'Reconcile and submit'), t('closeout_intro', 'Submitting locks organizer changes until staff accepts or reopens the event.'))}
      ${lockedBanner(event)}
      <div class="bg-stat-grid">
        ${statCard(t('attendance_registered', 'Registered'), closeout.registration_count || 0)}
        ${statCard(t('stat_checked_in', 'Checked in'), closeout.checked_in_count || 0)}
        ${statCard(t('stat_attended', 'Attended'), closeout.attended_count || 0)}
        ${statCard(t('stat_walkins', 'Walk-ins'), closeout.walk_in_count || 0)}
        ${statCard(t('stat_scholarships', 'Scholarships'), closeout.scholarship_count || 0)}
        ${statCard(t('stat_needs_review', 'Needs staff review'), closeout.unresolved_records || 0)}
      </div>
      <div class="bg-stat-grid">
        ${statCard(t('expected_cash', 'Expected cash'), money(closeout.expected_cash_cents))}
        ${statCard(t('expected_checks', 'Expected checks'), money(closeout.expected_check_cents))}
        ${statCard(t('registration_payment', 'Registration payment'), money(closeout.registration_payment_cents))}
        ${statCard(t('additional_donation', 'Additional donation'), money(closeout.donation_cents))}
        ${statCard(t('payment_records', 'Payment records'), closeout.tender_count || 0)}
        ${statCard(t('status', 'Status'), closeoutStatusLabel(status))}
      </div>
      ${Number(closeout.unresolved_records || 0) > 0 ? `<div class="bg-alert bg-alert-warning" role="status"><strong>${escapeHtml(t('review_remains_title', 'Staff review remains.'))}</strong> ${escapeHtml(t(Number(closeout.unresolved_records || 0) === 1 ? 'review_remains_one' : 'review_remains_many', Number(closeout.unresolved_records || 0) === 1 ? '{count} intake record still needs staff review. This does not change the counted cash or checks, but staff should resolve the queue before final acceptance.' : '{count} intake records still need staff review. This does not change the counted cash or checks, but staff should resolve the queue before final acceptance.', {count: Number(closeout.unresolved_records || 0)}))}</div>` : ''}
      ${locked ? `
        <div class="bg-panel bg-success-panel">
          <h3>${escapeHtml(t('closeout_status_heading', 'Closeout {status}', {status: closeoutStatusLabel(status)}))}</h3>
          <dl class="bg-detail-list">
            <div><dt>${escapeHtml(t('counted_cash', 'Counted cash'))}</dt><dd>${escapeHtml(money(closeout.counted_cash_cents))}</dd></div>
            <div><dt>${escapeHtml(t('counted_checks', 'Counted checks'))}</dt><dd>${escapeHtml(money(closeout.counted_check_cents))}</dd></div>
            <div><dt>${escapeHtml(t('physical_checks', 'Physical checks'))}</dt><dd>${Number(closeout.check_count || 0)}</dd></div>
            <div><dt>${escapeHtml(t('cash_variance', 'Cash variance'))}</dt><dd>${escapeHtml(signedMoney(closeout.cash_variance_cents))}</dd></div>
            <div><dt>${escapeHtml(t('check_variance', 'Check variance'))}</dt><dd>${escapeHtml(signedMoney(closeout.check_variance_cents))}</dd></div>
          </dl>
          <p>${escapeHtml(t('closeout_locked_body', 'Staff can review this closeout in WordPress. Staff must reopen it before organizers can change event records.'))}</p>
        </div>` : `
        <form id="bg-closeout-form" class="bg-panel bg-form" data-idempotency-key="${attr(uuid())}" data-revision="${attr(closeout.revision || '')}" novalidate>
          <div class="bg-form-grid">
            <label class="bg-field"><span>${escapeHtml(t('counted_cash', 'Counted cash'))}</span><span class="bg-money-input"><span aria-hidden="true">${escapeHtml(config.currencySymbol)}</span><input name="counted_cash" type="text" inputmode="decimal" value="${attr(centsToInput(closeout.counted_cash_cents))}" required></span></label>
            <label class="bg-field"><span>${escapeHtml(t('counted_checks', 'Counted checks'))}</span><span class="bg-money-input"><span aria-hidden="true">${escapeHtml(config.currencySymbol)}</span><input name="counted_check" type="text" inputmode="decimal" value="${attr(centsToInput(closeout.counted_check_cents))}" required></span></label>
            <label class="bg-field"><span>${escapeHtml(t('number_physical_checks', 'Number of physical checks'))} <small>${escapeHtml(t('optional', '(optional)'))}</small></span><input name="check_count" type="number" inputmode="numeric" min="0" max="10000" step="1" value="${attr(Number(closeout.check_count || 0))}"></label>
          </div>
          <div class="bg-variance-grid" aria-live="polite">
            <p>${escapeHtml(t('cash_variance', 'Cash variance'))} <strong id="bg-cash-variance">${escapeHtml(signedMoney(0))}</strong></p>
            <p>${escapeHtml(t('check_variance', 'Check variance'))} <strong id="bg-check-variance">${escapeHtml(signedMoney(0))}</strong></p>
          </div>
          <label class="bg-field"><span>${escapeHtml(t('variance_explanation', 'Variance explanation'))} <small>${escapeHtml(t('variance_required_note', '(required when either variance is not zero)'))}</small></span><textarea name="explanation" rows="5" maxlength="2000"></textarea></label>
          <label class="bg-choice bg-confirm-choice"><input name="confirm_complete" type="checkbox" value="1" required> ${escapeHtml(t('closeout_confirmation', 'I confirm that attendance, cash, check, registration-payment, and donation entries are complete for this event.'))}</label>
          <div class="bg-alert bg-alert-warning"><strong>${escapeHtml(t('submit_locks_title', 'Submitting locks the event.'))}</strong> ${escapeHtml(t('submit_locks_body', 'Attendance, walk-ins, private-answer sessions, and cash/check records cannot change until staff reopens it.'))}</div>
          <button class="bg-button bg-button-primary bg-button-large" type="submit">${escapeHtml(t('submit_closeout', 'Submit closeout for staff review'))}</button>
        </form>`}
    </section>`;
  updateVariance(closeout);
  main.focus({preventScroll: true});
}

function updateVariance(closeout = null) {
  const form = document.getElementById('bg-closeout-form');
  if (!form) return;
  const expectedCash = Number(closeout?.expected_cash_cents ?? state.event?.state?.cash_cents ?? 0);
  const expectedCheck = Number(closeout?.expected_check_cents ?? state.event?.state?.check_cents ?? 0);
  const countedCash = dollarsToCents(new FormData(form).get('counted_cash'));
  const countedCheck = dollarsToCents(new FormData(form).get('counted_check'));
  const cashOutput = document.getElementById('bg-cash-variance');
  const checkOutput = document.getElementById('bg-check-variance');
  if (cashOutput) cashOutput.textContent = countedCash === null ? t('invalid_amount', 'Invalid amount') : signedMoney(countedCash - expectedCash);
  if (checkOutput) checkOutput.textContent = countedCheck === null ? t('invalid_amount', 'Invalid amount') : signedMoney(countedCheck - expectedCheck);
}

async function submitCloseout(form, button) {
  const data = new FormData(form);
  const countedCash = dollarsToCents(data.get('counted_cash'));
  const countedCheck = dollarsToCents(data.get('counted_check'));
  const checkCount = Number.parseInt(String(data.get('check_count') || '0'), 10);
  if (countedCash === null || countedCheck === null || !Number.isInteger(checkCount) || checkCount < 0 || checkCount > 10000) {
    showToast(t('closeout_count_error', 'Enter valid counted totals and a valid number of physical checks.'), 'error');
    return;
  }
  if (data.get('confirm_complete') !== '1') {
    showToast(t('closeout_confirm_error', 'Confirm that attendance and money entries are complete before submitting.'), 'error');
    return;
  }
  if (!window.confirm(t('closeout_confirm_prompt', 'Submit this closeout and lock organizer changes for the event?'))) return;
  setBusy(button, true, t('submitting', 'Submitting…'));
  try {
    const result = await api(`events/${state.eventId}/closeout`, {
      method: 'POST',
      body: {
        counted_cash_cents: countedCash,
        counted_check_cents: countedCheck,
        check_count: checkCount,
        explanation: data.get('explanation') || '',
        expected_revision: form.dataset.revision || '',
        idempotency_key: form.dataset.idempotencyKey,
        confirm_complete: true,
      },
    });
    if (state.event?.state) {
      state.event.state.write_locked = Boolean(result.write_locked);
      state.event.state.closeout_status = result.status;
    }
    await renderCloseout(state.eventId);
    showToast(t('closeout_saved', 'Closeout submitted for staff review.'));
  } catch (error) {
    if (error?.code === 'closeout_stale') await renderCloseout(state.eventId);
    showToast(error.message || t('closeout_failed', 'The closeout could not be submitted.'), 'error');
  } finally {
    setBusy(button, false);
  }
}

async function renderAttendee(token, returnEventId) {
  document.body.classList.add('bg-attendee-mode');
  nav.hidden = true;
  nav.innerHTML = '';
  if (!/^[a-f0-9]{64}$/i.test(token)) throw new ApiError(t('invalid_handoff', 'The attendee handoff token is invalid.'), 'invalid_token', 400);
  let formData = state.attendee?.token === token ? state.attendee.data : null;
  if (!formData) {
    formData = await api(`attendee/${token}`);
    state.attendee = {token, data: formData, returnEventId};
  }
  main.innerHTML = `
    <section class="bg-attendee-page">
      <div class="bg-attendee-heading">
        <p class="bg-kicker">${escapeHtml(t('private_kicker', 'Private attendee questions'))}</p>
        <h2>${escapeHtml(formData.event_title)}</h2>
        <p>${escapeHtml(t('private_intro', 'Please enter these answers yourself. The organizer will not be shown your saved age range or demographic selections.'))}</p>
        <p class="bg-muted">${escapeHtml(t('private_expiry', 'This one-time session expires at {expires} UTC.', {expires: formData.expires_at}))}</p>
      </div>
      <form id="bg-attendee-form" class="bg-panel bg-form" data-token="${attr(token)}" data-return-event="${Number(returnEventId)}" novalidate>
        ${askQuestion(formData, 'age') ? `<fieldset class="bg-fieldset"><legend>${escapeHtml(t('age_range', 'Age range'))} ${requiredMark(formData, 'age')}</legend><div class="bg-choice-grid">
          ${(formData.age_ranges || []).map((value) => `<label class="bg-choice"><input type="radio" name="age_range" value="${attr(value)}" ${questionRequired(formData, 'age') ? 'required' : ''}> ${escapeHtml(value)}</label>`).join('')}
        </div></fieldset>` : ''}
        ${askQuestion(formData, 'demographics') ? `<fieldset class="bg-fieldset"><legend>${escapeHtml(t('demographic_responses', 'Demographic responses'))} ${requiredMark(formData, 'demographics')}</legend><p class="bg-form-note">${escapeHtml(t('demographic_help', 'Choose every response that applies, or choose Prefer not to say.'))}</p><div class="bg-choice-grid bg-choice-grid-long">
          ${(formData.demographics || []).map((value) => `<label class="bg-choice"><input type="checkbox" name="demographics[]" value="${attr(value)}" data-demographic="${attr(value)}"> ${escapeHtml(value)}</label>`).join('')}
        </div>
        <label id="bg-demographic-other-field" class="bg-field" hidden><span>${escapeHtml(t('demographic_other', 'Other demographic response'))}</span><input type="text" name="demographics_other" maxlength="190"></label></fieldset>` : ''}
        ${askQuestion(formData, 'heard') ? `<fieldset class="bg-fieldset"><legend>${escapeHtml(t('heard_legend', 'How did you hear about this event?'))} ${requiredMark(formData, 'heard')}</legend><p class="bg-form-note">${escapeHtml(t('select_all_apply', 'Select all that apply.'))}</p><div class="bg-choice-grid bg-choice-grid-long">
          ${(formData.heard_options || []).map((value) => `<label class="bg-choice"><input type="checkbox" name="heard_about[]" value="${attr(value)}" data-heard="${attr(value)}"> ${escapeHtml(value)}</label>`).join('')}
        </div><label id="bg-heard-other-field" class="bg-field" hidden><span>${escapeHtml(t('please_specify', 'Please specify'))}</span><input type="text" name="heard_other" maxlength="190"></label></fieldset>` : ''}
        ${askQuestion(formData, 'promote') ? `<fieldset class="bg-fieldset"><legend>${escapeHtml(t('promote_question', 'Would you help spread the word about this event?'))} ${requiredMark(formData, 'promote')}</legend><label class="bg-field"><span>${escapeHtml(t('choose_one', 'Choose one'))}</span><select name="promote"><option value="">${escapeHtml(t('choose_one_placeholder', 'Choose one...'))}</option>${(formData.promote_options || []).map((value) => `<option value="${attr(value)}">${escapeHtml(value)}</option>`).join('')}</select></label></fieldset>` : ''}
        ${askQuestion(formData, 'relationship') ? `<fieldset class="bg-fieldset"><legend>${escapeHtml(t('relationship_question', 'What is your relationship status?'))} ${requiredMark(formData, 'relationship')}</legend><div class="bg-choice-grid bg-choice-grid-long">
          ${(formData.relationship_options || []).map((value) => `<label class="bg-choice"><input type="checkbox" name="relationship[]" value="${attr(value)}"> ${escapeHtml(value)}</label>`).join('')}
        </div></fieldset>` : ''}
        <fieldset class="bg-fieldset"><legend>${escapeHtml(t('contact_sharing_question', 'May event organizers view your contact information?'))} <span aria-hidden="true">*</span></legend><div class="bg-choice-stack">
          <label class="bg-choice"><input type="radio" name="contact_sharing" value="allow" required> ${escapeHtml(t('contact_allow', 'Yes, organizers may view my name and contact information for event operations.'))}</label>
          <label class="bg-choice"><input type="radio" name="contact_sharing" value="withhold" required> ${escapeHtml(t('contact_withhold', 'No. Keep my identity and contact information hidden from organizers.'))}</label>
        </div></fieldset>
        ${formData.groups?.length ? `<fieldset class="bg-fieldset"><legend>${escapeHtml(t('email_lists', 'Email lists'))} <small>${escapeHtml(t('optional', '(optional)'))}</small></legend><p class="bg-form-note">${escapeHtml(t('email_lists_help', 'Selecting a list is an affirmative subscription. Leaving a box empty does not unsubscribe you from anything.'))}</p><div class="bg-choice-stack">${formData.groups.map((group) => `<label class="bg-choice"><input type="checkbox" name="group_ids[]" value="${Number(group.id)}"> <span><strong>${escapeHtml(group.name)}</strong>${group.description ? `<small>${escapeHtml(group.description)}</small>` : ''}</span></label>`).join('')}</div></fieldset>` : ''}
        <fieldset class="bg-fieldset"><legend>${escapeHtml(t('monthly_interest', 'Monthly giving interest'))} <small>${escapeHtml(t('optional', '(optional)'))}</small></legend><p class="bg-form-note">${escapeHtml(t('monthly_interest_help', 'This records interest only. It does not enroll you or mark you as a monthly donor.'))}</p><div class="bg-choice-grid">
          ${(formData.monthly_options || [{amount: 0, label: t('no_monthly_followup', 'No monthly-giving follow-up')}]).map((option) => {
            const amount = Number(option?.amount || 0);
            const label = String(option?.label || (amount === 0 ? t('no_monthly_followup', 'No monthly-giving follow-up') : t('per_month', '{amount} per month', {amount: money(amount)})));
            return `<label class="bg-choice"><input type="radio" name="monthly_interest_cents" value="${amount}" ${amount === 0 ? 'checked' : ''}> ${escapeHtml(label)}</label>`;
          }).join('')}
        </div></fieldset>
        <button class="bg-button bg-button-primary bg-button-large" type="submit">${escapeHtml(t('save_answers', 'Save my answers'))}</button>
      </form>
    </section>`;
  main.focus({preventScroll: true});
}

function askQuestion(formData, key) {
  return Boolean(formData?.questions?.[key]?.enabled);
}

function questionRequired(formData, key) {
  return Boolean(formData?.questions?.[key]?.enabled && formData?.questions?.[key]?.required);
}

function requiredMark(formData, key) {
  return questionRequired(formData, key) ? '<span aria-hidden="true">*</span>' : `<small>${escapeHtml(t('optional', '(optional)'))}</small>`;
}

function attendeeSuccess(result, returnEventId) {
  main.innerHTML = `
    <section class="bg-attendee-page">
      <div class="bg-panel bg-success-panel">
        <p class="bg-kicker">${escapeHtml(t('saved_securely', 'Saved securely'))}</p>
        <h2>${escapeHtml(t('answers_saved_heading', 'Your answers were saved'))}</h2>
        <p>${escapeHtml(result.message || t('answers_saved_fallback', 'Your answers and choices were saved.'))}</p>
        ${Number(result.monthly_interest_cents || 0) > 0 ? `<div class="bg-alert bg-alert-neutral"><strong>${escapeHtml(t('monthly_incomplete_title', 'Monthly giving is not complete yet.'))}</strong> ${escapeHtml(t('monthly_incomplete_body', 'Continue to the secure enrollment form to activate a recurring donation.'))}</div><a class="bg-button bg-button-primary" href="${attr(result.monthly_url)}" target="_blank" rel="noopener">${escapeHtml(t('continue_monthly', 'Continue to monthly enrollment'))}</a>` : ''}
        <button class="bg-button bg-button-secondary" type="button" data-action="return-organizer" data-event-id="${Number(returnEventId)}">${escapeHtml(t('return_device', 'Return device to organizer'))}</button>
      </div>
    </section>`;
  main.focus({preventScroll: true});
}

async function submitAttendee(form, button) {
  const data = new FormData(form);
  const demographics = data.getAll('demographics[]');
  const heardAbout = data.getAll('heard_about[]');
  const formData = state.attendee?.data || null;
  if (questionRequired(formData, 'heard') && !heardAbout.length) {
    showToast(t('heard_required', 'Choose at least one response for how you heard about the event.'), 'error');
    return;
  }
  setBusy(button, true, t('saving_securely', 'Saving securely…'));
  try {
    const result = await api(`attendee/${form.dataset.token}`, {
      method: 'POST',
      body: {
        age_range: data.get('age_range') || '',
        demographics,
        demographics_other: data.get('demographics_other') || '',
        heard_about: heardAbout,
        heard_other: data.get('heard_other') || '',
        promote: data.get('promote') || '',
        relationship: data.getAll('relationship[]'),
        contact_sharing: data.get('contact_sharing') || '',
        group_ids: data.getAll('group_ids[]').map(Number),
        monthly_interest_cents: Number(data.get('monthly_interest_cents') || 0),
      },
    });
    state.attendee = null;
    // The token is one-use and short-lived, but remove it from the current
    // browser-history entry immediately after a successful submission.
    window.history.replaceState(null, '', `#attendee-complete/${Number(form.dataset.returnEvent || 0)}`);
    attendeeSuccess(result, Number(form.dataset.returnEvent || 0));
  } catch (error) {
    showToast(error.message || t('answers_failed', 'Your answers could not be saved.'), 'error');
  } finally {
    setBusy(button, false);
  }
}

function updateDemographicChoices(changed) {
  if (!(changed instanceof HTMLInputElement) || changed.dataset.demographic === undefined) return;
  const boxes = [...document.querySelectorAll('input[data-demographic]')];
  if (changed.value === 'Prefer not to say' && changed.checked) {
    boxes.forEach((box) => { if (box !== changed) box.checked = false; });
  } else if (changed.checked) {
    const pnts = boxes.find((box) => box.value === 'Prefer not to say');
    if (pnts) pnts.checked = false;
  }
  const otherField = document.getElementById('bg-demographic-other-field');
  const other = boxes.find((box) => box.value === 'Other');
  if (otherField) otherField.hidden = !other?.checked;
}

function updateHeardChoices(changed) {
  if (!(changed instanceof HTMLInputElement) || changed.dataset.heard === undefined) return;
  const otherField = document.getElementById('bg-heard-other-field');
  const other = [...document.querySelectorAll('input[data-heard]')].find((box) => box.value === 'Other');
  if (otherField) otherField.hidden = !other?.checked;
}

async function routeApp(force = false) {
  const serial = ++state.routeSerial;
  const route = currentRoute();
  try {
    if (route.name === 'attendee') {
      loading(t('loading_private', 'Opening private attendee questions…'));
      await renderAttendee(route.parts[0] || '', Number(route.parts[1] || 0));
      return;
    }
    document.body.classList.remove('bg-attendee-mode');
    await ensureBootstrap(force);
    if (serial !== state.routeSerial) return;
    switch (route.name) {
      case 'event':
        loading(t('loading_dashboard', 'Loading event dashboard…'));
        await renderDashboard(Number(route.parts[0] || 0));
        break;
      case 'roster':
        loading(t('loading_roster', 'Loading event roster…'));
        await renderRoster(Number(route.parts[0] || 0), false);
        break;
      case 'walkin':
        loading(t('loading_walkin', 'Loading walk-in intake…'));
        state.walkInResult = null;
        await renderWalkIn(Number(route.parts[0] || 0));
        break;
      case 'tender':
        loading(t('loading_payments', 'Loading payment records…'));
        await renderTender(Number(route.parts[0] || 0), Number(route.parts[1] || 0), Number(route.parts[2] || 0));
        break;
      case 'closeout':
        loading(t('loading_closeout', 'Loading event closeout…'));
        await renderCloseout(Number(route.parts[0] || 0));
        break;
      case 'events':
      default:
        loading(t('loading_assigned', 'Loading assigned events…'));
        await renderEvents();
        break;
    }
  } catch (error) {
    if (serial !== state.routeSerial) return;
    renderError(error);
  }
}

function updateOnlineState() {
  const online = navigator.onLine;
  onlineState.textContent = online ? t('online', 'Online') : t('offline', 'Offline');
  onlineState.dataset.state = online ? 'online' : 'offline';
  if (!online) showToast(t('offline_write_warning', 'You are offline. BosqueGrande will not queue attendance or payment changes.'), 'warning');
}

async function registerServiceWorker() {
  if (!('serviceWorker' in navigator) || !config.serviceWorkerUrl) return;
  try {
    const scope = new URL(config.appUrl, window.location.href).pathname;
    await navigator.serviceWorker.register(config.serviceWorkerUrl, {scope});
  } catch (_error) {
    // The app remains fully usable online without service-worker support.
  }
}

window.addEventListener('beforeinstallprompt', (event) => {
  event.preventDefault();
  state.deferredInstall = event;
  installButton.hidden = false;
  const inPage = document.querySelector('[data-action="install"]');
  if (inPage) inPage.hidden = false;
});

window.addEventListener('appinstalled', () => {
  state.deferredInstall = null;
  installButton.hidden = true;
  showToast(t('installed', 'Event Organizer App was installed.'));
});

async function installApp() {
  if (!state.deferredInstall) {
    showToast(t('install_help', 'Use your browser menu to add this app to the home screen.'), 'warning');
    return;
  }
  state.deferredInstall.prompt();
  await state.deferredInstall.userChoice;
  state.deferredInstall = null;
  installButton.hidden = true;
}

main.addEventListener('click', (event) => {
  const target = event.target instanceof Element ? event.target.closest('[data-route], [data-action]') : null;
  if (!target) return;
  if (target.dataset.route) {
    event.preventDefault();
    go(target.dataset.route);
    return;
  }
  const action = target.dataset.action;
  if (action === 'refresh-route') void routeApp(true);
  if (action === 'install') void installApp();
  if (action === 'attendance') void saveAttendance(target, Number(target.dataset.registrationId || 0), target.dataset.status || 'registered');
  if (action === 'attendance-selected') {
    const card = target.closest('.bg-roster-card');
    const status = card?.querySelector('[data-role="attendance-status"]')?.value || 'registered';
    void saveAttendance(target, Number(target.dataset.registrationId || 0), status);
  }
  if (action === 'privacy') void startPrivacy(Number(target.dataset.registrationId || 0), target);
  if (action === 'another-walkin') {
    state.walkInResult = null;
    void renderWalkIn(state.eventId);
  }
  if (action === 'print-receipt') window.print();
  if (action === 'clear-receipt') {
    state.receipt = null;
    target.closest('.bg-receipt')?.remove();
  }
  if (action === 'return-organizer') {
    document.body.classList.remove('bg-attendee-mode');
    state.roster = [];
    go(`roster/${Number(target.dataset.eventId || 0)}`, true);
  }
});

main.addEventListener('submit', (event) => {
  event.preventDefault();
  const form = event.target;
  if (!(form instanceof HTMLFormElement)) return;
  const button = form.querySelector('button[type="submit"]');
  if (!(button instanceof HTMLButtonElement)) return;
  if (!form.reportValidity()) return;
  if (form.id === 'bg-attendee-lookup-form') void submitAttendeeLookup(form, button);
  if (form.id === 'bg-walkin-form') void submitWalkIn(form, button);
  if (form.id === 'bg-tender-form') void submitTender(form, button);
  if (form.id === 'bg-closeout-form') void submitCloseout(form, button);
  if (form.id === 'bg-attendee-form') void submitAttendee(form, button);
});

main.addEventListener('input', (event) => {
  if (event.target?.id === 'bg-roster-search' || event.target?.id === 'bg-roster-filter') filterRoster();
  if (event.target?.closest?.('#bg-closeout-form')) updateVariance();
});

main.addEventListener('change', (event) => {
  const target = event.target;
  if (target instanceof HTMLInputElement && target.name === 'method') toggleCheckReference();
  if (target instanceof HTMLInputElement && target.dataset.demographic !== undefined) updateDemographicChoices(target);
  if (target instanceof HTMLInputElement && target.dataset.heard !== undefined) updateHeardChoices(target);
});

nav.addEventListener('click', (event) => {
  const target = event.target instanceof Element ? event.target.closest('[data-route]') : null;
  if (target?.dataset.route) go(target.dataset.route);
});

refreshButton.addEventListener('click', () => void routeApp(true));
installButton.addEventListener('click', () => void installApp());
window.addEventListener('hashchange', () => void routeApp());
window.addEventListener('online', updateOnlineState);
window.addEventListener('offline', updateOnlineState);

updateOnlineState();
void registerServiceWorker();
void routeApp();
