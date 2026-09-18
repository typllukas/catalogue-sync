<?php

declare(strict_types=1);

namespace Architecture\RuleSamples;

final class ReportBuilder
{
    public function createQuery(string $question): string
    {
        return $question;
    }
}
