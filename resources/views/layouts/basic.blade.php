{{-- Wireframe layoutu podstawowego: czysty podział strony na header (head + top-nav z lewym i prawym kontenerem), content (lewa 10% / środek 50% / prawa 40%) i footer (sitemap z copyrightami + scripts). Całość zajmuje wysokość okna bez scrollbara przeglądarki — treść przelewająca się przewija wewnątrz swojej kolumny. --}}
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="h-full">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="description" content="@yield('description')">
    <title>@yield('title', config('app.name'))</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @stack('head')
</head>
<body class="flex h-dvh w-full flex-col overflow-hidden font-sans text-sm text-gray-900 dark:bg-gray-900 dark:text-gray-100">
    <header class="shrink-0 border-b border-gray-300 dark:border-gray-700">
        <nav class="flex items-center justify-between gap-4 px-4 py-2">
            <div class="flex items-center gap-4 border border-dashed border-gray-300 px-3 py-1 dark:border-gray-600">
                top-nav-left-container
            </div>
            <div class="flex items-center gap-4 border border-dashed border-gray-300 px-3 py-1 dark:border-gray-600">
                top-nav-right-container
            </div>
        </nav>
    </header>

    <div class="grid min-h-0 flex-1 grid-cols-[10%_50%_40%]">
        <div class="overflow-y-auto border-r border-gray-300 p-4 dark:border-gray-700">
            content-left-container
        </div>
        <div class="overflow-y-auto border-r border-gray-300 p-4 dark:border-gray-700">
            content-center-container
        </div>
        <div class="overflow-y-auto p-4">
            content-right-container
        </div>
    </div>

    <footer class="shrink-0 border-t border-gray-300 dark:border-gray-700">
        <div class="border-b border-dashed border-gray-300 px-4 py-2 dark:border-gray-600">
            footer-sitemap
            <div class="mt-1 border border-dashed border-gray-300 px-3 py-1 dark:border-gray-600">
                footer-sitemap-copyrights
            </div>
        </div>
        <div class="px-4 py-2">
            footer-scripts
            @stack('scripts')
        </div>
    </footer>
</body>
</html>
