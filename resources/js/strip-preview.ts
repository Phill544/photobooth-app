// Live strip preview for the create and edit forms: redraws a faithful strip
// (same compose modules the booth uses) as the owner changes layout, colour,
// caption, and logo, with placeholder cells standing in for the guests' photos.
// It also paints the layout and colour swatches from those same registries, so
// the pickers and the canvas can never disagree about a shape or a hue.
// Binds to any [data-strip-form] on the page and its [data-strip-preview] img.

import { composeStrip } from './strip-compose';
import { STRIP_THEMES, stripTheme } from './strip-theme';
import { TEMPLATES, templateFor } from './templates';

const form = document.querySelector<HTMLFormElement>('[data-strip-form]');
const preview = document.querySelector<HTMLImageElement>('[data-strip-preview]');

if (form && preview) {
    const nameInput = form.querySelector<HTMLInputElement>('[name="name"]')!;
    const captionInput = form.querySelector<HTMLInputElement>('[name="caption"]')!;
    const logoInput = form.querySelector<HTMLInputElement>('[name="logo"]');
    const removeLogo = form.querySelector<HTMLInputElement>('[name="remove_logo"]');
    const summary = document.querySelector<HTMLElement>('[data-strip-summary]');

    // Both pickers are radio groups, so the checked input is the current choice.
    const chosen = (name: string) => form.querySelector<HTMLInputElement>(`[name="${name}"]:checked`)?.value ?? '';
    const labelFor = (list: ReadonlyArray<{ key: string; label: string }>, key: string) =>
        (list.find((item) => item.key === key) ?? list[0]).label;

    // Draw the swatches: a mini strip per layout, a filled disc per colour.
    for (const swatch of form.querySelectorAll<HTMLElement>('[data-layout]')) {
        const { cellCount, columns } = templateFor(swatch.dataset.layout!);
        swatch.classList.toggle('is-grid', columns > 1);
        for (let i = 0; i < cellCount; i++) swatch.appendChild(document.createElement('i'));
    }
    for (const swatch of form.querySelectorAll<HTMLElement>('[data-theme]')) {
        swatch.style.background = stripTheme(swatch.dataset.theme!).background;
    }

    let logo: HTMLImageElement | null = null;

    // Load a logo (from a picked File or an existing URL) then repaint the preview.
    const loadLogo = (src: string | null) => {
        if (!src) { logo = null; render(); return; }
        const img = new Image();
        img.onload = () => { logo = img; render(); };
        img.src = src;
    };

    const placeholderShot = (width: number, height: number, index: number): HTMLCanvasElement => {
        const canvas = document.createElement('canvas');
        canvas.width = width;
        canvas.height = height;
        const ctx = canvas.getContext('2d')!;
        ctx.fillStyle = '#8f8a82';
        ctx.fillRect(0, 0, width, height);
        ctx.fillStyle = 'rgba(255, 255, 255, 0.65)';
        ctx.font = `${Math.round(height * 0.45)}px system-ui, sans-serif`;
        ctx.textAlign = 'center';
        ctx.textBaseline = 'middle';
        ctx.fillText(String(index + 1), width / 2, height / 2);
        return canvas;
    };

    const render = () => {
        const templateKey = chosen('template');
        const themeKey = chosen('theme');
        const template = templateFor(templateKey);
        const shots = Array.from(
            { length: template.cellCount },
            (_, i) => placeholderShot(template.cellWidth, template.cellHeight, i),
        );
        const branding = {
            ...stripTheme(themeKey),
            caption: captionInput.value.trim() || nameInput.value.trim() || 'Your event',
            logo,
        };
        preview.src = composeStrip(shots, template, branding).toDataURL('image/jpeg', 0.85);
        if (summary) summary.textContent = `${labelFor(TEMPLATES, templateKey)} · ${labelFor(STRIP_THEMES, themeKey)}`;
    };

    for (const field of [nameInput, captionInput]) {
        field.addEventListener('input', render);
    }
    for (const radio of form.querySelectorAll('[name="template"], [name="theme"]')) {
        radio.addEventListener('change', render);
    }

    // Resolve the logo the way the server will: a picked file wins, else the
    // existing logo, unless removal is ticked. Revoke the previous object URL
    // so repeated picks don't leak, and keep the preview matching what submits.
    let lastObjectUrl: string | null = null;
    const refreshLogo = () => {
        if (lastObjectUrl) { URL.revokeObjectURL(lastObjectUrl); lastObjectUrl = null; }
        if (removeLogo?.checked) { loadLogo(null); return; }
        const file = logoInput?.files?.[0];
        if (file) { lastObjectUrl = URL.createObjectURL(file); loadLogo(lastObjectUrl); return; }
        loadLogo(form.dataset.logoUrl || null);
    };

    logoInput?.addEventListener('change', () => {
        if (removeLogo) removeLogo.checked = false; // picking a logo cancels a pending removal
        refreshLogo();
    });
    removeLogo?.addEventListener('change', refreshLogo);

    refreshLogo(); // seed (also renders once the logo, if any, loads)
}
