<?php

use \Psr\Http\Message\ServerRequestInterface as Request;
use \Psr\Http\Message\ResponseInterface as Response;

$app->get('/', function (Request $request, Response $response, $args) {
    // Render index view
    return $this->view->render($response, 'index.latte');
})->setName('index');


$app->post('/test', function (Request $request, Response $response, $args) {
    //read POST data
    $input = $request->getParsedBody();

    //log
    $this->logger->info('Your name: ' . $input['person']);

    return $response->withHeader('Location', $this->router->pathFor('index'));
})->setName('redir');


/* Seznam vsech osob */

$app->get('/persons', function (Request $request, Response $response, $args) {
	$stmt = $this->db->query('SELECT * FROM person ORDER BY first_name'); 

	$tplVars['persons_list'] = $stmt->fetchall(); 
	return $this->view->render($response, 'persons.latte', $tplVars);
});

/* Vyhledavani */
$app->get('/search', function (Request $request, Response $response, $args) {
    $queryParams = $request->getQueryParams();
    if(!empty($queryParams)) {
        $stmt = $this->db->prepare('SELECT * FROM person WHERE lower(first_name) = lower(:fname) OR lower(last_name) = lower(:lname)');
        $stmt->bindParam(':fname', $queryParams['q']);
        $stmt->bindParam(':lname', $queryParams['q']);
        $stmt->execute();
        $tplVars['persons_list'] = $stmt->fetchall(); 

        return $this->view->render($response, 'persons.latte', $tplVars);
    }
})->setname('search');

/* render formulare */
$app->get('/newPerson', function (Request $request, Response $response, $args) {
    return $this->view->render($response, 'newPerson.latte');
})->setname('newPerson');