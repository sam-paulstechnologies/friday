<?php

namespace App\Services\Ai;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

class AiSnapshotImageService
{
    public function generate(array $context, string $recommendation): string
    {
        if (! extension_loaded('gd')) {
            throw new \RuntimeException('The GD PHP extension is required to generate AI snapshots.');
        }

        $width = 1400;
        $height = 960;
        $image = imagecreatetruecolor($width, $height);

        $colors = [
            'bg' => imagecolorallocate($image, 248, 250, 252),
            'panel' => imagecolorallocate($image, 255, 255, 255),
            'border' => imagecolorallocate($image, 203, 213, 225),
            'text' => imagecolorallocate($image, 15, 23, 42),
            'muted' => imagecolorallocate($image, 71, 85, 105),
            'blue' => imagecolorallocate($image, 37, 99, 235),
            'red' => imagecolorallocate($image, 220, 38, 38),
            'amber' => imagecolorallocate($image, 217, 119, 6),
            'green' => imagecolorallocate($image, 22, 163, 74),
            'row' => imagecolorallocate($image, 241, 245, 249),
        ];

        imagefilledrectangle($image, 0, 0, $width, $height, $colors['bg']);

        $scope = $context['scope']['name'] ?? 'All Tasks';
        $this->text($image, 40, 34, 'Friday AI Snapshot', $colors['text'], 5);
        $this->text($image, 40, 68, now()->toDateTimeString().' | Scope: '.$scope, $colors['muted'], 4);

        $this->kpis($image, $context['summary'], $colors);
        $this->tasks($image, $context['tasks'], $colors);
        $this->waiting($image, $context['waiting_delegated'], $colors);

        $this->box($image, 40, 866, 1320, 54, $colors);
        $this->text($image, 62, 884, 'Recommendation: '.$this->truncate($recommendation, 160), $colors['blue'], 3);

        $directory = storage_path('app/ai-snapshots');
        File::ensureDirectoryExists($directory);

        $path = $directory.'/ai-snapshot-'.now()->format('Ymd-His').'-'.uniqid().'.png';
        imagepng($image, $path);
        imagedestroy($image);

        return $path;
    }

    private function kpis(\GdImage $image, array $summary, array $colors): void
    {
        $cards = [
            ['Open', $summary['open'], $colors['blue']],
            ['Overdue', $summary['overdue'], $colors['red']],
            ['Due today', $summary['due_today'], $colors['amber']],
            ['Due week', $summary['due_this_week'], $colors['green']],
            ['Urgent/high', $summary['urgent_high'], $colors['red']],
            ['No due', $summary['no_due_date'], $colors['muted']],
        ];

        foreach ($cards as $index => [$label, $value, $color]) {
            $x = 40 + ($index * 220);
            $this->box($image, $x, 122, 200, 86, $colors);
            $this->text($image, $x + 16, 144, (string) $label, $colors['muted'], 3);
            $this->text($image, $x + 16, 172, (string) $value, $color, 5);
        }
    }

    private function tasks(\GdImage $image, array $tasks, array $colors): void
    {
        $this->box($image, 40, 236, 1320, 420, $colors);
        $this->text($image, 62, 258, 'Top Focus Tasks', $colors['text'], 4);
        $this->text($image, 62, 294, 'ID', $colors['muted'], 2);
        $this->text($image, 126, 294, 'Priority', $colors['muted'], 2);
        $this->text($image, 236, 294, 'Due', $colors['muted'], 2);
        $this->text($image, 360, 294, 'Project', $colors['muted'], 2);
        $this->text($image, 640, 294, 'Task', $colors['muted'], 2);

        foreach (array_slice($tasks, 0, 10) as $index => $task) {
            $y = 322 + ($index * 30);
            if ($index % 2 === 1) {
                imagefilledrectangle($image, 56, $y - 4, 1344, $y + 18, $colors['row']);
            }

            $this->text($image, 62, $y, (string) $task['id'], $colors['text'], 2);
            $this->text($image, 126, $y, $this->truncate((string) $task['priority'], 12), $colors['text'], 2);
            $this->text($image, 236, $y, (string) ($task['due_date'] ?? 'No due'), $colors['text'], 2);
            $this->text($image, 360, $y, $this->truncate((string) ($task['project'] ?? 'No project'), 32), $colors['text'], 2);
            $this->text($image, 640, $y, $this->truncate((string) $task['title'], 74), $colors['text'], 2);
        }
    }

    private function waiting(\GdImage $image, array $tasks, array $colors): void
    {
        $this->box($image, 40, 684, 1320, 150, $colors);
        $this->text($image, 62, 706, 'Waiting / Delegated', $colors['text'], 4);

        if ($tasks === []) {
            $this->text($image, 62, 744, 'No waiting candidates in this scope.', $colors['muted'], 3);
            return;
        }

        foreach (array_slice($tasks, 0, 4) as $index => $task) {
            $this->text($image, 62, 744 + ($index * 24), '#'.$task['id'].' '.$this->truncate($task['title'], 130), $colors['muted'], 3);
        }
    }

    private function box(\GdImage $image, int $x, int $y, int $w, int $h, array $colors): void
    {
        imagefilledrectangle($image, $x, $y, $x + $w, $y + $h, $colors['panel']);
        imagerectangle($image, $x, $y, $x + $w, $y + $h, $colors['border']);
    }

    private function text(\GdImage $image, int $x, int $y, string $text, int $color, int $font): void
    {
        imagestring($image, $font, $x, $y, $text, $color);
    }

    private function truncate(string $value, int $length): string
    {
        $value = preg_replace('/\s+/', ' ', trim($value)) ?? '';

        return Str::limit($value, $length, '...');
    }
}
