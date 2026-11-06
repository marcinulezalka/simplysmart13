{{-- Mail testowy strefy: szablon, odbiorca i kod. --}}
<html><body data-template="security-code" data-zone="{{ $mailZone->name }}"><p>{{ $accountName }}</p><p data-code>{{ $code }}</p></body></html>
