<?php

return [

  'api_per_minute' => (int) env('RATE_LIMIT_API_PER_MINUTE', 120),

  'auth_per_minute' => (int) env('RATE_LIMIT_AUTH_PER_MINUTE', 10),

  'ai_per_hour' => (int) env('RATE_LIMIT_AI_PER_HOUR', 20),

  'email_per_hour' => (int) env('RATE_LIMIT_EMAIL_PER_HOUR', 5),

  'booking_per_hour' => (int) env('RATE_LIMIT_BOOKING_PER_HOUR', 10),

];
