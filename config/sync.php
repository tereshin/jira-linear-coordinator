<?php

return [
    'linear_state_on_create_from_jira' => 'Backlog',

    'status_map' => [
        'jira_to_linear' => [
            'To Do'       => 'Todo',
            'In Progress' => 'In Progress',
            'Done'        => 'Done',
        ],
        'linear_to_jira' => [
            'Todo'        => 'To Do',
            'In Progress' => 'In Progress',
            'Done'        => 'Done',
        ],
    ],
];
