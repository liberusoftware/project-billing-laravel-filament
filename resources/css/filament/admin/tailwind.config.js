import preset from '../../../../vendor/filament/filament/tailwind.config.preset'

export default {
    presets: [preset],
    content: [
        './modules/billing-*/src/Filament/**/*.php',
        './modules/billing-*/resources/views/**/*.blade.php',
        './modules/billing-*/resources/views/filament/**/*.blade.php',
        './vendor/filament/**/*.blade.php',
        './vendor/laravel/jetstream/**/*.blade.php',
    ],
}
