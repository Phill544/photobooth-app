type UploadOptions = {
    kind: 'original' | 'strip';
    group: string;
    slot: number;
};

export function buildPhotoForm(photo: Blob, options: UploadOptions): FormData {
    const form = new FormData();
    form.append('photo', photo, `${options.kind}-${options.slot}.jpg`);
    form.append('kind', options.kind);
    form.append('group', options.group);
    form.append('slot', String(options.slot));
    return form;
}

export async function uploadPhoto(eventCode: string, photo: Blob, options: UploadOptions): Promise<number> {
    const csrf = document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')!.content;
    const response = await fetch(`/e/${eventCode}/photos`, {
        method: 'POST',
        headers: { 'X-CSRF-TOKEN': csrf, Accept: 'application/json' },
        body: buildPhotoForm(photo, options),
    });

    if (!response.ok) {
        throw new Error(`Upload failed with status ${response.status}`);
    }

    const { id } = await response.json();
    return id;
}
