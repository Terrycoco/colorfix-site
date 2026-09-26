<?php
declare(strict_types=1);

namespace App\DOCUMENTS\Services;

final class DocumentMergeService
{
    /**
     * @param array<string, string> $values
     */
    public function render(
        ?string $template,
        array $values
    ): string {
        $output =
            (string)($template ?? '');

        if ($output === '') {
            return '';
        }

        $replace = [];

        foreach ($values as $key => $value) {
            $replace[
                '{{' . $key . '}}'
            ] = $value;
        }

        return strtr(
            $output,
            $replace
        );
    }
}
