<?php
/** Presentation contract; synthetic values only, no platform, network or storage. */
define('_PS_VERSION_', '8.2.0');
require __DIR__.'/../classes/PortfolioAdmin.php';
$checks = 0;
function check($condition, $label) {
    global $checks;
    ++$checks;
    if (!$condition) { throw new RuntimeException($label); }
}
$module = (object) ['name'=>'example','displayName'=>'Example <module>','version'=>'1.2.3'];
$definition = ['form'=>['input'=>[
    ['type'=>'switch','name'=>'enabled','label'=>'Enable <feed>','hint'=>'Explanation'],
    ['type'=>'switch','name'=>'optional','label'=>'Optional','desc'=>'Existing help'],
    ['type'=>'select','name'=>'mode','label'=>'Mode','options'=>['query'=>[['id'=>0],['id'=>1]]]],
    ['type'=>'password','name'=>'key','label'=>'Key'],
    ['type'=>'textarea','name'=>'pem','label'=>'PEM','rows'=>8],
], 'submit'=>['title'=>'Save']]];
$converted = JooimPortfolioAdmin::fields($definition, ['enabled'=>1,'optional'=>0,'key'=>'']);
$inputs = $converted['form']['input'];
check($inputs[0]['type']==='html', 'Switch uses native HelperForm HTML slot');
check(strpos($inputs[0]['html_content'],'role="switch"')!==false, 'Native checkbox switch');
check(strpos($inputs[0]['html_content'],'Enable &lt;feed&gt;')!==false, 'Escaped translated label');
check(strpos($inputs[0]['html_content'],'value="1" checked')!==false, 'Enabled current value');
check(strpos($inputs[1]['html_content'],' checked')===false, 'Disabled current value');
check($inputs[0]['desc']==='Explanation' && $inputs[1]['desc']==='Existing help', 'Existing purpose/help preserved');
check($inputs[2]===$definition['form']['input'][2], 'Two-value enum remains a select');
check($inputs[3]['type']==='html' && strpos($inputs[3]['html_content'],'type="password"')!==false && strpos($inputs[3]['html_content'],'value="" autocomplete="new-password"')!==false, 'Credential replacement stays empty with new-password autocomplete');
$pem=$inputs[4]; unset($pem['form_group_class']);
check($pem===$definition['form']['input'][4], 'Long PEM field remains multiline');
check($converted['form']['submit']['class']==='btn btn-primary', 'Explicit primary save');
check($definition['form']['input'][0]['type']==='switch', 'Caller definition unchanged');
foreach ([0,1] as $value) {
    $toggle=JooimPortfolioAdmin::toggle('enabled', 'Enabled', $value);
    $doc=new DOMDocument(); $doc->loadHTML($toggle);
    $pairs=[];
    foreach ($doc->getElementsByTagName('input') as $input) {
        if ($input->getAttribute('type')==='checkbox' && !$input->hasAttribute('checked')) { continue; }
        $pairs[]=urlencode($input->getAttribute('name')).'='.urlencode($input->getAttribute('value'));
    }
    parse_str(implode('&',$pairs),$post);
    check($post['enabled']===(string)$value, 'Actual PHP form decoding preserves zero/one');
    check(strpos($toggle,'for="jooim-toggle-enabled"')!==false, 'Label references checkbox');
}
$form='<form method="post" action="/admin?token=synthetic"><input name="amount" value="2"></form>';
$html=JooimPortfolioAdmin::presentation($module, '<div class="panel jooim-support">Support</div><div class="panel jooim-updates">Updates</div><!--jooim-settings-->'.$form, '/modules/example/');
check(strpos($html,'Example &lt;module&gt;')!==false,'Metadata escaped');
check(strpos($html,'v1.2.3')!==false,'Actual module version shown');
check(strpos($html,$form)!==false,'Exact form payload and action preserved');
check(strpos($html,'jooim-settings')>strpos($html,'jooim-updates'),'Support/update/settings order');
check(substr_count($html,'<form')===1,'No nested or duplicated forms');
check(strpos($html,'<!--jooim-settings-->')===false,'Explicit marker consumed');
check(strpos(JooimPortfolioAdmin::presentation($module,'<div class="alert alert-warning">Read only</div>','/modules/example/'),'jooim-heading')===false,'Read-only/error output does not invent settings');
echo 'PASS '.$checks." presentation/form contract checks\n";
