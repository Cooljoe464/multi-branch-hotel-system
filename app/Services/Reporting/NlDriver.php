<?php

namespace App\Services\Reporting;

interface NlDriver
{
    /**
     * @return array{sql: string, bindings: list<mixed>, columns: list<string>, kind: string, title: string}|null
     *                                                                                                            Null when the question matches nothing the driver understands.
     */
    public function parse(string $question, string $from, string $to): ?array;
}
