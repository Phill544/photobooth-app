// Browser glue for the device camera. Deliberately dumb — no logic worth
// unit testing lives here. iOS Safari rules the constraints: one live
// stream at a time, loose "ideal" sizes, frame grabs via drawImage.

export async function startCamera(video: HTMLVideoElement): Promise<MediaStream> {
    const stream = await navigator.mediaDevices.getUserMedia({
        video: { facingMode: 'user', width: { ideal: 1280 }, height: { ideal: 720 } },
        audio: false,
    });
    video.srcObject = stream;
    await video.play();
    return stream;
}

export function grabFrame(video: HTMLVideoElement, mirror: boolean): Promise<Blob> {
    const canvas = document.createElement('canvas');
    canvas.width = video.videoWidth;
    canvas.height = video.videoHeight;

    const ctx = canvas.getContext('2d')!;
    if (mirror) {
        ctx.translate(canvas.width, 0);
        ctx.scale(-1, 1);
    }
    ctx.drawImage(video, 0, 0);

    return new Promise((resolve, reject) => {
        canvas.toBlob(
            (blob) => (blob ? resolve(blob) : reject(new Error('toBlob returned null'))),
            'image/jpeg',
            0.85,
        );
    });
}
