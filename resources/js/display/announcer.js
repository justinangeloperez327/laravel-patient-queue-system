/**
 * Sequential speech announcements. Browsers only allow speech after a user
 * gesture, so nothing is spoken until enable() runs from a click. Each
 * announcement finishes (or times out) before the next one starts, and the
 * same queue event is never spoken twice.
 */
export class Announcer {
    /**
     * @param {{speak(u: object): void, cancel(): void}} synth  window.speechSynthesis
     * @param {new (text: string) => object} Utterance        window.SpeechSynthesisUtterance
     * @param {{lang?: string, timeoutMs?: number, setTimer?: Function, clearTimer?: Function}} options
     */
    constructor(synth, Utterance, options = {}) {
        this.synth = synth;
        this.Utterance = Utterance;
        this.lang = options.lang ?? 'en-US';
        this.timeoutMs = options.timeoutMs ?? 15000;
        this.setTimer = options.setTimer ?? ((fn, ms) => setTimeout(fn, ms));
        this.clearTimer = options.clearTimer ?? ((id) => clearTimeout(id));
        this.enabled = false;
        this.speaking = false;
        this.queue = [];
        this.spoken = new Set();
        this.timer = null;
    }

    /** Call from a user gesture (e.g. the "Enable sound" button). */
    enable() {
        if (!this.synth || !this.Utterance) {
            return false;
        }

        this.enabled = true;
        this.pump();

        return true;
    }

    /**
     * @returns {boolean} whether the announcement was queued
     */
    announce(id, text) {
        if (!this.enabled || this.spoken.has(id) || this.queue.some((item) => item.id === id)) {
            return false;
        }

        this.queue.push({ id, text });
        this.pump();

        return true;
    }

    pump() {
        if (!this.enabled || this.speaking || this.queue.length === 0) {
            return;
        }

        const { id, text } = this.queue.shift();
        this.speaking = true;
        this.spoken.add(id);

        let utterance;

        try {
            utterance = new this.Utterance(text);
            utterance.lang = this.lang;
        } catch {
            this.speaking = false;
            this.pump();

            return;
        }

        const done = () => {
            if (this.timer !== null) {
                this.clearTimer(this.timer);
                this.timer = null;
            }

            if (!this.speaking) {
                return;
            }

            this.speaking = false;
            this.pump();
        };

        utterance.onend = done;
        utterance.onerror = done;
        // Some engines never fire onend; never let one stuck utterance block the queue.
        this.timer = this.setTimer(() => {
            this.synth.cancel();
            done();
        }, this.timeoutMs);

        try {
            this.synth.speak(utterance);
        } catch {
            // A failing speech engine must never stop the queue (or the display).
            done();
        }
    }
}
