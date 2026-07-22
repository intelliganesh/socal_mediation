<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>SoCal Mediation Admin Login</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Open+Sans:wght@400;600;700&display=swap" rel="stylesheet">
    <script src="https://unpkg.com/lucide@latest/dist/umd/lucide.min.js" defer></script>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="grid min-h-screen place-items-center bg-[#f3f4f7] p-4 text-[#1F2937]">
    <main class="w-full max-w-[380px]">
        <form class="rounded-[28px] border border-white/80 bg-white px-6 py-6 shadow-[0_24px_70px_rgba(17,24,39,0.16)] sm:px-10 sm:py-10" method="post" action="{{ route('admin.login.store') }}">
            @csrf
            <div class="text-center">
                <img class="mx-auto h-24 w-full rounded-2xl object-contain" src="{{ asset('admin-icons/logo.png') }}" alt="SoCal Mediation">
                {{-- <h1 class="mt-7 text-xl font-bold tracking-tight text-[#111827] sm:text-2xl">SoCal Mediation Admin</h1> --}}
                <p class="mt-3 text-md font-semibold text-gray-500">Sign in to manage consultations.<br>admin@socal.test/password</p>
            </div>

            <div class="mt-10 space-y-7">
                <label class="relative block">
                    <span class="absolute -top-3 left-5 bg-white px-2 text-base font-semibold text-[#082BC3]">Email</span>
                    <input class="h-10 w-full rounded-lg border border-[#9CA3AF] bg-white px-5 text-md font-semibold text-[#111827] outline-none placeholder:text-gray-500 focus:border-[#082BC3] focus:ring-4 focus:ring-[#F1F6FE]" type="email" name="email" value="{{ old('email') }}" placeholder="name@organization.com" required autofocus>
                </label>

                <label class="relative block">
                    <span class="absolute -top-3 left-5 bg-white px-2 text-base font-semibold text-[#082BC3]">Password</span>
                    <input id="admin-password" class="h-10 w-full rounded-lg border border-[#9CA3AF] bg-white px-5 pr-14 text-md font-semibold text-[#111827] outline-none placeholder:text-gray-500 focus:border-[#082BC3] focus:ring-4 focus:ring-[#F1F6FE]" type="password" name="password" placeholder="••••••••••••••••" required>
                    <button class="absolute right-4 top-1/2 grid h-9 w-9 -translate-y-1/2 place-items-center rounded-lg text-gray-600 hover:bg-[#F7F8FC]" type="button" data-toggle-password aria-label="Show password">
                        <i data-lucide="eye" class="h-5 w-5"></i>
                    </button>
                </label>
            </div>

            @error('email')
                <p class="mt-5 rounded-lg border border-[#F8EEF1] bg-[#F8EEF1] px-4 py-3 text-sm font-bold text-[#B91C1C]">{{ $message }}</p>
            @enderror

            <button class="mt-9 h-12 w-full rounded-lg bg-[#082BC3] text-lg font-bold text-white shadow-[0_12px_28px_rgba(8,43,195,0.26)] hover:bg-[#111827]" type="submit">Sign In</button>

            {{-- <a class="mt-6 block text-center text-lg font-semibold text-[#082BC3]" href="{{ route('admin.login') }}">Forgot password?</a> --}}
        </form>
    </main>

    <script>
        window.addEventListener('DOMContentLoaded', () => {
            if (window.lucide) {
                window.lucide.createIcons();
            }

            const button = document.querySelector('[data-toggle-password]');
            const password = document.getElementById('admin-password');

            button?.addEventListener('click', () => {
                const isPassword = password.type === 'password';
                password.type = isPassword ? 'text' : 'password';
                button.setAttribute('aria-label', isPassword ? 'Hide password' : 'Show password');
            });
        });
    </script>
</body>
</html>
