import Echo from 'laravel-echo';
import Pusher from 'pusher-js';
import { Announcer } from './display/announcer.js';
import { DisplayState, announcementText } from './display/state.js';

const root = document.getElementById('display');

if (root) {
    const config = {
        channel: root.dataset.channel,
        snapshotUrl: root.dataset.snapshotUrl,
        authUrl: root.dataset.authUrl,
        lang: root.dataset.voiceLang || 'en-US',
        reverb: JSON.parse(root.dataset.reverb || '{}'),
    };
    const csrf = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') ?? '';
    const state = new DisplayState();
    const announcer = new Announcer(window.speechSynthesis, window.SpeechSynthesisUtterance, { lang: config.lang });
    const connection = document.getElementById('connection');
    const soundButton = document.getElementById('enable-sound');

    const render = () => {
        const list = document.getElementById('now-serving');
        list.replaceChildren(...[...state.nowServing.values()].map((item) => {
            const li = document.createElement('li');
            li.className = 'flex flex-col gap-2 rounded-xl border border-zinc-700 bg-zinc-900 p-6' + (item.status === 'called' ? ' ring-2 ring-amber-400' : '');
            const ticket = document.createElement('span');
            ticket.className = 'whitespace-nowrap font-mono text-6xl font-semibold';
            ticket.textContent = item.ticket;
            const room = document.createElement('span');
            room.className = 'text-3xl text-zinc-300';
            room.textContent = item.room ?? '';
            li.append(ticket, room);

            return li;
        }));
        document.getElementById('now-serving-empty').hidden = state.nowServing.size > 0;
        document.getElementById('recent-calls').replaceChildren(...state.recent.map((call) => {
            const li = document.createElement('li');
            li.className = 'whitespace-nowrap rounded-md bg-zinc-900 px-3 py-1';
            li.textContent = `${call.ticket} → ${call.room ?? ''}`;

            return li;
        }));
    };

    const setConnection = (text) => {
        connection.textContent = text;
    };

    const resync = async () => {
        state.beginResync();
        const response = await fetch(config.snapshotUrl, { headers: { Accept: 'application/json' }, credentials: 'same-origin' });

        if (response.status === 403) {
            // The device was revoked or its key rotated: back to pairing.
            window.location.reload();

            return;
        }

        if (!response.ok) {
            throw new Error(`Snapshot failed with ${response.status}`);
        }

        const buffered = state.applySnapshot(await response.json());
        render();
        buffered.forEach(announce);
    };

    soundButton.addEventListener('click', () => {
        if (announcer.enable()) {
            soundButton.hidden = true;
        }
    });

    const tick = () => {
        document.getElementById('clock').textContent = new Date().toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
    };
    tick();
    setInterval(tick, 15000);

    window.Pusher = Pusher;
    const echo = new Echo({
        broadcaster: 'reverb',
        key: config.reverb.key,
        wsHost: config.reverb.host,
        wsPort: config.reverb.port ?? 80,
        wssPort: config.reverb.port ?? 443,
        forceTLS: (config.reverb.scheme ?? 'https') === 'https',
        enabledTransports: ['ws', 'wss'],
        authEndpoint: config.authUrl,
        auth: { headers: { 'X-CSRF-TOKEN': csrf, Accept: 'application/json' } },
    });

    const announce = (update) => {
        try {
            announcer.announce(update.id, announcementText(update));
        } catch {
            // Speech is best effort; the screen is the source of truth.
        }
    };

    echo.private(config.channel).listen('.display.updated', (update) => {
        const result = state.applyUpdate(update);

        // Render first: a speech failure must never hide a call from the screen.
        if (result.fresh) {
            render();
        }

        if (result.announce) {
            announce(update);
        }
    });

    // Every (re)connection reloads the authoritative snapshot; missed broadcasts are recovered here.
    echo.connector.pusher.connection.bind('state_change', ({ current }) => {
        if (current === 'connected') {
            setConnection('Live');
            resync().catch(() => setConnection('Reconnecting…'));
        } else {
            setConnection(current === 'connecting' ? 'Connecting…' : 'Reconnecting…');
        }
    });

    // Safety net: periodic resync also detects revocation while connected.
    setInterval(() => resync().catch(() => setConnection('Reconnecting…')), 60000);
    resync().catch(() => setConnection('Reconnecting…'));
}
