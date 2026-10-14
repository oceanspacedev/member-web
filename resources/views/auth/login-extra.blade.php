<div class="space-y-6">
    <div class="relative flex items-center justify-center">
        <div class="flex-grow border-t border-gray-200 dark:border-gray-700/80"></div>
        <span class="mx-4 flex-shrink text-xs font-semibold uppercase tracking-wider text-gray-400 dark:text-gray-500">
            Atau masuk dengan
        </span>
        <div class="flex-grow border-t border-gray-200 dark:border-gray-700/80"></div>
    </div>

    <x-filament::button
        tag="a"
        :href="route('filament.admin.auth.whatsapp-login')"
        color="gray"
        :outlined="true"
        icon="lc-whatsapp"
        class="w-full"
    >
        WhatsApp
    </x-filament::button>
</div>
