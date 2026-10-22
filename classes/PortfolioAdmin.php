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
            return self::presentation($module, $html, $path);
        }
        /** Explicit boundary supplied by each renderer; never parse or rearrange forms. */
        public static function presentation($module, $html, $path)
        {
            $parts = explode('<!--jooim-settings-->', $html, 2);
            if (count($parts) === 2) {
                $html = $parts[0].'<section class="panel jooim-settings">'
                    .'<header class="jooim-heading">'.(is_file(__DIR__.'/../logo.png') ? '<img width="64" height="64" alt="" src="'.self::escape($path.'logo.png').'">' : '')
                    .'<div><h2>'.self::escape($module->displayName).'</h2><span class="jooim-version">v'.self::escape($module->version).'</span></div></header>'
                    .$parts[1].'</section>';
            }
            $url = self::escape($path.'views/css/portfolio-admin.css?v=2');
            return '<link rel="stylesheet" href="'.$url.'"><div class="jooim-portfolio-admin">'.$html.'</div>';
        }

        public static function escape($value)
        {
            return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        }

        /** Label is already translated by the calling module, never translated twice. */
        public static function toggle($name, $label, $value, $id = '')
        {
            $id = $id !== '' ? $id : 'jooim-toggle-'.$name;
            return '<div class="jooim-toggle-row"><label for="'.self::escape($id).'">'.self::escape($label).'</label>'
                .'<span class="jooim-toggle"><input type="hidden" name="'.self::escape($name).'" value="0">'
                .'<input type="checkbox" role="switch" id="'.self::escape($id).'" name="'.self::escape($name).'" value="1"'.((string) $value === '1' ? ' checked' : '').'>'
                .'<span class="jooim-toggle-track" aria-hidden="true"></span></span></div>';
        }

        public static function password($name, $label, $help)
        {
            $id = 'jooim-secret-'.$name;
            return '<div class="jooim-secret-field"><label for="'.self::escape($id).'">'.self::escape($label).'</label>'
                .'<input type="password" id="'.self::escape($id).'" name="'.self::escape($name).'" value="" autocomplete="new-password" aria-describedby="'.self::escape($id).'_help">'
                .'<p class="help-block" id="'.self::escape($id).'_help">'.self::escape($help).'</p></div>';
        }

        /** Native HelperForm HTML slot, preserving all non-boolean input definitions. */
        public static function fields(array $definition, array $values)
        {
            foreach ($definition['form']['input'] as &$input) {
                if (in_array($input['type'] ?? '', ['password', 'textarea', 'file'], true)) {
                    $input['form_group_class'] = trim(($input['form_group_class'] ?? '').' jooim-wide-field');
                }
                if (($input['type'] ?? '') === 'password') {
                    $input['html_content'] = self::password($input['name'], $input['label'], $input['desc'] ?? '');
                    $input['type'] = 'html';
                    unset($input['label'], $input['desc']);
                    continue;
                }
                if (($input['type'] ?? '') !== 'switch') { continue; }
                $input['html_content'] = self::toggle($input['name'], $input['label'], $values[$input['name']] ?? 0);
                $input['type'] = 'html';
                $input['form_group_class'] = trim(($input['form_group_class'] ?? '').' jooim-toggle-field');
                if (empty($input['desc']) && !empty($input['hint'])) { $input['desc'] = $input['hint']; }
                unset($input['label'], $input['hint'], $input['values'], $input['is_bool']);
            }
            unset($input);
            if (isset($definition['form']['submit'])) {
                $definition['form']['submit']['class'] = 'btn btn-primary';
            }
            return $definition;
        }

    }
}
