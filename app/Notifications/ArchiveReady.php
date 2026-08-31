<?php

namespace App\Notifications;

use App\Models\Archive;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class ArchiveReady extends Notification
{
    public function __construct(public Archive $archive) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $event = $this->archive->event;

        return (new MailMessage)
            ->subject("Your {$event->name} photos are ready")
            ->greeting('Quikbooth')
            ->line("Everything from {$event->name} is in one file: {$this->archive->strip_count} "
                .str('strip')->plural($this->archive->strip_count).' in strips/ and '
                ."{$this->archive->photo_count} ".str('photo')->plural($this->archive->photo_count)
                .' in photos/.')
            // The size is here because this gets opened on a phone as often as a
            // desktop, and a host should know before they tap.
            ->line("It is {$this->archive->size()}, so a desktop and a decent connection are kinder to it than a phone.")
            ->action('Download everything', $this->archive->downloadUrl())
            ->line('The link works until '.$this->archive->expires_at->format('j M Y')
                .', then the file is deleted. Ask again any time for a fresh one.')
            ->salutation('— Quikbooth');
    }
}
