<?php
/**
 * External renderer stub.
 * Customize this script to generate an external response payload.
 */

$payload = json_decode($_IPS['payload'] ?? '{}', true) ?: [];

// Add your own rendering logic here. Returning no data lets the module fall back to its default rendering.
// Example response structure:
// echo json_encode([
//     'speech'    => 'Externe Antwort',
//     'reprompt'  => '',
//     'card'      => [],
//     'apl'       => null,
//     'directives'=> [],
// ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
