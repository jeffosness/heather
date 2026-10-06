/*
 * "Adventure" player — branching, scored scenario.
 * Content schema is documented in includes/presentation_type_adventure.php.
 *
 * Run state: { node, ui: {pick?, roll?, revealed? (count of reveal steps shown)}, scores, skulls, path[], result, clock }
 * path[] records every scoring event so the ending can recap the run.
 */
(() => {
    const { md, esc, el } = Present;
    const C = Present.content;
    const nodes = C.nodes || {};
    const scoring = Present.scoring;
    let rolling = false;
    let lastState = null; // latest rendered state — the die animation settles against it

    const optKey = (o, i) => o.key || String.fromCharCode(65 + i);
    const signed = (n) => (n > 0 ? '+' + n : String(n));
    const nodeTitle = (n) => [n.round, n.title].filter(Boolean).join(' — ');
    const cur = (s) => nodes[s.node];

    // ------------------------------------------------------------ state

    function bandFor(score) {
        const bands = (scoring.bands || []).slice().sort((a, b) => (b.min ?? -Infinity) - (a.min ?? -Infinity));
        return bands.find((b) => b.min === undefined || b.min === null || score >= b.min) || null;
    }

    function applyEnter(s, id) {
        const n = nodes[id];
        if (n.time) s.clock = n.time; // carried forward onto slides without their own time
        if (n.enter && (n.enter.points || n.enter.skull)) {
            Present.addPoints(s, n.enter.points || 0, !!n.enter.skull);
            s.path.push({ node: id, label: nodeTitle(n), delta: n.enter.points || 0, skull: !!n.enter.skull });
        }
        s.result = null;
        derive(s);
    }

    function initialState() {
        const s = { v: 1, node: C.start, ui: {}, scores: { class: scoring.start || 0 }, skulls: { class: 0 }, path: [], result: null };
        applyEnter(s, C.start);
        return s;
    }

    /**
     * Saved progress must still line up with the (possibly edited) lesson:
     * a picked option / rolled outcome that no longer exists would crash rendering.
     */
    function isValidState(s) {
        if (!s || !s.ui || !s.scores || !nodes[s.node] || !Array.isArray(s.path)) return false;
        const n = nodes[s.node];
        if (s.ui.pick != null && !(n.kind === 'choice' && (n.options || [])[s.ui.pick])) return false;
        if (s.ui.explore != null && !(n.kind === 'choice' && (n.options || [])[s.ui.explore])) return false;
        if (s.ui.roll != null && !(n.kind === 'chance' && outcomeFor(n, s.ui.roll))) return false;
        return true;
    }

    /** Keep the final result in step with the score (e.g. +/− pressed on the ending slide). */
    function derive(s) {
        if (nodes[s.node].kind !== 'ending') return;
        const sc = Present.score(s), band = bandFor(sc);
        s.result = { score: sc, skulls: Present.skulls(s), band: band ? band.title : '' };
    }

    const go = (next) => Present.commit((s) => { s.node = next; s.ui = {}; applyEnter(s, next); });

    /**
     * First pick scores. After that, picking another option only EXPLORES it
     * ("what would have happened?") — no points, no path entry; picking the
     * real answer again returns to it.
     */
    function pick(s, i) {
        const n = cur(s), o = (n.options || [])[i];
        if (n.kind !== 'choice' || !o) return;
        if (s.ui.pick != null) {
            const showing = s.ui.explore ?? s.ui.pick;
            if (i === showing) return;
            return Present.commit((t) => {
                t.ui.explore = i === t.ui.pick ? null : i;
                if (i !== t.ui.pick) t.ui.seen = [...new Set([...(t.ui.seen || []), i])];
            });
        }
        Present.commit((t) => {
            t.ui.pick = i;
            Present.addPoints(t, o.points || 0, !!o.skull);
            t.path.push({ node: t.node, label: nodeTitle(n), choice: optKey(o, i), text: o.text, delta: o.points || 0, skull: !!o.skull });
        });
    }

    const outcomeFor = (n, r) => (n.outcomes || []).find((o) => r >= o.min && r <= o.max);

    function settleRoll(r) {
        const n = cur(lastState), o = outcomeFor(n, r);
        Present.commit((t) => {
            t.ui.roll = r;
            if (o) {
                Present.addPoints(t, o.points || 0, !!o.skull);
                t.path.push({ node: t.node, label: nodeTitle(n), choice: 'Rolled ' + r, text: o.title, delta: o.points || 0, skull: !!o.skull });
            }
        });
    }

    function roll(s, forced) {
        const n = cur(s);
        if (n.kind !== 'chance' || s.ui.roll != null || rolling) return;
        const sides = n.sides || 6;
        const final = forced || 1 + Math.floor(Math.random() * sides);
        if (forced) return settleRoll(final);
        rolling = true;
        const die = document.querySelector('.die');
        die.classList.add('rolling');
        let ticks = 0;
        const spin = setInterval(() => {
            die.innerHTML = dieFace(1 + Math.floor(Math.random() * sides), sides);
            if (++ticks >= 14) {
                clearInterval(spin);
                rolling = false;
                settleRoll(final);
            }
        }, 85);
    }

    // A reveal is either one `body` or a list of `steps` shown one press at a time.
    // ui.revealed = how many steps are showing (legacy `true` = all).
    const revealSteps = (n) => (!n.reveal ? [] : (Array.isArray(n.reveal.steps) && n.reveal.steps.length ? n.reveal.steps : [n.reveal.body || '']));
    const shownSteps = (n, s) => (s.ui.revealed === true ? revealSteps(n).length : Number(s.ui.revealed) || 0);
    const revealPending = (n, s) => shownSteps(n, s) < revealSteps(n).length;
    const reveal = (s) => Present.commit((t) => { t.ui.revealed = shownSteps(cur(s), s) + 1; });

    function advance(s) {
        const n = cur(s);
        switch (n.kind) {
            case 'scene':
                return go(n.next);
            case 'choice':
                if (s.ui.pick != null) go(n.options[s.ui.pick].next);
                return;
            case 'chance':
                if (s.ui.roll == null) return roll(s);
                return go(outcomeFor(n, s.ui.roll).next);
            case 'activity':
                if (revealPending(n, s)) return reveal(s);
                return go(n.next);
        }
    }


    // ------------------------------------------------------------ rendering

    const PIPS = { 1: [5], 2: [1, 9], 3: [1, 5, 9], 4: [1, 3, 7, 9], 5: [1, 3, 5, 7, 9], 6: [1, 3, 4, 6, 7, 9] };
    function dieFace(v, sides) {
        if (sides !== 6) return `<span class="die-num">${v}</span>`;
        return '<span class="die-face">' + Array.from({ length: 9 }, (_, i) => `<span class="pip ${PIPS[v].includes(i + 1) ? 'on' : ''}"></span>`).join('') + '</span>';
    }

    function head(n) {
        const eyebrow = [n.round, n.eyebrow].filter(Boolean).join(' · ');
        return `<div class="slide-head">${eyebrow ? `<div class="eyebrow">${esc(eyebrow)}</div>` : ''}</div>
                ${n.title ? `<h1 class="slide-title">${esc(n.title)}</h1>` : ''}`;
    }

    const deltaBadge = (d, skull) => (d || skull)
        ? `<span class="delta ${d > 0 ? 'up' : d < 0 ? 'down' : ''}">${d ? signed(d) : ''}${skull ? ' ☠️' : ''}</span>` : '';

    const nextBtn = (label) => `<button type="button" class="btn-next" data-go>${esc(label || 'Continue')} <span class="kbd">Space</span></button>`;

    function renderChoice(n, s) {
        const picked = s.ui.pick, explore = s.ui.explore ?? null;
        const best = Math.max(...n.options.map((o) => o.points || 0));
        const opts = n.options.map((o, i) => {
            let cls = '';
            if (picked != null) {
                cls = i === picked ? 'picked' : (i === explore ? 'exploring' : 'dim');
                if ((o.points || 0) === best && best > 0) cls += ' best';
            }
            return `<button type="button" class="option ${cls}" data-pick="${i}">
                <span class="opt-key">${esc(optKey(o, i))}</span><span class="opt-text">${esc(o.text)}</span>
                ${picked != null && (o.points || 0) === best && best > 0 && i !== picked ? '<span class="opt-best">✓ best</span>' : ''}
            </button>`;
        }).join('');
        let fb = '';
        if (picked != null) {
            const real = n.options[picked], realKey = optKey(real, picked);
            if (explore != null) {
                const o = n.options[explore], d = o.points || 0;
                fb = `<div class="feedback whatif">
                    <div class="fb-head"><span>What if you'd chosen ${esc(optKey(o, explore))}?</span><span class="whatif-tag">Not scored</span></div>
                    <div class="md">${md(o.what_if || o.feedback)}</div>
                    ${o.what_if ? '' : `<div class="whatif-would">${d || o.skull ? `Would have been ${deltaBadge(d, o.skull)}` : 'Would have been worth 0 points'}</div>`}
                    <div class="whatif-btns">${nextBtn('Continue with ' + realKey)}
                        <button type="button" class="btn-ghost" data-pick="${picked}">← Back to our answer (${esc(realKey)})</button></div></div>`;
            } else {
                const d = real.points || 0;
                fb = `<div class="feedback ${d > 0 ? 'good' : d < 0 ? 'bad' : 'meh'}">
                    <div class="fb-head"><span>${esc(realKey)}</span>${deltaBadge(d, real.skull)}</div>
                    <div class="md">${md(real.feedback)}</div>${nextBtn()}</div>`;
            }
        }
        const hint = picked != null && n.options.length > 1
            ? '<div class="explore-hint">Other answers: press a letter to see what would have happened (not scored).</div>' : '';
        return `<div class="cols">
            <div class="col-main md">${md(n.body)}</div>
            <div class="col-side ${picked != null ? 'answered' : ''}">
                ${n.prompt ? `<div class="prompt">${md(n.prompt)}</div>` : ''}
                <div class="options">${opts}</div>${hint}${fb}
            </div></div>`;
    }

    function renderChance(n, s) {
        const sides = n.sides || 6, r = s.ui.roll;
        const o = r != null ? outcomeFor(n, r) : null;
        return `<div class="cols">
            <div class="col-main md">${md(n.body)}</div>
            <div class="col-side chance">
                <button type="button" class="die ${r != null ? 'settled' : ''}" data-roll ${r != null ? 'disabled' : ''} title="Roll (D)">${dieFace(r || sides, sides)}</button>
                ${r == null ? `<button type="button" class="btn-next" data-roll>🎲 Roll the die <span class="kbd">D</span></button>` : `
                <div class="feedback ${o && o.points < 0 ? 'bad' : 'good'} ${o && o.tone ? 'tone-' + esc(o.tone) : ''}">
                    <div class="fb-head"><span>${esc(o ? o.title : 'Rolled ' + r)}</span>${o ? deltaBadge(o.points || 0, o.skull) : ''}</div>
                    <div class="md">${o ? md(o.text) : ''}</div>${nextBtn()}</div>`}
            </div></div>`;
    }

    function renderActivity(n, s) {
        const teams = (n.teams || []).map((t) => `<div class="team-card"><div class="team-name">${esc(t.name)}</div><div class="md">${md(t.prompt)}</div></div>`).join('');
        const cards = (n.cards || []).map((c) => `<div class="pcard"><div class="pcard-label">${esc(c.label)}</div><div class="md">${md(c.text)}</div></div>`).join('');
        const steps = revealSteps(n), shown = shownSteps(n, s), pending = revealPending(n, s);
        const multi = steps.length > 1;
        const rev = !n.reveal ? '' : (shown ? `<div class="reveal ${shown === 1 ? 'fresh' : ''}"><div class="reveal-title">${esc(n.reveal.title || 'Answer')}</div>
                ${steps.slice(0, shown).map((st) => `<div class="md ${multi ? 'reveal-step' : ''}">${md(st)}</div>`).join('')}</div>` : '')
            + (pending ? `<button type="button" class="btn-reveal" data-reveal>${shown ? 'Reveal next' : 'Reveal'}${multi ? ` (${shown + 1}/${steps.length})` : ''} <span class="kbd">R</span></button>` : '');
        return `<div class="cols">
                <div class="col-main">
                    <div class="md">${md(n.body)}</div>
                    ${n.prompt ? `<div class="prompt">${md(n.prompt)}</div>` : ''}
                </div>
                <div class="col-side">
                    <div data-timer-slot></div>
                    ${rev}
                    ${pending ? '' : nextBtn()}
                </div>
            </div>
            ${teams ? `<div class="teams">${teams}</div>` : ''}
            ${cards ? `<div class="print-area">
                <div class="cards-head"><span>${esc(n.cards_title || '')}</span>
                ${n.printable ? '<button type="button" class="btn-ghost no-print" data-print>🖨 Print cards</button>' : ''}</div>
                <div class="pcards">${cards}</div></div>` : ''}`;
    }

    function renderEnding(n, s) {
        const sc = Present.score(s), band = bandFor(sc), sk = Present.skulls(s);
        const recap = (s.path || []).map((p) => `<li><span>${esc(p.label)}${p.choice ? ' · <b>' + esc(p.choice) + '</b>' : ''}</span>${deltaBadge(p.delta, p.skull)}</li>`).join('');
        return `<div class="md">${md(n.body)}</div>
            ${scoring.enabled ? `<div class="final ${band && band.tone ? 'tone-' + esc(band.tone) : ''}">
                <div class="final-score">${sc}<span>${esc(scoring.label || 'points')}${sk ? ' · ' + '☠️'.repeat(Math.min(sk, 5)) : ''}</span></div>
                ${band ? `<div class="final-band">${esc(band.title)}</div><div class="final-text">“${esc(band.text)}”</div>` : ''}
            </div>
            ${recap ? `<details class="recap"><summary>How we got here</summary><ul>${recap}</ul></details>` : ''}` : ''}`;
    }

    function render(stage, s, { entering }) {
        lastState = s;
        const n = cur(s);
        const body = { choice: renderChoice, chance: renderChance, activity: renderActivity, ending: renderEnding }[n.kind];
        const slide = el(`<section class="slide kind-${esc(n.kind)} ${n.tone ? 'tone-' + esc(n.tone) : ''} ${entering ? 'entering' : ''}">
            ${head(n)}
            ${body ? body(n, s) : `<div class="md scene-body">${md(n.body)}</div>${nextBtn()}`}
        </section>`);
        const slot = slide.querySelector('[data-timer-slot]');
        if (slot && n.timer) slot.replaceWith(Present.timer(s.node, Number(n.timer)));
        slide.addEventListener('click', (e) => {
            const t = e.target.closest('[data-pick],[data-go],[data-roll],[data-reveal],[data-print]');
            if (!t) return;
            if (t.dataset.pick != null) pick(s, Number(t.dataset.pick));
            else if (t.hasAttribute('data-go')) advance(s);
            else if (t.hasAttribute('data-roll')) roll(s);
            else if (t.hasAttribute('data-reveal')) reveal(s);
            else if (t.hasAttribute('data-print')) Present.print();
        });
        stage.appendChild(slide);
        if (entering && n.tone === 'danger') {
            stage.classList.remove('flash-danger');
            void stage.offsetWidth; // restart the animation
            stage.classList.add('flash-danger');
        }
        // New feedback / reveal can land below the fold on a 720p projector.
        const fresh = !entering && (slide.querySelector('.feedback') || slide.querySelector('.reveal-step:last-of-type, .reveal'));
        if (fresh) fresh.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
    }

    function onKey(key, e, s) {
        const n = cur(s);
        if (n.kind === 'choice') { // first press scores; later presses explore
            const i = n.options.findIndex((o, idx) => optKey(o, idx).toUpperCase() === key);
            if (i >= 0) { pick(s, i); return true; }
        }
        if (n.kind === 'chance' && s.ui.roll == null) {
            if (key === 'D') { roll(s); return true; }
            const forced = Number(key);
            if (forced >= 1 && forced <= (n.sides || 6)) { roll(s, forced); return true; }
        }
        if (n.kind === 'activity' && key === 'R' && revealPending(n, s)) { reveal(s); return true; }
        if (key === ' ' || key === 'Enter' || key === 'ArrowRight' || key === 'PageDown') { advance(s); return true; }
        return false;
    }

    // ------------------------------------------------------------ notes window

    function notes(s) {
        const n = cur(s);
        const target = (id) => nodes[id] ? esc(nodeTitle(nodes[id]) || id) : esc(id);
        let key = '';
        if (n.kind === 'choice') {
            key = '<h3>Answer key</h3><ul class="key">' + n.options.map((o, i) =>
                `<li class="${i === s.ui.pick ? 'picked' : ''}"><b>${esc(optKey(o, i))}</b> ${esc(o.text)} ${deltaBadge(o.points || 0, o.skull)} <span class="muted">→ ${target(o.next)}</span>`
                + (i === s.ui.pick ? ' <span class="muted">· class picked</span>' : (s.ui.seen || []).includes(i) ? ' <span class="muted">· explored ✓</span>' : '')
                + '</li>').join('') + '</ul>'
                + (s.ui.pick != null ? '<p class="muted">Press another letter to show what would have happened (not scored).</p>' : '');
        } else if (n.kind === 'chance') {
            key = '<h3>Outcomes</h3><ul class="key">' + (n.outcomes || []).map((o) =>
                `<li><b>${o.min}–${o.max}</b> ${esc(o.title)} ${deltaBadge(o.points || 0, o.skull)} <span class="muted">→ ${target(o.next)}</span></li>`).join('') + '</ul>'
                + '<p class="muted">Keys 1–' + (n.sides || 6) + ' force a result.</p>';
        } else if (n.kind === 'activity' && n.reveal) {
            const steps = revealSteps(n), shown = shownSteps(n, s);
            key = `<h3>Reveal — ${shown}/${steps.length} shown${revealPending(n, s) ? ' (press R)' : ''}</h3><ol class="key">`
                + steps.map((st, i) => `<li class="${i < shown ? 'picked' : ''}">${md(st)}</li>`).join('') + '</ol>';
        }
        const next = n.next ? `<p class="muted">Next: ${target(n.next)}</p>` : '';
        return `<div class="notes-now"><span class="muted">${esc(n.kind)}${n.time ? ' · ' + esc(n.time) : ''}</span><h2>${esc(nodeTitle(n) || s.node)}</h2></div>
            ${n.notes ? `<div class="notes-text md">${md(n.notes)}</div>` : '<p class="muted">No notes for this slide.</p>'}
            ${key}${next}`;
    }

    /** The slide's timestamp, shown as the shift clock in the top bar. */
    function clock(s) {
        const t = cur(s).time || s.clock;
        return t ? '🕒 ' + t : '';
    }

    Present.registerType('adventure', {
        initialState,
        isValidState, derive, render, onKey, notes, clock,
    });
})();
