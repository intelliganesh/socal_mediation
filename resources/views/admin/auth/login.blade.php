<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Admin Login</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Open+Sans:wght@400;600;700&display=swap" rel="stylesheet">
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="grid min-h-screen place-items-center bg-[#ECEDF9] p-4 text-[#1F2937]">
    <form class="w-full max-w-sm rounded-lg border border-[#E5E7EB] bg-white p-6 shadow-sm" method="post" action="{{ route('admin.login.store') }}">
        @csrf
        <h1 class="text-xl font-bold text-[#082BC3]">Admin Login</h1>
        <p class="mt-1 text-sm text-gray-500">Use seeded credentials: admin@socal.test / password</p>
        <label class="mt-6 block text-sm font-semibold">Email</label>
        <input class="mt-1 w-full rounded-md border border-[#E5E7EB] px-3 py-2" type="email" name="email" value="{{ old('email') }}" required>
        <label class="mt-4 block text-sm font-semibold">Password</label>
        <input class="mt-1 w-full rounded-md border border-[#E5E7EB] px-3 py-2" type="password" name="password" required>
        @error('email')
            <p class="mt-3 text-sm font-semibold text-[#75172E]">{{ $message }}</p>
        @enderror
        <button class="mt-6 w-full rounded-md bg-[#082BC3] px-4 py-2.5 text-sm font-bold text-white">Login</button>
    </form>
</body>
</html>
