<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Reset Admin Password</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Open+Sans:wght@400;600;700&display=swap" rel="stylesheet">
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="grid min-h-screen place-items-center bg-[#f3f4f7] p-4 text-[#1F2937]">
    <main class="w-full max-w-[420px]">
        <form class="rounded-2xl border border-[#E5E7EB] bg-white p-7 shadow-[0_24px_70px_rgba(17,24,39,0.16)] sm:p-9" method="post" action="{{ route('admin.password.email') }}">
            @csrf
            <img class="mx-auto h-20 w-full object-contain" src="{{ asset('admin-icons/logo.png') }}" alt="SoCal Mediation">
            <h1 class="mt-7 text-center text-2xl font-bold text-[#111827]">Reset your password</h1>
            <p class="mt-2 text-center text-sm font-semibold leading-6 text-gray-500">Enter your account email and we will send you a secure reset link.</p>

            @if(session('status'))
                <p class="mt-5 rounded-lg border border-[#BBF7D0] bg-[#BBF7D0] px-4 py-3 text-sm font-bold text-[#166534]">{{ session('status') }}</p>
            @endif

            <label class="mt-7 grid gap-2">
                <span class="text-sm font-bold text-[#111827]">Email</span>
                <input class="h-11 rounded-lg border border-[#9CA3AF] px-4 text-sm font-semibold outline-none focus:border-[#082BC3] focus:ring-4 focus:ring-[#F1F6FE]" type="email" name="email" value="{{ old('email') }}" autocomplete="email" required autofocus>
                @error('email')<span class="text-sm font-bold text-[#B91C1C]">{{ $message }}</span>@enderror
            </label>

            <button class="mt-7 h-11 w-full rounded-lg bg-[#082BC3] text-sm font-bold text-white hover:bg-[#111827]" type="submit">Send Reset Link</button>
            <a class="mt-5 block text-center text-sm font-bold text-[#082BC3] hover:text-[#111827]" href="{{ route('admin.login') }}">Back to sign in</a>
        </form>
    </main>
</body>
</html>
