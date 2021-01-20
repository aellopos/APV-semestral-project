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
    if (!empty($queryParams)) {
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
        if (empty($tplVars['formData'])) {
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
    if (empty($formData['first_name']) || empty($formData['last_name']) || empty($formData['nickname'])) {
        $tplVars['message'] = 'Please fill required fields';
    } else {
        try {
            if (!empty($formData['street_name']) || !empty($formData['street_number']) || !empty($formData['zip']) || !empty($formData['zip'])) {

                $stmt = $this->db->prepare("SELECT id_location FROM person WHERE id_person = :id_person");
                $stmt->bindValue(':id_person', $id_person);
                $stmt->execute();

                $id_location = $stmt->fetch()['id_location'];

                if ($id_location) {
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
            $stmt->bindValue(':gender', empty($formData['gender']) ? null : $formData['gender']);
            $stmt->bindValue(':birth_day', empty($formData['birth_day']) ? null : $formData['birth_day']);
            $stmt->bindValue(':height', empty($formData['height']) ? null : $formData['height']);
            $stmt->bindValue(':id_person', $id_person);
            $stmt->execute();

            $tplVars['message'] = 'Person successfully updated';
        } catch (PDOexception $e) {
            $tplVars['message'] = 'Error occured';
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

            if (!empty($formData['street_name']) || !empty($formData['street_number']) || !empty($formData['zip']) || !empty($formData['zip'])) {
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

$app->post('/persons/delete', function (Request $request, Response $response, $args) {
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



/* CONTACT */


/* Zobrazit */
$app->get('/contact', function (Request $request, Response $response, $args) {
    $id_person = $request->getQueryParam('id_person');

    if (!empty($id_person)) {
        $stmt = $this->db->prepare('SELECT * FROM person LEFT JOIN contact USING (id_person) LEFT JOIN contact_type USING (id_contact_type) WHERE id_person = :id_person');
        $stmt->bindValue(':id_person', $id_person);
        $stmt->execute();
        $tplVars['contactData'] = $stmt->fetchall();
        $tplVars['person'] = ["id_person" => $id_person];
        if (empty($tplVars['contactData'])) {
            exit('person not found');
        } else {
            return $this->view->render($response, 'profileContact.latte', $tplVars);
        }
    }
})->setname('viewContact');

/* Pridat */

$app->get('/contact/new', function (Request $request, Response $response, $args) {
    $id_person = $request->getQueryParam('id_person');

    $tplVars['contactData'] = [
        'contact' => '',
        'name' => '',
        'id_person' => $id_person
    ];

    return $this->view->render($response, 'newContact.latte', $tplVars);
})->setname('newContact');

$app->post('/contact/new', function (Request $request, Response $response, $args) {
    $formData = $request->getParsedBody();
    $tplVars = ['formData' => ''];

    if (empty($formData['contact_name'])) {
        $tplVars['message'] = 'Please field required fields';
    } else {
        try {
            $stmt = $this->db->prepare("INSERT INTO contact(id_person, id_contact_type, contact) VALUES (:id_person, :id_contact_type, :contact)");

            $stmt->bindValue(':id_person', $formData['id_person']);
            $stmt->bindValue(':id_contact_type', $formData['contact_type']);
            $stmt->bindValue(':contact', $formData['contact_name']);
            $stmt->execute();
            $tplVars['message'] = 'Contact successfully added';
        } catch (PDOException $e) {
            $tplVars['message'] = 'Error occured, working on it';
            /*log erroru*/
            $this->logger->error($e->getMessage());

            /*zachovani hodnot formu*/
            $tplVars['formData'] = $formData;
        }
    }

    return $this->view->render($response, 'newContact.latte', $tplVars);
});


/* Upravit */

$app->get('/contact/update', function (Request $request, Response $response, $args) {
    $params = $request->getqueryParams();

    if (!empty($params['id_contact'])) {
        $stmt = $this->db->prepare('SELECT * FROM contact LEFT JOIN contact_type USING (id_contact_type) WHERE id_contact = :id_contact');
        $stmt->bindValue(':id_contact', $params['id_contact']);
        $stmt->execute();
        $tplVars['contactData'] = $stmt->fetch();
        if (empty($tplVars['contactData'])) {
            exit('contact not found');
        } else {
            return $this->view->render($response, 'updateContact.latte', $tplVars);
        }
    }
})->setname('updateContact');

$app->post('/contact/update', function (Request $request, Response $response, $args) {
    $id_contact = $request->getQueryParam('id_contact');
    $formData = $request->getParsedBody();
    $tplVars = [];
    if (empty($formData['contact_name'])) {
        $tplVars['message'] = 'Please fill required fields';
    } else {
        try {
            $stmt = $this->db->prepare("UPDATE contact SET 
                                                contact = :contact,  
                                                id_contact_type = :id_contact_type
                                        WHERE id_contact = :id_contact");
            $stmt->bindValue(':contact', $formData['contact_name']);
            $stmt->bindValue(':id_contact_type', $formData['contact_type']);
            $stmt->bindValue(':id_contact', $id_contact);
            $stmt->execute();

            $tplVars['message'] = 'Contact successfully updated';
        } catch (PDOexception $e) {
            $tplVars['message'] = 'Error occured';
            $this->logger->error($e->getMessage());
        }
    }
    $tplVars['formData'] = $formData;
    return $this->view->render($response, 'updateContact.latte', $tplVars);
});

/* Smazat */

$app->post('/contact/delete', function (Request $request, Response $response, $args) {
    $id_contact = $request->getQueryParam('id_contact');
    if (!empty($id_contact)) {
        try {
            $stmt = $this->db->prepare('DELETE FROM contact WHERE id_contact = :id_contact');
            $stmt->bindValue(':id_contact', $id_contact);
            $stmt->execute();
        } catch (PDOException $e) {
            $this->logger->error($e->getMessage());
            exit('error occured');
        }
    } else {
        exit('id_person is missing');
    }

    return $response->withHeader('Location', $this->router->pathFor('persons'));
})->setname('contact_delete');


/* RELATIONSHIP */

/* Zobrazit */

$app->get('/relation', function (Request $request, Response $response, $args) {
    $id_person = $request->getQueryParam('id_person');

    if (!empty($id_person)) {
        $stmt = $this->db->prepare('SELECT * FROM relation LEFT JOIN relation_type USING (id_relation_type) 
        LEFT JOIN person ON relation.id_person2 = person.id_person WHERE relation.id_person1 = :id_person');
        $stmt->bindValue(':id_person', $id_person);
        $stmt->execute();
        $tplVars['relationData'] = $stmt->fetchall();
        $tplVars['person'] = ["id_person" => $id_person];
        if (empty($tplVars['relationData'])) {
            exit('This person does not have any relationships');
        } else {
            return $this->view->render($response, 'profileRelation.latte', $tplVars);
        }
    }
})->setname('viewRelation');

/* Pridat */

$app->get('/relation/new', function (Request $request, Response $response, $args) {
    $id_person = $request->getQueryParam('id_person');
    $stmt = $this->db->prepare('SELECT id_person, first_name, last_name FROM person');
    $stmt->execute();
    $tplVars['relationData'] = $stmt->fetchall();

    $tplVars['person'] = ["id_person" => $id_person];

    return $this->view->render($response, 'newRelation.latte', $tplVars);
})->setname('newRelation');

$app->post('/relation/new', function (Request $request, Response $response, $args) {
    $formData = $request->getParsedBody();
    $tplVars = ['formData' => ''];

    if (empty($formData['person_name'])) {
        $tplVars['message'] = 'Please field required fields';
    } else {
        try {
            $stmt = $this->db->prepare("INSERT INTO relation(id_person1, id_person2, description, id_relation_type) VALUES (:id_person1, :id_person2, :description, :id_relation_type)");
            $stmt->bindValue(':id_person1', $formData['id_person']);
            $stmt->bindValue(':id_person2', $formData['person_name']);
            $stmt->bindValue(':id_relation_type', $formData['relation_type']);
            $stmt->bindValue(':description', $formData['description']);
            $stmt->execute();
            $tplVars['message'] = 'Contact successfully added';
        } catch (PDOException $e) {
            $tplVars['message'] = 'Error occured, working on it';
            /*log erroru*/
            $this->logger->error($e->getMessage());

            /*zachovani hodnot formu*/
            $tplVars['formData'] = $formData;
        }
    }

    return $this->view->render($response, 'newRelation.latte', $tplVars);
});

/* Upravit */

$app->get('/relation/update', function (Request $request, Response $response, $args) {
    $params = $request->getqueryParams();

    if (!empty($params['id_relation'])) {
        $stmt = $this->db->prepare('SELECT * FROM relation LEFT JOIN relation_type USING (id_relation_type) LEFT JOIN person ON relation.id_person2 = person.id_person WHERE id_relation = :id_relation');
        $stmt->bindValue(':id_relation', $params['id_relation']);
        $stmt->execute();
        $tplVars['relationData'] = $stmt->fetch();
        if (empty($tplVars['relationData'])) {
            exit('relation not found');
        } else {
            return $this->view->render($response, 'updateRelation.latte', $tplVars);
        }
    }
})->setname('updateRelation');

$app->post('/relation/update', function (Request $request, Response $response, $args) {
    $id_relation = $request->getQueryParam('id_relation');
    $formData = $request->getParsedBody();
    $tplVars = [];

    try {
        $stmt = $this->db->prepare("UPDATE relation SET  
                                                id_relation_type = :id_relation_type,
                                                description = :description
                                        WHERE id_relation = :id_relation");
        $stmt->bindValue(':id_relation_type', $formData['relation_type']);
        $stmt->bindValue(':description', $formData['description']);
        $stmt->bindValue(':id_relation', $id_relation);
        $stmt->execute();

        $tplVars['message'] = 'Relation successfully updated';
    } catch (PDOexception $e) {
        $tplVars['message'] = 'Error occured';
        $this->logger->error($e->getMessage());
    }

    $tplVars['relationData'] = $formData;
    return $this->view->render($response, 'updateRelation.latte', $tplVars);
});

/* Smazat */

$app->post('/relation/delete', function (Request $request, Response $response, $args) {
    $id_relation = $request->getQueryParam('id_relation');
    if (!empty($id_relation)) {
        try {
            $stmt = $this->db->prepare('DELETE FROM relation WHERE id_relation = :id_relation');
            $stmt->bindValue(':id_relation', $id_relation);
            $stmt->execute();
        } catch (PDOException $e) {
            $this->logger->error($e->getMessage());
            exit('error occured');
        }
    } else {
        exit('id_relation is missing');
    }

    return $response->withHeader('Location', $this->router->pathFor('persons'));
})->setname('relation_delete');

/* MEETINGS */

/* Seznam vsech schuzek */

$app->get('/meetings', function (Request $request, Response $response, $args) {
    $stmt = $this->db->query('SELECT * FROM meeting LEFT JOIN location ON meeting.id_location = location.id_location');

    $tplVars['meeting_list'] = $stmt->fetchall();
    return $this->view->render($response, 'meetings.latte', $tplVars);
})->setname('meetings');

/* Ukaz schuzku */

$app->get('/meetings/update', function (Request $request, Response $response, $args) {
    $params = $request->getqueryParams();

    if (!empty($params['id_meeting'])) {
        $stmt = $this->db->prepare('SELECT * FROM meeting LEFT JOIN person_meeting USING (id_meeting) LEFT JOIN person ON person_meeting.id_person = person.id_person
        LEFT JOIN location ON meeting.id_location = location.id_location WHERE meeting.id_meeting = :id_meeting');
        $stmt->bindValue(':id_meeting', $params['id_meeting']);
        $stmt->execute();
        $tplVars['meetingData'] = $stmt->fetch();

        if (empty($tplVars['meetingData'])) {
            exit('meeting not found');
        } else {
            return $this->view->render($response, 'showMeeting.latte', $tplVars);
        }
    }
})->setname('showMeeting');

/* Pridej ucastnika */

$app->get('/meeting/new', function (Request $request, Response $response, $args) {
    $id_meeting = $request->getQueryParam('id_meeting');
    $stmt = $this->db->prepare('SELECT id_person, first_name, last_name FROM person');
    $stmt->execute();
    $tplVars['personData'] = $stmt->fetchall();

    $tplVars['meeting'] = ["id_meeting" => $id_meeting];

    return $this->view->render($response, 'attendeeForm.latte', $tplVars);
})->setname('newAttendee');

$app->post('/meeting/new', function (Request $request, Response $response, $args) {
    $formData = $request->getParsedBody();
    $tplVars = ['formData' => ''];

    try {
        $stmt = $this->db->prepare("INSERT INTO person_meeting(id_person, id_meeting) VALUES (:id_person, :id_meeting)");
        $stmt->bindValue(':id_person', $formData['person_name']);
        $stmt->bindValue(':id_meeting', $formData['id_meeting']);
        $stmt->execute();
        $tplVars['message'] = 'Contact successfully added';
    } catch (PDOException $e) {
        $tplVars['message'] = 'Error occured, working on it';
        /*log erroru*/
        $this->logger->error($e->getMessage());

        /*zachovani hodnot formu*/
        $tplVars['formData'] = $formData;
    }


    return $this->view->render($response, 'attendeeForm.latte', $tplVars);
});


/* mazani schuzek */

$app->post('/meetings/delete', function (Request $request, Response $response, $args) {
    $id_meeting = $request->getQueryParam('id_meeting');
    if (!empty($id_meeting)) {
        try {
            $stmt = $this->db->prepare('DELETE FROM meeting WHERE id_meeting = :id_meeting');
            $stmt->bindValue(':id_meeting', $id_meeting);
            $stmt->execute();
        } catch (PDOException $e) {
            $this->logger->error($e->getMessage());
            exit('error occured');
        }
    } else {
        exit('id_meeting is missing');
    }

    return $response->withHeader('Location', $this->router->pathFor('meetings'));
})->setname('meeting_delete');