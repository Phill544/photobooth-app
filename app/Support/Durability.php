<?php

namespace App\Support;

class Durability
{
    // True when the disk the app would write to is the container's own
    // filesystem. A serverless host resets that on every deploy and gives each
    // replica its own, so a photo written there is already lost — silently, with
    // no error anywhere. That is how this app lost its first set of photos.
    public static function diskIsEphemeral(): bool
    {
        $disk = config('filesystems.default');

        return config("filesystems.disks.{$disk}.driver") === 'local'
            && ! app()->environment('local', 'testing');
    }
}
