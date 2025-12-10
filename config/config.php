<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Default storage disk
    |--------------------------------------------------------------------------
    |
    | The filesystem disk used to read files unless a different disk is passed
    | explicitly to the Twig filter/function.
    |
    */

    'disk' => env('SECRET_DEFAULT_DISK', 'media'),

    /*
    |--------------------------------------------------------------------------
    | Default expiry (minutes)
    |--------------------------------------------------------------------------
    |
    | Number of minutes temporary links are valid if no explicit duration is
    | passed to the Twig filter/function.
    |
    */

    'expiry' => (int) env('SECRET_DEFAULT_EXPIRY', 15),

    /*
    |--------------------------------------------------------------------------
    | Delete after download by default
    |--------------------------------------------------------------------------
    |
    | If true, files will be removed from the disk after a successful streamed
    | download, unless you override it in the Twig call.
    |
    */

    'delete_after_download' => (bool) env('SECRET_DELETE_AFTER_DOWNLOAD', false),

];