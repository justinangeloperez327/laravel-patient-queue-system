/**
 * Display state: the authoritative snapshot plus broadcast updates applied
 * on top of it. Every update carries the id of the queue event it came from,
 * so updates already reflected in the snapshot, or delivered twice, are
 * ignored.
 */

const REMOVING = new Set(['skipped', 'completed', 'transferred', 'cancelled']);
const ANNOUNCING = new Set(['called', 'recalled']);
const MAX_SEEN = 1000;
const MAX_RECENT = 8;

export class DisplayState {
    constructor() {
        this.lastEventId = 0;
        this.seen = new Set();
        /** @type {Map<string, {ticket: string, room: string|null, status: string}>} */
        this.nowServing = new Map();
        this.recent = [];
        this.ready = false;
        this.pending = [];
    }

    /**
     * Replace everything with a fresh snapshot, then apply any updates that
     * arrived while it was loading and are newer than it.
     *
     * @returns {Array<object>} the buffered updates that should still be announced
     */
    applySnapshot(snapshot) {
        this.lastEventId = Number(snapshot.last_event_id) || 0;
        this.seen = new Set();
        this.nowServing = new Map(
            (snapshot.now_serving ?? []).map((item) => [item.ticket, { ticket: item.ticket, room: item.room, status: item.status }]),
        );
        this.recent = uniqueByTicket(snapshot.recent_calls ?? []).slice(0, MAX_RECENT);
        this.ready = true;

        const buffered = this.pending;
        this.pending = [];

        return buffered.filter((update) => this.applyUpdate(update).announce);
    }

    /** Hold updates while a (re)connection snapshot is loading. */
    beginResync() {
        this.ready = false;
    }

    /**
     * @returns {{fresh: boolean, announce: boolean}}
     */
    applyUpdate(update) {
        if (!this.ready) {
            this.pending.push(update);

            return { fresh: false, announce: false };
        }

        const id = Number(update.id);

        if (!Number.isFinite(id) || id <= this.lastEventId || this.seen.has(id)) {
            return { fresh: false, announce: false };
        }

        this.remember(id);

        if (ANNOUNCING.has(update.type)) {
            this.nowServing.set(update.ticket, { ticket: update.ticket, room: update.room, status: 'called' });
            // One entry per ticket: a recall moves the ticket to the front instead of duplicating it.
            this.recent = [update, ...this.recent.filter((call) => call.ticket !== update.ticket)].slice(0, MAX_RECENT);

            return { fresh: true, announce: true };
        }

        if (update.type === 'started') {
            this.nowServing.set(update.ticket, { ticket: update.ticket, room: update.room, status: 'serving' });
        } else if (REMOVING.has(update.type)) {
            this.nowServing.delete(update.ticket);
        }

        return { fresh: true, announce: false };
    }

    remember(id) {
        this.seen.add(id);

        if (this.seen.size > MAX_SEEN) {
            this.seen.delete(this.seen.values().next().value);
        }
    }
}

/** Keep the first (most recent) call of each ticket. */
function uniqueByTicket(calls) {
    const seen = new Set();

    return calls.filter((call) => !seen.has(call.ticket) && seen.add(call.ticket));
}

/** "C-001" → "C, 0, 0, 1" so speech engines read characters, not "one". */
export function spellTicket(ticket) {
    return String(ticket).replace(/-/g, '').split('').join(', ');
}

/** Ticket number and room only — never any patient detail. */
export function announcementText(update) {
    const ticket = spellTicket(update.ticket);

    return update.room ? `Ticket ${ticket}, please proceed to ${update.room}.` : `Ticket ${ticket}.`;
}
