<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $title ?? 'Socal Admin' }}</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Open+Sans:wght@400;600;700&display=swap" rel="stylesheet">
    <script src="https://unpkg.com/lucide@latest/dist/umd/lucide.min.js" defer></script>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="min-h-screen bg-[#f3f4f7] text-[#1F2937]">
    @php
        $navItems = [
            ['label' => 'Dashboard', 'icon' => 'layout-dashboard', 'href' => route('admin.dashboard'), 'active' => request()->routeIs('admin.dashboard')],
            ['label' => 'Consultations', 'icon' => 'calendar-days', 'href' => route('admin.consultations.index'), 'active' => request()->routeIs('admin.consultations.*')],
            ['label' => 'Calendar', 'icon' => 'calendar', 'href' => route('admin.calendar.index'), 'active' => request()->routeIs('admin.calendar.*')],
            ['label' => 'API Documentation', 'icon' => 'clipboard-list', 'href' => url('/api/documentation'), 'active' => false, 'external' => true, 'aria' => 'API Documentation'],
        ];
        $currentUser = auth()->user();
        $userName = $currentUser?->name ?: 'John Davis';
        $initials = collect(explode(' ', $userName))->filter()->map(fn ($part) => Str::substr($part, 0, 1))->take(2)->implode('') ?: 'JD';
    @endphp

    <div class="min-h-screen lg:flex">
        <aside class="hidden w-[268px] shrink-0 border-r border-[#E5E7EB] bg-[#F7F8FC] lg:fixed lg:inset-y-0 lg:flex lg:flex-col">
            <div class="flex h-20 items-center gap-3 border-b border-[#E5E7EB] px-6">
                {{-- <div class="grid h-11 w-11 shrink-0 place-items-center rounded-full bg-[#F1F6FE] text-sm font-bold text-[#082BC3]">SM</div>
                <div class="min-w-0">
                    <div class="truncate text-base font-bold leading-5 text-[#111827]">SoCal</div>
                    <div class="truncate text-base font-bold leading-5 text-[#111827]">Mediation</div>
                </div> --}}
                <img class="mx-auto h-16 w-full rounded-2xl object-contain" src="{{ asset('admin-icons/logo.png') }}" alt="SoCal Mediation">
            </div>

            <nav class="flex-1 space-y-1 px-3 py-8 text-sm font-bold">
                @foreach($navItems as $item)
                    <a
                        class="flex h-12 items-center gap-3 rounded-lg px-4 transition {{ $item['active'] ? 'bg-[#ECEDF9] text-[#082BC3]' : 'text-gray-600 hover:bg-[#F7F8FC] hover:text-[#111827]' }}"
                        href="{{ $item['href'] }}"
                        aria-label="{{ $item['aria'] ?? $item['label'] }}"
                        @if($item['external'] ?? false) target="_blank" rel="noopener" @endif
                    >
                        <i data-lucide="{{ $item['icon'] }}" class="h-5 w-5 shrink-0"></i>
                        <span class="truncate">{{ $item['label'] }}</span>
                    </a>
                @endforeach
            </nav>

           {{--  <button class="mx-3 mb-5 flex h-12 items-center gap-3 rounded-lg px-4 text-sm font-bold text-gray-600 hover:bg-[#F7F8FC]" type="button">
                <i data-lucide="chevron-left" class="h-5 w-5"></i>
                <span>Collapse</span>
            </button> --}}
        </aside>

        <div class="min-w-0 flex-1 lg:pl-[268px]">
            <header class="sticky top-0 z-20 border-b border-[#E5E7EB] bg-white">
                <div class="flex h-20 items-center justify-between gap-4 px-4 sm:px-6 lg:px-8">
                    <div class="flex min-w-0 items-center gap-4">
                        <details class="lg:hidden">
                            <summary class="grid h-10 w-10 cursor-pointer list-none place-items-center rounded-lg border border-[#E5E7EB] text-[#111827]">
                                <i data-lucide="menu" class="h-5 w-5"></i>
                            </summary>
                            <nav class="absolute left-4 right-4 z-30 mt-3 grid gap-1 rounded-xl border border-[#E5E7EB] bg-[#F7F8FC] p-3 text-sm font-bold shadow-lg">
                                @foreach($navItems as $item)
                                    <a
                                        class="flex items-center gap-3 rounded-lg px-4 py-3 {{ $item['active'] ? 'bg-[#ECEDF9] text-[#082BC3]' : 'text-gray-600 hover:bg-[#F7F8FC] hover:text-[#111827]' }}"
                                        href="{{ $item['href'] }}"
                                        aria-label="{{ $item['aria'] ?? $item['label'] }}"
                                        @if($item['external'] ?? false) target="_blank" rel="noopener" @endif
                                    >
                                        <i data-lucide="{{ $item['icon'] }}" class="h-5 w-5 shrink-0"></i>
                                        <span>{{ $item['label'] }}</span>
                                    </a>
                                @endforeach
                            </nav>
                        </details>
                        <nav class="min-w-0 text-sm font-bold" aria-label="Breadcrumb">
                            <ol class="flex min-w-0 items-center gap-2">
                                <li><a class="text-gray-500 hover:text-[#111827]" href="{{ route('admin.dashboard') }}">Dashboard</a></li>
                                @unless(request()->routeIs('admin.dashboard'))
                                    <li class="text-gray-400">/</li>
                                    <li class="truncate text-[#111827]">{{ $heading ?? 'Consultations' }}</li>
                                @endunless
                            </ol>
                        </nav>
                    </div>

                    <div class="flex shrink-0 items-center gap-3">
                        <div class="grid h-10 w-10 place-items-center rounded-full bg-[#082BC3] text-sm font-bold text-white">{{ $initials }}</div>
                        <div class="hidden text-sm font-bold text-[#111827] sm:block">{{ $userName }}</div>
                        @auth
                            <form method="post" action="{{ route('admin.logout') }}">
                                @csrf
                                <button class="grid h-9 w-9 place-items-center rounded-lg text-gray-500 hover:bg-[#F7F8FC] hover:text-[#111827]" aria-label="Logout">
                                    <i data-lucide="log-out" class="h-5 w-5"></i>
                                </button>
                            </form>
                        @else
                            <i data-lucide="chevron-down" class="h-4 w-4 text-gray-500"></i>
                        @endauth
                    </div>
                </div>
            </header>

            <main>
                <section class="px-4 py-6 sm:px-6 lg:px-8">
                    <div class="mb-5">
                        <h1 class="text-2xl font-bold tracking-tight text-[#111827]">{{ $heading ?? 'Dashboard' }}</h1>
                        <p class="mt-1 text-sm text-gray-500">{{ $subheading ?? 'Review bookings, payments, and calendar availability.' }}</p>
                    </div>
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
    </div>
    <script>
        window.addEventListener('DOMContentLoaded', () => {
            if (window.lucide) {
                window.lucide.createIcons();
            }
        });
    </script>
</body>
</html>
