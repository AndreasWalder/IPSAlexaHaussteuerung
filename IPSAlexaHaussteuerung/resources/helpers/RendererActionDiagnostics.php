<?php
declare(strict_types=1);

/**
 * Wrap only the existing user-action calls. Keep their return values and
 * permissions unchanged, and never let diagnostics affect a device command.
 */
function iah_renderer_action_dispatch(int $instanceId, string $source): Closure
{
    return static function (string $operation, int $id, mixed $value) use ($instanceId, $source): mixed {
        $result = match ($operation) {
            'RequestAction'   => RequestAction($id, $value),
            'SetValueBoolean' => SetValueBoolean($id, $value),
            'SetValueInteger' => SetValueInteger($id, $value),
            'SetValueFloat'   => SetValueFloat($id, $value),
            'SetValueString'  => SetValueString($id, $value),
        };
        if ($result === true && $instanceId > 0 && function_exists('IAH_RecordAlexaVariableAction')) {
            try {
                IAH_RecordAlexaVariableAction($instanceId, $id, $source, $operation,
                    json_encode($value, JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE));
            } catch (Throwable $ignored) {}
        }
        return $result;
    };
}
