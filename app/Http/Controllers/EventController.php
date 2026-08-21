<?php

namespace App\Http\Controllers;

use App\Models\Event;

class EventController extends Controller
{
    public function capture(Event $event)
    {
        return view('capture', ['event' => $event]);
    }

    public function gallery(Event $event)
    {
        return view('gallery', [
            'event' => $event,
            'photos' => $event->photos()->latest('id')->get(),
        ]);
    }
}
