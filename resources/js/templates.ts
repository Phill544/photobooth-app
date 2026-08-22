// A strip template defines the whole photo strip — crucially, how many photos
// it holds (cellCount) and how they lay out (columns). The shot count is never
// hard-coded anywhere else; the flow reads it from the event's chosen template.

export type StripTemplate = {
    cellCount: number;
    columns: number;
    cellWidth: number;
    cellHeight: number;
    padding: number;
    footerHeight: number;
};

// Colours are not here — they come from the event's strip-theme (see strip-theme.ts).
const base = {
    cellWidth: 600,
    cellHeight: 450,
    padding: 24,
    footerHeight: 96,
} as const;

// The owner picks one of these when creating an event; `key` is what we store.
export const TEMPLATES: ReadonlyArray<{ key: string; label: string; template: StripTemplate }> = [
    { key: 'classic', label: 'Classic strip · 3 photos', template: { ...base, cellCount: 3, columns: 1 } },
    { key: 'quad', label: 'Tall strip · 4 photos', template: { ...base, cellCount: 4, columns: 1 } },
    { key: 'grid', label: 'Grid · 2×2', template: { ...base, cellCount: 4, columns: 2 } },
    { key: 'single', label: 'Single shot', template: { ...base, cellCount: 1, columns: 1 } },
];

export const DEFAULT_TEMPLATE_KEY = 'classic';

export function templateFor(key: string): StripTemplate {
    const found = TEMPLATES.find((t) => t.key === key) ?? TEMPLATES[0];
    return found.template;
}
