<?php

return [
    'linear_state_on_create_from_jira' => 'Backlog',

    'status_map' => [
        'jira_to_linear' => [
            'Backlog'     => 'Backlog',
            'Todo'        => 'Todo',
            'To Do'       => 'Todo',
            'In Progress' => 'In Progress',
            'Done'        => 'Done',
        ],
        'linear_to_jira' => [
            'Backlog'     => 'Backlog',
            'Todo'        => 'Todo',
            'In Progress' => 'In Progress',
            'In Review'   => 'Done',
            'Done'        => 'Done',
            'Canceled'    => 'Done',
            'Duplicate'   => 'Done',
        ],
    ],
];
