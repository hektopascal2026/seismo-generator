<?php
/**
 * TemplateRenderer — minimal {{PLACEHOLDER}} string substitution.
 *
 * No Composer deps. Placeholders are {{UPPER_SNAKE_CASE}} only; anything else
 * is passed through untouched. If a template references a placeholder that
 * isn't supplied, render() throws (so typos fail loudly instead of shipping
 * a literal `{{FOO}}` into production).
 */

final class TemplateRenderer
{
    /**
     * @param string $templatePath Absolute path to a *.tmpl file
     * @param array<string, scalar|null> $vars Keys WITHOUT the surrounding braces.
     */
    public function render(string $templatePath, array $vars): string
    {
        if (!is_file($templatePath)) {
            throw new RuntimeException("Template not found: {$templatePath}");
        }
        $src = file_get_contents($templatePath);
        if ($src === false) {
            throw new RuntimeException("Cannot read template: {$templatePath}");
        }

        $result = preg_replace_callback(
            '/\{\{([A-Z0-9_]+)\}\}/',
            function (array $match) use ($vars, $templatePath) {
                $key = $match[1];
                if (!array_key_exists($key, $vars)) {
                    throw new RuntimeException(
                        "Template {$templatePath} references {{" . $key . "}} but no value was supplied."
                    );
                }
                $val = $vars[$key];
                return $val === null ? '' : (string)$val;
            },
            $src
        );

        if ($result === null) {
            throw new RuntimeException("preg_replace_callback failed for {$templatePath}");
        }
        return $result;
    }

    /**
     * Writes a rendered template to disk, creating the parent directory as
     * needed. Returns the absolute path written.
     */
    public function renderTo(string $templatePath, string $targetPath, array $vars): string
    {
        $rendered = $this->render($templatePath, $vars);
        $dir = dirname($targetPath);
        if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
            throw new RuntimeException("Cannot create directory: {$dir}");
        }
        if (file_put_contents($targetPath, $rendered) === false) {
            throw new RuntimeException("Cannot write {$targetPath}");
        }
        return $targetPath;
    }

    /** Escapes a string for safe inclusion as a PHP single-quoted literal. */
    public static function phpSingleQuoted(string $raw): string
    {
        return "'" . str_replace(['\\', "'"], ['\\\\', "\\'"], $raw) . "'";
    }
}
