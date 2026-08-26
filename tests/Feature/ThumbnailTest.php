<?php

use App\Support\Thumbnail;
use Illuminate\Http\UploadedFile;

// Gallery grids show images a few hundred pixels wide; the originals are camera
// frames and tall composed strips. These are the rules for the derivative.

function jpegBytes(int $width, int $height): string
{
    return UploadedFile::fake()->image('shot.jpg', $width, $height)->get();
}

function imageSize(string $bytes): array
{
    $info = getimagesizefromstring($bytes);

    return [$info[0], $info[1]];
}

it('scales a camera frame down to the grid width, keeping its shape', function () {
    $thumb = Thumbnail::fromImage(jpegBytes(1080, 810));

    expect(imageSize($thumb))->toBe([Thumbnail::MAX_WIDTH, (int) round(Thumbnail::MAX_WIDTH * 810 / 1080)]);
});

it('scales a tall strip by width, however long it runs', function () {
    $thumb = Thumbnail::fromImage(jpegBytes(1200, 3600));

    expect(imageSize($thumb))->toBe([Thumbnail::MAX_WIDTH, Thumbnail::MAX_WIDTH * 3]);
});

it('never upscales an image that is already small', function () {
    $original = jpegBytes(240, 180);

    $thumb = Thumbnail::fromImage($original);

    expect(imageSize($thumb))->toBe([240, 180]);
});

it('is a fraction of the weight of the original', function () {
    $original = jpegBytes(1200, 3600);

    expect(strlen(Thumbnail::fromImage($original)))->toBeLessThan(strlen($original) / 2);
});

it('always writes a jpeg, whatever came in', function () {
    $png = UploadedFile::fake()->image('logo.png', 1000, 1000)->get();

    $thumb = Thumbnail::fromImage($png);

    expect(getimagesizefromstring($thumb)['mime'])->toBe('image/jpeg');
});
