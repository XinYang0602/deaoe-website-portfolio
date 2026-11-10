<?php
require (getenv('DEAOE_APP') ?: '/opt/showcase-cms').'/common.php';
if(isset($_GET['products']) || isset($_GET['product'])){require APP.'/products-public.php';exit;}
header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-cache');
echo renderPage(readData('published',defaults()));
