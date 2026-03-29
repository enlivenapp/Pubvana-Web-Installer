<?php

namespace CI4Installer;

class Autoloader
{
    public static function register(string $baseDir): void
    {
        spl_autoload_register(function (string $class) use ($baseDir) {
            $prefix = 'CI4Installer\\';
            $prefixLen = strlen($prefix);

            if (strncmp($class, $prefix, $prefixLen) !== 0) {
                return;
            }

            $relativeClass = substr($class, $prefixLen);
            $file = $baseDir . '/' . str_replace('\\', '/', $relativeClass) . '.php';

            if (file_exists($file)) {
                require $file;
            }
        });
    }
}
