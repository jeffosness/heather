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

    // ------------------------------------------------------------ timers

    let audioCtx = null;
    function beep(times) {
        try {
            audioCtx = audioCtx || new (window.AudioContext || window.webkitAudioContext)();
            for (let i = 0; i < times; i++) {
                const o = audioCtx.createOscillator(), g = audioCtx.createGain();
                o.frequency.value = 880;
                o.connect(g); g.connect(audioCtx.destination);
                const t = audioCtx.currentTime + i * 0.35;
                g.gain.setValueAtTime(0.25, t);
                g.gain.exponentialRampToValueAtTime(0.001, t + 0.3);
                o.start(t); o.stop(t + 0.3);
            }
        } catch (e) { /* no audio — the visual flash still shows */ }
    }

    /**
     * A countdown widget, cached per key so it survives re-renders on the
     * same slide (e.g. revealing answers while the clock runs).
     */
    function timer(key, seconds) {
        if (timers.has(key)) return timers.get(key).node;
        let left = seconds, handle = null;
        const node = el(`<div class="timer">
            <div class="timer-digits"></div>
            <div class="timer-btns">
                <button type="button" class="btn-ghost" data-t="toggle">Start</button>
                <button type="button" class="btn-ghost" data-t="reset">Reset</button>
            </div></div>`);
        const digits = node.querySelector('.timer-digits');
        const toggleBtn = node.querySelector('[data-t="toggle"]');
        const paint = () => {
            digits.textContent = Math.floor(left / 60) + ':' + String(left % 60).padStart(2, '0');
            node.classList.toggle('low', left <= 5 && left > 0);
            node.classList.toggle('done', left === 0);
            toggleBtn.textContent = handle ? 'Pause' : (left === seconds ? 'Start' : (left === 0 ? 'Again' : 'Resume'));
        };
        const stop = () => { clearInterval(handle); handle = null; paint(); };
        const t = {
            node,
            toggle() {
                if (handle) return stop();
                if (left === 0) left = seconds;
                handle = setInterval(() => {
                    left = Math.max(0, left - 1);
                    if (left === 0) { stop(); beep(3); }
                    paint();
                }, 1000);
                paint();
            },
            reset() { stop(); left = seconds; paint(); },
        };
        toggleBtn.addEventListener('click', (e) => { e.stopPropagation(); t.toggle(); });
        node.querySelector('[data-t="reset"]').addEventListener('click', (e) => { e.stopPropagation(); t.reset(); });
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
            F: toggleFullscreen, N: openNotes,
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
            ({ undo, notes: openNotes, full: toggleFullscreen, reset })[b.dataset.act]();
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
                <footer class="notes-keys">Keys on the main screen: <b>A/B/C</b> choose · <b>Space</b> continue · <b>D</b> roll · <b>R</b> reveal · <b>T</b> timer · <b>Backspace</b> undo · <b>+/−</b> score · <b>F</b> full screen</footer>`;
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
