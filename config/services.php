<?php

return [

    'postmark' => ['key' => env('POSTMARK_API_KEY')],
    'resend' => ['key' => env('RESEND_API_KEY')],
    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    /*
    | Payments (FR-10.4). Online payment is optional: offline collection by the
    | service agent (cash / UPI / cheque / bank transfer) is the primary flow.
    */
    'payments' => [
        'default' => env('PAYMENT_GATEWAY', 'razorpay'),
    ],
    'razorpay' => [
        'key_id' => env('RAZORPAY_KEY_ID'),
        'key_secret' => env('RAZORPAY_KEY_SECRET'),
        'webhook_secret' => env('RAZORPAY_WEBHOOK_SECRET'),
    ],

    /*
    | Messaging provider per channel: log | msg91 | twilio (sms), log | meta | twilio
    | (whatsapp), log | fcm (push). "log" writes to the application log only.
    */
    'messaging' => [
        'sms' => env('SMS_DRIVER', 'log'),
        'whatsapp' => env('WHATSAPP_DRIVER', 'log'),
        'push' => env('PUSH_DRIVER', 'log'),
    ],
    'msg91' => [
        'auth_key' => env('MSG91_AUTH_KEY'),
        'template_id' => env('MSG91_TEMPLATE_ID'),
    ],
    'twilio' => [
        'sid' => env('TWILIO_SID'),
        'token' => env('TWILIO_TOKEN'),
        'from' => env('TWILIO_FROM'),
    ],
    'whatsapp' => [
        'token' => env('WHATSAPP_TOKEN'),
        'phone_number_id' => env('WHATSAPP_PHONE_NUMBER_ID'),
    ],
    'fcm' => [
        'credentials' => env('FCM_CREDENTIALS', storage_path('app/private/fcm.json')),
        // Servon Manager's Firebase project, if it has its own; falls back to the one above.
        'manager_credentials' => env('FCM_MANAGER_CREDENTIALS', storage_path('app/private/fcm-manager.json')),
    ],

    'maps' => [
        'google_key' => env('GOOGLE_MAPS_KEY'),
    ],

];
