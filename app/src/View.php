<?php
declare(strict_types=1);

namespace App;

/** Renders PHP templates from app/templates into a layout. */
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
        try {
            require APP_ROOT . '/app/templates/' . $__template . '.php';
        } catch (\Throwable $__error) {
            ob_end_clean(); // half a template must not end up in front of the error page
            throw $__error;
        }
        return (string) ob_get_clean();
    }
}
