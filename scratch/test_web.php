<?php
use App\Kernel;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Dotenv\Dotenv;

require __DIR__.'/../vendor/autoload.php';

(new Dotenv())->bootEnv(__DIR__.'/../.env');

$kernel = new Kernel($_SERVER['APP_ENV'], (bool) $_SERVER['APP_DEBUG']);
$kernel->boot();

$container = $kernel->getContainer();
$em = $container->get('doctrine.orm.entity_manager');

// Find any user with ROLE_ADMIN
$users = $em->getRepository(\App\Entity\User::class)->findAll();
$adminUser = null;
foreach ($users as $u) {
    if (in_array('ROLE_ADMIN', $u->getRoles(), true)) {
        $adminUser = $u;
        break;
    }
}

if (!$adminUser && !empty($users)) {
    $adminUser = $users[0];
}

if ($adminUser) {
    echo "Logging in admin user: " . $adminUser->getUsername() . "\n";
    $token = new \Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken($adminUser, 'main', $adminUser->getRoles());
    $container->get('security.token_storage')->setToken($token);
} else {
    echo "No admin user found in DB!\n";
}

$request = Request::create('/admin/corp-assets-visibility', 'GET');
$response = $kernel->handle($request);

echo "HTTP Status: " . $response->getStatusCode() . "\n";
if ($response->getStatusCode() === 302) {
    echo "Redirect URL: " . $response->headers->get('Location') . "\n";
} else {
    echo "HTML content:\n";
    // Look for checkbox labels in the rendered HTML
    $html = $response->getContent();
    if (preg_match_all('/<span[^>]*>\s*(.*?)\s*<\/span>/i', $html, $matches)) {
        print_r($matches[1]);
    } else {
        echo "No span tags found.\n";
    }
    
    // Also output lines containing Leihhangar or Duvolle
    echo "\nMatching lines in HTML:\n";
    $lines = explode("\n", $html);
    foreach ($lines as $line) {
        if (str_contains($line, 'Leihhangar') || str_contains($line, 'Duvolle') || str_contains($line, 'visibility[')) {
            echo trim($line) . "\n";
        }
    }
}
