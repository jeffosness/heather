/*
 * Cartoon surgical instrument icons, drawn in SVG (viewBox 120x60).
 * Shared across procedure lessons: Instruments.svg('metz') → markup.
 * Unknown keys render a neutral placeholder so a lesson never breaks.
 */
(() => {
    const ST = '#aab3bd', SD = '#5f6973', W = 'stroke-width';
    const g = (body) => `<g fill="${ST}" stroke="${SD}" ${W}="1.4" stroke-linejoin="round">${body}</g>`;

    // Finger-ring instruments: rings on the left, box lock at x=54, jaws to the right.
    function ringed(jaws, { ratchet = false } = {}) {
        return g(`<circle cx="14" cy="18" r="8" fill="none" ${W}="4" stroke="${ST}" />
            <circle cx="14" cy="18" r="8" fill="none" />
            <circle cx="14" cy="42" r="8" fill="none" ${W}="4" stroke="${ST}" />
            <circle cx="14" cy="42" r="8" fill="none" />
            <path d="M21 22 L54 29 L54 31 L21 25 Z" /><path d="M21 38 L54 31 L54 29 L21 35 Z" />
            ${ratchet ? `<path d="M24 26 l6 4 l-6 4" fill="none" />` : ''}
            <circle cx="54" cy="30" r="3.4" />${jaws}`);
    }
    const scissors = (len, curve, heavy) => ringed(
        `<path d="M56 ${heavy ? 26 : 28} Q${56 + len * 0.6} ${26 - curve} ${56 + len} ${30 - curve} L${56 + len - 2} ${31 - curve} Q${56 + len * 0.6} ${31 - curve} 56 31 Z" />
         <path d="M56 ${heavy ? 34 : 32} Q${56 + len * 0.6} ${34 - curve} ${56 + len} ${31 - curve} L${56 + len - 2} ${30 - curve} Q${56 + len * 0.6} ${29 - curve} 56 29 Z" />`);
    const clamp = (len, tipW) => ringed(
        `<path d="M56 28 L${56 + len} ${30 - tipW / 2} L${56 + len} ${30 + tipW / 2} L56 32 Z" />
         ${Array.from({ length: Math.floor(len / 6) }, (_, i) => `<line x1="${60 + i * 6}" y1="29" x2="${60 + i * 6}" y2="31" ${W}="1" />`).join('')}`,
        { ratchet: true });

    const DRAW = {
        scalpel: () => g(`<rect x="8" y="26" width="64" height="9" rx="3" />
            <path d="M28 28 h30 M28 33 h30" fill="none" ${W}="1" />
            <path d="M72 27 L94 25 Q110 26 112 32 Q100 36 72 35 Z" fill="#dfe4e9" />`),
        esu: () => `<path d="M4 46 C20 56 22 34 32 34" fill="none" stroke="#888" stroke-width="2" />
            <rect x="30" y="27" width="60" height="12" rx="6" fill="#3f78b5" stroke="#28537f" stroke-width="1.4" />
            <rect x="48" y="25" width="8" height="5" rx="1.5" fill="#f2c94c" stroke="#9c7d1a" /><rect x="60" y="25" width="8" height="5" rx="1.5" fill="#6fbf73" stroke="#3a7a3d" />
            <path d="M90 30 L108 33 L90 36 Z" fill="${ST}" stroke="${SD}" stroke-width="1.4" />`,
        mosquito: () => clamp(30, 3),
        hemostat: () => clamp(38, 4),
        needle_holder: () => ringed(`<path d="M56 27 L80 28 L80 32 L56 33 Z" /><path d="M80 29 Q96 14 104 28" fill="none" stroke="#555" ${W}="2" />
            <path d="M104 28 C110 40 116 46 118 56" fill="none" stroke="#7a3fa0" ${W}="1.6" />`, { ratchet: true }),
        metz: () => scissors(52, 4, false),
        mayo: () => scissors(40, 3, true),
        suture_scissors: () => scissors(40, 0, true),
        towel_clip: () => ringed(`<path d="M56 29 Q84 4 92 28" fill="none" ${W}="2.5" /><path d="M56 31 Q84 56 92 32" fill="none" ${W}="2.5" />`, { ratchet: true }),
        adson: () => g(`<path d="M8 30 L100 24 L102 26 L12 31 Z" /><path d="M8 30 L100 36 L102 34 L12 29 Z" />
            <path d="M30 27 h30 M30 33 h30" fill="none" ${W}="1" stroke-dasharray="2 2" />
            <path d="M101 25 l4 1 l-3 2 M101 35 l4 -1 l-3 -2" fill="none" />`),
        senn: () => g(`<rect x="22" y="27" width="70" height="6" rx="2" />
            <path d="M22 27 l-10 -8 l-3 3 l9 7 M22 30 l-12 0 M22 33 l-10 8 l-3 -3 l9 -7" fill="none" ${W}="2" />
            <path d="M92 27 L112 20 L114 24 L92 33 Z" />`),
        skin_hook: () => g(`<rect x="10" y="27" width="74" height="6" rx="3" /><path d="M84 30 L104 30 Q114 30 112 20" fill="none" ${W}="2.5" />`),
        weitlaner: () => ringed(`<path d="M56 28 L92 20 M56 32 L92 40" fill="none" ${W}="3" stroke="${ST}" />
            <path d="M92 20 l8 -4 M92 20 l8 0 M92 20 l8 4 M92 40 l8 -4 M92 40 l8 0 M92 40 l8 4" fill="none" ${W}="2" />
            <path d="M40 18 L40 42" ${W}="2" />`),
        deaver: () => g(`<path d="M8 42 L60 42 Q100 42 104 22 Q106 8 92 8 L92 14 Q100 14 98 22 Q94 36 60 36 L8 36 Z" />`),
        richardson: () => g(`<rect x="8" y="27" width="64" height="8" rx="3" /><path d="M72 24 L72 38 L104 52 L112 46 L80 33 L80 24 Z" />
            <circle cx="20" cy="31" r="3" fill="#fff" />`),
        army_navy: () => g(`<path d="M10 22 L24 22 L24 36 L96 36 L96 30 L110 30 L110 42 L16 42 L16 28 L10 28 Z" />`),
        hohmann: () => g(`<path d="M8 27 L70 25 L92 18 Q104 14 110 22 L104 26 Q100 22 94 24 L72 33 L8 33 Z" />
            <circle cx="40" cy="29" r="2.5" fill="#fff" /><circle cx="52" cy="29" r="2.5" fill="#fff" />`),
        freer: () => g(`<path d="M14 26 Q6 30 14 34 L106 34 Q114 30 106 26 Z" /><rect x="40" y="27" width="40" height="6" rx="2" fill="#c7ced6" />`),
        osteotome: () => g(`<rect x="34" y="27" width="64" height="7" rx="1" /><path d="M98 27 L110 29 L110 32 L98 34 Z" /><rect x="22" y="25" width="14" height="11" rx="3" />
            <rect x="2" y="10" width="14" height="22" rx="3" fill="#8d6e4f" stroke="#5d4430" /><rect x="7" y="32" width="4" height="22" fill="#8d6e4f" stroke="#5d4430" />`),
        rongeur: () => g(`<path d="M8 22 Q40 16 70 27 L72 30 Q40 22 10 28 Z" /><path d="M8 38 Q40 44 70 33 L72 30 Q40 38 10 32 Z" />
            <path d="M14 24 Q20 30 14 36" fill="none" ${W}="2" /><path d="M70 26 Q96 18 104 26 Q98 30 70 30 Z" /><path d="M70 34 Q96 42 104 34 Q98 30 70 30 Z" />`),
        rasp: () => g(`<rect x="8" y="26" width="44" height="9" rx="4" fill="#3d3d3d" stroke="#222" /><rect x="52" y="28" width="12" height="5" />
            <path d="M64 25 L108 26 Q114 30 108 35 L64 36 Z" />
            ${[70, 78, 86, 94, 102].map((x) => `<path d="M${x} 26 l4 9 M${x + 4} 26 l-4 9" ${W}="0.8" />`).join('')}`),
        saw: () => `<path d="M20 50 L30 20 Q32 14 40 14 L80 14 Q88 14 88 22 L88 30 L44 30 L36 52 Z" fill="#e7e1d3" stroke="#8a826f" stroke-width="1.4" />
            <rect x="88" y="18" width="10" height="8" fill="${ST}" stroke="${SD}" /><path d="M98 16 L114 12 L116 30 L98 28 Z" fill="#dfe4e9" stroke="${SD}" stroke-width="1.4" />
            <path d="M114 12 l2 3 l-2 3 l2 3 l-2 3 l2 3" fill="none" stroke="${SD}" />`,
        drill: () => `<path d="M20 50 L30 20 Q32 14 40 14 L80 14 Q88 14 88 22 L88 30 L44 30 L36 52 Z" fill="#e7e1d3" stroke="#8a826f" stroke-width="1.4" />
            <path d="M88 18 L96 20 L96 24 L88 26 Z" fill="${ST}" stroke="${SD}" /><line x1="96" y1="22" x2="116" y2="22" stroke="${SD}" stroke-width="2.4" />
            <path d="M100 20 l3 4 M105 20 l3 4 M110 20 l3 4" stroke="#fff" stroke-width="1" />`,
        kwire: () => `<path d="M20 50 L30 20 Q32 14 40 14 L80 14 Q88 14 88 22 L88 30 L44 30 L36 52 Z" fill="#d7e3ea" stroke="#71889a" stroke-width="1.4" />
            <path d="M88 18 L96 20 L96 24 L88 26 Z" fill="${ST}" stroke="${SD}" /><line x1="96" y1="22" x2="118" y2="22" stroke="${SD}" stroke-width="1.2" />`,
        depth_gauge: () => g(`<rect x="8" y="24" width="40" height="12" rx="3" /><rect x="48" y="27" width="50" height="6" />
            ${[54, 60, 66, 72, 78, 84, 90].map((x) => `<line x1="${x}" y1="27" x2="${x}" y2="30" ${W}="1" />`).join('')}
            <path d="M98 29 L114 29 l0 -4" fill="none" ${W}="1.6" />`),
        screwdriver: () => `<rect x="6" y="22" width="40" height="16" rx="6" fill="#4c7a5a" stroke="#2f5039" stroke-width="1.4" />
            <rect x="46" y="28" width="44" height="4" fill="${ST}" stroke="${SD}" />
            <path d="M90 26 L98 26 L98 34 L90 34 Z" fill="#d6dbe0" stroke="${SD}" /><path d="M98 27 L114 29 L114 31 L98 33 Z" fill="#d6dbe0" stroke="${SD}" />
            <path d="M100 27 l2 6 M104 28 l2 5 M108 28 l2 4" stroke="${SD}" stroke-width="0.9" />`,
        bulb: () => `<ellipse cx="34" cy="30" rx="26" ry="20" fill="#5b9bd5" stroke="#2f6aa1" stroke-width="1.4" />
            <path d="M58 26 L110 28 L110 32 L58 34 Z" fill="#cfe5f6" stroke="#2f6aa1" stroke-width="1.4" />
            <path d="M22 20 q8 -6 16 0" fill="none" stroke="#fff" stroke-width="2" opacity=".6" />`,
        sponge: () => `<rect x="30" y="8" width="60" height="44" rx="3" fill="#fbfbfb" stroke="#bbb" stroke-width="1.4" />
            ${[16, 24, 32, 40].map((y) => `<line x1="34" y1="${y}" x2="86" y2="${y}" stroke="#e6e6e6" />`).join('')}
            <line x1="40" y1="8" x2="40" y2="52" stroke="#2f6aa1" stroke-width="3" />`,
        count: () => `<rect x="34" y="6" width="52" height="50" rx="4" fill="#c49a6c" stroke="#8a6640" stroke-width="1.4" />
            <rect x="40" y="12" width="40" height="40" fill="#fff" stroke="#bbb" /><rect x="50" y="3" width="20" height="8" rx="2" fill="${ST}" stroke="${SD}" />
            ${[20, 28, 36, 44].map((y) => `<path d="M44 ${y} l3 3 l5 -6" fill="none" stroke="#3a8a46" stroke-width="1.6" /><line x1="56" y1="${y}" x2="76" y2="${y}" stroke="#aaa" />`).join('')}`,
        dressing: () => `<rect x="8" y="16" width="44" height="32" rx="2" fill="#fbfbfb" stroke="#bbb" stroke-width="1.4" />
            <path d="M12 22 h36 M12 30 h36 M12 38 h36" stroke="#e3e3e3" />
            <ellipse cx="86" cy="32" rx="22" ry="18" fill="#f4f1ea" stroke="#bbb" stroke-width="1.4" /><ellipse cx="86" cy="32" rx="8" ry="6" fill="#e2ddd2" stroke="#bbb" />`,
    };

    function svg(key) {
        const draw = DRAW[key];
        const body = draw ? draw() : `<rect x="20" y="15" width="80" height="30" rx="8" fill="#eee" stroke="#bbb" stroke-dasharray="4 3" /><text x="60" y="35" text-anchor="middle" font-size="12" fill="#999">?</text>`;
        return `<svg viewBox="0 0 120 60" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">${body}</svg>`;
    }

    // Fill every <span data-tool="key"> on the page.
    function paint(root = document) {
        root.querySelectorAll('[data-tool]').forEach((n) => { n.innerHTML = svg(n.dataset.tool); });
    }

    window.Instruments = { svg, paint, keys: Object.keys(DRAW) };
})();
