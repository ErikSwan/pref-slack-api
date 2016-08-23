<?php

if(version_compare(phpversion(), '5.4.0') === -1) {
    die('This script requires PHP >= 5.4.0.');
}

/**********************************/
/* PSEF Tours Slackbot Reminder   */
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

// Setup cURL
$curl = curl_init();
curl_setopt_array($curl, [
    CURLOPT_RETURNTRANSFER => TRUE,
    CURLOPT_CONNECTTIMEOUT => 5,
    CURLOPT_POST => TRUE,
    CURLOPT_URL => 'https://psef.slack.com/services/hooks/slackbot?token=' . $tokens['slackbot_remote_token'],
]);


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

// Get a list of Slack users - we're going to need this later
$response = $commander->execute('users.list', [
    'presence' => 0,
]);
$slack_users = $response->getBody()['members'];

// Get all Calendar events for the next hour.
$results = getEventsInNextHour($service, $config['calendar_id']);

foreach($results->getItems() as $event) {

    // Prep the event result
    $event_result = [
            'name' => $event->getSummary(),
            'time' => $event->getStart()->getDateTime(),
            'location' => $event->getLocation(),
    ];

    foreach($event->getAttendees() as $attendee) {
        $email = $attendee->getEmail();

        if($email !== 'officialpsef@gmail.com' &&  (empty($event->getExtendedProperties()) || $event->getExtendedProperties()->getPrivate()['slackbotReminded'] !== 'true')) {
            // Get the Slack user's name
            foreach($slack_users as $user) {
                if($user['profile']['email'] === $email) {
                    // Check if the user has reminders enabled
                    if($users->get($user['id'])) {
                        // If so, post a message as slackbot
                        curl_setopt($curl, CURLOPT_POSTFIELDS,
                            $twig->render('reminders.twig', [
                                'name' => $user['profile']['first_name'],
                                'event' => $event_result,
                        ]));
                        curl_setopt($curl, CURLOPT_URL,
                            'https://psef.slack.com/services/hooks/slackbot?token=' . $tokens['slackbot_remote_token'] . '&channel=' . urlencode('@' . $user['name']));

                        $reminder_status = FALSE;
                        for($i = 0; $i < 3; $i++) {
                            // Try the request up to three times, just to mitigate
                            // HTTP weirdness. Sleep for one second to avoid Slack API
                            // rate limits.
                            sleep(1);
                            if(curl_exec($curl) !== FALSE) {
                                $reminder_status = TRUE;
                                break;
                            }
                        }

                        if($reminder_status) {
                            // Set the event as reminders having been sent!
                            $props = $event->getExtendedProperties();

                            if(empty($props)) {
                                $props = new Google_Service_Calendar_EventExtendedProperties;
                                $props->setPrivate(array());
                            }

                            $props->setPrivate(
                                array_merge($props->getPrivate(), ['slackbotReminded' => 'true'])
                            );

                            $event->setExtendedProperties($props);

                            $service->events->update($config['calendar_id'], $event->getId(), $event);
                        }

                        // For debugging purposes. Cron will send this to a log file
                        echo(sprintf("Reminder sent to @%s for event: %s at %s in %s\n",
                            $user['name'],
                            $event_result['name'],
                            date('n/j/y g:ia', strtotime($event_result['time'])),
                            empty($event_result['location']) ? '(no location)' : $event_result['location']
                        ));
                    }
                }
            }
        }
    }
}
