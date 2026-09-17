<?php

include 'RestService/Server.php';

use RestService\Server;

Server::create('/')
    ->addGetRoute('test', function(){
        return 'Yay!';
    })
    ->addGetRoute('foo/(.*)', function($bar){
        return $bar;
    })
->run();

?>