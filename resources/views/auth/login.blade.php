<x-guest-layout>
    <div class="mb-7">
        <p class="mb-2 text-xs font-bold uppercase tracking-[.14em] text-emerald-700">Добре дошли</p>
        <h2 class="text-2xl font-bold tracking-tight text-slate-900">Вход в системата</h2>
        <p class="mt-2 text-sm text-slate-500">Използвайте служебния си акаунт за достъп до администрацията.</p>
    </div>

    <x-auth-session-status class="mb-4" :status="session('status')" />

    <form method="POST" action="{{ route('login') }}">
        @csrf

        <div>
            <x-input-label for="email" value="Имейл адрес" />
            <x-text-input id="email" class="block mt-1 w-full" type="email" name="email" :value="old('email')" required autofocus autocomplete="username" />
            <x-input-error :messages="$errors->get('email')" class="mt-2" />
        </div>

        <div class="mt-4">
            <x-input-label for="password" value="Парола" />

            <x-text-input id="password" class="block mt-1 w-full"
                            type="password"
                            name="password"
                            required autocomplete="current-password" />

            <x-input-error :messages="$errors->get('password')" class="mt-2" />
        </div>

        <div class="block mt-4">
            <label for="remember_me" class="inline-flex items-center">
                <input id="remember_me" type="checkbox" class="rounded border-gray-300 text-indigo-600 shadow-sm focus:ring-indigo-500" name="remember">
                <span class="ms-2 text-sm text-gray-600">Запомни ме</span>
            </label>
        </div>

        <div class="flex items-center justify-end mt-4">
            @if (Route::has('password.request'))
                <a class="text-sm text-emerald-700 hover:text-emerald-900 rounded-md focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-emerald-600" href="{{ route('password.request') }}">
                    Забравена парола?
                </a>
            @endif

            <x-primary-button class="ms-3">
                Вход
            </x-primary-button>
        </div>
    </form>
</x-guest-layout>
