<?php
function deaoeAddressRows(array $values): string {
    $schema=fields();
    $lines=preg_split('/\R/u', (string)($values['address']??$schema['address']['default']));
    $links=preg_split('/\R/u', (string)($values['address_map_urls']??''));
    $oldLink=trim((string)($values['map_url']??''));
    $svg='<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" aria-hidden="true"><path d="M20 10c0 6-8 12-8 12S4 16 4 10a8 8 0 1 1 16 0Z"/><circle cx="12" cy="10" r="2.6"/></svg>';
    $html='';
    foreach($lines as $i=>$line){
        $line=trim($line);if($line==='')continue;
        $url=trim($links[$i]??'');
        if($i===0 && $url==='' && !in_array(rtrim($oldLink,'/'),['','https://map.baidu.com','http://map.baidu.com','#'],true))$url=$oldLink;
        if(!filter_var($url,FILTER_VALIDATE_URL) || !in_array(strtolower(parse_url($url,PHP_URL_SCHEME)??''),['http','https'],true)){
            $query=preg_replace('/^[^：:]{1,20}[：:]\s*/u','',$line);
            $url='https://map.baidu.com/search/'.rawurlencode($query).'/';
        }
        $html.='<p class="address-line">'.h($line).'&nbsp;<a class="address-map" href="'.h($url).'" target="_blank" rel="noopener noreferrer" aria-label="'.h('在百度地图查看：'.$line).'" title="查看地图">'.$svg.'</a></p>';
    }
    return $html;
}
