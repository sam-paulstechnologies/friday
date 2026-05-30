<?php

namespace App\Services\DailyReview;

use Illuminate\Support\Facades\File;

class DailyBriefingImageService
{
    public function generate(array $briefing): string
    {
        if (! extension_loaded('gd')) {
            throw new \RuntimeException('The GD PHP extension is required to generate daily briefing PNGs.');
        }

        $width = 1400;
        $height = 1040;
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

        $this->text($image, 40, 34, $briefing['title'], $colors['text'], 5);
        $this->text($image, 40, 66, 'Today: '.$briefing['date'].'  |  '.$briefing['portfolio_label'], $colors['muted'], 4);
        $this->text($image, 40, 94, $briefing['summary_line'], $colors['blue'], 4);

        $this->kpis($image, $briefing['summary'], $colors);
        $this->portfolioSummary($image, $briefing['portfolio_summary'], $colors);
        $this->sections($image, $briefing['sections'], $colors);

        $this->spiritual($image, $briefing['spiritual'], $colors);

        $this->text($image, 40, 1000, $briefing['priority_label'], $colors['muted'], 3);
        $this->text($image, 1010, 1000, 'See full details in Miriam Reports / Workload', $colors['muted'], 3);

        $directory = storage_path('app/briefings');
        File::ensureDirectoryExists($directory);

        $path = $directory.'/daily-briefing-'.$briefing['date'].'-'.uniqid().'.png';

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
            ['Due this week', $summary['due_week'], $colors['green']],
        ];

        foreach ($cards as $index => [$label, $value, $color]) {
            $x = 40 + ($index * 330);
            $this->box($image, $x, 138, 300, 92, $colors);
            $this->text($image, $x + 22, 160, (string) $label, $colors['muted'], 3);
            $this->text($image, $x + 22, 188, (string) $value, $color, 5);
        }
    }

    private function portfolioSummary(\GdImage $image, array $summary, array $colors): void
    {
        $this->box($image, 40, 252, 1320, 112, $colors);
        $this->text($image, 62, 272, 'Portfolio Summary', $colors['text'], 4);

        foreach ($summary as $index => $row) {
            $x = 62 + ($index * 620);
            $y = 306;
            $this->text($image, $x, $y, $row['portfolio'], $colors['blue'], 4);
            $this->text($image, $x, $y + 30, "Open {$row['open']}  |  Overdue {$row['overdue']}  |  Due today {$row['due_today']}  |  Urgent/high {$row['urgent_high']}", $colors['muted'], 3);
        }
    }

    private function sections(\GdImage $image, array $sections, array $colors): void
    {
        $layout = [
            'focus' => [40, 388, 1320, 118, 'Focus'],
            'overdue' => [40, 524, 1320, 136, 'Overdue'],
            'due_today' => [40, 678, 1320, 136, 'Due Today'],
            'upcoming' => [40, 832, 1320, 84, 'Upcoming'],
        ];

        foreach ($layout as $key => [$x, $y, $w, $h, $title]) {
            $this->box($image, $x, $y, $w, $h, $colors);
            $this->text($image, $x + 20, $y + 14, $title, $colors['text'], 4);

            $rows = $sections[$key] ?? [];
            if ($rows === []) {
                $this->text($image, $x + 20, $y + 48, 'No matching tasks', $colors['muted'], 3);
                continue;
            }

            $this->tableHeader($image, $x + 20, $y + 44, $colors);

            foreach ($rows as $index => $row) {
                $rowY = $y + 68 + ($index * 18);
                if ($index % 2 === 1) {
                    imagefilledrectangle($image, $x + 16, $rowY - 3, $x + $w - 16, $rowY + 14, $colors['row']);
                }

                $this->tableRow($image, $x + 20, $rowY, $row, $colors);
            }
        }
    }

    private function spiritual(\GdImage $image, array $spiritual, array $colors): void
    {
        $this->box($image, 40, 932, 1320, 48, $colors);
        $this->text($image, 62, 948, 'Spiritual Reading', $colors['green'], 4);
        $line = "Today: {$spiritual['today_label']} | Progress: {$spiritual['completed_chapters']} / {$spiritual['total_chapters']} | Streak: {$spiritual['current_streak']} days | {$spiritual['status_label']}";
        $this->text($image, 280, 950, $this->truncate($line, 138), $colors['text'], 3);
    }

    private function tableHeader(\GdImage $image, int $x, int $y, array $colors): void
    {
        $this->text($image, $x, $y, 'No.', $colors['muted'], 2);
        $this->text($image, $x + 48, $y, 'Type', $colors['muted'], 2);
        $this->text($image, $x + 160, $y, 'Priority', $colors['muted'], 2);
        $this->text($image, $x + 260, $y, 'Due', $colors['muted'], 2);
        $this->text($image, $x + 390, $y, 'Portfolio', $colors['muted'], 2);
        $this->text($image, $x + 550, $y, 'Project', $colors['muted'], 2);
        $this->text($image, $x + 850, $y, 'Task', $colors['muted'], 2);
    }

    private function tableRow(\GdImage $image, int $x, int $y, array $row, array $colors): void
    {
        $this->text($image, $x, $y, $this->truncate($row['no'], 4), $colors['text'], 2);
        $this->text($image, $x + 48, $y, $this->truncate($row['type'], 13), $colors['text'], 2);
        $this->text($image, $x + 160, $y, $this->truncate($row['priority'], 10), $colors['text'], 2);
        $this->text($image, $x + 260, $y, $this->truncate($row['due'], 13), $colors['text'], 2);
        $this->text($image, $x + 390, $y, $this->truncate($row['portfolio'], 16), $colors['text'], 2);
        $this->text($image, $x + 550, $y, $this->truncate($row['project'], 34), $colors['text'], 2);
        $this->text($image, $x + 850, $y, $this->truncate($row['task'], 58), $colors['text'], 2);
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

        return strlen($value) > $length ? substr($value, 0, $length - 3).'...' : $value;
    }
}
