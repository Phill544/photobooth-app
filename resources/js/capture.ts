import { grabFrame, startCamera } from './camera';
import { uploadPhoto } from './upload';

const eventCode = document.body.dataset.eventCode!;
const video = document.querySelector<HTMLVideoElement>('#preview')!;

const screens = {
    start: document.querySelector<HTMLElement>('#start-screen')!,
    camera: document.querySelector<HTMLElement>('#camera-screen')!,
    uploading: document.querySelector<HTMLElement>('#uploading-screen')!,
    done: document.querySelector<HTMLElement>('#done-screen')!,
};

function show(name: keyof typeof screens) {
    for (const [key, screen] of Object.entries(screens)) {
        screen.hidden = key !== name;
    }
}

document.querySelector('#start')!.addEventListener('click', async () => {
    await startCamera(video);
    show('camera');
});

document.querySelector('#shutter')!.addEventListener('click', async () => {
    const photo = await grabFrame(video, true);
    show('uploading');
    await uploadPhoto(eventCode, photo, { kind: 'original', group: crypto.randomUUID(), slot: 1 });
    show('done');
});

document.querySelector('#again')!.addEventListener('click', () => show('camera'));
