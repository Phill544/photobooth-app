<?php

namespace App\Notifications;

use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;

// The framework's reset notification, queued. Nothing else changes — the copy
// still comes from the ResetPassword::toMailUsing callback in AppServiceProvider,
// because the static it reads lives on the parent.
//
// Queued because a transport that refuses a message throws, and unqueued
// notifications send inside the request that triggered them: a rejected
// recipient took down the whole page. It also keeps the forgot-password form's
// single answer honest — only a real address ever reaches the transport, so a
// failure that surfaced to the caller would say which addresses have accounts.
// ShouldBeEncrypted because queueing this moves a live credential out of
// request memory and into a store. ResetPassword carries the RAW token, while
// password_reset_tokens deliberately keeps only its hash — so an unencrypted
// payload puts a working reset link in the jobs table, and on failure leaves it
// in failed_jobs and the Cloud Queues dashboard, readable and replayable.
class QueuedResetPassword extends ResetPassword implements ShouldBeEncrypted, ShouldQueue
{
    use Queueable;
}
