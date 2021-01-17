<?php

use \Psr\Http\Message\ServerRequestInterface as Request;
use \Psr\Http\Message\ResponseInterface as Response;

include 'location.php';

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
})->setname('persons');

/* Vyhledavani */
$app->get('/search', function (Request $request, Response $response, $args) {
    /*parametry URL*/
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
$app->get('/person', function (Request $request, Response $response, $args) {
    $tplVars['formData'] = [
        'first_name' => '',
        'last_name' => '',
        'nickname' => '',
        'gender' => '',
        'height' => '',
        'birth_day' => '',
        'street_name' => '',
        'street_number' => '',
        'zip' => '',
        'city' => ''
    ];

    return $this->view->render($response, 'newPerson.latte', $tplVars);
})->setname('newPerson');

/* update */
$app->get('/person/update', function (Request $request, Response $response, $args) {
    $params = $request->getqueryParams();

    if (!empty($params['id_person'])) {
        $stmt = $this->db->prepare('SELECT * FROM person LEFT JOIN location USING (id_location) WHERE id_person = :id_person');
        $stmt->bindValue(':id_person', $params['id_person']);
        $stmt->execute();
        $tplVars['formData'] = $stmt->fetch();
        if(empty($tplVars['formData'])) {
            exit('person not found');
        } else {
            return $this->view->render($response, 'updatePerson.latte', $tplVars);
        }
    }
})->setname('updatePerson');

$app->post('/person/update', function (Request $request, Response $response, $args) {
    $id_person = $request->getQueryParam('id_person');
    $formData = $request->getParsedBody();
    $tplVars = [];
    if ( empty($formData['first_name']) || empty($formData['last_name']) || empty($formData['nickname']) ) {
        $tplVars['message'] = 'Please fill required fields';
    } else {
        try {
            if(!empty($formData['street_name']) || !empty($formData['street_number']) || !empty($formData['zip']) || !empty($formData['zip'])) {

                $stmt = $this->db->prepare("SELECT id_location FROM person WHERE id_person = :id_person");
                $stmt->bindValue(':id_person', $id_person);
                $stmt->execute();

                $id_location = $stmt->fetch()['id_location'];

                if($id_location) {
                    #ma adresu
                    editLocation($this, $id_location, $formData);
                } else {
                    #nema adresu
                    $id_location = newLocation($this, $formData);
                }
            }


            $stmt = $this->db->prepare("UPDATE person SET 
                                                first_name = :first_name,  
                                                last_name = :last_name,
                                                nickname = :nickname,
                                                birth_day = :birth_day,
                                                gender = :gender,
                                                height = :height,
                                                id_location = :id_location
                                        WHERE id_person = :id_person");
            $stmt->bindValue(':nickname', $formData['nickname']);
            $stmt->bindValue(':first_name', $formData['first_name']);
            $stmt->bindValue(':last_name', $formData['last_name']);
            $stmt->bindValue(':id_location', $id_location ? $id_location : null);
            $stmt->bindValue(':gender', empty($formData['gender']) ? null : $formData['gender'] );
            $stmt->bindValue(':birth_day', empty($formData['birth_day']) ? null : $formData['birth_day']);
            $stmt->bindValue(':height', empty($formData['height']) ? null : $formData['height']);
            $stmt->bindValue(':id_person', $id_person);
            $stmt->execute();

            $tplVars['message'] = 'Person successfully updated';
        } catch (PDOexception $e) {
            $tplVars['message'] = 'Error occured, sorry jako';
            $this->logger->error($e->getMessage());
        }
    }
    $tplVars['formData'] = $formData;
    return $this->view->render($response, 'updatePerson.latte', $tplVars);
});

/* zpracovani formulare */
$app->post('/person', function (Request $request, Response $response, $args) {
    /*telo pozadavku*/
    $formData = $request->getParsedBody();
    $tplVars = ['formData' => ''];

    if (empty($formData['first_name']) || empty($formData['last_name']) || empty($formData['nickname'])) {
        $tplVars['message'] = 'Please field required fields';
    } else {
        try {
            $this->db->beginTransaction();

            if(!empty($formData['street_name']) || !empty($formData['street_number']) || !empty($formData['zip']) || !empty($formData['zip'])) {
                #nema adresu
                $id_location = newLocation($this, $formData);
            }

            $stmt = $this->db->prepare("INSERT INTO person(nickname, first_name, last_name, id_location, birth_day, height, gender) VALUES (:nickname, :first_name, :last_name,:id_location, :birth_day, :height, :gender)");

            $stmt->bindValue(':nickname', $formData['nickname']);
            $stmt->bindValue(':first_name', $formData['first_name']);
            $stmt->bindValue(':last_name', $formData['last_name']);
            $stmt->bindValue(':id_location', $id_location ? $id_location : null);
            $stmt->bindValue(':birth_day', empty($formData['birth_day']) ? null : $formData['birth_day']);
            $stmt->bindValue(':height', empty($formData['height']) ? null : $formData['height']);
            $stmt->bindValue(':gender', empty($formData['gender']) ? null : $formData['gender']);
            $stmt->execute();
            $tplVars['message'] = 'Person successfully added';
            $this->db->commit();
        } catch (PDOException $e) {
            $tplVars['message'] = 'Error occured, working on it';
            /*log erroru*/
            $this->logger->error($e->getMessage());

            /*zachovani hodnot formu*/
            $tplVars['formData'] = $formData;
            $this->db->rollback();
        }
    }

    return $this->view->render($response, 'newPerson.latte', $tplVars);
});


/* mazani osob */

$app->post('/persons/delete', function (Request $request, Response $response, $args ) {
    $id_person = $request->getQueryParam('id_person');
    if (!empty($id_person)) {
        try {
            $stmt = $this->db->prepare('DELETE FROM contact WHERE id_person = :id_person');
            $stmt = $this->db->prepare('DELETE FROM person_meeting WHERE id_person = :id_person');
            $stmt = $this->db->prepare('DELETE FROM relation WHERE id_person = :id_person');
            $stmt = $this->db->prepare('DELETE FROM person WHERE id_person = :id_person');
            $stmt->bindValue(':id_person', $id_person);
            $stmt->execute();
        } catch (PDOException $e) {
            $this->logger->error($e->getMessage());
            exit('error occured');
        }
    } else {
        exit('id_person is missing');
    }

    return $response->withHeader('Location', $this->router->pathFor('persons'));
})->setname('person_delete');



# OTHER