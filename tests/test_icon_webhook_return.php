<?php
declare(strict_types=1);

/** Regression: the icon delivery hook must never exit its Symcon script. */
$source=(string)file_get_contents(
    dirname(__DIR__).'/IPSAlexaHaussteuerung/resources/helpers/WebHookIcons.php'
);
if ($source === '') throw new RuntimeException('Missing icon webhook source.');
$required=[
    "hash_equals(\$SECRET, \$token)",
    "http_response_code(\$code)",
    "http_response_code(304)",
    "http_response_code(500)",
    "readfile(\$path)",
];
foreach($required as $fragment) {
    if(!str_contains($source,$fragment))
        throw new RuntimeException('Required secure webhook behavior missing: '.$fragment);
}
$tokens=token_get_all($source,TOKEN_PARSE);
foreach($tokens as $token) {
    if (is_array($token) && $token[0]===T_EXIT)
        throw new RuntimeException('WebHookIcons must use return, never exit/die.');
}
if(substr_count($source,"$bad(403);\n        return;")!==1)
    throw new RuntimeException('403 must return after setting the HTTP status.');
if(substr_count($source,"$bad(404);\n        return;")!==2)
    throw new RuntimeException('404 must return after setting the HTTP status.');
echo "PASS: icon webhook ends normally and retains token, 304 and error handling\n";
