// Live strip preview for the create and edit forms: redraws a faithful strip
// (same compose modules the booth uses) as the owner changes layout, colour,
// caption, logo, and background, with placeholder cells standing in for the
// guests' photos. It also paints the layout and colour swatches from those same
// registries, so the pickers and the canvas can never disagree about a shape or
// a hue. Binds to any [data-strip-form] on the page and its [data-strip-preview] img.

import { centeredCrop } from './crop';
import { composeStrip } from './strip-compose';
import { stripSize } from './strip-layout';
import { STRIP_THEMES, stripTheme } from './strip-theme';
import { TEMPLATES, templateFor, type StripTemplate } from './templates';

const form = document.querySelector<HTMLFormElement>('[data-strip-form]');
const preview = document.querySelector<HTMLImageElement>('[data-strip-preview]');

if (form && preview) {
    const nameInput = form.querySelector<HTMLInputElement>('[name="name"]')!;
    const captionInput = form.querySelector<HTMLInputElement>('[name="caption"]')!;
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
    let backgroundSource: HTMLImageElement | null = null;
    let backgroundScaled: HTMLCanvasElement | null = null;

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

    // A picked file is whatever came off a camera roll — the server's dimension
    // rule has not seen it yet, so it may be 24 megapixels — and render() runs
    // on every keystroke in the name and caption fields. Rescaling that per
    // keypress is what makes a form feel broken, so the artwork is cropped into
    // a strip-sized canvas once, whenever it or the layout changes, and the
    // repaint blits that. Same trick as placeholderShot above.
    const rescaleBackground = (template: StripTemplate) => {
        if (!backgroundSource) { backgroundScaled = null; return; }

        const { width, height } = stripSize(template);
        const canvas = document.createElement('canvas');
        canvas.width = width;
        canvas.height = height;

        const crop = centeredCrop(backgroundSource.width, backgroundSource.height, width / height);
        canvas.getContext('2d')!
            .drawImage(backgroundSource, crop.x, crop.y, crop.width, crop.height, 0, 0, width, height);
        backgroundScaled = canvas;
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
            backgroundImage: backgroundScaled,
        };
        preview.src = composeStrip(shots, template, branding).toDataURL('image/jpeg', 0.85);
        if (summary) summary.textContent = `${labelFor(TEMPLATES, templateKey)} · ${labelFor(STRIP_THEMES, themeKey)}`;
    };

    // One image field: its file input, its "remove" tick, and whatever is
    // already saved. Resolves the source exactly the way the server will — a
    // picked file wins, a ticked removal beats both — and keeps its own object
    // URL, so the logo and the background can never revoke each other's.
    const pickedImage = (
        name: string,
        existingUrl: string | undefined,
        onChange: (image: HTMLImageElement | null) => void,
    ) => {
        const input = form.querySelector<HTMLInputElement>(`[name="${name}"]`);
        const removeBox = form.querySelector<HTMLInputElement>(`[name="remove_${name}"]`);
        let objectUrl: string | null = null;

        const load = (src: string | null) => {
            if (!src) { onChange(null); return; }
            const image = new Image();
            image.onload = () => onChange(image);
            image.onerror = () => onChange(null);
            image.src = src;
        };

        const refresh = () => {
            if (objectUrl) { URL.revokeObjectURL(objectUrl); objectUrl = null; }
            if (removeBox?.checked) { load(null); return; }
            const file = input?.files?.[0];
            if (file) { objectUrl = URL.createObjectURL(file); load(objectUrl); return; }
            load(existingUrl || null);
        };

        input?.addEventListener('change', () => {
            if (removeBox) removeBox.checked = false; // picking cancels a pending removal
            refresh();
        });
        removeBox?.addEventListener('change', refresh);

        refresh(); // seed (also renders once the image, if any, loads)
    };

    for (const field of [nameInput, captionInput]) {
        field.addEventListener('input', render);
    }
    for (const radio of form.querySelectorAll('[name="template"], [name="theme"]')) {
        radio.addEventListener('change', () => {
            // The strip's size changes with the layout, so the artwork has to be
            // re-cropped to it before the repaint that shows the new shape.
            rescaleBackground(templateFor(chosen('template')));
            render();
        });
    }

    pickedImage('logo', form.dataset.logoUrl, (image) => { logo = image; render(); });
    pickedImage('background', form.dataset.backgroundUrl, (image) => {
        backgroundSource = image;
        rescaleBackground(templateFor(chosen('template')));
        render();
    });
}
