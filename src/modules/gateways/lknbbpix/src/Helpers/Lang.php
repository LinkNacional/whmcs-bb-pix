<?php

namespace Lkn\BBPix\Helpers;

/**
 * Language Helper for LKNBBPIX Module
 * Handles multilingual support for the gateway
 */
final class Lang
{
    /**
     * @var array<string, string>
     */
    private static array $strings = [];

    /**
     * @var string|null
     */
    private static ?string $currentLang = null;

    /**
     * Load language file
     *
     * @param string|null $langCode
     * @return void
     */
    public static function load(?string $langCode = null): void
    {
        if ($langCode === null) {
            $langCode = self::detectLanguage();
        }

        // Normalize language code
        $langCode = self::normalizeLanguageCode($langCode);
        
        self::$currentLang = $langCode;

        $langFile = __DIR__ . '/../../lang/' . $langCode . '.php';

        if (!file_exists($langFile)) {
            // Fallback to English
            $langFile = __DIR__ . '/../../lang/english.php';
        }

        if (file_exists($langFile)) {
            /** @var array<string, string> $_GATEWAYLANG */
            $_GATEWAYLANG = [];
            require $langFile;
            self::$strings = $_GATEWAYLANG ?? [];
        }
    }

    /**
     * Detect current language from WHMCS
     *
     * @return string
     */
    private static function detectLanguage(): string
    {
        // Try to get from URL parameter first (for language switching)
        if (isset($_GET['language']) && !empty($_GET['language'])) {
            return $_GET['language'];
        }

        // Try to get from session
        if (isset($_SESSION['Language'])) {
            return $_SESSION['Language'];
        }

        // Try to get from global $_LANG
        global $_LANG;
        if (isset($_LANG['locale'])) {
            $locale = $_LANG['locale'];
            // Convert locale to language code
            if (strpos($locale, 'pt_BR') !== false) {
                return 'portuguese-br';
            } elseif (strpos($locale, 'pt_PT') !== false) {
                return 'portuguese-pt';
            } elseif (strpos($locale, 'es_') !== false) {
                return 'spanish';
            }
        }

        // Default to English
        return 'english';
    }

    /**
     * Normalize language code
     *
     * @param string $langCode
     * @return string
     */
    private static function normalizeLanguageCode(string $langCode): string
    {
        $map = [
            'portuguese-br' => 'portuguese-br',
            'portuguese-pt' => 'portuguese-pt',
            'portugues-br' => 'portuguese-br',
            'portugues-pt' => 'portuguese-pt',
            'pt-br' => 'portuguese-br',
            'pt-pt' => 'portuguese-pt',
            'pt_BR' => 'portuguese-br',
            'pt_PT' => 'portuguese-pt',
            'spanish' => 'spanish',
            'es' => 'spanish',
            'es_ES' => 'spanish',
            'english' => 'english',
            'en' => 'english',
            'en_US' => 'english',
        ];

        $normalized = strtolower($langCode);
        return $map[$normalized] ?? 'english';
    }

    /**
     * Get translation string
     *
     * @param string $key
     * @param array<string, mixed> $replacements
     * @return string
     */
    public static function trans(string $key, array $replacements = []): string
    {
        if (empty(self::$strings)) {
            self::load();
        }

        $string = self::$strings[$key] ?? $key;

        foreach ($replacements as $k => $v) {
            $string = str_replace(':' . $k, (string) $v, $string);
        }

        return $string;
    }

    /**
     * Alias for trans()
     *
     * @param string $key
     * @param array<string, mixed> $replacements
     * @return string
     */
    public static function get(string $key, array $replacements = []): string
    {
        return self::trans($key, $replacements);
    }

    /**
     * Get all translations
     *
     * @return array<string, string>
     */
    public static function all(): array
    {
        if (empty(self::$strings)) {
            self::load();
        }

        return self::$strings;
    }

    /**
     * Get current language code
     *
     * @return string
     */
    public static function getCurrentLanguage(): string
    {
        if (self::$currentLang === null) {
            self::load();
        }

        return self::$currentLang ?? 'english';
    }

    /**
     * Get DataTables language URL based on current language
     *
     * @return string
     */
    public static function getDataTablesLanguageUrl(): string
    {
        $lang = self::getCurrentLanguage();
        
        $urls = [
            'portuguese-br' => '//cdn.datatables.net/plug-ins/1.10.24/i18n/Portuguese-Brasil.json',
            'portuguese-pt' => '//cdn.datatables.net/plug-ins/1.10.24/i18n/Portuguese.json',
            'spanish' => '//cdn.datatables.net/plug-ins/1.10.24/i18n/Spanish.json',
            'english' => '//cdn.datatables.net/plug-ins/1.10.24/i18n/English.json',
        ];

        return $urls[$lang] ?? $urls['english'];
    }
}

