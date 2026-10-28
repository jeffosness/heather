/*
 * Bunionectomy surgical field: a cartoon top-down view of a right foot,
 * drawn in SVG. ProcedureFields.bunionectomy(stage) returns the SVG markup
 * for a named stage. Stages are cumulative: each one adds/clears layer
 * flags on top of the stage before it, so a lesson only names a stage.
 */
(() => {
    const STAGES = [
        ['prepped',        { valgus: 1, bump: 1, mark: 1 }],
        ['incised',        { mark: 0, incision: 1, bleed: 1 }],
        ['hemostasis',     { bleed: 0 }],
        ['dissected',      { incision: 0, wound: 1 }],
        ['retracted',      { rakes: 1 }],
        ['capsule_open',   { bone: 1, tissueOnBone: 1 }],
        ['bone_exposed',   { tissueOnBone: 0 }],
        ['hohmann',        { hohmann: 1 }],
        ['bump_cut',       { bump: 0, cut: 1, rough: 1, dust: 1 }],
        ['smoothed',       { rough: 0 }],
        ['irrigated',      { dust: 0, wet: 1 }],
        ['corrective',     { wet: 0, chevron: 1 }],
        ['released',       { release: 1 }],
        ['realigned',      { valgus: 0, kwire: 1 }],
        ['drilled',        { hole: 1 }],
        ['screwed',        { hole: 0, screws: 1, kwire: 0 }],
        ['final_irrigate', { wet: 1 }],
        ['closed_deep',    { wet: 0, bone: 0, hohmann: 0, capsuleClosed: 1 }],
        ['closed_skin',    { wound: 0, rakes: 0, capsuleClosed: 0, stitches: 1 }],
        ['dressed',        { dressing: 1 }],
    ];

    function flagsFor(stage) {
        const f = {};
        for (const [name, delta] of STAGES) {
            Object.assign(f, delta);
            if (name === stage) return f;
        }
        return f;
    }

    const SKIN = '#f3c9a4', SKIN_LINE = '#b97c58', DRAPE = '#5b8fb9', DRAPE_DARK = '#4a7ba3';
    const FAT = '#f7dd8f', CAPSULE = '#efc6c6', BONE = '#f4ecd6', BONE_LINE = '#b9a982';
    const STEEL = '#a7b0ba', STEEL_DARK = '#66707b', BLOOD = '#c62828';

    // Wound (incision) sits over the medial side of the 1st MTP joint.
    const WX = 94, WY = 168;

    function foot(f) {
        const toeAngle = f.valgus ? 24 : 2;
        const lesserShift = f.valgus ? 8 : 0;
        // The big toe pivots at the joint; its base overlaps the forefoot.
        const bigToe = `<g transform="rotate(${toeAngle} 106 136)">
            <rect x="84" y="34" width="46" height="106" rx="23" />
        </g>`;
        const lesser = [[146, 84, 15, 24], [176, 92, 13, 22], [202, 104, 12, 20], [224, 120, 11, 17]]
            .map(([x, y, rx, ry]) => `<ellipse cx="${x + lesserShift}" cy="${y}" rx="${rx}" ry="${ry}" />`).join('');
        const body = `<path d="M96 330 C88 280 86 230 92 190 C94 160 92 140 100 122 C130 104 200 104 240 126
            C252 160 252 220 238 270 C232 296 230 316 230 330 Z" />`;
        const bump = f.bump ? '<circle cx="88" cy="166" r="22" />' : '';
        const shapes = body + bump + bigToe + lesser;
        // Outline pass (fat stroke) under a fill pass so overlaps merge cleanly.
        return `<g fill="${SKIN_LINE}" stroke="${SKIN_LINE}" stroke-width="5">${shapes}</g>
            <g fill="${SKIN}" stroke="none">${shapes}</g>
            <rect x="94" y="40" width="26" height="24" rx="10" fill="#f8e1cf" stroke="${SKIN_LINE}" stroke-width="1.2" transform="rotate(${toeAngle} 106 136)" />`;
    }

    function drapes() {
        return `<rect width="320" height="340" fill="${DRAPE}" />
            <g stroke="${DRAPE_DARK}" stroke-width="1" opacity=".5">
                ${Array.from({ length: 9 }, (_, i) => `<line x1="0" y1="${i * 40}" x2="320" y2="${i * 40 + 20}" />`).join('')}
            </g>
            <rect x="0" y="262" width="320" height="40" fill="${DRAPE_DARK}" />
            <text x="156" y="281" text-anchor="middle" font-size="11" fill="#dfeaf3" font-family="Inter, sans-serif">sterile drape</text>`;
    }

    function wound(f) {
        let s = '';
        const rx = f.rakes ? 22 : 13, ry = 44;
        s += `<ellipse cx="${WX}" cy="${WY}" rx="${rx}" ry="${ry}" fill="#d9776f" />`;
        s += `<ellipse cx="${WX}" cy="${WY}" rx="${rx - 3}" ry="${ry - 4}" fill="${FAT}" />`;
        if (!f.bone && !f.capsuleClosed) {
            s += `<ellipse cx="${WX}" cy="${WY}" rx="${rx - 8}" ry="${ry - 12}" fill="${CAPSULE}" />`;
        }
        if (f.capsuleClosed) {
            s += `<ellipse cx="${WX}" cy="${WY}" rx="${rx - 8}" ry="${ry - 12}" fill="${CAPSULE}" />
                <line x1="${WX}" y1="${WY - 28}" x2="${WX}" y2="${WY + 28}" stroke="#b07d7d" stroke-width="1.5" />
                ${[-20, -10, 0, 10, 20].map((d) => `<line x1="${WX - 5}" y1="${WY + d - 3}" x2="${WX + 5}" y2="${WY + d + 3}" stroke="#7a3fa0" stroke-width="2" />`).join('')}`;
        }
        if (f.bone) s += bone(f);
        if (f.tissueOnBone) {
            s += `<path d="M${WX - 12} ${WY - 18} q-6 18 0 36 l6 0 q-4 -18 0 -36 z" fill="${CAPSULE}" opacity=".9" />`;
        }
        if (f.wet) {
            s += `<g fill="#bfe3f7" opacity=".75">
                <ellipse cx="${WX - 4}" cy="${WY - 14}" rx="4" ry="7" /><ellipse cx="${WX + 6}" cy="${WY + 10}" rx="3" ry="5" />
                <circle cx="${WX + 2}" cy="${WY + 26}" r="2.5" /></g>
                <path d="M${WX - 8} ${WY - 30} q4 -3 8 0" stroke="#fff" stroke-width="2" fill="none" />`;
        }
        if (f.rakes) s += rakes(rx);
        return s;
    }

    let clipSeq = 0; // many fields can share a page, so clip ids must be unique

    function bone(f) {
        // Metatarsal head seen through the wound; the bump is its medial bulge.
        const clip = 'bn-cut-' + (++clipSeq);
        let s = `<clipPath id="${clip}"><rect x="${f.bump ? 0 : WX - 6}" y="0" width="320" height="340" /></clipPath>
            <g clip-path="url(#${clip})">
                <ellipse cx="${WX + 4}" cy="${WY}" rx="14" ry="22" fill="${BONE}" stroke="${BONE_LINE}" stroke-width="1.5" />
                ${f.bump ? `<ellipse cx="${WX - 8}" cy="${WY + 2}" rx="8" ry="12" fill="${BONE}" stroke="${BONE_LINE}" stroke-width="1.5" />` : ''}
            </g>`;
        if (f.cut) {
            s += f.rough
                ? `<polyline points="${WX - 6},${WY - 20} ${WX - 3},${WY - 14} ${WX - 7},${WY - 8} ${WX - 3},${WY - 2} ${WX - 7},${WY + 4} ${WX - 3},${WY + 10} ${WX - 7},${WY + 16} ${WX - 5},${WY + 20}" fill="none" stroke="${BONE_LINE}" stroke-width="1.5" />`
                : `<line x1="${WX - 6}" y1="${WY - 20}" x2="${WX - 6}" y2="${WY + 20}" stroke="${BONE_LINE}" stroke-width="1.5" />`;
        }
        if (f.dust) {
            s += `<g fill="#fffbe9">${[[-12, -26], [14, -30], [-14, 22], [16, 28], [-2, 34], [10, -12], [-10, 8]]
                .map(([x, y]) => `<circle cx="${WX + x}" cy="${WY + y}" r="1.8" />`).join('')}</g>`;
        }
        if (f.chevron) {
            s += `<polyline points="${WX + 14},${WY - 14} ${WX - 2},${WY} ${WX + 14},${WY + 14}" fill="none" stroke="${BLOOD}" stroke-width="1.5" stroke-dasharray="3 2" />`;
        }
        if (f.hole) s += `<circle cx="${WX + 4}" cy="${WY - 6}" r="2.6" fill="#5a4a2a" /><circle cx="${WX + 6}" cy="${WY + 8}" r="2.6" fill="#5a4a2a" />`;
        if (f.screws) {
            s += [[WX + 4, WY - 6], [WX + 6, WY + 8]].map(([x, y]) => `<circle cx="${x}" cy="${y}" r="4" fill="#d6dbe0" stroke="${STEEL_DARK}" stroke-width="1" />
                <path d="M${x - 2} ${y} h4 M${x} ${y - 2} v4" stroke="${STEEL_DARK}" stroke-width="1.2" />`).join('');
        }
        if (f.hohmann) {
            s += `<g fill="${STEEL}" stroke="${STEEL_DARK}" stroke-width="1">
                <path d="M${WX - 2} ${WY - 26} q10 -6 18 2 l18 -30 l6 3 l-20 32 q-10 -4 -22 -7 z" />
                <path d="M${WX - 2} ${WY + 26} q10 6 18 -2 l18 30 l6 -3 l-20 -32 q-10 4 -22 7 z" /></g>`;
        }
        return s;
    }

    function rakes(rx) {
        const one = (x, dir) => `<g fill="${STEEL}" stroke="${STEEL_DARK}" stroke-width="1">
            <rect x="${dir < 0 ? x - 54 : x + 6}" y="${WY - 4}" width="48" height="8" rx="3" />
            ${[-10, 0, 10].map((d) => `<path d="M${x + dir * 6} ${WY + d - 2} q${-dir * 8} 0 ${-dir * 8} 4 l${dir * 3} 0 q0 -2 ${dir * 5} -2 z" />`).join('')}
            <rect x="${dir < 0 ? x - 8 : x + 2}" y="${WY - 14}" width="6" height="28" rx="2" /></g>`;
        return one(WX - rx, -1) + one(WX + rx, 1);
    }

    function svg(stage) {
        const f = flagsFor(stage);
        let s = drapes() + foot(f);
        if (f.mark) s += `<line x1="${WX}" y1="${WY - 42}" x2="${WX}" y2="${WY + 42}" stroke="#7a3fa0" stroke-width="2.5" stroke-dasharray="6 4" />`;
        if (f.incision) s += `<path d="M${WX} ${WY - 42} q3 42 0 84" stroke="#9c2b2b" stroke-width="3" fill="none" />`;
        if (f.bleed) {
            s += `<g fill="${BLOOD}">${[[-4, -30], [5, -12], [-5, 6], [4, 22], [-3, 36]]
                .map(([x, y]) => `<circle cx="${WX + x}" cy="${WY + y}" r="3.2" />`).join('')}</g>`;
        }
        if (f.wound) s += wound(f);
        if (f.release) {
            s += `<path d="M132 116 l8 22" stroke="#9c2b2b" stroke-width="2.5" />
                ${f.stitches ? '<path d="M131 124 l7 -3 M134 132 l7 -3" stroke="#1f1f1f" stroke-width="1.6" />' : ''}`;
        }
        if (f.kwire) {
            s += `<line x1="108" y1="46" x2="100" y2="214" stroke="${STEEL_DARK}" stroke-width="2.2" />
                <circle cx="108" cy="46" r="2.5" fill="${STEEL_DARK}" />`;
        }
        if (f.stitches) {
            s += `<path d="M${WX} ${WY - 42} q3 42 0 84" stroke="#9c2b2b" stroke-width="2" fill="none" />
                ${[-36, -24, -12, 0, 12, 24, 36].map((d) => `<line x1="${WX - 7}" y1="${WY + d - 2}" x2="${WX + 8}" y2="${WY + d + 2}" stroke="#1f1f1f" stroke-width="1.8" />`).join('')}`;
        }
        if (f.dressing) {
            s += `<g><path d="M78 92 C120 76 200 84 252 116 L252 250 C200 262 130 262 84 250 Z" fill="#fbfbfb" stroke="#c9c9c9" stroke-width="2" />
                ${[110, 140, 170, 200, 230].map((y) => `<path d="M84 ${y + 6} C140 ${y - 10} 200 ${y - 6} 252 ${y + 8}" stroke="#e1e1e1" stroke-width="2" fill="none" />`).join('')}</g>`;
        }
        return `<svg viewBox="30 18 252 272" xmlns="http://www.w3.org/2000/svg" role="img" aria-label="Surgical field: ${stage.replace(/_/g, ' ')}">${s}</svg>`;
    }

    window.ProcedureFields = window.ProcedureFields || {};
    window.ProcedureFields.bunionectomy = svg;
    window.ProcedureFields.bunionectomy.stages = STAGES.map(([n]) => n);
})();
