<?php

require __DIR__. '/../vendor/autoload.php';

use Slim\Factory\AppFactory;
use DI\Container;
use Slim\Middleware\MethodOverrideMiddleware;
use function Funct\Collection\flattenAll;
use function Funct\Collection\reject;
use function Funct\Collection\toJson;
use function Symfony\Component\String\s;

session_start();

const PATH =  __DIR__ . '/../files/users.txt';

function getAllUsers()
{
    $fileUsers = explode(PHP_EOL, file_get_contents(PATH));
    return array_map(fn($filterUser) => json_decode($filterUser, true), $fileUsers);
}
function getOneUser($id)
{
    $users = getAllUsers();
    return array_values(array_filter($users, fn($user) => $user['id'] === $id));
}
/**
 * function delete user, when find one in collection
 */
function deleteUser($id)
{

}
/**
 * Function validate data with simple rule
 */
function validate(array $data, array $params)
{
    $errors = [];
    foreach ($params as $value) {
        if (empty($data[$value])) {
            $errors[$value] = "{$value} can't be empty";
        }
    }
    return $errors;
}
$container = new Container();
$container->set('renderer', function () {
    // Параметром передается базовая директория, в которой будут храниться шаблоны
    return new \Slim\Views\PhpRenderer(__DIR__ . '/../templates');
});
$container->set('flash', function () {
    return new \Slim\Flash\Messages();
});

//$app = AppFactory::createFromContainer($container);
//$app->addErrorMiddleware(true, true, true);
AppFactory::setContainer($container);
$app = AppFactory::create();
$app->addRoutingMiddleware();
$methodOverrideMiddleware = new MethodOverrideMiddleware();
$app->add($methodOverrideMiddleware);

$app->get('/', function ($request, $response) {
    return $this->get('renderer')->render($response, 'index.phtml');
})->setName('main');

$app->get('/users', function ($request, $response)  { //use ($users)
    $term = $request->getQueryParam('term');
    $users = getAllUsers();
    //var_dump($users);
   // die();
    $filterUsers = array_filter($users, function ($user) use ($term) {
        return (empty($term)) ? true : s($user['nickname'])->ignoreCase()->containsAny($term);
    });
    $flash = $this->get('flash')->getMessages();
    //var_dump($filterUsers);
    $params = [
        'users' => $filterUsers,
        'term' => $term,
        'flash' => $flash
    ];
    return $this->get('renderer')->render($response, 'users/index.phtml', $params);
})->setName('users');

$app->post('/users', function ($request, $response) {
    
    $users = $request->getParsedBodyParam('user');
    $errors = validate($users, ['nickname', 'email']);
    if (count($errors) === 0) {
        $readFile = file_get_contents(PATH);
        $users['id'] = uniqid();
        $readFile .= json_encode($users). PHP_EOL;
        $file = file_put_contents(PATH, $readFile);
        $this->get('flash')->addMessage('success', 'user added');
        return $response->withRedirect('users');
    }
    //var_dump($errors);
    $params = ['users' => $users, 'errors' => $errors];
    return $this->get('renderer')->render($response, 'users/new.phtml', $params);
});

$app->delete('/users/{id}', function ($request, $response, $args) {
    $id = $args['id'];
    $users = getAllUsers();
    $withoutUser = array_values(reject($users, fn($user) => $user['id'] === $id));
    $ws = array_map(fn($wu) => file_put_contents(PATH, json_encode($wu)), $withoutUser);
    //$users = deleteUser($id);
    $this->get('flash')->addMessage('success', 'User has been deleted');
    return $response->withRedirect('/users');
});
$app->get('/users/new', function ($request, $response) {
    return $this->get('renderer')->render($response, 'users/new.phtml');
});

$app->get('/users/{id}', function ($request, $response, $args) {
    $id = $args['id'];
    $user = getOneUser($id);
    //var_dump($user);
    $params = ['user' => $user];
    return $this->get('renderer')->render($response, 'users/show.phtml', $params);
});

$app->run();