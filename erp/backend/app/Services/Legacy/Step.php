<?php

namespace App\Services\Legacy;

/** One kind of old data moved into the ERP (erp/docs/LEGACY-IMPORT.md). */
interface Step
{
    public function key(): string;

    public function run(LegacyImport $import): void;
}
