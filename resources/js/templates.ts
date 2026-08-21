// A strip template defines everything about a photo strip, including how
// many photos it holds — the shot count is never hard-coded anywhere else.

export type StripTemplate = {
    cellCount: number;
    cellWidth: number;
    cellHeight: number;
    padding: number;
    footerHeight: number;
    background: string;
    textColor: string;
};

export const classicStrip: StripTemplate = {
    cellCount: 3,
    cellWidth: 600,
    cellHeight: 450,
    padding: 24,
    footerHeight: 96,
    background: '#111111',
    textColor: '#ffffff',
};
