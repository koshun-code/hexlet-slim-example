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
/**
 * function return all users from file
 */
function getAllUsers()
{
    $fileUsers = explode(PHP_EOL, file_get_contents(PATH));
    $map = array_map(fn($filterUser) => json_decode($filterUser, true), $fileUsers);
    return array_filter($map, fn($value) => !is_null($value));
}
/**
 * function return one user use id from file
 */
function getOneUser($id)
{
    $users = getAllUsers();
    //return array_values(array_filter($users, fn($user) => $user['id'] === $id));
    foreach ($users as $user) {
        if ($user['id'] === $id) {
            return $user;
        }
    }
    return false;
}
/**
 * function delete user, when find one in collection
 */
function deleteUser($id)
{
    $users = getAllUsers();
    $data =  array_filter($users, fn($user) => $user['id'] != $id);
    return save($data);
}
/**
 * function save data in file
 */
function save($data)
{
    $innerData = array_map(fn($value) => json_encode($value). PHP_EOL, $data);
    file_put_contents(PATH, $innerData);
}
/*
    function change user data
*/
function changeUser($id, $data)
{
  $users = getAllUsers();
  $changeUser = array_reduce($users, function ($acc, $user) use ($id, $data) {
    if ($user['id'] == $id) {
      $user['nickname'] = $data['nickname'];
      $user['email'] = $data['email'];
      $acc[] = $user;
    } else {
      $acc[] = $user;
    }
    return $acc;
  }, []);
  return save($changeUser);
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

$users = [
    ['name' => 'admin', 'passwordDigest' => hash('sha256', 'secret')],
    ['name' => 'mike', 'passwordDigest' => hash('sha256', 'superpass')],
    ['name' => 'kate', 'passwordDigest' => hash('sha256', 'strongpass')]
];
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
$router = $app->getRouteCollector()->getRouteParser();

$app->get('/', function ($request, $response) {
    $messages = $this->get('flash')->getMessages();
    $params = ['flash' => $messages ];
    return $this->get('renderer')->render($response, 'index.phtml', $params);
})->setName('main');

$app->post('/session', function ($request, $response) use ($users, $router) {
    ['name' => $name, 'password' => $password] = $request->getParsedBodyParam('user');
     foreach ($users as $user) {
         if ($user['name'] === $name && $user['passwordDigest'] === hash('sha256', $password)) {
             $_SESSION['user'] = $user;
             return $response->withRedirect($router->urlFor('main'));
         } else {
             $message = $this->get('flash')->addMessage('warning', 'Wrong password or name');
             return $response->withRedirect($router->urlFor('main'));
         }
     }
});

$app->delete('/session', function ($request, $response) use ($router) {
    $_SESSION = [];
    session_destroy();
    return $response->withRedirect($router->urlFor('main'));
});

$app->get('/users', function ($request, $response)  { //use ($users)
    $term = $request->getQueryParam('term');
    $users = getAllUsers();
    $filterUsers = array_filter($users, function ($user) use ($term) {
        return (empty($term)) ? true : s($user['nickname'])->ignoreCase()->containsAny($term);
    });
    $flash = $this->get('flash')->getMessages();
    $params = [
        'users' => $filterUsers,
        'term' => $term,
        'flash' => $flash
    ];
    return $this->get('renderer')->render($response, 'users/index.phtml', $params);
})->setName('users');

$app->post('/users', function ($request, $response) use ($router) {
    
    $users = $request->getParsedBodyParam('user');
    $errors = validate($users, ['nickname', 'email']);
    if (count($errors) === 0) {
        $readFile = file_get_contents(PATH);
        $users['id'] = uniqid();
        $readFile .= json_encode($users). PHP_EOL;
        $file = file_put_contents(PATH, $readFile);
        $this->get('flash')->addMessage('success', 'user added');
        return $response->withRedirect($router->urlFor('users'));
    }
    //var_dump($errors);
    $params = ['users' => $users, 'errors' => $errors];
    return $this->get('renderer')->render($response, 'users/new.phtml', $params);
});

$app->delete('/users/{id}', function ($request, $response, $args) use ($router) {
    $id = $args['id'];
    $deleteUser = deleteUser($id);
    $this->get('flash')->addMessage('success', 'User has been deleted');
    return $response->withRedirect($router->urlFor('users'));   
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

$app->get('/users/{id}/edit', function ($request, $response, $args) {
    $id = $args['id'];
    $user = getOneUser($id);
    //var_dump($user); die();
    $params = ['user' => $user, 'errors' => []];
    return $this->get('renderer')->render($response, 'users/edit.phtml', $params);
});

$app->patch('/users/{id}', function ($request, $response, array $args) use ($router) {
    $id = $args['id'];
    $data = $request->getParsedBodyParam('user');
    $errors = validate($data, ['nickname', 'email']);
    //var_dump($errors); die();
    if (count($errors) === 0) {
        changeUser($id, $data);
        $this->get('flash')->addMessage('success', 'User has been updated');
        return $response->withRedirect($router->urlFor('users'));
    }
    $user = getOneUser($id);
    $params = ['user' => $user, 'errors' => $errors];
    return $this->get('renderer')->render($response, 'users/edit.phtml', $params);
});
$app->run();