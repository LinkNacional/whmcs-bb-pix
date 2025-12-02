<?php

namespace Lkn\BBPix\Helpers;

use Exception;
use Smarty;

final class View
{
    private const TEMPLATES_PATH = __DIR__ . '/../templates';
    private const DEFAULT_THEME = 'lagom2';

    public static function render(string $view, array $vars = []): string
    {
        // Detectar tema ativo do WHMCS
        $activeTheme = self::getActiveTheme();
        
        // Converter notação de ponto em caminho de arquivo
        $viewPath = str_replace('.', '/', $view);
        
        // Tentar buscar o template no tema ativo
        $themeTemplatePath = self::TEMPLATES_PATH . "/$activeTheme/$viewPath.tpl";
        
        // Se não existir no tema ativo, tentar tema padrão
        if (!file_exists($themeTemplatePath)) {
            $themeTemplatePath = self::TEMPLATES_PATH . "/" . self::DEFAULT_THEME . "/$viewPath.tpl";
        }
        
        // Se ainda não existir, lançar exceção
        if (!file_exists($themeTemplatePath)) {
            throw new Exception("Smarty template not found: $viewPath (theme: $activeTheme)");
        }

        $smarty = new Smarty();
        $smarty = self::assignVars($smarty, $vars);

        return $smarty->fetch($themeTemplatePath);
    }

    /**
     * Detecta o tema ativo do WHMCS
     *
     * @return string
     */
    private static function getActiveTheme(): string
    {
        // Tentar obter o tema da configuração global do WHMCS
        if (isset($GLOBALS['CONFIG']['Template']) && !empty($GLOBALS['CONFIG']['Template'])) {
            return $GLOBALS['CONFIG']['Template'];
        }
        
        // Tentar obter do smarty global (se disponível)
        if (isset($GLOBALS['smarty']) && method_exists($GLOBALS['smarty'], 'getTemplateVars')) {
            $template = $GLOBALS['smarty']->getTemplateVars('template');
            if (!empty($template)) {
                return $template;
            }
        }
        
        // Fallback para tema padrão
        return self::DEFAULT_THEME;
    }

    private static function assignVars(Smarty $smartyInstance, array $vars): Smarty
    {
        foreach ($vars as $key => $value) {
            $smartyInstance->assign($key, $value);
        }

        return $smartyInstance;
    }
}
