<?php

return [
    'linear_state_on_create_from_jira' => 'Backlog',

    'status_map' => [
        'jira_to_linear' => [
            'Backlog'     => 'Backlog',
            'Todo'        => 'Todo',
            'To Do'       => 'Todo',
            'К выполнению' => 'Todo',
            'In Progress' => 'In Progress',
            'В работе'    => 'In Progress',
            'Done'        => 'Done',
            'Готово'      => 'Done',
            'Черновик'    => 'Draft',
            'Draft'       => 'Draft',
        ],
        'linear_to_jira' => [
            'Backlog'     => 'Backlog',
            'Todo'        => 'К выполнению',
            'In Progress' => 'В работе',
            'In Review'   => 'Готово',
            'Done'        => 'Готово',
            'Canceled'    => 'Готово',
            'Duplicate'   => 'Готово',
        ],
    ],
];
