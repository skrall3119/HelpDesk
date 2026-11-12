<?php

return [
    // Only the dedicated integration checkout may be changed by deployment automation.
    'environment' => env('DEPLOYMENT_ENVIRONMENT', 'developer'),
];
