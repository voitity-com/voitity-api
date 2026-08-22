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
        return new Envelope(
            from: new Address(
                (string) config('mail.business_from.address'),
                (string) config('mail.business_from.name'),
            ),
            subject: $this->lead->business->name.' - New Lead',
        );
    }

    public function content(): Content
    {
        return new Content(view: 'emails.business-lead');
    }
}
