import type { Rect } from './strip-layout';

// Cameras hand back whatever resolution they feel like, so every frame is
// cropped to the template's cell aspect before it touches the strip.
export function centeredCrop(sourceWidth: number, sourceHeight: number, targetAspect: number): Rect {
    const sourceAspect = sourceWidth / sourceHeight;

    if (sourceAspect > targetAspect) {
        const width = sourceHeight * targetAspect;
        return { x: (sourceWidth - width) / 2, y: 0, width, height: sourceHeight };
    }

    const height = sourceWidth / targetAspect;
    return { x: 0, y: (sourceHeight - height) / 2, width: sourceWidth, height };
}
