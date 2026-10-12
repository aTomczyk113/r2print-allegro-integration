<x-mail::message>
# Dziękujemy za zakup!

Cześć,

Twoje zamówienie z Allegro obejmuje produkt, który możesz teraz samodzielnie zaprojektować na naszej stronie:

@foreach($items as $item)
- **{{ $item->product->name }}** — {{ $item->quantity }} szt.
@endforeach

Kliknij poniżej, aby przejść do kreatora:

<x-mail::button :url="$redeemUrl">
Zaprojektuj teraz
</x-mail::button>

Jeśli masz kilka różnych produktów do zaprojektowania, link przeprowadzi Cię przez wszystkie po kolei.

W razie pytań odpowiedz na tego maila lub skontaktuj się z nami.<br>
Zespół {{ config('app.name') }}
</x-mail::message>
