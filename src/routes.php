<?php

use \Psr\Http\Message\ServerRequestInterface as Request;
use \Psr\Http\Message\ResponseInterface as Response;

include 'location.php';

define('PASSWD_SALT', 'x3201kkafh!');



function validateToken($db, $token) {
	$stmt = $db->prepare('SELECT * FROM auth_users WHERE token = :token');
	$stmt->bindValue(':token', $token);
	$stmt->execute();
	$auth = $stmt->fetch();
	# Vrati true ak je token platny (=existuje v db)
	return !empty($auth['token']);
}


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


# Nacitaj regigstracny formular
$app->get('/register', function (Request $request, Response $response, $args) {
	$tplVars = [
		'formData' => [
			'email' => '',
			'password' => ''
		],
		'title' => 'Registration'
	];

	return $this->view->render($response, 'sign.latte', $tplVars);
})->setName('register');


# Registracia uzivatela
$app->post('/register', function (Request $request, Response $response, $args) {
	$formData = $request->getParsedBody();
	# Overime ci uzivatel uz nahodou neexistuje
	$stmt = $this->db->prepare("SELECT * FROM auth_users WHERE email = :email");
	$stmt->bindValue(':email', $formData['email']);
	$stmt->execute();
	$user = $stmt->fetch();
	if (!empty($user['email'])) {
		$tplVars['message'] = 'Sorry, email is already used';
		return $this->view->render($response, 'sign.latte', $tplVars);
	} else {
		try {
			# Nahodne vygenerovany token
			$token = bin2hex(random_bytes(20));
			$this->db->beginTransaction();
			$stmt = $this->db->prepare("INSERT INTO auth_users (email, password, token) VALUES (:email, :password, :token)");
			$stmt->bindValue(':email', $formData['email']);
			$stmt->bindValue(':password', md5(PASSWD_SALT . $formData['password']));
			$stmt->bindValue(':token', $token);
			$stmt->execute();

			# Ulozime cookie pre autentifikovaneho uzivatela
			setcookie("token", $token, 0);
			$this->db->commit();
			return $response->withHeader('Location', $this->router->pathFor('persons'));
		} catch (PDOexception $e) {
			$tplVars['message'] = 'Error occured, sorry jako';
			$tplVars['formData'] = $formData;
			$this->logger->error($e->getMessage());
			$this->db->rollback();
			return $this->view->render($response, 'sign.latte', $tplVars);
		}
	}
});


# Nacitaj prihlasovaci formular
$app->get('/login', function (Request $request, Response $response, $args) {
	$tplVars = [
		'formData' => [
			'email' => '',
			'password' => ''
		],
		'title' => 'Login'
	];
	return $this->view->render($response, 'sign.latte', $tplVars);
})->setName('login');


# Registracia uzivatela
$app->post('/login', function (Request $request, Response $response, $args) {
	$formData = $request->getParsedBody();
	# Overime ci uzivatel uz nahodou neexistuje
	$stmt = $this->db->prepare("SELECT * FROM auth_users WHERE email = :email AND password = :password");
	$stmt->bindValue(':email', $formData['email']);
	$stmt->bindValue(':password', md5(PASSWD_SALT . $formData['password']));
	$stmt->execute();
	$user = $stmt->fetch();
	if (empty($user['email'])) {
		$tplVars['message'] = 'Sorry, email or password is incorrect';
		return $this->view->render($response, 'sign.latte', $tplVars);
	} else {
		try {
			# Nahodne vygenerovany token
			$token = bin2hex(random_bytes(20));
			$this->db->beginTransaction();
			$stmt = $this->db->prepare("UPDATE auth_users SET token = :token WHERE id_users = :id_users");
			$stmt->bindValue(':token', $token);
			$stmt->bindValue(':id_users', $user['id_users']);
			$stmt->execute();
			$this->db->commit();

			# Ulozime cookie pre autentifikovaneho uzivatela
			setcookie("token", $token, 0);
			return $response->withHeader('Location', $this->router->pathFor('persons'));
		} catch (PDOexception $e) {
			$tplVars['message'] = 'Error occured, sorry jako';
			$tplVars['formData'] = $formData;
			$this->logger->error($e->getMessage());
			$this->db->rollback();
			return $this->view->render($response, 'sign.latte', $tplVars);
		}
	}
});


/* logout */
$app->get('/logout', function (Request $request, Response $response, $args) {
	setcookie("token", "", time() - 3600); 
	return $response->withHeader('Location', $this->router->pathFor('index'));
})->setName('logout');



# Vsetky routy v tejto skupine budu dostupne LEN s platnym tokenom
# zaroven dostanu prefix /auth
# Je dolezite POUZIVAT set name a odvolavat sa na linky ce link
$app->group('/auth', function() use($app) {

	/* Zoznam vsech osob v DB */
	$app->get('/persons', function (Request $request, Response $response, $args) {
		// protect();
		$params = $request->getQueryParams();
		if (empty($params['limit'])) {
			$params['limit'] = 10;
		};
		if (empty($params['page'])) {
			$params['page'] = 0;
		}
		$stmt = $this->db->query('SELECT count(*) pocet FROM person');
		$total_pages = $stmt->fetch()['pocet'];
		$stmt = $this->db->prepare('SELECT * FROM person ORDER BY first_name LIMIT :limit OFFSET :offset'); # toto vrati len DB objekt, nie vysledok!
		$stmt->bindValue(':limit', $params['limit']);
		$stmt->bindValue(':offset', $params['page'] * $params['limit']);
		$stmt->execute();
		$tplVars = [
			'persons_list' => $stmt->fetchall(), #[ ['id_person' => 1, 'first_name' => 'Alice' ... ], ['id_person' => 2, 'first_name' => 'Bob' ... ] . ]
			'total_pages' => $total_pages / $params['limit'],
			'page' => $params['page'],
			'limit' => $params['limit']
		];
		return $this->view->render($response, 'persons.latte', $tplVars);
	})->setName('persons');


	/* nacitanie formularu */
	$app->get('/person/update', function (Request $request, Response $response, $args) {
		# Skontrolujeme ci je uzivatel autorizovany na danu akciu


		$params = $request->getQueryParams(); # $params = [id_person => 1232, firstname => aaa]
		if (! empty($params['id_person'])) {
			$stmt = $this->db->prepare('SELECT * FROM person 
										LEFT JOIN location USING (id_location) 
										WHERE id_person = :id_person');
			$stmt->bindValue(':id_person', $params['id_person']);
			$stmt->execute();
			$tplVars['formData'] = $stmt->fetch();
			if (empty($tplVars['formData'])) {
				exit('person not found');
			} else {
				return $this->view->render($response, 'updatePerson.latte', $tplVars);
			}
		}
	})->setName('updatePerson');


	$app->post('/person/update', function (Request $request, Response $response, $args) {
		$id_person = $request->getQueryParam('id_person');
		$formData = $request->getParsedBody();
		$tplVars = [];
		if ( empty($formData['first_name']) || empty($formData['last_name']) || empty($formData['nickname']) ) {
			$tplVars['message'] = 'Please fill required fields';
		} else {
			try {
				# Kontrolujeme ci bola aspon jedna cast adresy vyplnena
				if ( !empty($formData['street_name']) || !empty($formData['street_number']) || !empty($formData['city']) || !empty($formData['zip']) ) {

					$stmt = $this->db->prepare('SELECT id_location FROM person WHERE id_person = :id_person');
					$stmt->bindValue(':id_person', $id_person);
					$stmt->execute();
					$id_location = $stmt->fetch()['id_location']; # {'id_location' => 123}
					if ($id_location) {
						## Osoba ma adresu (id_location IS NOT NULL)
						editLocation($this, $id_location, $formData);
					} else {
						## Osoba nema adresu (id_location NULL)
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
				$stmt->bindValue(':id_location',  $id_location ? $id_location : null);
				$stmt->bindValue(':gender', empty($formData['gender']) ? null : $formData['gender'] );
				$stmt->bindValue(':birth_day', empty($formData['birth_day']) ? null : $formData['birth_day']);
				$stmt->bindValue(':height', empty($formData['height']) ? null : $formData['height']);
				$stmt->bindValue(':id_person', $id_person);
				$stmt->execute();

			} catch (PDOexception $e) {
				$tplVars['message'] = 'Error occured, sorry jako';
				$this->logger->error($e->getMessage());
			}
		}
		$tplVars['formData'] = $formData;
		return $this->view->render($response, 'updatePerson.latte', $tplVars);
	});


	/* Delete osob */
	$app->post('/persons/delete', function (Request $request, Response $response, $args) {
		$id_person = $request->getQueryParam('id_person');
		if (!empty($id_person)) {
			try {
				# delete from contact
				# delete from person_meeting
				# delete from relation 

				$stmt = $this->db->prepare('DELETE FROM person WHERE id_person = :id_person');
				$stmt->bindValue(':id_person', $id_person);
				$stmt->execute();
			} catch (PDOexception $e) {
				$this->logger->error($e->getMessage());
				exit('error occured');
			}
		} else {
			exit('is person is missing');
		}
		return $response->withHeader('Location', $this->router->pathFor('persons'));
	})->setName('person_delete');


	/* nacitanie formularu */
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
	})->setName('newPerson');


	/* spracovanie formu po odoslani */
	$app->post('/person', function (Request $request, Response $response, $args) {
		$formData = $request->getParsedBody();
		$tplVars = [];
		if ( empty($formData['first_name']) || empty($formData['last_name']) || empty($formData['nickname']) ) {
			$tplVars['message'] = 'Please fill required fields';
		} else {
			try {
				$this->db->beginTransaction();

				if ( !empty($formData['street_name']) || !empty($formData['street_number']) || !empty($formData['city']) || !empty($formData['zip']) ) {			
					## Osoba nema adresu (id_location NULL)
					$id_location = newLocation($this, $formData);
				}

				$stmt = $this->db->prepare("INSERT INTO person (nickname, first_name, last_name, id_location, birth_day, height, gender) VALUES (:nickname, :first_name, :last_name, :id_location, :birth_day, :height, :gender)");	
				$stmt->bindValue(':nickname', $formData['nickname']);
				$stmt->bindValue(':first_name', $formData['first_name']);
				$stmt->bindValue(':last_name', $formData['last_name']);
				$stmt->bindValue(':id_location', $id_location ? $id_location : null);
				$stmt->bindValue(':gender', empty($formData['gender']) ? null : $formData['gender'] ) ;
				$stmt->bindValue(':birth_day', empty($formData['birth_day']) ? null : $formData['birth_day']);
				$stmt->bindValue(':height', empty($formData['height']) ? null : $formData['height']);
				$stmt->execute();
				$tplVars['message'] = 'Person succefully added';
				$this->db->commit();

			} catch (PDOexception $e) {
				$tplVars['message'] = 'Error occured, sorry jako';
				$this->logger->error($e->getMessage());
				$tplVars['formData'] = $formData;
				$this->db->rollback();
			}
		}
		return $this->view->render($response, 'newPerson.latte', $tplVars);
	});


	$app->get('/search', function (Request $request, Response $response, $args) {
		$queryParams = $request->getQueryParams(); # [kluc => hodnota]
		if(! empty($queryParams) ) {
			$stmt = $this->db->prepare("SELECT * FROM person WHERE lower(first_name) = lower(:fname) OR lower(last_name) = lower(:lname)");
			$stmt->bindParam(':fname', $queryParams['q']);
			$stmt->bindParam(':lname', $queryParams['q']);
			$stmt->execute();
			$tplVars['persons_list'] = $stmt->fetchall();
			return $this->view->render($response, 'persons.latte', $tplVars);
		}
	})->setName('search');


### AUTH HANDLER ###
})->add(function($request, $response, $next) {
	# Vynutenie autentizacie
	if (empty($_COOKIE['token']) || !validateToken($this->db, $_COOKIE['token'])) {
		return $response->withHeader('Location', $this->router->pathFor('login'));	
	} else {
		return $next($request, $response);
	}
});















