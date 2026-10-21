<?php
/** Shared presentation boundary for legacy module forms; no business logic. */
if (!defined('_PS_VERSION_')) { exit; }
if (!class_exists('JooimPortfolioAdmin', false)) {
    final class JooimPortfolioAdmin
    {
        public static function authorize($module, array $actions, $context)
        {
            $submitted = false;
            foreach ($actions as $action) { $submitted = $submitted || Tools::isSubmit($action); }
            if (!$submitted) { return true; }
            $token = Tools::getValue('token');
            $employee = $context->employee ?? null;
            $shop = (int) $context->shop->id;
            return ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST'
                && is_string($token) && hash_equals(Tools::getAdminTokenLite('AdminModules'), $token)
                && $employee && $employee->can('edit', 'AdminModulesSf')
                && $module->getPermission('configure') && $employee->hasAuthOnShop($shop)
                && (string) Tools::getValue('jooim_portfolio_shop', '') === (string) $shop;
        }

        public static function render($module, $html, $context, $path)
        {
            $shop = (int) $context->shop->id;
            $hidden = '<input type="hidden" name="jooim_portfolio_shop" value="'.$shop.'">';
            // Bind every legacy POST form to the selected shop without changing its payload fields.
            $html = preg_replace_callback('/<form\b[^>]*>/i', static function ($match) use ($hidden) {
                return $match[0].(preg_match('/\bmethod\s*=\s*[\'\"]?post\b/i', $match[0]) ? $hidden : '');
            }, $html);
            $url = htmlspecialchars($path.'views/css/portfolio-admin.css?v=1', ENT_QUOTES, 'UTF-8');
            return '<link rel="stylesheet" href="'.$url.'"><div class="jooim-portfolio-admin">'.$html.'</div>';
        }
    }
}
