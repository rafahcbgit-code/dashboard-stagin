<?php

namespace Maatwebsite\Excel\Concerns;

use PhpOffice\PhpSpreadsheet\Cell\Cell;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Shared\StringHelper;

trait DisableIntelligentFormatting
{
    public function bindValue(Cell $cell, $value)
    {
        // sanitize UTF-8 strings
        if (is_string($value)) {
            $value = StringHelper::sanitizeUTF8($value);
        }

        // Set value explicit
        $cell->setValueExplicit($value, DataType::TYPE_STRING);

        // Done!
        return true;
    }
}