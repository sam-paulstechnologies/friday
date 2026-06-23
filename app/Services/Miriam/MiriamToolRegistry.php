<?php

namespace App\Services\Miriam;

class MiriamToolRegistry
{
    public const READ_ONLY = 'read';
    public const LOW_RISK_WRITE = 'low_risk_write';
    public const CONFIRMATION_REQUIRED = 'confirmation_required';
    public const NEVER_AUTOMATIC = 'never_automatic';

    public function all(): array
    {
        return [
            'read_today_agenda' => $this->tool('read_today_agenda', self::READ_ONLY, [
                'date' => ['type' => 'string', 'required' => false],
            ]),
            'read_tomorrow_agenda' => $this->tool('read_tomorrow_agenda', self::READ_ONLY, []),
            'read_calendar_range' => $this->tool('read_calendar_range', self::READ_ONLY, [
                'start_date' => ['type' => 'string', 'required' => true],
                'end_date' => ['type' => 'string', 'required' => true],
            ]),
            'list_reminders' => $this->tool('list_reminders', self::READ_ONLY, [
                'period' => ['type' => 'string', 'required' => false],
                'status' => ['type' => 'string', 'required' => false],
            ]),
            'create_reminder' => $this->tool('create_reminder', self::LOW_RISK_WRITE, [
                'title' => ['type' => 'string', 'required' => true],
                'due_at_local' => ['type' => 'string', 'required' => true],
                'timezone' => ['type' => 'string', 'required' => true],
            ]),
            'update_reminder_status' => $this->tool('update_reminder_status', self::LOW_RISK_WRITE, [
                'reminder_id' => ['type' => 'integer', 'required' => true],
                'status' => ['type' => 'string', 'required' => true],
            ]),
            'list_pending_tasks' => $this->tool('list_pending_tasks', self::READ_ONLY, []),
            'create_task' => $this->tool('create_task', self::LOW_RISK_WRITE, [
                'title' => ['type' => 'string', 'required' => true],
                'due_at_local' => ['type' => 'string', 'required' => false],
                'timezone' => ['type' => 'string', 'required' => false],
            ]),
            'read_medication_status' => $this->tool('read_medication_status', self::READ_ONLY, [
                'dose_key' => ['type' => 'string', 'required' => false],
            ]),
            'send_medication_action_card' => $this->tool('send_medication_action_card', self::CONFIRMATION_REQUIRED, [
                'dose_log_id' => ['type' => 'integer', 'required' => false],
            ]),
            'read_health_summary' => $this->tool('read_health_summary', self::READ_ONLY, []),
            'read_development_status' => $this->tool('read_development_status', self::READ_ONLY, []),
            'read_recent_miriam_activity' => $this->tool('read_recent_miriam_activity', self::READ_ONLY, []),
            'search_miriam_memory' => $this->tool('search_miriam_memory', self::READ_ONLY, [
                'query' => ['type' => 'string', 'required' => true],
            ]),
        ];
    }

    public function get(string $name): ?array
    {
        return $this->all()[$name] ?? null;
    }

    public function allowsAutomatic(string $name, float $confidence = 1.0, string $riskLevel = 'low'): bool
    {
        $tool = $this->get($name);

        if (! $tool) {
            return false;
        }

        if ($tool['safety'] === self::READ_ONLY) {
            return true;
        }

        return $tool['safety'] === self::LOW_RISK_WRITE
            && $confidence >= MiriamBrainService::MIN_CONFIDENCE
            && $riskLevel === 'low';
    }

    private function tool(string $name, string $safety, array $schema): array
    {
        return [
            'name' => $name,
            'safety' => $safety,
            'schema' => $schema,
        ];
    }
}
