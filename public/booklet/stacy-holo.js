/*
 * Stacy as a hologram: a slowly turning sphere of points of light, with two
 * thin rings orbiting her like the edges of a book's pages.
 *
 *   <stacy-holo></stacy-holo>
 *
 * It reads Stacy's state from the nearest [data-state] or [data-voice] above it
 * (idle · listening · thinking · speaking) and how loud things are from
 * StacyHolo.level (0–1), which the voice script keeps up to date:
 *
 *   idle       breathes, turning slowly
 *   listening  ripples with your voice, warm coral
 *   thinking   swirls, the rings spin up, lilac
 *   speaking   a wave runs round her middle in time with her voice, sky blue
 *
 * When she hears her name (StacyHolo.event = { kind: 'wake' }), she blooms: the
 * sphere swells and a ring of light ripples out from her.
 *
 * With hands-free on ([data-awake] above it), she's waiting for "Stacy…": a
 * brighter band sweeps slowly over her, scanning, and a small green light
 * orbits her like a moon, with a fading trail.
 *
 * Pauses when off screen or the tab is hidden; draws a still frame when the
 * person prefers reduced motion.
 */
(() => {
    if (customElements.get('stacy-holo')) return;

    const TAU = Math.PI * 2;
    const calm = () => window.matchMedia('(prefers-reduced-motion: reduce)').matches;

    // Colours per state: [r, g, b]. Light over dark surfaces (it glows); deeper
    // over paper, where light points would vanish.
    const TINTS = {
        idle: [246, 226, 190],      // warm paper light
        listening: [255, 150, 120], // coral
        thinking: [200, 172, 255],  // lilac
        speaking: [150, 200, 255],  // sky
    };
    const TINTS_ON_PAPER = {
        idle: [176, 120, 40],       // amber ink
        listening: [214, 88, 56],   // deep coral
        thinking: [120, 84, 210],   // violet
        speaking: [52, 116, 214],   // blue
    };

    // How light the surface behind a point on screen is (0 dark … 1 light),
    // ignoring the element itself.
    function surfaceBehind(el) {
        const box = el.getBoundingClientRect();
        const x = box.left + box.width / 2;
        const y = box.top + box.height / 2;
        if (x < 0 || y < 0 || x > innerWidth || y > innerHeight) return null;
        for (let node of document.elementsFromPoint(x, y)) {
            if (el.contains(node) || node.contains(el) && node.closest('.orb, .stacy-badge')) continue;
            for (; node && node !== document.documentElement; node = node.parentElement) {
                const bg = getComputedStyle(node).backgroundColor;
                const m = bg.match(/rgba?\(([^)]+)\)/);
                if (!m) continue;
                const [r, g, b, a = 1] = m[1].split(',').map(Number);
                if (a < 0.5) continue;
                return (0.2126 * r + 0.7152 * g + 0.0722 * b) / 255;
            }
        }
        const body = getComputedStyle(document.body).backgroundColor.match(/\d+/g) || [0, 0, 0];
        return (0.2126 * body[0] + 0.7152 * body[1] + 0.0722 * body[2]) / 255;
    }

    const mix = (a, b, t) => a + (b - a) * t;

    // Shared, so every Stacy on the page moves with the same voice.
    const Holo = (window.StacyHolo = window.StacyHolo || { level: 0, pulse: 0 });

    class StacyHoloElement extends HTMLElement {
        connectedCallback() {
            if (this._canvas) return;
            this._canvas = document.createElement('canvas');
            this._canvas.setAttribute('aria-hidden', 'true');
            this.appendChild(this._canvas);
            this._ctx = this._canvas.getContext('2d');

            this._t = Math.random() * 100;
            this._spin = 0;
            this._tint = [...TINTS.idle];
            this._paper = 0; // 0 over dark … 1 over paper (eased)
            this._onPaper = false;
            this._look();
            this._looker = setInterval(() => this._look(), 700);
            this._energy = 0; // eased level
            this._mood = { listening: 0, thinking: 0, speaking: 0 };
            this._awake = 0; // eased 0…1
            this._last = performance.now();
            this._visible = true;

            this._resize = () => this._size();
            this._resizer = new ResizeObserver(this._resize);
            this._resizer.observe(this);

            this._seen = new IntersectionObserver(([e]) => { this._visible = e.isIntersecting; this._kick(); });
            this._seen.observe(this);

            this._onVis = () => this._kick();
            document.addEventListener('visibilitychange', this._onVis);

            this._size();
            this._kick();
        }

        disconnectedCallback() {
            cancelAnimationFrame(this._raf);
            this._raf = null;
            clearTimeout(this._calmTimer);
            clearInterval(this._looker);
            this._resizer?.disconnect();
            this._seen?.disconnect();
            document.removeEventListener('visibilitychange', this._onVis);
            this._canvas?.remove();
            this._canvas = null;
        }

        _size() {
            const box = this.getBoundingClientRect();
            const size = Math.max(16, Math.min(box.width, box.height));
            const dpr = Math.min(2, window.devicePixelRatio || 1);
            this._px = size;
            this._canvas.width = Math.round(size * dpr);
            this._canvas.height = Math.round(size * dpr);
            this._canvas.style.width = size + 'px';
            this._canvas.style.height = size + 'px';
            this._ctx.setTransform(dpr, 0, 0, dpr, 0, 0);

            // Points: fewer for the little orb, more for the big one.
            const count = size < 90 ? 160 : size < 160 ? 300 : 430;
            if (this._points?.length !== count) this._points = this._sphere(count);
            this._rings = [this._ring(size < 90 ? 34 : 70), this._ring(size < 90 ? 26 : 54)];
            this._draw(0);
        }

        // Evenly spread points over a sphere (a Fibonacci lattice), each with its own twinkle.
        _sphere(n) {
            const pts = [];
            const golden = Math.PI * (3 - Math.sqrt(5));
            for (let i = 0; i < n; i++) {
                const y = 1 - (i / (n - 1)) * 2;
                const r = Math.sqrt(1 - y * y);
                const lon = golden * i;
                pts.push({ lat: Math.asin(y), lon, seed: Math.random() * TAU, size: 0.7 + Math.random() * 0.6 });
            }
            return pts;
        }

        _ring(n) {
            return Array.from({ length: n }, (_, i) => ({ a: (i / n) * TAU, seed: Math.random() * TAU }));
        }

        /** Check what she's floating over, so she stays visible on dark and on paper. */
        _look() {
            if (document.hidden || !this.isConnected) return;
            const forced = this.dataset.surface;
            if (forced) { this._onPaper = forced === 'paper'; return; }
            const light = surfaceBehind(this);
            if (light !== null) this._onPaper = light > 0.55;
        }

        _isAwake() {
            return !!this.closest('[data-awake]');
        }

        _state() {
            const host = this.closest('[data-state], [data-voice]');
            const s = host?.dataset.state || host?.dataset.voice || 'idle';
            return TINTS[s] ? s : 'idle';
        }

        _kick() {
            if (this._raf || !this._canvas) return;
            if (document.hidden || !this._visible) return;
            if (calm()) { this._draw(0); this._calmTimer = setTimeout(() => { this._calmTimer = null; this._kick(); }, 600); return; }
            this._last = performance.now();
            const loop = (now) => {
                this._raf = null;
                if (!this._canvas || document.hidden || !this._visible) return;
                const dt = Math.min(0.05, (now - this._last) / 1000);
                this._last = now;
                this._draw(dt);
                this._raf = requestAnimationFrame(loop);
            };
            this._raf = requestAnimationFrame(loop);
        }

        _draw(dt) {
            const ctx = this._ctx;
            const S = this._px;
            if (!ctx || !S) return;

            const state = this._state();
            const ease = 1 - Math.pow(0.02, dt || 1);

            // Ease the mood and colour towards the state, so changes melt rather than snap.
            for (const k of Object.keys(this._mood)) this._mood[k] = mix(this._mood[k], state === k ? 1 : 0, ease * 1.4);
            this._paper = mix(this._paper, this._onPaper ? 1 : 0, dt ? ease * 1.5 : 1);
            const P = this._paper;
            const target = TINTS[state].map((v, i) => mix(v, TINTS_ON_PAPER[state][i], P));
            this._tint = this._tint.map((c, i) => mix(c, target[i], dt ? ease : 1));

            // How loud: the voice script's level, plus a little life of its own while speaking.
            Holo.pulse *= Math.pow(0.04, dt || 0);
            const raw = Math.min(1, (Holo.level || 0) + Holo.pulse);
            this._energy = mix(this._energy, raw, ease * 2.2);

            const { listening, thinking, speaking } = this._mood;
            // Awake (hands-free) shows only while she's idle and waiting.
            this._awake = mix(this._awake, this._isAwake() && state === 'idle' ? 1 : 0, dt ? ease : 1);
            const W = this._awake;
            // Her name was just heard: a bloom that fades over about a second.
            const ev = Holo.event;
            const age = ev && ev.kind === 'wake' ? (performance.now() - ev.at) / 1000 : 9;
            const bloom = age < 1.4 ? Math.exp(-age * 3.2) * Math.min(1, age * 18) : 0;
            const e = this._energy;
            this._t += dt;
            const t = this._t;
            this._spin += dt * (0.32 + thinking * 1.9 + listening * 0.25 + speaking * 0.3);

            const cx = S / 2;
            const cy = S / 2;
            const R = S * 0.29 * (1 + Math.sin(t * 1.3) * 0.018 * (1 - thinking));
            const tiltX = 0.38 + Math.sin(t * 0.21) * 0.08;
            const [cr, cg, cb] = this._tint.map(Math.round);

            ctx.clearRect(0, 0, S, S);

            // A soft glow around her (fainter over paper, where it would muddy).
            const glow = ctx.createRadialGradient(cx, cy, R * 0.1, cx, cy, R * 1.55);
            glow.addColorStop(0, `rgba(${cr},${cg},${cb},${(0.16 + e * 0.18) * (1 - P * 0.6)})`);
            glow.addColorStop(1, `rgba(${cr},${cg},${cb},0)`);
            ctx.fillStyle = glow;
            ctx.fillRect(0, 0, S, S);

            // Over dark, light adds up like a projection; over paper it's drawn like ink.
            ctx.globalCompositeOperation = P < 0.5 ? 'lighter' : 'source-over';
            const boost = 1 + P * 0.25;

            const cosX = Math.cos(tiltX);
            const sinX = Math.sin(tiltX);
            const dot = S < 90 ? 0.95 : S < 160 ? 1.15 : 1.25;

            // The sphere.
            for (const p of this._points) {
                // Thinking: the sphere twists, bands sliding past each other.
                const lon = p.lon + this._spin + thinking * Math.sin(p.lat * 3 + t * 1.6) * 0.9;
                const lat = p.lat;

                let r = 1;
                // Listening: ripples run from the poles, as loud as your voice.
                r += listening * (0.04 + e * 0.22) * Math.sin(lat * 7 - t * 9 + p.seed * 0.3);
                // Speaking: a wave around her middle, like a mouth, in time with her voice.
                const band = Math.exp(-((lat / 0.42) ** 2));
                r += speaking * band * (0.05 + e * 0.38) * (0.55 + 0.45 * Math.sin(p.lon * 5 - t * 11));
                // Always: a gentle shimmer.
                r += Math.sin(t * 2 + p.seed) * 0.012;
                // Bloom: she swells for a moment, the outer points a little more.
                r += bloom * (0.16 + 0.1 * Math.sin(p.seed * 3));

                const x0 = Math.cos(lat) * Math.cos(lon) * r;
                const y0 = Math.sin(lat) * r;
                const z0 = Math.cos(lat) * Math.sin(lon) * r;
                const y = y0 * cosX - z0 * sinX;
                const z = y0 * sinX + z0 * cosX;

                const depth = (z + 1.4) / 2.8; // 0 far … 1 near
                const persp = 1 / (1.9 - z * 0.45);
                const sx = cx + x0 * R * persp * 1.9;
                const sy = cy + y * R * persp * 1.9;

                const twinkle = 0.65 + 0.35 * Math.sin(t * 3 + p.seed * 5);
                // Hands-free: a band of light sweeps slowly up and down, scanning for her name.
                const scanLat = Math.sin(t * 0.85) * 1.25;
                const scan = W * Math.exp(-(((lat - scanLat) / 0.16) ** 2));
                const a = Math.min(1, ((0.12 + depth * 0.78) * twinkle * (0.85 + e * 0.3) + scan * 0.55 + bloom * 0.5) * boost);
                const size = dot * p.size * (0.55 + depth * 0.9) * (1 + speaking * band * e * 0.6 + scan * 0.5);

                ctx.fillStyle = `rgba(${cr},${cg},${cb},${a.toFixed(3)})`;
                ctx.beginPath();
                ctx.arc(sx, sy, size, 0, TAU);
                ctx.fill();
            }

            // Two thin rings, like the edges of pages, tilted and turning against each other.
            this._rings.forEach((ring, k) => {
                const radius = (1.2 + k * 0.12) * (1 + speaking * e * 0.08) * (1 + listening * e * 0.05);
                const incline = (k ? -0.9 : 1.15) + Math.sin(t * 0.3 + k) * 0.1;
                const turn = this._spin * (k ? -1.6 : 1.1) * (1 + thinking * 1.5);
                const ci = Math.cos(incline), si = Math.sin(incline);
                for (const q of ring) {
                    const ang = q.a + turn;
                    const x0 = Math.cos(ang) * radius;
                    const z0 = Math.sin(ang) * radius;
                    const y1 = z0 * si;
                    const z1 = z0 * ci;
                    const y = y1 * cosX - z1 * sinX;
                    const z = y1 * sinX + z1 * cosX;
                    const depth = (z / radius + 1) / 2;
                    const persp = 1 / (1.9 - z * 0.3);
                    const sx = cx + x0 * R * persp * 1.9;
                    const sy = cy + y * R * persp * 1.9;
                    const a = (0.18 + depth * 0.6) * (0.7 + 0.3 * Math.sin(t * 4 + q.seed * 3)) * (0.75 + thinking * 0.4);
                    ctx.fillStyle = `rgba(${cr},${cg},${cb},${a.toFixed(3)})`;
                    ctx.beginPath();
                    ctx.arc(sx, sy, dot * (0.5 + depth * 0.55), 0, TAU);
                    ctx.fill();
                }
            });

            // Bloom: a ring of light ripples out from her and fades.
            if (age < 1) {
                const spread = R * 1.9 * (0.62 + age * 0.75);
                const fade = (1 - age) ** 1.6;
                const n = S < 90 ? 36 : 72;
                for (let k = 0; k < n; k++) {
                    const ang = (k / n) * TAU + age * 0.6;
                    const wob = 1 + 0.04 * Math.sin(k * 3 + t * 6);
                    const sx = cx + Math.cos(ang) * spread * wob;
                    const sy = cy + Math.sin(ang) * spread * wob * 0.92;
                    ctx.fillStyle = `rgba(${cr},${cg},${cb},${(0.85 * fade).toFixed(3)})`;
                    ctx.beginPath();
                    ctx.arc(sx, sy, dot * (1.2 - age * 0.6), 0, TAU);
                    ctx.fill();
                }
            }

            // Hands-free: a little green moon with a fading trail.
            if (W > 0.02) {
                const [gr, gg, gb] = P < 0.5 ? [120, 236, 168] : [30, 150, 92];
                const orbit = 1.42;
                const incline = 0.5;
                const ci = Math.cos(incline), si = Math.sin(incline);
                for (let k = 14; k >= 0; k--) {
                    const ang = t * 1.35 - k * 0.075;
                    const x0 = Math.cos(ang) * orbit;
                    const z0 = Math.sin(ang) * orbit;
                    const y1 = z0 * si;
                    const z1 = z0 * ci;
                    const y = y1 * cosX - z1 * sinX;
                    const z = y1 * sinX + z1 * cosX;
                    const depth = (z / orbit + 1) / 2;
                    const persp = 1 / (1.9 - z * 0.3);
                    const sx = cx + x0 * R * persp * 1.9;
                    const sy = cy + y * R * persp * 1.9;
                    const fade = 1 - k / 15;
                    const head = k === 0;
                    const a = W * fade * (head ? 1 : 0.45) * (0.45 + depth * 0.55) * (head ? 0.85 + 0.15 * Math.sin(t * 5) : 1);
                    ctx.fillStyle = `rgba(${gr},${gg},${gb},${a.toFixed(3)})`;
                    ctx.beginPath();
                    ctx.arc(sx, sy, dot * (head ? 2.1 : 1.1 * fade + 0.3) * (0.7 + depth * 0.5), 0, TAU);
                    ctx.fill();
                }
            }

            ctx.globalCompositeOperation = 'source-over';
        }
    }

    customElements.define('stacy-holo', StacyHoloElement);
})();
