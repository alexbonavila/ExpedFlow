<?php

namespace App\Support;

use App\Models\ExpedientType;
use App\Models\TaskTemplate;

final class TemplateScope
{
    public static function type(string $type, bool $lock = false): ExpedientType
    {
        $query = ExpedientType::query();

        return ($lock ? $query->lockForUpdate() : $query)->findOrFail(AuthorizationInput::identifier($type));
    }

    public static function template(ExpedientType $type, string $template, bool $lock = false): TaskTemplate
    {
        $query = $type->templates();

        return ($lock ? $query->lockForUpdate() : $query)->findOrFail(AuthorizationInput::identifier($template));
    }
}
