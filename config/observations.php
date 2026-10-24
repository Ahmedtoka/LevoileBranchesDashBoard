<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Manager approval gate (Plan H)
    |--------------------------------------------------------------------------
    |
    | When true (default), submitting an observation visit only marks it `submitted` and notifies the
    | managers of the reporting department; the tickets are created when a manager approves the visit
    | (ObservationService::approveVisit). When false, submit creates the tickets at once and the visit
    | is approved by the submitter, as before Plan H.
    |
    */

    'manager_approval' => env('OBS_MANAGER_APPROVAL', true),

];
