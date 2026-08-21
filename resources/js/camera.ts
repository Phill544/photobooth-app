// Browser glue for the device camera. Deliberately dumb — the geometry
// lives in crop.ts where it's unit tested. iOS Safari rules the constraints:
// one live stream at a time, loose "ideal" sizes, frame grabs via drawImage.

import { centeredCrop } from './crop';

export async function startCamera(video: HTMLVideoElement): Promise<MediaStream> {
    const stream = await navigator.mediaDevices.getUserMedia({
        video: { facingMode: 'user', width: { ideal: 1280 }, height: { ideal: 720 } },
        audio: false,
    });
    video.srcObject = stream;
    await video.play();
    return stream;
}

export function onCameraLost(stream: MediaStream, handle: () => void): void {
    for (const track of stream.getVideoTracks()) {
        track.addEventListener('ended', handle);
    }
}

export function cameraIsLive(stream: MediaStream): boolean {
    return stream.getVideoTracks().some((track) => track.readyState === 'live');
}

// Grabs the current frame cropped to the target aspect, at the camera's
// native resolution. Mirrored to match the mirrored selfie preview.
export function grabFrame(video: HTMLVideoElement, mirror: boolean, targetAspect: number): HTMLCanvasElement {
    const crop = centeredCrop(video.videoWidth, video.videoHeight, targetAspect);

    const canvas = document.createElement('canvas');
    canvas.width = Math.round(crop.width);
    canvas.height = Math.round(crop.height);

    const ctx = canvas.getContext('2d')!;
    if (mirror) {
        ctx.translate(canvas.width, 0);
        ctx.scale(-1, 1);
    }
    ctx.drawImage(video, crop.x, crop.y, crop.width, crop.height, 0, 0, canvas.width, canvas.height);

    return canvas;
}

export function toJpegBlob(canvas: HTMLCanvasElement): Promise<Blob> {
    return new Promise((resolve, reject) => {
        canvas.toBlob(
            (blob) => (blob ? resolve(blob) : reject(new Error('toBlob returned null'))),
            'image/jpeg',
            0.85,
        );
    });
}
