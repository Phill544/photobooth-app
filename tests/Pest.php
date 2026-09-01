<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use Illuminate\Testing\TestResponse;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\AbstractTransport;
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

// A transport that rejects the way SES does when it will not take a recipient.
// This is the production failure of 2026-09-01 in a test: the mail throws from
// inside the request that was doing something else important.
function breakTheMailer(): void
{
    Mail::extend('exploding', fn () => new class extends AbstractTransport
    {
        protected function doSend(SentMessage $message): void
        {
            throw new TransportException('Email address is not verified.');
        }

        public function __toString(): string
        {
            return 'exploding';
        }
    });

    config(['mail.default' => 'exploding', 'mail.mailers.exploding' => ['transport' => 'exploding']]);
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
