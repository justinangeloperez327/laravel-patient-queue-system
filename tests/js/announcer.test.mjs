import assert from 'node:assert/strict';
import { describe, it } from 'node:test';
import { Announcer } from '../../resources/js/display/announcer.js';

class FakeUtterance {
    constructor(text) {
        this.text = text;
        this.lang = '';
        this.onend = null;
        this.onerror = null;
    }
}

function fakeSynth() {
    return {
        spoken: [],
        current: null,
        cancelled: 0,
        speak(utterance) {
            if (this.current !== null) {
                throw new Error('Overlapping speech');
            }
            this.current = utterance;
            this.spoken.push(utterance.text);
        },
        finish() {
            const utterance = this.current;
            this.current = null;
            utterance.onend?.();
        },
        cancel() {
            this.cancelled++;
            this.current = null;
        },
    };
}

function fakeTimers() {
    const timers = new Map();
    let next = 1;

    return {
        setTimer: (fn) => { const id = next++; timers.set(id, fn); return id; },
        clearTimer: (id) => timers.delete(id),
        fire: () => { const [id, fn] = [...timers.entries()][0]; timers.delete(id); fn(); },
        count: () => timers.size,
    };
}

describe('Announcer', () => {
    it('stays silent until sound is enabled by a user gesture', () => {
        const synth = fakeSynth();
        const announcer = new Announcer(synth, FakeUtterance, fakeTimers());

        assert.equal(announcer.announce(1, 'Ticket C, 0, 0, 1.'), false);
        assert.deepEqual(synth.spoken, []);

        assert.equal(announcer.enable(), true);
        assert.equal(announcer.announce(2, 'Ticket C, 0, 0, 2.'), true);
        assert.deepEqual(synth.spoken, ['Ticket C, 0, 0, 2.']);
    });

    it('cannot be enabled without speech support', () => {
        assert.equal(new Announcer(undefined, undefined).enable(), false);
    });

    it('speaks announcements one after another, never overlapping', () => {
        const synth = fakeSynth();
        const announcer = new Announcer(synth, FakeUtterance, { lang: 'fil-PH', ...fakeTimers() });
        announcer.enable();

        announcer.announce(1, 'first');
        announcer.announce(2, 'second');
        announcer.announce(3, 'third');

        assert.deepEqual(synth.spoken, ['first']);
        assert.equal(synth.current.lang, 'fil-PH');
        synth.finish();
        assert.deepEqual(synth.spoken, ['first', 'second']);
        synth.finish();
        synth.finish();
        assert.deepEqual(synth.spoken, ['first', 'second', 'third']);
    });

    it('never repeats the same event', () => {
        const synth = fakeSynth();
        const announcer = new Announcer(synth, FakeUtterance, fakeTimers());
        announcer.enable();

        announcer.announce(7, 'call');
        assert.equal(announcer.announce(7, 'call'), false); // queued/speaking
        synth.finish();
        assert.equal(announcer.announce(7, 'call'), false); // already spoken
        assert.deepEqual(synth.spoken, ['call']);
    });

    it('moves on when an utterance errors', () => {
        const synth = fakeSynth();
        const announcer = new Announcer(synth, FakeUtterance, fakeTimers());
        announcer.enable();
        announcer.announce(1, 'a');
        announcer.announce(2, 'b');

        const current = synth.current;
        synth.current = null;
        current.onerror();

        assert.deepEqual(synth.spoken, ['a', 'b']);
    });

    it('recovers when an engine never reports the end of speech', () => {
        const synth = fakeSynth();
        const timers = fakeTimers();
        const announcer = new Announcer(synth, FakeUtterance, { timeoutMs: 1000, ...timers });
        announcer.enable();
        announcer.announce(1, 'stuck');
        announcer.announce(2, 'next');

        timers.fire();

        assert.equal(synth.cancelled, 1);
        assert.deepEqual(synth.spoken, ['stuck', 'next']);
        synth.finish();
        assert.equal(timers.count(), 0);
    });
});

describe('Announcer resilience', () => {
    it('keeps going when the speech engine throws', () => {
        const spoken = [];
        let calls = 0;
        const synth = {
            speak(utterance) {
                calls++;
                if (calls === 1) {
                    throw new TypeError('parameter 1 is not of type SpeechSynthesisUtterance');
                }
                spoken.push(utterance.text);
            },
            cancel() {},
        };
        const timers = fakeTimers();
        const announcer = new Announcer(synth, FakeUtterance, timers);
        announcer.enable();

        assert.doesNotThrow(() => announcer.announce(1, 'fails'));
        assert.doesNotThrow(() => announcer.announce(2, 'works'));
        assert.deepEqual(spoken, ['works']);
        assert.equal(timers.count(), 1); // only the live utterance's watchdog
    });

    it('keeps going when an utterance cannot be constructed', () => {
        const synth = fakeSynth();
        let first = true;
        function FlakyUtterance(text) {
            if (first) {
                first = false;
                throw new Error('unsupported');
            }
            this.text = text;
        }
        const announcer = new Announcer(synth, FlakyUtterance, fakeTimers());
        announcer.enable();

        announcer.announce(1, 'a');
        announcer.announce(2, 'b');

        assert.deepEqual(synth.spoken, ['b']);
    });
});
