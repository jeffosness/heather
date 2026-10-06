/*
 * Presenter shell shared by every presentation type.
 *
 * Owns: the top bar (title, score, skulls, controls), keyboard routing,
 * undo history, persistence (localStorage always; the session record on
 * the server when presenting a scheduled session), the presenter-notes
 * window (BroadcastChannel), timers, and safe text rendering.
 *
 * A type registers a player:
 *   Present.registerType('key', {
 *     initialState(),        // fresh run state
 *     isValidState(state),   // false → start fresh (lesson was edited)
 *     derive(state),         // optional: recompute derived fields after every change
 *     render(stageEl, state, {entering}),
 *     onKey(key, event, state) → true if handled,
 *     notes(state) → HTML for the presenter-notes window,
 *     clock(state) → optional text for the top-bar clock,
 *   })
 *
 * Scores are keyed by team ('class' today) so team mode can slot in later.
 */
(() => {
    const P = window.PRESENT;
    const TEAM = 'class';
    const HISTORY_LIMIT = 200;
    const types = {};
    let player = null;
    let state = null;
    let history = [];
    let lastNodeRendered = null;
    let channel = null;
    const timers = new Map();

    // ------------------------------------------------------------ text

    const esc = (s) => String(s ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));

    const inline = (s) => esc(s)
        .replace(/\*\*(.+?)\*\*/g, '<strong>$1</strong>')
        .replace(/(^|[^*])\*(?!\s)([^*]+?)\*(?!\*)/g, '$1<em>$2</em>');

    /** **bold**, *italic*, "- " bullet lines, blank-line paragraphs. Escapes everything else. */
    function md(text) {
        if (!text) return '';
        return String(text).trim().split(/\n{2,}/).map((block) => {
            const lines = block.split('\n');
            if (lines.every((l) => /^\s*[-•]\s+/.test(l))) {
                return '<ul>' + lines.map((l) => '<li>' + inline(l.replace(/^\s*[-•]\s+/, '')) + '</li>').join('') + '</ul>';
            }
            return '<p>' + lines.map(inline).join('<br>') + '</p>';
        }).join('');
    }

    function el(html) {
        const t = document.createElement('template');
        t.innerHTML = html.trim();
        return t.content.firstElementChild;
    }

    // ------------------------------------------------------------ scoring

    const scoring = (P.lesson.content && P.lesson.content.scoring) || { enabled: false };
    const score = (s) => (s.scores && s.scores[TEAM]) || 0;
    const skulls = (s) => (s.skulls && s.skulls[TEAM]) || 0;

    function addPoints(s, delta, skull) {
        s.scores = s.scores || {};
        s.skulls = s.skulls || {};
        s.scores[TEAM] = (s.scores[TEAM] || 0) + (Number(delta) || 0);
        if (skull) s.skulls[TEAM] = (s.skulls[TEAM] || 0) + 1;
    }

    function floatDelta(delta, skull) {
        if (!scoring.enabled || (!delta && !skull)) return;
        const anchor = document.querySelector('.tb-score');
        if (!anchor) return;
        const text = (delta ? (delta > 0 ? '+' + delta : String(delta)) : '') + (skull ? ' ☠️' : '');
        const f = el(`<div class="float-delta ${delta > 0 ? 'up' : 'down'}">${esc(text)}</div>`);
        anchor.appendChild(f);
        setTimeout(() => f.remove(), 1800);
    }

    // ------------------------------------------------------------ persistence

    function persist() {
        try {
            localStorage.setItem(P.stateKey, JSON.stringify({ state, history }));
        } catch (e) { /* private mode / full — server copy still saves */ }
        if (channel) channel.postMessage({ state });
        if (P.sessionId) {
            const body = new URLSearchParams({ session: P.sessionId, state: JSON.stringify(state), csrf: P.csrf });
            fetch(P.saveUrl, { method: 'POST', body, credentials: 'same-origin' })
                .then((r) => r.json().then((j) => r.ok && j && j.ok === true))
                .then((saved) => document.body.classList.toggle('save-failed', !saved))
                .catch(() => document.body.classList.add('save-failed'));
        }
    }

    function loadLocal() {
        try {
            const raw = JSON.parse(localStorage.getItem(P.stateKey) || 'null');
            return raw && raw.state ? raw : null;
        } catch (e) {
            return null;
        }
    }

    /** Most recent of: this browser's copy, the server's copy. */
    function restore() {
        let local = loadLocal();
        // Session was reset from the admin list after this browser saved — ignore the stale copy.
        if (local && P.resetAt && (local.state.savedAt || 0) < P.resetAt) local = null;
        const server = P.saved;
        let chosen = null;
        if (local && (!server || (local.state.savedAt || 0) >= (server.savedAt || 0))) {
            chosen = local.state;
            history = Array.isArray(local.history) ? local.history : [];
        } else if (server) {
            chosen = server;
            history = [];
        }
        if (!chosen || !player.isValidState(chosen)) {
            history = [];
            return player.initialState();
        }
        return chosen;
    }

    // ------------------------------------------------------------ state changes

    /** Apply a mutation to a copy of state, push undo history, persist, re-render. */
    function commit(mutator) {
        const before = JSON.stringify(state);
        const next = JSON.parse(before);
        const oldScore = score(next), oldSkulls = skulls(next);
        mutator(next);
        if (player.derive) player.derive(next); // e.g. refresh the final result after a +/− adjustment
        next.savedAt = Date.now();
        history.push(before);
        if (history.length > HISTORY_LIMIT) history.shift();
        state = next;
        persist();
        render();
        floatDelta(score(state) - oldScore, skulls(state) > oldSkulls);
    }

    function undo() {
        if (!history.length) return;
        state = JSON.parse(history.pop());
        state.savedAt = Date.now();
        persist();
        render();
    }

    function reset() {
        if (!confirm('Restart this presentation from the beginning? The score resets too.')) return;
        history = [];
        state = player.initialState();
        state.savedAt = Date.now();
        stopTimers();
        persist();
        render();
    }

    function adjust(delta) {
        if (!scoring.enabled) return;
        commit((s) => {
            addPoints(s, delta, false);
            (s.path = s.path || []).push({ node: s.node, label: 'Adjusted by instructor', delta });
        });
    }

    // ------------------------------------------------------------ timers + sound

    let audioCtx = null;
    let muted = false;
    try { muted = localStorage.getItem('present:muted') === '1'; } catch (e) { /* default: sound on */ }

    function audio() {
        if (muted) return null;
        try {
            audioCtx = audioCtx || new (window.AudioContext || window.webkitAudioContext)();
            if (audioCtx.state === 'suspended') audioCtx.resume();
            return audioCtx;
        } catch (e) {
            return null; // no audio — the visual countdown still works
        }
    }

    /** One short clock tick; pitch rises as time runs out (urgency 0 → 1). */
    function tick(urgency) {
        const ctx = audio();
        if (!ctx) return;
        const o = ctx.createOscillator(), g = ctx.createGain(), t = ctx.currentTime;
        o.type = 'square';
        o.frequency.value = 700 + 900 * urgency;
        o.connect(g); g.connect(ctx.destination);
        g.gain.setValueAtTime(0.12, t);
        g.gain.exponentialRampToValueAtTime(0.001, t + 0.04);
        o.start(t); o.stop(t + 0.05);
    }

    /** Game-show buzzer: low, detuned, harsh. */
    function buzzer() {
        const ctx = audio();
        if (!ctx) return;
        const t = ctx.currentTime, g = ctx.createGain(), lp = ctx.createBiquadFilter();
        lp.type = 'lowpass'; lp.frequency.value = 1400;
        g.connect(lp); lp.connect(ctx.destination);
        g.gain.setValueAtTime(0.0001, t);
        g.gain.exponentialRampToValueAtTime(0.35, t + 0.02);
        g.gain.setValueAtTime(0.35, t + 1.1);
        g.gain.exponentialRampToValueAtTime(0.0001, t + 1.3);
        [[110, 'sawtooth'], [116, 'sawtooth'], [55, 'square']].forEach(([f, type]) => {
            const o = ctx.createOscillator();
            o.type = type; o.frequency.value = f;
            o.connect(g); o.start(t); o.stop(t + 1.32);
        });
    }

    function toggleMute() {
        muted = !muted;
        try { localStorage.setItem('present:muted', muted ? '1' : '0'); } catch (e) { /* session only */ }
        const b = document.querySelector('[data-act="mute"]');
        if (b) b.textContent = muted ? '🔇' : '🔊';
    }

    /**
     * Ticks once a second, then accelerates (and rises in pitch) through the
     * final stretch — the last half of the timer, at least 10s — down to a
     * rapid-fire tick before the buzzer.
     */
    function tickDelay(remainingMs, totalMs) {
        const windowMs = Math.min(totalMs, Math.max(10000, totalMs / 2));
        if (remainingMs > windowMs) return { delay: 1000, urgency: 0 };
        const f = remainingMs / windowMs; // 1 → 0 across the window
        return { delay: 110 + 890 * Math.pow(f, 1.5), urgency: 1 - f };
    }

    /**
     * A countdown widget, cached per key so it survives re-renders on the
     * same slide (e.g. revealing answers while the clock runs). Time is kept
     * against the real clock so it never drifts.
     */
    function timer(key, seconds) {
        if (timers.has(key)) return timers.get(key).node;
        const totalMs = seconds * 1000;
        let remainingMs = totalMs, endAt = 0, displayHandle = null, tickHandle = null;
        const node = el(`<div class="timer">
            <div class="timer-digits"></div>
            <div class="timer-btns">
                <button type="button" class="btn-ghost" data-t="toggle">Start</button>
                <button type="button" class="btn-ghost" data-t="reset">Reset</button>
            </div></div>`);
        const digits = node.querySelector('.timer-digits');
        const toggleBtn = node.querySelector('[data-t="toggle"]');
        const running = () => displayHandle !== null;
        const paint = () => {
            const secs = Math.ceil(remainingMs / 1000);
            digits.textContent = Math.floor(secs / 60) + ':' + String(secs % 60).padStart(2, '0');
            node.classList.toggle('low', secs <= 5 && secs > 0);
            node.classList.toggle('done', remainingMs === 0);
            toggleBtn.textContent = running() ? 'Pause' : (remainingMs === totalMs ? 'Start' : (remainingMs === 0 ? 'Again' : 'Resume'));
        };
        const stop = () => {
            clearInterval(displayHandle); clearTimeout(tickHandle);
            displayHandle = tickHandle = null;
            paint();
        };
        const scheduleTick = () => {
            const { delay } = tickDelay(remainingMs, totalMs);
            tickHandle = setTimeout(() => {
                if (!running() || remainingMs <= 0) return;
                tick(tickDelay(remainingMs, totalMs).urgency);
                scheduleTick();
            }, delay);
        };
        const t = {
            node,
            toggle() {
                if (running()) {
                    remainingMs = Math.max(0, endAt - Date.now());
                    return stop();
                }
                if (remainingMs === 0) remainingMs = totalMs;
                endAt = Date.now() + remainingMs;
                displayHandle = setInterval(() => {
                    remainingMs = Math.max(0, endAt - Date.now());
                    if (remainingMs === 0) { stop(); buzzer(); }
                    paint();
                }, 100);
                tick(0);
                scheduleTick();
                paint();
            },
            reset() { stop(); remainingMs = totalMs; paint(); },
        };
        toggleBtn.addEventListener('click', (e) => { e.stopPropagation(); t.toggle(); toggleBtn.blur(); });
        node.querySelector('[data-t="reset"]').addEventListener('click', (e) => { e.stopPropagation(); t.reset(); e.currentTarget.blur(); });
        paint();
        timers.set(key, t);
        return node;
    }

    function stopTimers() {
        timers.forEach((t) => t.reset());
        timers.clear();
    }

    // ------------------------------------------------------------ shell

    function shellHtml() {
        return `
        <header class="topbar">
            <div class="tb-left">
                <span class="tb-title">${esc(P.lesson.title)}</span>
                <span class="tb-label">${esc(P.label)}</span>
            </div>
            <div class="tb-clock"></div>
            ${scoring.enabled ? `<div class="tb-score" title="${esc(scoring.label || 'Score')} — press + / − to adjust">
                <span class="tb-score-label">${esc(scoring.label || 'Score')}</span>
                <span class="tb-score-num"></span><span class="tb-skulls"></span></div>` : ''}
            <nav class="tb-actions">
                <button type="button" data-act="undo" title="Undo (Backspace)">↶ Undo</button>
                <button type="button" data-act="mute" title="Timer sounds on/off (M)">${muted ? '🔇' : '🔊'}</button>
                <button type="button" data-act="notes" title="Presenter notes window (N)">Notes</button>
                <button type="button" data-act="full" title="Full screen (F)">⛶</button>
                <button type="button" data-act="reset" title="Restart from the beginning">Restart</button>
                <a href="${esc(P.exitUrl)}" title="Back to presentations">✕</a>
            </nav>
        </header>
        <main id="stage" class="stage"></main>
        <div class="save-warning">⚠ Not saving to the server — progress is still kept in this browser.</div>`;
    }

    function render() {
        const stage = document.getElementById('stage');
        const entering = state.node !== lastNodeRendered;
        if (entering && lastNodeRendered !== null) stopTimers();
        lastNodeRendered = state.node;
        stage.innerHTML = '';
        player.render(stage, state, { entering });
        if (entering) stage.scrollTop = 0;
        const num = document.querySelector('.tb-score-num');
        if (num) num.textContent = score(state);
        const sk = document.querySelector('.tb-skulls');
        if (sk) sk.textContent = skulls(state) ? ' ☠️'.repeat(Math.min(skulls(state), 5)) : '';
        const clock = document.querySelector('.tb-clock');
        if (clock) clock.textContent = player.clock ? (player.clock(state) || '') : '';
    }

    function toggleFullscreen() {
        if (document.fullscreenElement) document.exitFullscreen();
        else document.documentElement.requestFullscreen && document.documentElement.requestFullscreen();
    }

    function openNotes() {
        window.open(P.notesUrl, 'present-notes', 'width=760,height=900');
    }

    function onKey(e) {
        if (e.ctrlKey || e.metaKey || e.altKey) return;
        if (e.target.closest && e.target.closest('input, textarea, select')) return;
        const key = e.key.length === 1 ? e.key.toUpperCase() : e.key;
        if (player.onKey && player.onKey(key, e, state)) { e.preventDefault(); return; }
        const actions = {
            Backspace: undo, ArrowLeft: undo,
            F: toggleFullscreen, N: openNotes, M: toggleMute,
            '+': () => adjust(1), '=': () => adjust(1),
            '-': () => adjust(-1), '_': () => adjust(-1),
            T: () => { const t = [...timers.values()][0]; if (t) t.toggle(); },
        };
        if (actions[key]) { e.preventDefault(); actions[key](); }
    }

    function startStage() {
        const app = document.getElementById('app');
        app.innerHTML = shellHtml();
        app.querySelector('.tb-actions').addEventListener('click', (e) => {
            const b = e.target.closest('[data-act]');
            if (!b) return;
            ({ undo, notes: openNotes, full: toggleFullscreen, reset, mute: toggleMute })[b.dataset.act]();
            b.blur();
        });
        document.addEventListener('keydown', onKey);
        state = restore();
        state.savedAt = state.savedAt || Date.now();
        render();
        persist();
    }

    // ------------------------------------------------------------ notes window

    function startNotes() {
        const app = document.getElementById('app');
        const paint = (s) => {
            if (!s || !player.isValidState(s)) s = player.initialState();
            app.innerHTML = `
                <header class="notes-head">
                    <div><strong>${esc(P.lesson.title)}</strong> <span class="muted">· ${esc(P.label)} · presenter notes</span></div>
                    ${scoring.enabled ? `<div class="notes-score">${esc(scoring.label || 'Score')}: <strong>${score(s)}</strong>${skulls(s) ? ' ' + '☠️'.repeat(Math.min(skulls(s), 5)) : ''}</div>` : ''}
                </header>
                <div class="notes-body">${player.notes(s)}</div>
                <footer class="notes-keys">Keys on the main screen: <b>A/B/C</b> choose · <b>Space</b> continue · <b>D</b> roll · <b>R</b> reveal · <b>T</b> timer · <b>M</b> mute · <b>Backspace</b> undo · <b>+/−</b> score · <b>F</b> full screen</footer>`;
        };
        const local = loadLocal();
        paint(local ? local.state : P.saved);
        if (channel) channel.onmessage = (m) => paint(m.data.state);
        window.addEventListener('storage', (e) => {
            if (e.key === P.stateKey) { const l = loadLocal(); if (l) paint(l.state); }
        });
    }

    // ------------------------------------------------------------ public API

    window.Present = {
        registerType(key, impl) { types[key] = impl; },
        content: P.lesson.content,
        scoring, esc, md, el, timer, commit, addPoints, score, skulls,
        print() { window.print(); },
        start() {
            player = types[P.type];
            if (!player) {
                document.getElementById('app').textContent = 'No player for presentation type "' + P.type + '".';
                return;
            }
            if ('BroadcastChannel' in window) channel = new BroadcastChannel(P.stateKey);
            P.isNotes ? startNotes() : startStage();
        },
    };
})();
