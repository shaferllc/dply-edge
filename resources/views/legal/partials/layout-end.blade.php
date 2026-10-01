        <section class="border-b border-edge-line">
            <div class="mx-auto max-w-6xl px-6 py-10 lg:px-10">
                <p class="text-sm text-edge-mute">
                    {{ __('Questions:') }}
                    <a href="mailto:{{ config('dply.support_email') }}" class="border-b border-edge-lime/50 pb-0.5 text-edge-text transition-colors hover:border-edge-lime hover:text-edge-lime">{{ config('dply.support_email') }}</a>
                </p>
            </div>
        </section>
    </main>

    <x-edge-marketing-footer />
    @livewireScripts
</body>
</html>
