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
	$this->view->render($response, 'persons.latte', $tplVars);
});