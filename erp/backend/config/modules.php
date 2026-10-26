<?php

/*
| Modules an organization can switch off (organization settings → modules).
| A switched-off module's permissions count as not held, its self-service
| routes answer 403, and the app hides its menu entries. Core modules
| (organization, structure, users, roles, employees) cannot be switched off.
*/

return [
    'toggleable' => ['students', 'attendance', 'leave', 'daily_work', 'tasks', 'performance', 'letters', 'finance', 'renewals', 'vehicles', 'maintenance', 'requests', 'grievances', 'hostels'],
];
