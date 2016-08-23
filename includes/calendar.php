<?php

define('SCOPES', implode(' ', array(
  Google_Service_Calendar::CALENDAR)
));

function getClient($config) {
    $client = new Google_Client();
    $client->setApplicationName($config['application_name']);
    $client->setScopes(SCOPES);
    $client->setAuthConfigFile($config['calendar_client_secret_path']);
    $client->setAccessType('offline');
    return $client;
}

function getEventsByUser($service, $config, $calendar_id, $user_email) {
    $optParams = [
        'maxResults' => $config['max_events'],
        'orderBy' => 'startTime',
        'singleEvents' => TRUE,
        'timeMin' => date('c'),
        'q' => $user_email,
    ];

    return $service->events->listEvents($calendar_id, $optParams);
}

function getEventsByDate($service, $calendar_id, $date) {
    $dateTime = new DateTime();
    $dateTime->setTimestamp($date)->setTime(0,0,0);

    $optParams = [
        'maxResults' => 20,
        'orderBy' => 'startTime',
        'singleEvents' => TRUE,
        'timeMin' => $dateTime->format('c'),
        'timeMax' => $dateTime->add(new DateInterval('P1D'))->format('c'),
    ];

    return $service->events->listEvents($calendar_id, $optParams);
}

function getEventsInNextHour($service, $calendar_id) {
    $dateTime = new DateTime();
    $dateTime->add(new DateInterval('PT1H'));

    $optParams = [
        'orderBy' => 'startTime',
        'singleEvents' => TRUE,
        'timeMin' => $dateTime->format('c'),
        'timeMax' => $dateTime->add(new DateInterval('PT1S'))->format('c'),
    ];

    return $service->events->listEvents($calendar_id, $optParams);
}

function relativeDate($ts) {
    if(!ctype_digit($ts)) $ts = strtotime($ts);

    $diff = (new DateTime())->setTime(0,0,0)->getTimestamp() - $ts;
    if($diff >= 0) {
        $day_diff = floor($diff / 86400);
        if($day_diff == 0) return 'today';
        if($day_diff == 1) return 'yesterday';
        if($day_diff < 7) return $day_diff . ' days ago';
        if($day_diff < 31) return ceil($day_diff / 7) . ' weeks ago';
        if($day_diff < 60) return 'last month';
        return date('F Y', $ts);
    } else {
        $diff = abs($diff);
        $day_diff = floor($diff / 86400);
        if($day_diff == 0) return 'today';
        if($day_diff == 1) return 'tomorrow';
        if($day_diff < 4) return date('l', $ts);
        if($day_diff < 7 + (7 - date('w'))) return date('l', $ts) . ' next week';
        if(ceil($day_diff / 7) < 4) return 'in ' . ceil($day_diff / 7) . ' weeks';
        if(date('n', $ts) == date('n') + 1) return 'next month';
        return date('F Y', $ts);
    }
}
