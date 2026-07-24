<?php
use App\Kernel;
use Symfony\Component\Dotenv\Dotenv;

require dirname(__DIR__).'/vendor/autoload.php';

if (file_exists(dirname(__DIR__).'/.env.local')) {
    (new Dotenv())->bootEnv(dirname(__DIR__).'/.env.local');
} else {
    (new Dotenv())->bootEnv(dirname(__DIR__).'/.env');
}

$kernel = new Kernel($_SERVER['APP_ENV'], (bool) $_SERVER['APP_DEBUG']);
$kernel->boot();
$container = $kernel->getContainer();
$sdeService = $container->get(\App\Service\SdeService::class);
$details = $sdeService->getBlueprintDetails(46161, 9);
print_r($details);
