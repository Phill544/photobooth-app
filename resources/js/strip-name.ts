// What a guest's own copy of a strip is called.
//
// Every strip from one event used to arrive as the same `{event}-strip.jpg`, so
// the second one a guest saved had a phone asking whether to replace a photo
// that was not the one already on it — and that prompt sits between a guest and
// the only copy of their strip they will ever hold. Stamping the name with the
// moment the strip was composed makes each one its own file; saving the *same*
// strip twice still collides, which is correct, because it is the same file.
//
// The shape is the one HANDOVER item 34 settled for all three places this app
// names a file: fields joined by underscores, hyphens inside them, every field
// zero-padded and biggest unit first, so a plain sort of a downloads folder is
// chronological and one session's files stay together.
//
// The clock is the guest's own phone, which is the right one: it matches the
// timestamps on the rest of their camera roll. The server's two naming jobs (the
// album's Content-Disposition and the zip) have no such clock to read, which is
// what item 34(2) is for — it sends a per-photo `taken_at` and UTC offset up with
// each upload, rather than giving an event one timezone of its own.
//
// `stem` is an argument rather than the event name read straight from the page:
// item 34's first commit replaces it with `Event::fileStem()`, and when it does,
// only the caller moves.

const pad = (value: number) => String(value).padStart(2, '0');

export function stripFilename(stem: string, at: Date): string {
    const date = `${at.getFullYear()}-${pad(at.getMonth() + 1)}-${pad(at.getDate())}`;
    const time = `${pad(at.getHours())}-${pad(at.getMinutes())}-${pad(at.getSeconds())}`;

    return `${stem}_${date}_${time}_strip.jpg`;
}
