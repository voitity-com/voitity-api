<?php

namespace App\Mail;

use App\Models\BusinessLead;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class BusinessLeadNotification extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public readonly BusinessLead $lead) {}

    public function envelope(): Envelope
    {
        $settings = $this->lead->business->settings;

        return new Envelope(
            from: $settings?->sender_email ? new Address($settings->sender_email, $settings->sender_name ?: $this->lead->business->name) : null,
            replyTo: $settings?->reply_to_email ? [new Address($settings->reply_to_email)] : [],
            subject: 'Nuevo lead para '.$this->lead->business->name,
        );
    }

    public function content(): Content
    {
        return new Content(view: 'emails.business-lead');
    }
}
