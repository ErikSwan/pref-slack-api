<?php

if(version_compare(phpversion(), '5.4.0') === -1) {
    die('This script requires PHP >= 5.4.0.');
}

/**********************************/
/* PSEF Tours Slack Slash Command */
/*--------------------------------*/
/* Author: Erik Swan              */
/**********************************/

// This is hacky, I know. If you want to extend it, it would probably be best to
// rebuild it with something like Slim.

// Let Composer autoload Twig
require_once __DIR__ . '/vendor/autoload.php';
// Include some helper functions
require_once __DIR__ . '/includes/calendar.php';

use Flintstone\Flintstone;

// Include configuration
$config = require_once __DIR__ . '/config.php';
// Include auth tokens
$tokens = require_once __DIR__ . '/auth/tokens.php';

if(php_sapi_name() == "cli") {
    // Script is being run from the CLI. Assume this is to setup OAuth with the
    // Google API.
    $client = getClient($config);

    if(file_exists($config['calendar_credentials_path'])) {
        // An access token already exists.
        print("An access token already exists! Exiting...");
    } else {
        // Request authorization from the user.
        $authUrl = $client->createAuthUrl();
        printf("Open the following link in your browser:\n%s\n", $authUrl);
        print('Enter verification code: ');
        $authCode = trim(fgets(STDIN));

        // Exchange authorization code for an access token.
        $accessToken = $client->authenticate($authCode);

        // Store the credentials to disk.
        if(!file_exists(dirname($config['calendar_credentials_path']))) {
          mkdir(dirname($config['calendar_credentials_path']), 0700, true);
        }

        file_put_contents($config['calendar_credentials_path'], $accessToken);
        printf("Credentials saved to %s\n", $config['calendar_credentials_path']);
    }
} else {
    // Setup Twig
    $loader = new Twig_Loader_Filesystem(__DIR__ . '/templates');
    $twig = new Twig_Environment($loader, array(
        'autoescape' => FALSE,
        // 'cache' => __DIR__ . '/../cache',
    ));

    // Setup Flintstone (file db for storing user reminder preferences)
    $users = Flintstone::load('users', [
        'dir' => __DIR__ . '/db',
    ]);

    // Check Slack auth token
    if($_POST['token'] !== $tokens['slack_slash_token']) {
        http_response_code(401); // Unauthorized
        die('Slash command token does not match!');
    }

    // Check that Google API credentials exist
    if(!file_exists($config['calendar_credentials_path'])) {
        http_response_code(404);
        die('Calendar API credentials not found! Run this script from the ' .
            'command line to setup credentials!');
    } else {
        // Setup Google API Client
        $client = getClient($config);
        $accessToken = file_get_contents($config['calendar_credentials_path']);
        $client->setAccessToken($accessToken);

        // Refresh the token if it's expired.
        if ($client->isAccessTokenExpired()) {
            $client->refreshToken($client->getRefreshToken());
            file_put_contents($config['calendar_credentials_path'], $client->getAccessToken());
        }
    }

    // Initialize Slack API
    $interactor = new Frlnc\Slack\Http\CurlInteractor;
    $interactor->setResponseFactory(new Frlnc\Slack\Http\SlackResponseFactory);
    $commander = new Frlnc\Slack\Core\Commander($tokens['slack_api_token'], $interactor);

    // Initialize Google Calendar API
    $service = new Google_Service_Calendar($client);

    // Set the timezone
    date_default_timezone_set($config['timezone']);

    $text = strtolower(trim($_POST['text']));

    // Get a list of Slack users - we're going to need this later
    $response = $commander->execute('users.list', [
        'presence' => 0,
    ]);
    $slack_users = $response->getBody()['members'];

    // Main conditional for parsing command syntax
    if(!empty($text)) {

        // HELP
        if(strpos($text, 'help') === 0) {
            // Output help text.
            die($twig->render('help.twig'));
        }

        // REMINDERS ENABLE/DISABLE
        else if(strpos($text, 'reminders') === 0) {
            $param = explode(' ', $text)[1]; // should be 'enable' or 'disable'
            $prev_state = $users->get($_POST['user_id']);

            if($param === 'enable') {
                $users->set($_POST['user_id'], 1);
                die($twig->render('reminder-status.twig', ['prev_state' => $prev_state, 'state' => TRUE]));
            } else if($param === 'disable') {
                $users->set($_POST['user_id'], 0);
                die($twig->render('reminder-status.twig', ['prev_state' => $prev_state, 'state' => FALSE]));
            } else {
                die($twig->render('error.twig', ['error' => 'invalid_syntax']));
            }
        }

        // @PERSON and ME
        else if(substr($text, 0, 1) === '@' || $text === 'me') {
            // Parameter is a user

            if($text === 'me') {
                $search_type = 'me';
                foreach($slack_users as $member) {
                    if($member['id'] === $_POST['user_id']) {
                        // Found our member!
                        $search_user = [
                            'email' => $member['profile']['email'],
                            'real_name' => $member['profile']['real_name'],
                            'id' => $member['id'],
                            'name' => $member['name'],
                        ];
                        $results = getEventsByUser($service, $config, $config['calendar_id'],
                            $search_user['email']);
                        break;
                    }
                }
                if(!isset($search_user)) {
                    die("Couldn't find you in the members list. This shouldn't happen.");
                }
            } else {
                $search_type = 'user';
                // Get user's email address and use it as the search string
                foreach($slack_users as $member) {
                    if($member['name'] === substr($text, 1)) {
                        // Found our member!
                        $search_user = [
                            'email' => $member['profile']['email'],
                            'real_name' => $member['profile']['real_name'],
                            'id' => $member['id'],
                            'name' => $member['name'],
                        ];
                        $results = getEventsByUser($service, $config, $config['calendar_id'],
                            $search_user['email']);
                        break;
                    }
                }
                if(empty($search_user)) {
                    die($twig->render('error.twig', [
                        'error' => 'no_user',
                        'user' => $text
                    ]));
                }
            }
        } else {
            // Assume parameter is a date
            $search_type = 'date';

            $date = strtotime($text);
            if($date === FALSE) {
                // They didn't pass a user, and we couldn't parse the date string.
                // Throw an error message!
                die($twig->render('error.twig', ['error' => 'invalid_syntax']));
            } else {
                // Valid date
                $results = getEventsByDate($service, $config['calendar_id'], $date);
            }
        }
    } else {
        $search_type = 'date';
        $text = 'today';

        // Get events for today
        $date = strtotime('today');
        $results = getEventsByDate($service, $config['calendar_id'], $date);
    }

    if(count($results->getItems()) == 0) {
        // No results!
        die($twig->render('error.twig', [
            'error' => 'no_results',
            'type' => $search_type,
            'date' => isset($date) ? date('l, F j', $date) : '',
            'user' => $text
        ]));
    } else {
        // Prepare the results array
        $output_results = [];
        foreach($results->getItems() as $event) {
            $event_result = [
                'name' => $event->getSummary(),
                'time' => $event->getStart()->getDateTime(),
                'location' => $event->getLocation(),
                'attendees' => [],
            ];
            foreach($event->getAttendees() as $attendee) {
                $email = $attendee->getEmail();

                $real_name = NULL;
                $id = NULL;
                $real_name = NULL;

                if($email !== 'officialpsef@gmail.com') {
                    // Get the Slack user's display name
                    foreach($slack_users as $user) {
                        if($user['profile']['email'] === $email) {
                            $real_name = $user['profile']['real_name'];
                            $id = $user['id'];
                            $name = $user['name'];
                        }
                    }
                    array_push($event_result['attendees'], [
                        'id' => $id,
                        'name' => $name,
                        'real_name' => $real_name,
                        'email' => $email,
                    ]);
                }
            }

            $timestamp = (new DateTime($event->getStart()->getDateTime()))->setTime(0,0,0)->getTimestamp();
            if(!isset($output_results[$timestamp])) {
                $output_results[$timestamp]['datestring'] = relativeDate($timestamp);
		$output_results[$timestamp]['date'] = $timestamp;
                $output_results[$timestamp]['events'] = [];
            }
            array_push($output_results[$timestamp]['events'], $event_result);
        }
    }

    // Render the template!
    echo $twig->render('results.twig', [
        'results' => $output_results,
        'type' => $search_type,
        'input' => $text,
        'date' => isset($date) ? date('l, F j', $date) : '',
        'user' => $search_user
    ]);
}
