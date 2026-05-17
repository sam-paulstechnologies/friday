<?php

namespace App\Services\Slack;

class SlackCommandParser
{
    public function parse(string $text): array
    {
        $text = trim($text);

        if ($text === '' || strtolower($text) === 'help') {
            return ['action' => 'help', 'numbers' => [], 'text' => null, 'date' => null];
        }

        if (preg_match('/^done\s+([0-9,\s]+)$/i', $text, $matches)) {
            return ['action' => 'done', 'numbers' => $this->numbers($matches[1]), 'text' => null, 'date' => null];
        }

        if (preg_match('/^move\s+(\d+)\s+(.+)$/i', $text, $matches)) {
            return ['action' => 'move', 'numbers' => [(int) $matches[1]], 'text' => trim($matches[2]), 'date' => trim($matches[2])];
        }

        if (preg_match('/^block\s+(\d+)\s+(.+)$/i', $text, $matches)) {
            return ['action' => 'block', 'numbers' => [(int) $matches[1]], 'text' => trim($matches[2]), 'date' => null];
        }

        if (preg_match('/^waiting\s+(\d+)\s+(.+)$/i', $text, $matches)) {
            return ['action' => 'waiting', 'numbers' => [(int) $matches[1]], 'text' => trim($matches[2]), 'date' => null];
        }

        if (preg_match('/^note\s+(\d+)\s+(.+)$/i', $text, $matches)) {
            return ['action' => 'note', 'numbers' => [(int) $matches[1]], 'text' => trim($matches[2]), 'date' => null];
        }

        if (preg_match('/^skip\s+(\d+)$/i', $text, $matches)) {
            return ['action' => 'skip', 'numbers' => [(int) $matches[1]], 'text' => null, 'date' => null];
        }

        return ['action' => 'unknown', 'numbers' => [], 'text' => $text, 'date' => null];
    }

    private function numbers(string $value): array
    {
        return collect(preg_split('/[,\s]+/', $value))
            ->filter()
            ->map(fn (string $number) => (int) $number)
            ->filter(fn (int $number) => $number > 0)
            ->values()
            ->all();
    }
}
