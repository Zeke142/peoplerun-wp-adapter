<?php
if(str_starts_with(parse_url($_SERVER['REQUEST_URI'],PHP_URL_PATH)?:'','/o01/')) {require __DIR__.'/http-gateway.php';return true;}
return false;
