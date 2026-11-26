import assert from 'node:assert/strict';
import { describe, it } from 'node:test';
import { DisplayState, announcementText, spellTicket } from '../../resources/js/display/state.js';

const snapshot = (overrides = {}) => ({
    last_event_id: 10,
    now_serving: [{ ticket: 'C-001', room: 'Room 1', status: 'serving', service_id: 1, called_at: null }],
    recent_calls: [{ id: 9, type: 'called', ticket: 'C-001', room: 'Room 1', service_id: 1, at: '' }],
    ...overrides,
});

const update = (id, type, ticket = 'C-002', room = 'Room 2') => ({ id, type, ticket, room, service_id: 1, at: '' });

describe('DisplayState', () => {
    it('loads the authoritative snapshot', () => {
        const state = new DisplayState();
        state.applySnapshot(snapshot());

        assert.equal(state.lastEventId, 10);
        assert.deepEqual([...state.nowServing.keys()], ['C-001']);
        assert.equal(state.recent.length, 1);
    });

    it('announces a new call once and ignores duplicate deliveries', () => {
        const state = new DisplayState();
        state.applySnapshot(snapshot());

        assert.deepEqual(state.applyUpdate(update(11, 'called')), { fresh: true, announce: true });
        assert.deepEqual(state.applyUpdate(update(11, 'called')), { fresh: false, announce: false });
        assert.equal(state.nowServing.get('C-002').status, 'called');
        assert.equal(state.recent[0].id, 11);
    });

    it('ignores events already reflected in the snapshot', () => {
        const state = new DisplayState();
        state.applySnapshot(snapshot());

        assert.deepEqual(state.applyUpdate(update(10, 'called')), { fresh: false, announce: false });
        assert.deepEqual(state.applyUpdate(update(3, 'completed', 'C-001')), { fresh: false, announce: false });
        assert.ok(state.nowServing.has('C-001'));
    });

    it('announces recalls (a new event id) but not service changes', () => {
        const state = new DisplayState();
        state.applySnapshot(snapshot());
        state.applyUpdate(update(11, 'called'));

        assert.equal(state.applyUpdate(update(12, 'recalled')).announce, true);
        assert.equal(state.applyUpdate(update(13, 'started')).announce, false);
        assert.equal(state.nowServing.get('C-002').status, 'serving');
    });

    it('removes tickets that leave the counter', () => {
        for (const type of ['skipped', 'completed', 'transferred', 'cancelled']) {
            const state = new DisplayState();
            state.applySnapshot(snapshot());
            state.applyUpdate(update(11, type, 'C-001'));
            assert.equal(state.nowServing.has('C-001'), false, type);
        }
    });

    it('buffers updates during a reconnect and replays only those newer than the snapshot', () => {
        const state = new DisplayState();
        state.applySnapshot(snapshot());
        state.beginResync();

        // Delivered while the reconnect snapshot is loading.
        state.applyUpdate(update(14, 'called', 'C-003', 'Room 3'));
        state.applyUpdate(update(15, 'called', 'C-004', 'Room 4'));
        state.applyUpdate(update(15, 'called', 'C-004', 'Room 4'));

        const toAnnounce = state.applySnapshot(snapshot({
            last_event_id: 14,
            now_serving: [{ ticket: 'C-003', room: 'Room 3', status: 'called' }],
            recent_calls: [update(14, 'called', 'C-003', 'Room 3')],
        }));

        assert.deepEqual(toAnnounce.map((u) => u.id), [15]);
        assert.deepEqual([...state.nowServing.keys()].sort(), ['C-003', 'C-004']);
    });

    it('a reconnect snapshot replaces stale state', () => {
        const state = new DisplayState();
        state.applySnapshot(snapshot());
        state.applyUpdate(update(11, 'called'));
        state.beginResync();
        state.applySnapshot(snapshot({ last_event_id: 20, now_serving: [] }));

        assert.equal(state.nowServing.size, 0);
        assert.deepEqual(state.applyUpdate(update(11, 'called')), { fresh: false, announce: false });
    });

    it('keeps at most eight recent calls', () => {
        const state = new DisplayState();
        state.applySnapshot(snapshot({ recent_calls: [] }));

        for (let id = 11; id <= 25; id++) {
            state.applyUpdate(update(id, 'called', `C-0${id}`));
        }

        assert.equal(state.recent.length, 8);
        assert.equal(state.recent[0].id, 25);
    });
});

describe('announcement text', () => {
    it('spells the ticket and names the room only', () => {
        assert.equal(spellTicket('C-001'), 'C, 0, 0, 1');
        assert.equal(announcementText(update(1, 'called', 'LB-012', 'Laboratory window 1')), 'Ticket L, B, 0, 1, 2, please proceed to Laboratory window 1.');
        assert.equal(announcementText({ ticket: 'T-003', room: null }), 'Ticket T, 0, 0, 3.');
    });
});

describe('recent calls', () => {
    it('lists each ticket once, at its latest call', () => {
        const state = new DisplayState();
        state.applySnapshot(snapshot({
            recent_calls: [update(9, 'recalled', 'C-001'), update(8, 'called', 'C-001'), update(7, 'called', 'C-000')],
        }));

        assert.deepEqual(state.recent.map((c) => c.id), [9, 7]);

        state.applyUpdate(update(11, 'called', 'C-000'));
        assert.deepEqual(state.recent.map((c) => [c.id, c.ticket]), [[11, 'C-000'], [9, 'C-001']]);
    });
});
