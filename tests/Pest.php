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
