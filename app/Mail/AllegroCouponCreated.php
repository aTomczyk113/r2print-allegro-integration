<?php

namespace App\Mail;

use App\Models\ProductCoupon;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class AllegroCouponCreated extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public ProductCoupon $coupon)
    {
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Zaprojektuj swój produkt — link w środku',
        );
    }

    public function content(): Content
    {
        $items = $this->coupon->items()->with('product')->get();

        return new Content(
            markdown: 'emails.allegro.coupon-created',
            with: [
                'coupon'    => $this->coupon,
                'items'     => $items,
                'redeemUrl' => route('allegro-code.redeem', $this->coupon->code),
            ],
        );
    }
}
