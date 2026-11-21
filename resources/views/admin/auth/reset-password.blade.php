<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Choose New Password</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Open+Sans:wght@400;600;700&display=swap" rel="stylesheet">
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="grid min-h-screen place-items-center bg-[#f3f4f7] p-4 text-[#1F2937]">
    <main class="w-full max-w-[420px]">
        <form class="rounded-2xl border border-[#E5E7EB] bg-white p-7 shadow-[0_24px_70px_rgba(17,24,39,0.16)] sm:p-9" method="post" action="{{ route('admin.password.reset.update') }}">
            @csrf
            <input type="hidden" name="token" value="{{ $token }}">
            <h1 class="text-center text-2xl font-bold text-[#111827]">Choose a new password</h1>
            <p class="mt-2 text-center text-sm font-semibold text-gray-500">Use at least 8 characters.</p>

            <div class="mt-7 grid gap-5">
                <label class="grid gap-2">
                    <span class="text-sm font-bold text-[#111827]">Email</span>
                    <input class="h-11 rounded-lg border border-[#9CA3AF] px-4 text-sm font-semibold" type="email" name="email" value="{{ old('email', $email) }}" autocomplete="email" required>
                </label>
                <label class="grid gap-2">
                    <span class="text-sm font-bold text-[#111827]">New Password</span>
                    <input class="h-11 rounded-lg border border-[#9CA3AF] px-4 text-sm font-semibold" type="password" name="password" autocomplete="new-password" required>
                </label>
                <label class="grid gap-2">
                    <span class="text-sm font-bold text-[#111827]">Confirm Password</span>
                    <input class="h-11 rounded-lg border border-[#9CA3AF] px-4 text-sm font-semibold" type="password" name="password_confirmation" autocomplete="new-password" required>
                </label>
            </div>

            @if($errors->any())
                <p class="mt-5 rounded-lg border border-[#F8EEF1] bg-[#F8EEF1] px-4 py-3 text-sm font-bold text-[#B91C1C]">{{ $errors->first() }}</p>
            @endif

            <button class="mt-7 h-11 w-full rounded-lg bg-[#082BC3] text-sm font-bold text-white hover:bg-[#111827]" type="submit">Reset Password</button>
        </form>
    </main>
</body>
</html>
