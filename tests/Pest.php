<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->beforeEach(fn () => $this->withoutVite())
    ->in('Feature');

function uploadPhoto(string $code, array $overrides = []): TestResponse
{
    return test()->post("/e/$code/photos", array_merge([
        'photo' => UploadedFile::fake()->image('shot.jpg', 1080, 810),
        'kind' => 'original',
        'group' => fake()->uuid(),
        'slot' => 1,
    ], $overrides));
}

// The entry names inside a built archive. Reading the real zip back is the only
// way to know the folders are what the host will actually see on their desktop.
function zipEntries(string $path): array
{
    $zip = new ZipArchive;
    $zip->open($path);
    $names = array_map(fn (int $i) => $zip->getNameIndex($i), range(0, $zip->numFiles - 1));
    $zip->close();

    return $names;
}

// An upload as the booth itself sends it: multipart, and asking for JSON. The
// Accept header is what turns a rejected upload into a 422 the client can read
// instead of an HTML validation redirect.
function boothUpload(string $code, array $overrides = []): TestResponse
{
    return test()->withHeader('Accept', 'application/json')
        ->post("/e/$code/photos", array_merge([
            'photo' => UploadedFile::fake()->image('shot.jpg', 1080, 810),
            'kind' => 'original',
            'group' => fake()->uuid(),
            'slot' => 1,
        ], $overrides));
}
