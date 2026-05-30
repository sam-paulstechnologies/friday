<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Crypt;

class AiSetting extends Model
{
    use HasFactory;

    public const PROVIDER_OPENAI = 'openai';

    public const MODELS = [
        'gpt-4o-mini',
        'gpt-5.4-mini',
        'gpt-5.4-nano',
        'gpt-5.4',
    ];

    protected $fillable = [
        'workspace_id',
        'provider',
        'encrypted_api_key',
        'default_model',
        'planner_model',
        'max_tasks_sent',
        'max_output_tokens',
        'is_enabled',
    ];

    protected $casts = [
        'is_enabled' => 'boolean',
        'max_tasks_sent' => 'integer',
        'max_output_tokens' => 'integer',
    ];

    protected $hidden = [
        'encrypted_api_key',
    ];

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function setApiKey(?string $apiKey): void
    {
        if (blank($apiKey)) {
            return;
        }

        $this->encrypted_api_key = Crypt::encryptString($apiKey);
    }

    public function apiKey(): ?string
    {
        if (blank($this->encrypted_api_key)) {
            return null;
        }

        return Crypt::decryptString($this->encrypted_api_key);
    }

    public function maskedApiKey(): ?string
    {
        $apiKey = $this->apiKey();

        if (blank($apiKey)) {
            return null;
        }

        $suffix = substr($apiKey, -4);

        return 'sk-...'.$suffix;
    }
}
