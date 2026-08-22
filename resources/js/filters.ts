// Photo filters. iOS Safari (2026) ships canvas ctx.filter behind a disabled
// flag, so it silently no-ops — a CSS-filtered preview would then not match the
// captured frame. To stay WYSIWYG on every device, each filter is defined once
// as an ordered op list, from which we derive BOTH the CSS string (live preview
// + the ctx.filter fast path on Chrome) AND a 4x5 colour matrix (the pixel
// fallback on iOS). The two are equivalent by construction.

type Op = { fn: string; value: number };

// A 4x5 (RGBA) colour matrix in row-major order; the 5th column is an offset in
// 0..255. output = c0*r + c1*g + c2*b + c3*a + offset, per channel.
export type ColorMatrix = number[];

const IDENTITY: ColorMatrix = [
    1, 0, 0, 0, 0,
    0, 1, 0, 0, 0,
    0, 0, 1, 0, 0,
    0, 0, 0, 1, 0,
];

// Per-op matrices, matching the CSS/SVG filter-effects spec so the preview and
// the pixel fallback produce the same look.
function opMatrix({ fn, value: v }: Op): ColorMatrix {
    switch (fn) {
        case 'brightness':
            return [v, 0, 0, 0, 0, 0, v, 0, 0, 0, 0, 0, v, 0, 0, 0, 0, 0, 1, 0];
        case 'contrast': {
            const off = (1 - v) * 127.5;
            return [v, 0, 0, 0, off, 0, v, 0, 0, off, 0, 0, v, 0, off, 0, 0, 0, 1, 0];
        }
        case 'saturate':
            return [
                0.213 + 0.787 * v, 0.715 - 0.715 * v, 0.072 - 0.072 * v, 0, 0,
                0.213 - 0.213 * v, 0.715 + 0.285 * v, 0.072 - 0.072 * v, 0, 0,
                0.213 - 0.213 * v, 0.715 - 0.715 * v, 0.072 + 0.928 * v, 0, 0,
                0, 0, 0, 1, 0,
            ];
        case 'grayscale': {
            const k = 1 - v; // v=1 => full grayscale
            return [
                0.2126 + 0.7874 * k, 0.7152 - 0.7152 * k, 0.0722 - 0.0722 * k, 0, 0,
                0.2126 - 0.2126 * k, 0.7152 + 0.2848 * k, 0.0722 - 0.0722 * k, 0, 0,
                0.2126 - 0.2126 * k, 0.7152 - 0.7152 * k, 0.0722 + 0.9278 * k, 0, 0,
                0, 0, 0, 1, 0,
            ];
        }
        case 'sepia': {
            const k = 1 - v;
            return [
                0.393 + 0.607 * k, 0.769 - 0.769 * k, 0.189 - 0.189 * k, 0, 0,
                0.349 - 0.349 * k, 0.686 + 0.314 * k, 0.168 - 0.168 * k, 0, 0,
                0.272 - 0.272 * k, 0.534 - 0.534 * k, 0.131 + 0.869 * k, 0, 0,
                0, 0, 0, 1, 0,
            ];
        }
        case 'hue-rotate': {
            const a = (v * Math.PI) / 180;
            const c = Math.cos(a);
            const s = Math.sin(a);
            return [
                0.213 + c * 0.787 - s * 0.213, 0.715 - c * 0.715 - s * 0.715, 0.072 - c * 0.072 + s * 0.928, 0, 0,
                0.213 - c * 0.213 + s * 0.143, 0.715 + c * 0.285 + s * 0.140, 0.072 - c * 0.072 - s * 0.283, 0, 0,
                0.213 - c * 0.213 - s * 0.787, 0.715 - c * 0.715 + s * 0.715, 0.072 + c * 0.928 + s * 0.072, 0, 0,
                0, 0, 0, 1, 0,
            ];
        }
        default:
            return IDENTITY;
    }
}

// Multiply two matrices in homogeneous 5x5 form (rows [c0..c3, offset, and an
// implicit [0,0,0,0,1] row]). Returns a 4x5.
function multiply(a: ColorMatrix, b: ColorMatrix): ColorMatrix {
    const rowsA = [a.slice(0, 5), a.slice(5, 10), a.slice(10, 15), a.slice(15, 20), [0, 0, 0, 0, 1]];
    const rowsB = [b.slice(0, 5), b.slice(5, 10), b.slice(10, 15), b.slice(15, 20), [0, 0, 0, 0, 1]];
    const out: number[] = [];
    for (let r = 0; r < 4; r++) {
        for (let c = 0; c < 5; c++) {
            let sum = 0;
            for (let k = 0; k < 5; k++) sum += rowsA[r][k] * rowsB[k][c];
            out.push(sum);
        }
    }
    return out;
}

// CSS applies ops left-to-right, so the combined matrix is Mn·…·M1.
function composeMatrix(ops: Op[]): ColorMatrix {
    return ops.reduce((acc, op) => multiply(opMatrix(op), acc), IDENTITY);
}

function cssFor(ops: Op[]): string {
    if (ops.length === 0) return 'none';
    return ops.map(({ fn, value }) => `${fn}(${value}${fn === 'hue-rotate' ? 'deg' : ''})`).join(' ');
}

export type Filter = { key: string; label: string; css: string; matrix: ColorMatrix };

const DEFINITIONS: Array<{ key: string; label: string; ops: Op[] }> = [
    { key: 'none', label: 'None', ops: [] },
    { key: 'mono', label: 'Noir', ops: [{ fn: 'grayscale', value: 1 }, { fn: 'contrast', value: 1.08 }, { fn: 'brightness', value: 1.05 }] },
    { key: 'warm', label: 'Golden', ops: [{ fn: 'sepia', value: 0.3 }, { fn: 'saturate', value: 1.2 }, { fn: 'brightness', value: 1.06 }, { fn: 'contrast', value: 1.02 }] },
    { key: 'cool', label: 'Cool', ops: [{ fn: 'saturate', value: 1.05 }, { fn: 'brightness', value: 1.04 }, { fn: 'contrast', value: 1.06 }, { fn: 'hue-rotate', value: -10 }] },
    { key: 'vivid', label: 'Pop', ops: [{ fn: 'saturate', value: 1.45 }, { fn: 'contrast', value: 1.12 }, { fn: 'brightness', value: 1.03 }] },
    { key: 'film', label: 'Film', ops: [{ fn: 'contrast', value: 0.88 }, { fn: 'brightness', value: 1.08 }, { fn: 'saturate', value: 0.92 }, { fn: 'sepia', value: 0.18 }] },
];

export const FILTERS: ReadonlyArray<Filter> = DEFINITIONS.map(({ key, label, ops }) => ({
    key,
    label,
    css: cssFor(ops),
    matrix: composeMatrix(ops),
}));

export function filterFor(key: string): Filter {
    return FILTERS.find((f) => f.key === key) ?? FILTERS[0];
}

// Applies a colour matrix in place — the iOS capture path (getImageData data).
export function applyColorMatrix(data: Uint8ClampedArray, m: ColorMatrix): void {
    for (let i = 0; i < data.length; i += 4) {
        const r = data[i];
        const g = data[i + 1];
        const b = data[i + 2];
        const a = data[i + 3];
        data[i] = m[0] * r + m[1] * g + m[2] * b + m[3] * a + m[4];
        data[i + 1] = m[5] * r + m[6] * g + m[7] * b + m[8] * a + m[9];
        data[i + 2] = m[10] * r + m[11] * g + m[12] * b + m[13] * a + m[14];
        data[i + 3] = m[15] * r + m[16] * g + m[17] * b + m[18] * a + m[19];
    }
}
