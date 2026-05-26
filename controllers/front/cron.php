<?php
/**
 * HTTP cron endpoint for JooIM DB Cleaner.
 */
class Jooim_dbcleanerCronModuleFrontController extends ModuleFrontController
{
    public $ssl = true;
    public $display_header = false;
    public $display_footer = false;

    public function initContent()
    {
        parent::initContent();

        header('Content-Type: application/json; charset=utf-8');

        $token = Tools::getValue('token');

        if (!$this->module->isValidCronToken($token)) {
            http_response_code(403);
            die(json_encode(array('status' => 'error', 'message' => 'Invalid token')));
        }

        require_once _PS_MODULE_DIR_.$this->module->name.'/classes/JooimDbcleanerCleaner.php';
        $cleaner = new JooimDbcleanerCleaner($this->module);
        $result = $cleaner->run('http');

        die(json_encode($result));
    }
}
