// A strip template defines everything about a photo strip, including how
// many photos it holds — the shot count is never hard-coded anywhere else.

export type StripTemplate = {
    name: string;
    cellCount: number;
    cellWidth: number;
    cellHeight: number;
    padding: number;
    footerHeight: number;
    background: string;
    textColor: string;
};

export const classicStrip: StripTemplate = {
    name: 'classic',
    cellCount: 3,
    cellWidth: 600,
    cellHeight: 450,
    padding: 24,
    footerHeight: 96,
    background: '#111111',
    textColor: '#ffffff',
};
