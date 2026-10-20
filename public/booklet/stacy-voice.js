/*
 * Stacy's voice: talk to her, and hear her answer.
 *
 * Listening uses the browser's own speech recognition (Chrome, Edge, Safari).
 * Where that's missing, a short voice note is recorded and sent to the server,
 * which transcribes it with OpenAI or Groq. Replies are read aloud with the
 * browser's speech synthesis.
 *
 * Hands-free mode keeps a quiet ear open for the wake word "Stacy", then
 * takes the rest of the sentence (or the next one) as the question.
 *
 * Use:  <div x-data="stacyVoice(@js($this->voice))"> … </div>
 * inside a Livewire component that uses App\Livewire\Concerns\TalksToStacy.
 */
(() => {
    const Recognition = window.SpeechRecognition || window.webkitSpeechRecognition;
    const WAKE = /\b(?:hey\s+|ok(?:ay)?\s+)?sta(?:c|ss)(?:y|ey|ie|i)\b[\s,.!?]*/i;
    const YES = /^(yes|yeah|yep|sure|ok(ay)?|do it|go ahead|please do|confirm(ed)?|absolutely|correct)\b/i;
    const NO = /^(no|nope|nah|not now|cancel|don'?t|stop|never ?mind|leave it)\b/i;
    const HUSH = /^(stop|quiet|shush|be quiet|that'?s enough|stop talking)\W*$/i;

    const secure = () => window.isSecureContext;

    // The hologram (stacy-holo.js) moves with this: 0 quiet … 1 loud.
    const holo = () => (window.StacyHolo = window.StacyHolo || { level: 0, pulse: 0 });
    const setLevel = (v) => { holo().level = Math.max(0, Math.min(1, v)); };

    function sentences(text) {
        // Long utterances get cut off in some browsers, so speak a sentence at a time.
        return (text.match(/[^.!?…]+[.!?…]*\s*/g) || [text])
            .map((s) => s.trim())
            .filter(Boolean)
            .reduce((chunks, s) => {
                const last = chunks[chunks.length - 1];
                if (last && (last + ' ' + s).length < 180) chunks[chunks.length - 1] = last + ' ' + s;
                else chunks.push(s);
                return chunks;
            }, []);
    }

    function stacyVoice(config = {}) {
        return {
            // idle · listening · thinking · speaking
            state: 'idle',
            heard: '',
            level: 0,
            note: '',
            speakReplies: config.speak !== false,
            handsFree: false,
            supported: !!Recognition || (!!window.MediaRecorder && !!config.canRecord),
            canSpeak: 'speechSynthesis' in window,
            _speakToken: 0,
            _player: null,

            _rec: null,
            _wakeRec: null,
            _recorder: null,
            _stream: null,
            _audio: null,
            _raf: null,
            _followUp: false,
            _awaitingCommand: false,
            _stopTimer: null,
            _starting: false,
            _watchdog: null,

            init() {
                this.handsFree = !!config.wake && this._remembered('stacy.wake', true);
                if (this.handsFree) this.$nextTick(() => this.startWake());

                this._onSaid = (e) => this.said(e.detail || {});
                window.addEventListener('stacy-said', this._onSaid);

                this._onKey = (e) => {
                    // Alt+S: talk to Stacy from anywhere in the book.
                    if (e.altKey && !e.metaKey && !e.ctrlKey && e.code === 'KeyS') {
                        e.preventDefault();
                        this.toggle();
                    }
                    if (e.key === 'Escape' && this.state !== 'idle') this.stopAll();
                };
                window.addEventListener('keydown', this._onKey);

                if (this.canSpeak) speechSynthesis.getVoices();

                // Left the tab for hours, or the computer slept: say welcome back.
                if (config.greets) this._watchAway();
            },

            _watchAway() {
                const GAP = 4 * 60 * 60 * 1000;
                let hiddenAt = document.hidden ? Date.now() : null;
                let tick = Date.now();
                const back = (ms) => { if (ms >= GAP) this.$wire.welcomeBack(Math.round(ms / 60000)); };

                this._onVisible = () => {
                    if (document.hidden) { hiddenAt = Date.now(); return; }
                    if (hiddenAt) back(Date.now() - hiddenAt);
                    hiddenAt = null;
                    tick = Date.now();
                };
                document.addEventListener('visibilitychange', this._onVisible);

                // A sleeping computer stops the clock ticking: a long gap between ticks.
                this._awayTimer = setInterval(() => {
                    const now = Date.now();
                    if (!document.hidden) back(now - tick);
                    tick = now;
                }, 60 * 1000);
            },

            destroy() {
                if (this._onVisible) document.removeEventListener('visibilitychange', this._onVisible);
                clearInterval(this._awayTimer);
                (this._watchers || []).forEach((w) => w.disconnect());
                window.removeEventListener('stacy-said', this._onSaid);
                window.removeEventListener('keydown', this._onKey);
                if (this._leaving) {
                    this.stopListening();
                    this.pauseWake();
                    this._unmeter();
                } else {
                    this.stopAll(true);
                }
            },

            get label() {
                return {
                    idle: this.handsFree ? 'Say “Stacy…”' : 'Talk to Stacy',
                    listening: 'Listening…',
                    thinking: 'Thinking…',
                    speaking: 'Speaking… (tap to stop)',
                }[this.state];
            },

            /** A typed question: show it at once while Stacy thinks. */
            typed(text) {
                text = String(text || '').trim();
                if (!text || text.length > 4000) return;
                this.hush();
                this._spoken = false;
                this.heard = text;
            },

            /** Keep a conversation scrolled to the newest line as it grows. */
            stickToBottom(el) {
                const go = () => { el.scrollTop = el.scrollHeight; };
                this.$nextTick(go);
                const watcher = new MutationObserver(() => requestAnimationFrame(go));
                watcher.observe(el, { childList: true, subtree: true, characterData: true });
                this._watchers = (this._watchers || []).concat(watcher);
            },

            // The "/" menu ----------------------------------------------------

            slashAt: 0,

            /** Shortcuts matching what's typed after "/" (only while the command word is being typed). */
            slashItems() {
                const draft = String(this.$wire?.draft ?? '');
                const m = draft.match(/^\/([a-z]*)$/i);
                if (!m) return [];
                const word = m[1].toLowerCase();
                return (config.commands || []).filter((c) => c.name.startsWith(word)).slice(0, 8);
            },

            slashOpen() { return this.slashItems().length > 0; },

            slashKey(e) {
                const items = this.slashItems();
                if (!items.length) { this.slashAt = 0; return; }
                if (e.key === 'ArrowDown') { e.preventDefault(); this.slashAt = (this.slashAt + 1) % items.length; }
                else if (e.key === 'ArrowUp') { e.preventDefault(); this.slashAt = (this.slashAt - 1 + items.length) % items.length; }
                else if (e.key === 'Tab' || e.key === 'Enter') { e.preventDefault(); e.stopPropagation(); this.pickSlash(items[Math.min(this.slashAt, items.length - 1)]); }
                else if (e.key === 'Escape') { e.preventDefault(); e.stopPropagation(); this.$wire.draft = ''; }
            },

            /** Choose a shortcut: ones that need words wait for them; the rest go straight away. */
            pickSlash(cmd) {
                this.slashAt = 0;
                const text = '/' + cmd.name;
                if (cmd.args) {
                    this.$wire.draft = text + ' ';
                    this.$nextTick(() => {
                        const input = this.$root.querySelector('input[wire\\:model="draft"]');
                        input?.focus();
                        input?.setSelectionRange(input.value.length, input.value.length);
                    });
                    return;
                }
                this.$wire.draft = '';
                this.typed(text);
                this.$wire.ask(text);
            },

            // Talking ----------------------------------------------------------

            toggle() {
                if (this._starting) return;
                if (this.state === 'speaking') return this.hush();
                if (this.state === 'listening') {
                    // Nothing actually listening (it failed quietly)? Just reset.
                    if (!this._rec && !this._recorder) { this.state = 'idle'; return; }
                    return this.stopListening();
                }
                if (this.state === 'idle') return this.listen();
            },

            async listen() {
                if (this._starting || this._rec || this._recorder) return;
                this.note = '';

                if (!secure()) {
                    this.note = 'The microphone only works over https (or on localhost).';
                    return;
                }
                if (!Recognition && !(window.MediaRecorder && config.canRecord)) {
                    this.note = 'This browser can’t listen yet. Try Chrome, Edge or Safari, or switch on a service that can transcribe (OpenRouter, OpenAI or Groq) in Stacy’s brain.';
                    return;
                }

                this._starting = true;
                this.state = 'listening'; // show it straight away; the mic follows a moment later
                this.hush();
                // The "Stacy…" ear has to let go of the microphone before another can start,
                // or the browser refuses the new one.
                await this.pauseWake();

                try {
                    if (Recognition && !this._preferRecording) this._listenWithBrowser();
                    else await this._record();
                } finally {
                    this._starting = false;
                }
            },

            stopListening() {
                if (this._rec) { try { this._rec.stop(); } catch (e) { this._rec = null; } }
                if (this._recorder && this._recorder.state === 'recording') this._recorder.stop();
                if (!this._rec && !this._recorder && this.state === 'listening') this.state = 'idle';
            },

            _listenWithBrowser() {
                const rec = new Recognition();
                rec.lang = config.lang || document.documentElement.lang || 'en-US';
                rec.interimResults = true;
                rec.continuous = false;
                rec.maxAlternatives = 1;

                let finalText = '';
                let fellBack = false;
                this.heard = '';
                this._rec = rec;

                // The rings follow the voice from the recogniser's own events: opening a
                // second microphone for them made Safari and Android stop listening.
                const glow = (v) => { this.$root?.style.setProperty('--level', v); setLevel(v); };
                rec.onstart = () => { this.state = 'listening'; glow(0.15); if (!this._followUp) this._sound('listen'); };
                rec.onsoundstart = () => glow(0.45);
                rec.onspeechstart = () => glow(0.85);
                rec.onspeechend = () => glow(0.2);
                rec.onresult = (e) => {
                    let interim = '';
                    for (let i = e.resultIndex; i < e.results.length; i++) {
                        const t = e.results[i][0].transcript;
                        if (e.results[i].isFinal) finalText += t;
                        else interim += t;
                    }
                    this.heard = (finalText + ' ' + interim).trim();
                };
                rec.onerror = (e) => {
                    const canRecord = config.canRecord && window.MediaRecorder;
                    if (e.error === 'not-allowed') {
                        this.note = 'Microphone access is blocked. Allow it in the address bar, then try again.';
                    } else if (e.error === 'audio-capture') {
                        this.note = 'No microphone found. Check one is plugged in and not used by another app.';
                    } else if (e.error === 'no-speech') {
                        this.note = 'I didn’t hear anything. Tap the mic and try again.';
                        this._sound('miss');
                    } else if ((e.error === 'network' || e.error === 'service-not-allowed' || e.error === 'language-not-supported') && canRecord) {
                        // The browser's speech service isn't available here: record a voice note instead,
                        // and keep doing so for the rest of this visit.
                        fellBack = true;
                        this._preferRecording = true;
                    } else if (e.error === 'network' || e.error === 'service-not-allowed') {
                        this.note = 'This browser’s speech service isn’t reachable. Turn on a service that can transcribe (OpenRouter, OpenAI or Groq) in Stacy’s brain, or type instead.';
                    }
                };
                rec.onend = () => {
                    if (this._rec === rec) this._rec = null;
                    glow(0);
                    if (fellBack) { this._record(); return; } // carries on as a voice note
                    const text = finalText.trim() || this.heard.trim();
                    if (this.state === 'listening') this.state = 'idle';
                    if (text) this.heardSomething(text);
                    else this.resumeWake();
                };

                try {
                    rec.start();
                } catch (e) {
                    // Still busy from a moment ago: try once more shortly.
                    this._rec = null;
                    setTimeout(() => {
                        try { this._rec = rec; rec.start(); } catch (err) { this._rec = null; this.state = 'idle'; this.note = 'The microphone is busy. Try again in a moment.'; }
                    }, 350);
                }
            },

            async _record() {
                let stream;
                try {
                    stream = await navigator.mediaDevices.getUserMedia({ audio: true });
                } catch (e) {
                    this.state = 'idle';
                    this.note = e && e.name === 'NotFoundError'
                        ? 'No microphone found. Check one is plugged in.'
                        : 'Microphone access is blocked. Allow it in the address bar, then try again.';
                    this.resumeWake();
                    return;
                }

                const type = ['audio/webm;codecs=opus', 'audio/webm', 'audio/mp4', 'audio/ogg']
                    .find((t) => MediaRecorder.isTypeSupported && MediaRecorder.isTypeSupported(t)) || '';
                const recorder = new MediaRecorder(stream, type ? { mimeType: type } : undefined);
                const chunks = [];
                this._recorder = recorder;
                this._stream = stream;
                this.heard = '';
                this.state = 'listening';
                this._sound('listen');
                this._meter(stream, true);

                recorder.ondataavailable = (e) => e.data.size && chunks.push(e.data);
                recorder.onstop = () => {
                    clearTimeout(this._stopTimer);
                    this._unmeter();
                    stream.getTracks().forEach((t) => t.stop());
                    this._recorder = null;
                    this._stream = null;

                    const blob = new Blob(chunks, { type: recorder.mimeType || 'audio/webm' });
                    if (blob.size < 1200) { this.state = 'idle'; this.note = 'That was too short to hear. Tap, speak, then tap again.'; this.resumeWake(); return; }

                    const ext = (blob.type.split('/')[1] || 'webm').split(';')[0].replace('mp4', 'm4a');
                    const file = new File([blob], 'voice.' + ext, { type: blob.type });

                    this.state = 'thinking';
                    this._spoken = true;
                    this.heard = 'Listening back…';
                    this._watch();
                    this.$wire.upload('voiceNote', file,
                        () => this.$wire.hearVoiceNote(),
                        () => { this._unwatch(); this.state = 'idle'; this.heard = ''; this.note = 'The recording didn’t upload. Try again?'; });
                };

                recorder.start();
                // Stop by itself after a long pause-free minute.
                this._stopTimer = setTimeout(() => recorder.state === 'recording' && recorder.stop(), 60000);
            },

            // What was heard ---------------------------------------------------

            heardSomething(text) {
                text = text.trim();
                if (text) this._sound('heard');
                const pending = this._followUp;
                this._followUp = false;

                if (HUSH.test(text)) { this.hush(); this.resumeWake(); return; }

                if (pending && YES.test(text)) return this._send(() => this.$wire.answerLatest(true), text);
                if (pending && NO.test(text)) return this._send(() => this.$wire.answerLatest(false), text);

                this._send(() => this.$wire.ask(text, 'voice'), text);
            },

            _send(call, text) {
                this.heard = text;
                this.state = 'thinking';
                this._spoken = true;
                this._watch();
                Promise.resolve(call()).catch(() => { this._unwatch(); this.state = 'idle'; this.heard = ''; this.note = 'Stacy couldn’t answer just now. Try again?'; });
            },

            /** Never stay "Thinking…" for ever: if no answer comes, free the mic again. */
            _watch() {
                clearTimeout(this._watchdog);
                this._watchdog = setTimeout(() => {
                    if (this.state !== 'thinking') return;
                    this.state = 'idle';
                    this.heard = '';
                    this.note = 'No answer came back. Check the connection and try again.';
                    this.resumeWake();
                }, 160000);
            },

            _unwatch() { clearTimeout(this._watchdog); this._watchdog = null; },

            /** Stacy answered (Livewire event "stacy-said"). */
            said({ id, text, pending, go }) {
                if (id && this.$wire && id !== this.$wire.$id) return;
                this._unwatch();

                // "Take me to Pages": go there. Speech carries on across the page change.
                if (go) {
                    if (this._spoken && this.speakReplies && text) {
                        this.speak(text);
                    }
                    this._leaving = true; // let the words finish on the next page
                    this._spoken = false;
                    this.heard = '';
                    this.state = 'idle';
                    setTimeout(() => {
                        // "Take me to search" opens the search window rather than a page.
                        if (new URL(go, location.href).hash === '#search') {
                            this._leaving = false;
                            window.dispatchEvent(new CustomEvent('open-search'));
                            return;
                        }
                        if (window.Livewire?.navigate) window.Livewire.navigate(go);
                        else window.location.assign(go);
                    }, 650);
                    return;
                }

                const askedAloud = this._spoken;
                this._spoken = false;
                this.state = 'idle';
                this.heard = '';

                // Typed questions get written answers; spoken ones get spoken answers.
                if (!text || !askedAloud || !this.speakReplies || (!this.canSpeak && config.engine !== 'natural')) {
                    this.resumeWake();
                    return;
                }

                this.speak(text, () => {
                    // She asked "shall I?" out loud: listen once for yes or no.
                    if (pending && askedAloud) {
                        this._followUp = true;
                        this.listen();
                    } else {
                        this.resumeWake();
                    }
                });
            },

            // Speaking ---------------------------------------------------------

            voice() {
                const voices = speechSynthesis.getVoices();
                const lang = (config.lang || 'en').toLowerCase();
                return voices.find((v) => v.voiceURI === config.voice || v.name === config.voice)
                    || voices.find((v) => v.lang.toLowerCase() === lang && /natural|neural|google|samantha|aria|jenny|sonia/i.test(v.name))
                    || voices.find((v) => v.lang.toLowerCase().startsWith(lang.slice(0, 2)))
                    || null;
            },

            speak(text, done = () => {}) {
                if (config.engine === 'natural' && config.endpoint) return this._speakNatural(text, done);
                return this._speakBrowser(text, done);
            },

            /** Her natural voice: the words come back as audio from the server. */
            _speakNatural(text, done = () => {}) {
                this.hush();
                const token = ++this._speakToken;
                this.pauseWake();
                this.state = 'thinking';

                const csrf = document.querySelector('meta[name="csrf-token"]')?.content || '';
                fetch(config.endpoint, {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: { 'Content-Type': 'application/json', Accept: 'audio/mpeg', 'X-CSRF-TOKEN': csrf, 'X-Requested-With': 'XMLHttpRequest' },
                    body: JSON.stringify({ text }),
                })
                    .then((res) => (res.ok ? res.blob() : Promise.reject(new Error('voice ' + res.status))))
                    .then((blob) => {
                        if (token !== this._speakToken) return;
                        const url = URL.createObjectURL(blob);
                        const player = new Audio(url);
                        player.playbackRate = Math.min(1.6, Math.max(0.75, Number(config.rate) || 1));
                        this._player = player;
                        const finish = () => {
                            this._unfollow();
                            URL.revokeObjectURL(url);
                            if (this._player === player) this._player = null;
                            if (token === this._speakToken) { this.state = 'idle'; done(); }
                        };
                        player.onended = finish;
                        player.onerror = () => { finish(); };
                        player.onplaying = () => { if (token === this._speakToken) { this.state = 'speaking'; this._follow(player); } };
                        return player.play();
                    })
                    .catch(() => {
                        // No natural voice right now (offline, no credit, autoplay blocked): use the browser's.
                        if (token === this._speakToken) this._speakBrowser(text, done);
                    });
            },

            _speakBrowser(text, done = () => {}) {
                if (!this.canSpeak) { this.state = 'idle'; return done(); }

                speechSynthesis.cancel();
                const parts = sentences(text);
                const voice = this.voice();
                this.state = 'speaking';
                this.pauseWake();

                this._follow(null);
                let i = 0;
                const next = () => {
                    if (this.state !== 'speaking') { this._unfollow(); return; }
                    if (i >= parts.length) { this._unfollow(); this.state = 'idle'; return done(); }

                    const u = new SpeechSynthesisUtterance(parts[i++]);
                    if (voice) { u.voice = voice; u.lang = voice.lang; } else if (config.lang) { u.lang = config.lang; }
                    u.rate = Math.min(2, Math.max(0.6, Number(config.rate) || 1));
                    u.pitch = 1.02;
                    u.onend = next;
                    u.onboundary = () => { holo().pulse = 0.55 + Math.random() * 0.35; };
                    u.onerror = () => { this._unfollow(); this.state = 'idle'; done(); };
                    speechSynthesis.speak(u);
                };
                next();
            },

            hush() {
                this._unfollow();
                this._speakToken = (this._speakToken || 0) + 1;
                if (this._player) { this._player.pause(); this._player = null; }
                if (this.canSpeak) speechSynthesis.cancel();
                if (this.state === 'speaking') this.state = 'idle';
            },

            /**
             * Let the hologram move with her voice: the real loudness of her natural
             * voice, or (for the browser's voice, which can't be measured) a murmur.
             */
            _follow(player) {
                this._unfollow();
                let analyser = null;
                if (player) {
                    try {
                        const Ctx = window.AudioContext || window.webkitAudioContext;
                        this._voiceCtx = this._voiceCtx || new Ctx();
                        // Only route her voice through the meter when audio is allowed to run;
                        // a suspended one would make her silent. (The murmur stands in instead.)
                        if (this._voiceCtx.state !== 'running') { this._voiceCtx.resume().catch(() => {}); throw new Error('not yet'); }
                        const source = this._voiceCtx.createMediaElementSource(player);
                        analyser = this._voiceCtx.createAnalyser();
                        analyser.fftSize = 256;
                        source.connect(analyser);
                        analyser.connect(this._voiceCtx.destination);
                    } catch (e) { analyser = null; }
                }
                const data = analyser ? new Uint8Array(analyser.frequencyBinCount) : null;
                let phase = 0;
                const tick = () => {
                    if (analyser) {
                        analyser.getByteFrequencyData(data);
                        // Speech lives in the lower bins; weight them.
                        let sum = 0;
                        for (let k = 2; k < 48; k++) sum += data[k];
                        setLevel(sum / 46 / 120);
                    } else {
                        phase += 0.23;
                        setLevel(0.28 + 0.22 * Math.abs(Math.sin(phase)) * (0.6 + Math.random() * 0.4));
                    }
                    this._voiceRaf = requestAnimationFrame(tick);
                };
                tick();
            },

            _unfollow() {
                cancelAnimationFrame(this._voiceRaf);
                this._voiceRaf = null;
                setLevel(0);
            },

            toggleSpeak() {
                this.speakReplies = !this.speakReplies;
                if (!this.speakReplies) this.hush();
            },

            stopAll(quiet = false) {
                this._unwatch();
                this.hush();
                this.stopListening();
                this.pauseWake();
                this._unmeter();
                if (!quiet) this.state = 'idle';
            },

            // Hands-free ("Stacy, …") -------------------------------------------

            toggleHandsFree() {
                this.handsFree = !this.handsFree;
                this._remember('stacy.wake', this.handsFree);
                this.handsFree ? this.startWake() : this.pauseWake();
            },

            startWake() {
                if (!this.handsFree || !Recognition || !secure() || this._wakeRec || (this.state !== 'idle' && !this._awaitingCommand)) return;

                const rec = new Recognition();
                rec.lang = config.lang || 'en-US';
                rec.continuous = true;
                rec.interimResults = false;
                this._wakeRec = rec;

                rec.onresult = (e) => {
                    const text = e.results[e.results.length - 1][0].transcript.trim();

                    if (this._awaitingCommand) {
                        this._awaitingCommand = false;
                        clearTimeout(this._awaitTimer);
                        this.pauseWake();
                        return this.heardSomething(text);
                    }

                    const match = text.match(WAKE);
                    if (!match) return;

                    const command = text.slice(match.index + match[0].length).trim();
                    this._sound('wake');
                    this._flash('wake');
                    if (this.$root?.classList.contains('orb') && this.$wire) this.$wire.open = true; // show the little card

                    if (command.length > 2) {
                        this.pauseWake();
                        this.heardSomething(command);
                    } else {
                        // Just "Stacy": the next thing said is the question. Look like it.
                        this._awaitingCommand = true;
                        this.heard = 'Yes?';
                        this.state = 'listening';
                        clearTimeout(this._awaitTimer);
                        this._awaitTimer = setTimeout(() => {
                            if (!this._awaitingCommand) return;
                            this._awaitingCommand = false;
                            this.heard = '';
                            if (this.state === 'listening') this.state = 'idle';
                            this._sound('miss');
                        }, 8000);
                    }
                };
                rec.onerror = (e) => {
                    if (e.error === 'not-allowed' || e.error === 'service-not-allowed') {
                        this.handsFree = false;
                        this.note = 'Hands-free needs microphone access.';
                    }
                };
                rec.onend = () => {
                    // Browsers stop listening after a while; keep the ear open.
                    const wasMine = this._wakeRec === rec;
                    this._wakeRec = null;
                    if (wasMine && this._awaitingCommand) {
                        // It closed while waiting for the question: keep waiting on a fresh ear.
                        setTimeout(() => this.startWake(), 200);
                        return;
                    }
                    if (wasMine && this.handsFree && this.state === 'idle') setTimeout(() => this.startWake(), 400);
                };

                try { rec.start(); } catch (e) { this._wakeRec = null; }
            },

            /** Close the "Stacy…" ear. Resolves once the microphone is free again. */
            pauseWake() {
                const rec = this._wakeRec;
                this._wakeRec = null;
                this._awaitingCommand = false;
                if (!rec) return Promise.resolve();
                return new Promise((resolve) => {
                    const done = () => { clearTimeout(timer); resolve(); };
                    const timer = setTimeout(done, 400);
                    rec.addEventListener('end', () => setTimeout(done, 60), { once: true });
                    try { rec.abort(); } catch (e) { done(); }
                });
            },

            resumeWake() {
                if (this.handsFree) setTimeout(() => this.startWake(), 300);
            },

            // The little moving rings ------------------------------------------

            async _meter(stream = null, owned = false) {
                if (window.matchMedia('(prefers-reduced-motion: reduce)').matches) return;
                try {
                    stream = stream || await navigator.mediaDevices.getUserMedia({ audio: true });
                } catch (e) { return; }
                if (this.state !== 'listening') { if (!owned) stream.getTracks().forEach((t) => t.stop()); return; }

                const Ctx = window.AudioContext || window.webkitAudioContext;
                const ctx = new Ctx();
                const analyser = ctx.createAnalyser();
                analyser.fftSize = 256;
                ctx.createMediaStreamSource(stream).connect(analyser);
                const data = new Uint8Array(analyser.frequencyBinCount);
                this._audio = { ctx, stream, owned };

                const tick = () => {
                    analyser.getByteFrequencyData(data);
                    const avg = data.reduce((a, b) => a + b, 0) / data.length;
                    this.level = Math.min(1, avg / 70);
                    this.$root.style.setProperty('--level', this.level.toFixed(2));
                    setLevel(this.level);
                    this._raf = requestAnimationFrame(tick);
                };
                tick();
            },

            _unmeter() {
                cancelAnimationFrame(this._raf);
                this.level = 0;
                setLevel(0);
                if (this.$root) this.$root.style.setProperty('--level', 0);
                if (this._audio) {
                    if (!this._audio.owned) this._audio.stream.getTracks().forEach((t) => t.stop());
                    this._audio.ctx.close().catch(() => {});
                    this._audio = null;
                }
            },

            /**
             * Stacy's sounds, made on the spot (no audio files):
             *   wake   her name was heard: a page flutter, then a warm rising chime
             *   listen the mic opened (tap or Alt+S): a single soft note
             *   heard  got your question: two quick notes, down and settled
             *   miss   nothing heard / gave up waiting: one low, soft note
             */
            _sound(kind) {
                try {
                    const Ctx = window.AudioContext || window.webkitAudioContext;
                    const ctx = (this._sfx = this._sfx && this._sfx.state !== 'closed' ? this._sfx : new Ctx());
                    if (ctx.state === 'suspended') ctx.resume().catch(() => {});
                    const now = ctx.currentTime + 0.01;
                    const out = ctx.createGain();
                    out.gain.value = 0.9;
                    out.connect(ctx.destination);

                    // A soft bell: a triangle with a quiet octave shimmer, quick attack, long fade.
                    const bell = (freq, at, len = 0.7, vol = 0.07) => {
                        [[1, 'triangle', vol], [2, 'sine', vol * 0.28], [3.01, 'sine', vol * 0.08]].forEach(([mult, type, v]) => {
                            const o = ctx.createOscillator();
                            const g = ctx.createGain();
                            o.type = type;
                            o.frequency.setValueAtTime(freq * mult, now + at);
                            g.gain.setValueAtTime(0.0001, now + at);
                            g.gain.exponentialRampToValueAtTime(v, now + at + 0.012);
                            g.gain.exponentialRampToValueAtTime(0.0001, now + at + len);
                            o.connect(g).connect(out);
                            o.start(now + at);
                            o.stop(now + at + len + 0.05);
                        });
                    };

                    // A page lifting: a breath of filtered noise that sweeps up.
                    const flutter = (at, len = 0.22, vol = 0.05) => {
                        const n = Math.floor(ctx.sampleRate * len);
                        const buf = ctx.createBuffer(1, n, ctx.sampleRate);
                        const d = buf.getChannelData(0);
                        for (let k = 0; k < n; k++) d[k] = (Math.random() * 2 - 1) * (1 - k / n);
                        const src = ctx.createBufferSource();
                        src.buffer = buf;
                        const bp = ctx.createBiquadFilter();
                        bp.type = 'bandpass';
                        bp.Q.value = 1.4;
                        bp.frequency.setValueAtTime(700, now + at);
                        bp.frequency.exponentialRampToValueAtTime(4200, now + at + len);
                        const g = ctx.createGain();
                        g.gain.setValueAtTime(0.0001, now + at);
                        g.gain.exponentialRampToValueAtTime(vol, now + at + 0.04);
                        g.gain.exponentialRampToValueAtTime(0.0001, now + at + len);
                        src.connect(bp).connect(g).connect(out);
                        src.start(now + at);
                    };

                    // A tiny paper tick: a few milliseconds of bright noise (typing).
                    const tick = (vol = 0.09) => {
                        const n = Math.floor(ctx.sampleRate * 0.012);
                        if (!this._tickBuf || this._tickBuf.length !== n) {
                            this._tickBuf = ctx.createBuffer(1, n, ctx.sampleRate);
                            const d = this._tickBuf.getChannelData(0);
                            for (let k = 0; k < n; k++) d[k] = (Math.random() * 2 - 1) * Math.pow(1 - k / n, 3);
                        }
                        const src = ctx.createBufferSource();
                        src.buffer = this._tickBuf;
                        src.playbackRate.value = 0.85 + Math.random() * 0.4;
                        const bp = ctx.createBiquadFilter();
                        bp.type = 'bandpass';
                        bp.Q.value = 2.2;
                        bp.frequency.value = 2600 + Math.random() * 1800;
                        const g = ctx.createGain();
                        g.gain.value = vol;
                        src.connect(bp).connect(g).connect(out);
                        src.start(now);
                    };

                    // A breath that falls away (a page settling back down).
                    const fall = (at, len = 0.32, vol = 0.12) => {
                        const n = Math.floor(ctx.sampleRate * len);
                        const buf = ctx.createBuffer(1, n, ctx.sampleRate);
                        const d = buf.getChannelData(0);
                        for (let k = 0; k < n; k++) d[k] = (Math.random() * 2 - 1) * Math.sin(Math.PI * k / n);
                        const src = ctx.createBufferSource();
                        src.buffer = buf;
                        const bp = ctx.createBiquadFilter();
                        bp.type = 'bandpass';
                        bp.Q.value = 1.1;
                        bp.frequency.setValueAtTime(2600, now + at);
                        bp.frequency.exponentialRampToValueAtTime(500, now + at + len);
                        const g = ctx.createGain();
                        g.gain.value = vol;
                        src.connect(bp).connect(g).connect(out);
                        src.start(now + at);
                    };

                    if (kind === 'greet') {
                        // Hello: a page lifts, then a warm rising chord (C, E, G, B, then a high E shimmer).
                        flutter(0, 0.3, 0.04);
                        bell(523.25, 0.06, 1.2, 0.05);   // C5
                        bell(659.25, 0.14, 1.2, 0.05);   // E5
                        bell(783.99, 0.22, 1.2, 0.055);  // G5
                        bell(987.77, 0.30, 1.5, 0.06);   // B5
                        bell(1318.51, 0.46, 1.8, 0.03);  // E6, a soft shimmer on top
                    } else if (kind === 'tick') {
                        tick();
                    } else if (kind === 'settle') {
                        bell(783.99, 0, 0.6, 0.035);     // G5
                        bell(1046.5, 0.09, 0.9, 0.035);  // C6
                    } else if (kind === 'tuck') {
                        fall(0);
                    } else if (kind === 'wake') {
                        flutter(0);
                        bell(659.25, 0.10);           // E5
                        bell(830.61, 0.17);           // G#5
                        bell(987.77, 0.24, 1.0, 0.08); // B5, held a little longer
                    } else if (kind === 'listen') {
                        bell(880, 0, 0.45, 0.05);       // A5
                    } else if (kind === 'heard') {
                        bell(987.77, 0, 0.35, 0.05);    // B5
                        bell(739.99, 0.08, 0.55, 0.05); // F#5
                    } else if (kind === 'miss') {
                        bell(392, 0, 0.5, 0.045);       // G4, soft and low
                    }
                } catch (e) { /* no sound available */ }
            },

            /** Tell the hologram something happened ("wake" makes her bloom). */
            _flash(kind) {
                const h = (window.StacyHolo = window.StacyHolo || { level: 0, pulse: 0 });
                h.event = { kind, at: performance.now() };
            },

            _remember(key, value) { try { sessionStorage.setItem(key, value ? '1' : '0'); } catch (e) {} },
            _remembered(key, fallback) {
                try { const v = sessionStorage.getItem(key); return v === null ? fallback : v === '1'; } catch (e) { return fallback; }
            },
        };
    }

    /** Voice list for the Account page's "Stacy’s voice" picker. */
    function stacyVoicePicker(current = '') {
        return {
            voices: [],
            current,
            loading: false,
            error: '',
            playing: null,
            init() {
                if (!('speechSynthesis' in window)) return;
                const load = () => {
                    this.voices = speechSynthesis.getVoices()
                        .map((v) => ({ id: v.voiceURI, name: v.name, lang: v.lang }))
                        .sort((a, b) => a.lang.localeCompare(b.lang) || a.name.localeCompare(b.name));
                };
                load();
                speechSynthesis.addEventListener('voiceschanged', load);
            },
            /** Hear the natural voice, with the voice and style being chosen. */
            testNatural(endpoint, voice, style, rate) {
                this.playing && this.playing.pause();
                this.loading = true;
                const csrf = document.querySelector('meta[name="csrf-token"]')?.content || '';
                fetch(endpoint, {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: { 'Content-Type': 'application/json', Accept: 'audio/mpeg', 'X-CSRF-TOKEN': csrf, 'X-Requested-With': 'XMLHttpRequest' },
                    body: JSON.stringify({ text: 'Hi, I’m Stacy. This is how I’ll sound when I answer you. Ask me anything about the book.', voice, style }),
                })
                    .then(async (res) => {
                        if (!res.ok) throw new Error((await res.json().catch(() => ({}))).message || 'That voice didn’t come through.');
                        return res.blob();
                    })
                    .then((blob) => {
                        this.error = '';
                        this.playing = new Audio(URL.createObjectURL(blob));
                        this.playing.playbackRate = Number(rate) || 1;
                        return this.playing.play();
                    })
                    .catch((e) => { this.error = e.message; })
                    .finally(() => { this.loading = false; });
            },

            test(voiceId, rate, lang) {
                if (!('speechSynthesis' in window)) return;
                speechSynthesis.cancel();
                const u = new SpeechSynthesisUtterance('Hello, I’m Stacy. This is how I’ll sound when I answer you.');
                const v = speechSynthesis.getVoices().find((x) => x.voiceURI === voiceId);
                if (v) { u.voice = v; u.lang = v.lang; } else if (lang) { u.lang = lang; }
                u.rate = Number(rate) || 1;
                speechSynthesis.speak(u);
            },
        };
    }

    /**
     * Stacy's hello: types itself in a moment after the page arrives, makes the
     * hologram bloom, says it out loud if that's switched on, then tucks itself
     * away after a while (not while you're pointing at it).
     */
    function stacyGreeting(g = {}) {
        return {
            shown: false,
            done: false,
            title: '',
            lines: [],
            _timer: null,
            _holding: false,
            _gone: false,

            init() {
                setTimeout(() => this.start(), 900);
            },

            async start() {
                if (this._gone) return;
                this.shown = true;
                this._flash?.('wake');
                if (this._allowed()) this._chime('greet');
                else this._waitForFirstTouch();

                const calm = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
                if (calm) {
                    this.title = g.title || '';
                    this.lines = [...(g.lines || [])];
                } else {
                    await this._type(g.title || '', (t) => { this.title = t; }, 26);
                    for (const line of g.lines || []) {
                        this.lines.push('');
                        const i = this.lines.length - 1;
                        await this._type(line, (t) => { this.lines[i] = t; }, 11);
                    }
                }
                if (this._gone) return;
                this.done = true;
                this._chime('settle');
                if (this._allowed()) this._say(450);
                this._arm();
            },

            /** Does the browser allow sound yet? (Only once you've clicked or typed on the page.) */
            _allowed() {
                const ua = navigator.userActivation;
                return !ua || ua.hasBeenActive;
            },

            /** A little sound, if sounds are on and allowed. */
            _chime(kind) {
                if (g.sound && !this._gone && this._allowed()) this._sound?.(kind);
            },

            /** Say the greeting out loud: when the book allows it and this person hears
                her answers. The bubble stays up while she's talking. */
            _say(delay = 0) {
                if (this._said || this._gone || !g.aloud || !g.say || !this.speakReplies || !this.speak) return;
                this._said = true;
                setTimeout(() => {
                    if (this._gone) return;
                    this.hold();
                    this._talking = true;
                    this.speak(g.say, () => {
                        this._talking = false;
                        if (!this._gone) this.release();
                    });
                }, delay);
            },

            /** On a freshly loaded page the browser keeps her quiet until you touch it:
                the first click or key (while she's still showing) brings the chime and her voice. */
            _waitForFirstTouch() {
                if (!g.sound && !g.aloud) return;
                const shownAt = Date.now();
                const later = () => {
                    off();
                    if (!this.shown || this._gone || Date.now() - shownAt > 20000) return;
                    this._chime('greet');
                    if (this.done) this._say(700);
                    // (if she's still writing, she speaks when she finishes)
                };
                const off = () => ['pointerdown', 'keydown'].forEach((e) => window.removeEventListener(e, later, true));
                ['pointerdown', 'keydown'].forEach((e) => window.addEventListener(e, later, true));
                this._offHello = off;
            },

            _type(text, set, every) {
                return new Promise((resolve) => {
                    let n = 0;
                    const step = () => {
                        if (this._gone) return resolve();
                        n = Math.min(text.length, n + (text[n] === ' ' ? 2 : 1));
                        set(text.slice(0, n));
                        if (n % 3 === 0 && text[n - 1] !== ' ') this._chime('tick');
                        n < text.length ? setTimeout(step, every) : resolve();
                    };
                    step();
                });
            },

            _arm() {
                clearTimeout(this._timer);
                this._timer = setTimeout(() => { if (!this._holding) this.close(); }, 16000);
            },
            hold() { this._holding = true; clearTimeout(this._timer); },
            release() { this._holding = false; if (this.done) this._arm(); },

            close(now = false) {
                if (!now && this.shown && this.done) this._chime('tuck');
                if (this._talking) { this._talking = false; this.hush?.(); } // closing her stops her mid-sentence
                this._offHello?.();
                this._gone = true;
                clearTimeout(this._timer);
                this.shown = false;
                setTimeout(() => this.$wire.dismissGreeting(), now ? 0 : 320);
            },

            destroy() { this._gone = true; this._offHello?.(); clearTimeout(this._timer); },
        };
    }

    // Registered when Alpine starts, or straight away if it already has
    // (this file can arrive after the page, e.g. opening the book right after signing in).
    const register = () => {
        window.Alpine.data('stacyGreeting', stacyGreeting);
        window.Alpine.data('stacyVoice', stacyVoice);
        window.Alpine.data('stacyVoicePicker', stacyVoicePicker);
    };
    if (window.Alpine) register();
    document.addEventListener('alpine:init', register);
})();
