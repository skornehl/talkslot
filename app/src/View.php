<?php
declare(strict_types=1);

namespace App;

/** Rendert PHP-Templates aus app/templates in ein Layout. */
final class View
{
    public static function render(string $template, array $vars = [], ?string $layout = 'layout'): void
    {
        $content = self::capture($template, $vars);
        if ($layout === null) {
            echo $content;
            return;
        }
        echo self::capture($layout, ['content' => $content] + $vars);
    }

    public static function capture(string $__template, array $__vars): string
    {
        extract($__vars, EXTR_SKIP);
        ob_start();
        require APP_ROOT . '/app/templates/' . $__template . '.php';
        return (string) ob_get_clean();
    }
}
