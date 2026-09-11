<?php

namespace Database\Seeders;

use App\Models\Event;
use App\Models\Photo;
use App\Support\Thumbnail;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

// Fills a seeded event with the photos a night of it would have left behind —
// real JPEGs on the disk, a derivative beside each one, and rows that point at
// them, all written exactly where PhotoController::store and GenerateThumbnail
// put theirs. The point is loading times: an album's cost is its request count,
// so an event only tells you anything if its photos are really there.
trait SeedsAlbums
{
    // Mirrors resources/js/templates.ts + strip-layout.ts. Seed data only —
    // nothing in production reads it — but matching means a seeded strip
    // resizes and lays out on the wall exactly like a real one.
    private const SHAPES = [
        'classic' => ['shots' => 3, 'strip' => [1008, 2544]],
        'quad' => ['shots' => 4, 'strip' => [1008, 3288]],
        'grid' => ['shots' => 4, 'strip' => [1992, 1800]],
        'single' => ['shots' => 1, 'strip' => [1008, 1056]],
    ];

    // The cell and the mat around it, from the same `base` — kept here rather
    // than spelled into strip() so a resize is these two numbers and the table
    // above, not four more hidden in an imagecopy call.
    private const CELL = [960, 720];

    private const PADDING = 24;

    // Backgrounds from resources/js/strip-theme.ts, same deal.
    private const MATS = [
        'midnight' => [0x11, 0x11, 0x11],
        'blush' => [0xF3, 0xD3, 0xD8],
        'forest' => [0x1E, 0x3A, 0x2F],
        'sand' => [0xED, 0xE4, 0xD3],
        'champagne' => [0x14, 0x14, 0x0F],
    ];

    // A guest's camera gives whatever it gives; this is a quarter of it, which
    // keeps a 4000-photo event to a few hundred MB and still makes the browser
    // fetch something.
    private const SHOT = [640, 480];

    // Distinct images, encoded once and written many times: a 4000-photo event
    // is 8000 files, and drawing each one would turn a seed into a coffee break.
    private const VARIANTS = 12;

    private array $pool = [];

    private function fillAlbum(Event $event, int $sessions, Carbon $openedAt, float $hours): void
    {
        if ($event->photos()->exists()) {
            $this->command?->line("  {$event->code} already has photos — leaving it alone.");

            return;
        }

        $shape = self::SHAPES[$event->template];
        $bar = $sessions > 100 ? $this->command?->getOutput()->createProgressBar($sessions) : null;

        // Oldest first, because ids are what order an album: inserting a night
        // backwards would make the newest strips the ones at the bottom.
        $rows = [];
        foreach (range(1, $sessions) as $index) {
            $at = $openedAt->copy()->addSeconds((int) ($index / $sessions * $hours * 3600));
            $group = Str::uuid()->toString();

            $rows[] = $this->row($event, $at, $group, 'strip', 0, $index);
            foreach (range(1, $shape['shots']) as $slot) {
                $rows[] = $this->row($event, $at, $group, 'original', $slot, $index + $slot);
            }

            // Chunked: a busy night is thousands of rows, and one insert each
            // is one SQLite transaction each.
            if (count($rows) >= 500) {
                Photo::insert($rows);
                $rows = [];
            }
            $bar?->advance();
        }

        Photo::insert($rows);
        $bar?->finish();

        // `Photo::insert` writes straight past the model, so the upload path that
        // normally starts the retention window never runs here.
        //
        // Counted from the seed, not from `$openedAt`. The nights are fixed
        // literals in the past — that is what makes a seeded album read like one
        // that already happened — so anchoring the window to them would hand
        // every fixture a date that has already gone: NEWYRS opens on New Year's
        // Eve 2025, and 90 days past that is expired *and* through its grace, so
        // the 4000-photo album the paging work is measured against would arrive
        // as an expired page and be one `photobooth:sweep-expired` from deletion.
        // A fixture has to be live to be worth looking at.
        //
        // Left alone where the seeder set a date of its own: LAPSED and SWEPT2
        // are their windows, and they are the states nothing else can produce.
        if ($event->photos_expire_at === null) {
            $event->update(['photos_expire_at' => now()->addDays(Event::RETENTION_DAYS)]);
        }

        $this->command?->line("  {$event->code}: {$sessions} sessions.");
    }

    // One photo: its bytes, its derivative, and the row that names both.
    private function row(Event $event, Carbon $at, string $group, string $kind, int $slot, int $variant): array
    {
        [$image, $thumb] = $this->variant($event, $kind, $variant);

        $path = "events/{$event->id}/".Str::random(40).'.jpg';
        Storage::put($path, $image);
        Storage::put($thumbPath = dirname($path).'/thumbs/'.basename($path), $thumb);

        return [
            'event_id' => $event->id,
            'kind' => $kind,
            'group_uuid' => $group,
            'slot' => $slot,
            'path' => $path,
            'thumb_path' => $thumbPath,
            'created_at' => $at,
            'updated_at' => $at,
        ];
    }

    // The pool. Thumbnails go through the app's own resizer, so a seeded album
    // grid is serving exactly what the queue would have written.
    private function variant(Event $event, string $kind, int $variant): array
    {
        $key = $kind === 'strip' ? "{$event->template}:{$event->theme}" : 'shot';
        $slot = $variant % self::VARIANTS;

        if (! isset($this->pool[$key][$slot])) {
            $image = $kind === 'strip'
                ? $this->strip($event, $slot)
                : $this->shot(self::SHOT[0], self::SHOT[1], $slot);

            $this->pool[$key][$slot] = [$image, Thumbnail::fromImage($image)];
        }

        return $this->pool[$key][$slot];
    }

    // A composed strip: the event's mat colour, its cells, and a footer where
    // the caption sits.
    private function strip(Event $event, int $variant): string
    {
        $shape = self::SHAPES[$event->template];
        [$width, $height] = $shape['strip'];
        $columns = $event->template === 'grid' ? 2 : 1;

        $strip = imagecreatetruecolor($width, $height);
        imagefill($strip, 0, 0, imagecolorallocate($strip, ...self::MATS[$event->theme]));

        [$cellWidth, $cellHeight] = self::CELL;

        foreach (range(0, $shape['shots'] - 1) as $cell) {
            // imagecopy, not imagecopyresampled: the frame is drawn at the cell's
            // own size, so there is nothing to scale — and a source that is not
            // would land at its own size in the corner rather than complain.
            $frame = imagecreatefromstring($this->shot($cellWidth, $cellHeight, ($variant + $cell) % self::VARIANTS));
            imagecopy(
                $strip, $frame,
                self::PADDING + ($cell % $columns) * ($cellWidth + self::PADDING),
                self::PADDING + intdiv($cell, $columns) * ($cellHeight + self::PADDING),
                0, 0, $cellWidth, $cellHeight,
            );
            imagedestroy($frame);
        }

        return $this->jpeg($strip);
    }

    // A stand-in for a camera frame: a soft wash blown up out of a handful of
    // random pixels, a subject in the middle of it, and grain over the top. It
    // wants to be photographic in the only two ways that matter here — it has
    // to be tellable apart from its neighbours at thumbnail size, and it has to
    // give JPEG something to chew on. A flat fill encodes to a few hundred
    // bytes, and an album of those loads like nothing is in it.
    private function shot(int $width, int $height, int $variant): string
    {
        mt_srand($variant * 7919);

        // imagescale, not imagecopyresampled: a 40x upscale is past the point
        // where resampling averages anything, and the frames come out as
        // confetti rather than as colour.
        $seed = imagecreatetruecolor(16, 12);
        foreach (range(0, 15) as $x) {
            foreach (range(0, 11) as $y) {
                imagesetpixel($seed, $x, $y, imagecolorallocate($seed, mt_rand(30, 235), mt_rand(30, 225), mt_rand(40, 240)));
            }
        }
        $image = imagescale($seed, $width, $height, IMG_BILINEAR_FIXED);
        imagedestroy($seed);

        imagealphablending($image, true);
        $subject = imagecolorallocatealpha($image, mt_rand(0, 90), mt_rand(0, 90), mt_rand(0, 110), 40);
        imagefilledellipse($image, (int) ($width * mt_rand(35, 65) / 100), (int) ($height * .58), (int) ($width * .42), (int) ($height * .66), $subject);

        $grain = imagecolorallocatealpha($image, 128, 128, 128, 90);
        foreach (range(1, (int) ($width * $height / 14)) as $ignored) {
            imagesetpixel($image, mt_rand(0, $width - 1), mt_rand(0, $height - 1), $grain);
        }

        return $this->jpeg($image);
    }

    // Stand-in host artwork: something a host might plausibly have made in
    // Canva, so the seeded booth shows what a background actually does to a
    // strip. Drawn at the classic strip's own size — a real host would use the
    // layout guide for that — though compose covers whatever it is given.
    private function backgroundArt(int $width, int $height): string
    {
        $art = imagecreatetruecolor($width, $height);
        imagefill($art, 0, 0, imagecolorallocate($art, 24, 26, 46));

        $band = imagecolorallocate($art, 196, 152, 84);
        for ($y = -$width; $y < $height; $y += 96) {
            imagefilledpolygon($art, [0, $y, $width, $y + $width, $width, $y + $width + 32, 0, $y + 32], $band);
        }

        // An inner rule, so the mat reads as a frame rather than as wallpaper.
        imagesetthickness($art, 6);
        imagerectangle($art, 14, 14, $width - 15, $height - 15, imagecolorallocate($art, 240, 234, 214));

        return $this->jpeg($art);
    }

    private function jpeg(\GdImage $image): string
    {
        ob_start();
        imagejpeg($image, null, 74);
        imagedestroy($image);

        return ob_get_clean();
    }
}
