<?php

// Acts as the app backend: same lib Laravel's reverb broadcaster uses.
require __DIR__.'/../../vendor/autoload.php';
[$_, $id, $key, $secret, $channel, $msg] = $argv;
$p = new Pusher\Pusher($key, $secret, $id, ['host' => '127.0.0.1', 'port' => 8080, 'scheme' => 'http', 'useTLS' => false]);
var_dump($p->trigger($channel, 'OrderPaid', ['msg' => $msg]));
