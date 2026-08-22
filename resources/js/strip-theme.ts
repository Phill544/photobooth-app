// Colour themes for the composed strip. The owner picks one per event; the
// keys/labels are mirrored in Event::STRIP_THEMES (PHP) for the form and
// validation, while the hex values live here because the canvas needs them.

export type StripColours = { background: string; textColor: string };

export const STRIP_THEMES: ReadonlyArray<{ key: string; label: string } & StripColours> = [
    { key: 'midnight', label: 'Midnight', background: '#111111', textColor: '#FFFFFF' },
    { key: 'blush', label: 'Blush', background: '#F3D3D8', textColor: '#4A2029' },
    { key: 'forest', label: 'Forest', background: '#1E3A2F', textColor: '#F0EAD6' },
    { key: 'sand', label: 'Sand', background: '#EDE4D3', textColor: '#3A3226' },
    { key: 'champagne', label: 'Champagne', background: '#14140F', textColor: '#E7B15E' },
];

export function stripTheme(key: string): StripColours {
    const found = STRIP_THEMES.find((t) => t.key === key) ?? STRIP_THEMES[0];
    return { background: found.background, textColor: found.textColor };
}
