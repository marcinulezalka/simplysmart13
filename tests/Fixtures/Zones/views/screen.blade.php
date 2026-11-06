{{-- Wspólny ekran testowy strefy: nazwa ekranu, strefa, komunikat i błędy formularza. --}}
<html><body data-screen="{{ $screen }}" data-zone="{{ $zone->name }}">
<h1>{{ $title }}</h1>
@if (session(\Simplysmart\Polygon\Http\StatusMessage::KEY))<p data-status>{{ session(\Simplysmart\Polygon\Http\StatusMessage::KEY) }}</p>@endif
@foreach ($errors->all() as $error)<p data-error>{{ $error }}</p>@endforeach
{{ $slot ?? '' }}
</body></html>
