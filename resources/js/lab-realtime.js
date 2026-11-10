// /lab/realtime: subscribe to the lab channels, draw a timeline of what arrives,
// post the experiment buttons with fetch() + X-Socket-ID. Texts come from the
// #lab-realtime-i18n JSON (lang/{uk,en}/lab.php => realtime.js).

const root = document.getElementById('lab-realtime');
const t = JSON.parse(document.getElementById('lab-realtime-i18n').textContent);
const echo = window.Echo;

const format = (text, params = {}) =>
    Object.entries(params).reduce((s, [k, v]) => s.replaceAll(`:${k}`, v), text);

const timeline = root.querySelector('[data-timeline]');
const clock = (ms) => new Date(ms).toLocaleTimeString(document.documentElement.lang, {hour12: false}) + '.' + String(ms % 1000).padStart(3, '0');

// Full class names: Tailwind only generates classes it finds literally in the sources.
const tones = {
    stone: 'border-stone-400', sky: 'border-sky-400', violet: 'border-violet-400', emerald: 'border-emerald-400',
    amber: 'border-amber-400', rose: 'border-rose-400', red: 'border-red-500',
};

/**
 * One timeline row. `sentAt` (ms, server clock) => latency to this browser.
 */
function add({channel, event, text, origin = null, pid = null, sentAt = null, tone = 'stone'}) {
    root.querySelector('[data-timeline-empty]')?.remove();
    const now = Date.now();
    const li = document.createElement('li');
    li.className = `rounded border-l-4 ${tones[tone]} bg-white px-2 py-1`;

    const head = document.createElement('div');
    head.className = 'flex flex-wrap gap-x-2 text-stone-500';
    const part = (tag, text) => Object.assign(document.createElement(tag), {textContent: text});
    head.append(part('span', clock(now)), part('code', `${channel} · ${event}`));
    if (origin) head.append(part('span', `${origin}#${pid}`));
    if (sentAt) head.append(part('span', format(t.latency, {ms: Math.max(0, now - sentAt)})));

    const body = document.createElement('div');
    body.textContent = text; // textContent: payloads never become HTML

    li.append(head, body);
    timeline.prepend(li);
}

root.querySelector('[data-timeline-clear]').addEventListener('click', () => timeline.replaceChildren());

// --- Connection state -----------------------------------------------------------
const stateEl = root.querySelector('[data-connection-state]');
const socketEl = root.querySelector('[data-socket-id]');
if (echo) {
    const connection = echo.connector.pusher.connection;
    const show = (state) => {
        stateEl.textContent = t.states[state] ?? state;
        socketEl.textContent = echo.socketId() ?? '—';
    };
    show(connection.state);
    connection.bind('state_change', ({current}) => show(current));
}

// --- Experiments 1–3: public channel lab.timeline --------------------------------
echo?.channel('lab.timeline')
    // LabTrace: steps of the listener experiment.
    .listen('.lab.trace', (e) => add({
        channel: 'lab.timeline', event: 'lab.trace', origin: e.origin, pid: e.pid, sentAt: e.sentAt,
        text: `${e.variant}: ${t.steps[e.step] ?? e.step}`,
        tone: e.origin === 'worker' ? 'violet' : 'sky',
    }))
    // LabBroadcast / LabBroadcastNow: createdAt (request) vs sentAt (where it was sent).
    .listen('.lab.broadcast', (e) => add({
        channel: 'lab.timeline', event: 'lab.broadcast', origin: e.origin, pid: e.pid, sentAt: e.sentAt,
        text: format(t.broadcast, {variant: e.variant, ms: e.sentAt - e.createdAt}),
        tone: 'emerald',
    }));

// --- Experiment 4: public channel warehouse --------------------------------------
echo?.channel('warehouse').listen('.stock.changed', (e) => add({
    channel: 'warehouse', event: 'stock.changed',
    text: format(t.stock, {book: e.book_id, label: e.label, available: e.available}),
    tone: 'amber',
}));

// --- Experiment 5: own private channel --------------------------------------------
const userId = root.dataset.userId;
if (echo && userId) {
    echo.private(`App.Models.User.${userId}`).notification((n) => add({
        channel: `private-App.Models.User.${userId}`, event: n.type, sentAt: n.sentAt ?? null,
        text: n.kind === 'lab' ? format(t.notification, {user: n.user_id}) : JSON.stringify(n),
        tone: 'rose',
    }));
}

// --- Experiment 6: someone else's private channel --------------------------------
root.querySelector('[data-subscribe-form]').addEventListener('submit', (event) => {
    event.preventDefault();
    if (!echo) return add({channel: '—', event: 'echo', text: t.states.no_echo, tone: 'red'});
    const name = `App.Models.User.${new FormData(event.target).get('user_id')}`;
    if (name === `App.Models.User.${userId}`) return add({channel: name, event: 'subscribe', text: t.subscribe_own, tone: 'amber'});

    echo.leave(name); // a second try re-asks /broadcasting/auth
    echo.private(name)
        .subscribed(() => add({channel: `private-${name}`, event: 'subscribed', text: format(t.subscribed, {channel: name}), tone: 'emerald'}))
        .error((error) => add({
            channel: `private-${name}`, event: 'subscription_error',
            text: format(t.denied, {channel: name, status: error?.status ?? '?'}),
            tone: 'red',
        }));
});

// --- Buttons: fetch() instead of a full page reload, so the timeline survives -----
root.querySelectorAll('[data-lab-form]').forEach((form) => form.addEventListener('submit', async (event) => {
    event.preventDefault();
    const headers = {Accept: 'application/json'};
    // Echo would add it to axios requests; with fetch() we send it ourselves.
    // The server reads it in ->toOthers() and skips this connection.
    if (echo?.socketId()) headers['X-Socket-ID'] = echo.socketId();

    const response = await fetch(form.action, {method: 'POST', body: new FormData(form), headers});
    const data = await response.json().catch(() => ({message: response.statusText}));
    add({channel: 'HTTP', event: `${response.status}`, text: data.message ?? '', tone: response.ok ? 'stone' : 'red'});
}));
