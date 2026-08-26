type UploadOptions = {
    kind: 'original' | 'strip';
    group: string;
    slot: number;
};

// Why an upload was refused, in the four flavours the guest experience cares
// about: the booth shut mid-session, the event is over its rate limit (and the
// server says when to come back), the file itself was rejected, or the network
// simply isn't there.
export type UploadFailureKind = 'closed' | 'throttled' | 'rejected' | 'network';

export class UploadError extends Error {
    constructor(
        readonly kind: UploadFailureKind,
        readonly status: number,
        readonly retryAfterMs: number | null,
    ) {
        super(`upload failed: ${kind} (${status})`);
        this.name = 'UploadError';
    }
}

export function buildPhotoForm(photo: Blob, options: UploadOptions): FormData {
    const form = new FormData();
    form.append('photo', photo, `${options.kind}-${options.slot}.jpg`);
    form.append('kind', options.kind);
    form.append('group', options.group);
    form.append('slot', String(options.slot));
    return form;
}

export function uploadFailure(status: number, retryAfter: string | null): UploadError {
    if (status === 410) return new UploadError('closed', status, null);
    if (status === 422) return new UploadError('rejected', status, null);
    if (status === 429) return new UploadError('throttled', status, retryAfterMs(retryAfter));
    return new UploadError('network', status, null);
}

// A retry can't reopen a closed booth or make a rejected file acceptable; a
// throttle and a flaky access point both clear on their own.
export function isTerminal(error: UploadError): boolean {
    return error.kind === 'closed' || error.kind === 'rejected';
}

// Laravel's throttle sends Retry-After as whole seconds. The HTTP-date form is
// legal too, and unused here — an unreadable value just means "no stated wait".
function retryAfterMs(header: string | null): number | null {
    const seconds = Number(header);
    if (!header || !Number.isFinite(seconds)) return null;
    return seconds * 1000;
}

export async function uploadPhoto(eventCode: string, photo: Blob, options: UploadOptions): Promise<number> {
    const csrf = document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')!.content;
    let response: Response;
    try {
        response = await fetch(`/e/${eventCode}/photos`, {
            method: 'POST',
            headers: { 'X-CSRF-TOKEN': csrf, Accept: 'application/json' },
            body: buildPhotoForm(photo, options),
        });
    } catch {
        // fetch only rejects when the request never got an answer — status 0 is
        // the honest reading of "we never heard back".
        throw uploadFailure(0, null);
    }

    if (!response.ok) {
        throw uploadFailure(response.status, response.headers.get('Retry-After'));
    }

    const { id } = await response.json();
    return id;
}
