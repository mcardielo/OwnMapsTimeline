<?php
/**
 * View — Minimal template renderer.
 * Usage: View::render('login', ['error' => '...'], 'layout');
 *        View::render('login', ['error' => '...'], null); // no layout
 */

declare(strict_types=1);

class View
{
    /** Render a view with optional layout wrapping */
    public static function render(string $view, array $data = [], ?string $layout = 'layout'): void
    {
        $viewsDir = __DIR__ . '/../views';

        extract($data, EXTR_SKIP);

        ob_start();
        require "{$viewsDir}/{$view}.php";
        $content = ob_get_clean();

        if ($layout) {
            // Pass $content + $data to layout
            ob_start();
            require "{$viewsDir}/{$layout}.php";
            echo ob_get_clean();
        } else {
            echo $content;
        }
    }

    /** Shortcut for standalone pages (no wrapping) */
    public static function page(string $view, array $data = []): void
    {
        self::render($view, $data, null);
    }

    /** Escape HTML */
    public static function esc(mixed $value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
    }
}
