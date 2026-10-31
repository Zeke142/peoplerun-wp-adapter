<?php
if(str_starts_with(parse_url($_SERVER['REQUEST_URI'],PHP_URL_PATH)?:'','/o01/')) {require __DIR__.'/http-gateway.php';return true;}
if(str_starts_with(parse_url($_SERVER['REQUEST_URI'],PHP_URL_PATH)?:'','/o01-approval/')) {require __DIR__.'/o01-approval/endpoint.php';return true;}
return false;
