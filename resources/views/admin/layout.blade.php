<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $title ?? 'Socal Admin' }}</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Open+Sans:wght@400;600;700&display=swap" rel="stylesheet">
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="min-h-screen bg-[#F3F0FF] p-2 text-[#1F2937] sm:p-4">
    <div class="mx-auto min-h-[calc(100vh-1rem)] max-w-7xl overflow-hidden rounded-xl border border-white/70 bg-white shadow-[0_18px_60px_rgba(17,24,39,0.08)] sm:min-h-[calc(100vh-2rem)] sm:rounded-2xl">
        <header class="flex flex-wrap items-center justify-between gap-3 border-b border-[#E5E7EB] bg-white px-4 py-3 sm:px-6 sm:py-4">
            <div class="flex min-w-0 items-center gap-3">
                <div class="grid h-10 w-10 place-items-center rounded-lg bg-[#F1F6FE] text-lg font-bold text-[#082BC3]">SM</div>
                <div class="min-w-0">
                    <div class="truncate text-base font-bold">SoCal Mediation Admin</div>
                    <div class="text-xs font-semibold text-gray-500">Shared firm database</div>
                </div>
            </div>
            <nav class="hidden flex-1 flex-wrap items-center justify-center gap-2 text-sm font-bold md:flex">
                <a class="border-b-2 px-4 py-3 {{ request()->routeIs('admin.dashboard') ? 'border-[#082BC3] text-[#111827]' : 'border-transparent text-gray-600 hover:text-[#111827]' }}" href="{{ route('admin.dashboard') }}">Dashboard</a>
                <a class="border-b-2 px-4 py-3 {{ request()->routeIs('admin.consultations.*') ? 'border-[#082BC3] text-[#111827]' : 'border-transparent text-gray-600 hover:text-[#111827]' }}" href="{{ route('admin.consultations.index') }}">Consultations</a>
                <a class="border-b-2 px-4 py-3 {{ request()->routeIs('admin.calendar.*') ? 'border-[#082BC3] text-[#111827]' : 'border-transparent text-gray-600 hover:text-[#111827]' }}" href="{{ route('admin.calendar.index') }}">Booking Calendar</a>
                <a class="border-b-2 border-transparent px-4 py-3 text-gray-600 hover:text-[#111827]" href="{{ url('/api/documentation') }}" target="_blank" rel="noopener">API Documentation</a>
            </nav>
            @auth
                <div class="hidden sm:block">
                    <form method="post" action="{{ route('admin.logout') }}">
                        @csrf
                        <button class="rounded-lg border border-[#E5E7EB] px-3 py-2 text-sm font-bold hover:bg-gray-50">Logout</button>
                    </form>
                </div>
            @endauth
            <details class="md:hidden">
                <summary class="flex cursor-pointer list-none items-center gap-2 rounded-lg border border-[#E5E7EB] px-3 py-2 text-sm font-bold text-[#111827]">
                    <span>Menu</span>
                    <span class="grid gap-1">
                        <span class="block h-0.5 w-4 rounded-full bg-[#111827]"></span>
                        <span class="block h-0.5 w-4 rounded-full bg-[#111827]"></span>
                        <span class="block h-0.5 w-4 rounded-full bg-[#111827]"></span>
                    </span>
                </summary>
                <nav class="absolute left-4 right-4 z-20 mt-3 grid gap-1 rounded-xl border border-[#E5E7EB] bg-white p-3 text-sm font-bold shadow-lg">
                    <a class="border-l-2 px-4 py-3 {{ request()->routeIs('admin.dashboard') ? 'border-[#082BC3] bg-[#F7F8FC] text-[#111827]' : 'border-transparent text-gray-600 hover:bg-gray-50' }}" href="{{ route('admin.dashboard') }}">Dashboard</a>
                    <a class="border-l-2 px-4 py-3 {{ request()->routeIs('admin.consultations.*') ? 'border-[#082BC3] bg-[#F7F8FC] text-[#111827]' : 'border-transparent text-gray-600 hover:bg-gray-50' }}" href="{{ route('admin.consultations.index') }}">Consultations</a>
                    <a class="border-l-2 px-4 py-3 {{ request()->routeIs('admin.calendar.*') ? 'border-[#082BC3] bg-[#F7F8FC] text-[#111827]' : 'border-transparent text-gray-600 hover:bg-gray-50' }}" href="{{ route('admin.calendar.index') }}">Booking Calendar</a>
                    <a class="rounded-lg px-4 py-3 text-gray-600 hover:bg-gray-50" href="{{ url('/api/documentation') }}" target="_blank" rel="noopener">API Documentation</a>
                    @auth
                        <form method="post" action="{{ route('admin.logout') }}">
                            @csrf
                            <button class="w-full rounded-lg border border-[#E5E7EB] px-4 py-3 text-left text-sm font-bold text-[#111827] hover:bg-gray-50">Logout</button>
                        </form>
                    @endauth
                </nav>
            </details>
        </header>

        <main>
            <section class="border-b border-[#E5E7EB] bg-[#f3f4f7] px-4 py-5 sm:px-6">
                <h1 class="text-xl font-bold tracking-tight sm:text-2xl">{{ $heading ?? 'Dashboard' }}</h1>
                <p class="mt-1 text-sm text-gray-500">{{ $subheading ?? 'Review bookings, payments, and calendar availability.' }}</p>
            </section>
            <section class="bg-[#f3f4f7] px-4 py-5 sm:px-6 sm:py-6">
                @if(session('status'))
                    <div class="mb-4 rounded-lg border border-[#BBF7D0] bg-[#BBF7D0] px-4 py-3 text-sm font-bold text-[#166534]">{{ session('status') }}</div>
                @endif
                @if(session('error'))
                    <div class="mb-4 rounded-lg border border-[#F8EEF1] bg-[#F8EEF1] px-4 py-3 text-sm font-bold text-[#B91C1C]">{{ session('error') }}</div>
                @endif
                @if($errors->any())
                    <div class="mb-4 rounded-lg border border-[#F8EEF1] bg-[#F8EEF1] px-4 py-3 text-sm font-bold text-[#B91C1C]">
                        {{ $errors->first() }}
                    </div>
                @endif
                {{ $slot }}
            </section>
        </main>
    </div>
</body>
</html>
