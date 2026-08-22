// Live strip preview for the create and edit forms: redraws a faithful strip
// (same compose modules the booth uses) as the owner changes layout, colour,
// and caption, with placeholder cells standing in for the guests' photos.
// Binds to any [data-strip-form] on the page and its [data-strip-preview] img.

import { composeStrip } from './strip-compose';
import { stripTheme } from './strip-theme';
import { templateFor } from './templates';

const form = document.querySelector<HTMLFormElement>('[data-strip-form]');
const preview = document.querySelector<HTMLImageElement>('[data-strip-preview]');

if (form && preview) {
    const nameInput = form.querySelector<HTMLInputElement>('[name="name"]')!;
    const templateSelect = form.querySelector<HTMLSelectElement>('[name="template"]')!;
    const themeSelect = form.querySelector<HTMLSelectElement>('[name="theme"]')!;
    const captionInput = form.querySelector<HTMLInputElement>('[name="caption"]')!;
    const logoInput = form.querySelector<HTMLInputElement>('[name="logo"]');
    const removeLogo = form.querySelector<HTMLInputElement>('[name="remove_logo"]');

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
        const template = templateFor(templateSelect.value);
        const shots = Array.from(
            { length: template.cellCount },
            (_, i) => placeholderShot(template.cellWidth, template.cellHeight, i),
        );
        const branding = {
            ...stripTheme(themeSelect.value),
            caption: captionInput.value.trim() || nameInput.value.trim() || 'Your event',
            logo,
        };
        preview.src = composeStrip(shots, template, branding).toDataURL('image/jpeg', 0.85);
    };

    for (const field of [nameInput, templateSelect, themeSelect, captionInput]) {
        field.addEventListener('input', render);
        field.addEventListener('change', render);
    }

    logoInput?.addEventListener('change', () => {
        const file = logoInput.files?.[0];
        if (removeLogo) removeLogo.checked = false; // picking a logo cancels a pending removal
        loadLogo(file ? URL.createObjectURL(file) : (form.dataset.logoUrl || null));
    });
    removeLogo?.addEventListener('change', () => {
        loadLogo(removeLogo.checked ? null : (form.dataset.logoUrl || null));
    });

    // Seed with the event's current logo on the edit form (data-logo-url).
    if (form.dataset.logoUrl) loadLogo(form.dataset.logoUrl);
    else render();
}
