<?php

declare(strict_types=1);

/**
 * Autoloader testów. Świadomie własny, a nie composerowy: rdzeń pakietu nie
 * zależy od Flow ani od niczego z vendora, więc testy mają się uruchamiać
 * wszędzie, także tam, gdzie nikt nie robił „composer install" (potok CI,
 * maszyna bez sieci).
 *
 * Ładujemy wyłącznie Classes/ i tests/. Gdyby test sięgnął po klasę zależną
 * od Flow, autoloader jej nie znajdzie i test od razu to pokaże. To jest
 * pożądane: pilnuje, żeby rdzeń pozostał niezależny od frameworka.
 */
spl_autoload_register(static function (string $class): void {
    $roots = [
        'Calmfox\\Watch\\Tests\\' => __DIR__.'/',
        'Calmfox\\Watch\\' => \dirname(__DIR__).'/Classes/',
    ];
    foreach ($roots as $prefix => $directory) {
        if (!str_starts_with($class, $prefix)) {
            continue;
        }
        $relative = substr($class, \strlen($prefix));
        $file = $directory.str_replace('\\', '/', $relative).'.php';
        if (is_file($file)) {
            require_once $file;
        }

        return;
    }
});
