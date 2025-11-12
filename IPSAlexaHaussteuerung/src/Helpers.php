<?php
declare(strict_types=1);
namespace IPSAlexaHaussteuerung;
final class Helpers{public static function jsonToArray(string $json,$fallback){$arr=@json_decode($json,true);return is_array($arr)?$arr:$fallback;}}
